<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Config\OriginConfig;
use MirrorGate\Config\UpstreamConfig;
use MirrorGate\Http\HttpException;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Support\Str;

/**
 * Upstream HTTP client on ext-curl.
 *
 * Properties
 * - One cURL handle per attempt; no handle is ever closed explicitly (they are
 *   garbage collected; the explicit close function is deprecated on PHP 8.5).
 * - Redirects are never followed (SEC-06), TLS verification cannot be disabled
 *   (SEC-04), only http/https are allowed, the connect address is pinned through
 *   CURLOPT_RESOLVE after OriginGuard validated it (SEC-03).
 * - Header and body callbacks never throw; they record failures in a
 *   TransferState and return a failing value (SEC-05).
 * - Retries (idempotent methods, before the sink saw headers, bounded by 60% of
 *   the total timeout) use full-jitter back-off.
 * - The circuit breaker is NOT consulted here; only the Kernel talks to it.
 */
final class UpstreamClient implements UpstreamClientInterface
{
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** Request headers that are always owned by this client or by libcurl. */
    private const HEADER_DENY = [
        'host', 'content-length', 'transfer-encoding', 'connection', 'keep-alive',
        'proxy-connection', 'proxy-authorization', 'proxy-authenticate', 'te',
        'trailer', 'upgrade', 'expect', 'http2-settings',
    ];

    /** Value a read callback returns to abort the upload (CURL_READFUNC_ABORT). */
    private const READFUNC_ABORT = 0x10000000;

    private ?string $proxyUrl = null;
    private ?string $proxyAuth = null;
    private bool $proxyInvalid = false;

    public function __construct(
        private readonly UpstreamConfig $upstream,
        private readonly OriginConfig $origin,
        private readonly OriginGuard $guard,
        private readonly RetryPolicy $retry,
        private readonly LoggerInterface $log,
    ) {
        $this->parseProxy($origin->outboundProxy);
    }

    public function fetch(UpstreamRequest $request, UpstreamSink $sink): UpstreamResult
    {
        $started = \microtime(true);
        $method = $request->method;

        if (!\in_array($method, self::METHODS, true)) {
            return $this->failed(ErrorKind::Unsupported, 0, 'method_not_supported', 1, $started);
        }

        // Fail before any network I/O when the declared body is over the limit.
        if ($request->bodyLength !== null && $request->bodyLength > $this->upstream->maxRequestBodyBytes) {
            throw new HttpException(413, 'Request body too large.');
        }

        $hasBody = $this->usesUploadBody($request);
        $totalTimeout = \max(1, $request->timeout ?? $this->upstream->timeout);
        $maxAttempts = $this->retry->maxAttempts();
        $canRetry = $this->retry->isIdempotent($method) && !$hasBody;

        $attempt = 0;
        while (true) {
            ++$attempt;
            $result = $this->attempt($request, $sink);
            $error = $result->error;

            if ($error !== null
                && $result->head === null
                && $canRetry
                && $attempt < $maxAttempts
                && ErrorMapper::isRetryable($error->kind)
            ) {
                $delay = $this->retry->delayMs($attempt);
                $elapsedMs = (\microtime(true) - $started) * 1000.0;
                if ($this->retry->withinBudget($elapsedMs, $delay, $totalTimeout)) {
                    $this->log->info('retrying upstream request', [
                        'attempt' => $attempt,
                        'kind' => $error->kind->name,
                        'delay_ms' => $delay,
                    ]);
                    if ($delay > 0) {
                        \usleep($delay * 1000);
                    }
                    if (!$this->safeTick($sink)) {
                        return $this->failed(ErrorKind::ClientAborted, 0, 'aborted_between_attempts', $attempt, $started);
                    }
                    continue;
                }
            }

            return new UpstreamResult(
                $result->head,
                $result->body,
                $result->bytesReceived,
                $result->completed,
                $error,
                $attempt,
                \microtime(true) - $started,
            );
        }
    }

