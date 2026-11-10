<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class RewriteConfig
{
    public function __construct(
        public bool $enabled,               // REWRITE_ENABLED
        public bool $html,
        public bool $css,
        public bool $json,
        public bool $xml,
        public bool $js,                    // REWRITE_JS (bool: host literals only)
        public bool $rsc,                   // REWRITE_RSC
        public bool $visibleText,           // REWRITE_VISIBLE_TEXT
        public bool $requestBody,           // REWRITE_REQUEST_BODY
        public bool $stripIntegrity,        // REWRITE_STRIP_INTEGRITY
        public int $maxBytes,               // REWRITE_MAX_BYTES
        public int $sitemapMaxBytes,        // REWRITE_SITEMAP_MAX_BYTES
        public string $seoMode,             // SEO_MODE: mirror|canonical-origin|noindex
        public string $cspMode,             // CSP_MODE: rewrite|strip|passthrough
        public string $serviceWorkerMode,   // SERVICE_WORKER_MODE: passthrough|rewrite|block
        public string $hstsMode,            // HSTS_MODE: strip|own
        public ?string $hstsValue,          // HSTS_VALUE
        public bool $stripAltSvc,           // STRIP_ALT_SVC
        public string $cookieDomainMode,    // COOKIE_DOMAIN_MODE: drop|rewrite
        public bool $cookieForceSecure,     // COOKIE_FORCE_SECURE
        public string $redirectExternal,    // REDIRECT_EXTERNAL: pass|block
        public bool $recompress,            // RECOMPRESS
        /** @var list<string> lowercase names or prefixes ending in '*' */ public array $responseHeaderStrip = [],   // RESPONSE_HEADER_STRIP
    ) {
    }
}
