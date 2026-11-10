<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

use MirrorGate\Cache\HttpCache;
use MirrorGate\Config\AppConfig;
use MirrorGate\Config\Config;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Rewrite\RewriteContext;
use MirrorGate\Rewrite\RewriteKind;
use MirrorGate\Rewrite\Rewriter;
use MirrorGate\Security\ConcurrencyLimiter;
use MirrorGate\Security\ConcurrencySlot;
use MirrorGate\Security\HeaderSanitizer;
use MirrorGate\Upstream\SinkDecision;
use MirrorGate\Upstream\UpstreamHead;
use MirrorGate\Upstream\UpstreamSink;

/**
 * Decides, as soon as the origin response headers are complete, whether the body is buffered
 * (rewriting, caching, header-only responses), streamed to the client (SSE and passthrough) or
 * discarded (stream cap, origin error pages). It is the only class that writes into a streamed
 * response.
 */
final class ProxySink implements UpstreamSink
{
    public const REASON_STREAM_SLOT = 'stream_slot';
    public const REASON_CLIENT_ABORT = 'client_abort';
    public const REASON_LIMIT = 'limit';
    /** The origin answered with a 5xx that is replaced by a MirrorGate page or a stale entry. */
    public const REASON_ORIGIN_ERROR = 'origin_error';
    /** rewriteResponseHeaders rejected a Location/Content-Location/Refresh value. */
    public const REASON_HEADER_REJECTED = 'header_rejected';
    /** The origin produced a final status that cannot be proxied (for example 101). */
    public const REASON_UNEXPECTED_STATUS = 'unexpected_status';
    /** A background refresh produced a response that would need streaming; it is not stored. */
    public const REASON_BACKGROUND = 'background';

    private const LARGE_MEDIA_EXTENSIONS = [
        'mp4', 'webm', 'mp3', 'ogg', 'oga', 'ogv', 'm4a', 'm4v', 'mov', 'mkv', 'avi', 'flac', 'wav', 'aac',
        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'iso', 'dmg', 'exe', 'msi', 'apk', 'bin', 'pdf',
    ];

    private const RSC_HEADERS = ['RSC', 'Next-Router-State-Tree', 'Next-Router-Prefetch', 'Next-Url', 'Next-Action'];

    private ?SinkDecision $decision = null;
    private ?string $discardReason = null;
    private ?UpstreamHead $head = null;
    private ?RewriteKind $kind = null;
    private ?RewriteContext $rewriteContext = null;
    private ?HttpException $headerError = null;
    private ?ConcurrencySlot $slot = null;
    private bool $streamStarted = false;
    private bool $isSse = false;
    private bool $forcePassthrough = false;
    private bool $staleAvailable = false;
    private bool $background = false;
    private int $bytesSent = 0;
    private float $deadline = INF;
    private float $lastActivity = 0.0;
    private bool $endedOnBlankLine = true;
    private string $originPath;

    public function __construct(
        private readonly Config $config,
        private readonly RequestContext $ctx,
        private readonly string $originHost,
        private readonly Rewriter $rewriter,
        private readonly HttpCache $cache,
        private readonly ResponseEmitter $emitter,
        private readonly ConcurrencyLimiter $streamLimiter,
        private readonly LoggerInterface $log,
        private readonly bool $cacheEligible,
        private readonly bool $serviceWorkerRequest,
    ) {
        $this->originPath = $ctx->request->target->path;
    }

    /** Path on the origin host (differs from the client path in prefix mode); used for classification. */
    public function setOriginPath(string $path): void
    {
        $this->originPath = $path;
    }

    /** Disables rewriting and buffering: the response is streamed as-is (ResponseTooLarge re-fetch). */
    public function forcePassthrough(): void
    {
        $this->forcePassthrough = true;
    }

    /** A usable stale cache entry exists: origin 500/502/503/504 responses are discarded so the Kernel can serve it. */
    public function markStaleAvailable(): void
    {
        $this->staleAvailable = true;
    }

    /** Background refresh: the sink never writes to the client; streaming decisions become Discard. */
    public function markBackground(): void
    {
        $this->background = true;
    }

    public function decision(): ?SinkDecision
    {
        return $this->decision;
    }

    public function discardReason(): ?string
    {
        return $this->discardReason;
    }

    public function head(): ?UpstreamHead
    {
        return $this->head;
    }

    public function streamStarted(): bool
    {
        return $this->streamStarted;
    }

    public function bytesSent(): int
    {
        return $this->bytesSent;
    }

    /** Rewrite classification computed from the response headers; None before headers arrive. */
    public function kind(): RewriteKind
    {
        return $this->kind ?? RewriteKind::None;
    }

    public function rewriteContext(): ?RewriteContext
    {
        return $this->rewriteContext;
    }

    /** Set when rewriteResponseHeaders rejected a header (the Kernel renders the 502 page). */
    public function headerError(): ?HttpException
    {
        return $this->headerError;
    }

