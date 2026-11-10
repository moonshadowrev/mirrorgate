<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Security\ConcurrencyLimiter;
use PHPUnit\Framework\TestCase;

final class ConcurrencyLimiterTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = Fixtures::tempDir('cl');
    }

    protected function tearDown(): void
    {
        Fixtures::removeTree($this->dir);
    }

    public function testCapAndRelease(): void
    {
        $l = new ConcurrencyLimiter($this->dir, new FixedClock());
        $a = $l->acquire('conc', 'subject', 2);
        $b = $l->acquire('conc', 'subject', 2);
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNull($l->acquire('conc', 'subject', 2));
        self::assertNotNull($l->acquire('conc', 'other', 2), 'other subjects are independent');
        $a->release();
        $a->release(); // idempotent
        $c = $l->acquire('conc', 'subject', 2);
        self::assertNotNull($c);
        self::assertNull($l->acquire('conc', 'subject', 2));
    }

    public function testScopesAreSeparate(): void
    {
        $l = new ConcurrencyLimiter($this->dir, new FixedClock());
        $a = $l->acquire('stream', 'x', 1);
        self::assertNotNull($a);
        self::assertNull($l->acquire('stream', 'x', 1));
        self::assertNotNull($l->acquire('global', 'x', 1));
    }

    public function testLeaseExpires(): void
    {
        $clock = new FixedClock();
        $l = new ConcurrencyLimiter($this->dir, $clock, 30);
        $slot = $l->acquire('conc', 's', 1);
        self::assertNotNull($slot);
        self::assertNull($l->acquire('conc', 's', 1));
        $clock->now += 31;
        self::assertNotNull($l->acquire('conc', 's', 1), 'a crashed worker lease expires');
    }

    public function testDestructorReleases(): void
    {
        $l = new ConcurrencyLimiter($this->dir, new FixedClock());
        $slot = $l->acquire('conc', 's', 1);
        self::assertNull($l->acquire('conc', 's', 1));
        unset($slot);
        self::assertNotNull($l->acquire('conc', 's', 1));
    }

    public function testDisabledLimitAndBadScope(): void
    {
        $l = new ConcurrencyLimiter($this->dir, new FixedClock());
        self::assertNotNull($l->acquire('conc', 's', 0));
        $this->expectException(\InvalidArgumentException::class);
        $l->acquire('../etc', 's', 1);
    }

    public function testShardFilesAreBounded(): void
    {
        $l = new ConcurrencyLimiter($this->dir, new FixedClock());
        $slots = [];
        for ($i = 0; $i < 300; $i++) {
            $slots[] = $l->acquire('conc', 'subject-' . $i, 1);
        }
        $files = glob($this->dir . '/conc/*.cl');
        self::assertIsArray($files);
        self::assertLessThanOrEqual(256, count($files));
    }
}
