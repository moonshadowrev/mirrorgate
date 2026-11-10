<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Decides what happens to `Access-Control-Allow-Origin`.
 *
 * Only values that name a handled origin (translated to the matching mirror origin) or the exact
 * request `Origin` of a mirror host survive. An arbitrary request origin is never reflected and an
 * unknown origin is dropped, which makes the browser refuse the cross-origin read.
 */
final class CorsRewriter
{
    public function __construct(private readonly HostMapper $mapper)
    {
    }

    /** @return string|null the value to send, or null to remove the header */
    public function rewriteAllowOrigin(string $value, ?string $requestOrigin): ?string
    {
        $value = trim($value);
        if ($value === '*') {
            return '*';
        }
        if ($value === '' || preg_match('~[\x00-\x1F\x7F\\\\]~', $value) === 1) {
            return null;
        }
        if (preg_match('~^https?://[^/?#\s@]+$~i', $value) !== 1) {
            return null;
        }

        $mapped = $this->mapper->mapAbsoluteUrl($value);
        if ($mapped !== null) {
            return preg_replace('~^(https?://[^/]+).*$~s', '$1', $mapped) ?? null;
        }

        if ($requestOrigin !== null && $value === $requestOrigin
            && preg_match('~^https?://([^/?#\s:@]+)(?::\d{1,5})?$~i', $value, $m) === 1
            && $this->mapper->isMirrorHost($m[1])) {
            return $value;
        }
        return null;
    }
}
