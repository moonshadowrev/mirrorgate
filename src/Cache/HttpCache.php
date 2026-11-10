<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

use MirrorGate\Config\CacheConfig;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\Request;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Support\Clock;
use MirrorGate\Support\Str;

/**
 * File based HTTP cache for one MirrorGate instance.
 *
 * Responsibilities: key derivation, lookup with soft purge handling, storing rewritten responses,
 * conditional revalidation support (refresh after a 304), request coalescing locks, purging and
 * garbage collection. It never throws for I/O problems on the request path: failures degrade to a
 * cache miss or a refused store and are logged without paths.
 */
final class HttpCache implements CacheAdminInterface
{
    private const MAX_VARIANTS_PER_URL = 64;
    private const MAX_HEADER_VALUE_BYTES = 8192;
    private const MAX_HEADERS_BYTES = 32768;
    private const PURGE_MAX_ENTRIES = 20000;
    private const PURGE_MAX_SECONDS = 2.0;
    private const PREFIX_DEPTH = 8;
    private const GZIP_MIN_BYTES = 1024;

    /** Response headers that are never stored. */
    private const DENIED_HEADERS = [
        'set-cookie', 'set-cookie2', 'host', 'content-length', 'content-encoding', 'age', 'etag', 'date', 'server',
        'x-powered-by', 'transfer-encoding', 'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'te', 'trailer', 'upgrade', 'via', 'cache-status', 'alt-svc', 'content-md5', 'digest', 'content-digest',
        'repr-digest', 'content-range',
    ];

    private readonly CacheStore $store;
    private readonly CacheKeyBuilder $builder;
    private readonly CachePolicy $policy;
    private readonly CacheGc $gc;
    private readonly string $pathMetaKey;

    /** @var array<string,Request> most recent requests by primary hash (for waitForEntry) */
    private array $recent = [];

    public function __construct(
        private readonly CacheConfig $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        string $instanceId,
        string $rewriteFingerprint,
        string $pathMetaKey = '',
    ) {
        $this->pathMetaKey = $pathMetaKey !== '' ? $pathMetaKey : 'mirrorgate-pathmeta';
        $this->store = new CacheStore($config->dir, $instanceId, $config, $clock, $logger);
        $this->builder = new CacheKeyBuilder($config, $instanceId, $rewriteFingerprint, fn (): int => $this->store->generation());
        $this->policy = new CachePolicy($config, $clock);
        $this->gc = new CacheGc($this->store, $config, $clock, $logger);
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled && $this->store->isUsable();
    }

    public function isRequestEligible(Request $request): bool
    {
        return $this->isEnabled() && $this->policy->isRequestEligible($request);
    }

    /**
     * Query string to send upstream for a cache fill: the same value the key is built from, so the stored
     * response is a pure function of the key (an ignored parameter never reaches the origin).
     */
    public function upstreamQuery(string $query): string
    {
        return $this->builder->normalizeQuery($query);
    }

    public function policy(): CachePolicy
    {
        return $this->policy;
    }

    /**
     * @param list<string> $varySelector
     */
    public function keyFor(Request $request, array $varySelector): CacheKey
    {
        $key = $this->builder->build($request, $varySelector);
        $this->remember($key->primaryHash, $request);

        return $key;
    }

    public function lookup(Request $request): CacheLookup
    {
        $missKey = $this->builder->build($request, []);
        if (!$this->isEnabled()) {
            return new CacheLookup(CacheState::Miss, null, $missKey);
        }
        $this->remember($missKey->primaryHash, $request);

        try {
            $index = $this->store->readIndex($missKey->primaryHash);
            if ($index === null) {
                return new CacheLookup(CacheState::Miss, null, $missKey);
            }
            $key = $this->builder->build($request, $index['selector']);
            $entry = $this->store->readEntry($key->variantHash);
            if ($entry === null) {
                return new CacheLookup(CacheState::Miss, null, $key);
            }
            $now = $this->clock->time();
            if ($now > $this->retainUntil($entry)) {
                $this->store->deleteEntry($key->variantHash);

                return new CacheLookup(CacheState::Miss, null, $key);
            }

            return new CacheLookup($this->stateOf($entry, $index['softAt'], $now), $entry, $key);
        } catch (\Throwable $e) {
            $this->logger->warning('cache_lookup_failed', ['error' => get_class($e)]);

            return new CacheLookup(CacheState::Miss, null, $missKey);
        }
    }

