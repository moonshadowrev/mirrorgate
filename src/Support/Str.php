<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Support;

final class Str
{
    /**
     * True when the string contains CR, LF, NUL or any other byte below 0x20 except HT, or DEL (0x7F).
     */
    public static function hasControlChars(string $s): bool
    {
        return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) === 1;
    }

    /**
     * True when the name is a non-empty RFC 9110 token (tchar+).
     */
    public static function isHeaderToken(string $name): bool
    {
        return preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $name) === 1;
    }

    public static function stripControl(string $s): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? '';
    }

    public static function truncate(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        return strlen($s) <= $max ? $s : substr($s, 0, $max);
    }

    /**
     * Parses "10M", "512K", "2G" or a plain integer into bytes (powers of 1024).
     *
     * @throws \InvalidArgumentException
     */
    public static function parseBytes(string $v): int
    {
        $v = trim($v);
        if (preg_match('/\A(\d{1,15})([KMGkmg])?\z/', $v, $m) !== 1) {
            throw new \InvalidArgumentException('Invalid byte size.');
        }
        $n = (int) $m[1];
        $unit = strtoupper($m[2] ?? '');
        $mult = match ($unit) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };
        if ($n > intdiv(PHP_INT_MAX, $mult)) {
            throw new \InvalidArgumentException('Byte size out of range.');
        }
        return $n * $mult;
    }

    public static function constantTimeEquals(string $a, string $b): bool
    {
        return hash_equals(hash('sha256', $a, true), hash('sha256', $b, true));
    }
}
