<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

/**
 * Result of OriginGuard::resolve(). With an outbound proxy configured, $ip and
 * $curlResolveEntry are empty strings (the proxy is the SSRF boundary).
 */
final readonly class ResolvedTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $ip,
        public string $curlResolveEntry,
    ) {
    }
}
