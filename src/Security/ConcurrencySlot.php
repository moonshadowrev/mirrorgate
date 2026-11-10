<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

/**
 * Handle for one acquired concurrency lease. The lease is released explicitly, by the destructor,
 * or by a shutdown function, whichever comes first. Releasing is idempotent.
 */
final class ConcurrencySlot
{
    /** @var list<\WeakReference<ConcurrencySlot>> */
    private static array $live = [];
    private static bool $shutdownRegistered = false;

    private ?\Closure $releaser;

    /**
     * @param \Closure():void|null $releaser null creates a no-op slot (limit disabled)
     */
    public function __construct(?\Closure $releaser = null)
    {
        $this->releaser = $releaser;
        if ($releaser !== null) {
            self::$live[] = \WeakReference::create($this);
            if (!self::$shutdownRegistered) {
                self::$shutdownRegistered = true;
                register_shutdown_function([self::class, 'releaseAll']);
            }
        }
    }

    public function release(): void
    {
        $releaser = $this->releaser;
        $this->releaser = null;
        if ($releaser === null) {
            return;
        }
        try {
            $releaser();
        } catch (\Throwable) {
            // Lease expiry reclaims the slot if the shard file could not be updated.
        }
    }

    /**
     * Shutdown hook: releases every slot that is still alive.
     *
     * @internal
     */
    public static function releaseAll(): void
    {
        foreach (self::$live as $ref) {
            $slot = $ref->get();
            if ($slot !== null) {
                $slot->release();
            }
        }
        self::$live = [];
    }

    public function __destruct()
    {
        $this->release();
    }
}
