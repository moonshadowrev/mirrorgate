<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Config\OriginConfig;
use MirrorGate\Http\NormalizedTarget;

/**
 * Builds upstream URLs from configuration plus a normalized path and query.
 * Nothing client-controlled other than that path and query ever reaches the
 * scheme, host or port (SEC-01).
 */
final class UpstreamUrlBuilder
{
    public function __construct(private readonly OriginConfig $origin)
    {
    }

    /**
     * @throws \InvalidArgumentException when $originHost is neither the origin
     *         host nor an EXTRA_ORIGINS host, or the target contains bytes that
     *         cannot appear in a request line
     */
    public function build(NormalizedTarget $t, string $originHost): UpstreamTarget
    {
        $host = self::canonicalHost($originHost);
        $isPrimary = $host === self::canonicalHost($this->origin->host);

        if (!$isPrimary && !$this->isExtraOrigin($host)) {
            throw new \InvalidArgumentException('Origin host is not configured.');
        }

        $path = $t->path;
        $query = $t->query;
        if ($path === '' || $path[0] !== '/' || self::hasForbiddenBytes($path, true)) {
            throw new \InvalidArgumentException('Invalid upstream path.');
        }
        if ($query !== '' && self::hasForbiddenBytes($query, false)) {
            throw new \InvalidArgumentException('Invalid upstream query.');
        }

        $scheme = $this->origin->scheme;
        $port = $this->origin->port;
        $pathAndQuery = $t->pathAndQuery();

        return new UpstreamTarget(
            $scheme,
            $host,
            $port,
            $scheme . '://' . self::authority($scheme, $host, $port) . $pathAndQuery,
            $isPrimary ? $this->origin->hostHeader : self::authority($scheme, $host, $port),
            $pathAndQuery,
        );
    }

    /**
     * "host" or "host:port" (port omitted when it is the scheme default);
     * IPv6 literals are bracketed.
     */
    public static function authority(string $scheme, string $host, int $port): string
    {
        $h = \str_contains($host, ':') ? '[' . $host . ']' : $host;
        $default = $scheme === 'https' ? 443 : 80;

        return $port === $default ? $h : $h . ':' . $port;
    }

    private function isExtraOrigin(string $host): bool
    {
        foreach ($this->origin->extraOrigins as $extra) {
            if ($host === self::canonicalHost($extra)) {
                return true;
            }
        }

        return false;
    }

    private static function canonicalHost(string $host): string
    {
        $host = \strtolower($host);
        if (\str_ends_with($host, '.')) {
            $host = \substr($host, 0, -1);
        }
        if ($host !== '' && $host[0] === '[' && \str_ends_with($host, ']')) {
            $host = \substr($host, 1, -1);
        }

        return $host;
    }

    private static function hasForbiddenBytes(string $s, bool $isPath): bool
    {
        // Controls, space and DEL can never appear in a request target; '#'
        // starts a fragment; a backslash is treated as a slash by some servers.
        if (\preg_match('/[\x00-\x20\x7F#]/', $s) === 1) {
            return true;
        }

        return $isPath && \str_contains($s, '\\');
    }
}
