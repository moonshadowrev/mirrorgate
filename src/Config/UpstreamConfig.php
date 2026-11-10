<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class UpstreamConfig
{
    public function __construct(
        public int $connectTimeout,         // ORIGIN_CONNECT_TIMEOUT
        public int $timeout,                // ORIGIN_TIMEOUT
        public int $lowSpeedTime,           // ORIGIN_LOW_SPEED_TIME
        public int $lowSpeedLimit,          // ORIGIN_LOW_SPEED_LIMIT (bytes/s)
        public int $retries,                // ORIGIN_RETRIES
        public int $retryBaseMs,
        public int $retryCapMs,
        public string $httpVersion,         // ORIGIN_HTTP_VERSION: 1.1|2
        public int $maxRequestBodyBytes,    // MAX_REQUEST_BODY_BYTES
        public int $maxUrlLength,           // MAX_URL_LENGTH
        public int $maxHeaderCount,         // MAX_HEADER_COUNT
        public int $maxHeaderBytes,         // MAX_HEADER_BYTES (per line)
        public int $maxStreamBytes,         // MAX_STREAM_BYTES (0 = unlimited)
        public bool $breakerEnabled,
        public int $breakerThreshold,
        public int $breakerWindow,
        public int $breakerCooldown,
        public int $breakerMaxCooldown,
        public bool $forwardCookies,
        public bool $forwardAuthorization,
        public bool $forwardClientIp,
        /** @var list<string> */ public array $allowedMethods,       // ALLOWED_METHODS
        /** @var list<string> lowercase names or trailing-dash prefixes */ public array $requestHeaderStrip = [], // REQUEST_HEADER_STRIP
    ) {
    }
}
