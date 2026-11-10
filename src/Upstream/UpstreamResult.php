<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

final readonly class UpstreamResult
{
    public function __construct(
        public ?UpstreamHead $head,
        public ?string $body,
        public int $bytesReceived,
        public bool $completed,
        public ?UpstreamError $error,
        public int $attempts,
        public float $totalTime,
    ) {
    }
}
