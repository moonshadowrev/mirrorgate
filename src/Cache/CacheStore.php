<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

use MirrorGate\Config\CacheConfig;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Support\Clock;
use MirrorGate\Support\FileUtil;
use MirrorGate\Support\Json;

/**
 * Filesystem layer of the cache. Layout below `<CACHE_DIR>/<instance>/`:
 *
 *   idx/<hh>/<primaryHash>.json       primary index: Vary selector, variants, soft purge marker
 *   e/<hh>/<hh>/<variantHash>         entry files (MGC1 format)
 *   locks/<hh>.lock                   256 shared refresh/index locks
 *   usage.json                        per shard entry and byte counters (admission control)
 *   generation, soft-generation       purge counters
 *   gc.stamp, gc.lock, gc.cursor      garbage collection state
 *
 * Every path is derived from validated 64 character lowercase hex hashes only; no request data
 * ever becomes part of a path.
 */
final class CacheStore
{
    public const SHARDS = 256;

    private string $base;
    private ?bool $ready = null;
    private int $dirMode = 0750;
    private int $fileMode = 0640;
    private CacheWriter $writer;

    /** @var array{0:int,1:int}|null [time, generation] */
    private ?array $generationMemo = null;

    public function __construct(
        string $cacheDir,
        string $instanceId,
        private readonly CacheConfig $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->base = $cacheDir === '' ? '' : rtrim($cacheDir, '/\\') . '/' . self::safeInstanceDir($instanceId);
        $this->writer = new CacheWriter($this->fileMode);
    }

