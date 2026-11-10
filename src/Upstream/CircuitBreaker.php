<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Config\UpstreamConfig;
use MirrorGate\Support\Clock;
use MirrorGate\Support\FileUtil;
use MirrorGate\Support\Json;

/**
 * Circuit breaker persisted in the file store so that every PHP worker of an
 * instance shares one view of origin health (SEC-72).
 *
 * States
 * - closed:    traffic flows; failures are collected in a sliding window and
 *              BREAKER_THRESHOLD failures within BREAKER_WINDOW open the circuit.
 * - open:      allow() answers false without any network I/O until the cool-down
 *              (BREAKER_COOLDOWN, doubling up to BREAKER_MAX_COOLDOWN) elapsed.
 * - half-open: exactly ONE probe request is admitted. The probe is the process
 *              holding a non-blocking flock() on probe.lock; the lock is released
 *              by recordSuccess()/recordFailure(), by the destructor, or by the
 *              operating system when the process dies. A successful probe closes
 *              the circuit; a failed one re-opens it with a doubled cool-down.
 *
 * Only the Kernel calls allow() (once per client request) and the record*()
 * methods; the UpstreamClient never touches the breaker.
 *
 * Storage problems fail open: an unusable state directory disables the breaker
 * rather than blocking traffic.
 */
final class CircuitBreaker implements BreakerInspector
{
    private const STATE_FILE = 'breaker.json';
    private const STATE_LOCK = 'breaker.lock';
    private const PROBE_LOCK = 'probe.lock';
    private const MAX_STATE_BYTES = 8192;
    private const LOCK_WAIT_US = 250000;

    /** @var resource|null probe lock held by this process while it is the half-open probe */
    private mixed $probeHandle = null;

    private bool $dirReady = false;

    public function __construct(
        private readonly UpstreamConfig $config,
        private readonly string $dir,
        private readonly Clock $clock,
    ) {
    }

    public function __destruct()
    {
        $this->releaseProbe();
    }

    /**
     * Whether a request may use the origin. In the half-open state at most one
     * caller (across all processes) receives true.
     */
    public function allow(): bool
    {
        if (!$this->config->breakerEnabled) {
            return true;
        }

        if ($this->probeHandle !== null) {
            // This process already is the probe (re-fetch / background refresh).
            return true;
        }

        $state = $this->read();
        if ($state['state'] === 'closed') {
            return true;
        }

        $now = $this->clock->time();
        if ($state['state'] === 'open' && $now < $state['openUntil']) {
            return false;
        }

        return $this->acquireProbe();
    }

    public function recordSuccess(): void
    {
        if (!$this->config->breakerEnabled || $this->probeHandle === null) {
            // Successes in the closed state need no bookkeeping; late successes
            // from requests that started before the circuit opened must not
            // close it (only the probe may).
            return;
        }

        $lock = $this->lockState();
        try {
            $this->write([
                'state' => 'closed',
                'failures' => [],
                'openUntil' => 0,
                'cooldown' => $this->config->breakerCooldown,
            ]);
        } finally {
            $this->unlockState($lock);
            $this->releaseProbe();
        }
    }

    public function recordFailure(ErrorKind $kind): void
    {
        if (!$this->config->breakerEnabled) {
            return;
        }

        if (!ErrorMapper::countsForBreaker($kind)) {
            // Neutral outcome: a probe that ended neutrally frees the slot so
            // the next request can probe again; the state is untouched.
            $this->releaseProbe();

            return;
        }

        $lock = $this->lockState();
        try {
            $state = $this->read();
            $now = $this->clock->time();
            $wasProbe = $this->probeHandle !== null;

            if ($wasProbe) {
                $cooldown = \min(
                    \max($state['cooldown'], $this->config->breakerCooldown) * 2,
                    $this->config->breakerMaxCooldown,
                );
                $this->write([
                    'state' => 'open',
                    'failures' => $state['failures'],
                    'openUntil' => $now + $cooldown,
                    'cooldown' => $cooldown,
                ]);

                return;
            }

            if ($state['state'] !== 'closed') {
                // Late failure from a request that began before the circuit
                // opened: already accounted for, do not extend the cool-down.
                return;
            }

            $failures = $this->pruned($state['failures'], $now);
            $failures[] = $now;
            if (\count($failures) > $this->config->breakerThreshold) {
                $failures = \array_slice($failures, -$this->config->breakerThreshold);
            }

            if (\count($failures) >= $this->config->breakerThreshold) {
                $cooldown = \max($state['cooldown'], $this->config->breakerCooldown);
                $this->write([
                    'state' => 'open',
                    'failures' => $failures,
                    'openUntil' => $now + $cooldown,
                    'cooldown' => $cooldown,
                ]);

                return;
            }

            $this->write([
                'state' => 'closed',
                'failures' => $failures,
                'openUntil' => 0,
                'cooldown' => $state['cooldown'],
            ]);
        } finally {
            $this->unlockState($lock);
            $this->releaseProbe();
        }
    }

    public function state(): BreakerState
    {
        if (!$this->config->breakerEnabled) {
            return BreakerState::Closed;
        }

        $state = $this->read();
        if ($state['state'] === 'closed') {
            return BreakerState::Closed;
        }
        if ($state['state'] === 'open' && $this->clock->time() < $state['openUntil']) {
            return BreakerState::Open;
        }

        return BreakerState::HalfOpen;
    }

