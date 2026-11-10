<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Security\IpClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpClassifierTest extends TestCase
{
    /** @return iterable<string,array{0:string}> */
    public static function nonPublic(): iterable
    {
        $v4 = [
            '0.0.0.0', '0.255.255.255', '10.0.0.0', '10.255.255.255', '100.64.0.0', '100.127.255.255',
            '127.0.0.1', '127.255.255.255', '169.254.169.254', '169.254.0.0', '169.254.255.255',
            '172.16.0.0', '172.31.255.255', '192.0.0.0', '192.0.0.7', '192.0.0.255', '192.0.2.0', '192.0.2.255',
            '192.88.99.0', '192.88.99.255', '192.168.0.0', '192.168.255.255', '198.18.0.0', '198.19.255.255',
            '198.51.100.0', '198.51.100.255', '203.0.113.0', '203.0.113.255', '224.0.0.0', '239.255.255.255',
            '240.0.0.0', '255.255.255.255',
        ];
        foreach ($v4 as $ip) {
            yield 'v4 ' . $ip => [$ip];
        }
        $v6 = [
            '::', '::1', '::7f00:1', '::ffff:10.0.0.1', '::ffff:7f00:1', '::ffff:169.254.169.254', 'fc00::1',
            'fdff:ffff::1', 'fe80::1', 'febf::1', 'fec0::1', 'ff02::1', '100::1', '2001::1', '2001:0:ffff::1',
            '2001:10::1', '2001:1f:ffff::1', '2001:20::1', '2001:db8::1', '2001:db8:ffff:ffff::1', '2002::1',
            '2002:7f00:1::1', '2002:ffff:ffff::1', '3fff::1', '3fff:fff:ffff::1', '64:ff9b::7f00:1',
            '64:ff9b::a00:1', '64:ff9b:1::1', '0064:ff9b:1:ffff::1', '5f00::1', '4000::1', '1fff::1',
        ];
        foreach ($v6 as $ip) {
            yield 'v6 ' . $ip => [$ip];
        }
        foreach (['', 'abc', '1.2.3', '1.2.3.4.5', '256.1.1.1', '01.2.3.4', '2130706433', '0x7f.1', '0177.0.0.1', '[::1]', '1.2.3.4/8', ' 8.8.8.8', 'fe80::1%eth0'] as $bad) {
            yield 'invalid ' . $bad => [$bad];
        }
    }

    #[DataProvider('nonPublic')]
    public function testNonPublicAddressesAreRejected(string $ip): void
    {
        self::assertFalse(IpClassifier::isPublic($ip), $ip);
    }

    /** @return iterable<string,array{0:string}> */
    public static function publicAddresses(): iterable
    {
        foreach ([
            '1.1.1.1', '8.8.8.8', '9.255.255.255', '11.0.0.0', '100.63.255.255', '100.128.0.0', '126.255.255.255',
            '128.0.0.0', '169.253.255.255', '169.255.0.0', '172.15.255.255', '172.32.0.0', '192.0.1.0', '192.0.3.0',
            '192.88.98.255', '192.88.100.0', '192.167.255.255', '192.169.0.0', '198.17.255.255', '198.20.0.0',
            '198.51.99.255', '198.51.101.0', '203.0.112.255', '203.0.114.0', '223.255.255.255', '::ffff:8.8.8.8',
            '2606:4700:4700::1111', '2001:4860:4860::8888', '2001:1::1', '2001:200::1', '2001:db7::1', '2001:db9::1',
            '2003::1', '2a00:1450:4001::1', '3ffe::1', '3fff:1000::1', '64:ff9b::808:808',
        ] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[DataProvider('publicAddresses')]
    public function testPublicAddressesAreAccepted(string $ip): void
    {
        self::assertTrue(IpClassifier::isPublic($ip), $ip);
    }

    public function testCaseInsensitiveIpv6(): void
    {
        self::assertTrue(IpClassifier::isPublic('2606:4700:4700::1111'));
        self::assertTrue(IpClassifier::isPublic('2606:4700:4700::1111'));
        self::assertFalse(IpClassifier::isPublic('FE80::1'));
    }
}
