<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Cache\CacheAdminInterface;
use MirrorGate\Config\Config;
use MirrorGate\Console\SelfCheckInterface;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\HttpException;
use MirrorGate\Http\RequestContext;
use MirrorGate\Http\Response;
use MirrorGate\Support\Clock;
use MirrorGate\Support\Json;
use MirrorGate\Support\Str;
use MirrorGate\Upstream\BreakerInspector;
use MirrorGate\Upstream\BreakerState;

/**
 * Admin endpoints under ADMIN_PATH_PREFIX: /live /health /stats /purge /check /streamtest.
 *
 * Every route is authorised first (AdminAuthenticator), so an unauthenticated visitor can never
 * tell a real admin path from a missing one. Responses never contain configuration values, the
 * origin URL, the token, filesystem paths or stack traces (SEC-52).
 *
 * HttpExceptions (404, 429, 405, 413, 415, 400) are thrown for the Kernel to render.
 */
final class AdminController
{
    private const MAX_BODY_BYTES = 16384;
    private const MAX_TARGET_BYTES = 2048;
    private const STREAMTEST_CHUNKS = 5;
    private const STREAMTEST_INTERVAL_US = 500000;

    public function __construct(
        private readonly Config $config,
        private readonly AdminAuthenticator $auth,
        private readonly CacheAdminInterface $cache,
        private readonly BreakerInspector $breaker,
        private readonly SelfCheckInterface $selfCheck,
        private readonly Clock $clock,
    ) {
    }

    /** @throws HttpException */
    public function handle(RequestContext $ctx): Response
    {
        $denied = $this->auth->authorise($ctx->request, $ctx->clientIp);
        if ($denied !== null) {
            throw $denied;
        }

        $prefix = $this->config->admin->pathPrefix;
        $path = $ctx->request->target->path;
        if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
            throw new HttpException(404, 'Not found');
        }
        $route = substr($path, strlen($prefix));
        $method = $ctx->request->method;

