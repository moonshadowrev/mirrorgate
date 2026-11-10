<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

/**
 * Cache usage snapshot. Deliberately carries no filesystem path.
 */
final readonly class CacheStats
{
    public function __construct(
        public int $entries,
        public int $bytes,
        public int $generation,
    ) {
    }
}