    public static function safeInstanceDir(string $instanceId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $instanceId) === 1) {
            return $instanceId;
        }

        return substr(hash('sha256', $instanceId), 0, 24);
    }

    public static function isHash(string $value): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    public function isUsable(): bool
    {
        return $this->base !== '';
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * Creates the instance directories (with the deny files of FileUtil::ensureDir). Shard
     * directories below them are plain directories: the protected parents cover them, and writing
     * deny files into up to 65536 leaf directories would waste inodes.
     */
    public function ensureReady(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }
        if ($this->base === '') {
            return $this->ready = false;
        }
        try {
            FileUtil::ensureDir($this->base);
            foreach (['idx', 'e', 'locks'] as $dir) {
                FileUtil::ensureDir($this->base . '/' . $dir);
            }
            $perms = @fileperms($this->base);
            if ($perms !== false) {
                $this->dirMode = ($perms & 0777) | 0700;
                $this->fileMode = $this->dirMode & 0666;
                $this->writer = new CacheWriter($this->fileMode);
            }
            $this->ready = true;
        } catch (\Throwable) {
            $this->logger->warning('cache_dir_unavailable');
            $this->ready = false;
        }

        return $this->ready;
    }

    public function writer(): CacheWriter
    {
        return $this->writer;
    }

    // ---- paths ------------------------------------------------------------------------------

    public function entryPath(string $variantHash): string
    {
        self::assertHash($variantHash);

        return $this->base . '/e/' . substr($variantHash, 0, 2) . '/' . substr($variantHash, 2, 2) . '/' . $variantHash;
    }

    public function indexPath(string $primaryHash): string
    {
        self::assertHash($primaryHash);

        return $this->base . '/idx/' . substr($primaryHash, 0, 2) . '/' . $primaryHash . '.json';
    }

    /** Lock slot for index read-modify-write cycles; separate from the origin-fetch locks held for a whole fill. */
    public function indexLockPath(string $hash): string
    {
        self::assertHash($hash);

        return $this->base . '/locks/ix' . substr($hash, 0, 2) . '.lock';
    }

    public function acquireIndexLock(string $hash, int $waitMs): ?CacheLock
    {
        return $this->acquire($this->indexLockPath($hash), $waitMs);
    }

    /** Registry of the query-string variants stored for one bare path (see CACHE_MAX_QUERY_VARIANTS). */
    public function queryRegistryPath(string $pathHash): string
    {
        self::assertHash($pathHash);

        return $this->base . '/idx/' . substr($pathHash, 0, 2) . '/q-' . $pathHash . '.json';
    }

    /** @return list<string> primary hashes, oldest first */
    public function readQueryRegistry(string $pathHash): array
    {
        $raw = FileUtil::readFile($this->queryRegistryPath($pathHash), 65536);
        if ($raw === null) {
            return [];
        }
        try {
            $data = Json::decode($raw, 4);
        } catch (\JsonException) {
            return [];
        }
        $out = [];
        if (is_array($data) && isset($data['primaries']) && is_array($data['primaries'])) {
            foreach ($data['primaries'] as $primary) {
                if (is_string($primary) && self::isHash($primary)) {
                    $out[] = $primary;
                }
            }
        }

        return $out;
    }

    /** @param list<string> $primaries */
    public function writeQueryRegistry(string $pathHash, array $primaries): bool
    {
        $path = $this->queryRegistryPath($pathHash);
        if (!$this->ensureReady() || !$this->ensureDirFor($path)) {
            return false;
        }
        try {
            FileUtil::atomicWrite($path, Json::encode(['gen' => $this->generation(), 'primaries' => $primaries]));
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function lockPath(string $hash): string
    {
        self::assertHash($hash);

        return $this->base . '/locks/' . substr($hash, 0, 2) . '.lock';
    }

    public static function shardName(int $shard): string
    {
        return sprintf('%02x', $shard & 0xff);
    }

    public function ensureDirFor(string $file): bool
    {
        $dir = dirname($file);
        if (is_dir($dir)) {
            return true;
        }
        if (!@mkdir($dir, $this->dirMode, true) && !is_dir($dir)) {
            return false;
        }

        return true;
    }

    private static function assertHash(string $hash): void
    {
        if (!self::isHash($hash)) {
            throw new \InvalidArgumentException('Invalid cache hash.');
        }
    }

    // ---- generation counters ----------------------------------------------------------------

    public function generation(): int
    {
        $now = $this->clock->time();
        if ($this->generationMemo !== null && $this->generationMemo[0] === $now) {
            return $this->generationMemo[1];
        }
        $value = $this->readCounter('generation');
        $this->generationMemo = [$now, $value];

        return $value;
    }

    public function bumpGeneration(): int
    {
        $next = $this->readCounter('generation') + 1;
        if ($this->ensureReady()) {
            FileUtil::atomicWrite($this->base . '/generation', (string) $next);
        }
        $this->generationMemo = null;

        return $next;
    }

    public function softGeneration(): int
    {
        return $this->readCounter('soft-generation');
    }

    public function setSoftGeneration(int $timestamp): void
    {
        if ($this->ensureReady()) {
            FileUtil::atomicWrite($this->base . '/soft-generation', (string) $timestamp);
        }
    }

    public function readCounter(string $name): int
    {
        if ($this->base === '') {
            return 0;
        }
        $raw = FileUtil::readFile($this->base . '/' . $name, 32);
        $raw = $raw === null ? '' : trim($raw);

        return $raw !== '' && ctype_digit($raw) && strlen($raw) <= 15 ? (int) $raw : 0;
    }

    public function writeCounter(string $name, int $value): void
    {
        if ($this->ensureReady()) {
            FileUtil::atomicWrite($this->base . '/' . $name, (string) max(0, $value));
        }
    }

    // ---- primary index ----------------------------------------------------------------------

    /**
     * @return array{gen:int, selector:list<string>, variants:list<string>, softAt:int}|null
     */
    public function readIndex(string $primaryHash): ?array
    {
        $raw = FileUtil::readFile($this->indexPath($primaryHash), 65536);
        if ($raw === null) {
            return null;
        }
        try {
            $data = Json::decode($raw, 4);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !isset($data['variants'], $data['varySelector']) || !is_array($data['variants']) || !is_array($data['varySelector'])) {
            return null;
        }
        $variants = [];
        foreach ($data['variants'] as $variant) {
            if (is_string($variant) && self::isHash($variant)) {
                $variants[] = $variant;
            }
        }
        $selector = [];
        foreach ($data['varySelector'] as $name) {
            if (is_string($name)) {
                $selector[] = $name;
            }
        }
        $gen = $data['gen'] ?? 0;
        $softAt = $data['softAt'] ?? 0;

        return [
            'gen' => is_int($gen) ? $gen : 0,
            'selector' => CacheKeyBuilder::canonicalSelector($selector),
            'variants' => $variants,
            'softAt' => is_int($softAt) ? $softAt : 0,
        ];
    }

    /**
     * @param array{gen:int, selector:list<string>, variants:list<string>, softAt:int} $index
     */
    public function writeIndex(string $primaryHash, array $index): bool
    {
        if (!$this->ensureReady()) {
            return false;
        }
        $path = $this->indexPath($primaryHash);
        if (!$this->ensureDirFor($path)) {
            return false;
        }
        try {
            FileUtil::atomicWrite($path, Json::encode([
                'gen' => $index['gen'],
                'varySelector' => $index['selector'],
                'variants' => $index['variants'],
                'softAt' => $index['softAt'],
            ]));
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    // ---- entries ----------------------------------------------------------------------------

    /**
     * Opens an entry file for reading after verifying its location (hex file name below
     * e/<hh>/<hh>/), that it is a regular file and not a symlink, and that its real path stays
     * inside the instance directory.
     *
     * @return resource|null handle at offset 0
     */
    public static function openVerified(string $file)
    {
        $name = basename($file);
        $second = dirname($file);
        $first = dirname($second);
        $entries = dirname($first);
        if (preg_match('/^[0-9a-f]{64}$/D', $name) !== 1
            || basename($entries) !== 'e'
            || basename($first) !== substr($name, 0, 2)
            || basename($second) !== substr($name, 2, 2)
            || is_link($file)
            || !is_file($file)
            || !FileUtil::isInside($file, dirname($entries))
        ) {
            return null;
        }
        $handle = @fopen($file, 'rb');

        return is_resource($handle) ? $handle : null;
    }

    public function readEntry(string $variantHash): ?CacheEntry
    {
        $path = $this->entryPath($variantHash);
        $loaded = $this->loadMeta($path, true);
        if ($loaded === null) {
            return null;
        }

        return $this->entryFromMeta($loaded['meta'], $path, $loaded['offset'], $variantHash);
    }

    /**
     * Reads and validates the header of an entry file. A file that fails validation is deleted.
     *
     * @return array{meta: array<string,mixed>, offset: int, size: int}|null
     */
    public function loadMeta(string $path, bool $verifySmallBody): ?array
    {
        $handle = self::openVerified($path);
        if ($handle === null) {
            return null;
        }
        $valid = false;
        $result = null;
        try {
            $header = CacheFormat::readHeader($handle);
            $stat = fstat($handle);
            if ($header !== null && $stat !== false) {
                $meta = $header['meta'];
                $bodyLength = $meta['bodyLength'] ?? null;
                if (is_int($bodyLength) && $bodyLength >= 0 && $stat['size'] === $header['offset'] + $bodyLength) {
                    $valid = true;
                    if ($verifySmallBody && $bodyLength <= 262144) {
                        $body = $bodyLength === 0 ? '' : stream_get_contents($handle, $bodyLength);
                        $valid = is_string($body) && ($meta['crc32'] ?? null) === CacheFormat::crc($body);
                    }
                    if ($valid) {
                        $result = ['meta' => $meta, 'offset' => $header['offset'], 'size' => $stat['size']];
                    }
                }
            }
        } finally {
            fclose($handle);
        }
        if (!$valid) {
            $this->deleteFile($path);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $m
     */
    public function entryFromMeta(array $m, string $file, int $offset, string $expectedVariant): ?CacheEntry
    {
        $ints = ['status', 'bodyLength', 'createdAt', 'freshUntil', 'swrUntil', 'staleIfErrorUntil'];
        foreach ($ints as $key) {
            if (!isset($m[$key]) || !is_int($m[$key])) {
                return null;
            }
        }
        if (($m['variant'] ?? null) !== $expectedVariant
            || !isset($m['storedEncoding'], $m['mirrorEtag'], $m['contentType'], $m['headers'], $m['varySelector'])
            || !is_string($m['storedEncoding']) || !in_array($m['storedEncoding'], ['identity', 'gzip'], true)
            || !is_string($m['mirrorEtag']) || !is_string($m['contentType'])
            || !is_array($m['headers']) || !is_array($m['varySelector'])
            || $m['status'] < 100 || $m['status'] > 599
        ) {
            return null;
        }
        $pairs = [];
        foreach ($m['headers'] as $pair) {
            if (!is_array($pair) || count($pair) !== 2 || !isset($pair[0], $pair[1]) || !is_string($pair[0]) || !is_string($pair[1])) {
                return null;
            }
            $pairs[] = [$pair[0], $pair[1]];
        }
        try {
            $headers = new HeaderBag($pairs);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $selector = [];
        foreach ($m['varySelector'] as $name) {
            if (is_string($name)) {
                $selector[] = $name;
            }
        }
        $etag = isset($m['originEtag']) && is_string($m['originEtag']) ? $m['originEtag'] : null;
        $lastModified = isset($m['originLastModified']) && is_string($m['originLastModified']) ? $m['originLastModified'] : null;
        $path = isset($m['path']) && is_string($m['path']) ? $m['path'] : '';

        return new CacheEntry(
            $m['status'],
            $headers,
            $m['bodyLength'],
            $m['storedEncoding'],
            $m['createdAt'],
            $m['freshUntil'],
            $m['swrUntil'],
            $m['staleIfErrorUntil'],
            $etag,
            $lastModified,
            $m['mirrorEtag'],
            ($m['mustRevalidate'] ?? false) === true,
            $selector,
            $m['contentType'],
            $path,
            $file,
            $offset,
        );
    }

    /** Deletes a file and corrects the usage counters for it. */
    public function deleteEntry(string $variantHash): int
    {
        $path = $this->entryPath($variantHash);
        $size = @filesize($path);
        if ($size === false || !FileUtil::safeUnlink($path)) {
            return 0;
        }
        $this->usageAdjust($variantHash, -1, -$size);

        return $size;
    }

    private function deleteFile(string $path): void
    {
        $size = @filesize($path);
        if (FileUtil::safeUnlink($path) && $size !== false) {
            $hash = basename($path);
            if (self::isHash($hash)) {
                $this->usageAdjust($hash, -1, -$size);
            }
        }
    }

    /**
     * Lists entry files of one shard (`e/<hh>`), including orphan temp files.
     *
     * @return \Generator<int, array{dir:string, name:string}>
     */
    public function eachShardFile(int $shard): \Generator
    {
        $root = $this->base . '/e/' . self::shardName($shard);
        $top = @opendir($root);
        if ($top === false) {
            return;
        }
        try {
            while (($sub = readdir($top)) !== false) {
                if (preg_match('/^[0-9a-f]{2}$/D', $sub) !== 1) {
                    continue;
                }
                $dir = $root . '/' . $sub;
                $inner = @opendir($dir);
                if ($inner === false) {
                    continue;
                }
                try {
                    while (($name = readdir($inner)) !== false) {
                        if ($name !== '.' && $name !== '..') {
                            yield ['dir' => $dir, 'name' => $name];
                        }
                    }
                } finally {
                    closedir($inner);
                }
            }
        } finally {
            closedir($top);
        }
    }

    // ---- usage counters ---------------------------------------------------------------------

    /**
     * Adjusts the counters of the shard that holds $hash. Counters are advisory: garbage
     * collection recomputes each shard exactly when it scans it.
     */
    public function usageAdjust(string $hash, int $entries, int $bytes): void
    {
        $shard = (int) hexdec(substr($hash, 0, 2));
        $this->usageMutate(static function (array &$s) use ($shard, $entries, $bytes): void {
            [$e, $b] = $s[$shard];
            $s[$shard] = [max(0, $e + $entries), max(0, $b + $bytes)];
        });
    }

    public function usageSetShard(int $shard, int $entries, int $bytes): void
    {
        $shard &= 0xff;
        $this->usageMutate(static function (array &$s) use ($shard, $entries, $bytes): void {
            $s[$shard] = [max(0, $entries), max(0, $bytes)];
        });
    }

    /**
     * @return array{entries:int, bytes:int}
     */
    public function usageTotals(): array
    {
        $handle = $this->base === '' ? false : @fopen($this->base . '/usage.json', 'rb');
        if ($handle === false) {
            return ['entries' => 0, 'bytes' => 0];
        }
        try {
            @flock($handle, LOCK_SH);
            $raw = stream_get_contents($handle, 65536);
            @flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        $entries = 0;
        $bytes = 0;
        foreach (self::parseUsage(is_string($raw) ? $raw : '') as [$e, $b]) {
            $entries += $e;
            $bytes += $b;
        }

        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /**
     * @param \Closure(array<int,array{0:int,1:int}>): void $mutate
     */
    private function usageMutate(\Closure $mutate): void
    {
        if (!$this->ensureReady()) {
            return;
        }
        $path = $this->base . '/usage.json';
        $isNew = !is_file($path);
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($handle, 65536);
            $state = self::parseUsage(is_string($raw) ? $raw : '');
            $mutate($state);
            $json = Json::encode(['s' => $state]);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
            if ($isNew) {
                @chmod($path, $this->fileMode);
            }
        } catch (\Throwable) {
            // advisory counters: never fail the caller
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<int,array{0:int,1:int}>
     */
    private static function parseUsage(string $raw): array
    {
        $state = array_fill(0, self::SHARDS, [0, 0]);
        if ($raw === '') {
            return $state;
        }
        try {
            $data = Json::decode($raw, 4);
        } catch (\JsonException) {
            return $state;
        }
        if (!is_array($data) || !isset($data['s']) || !is_array($data['s'])) {
            return $state;
        }
        $i = 0;
        foreach ($data['s'] as $pair) {
            if ($i >= self::SHARDS) {
                break;
            }
            if (is_array($pair) && isset($pair[0], $pair[1]) && is_int($pair[0]) && is_int($pair[1])) {
                $state[$i] = [max(0, $pair[0]), max(0, $pair[1])];
            }
            $i++;
        }

        return $state;
    }

    // ---- locks ------------------------------------------------------------------------------

    /**
     * Acquires the shared lock slot for a hash (`locks/<hh>.lock`).
     */
    public function acquireLock(string $hash, int $waitMs): ?CacheLock
    {
        return $this->acquire($this->lockPath($hash), $waitMs);
    }

    /**
     * Acquires a named lock directly below the instance directory (for example "gc.lock").
     */
    public function acquireNamed(string $name, int $waitMs): ?CacheLock
    {
        if ($this->base === '' || preg_match('/^[a-z.]{1,32}$/D', $name) !== 1) {
            return null;
        }

        return $this->acquire($this->base . '/' . $name, $waitMs);
    }

    private function acquire(string $path, int $waitMs): ?CacheLock
    {
        if (!$this->ensureReady() || CacheLock::isHeld($path)) {
            return null;
        }
        $deadline = $this->clock->microtime() + max(0, $waitMs) / 1000;
        if ($this->config->lockMode === 'mkdir') {
            $reclaimed = false;
            while (true) {
                if (@mkdir($path, 0700)) {
                    return new CacheLock($path, null, true);
                }
                $mtime = @filemtime($path);
                if (!$reclaimed && $mtime !== false && $this->clock->time() - $mtime > 60) {
                    $reclaimed = true;
                    @rmdir($path);
                    continue;
                }
                if ($this->clock->microtime() >= $deadline) {
                    return null;
                }
                usleep(10000);
            }
        }
        $handle = FileUtil::openLock($path);
        if ($handle === null) {
            return null;
        }
        while (true) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new CacheLock($path, $handle, false);
            }
            if ($this->clock->microtime() >= $deadline) {
                fclose($handle);
                return null;
            }
            usleep(10000);
        }
    }

    public function lockMode(): string
    {
        return $this->config->lockMode;
    }

    // ---- misc -------------------------------------------------------------------------------

    public function readCursor(): int
    {
        return $this->readCounter('gc.cursor') & 0xff;
    }

    /** Best-effort removal used by purge and garbage collection. */
    public function removeIndex(string $primaryHash): void
    {
        FileUtil::safeUnlink($this->indexPath($primaryHash));
    }
}
