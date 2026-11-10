<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

/**
 * A held cache lock (flock handle or mkdir marker). release() is idempotent. flock locks are
 * released by the kernel when the process dies; mkdir markers expire after a timeout (see
 * CacheStore::acquire()).
 */
final class CacheLock
{
    /** @var array<string,true> lock paths held by this process */
    private static array $held = [];

    private bool $released = false;

    /**
     * @param resource|null $handle flock handle, null in mkdir mode
     */
    public function __construct(
        private readonly string $path,
        private mixed $handle,
        private readonly bool $mkdirMode,
    ) {
        self::$held[$path] = true;
    }

    /** True when this process currently holds the lock at $path (flock cannot be re-entered on a second handle). */
    public static function isHeld(string $path): bool
    {
        return isset(self::$held[$path]);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        unset(self::$held[$this->path]);
        if ($this->mkdirMode) {
            @rmdir($this->path);
            return;
        }
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
