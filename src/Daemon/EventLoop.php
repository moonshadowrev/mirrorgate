<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

/**
 * Minimal single-process stream_select() reactor.
 *
 * Streams must be non-blocking. Callbacks may add or remove streams and timers freely,
 * including the stream they are being invoked for.
 */
final class EventLoop
{
    private const MAX_WAIT_SECONDS = 0.2;
    private const MAX_CONSECUTIVE_SELECT_FAILURES = 100;

    /** @var array<int,resource> */
    private array $readStreams = [];
    /** @var array<int,resource> */
    private array $writeStreams = [];
    /** @var array<int,callable> */
    private array $readCallbacks = [];
    /** @var array<int,callable> */
    private array $writeCallbacks = [];
    /** @var array<int,array{due:float,cb:callable,interval:?float}> */
    private array $timers = [];
    private int $nextTimerId = 1;
    private bool $running = false;
    /** @var (callable(\Throwable):void)|null */
    private $errorHandler = null;

    /**
     * Installs a handler for exceptions thrown by callbacks. Without one the exception propagates out of run().
     *
     * @param (callable(\Throwable):void)|null $handler
     */
    public function setErrorHandler(?callable $handler): void
    {
        $this->errorHandler = $handler;
    }

    /** @param resource $stream */
    public function addReadStream($stream, callable $cb): void
    {
        $id = get_resource_id($stream);
        $this->readStreams[$id] = $stream;
        $this->readCallbacks[$id] = $cb;
    }

    /** @param resource $stream */
    public function removeReadStream($stream): void
    {
        $id = get_resource_id($stream);
        unset($this->readStreams[$id], $this->readCallbacks[$id]);
    }

    /** @param resource $stream */
    public function addWriteStream($stream, callable $cb): void
    {
        $id = get_resource_id($stream);
        $this->writeStreams[$id] = $stream;
        $this->writeCallbacks[$id] = $cb;
    }

    /** @param resource $stream */
    public function removeWriteStream($stream): void
    {
        $id = get_resource_id($stream);
        unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
    }

    public function addTimer(float $seconds, callable $cb, bool $periodic = false): int
    {
        $seconds = max(0.0, $seconds);
        $id = $this->nextTimerId++;
        $this->timers[$id] = [
            'due' => microtime(true) + $seconds,
            'cb' => $cb,
            'interval' => $periodic ? max(0.001, $seconds) : null,
        ];
        return $id;
    }

    public function cancelTimer(int $id): void
    {
        unset($this->timers[$id]);
    }

    /** Number of distinct streams currently watched for reading or writing. */
    public function connectionCount(): int
    {
        return count($this->readStreams + $this->writeStreams);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function run(): void
    {
        $this->running = true;
        $failures = 0;

        while ($this->running) {
            $this->runTimers();
            if (!$this->running) {
                break;
            }

            $timeout = $this->nextTimeout();
            $read = $this->readStreams;
            $write = $this->writeStreams;

            if ($read === [] && $write === []) {
                usleep((int) round($timeout * 1_000_000));
                continue;
            }

            $except = null;
            $sec = (int) floor($timeout);
            $usec = (int) round(($timeout - $sec) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $sec, $usec);

            if ($ready === false) {
                // Interrupted by a signal, or a watched stream went bad.
                $failures++;
                $this->dropInvalidStreams();
                if ($failures >= self::MAX_CONSECUTIVE_SELECT_FAILURES) {
                    throw new \RuntimeException('stream_select keeps failing');
                }
                usleep(1000);
                continue;
            }
            $failures = 0;

            foreach ($read as $stream) {
                $id = get_resource_id($stream);
                if (isset($this->readCallbacks[$id])) {
                    $this->invoke($this->readCallbacks[$id], $stream);
                }
            }
            foreach ($write as $stream) {
                $id = get_resource_id($stream);
                if (isset($this->writeCallbacks[$id])) {
                    $this->invoke($this->writeCallbacks[$id], $stream);
                }
            }
        }
    }

    private function nextTimeout(): float
    {
        $timeout = self::MAX_WAIT_SECONDS;
        if ($this->timers !== []) {
            $now = microtime(true);
            foreach ($this->timers as $timer) {
                $timeout = min($timeout, max(0.0, $timer['due'] - $now));
            }
        }
        return $timeout;
    }

    private function runTimers(): void
    {
        if ($this->timers === []) {
            return;
        }
        $now = microtime(true);
        foreach (array_keys($this->timers) as $id) {
            // A callback run earlier in this pass may have cancelled this timer.
            $timer = $this->timers[$id] ?? null;
            if ($timer === null || $timer['due'] > $now) {
                continue;
            }
            if ($timer['interval'] === null) {
                unset($this->timers[$id]);
            } else {
                $this->timers[$id]['due'] = $now + $timer['interval'];
            }
            $this->invoke($timer['cb'], null);
        }
    }

    /** @param resource|null $stream */
    private function invoke(callable $cb, $stream): void
    {
        try {
            if ($stream === null) {
                $cb();
            } else {
                $cb($stream);
            }
        } catch (\Throwable $e) {
            if ($this->errorHandler === null) {
                throw $e;
            }
            ($this->errorHandler)($e);
        }
    }

    private function dropInvalidStreams(): void
    {
        foreach ($this->readStreams as $id => $stream) {
            if (!is_resource($stream)) {
                unset($this->readStreams[$id], $this->readCallbacks[$id]);
            }
        }
        foreach ($this->writeStreams as $id => $stream) {
            if (!is_resource($stream)) {
                unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
            }
        }
    }
}
