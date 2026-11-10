<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class RateLimitConfig
{
    public function __construct(
        public bool $enabled,
        public int $requests,
        public int $window,
        public string $driver,                // RATE_LIMIT_DRIVER: auto|apcu|file
        public int $maxConcurrent,            // RATE_LIMIT_MAX_CONCURRENT per client IP (0 = off)
        public int $originFetchesPerMinute,   // RATE_LIMIT_ORIGIN_FETCHES_PER_MINUTE (0 = off)
        /** @var list<string> */ public array $exemptIps,   // RATE_LIMIT_EXEMPT_IPS (CIDRs)
        public string $salt,                  // RATE_LIMIT_SALT; '' when unset
        public int $globalMaxConcurrent = 8,  // GLOBAL_MAX_CONCURRENT
        public ?string $sharedDir = null,     // CONCURRENCY_SHARED_DIR
    ) {
    }
}
