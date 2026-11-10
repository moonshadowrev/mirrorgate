<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

use MirrorGate\Config\CacheConfig;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Support\Clock;
use MirrorGate\Support\FileUtil;

/**
 * Incremental, time-bounded garbage collector.
 *
 * Each run continues at the shard stored in `gc.cursor` and scans whole shards until the time or
 * file budget is exhausted. For every entry it removes: files of an older generation, files past
 * their retention (latest freshness window, plus one hour when validators allow conditional
 * revalidation), unreadable or corrupt files, and orphan `.part` temp files older than 10
 * minutes. It also prunes index files, removes unlocked lock files older than one hour and, when
 * usage is above 95 percent of CACHE_MAX_ENTRIES / CACHE_MAX_BYTES, evicts the oldest scanned
 * entries (by createdAt) down to 90 percent.
 */
final class CacheGc
{
    private const ORPHAN_AGE = 600;
    private const LOCK_AGE = 3600;
    private const REGISTRY_AGE = 604800;
    private const QUERY_EVICTION_BIAS = 4000000000;
    private const VALIDATOR_GRACE = 3600;

    public function __construct(
        private readonly CacheStore $store,
        private readonly CacheConfig $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(int $budgetMs): GcReport
    {
        $started = $this->clock->microtime();
        $empty = new GcReport(0, 0, 0, 0.0);
        if (!$this->store->isUsable() || !is_dir($this->store->base())) {
            return $empty;
        }
        $lock = $this->store->acquireNamed('gc.lock', 0);
        if ($lock === null) {
            return $empty;
        }

        try {
            return $this->sweep($started, max(10, $budgetMs));
        } catch (\Throwable $e) {
            $this->logger->warning('cache_gc_failed', ['error' => get_class($e)]);

            return new GcReport(0, 0, 0, $this->clock->microtime() - $started);
        } finally {
            $lock->release();
        }
    }

    private function sweep(float $started, int $budgetMs): GcReport
    {
        $deadline = $started + $budgetMs / 1000;
        $fileCap = $budgetMs <= 250 ? 2000 : $budgetMs * 50;
        $generation = $this->store->generation();
        $now = $this->clock->time();
        $cursor = $this->store->readCursor();

        $stats = ['scanned' => 0, 'deleted' => 0, 'freed' => 0];
        /** @var list<array{0:int,1:string,2:int,3:string}> $candidates createdAt, path, size, variant */
        $candidates = [];
        $next = $cursor;
        for ($i = 0; $i < CacheStore::SHARDS; $i++) {
            if ($i > 0 && ($this->clock->microtime() >= $deadline || $stats['scanned'] >= $fileCap)) {
                break;
            }
            $shard = ($cursor + $i) & 0xff;
            $complete = $this->scanShard($shard, $generation, $now, $deadline, $stats, $candidates);
            if (!$complete) {
                $next = $shard;
                break;
            }
            $this->scanIndexShard($shard, $generation, $now, $stats);
            $next = ($shard + 1) & 0xff;
        }
        $this->store->writeCounter('gc.cursor', $next);
        $this->cleanLocks($now);
        $this->evict($candidates, $stats);

        $report = new GcReport($stats['scanned'], $stats['deleted'], $stats['freed'], $this->clock->microtime() - $started);
        if ($report->deleted > 0) {
            $this->logger->debug('cache_gc', ['scanned' => $report->scanned, 'deleted' => $report->deleted]);
        }

        return $report;
    }

    /**
     * @param array{scanned:int, deleted:int, freed:int} $stats
     * @param list<array{0:int,1:string,2:int,3:string}> $candidates
     * @return bool false when the deadline interrupted the shard
     */
    private function scanShard(int $shard, int $generation, int $now, float $deadline, array &$stats, array &$candidates): bool
    {
        $entries = 0;
        $bytes = 0;
        $touchedDirs = [];
        foreach ($this->store->eachShardFile($shard) as $item) {
            if ($this->clock->microtime() >= $deadline && $stats['scanned'] > 0) {
                return false;
            }
            $path = $item['dir'] . '/' . $item['name'];
            $touchedDirs[$item['dir']] = true;
            $stats['scanned']++;

            if (preg_match(CacheWriter::TEMP_PATTERN, $item['name']) === 1) {
                $mtime = @filemtime($path);
                if ($mtime !== false && $now - $mtime > self::ORPHAN_AGE) {
                    $this->drop($path, $stats);
                }
                continue;
            }
            if (!CacheStore::isHash($item['name'])) {
                continue;
            }
            $loaded = $this->store->loadMeta($path, false);
            if ($loaded === null) {
                // loadMeta() already deleted a corrupt file; a file it refused to open is left alone
                $stats['deleted'] += is_file($path) ? 0 : 1;
                continue;
            }
            $meta = $loaded['meta'];
            $gen = $meta['gen'] ?? 0;
            if (!is_int($gen) || $gen < $generation || $now > $this->retainUntil($meta)) {
                $this->drop($path, $stats, $loaded['size']);
                continue;
            }
            $entries++;
            $bytes += $loaded['size'];
            $created = $meta['createdAt'] ?? 0;
            // Entries stored under a query string are evicted before plain-path entries, so a flood of
            // cache-busting URLs cannot push out the hot pages.
            $age = is_int($created) ? $created : 0;
            if (($meta['hasQuery'] ?? false) === true) {
                $age -= self::QUERY_EVICTION_BIAS;
            }
            $candidates[] = [$age, $path, $loaded['size'], $item['name']];
        }
        foreach (array_keys($touchedDirs) as $dir) {
            @rmdir($dir); // succeeds only when empty
        }
        $this->store->usageSetShard($shard, $entries, $bytes);

        return true;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function retainUntil(array $meta): int
    {
        $latest = 0;
        foreach (['freshUntil', 'swrUntil', 'staleIfErrorUntil'] as $key) {
            if (isset($meta[$key]) && is_int($meta[$key])) {
                $latest = max($latest, $meta[$key]);
            }
        }
        $validators = (isset($meta['originEtag']) && is_string($meta['originEtag']) && $meta['originEtag'] !== '')
            || (isset($meta['originLastModified']) && is_string($meta['originLastModified']) && $meta['originLastModified'] !== '');

        return $latest + ($validators ? self::VALIDATOR_GRACE : 0);
    }

    /**
     * @param array{scanned:int, deleted:int, freed:int} $stats
     */
    private function drop(string $path, array &$stats, ?int $size = null): void
    {
        $size ??= (int) @filesize($path);
        if (FileUtil::safeUnlink($path)) {
            $stats['deleted']++;
            $stats['freed'] += $size;
        }
    }

    /**
     * @param array{scanned:int, deleted:int, freed:int} $stats
     */
    private function scanIndexShard(int $shard, int $generation, int $now, array &$stats): void
    {
        $dir = $this->store->base() . '/idx/' . CacheStore::shardName($shard);
        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }
        $names = [];
        while (($name = readdir($handle)) !== false) {
            if ($name !== '.' && $name !== '..') {
                $names[] = $name;
            }
        }
        closedir($handle);

        foreach ($names as $name) {
            $path = $dir . '/' . $name;
            $stats['scanned']++;
            if (preg_match('/^q-[0-9a-f]{64}\.json$/D', $name) === 1) {
                // Query-variant registry: its hash embeds the generation, so only age matters.
                $mtime = @filemtime($path);
                if ($mtime !== false && $now - $mtime > self::REGISTRY_AGE) {
                    $this->drop($path, $stats);
                }
                continue;
            }
            if (preg_match('/^([0-9a-f]{64})\.json$/D', $name, $m) !== 1) {
                $mtime = @filemtime($path);
                if ($mtime !== false && $now - $mtime > self::ORPHAN_AGE && is_file($path)) {
                    $this->drop($path, $stats);
                }
                continue;
            }
            $primary = $m[1];
            $index = $this->store->readIndex($primary);
            if ($index === null || $index['gen'] < $generation) {
                $this->drop($path, $stats);
                continue;
            }
            $alive = [];
            foreach ($index['variants'] as $variant) {
                if (is_file($this->store->entryPath($variant))) {
                    $alive[] = $variant;
                }
            }
            if ($alive === []) {
                $this->drop($path, $stats);
            } elseif (count($alive) !== count($index['variants'])) {
                $index['variants'] = $alive;
                $this->store->writeIndex($primary, $index);
            }
        }
        @rmdir($dir);
    }

    private function cleanLocks(int $now): void
    {
        $dir = $this->store->base() . '/locks';
        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }
        $names = [];
        while (($name = readdir($handle)) !== false) {
            if (preg_match('/^(?:ix)?[0-9a-f]{2}\.lock$/D', $name) === 1) {
                $names[] = $name;
            }
        }
        closedir($handle);

        foreach ($names as $name) {
            $path = $dir . '/' . $name;
            $mtime = @filemtime($path);
            if ($mtime === false || $now - $mtime <= self::LOCK_AGE) {
                continue;
            }
            if ($this->store->lockMode() === 'mkdir') {
                if (is_dir($path) && !CacheLock::isHeld($path)) {
                    @rmdir($path);
                }
                continue;
            }
            if (CacheLock::isHeld($path) || !is_file($path)) {
                continue;
            }
            $lock = @fopen($path, 'cb');
            if ($lock === false) {
                continue;
            }
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                @unlink($path);
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    /**
     * @param list<array{0:int,1:string,2:int,3:string}> $candidates
     * @param array{scanned:int, deleted:int, freed:int} $stats
     */
    private function evict(array $candidates, array &$stats): void
    {
        $totals = $this->store->usageTotals();
        $maxEntries = $this->config->maxEntries;
        $maxBytes = $this->config->maxBytes;
        if ($totals['entries'] <= (int) ($maxEntries * 0.95) && $totals['bytes'] <= (int) ($maxBytes * 0.95)) {
            return;
        }
        usort($candidates, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $targetEntries = (int) ($maxEntries * 0.90);
        $targetBytes = (int) ($maxBytes * 0.90);
        $entries = $totals['entries'];
        $bytes = $totals['bytes'];
        foreach ($candidates as [, $path, $size, $variant]) {
            if ($entries <= $targetEntries && $bytes <= $targetBytes) {
                break;
            }
            if (FileUtil::safeUnlink($path)) {
                $stats['deleted']++;
                $stats['freed'] += $size;
                $entries--;
                $bytes -= $size;
                $this->store->usageAdjust($variant, -1, -$size);
            }
        }
    }
}
