<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

/**
 * Turns the raw request target into a canonical path plus the untouched query string.
 *
 * Rules (SEC-10, SEC-11): reject control bytes, raw spaces, backslashes, encoded NUL/CR/LF/backslash,
 * absolute-form targets and fragments; collapse a leading run of slashes; decode unreserved
 * characters once; keep %2F encoded; uppercase percent-encoding hex; remove dot segments after
 * decoding; never decode twice.
 */
final class PathNormalizer
{
    /**
     * @throws BadRequestException
     */
    public static function normalize(string $rawTarget): NormalizedTarget
    {
        if ($rawTarget === '') {
            throw new BadRequestException('Empty request target.');
        }
        if (preg_match('/[\x00-\x20\x7F]/', $rawTarget) === 1) {
            throw new BadRequestException('Control characters or spaces in the request target.');
        }
        if (str_contains($rawTarget, '\\')) {
            throw new BadRequestException('Backslash in the request target.');
        }
        if (str_contains($rawTarget, '#')) {
            throw new BadRequestException('Fragment in the request target.');
        }
        if ($rawTarget[0] !== '/') {
            // Absolute-form ("http://host/..."), authority-form, asterisk-form and anything else.
            throw new BadRequestException('Only origin-form request targets are accepted.');
        }

        $qPos = strpos($rawTarget, '?');
        if ($qPos === false) {
            $rawPath = $rawTarget;
            $query = '';
        } else {
            $rawPath = substr($rawTarget, 0, $qPos);
            $query = substr($rawTarget, $qPos + 1);
        }

        // Collapse a leading run of slashes so "//evil.com" can never become a protocol-relative reference.
        $rawPath = '/' . ltrim($rawPath, '/');

        $segments = explode('/', substr($rawPath, 1));
        $stack = [];
        $trailingSlash = false;
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            $canonical = self::canonicalSegment($segment);
            if ($canonical === '.') {
                $trailingSlash = ($i === $last);
                continue;
            }
            if ($canonical === '..') {
                array_pop($stack);
                $trailingSlash = ($i === $last);
                continue;
            }
            $trailingSlash = false;
            $stack[] = $canonical;
        }

        $path = '/' . implode('/', $stack);
        if ($trailingSlash && $path !== '/') {
            $path .= '/';
        }
        return new NormalizedTarget($path, $query);
    }

    /**
     * @throws BadRequestException
     */
    private static function canonicalSegment(string $segment): string
    {
        if ($segment === '') {
            return '';
        }
        $out = '';
        $len = strlen($segment);
        for ($i = 0; $i < $len; $i++) {
            $c = $segment[$i];
            if ($c === '%') {
                $hex = substr($segment, $i + 1, 2);
                if (strlen($hex) !== 2 || !ctype_xdigit($hex)) {
                    throw new BadRequestException('Invalid percent-encoding.');
                }
                $byte = (int) hexdec($hex);
                if ($byte <= 0x1F || $byte === 0x7F || $byte === 0x5C) {
                    throw new BadRequestException('Forbidden encoded byte.');
                }
                if (self::isUnreserved($byte)) {
                    $out .= chr($byte);
                } else {
                    $out .= '%' . strtoupper($hex);
                }
                $i += 2;
                continue;
            }
            $o = ord($c);
            if (self::isUnreserved($o) || strpos('!$&\'()*+,;=:@', $c) !== false) {
                $out .= $c;
            } else {
                // Bytes outside the pchar set (including raw UTF-8) are encoded canonically.
                $out .= sprintf('%%%02X', $o);
            }
        }
        return $out;
    }

    private static function isUnreserved(int $byte): bool
    {
        return ($byte >= 0x30 && $byte <= 0x39)
            || ($byte >= 0x41 && $byte <= 0x5A)
            || ($byte >= 0x61 && $byte <= 0x7A)
            || $byte === 0x2D || $byte === 0x2E || $byte === 0x5F || $byte === 0x7E;
    }
}