    /**
     * Runs exactly one attempt. The returned result carries attempts=0 and
     * totalTime=0.0; fetch() fills both in.
     */
    private function attempt(UpstreamRequest $request, UpstreamSink $sink): UpstreamResult
    {
        $target = $request->target;
        $reason = $this->validateTarget($target);
        if ($reason !== null) {
            return $this->failed(ErrorKind::SsrfBlocked, 0, $reason, 0, \microtime(true));
        }
        if ($this->proxyInvalid) {
            return $this->failed(ErrorKind::Unsupported, 0, 'outbound_proxy_invalid', 0, \microtime(true));
        }

        try {
            $resolved = $this->guard->resolve($target->host, $target->port);
        } catch (SsrfBlockedException $e) {
            $this->log->warning('upstream target blocked', ['reason' => $e->getMessage()]);

            return $this->failed(ErrorKind::SsrfBlocked, 0, $e->getMessage(), 0, \microtime(true));
        } catch (DnsFailureException $e) {
            return $this->failed(ErrorKind::Dns, 6, $e->getMessage(), 0, \microtime(true));
        } catch (\Throwable $e) {
            return $this->failed(ErrorKind::Unknown, 0, 'guard_' . $e::class, 0, \microtime(true));
        }

        $method = $request->method;
        $isHead = $method === 'HEAD';
        $upload = $this->usesUploadBody($request);
        $declared = $request->bodyLength;
        $stream = $request->bodyStream;

        if ($upload && ($declared === null || $declared > 0) && !\is_resource($stream)) {
            return $this->failed(ErrorKind::Unsupported, 0, 'body_stream_missing', 0, \microtime(true));
        }

        $parser = new ResponseHeaderParser($this->log, $request->decodeContent, $isHead);
        $state = new TransferState($parser);

        $ch = \curl_init();
        if ($ch === false) {
            return $this->failed(ErrorKind::Unknown, 0, 'curl_init_failed', 0, \microtime(true));
        }

        $headerLines = $this->requestHeaderLines($request, $upload && $declared === null);
        $connectTimeout = \max(1, $request->connectTimeout ?? $this->upstream->connectTimeout);
        $timeout = $request->isStreamingHint ? 0 : \max(1, $request->timeout ?? $this->upstream->timeout);

        $required = [
            CURLOPT_URL => $target->url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => $this->proxyUrl ?? '',
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_AUTOREFERER => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HTTP_VERSION => CurlCapabilities::httpVersionValue($this->upstream->httpVersion, $target->scheme),
            CURLOPT_HEADERFUNCTION => $this->headerCallback($state, $sink),
            CURLOPT_WRITEFUNCTION => $this->writeCallback($state, $sink, \max(0, $request->maxBufferBytes)),
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => $this->progressCallback($state, $sink),
        ];
        $required += CurlCapabilities::protocolsOption();

        if ($resolved->curlResolveEntry !== '') {
            $required[CURLOPT_RESOLVE] = [$resolved->curlResolveEntry];
        }
        if ($request->decodeContent) {
            $required[CURLOPT_ENCODING] = '';
        }
        $caBundle = $this->origin->caBundle;
        if ($caBundle !== null && $caBundle !== '') {
            $required[CURLOPT_CAINFO] = $caBundle;
        }
        if ($this->proxyAuth !== null) {
            $required[CURLOPT_PROXYUSERPWD] = $this->proxyAuth;
        }

        if ($isHead) {
            $required[CURLOPT_NOBODY] = true;
        } elseif ($upload) {
            $required[CURLOPT_UPLOAD] = true;
            $required[CURLOPT_CUSTOMREQUEST] = $method;
            $required[CURLOPT_READFUNCTION] = $this->readCallback($state, $stream, $declared);
            if ($declared !== null) {
                $required[CURLOPT_INFILESIZE] = $declared;
            }
        } elseif ($method === 'GET') {
            $required[CURLOPT_HTTPGET] = true;
        } else {
            $required[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if (!$this->applyOptions($ch, $required)) {
            return $this->failed(ErrorKind::Unsupported, 0, 'curl_setopt_failed', 0, \microtime(true));
        }

        $optional = [];
        $this->addOptional($optional, 'CURLOPT_TCP_KEEPALIVE', 1);
        $this->addOptional($optional, 'CURLOPT_PATH_AS_IS', true);
        $this->addOptional($optional, 'CURLOPT_DISALLOW_USERNAME_IN_URL', true);
        $this->addOptional($optional, 'CURLOPT_HTTP09_ALLOWED', false);
        if (\defined('CURL_SSLVERSION_TLSv1_2')) {
            $this->addOptional($optional, 'CURLOPT_SSLVERSION', \constant('CURL_SSLVERSION_TLSv1_2'));
        }
        if ($this->proxyUrl !== null) {
            $this->addOptional($optional, 'CURLOPT_SUPPRESS_CONNECT_HEADERS', true);
        }
        if ($resolved->ip !== '' && \defined('CURL_IPRESOLVE_V4') && \defined('CURL_IPRESOLVE_V6')) {
            $isV4 = \filter_var($resolved->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $this->addOptional($optional, 'CURLOPT_IPRESOLVE', \constant($isV4 ? 'CURL_IPRESOLVE_V4' : 'CURL_IPRESOLVE_V6'));
        }
        if (!$request->isStreamingHint && $this->upstream->lowSpeedTime > 0) {
            $optional[CURLOPT_LOW_SPEED_LIMIT] = \max(1, $this->upstream->lowSpeedLimit);
            $optional[CURLOPT_LOW_SPEED_TIME] = $this->upstream->lowSpeedTime;
        }
        $this->applyOptionalOptions($ch, $optional);

        $ok = \curl_exec($ch);
        $errno = \curl_errno($ch);
        $message = \curl_error($ch);
        $connectTime = (float) \curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $appConnectTime = (float) \curl_getinfo($ch, CURLINFO_APPCONNECT_TIME);

        $head = $state->head;
        $error = null;

        if ($errno === 0 && $ok !== false) {
            if ($head === null) {
                $error = new UpstreamError(ErrorKind::Protocol, 0, 'no_final_response');
            }
        } else {
            $kind = $this->classify($errno, $state, $target->scheme, $connectTime, $appConnectTime);
            $detail = $state->protocolFailure ?? $state->internalFailure ?? '';
            $error = new UpstreamError(
                $kind,
                $errno,
                \sprintf('curl %d: %s%s', $errno, Str::truncate(Str::stripControl($message), 200), $detail !== '' ? ' [' . $detail . ']' : ''),
            );
            $this->log->debug('upstream attempt failed', ['kind' => $kind->name, 'errno' => $errno]);
        }

        $completed = $error === null && $head !== null;
        $body = ($completed && $state->decision === SinkDecision::Buffer) ? $state->buffer : null;

        return new UpstreamResult($head, $body, $state->bytesReceived, $completed, $error, 0, 0.0);
    }

    private function headerCallback(TransferState $state, UpstreamSink $sink): \Closure
    {
        return static function (\CurlHandle $ch, string $line) use ($state, $sink): int {
            try {
                if ($state->head !== null) {
                    // Trailers after the final header block are ignored.
                    return \strlen($line);
                }

                $result = $state->parser->feed($line);
                if ($result === ResponseHeaderParser::FAIL) {
                    $state->protocolFailure = $state->parser->failure() ?? 'protocol';

                    return 0;
                }
                if ($result === ResponseHeaderParser::READY) {
                    $head = $state->parser->head();
                    if ($head === null) {
                        $state->internalFailure = 'missing_head';

                        return 0;
                    }
                    $state->head = $head;
                    $state->headersDelivered = true;
                    $decision = $sink->onHeaders($head);
                    $state->decision = $decision;
                    if ($decision === SinkDecision::Discard) {
                        $state->discarded = true;

                        return 0;
                    }
                }

                return \strlen($line);
            } catch (\Throwable $e) {
                $state->internalFailure = 'header_callback_' . $e::class;

                return 0;
            }
        };
    }

    private function writeCallback(TransferState $state, UpstreamSink $sink, int $maxBuffer): \Closure
    {
        return static function (\CurlHandle $ch, string $data) use ($state, $sink, $maxBuffer): int {
            $length = \strlen($data);
            try {
                if ($state->head === null || $state->decision === null) {
                    $state->internalFailure = 'body_before_headers';

                    return 0;
                }

                if ($state->decision === SinkDecision::Buffer) {
                    if ($state->bufferBytes + $length > $maxBuffer) {
                        $state->responseTooLarge = true;

                        return 0;
                    }
                    $state->buffer .= $data;
                    $state->bufferBytes += $length;
                    $state->bytesReceived += $length;

                    return $length;
                }

                if ($state->decision === SinkDecision::Stream) {
                    $state->bytesReceived += $length;
                    if (!$sink->onChunk($data)) {
                        $state->sinkAborted = true;

                        return 0;
                    }

                    return $length;
                }

                return 0; // Discard: no body wanted
            } catch (\Throwable $e) {
                $state->internalFailure = 'write_callback_' . $e::class;

                return 0;
            }
        };
    }

    private function progressCallback(TransferState $state, UpstreamSink $sink): \Closure
    {
        return static function (\CurlHandle $ch, int $dlTotal = 0, int $dlNow = 0, int $ulTotal = 0, int $ulNow = 0) use ($state, $sink): int {
            $now = \microtime(true);
            // libcurl may invoke this far more often than once per second.
            if ($state->lastTick > 0.0 && ($now - $state->lastTick) < 1.0) {
                return 0;
            }
            $state->lastTick = $now;

            try {
                if (!$sink->onTick($now)) {
                    $state->sinkAborted = true;

                    return 1;
                }
            } catch (\Throwable $e) {
                $state->internalFailure = 'tick_callback_' . $e::class;

                return 1;
            }

            return 0;
        };
    }

    /**
     * @param resource|null $stream
     */
    private function readCallback(TransferState $state, mixed $stream, ?int $declared): \Closure
    {
        $limit = $this->upstream->maxRequestBodyBytes;

        return static function (\CurlHandle $ch, mixed $fd, int $length) use ($state, $stream, $declared, $limit): string|int {
            try {
                if (!\is_resource($stream) || $length <= 0) {
                    return '';
                }

                $want = $length;
                if ($declared !== null) {
                    $remaining = $declared - $state->uploaded;
                    if ($remaining <= 0) {
                        return '';
                    }
                    $want = \min($want, $remaining);
                }

                $allowed = $limit - $state->uploaded;
                if ($allowed <= 0) {
                    $probe = \fread($stream, 1);
                    if ($probe !== false && $probe !== '') {
                        // Abort hard: finishing the upload gracefully would hand
                        // the origin a truncated body that looks complete.
                        $state->requestTooLarge = true;

                        return self::READFUNC_ABORT;
                    }

                    return '';
                }
                $want = \min($want, $allowed);

                $data = \fread($stream, $want);
                if ($data === false) {
                    $state->internalFailure = 'body_read_failed';

                    return self::READFUNC_ABORT;
                }
                if ($data === '') {
                    if ($declared !== null && $state->uploaded < $declared) {
                        $state->uploadShort = true;

                        return self::READFUNC_ABORT;
                    }

                    return '';
                }

                $state->uploaded += \strlen($data);

                return $data;
            } catch (\Throwable $e) {
                $state->internalFailure = 'read_callback_' . $e::class;

                return self::READFUNC_ABORT;
            }
        };
    }

    /**
     * Maps a cURL error to an ErrorKind. Flags recorded by the callbacks win
     * over the numeric code because they know WHY a callback aborted.
     */
    private function classify(int $errno, TransferState $state, string $scheme, float $connectTime, float $appConnectTime): ErrorKind
    {
        if ($state->requestTooLarge) {
            return ErrorKind::RequestTooLarge;
        }
        if ($state->responseTooLarge) {
            return ErrorKind::ResponseTooLarge;
        }
        if ($state->discarded || $state->sinkAborted) {
            return ErrorKind::ClientAborted;
        }
        if ($state->protocolFailure !== null) {
            return ErrorKind::Protocol;
        }
        if ($state->internalFailure !== null) {
            return ErrorKind::Unknown;
        }
        if ($state->uploadShort) {
            return ErrorKind::Reset;
        }

        return match ($errno) {
            5, 6 => ErrorKind::Dns,
            7, 97 => ErrorKind::Connect,
            28 => $this->isConnectPhase($state, $scheme, $connectTime, $appConnectTime)
                ? ErrorKind::ConnectTimeout
                : ErrorKind::Timeout,
            35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98 => ErrorKind::Tls,
            16, 52, 55, 56, 65, 92 => ErrorKind::Reset,
            18 => ErrorKind::Partial,
            47 => ErrorKind::TooManyRedirects,
            8, 61, 93 => ErrorKind::Protocol,
            1, 2, 3, 4, 25, 26, 27 => ErrorKind::Unsupported,
            42 => ErrorKind::ClientAborted,
            default => ErrorKind::Unknown,
        };
    }

    private function isConnectPhase(TransferState $state, string $scheme, float $connectTime, float $appConnectTime): bool
    {
        if ($state->head !== null) {
            return false;
        }
        if ($connectTime <= 0.0) {
            return true;
        }

        // TCP is up but the TLS handshake never completed.
        return $scheme === 'https' && $appConnectTime <= 0.0 && $this->proxyUrl === null;
    }

    /**
     * Whether the request body is sent through the upload (read callback) path.
     */
    private function usesUploadBody(UpstreamRequest $request): bool
    {
        if ($request->method === 'HEAD') {
            return false;
        }
        if ($request->bodyLength === null) {
            return $request->bodyStream !== null;
        }
        if ($request->bodyLength > 0) {
            return true;
        }

        // Zero-length body: POST/PUT/PATCH announce "Content-Length: 0".
        return \in_array($request->method, ['POST', 'PUT', 'PATCH'], true);
    }

    /**
     * Validates that the target matches configuration byte for byte, so even a
     * buggy caller cannot steer the connection elsewhere (SEC-01).
     */
    private function validateTarget(UpstreamTarget $t): ?string
    {
        if ($t->scheme !== 'http' && $t->scheme !== 'https') {
            return 'scheme_not_allowed';
        }
        if ($t->scheme !== $this->origin->scheme) {
            return 'scheme_mismatch';
        }
        if ($t->scheme === 'http' && !$this->origin->allowHttp) {
            return 'http_not_allowed';
        }
        if ($t->host === '' || $t->port < 1 || $t->port > 65535) {
            return 'target_invalid';
        }

        $prefix = $t->scheme . '://' . UpstreamUrlBuilder::authority($t->scheme, $t->host, $t->port);
        if (!\str_starts_with($t->url, $prefix . '/') || \strlen($t->url) <= \strlen($prefix)) {
            return 'url_mismatch';
        }

        $afterAuthority = \substr($t->url, \strlen($prefix));
        if (\preg_match('/[\x00-\x20\x7F#]/', $t->url) === 1) {
            return 'url_bytes';
        }
        $queryPos = \strpos($afterAuthority, '?');
        $pathPart = $queryPos === false ? $afterAuthority : \substr($afterAuthority, 0, $queryPos);
        if (\str_contains($pathPart, '\\')) {
            return 'url_bytes';
        }

        if (\preg_match('/^(?:[A-Za-z0-9.\-]+|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/D', $t->hostHeader) !== 1) {
            return 'host_header_invalid';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function requestHeaderLines(UpstreamRequest $request, bool $chunkedUpload): array
    {
        $lines = [];
        $hasAccept = false;

        foreach ($request->headers->all() as [$name, $value]) {
            $lower = \strtr(\strtolower($name), '_', '-');
            if (\in_array($lower, self::HEADER_DENY, true)) {
                continue;
            }
            if ($request->decodeContent && $lower === 'accept-encoding') {
                continue;
            }
            if (!Str::isHeaderToken($name) || Str::hasControlChars($value)) {
                $this->log->warning('dropped invalid request header', ['header' => Str::truncate(Str::stripControl($name), 64)]);
                continue;
            }
            if ($lower === 'accept') {
                $hasAccept = true;
            }
            // "Name;" is libcurl's syntax for sending a header with an empty value.
            $lines[] = $value === '' ? $name . ';' : $name . ': ' . $value;
        }

        $lines[] = 'Host: ' . $request->target->hostHeader;
        $lines[] = 'Expect:';
        if (!$hasAccept) {
            $lines[] = 'Accept:'; // stop libcurl from inventing "Accept: */*"
        }
        if ($chunkedUpload) {
            $lines[] = 'Transfer-Encoding: chunked';
        }

        return $lines;
    }

    /**
     * Applies security-relevant options; any failure aborts the attempt.
     *
     * @param array<int,mixed> $options
     */
    private function applyOptions(\CurlHandle $ch, array $options): bool
    {
        try {
            return \curl_setopt_array($ch, $options);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<int,mixed> $options
     */
    private function applyOptionalOptions(\CurlHandle $ch, array $options): void
    {
        foreach ($options as $option => $value) {
            try {
                \curl_setopt($ch, $option, $value);
            } catch (\Throwable) {
                // Optional hardening/tuning that this build does not support.
            }
        }
    }

    /**
     * @param array<int,mixed> $options
     */
    private function addOptional(array &$options, string $constant, mixed $value): void
    {
        if (\defined($constant)) {
            $options[(int) \constant($constant)] = $value;
        }
    }

    private function safeTick(UpstreamSink $sink): bool
    {
        try {
            return $sink->onTick(\microtime(true));
        } catch (\Throwable) {
            return false;
        }
    }

    private function failed(ErrorKind $kind, int $errno, string $message, int $attempts, float $started): UpstreamResult
    {
        return new UpstreamResult(
            null,
            null,
            0,
            false,
            new UpstreamError($kind, $errno, $message),
            $attempts,
            \microtime(true) - $started,
        );
    }

    /**
     * Splits OUTBOUND_PROXY into a credential-free URL and a user:password
     * pair, so credentials never appear in the URL libcurl or logs see.
     */
    private function parseProxy(?string $proxy): void
    {
        if ($proxy === null || $proxy === '') {
            return;
        }

        $parts = \parse_url($proxy);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = \is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!\is_array($parts) || !\is_string($scheme) || !\is_string($host) || $host === ''
            || !\in_array(\strtolower($scheme), ['http', 'https', 'socks5', 'socks5h'], true)) {
            $this->proxyInvalid = true;

            return;
        }

        $url = \strtolower($scheme) . '://' . $host;
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $this->proxyUrl = $url;

        if (isset($parts['user'])) {
            $this->proxyAuth = \rawurldecode($parts['user']) . ':' . \rawurldecode($parts['pass'] ?? '');
        }
    }
}
