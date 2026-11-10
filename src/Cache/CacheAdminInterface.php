<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

/**
 * Administrative operations on the cache, consumed by the admin controller and the CLI.
 */
interface CacheAdminInterface
{
    /** Purge every variant of one URL. Returns the number of affected entries. */
    public function purgeUrl(string $pathAndQuery, bool $soft): int;

    /** Purge every entry below a path prefix (segment aligned). Returns the number of affected entries. */
    public function purgePrefix(string $pathPrefix, bool $soft): int;

    /** Purge the whole cache of this instance. Returns the number of entries that existed. */
    public function purgeAll(bool $soft): int;

    public function stats(): CacheStats;
}
