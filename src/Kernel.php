<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate;

use MirrorGate\Cache\CacheEntry;
use MirrorGate\Cache\CacheLookup;
use MirrorGate\Cache\CacheState;
use MirrorGate\Config\Config;
use MirrorGate\Error\ErrorPage;
use MirrorGate\Http\BodySpooler;
use MirrorGate\Http\Compressor;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\HttpException;
use MirrorGate\Http\NormalizedTarget;
use MirrorGate\Http\ProxySink;
use MirrorGate\Http\Request;
use MirrorGate\Http\RequestContext;
use MirrorGate\Http\Response;
use MirrorGate\Http\ResponseEmitter;
use MirrorGate\Http\Route;
use MirrorGate\Http\RouteKind;
use MirrorGate\Rewrite\RewriteKind;
use MirrorGate\Security\CidrList;
use MirrorGate\Security\ConcurrencySlot;
use MirrorGate\Security\HeaderSanitizer;
use MirrorGate\Security\RateLimiterFactory;
use MirrorGate\Support\FileUtil;
use MirrorGate\Support\Json;
use MirrorGate\Support\Str;
use MirrorGate\Upstream\ErrorKind;
use MirrorGate\Upstream\ErrorMapper;
use MirrorGate\Upstream\SinkDecision;
use MirrorGate\Upstream\UpstreamRequest;
use MirrorGate\Upstream\UpstreamResult;

/**
 * Request lifecycle. The Kernel is the integration point: it calls the other
 * modules in the frozen order, turns every failure into a response, and is the only caller of the
 * circuit breaker.
 */
final class Kernel
{
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const RSC_HEADERS = ['RSC', 'Next-Router-State-Tree', 'Next-Router-Prefetch', 'Next-Url', 'Next-Action'];
    private const REWRITE_BODY_MAX = 1048576;

    private readonly Config $config;
    private string $requestId = '';
    private float $started = 0.0;
    private string $clientIp = '';
    private int $logStatus = 0;
    private string $logCache = '-';
    private int $logBytes = 0;
    private string $logUpstream = '-';
    private bool $proxied = false;
    private bool $shutdownRegistered = false;
    private ?ConcurrencySlot $ipSlot = null;
    private ?ConcurrencySlot $globalSlot = null;
    private ?ProxySink $sink = null;
    private ?\Closure $refreshJob = null;
    private ?CidrList $exempt = null;

    public function __construct(private readonly Container $c)
    {
        $this->config = $c->config();
    }

