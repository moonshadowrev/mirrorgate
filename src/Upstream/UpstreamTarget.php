<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

final readonly class UpstreamTarget
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $url,
        public string $hostHeader,
        public string $pathAndQuery,
    ) {
    }
}