    public function isSse(): bool
    {
        return $this->isSse;
    }

    public function releaseSlot(): void
    {
        if ($this->slot !== null) {
            $this->slot->release();
            $this->slot = null;
        }
    }

    public function onHeaders(UpstreamHead $head): SinkDecision
    {
        $this->head = $head;
        $status = $head->status;
        $request = $this->ctx->request;
        $isHead = $this->ctx->isHead;

        $contentType = $head->headers->get('Content-Type');
        $media = self::mediaType($contentType);

        $this->rewriteContext = new RewriteContext(
            $request->target->path,
            $this->originHost,
            $status,
            $isHead,
            $request->headers->get('Origin'),
            $request->hostHeader !== null ? self::hostNameOf($request->hostHeader) : null,
        );

        $kind = $this->rewriter->classify($contentType, $this->originPath, $status, $this->serviceWorkerRequest);
        if ($this->rscHeadersPresent() && !$this->config->rewrite->rsc) {
            $kind = RewriteKind::None;
        }
        $this->kind = $kind;

        if ($status < 200) {
            return $this->discard(self::REASON_UNEXPECTED_STATUS);
        }
        if ($this->replacesOriginError($status)) {
            return $this->discard(self::REASON_ORIGIN_ERROR);
        }

        // Rule 2: server-sent events.
        if ($media === 'text/event-stream' && $this->config->stream->sseEnabled && !$isHead) {
            $this->kind = RewriteKind::EventStream;
            if ($this->background) {
                return $this->discard(self::REASON_BACKGROUND);
            }
            $slot = $this->streamLimiter->acquire(
                'stream',
                $this->config->app->instanceId,
                $this->config->stream->maxConcurrentStreams,
            );
            if ($slot === null) {
                return $this->discard(self::REASON_STREAM_SLOT);
            }
            $this->slot = $slot;
            $this->isSse = true;
            return $this->startStream($head, true);
        }

        // Rule 5: header-only responses.
        if ($isHead || $status === 204 || $status === 304) {
            $this->decision = SinkDecision::Buffer;
            return SinkDecision::Buffer;
        }

        if (!$this->forcePassthrough) {
            $known = $head->contentLength ?? $head->encodedContentLength;

            // Rule 3: rewritable content.
            if ($kind !== RewriteKind::None && $status !== 206) {
                $limit = ($kind === RewriteKind::Sitemap || $kind === RewriteKind::Robots)
                    ? $this->config->rewrite->sitemapMaxBytes
                    : $this->config->rewrite->maxBytes;
                if ($known === null || $known <= $limit) {
                    $this->decision = SinkDecision::Buffer;
                    return SinkDecision::Buffer;
                }
                return $this->startStream($head, false);
            }

            // Rule 4: cacheable content.
            if ($this->cacheEligible && $status !== 206) {
                $fits = $known !== null
                    ? $known <= $this->config->cache->maxObjectBytes
                    : !$this->isLargeMediaPath();
                if ($fits) {
                    $storable = false;
                    try {
                        $storable = $this->cache->policy()->evaluate($request, $status, $head->headers, null)->storable;
                    } catch (\Throwable $e) {
                        $this->log->warning('cache_policy_failed', ['exception' => get_class($e)]);
                    }
                    if ($storable) {
                        $this->decision = SinkDecision::Buffer;
                        return SinkDecision::Buffer;
                    }
                }
            }
        }

        // Rule 6: passthrough.
        return $this->startStream($head, false);
    }

    public function onChunk(string $chunk): bool
    {
        if (!$this->streamStarted) {
            return true;
        }
        $len = strlen($chunk);
        $max = $this->config->upstream->maxStreamBytes;
        if ($max > 0 && $this->bytesSent + $len > $max) {
            $this->discardReason = self::REASON_LIMIT;
            return false;
        }
        if (!$this->emitter->write($chunk)) {
            $this->discardReason = self::REASON_CLIENT_ABORT;
            return false;
        }
        $this->bytesSent += $len;
        $this->lastActivity = microtime(true);
        if ($this->isSse && $len > 0) {
            $this->endedOnBlankLine = str_ends_with($chunk, "\n\n") || str_ends_with($chunk, "\r\n\r\n");
        }
        return true;
    }

    public function onTick(float $now): bool
    {
        if (connection_aborted() === 1) {
            if ($this->discardReason === null) {
                $this->discardReason = self::REASON_CLIENT_ABORT;
            }
            return false;
        }
        if (!$this->streamStarted) {
            return true;
        }
        if ($now >= $this->deadline) {
            $this->discardReason = self::REASON_LIMIT;
            return false;
        }
        $heartbeat = $this->config->stream->sseHeartbeat;
        if (
            $this->isSse
            && $heartbeat > 0
            && ($now - $this->lastActivity) >= $heartbeat
            && ($this->endedOnBlankLine || $this->bytesSent === 0)
        ) {
            if (!$this->emitter->write(": ping\n\n")) {
                $this->discardReason = self::REASON_CLIENT_ABORT;
                return false;
            }
            $this->lastActivity = $now;
        }
        return true;
    }

