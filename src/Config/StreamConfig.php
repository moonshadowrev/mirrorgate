<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class StreamConfig
{
    public function __construct(
        public bool $sseEnabled,
        public int $sseMaxDuration,
        public int $sseHeartbeat,
        public int $maxConcurrentStreams,
    ) {
    }
}
