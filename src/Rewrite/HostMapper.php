<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

use MirrorGate\Config\MirrorConfig;
use MirrorGate\Config\OriginConfig;

/**
 * Maps origin hosts to mirror locations and back.
 *
 * Three kinds of origin host exist:
 *  - the primary origin host (ORIGIN_URL), mirrored 1:1 onto the mirror host;
 *  - HOST_MAP hosts, mirrored 1:1 onto sibling mirror hosts served by other instances;
 *  - prefix-mode hosts (EXTRA_ORIGINS with SUBDOMAIN_MODE=prefix), served below
 *    `<mirror>/<SUBDOMAIN_PREFIX>/<origin host>`.
 *
 * Every returned string is built from validated configuration plus the untouched remainder of the
 * input URL (path, query, fragment); a host that is not handled yields null.
 */
final class HostMapper
{
    /** @var array<string,string> lowercase origin host => mirror host (1:1 hosts, primary first) */
    private array $oneToOne = [];

    /** @var array<string,string> lowercase mirror host => origin host */
    private array $reverse = [];

    /** @var array<string,true> lowercase prefix-mode origin hosts (excluding 1:1 hosts) */
    private array $prefixHosts = [];

    /** @var list<string> */
    private array $allOriginHosts;

    private readonly string $mirrorScheme;
    private readonly string $mirrorPortSuffix;
    private readonly string $prefix;
    private readonly bool $prefixMode;

    public function __construct(
        private readonly OriginConfig $origin,
        private readonly MirrorConfig $mirror,
    ) {
        $this->mirrorScheme = $mirror->isHttps ? 'https' : 'http';
        $defaultPort = $mirror->isHttps ? 443 : 80;
        $this->mirrorPortSuffix = $mirror->port === $defaultPort ? '' : ':' . $mirror->port;
        $this->prefix = rtrim($origin->subdomainPrefix, '/');
        $this->prefixMode = $origin->subdomainMode === 'prefix';

        $primary = self::normalizeHost($origin->host);
        $this->oneToOne[$primary] = self::normalizeHost($mirror->host);

        foreach ($origin->hostMap as $originHost => $mirrorHost) {
            $originHost = self::normalizeHost((string) $originHost);
            $mirrorHost = self::normalizeHost($mirrorHost);
            if ($originHost === '' || $mirrorHost === '' || isset($this->oneToOne[$originHost])) {
                continue;
            }
            $this->oneToOne[$originHost] = $mirrorHost;
        }

        foreach ($this->oneToOne as $originHost => $mirrorHost) {
            if (!isset($this->reverse[$mirrorHost])) {
                $this->reverse[$mirrorHost] = $originHost;
            }
        }
        foreach ($mirror->allowedHosts as $alias) {
            $alias = self::normalizeHost($alias);
            if ($alias !== '' && !isset($this->reverse[$alias])) {
                $this->reverse[$alias] = $primary;
            }
        }
        // The primary mirror host always maps back to the primary origin.
        $this->reverse[self::normalizeHost($mirror->host)] = $primary;

        if ($this->prefixMode) {
            foreach ($origin->extraOrigins as $extra) {
                $extra = self::normalizeHost($extra);
                if ($extra !== '' && !isset($this->oneToOne[$extra])) {
                    $this->prefixHosts[$extra] = true;
                }
            }
        }

        $hosts = array_merge(array_keys($this->oneToOne), array_keys($this->prefixHosts));
        $hosts = array_values(array_unique($hosts));
        usort($hosts, static function (string $a, string $b): int {
            $byLength = strlen($b) <=> strlen($a);
            return $byLength !== 0 ? $byLength : strcmp($a, $b);
        });
        $this->allOriginHosts = $hosts;
    }

    /**
     * Base URL (scheme + authority, plus the prefix path for prefix-mode hosts) under which the mirror
     * serves the given origin host, or null when the host is not handled.
     */
    public function mirrorBaseFor(string $originHost): ?string
    {
        $host = self::normalizeHost($originHost);
        if (isset($this->oneToOne[$host])) {
            return $this->mirrorScheme . '://' . $this->mirrorAuthority($host);
        }
        if (isset($this->prefixHosts[$host])) {
            return $this->mirrorScheme . '://' . $this->mirror->hostHeader . $this->prefix . '/' . $host;
        }
        return null;
    }

    /**
     * Mirror authority (host plus a non-default port) for 1:1 mapped hosts; null for prefix-mode
     * hosts and unknown hosts.
     */
    public function mirrorAuthorityFor(string $originHost): ?string
    {
        $host = self::normalizeHost($originHost);
        return isset($this->oneToOne[$host]) ? $this->mirrorAuthority($host) : null;
    }

    /** Bare mirror host name (no port) for 1:1 mapped origin hosts. */
    public function mirrorHostNameFor(string $originHost): ?string
    {
        $host = self::normalizeHost($originHost);
        return $this->oneToOne[$host] ?? null;
    }

    /**
     * Path prefix that root-relative URLs of the given origin host need on the mirror
     * (`/__mg/s/<host>` for prefix-mode hosts, empty string otherwise).
     */
    public function prefixForOrigin(string $originHost): string
    {
        $host = self::normalizeHost($originHost);
        return isset($this->prefixHosts[$host]) ? $this->prefix . '/' . $host : '';
    }

