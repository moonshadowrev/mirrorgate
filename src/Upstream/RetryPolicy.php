<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Config\UpstreamConfig;

/**
 * Retry budget and exponential back-off with full jitter.
 */
final class RetryPolicy
{
    /** Retries may consume at most this share of the total timeout. */
    private const BUDGET_PERCENT = 60;

    public function __construct(private readonly UpstreamConfig $config)
    {
    }

    /**
     * Total number of attempts allowed in one fetch() call (first try included).
     */
    public function maxAttempts(): int
    {
        return 1 + \max(0, $this->config->retries);
    }

    /**
     * Delay in milliseconds before the next attempt. $attempt is the number of
     * attempts that already failed (1 = the first attempt just failed).
     * Full jitter: uniform in [0, min(cap, base * 2^(attempt-1))].
     */
    public function delayMs(int $attempt): int
    {
        $exponent = \min(\max($attempt - 1, 0), 16);
        $ceiling = \min($this->config->retryCapMs, $this->config->retryBaseMs * (1 << $exponent));

        return $ceiling <= 0 ? 0 : \random_int(0, $ceiling);
    }

    public function isIdempotent(string $method): bool
    {
        return $method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS';
    }

    /**
     * True when sleeping $delayMs after $elapsedMs of work still leaves the
     * retry inside 60% of the total timeout.
     */
    public function withinBudget(float $elapsedMs, int $delayMs, int $totalTimeoutSeconds): bool
    {
        $budgetMs = $totalTimeoutSeconds * 1000 * self::BUDGET_PERCENT / 100;

        return ($elapsedMs + $delayMs) < $budgetMs;
    }
}
