<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

final readonly class RequestContext
{
    public function __construct(
        public Request $request,
        public string $requestId,      // 16 lowercase hex chars from random_bytes(8)
        public string $clientIp,       // resolved by ClientIpResolver
        public float $startedAt,       // microtime(true)
        public bool $isHead,
    ) {
    }
}