    /** Origin host that serves requests addressed to the given mirror host (port ignored), else null. */
    public function originHostFor(string $mirrorHost): ?string
    {
        $host = self::normalizeHost(self::stripPort($mirrorHost));
        return $this->reverse[$host] ?? null;
    }

    /** @return list<string> every mirror host name this instance recognizes, longest first */
    public function mirrorHosts(): array
    {
        $hosts = array_keys($this->reverse);
        usort($hosts, static function (string $a, string $b): int {
            $byLength = strlen($b) <=> strlen($a);
            return $byLength !== 0 ? $byLength : strcmp($a, $b);
        });
        return $hosts;
    }

    /** True when the host is one of the mirror hosts (primary, alias or HOST_MAP sibling). */
    public function isMirrorHost(string $host): bool
    {
        return $this->originHostFor($host) !== null;
    }

    /** @return list<string> every origin host that can be rewritten, longest first */
    public function originHosts(): array
    {
        return $this->allOriginHosts;
    }

    /** @return list<string> origin hosts mapped 1:1 (primary and HOST_MAP), longest first */
    public function oneToOneHosts(): array
    {
        $hosts = array_keys($this->oneToOne);
        usort($hosts, static function (string $a, string $b): int {
            $byLength = strlen($b) <=> strlen($a);
            return $byLength !== 0 ? $byLength : strcmp($a, $b);
        });
        return $hosts;
    }

    public function isOriginHost(string $host): bool
    {
        $host = self::normalizeHost(self::stripPort($host));
        return isset($this->oneToOne[$host]) || isset($this->prefixHosts[$host]);
    }

    public function isPrefixHost(string $host): bool
    {
        return isset($this->prefixHosts[self::normalizeHost($host)]);
    }

    /**
     * Maps an absolute http(s) URL of a handled origin host to its mirror URL. The path, query and
     * fragment are carried over byte for byte. Returns null for other hosts, other schemes,
     * userinfo, or an explicit port that does not belong to the origin.
     */
    public function mapAbsoluteUrl(string $url): ?string
    {
        $parts = self::splitAbsolute($url);
        if ($parts === null) {
            return null;
        }
        [, $host, $port, $rest] = $parts;
        if (!$this->originPortAllowed($host, $port)) {
            return null;
        }
        $base = $this->mirrorBaseFor($host);
        if ($base === null) {
            return null;
        }
        return $base . $rest;
    }

    /**
     * Maps an absolute http(s) URL on a mirror host back to the origin. Used in the request
     * direction (Origin and Referer). Returns null for unknown hosts.
     */
    public function unmapAbsoluteUrl(string $url): ?string
    {
        $parts = self::splitAbsolute($url);
        if ($parts === null) {
            return null;
        }
        [, $host, $port, $rest] = $parts;
        $defaultPort = $this->mirror->isHttps ? 443 : 80;
        if ($port !== null && $port !== $this->mirror->port && $port !== $defaultPort) {
            return null;
        }
        $originHost = $this->reverse[$host] ?? null;
        if ($originHost === null) {
            return null;
        }

        if ($this->prefixMode && $this->prefix !== '' && str_starts_with($rest, $this->prefix . '/')) {
            $after = substr($rest, strlen($this->prefix) + 1);
            $end = strcspn($after, '/?#');
            $candidate = strtolower(substr($after, 0, $end));
            if (isset($this->prefixHosts[$candidate]) || $candidate === self::normalizeHost($this->origin->host)) {
                $remainder = substr($after, $end);
                if ($remainder === '' || $remainder[0] !== '/') {
                    $remainder = '/' . $remainder;
                }
                return $this->originBase($candidate) . $remainder;
            }
        }
        return $this->originBase($originHost) . $rest;
    }

    private function originBase(string $originHost): string
    {
        $primary = self::normalizeHost($this->origin->host);
        $authority = $originHost === $primary ? $this->origin->hostHeader : $originHost;
        return $this->origin->scheme . '://' . $authority;
    }

    private function mirrorAuthority(string $originHost): string
    {
        $mirrorHost = $this->oneToOne[$originHost];
        if ($mirrorHost === self::normalizeHost($this->mirror->host)) {
            return $this->mirror->hostHeader;
        }
        return $mirrorHost . $this->mirrorPortSuffix;
    }

    /**
     * True when an explicit port written after an origin host belongs to that origin: absent, the
     * default port of either scheme, or the configured origin port of the primary host.
     */
    public function originPortAllowed(string $host, ?int $port): bool
    {
        $host = self::normalizeHost($host);
        if ($port === null) {
            return true;
        }
        if ($port === 80 || $port === 443) {
            return true;
        }
        return $host === self::normalizeHost($this->origin->host) && $port === $this->origin->port;
    }

    /**
     * @return array{0:string,1:string,2:?int,3:string}|null scheme, lowercase host, port, remainder
     */
    private static function splitAbsolute(string $url): ?array
    {
        if (preg_match('~^(https?)://([^/?#:@\\\\\s\x00-\x1F\x7F\[\]]+)(?::(\d{1,5}))?([/?#].*)?$~isD', $url, $m) !== 1) {
            return null;
        }
        $port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
        return [strtolower($m[1]), self::normalizeHost($m[2]), $port, $m[4] ?? ''];
    }

    public static function normalizeHost(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }

    private static function stripPort(string $host): string
    {
        $host = trim($host);
        if ($host !== '' && $host[0] === '[') {
            return $host;
        }
        $colon = strrpos($host, ':');
        return $colon === false ? $host : substr($host, 0, $colon);
    }
}
