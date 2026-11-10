<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

/**
 * Freshness state of a cache lookup.
 */
enum CacheState
{
    case Miss;
    case Fresh;
    case StaleWhileRevalidate;
    case Expired;
    case StaleUsableOnError;

    /**
     * Value for the X-MirrorGate-Cache header when an entry in this state is served as is
     * (Fresh => HIT, any stale state => STALE). Miss and Expired describe the lookup, not a served
     * entry, and map to the labels the Kernel uses after a fetch (MISS / EXPIRED).
     */
    public function headerValue(): string
    {
        return match ($this) {
            self::Miss => 'MISS',
            self::Fresh => 'HIT',
            self::StaleWhileRevalidate, self::StaleUsableOnError => 'STALE',
            self::Expired => 'EXPIRED',
        };
    }
}
