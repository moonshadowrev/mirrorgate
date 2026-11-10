<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

/**
 * Result of CachePolicy::evaluate(). Durations are seconds; $reason is a short machine code.
 */
final readonly class CacheDecision
{
    public function __construct(
        public bool $storable,
        public int $ttl,
        public int $swr,
        public int $staleIfError,
        public bool $mustRevalidate,
        public string $reason,
    ) {
    }
}