    /**
     * @param list<string> $varySelector
     */
    public function store(
        CacheKey $key,
        int $status,
        HeaderBag $mirrorHeaders,
        string $identityBody,
        CacheDecision $decision,
        ?string $originEtag,
        ?string $originLastModified,
        array $varySelector,
    ): bool {
        try {
            return $this->doStore($key, $status, $mirrorHeaders, $identityBody, $decision, $originEtag, $originLastModified, $varySelector);
        } catch (\Throwable $e) {
            $this->logger->warning('cache_store_failed', ['error' => get_class($e)]);

            return false;
        }
    }

    public function refresh(CacheKey $key, CacheDecision $decision, ?HeaderBag $new304Headers): bool
    {
        try {
            return $this->doRefresh($key, $decision, $new304Headers);
        } catch (\Throwable $e) {
            $this->logger->warning('cache_refresh_failed', ['error' => get_class($e)]);

            return false;
        }
    }

    public function tryLockRefresh(CacheKey $key): ?CacheLock
    {
        if (!$this->isEnabled() || !CacheStore::isHash($key->variantHash)) {
            return null;
        }
        try {
            return $this->store->acquireLock($key->variantHash, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Polls until another request has published a usable entry, the lock holder is gone, or the
     * time is up. Only Fresh and StaleWhileRevalidate entries are returned.
     */
    public function waitForEntry(CacheKey $key, int $maxMs): ?CacheEntry
    {
        if (!$this->isEnabled() || !CacheStore::isHash($key->primaryHash) || !CacheStore::isHash($key->variantHash)) {
            return null;
        }
        $deadline = $this->clock->microtime() + max(0, $maxMs) / 1000;
        $request = $this->recent[$key->primaryHash] ?? null;
        while (true) {
            $entry = $this->readPublished($key, $request);
            if ($entry !== null || $maxMs <= 0) {
                return $entry;
            }
            // No holder left (fetch failed or response was not cacheable): stop waiting.
            $probe = $this->store->acquireLock($key->variantHash, 0);
            if ($probe !== null) {
                $probe->release();

                return $this->readPublished($key, $request);
            }
            if ($this->clock->microtime() >= $deadline) {
                return null;
            }
            usleep(25000);
        }
    }

    public function purgeUrl(string $pathAndQuery, bool $soft): int
    {
        $normalized = $this->builder->normalizePathAndQuery($pathAndQuery);
        if ($normalized === null || !$this->isEnabled()) {
            return 0;
        }
        try {
            $primary = $this->builder->primaryHashFor($normalized);
            $index = $this->store->readIndex($primary);
            if ($index === null) {
                return 0;
            }
            $count = 0;
            foreach ($index['variants'] as $variant) {
                if (is_file($this->store->entryPath($variant))) {
                    $count++;
                    if (!$soft) {
                        $this->store->deleteEntry($variant);
                    }
                }
            }
            if ($soft) {
                $this->markSoftPurged($primary);
            } else {
                $this->store->removeIndex($primary);
            }

            return $count;
        } catch (\Throwable $e) {
            $this->logger->warning('cache_purge_failed', ['error' => get_class($e)]);

            return 0;
        }
    }

    public function purgePrefix(string $pathPrefix, bool $soft): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }
        $pos = strpos($pathPrefix, '?');
        $normalized = $this->builder->normalizePathAndQuery($pos === false ? $pathPrefix : substr($pathPrefix, 0, $pos));
        if ($normalized === null) {
            return 0;
        }
        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== ''));
        $matchAll = $segments === [];
        if (!$matchAll && !$this->config->storePlainPaths && count($segments) > self::PREFIX_DEPTH) {
            return 0;
        }
        $wantedHash = $matchAll ? '' : $this->prefixHash('/' . implode('/', $segments));
        $plainPrefix = '/' . implode('/', $segments);
        $depth = count($segments);

