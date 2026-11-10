<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

/**
 * Decides whether an address is globally routable unicast. Used as the SSRF gate for the origin
 * and for daemon targets. Fails closed: anything unparsable or unusual is not public.
 */
final class IpClassifier
{
    /** @var list<array{0:string,1:int}> blocked IPv4 ranges as [dotted network, prefix] */
    private const V4_BLOCKED = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
        ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    private const NAT64_PREFIX = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";

    /**
     * True only for globally routable unicast addresses. Both the explicit lists and
     * filter_var(NO_PRIV_RANGE | NO_RES_RANGE) must accept the address.
     */
    public static function isPublic(string $ip): bool
    {
        $bin = Cidr::packIp($ip);
        if ($bin === null) {
            return false;
        }
        if (Cidr::isMapped($bin)) {
            return self::isPublicV4(substr($bin, 12, 4));
        }
        if (substr($bin, 0, 12) === self::NAT64_PREFIX) {
            return self::isPublicV4(substr($bin, 12, 4));
        }
        return self::isPublicV6($bin);
    }

    private static function isPublicV4(string $b4): bool
    {
        $unpacked = unpack('N', $b4);
        if ($unpacked === false) {
            return false;
        }
        $n = (int) $unpacked[1];
        foreach (self::V4_BLOCKED as [$net, $prefix]) {
            $base = ip2long($net);
            if ($base === false) {
                continue;
            }
            $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
            if (($n & $mask) === ($base & $mask)) {
                return false;
            }
        }
        $text = inet_ntop($b4);
        if ($text === false) {
            return false;
        }
        return filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function isPublicV6(string $b): bool
    {
        $b0 = ord($b[0]);
        $b1 = ord($b[1]);
        $b2 = ord($b[2]);
        $b3 = ord($b[3]);
        // Allowlist: global unicast 2000::/3 only.
        if (($b0 & 0xE0) !== 0x20) {
            return false;
        }
        if ($b0 === 0x20 && $b1 === 0x01) {
            // 2001::/32 Teredo
            if ($b2 === 0x00 && $b3 === 0x00) {
                return false;
            }
            // 2001:10::/28 ORCHID, 2001:20::/28 ORCHIDv2
            if ($b2 === 0x00 && (($b3 & 0xF0) === 0x10 || ($b3 & 0xF0) === 0x20)) {
                return false;
            }
            // 2001:2::/48 benchmarking
            if ($b2 === 0x00 && $b3 === 0x02 && $b[4] === "\x00" && $b[5] === "\x00") {
                return false;
            }
            // 2001:db8::/32 documentation
            if ($b2 === 0x0D && $b3 === 0xB8) {
                return false;
            }
        }
        // 2002::/16 6to4 (blocked outright)
        if ($b0 === 0x20 && $b1 === 0x02) {
            return false;
        }
        // 3fff::/20 documentation
        if ($b0 === 0x3F && $b1 === 0xFF && ($b2 & 0xF0) === 0x00) {
            return false;
        }
        $text = inet_ntop($b);
        if ($text === false) {
            return false;
        }
        return filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
