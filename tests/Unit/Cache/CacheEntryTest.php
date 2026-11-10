<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Cache;

use MirrorGate\Cache\CacheEntry;
use MirrorGate\Cache\CacheState;
use MirrorGate\Http\HeaderBag;
use PHPUnit\Framework\TestCase;

final class CacheEntryTest extends TestCase
{
    private function entry(bool $mustRevalidate = false): CacheEntry
    {
        return new CacheEntry(200, new HeaderBag([]), 0, 'identity', 1000, 1100, 1160, 1500, null, null, 'W/"mg-x"', $mustRevalidate, [], 'text/html', '');
    }

    public function testStateTransitions(): void
    {
        $e = $this->entry();
        self::assertSame(CacheState::Fresh, $e->state(1099, true));
        self::assertSame(CacheState::StaleWhileRevalidate, $e->state(1100, true));
        self::assertSame(CacheState::StaleUsableOnError, $e->state(1160, true));
        self::assertSame(CacheState::Expired, $e->state(1500, true));
    }

    public function testMustRevalidate(): void
    {
        $e = $this->entry(true);
        self::assertSame(CacheState::StaleUsableOnError, $e->state(1120, true));
        self::assertSame(CacheState::Expired, $e->state(1120, false));
    }

    public function testEtagFormulaAndAge(): void
    {
        self::assertSame('W/"mg-' . substr(sha1('abc'), 0, 16) . '"', CacheEntry::etagFor('abc'));
        self::assertSame(5, $this->entry()->age(1005));
        self::assertSame(0, $this->entry()->age(900));
    }

    public function testBodyUnavailableWithoutFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->entry()->openBody();
    }

    public function testRejectsFilesOutsideCacheLayout(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mg');
        self::assertIsString($tmp);
        $e = new CacheEntry(200, new HeaderBag([]), 0, 'identity', 1, 2, 3, 4, null, null, 'x', false, [], '', '', $tmp, 0);
        try {
            $this->expectException(\RuntimeException::class);
            $e->bodyContents();
        } finally {
            @unlink($tmp);
        }
    }
}
