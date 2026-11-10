<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Cache;

use MirrorGate\Cache\CacheDecision;
use MirrorGate\Cache\HttpCache;
use MirrorGate\Config\CacheConfig;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\NormalizedTarget;
use MirrorGate\Http\Request;
use MirrorGate\Log\NullLogger;
use MirrorGate\Support\Clock;
use PHPUnit\Framework\TestCase;

/**
 * Shared helpers for the cache unit tests. Only value objects and interfaces of other modules are
 * used (rule 0.14); the clock is an in-memory fake.
 */
abstract class CacheTestCase extends TestCase
{
    protected string $dir = '';
    protected Clock $clock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mg-cache-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0750, true);
        $this->clock = new class () implements Clock {
            public int $now = 1_700_000_000;

            public function time(): int
            {
                return $this->now;
            }

            public function microtime(): float
            {
                return (float) $this->now + 0.5;
            }
        };
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    protected function advance(int $seconds): void
    {
        $this->clock->now += $seconds; // @phpstan-ignore property.notFound
    }

    /** @param array<string,mixed> $overrides */
    protected function config(array $overrides = []): CacheConfig
    {
        $values = $overrides + [
            'enabled' => true,
            'dir' => $this->dir,
            'maxBytes' => 256 * 1024 * 1024,
            'maxObjectBytes' => 5 * 1024 * 1024,
            'defaultTtl' => 300,
            'minTtl' => 0,
            'maxTtl' => 86400,
            'negativeTtl' => 60,
            'swrSeconds' => 60,
            'staleIfErrorSeconds' => 604800,
            'staticExtensions' => ['js', 'css', 'png'],
            'rules' => [],
            'cookieMode' => 'static-only',
            'honorClientNoCache' => false,
            'ignoreMustRevalidateOnError' => true,
            'coalesceWaitMs' => 1500,
            'gcProbability' => 100,
            'gcIntervalSeconds' => 0,
            'lockMode' => 'flock',
            'ignoreQueryParams' => ['utm_source'],
            'varyExtra' => [],
            'statuses' => [200, 301, 308, 404, 410],
            'maxEntries' => 20000,
            'storePlainPaths' => false,
        ];

        return new CacheConfig(...$values);
    }

    protected function cache(?CacheConfig $config = null, string $instance = 'inst1', string $fingerprint = 'fp1'): HttpCache
    {
        return new HttpCache($config ?? $this->config(), $this->clock, new NullLogger(), $instance, $fingerprint, 'pathkey');
    }

    /** @param list<array{0:string,1:string}> $headers */
    protected function request(string $path = '/page', string $query = '', array $headers = [], string $method = 'GET'): Request
    {
        $target = new NormalizedTarget($path, $query);

        return new Request($method, $target, new HeaderBag($headers), '127.0.0.1', 'overload.ir', true, null, false, $target->pathAndQuery());
    }

    protected function decision(int $ttl = 60, int $swr = 30, int $sie = 3600, bool $mustRevalidate = false): CacheDecision
    {
        return new CacheDecision(true, $ttl, $swr, $sie, $mustRevalidate, 'ok');
    }

    /** @param list<array{0:string,1:string}> $headers */
    protected function storeBody(HttpCache $cache, Request $request, string $body, array $headers = [], ?CacheDecision $decision = null, array $selector = []): bool
    {
        $mirror = new HeaderBag($headers === [] ? [['Content-Type', 'text/html; charset=utf-8']] : $headers);
        $key = $cache->keyFor($request, $selector);

        return $cache->store($key, 200, $mirror, $body, $decision ?? $this->decision(), '"abc"', 'Wed, 01 Jan 2025 00:00:00 GMT', $selector);
    }

    /** @return list<string> */
    protected function entryFiles(): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && preg_match('#/e/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}$#', $file->getPathname()) === 1) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
