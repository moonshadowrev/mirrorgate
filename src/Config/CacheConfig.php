<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class CacheConfig
{
    public function __construct(
        public bool $enabled,
        public string $dir,
        public int $maxBytes,
        public int $maxObjectBytes,
        public int $defaultTtl,
        public int $minTtl,
        public int $maxTtl,
        public int $negativeTtl,
        public int $swrSeconds,
        public int $staleIfErrorSeconds,
        /** @var list<string> lowercase, no dot */ public array $staticExtensions,
        /** @var list<array{pattern:string,ttl:int}> */ public array $rules,       // CACHE_RULES; ttl -1 = bypass
        public string $cookieMode,          // CACHE_COOKIE_MODE: bypass|static-only
        public bool $honorClientNoCache,    // CACHE_HONOR_CLIENT_NOCACHE
        public bool $ignoreMustRevalidateOnError,   // CACHE_IGNORE_MUST_REVALIDATE_ON_ERROR
        public int $coalesceWaitMs,
        public int $gcProbability,
        public int $gcIntervalSeconds,
        public string $lockMode,            // CACHE_LOCK_MODE: flock|mkdir
        /** @var list<string> */ public array $ignoreQueryParams,      // CACHE_IGNORE_QUERY_PARAMS
        /** @var list<string> */ public array $varyExtra,              // CACHE_VARY_EXTRA (lowercase header names)
        /** @var list<int> */ public array $statuses,                  // CACHE_STATUSES
        public int $maxEntries = 20000,     // CACHE_MAX_ENTRIES
        public bool $storePlainPaths = false,   // true only when LOG_PATH_MODE=full
        /** @var list<string> lowercase request header names that make a request cache-ineligible */
        public array $bypassHeaders = ['x-api-key', 'x-auth-token', 'x-access-token', 'proxy-authorization', 'x-csrf-token', 'x-xsrf-token'], // CACHE_BYPASS_HEADERS
        public int $maxQueryVariants = 20,      // CACHE_MAX_QUERY_VARIANTS (distinct stored query strings per path)
    ) {
    }
}
