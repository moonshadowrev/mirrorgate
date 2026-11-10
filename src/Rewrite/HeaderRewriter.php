<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

use MirrorGate\Config\MirrorConfig;
use MirrorGate\Config\OriginConfig;
use MirrorGate\Config\RewriteConfig;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\HttpException;
use MirrorGate\Support\Str;

/**
 * Transforms origin response headers into the header set the client receives (and the cache stores).
 *
 * Responsibilities: remove hop-by-hop headers, server fingerprints and origin internals; rewrite
 * `Location`, `Content-Location`, `Refresh`, `Link`, `Set-Cookie`, CORS and CSP headers; apply the
 * HSTS and SEO modes. A rejected redirect target raises HttpException(502).
 */
final class HeaderRewriter
{
    private const HOP_BY_HOP = [
        'connection' => true, 'keep-alive' => true, 'proxy-authenticate' => true,
        'proxy-authorization' => true, 'proxy-connection' => true, 'te' => true,
        'trailer' => true, 'transfer-encoding' => true, 'upgrade' => true,
    ];

    /** Always removed. */
    private const REMOVE = [
        'server' => true, 'x-powered-by' => true, 'via' => true, 'host' => true,
        'public-key-pins' => true, 'public-key-pins-report-only' => true, 'expect-ct' => true,
        'nel' => true, 'report-to' => true, 'reporting-endpoints' => true,
        'x-served-by' => true, 'x-backend' => true, 'x-upstream' => true, 'x-varnish' => true,
        'x-cache' => true, 'x-cache-hits' => true, 'x-request-id' => true,
        'x-sendfile' => true, 'x-accel-redirect' => true, 'x-accel-buffering' => true,
        'x-accel-limit-rate' => true, 'x-accel-charset' => true,
        'x-litespeed-location' => true, 'x-litespeed-cache' => true, 'x-litespeed-tag' => true,
        'age' => true, 'date' => true, 'content-length' => true,
        'content-md5' => true, 'digest' => true, 'content-digest' => true, 'repr-digest' => true,
        'strict-transport-security' => true,
        // Origin internals.
        'server-timing' => true, 'x-runtime' => true, 'x-request-start' => true, 'x-real-ip' => true,
        'x-timer' => true, 'traceparent' => true, 'tracestate' => true, 'x-cloud-trace-context' => true,
    ];

    /** Removed when the body was rewritten (the origin values describe the original bytes). */
    private const REMOVE_WHEN_BODY_CHANGED = ['accept-ranges' => true, 'etag' => true, 'content-encoding' => true];

    private const REMOVE_PREFIXES = [
        'cf-', 'x-envoy-', 'x-kong-', 'x-vercel-', 'x-amz-', 'x-amzn-', 'x-azure-', 'x-nextjs-',
        'x-forwarded-', 'x-trace', 'x-b3-', 'x-original-',
    ];

    private const CSP_HEADERS = [
        'content-security-policy' => true, 'content-security-policy-report-only' => true,
        'x-content-security-policy' => true, 'x-webkit-csp' => true,
    ];

    /** Headers whose value is or contains origin URLs. */
    private const URL_VALUED = [
        'x-frame-options' => true, 'timing-allow-origin' => true,
        'permissions-policy' => true, 'feature-policy' => true,
    ];

    private const MAX_COOKIES = 40;

    /** @var list<string> exact lowercase names from RESPONSE_HEADER_STRIP */
    private readonly array $stripExact;

    /** @var list<string> lowercase prefixes from RESPONSE_HEADER_STRIP entries ending in '*' */
    private readonly array $stripPrefixes;

    public function __construct(
        private readonly RewriteConfig $config,
        private readonly OriginConfig $origin,
        private readonly MirrorConfig $mirror,
        private readonly LocationRewriter $location,
        private readonly CookieRewriter $cookies,
        private readonly CspRewriter $csp,
        private readonly LinkHeaderRewriter $links,
        private readonly CorsRewriter $cors,
        private readonly HostReplacer $replacer,
    ) {
        $exact = [];
        $prefixes = [];
        foreach ($config->responseHeaderStrip as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if (str_ends_with($entry, '*')) {
                $prefix = rtrim($entry, '*');
                if ($prefix !== '') {
                    $prefixes[] = $prefix;
                }
            } else {
                $exact[] = $entry;
            }
        }
        $this->stripExact = $exact;
        $this->stripPrefixes = $prefixes;
    }

