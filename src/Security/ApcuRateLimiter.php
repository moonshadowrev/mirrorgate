<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

/**
 * Fixed window rate limiter on top of APCu. A counter entry is created with a TTL equal to the
 * window and an expiry marker next to it, so the retry delay can be reported. APCu problems fail open.
 */
final class ApcuRateLimiter implements RateLimiterInterface, RateLimitPeekInterface
{
    public function __construct(private readonly string $prefix)
    {
    }

    public static function isUsable(): bool
    {
        return function_exists('apcu_add') && function_exists('apcu_inc') && function_exists('apcu_fetch')
            && function_exists('apcu_delete') && function_exists('apcu_enabled') && apcu_enabled();
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $limit = max(0, $limit);
        $window = max(1, $windowSeconds);
        if (!self::isUsable()) {
            return new RateLimitResult(true, $limit, $limit, 0);
        }
        $counter = $this->counterKey($key);
        $expiry = $counter . '|e';
        $now = time();
        // A fresh window: the counter lives exactly $window seconds, the marker records when it ends.
        if (apcu_add($counter, 0, $window)) {
            apcu_store($expiry, $now + $window, $window);
        }
        $success = false;
        $count = apcu_inc($counter, 1, $success);
        if ($count === false || !$success) {
            return new RateLimitResult(true, $limit, $limit, 0);
        }
        $count = (int) $count;
        $allowed = $count <= $limit;
        return new RateLimitResult(
            $allowed,
            $limit,
            max(0, $limit - $count),
            $allowed ? 0 : $this->retryAfter($expiry, $now, $window),
        );
    }

    public function peek(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $limit = max(0, $limit);
        $window = max(1, $windowSeconds);
        if (!self::isUsable()) {
            return new RateLimitResult(true, $limit, $limit, 0);
        }
        $counter = $this->counterKey($key);
        $ok = false;
        $value = apcu_fetch($counter, $ok);
        $count = ($ok && is_int($value)) ? $value : 0;
        $allowed = $count < $limit;
        return new RateLimitResult(
            $allowed,
            $limit,
            max(0, $limit - $count),
            $allowed ? 0 : $this->retryAfter($counter . '|e', time(), $window),
        );
    }

    public function reset(string $key): void
    {
        if (!self::isUsable()) {
            return;
        }
        $counter = $this->counterKey($key);
        apcu_delete($counter);
        apcu_delete($counter . '|e');
    }

    private function counterKey(string $key): string
    {
        return $this->prefix . ':' . hash('sha256', $key);
    }

    private function retryAfter(string $expiryKey, int $now, int $window): int
    {
        $ok = false;
        $until = apcu_fetch($expiryKey, $ok);
        if ($ok && is_int($until)) {
            return max(1, $until - $now);
        }
        return $window;
    }
}
