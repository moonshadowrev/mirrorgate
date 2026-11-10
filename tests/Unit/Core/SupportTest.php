<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Core;

use MirrorGate\Support\Hostname;
use MirrorGate\Support\Punycode;
use MirrorGate\Support\Str;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testPunycodeVectors(): void
    {
        self::assertSame('xn--bcher-kva', Punycode::encodeLabel('bücher'));
        self::assertSame('xn--mgbug7c06b', Punycode::encodeLabel('فارسی'));
        self::assertSame('xn--wgv71a119e', Punycode::encodeLabel('日本語'));
        self::assertSame('xn--mnchen-3ya.de', Punycode::toAscii('münchen.de'));
        self::assertSame('plain', Punycode::encodeLabel('PLAIN'));
    }

    public function testHostnameHelpers(): void
    {
        self::assertSame('overlord.team', Hostname::normalize('Overlord.TEAM.'));
        self::assertTrue(Hostname::isIpLiteral('2130706433'));
        self::assertTrue(Hostname::isIpLiteral('0x7f.1'));
        self::assertTrue(Hostname::isIpLiteral('0177.0.0.1'));
        self::assertTrue(Hostname::isIpLiteral('[::1]'));
        self::assertFalse(Hostname::isIpLiteral('example.com'));
        self::assertTrue(Hostname::isValid('a-b.example.com'));
        self::assertFalse(Hostname::isValid('a..b'));
        self::assertFalse(Hostname::isValid('-a.com'));
        self::assertTrue(Hostname::isSameOrSubdomain('api.overlord.team', 'overlord.team'));
        self::assertFalse(Hostname::isSameOrSubdomain('evil-overlord.team', 'overlord.team'));
        self::assertSame(['host' => '::1', 'port' => 8080], Hostname::split('[::1]:8080'));
        foreach (['a@b', 'a\\b', 'a b', '::1', 'h:99999'] as $bad) {
            try {
                Hostname::split($bad);
                self::fail('expected rejection of ' . $bad);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testStr(): void
    {
        self::assertSame(10485760, Str::parseBytes('10M'));
        self::assertTrue(Str::hasControlChars("a\r\nb"));
        self::assertFalse(Str::hasControlChars("a\tb"));
        self::assertTrue(Str::isHeaderToken('X-Foo'));
        self::assertFalse(Str::isHeaderToken('X Foo'));
        self::assertTrue(Str::constantTimeEquals('abc', 'abc'));
        self::assertFalse(Str::constantTimeEquals('abc', 'abd'));
    }
}
