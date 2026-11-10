<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class MirrorConfig
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $hostHeader,          // host or host:port, used to build absolute mirror URLs
        public bool $isHttps,
        /** @var list<string> */ public array $aliases,        // MIRROR_ALIASES
        /** @var list<string> */ public array $allowedHosts,   // host(MIRROR_URL) + aliases, normalized, no ports
        public ?string $wsPublicUrl,        // WS_PUBLIC_URL
    ) {
    }
}
