<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class Config
{
    public function __construct(
        public string $projectRoot,
        public string $envFile,
        public AppConfig $app,
        public OriginConfig $origin,
        public MirrorConfig $mirror,
        public UpstreamConfig $upstream,
        public RewriteConfig $rewrite,
        public CacheConfig $cache,
        public SecurityConfig $security,
        public RateLimitConfig $rateLimit,
        public AdminConfig $admin,
        public StreamConfig $stream,
        public DaemonConfig $daemon,
    ) {
    }
}
