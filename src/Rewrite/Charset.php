<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Character set detection for textual response bodies.
 *
 * The rewriter works on bytes, which is safe for UTF-8 and for every charset that keeps ASCII
 * unchanged. Wide encodings (UTF-16, UTF-32, UCS-2), UTF-7 and escape-sequence encodings
 * (ISO-2022, HZ) are reported as incompatible and the body is then passed through untouched.
 */
final class Charset
{
    /**
     * Detection order: Content-Type charset parameter, byte order mark, XML declaration,
     * `<meta>` declaration within the first 1024 bytes, default `utf-8`.
     * Returns a lowercase charset label.
     */
    public static function detect(?string $contentType, string $body): string
    {
        $fromHeader = self::fromContentType($contentType);
        if ($fromHeader !== null) {
            return $fromHeader;
        }

        $bom = self::fromBom($body);
        if ($bom !== null) {
            return $bom;
        }

        $head = substr($body, 0, 1024);

        if (str_starts_with($head, '<?xml')
            && preg_match('~^<\?xml[^>]*\sencoding\s*=\s*["\']([A-Za-z0-9_.:\-]{1,40})["\']~', $head, $m) === 1) {
            return self::normalize($m[1]);
        }

        if (preg_match('~<meta[^>]+charset\s*=\s*["\']?\s*([A-Za-z0-9_.:\-]{1,40})~i', $head, $m) === 1) {
            return self::normalize($m[1]);
        }

        return 'utf-8';
    }

    /** Charset label from the `charset` parameter of a Content-Type value, or null. */
    public static function fromContentType(?string $contentType): ?string
    {
        if ($contentType === null || $contentType === '') {
            return null;
        }
        if (preg_match('~;\s*charset\s*=\s*(?:"([^"]{1,40})"|\'([^\']{1,40})\'|([A-Za-z0-9_.:\-]{1,40}))~i', $contentType, $m) !== 1) {
            return null;
        }
        $label = $m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
        $label = self::normalize($label);
        return $label === '' ? null : $label;
    }

    /** True when ASCII bytes keep their value in the charset, so host names can be replaced bytewise. */
    public static function isAsciiCompatible(string $charset): bool
    {
        $charset = self::normalize($charset);
        if ($charset === '') {
            return true;
        }
        return preg_match('~^(?:utf-?(?:16|32|7)|ucs-?[24]|unicode|csunicode|iso-2022|csiso2022|hz-|cesu|utf-?1$|ibm0?37|cp0?37|ebcdic|x-?utf-?(?:16|32))~', $charset) !== 1;
    }

    private static function fromBom(string $body): ?string
    {
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            return 'utf-8';
        }
        if (str_starts_with($body, "\xFF\xFE\x00\x00")) {
            return 'utf-32le';
        }
        if (str_starts_with($body, "\x00\x00\xFE\xFF")) {
            return 'utf-32be';
        }
        if (str_starts_with($body, "\xFF\xFE")) {
            return 'utf-16le';
        }
        if (str_starts_with($body, "\xFE\xFF")) {
            return 'utf-16be';
        }
        return null;
    }

    private static function normalize(string $label): string
    {
        $label = strtolower(trim($label));
        return $label === 'utf8' ? 'utf-8' : $label;
    }
}
