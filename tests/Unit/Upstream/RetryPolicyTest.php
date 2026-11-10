<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Upstream;

use MirrorGate\Tests\Support\Upstream\UpstreamTestConfig;
use MirrorGate\Upstream\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testMaxAttemptsIncludesFirstTry(): void
    {
        self::assertSame(1, (new RetryPolicy(UpstreamTestConfig::upstream(retries: 0)))->maxAttempts());
        self::assertSame(4, (new RetryPolicy(UpstreamTestConfig::upstream(retries: 3)))->maxAttempts());
    }

    public function testDelayIsFullJitterWithinCap(): void
    {
        $policy = new RetryPolicy(UpstreamTestConfig::upstream(retryBaseMs: 100, retryCapMs: 1000));
        for ($i = 0; $i < 200; ++$i) {
            self::assertLessThanOrEqual(100, $policy->delayMs(1));
            self::assertLessThanOrEqual(200, $policy->delayMs(2));
            self::assertLessThanOrEqual(1000, $policy->delayMs(50));
            self::assertGreaterThanOrEqual(0, $policy->delayMs(3));
        }
    }

    public function testOnlySafeMethodsAreIdempotent(): void
    {
        $policy = new RetryPolicy(UpstreamTestConfig::upstream());
        foreach (['GET', 'HEAD', 'OPTIONS'] as $m) {
            self::assertTrue($policy->isIdempotent($m));
        }
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
            self::assertFalse($policy->isIdempotent($m));
        }
    }

    public function testBudgetIsSixtyPercentOfTotalTimeout(): void
    {
        $policy = new RetryPolicy(UpstreamTestConfig::upstream());
        self::assertTrue($policy->withinBudget(1000.0, 500, 10));
        self::assertFalse($policy->withinBudget(5800.0, 500, 10));
        self::assertFalse($policy->withinBudget(6000.0, 0, 10));
    }
}
