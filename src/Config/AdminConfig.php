<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class AdminConfig
{
    public function __construct(
        public bool $enabled,
        public string $token,
        public string $pathPrefix,
        /** @var list<string> */ public array $ipAllow,     // ADMIN_IP_ALLOW (CIDRs; empty = any)
        public int $failLimit,
        public int $failWindow,
        public int $requestsPerMinute = 60,   // ADMIN_REQUESTS_PER_MINUTE (per client /64)
        public int $globalFailLimit = 50,     // ADMIN_GLOBAL_FAIL_LIMIT per ADMIN_FAIL_WINDOW across all clients
        public bool $allowHttp = false,       // ADMIN_ALLOW_HTTP
    ) {
    }
}
