<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Log\LoggerInterface;
use MirrorGate\Support\Clock;
use MirrorGate\Support\FileUtil;
use MirrorGate\Support\Json;

/**
 * File-backed DNS cache in front of another resolver (SEC-87).
 *
 * - A cached answer younger than $ttl is returned without any lookup.
 * - Otherwise the inner resolver is asked; a non-empty answer replaces the cache.
 * - When the inner resolver fails (empty answer or exception), a cached answer
 *   up to $ttl + $staleTtl old is still returned.
 * - Otherwise an empty list is returned and the caller (OriginGuard) falls back
 *   to ORIGIN_RESOLVE_IP.
 *
 * Cache file names are SHA-256 digests of the host name, so no request data
 * forms a path. Answers are NOT trusted: OriginGuard re-validates every
 * returned address on every call, including cache hits.
 */
final class CachingDnsResolver implements DnsResolverInterface
{
    private const MAX_FILE_BYTES = 8192;
    private const MAX_ANSWERS = 16;

    /** @var array<string,array{at:int,ips:list<string>}> per-process memo of answers written by this process */
    private array $memo = [];

    private bool $dirReady = false;

    public function __construct(
        private readonly DnsResolverInterface $inner,
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly int $ttl,
        private readonly int $staleTtl,
        private readonly LoggerInterface $log,
    ) {
    }

    public function resolve(string $host): array
    {
        $host = \strtolower(\rtrim($host, '.'));
        if ($host === '') {
            return [];
        }

        $now = $this->clock->time();
        $cached = $this->read($host);

        if ($cached !== null && ($now - $cached['at']) >= 0 && ($now - $cached['at']) < $this->ttl) {
            return $cached['ips'];
        }

        $fresh = [];
        try {
            $fresh = $this->inner->resolve($host);
        } catch (\Throwable) {
            $this->log->warning('dns resolver failed');
        }
        $fresh = $this->sanitize($fresh);

        if ($fresh !== []) {
            $this->write($host, $now, $fresh);

            return $fresh;
        }

        if ($cached !== null) {
            $age = $now - $cached['at'];
            if ($age >= 0 && $age < ($this->ttl + $this->staleTtl)) {
                $this->log->info('dns serving stale answer', ['age' => $age]);

                return $cached['ips'];
            }
        }

        return [];
    }

    private function path(string $host): string
    {
        return $this->dir . '/' . \substr(\hash('sha256', $host), 0, 32) . '.json';
    }

    /**
     * @return array{at:int,ips:list<string>}|null
     */
    private function read(string $host): ?array
    {
        if (isset($this->memo[$host])) {
            return $this->memo[$host];
        }

        $raw = FileUtil::readFile($this->path($host), self::MAX_FILE_BYTES);
        if ($raw === null) {
            return null;
        }

        try {
            $data = Json::decode($raw, 4);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !isset($data['at'], $data['ips'], $data['host'])
            || !\is_int($data['at']) || !\is_array($data['ips']) || $data['host'] !== $host) {
            return null;
        }

        $ips = $this->sanitize($data['ips']);
        if ($ips === []) {
            return null;
        }

        return ['at' => $data['at'], 'ips' => $ips];
    }

    /**
     * @param list<string> $ips
     */
    private function write(string $host, int $now, array $ips): void
    {
        $this->memo[$host] = ['at' => $now, 'ips' => $ips];

        try {
            if (!$this->dirReady) {
                FileUtil::ensureDir($this->dir);
                $this->dirReady = true;
            }
            FileUtil::atomicWrite(
                $this->path($host),
                Json::encode(['host' => $host, 'at' => $now, 'ips' => $ips]),
            );
        } catch (\Throwable) {
            $this->log->warning('dns cache not writable');
        }
    }

    /**
     * @param array<mixed> $ips
     * @return list<string>
     */
    private function sanitize(array $ips): array
    {
        $out = [];
        foreach ($ips as $ip) {
            if (\is_string($ip) && \filter_var($ip, FILTER_VALIDATE_IP) !== false && !\in_array($ip, $out, true)) {
                $out[] = $ip;
            }
            if (\count($out) >= self::MAX_ANSWERS) {
                break;
            }
        }

        return $out;
    }
}
