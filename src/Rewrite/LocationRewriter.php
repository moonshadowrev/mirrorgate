<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

use MirrorGate\Config\RewriteConfig;

/**
 * Rewrites redirect-like header values (`Location`, `Content-Location`, the URL part of `Refresh`).
 *
 * A null return means the value must not reach the client: it is malformed, uses a dangerous scheme,
 * contains control characters or backslashes, or points to an external host while
 * REDIRECT_EXTERNAL=block. The caller converts null into a generic 502.
 */
final class LocationRewriter
{
    private const BLOCKED_SCHEMES = ['javascript', 'data', 'vbscript', 'file', 'blob', 'about', 'filesystem'];

    public function __construct(
        private readonly HostMapper $mapper,
        private readonly RewriteConfig $config,
    ) {
    }

    public function rewrite(string $location, RewriteContext $ctx): ?string
    {
        if (preg_match('~[\x00-\x1F\x7F]~', $location) === 1 || str_contains($location, '\\')) {
            return null;
        }
        $value = trim($location, ' ');
        if ($value === '') {
            return '';
        }
        $value = str_replace(' ', '%20', $value);

        if (preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*):~', $value, $m) === 1) {
            $scheme = strtolower($m[1]);
            if (in_array($scheme, self::BLOCKED_SCHEMES, true)) {
                return null;
            }
            if ($scheme === 'http' || $scheme === 'https') {
                return $this->absolute($scheme, substr($value, strlen($scheme) + 1));
            }
            return $this->external($value);
        }

        if (str_starts_with($value, '//')) {
            return $this->absolute(null, ltrim($value, '/'));
        }

        if ($value[0] === '/') {
            $prefix = $this->mapper->prefixForOrigin($ctx->originHostOfResponse);
            return $prefix . $value;
        }

        // Relative reference (path, query or fragment only): resolves against the mirror.
        return $value;
    }

    /**
     * Rewrites a `Refresh` header value such as `5; url=https://origin/x`.
     * Returns '' when the value has no usable shape (the header is then dropped) and null when unsafe.
     */
    public function rewriteRefresh(string $refresh, RewriteContext $ctx): ?string
    {
        if (preg_match('~[\x00-\x1F\x7F]~', $refresh) === 1) {
            return null;
        }
        if (preg_match('~^\s*(\d+(?:\.\d+)?)\s*(?:[;,]\s*(?:url\s*=\s*)?(.*?))?\s*$~is', $refresh, $m) !== 1) {
            return '';
        }
        $seconds = $m[1];
        $target = trim($m[2] ?? '');
        if (strlen($target) >= 2 && ($target[0] === '"' || $target[0] === "'") && $target[strlen($target) - 1] === $target[0]) {
            $target = substr($target, 1, -1);
        }
        if ($target === '') {
            return $seconds;
        }
        $rewritten = $this->rewrite($target, $ctx);
        if ($rewritten === null) {
            return null;
        }
        return $seconds . '; url=' . $rewritten;
    }

    /** `$rest` is whatever follows `scheme:` (or the text after the leading slashes). */
    private function absolute(?string $scheme, string $rest): ?string
    {
        $afterSlashes = ltrim($rest, '/');
        if ($scheme !== null && $afterSlashes === $rest) {
            // "https:evil.com" has no authority marker; refuse rather than guess what a browser does.
            return null;
        }

        $end = strcspn($afterSlashes, '/?#');
        $authority = substr($afterSlashes, 0, $end);
        $remainder = substr($afterSlashes, $end);

        if ($authority === '' || str_contains($authority, '@')) {
            return null;
        }
        if ($authority[0] === '[') {
            // IPv6 literal hosts are never handled origin hosts.
            return $this->external(($scheme ?? '') === '' ? '//' . $afterSlashes : $scheme . '://' . $afterSlashes);
        }
        if (preg_match('~^([^:]+)(?::(\d*))?$~', $authority, $m) !== 1) {
            return null;
        }
        $host = HostMapper::normalizeHost($m[1]);
        $portText = $m[2] ?? '';
        $port = $portText !== '' ? (int) $portText : null;
        if ($portText !== '' && (strlen($portText) > 5 || $port === null || $port > 65535)) {
            return null;
        }

        $effectiveScheme = $scheme ?? 'https';
        $canonicalAuthority = $host . ($port !== null ? ':' . $port : '');
        $canonical = $effectiveScheme . '://' . $canonicalAuthority . $remainder;

        if ($this->mapper->isOriginHost($host)) {
            $mapped = $this->mapper->mapAbsoluteUrl($canonical);
            if ($mapped !== null) {
                return $scheme === null ? $this->stripScheme($mapped) : $mapped;
            }
        } elseif ($this->mapper->isMirrorHost($host)) {
            return $scheme === null ? '//' . $canonicalAuthority . $remainder : $canonical;
        }

        return $this->external($scheme === null ? '//' . $canonicalAuthority . $remainder : $canonical);
    }

    private function external(string $value): ?string
    {
        return $this->config->redirectExternal === 'block' ? null : $value;
    }

    private function stripScheme(string $url): string
    {
        $pos = strpos($url, '://');
        return $pos === false ? $url : substr($url, $pos + 1);
    }
}
