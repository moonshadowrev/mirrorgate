<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

/**
 * Feature detection for the linked libcurl and the PHP curl extension.
 *
 * Every constant is looked up through defined()/constant() so that a build
 * lacking an option never causes a fatal "undefined constant" error.
 */
final class CurlCapabilities
{
    /** @var array<string,mixed>|null */
    private static ?array $versionInfo = null;

    /**
     * Options restricting both the initial request and (defensively) any
     * redirect to http and https. Prefers the string form (libcurl 7.85+),
     * falls back to the legacy bitmask.
     *
     * @return array<int,mixed>
     */
    public static function protocolsOption(): array
    {
        if (\defined('CURLOPT_PROTOCOLS_STR') && \defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            return [
                (int) \constant('CURLOPT_PROTOCOLS_STR') => 'http,https',
                (int) \constant('CURLOPT_REDIR_PROTOCOLS_STR') => 'http,https',
            ];
        }

        $mask = (int) \constant('CURLPROTO_HTTP') | (int) \constant('CURLPROTO_HTTPS');

        return [
            (int) \constant('CURLOPT_PROTOCOLS') => $mask,
            (int) \constant('CURLOPT_REDIR_PROTOCOLS') => $mask,
        ];
    }

    public static function supportsHttp2(): bool
    {
        if (!\defined('CURL_HTTP_VERSION_2TLS') || !\defined('CURL_VERSION_HTTP2')) {
            return false;
        }

        $features = self::features();

        return ($features & (int) \constant('CURL_VERSION_HTTP2')) !== 0;
    }

    public static function supportsBrotli(): bool
    {
        if (!\defined('CURL_VERSION_BROTLI')) {
            return false;
        }

        return (self::features() & (int) \constant('CURL_VERSION_BROTLI')) !== 0;
    }

    /**
     * Returns the CURLOPT_HTTP_VERSION value to use. HTTP/2 is requested only
     * for https (ALPN, never h2c upgrade) and only when libcurl supports it.
     */
    public static function httpVersionValue(string $configured, string $scheme): int
    {
        if ($configured === '2' && $scheme === 'https' && self::supportsHttp2()) {
            return (int) \constant('CURL_HTTP_VERSION_2TLS');
        }

        return \defined('CURL_HTTP_VERSION_1_1') ? (int) \constant('CURL_HTTP_VERSION_1_1') : 2;
    }

    private static function features(): int
    {
        $info = self::versionInfo();
        $features = $info['features'] ?? 0;

        return \is_int($features) ? $features : 0;
    }

    /**
     * @return array<string,mixed>
     */
    private static function versionInfo(): array
    {
        if (self::$versionInfo === null) {
            $info = \function_exists('curl_version') ? \curl_version() : false;
            self::$versionInfo = \is_array($info) ? $info : [];
        }

        return self::$versionInfo;
    }
}
