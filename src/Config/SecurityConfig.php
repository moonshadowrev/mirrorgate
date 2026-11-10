<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class SecurityConfig
{
    public function __construct(
        /** @var list<string> CIDR strings, validated */ public array $trustedProxies,   // TRUSTED_PROXIES
        public string $trustedProxyHeader,    // TRUSTED_PROXY_HEADER: x-forwarded-for|cf-connecting-ip|x-real-ip
        /** @var list<string> */ public array $blockedPaths,       // BLOCKED_PATHS (glob patterns using * and ?)
        public bool $securityHeaders,         // SECURITY_HEADERS (applies to MirrorGate-generated responses only)
        public string $loopHeader,            // fixed "X-MirrorGate-Hop"
    ) {
    }
}
