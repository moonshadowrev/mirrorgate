<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Upstream;

use MirrorGate\Tests\Support\Upstream\FixedClock;
use MirrorGate\Tests\Support\Upstream\UpstreamTestConfig;
use MirrorGate\Upstream\BreakerState;
use MirrorGate\Upstream\CircuitBreaker;
use MirrorGate\Upstream\ErrorKind;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/mg-breaker-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->dir)) {
            foreach (\glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (\is_file($file)) {
                    @\unlink($file);
                }
            }
            @\rmdir($this->dir);
        }
    }

    private function make(FixedClock $clock, bool $enabled = true): CircuitBreaker
    {
        return new CircuitBreaker(
            UpstreamTestConfig::upstream(breakerEnabled: $enabled, breakerThreshold: 3, breakerWindow: 30, breakerCooldown: 10, breakerMaxCooldown: 40),
            $this->dir,
            $clock,
        );
    }

    private function trip(CircuitBreaker $b): void
    {
        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($b->allow());
            $b->recordFailure(ErrorKind::Connect);
        }
    }

    public function testStaysClosedBelowThreshold(): void
    {
        $b = $this->make(new FixedClock());
        $b->recordFailure(ErrorKind::Connect);
        $b->recordFailure(ErrorKind::Timeout);

        self::assertTrue($b->allow());
        self::assertSame(BreakerState::Closed, $b->state());
        self::assertSame(2, $b->snapshot()['failures']);
    }

    public function testOpensAtThresholdAndBlocksWithoutIo(): void
    {
        $clock = new FixedClock();
        $b = $this->make($clock);
        $this->trip($b);

        self::assertSame(BreakerState::Open, $b->state());
        self::assertFalse($b->allow());
        self::assertSame(10, $b->retryAfter());
        $clock->advance(4);
        self::assertSame(6, $b->retryAfter());
        self::assertSame('open', $b->snapshot()['state']);
    }

    public function testFailuresOutsideTheWindowDoNotAccumulate(): void
    {
        $clock = new FixedClock();
        $b = $this->make($clock);
        $b->recordFailure(ErrorKind::Connect);
        $b->recordFailure(ErrorKind::Connect);
        $clock->advance(31);
        $b->recordFailure(ErrorKind::Connect);

        self::assertSame(BreakerState::Closed, $b->state());
    }

    public function testNeutralKindsAreIgnored(): void
    {
        $b = $this->make(new FixedClock());
        for ($i = 0; $i < 10; ++$i) {
            $b->recordFailure(ErrorKind::ClientAborted);
            $b->recordFailure(ErrorKind::RequestTooLarge);
            $b->recordFailure(ErrorKind::SsrfBlocked);
        }

        self::assertSame(BreakerState::Closed, $b->state());
        self::assertSame(0, $b->snapshot()['failures']);
    }

    public function testHalfOpenAdmitsExactlyOneProbeAcrossProcesses(): void
    {
        $clock = new FixedClock();
        $first = $this->make($clock);
        $this->trip($first);
        $clock->advance(10);

        $second = $this->make($clock);
        self::assertSame(BreakerState::HalfOpen, $second->state());
        self::assertTrue($first->allow(), 'first caller becomes the probe');
        self::assertFalse($second->allow(), 'second caller is refused while the probe is in flight');
        self::assertSame(1, $second->retryAfter());
    }

    public function testSuccessfulProbeClosesTheCircuit(): void
    {
        $clock = new FixedClock();
        $b = $this->make($clock);
        $this->trip($b);
        $clock->advance(10);

        self::assertTrue($b->allow());
        $b->recordSuccess();

        self::assertSame(BreakerState::Closed, $b->state());
        self::assertTrue($this->make($clock)->allow());
        self::assertSame(0, $b->snapshot()['failures']);
    }

    public function testFailedProbeReopensWithDoubledCooldownUpToTheCap(): void
    {
        $clock = new FixedClock();
        $b = $this->make($clock);
        $this->trip($b);

        $expected = [20, 40, 40];
        foreach ($expected as $cooldown) {
            $clock->advance($b->snapshot()['cooldown']);
            self::assertTrue($b->allow());
            $b->recordFailure(ErrorKind::Timeout);

            self::assertSame(BreakerState::Open, $b->state());
            self::assertSame($cooldown, $b->snapshot()['cooldown']);
            self::assertFalse($b->allow());
        }
    }

    public function testSuccessResetsCooldownToBase(): void
    {
        $clock = new FixedClock();
        $b = $this->make($clock);
        $this->trip($b);
        $clock->advance(10);
        $b->allow();
        $b->recordFailure(ErrorKind::Connect);
        self::assertSame(20, $b->snapshot()['cooldown']);

        $clock->advance(20);
        $b->allow();
        $b->recordSuccess();

        self::assertSame(10, $b->snapshot()['cooldown']);
    }

    public function testLateSuccessFromAnotherRequestDoesNotCloseAnOpenCircuit(): void
    {
        $clock = new FixedClock();
        $b = $this->make($clock);
        $this->trip($b);

        $other = $this->make($clock);
        $other->recordSuccess();

        self::assertSame(BreakerState::Open, $b->state());
    }

    public function testNeutralProbeOutcomeFreesTheProbeSlot(): void
    {
        $clock = new FixedClock();
        $b = $this->make($clock);
        $this->trip($b);
        $clock->advance(10);
        self::assertTrue($b->allow());
        $b->recordFailure(ErrorKind::ClientAborted);

        self::assertTrue($this->make($clock)->allow());
    }

    public function testAllowIsIdempotentForTheProbeOwner(): void
    {
        $clock = new FixedClock();
        $b = $this->make($clock);
        $this->trip($b);
        $clock->advance(10);

        self::assertTrue($b->allow());
        self::assertTrue($b->allow(), 're-fetch and background refresh reuse the single decision');
    }

    public function testDisabledBreakerAlwaysAllows(): void
    {
        $b = $this->make(new FixedClock(), false);
        for ($i = 0; $i < 20; ++$i) {
            $b->recordFailure(ErrorKind::Connect);
        }

        self::assertTrue($b->allow());
        self::assertSame(BreakerState::Closed, $b->state());
    }

    public function testStatePersistsAcrossInstancesAndCorruptionFailsClosedToFresh(): void
    {
        $clock = new FixedClock();
        $this->trip($this->make($clock));
        self::assertSame(BreakerState::Open, $this->make($clock)->state());

        \file_put_contents($this->dir . '/breaker.json', 'garbage');
        self::assertSame(BreakerState::Closed, $this->make($clock)->state());
    }

    public function testSnapshotShape(): void
    {
        $snap = $this->make(new FixedClock())->snapshot();

        self::assertSame(['state', 'failures', 'openUntil', 'cooldown'], \array_keys($snap));
        self::assertSame('closed', $snap['state']);
    }
}
