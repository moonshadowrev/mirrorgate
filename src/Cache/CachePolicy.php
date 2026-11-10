<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

use MirrorGate\Config\CacheConfig;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\Request;
use MirrorGate\Support\Clock;

/**
 * Decides whether a response may be stored and for how long.
 *
 * Freshness precedence: CACHE_RULES override, else s-maxage, max-age, Expires, Last-Modified
 * heuristic (10 percent of the document age), CACHE_DEFAULT_TTL; clamped to
 * [CACHE_MIN_TTL, CACHE_MAX_TTL] except for rule overrides.
 */
final class CachePolicy
{
    private const MAX_WINDOW = 2592000; // 30 days cap for swr / stale-if-error values

    /** Request headers of framework-internal protocols whose responses must never be shared. */
    private const BYPASS_REQUEST_HEADERS = [
        'authorization', 'range', 'upgrade',
        'rsc', 'next-router-state-tree', 'next-router-prefetch', 'next-url', 'next-action',
        'service-worker',
    ];

    /** Request headers a cache fill never sends and whose Vary mention therefore cannot change the stored body. */
    private const VARY_NEUTRAL = [
        'accept-encoding', 'origin', 'content-type', 'service-worker',
        'rsc', 'next-router-state-tree', 'next-router-prefetch', 'next-url', 'next-action',
    ];

    /** @var array<string,string> glob => compiled regex */
    private array $compiled = [];

    public function __construct(private readonly CacheConfig $config, private readonly Clock $clock)
    {
    }

