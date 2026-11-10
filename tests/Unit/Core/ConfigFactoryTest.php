<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Core;

use MirrorGate\Config\ConfigException;
use MirrorGate\Config\ConfigFactory;
use PHPUnit\Framework\TestCase;

final class ConfigFactoryTest extends TestCase
{
    private const BASE = ['ORIGIN_URL' => 'https://overlord.team', 'MIRROR_URL' => 'https://ormantek.com'];

    public function testDefaults(): void
    {
        $c = ConfigFactory::create(self::BASE, '/srv/app', '/srv/app/.env');
        self::assertSame('/srv/app/var', $c->app->storageDir);
        self::assertSame(443, $c->origin->port);
        self::assertSame(['ormantek.com'], $c->mirror->allowedHosts);
        self::assertSame([200, 301, 308, 404, 410], $c->cache->statuses);
        self::assertSame(8, $c->rateLimit->globalMaxConcurrent);
        self::assertSame(['https://ormantek.com'], $c->daemon->wsOriginAllow);
        self::assertSame(12, strlen($c->app->instanceId));
        self::assertFalse($c->admin->enabled);
    }

    public function testCollectsAllErrorsWithoutValues(): void
    {
        $bad = [
            'ORIGIN_URL' => 'http://x.com/path', 'MIRROR_URL' => 'https://api.x.com', 'ADMIN_ENABLED' => 'true',
            'ADMIN_TOKEN' => 'short', 'CACHE_STATUSES' => '200,999', 'RATE_LIMIT_REQUESTS' => '0',
        ];
        try {
            ConfigFactory::create($bad, '/srv', '/srv/.env');
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            $codes = $e->codes();
            self::assertContains('ORIGIN_URL:path_not_allowed', $codes);
            self::assertContains('CACHE_STATUSES:unsupported_status', $codes);
            self::assertContains('RATE_LIMIT_REQUESTS:out_of_range', $codes);
            self::assertContains('ADMIN_TOKEN:too_short', $codes);
            foreach ($e->getErrors() as $line) {
                self::assertStringNotContainsString('short', explode(' - ', $line, 2)[1] ?? '');
            }
        }
    }

    public function testMirrorMustNotBeUnderOrigin(): void
    {
        $this->expectException(ConfigException::class);
        ConfigFactory::create(['ORIGIN_URL' => 'https://x.com', 'MIRROR_URL' => 'https://api.x.com'], '/srv', '/srv/.env');
    }

    public function testPrefixModeNeedsAcknowledgement(): void
    {
        try {
            ConfigFactory::create(self::BASE + ['EXTRA_ORIGINS' => 'api.overlord.team', 'SUBDOMAIN_MODE' => 'prefix'], '/srv', '/srv/.env');
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            self::assertContains('EXTRA_ORIGINS:ack_required', $e->codes());
            self::assertContains('SUBDOMAIN_MODE:ack_required', $e->codes());
        }
        $c = ConfigFactory::create(self::BASE + ['EXTRA_ORIGINS' => 'api.overlord.team', 'SUBDOMAIN_MODE' => 'prefix', 'PREFIX_MODE_ACK_SHARED_ORIGIN' => 'true'], '/srv', '/srv/.env');
        self::assertSame(['api.overlord.team'], $c->origin->extraOrigins);
    }

    public function testFullFeatureSet(): void
    {
        $c = ConfigFactory::create(self::BASE + [
            'ADMIN_ENABLED' => 'true', 'ADMIN_TOKEN' => bin2hex(random_bytes(20)),
            'HOST_MAP' => 'api.overlord.team=api.ormantek.com', 'TRUSTED_PROXIES' => '10.0.0.0/8, 2001:db8::/32',
            'MIRROR_ALIASES' => 'www.ormantek.com', 'AUTH_PASSTHROUGH_BLOCK' => '/login*',
            'CACHE_RULES' => '/api/*=-1,/static/*=3600', 'WS_PUBLIC_URL' => 'wss://ws.ormantek.com:8443',
        ], '/srv', '/srv/.env');
        self::assertSame(['api.overlord.team' => 'api.ormantek.com'], $c->origin->hostMap);
        self::assertSame(['pattern' => '/api/*', 'ttl' => -1], $c->cache->rules[0]);
        self::assertSame(['/login*'], $c->security->blockedPaths);
        self::assertSame('wss://ws.ormantek.com:8443', $c->mirror->wsPublicUrl);
        self::assertSame(['ormantek.com', 'www.ormantek.com'], $c->mirror->allowedHosts);
    }

    public function testAdminRequiresHttpsOrOptOut(): void
    {
        $env = ['ORIGIN_URL' => 'https://overlord.team', 'MIRROR_URL' => 'http://ormantek.com', 'ADMIN_ENABLED' => 'true', 'ADMIN_TOKEN' => bin2hex(random_bytes(20))];
        try {
            ConfigFactory::create($env, '/srv', '/srv/.env');
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            self::assertContains('ADMIN_ENABLED:requires_https', $e->codes());
        }
        self::assertTrue(ConfigFactory::create($env + ['ADMIN_ALLOW_HTTP' => 'true'], '/srv', '/srv/.env')->admin->allowHttp);
    }

    public function testDaemonDefaultsKeepTheBufferBudgetInsideTheMemoryLimit(): void
    {
        $c = ConfigFactory::create(self::BASE, '/srv/app', '/srv/app/.env');
        self::assertSame(2, $c->daemon->udpMaxAmplification);
        self::assertSame([], $c->daemon->tcpClientAllow);
        $worst = 2 * $c->daemon->maxConnections * ($c->daemon->bufferHighWater + 65536);
        self::assertLessThanOrEqual(256 * 1024 * 1024, $worst);

        $explicit = ConfigFactory::create(self::BASE + ['DAEMON_BUFFER_HIGH_WATER' => '1M'], '/srv/app', '/srv/app/.env');
        self::assertSame(1048576, $explicit->daemon->bufferHighWater);
    }

    public function testWideUdpClientAllowListsAreRejected(): void
    {
        foreach (['0.0.0.0/0', '::/0', '10.0.0.0/7', '2001::/16'] as $range) {
            try {
                ConfigFactory::create(self::BASE + ['DAEMON_UDP_CLIENT_ALLOW' => $range], '/srv/app', '/srv/app/.env');
                self::fail('expected a ConfigException for ' . $range);
            } catch (ConfigException $e) {
                self::assertSame(['DAEMON_UDP_CLIENT_ALLOW:range_too_wide'], $e->codes());
            }
        }
        $ok = ConfigFactory::create(self::BASE + ['DAEMON_UDP_CLIENT_ALLOW' => '10.0.0.0/8,2001:db8::/32', 'DAEMON_TCP_CLIENT_ALLOW' => '0.0.0.0/0'], '/srv/app', '/srv/app/.env');
        self::assertSame(['10.0.0.0/8', '2001:db8::/32'], $ok->daemon->udpClientAllow);
        self::assertSame(['0.0.0.0/0'], $ok->daemon->tcpClientAllow);
    }
}
