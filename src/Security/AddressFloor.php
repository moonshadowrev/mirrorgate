<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

/**
 * Ranges that are never valid connect targets, even when private targets are explicitly allowed:
 * the unspecified address, link-local (cloud metadata lives at 169.254.169.254) and the AWS IPv6
 * metadata address. IPv4 addresses embedded in IPv4-mapped, NAT64 (64:ff9b::/96), 6to4 (2002::/16)
 * and IPv4-compatible (::/96) IPv6 forms are judged by the embedded address.
 */
final class AddressFloor
{
    private const MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";
    private const NAT64_PREFIX = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";
    private const COMPAT_PREFIX = "\0\0\0\0\0\0\0\0\0\0\0\0";

    public static function isBlocked(string $ip): bool
    {
        $bin = @\inet_pton(\trim($ip, '[]'));
        if ($bin === false) {
            return true;
        }

        if (\strlen($bin) === 16) {
            if ($bin === \str_repeat("\0", 16)) {
                return true;
            }
            $prefix = \substr($bin, 0, 12);
            if ($prefix === self::MAPPED_PREFIX || $prefix === self::NAT64_PREFIX || $prefix === self::COMPAT_PREFIX) {
                return self::isBlockedV4(\substr($bin, 12, 4));
            }
            // 6to4: the embedded IPv4 address sits in bytes 2-5.
            if (\ord($bin[0]) === 0x20 && \ord($bin[1]) === 0x02 && self::isBlockedV4(\substr($bin, 2, 4))) {
                return true;
            }
            // fe80::/10 link-local
            if (\ord($bin[0]) === 0xfe && (\ord($bin[1]) & 0xc0) === 0x80) {
                return true;
            }
            // fd00:ec2::254 (AWS IMDS over IPv6)
            $imds = \inet_pton('fd00:ec2::254');

            return $imds !== false && $bin === $imds;
        }

        return self::isBlockedV4($bin);
    }

    private static function isBlockedV4(string $bin): bool
    {
        if (\strlen($bin) !== 4) {
            return true;
        }
        $a = \ord($bin[0]);
        $b = \ord($bin[1]);

        return $a === 0 || ($a === 169 && $b === 254);
    }
}
