<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

/**
 * Identifies one cached representation. Both hashes are lowercase sha256 hex strings, so they are
 * always safe to use as file names.
 */
final readonly class CacheKey
{
    public function __construct(
        public string $primaryHash,
        public string $variantHash,
        public string $pathAndQuery,
    ) {
    }
}
