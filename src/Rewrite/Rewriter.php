<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

use MirrorGate\Config\MirrorConfig;
use MirrorGate\Config\OriginConfig;
use MirrorGate\Config\RewriteConfig;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Version;

/**
 * Facade of the rewriting module: classifies responses, rewrites bodies and headers in the
 * origin-to-mirror direction, and the few request headers and bodies in the opposite direction.
 *
 * Nothing in this class ever throws for body problems: unreadable, oversized, binary or
 * unsupported content is returned untouched together with a skip reason. Header rewriting throws
 * HttpException(502) only for an unsafe or blocked redirect target.
 */
final class Rewriter
{
    /** Peak memory of a rewrite as a multiple of the body size (input, output and regex working copies). */
    private const MEMORY_FACTOR = 4;

    private const MAX_REQUEST_BODY = 1048576;

    private readonly HostMapper $mapper;
    private readonly HostReplacer $replacer;
    private readonly ContentClassifier $classifier;
    private readonly CssRewriter $css;
    private readonly HtmlRewriter $html;
    private readonly JsonRewriter $json;
    private readonly XmlRewriter $xml;
    private readonly JsRewriter $js;
    private readonly RobotsRewriter $robots;
    private readonly HeaderRewriter $headers;
    private readonly RequestBodyRewriter $requestBody;

    public function __construct(
        private readonly RewriteConfig $rewrite,
        private readonly OriginConfig $origin,
        private readonly MirrorConfig $mirror,
        private readonly LoggerInterface $logger,
    ) {
        $this->mapper = new HostMapper($origin, $mirror);
        $this->replacer = new HostReplacer($this->mapper, $mirror->wsPublicUrl, $mirror);
        $this->classifier = new ContentClassifier($rewrite);
        $this->css = new CssRewriter();
        $csp = new CspRewriter($this->mapper, $origin, $mirror);
        $this->html = new HtmlRewriter($rewrite, $this->mapper, $this->css, new SrcsetRewriter(), $csp);
        $this->json = new JsonRewriter($this->replacer);
        $this->xml = new XmlRewriter($this->replacer);
        $this->js = new JsRewriter($this->replacer);
        $this->robots = new RobotsRewriter($this->replacer);
        $this->headers = new HeaderRewriter(
            $rewrite,
            $origin,
            $mirror,
            new LocationRewriter($this->mapper, $rewrite),
            new CookieRewriter($rewrite, $mirror, $this->mapper),
            $csp,
            new LinkHeaderRewriter(),
            new CorsRewriter($this->mapper),
            $this->replacer,
        );
        $this->requestBody = new RequestBodyRewriter($this->mapper);
    }

    /** Classify by response Content-Type (parameters ignored), status and path. */
    public function classify(?string $contentType, string $path, int $status, bool $serviceWorkerRequest = false): RewriteKind
    {
        return $this->classifier->classify($contentType, $path, $status, $serviceWorkerRequest);
    }

    /** Cheap guess before the request is sent, from the path extension and the client Accept header. */
    public function likelyRewritable(string $path, ?string $acceptHeader, bool $serviceWorkerRequest = false): bool
    {
        return $this->classifier->likelyRewritable($path, $acceptHeader, $serviceWorkerRequest);
    }

