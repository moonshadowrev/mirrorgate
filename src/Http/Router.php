<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

use MirrorGate\Config\Config;

final class Router
{
    /** @var array<string,string> compiled glob => regex cache */
    private array $regexCache = [];

    public function route(Request $request, Config $config): Route
    {
        $path = $request->target->path;

        $prefix = $config->admin->pathPrefix;
        // With the admin surface disabled the prefix is an ordinary path and is proxied like any other.
        if ($config->admin->enabled && $config->admin->token !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            return new Route(RouteKind::Admin, null, $path);
        }

        if (str_starts_with($path, '/.well-known/acme-challenge/')) {
            return new Route(RouteKind::AcmeChallenge, null, $path);
        }

        if ($this->isBlocked($path, $config->security->blockedPaths)) {
            return new Route(RouteKind::Blocked, null, $path);
        }

        $sw = $request->headers->get('Service-Worker');
        if ($sw !== null && strtolower(trim($sw)) === 'script' && $config->rewrite->serviceWorkerMode === 'block') {
            return new Route(RouteKind::ServiceWorkerKill, null, $path);
        }

        if ($config->origin->subdomainMode === 'prefix') {
            $sub = $config->origin->subdomainPrefix;
            if ($path === $sub || str_starts_with($path, $sub . '/')) {
                $rest = substr($path, strlen($sub) + 1);
                if ($rest === '') {
                    return new Route(RouteKind::Blocked, null, $path);
                }
                $slash = strpos($rest, '/');
                $host = strtolower($slash === false ? $rest : substr($rest, 0, $slash));
                $originPath = $slash === false ? '/' : substr($rest, $slash);
                $known = $host === $config->origin->host || in_array($host, $config->origin->extraOrigins, true);
                if (!$known || $originPath === '' || $originPath[0] !== '/') {
                    return new Route(RouteKind::Blocked, null, $path);
                }
                // The policy applies to the path the origin will actually see, not only the mirror path.
                if ($this->isBlocked($originPath, $config->security->blockedPaths)) {
                    return new Route(RouteKind::Blocked, null, $path);
                }
                return new Route(RouteKind::Proxy, $host, $originPath);
            }
        }

        return new Route(RouteKind::Proxy, $config->origin->host, $path);
    }

    /**
     * Tests the path and the forms a backend may decode it to: encoded slashes and backslashes
     * resolved, and ";params" removed from every segment.
     *
     * @param list<string> $patterns
     */
    public function isBlocked(string $path, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }
        $candidates = [$path];
        $decoded = str_ireplace(['%2f', '%5c'], '/', $path);
        if ($decoded !== $path) {
            $candidates[] = $decoded;
        }
        foreach ($candidates as $candidate) {
            $stripped = preg_replace('#;[^/]*#', '', $candidate);
            if (is_string($stripped) && $stripped !== $candidate) {
                $candidates[] = $stripped;
            }
        }
        foreach (array_unique($candidates) as $candidate) {
            if ($this->matchesAny($candidate, $patterns)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (!isset($this->regexCache[$pattern])) {
                $quoted = preg_quote($pattern, '#');
                $quoted = str_replace(['\*', '\?'], ['.*', '.'], $quoted);
                $this->regexCache[$pattern] = '#\A' . $quoted . '\z#iD';
            }
            if (preg_match($this->regexCache[$pattern], $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
