<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Http\HeaderBag;
use MirrorGate\Log\NullLogger;
use MirrorGate\Security\HeaderSanitizer;
use PHPUnit\Framework\TestCase;

final class HeaderSanitizerTest extends TestCase
{
    public function testRemovesFixedHopByHopSet(): void
    {
        $pairs = [];
        foreach (HeaderSanitizer::HOP_BY_HOP as $name) {
            $pairs[] = [$name, 'x'];
        }
        $pairs[] = ['X-Keep', '1'];
        $out = HeaderSanitizer::removeHopByHop(HeaderBag::fromPairs($pairs));
        self::assertSame([['X-Keep', '1']], $out->all());
    }

    public function testRemovesConnectionNamedFields(): void
    {
        $out = HeaderSanitizer::removeHopByHop(HeaderBag::fromPairs([
            ['Connection', 'close, X-Foo , bad token!'],
            ['X-Foo', 'secret'],
            ['X-Bar', 'keep'],
        ]));
        self::assertSame([['X-Bar', 'keep']], $out->all());
    }

    public function testConnectionCannotStripFramingOrIdentityHeaders(): void
    {
        $out = HeaderSanitizer::removeHopByHop(HeaderBag::fromPairs([
            ['Connection', 'Content-Length, Content-Type, Host, Set-Cookie'],
            ['Content-Length', '5'], ['Content-Type', 'text/plain'], ['Host', 'a'], ['Set-Cookie', 'a=b'],
        ]));
        self::assertSame(4, count($out));
    }

    public function testDropInvalidPairs(): void
    {
        $out = HeaderSanitizer::dropInvalid([
            ['Good', 'ok'],
            ["Bad\r\nName", 'x'],
            ['Bad Name', 'x'],
            ['X-Inject', "a\r\nSet-Cookie: pwn=1"],
            ['X-Nul', "a\0b"],
            ['X-Ctl', "a\x01b"],
            ['', 'x'],
            ['X-Tab', "a\tb"],
            ['Set-Cookie', 'a=1'],
            ['Set-Cookie', 'b=2'],
        ], new NullLogger());
        self::assertSame([['Good', 'ok'], ['X-Tab', "a\tb"], ['Set-Cookie', 'a=1'], ['Set-Cookie', 'b=2']], $out->all());
    }
}
