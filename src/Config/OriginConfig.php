<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class OriginConfig
{
    public function __construct(
        public string $url,                 // ORIGIN_URL normalized "scheme://host[:port]" no trailing slash
        public string $scheme,
        public string $host,
        public int $port,
        public string $hostHeader,          // host or host:port (port only when non-default)
        public bool $allowHttp,             // ORIGIN_ALLOW_HTTP
        public bool $allowPrivate,          // ALLOW_PRIVATE_ORIGIN
        /** @var list<int> */ public array $allowedPorts,    // ORIGIN_PORTS_ALLOW (always includes $port)
        public ?string $resolveIp,          // ORIGIN_RESOLVE_IP
        public ?string $outboundProxy,      // OUTBOUND_PROXY (http://, https://, socks5://, socks5h://)
        public ?string $caBundle,           // CA_BUNDLE
        /** @var array<string,string> origin host => mirror host */ public array $hostMap,   // HOST_MAP
        /** @var list<string> */ public array $extraOrigins,          // EXTRA_ORIGINS (hosts)
        public string $subdomainMode,       // SUBDOMAIN_MODE: off|prefix
        public string $subdomainPrefix,     // SUBDOMAIN_PREFIX, "/__mg/s" (no trailing slash)
        public bool $prefixModeAck = false, // PREFIX_MODE_ACK_SHARED_ORIGIN
        public int $dnsTtl = 60,            // DNS_TTL
        public int $dnsStaleTtl = 3600,     // DNS_STALE_TTL
    ) {
    }
}
