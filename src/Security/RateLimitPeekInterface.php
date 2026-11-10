<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

/**
 * Optional capability of a rate limiter (module-internal extension of RateLimiterInterface):
 * read the current counter without incrementing it. AdminAuthenticator uses it to check the failure
 * counters BEFORE comparing the token and to increment them only on failure.
 */
interface RateLimitPeekInterface
{
    /**
     * Reports the state of the current window without counting a hit. The result is "allowed"
     * while fewer than $limit hits were registered.
     */
    public function peek(string $key, int $limit, int $windowSeconds): RateLimitResult;
}
