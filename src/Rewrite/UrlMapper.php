<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Rewrites one URL-valued string (an attribute value, a CSS url() token, a Link target).
 *
 * Absolute and protocol-relative URLs of handled origin hosts move to the mirror. When the response
 * comes from a prefix-mode origin host, root-relative URLs (`/x`) additionally receive the
 * `/<prefix>/<host>` path so that they still resolve to the right origin. Everything else,
 * including relative references and `data:` URIs, is returned unchanged.
 */
final readonly class UrlMapper
{
    public function __construct(
        private HostReplacer $replacer,
        private string $rootPrefix = '',
    ) {
    }

    public function map(string $url): string
    {
        if ($url === '') {
            return $url;
        }
        $trimmed = ltrim($url, " \t\n\r\f");
        if ($trimmed === '' || self::isDataUri($trimmed)) {
            return $url;
        }
        if ($this->rootPrefix !== '' && $trimmed[0] === '/' && ($trimmed[1] ?? '') !== '/') {
            $lead = substr($url, 0, strlen($url) - strlen($trimmed));
            return $lead . $this->rootPrefix . $trimmed;
        }
        return $this->replacer->replaceUrlsOnly($url);
    }

    public function replacer(): HostReplacer
    {
        return $this->replacer;
    }

    private static function isDataUri(string $trimmed): bool
    {
        return strncasecmp($trimmed, 'data:', 5) === 0;
    }
}
