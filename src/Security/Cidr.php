<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

/**
 * Pure IPv4/IPv6 CIDR helper.
 *
 * Every address is normalised to a 16 byte packed IPv6 form. IPv4 addresses (and IPv4-mapped IPv6
 * addresses) are stored as ::ffff:a.b.c.d, so an IPv4 prefix length p is represented as p + 96.
 * This makes "10.0.0.0/8" and "::ffff:10.0.0.0/104" identical.
 */
final class Cidr
{
    private const MAPPED_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    private const IPV4_PATTERN = '/^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])$/D';

    /**
     * Parses "ip" or "ip/prefix".
     *
     * @return array{bin:string, prefix:int, v4:bool} bin = masked 16 byte network address, prefix = length in the 128 bit space
     * @throws \InvalidArgumentException
     */
    public static function parse(string $cidr): array
    {
        $cidr = trim($cidr);
        if ($cidr === '' || strlen($cidr) > 64 || substr_count($cidr, '/') > 1) {
            throw new \InvalidArgumentException('Invalid CIDR.');
        }
        $parts = explode('/', $cidr, 2);
        $ipPart = $parts[0];
        $bin = self::packIp($ipPart);
        if ($bin === null) {
            throw new \InvalidArgumentException('Invalid CIDR address.');
        }
        $isDottedV4 = preg_match(self::IPV4_PATTERN, $ipPart) === 1;
        if (count($parts) === 2) {
            if (preg_match('/^[0-9]{1,3}$/D', $parts[1]) !== 1) {
                throw new \InvalidArgumentException('Invalid CIDR prefix.');
            }
            $prefix = (int) $parts[1];
            if ($isDottedV4) {
                if ($prefix > 32) {
                    throw new \InvalidArgumentException('Invalid CIDR prefix.');
                }
                $prefix += 96;
            } elseif ($prefix > 128) {
                throw new \InvalidArgumentException('Invalid CIDR prefix.');
            }
        } else {
            $prefix = 128;
        }
        return [
            'bin' => $bin & self::mask($prefix),
            'prefix' => $prefix,
            'v4' => $isDottedV4 || ($prefix >= 96 && self::isMapped($bin)),
        ];
    }

    public static function matches(string $ip, string $cidr): bool
    {
        $bin = self::packIp($ip);
        if ($bin === null) {
            return false;
        }
        try {
            $net = self::parse($cidr);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return ($bin & self::mask($net['prefix'])) === $net['bin'];
    }

    public static function isValid(string $cidr): bool
    {
        try {
            self::parse($cidr);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return true;
    }

    /**
     * Packs a textual IP into 16 bytes (IPv4 becomes IPv4-mapped). Returns null for anything that is
     * not a strict IPv4 dotted quad (no leading zeros) or a valid IPv6 literal without zone id or brackets.
     */
    public static function packIp(string $ip): ?string
    {
        if ($ip === '' || strlen($ip) > 45) {
            return null;
        }
        if (preg_match(self::IPV4_PATTERN, $ip) === 1) {
            $b = inet_pton($ip);
            if ($b === false || strlen($b) !== 4) {
                return null;
            }
            return self::MAPPED_PREFIX . $b;
        }
        if (!str_contains($ip, ':') || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        $b = inet_pton($ip);
        if ($b === false || strlen($b) !== 16) {
            return null;
        }
        return $b;
    }

    /** True when the 16 byte packed address is IPv4-mapped (::ffff:0:0/96). */
    public static function isMapped(string $bin): bool
    {
        return strlen($bin) === 16 && substr($bin, 0, 12) === self::MAPPED_PREFIX;
    }

    /**
     * Canonical text for a valid IP: IPv4-mapped IPv6 becomes dotted IPv4, other IPv6 is compressed lowercase.
     * Returns null for invalid input.
     */
    public static function normalizeIp(string $ip): ?string
    {
        $bin = self::packIp($ip);
        if ($bin === null) {
            return null;
        }
        $text = inet_ntop(self::isMapped($bin) ? substr($bin, 12, 4) : $bin);
        return $text === false ? null : $text;
    }

    /** 16 byte network mask for a prefix length in the 128 bit space. */
    private static function mask(int $bits): string
    {
        $bits = max(0, min(128, $bits));
        $full = intdiv($bits, 8);
        $rest = $bits % 8;
        $mask = str_repeat("\xff", $full);
        if ($rest > 0) {
            $mask .= chr((0xFF << (8 - $rest)) & 0xFF);
        }
        return str_pad($mask, 16, "\x00");
    }
}