        $count = 0;
        $scanned = 0;
        $started = $this->clock->microtime();
        $softPrimaries = [];
        try {
            for ($shard = 0; $shard < CacheStore::SHARDS; $shard++) {
                foreach ($this->store->eachShardFile($shard) as $item) {
                    if ($scanned >= self::PURGE_MAX_ENTRIES || $this->clock->microtime() - $started > self::PURGE_MAX_SECONDS) {
                        break 2;
                    }
                    if (!CacheStore::isHash($item['name'])) {
                        continue;
                    }
                    $scanned++;
                    $loaded = $this->store->loadMeta($item['dir'] . '/' . $item['name'], false);
                    if ($loaded === null) {
                        continue;
                    }
                    $meta = $loaded['meta'];
                    if (!$matchAll && !$this->metaMatchesPrefix($meta, $plainPrefix, $wantedHash, $depth)) {
                        continue;
                    }
                    $count++;
                    if ($soft) {
                        if (isset($meta['primary']) && is_string($meta['primary']) && CacheStore::isHash($meta['primary'])) {
                            $softPrimaries[$meta['primary']] = true;
                        }
                    } else {
                        $this->store->deleteEntry($item['name']);
                    }
                }
            }
            foreach (array_keys($softPrimaries) as $primary) {
                $this->markSoftPurged($primary);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('cache_purge_failed', ['error' => get_class($e)]);
        }

        return $count;
    }

