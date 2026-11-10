<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Core;

use MirrorGate\Http\HeaderBag;
use PHPUnit\Framework\TestCase;

final class HeaderBagTest extends TestCase
{
    public function testCaseInsensitiveAccessAndDuplicates(): void
    {
        $h = new HeaderBag([['Host', 'a'], ['X-A', '1'], ['x-a', '2']]);
        self::assertSame('1', $h->get('X-A'));
        self::assertSame(['1', '2'], $h->getAll('x-A'));
        self::assertTrue($h->has('HOST'));
        self::assertSame(['host', 'x-a'], $h->names());
        self::assertCount(3, $h);
    }

    public function testImmutableMutators(): void
    {
        $h = new HeaderBag([['Host', 'a'], ['X-A', '1'], ['x-a', '2']]);
        self::assertSame([['Host', 'a'], ['X-A', 'z']], $h->set('X-A', 'z')->all());
        self::assertCount(3, $h);
        self::assertCount(2, $h->remove('host'));
        self::assertCount(1, $h->withoutNames(['x-a']));
        self::assertCount(0, $h->withoutNames(['x-a', 'HOST']));
    }

    public function testRejectsInvalidNamesAndValues(): void
    {
        $h = new HeaderBag();
        foreach ([['Bad Name', 'x'], ['X', "a\r\nb"], ['X', "a\0b"], ['', 'x']] as [$n, $v]) {
            try {
                $h->add($n, $v);
                self::fail('expected rejection');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
