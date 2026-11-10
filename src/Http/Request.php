<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

use MirrorGate\Support\Str;

final readonly class Request
{
    public function __construct(
        public string $method,                 // uppercase
        public NormalizedTarget $target,
        public HeaderBag $headers,             // request headers, original names
        public string $remoteAddr,             // REMOTE_ADDR (validated IP string)
        public ?string $hostHeader,            // raw Host header (never trusted)
        public bool $viaTls,                   // HTTPS server var; informational only
        public ?int $contentLength,            // parsed Content-Length or null
        public bool $chunkedBody,              // Transfer-Encoding: chunked present; informational only
        public string $rawTarget,              // REQUEST_URI exactly as received (for validation only)
        public mixed $spooledBody = null,      // resource|null; set only through withSpooledBody()
    ) {
    }

    /**
     * @return resource|null readable stream (php://input or the spooled copy) or null when there is no body
     */
    public function bodyStream()
    {
        if (is_resource($this->spooledBody)) {
            return $this->spooledBody;
        }
        if ($this->contentLength === 0 && !$this->chunkedBody) {
            return null;
        }
        if ($this->contentLength === null && !$this->chunkedBody && in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        $h = @fopen('php://input', 'rb');
        return $h === false ? null : $h;
    }

    public function withHeaders(HeaderBag $headers): self
    {
        return new self(
            $this->method,
            $this->target,
            $headers,
            $this->remoteAddr,
            $this->hostHeader,
            $this->viaTls,
            $this->contentLength,
            $this->chunkedBody,
            $this->rawTarget,
            $this->spooledBody,
        );
    }

    /**
     * Copy whose bodyStream() is the (rewound) spooled stream, with contentLength set to $length and
     * chunkedBody cleared. Called only by BodySpooler.
     *
     * @param resource $stream
     */
    public function withSpooledBody($stream, int $length): self
    {
        return new self(
            $this->method,
            $this->target,
            $this->headers,
            $this->remoteAddr,
            $this->hostHeader,
            $this->viaTls,
            $length,
            false,
            $this->rawTarget,
            $stream,
        );
    }

    /**
     * Builds a request from the PHP superglobal-style arrays.
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed>|null $headers optional headers as returned by getallheaders()
     * @throws BadRequestException
     */
    public static function fromGlobals(array $server, ?array $headers = null): self
    {
        $method = isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
            ? strtoupper($server['REQUEST_METHOD'])
            : '';
        if ($method === '' || !Str::isHeaderToken($method)) {
            throw new BadRequestException('Invalid request method.');
        }

        $rawTarget = isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI']) ? $server['REQUEST_URI'] : '';
        $target = PathNormalizer::normalize($rawTarget);

        $pairs = [];
        if ($headers !== null) {
            foreach ($headers as $name => $value) {
                $pairs[] = [(string) $name, is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value];
            }
            if (!self::hasName($pairs, 'authorization')) {
                $auth = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
                if (is_string($auth) && $auth !== '') {
                    $pairs[] = ['Authorization', $auth];
                }
            }
        } else {
            foreach ($server as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }
                if (str_starts_with($key, 'HTTP_')) {
                    $name = self::headerNameFromServerKey(substr($key, 5));
                    $pairs[] = [$name, $value];
                } elseif ($key === 'CONTENT_TYPE' && $value !== '') {
                    $pairs[] = ['Content-Type', $value];
                } elseif ($key === 'CONTENT_LENGTH' && $value !== '') {
                    $pairs[] = ['Content-Length', $value];
                }
            }
            if (!self::hasName($pairs, 'authorization')) {
                $auth = $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
                if (is_string($auth) && $auth !== '') {
                    $pairs[] = ['Authorization', $auth];
                }
            }
        }

        // Names with "_" are dropped: CGI-style backends map "_" to "-", which would let a client smuggle
        // X_Forwarded_For and similar past the dash-spelled denylist.
        $pairs = array_values(array_filter($pairs, static fn (array $p): bool => !str_contains($p[0], '_')));

        foreach ($pairs as [$n, $v]) {
            if (!Str::isHeaderToken($n) || Str::hasControlChars($v)) {
                throw new BadRequestException('Invalid request header.');
            }
        }
        try {
            $bag = new HeaderBag($pairs);
        } catch (\InvalidArgumentException) {
            throw new BadRequestException('Invalid request header.');
        }

        $remote = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : '';
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            throw new BadRequestException('Invalid client address.');
        }

        $host = $bag->get('Host');
        if ($host === null && isset($server['HTTP_HOST']) && is_string($server['HTTP_HOST'])) {
            $host = $server['HTTP_HOST'];
        }

        $https = $server['HTTPS'] ?? '';
        $viaTls = is_string($https) && $https !== '' && strtolower($https) !== 'off';

        $cl = $bag->get('Content-Length');
        if ($cl === null && isset($server['CONTENT_LENGTH']) && is_string($server['CONTENT_LENGTH'])) {
            $cl = $server['CONTENT_LENGTH'];
        }
        $contentLength = null;
        if ($cl !== null && preg_match('/\A\d{1,18}\z/', trim($cl)) === 1) {
            $contentLength = (int) trim($cl);
        }

        $chunked = false;
        foreach ($bag->getAll('Transfer-Encoding') as $te) {
            if (stripos($te, 'chunked') !== false) {
                $chunked = true;
            }
        }

        return new self(
            $method,
            $target,
            $bag,
            $remote,
            $host,
            $viaTls,
            $contentLength,
            $chunked,
            $rawTarget,
        );
    }

    /**
     * @param list<array{0:string,1:string}> $pairs
     */
    private static function hasName(array $pairs, string $lowerName): bool
    {
        foreach ($pairs as [$n]) {
            if (strtolower($n) === $lowerName) {
                return true;
            }
        }
        return false;
    }

    private static function headerNameFromServerKey(string $key): string
    {
        $parts = explode('_', strtolower($key));
        return implode('-', array_map('ucfirst', $parts));
    }
}