    public function purgeAll(bool $soft): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }
        $count = $this->store->usageTotals()['entries'];
        if ($soft) {
            $this->store->setSoftGeneration($this->clock->time());

            return $count;
        }
        $this->store->bumpGeneration();
        // Entries of the previous generation are unreachable now; reclaim as much as the budget allows.
        $this->gc->run(1500);

        return $count;
    }

    public function stats(): CacheStats
    {
        $totals = $this->store->usageTotals();

        return new CacheStats($totals['entries'], $totals['bytes'], $this->store->generation());
    }

    public function maybeGc(): void
    {
        if (!$this->isEnabled() || $this->config->gcProbability <= 0) {
            return;
        }
        try {
            if ($this->config->gcProbability < 100 && random_int(1, 100) > $this->config->gcProbability) {
                return;
            }
            $now = $this->clock->time();
            $last = $this->store->readCounter('gc.stamp');
            if ($last > 0 && $now - $last < $this->config->gcIntervalSeconds) {
                return;
            }
            if (!is_dir($this->store->base())) {
                return;
            }
            $this->store->writeCounter('gc.stamp', $now);
            $this->gc->run(200);
        } catch (\Throwable $e) {
            $this->logger->warning('cache_gc_failed', ['error' => get_class($e)]);
        }
    }

    public function gc(int $budgetMs): GcReport
    {
        return $this->gc->run($budgetMs);
    }

    // ---- internals --------------------------------------------------------------------------

    private function remember(string $primary, Request $request): void
    {
        if (count($this->recent) >= 8) {
            array_shift($this->recent);
        }
        $this->recent[$primary] = $request;
    }

    private function retainUntil(CacheEntry $entry): int
    {
        $latest = max($entry->freshUntil, $entry->swrUntil, $entry->staleIfErrorUntil);
        $validators = ($entry->originEtag !== null && $entry->originEtag !== '')
            || ($entry->originLastModified !== null && $entry->originLastModified !== '');

        return $latest + ($validators ? 3600 : 0);
    }

    /** Entry state with soft purge applied: a soft purged Fresh/SWR entry becomes Expired. */
    private function stateOf(CacheEntry $entry, int $indexSoftAt, int $now): CacheState
    {
        $state = $entry->state($now, $this->config->ignoreMustRevalidateOnError);
        if ($state === CacheState::Fresh || $state === CacheState::StaleWhileRevalidate) {
            $softAt = max($indexSoftAt, $this->store->softGeneration());
            if ($softAt > 0 && $entry->createdAt <= $softAt) {
                return CacheState::Expired;
            }
        }

        return $state;
    }

    private function readPublished(CacheKey $key, ?Request $request): ?CacheEntry
    {
        try {
            $index = $this->store->readIndex($key->primaryHash);
            $variant = $key->variantHash;
            if ($index !== null && $request !== null) {
                $variant = $this->builder->variantHash($key->primaryHash, $index['selector'], $request->headers);
            }
            $entry = $this->store->readEntry($variant);
            if ($entry === null) {
                return null;
            }
            $state = $this->stateOf($entry, $index['softAt'] ?? 0, $this->clock->time());

            return $state === CacheState::Fresh || $state === CacheState::StaleWhileRevalidate ? $entry : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $varySelector
     */
    private function doStore(
        CacheKey $key,
        int $status,
        HeaderBag $mirrorHeaders,
        string $identityBody,
        CacheDecision $decision,
        ?string $originEtag,
        ?string $originLastModified,
        array $varySelector,
    ): bool {
        if (!$this->isEnabled() || !$decision->storable) {
            return false;
        }
        if (!CacheStore::isHash($key->primaryHash) || !CacheStore::isHash($key->variantHash)
            || !in_array($status, $this->config->statuses, true) || $status === 206
            || strlen($identityBody) > $this->config->maxObjectBytes
        ) {
            return false;
        }
        // Defence in depth: the Kernel must not hand us these, but a stored copy would be a leak.
        if ($mirrorHeaders->has('set-cookie') || $mirrorHeaders->has('set-cookie2')) {
            return false;
        }
        foreach (['content-security-policy', 'content-security-policy-report-only'] as $csp) {
            foreach ($mirrorHeaders->getAll($csp) as $value) {
                if (stripos($value, 'nonce-') !== false) {
                    return false;
                }
            }
        }
        if (!$this->store->ensureReady()) {
            return false;
        }

        $headers = $this->filterHeaders($mirrorHeaders);
        $contentType = $mirrorHeaders->get('content-type') ?? '';
        if (!self::isCleanUtf8($contentType) || strlen($contentType) > 256) {
            $contentType = '';
        }

        $storedEncoding = 'identity';
        $storedBody = $identityBody;
        if (strlen($identityBody) > self::GZIP_MIN_BYTES && function_exists('gzencode') && self::isCompressible($contentType)) {
            $gz = gzencode($identityBody, 6);
            if (is_string($gz) && strlen($gz) < strlen($identityBody)) {
                $storedEncoding = 'gzip';
                $storedBody = $gz;
            }
        }

        $now = $this->clock->time();
        $freshUntil = $now + max(0, $decision->ttl);
        $selector = CacheKeyBuilder::canonicalSelector($varySelector);
        $path = $this->store->entryPath($key->variantHash);
        $oldSize = @filesize($path);
        $newSize = strlen($storedBody) + 2048;

        if (!$this->admit($oldSize !== false, $newSize - ($oldSize === false ? 0 : $oldSize))) {
            $this->logger->info('cache_store_refused', ['reason' => 'capacity']);

            return false;
        }

        if (!$this->registerQueryVariant($key)) {
            $this->logger->info('cache_store_refused', ['reason' => 'query_registry']);

            return false;
        }

        [$pathHash, $prefixHashes, $plain] = $this->pathMetadata($key->pathAndQuery);
        $meta = [
            'hasQuery' => str_contains($key->pathAndQuery, '?'),
            'gen' => $this->store->generation(),
            'primary' => $key->primaryHash,
            'variant' => $key->variantHash,
            'status' => $status,
            'headers' => $headers,
            'bodyLength' => strlen($storedBody),
            'identityLength' => strlen($identityBody),
            'storedEncoding' => $storedEncoding,
            'createdAt' => $now,
            'freshUntil' => $freshUntil,
            'swrUntil' => $freshUntil + max(0, $decision->swr),
            'staleIfErrorUntil' => $freshUntil + max(0, $decision->staleIfError),
            'originEtag' => self::cleanValidator($originEtag),
            'originLastModified' => self::cleanValidator($originLastModified),
            'mirrorEtag' => CacheEntry::etagFor($identityBody),
            'mustRevalidate' => $decision->mustRevalidate,
            'varySelector' => $selector,
            'contentType' => $contentType,
            'path' => $plain,
            'pathHash' => $pathHash,
            'prefixHashes' => $prefixHashes,
            'crc32' => CacheFormat::crc($storedBody),
        ];

        try {
            $contents = CacheFormat::encode($meta, $storedBody);
        } catch (\JsonException | \LengthException) {
            return false;
        }
        if (!$this->writeFile($path, $contents)) {
            return false;
        }
        $this->store->usageAdjust(
            $key->variantHash,
            $oldSize === false ? 1 : 0,
            strlen($contents) - ($oldSize === false ? 0 : $oldSize),
        );
        if (!$this->updateIndex($key, $selector, $now)) {
            // An entry that is not recorded in the index could not be purged by URL, so it must not stay.
            $this->store->deleteEntry($key->variantHash);
            $this->logger->info('cache_store_refused', ['reason' => 'index_lock']);

            return false;
        }

        return true;
    }

    private function doRefresh(CacheKey $key, CacheDecision $decision, ?HeaderBag $new304Headers): bool
    {
        if (!$this->isEnabled() || !CacheStore::isHash($key->variantHash)) {
            return false;
        }
        if (!$decision->storable) {
            // The origin no longer allows storing this representation.
            $this->store->deleteEntry($key->variantHash);

            return false;
        }
        $path = $this->store->entryPath($key->variantHash);
        $loaded = $this->store->loadMeta($path, false);
        if ($loaded === null) {
            return false;
        }
        $handle = CacheStore::openVerified($path);
        if ($handle === null) {
            return false;
        }
        try {
            fseek($handle, $loaded['offset']);
            $body = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        if (!is_string($body)) {
            return false;
        }

        $meta = $loaded['meta'];
        $now = $this->clock->time();
        $freshUntil = $now + max(0, $decision->ttl);
        $meta['createdAt'] = $now;
        $meta['freshUntil'] = $freshUntil;
        $meta['swrUntil'] = $freshUntil + max(0, $decision->swr);
        $meta['staleIfErrorUntil'] = $freshUntil + max(0, $decision->staleIfError);
        $meta['mustRevalidate'] = $decision->mustRevalidate;
        $meta['gen'] = $this->store->generation();
        if ($new304Headers !== null) {
            $etag = self::cleanValidator($new304Headers->get('etag'));
            if ($etag !== null) {
                $meta['originEtag'] = $etag;
            }
            $lastModified = self::cleanValidator($new304Headers->get('last-modified'));
            if ($lastModified !== null) {
                $meta['originLastModified'] = $lastModified;
            }
            $meta['headers'] = $this->mergeFreshnessHeaders($meta['headers'] ?? [], $new304Headers);
        }

        try {
            $contents = CacheFormat::encode($meta, $body);
        } catch (\JsonException | \LengthException) {
            return false;
        }
        if (!$this->writeFile($path, $contents)) {
            return false;
        }
        $this->store->usageAdjust($key->variantHash, 0, strlen($contents) - $loaded['size']);

        return true;
    }

    /**
     * @param mixed $stored
     * @return list<array{0:string,1:string}>
     */
    private function mergeFreshnessHeaders(mixed $stored, HeaderBag $update): array
    {
        $replace = [];
        foreach (['cache-control', 'expires'] as $name) {
            $values = $update->getAll($name);
            if ($values !== []) {
                $replace[$name] = $values;
            }
        }
        $out = [];
        if (is_array($stored)) {
            foreach ($stored as $pair) {
                if (is_array($pair) && isset($pair[0], $pair[1]) && is_string($pair[0]) && is_string($pair[1])
                    && !isset($replace[strtolower($pair[0])])
                ) {
                    $out[] = [$pair[0], $pair[1]];
                }
            }
        }
        foreach ($replace as $name => $values) {
            foreach ($values as $value) {
                if (self::isCleanUtf8($value) && strlen($value) <= self::MAX_HEADER_VALUE_BYTES) {
                    $out[] = [$name, $value];
                }
            }
        }

        return $out;
    }

    /** Write with one retry in case garbage collection removed the empty shard directory. */
    private function writeFile(string $path, string $contents): bool
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($this->store->ensureDirFor($path) && $this->store->writer()->write($path, $contents)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admission control for CACHE_MAX_ENTRIES and CACHE_MAX_BYTES: one bounded GC pass, then refuse.
     */
    private function admit(bool $replacing, int $deltaBytes): bool
    {
        if (!$this->isOverCapacity($replacing, $deltaBytes)) {
            return true;
        }
        $this->gc->run(250);

        return !$this->isOverCapacity($replacing, $deltaBytes);
    }

    /**
     * Reads shared usage counters, which other requests change at any time.
     *
     * @phpstan-impure
     */
    private function isOverCapacity(bool $replacing, int $deltaBytes): bool
    {
        $totals = $this->store->usageTotals();

        return (!$replacing && $totals['entries'] >= $this->config->maxEntries)
            || $totals['bytes'] + max(0, $deltaBytes) > $this->config->maxBytes;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function metaMatchesPrefix(array $meta, string $plainPrefix, string $wantedHash, int $depth): bool
    {
        $plain = $meta['path'] ?? '';
        if (is_string($plain) && $plain !== '') {
            $pos = strpos($plain, '?');
            $path = $pos === false ? $plain : substr($plain, 0, $pos);

            return $path === $plainPrefix || str_starts_with($path, $plainPrefix . '/');
        }
        $hashes = $meta['prefixHashes'] ?? null;

        return is_array($hashes) && ($hashes[$depth - 1] ?? null) === $wantedHash;
    }

    private function prefixHash(string $prefix): string
    {
        return substr(hash_hmac('sha256', 'prefix:' . $prefix, $this->pathMetaKey), 0, 32);
    }

    /**
     * @return array{0:string, 1:list<string>, 2:string} pathHash, prefix hashes, plaintext path (or '')
     */
    private function pathMetadata(string $pathAndQuery): array
    {
        $pos = strpos($pathAndQuery, '?');
        $path = $pos === false ? $pathAndQuery : substr($pathAndQuery, 0, $pos);
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        $prefixes = [];
        $acc = '';
        foreach (array_slice($segments, 0, self::PREFIX_DEPTH) as $segment) {
            $acc .= '/' . $segment;
            $prefixes[] = $this->prefixHash($acc);
        }
        $pathHash = substr(hash_hmac('sha256', 'path:' . $pathAndQuery, $this->pathMetaKey), 0, 32);

        return [$pathHash, $prefixes, $this->config->storePlainPaths && self::isCleanUtf8($pathAndQuery) ? $pathAndQuery : ''];
    }

    /**
     * Records the variant in the primary index. The read-modify-write cycle only runs while holding the
     * index lock; when the lock cannot be obtained the update is skipped and the caller discards the entry.
     *
     * @param list<string> $selector
     */
    private function updateIndex(CacheKey $key, array $selector, int $now): bool
    {
        $lock = $this->store->acquireIndexLock($key->primaryHash, 500);
        if ($lock === null && !CacheLock::isHeld($this->store->indexLockPath($key->primaryHash))) {
            return false;
        }
        try {
            $generation = $this->store->generation();
            $index = $this->store->readIndex($key->primaryHash);
            if ($index === null || $index['selector'] !== $selector || $index['gen'] !== $generation) {
                if ($index !== null) {
                    foreach ($index['variants'] as $old) {
                        if ($old !== $key->variantHash) {
                            $this->store->deleteEntry($old);
                        }
                    }
                }
                $index = [
                    'gen' => $generation,
                    'selector' => $selector,
                    'variants' => [],
                    'softAt' => $index['softAt'] ?? 0,
                ];
            }
            $variants = array_values(array_diff($index['variants'], [$key->variantHash]));
            $variants[] = $key->variantHash;
            while (count($variants) > self::MAX_VARIANTS_PER_URL) {
                $evicted = array_shift($variants);
                $this->store->deleteEntry($evicted);
            }
            $index['variants'] = $variants;

            return $this->store->writeIndex($key->primaryHash, $index);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Soft purge marker, written under the index lock so it cannot be overwritten by a concurrent fill.
     * When the lock is unavailable the entries are removed instead, which is the stronger purge.
     */
    private function markSoftPurged(string $primary): void
    {
        $lock = $this->store->acquireIndexLock($primary, 500);
        if ($lock === null && !CacheLock::isHeld($this->store->indexLockPath($primary))) {
            $index = $this->store->readIndex($primary);
            foreach ($index['variants'] ?? [] as $variant) {
                $this->store->deleteEntry($variant);
            }

            return;
        }
        try {
            $index = $this->store->readIndex($primary);
            if ($index !== null) {
                $index['softAt'] = max($index['softAt'], $this->clock->time());
                $this->store->writeIndex($primary, $index);
            }
        } finally {
            $lock?->release();
        }
    }

    /**
     * Bounds the number of distinct query strings stored per path (CACHE_MAX_QUERY_VARIANTS). When the cap
     * is reached the oldest query variant of that path is evicted, so cache-busting URLs only ever displace
     * each other.
     *
     * @return bool false when the registry could not be updated safely
     */
    private function registerQueryVariant(CacheKey $key): bool
    {
        $pos = strpos($key->pathAndQuery, '?');
        if ($pos === false) {
            return true;
        }
        $pathHash = $this->builder->primaryHashFor(substr($key->pathAndQuery, 0, $pos));
        $lock = $this->store->acquireIndexLock($pathHash, 500);
        if ($lock === null) {
            return false;
        }
        try {
            $cap = max(1, $this->config->maxQueryVariants);
            $list = array_values(array_diff($this->store->readQueryRegistry($pathHash), [$key->primaryHash]));
            if (count($list) >= $cap) {
                $list = array_values(array_filter($list, fn (string $p): bool => $this->store->readIndex($p) !== null));
            }
            $list[] = $key->primaryHash;
            while (count($list) > $cap) {
                $old = array_shift($list);
                $index = $this->store->readIndex($old);
                foreach ($index['variants'] ?? [] as $variant) {
                    $this->store->deleteEntry($variant);
                }
                $this->store->removeIndex($old);
            }

            return $this->store->writeQueryRegistry($pathHash, $list);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private function filterHeaders(HeaderBag $headers): array
    {
        $out = [];
        $total = 0;
        foreach ($headers->all() as [$name, $value]) {
            $lower = strtolower($name);
            if (in_array($lower, self::DENIED_HEADERS, true) || str_starts_with($lower, 'x-mirrorgate-')
                || strlen($value) > self::MAX_HEADER_VALUE_BYTES || !self::isCleanUtf8($name . $value)
            ) {
                continue;
            }
            $total += strlen($name) + strlen($value) + 8;
            if ($total > self::MAX_HEADERS_BYTES) {
                break;
            }
            $out[] = [$name, $value];
        }

        return $out;
    }

    private static function cleanValidator(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) > 256 || Str::hasControlChars($value) || !self::isCleanUtf8($value)) {
            return null;
        }

        return $value;
    }

    private static function isCleanUtf8(string $value): bool
    {
        return $value === '' || (preg_match('//u', $value) === 1 && !Str::hasControlChars($value));
    }

    private static function isCompressible(string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($type === '') {
            return false;
        }
        if (str_starts_with($type, 'text/')) {
            return true;
        }
        if (str_ends_with($type, '+json') || str_ends_with($type, '+xml')) {
            return true;
        }

        return in_array($type, [
            'application/json', 'application/javascript', 'application/x-javascript', 'application/ecmascript',
            'application/xml', 'application/xhtml+xml', 'application/rss+xml', 'application/atom+xml',
            'application/manifest+json', 'application/ld+json', 'image/svg+xml',
        ], true);
    }
}