    /**
     * @throws HttpException status 502 when a redirect-like header carries an unsafe or blocked target
     */
    public function rewrite(HeaderBag $origin, int $status, bool $bodyChanged, RewriteContext $ctx, UrlMapper $urls): HeaderBag
    {
        $out = [];
        $varyValues = [];
        $robotsValues = [];
        $allowOrigin = null;
        $allowCredentials = null;
        $cookieCount = 0;
        $isHtml = false;
        $keepCanonical = $this->config->seoMode === 'canonical-origin';

        foreach ($origin->all() as [$name, $value]) {
            $lower = strtolower($name);

            if ($lower === 'content-type') {
                $type = ContentClassifier::mediaType($value);
                $isHtml = $type === 'text/html' || $type === 'application/xhtml+xml';
            }
            if ($this->isRemoved($lower, $bodyChanged)) {
                continue;
            }

            switch ($lower) {
                case 'location':
                case 'content-location':
                    $rewritten = $this->location->rewrite($value, $ctx);
                    if ($rewritten === null) {
                        throw new HttpException(502, 'Origin returned an unsafe or blocked redirect target.');
                    }
                    if ($rewritten !== '') {
                        $out[] = [$name, $rewritten];
                    }
                    break;

                case 'refresh':
                    $rewritten = $this->location->rewriteRefresh($value, $ctx);
                    if ($rewritten === null) {
                        throw new HttpException(502, 'Origin returned an unsafe or blocked redirect target.');
                    }
                    if ($rewritten !== '') {
                        $out[] = [$name, $rewritten];
                    }
                    break;

                case 'link':
                    $out[] = [$name, $this->links->rewrite($value, $urls, $keepCanonical)];
                    break;

                case 'set-cookie':
                    if ($cookieCount < self::MAX_COOKIES) {
                        $cookie = $this->cookies->rewrite($value, $ctx->originHostOfResponse, $ctx->requestHost);
                        if ($cookie !== null) {
                            $out[] = [$name, $cookie];
                            $cookieCount++;
                        }
                    }
                    break;

                case 'access-control-allow-origin':
                    if ($allowOrigin === null) {
                        $allowOrigin = $value;
                    }
                    break;

                case 'access-control-allow-credentials':
                    $allowCredentials = $value;
                    break;

                case 'vary':
                    $varyValues[] = $value;
                    $out[] = [$name, $value];
                    break;

                case 'x-robots-tag':
                    $robotsValues[] = $value;
                    if ($this->config->seoMode !== 'noindex') {
                        $out[] = [$name, $value];
                    }
                    break;

                default:
                    if (isset(self::CSP_HEADERS[$lower])) {
                        if ($this->config->cspMode === 'strip') {
                            break;
                        }
                        $out[] = [$name, $this->config->cspMode === 'passthrough' ? $value : $this->csp->rewrite($value, $bodyChanged)];
                        break;
                    }
                    if (isset(self::URL_VALUED[$lower])) {
                        $out[] = [$name, $this->replacer->replaceUrlsOnly($value)];
                        break;
                    }
                    // Any other header may carry an absolute origin URL (X-Pingback, SourceMap, WWW-Authenticate, ...).
                    $out[] = [$name, str_contains($value, '//') ? $this->replacer->replaceUrlsOnly($value) : $value];
                    break;
            }
        }

        // CORS: never reflect an arbitrary origin, never combine credentials with a wildcard.
        $needsVaryOrigin = false;
        if ($allowOrigin !== null) {
            $rewrittenOrigin = $this->cors->rewriteAllowOrigin($allowOrigin, $ctx->requestOrigin);
            if ($rewrittenOrigin !== null) {
                $out[] = ['Access-Control-Allow-Origin', $rewrittenOrigin];
                if ($rewrittenOrigin !== '*') {
                    $needsVaryOrigin = true;
                    if ($allowCredentials !== null) {
                        $out[] = ['Access-Control-Allow-Credentials', $allowCredentials];
                    }
                }
            }
        }
        if ($needsVaryOrigin && !self::varyCovers($varyValues, 'origin')) {
            $out[] = ['Vary', 'Origin'];
        }

        if ($this->config->hstsMode === 'own' && $this->mirror->isHttps
            && $this->config->hstsValue !== null && $this->config->hstsValue !== '') {
            $out[] = ['Strict-Transport-Security', $this->config->hstsValue];
        }

        if ($this->config->seoMode === 'noindex') {
            $out[] = ['X-Robots-Tag', self::mergeRobots($robotsValues)];
        }

        if ($keepCanonical && $isHtml) {
            $out[] = ['Link', '<' . $this->origin->url . self::safePath($ctx->requestPath) . '>; rel="canonical"'];
        }

        $clean = [];
        foreach ($out as [$name, $value]) {
            if (Str::hasControlChars($value) || !Str::isHeaderToken($name)) {
                continue;
            }
            $clean[] = [$name, $value];
        }
        return HeaderBag::fromPairs($clean);
    }

    private function isRemoved(string $lower, bool $bodyChanged): bool
    {
        if (isset(self::HOP_BY_HOP[$lower]) || isset(self::REMOVE[$lower])) {
            return true;
        }
        if ($lower === 'alt-svc' && $this->config->stripAltSvc) {
            return true;
        }
        if ($bodyChanged && isset(self::REMOVE_WHEN_BODY_CHANGED[$lower])) {
            return true;
        }
        foreach (self::REMOVE_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        if (in_array($lower, $this->stripExact, true)) {
            return true;
        }
        foreach ($this->stripPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $varyValues */
    private static function varyCovers(array $varyValues, string $token): bool
    {
        foreach ($varyValues as $value) {
            foreach (explode(',', $value) as $item) {
                $item = strtolower(trim($item));
                if ($item === '*' || $item === $token) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param list<string> $existing origin X-Robots-Tag values */
    private static function mergeRobots(array $existing): string
    {
        $tokens = [];
        foreach ($existing as $value) {
            foreach (explode(',', $value) as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $tokens[strtolower($item)] = $item;
                }
            }
        }
        $tokens['noindex'] = 'noindex';
        $tokens['nofollow'] = 'nofollow';
        return implode(', ', array_values($tokens));
    }

    /** Keeps only characters that are valid in an already-normalized URL path. */
    private static function safePath(string $path): string
    {
        $clean = preg_replace('#[^A-Za-z0-9\-._~!$&\'()*+,;=:@/%]#', '', $path) ?? '';
        if ($clean === '' || $clean[0] !== '/') {
            $clean = '/' . $clean;
        }
        return $clean;
    }
}
