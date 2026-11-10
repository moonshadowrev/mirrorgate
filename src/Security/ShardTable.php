<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Support\FileUtil;

/**
 * Internal helper for the shard files of FileRateLimiter and ConcurrencyLimiter: a small
 * tab separated text table that is read and rewritten as a whole under flock.
 *
 * @internal
 */
final class ShardTable
{
    private const MAX_READ_BYTES = 262144;

    /**
     * Opens a shard file for read and write, creating it when missing. Creation goes through
     * FileUtil::openLock (a single fopen 'c+b'), so two workers racing on a new shard always end
     * up locking the same inode.
     *
     * @return resource|null
     */
    public static function open(string $file)
    {
        $fh = FileUtil::openLock($file);
        return is_resource($fh) ? $fh : null;
    }

    /**
     * Reads all well formed rows. Each row is a list of tab separated fields.
     *
     * @param resource $fh
     * @return list<list<string>>
     */
    public static function read($fh, int $expectedFields): array
    {
        rewind($fh);
        $raw = stream_get_contents($fh, self::MAX_READ_BYTES);
        if ($raw === false || $raw === '') {
            return [];
        }
        $rows = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = explode("\t", $line);
            if (count($fields) !== $expectedFields) {
                continue;
            }
            $rows[] = $fields;
        }
        return $rows;
    }

    /**
     * Replaces the file content with the given rows.
     *
     * @param resource $fh
     * @param list<list<string>> $rows
     */
    public static function write($fh, array $rows): void
    {
        $out = '';
        foreach ($rows as $row) {
            $out .= implode("\t", $row) . "\n";
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $out);
        fflush($fh);
    }
}
