<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Support;

/**
 * Pure-PHP RFC 3492 Punycode encoder, used for IDN hosts when ext-intl is not available.
 */
final class Punycode
{
    private const BASE = 36;
    private const TMIN = 1;
    private const TMAX = 26;
    private const SKEW = 38;
    private const DAMP = 700;
    private const INITIAL_BIAS = 72;
    private const INITIAL_N = 128;

    /**
     * @throws \InvalidArgumentException
     */
    public static function encodeLabel(string $utf8Label): string
    {
        if ($utf8Label === '') {
            throw new \InvalidArgumentException('Empty label.');
        }
        if (!mb_check_encoding($utf8Label, 'UTF-8')) {
            throw new \InvalidArgumentException('Label is not valid UTF-8.');
        }
        $label = mb_strtolower($utf8Label, 'UTF-8');
        if (preg_match('/\A[\x00-\x7F]+\z/', $label) === 1) {
            return $label;
        }
        if (preg_match('/\A[\p{L}\p{N}\p{M}\-]+\z/u', $label) !== 1) {
            throw new \InvalidArgumentException('Label contains characters that are not allowed.');
        }

        $chars = mb_str_split($label, 1, 'UTF-8');
        $codePoints = [];
        foreach ($chars as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false) {
                throw new \InvalidArgumentException('Invalid code point.');
            }
            $codePoints[] = $cp;
        }

        $output = '';
        foreach ($codePoints as $cp) {
            if ($cp < 128) {
                $output .= chr($cp);
            }
        }
        $basicCount = strlen($output);
        $handled = $basicCount;
        if ($basicCount > 0) {
            $output .= '-';
        }

        $total = count($codePoints);
        $n = self::INITIAL_N;
        $delta = 0;
        $bias = self::INITIAL_BIAS;

        while ($handled < $total) {
            $m = PHP_INT_MAX;
            foreach ($codePoints as $cp) {
                if ($cp >= $n && $cp < $m) {
                    $m = $cp;
                }
            }
            $delta += ($m - $n) * ($handled + 1);
            $n = $m;
            foreach ($codePoints as $cp) {
                if ($cp < $n) {
                    $delta++;
                }
                if ($cp === $n) {
                    $q = $delta;
                    for ($k = self::BASE;; $k += self::BASE) {
                        $t = $k <= $bias ? self::TMIN : ($k >= $bias + self::TMAX ? self::TMAX : $k - $bias);
                        if ($q < $t) {
                            break;
                        }
                        $output .= self::digit($t + (($q - $t) % (self::BASE - $t)));
                        $q = intdiv($q - $t, self::BASE - $t);
                    }
                    $output .= self::digit($q);
                    $bias = self::adapt($delta, $handled + 1, $handled === $basicCount);
                    $delta = 0;
                    $handled++;
                }
            }
            $delta++;
            $n++;
        }

        $result = 'xn--' . $output;
        if (strlen($result) > 63) {
            throw new \InvalidArgumentException('Label is too long.');
        }
        return $result;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function toAscii(string $host): string
    {
        $labels = explode('.', $host);
        $out = [];
        foreach ($labels as $label) {
            $out[] = self::encodeLabel($label);
        }
        return implode('.', $out);
    }

    private static function digit(int $d): string
    {
        // 0..25 => a..z, 26..35 => 0..9
        return $d < 26 ? chr(97 + $d) : chr(22 + $d);
    }

    private static function adapt(int $delta, int $numPoints, bool $firstTime): int
    {
        $delta = $firstTime ? intdiv($delta, self::DAMP) : intdiv($delta, 2);
        $delta += intdiv($delta, $numPoints);
        $k = 0;
        while ($delta > intdiv((self::BASE - self::TMIN) * self::TMAX, 2)) {
            $delta = intdiv($delta, self::BASE - self::TMIN);
            $k += self::BASE;
        }
        return $k + intdiv((self::BASE - self::TMIN + 1) * $delta, $delta + self::SKEW);
    }
}
