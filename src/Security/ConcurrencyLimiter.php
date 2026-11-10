<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Support\Clock;
use MirrorGate\Support\FileUtil;

/**
 * Lease based concurrency limiter. State lives in 256 shard files per scope,
 * `<dir>/<scope>/<hh>.cl`, with lines `<subjectHash16>\t<leaseId>\t<expiresAt>`. Acquiring counts
 * the unexpired leases of the subject under flock and appends one when below the maximum.
 * A crashed worker never releases, so its lease simply expires after $leaseSeconds.
 * Storage problems fail open (a slot is granted), because blocking all traffic on a disk
 * error is worse than briefly exceeding a cap.
 */
final class ConcurrencyLimiter
{
    private const FIELDS = 3;
    private const MAX_LINES = 512;

    /** @var array<string,bool> */
    private array $readyDirs = [];

    public function __construct(
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly int $leaseSeconds = 120,
    ) {
    }

    /**
     * Returns a slot, or null when the subject already holds $max unexpired leases.
     * $max <= 0 disables the limit and returns a no-op slot.
     */
    public function acquire(string $scope, string $subject, int $max): ?ConcurrencySlot
    {
        if ($max <= 0) {
            return new ConcurrencySlot();
        }
        if (preg_match('/^[a-z0-9_-]{1,32}$/D', $scope) !== 1) {
            throw new \InvalidArgumentException('Invalid concurrency scope.');
        }
        $hash = hash('sha256', $subject);
        $file = $this->shardFile($scope, substr($hash, 0, 2));
        $id = substr($hash, 2, 16);
        $fh = $file === null ? null : ShardTable::open($file);
        if ($fh === null || !flock($fh, LOCK_EX)) {
            if ($fh !== null) {
                fclose($fh);
            }
            return new ConcurrencySlot();
        }
        $now = $this->clock->time();
        $lease = bin2hex(random_bytes(8));
        $granted = false;
        try {
            $rows = [];
            $held = 0;
            foreach (ShardTable::read($fh, self::FIELDS) as $row) {
                if ((int) $row[2] <= $now) {
                    continue;
                }
                if ($row[0] === $id) {
                    $held++;
                }
                $rows[] = $row;
            }
            if ($held < $max && count($rows) < self::MAX_LINES) {
                $rows[] = [$id, $lease, (string) ($now + max(1, $this->leaseSeconds))];
                $granted = true;
            }
            ShardTable::write($fh, $rows);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        if (!$granted) {
            return null;
        }
        $path = (string) $file;
        return new ConcurrencySlot(fn () => $this->releaseLease($path, $lease));
    }

    private function releaseLease(string $file, string $lease): void
    {
        if (!is_file($file)) {
            return;
        }
        $fh = ShardTable::open($file);
        if ($fh === null) {
            return;
        }
        if (flock($fh, LOCK_EX)) {
            try {
                $rows = ShardTable::read($fh, self::FIELDS);
                $kept = array_values(array_filter($rows, static fn (array $r): bool => $r[1] !== $lease));
                if (count($kept) !== count($rows)) {
                    ShardTable::write($fh, $kept);
                }
            } finally {
                flock($fh, LOCK_UN);
            }
        }
        fclose($fh);
    }

    private function shardFile(string $scope, string $shard): ?string
    {
        $scopeDir = rtrim($this->dir, '/\\') . '/' . $scope;
        if (!isset($this->readyDirs[$scopeDir])) {
            try {
                FileUtil::ensureDir($scopeDir);
            } catch (\Throwable) {
                return null;
            }
            $this->readyDirs[$scopeDir] = true;
        }
        return $scopeDir . '/' . $shard . '.cl';
    }
}