    /**
     * Pre-lookup eligibility of a client request.
     */
    public function isRequestEligible(Request $request): bool
    {
        if (!$this->config->enabled) {
            return false;
        }
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return false;
        }
        foreach (self::BYPASS_REQUEST_HEADERS as $name) {
            if ($request->headers->has($name)) {
                return false;
            }
        }
        foreach ($this->config->bypassHeaders as $name) {
            $value = $request->headers->get($name);
            if ($value !== null && $value !== '') {
                return false;
            }
        }
        if (!self::originIsSameSite($request)) {
            return false;
        }
        $path = $request->target->path;
        $cookie = $request->headers->get('cookie');
        if ($cookie !== null && trim($cookie) !== '') {
            if ($this->config->cookieMode !== 'static-only') {
                return false;
            }
            if (!in_array(self::extensionOf($path), $this->config->staticExtensions, true)) {
                return false;
            }
        }
        $rule = $this->ruleTtl($path);
        if ($rule !== null && $rule < 0) {
            return false;
        }
        if ($this->config->honorClientNoCache) {
            $cc = self::parseCacheControl($request->headers->getAll('cache-control'));
            if (array_key_exists('no-cache', $cc) || array_key_exists('no-store', $cc) || (array_key_exists('max-age', $cc) && (int) $cc['max-age'] === 0)) {
                return false;
            }
            $pragma = $request->headers->get('pragma');
            if ($pragma !== null && stripos($pragma, 'no-cache') !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * A cross-site Origin header (CORS) is never answered from or stored in the shared cache: the fill
     * request does not carry it, so the stored response could not match what the origin would send.
     */
    private static function originIsSameSite(Request $request): bool
    {
        $origin = $request->headers->get('origin');
        if ($origin === null || trim($origin) === '') {
            return true;
        }
        $host = parse_url(trim($origin), PHP_URL_HOST);
        $own = parse_url('//' . $request->hostHeader, PHP_URL_HOST);

        return is_string($host) && is_string($own) && strcasecmp($host, $own) === 0;
    }

    /**
     * @param int|null $bodyLength identity body length, null when not known yet
     */
    public function evaluate(Request $request, int $status, HeaderBag $originHeaders, ?int $bodyLength): CacheDecision
    {
        $no = static fn (string $reason): CacheDecision => new CacheDecision(false, 0, 0, 0, false, $reason);

        if (!$this->config->enabled) {
            return $no('cache_disabled');
        }
        if ($request->method !== 'GET') {
            return $no('method');
        }
        if ($request->headers->has('authorization')) {
            return $no('authorization');
        }
        if ($status === 206 || $originHeaders->has('content-range')) {
            return $no('partial_content');
        }
        if (!in_array($status, $this->config->statuses, true)) {
            return $no('status_not_cacheable');
        }
        if ($originHeaders->has('set-cookie')) {
            return $no('set_cookie');
        }
        foreach ($originHeaders->getAll('vary') as $value) {
            foreach (explode(',', $value) as $token) {
                $token = strtolower(trim($token));
                if ($token === '*') {
                    return $no('vary_star');
                }
                if ($token === 'cookie' || $token === 'authorization') {
                    return $no('vary_personalized');
                }
                if ($token !== '' && !in_array($token, self::VARY_NEUTRAL, true) && !in_array($token, $this->config->varyExtra, true)) {
                    // The fill request never sends this header, so one anonymous representation
                    // would be served to clients that asked for a different one.
                    return $no('vary_unsupported');
                }
            }
        }
        foreach (['content-security-policy', 'content-security-policy-report-only'] as $csp) {
            foreach ($originHeaders->getAll($csp) as $value) {
                if (stripos($value, 'nonce-') !== false) {
                    return $no('csp_nonce');
                }
            }
        }
        $cc = self::parseCacheControl($originHeaders->getAll('cache-control'));
        if (array_key_exists('no-store', $cc)) {
            return $no('no_store');
        }
        if (array_key_exists('private', $cc)) {
            return $no('private');
        }
        $path = $request->target->path;
        $rule = $this->ruleTtl($path);
        if ($rule !== null && $rule < 0) {
            return $no('rule_bypass');
        }
        $negative = $status === 404 || $status === 410;
        if ($negative && $request->target->query !== '' && $rule === null) {
            return $no('negative_with_query');
        }
        if ($bodyLength !== null && $bodyLength > $this->config->maxObjectBytes) {
            return $no('too_large');
        }

        $hasValidator = $originHeaders->has('etag') || $originHeaders->has('last-modified');
        $noCache = array_key_exists('no-cache', $cc);
        $mustRevalidate = $noCache || array_key_exists('must-revalidate', $cc) || array_key_exists('proxy-revalidate', $cc);
        if ($noCache && !$hasValidator && $rule === null) {
            return $no('no_cache_no_validator');
        }

        $now = $this->clock->time();
        $explicit = $this->explicitFreshness($cc, $originHeaders, $now);
        if ($explicit !== null) {
            $age = $originHeaders->get('age');
            if ($age !== null && ctype_digit(trim($age)) && strlen(trim($age)) <= 10) {
                $explicit = max(0, $explicit - (int) trim($age));
            }
        }
        $ttl = $explicit ?? $this->heuristicFreshness($originHeaders, $now) ?? $this->config->defaultTtl;
        if ($negative) {
            $ttl = $explicit !== null ? min($explicit, $this->config->negativeTtl) : $this->config->negativeTtl;
        }
        if ($rule !== null) {
            $ttl = $rule;
        } else {
            $ttl = max($this->config->minTtl, min($this->config->maxTtl, $ttl));
        }
        if ($noCache) {
            $ttl = 0;
        }

        $swr = $mustRevalidate ? 0 : self::directiveSeconds($cc, 'stale-while-revalidate') ?? $this->config->swrSeconds;
        $sie = self::directiveSeconds($cc, 'stale-if-error') ?? $this->config->staleIfErrorSeconds;

        return new CacheDecision(
            true,
            $ttl,
            max(0, min(self::MAX_WINDOW, $swr)),
            max(0, min(self::MAX_WINDOW, $sie)),
            $mustRevalidate,
            'ok',
        );
    }

    /**
     * True when an HTML body carries a CSP meta tag that contains a nonce. Fails closed: when the
     * scan itself fails, the body is reported as unsafe to store.
     */
    public function bodyContainsCspNonce(string $identityBody): bool
    {
        if (stripos($identityBody, 'nonce-') === false) {
            return false;
        }
        $found = preg_match_all(
            '/<meta\b[^>]*http-equiv\s*=\s*["\']?content-security-policy(?:-report-only)?["\']?[^>]*>/i',
            $identityBody,
            $matches,
        );
        if ($found === false) {
            return true;
        }
        foreach ($matches[0] as $tag) {
            if (stripos($tag, 'nonce-') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vary header names (from the final mirror-side headers) that can change what an anonymous cache
     * fill returns, merged with CACHE_VARY_EXTRA. Names the cache-fill request builder never sends
     * (6.1) cannot influence the stored response, so they are not part of the key; this keeps a
     * client from multiplying entries with arbitrary values of an ignored header.
     *
     * @return list<string>
     */
    public function varySelector(HeaderBag $originHeaders): array
    {
        // The fill request carries none of the other Vary names, so only CACHE_VARY_EXTRA selects variants.
        return CacheKeyBuilder::canonicalSelector(array_map('strtolower', $this->config->varyExtra));
    }

    /**
     * TTL of the first matching CACHE_RULES entry, or null when none matches. -1 means bypass.
     */
    public function ruleTtl(string $path): ?int
    {
        foreach ($this->config->rules as $rule) {
            $glob = $rule['pattern'];
            $regex = $this->compiled[$glob] ??= self::globToRegex($glob);
            if (preg_match($regex, $path) === 1) {
                return $rule['ttl'];
            }
        }

        return null;
    }

    /**
     * Parses Cache-Control values. Duplicate numeric max-age / s-maxage / swr / sie directives keep
     * the smallest value; other duplicates keep the first.
     *
     * @param list<string> $values
     * @return array<string,string|null> lowercase directive => unquoted argument
     */
    public static function parseCacheControl(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $count = preg_match_all(
                '/([A-Za-z0-9!#$%&\'*+.^_`|~-]+)\s*(?:=\s*("(?:[^"\\\\]|\\\\.)*"|[^,\s]*))?/',
                $value,
                $matches,
                PREG_SET_ORDER,
            );
            if ($count === false) {
                continue;
            }
            foreach ($matches as $m) {
                $name = strtolower($m[1]);
                $arg = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
                if ($arg !== null && $arg[0] === '"') {
                    $arg = stripcslashes(substr($arg, 1, -1));
                }
                if (!array_key_exists($name, $out)) {
                    $out[$name] = $arg;
                } elseif (in_array($name, ['max-age', 's-maxage', 'stale-while-revalidate', 'stale-if-error'], true)) {
                    $a = self::seconds($out[$name]);
                    $b = self::seconds($arg);
                    $out[$name] = (string) min($a ?? 0, $b ?? 0);
                }
            }
        }

        return $out;
    }

    /** Lowercase extension of the last path segment, '' when none. */
    public static function extensionOf(string $path): string
    {
        $slash = strrpos($path, '/');
        $segment = $slash === false ? $path : substr($path, $slash + 1);
        $dot = strrpos($segment, '.');

        return $dot === false ? '' : strtolower(substr($segment, $dot + 1));
    }

    /**
     * @param array<string,string|null> $cc
     */
    private function explicitFreshness(array $cc, HeaderBag $headers, int $now): ?int
    {
        foreach (['s-maxage', 'max-age'] as $directive) {
            if (array_key_exists($directive, $cc)) {
                return self::seconds($cc[$directive]) ?? 0;
            }
        }
        $expires = $headers->get('expires');
        if ($expires !== null) {
            $expiresAt = strtotime($expires);
            $date = $headers->get('date');
            $dateAt = $date !== null ? strtotime($date) : false;
            $base = $dateAt === false ? $now : $dateAt;

            return $expiresAt === false ? 0 : max(0, $expiresAt - $base);
        }

        return null;
    }

    private function heuristicFreshness(HeaderBag $headers, int $now): ?int
    {
        $lm = $headers->get('last-modified');
        if ($lm === null) {
            return null;
        }
        $lmAt = strtotime($lm);
        if ($lmAt === false) {
            return null;
        }
        $date = $headers->get('date');
        $dateAt = $date !== null ? strtotime($date) : false;
        $base = $dateAt === false ? $now : $dateAt;

        return $base > $lmAt ? (int) floor(($base - $lmAt) / 10) : null;
    }

    /**
     * @param array<string,string|null> $cc
     */
    private static function directiveSeconds(array $cc, string $name): ?int
    {
        return array_key_exists($name, $cc) ? self::seconds($cc[$name]) : null;
    }

    /** Parses delta-seconds; null for missing or invalid values. */
    private static function seconds(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= 10 && ctype_digit($value) ? (int) $value : null;
    }

    private static function globToRegex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $c = $glob[$i];
            $regex .= match ($c) {
                '*' => '.*',
                '?' => '.',
                default => preg_quote($c, '#'),
            };
        }

        return '#^' . $regex . '$#sD';
    }
}
