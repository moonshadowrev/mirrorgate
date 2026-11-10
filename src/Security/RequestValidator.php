<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Config\UpstreamConfig;
use MirrorGate\Http\HttpException;
use MirrorGate\Http\Request;
use MirrorGate\Support\Str;

/**
 * Structural validation of an incoming request. Every rejection is a plain
 * HttpException whose message is a fixed string; nothing from the request is echoed back.
 *
 * Smuggling checks only see what the SAPI exposes. Web servers normally de-chunk bodies before
 * PHP runs, so these checks are best effort; the real defence is that the Kernel spools the body
 * and always sends its own exact Content-Length upstream.
 */
final class RequestValidator
{
    private const KNOWN_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'];
    /** Methods that may never be proxied, whatever ALLOWED_METHODS says. */
    private const NEVER_ALLOWED = ['TRACE', 'CONNECT'];
    private const MAX_CL_DIGITS = 18;

    /** @var list<string> */
    private readonly array $allowed;

    public function __construct(private readonly UpstreamConfig $config)
    {
        $allowed = [];
        foreach ($config->allowedMethods as $method) {
            $method = strtoupper($method);
            if (in_array($method, self::KNOWN_METHODS, true) && !in_array($method, self::NEVER_ALLOWED, true)) {
                $allowed[$method] = $method;
            }
        }
        $this->allowed = array_values($allowed);
    }

    /** @throws HttpException 400, 405, 413, 414, 417, 431, 501 */
    public function validate(Request $request): void
    {
        $this->checkMethod($request);
        $this->checkSizesAndSyntax($request);
        $this->checkFraming($request);
        $this->checkUpgrade($request);
        $this->checkExpect($request);
    }

    private function checkMethod(Request $request): void
    {
        $method = $request->method;
        if (!in_array($method, self::KNOWN_METHODS, true)) {
            throw new HttpException(501, 'Not implemented');
        }
        if (!in_array($method, $this->allowed, true)) {
            throw new HttpException(405, 'Method not allowed', [['Allow', implode(', ', $this->allowed)]]);
        }
    }

    private function checkSizesAndSyntax(Request $request): void
    {
        if (strlen($request->rawTarget) > $this->config->maxUrlLength) {
            throw new HttpException(414, 'URI too long');
        }
        $headers = $request->headers;
        if (count($headers) > $this->config->maxHeaderCount) {
            throw new HttpException(431, 'Too many header fields');
        }
        foreach ($headers->all() as [$name, $value]) {
            if (strlen($name) + strlen($value) + 2 > $this->config->maxHeaderBytes) {
                throw new HttpException(431, 'Header field too large');
            }
            if (!Str::isHeaderToken($name) || Str::hasControlChars($name) || Str::hasControlChars($value)) {
                throw new HttpException(400, 'Malformed header');
            }
        }
        $hosts = $headers->getAll('host');
        if (count($hosts) > 1) {
            throw new HttpException(400, 'Duplicate Host header');
        }
        if ($hosts !== [] && str_contains($hosts[0], ',')) {
            throw new HttpException(400, 'Malformed Host header');
        }
    }

    private function checkFraming(Request $request): void
    {
        $headers = $request->headers;
        $lengths = $headers->getAll('content-length');
        $encodings = $headers->getAll('transfer-encoding');
        if ($lengths !== [] && $encodings !== []) {
            throw new HttpException(400, 'Conflicting message framing');
        }
        if ($encodings !== []) {
            foreach ($encodings as $value) {
                if (strtolower(trim($value)) !== 'chunked') {
                    throw new HttpException(501, 'Unsupported transfer coding');
                }
            }
            if (count($encodings) > 1) {
                throw new HttpException(501, 'Unsupported transfer coding');
            }
        }
        $declared = null;
        foreach ($lengths as $line) {
            foreach (explode(',', $line) as $part) {
                $part = trim($part);
                if (preg_match('/^[0-9]+$/D', $part) !== 1) {
                    throw new HttpException(400, 'Invalid Content-Length');
                }
                if (strlen($part) > self::MAX_CL_DIGITS) {
                    throw new HttpException(413, 'Payload too large');
                }
                $n = (int) $part;
                if ($declared !== null && $declared !== $n) {
                    throw new HttpException(400, 'Conflicting Content-Length');
                }
                $declared = $n;
            }
        }
        $limit = $this->config->maxRequestBodyBytes;
        if (($declared !== null && $declared > $limit) || ($request->contentLength !== null && $request->contentLength > $limit)) {
            throw new HttpException(413, 'Payload too large');
        }
    }

    private function checkUpgrade(Request $request): void
    {
        foreach ($request->headers->getAll('upgrade') as $line) {
            foreach (explode(',', $line) as $token) {
                $token = strtolower(trim($token));
                if ($token === '') {
                    continue;
                }
                // h2c is dropped by hop-by-hop removal and never honoured; anything else
                // (notably websocket) cannot be held by PHP.
                if ($token !== 'h2c') {
                    throw new HttpException(501, 'Upgrade not supported');
                }
            }
        }
    }

    private function checkExpect(Request $request): void
    {
        foreach ($request->headers->getAll('expect') as $value) {
            if (strtolower(trim($value)) !== '100-continue') {
                throw new HttpException(417, 'Expectation failed');
            }
        }
    }
}