    /**
     * Adds the MirrorGate bookkeeping headers (Via, X-MirrorGate-Cache, Age, Cache-Status).
     */
    public static function decorate(
        AppConfig $app,
        HeaderBag $headers,
        string $state,
        ?int $age = null,
        ?int $ttl = null,
        ?int $forwardStatus = null,
    ): HeaderBag {
        if ($app->addVia) {
            $headers = $headers->set('Via', '1.1 mirrorgate');
        }
        if (!$app->exposeCacheHeaders) {
            return $headers;
        }
        $headers = $headers->set('X-MirrorGate-Cache', $state);
        if ($age !== null && in_array($state, ['HIT', 'STALE', 'REVALIDATED', 'MISS', 'EXPIRED'], true)) {
            $headers = $headers->set('Age', (string) max(0, $age));
        } else {
            $headers = $headers->remove('Age');
        }
        $status = match ($state) {
            'HIT', 'REVALIDATED' => 'MirrorGate; hit' . ($ttl !== null ? '; ttl=' . max(0, $ttl) : ''),
            'STALE' => $forwardStatus !== null
                ? 'MirrorGate; fwd=stale; fwd-status=' . $forwardStatus
                : 'MirrorGate; hit; ttl=0',
            'BYPASS', 'STREAM' => 'MirrorGate; fwd=bypass',
            default => 'MirrorGate; fwd=miss',
        };
        return $headers->set('Cache-Status', $status);
    }

    private function startStream(UpstreamHead $head, bool $sse): SinkDecision
    {
        if ($this->background) {
            return $this->discard(self::REASON_BACKGROUND);
        }
        $rewriteContext = $this->rewriteContext;
        if ($rewriteContext === null) {
            return $this->discard(self::REASON_UNEXPECTED_STATUS);
        }
        try {
            $headers = $this->rewriter->rewriteResponseHeaders($head->headers, $head->status, false, $rewriteContext);
        } catch (HttpException $e) {
            $this->headerError = $e;
            $this->releaseSlot();
            return $this->discard(self::REASON_HEADER_REJECTED);
        }
        $headers = HeaderSanitizer::removeHopByHop($headers);

        if ($sse) {
            $headers = $headers
                ->remove('Content-Length')
                ->set('Cache-Control', 'no-cache, no-transform')
                ->set('X-Accel-Buffering', 'no')
                ->set('X-LiteSpeed-Cache-Control', 'no-cache');
            $state = 'STREAM';
        } else {
            $headers = $headers->remove('Content-Length');
            if (!$head->wasDecoded && $head->contentLength !== null) {
                $headers = $headers->set('Content-Length', (string) $head->contentLength);
            }
            $state = 'BYPASS';
        }
        $headers = self::decorate($this->config->app, $headers, $state);

        $remaining = $this->emitter->prepareStreaming();
        $now = microtime(true);
        $this->lastActivity = $now;
        if ($sse) {
            $seconds = min($this->config->stream->sseMaxDuration, $remaining);
            $this->deadline = $now + $seconds;
        } elseif ($remaining < PHP_INT_MAX) {
            $this->deadline = $now + $remaining;
        }

        $this->emitter->begin($head->status, $headers, true);
        $this->streamStarted = true;
        $this->decision = SinkDecision::Stream;
        return SinkDecision::Stream;
    }

    private function discard(string $reason): SinkDecision
    {
        $this->decision = SinkDecision::Discard;
        $this->discardReason = $reason;
        return SinkDecision::Discard;
    }

    private function replacesOriginError(int $status): bool
    {
        if ($status < 500 || $status > 599) {
            return false;
        }
        if ($this->staleAvailable && in_array($status, [500, 502, 503, 504], true)) {
            return true;
        }
        return match ($this->config->app->errorPageUpstream) {
            'all' => true,
            'gateway' => in_array($status, [502, 503, 504], true),
            default => false,
        };
    }

    private function rscHeadersPresent(): bool
    {
        foreach (self::RSC_HEADERS as $name) {
            if ($this->ctx->request->headers->has($name)) {
                return true;
            }
        }
        return false;
    }

    private function isLargeMediaPath(): bool
    {
        $path = $this->originPath;
        $dot = strrpos($path, '.');
        if ($dot === false || $dot < strrpos($path, '/')) {
            return false;
        }
        return in_array(strtolower(substr($path, $dot + 1)), self::LARGE_MEDIA_EXTENSIONS, true);
    }

    private static function mediaType(?string $contentType): string
    {
        if ($contentType === null) {
            return '';
        }
        $semi = strpos($contentType, ';');
        $media = $semi === false ? $contentType : substr($contentType, 0, $semi);
        return strtolower(trim($media));
    }

    private static function hostNameOf(string $hostHeader): ?string
    {
        $host = parse_url('//' . $hostHeader, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(rtrim($host, '.')) : null;
    }
}
