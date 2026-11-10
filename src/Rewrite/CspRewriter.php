<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

use MirrorGate\Config\MirrorConfig;
use MirrorGate\Config\OriginConfig;

/**
 * Rewrites Content-Security-Policy values so that host sources name the mirror instead of the origin.
 *
 * Keywords, nonces, scheme-only sources and third-party hosts are kept. Hash sources can be
 * dropped from script and style directives because rewritten inline content no longer matches them.
 * The `report-to` directive is removed (the Report-To headers are stripped as well) and `report-uri`
 * targets are mapped, kept when relative or third party, and dropped when they point at an
 * origin-internal host that the mirror does not serve.
 */
final class CspRewriter
{
    private const HASH_DIRECTIVES = [
        'script-src' => true, 'script-src-elem' => true, 'script-src-attr' => true,
        'style-src' => true, 'style-src-elem' => true, 'style-src-attr' => true,
    ];

    private readonly string $mirrorScheme;
    private readonly ?string $wsAuthority;
    private readonly ?string $wsScheme;

    public function __construct(
        private readonly HostMapper $mapper,
        private readonly OriginConfig $origin,
        MirrorConfig $mirror,
    ) {
        $this->mirrorScheme = $mirror->isHttps ? 'https' : 'http';
        $scheme = null;
        $authority = null;
        if ($mirror->wsPublicUrl !== null && preg_match('~^(wss?)://([A-Za-z0-9.\-]+(?::\d{1,5})?)~', $mirror->wsPublicUrl, $m) === 1) {
            $scheme = $m[1];
            $authority = $m[2];
        }
        $this->wsScheme = $scheme;
        $this->wsAuthority = $authority;
    }

    public function rewrite(string $policy, bool $dropHashes): string
    {
        $policies = [];
        foreach (explode(',', $policy) as $single) {
            $rewritten = $this->rewriteSingle($single, $dropHashes);
            if ($rewritten !== '') {
                $policies[] = $rewritten;
            }
        }
        return implode(', ', $policies);
    }

    private function rewriteSingle(string $policy, bool $dropHashes): string
    {
        $directives = [];
        foreach (explode(';', $policy) as $directive) {
            $tokens = preg_split('~[ \t]+~', trim($directive), -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens === false || $tokens === []) {
                continue;
            }
            $name = strtolower($tokens[0]);
            array_shift($tokens);

            if ($name === 'report-to') {
                continue;
            }
            if ($name === 'report-uri') {
                $kept = [];
                foreach ($tokens as $token) {
                    $mapped = $this->mapReportUri($token);
                    if ($mapped !== null) {
                        $kept[] = $mapped;
                    }
                }
                if ($kept !== []) {
                    $directives[] = $name . ' ' . implode(' ', $kept);
                }
                continue;
            }

            $sources = [];
            foreach ($tokens as $token) {
                if ($token !== '' && $token[0] === "'") {
                    if ($dropHashes && isset(self::HASH_DIRECTIVES[$name])
                        && preg_match("~^'sha(?:256|384|512)-~i", $token) === 1) {
                        continue;
                    }
                    $sources[] = $token;
                    continue;
                }
                $sources[] = $this->mapSource($token);
            }
            $directives[] = $sources === [] ? $name : $name . ' ' . implode(' ', $sources);
        }
        return implode('; ', $directives);
    }

    private function mapSource(string $token): string
    {
        $pattern = '~^(?:([A-Za-z][A-Za-z0-9+.\-]*)://)?(\*\.)?([A-Za-z0-9.\-]+)(?::(\d{1,5}|\*))?(/\S*)?$~';
        if (preg_match($pattern, $token, $m) !== 1) {
            return $token;
        }
        $scheme = strtolower($m[1]);
        $wildcard = $m[2] !== '';
        $host = HostMapper::normalizeHost($m[3]);
        $port = $m[4] ?? '';
        $path = $m[5] ?? '';

        if ($host === '' || !$this->mapper->isOriginHost($host)) {
            return $token;
        }
        if ($port !== '' && $port !== '*' && !$this->mapper->originPortAllowed($host, (int) $port)) {
            return $token;
        }

        if ($scheme === 'ws' || $scheme === 'wss') {
            if ($this->wsScheme === null || $this->wsAuthority === null || $wildcard) {
                return $token;
            }
            return $this->wsScheme . '://' . $this->wsAuthority . $path;
        }
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            return $token;
        }

        $prefix = $scheme === '' ? '' : $this->mirrorScheme . '://';

        if ($wildcard) {
            $name = $this->mapper->mirrorHostNameFor($host);
            if ($name === null) {
                return $token;
            }
            return $prefix . '*.' . $name . ($port === '*' ? ':*' : '') . $path;
        }

        $base = $this->mapper->mirrorBaseFor($host);
        if ($base === null) {
            return $token;
        }
        $rest = substr($base, (int) strpos($base, '://') + 3);
        if ($port === '*') {
            $slash = strpos($rest, '/');
            $authority = $slash === false ? $rest : substr($rest, 0, $slash);
            $rest = (preg_replace('~:\d+$~', '', $authority) ?? $authority) . ':*' . ($slash === false ? '' : substr($rest, $slash));
        }
        return $prefix . $rest . $path;
    }

    private function mapReportUri(string $token): ?string
    {
        if (preg_match('~^https?://~i', $token) !== 1) {
            return $token;
        }
        $mapped = $this->mapper->mapAbsoluteUrl($token);
        if ($mapped !== null) {
            return $mapped;
        }
        if (preg_match('~^https?://([^/?#:@\s]+)~i', $token, $m) === 1) {
            $host = HostMapper::normalizeHost($m[1]);
            $originHost = HostMapper::normalizeHost($this->origin->host);
            if ($host === $originHost || str_ends_with($host, '.' . $originHost)) {
                return null;
            }
        }
        return $token;
    }
}
