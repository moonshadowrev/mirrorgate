<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Support\Clock;
use MirrorGate\Support\FileUtil;

/**
 * Fixed window rate limiter backed by a fixed set of 4096 shard files `<dir>/<hhh>.rl`.
 *
 * Each shard is a text table of `<bucketHash16>\t<windowStart>\t<count>` lines, rewritten under
 * flock. Expired lines are pruned on every write and a shard keeps at most 256 lines (oldest
 * evicted), so inode and disk use are bounded no matter how many client addresses appear. Callers
 * pass already hashed keys (RateLimiterFactory::keyFor); keys are hashed again here so that no key
 * material ever forms a path. Storage problems fail open for traffic limiting (availability wins),
 * are reported once through error_log and are visible to the self-check through probe().
 */
final class FileRateLimiter implements RateLimiterInterface, RateLimitPeekInterface
{
    private const MAX_LINES = 256;
    /** Rows older than this are dropped; the longest supported window is far below it. */
    private const PRUNE_AFTER = 86400;
    private const FIELDS = 3;

    private bool $dirReady = false;
    private static bool $warned = false;

    public function __construct(private readonly string $dir, private readonly Clock $clock)
    {
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $limit = max(0, $limit);
        $window = max(1, $windowSeconds);
        $now = $this->clock->time();
        $start = $now - ($now % $window);
        [$file, $id] = $this->locate($key);
        $fh = $this->openShard($file);
        if ($fh === null || !flock($fh, LOCK_EX)) {
            if ($fh !== null) {
                fclose($fh);
            }
            self::warnOnce();
            return new RateLimitResult(true, $limit, $limit, 0);
        }
        $count = 1;
        try {
            $rows = $this->liveRows($fh, $now);
            $found = false;
            foreach ($rows as $i => $row) {
                if ($row[0] === $id) {
                    $prevStart = (int) $row[1];
                    $count = $prevStart === $start ? ((int) $row[2]) + 1 : 1;
                    $rows[$i] = [$id, (string) $start, (string) $count];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $rows[] = [$id, (string) $start, '1'];
            }
            ShardTable::write($fh, $this->evict($rows));
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $allowed = $count <= $limit;
        return new RateLimitResult(
            $allowed,
            $limit,
            max(0, $limit - $count),
            $allowed ? 0 : max(1, $start + $window - $now),
        );
    }

    /** True when the shard storage can be created, opened and locked; false means limits are silently off. */
    public function probe(): bool
    {
        $fh = $this->openShard($this->dir . '/probe.rl');
        if ($fh === null) {
            return false;
        }
        $ok = flock($fh, LOCK_EX);
        if ($ok) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);

        return $ok;
    }

    private static function warnOnce(): void
    {
        if (!self::$warned) {
            self::$warned = true;
            error_log('MirrorGate: the rate-limit storage is not usable; request limits are not enforced (check STORAGE_DIR/ratelimit).');
        }
    }

    public function peek(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $limit = max(0, $limit);
        $window = max(1, $windowSeconds);
        $now = $this->clock->time();
        $start = $now - ($now % $window);
        [$file, $id] = $this->locate($key);
        $count = 0;
        if (is_file($file)) {
            $fh = @fopen($file, 'rb');
            if (is_resource($fh)) {
                if (flock($fh, LOCK_SH)) {
                    foreach (ShardTable::read($fh, self::FIELDS) as $row) {
                        if ($row[0] === $id && (int) $row[1] === $start) {
                            $count = (int) $row[2];
                            break;
                        }
                    }
                    flock($fh, LOCK_UN);
                }
                fclose($fh);
            }
        }
        $allowed = $count < $limit;
        return new RateLimitResult(
            $allowed,
            $limit,
            max(0, $limit - $count),
            $allowed ? 0 : max(1, $start + $window - $now),
        );
    }

    public function reset(string $key): void
    {
        [$file, $id] = $this->locate($key);
        if (!is_file($file)) {
            return;
        }
        $fh = $this->openShard($file);
        if ($fh === null) {
            return;
        }
        if (flock($fh, LOCK_EX)) {
            try {
                $rows = ShardTable::read($fh, self::FIELDS);
                $kept = array_values(array_filter($rows, static fn (array $r): bool => $r[0] !== $id));
                if (count($kept) !== count($rows)) {
                    ShardTable::write($fh, $kept);
                }
            } finally {
                flock($fh, LOCK_UN);
            }
        }
        fclose($fh);
    }

    /**
     * @return array{0:string,1:string} shard file path and 16 hex bucket id
     */
    private function locate(string $key): array
    {
        $hash = hash('sha256', $key);
        return [$this->dir . '/' . substr($hash, 0, 3) . '.rl', substr($hash, 3, 16)];
    }

    /** @return resource|null */
    private function openShard(string $file)
    {
        if (!$this->dirReady) {
            try {
                FileUtil::ensureDir($this->dir);
            } catch (\Throwable) {
                return null;
            }
            $this->dirReady = true;
        }
        return ShardTable::open($file);
    }

    /**
     * @param resource $fh
     * @return list<list<string>>
     */
    private function liveRows($fh, int $now): array
    {
        $rows = [];
        foreach (ShardTable::read($fh, self::FIELDS) as $row) {
            if (abs($now - (int) $row[1]) >= self::PRUNE_AFTER) {
                continue;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private function evict(array $rows): array
    {
        if (count($rows) <= self::MAX_LINES) {
            return $rows;
        }
        usort($rows, static fn (array $a, array $b): int => ((int) $a[1]) <=> ((int) $b[1]));
        return array_slice($rows, count($rows) - self::MAX_LINES);
    }
}
