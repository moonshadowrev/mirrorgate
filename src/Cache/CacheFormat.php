<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

use MirrorGate\Support\Json;

/**
 * On-disk entry format, version 1:
 *
 *   MGC1\n
 *   <decimal metadata length>\n
 *   <JSON metadata>
 *   <raw stored body bytes>
 *
 * Files are only ever parsed with json_decode and string functions. They are never unserialized,
 * included or executed.
 */
final class CacheFormat
{
    public const MAGIC = "MGC1\n";
    public const VERSION = 1;
    public const MAX_META_BYTES = 65536;

    /**
     * @param array<string,mixed> $meta
     * @throws \JsonException when the metadata cannot be encoded
     * @throws \LengthException when the metadata exceeds MAX_META_BYTES
     */
    public static function encode(array $meta, string $storedBody): string
    {
        $meta['v'] = self::VERSION;
        $json = Json::encode($meta);
        $length = strlen($json);
        if ($length > self::MAX_META_BYTES) {
            throw new \LengthException('Cache metadata too large.');
        }

        return self::MAGIC . $length . "\n" . $json . $storedBody;
    }

    /**
     * Reads and validates magic, length line and JSON metadata from a handle positioned at offset 0.
     * The handle is left positioned at the first body byte.
     *
     * @param resource $handle
     * @return array{meta: array<string,mixed>, offset: int}|null null on any format violation
     */
    public static function readHeader($handle): ?array
    {
        $magic = fread($handle, strlen(self::MAGIC));
        if ($magic !== self::MAGIC) {
            return null;
        }
        $line = fgets($handle, 12);
        if ($line === false || preg_match('/^(\d{1,6})\n$/D', $line, $m) !== 1) {
            return null;
        }
        $length = (int) $m[1];
        if ($length < 2 || $length > self::MAX_META_BYTES) {
            return null;
        }
        $json = fread($handle, $length);
        if (!is_string($json) || strlen($json) !== $length) {
            return null;
        }
        try {
            $meta = Json::decode($json, 8);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($meta) || ($meta['v'] ?? null) !== self::VERSION) {
            return null;
        }
        /** @var array<string,mixed> $meta */

        return ['meta' => $meta, 'offset' => strlen(self::MAGIC) + strlen($line) + $length];
    }

    /** CRC32 (hex) of the stored body bytes. */
    public static function crc(string $storedBody): string
    {
        return hash('crc32b', $storedBody);
    }
}