    public function rewriteBody(string $body, RewriteKind $kind, ?string $contentType, RewriteContext $ctx): RewriteResult
    {
        if ($kind === RewriteKind::None) {
            return new RewriteResult($body, false, true, 'kind-none');
        }
        if ($kind === RewriteKind::EventStream) {
            return new RewriteResult($body, false, true, 'event-stream');
        }
        if ($ctx->isHead) {
            return new RewriteResult($body, false, true, 'head');
        }
        if ($body === '') {
            return new RewriteResult($body, false, false, null);
        }

        $limit = ($kind === RewriteKind::Sitemap || $kind === RewriteKind::Robots)
            ? $this->rewrite->sitemapMaxBytes
            : $this->rewrite->maxBytes;
        if (strlen($body) > $limit) {
            return new RewriteResult($body, false, true, 'too-large');
        }

        if (!self::fitsInMemory(strlen($body))) {
            $this->logger->warning('rewrite_skipped_memory', ['kind' => $kind->name, 'bytes' => strlen($body)]);
            return new RewriteResult($body, false, true, 'memory');
        }

        if (!Charset::isAsciiCompatible(Charset::detect($contentType, $body))) {
            return new RewriteResult($body, false, true, 'charset');
        }
        if (self::looksBinary($body)) {
            return new RewriteResult($body, false, true, 'binary');
        }

        $this->replacer->clearFailure();
        $this->css->clearFailure();

        try {
            $reason = null;
            $urls = new UrlMapper($this->replacer, $this->mapper->prefixForOrigin($ctx->originHostOfResponse));
            $output = $this->dispatch($body, $kind, $urls, $reason);
        } catch (\Throwable $e) {
            $this->logger->warning('rewrite_failed', ['kind' => $kind->name, 'error' => get_debug_type($e)]);
            return new RewriteResult($body, false, true, 'error');
        }

        if ($reason !== null) {
            return new RewriteResult($body, false, true, $reason);
        }
        if ($this->replacer->hasFailed() || $this->css->hasFailed()) {
            $this->logger->warning('rewrite_regex_failure', ['kind' => $kind->name]);
            return new RewriteResult($body, false, true, 'preg-error');
        }
        return new RewriteResult($output, $output !== $body, false, null);
    }

    /**
     * Rewriting holds the input, the output and transient copies, so it needs several times the body size.
     * A fatal "memory exhausted" cannot be caught, so the body is passed through unrewritten instead.
     */
    private static function fitsInMemory(int $bytes): bool
    {
        $limit = self::memoryLimitBytes();
        if ($limit === null) {
            return true;
        }

        return $bytes * self::MEMORY_FACTOR <= $limit - memory_get_usage(true);
    }

