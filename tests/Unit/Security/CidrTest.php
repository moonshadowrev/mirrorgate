<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Security\Cidr;
use MirrorGate\Security\CidrList;
use PHPUnit\Framework\TestCase;

final class CidrTest extends TestCase
{
    public function testValidity(): void
    {
        foreach (['10.0.0.0/8', '10.0.0.1', '::/0', '2001:db8::/32', '::1', '192.168.1.1/32', '0.0.0.0/0', '::ffff:10.0.0.0/104'] as $ok) {
            self::assertTrue(Cidr::isValid($ok), $ok);
        }
        foreach (['', '10.0.0.0/33', '10.0.0.0/-1', '10.0.0.0/8/8', 'x', '10.0.0/8', '::/129', '1.2.3.4/a', '01.2.3.4/8', '[::1]/128'] as $bad) {
            self::assertFalse(Cidr::isValid($bad), $bad);
        }
    }

    public function testMatchesIpv4(): void
    {
        self::assertTrue(Cidr::matches('10.1.2.3', '10.0.0.0/8'));
        self::assertFalse(Cidr::matches('11.0.0.1', '10.0.0.0/8'));
        self::assertTrue(Cidr::matches('192.168.1.1', '192.168.1.1'));
        self::assertFalse(Cidr::matches('192.168.1.2', '192.168.1.1'));
        self::assertTrue(Cidr::matches('172.31.255.255', '172.16.0.0/12'));
        self::assertFalse(Cidr::matches('172.32.0.0', '172.16.0.0/12'));
        self::assertTrue(Cidr::matches('8.8.8.8', '0.0.0.0/0'));
    }

    public function testMatchesIpv6AndMapped(): void
    {
        self::assertTrue(Cidr::matches('2001:db8::5', '2001:db8::/32'));
        self::assertFalse(Cidr::matches('2001:db9::5', '2001:db8::/32'));
        self::assertTrue(Cidr::matches('::ffff:10.1.2.3', '10.0.0.0/8'));
        self::assertTrue(Cidr::matches('10.1.2.3', '::ffff:10.0.0.0/104'));
        self::assertFalse(Cidr::matches('2001:db8::1', '10.0.0.0/8'));
        self::assertFalse(Cidr::matches('not-an-ip', '10.0.0.0/8'));
        self::assertFalse(Cidr::matches('10.0.0.1', 'garbage'));
    }

    public function testListContains(): void
    {
        $list = new CidrList(['10.0.0.0/8', '2001:db8::/32', 'invalid', '203.0.113.7']);
        self::assertTrue($list->contains('10.9.9.9'));
        self::assertTrue($list->contains('2001:db8:1::1'));
        self::assertTrue($list->contains('203.0.113.7'));
        self::assertFalse($list->contains('203.0.113.8'));
        self::assertFalse($list->contains('junk'));
        self::assertFalse($list->isEmpty());
        self::assertTrue((new CidrList([]))->isEmpty());
        self::assertFalse((new CidrList([]))->contains('1.1.1.1'));
        self::assertTrue((new CidrList(['0.0.0.0/0']))->contains('1.1.1.1'));
    }

    public function testNormalizeIp(): void
    {
        self::assertSame('1.2.3.4', Cidr::normalizeIp('::ffff:1.2.3.4'));
        self::assertSame('2001:db8::1', Cidr::normalizeIp('2001:0DB8:0:0:0:0:0:1'));
        self::assertNull(Cidr::normalizeIp('1.2.3.4:80'));
        self::assertNull(Cidr::normalizeIp('01.2.3.4'));
    }
}
