<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Upstream;

use MirrorGate\Log\NullLogger;
use MirrorGate\Tests\Support\Upstream\FakeDnsResolver;
use MirrorGate\Tests\Support\Upstream\FixedClock;
use MirrorGate\Upstream\CachingDnsResolver;
use PHPUnit\Framework\TestCase;

final class CachingDnsResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/mg-dns-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->dir)) {
            foreach (\glob($this->dir . '/*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($this->dir);
        }
    }

    private function make(FakeDnsResolver $inner, FixedClock $clock): CachingDnsResolver
    {
        return new CachingDnsResolver($inner, $this->dir, $clock, 60, 3600, new NullLogger());
    }

    public function testFreshAnswerIsServedFromCacheAcrossInstances(): void
    {
        $clock = new FixedClock();
        $inner = new FakeDnsResolver(['overlord.team' => ['93.184.216.34']]);

        self::assertSame(['93.184.216.34'], $this->make($inner, $clock)->resolve('overlord.team'));
        $clock->advance(30);
        // A new instance has no memo; the file cache must answer.
        self::assertSame(['93.184.216.34'], $this->make($inner, $clock)->resolve('overlord.team'));
        self::assertSame(1, $inner->calls);
    }

    public function testExpiredAnswerIsRefreshed(): void
    {
        $clock = new FixedClock();
        $inner = new FakeDnsResolver(['overlord.team' => ['93.184.216.34']]);
        $this->make($inner, $clock)->resolve('overlord.team');

        $clock->advance(61);
        $inner->answers['overlord.team'] = ['93.184.216.35'];

        self::assertSame(['93.184.216.35'], $this->make($inner, $clock)->resolve('overlord.team'));
        self::assertSame(2, $inner->calls);
    }

    public function testStaleAnswerIsServedWhenResolverFails(): void
    {
        $clock = new FixedClock();
        $inner = new FakeDnsResolver(['overlord.team' => ['93.184.216.34']]);
        $this->make($inner, $clock)->resolve('overlord.team');

        $clock->advance(600);
        $inner->fail = true;

        self::assertSame(['93.184.216.34'], $this->make($inner, $clock)->resolve('overlord.team'));
    }

    public function testStaleAnswerExpiresAfterStaleTtl(): void
    {
        $clock = new FixedClock();
        $inner = new FakeDnsResolver(['overlord.team' => ['93.184.216.34']]);
        $this->make($inner, $clock)->resolve('overlord.team');

        $clock->advance(60 + 3600);
        $inner->fail = true;

        self::assertSame([], $this->make($inner, $clock)->resolve('overlord.team'));
    }

    public function testEmptyAnswerFallsBackToStale(): void
    {
        $clock = new FixedClock();
        $inner = new FakeDnsResolver(['overlord.team' => ['93.184.216.34']]);
        $this->make($inner, $clock)->resolve('overlord.team');

        $clock->advance(120);
        $inner->answers = [];

        self::assertSame(['93.184.216.34'], $this->make($inner, $clock)->resolve('overlord.team'));
    }

    public function testCacheFileNamesAreDigestsOnly(): void
    {
        $inner = new FakeDnsResolver(['../../etc/passwd' => ['93.184.216.34']]);
        $this->make($inner, new FixedClock())->resolve('../../etc/passwd');

        foreach (\glob($this->dir . '/*.json') ?: [] as $file) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.json$/', \basename($file));
        }
        self::assertFileDoesNotExist($this->dir . '/../../etc/passwd.json');
    }

    public function testCorruptCacheFileIsIgnored(): void
    {
        $clock = new FixedClock();
        $inner = new FakeDnsResolver(['overlord.team' => ['93.184.216.34']]);
        $this->make($inner, $clock)->resolve('overlord.team');
        foreach (\glob($this->dir . '/*.json') ?: [] as $file) {
            \file_put_contents($file, '{not json');
        }

        self::assertSame(['93.184.216.34'], $this->make($inner, $clock)->resolve('overlord.team'));
        self::assertSame(2, $inner->calls);
    }

    public function testInvalidAddressesFromResolverAreDropped(): void
    {
        $inner = new FakeDnsResolver(['overlord.team' => ['not-an-ip', '93.184.216.34', "1.2.3.4\r\n"]]);

        self::assertSame(['93.184.216.34'], $this->make($inner, new FixedClock())->resolve('overlord.team'));
    }
}
