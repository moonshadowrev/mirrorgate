<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

/**
 * Header set attached to MirrorGate-generated (non proxied) responses. Proxied origin responses
 * are never touched by this class.
 */
final class SecurityHeaders
{
    /** Strict policy for generated pages: inline CSS only, no scripts, no external loads. */
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    /**
     * With $enabled = false (SECURITY_HEADERS=false) only the two always-safe headers remain, so a
     * generated response is still never sniffed or cached.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function forGenerated(bool $enabled): array
    {
        $headers = [
            ['X-Content-Type-Options', 'nosniff'],
            ['Cache-Control', 'no-store'],
        ];
        if (!$enabled) {
            return $headers;
        }
        $headers[] = ['Referrer-Policy', 'no-referrer'];
        $headers[] = ['X-Frame-Options', 'DENY'];
        $headers[] = ['Content-Security-Policy', self::CSP];
        return $headers;
    }
}
