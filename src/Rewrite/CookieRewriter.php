<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

use MirrorGate\Config\MirrorConfig;
use MirrorGate\Config\RewriteConfig;

/**
 * Rewrites one `Set-Cookie` header line for delivery from the mirror host.
 *
 * Each line is handled on its own (the Expires attribute contains a comma, so Set-Cookie values
 * are never split on commas). Cookies with control characters, oversized names or values, or a
 * name in the mirror-reserved `mg_` namespace are dropped.
 */
final class CookieRewriter
{
    private const MAX_NAME = 256;
    private const MAX_LINE = 4096;

    public function __construct(
        private readonly RewriteConfig $config,
        private readonly MirrorConfig $mirror,
        private readonly HostMapper $mapper,
    ) {
    }

    /**
     * @param string|null $originHost origin host that sent the cookie; prefix-mode hosts get a scoped Path
     * @param string|null $requestHost host name the client addressed (validated), used for Domain
     * @return string|null the rewritten line, or null when the cookie must be dropped
     */
    public function rewrite(string $line, ?string $originHost = null, ?string $requestHost = null): ?string
    {
        $scope = $originHost !== null ? $this->mapper->prefixForOrigin($originHost) : '';
        if (strlen($line) > self::MAX_LINE || preg_match('~[\x00-\x1F\x7F]~', $line) === 1) {
            return null;
        }

        $parts = explode(';', $line);
        $first = trim((string) array_shift($parts));
        $equals = strpos($first, '=');
        if ($equals === false || $equals === 0) {
            return null;
        }
        $name = substr($first, 0, $equals);
        if (strlen($name) > self::MAX_NAME || preg_match('~^(?:__host-|__secure-)?mg_~i', $name) === 1) {
            return null;
        }
        $hostPrefixed = strncasecmp($name, '__Host-', 7) === 0;
        if ($scope !== '' && $hostPrefixed) {
            return null; // __Host- cookies require Path=/, which would span every host sharing the mirror
        }
        $hasPath = false;

        $out = [$first];
        $hasSecure = false;
        $httpOnly = false;
        $partitioned = false;
        $sameSite = null;

        foreach ($parts as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $eq = strpos($raw, '=');
            $attrName = strtolower(trim($eq === false ? $raw : substr($raw, 0, $eq)));
            $attrValue = $eq === false ? '' : trim(substr($raw, $eq + 1));

            switch ($attrName) {
                case 'domain':
                    if ($scope === '' && !$hostPrefixed && $this->config->cookieDomainMode === 'rewrite') {
                        $mapped = $this->mapDomain($attrValue, $requestHost);
                        if ($mapped !== null) {
                            $out[] = 'Domain=' . $mapped;
                        }
                    }
                    break;
                case 'path':
                    $hasPath = true;
                    $out[] = 'Path=' . ($scope === '' ? $attrValue : self::scopedPath($scope, $attrValue));
                    break;
                case 'expires':
                    $out[] = 'Expires=' . $attrValue;
                    break;
                case 'max-age':
                    $out[] = 'Max-Age=' . $attrValue;
                    break;
                case 'secure':
                    $hasSecure = true;
                    break;
                case 'httponly':
                    $httpOnly = true;
                    break;
                case 'partitioned':
                    $partitioned = true;
                    break;
                case 'samesite':
                    $normalized = ucfirst(strtolower($attrValue));
                    if (in_array($normalized, ['Strict', 'Lax', 'None'], true)) {
                        $sameSite = $normalized;
                    }
                    break;
                default:
                    if (preg_match('~^[A-Za-z0-9_-]+(?:=[^;]*)?$~', $raw) === 1) {
                        $out[] = $raw;
                    }
                    break;
            }
        }

        if ($scope !== '' && !$hasPath) {
            $out[] = 'Path=' . $scope;
        }

        $secure = false;
        if ($this->mirror->isHttps) {
            $secure = $hasSecure || $this->config->cookieForceSecure || $sameSite === 'None' || $partitioned;
        } elseif ($sameSite === 'None') {
            $sameSite = 'Lax';
        }

        if ($secure) {
            $out[] = 'Secure';
        }
        if ($httpOnly) {
            $out[] = 'HttpOnly';
        }
        if ($sameSite !== null) {
            $out[] = 'SameSite=' . $sameSite;
        }
        if ($partitioned && $secure) {
            $out[] = 'Partitioned';
        }

        return implode('; ', $out);
    }

    /**
     * Maps the Domain attribute of an origin cookie to the mirror host. Only a Domain that equals a
     * handled origin host is mapped, and only when the browser would accept it for the mirror host.
     */
    private function mapDomain(string $domain, ?string $requestHost): ?string
    {
        $domain = strtolower(ltrim($domain, '.'));
        if ($domain === '') {
            return null;
        }
        $mirrorDomain = $this->mapper->mirrorHostNameFor($domain);
        if ($mirrorDomain === null) {
            return null;
        }
        // The browser only accepts a Domain that matches the host it is talking to, which may be an alias.
        $mirrorHost = HostMapper::normalizeHost($requestHost ?? $this->mirror->host);
        if ($mirrorHost !== $mirrorDomain && !str_ends_with($mirrorHost, '.' . $mirrorDomain)) {
            return null;
        }
        return $mirrorDomain;
    }

    /** Confines a cookie Path of a prefix-mode origin host to that host's prefix on the mirror. */
    private static function scopedPath(string $scope, string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/') {
            return $scope; // the default-path rules of the browser would apply; stay inside the prefix
        }

        return $path === '/' ? $scope : $scope . $path;
    }
}
