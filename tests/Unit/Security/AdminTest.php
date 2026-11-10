<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Cache\CacheAdminInterface;
use MirrorGate\Cache\CacheStats;
use MirrorGate\Config\Config;
use MirrorGate\Console\CheckLevel;
use MirrorGate\Console\CheckResult;
use MirrorGate\Console\SelfCheckInterface;
use MirrorGate\Http\HttpException;
use MirrorGate\Security\AdminAuthenticator;
use MirrorGate\Security\AdminController;
use MirrorGate\Security\FileRateLimiter;
use MirrorGate\Upstream\BreakerInspector;
use MirrorGate\Upstream\BreakerState;
use PHPUnit\Framework\TestCase;

/** Covers AdminAuthenticator (SEC-50, SEC-51, SEC-90) and AdminController routing (SEC-52). */
final class AdminTest extends TestCase
{
    private const TOKEN = 'Zq7vK2mP9xW4nR8tL5bY3cH6jD1fG0sA';

    private string $dir = '';
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->dir = Fixtures::tempDir('admin');
        $this->clock = new FixedClock();
    }

    protected function tearDown(): void
    {
        Fixtures::removeTree($this->dir);
    }

    /** @param array<string,mixed> $cfg */
    private function auth(array $cfg = []): AdminAuthenticator
    {
        $config = Fixtures::config($cfg);
        return new AdminAuthenticator($config->admin, new FileRateLimiter($this->dir . '/rl', $this->clock));
    }

    /** @param list<array{0:string,1:string}> $headers */
    private function authorise(AdminAuthenticator $auth, array $headers, string $ip = '203.0.113.9'): ?int
    {
        $e = $auth->authorise(Fixtures::request('GET', '/__mirrorgate/live', $headers), $ip);
        return $e?->status;
    }

    public function testValidTokenViaBearerAndCustomHeader(): void
    {
        $auth = $this->auth();
        self::assertNull($this->authorise($auth, [['Authorization', 'Bearer ' . self::TOKEN]]));
        self::assertNull($this->authorise($auth, [['Authorization', 'bearer   ' . self::TOKEN]]));
        self::assertNull($this->authorise($auth, [['X-MirrorGate-Token', self::TOKEN]]));
        // A non-bearer Authorization (for example web server basic auth) does not hide the fallback header.
        self::assertNull($this->authorise($auth, [['Authorization', 'Basic abc'], ['X-MirrorGate-Token', self::TOKEN]]));
    }

    public function testWrongOrMissingTokenIsGeneric404(): void
    {
        $auth = $this->auth(['failLimit' => 100]);
        self::assertSame(404, $this->authorise($auth, []));
        self::assertSame(404, $this->authorise($auth, [['Authorization', 'Bearer wrong']]));
        self::assertSame(404, $this->authorise($auth, [['Authorization', 'Bearer ' . self::TOKEN . 'x']]));
        self::assertSame(404, $this->authorise($auth, [['X-MirrorGate-Token', substr(self::TOKEN, 0, -1)]]));
        self::assertSame(404, $this->authorise($auth, [['Authorization', 'Bearer ' . str_repeat('a', 5000)]]));
    }

    public function testTokenIsNeverTakenFromQueryOrCookie(): void
    {
        $auth = $this->auth(['failLimit' => 100]);
        $withQuery = Fixtures::request('GET', '/__mirrorgate/live', [], query: 'token=' . self::TOKEN);
        self::assertSame(404, $auth->authorise($withQuery, '203.0.113.9')?->status);
        $withCookie = Fixtures::request('GET', '/__mirrorgate/live', [['Cookie', 'token=' . self::TOKEN]]);
        self::assertSame(404, $auth->authorise($withCookie, '203.0.113.9')?->status);
    }

    public function testDisabledAdminIs404EvenWithValidToken(): void
    {
        $auth = $this->auth(['adminEnabled' => false]);
        self::assertSame(404, $this->authorise($auth, [['Authorization', 'Bearer ' . self::TOKEN]]));
    }

    public function testIpAllowListIsEnforcedBeforeCounters(): void
    {
        $auth = $this->auth(['adminIpAllow' => ['198.51.100.0/24'], 'failLimit' => 2]);
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(404, $this->authorise($auth, [['Authorization', 'Bearer wrong']], '203.0.113.9'));
        }
        // The outsider never fed the failure counters, so an allowed client is not locked out.
        self::assertNull($this->authorise($auth, [['Authorization', 'Bearer ' . self::TOKEN]], '198.51.100.20'));
    }

    public function testValidTokenIsNeverLockedOut(): void
    {
        $auth = $this->auth(['failLimit' => 3, 'globalFailLimit' => 3]);
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(404, $this->authorise($auth, [['Authorization', 'Bearer wrong']]), 'failures stay a generic 404');
        }
        // Anonymous failures from the same address (or from many addresses) must not deny the admin.
        self::assertNull($this->authorise($auth, [['Authorization', 'Bearer ' . self::TOKEN]]));
        for ($i = 0; $i < 20; $i++) {
            $this->authorise($auth, [['Authorization', 'Bearer wrong']], '198.51.100.' . $i);
        }
        self::assertNull($this->authorise($auth, [['Authorization', 'Bearer ' . self::TOKEN]], '203.0.113.200'));
    }

    public function testThrottledAnonymousRequestsNeverAnswer429(): void
    {
        $auth = $this->auth(['failLimit' => 2, 'globalFailLimit' => 2]);
        for ($i = 0; $i < 200; $i++) {
            self::assertSame(404, $this->authorise($auth, []));
        }
    }

    public function testSuccessfulRequestsDoNotConsumeFailureBudget(): void
    {
        $auth = $this->auth(['failLimit' => 2]);
        for ($i = 0; $i < 200; $i++) {
            self::assertNull($this->authorise($auth, [['Authorization', 'Bearer ' . self::TOKEN]]));
        }
    }

    public function testPlainHttpIsRefusedUnlessAllowed(): void
    {
        $plain = Fixtures::request('GET', '/__mirrorgate/live', [['Authorization', 'Bearer ' . self::TOKEN]], tls: false);
        self::assertSame(404, $this->auth()->authorise($plain, '203.0.113.9')?->status);

        // A proxy that is not trusted cannot vouch for TLS.
        $forwarded = Fixtures::request('GET', '/__mirrorgate/live', [['Authorization', 'Bearer ' . self::TOKEN], ['X-Forwarded-Proto', 'https']], tls: false);
        self::assertSame(404, $this->auth()->authorise($forwarded, '203.0.113.9')?->status);

        $config = Fixtures::config();
        $trusting = new AdminAuthenticator($config->admin, new FileRateLimiter($this->dir . '/rlt', $this->clock), ['203.0.113.0/24']);
        self::assertNull($trusting->authorise($forwarded, '203.0.113.9'));
        self::assertSame(404, $trusting->authorise($plain, '203.0.113.9')?->status);

        $allowHttp = new \MirrorGate\Config\AdminConfig(true, self::TOKEN, '/__mirrorgate', [], 5, 900, 60, 50, true);
        $open = new AdminAuthenticator($allowHttp, new FileRateLimiter($this->dir . '/rlh', $this->clock));
        self::assertNull($open->authorise($plain, '203.0.113.9'));
    }

    // ------------------------------------------------------------------ controller

    /** @param array<string,mixed> $cfg */
    private function controller(array $cfg = [], ?CacheAdminInterface $cache = null, ?SelfCheckInterface $check = null): AdminController
    {
        $config = Fixtures::config($cfg + ['storageDir' => '/secret/storage/dir', 'failLimit' => 100]);
        $auth = new AdminAuthenticator($config->admin, new FileRateLimiter($this->dir . '/rlc', $this->clock));
        $cache ??= new class () implements CacheAdminInterface {
            /** @var list<string> */
            public array $calls = [];

            public function purgeUrl(string $pathAndQuery, bool $soft): int
            {
                $this->calls[] = 'url:' . $pathAndQuery . ':' . ($soft ? 's' : 'h');
                return 2;
            }

            public function purgePrefix(string $pathPrefix, bool $soft): int
            {
                $this->calls[] = 'prefix:' . $pathPrefix;
                return 5;
            }

            public function purgeAll(bool $soft): int
            {
                $this->calls[] = 'all:' . ($soft ? 's' : 'h');
                return 9;
            }

            public function stats(): CacheStats
            {
                return new CacheStats(12, 3456, 7);
            }
        };
        $breaker = new class () implements BreakerInspector {
            public function state(): BreakerState
            {
                return BreakerState::Closed;
            }

            public function snapshot(): array
            {
                return ['state' => 'closed', 'failures' => 1, 'openUntil' => 0, 'cooldown' => 30];
            }
        };
        $check ??= new class () implements SelfCheckInterface {
            public function run(bool $network): array
            {
                return [
                    new CheckResult('storage', CheckLevel::Ok, 'writable'),
                    new CheckResult('leak', CheckLevel::Warn, 'path /secret/storage/dir/logs for https://overlord.team token ' . 'Zq7vK2mP9xW4nR8tL5bY3cH6jD1fG0sA'),
                ];
            }
        };
        return new AdminController($config, $auth, $cache, $breaker, $check, $this->clock);
    }

    /**
     * @param list<array{0:string,1:string}> $headers
     */
    private function call(AdminController $c, string $method, string $path, array $headers = [], ?string $body = null): \MirrorGate\Http\Response
    {
        $stream = null;
        if ($body !== null) {
            $stream = fopen('php://temp', 'r+b');
            self::assertIsResource($stream);
            fwrite($stream, $body);
            rewind($stream);
        }
        $headers[] = ['Authorization', 'Bearer ' . self::TOKEN];
        $req = Fixtures::request($method, $path, $headers, body: $stream);
        return $c->handle(Fixtures::context($req, isHead: $method === 'HEAD'));
    }

    private function statusOf(AdminController $c, string $method, string $path, array $headers = [], ?string $body = null): int
    {
        try {
            return $this->call($c, $method, $path, $headers, $body)->status;
        } catch (HttpException $e) {
            return $e->status;
        }
    }

    public function testLiveHealthStats(): void
    {
        $c = $this->controller();
        $live = $this->call($c, 'GET', '/__mirrorgate/live');
        self::assertSame(200, $live->status);
        self::assertSame('ok', $live->body);
        self::assertSame('no-store', $live->headers->get('cache-control'));

        $health = json_decode($this->call($c, 'GET', '/__mirrorgate/health')->body, true);
        self::assertIsArray($health);
        self::assertTrue($health['ok']);
        self::assertSame(['entries' => 12, 'bytes' => 3456], $health['cache']);
        self::assertSame(['state' => 'closed', 'failures' => 1], $health['breaker']);
        self::assertIsInt($health['time']);

        $stats = $this->call($c, 'GET', '/__mirrorgate/stats');
        $data = json_decode($stats->body, true);
        self::assertSame(['entries' => 12, 'bytes' => 3456, 'generation' => 7], $data['cache']);
        self::assertSame(['state', 'failures', 'openUntil', 'cooldown'], array_keys($data['breaker']));
        self::assertStringNotContainsString('/secret/storage', $stats->body);
    }

    public function testRoutingAndMethods(): void
    {
        $c = $this->controller();
        self::assertSame(404, $this->statusOf($c, 'GET', '/__mirrorgate'));
        self::assertSame(404, $this->statusOf($c, 'GET', '/__mirrorgate/'));
        self::assertSame(404, $this->statusOf($c, 'GET', '/__mirrorgate/nope'));
        self::assertSame(404, $this->statusOf($c, 'GET', '/__mirrorgatex/live'));
        self::assertSame(405, $this->statusOf($c, 'POST', '/__mirrorgate/live'));
        self::assertSame(405, $this->statusOf($c, 'GET', '/__mirrorgate/purge'));
        self::assertSame(405, $this->statusOf($c, 'GET', '/__mirrorgate/check'));
        self::assertSame(200, $this->statusOf($c, 'HEAD', '/__mirrorgate/live'));
    }

    public function testUnauthenticatedRoutesAreIndistinguishableFromMissing(): void
    {
        $c = $this->controller();
        foreach (['/__mirrorgate/live', '/__mirrorgate/stats', '/__mirrorgate/nope', '/__mirrorgate/purge'] as $path) {
            try {
                $c->handle(Fixtures::context(Fixtures::request('GET', $path)));
                self::fail('Expected 404');
            } catch (HttpException $e) {
                self::assertSame(404, $e->status, $path);
            }
        }
    }

    public function testPurge(): void
    {
        $json = [['Content-Type', 'application/json']];
        $c = $this->controller();
        $r = $this->call($c, 'POST', '/__mirrorgate/purge', $json, '{"mode":"url","target":"/a?b=1","soft":true}');
        self::assertSame(['purged' => 2], json_decode($r->body, true));
        $r = $this->call($c, 'POST', '/__mirrorgate/purge', [['Content-Type', 'application/json; charset=utf-8']], '{"mode":"prefix","target":"/blog/"}');
        self::assertSame(['purged' => 5], json_decode($r->body, true));
        $r = $this->call($c, 'POST', '/__mirrorgate/purge', $json, '{"mode":"all"}');
        self::assertSame(['purged' => 9], json_decode($r->body, true));

        self::assertSame(415, $this->statusOf($c, 'POST', '/__mirrorgate/purge', [['Content-Type', 'text/plain']], '{"mode":"all"}'));
        self::assertSame(415, $this->statusOf($c, 'POST', '/__mirrorgate/purge', [], '{"mode":"all"}'));
        foreach ([
            '', 'not json', '[]', '"all"', '{}', '{"mode":"bogus"}', '{"mode":"url"}', '{"mode":"url","target":"nope"}',
            '{"mode":"url","target":"//evil.com"}', '{"mode":"url","target":"/a b"}', '{"mode":"url","target":"/a\\\\b"}',
            '{"mode":"url","target":"/a#frag"}', '{"mode":"url","target":"/a\\u0000"}', '{"mode":"url","target":"/a\\r\\nX: y"}',
            '{"mode":"all","soft":"yes"}', '{"mode":"url","target":["/a"]}',
        ] as $body) {
            self::assertSame(400, $this->statusOf($c, 'POST', '/__mirrorgate/purge', $json, $body), $body);
        }
        self::assertSame(413, $this->statusOf($c, 'POST', '/__mirrorgate/purge', $json, '{"mode":"all","x":"' . str_repeat('a', 20000) . '"}'));
    }

    public function testCheckOutputIsScrubbed(): void
    {
        $c = $this->controller();
        $r = $this->call($c, 'POST', '/__mirrorgate/check');
        self::assertSame(200, $r->status);
        $body = $r->body;
        foreach (['/secret/storage/dir', 'overlord.team', self::TOKEN] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
        $data = json_decode($body, true);
        self::assertSame('ok', $data['checks'][0]['level']);
        self::assertSame('warn', $data['checks'][1]['level']);
    }

    public function testStreamTestReturnsEmptyBufferedResponseForHead(): void
    {
        $c = $this->controller();
        $r = $this->call($c, 'HEAD', '/__mirrorgate/streamtest');
        self::assertSame(200, $r->status);
        self::assertSame('', $r->body);
        self::assertSame('no', $r->headers->get('x-accel-buffering'));
    }

    public function testPurgeWarnsWhenNothingMatched(): void
    {
        $cache = new class () implements CacheAdminInterface {
            public function purgeUrl(string $pathAndQuery, bool $soft): int
            {
                return 0;
            }

            public function purgePrefix(string $pathPrefix, bool $soft): int
            {
                return 0;
            }

            public function purgeAll(bool $soft): int
            {
                return 0;
            }

            public function stats(): CacheStats
            {
                return new CacheStats(0, 0, 1);
            }
        };
        $c = $this->controller([], $cache);
        $r = $this->call($c, 'POST', '/__mirrorgate/purge', [['Content-Type', 'application/json']], '{"mode":"url","target":"/nope"}');
        $data = json_decode($r->body, true);
        self::assertSame(200, $r->status);
        self::assertSame(0, $data['purged']);
        self::assertArrayHasKey('warning', $data);
        $all = json_decode($this->call($c, 'POST', '/__mirrorgate/purge', [['Content-Type', 'application/json']], '{"mode":"all"}')->body, true);
        self::assertArrayNotHasKey('warning', $all);
    }

    public function testSelfCheckWarnsAboutAdminBehindUntrustedProxy(): void
    {
        $config = \MirrorGate\Config\ConfigFactory::create([
            'ORIGIN_URL' => 'https://overlord.team', 'MIRROR_URL' => 'https://ormantek.com',
            'ADMIN_ENABLED' => 'true', 'ADMIN_TOKEN' => self::TOKEN, 'STORAGE_DIR' => $this->dir . '/st',
        ], $this->dir, $this->dir . '/.env');
        $results = (new \MirrorGate\Console\SelfCheck($config))->run(false);
        $names = array_map(static fn (CheckResult $r): string => $r->name, $results);
        self::assertContains('admin.proxy', $names);
        self::assertContains('ratelimit.storage', $names);
    }

    public function testRateLimiterProbeReportsUnusableStorage(): void
    {
        $good = new FileRateLimiter($this->dir . '/probe-ok', $this->clock);
        self::assertTrue($good->probe());
        file_put_contents($this->dir . '/blocker', 'x');
        $bad = new FileRateLimiter($this->dir . '/blocker/ratelimit', $this->clock);
        self::assertFalse($bad->probe());
        // Traffic limiting stays available (fails open) but the failure is no longer silent.
        $logFile = $this->dir . '/error.log';
        $previousLog = ini_set('error_log', $logFile);
        try {
            $result = $bad->hit('k', 1, 60);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
        }
        self::assertTrue($result->allowed);
        self::assertStringContainsString('rate-limit storage is not usable', (string) file_get_contents($logFile));
    }

    public function testAtomicWriteCreatesOwnerOnlyTemporaryFiles(): void
    {
        $old = umask(0);
        try {
            $path = $this->dir . '/secret';
            \MirrorGate\Support\FileUtil::atomicWrite($path, 'data', 0600);
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(0, umask(), 'the process umask is restored');
            \MirrorGate\Support\FileUtil::atomicWrite($this->dir . '/shared', 'data', 0644);
            self::assertSame(0644, fileperms($this->dir . '/shared') & 0777);
        } finally {
            umask($old);
        }
    }
}
