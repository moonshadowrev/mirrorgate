<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Config\RateLimitConfig;
use MirrorGate\Support\Clock;

final class RateLimiterFactory
{
    /**
     * Picks the driver: "apcu" and "auto" use APCu when it is usable, everything else (or APCu
     * unavailable) uses the shard files under `<storageDir>/ratelimit`.
     */
    public static function create(RateLimitConfig $c, string $storageDir, Clock $clock): RateLimiterInterface
    {
        if ($c->driver !== 'file' && ApcuRateLimiter::isUsable()) {
            // The prefix keeps instances that share one APCu pool (same PHP-FPM pool) apart.
            return new ApcuRateLimiter('mg:rl:' . substr(hash('sha256', $storageDir), 0, 12));
        }
        return new FileRateLimiter(rtrim($storageDir, '/\\') . '/ratelimit', $clock);
    }

    /**
     * HMAC-SHA256("$scope|$subject", $salt) as hex. IP subjects are normalised first: IPv4-mapped
     * IPv6 becomes IPv4 and every other IPv6 address is collapsed to its /64 prefix. Non-IP
     * subjects (for example the instance id) are used verbatim. Raw addresses are never stored.
     */
    public static function keyFor(string $scope, string $subject, string $salt): string
    {
        return hash_hmac('sha256', $scope . '|' . self::normalizeSubject($subject), $salt);
    }

    private static function normalizeSubject(string $subject): string
    {
        $bin = Cidr::packIp($subject);
        if ($bin === null) {
            return $subject;
        }
        if (Cidr::isMapped($bin)) {
            $v4 = inet_ntop(substr($bin, 12, 4));
            return $v4 === false ? $subject : $v4;
        }
        $prefix = inet_ntop(substr($bin, 0, 8) . str_repeat("\x00", 8));
        return $prefix === false ? $subject : $prefix . '/64';
    }
}
