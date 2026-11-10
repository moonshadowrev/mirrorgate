<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

interface RateLimiterInterface
{
    /**
     * Registers one hit for $key in a fixed window and reports whether the hit is within $limit.
     * Limiters fail open: a storage problem never turns into a denial of service for visitors.
     */
    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult;

    public function reset(string $key): void;
}