        switch ($route) {
            case '/live':
                $this->requireRead($method);
                return $this->finish(Response::text(200, 'ok'));
            case '/health':
                $this->requireRead($method);
                return $this->health();
            case '/stats':
                $this->requireRead($method);
                return $this->stats();
            case '/streamtest':
                $this->requireRead($method);
                return $this->streamTest($ctx);
            case '/purge':
                $this->requirePost($method);
                return $this->purge($ctx);
            case '/check':
                $this->requirePost($method);
                return $this->check();
            default:
                throw new HttpException(404, 'Not found');
        }
    }

    private function requireRead(string $method): void
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            throw new HttpException(405, 'Method not allowed', [['Allow', 'GET, HEAD']]);
        }
    }

    private function requirePost(string $method): void
    {
        if ($method !== 'POST') {
            throw new HttpException(405, 'Method not allowed', [['Allow', 'POST']]);
        }
    }

    private function health(): Response
    {
        $stats = $this->cache->stats();
        $snapshot = $this->breaker->snapshot();
        $state = $this->breaker->state();
        return $this->finish(Response::json(200, [
            'ok' => $state !== BreakerState::Open,
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'cache' => ['entries' => $stats->entries, 'bytes' => $stats->bytes],
            'breaker' => ['state' => $snapshot['state'], 'failures' => $snapshot['failures']],
            'time' => $this->clock->time(),
        ]));
    }

    private function stats(): Response
    {
        $stats = $this->cache->stats();
        $snapshot = $this->breaker->snapshot();
        return $this->finish(Response::json(200, [
            'cache' => [
                'entries' => $stats->entries,
                'bytes' => $stats->bytes,
                'generation' => $stats->generation,
            ],
            'breaker' => [
                'state' => $snapshot['state'],
                'failures' => $snapshot['failures'],
                'openUntil' => $snapshot['openUntil'],
                'cooldown' => $snapshot['cooldown'],
            ],
        ]));
    }

    private function purge(RequestContext $ctx): Response
    {
        $data = $this->readJsonBody($ctx);
        $mode = $data['mode'] ?? null;
        $soft = $data['soft'] ?? false;
        if (!is_string($mode) || !in_array($mode, ['url', 'prefix', 'all'], true) || !is_bool($soft)) {
            throw new HttpException(400, 'Invalid purge request');
        }
        try {
            if ($mode === 'all') {
                $count = $this->cache->purgeAll($soft);
            } else {
                $target = $data['target'] ?? null;
                if (!is_string($target) || !self::isValidTarget($target)) {
                    throw new HttpException(400, 'Invalid purge target');
                }
                $count = $mode === 'url'
                    ? $this->cache->purgeUrl($target, $soft)
                    : $this->cache->purgePrefix($target, $soft);
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new HttpException(500, 'Purge failed');
        }
        $result = ['purged' => $count];
        if ($count === 0 && $mode !== 'all') {
            $result['warning'] = 'No cache entry matched the target; targets are compared in their canonical (normalized) form.';
        }
        return $this->finish(Response::json(200, $result));
    }

    private function check(): Response
    {
        try {
            $results = $this->selfCheck->run(true);
        } catch (\Throwable) {
            throw new HttpException(500, 'Check failed');
        }
        $out = [];
        foreach ($results as $result) {
            $out[] = [
                'name' => Str::truncate(Str::stripControl($result->name), 120),
                'level' => strtolower($result->level->name),
                'message' => Str::truncate(Str::stripControl($this->scrub($result->message)), 500),
            ];
        }
        return $this->finish(Response::json(200, ['checks' => $out]));
    }

    /**
     * Writes five timestamped lines 500 ms apart, flushing after each. The lines go to
     * the client directly, because a buffered Response would defeat the test. The returned Response
     * has an empty body: once output started, the emitter skips header() and echoes nothing.
     */
    private function streamTest(RequestContext $ctx): Response
    {
        $response = $this->finish(new Response(200, HeaderBag::fromPairs([
            ['Content-Type', 'text/plain; charset=utf-8'],
            ['X-Accel-Buffering', 'no'],
            ['Content-Encoding', 'identity'],
        ])));
        if ($ctx->isHead) {
            return $response;
        }
        if (!headers_sent()) {
            http_response_code(200);
            foreach ($response->headers->all() as [$name, $value]) {
                header($name . ': ' . $value, false);
            }
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }
        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) {
                break;
            }
        }
        $started = microtime(true);
        for ($i = 1; $i <= self::STREAMTEST_CHUNKS; $i++) {
            if ($i > 1) {
                usleep(self::STREAMTEST_INTERVAL_US);
            }
            echo 'chunk ' . $i . ' t=' . (int) round((microtime(true) - $started) * 1000) . "\n";
            if (function_exists('flush')) {
                flush();
            }
            if (connection_aborted() === 1) {
                break;
            }
        }
        return new Response(200, new HeaderBag(), '');
    }

    /**
     * Reads and decodes a small JSON object body. Requires an application/json content type.
     *
     * @return array<string,mixed>
     */
    private function readJsonBody(RequestContext $ctx): array
    {
        $contentType = strtolower(trim((string) $ctx->request->headers->get('content-type')));
        $mediaType = trim(explode(';', $contentType, 2)[0]);
        if ($mediaType !== 'application/json') {
            throw new HttpException(415, 'JSON content type required');
        }
        $stream = $ctx->request->bodyStream();
        $raw = '';
        if (is_resource($stream)) {
            $read = stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
            $raw = $read === false ? '' : $read;
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Payload too large');
        }
        try {
            $data = Json::decode($raw, 8);
        } catch (\JsonException) {
            throw new HttpException(400, 'Invalid JSON');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(400, 'Invalid JSON object');
        }
        /** @var array<string,mixed> $data */
        return $data;
    }

    /** Purge targets are paths (with optional query): absolute, no control characters, spaces, backslashes or fragments. */
    private static function isValidTarget(string $target): bool
    {
        return $target !== ''
            && strlen($target) <= self::MAX_TARGET_BYTES
            && $target[0] === '/'
            && !str_starts_with($target, '//')
            && !Str::hasControlChars($target)
            && preg_match('/[\s\\\\#]/', $target) !== 1;
    }

    /** Removes configuration values and filesystem locations from a self-check message (SEC-52). */
    private function scrub(string $message): string
    {
        $c = $this->config;
        $secrets = [
            $c->admin->token,
            $c->rateLimit->salt,
            $c->projectRoot,
            $c->envFile,
            $c->app->storageDir,
            $c->app->logFile,
            $c->cache->dir,
            $c->origin->url,
            $c->origin->host,
            (string) $c->origin->resolveIp,
            (string) $c->origin->outboundProxy,
            (string) $c->origin->caBundle,
            (string) $c->app->errorPageDir,
        ];
        // Longest first so a path is replaced before its parent directory.
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 4) {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }
        return $message;
    }

    /** Applies the generated-response header set; admin output is never cacheable. */
    private function finish(Response $response): Response
    {
        foreach (SecurityHeaders::forGenerated($this->config->security->securityHeaders) as [$name, $value]) {
            $response = $response->withHeader($name, $value);
        }
        return $response->withHeader('Cache-Control', 'no-store');
    }
}
