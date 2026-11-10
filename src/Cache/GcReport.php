<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

/**
 * Summary of one garbage collection run.
 */
final readonly class GcReport
{
    public function __construct(
        public int $scanned,
        public int $deleted,
        public int $freedBytes,
        public float $seconds,
    ) {
    }
}
