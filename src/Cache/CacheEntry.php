<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

use MirrorGate\Http\HeaderBag;
use MirrorGate\Support\FileUtil;

/**
 * Metadata of one stored response plus a lazy handle on its body.
 */
final readonly class CacheEntry
{
    /**
     * @param list<string> $varySelector
     */
    public function __construct(
        public int $status,
        public HeaderBag $headers,
        public int $bodyLength,
        public string $storedEncoding,
        public int $createdAt,
        public int $freshUntil,
        public int $swrUntil,
        public int $staleIfErrorUntil,
        public ?string $originEtag,
        public ?string $originLastModified,
        public string $mirrorEtag,
        public bool $mustRevalidate,
        public array $varySelector,
        public string $contentType,
        public string $pathAndQuery,
        public ?string $file = null,
        public int $bodyOffset = 0,
    ) {
    }

    /**
     * Pure function of timestamps and flags.
     *
     * A must-revalidate entry is never served stale while the origin is healthy (no
     * StaleWhileRevalidate); it may still be served on origin errors unless
     * $ignoreMustRevalidateOnError is false.
     */
    public function state(int $now, bool $ignoreMustRevalidateOnError): CacheState
    {
        if ($now < $this->freshUntil) {
            return CacheState::Fresh;
        }
        if (!$this->mustRevalidate && $now < $this->swrUntil) {
            return CacheState::StaleWhileRevalidate;
        }
        if ($now < $this->staleIfErrorUntil && (!$this->mustRevalidate || $ignoreMustRevalidateOnError)) {
            return CacheState::StaleUsableOnError;
        }

        return CacheState::Expired;
    }

    /** The single formula for the client-facing weak ETag. */
    public static function etagFor(string $identityBody): string
    {
        return 'W/"mg-' . substr(sha1($identityBody), 0, 16) . '"';
    }

    public function age(int $now): int
    {
        return max(0, $now - $this->createdAt);
    }

    /**
     * Opens the entry file (layout, symlink and containment checks first) and seeks to the body.
     *
     * @return resource bytes exactly as stored; apply $storedEncoding when serving
     * @throws \RuntimeException when the file is missing or fails verification
     */
    public function openBody()
    {
        $handle = $this->file === null ? null : CacheStore::openVerified($this->file);
        if ($handle === null) {
            throw new \RuntimeException('Cache entry body is not available.');
        }
        if (fseek($handle, $this->bodyOffset) !== 0) {
            fclose($handle);
            throw new \RuntimeException('Cache entry body is not available.');
        }

        return $handle;
    }

    /**
     * Body decoded to identity. Verifies length and CRC32; a corrupt file is deleted.
     *
     * @throws \RuntimeException when the body is unavailable or corrupt
     */
    public function bodyContents(): string
    {
        $file = $this->file;
        $handle = $file === null ? null : CacheStore::openVerified($file);
        if ($file === null || $handle === null) {
            throw new \RuntimeException('Cache entry body is not available.');
        }
        try {
            $header = CacheFormat::readHeader($handle);
            $raw = $header === null ? false : stream_get_contents($handle, $this->bodyLength + 1);
        } finally {
            fclose($handle);
        }
        if ($header === null || !is_string($raw) || $header['offset'] !== $this->bodyOffset
            || strlen($raw) !== $this->bodyLength
            || ($header['meta']['crc32'] ?? null) !== CacheFormat::crc($raw)
        ) {
            FileUtil::safeUnlink($file);
            throw new \RuntimeException('Cache entry is corrupt.');
        }
        if ($this->storedEncoding !== 'gzip') {
            return $raw;
        }
        $identityLength = $header['meta']['identityLength'] ?? null;
        $limit = is_int($identityLength) && $identityLength >= 0 ? $identityLength + 1 : $this->bodyLength * 20;
        $plain = @gzdecode($raw, max(1, $limit));
        if (!is_string($plain) || (is_int($identityLength) && strlen($plain) !== $identityLength)) {
            FileUtil::safeUnlink($file);
            throw new \RuntimeException('Cache entry is corrupt.');
        }

        return $plain;
    }

    /**
     * Validators for upstream revalidation.
     *
     * @return array<string,string>
     */
    public function conditionalHeaders(): array
    {
        $headers = [];
        if ($this->originEtag !== null && $this->originEtag !== '') {
            $headers['If-None-Match'] = $this->originEtag;
        }
        if ($this->originLastModified !== null && $this->originLastModified !== '') {
            $headers['If-Modified-Since'] = $this->originLastModified;
        }

        return $headers;
    }
}
