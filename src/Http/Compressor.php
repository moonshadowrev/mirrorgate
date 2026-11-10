<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

final class Compressor
{
    /**
     * Decodes a gzip/deflate body with a hard cap on the inflated size.
     *
     * @return string|null null on bomb (> $maxBytes inflated), corrupt data, or unsupported/unavailable encoding
     */
    public static function decode(string $body, string $encoding, int $maxBytes): ?string
    {
        $encoding = strtolower(trim($encoding));
        if ($encoding === '' || $encoding === 'identity') {
            return strlen($body) <= $maxBytes ? $body : null;
        }
        if ($encoding === 'br') {
            if (!function_exists('brotli_uncompress')) {
                return null;
            }
            $out = @brotli_uncompress($body, $maxBytes + 1);
            if (!is_string($out) || strlen($out) > $maxBytes) {
                return null;
            }
            return $out;
        }
        if ($encoding === 'gzip' || $encoding === 'x-gzip') {
            return self::inflate($body, ZLIB_ENCODING_GZIP, $maxBytes);
        }
        if ($encoding === 'deflate') {
            if (!function_exists('inflate_init')) {
                return null;
            }
            $out = self::inflate($body, ZLIB_ENCODING_DEFLATE, $maxBytes);
            if ($out === null) {
                // Many servers send raw deflate streams under the "deflate" label.
                $out = self::inflate($body, ZLIB_ENCODING_RAW, $maxBytes);
            }
            return $out;
        }
        return null;
    }

    private static function inflate(string $body, int $mode, int $maxBytes): ?string
    {
        if (!function_exists('inflate_init') || !function_exists('inflate_add')) {
            return null;
        }
        $ctx = @inflate_init($mode);
        if ($ctx === false) {
            return null;
        }
        $out = '';
        $len = strlen($body);
        if ($len === 0) {
            return '';
        }
        $chunk = 4096;
        for ($offset = 0; $offset < $len; $offset += $chunk) {
            $piece = substr($body, $offset, $chunk);
            $last = ($offset + $chunk) >= $len;
            $data = @inflate_add($ctx, $piece, $last ? ZLIB_FINISH : ZLIB_NO_FLUSH);
            if ($data === false) {
                return null;
            }
            $out .= $data;
            if (strlen($out) > $maxBytes) {
                return null;
            }
        }
        if (inflate_get_status($ctx) !== ZLIB_STREAM_END) {
            return null;
        }
        return $out;
    }

    /**
     * @return string exactly 'gzip' or 'identity'
     */
    public static function negotiate(?string $acceptEncoding): string
    {
        if ($acceptEncoding === null || $acceptEncoding === '' || !self::isAvailable()) {
            return 'identity';
        }
        $gzipQ = null;
        $starQ = null;
        foreach (explode(',', $acceptEncoding) as $part) {
            $bits = explode(';', $part);
            $token = strtolower(trim($bits[0]));
            $q = 1.0;
            for ($i = 1, $n = count($bits); $i < $n; $i++) {
                if (preg_match('/\A\s*q\s*=\s*([0-9]*\.?[0-9]+)\s*\z/i', $bits[$i], $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            if ($token === 'gzip' || $token === 'x-gzip') {
                $gzipQ = $q;
            } elseif ($token === '*') {
                $starQ = $q;
            }
        }
        $effective = $gzipQ ?? $starQ;
        return ($effective !== null && $effective > 0.0) ? 'gzip' : 'identity';
    }

    /**
     * @return string|null null when ext-zlib is missing
     */
    public static function gzip(string $body): ?string
    {
        if (!function_exists('gzencode')) {
            return null;
        }
        $out = @gzencode($body, 6);
        return is_string($out) ? $out : null;
    }

    public static function isAvailable(): bool
    {
        return function_exists('gzencode') && function_exists('inflate_init');
    }
}