    public function handle(Request $request): void
    {
        $this->started = microtime(true);
        $this->requestId = bin2hex(random_bytes(8));
        $this->clientIp = '';
        $this->logStatus = 0;
        $this->logCache = '-';
        $this->logBytes = 0;
        $this->logUpstream = '-';
        $this->proxied = false;
        $this->sink = null;
        $this->refreshJob = null;
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void {
                $this->releaseSlots(true);
            });
        }

        $headOnly = $request->method === 'HEAD';
        try {
            $this->dispatch($request);
        } catch (HttpException $e) {
            $this->renderException($e, $headOnly);
        } catch (\Throwable $e) {
            $this->c->logger()->error('unhandled_exception', [
                'rid' => $this->requestId,
                'class' => get_class($e),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);
            $this->renderPage(ErrorPage::InternalError, null, $headOnly);
        }

        $this->c->emitter()->detach();
        $this->accessLog($request);
    }

    /**
     * Shutdown work after the response has been sent.
     */
    public function afterResponse(): void
    {
        try {
            $this->releaseSlots(false);
            $job = $this->refreshJob;
            $this->refreshJob = null;
            if ($job !== null && ResponseEmitter::canDetach()) {
                try {
                    $job();
                } catch (\Throwable $e) {
                    $this->c->logger()->warning('background_refresh_failed', ['class' => get_class($e)]);
                }
            }
            $this->releaseSlots(true);
            if ($this->config->cache->enabled) {
                $this->c->httpCache()->maybeGc();
            }
        } catch (\Throwable $e) {
            $this->c->logger()->warning('after_response_failed', ['class' => get_class($e)]);
        }
    }

    // ------------------------------------------------------------------ dispatch

    private function dispatch(Request $request): void
    {
        $c = $this->c;
        $c->requestValidator()->validate($request);
        $c->hostGuard()->assertAllowed($request);
        $this->clientIp = $c->clientIpResolver()->resolve($request);
        $ctx = new RequestContext($request, $this->requestId, $this->clientIp, $this->started, $request->method === 'HEAD');
        $this->sampleProxyHint($request);
        $this->loopGuard($request);

        $route = $c->router()->route($request, $this->config);
        if ($route->kind === RouteKind::Admin) {
            $response = $c->adminController()->handle($ctx);
            $this->emitResponse($response, $ctx->isHead, '-');
            return;
        }

        $this->applyLimits();

        switch ($route->kind) {
            case RouteKind::Blocked:
                throw new HttpException(404, 'ROUTE:blocked');
            case RouteKind::AcmeChallenge:
                $this->serveAcmeChallenge($ctx, $route);
                return;
            case RouteKind::ServiceWorkerKill:
                $body = $c->rewriter()->serviceWorkerKillScript();
                $response = new Response(200, HeaderBag::fromPairs([
                    ['Content-Type', 'text/javascript; charset=utf-8'],
                    ['Cache-Control', 'no-store'],
                    ['X-Content-Type-Options', 'nosniff'],
                ]), $body);
                $this->emitResponse($response, $ctx->isHead, '-');
                return;
            case RouteKind::Proxy:
                $this->proxy($ctx, $route);
                return;
            default:
                throw new HttpException(404, 'ROUTE:unknown');
        }
    }

    private function loopGuard(Request $request): void
    {
        $entries = [];
        foreach ($request->headers->getAll($this->config->security->loopHeader) as $value) {
            foreach (explode(',', $value) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $entries[] = $part;
                }
            }
        }
        if ($entries === []) {
            return;
        }
        $own = strtolower($this->config->app->instanceId);
        $looped = false;
        foreach ($entries as $entry) {
            if (strtolower($entry) === $own) {
                $looped = true;
                break;
            }
        }
        if ($looped || count($entries) >= 8) {
            throw new HttpException(508, 'LOOP:detected');
        }
    }

    private function applyLimits(): void
    {
        $rl = $this->config->rateLimit;
        if ($this->isExempt($this->clientIp)) {
            return;
        }
        $salt = $this->c->salt();
        if ($rl->enabled) {
            $result = $this->c->rateLimiter()->hit(
                RateLimiterFactory::keyFor('req', $this->clientIp, $salt),
                $rl->requests,
                $rl->window,
            );
            if (!$result->allowed) {
                throw new HttpException(429, 'RATE:limited', [['Retry-After', (string) max(1, $result->retryAfter)]]);
            }
        }
        if ($rl->maxConcurrent > 0) {
            $slot = $this->c->concurrencyLimiter()->acquire(
                'conc',
                RateLimiterFactory::keyFor('conc', $this->clientIp, $salt),
                $rl->maxConcurrent,
            );
            if ($slot === null) {
                throw new HttpException(429, 'RATE:concurrency', [['Retry-After', '1']]);
            }
            $this->ipSlot = $slot;
        }
    }

    private function isExempt(string $ip): bool
    {
        $list = $this->config->rateLimit->exemptIps;
        if ($list === []) {
            return false;
        }
        $this->exempt ??= new CidrList($list);
        return $this->exempt->contains($ip);
    }

    private function serveAcmeChallenge(RequestContext $ctx, Route $route): void
    {
        $request = $ctx->request;
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            throw new HttpException(404, 'ACME:method');
        }
        $segment = substr($route->originPath, strlen('/.well-known/acme-challenge/'));
        $docroot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
        if ($docroot === '' || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $segment) !== 1) {
            throw new HttpException(404, 'ACME:not_found');
        }
        $dir = rtrim($docroot, '/\\') . '/.well-known/acme-challenge';
        $file = $dir . '/' . $segment;
        if (!is_file($file) || !FileUtil::isInside($file, $dir)) {
            throw new HttpException(404, 'ACME:not_found');
        }
        $body = FileUtil::readFile($file, 4096);
        if ($body === null) {
            throw new HttpException(404, 'ACME:not_found');
        }
        $response = new Response(200, HeaderBag::fromPairs([
            ['Content-Type', 'text/plain; charset=utf-8'],
            ['Cache-Control', 'no-store'],
            ['X-Content-Type-Options', 'nosniff'],
        ]), $body);
        $this->emitResponse($response, $ctx->isHead, '-');
    }

    // ------------------------------------------------------------------ proxy flow

    private function proxy(RequestContext $ctx, Route $route): void
    {
        $this->proxied = true;
        $cfg = $this->config;
        $c = $this->c;

        $ctx = $this->acquireBody($ctx);
        $request = $ctx->request;

        $cache = $c->httpCache();
        $cacheEligible = $cache->isEnabled() && $cache->isRequestEligible($request);
        $lookup = null;
        $stale = null;

        if ($cacheEligible) {
            $lookup = $cache->lookup($request);
            $entry = $lookup->entry;
            switch ($lookup->state) {
                case CacheState::Fresh:
                    if ($entry !== null && $this->serveEntry($ctx, $entry, 'HIT')) {
                        return;
                    }
                    break;
                case CacheState::StaleWhileRevalidate:
                    if ($entry !== null && ResponseEmitter::canDetach()) {
                        $this->scheduleRefresh($ctx, $route, $lookup);
                        if ($this->serveEntry($ctx, $entry, 'STALE')) {
                            return;
                        }
                        $this->discardRefresh();
                    }
                    break;
                default:
                    break;
            }
            if ($entry !== null && $lookup->state !== CacheState::Miss && $this->staleUsable($entry)) {
                $stale = $entry;
            }
        }

        $lock = null;
        if ($cacheEligible && $lookup !== null && !$ctx->isHead) {
            $lock = $cache->tryLockRefresh($lookup->key);
            if ($lock === null) {
                if ($stale !== null && $this->serveEntry($ctx, $stale, 'STALE')) {
                    return;
                }
                $waited = $cache->waitForEntry($lookup->key, $cfg->cache->coalesceWaitMs);
                if ($waited !== null && $this->serveEntry($ctx, $waited, 'HIT')) {
                    return;
                }
                // Fail open: fetch anyway.
            }
        }

        try {
            $this->fetchFromOrigin($ctx, $route, $cacheEligible, $lookup, $stale);
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    private function acquireBody(RequestContext $ctx): RequestContext
    {
        $request = $ctx->request;
        $carries = ($request->contentLength !== null && $request->contentLength > 0)
            || $request->chunkedBody
            || in_array($request->method, self::BODY_METHODS, true);
        if (!$carries) {
            return $ctx;
        }
        if (filter_var(ini_get('enable_post_data_reading'), FILTER_VALIDATE_BOOLEAN)) {
            $ct = strtolower($request->headers->get('Content-Type') ?? '');
            if (str_contains($ct, 'multipart/form-data') || str_contains($ct, 'application/x-www-form-urlencoded')) {
                $this->c->logger()->error('ini_misconfigured', ['rid' => $this->requestId, 'code' => 'INI:enable_post_data_reading']);
                throw new HttpException(500, 'INI:enable_post_data_reading');
            }
        }
        $spooled = (new BodySpooler($this->config->upstream->maxRequestBodyBytes))->spool($request);
        return new RequestContext($spooled, $ctx->requestId, $ctx->clientIp, $ctx->startedAt, $ctx->isHead);
    }

    private function staleUsable(CacheEntry $entry): bool
    {
        // must-revalidate entries are only served stale on error when CACHE_IGNORE_MUST_REVALIDATE_ON_ERROR allows it.
        return $entry->state($this->c->clock()->time(), $this->config->cache->ignoreMustRevalidateOnError) !== CacheState::Expired;
    }

    /**
     * Breaker, account-wide cap, origin budget, then the fetch itself.
     */
    private function fetchFromOrigin(RequestContext $ctx, Route $route, bool $cacheEligible, ?CacheLookup $lookup, ?CacheEntry $stale): void
    {
        $cfg = $this->config;
        $c = $this->c;

        if (!$c->breaker()->allow()) {
            $this->failureForKind($ctx, ErrorKind::CircuitOpen, $stale);
            return;
        }

        if ($cfg->rateLimit->globalMaxConcurrent > 0) {
            $slot = $c->globalConcurrencyLimiter()->acquire('global', 'global', $cfg->rateLimit->globalMaxConcurrent);
            if ($slot === null) {
                $this->failureWithStatus($ctx, 503, 1, $stale);
                return;
            }
            $this->globalSlot = $slot;
        }

        if ($cfg->rateLimit->originFetchesPerMinute > 0) {
            $budget = $c->rateLimiter()->hit(
                RateLimiterFactory::keyFor('origin', $cfg->app->instanceId, $c->salt()),
                $cfg->rateLimit->originFetchesPerMinute,
                60,
            );
            if (!$budget->allowed) {
                $this->failureWithStatus($ctx, 503, max(1, $budget->retryAfter), $stale);
                return;
            }
        }

        $this->logUpstream = $this->upstreamLabel($route);
        $this->originFetch($ctx, $route, $cacheEligible, $lookup, $stale, false);
    }

    private function originFetch(RequestContext $ctx, Route $route, bool $cacheEligible, ?CacheLookup $lookup, ?CacheEntry $stale, bool $forcePassthrough): void
    {
        $c = $this->c;
        $swRequest = $this->isServiceWorkerRequest($ctx->request);
        $sink = new ProxySink(
            $this->config,
            $ctx,
            (string) $route->originHost,
            $c->rewriter(),
            $c->httpCache(),
            $c->emitter(),
            $c->concurrencyLimiter(),
            $c->logger(),
            $cacheEligible,
            $swRequest,
        );
        $sink->setOriginPath($route->originPath);
        if ($forcePassthrough) {
            $sink->forcePassthrough();
        }
        if ($stale !== null && $this->staleUsable($stale)) {
            $sink->markStaleAvailable();
        }
        $this->sink = $sink;

        [$upstream, $buffered] = $this->buildUpstreamRequest($ctx, $route, $cacheEligible, $stale, $forcePassthrough);
        $result = $c->upstreamClient()->fetch($upstream, $sink);

        $this->handleResult($ctx, $route, $cacheEligible, $lookup, $stale, $sink, $result, $buffered, $forcePassthrough);
    }

    private function handleResult(
        RequestContext $ctx,
        Route $route,
        bool $cacheEligible,
        ?CacheLookup $lookup,
        ?CacheEntry $stale,
        ProxySink $sink,
        UpstreamResult $result,
        bool $buffered,
        bool $isRefetch,
    ): void {
        $head = $sink->head();

        if ($sink->decision() === SinkDecision::Discard) {
            $reason = $sink->discardReason();
            if ($reason === ProxySink::REASON_STREAM_SLOT) {
                $this->failureWithStatus($ctx, 503, 5, null);
                return;
            }
            $this->account($result, $head?->status);
            switch ($reason) {
                case ProxySink::REASON_ORIGIN_ERROR:
                    if ($head !== null) {
                        $this->originErrorResponse($ctx, $head->status, $head->headers, $stale);
                        return;
                    }
                    break;
                case ProxySink::REASON_HEADER_REJECTED:
                    throw $sink->headerError() ?? new HttpException(502, 'HEADER:rejected');
                case ProxySink::REASON_CLIENT_ABORT:
                    $this->logStatus = 499;
                    return;
                default:
                    break;
            }
            $this->failureWithStatus($ctx, 502, null, $stale);
            return;
        }

        if ($sink->streamStarted()) {
            $this->account($result, $head?->status);
            if ($result->error !== null) {
                $this->c->logger()->warning('stream_interrupted', [
                    'rid' => $this->requestId,
                    'kind' => $result->error->kind->name,
                    'reason' => $sink->discardReason() ?? '-',
                ]);
            } else {
                $this->c->emitter()->finish();
            }
            $this->logStatus = $head !== null ? $head->status : 200;
            $this->logBytes = $sink->bytesSent();
            $this->logCache = $sink->isSse() ? 'STREAM' : 'BYPASS';
            return;
        }

        if ($result->error !== null) {
            $kind = $result->error->kind;
            if ($kind === ErrorKind::ResponseTooLarge && !$isRefetch) {
                $this->c->logger()->info('rewrite_buffer_exceeded', ['rid' => $this->requestId]);
                $this->originFetch($ctx, $route, false, null, null, true);
                return;
            }
            $this->account($result, null);
            $this->failureForKind($ctx, $kind, $stale);
            return;
        }

        if ($head === null) {
            $this->failureWithStatus($ctx, 502, null, $stale);
            return;
        }

        $this->account($result, $head->status);
        $this->respondBuffered($ctx, $route, $cacheEligible, $lookup, $stale, $sink, $result, $buffered && $stale !== null);
    }

    /**
     * Breaker accounting. Called once per fetch outcome; only the Kernel touches the breaker.
     */
    private function account(UpstreamResult $result, ?int $originStatus): void
    {
        $breaker = $this->c->breaker();
        if ($originStatus !== null && in_array($originStatus, [502, 503, 504], true)) {
            $breaker->recordFailure(ErrorKind::Protocol);
            return;
        }
        if ($result->error !== null) {
            if (ErrorMapper::countsForBreaker($result->error->kind) && ($originStatus === null || $originStatus >= 200)) {
                $breaker->recordFailure($result->error->kind);
            }
            return;
        }
        if ($originStatus !== null) {
            $breaker->recordSuccess();
        }
    }

    // ------------------------------------------------------------------ upstream request

    /**
     * @return array{0:UpstreamRequest,1:bool} the request and whether it is a buffered (cache/rewrite) fetch
     */
    private function buildUpstreamRequest(RequestContext $ctx, Route $route, bool $cacheEligible, ?CacheEntry $validate, bool $forcePassthrough): array
    {
        $c = $this->c;
        $cfg = $this->config;
        $request = $ctx->request;

        $swRequest = $this->isServiceWorkerRequest($request);
        $accept = $request->headers->get('Accept');
        $rewritable = $c->rewriter()->likelyRewritable($route->originPath, $accept, $swRequest);
        if ($rewritable && !$cfg->rewrite->rsc && $this->hasRscHeaders($request)) {
            $rewritable = false;
        }
        $buffered = !$forcePassthrough
            && ($cacheEligible || $rewritable)
            && $request->method === 'GET'
            && !$request->headers->has('Range');

        $headers = $c->headerBuilder()->build(
            $ctx,
            !$buffered,
            $this->reducedAcceptEncoding($request),
            $buffered,
            $cacheEligible,
        );
        if ($buffered && $validate !== null) {
            foreach ($validate->conditionalHeaders() as $name => $value) {
                $headers = $headers->set((string) $name, (string) $value);
            }
        }
        $headers = $c->rewriter()->rewriteRequestHeaders($headers);

        $target = $c->urlBuilder()->build(
            new NormalizedTarget(
                $route->originPath,
                $cacheEligible ? $c->httpCache()->upstreamQuery($request->target->query) : $request->target->query,
            ),
            (string) $route->originHost,
        );

        [$stream, $length] = $this->requestBody($request);

        $maxBuffer = $cfg->rewrite->maxBytes;
        if ($cacheEligible) {
            $maxBuffer = max($maxBuffer, $cfg->cache->maxObjectBytes);
        }
        if (preg_match('#(?:/sitemap[^/]*\.xml|/robots\.txt)\z#i', $route->originPath) === 1) {
            $maxBuffer = max($maxBuffer, $cfg->rewrite->sitemapMaxBytes);
        }

        $connectTimeout = null;
        $timeout = null;
        $ceiling = ResponseEmitter::runtimeCeiling();
        if ($ceiling < PHP_INT_MAX) {
            $left = (int) floor($ceiling - (microtime(true) - $this->started));
            $timeout = max(1, min($cfg->upstream->timeout, $left));
            $connectTimeout = min($cfg->upstream->connectTimeout, $timeout);
        }

        $streamingHint = $accept !== null && stripos($accept, 'text/event-stream') !== false;

        $upstream = new UpstreamRequest(
            $request->method,
            $target,
            $headers,
            $stream,
            $length,
            $buffered,
            $maxBuffer,
            $connectTimeout,
            $timeout,
            $streamingHint,
        );
        return [$upstream, $buffered];
    }

    /**
     * @return array{0:mixed,1:?int} stream (resource|null) and exact length
     */
    private function requestBody(Request $request): array
    {
        $length = $request->contentLength;
        $stream = null;
        if ($length !== null && $length > 0) {
            $stream = $request->bodyStream();
            if ($stream === null) {
                $length = 0;
            }
        }
        if ($stream === null) {
            return [null, in_array($request->method, ['POST', 'PUT', 'PATCH'], true) ? 0 : null];
        }
        $cfg = $this->config->rewrite;
        $ct = strtolower($request->headers->get('Content-Type') ?? '');
        if (
            $cfg->enabled && $cfg->requestBody && $length !== null && $length <= self::REWRITE_BODY_MAX
            && (str_contains($ct, 'application/x-www-form-urlencoded') || str_contains($ct, 'json'))
        ) {
            rewind($stream);
            $data = stream_get_contents($stream, self::REWRITE_BODY_MAX + 1);
            if (is_string($data)) {
                $new = $this->c->rewriter()->rewriteRequestBody($data, $request->headers->get('Content-Type'));
                $tmp = fopen('php://temp/maxmemory:1048576', 'w+b');
                if ($tmp !== false) {
                    fwrite($tmp, $new);
                    rewind($tmp);
                    return [$tmp, strlen($new)];
                }
            }
            rewind($stream);
        }
        return [$stream, $length];
    }

    private function reducedAcceptEncoding(Request $request): ?string
    {
        if ($request->headers->has('Range')) {
            return 'identity';
        }
        $value = $request->headers->get('Accept-Encoding');
        if ($value === null) {
            return null;
        }
        $keep = [];
        foreach (explode(',', $value) as $part) {
            $bits = explode(';', $part);
            $token = strtolower(trim($bits[0]));
            if (!in_array($token, ['gzip', 'br', 'deflate', 'identity'], true)) {
                continue;
            }
            $zero = false;
            for ($i = 1, $n = count($bits); $i < $n; $i++) {
                if (preg_match('/\A\s*q\s*=\s*0(?:\.0*)?\s*\z/i', $bits[$i]) === 1) {
                    $zero = true;
                }
            }
            if (!$zero) {
                $keep[$token] = true;
            }
        }
        return $keep === [] ? 'identity' : implode(', ', array_keys($keep));
    }

    private function hasRscHeaders(Request $request): bool
    {
        foreach (self::RSC_HEADERS as $name) {
            if ($request->headers->has($name)) {
                return true;
            }
        }
        return false;
    }

    private function isServiceWorkerRequest(Request $request): bool
    {
        $v = $request->headers->get('Service-Worker');
        return $v !== null && strtolower(trim($v)) === 'script';
    }

    private function upstreamLabel(Route $route): string
    {
        $host = (string) $route->originHost;
        if ($host === $this->config->origin->host) {
            return 'primary';
        }
        $idx = array_search($host, $this->config->origin->extraOrigins, true);
        return $idx === false ? 'primary' : 'extra:' . $idx;
    }

    // ------------------------------------------------------------------ buffered pipeline

    private function respondBuffered(
        RequestContext $ctx,
        Route $route,
        bool $cacheEligible,
        ?CacheLookup $lookup,
        ?CacheEntry $stale,
        ProxySink $sink,
        UpstreamResult $result,
        bool $revalidating,
    ): void {
        $out = $this->bufferedPipeline($ctx, $cacheEligible, $lookup, $stale, $sink, $result, $revalidating, false);
        if ($out === null) {
            return;
        }
        [$response, $state, $entry] = $out;
        if ($entry !== null) {
            if (!$this->serveEntry($ctx, $entry, $state)) {
                $this->failureWithStatus($ctx, 502, null, null);
            }
            return;
        }
        if ($response === null) {
            return;
        }
        $this->emitResponse($response, $ctx->isHead, $state);
    }

    /**
     * Shared by the foreground response and the background refresh.
     *
     * @return array{0:?Response,1:string,2:?CacheEntry}|null response, cache state label, entry to serve instead
     */
    private function bufferedPipeline(
        RequestContext $ctx,
        bool $cacheEligible,
        ?CacheLookup $lookup,
        ?CacheEntry $stale,
        ProxySink $sink,
        UpstreamResult $result,
        bool $revalidating,
        bool $background,
    ): ?array {
        $c = $this->c;
        $cfg = $this->config;
        $request = $ctx->request;
        $head = $sink->head();
        $rewriteCtx = $sink->rewriteContext();
        if ($head === null || $rewriteCtx === null) {
            return null;
        }
        $status = $head->status;
        $rewriter = $c->rewriter();
        $cache = $c->httpCache();

        // 2. Revalidation answered with 304.
        if ($status === 304 && $revalidating && $stale !== null && $lookup !== null) {
            try {
                $decision = $cache->policy()->evaluate($request, $stale->status, $head->headers, $stale->bodyLength);
                if ($decision->storable) {
                    $cache->refresh($lookup->key, $decision, $head->headers);
                }
            } catch (\Throwable $e) {
                $c->logger()->warning('cache_refresh_failed', ['rid' => $this->requestId, 'class' => get_class($e)]);
            }
            return $background ? null : [null, 'REVALIDATED', $stale];
        }
        if ($background) {
            if ($ctx->isHead || $status === 204 || $status === 304 || $request->method !== 'GET' || !$cacheEligible) {
                return null;
            }
        }

        // Header-only responses: HEAD, 204, 304.
        if ($ctx->isHead || $status === 204 || $status === 304) {
            $bodyChanged = $ctx->isHead && $sink->kind() !== RewriteKind::None;
            $headers = HeaderSanitizer::removeHopByHop(
                $rewriter->rewriteResponseHeaders($head->headers, $status, $bodyChanged, $rewriteCtx),
            );
            if ($ctx->isHead && !$bodyChanged && !$head->wasDecoded && $head->contentLength !== null) {
                $headers = $headers->set('Content-Length', (string) $head->contentLength);
            }
            return [new Response($status, ProxySink::decorate($cfg->app, $headers, 'BYPASS'), ''), 'BYPASS', null];
        }

        $body = $result->body ?? '';
        $kind = $sink->kind();
        $contentType = $head->headers->get('Content-Type');
        $originHeaders = $head->headers;

        // 4. Leftover Content-Encoding (origin ignored our request for identity).
        $identity = $body;
        $undecodable = false;
        $decodedByUs = false;
        $encoding = $head->wasDecoded ? null : $head->headers->get('Content-Encoding');
        if ($encoding !== null && strtolower(trim($encoding)) !== 'identity' && trim($encoding) !== '') {
            $decoded = Compressor::decode($body, $encoding, $cfg->rewrite->maxBytes);
            if ($decoded === null) {
                $undecodable = true;
            } else {
                $identity = $decoded;
                $decodedByUs = true;
                $originHeaders = $originHeaders->remove('Content-Encoding');
            }
        }

        // 5. Rewrite the body.
        $changed = false;
        $newBody = $identity;
        $rewriteIncomplete = false; // the body was meant to be rewritten but is served as the origin sent it
        if (!$undecodable && $kind !== RewriteKind::None) {
            $limit = ($kind === RewriteKind::Sitemap || $kind === RewriteKind::Robots)
                ? $cfg->rewrite->sitemapMaxBytes
                : $cfg->rewrite->maxBytes;
            if (strlen($identity) <= $limit) {
                $rewritten = $rewriter->rewriteBody($identity, $kind, $contentType, $rewriteCtx);
                $newBody = $rewritten->body;
                $changed = $rewritten->changed;
                if ($rewritten->skipped) {
                    $c->logger()->info('rewrite_skipped', ['rid' => $this->requestId, 'reason' => $rewritten->reason ?? '-']);
                    $rewriteIncomplete = in_array($rewritten->reason, ['preg-error', 'error', 'memory', 'too-large'], true);
                }
            } else {
                $c->logger()->info('rewrite_skipped', ['rid' => $this->requestId, 'reason' => 'size']);
                $rewriteIncomplete = true;
            }
        }

        // 6. Headers.
        try {
            $mirrorHeaders = HeaderSanitizer::removeHopByHop(
                $rewriter->rewriteResponseHeaders($originHeaders, $status, $changed || $decodedByUs, $rewriteCtx),
            );
        } catch (HttpException $e) {
            if ($background) {
                return null;
            }
            throw $e;
        }

        // 7. ETag, Content-Length, Accept-Ranges.
        $mirrorHeaders = $mirrorHeaders->remove(
            'ETag',
            'Content-MD5',
            'Digest',
            'Content-Digest',
            'Repr-Digest',
            'Accept-Ranges',
            'Content-Length',
            'Age',
        );
        if (!$undecodable) {
            $mirrorHeaders = $mirrorHeaders->remove('Content-Encoding');
        }

        // 8. Cache (GET only).
        $stored = false;
        $complete = $result->completed
            && !($head->contentLength !== null && !$head->wasDecoded && $result->bytesReceived !== $head->contentLength);
        if ($cacheEligible && !$undecodable && $request->method === 'GET' && $complete) {
            try {
                $policy = $cache->policy();
                $vary = $policy->varySelector($mirrorHeaders);
                $key = $cache->keyFor($request, $vary);
                $decision = $policy->evaluate($request, $status, $head->headers, strlen($newBody));
                $nonce = $kind === RewriteKind::Html && $policy->bodyContainsCspNonce($newBody);
                if ($decision->storable && !$nonce && !$rewriteIncomplete) {
                    $stored = $cache->store(
                        $key,
                        $status,
                        $mirrorHeaders,
                        $newBody,
                        $decision,
                        $head->headers->get('ETag'),
                        $head->headers->get('Last-Modified'),
                        $vary,
                    );
                }
            } catch (\Throwable $e) {
                $c->logger()->warning('cache_store_failed', ['rid' => $this->requestId, 'class' => get_class($e)]);
            }
        }
        if ($background) {
            return null;
        }
        $hadEntry = $lookup !== null && $lookup->entry !== null && $lookup->state !== CacheState::Miss;
        $state = $stored ? ($hadEntry ? 'EXPIRED' : 'MISS') : 'BYPASS';

        $final = $mirrorHeaders->set('Accept-Ranges', 'none');
        $etag = null;
        if (!$undecodable) {
            $etag = CacheEntry::etagFor($newBody);
            $final = $final->set('ETag', $etag);
        }

        // 9. Client conditional request.
        if ($status === 200 && $etag !== null && $this->clientNotModified($request, $etag, $final->get('Last-Modified'), null)) {
            $notModified = $final->remove('Content-Type', 'Content-Length', 'Content-Encoding');
            return [
                new Response(304, ProxySink::decorate($cfg->app, $notModified, $state, $state === 'BYPASS' ? null : 0, null), ''),
                $state,
                null,
            ];
        }

        // 10. Compression.
        $outBody = $newBody;
        if (
            !$undecodable
            && $cfg->rewrite->recompress
            && strlen($newBody) >= 1024
            && $this->isCompressible($final->get('Content-Type'))
            && Compressor::negotiate($request->headers->get('Accept-Encoding')) === 'gzip'
        ) {
            $gz = Compressor::gzip($newBody);
            if ($gz !== null && strlen($gz) < strlen($newBody)) {
                $outBody = $gz;
                $final = $final->set('Content-Encoding', 'gzip');
            }
            $final = $this->mergeVary($final, 'Accept-Encoding');
        }
        $final = $final->set('Content-Length', (string) strlen($outBody));

        // 11. Emit.
        $final = ProxySink::decorate($cfg->app, $final, $state, in_array($state, ['MISS', 'EXPIRED'], true) ? 0 : null);
        return [new Response($status, $final, $outBody), $state, null];
    }

    private function isCompressible(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }
        $semi = strpos($contentType, ';');
        $media = strtolower(trim($semi === false ? $contentType : substr($contentType, 0, $semi)));
        if (str_starts_with($media, 'text/')) {
            return true;
        }
        if (str_ends_with($media, '+json') || str_ends_with($media, '+xml')) {
            return true;
        }
        return in_array($media, [
            'application/json', 'application/javascript', 'application/x-javascript', 'application/xml',
            'application/xhtml+xml', 'application/rss+xml', 'application/atom+xml', 'image/svg+xml',
        ], true);
    }

    private function mergeVary(HeaderBag $headers, string $token): HeaderBag
    {
        $existing = $headers->getAll('Vary');
        foreach ($existing as $line) {
            foreach (explode(',', $line) as $part) {
                $p = trim($part);
                if ($p === '*' || strcasecmp($p, $token) === 0) {
                    return $headers;
                }
            }
        }
        if ($existing === []) {
            return $headers->set('Vary', $token);
        }
        return $headers->set('Vary', implode(', ', $existing) . ', ' . $token);
    }

    private function clientNotModified(Request $request, string $etag, ?string $lastModified, ?int $fallbackTime): bool
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return false;
        }
        $inm = $request->headers->get('If-None-Match');
        if ($inm !== null) {
            $inm = trim($inm);
            if ($inm === '*') {
                return true;
            }
            $weak = static fn (string $t): string => str_starts_with($t, 'W/') ? substr($t, 2) : $t;
            $mine = $weak($etag);
            foreach (explode(',', $inm) as $candidate) {
                if ($weak(trim($candidate)) === $mine) {
                    return true;
                }
            }
            return false;
        }
        $ims = $request->headers->get('If-Modified-Since');
        if ($ims !== null) {
            $imsTime = strtotime($ims);
            if ($imsTime === false) {
                return false;
            }
            $ref = $lastModified !== null ? strtotime($lastModified) : false;
            if ($ref === false) {
                $ref = $fallbackTime ?? false;
            }
            return $ref !== false && $ref <= $imsTime;
        }
        return false;
    }

    // ------------------------------------------------------------------ cache serving

    private function serveEntry(RequestContext $ctx, CacheEntry $entry, string $state, ?int $forwardStatus = null): bool
    {
        $cfg = $this->config;
        $request = $ctx->request;
        $now = $this->c->clock()->time();
        try {
            $headers = $entry->headers->remove('Content-Length', 'Content-Encoding', 'Age', 'ETag', 'Accept-Ranges');
            $age = $entry->age($now);
            $ttl = max(0, $entry->freshUntil - $now);

            if ($state === 'STALE') {
                $headers = $this->reduceCacheControl($headers, min($ttl, 30));
            }
            $headers = $headers->set('ETag', $entry->mirrorEtag)->set('Accept-Ranges', 'none');

            if ($entry->status === 200 && $this->clientNotModified($request, $entry->mirrorEtag, $headers->get('Last-Modified'), $entry->createdAt)) {
                $notModified = $headers->remove('Content-Type');
                $response = new Response(304, ProxySink::decorate($cfg->app, $notModified, $state, $age, $ttl, $forwardStatus), '');
                $this->emitResponse($response, $ctx->isHead, $state);
                return true;
            }

            $body = '';
            if ($entry->storedEncoding === 'gzip') {
                if (Compressor::negotiate($request->headers->get('Accept-Encoding')) === 'gzip') {
                    $stream = $entry->openBody();
                    $raw = stream_get_contents($stream);
                    fclose($stream);
                    if (!is_string($raw)) {
                        return false;
                    }
                    $body = $raw;
                    $headers = $headers->set('Content-Encoding', 'gzip');
                } else {
                    $body = $entry->bodyContents();
                }
                $headers = $this->mergeVary($headers, 'Accept-Encoding');
            } else {
                $stream = $entry->openBody();
                $raw = stream_get_contents($stream);
                fclose($stream);
                if (!is_string($raw)) {
                    return false;
                }
                $body = $raw;
            }
            $headers = $headers->set('Content-Length', (string) strlen($body));
            $headers = ProxySink::decorate($cfg->app, $headers, $state, $age, $ttl, $forwardStatus);
            $this->emitResponse(new Response($entry->status, $headers, $body), $ctx->isHead, $state);
            return true;
        } catch (\Throwable $e) {
            $this->c->logger()->warning('cache_serve_failed', ['rid' => $this->requestId, 'class' => get_class($e)]);
            return false;
        }
    }

    private function reduceCacheControl(HeaderBag $headers, int $maxAge): HeaderBag
    {
        $cc = $headers->get('Cache-Control');
        if ($cc === null) {
            return $headers->set('Cache-Control', 'max-age=' . $maxAge);
        }
        $replaced = preg_replace('/\b(s-maxage|max-age)\s*=\s*"?\d+"?/i', '$1=' . $maxAge, $cc, -1, $count);
        if ($replaced === null) {
            return $headers;
        }
        if ($count === 0) {
            $replaced = $cc . ', max-age=' . $maxAge;
        }
        return $headers->set('Cache-Control', $replaced);
    }

    // ------------------------------------------------------------------ background refresh

    private function scheduleRefresh(RequestContext $ctx, Route $route, CacheLookup $lookup): void
    {
        if ($this->refreshJob !== null || $lookup->entry === null) {
            return;
        }
        $c = $this->c;
        $cfg = $this->config;
        $cache = $c->httpCache();
        $lock = $cache->tryLockRefresh($lookup->key);
        if ($lock === null) {
            return;
        }
        if (!$c->breaker()->allow()) {
            $lock->release();
            return;
        }
        $slot = null;
        if ($cfg->rateLimit->globalMaxConcurrent > 0) {
            $slot = $c->globalConcurrencyLimiter()->acquire('global', 'global', $cfg->rateLimit->globalMaxConcurrent);
            if ($slot === null) {
                $lock->release();
                return;
            }
        }
        if ($cfg->rateLimit->originFetchesPerMinute > 0) {
            $budget = $c->rateLimiter()->hit(
                RateLimiterFactory::keyFor('origin', $cfg->app->instanceId, $c->salt()),
                $cfg->rateLimit->originFetchesPerMinute,
                60,
            );
            if (!$budget->allowed) {
                $slot?->release();
                $lock->release();
                return;
            }
        }
        $this->globalSlot = $slot;
        $this->logUpstream = $this->upstreamLabel($route);

        $entry = $lookup->entry;
        $this->refreshJob = function () use ($ctx, $route, $lookup, $entry, $lock): void {
            try {
                $this->runRefresh($ctx, $route, $lookup, $entry);
            } finally {
                $lock->release();
            }
        };
    }

    private function discardRefresh(): void
    {
        $this->refreshJob = null;
        if ($this->globalSlot !== null) {
            $this->globalSlot->release();
            $this->globalSlot = null;
        }
    }

    private function runRefresh(RequestContext $ctx, Route $route, CacheLookup $lookup, CacheEntry $entry): void
    {
        $c = $this->c;
        $cfg = $this->config;
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        if (ResponseEmitter::canSetTimeLimit() && ResponseEmitter::runtimeCeiling() >= PHP_INT_MAX) {
            @set_time_limit(max(30, $cfg->upstream->timeout * 2));
        }

        $sink = new ProxySink(
            $cfg,
            $ctx,
            (string) $route->originHost,
            $c->rewriter(),
            $c->httpCache(),
            $c->emitter(),
            $c->concurrencyLimiter(),
            $c->logger(),
            true,
            $this->isServiceWorkerRequest($ctx->request),
        );
        $sink->setOriginPath($route->originPath);
        $sink->markBackground();
        [$upstream, $buffered] = $this->buildUpstreamRequest($ctx, $route, true, $entry, false);
        $result = $c->upstreamClient()->fetch($upstream, $sink);
        $head = $sink->head();
        $this->account($result, $head?->status);
        if ($result->error !== null || $head === null || $sink->decision() !== SinkDecision::Buffer) {
            return;
        }
        $this->bufferedPipeline($ctx, true, $lookup, $entry, $sink, $result, $buffered, true);
    }

    // ------------------------------------------------------------------ failures

    private function failureForKind(RequestContext $ctx, ErrorKind $kind, ?CacheEntry $stale): void
    {
        if ($kind === ErrorKind::ClientAborted) {
            $this->logStatus = 499;
            return;
        }
        $status = ErrorMapper::httpStatus($kind);
        $retry = null;
        if ($kind === ErrorKind::CircuitOpen) {
            $retry = max(1, $this->c->breaker()->retryAfter());
        }
        $this->c->logger()->warning('upstream_failure', ['rid' => $this->requestId, 'kind' => $kind->name]);
        $this->failureWithStatus($ctx, $status, $retry, $stale);
    }

    private function failureWithStatus(RequestContext $ctx, int $status, ?int $retryAfter, ?CacheEntry $stale, ?int $forwardStatus = null): void
    {
        if ($status >= 500 && $stale !== null && $this->staleUsable($stale)) {
            if ($this->serveEntry($ctx, $stale, 'STALE', $forwardStatus ?? $status)) {
                return;
            }
        }
        if ($status === 503 && $retryAfter === null) {
            $retryAfter = $this->defaultRetryAfter();
        }
        $this->renderPage(ErrorPage::fromStatus($status), $retryAfter, $ctx->isHead);
    }

    private function originErrorResponse(RequestContext $ctx, int $status, HeaderBag $originHeaders, ?CacheEntry $stale): void
    {
        $retry = null;
        if ($status === 503) {
            $ra = $originHeaders->get('Retry-After');
            if ($ra !== null && preg_match('/\A\d{1,4}\z/', trim($ra)) === 1 && (int) trim($ra) <= 3600) {
                $retry = max(1, (int) trim($ra));
            }
        }
        $this->failureWithStatus($ctx, $status, $retry, $stale, $status);
    }

    private function defaultRetryAfter(): int
    {
        try {
            $left = $this->c->breaker()->retryAfter();
            if ($left > 0) {
                return $left;
            }
        } catch (\Throwable) {
            // fall through
        }
        return 5;
    }

    // ------------------------------------------------------------------ output

    private function renderException(HttpException $e, bool $headOnly): void
    {
        if ($e->status === 499) {
            $this->logStatus = 499;
            return;
        }
        if ($e->status >= 500 && $e->status !== 501 && $e->status !== 508) {
            $this->c->logger()->error('http_exception', [
                'rid' => $this->requestId,
                'status' => $e->status,
                'code' => Str::truncate(Str::stripControl($e->getMessage()), 80),
            ]);
        }
        $retry = null;
        foreach ($e->headers as [$name, $value]) {
            if (strtolower($name) === 'retry-after' && preg_match('/\A\d{1,6}\z/', trim($value)) === 1) {
                $retry = (int) trim($value);
            }
        }
        $this->renderPage(ErrorPage::fromStatus($e->status), $retry, $headOnly, $e->headers);
    }

    /**
     * @param list<array{0:string,1:string}> $extraHeaders only Allow and Retry-After are honoured
     */
    private function renderPage(ErrorPage $page, ?int $retryAfter, bool $headOnly, array $extraHeaders = []): void
    {
        $emitter = $this->c->emitter();
        if ($emitter->hasBegun()) {
            return;
        }
        $response = $this->c->errorRenderer()->render($page, $this->requestId, $retryAfter, $headOnly);
        foreach ($extraHeaders as [$name, $value]) {
            $lc = strtolower($name);
            if ($lc === 'allow' || $lc === 'retry-after') {
                try {
                    $response = $response->withHeader($name, $value);
                } catch (\InvalidArgumentException) {
                    // ignore malformed extra header
                }
            }
        }
        if ($this->proxied && $this->config->app->exposeCacheHeaders) {
            $response = $response->withHeader('X-MirrorGate-Cache', 'ERROR');
        }
        $this->logStatus = $response->status;
        $this->logCache = $this->proxied ? 'ERROR' : '-';
        $this->logBytes = $headOnly ? 0 : strlen($response->body);
        $emitter->emit($response, $headOnly);
    }

    private function emitResponse(Response $response, bool $headOnly, string $cacheState): void
    {
        $this->logStatus = $response->status;
        $this->logCache = $cacheState;
        $this->logBytes = $headOnly ? 0 : strlen($response->body);
        $this->c->emitter()->emit($response, $headOnly);
    }

    public function releaseSlots(bool $includeGlobal): void
    {
        if ($this->ipSlot !== null) {
            $this->ipSlot->release();
            $this->ipSlot = null;
        }
        if ($this->sink !== null) {
            $this->sink->releaseSlot();
        }
        if ($this->globalSlot !== null && ($includeGlobal || $this->refreshJob === null)) {
            $this->globalSlot->release();
            $this->globalSlot = null;
        }
    }

    // ------------------------------------------------------------------ logging and hints

    private function accessLog(Request $request): void
    {
        try {
            $salt = $this->c->salt();
            $ipHash = $this->clientIp === ''
                ? '-'
                : substr(RateLimiterFactory::keyFor('log', $this->clientIp, $salt), 0, 12);
            $this->c->logger()->info('access', [
                'rid' => $this->requestId,
                'ip_hash' => $ipHash,
                'm' => $request->method,
                'path' => $this->logPath($request->target->path, $salt),
                'st' => $this->logStatus,
                'cache' => $this->logCache,
                'ms' => (int) round((microtime(true) - $this->started) * 1000),
                'bytes' => $this->logBytes,
                'up' => $this->logUpstream,
            ]);
        } catch (\Throwable) {
            // logging must never break the response
        }
    }

    private function logPath(string $path, string $salt): string
    {
        switch ($this->config->app->logPathMode) {
            case 'full':
                return $path;
            case 'hash':
                return substr(hash_hmac('sha256', $path, $salt), 0, 12);
            default:
                $trimmed = trim($path, '/');
                if ($trimmed === '') {
                    return '/';
                }
                $pos = strpos($trimmed, '/');
                if ($pos === false) {
                    return '/' . $trimmed;
                }
                return '/' . substr($trimmed, 0, $pos) . '/*';
        }
    }

    /**
     * Records, for about 1 request in 50, whether forwarded client address headers differ between
     * requests from the same connecting address while TRUSTED_PROXIES is empty. Only counters and
     * keyed hashes are stored (never raw addresses).
     */
    private function sampleProxyHint(Request $request): void
    {
        if ($this->config->security->trustedProxies !== []) {
            return;
        }
        try {
            if (random_int(1, 50) !== 1) {
                return;
            }
            $forwarded = '';
            foreach (['X-Forwarded-For', 'CF-Connecting-IP', 'X-Real-IP'] as $name) {
                $v = $request->headers->get($name);
                if ($v !== null && $v !== '') {
                    $forwarded .= $name . '=' . $v . ';';
                }
            }
            if ($forwarded === '') {
                return;
            }
            $salt = $this->c->salt();
            $remoteHash = substr(hash_hmac('sha256', 'proxyhint-remote|' . $request->remoteAddr, $salt), 0, 12);
            $valueHash = substr(hash_hmac('sha256', 'proxyhint-value|' . $forwarded, $salt), 0, 12);
            $file = $this->config->app->storageDir . '/run/proxyhint.json';
            $data = [];
            $raw = FileUtil::readFile($file, 65536);
            if ($raw !== null) {
                try {
                    $decoded = Json::decode($raw);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                } catch (\JsonException) {
                    $data = [];
                }
            }
            $entry = $data[$remoteHash] ?? ['n' => 0, 'h' => []];
            $entry = is_array($entry) ? $entry : ['n' => 0, 'h' => []];
            $hashes = isset($entry['h']) && is_array($entry['h']) ? array_values(array_map('strval', $entry['h'])) : [];
            if (!in_array($valueHash, $hashes, true) && count($hashes) < 8) {
                $hashes[] = $valueHash;
            }
            $data[$remoteHash] = ['n' => (int) ($entry['n'] ?? 0) + 1, 'h' => $hashes];
            if (count($data) > 8) {
                $data = array_slice($data, -8, null, true);
            }
            FileUtil::ensureDir(dirname($file));
            FileUtil::atomicWrite($file, Json::encode($data));
        } catch (\Throwable) {
            // best effort only
        }
    }
}
