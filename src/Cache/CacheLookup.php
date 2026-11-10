<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

/**
 * Result of HttpCache::lookup().
 */
final readonly class CacheLookup
{
    public function __construct(
        public CacheState $state,
        public ?CacheEntry $entry,
        public CacheKey $key,
    ) {
    }
}