    /** @return int|null null when memory_limit is unlimited or unparsable */
    private static function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1' || preg_match('/^(\d+)\s*([KMG]?)$/i', $raw, $m) !== 1) {
            return null;
        }
        $value = (int) $m[1];
        $unit = strtoupper($m[2]);

        return $unit === 'G' ? $value * 1073741824 : ($unit === 'M' ? $value * 1048576 : ($unit === 'K' ? $value * 1024 : $value));
    }

    /**
     * Origin response headers to client response headers.
     *
     * @throws \MirrorGate\Http\HttpException status 502 when a Location, Content-Location or Refresh target is rejected
     */
    public function rewriteResponseHeaders(HeaderBag $origin, int $status, bool $bodyChanged, RewriteContext $ctx): HeaderBag
    {
        $urls = new UrlMapper($this->replacer, $this->mapper->prefixForOrigin($ctx->originHostOfResponse));
        return $this->headers->rewrite($origin, $status, $bodyChanged, $ctx, $urls);
    }

    /** Request direction: rewrites `Origin` and `Referer` from the mirror to the origin. */
    public function rewriteRequestHeaders(HeaderBag $client): HeaderBag
    {
        $result = $client;
        foreach (['Origin', 'Referer'] as $name) {
            $value = $result->get($name);
            if ($value === null || $value === '' || $value === 'null') {
                continue;
            }
            $mapped = $this->mapper->unmapAbsoluteUrl($value);
            if ($mapped !== null) {
                $result = $result->set($name, $mapped);
            }
        }
        return $result;
    }

    public function rewriteRequestBody(string $body, ?string $contentType): string
    {
        if (!$this->rewrite->requestBody || $body === '' || strlen($body) > self::MAX_REQUEST_BODY) {
            return $body;
        }
        $type = ContentClassifier::mediaType($contentType);
        if ($type === 'application/x-www-form-urlencoded') {
            return $this->requestBody->rewriteForm($body);
        }
        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            return $this->requestBody->rewriteJson($body);
        }
        return $body;
    }

    /**
     * Stable 16-hex digest of everything that influences rewritten output: the rewrite settings,
     * the rewriter version, the host map, origin and mirror identity, subdomain mode and WS URL.
     */
    public function fingerprint(): string
    {
        $settings = [];
        foreach (get_object_vars($this->rewrite) as $key => $value) {
            if (is_array($value)) {
                $value = array_values($value);
                sort($value);
            }
            $settings[$key] = $value;
        }

        $hostMap = $this->origin->hostMap;
        ksort($hostMap);
        $aliases = $this->mirror->aliases;
        sort($aliases);
        $extra = $this->origin->extraOrigins;
        sort($extra);

        $payload = [
            'rewriter' => Version::REWRITER_VERSION,
            'settings' => $settings,
            'hostMap' => $hostMap,
            'origin' => $this->origin->url,
            'mirror' => $this->mirror->url,
            'aliases' => $aliases,
            'subdomainMode' => $this->origin->subdomainMode,
            'subdomainPrefix' => $this->origin->subdomainPrefix,
            'extraOrigins' => $extra,
            'wsPublicUrl' => $this->mirror->wsPublicUrl,
        ];

        return substr(hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''), 0, 16);
    }

    /** Body of the kill-switch worker served when SERVICE_WORKER_MODE=block. */
    public function serviceWorkerKillScript(): string
    {
        return ServiceWorkerKill::script();
    }

    public function hostMapper(): HostMapper
    {
        return $this->mapper;
    }

    private function dispatch(string $body, RewriteKind $kind, UrlMapper $urls, ?string &$reason): string
    {
        $hasHost = $this->replacer->mightContain($body);

        switch ($kind) {
            case RewriteKind::Html:
                if (!$this->htmlNeedsScan($body, $hasHost, $urls)) {
                    return $body;
                }
                return $this->html->rewrite($body, $urls);

            case RewriteKind::Css:
                if (!$hasHost && $urls->map('/x') === '/x') {
                    return $body;
                }
                return $this->css->rewrite($body, $urls);

            case RewriteKind::Json:
            case RewriteKind::Manifest:
                if (!$hasHost) {
                    return $body;
                }
                [$output, $reason] = $this->json->rewrite($body);
                return $output;

            case RewriteKind::Xml:
                return $hasHost ? $this->xml->rewrite($body) : $body;

            case RewriteKind::Sitemap:
                if ($this->rewrite->seoMode === 'canonical-origin') {
                    return $body;
                }
                return $hasHost ? $this->xml->rewrite($body) : $body;

            case RewriteKind::Robots:
                if ($this->rewrite->seoMode === 'canonical-origin') {
                    return $body;
                }
                return $hasHost ? $this->robots->rewrite($body) : $body;

            case RewriteKind::Js:
                return $hasHost ? $this->js->rewrite($body) : $body;

            case RewriteKind::Rsc:
                return $hasHost ? $this->replacer->replace($body) : $body;

            default:
                return $body;
        }
    }

    private function htmlNeedsScan(string $body, bool $hasHost, UrlMapper $urls): bool
    {
        if ($hasHost || $this->rewrite->seoMode === 'noindex') {
            return true;
        }
        if ($urls->map('/x') !== '/x') {
            return true;
        }
        if ($this->rewrite->stripIntegrity && stripos($body, 'integrity') !== false) {
            return true;
        }
        return $this->rewrite->cspMode === 'strip' && stripos($body, 'content-security-policy') !== false;
    }

    private static function looksBinary(string $body): bool
    {
        if (str_starts_with($body, "\0asm") || str_starts_with($body, "\x89PNG") || str_starts_with($body, 'GIF8')
            || str_starts_with($body, "\xFF\xD8\xFF") || str_starts_with($body, '%PDF') || str_starts_with($body, "PK\x03\x04")
            || str_starts_with($body, "\x1F\x8B")) {
            return true;
        }
        return strpos(substr($body, 0, 8192), "\0") !== false;
    }
}
