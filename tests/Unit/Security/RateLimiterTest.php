<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Security\FileRateLimiter;
use MirrorGate\Security\RateLimiterFactory;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = Fixtures::tempDir('rl');
    }

    protected function tearDown(): void
    {
        Fixtures::removeTree($this->dir);
    }

    public function testFixedWindowCountsAndBlocks(): void
    {
        $clock = new FixedClock(1_700_000_010);
        $rl = new FileRateLimiter($this->dir . '/ratelimit', $clock);
        $key = RateLimiterFactory::keyFor('req', '203.0.113.9', 's');

        for ($i = 1; $i <= 3; $i++) {
            $res = $rl->hit($key, 3, 60);
            self::assertTrue($res->allowed);
            self::assertSame(3 - $i, $res->remaining);
            self::assertSame(3, $res->limit);
        }
        $blocked = $rl->hit($key, 3, 60);
        self::assertFalse($blocked->allowed);
        self::assertSame(0, $blocked->remaining);
        self::assertGreaterThanOrEqual(1, $blocked->retryAfter);
        self::assertLessThanOrEqual(60, $blocked->retryAfter);

        // The next window starts clean.
        $clock->now += 60;
        self::assertTrue($rl->hit($key, 3, 60)->allowed);
    }

    public function testKeysAreIndependentAndResetWorks(): void
    {
        $rl = new FileRateLimiter($this->dir . '/ratelimit', new FixedClock());
        $a = RateLimiterFactory::keyFor('req', '203.0.113.9', 's');
        $b = RateLimiterFactory::keyFor('req', '203.0.113.10', 's');
        self::assertTrue($rl->hit($a, 1, 60)->allowed);
        self::assertFalse($rl->hit($a, 1, 60)->allowed);
        self::assertTrue($rl->hit($b, 1, 60)->allowed);
        $rl->reset($a);
        self::assertTrue($rl->hit($a, 1, 60)->allowed);
    }

    public function testPeekDoesNotCount(): void
    {
        $rl = new FileRateLimiter($this->dir . '/ratelimit', new FixedClock());
        $key = RateLimiterFactory::keyFor('f', 'x', 's');
        self::assertTrue($rl->peek($key, 2, 60)->allowed);
        $rl->hit($key, 2, 60);
        self::assertTrue($rl->peek($key, 2, 60)->allowed);
        $rl->hit($key, 2, 60);
        $peek = $rl->peek($key, 2, 60);
        self::assertFalse($peek->allowed);
        self::assertGreaterThanOrEqual(1, $peek->retryAfter);
        // Peeking never advanced the counter: the third real hit is still the third.
        $third = $rl->hit($key, 3, 60);
        self::assertTrue($third->allowed);
        self::assertSame(0, $third->remaining);
    }

    public function testStateIsBoundedAndHoldsNoRawAddress(): void
    {
        $rl = new FileRateLimiter($this->dir . '/ratelimit', new FixedClock());
        for ($i = 0; $i < 600; $i++) {
            $rl->hit(RateLimiterFactory::keyFor('req', '198.51.100.' . ($i % 250) . ':' . $i, 'salt'), 5, 60);
        }
        $files = glob($this->dir . '/ratelimit/*.rl');
        self::assertIsArray($files);
        self::assertLessThanOrEqual(4096, count($files));
        $blob = '';
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines);
            self::assertLessThanOrEqual(256, count($lines));
            $blob .= (string) file_get_contents($file);
        }
        self::assertStringNotContainsString('198.51.100', $blob);
        self::assertFileExists($this->dir . '/ratelimit/.htaccess');
    }

    public function testKeyForNormalisesIpSubjects(): void
    {
        $k = static fn (string $s): string => RateLimiterFactory::keyFor('req', $s, 'salt');
        self::assertSame($k('203.0.113.9'), $k('::ffff:203.0.113.9'));
        self::assertSame($k('2001:db8:1:2::1'), $k('2001:db8:1:2:ffff:ffff:ffff:ffff'));
        self::assertNotSame($k('2001:db8:1:2::1'), $k('2001:db8:1:3::1'));
        self::assertNotSame($k('203.0.113.9'), RateLimiterFactory::keyFor('req', '203.0.113.9', 'other'));
        self::assertNotSame($k('203.0.113.9'), RateLimiterFactory::keyFor('conc', '203.0.113.9', 'salt'));
        self::assertSame(64, strlen($k('instance-id')));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $k('203.0.113.9'));
    }
}