    /**
     * Seconds until the next probe may be admitted; 0 when closed.
     */
    public function retryAfter(): int
    {
        $state = $this->state();
        if ($state === BreakerState::Closed) {
            return 0;
        }
        if ($state === BreakerState::HalfOpen) {
            return 1;
        }

        $raw = $this->read();

        return \max(1, $raw['openUntil'] - $this->clock->time());
    }

    /**
     * @return array{state:string,failures:int,openUntil:int,cooldown:int}
     */
    public function snapshot(): array
    {
        $raw = $this->config->breakerEnabled ? $this->read() : $this->fresh();
        $state = $this->state();
        $now = $this->clock->time();

        $failures = $raw['state'] === 'closed'
            ? \count($this->pruned($raw['failures'], $now))
            : \count($raw['failures']);

        return [
            'state' => match ($state) {
                BreakerState::Closed => 'closed',
                BreakerState::Open => 'open',
                BreakerState::HalfOpen => 'half_open',
            },
            'failures' => $failures,
            'openUntil' => $raw['openUntil'],
            'cooldown' => $raw['cooldown'],
        ];
    }

    private function acquireProbe(): bool
    {
        if (!$this->prepareDir()) {
            return true; // fail open
        }

        $lock = $this->lockState();
        try {
            // Re-read under the lock: another worker may have closed or probed.
            $state = $this->read();
            if ($state['state'] === 'closed') {
                return true;
            }
            if ($state['state'] === 'open' && $this->clock->time() < $state['openUntil']) {
                return false;
            }

            $handle = FileUtil::openLock($this->dir . '/' . self::PROBE_LOCK);
            if ($handle === null) {
                return true; // fail open
            }
            if (!\flock($handle, LOCK_EX | LOCK_NB)) {
                \fclose($handle);

                return false;
            }

            $this->probeHandle = $handle;
            if ($state['state'] !== 'half_open') {
                $state['state'] = 'half_open';
                $this->write($state);
            }

            return true;
        } finally {
            $this->unlockState($lock);
        }
    }

    private function releaseProbe(): void
    {
        $handle = $this->probeHandle;
        $this->probeHandle = null;
        if ($handle !== null) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
        }
    }

    /**
     * @return array{state:string,failures:list<int>,openUntil:int,cooldown:int}
     */
    private function fresh(): array
    {
        return [
            'state' => 'closed',
            'failures' => [],
            'openUntil' => 0,
            'cooldown' => $this->config->breakerCooldown,
        ];
    }

    /**
     * @return array{state:string,failures:list<int>,openUntil:int,cooldown:int}
     */
    private function read(): array
    {
        $raw = FileUtil::readFile($this->dir . '/' . self::STATE_FILE, self::MAX_STATE_BYTES);
        if ($raw === null) {
            return $this->fresh();
        }

        try {
            $data = Json::decode($raw, 4);
        } catch (\JsonException) {
            return $this->fresh();
        }

        if (!\is_array($data)) {
            return $this->fresh();
        }

        $state = $data['state'] ?? null;
        $openUntil = $data['openUntil'] ?? null;
        $cooldown = $data['cooldown'] ?? null;
        $failuresRaw = $data['failures'] ?? null;
        if (!\in_array($state, ['closed', 'open', 'half_open'], true)
            || !\is_int($openUntil) || !\is_int($cooldown) || !\is_array($failuresRaw)) {
            return $this->fresh();
        }

        $failures = [];
        foreach ($failuresRaw as $ts) {
            if (\is_int($ts)) {
                $failures[] = $ts;
            }
            if (\count($failures) >= 100) {
                break;
            }
        }

        return [
            'state' => $state,
            'failures' => $failures,
            'openUntil' => $openUntil,
            'cooldown' => \max($this->config->breakerCooldown, \min($cooldown, $this->config->breakerMaxCooldown)),
        ];
    }

    /**
     * @param array{state:string,failures:list<int>,openUntil:int,cooldown:int} $state
     */
    private function write(array $state): void
    {
        if (!$this->prepareDir()) {
            return;
        }

        try {
            FileUtil::atomicWrite($this->dir . '/' . self::STATE_FILE, Json::encode($state));
        } catch (\Throwable) {
            // Fail open: a read-only store disables persistence, not traffic.
        }
    }

    /**
     * @param list<int> $failures
     * @return list<int>
     */
    private function pruned(array $failures, int $now): array
    {
        $floor = $now - $this->config->breakerWindow;

        return \array_values(\array_filter($failures, static fn (int $ts): bool => $ts > $floor));
    }

    private function prepareDir(): bool
    {
        if ($this->dirReady) {
            return true;
        }

        try {
            FileUtil::ensureDir($this->dir);
        } catch (\Throwable) {
            return false;
        }

        $this->dirReady = true;

        return true;
    }

    /**
     * Short exclusive lock serialising read-modify-write cycles. Waits at most
     * about 250 ms and then proceeds without it (a lost update only delays the
     * breaker by one failure).
     *
     * @return resource|null
     */
    private function lockState()
    {
        if (!$this->prepareDir()) {
            return null;
        }

        $handle = FileUtil::openLock($this->dir . '/' . self::STATE_LOCK);
        if ($handle === null) {
            return null;
        }

        $waited = 0;
        while (!\flock($handle, LOCK_EX | LOCK_NB)) {
            if ($waited >= self::LOCK_WAIT_US) {
                \fclose($handle);

                return null;
            }
            \usleep(2000);
            $waited += 2000;
        }

        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function unlockState($handle): void
    {
        if ($handle !== null) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
        }
    }
}
