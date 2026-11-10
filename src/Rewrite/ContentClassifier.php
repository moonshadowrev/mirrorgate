<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

use MirrorGate\Config\RewriteConfig;

/**
 * Decides which rewriter, if any, applies to a response. Decisions use the response media type
 * (parameters ignored), the status and the normalized request path; the body is never inspected here.
 */
final class ContentClassifier
{
    private const MANIFEST_TYPES = [
        'application/manifest+json',
        'application/x-web-app-manifest+json',
    ];

    private const JS_TYPES = [
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'text/ecmascript',
        'application/ecmascript',
    ];

    /** Paths that are never rewritten, whatever their content type. */
    private const PROTECTED_PATHS = [
        '/.well-known/assetlinks.json',
        '/.well-known/apple-app-site-association',
        '/.well-known/openid-configuration',
        '/.well-known/oauth-authorization-server',
    ];

    /** Extensions of large or opaque payloads that are never buffered for rewriting. */
    private const OPAQUE_EXTENSIONS = [
        'mp4', 'm4v', 'webm', 'mkv', 'mov', 'avi', 'wmv', 'flv', 'mpg', 'mpeg', 'ts', 'm3u8',
        'mp3', 'ogg', 'oga', 'ogv', 'm4a', 'aac', 'wav', 'flac', 'opus', 'weba',
        'zip', 'gz', 'tgz', 'bz2', 'xz', 'zst', '7z', 'rar', 'tar', 'iso', 'dmg', 'exe', 'msi', 'apk', 'bin', 'deb', 'rpm', 'jar', 'war',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff', 'heic', 'svg', 'svgz', 'cur',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'pdf', 'wasm', 'map', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'psd', 'swf', 'pb', 'proto',
    ];

    public function __construct(private readonly RewriteConfig $config)
    {
    }

    public function classify(?string $contentType, string $path, int $status, bool $serviceWorkerRequest = false): RewriteKind
    {
        if (!$this->config->enabled) {
            return RewriteKind::None;
        }
        if ($status === 204 || $status === 206 || $status === 304 || $status < 200) {
            return RewriteKind::None;
        }
        if (self::isProtectedPath($path)) {
            return RewriteKind::None;
        }

        $type = self::mediaType($contentType);
        if ($type === '') {
            return RewriteKind::None;
        }

        if ($type === 'text/html' || $type === 'application/xhtml+xml') {
            return $this->config->html ? RewriteKind::Html : RewriteKind::None;
        }
        if ($type === 'text/css') {
            return $this->config->css ? RewriteKind::Css : RewriteKind::None;
        }
        if (in_array($type, self::MANIFEST_TYPES, true)) {
            return $this->config->json ? RewriteKind::Manifest : RewriteKind::None;
        }
        if ($type === 'application/json' || $type === 'application/ld+json' || str_ends_with($type, '+json')) {
            return $this->config->json ? RewriteKind::Json : RewriteKind::None;
        }
        if ($type === 'image/svg+xml') {
            return RewriteKind::None;
        }
        if ($type === 'text/xml' || $type === 'application/xml' || str_ends_with($type, '+xml')) {
            if (!$this->config->xml) {
                return RewriteKind::None;
            }
            return self::isSitemapPath($path) ? RewriteKind::Sitemap : RewriteKind::Xml;
        }
        if ($type === 'text/plain') {
            return $path === '/robots.txt' ? RewriteKind::Robots : RewriteKind::None;
        }
        if (in_array($type, self::JS_TYPES, true)) {
            $rewriteWorker = $serviceWorkerRequest && $this->config->serviceWorkerMode === 'rewrite';
            return ($this->config->js || $rewriteWorker) ? RewriteKind::Js : RewriteKind::None;
        }
        if ($type === 'text/x-component') {
            return $this->config->rsc ? RewriteKind::Rsc : RewriteKind::None;
        }
        if ($type === 'text/event-stream') {
            return RewriteKind::EventStream;
        }
        return RewriteKind::None;
    }

    public function likelyRewritable(string $path, ?string $acceptHeader, bool $serviceWorkerRequest = false): bool
    {
        if (!$this->config->enabled) {
            return false;
        }
        if (self::isProtectedPath($path)) {
            return false;
        }
        $lower = strtolower($path);
        if (str_starts_with($lower, '/_next/image')) {
            return false;
        }

        $extension = self::extension($lower);
        if ($extension !== '') {
            if (in_array($extension, self::OPAQUE_EXTENSIONS, true)) {
                return false;
            }
            switch ($extension) {
                case 'html':
                case 'htm':
                case 'xhtml':
                    return $this->config->html;
                case 'css':
                    return $this->config->css;
                case 'json':
                case 'webmanifest':
                case 'manifest':
                    return $this->config->json;
                case 'xml':
                case 'rss':
                case 'atom':
                    return $this->config->xml;
                case 'txt':
                    return $lower === '/robots.txt';
                case 'js':
                case 'mjs':
                case 'cjs':
                    return $this->config->js || ($serviceWorkerRequest && $this->config->serviceWorkerMode === 'rewrite');
                default:
                    break;
            }
        }

        if ($extension === '' && $acceptHeader !== null && self::acceptsOnlyMedia($acceptHeader)) {
            return false;
        }
        return true;
    }

    /** Lowercase media type without parameters, or an empty string. */
    public static function mediaType(?string $contentType): string
    {
        if ($contentType === null) {
            return '';
        }
        $semicolon = strpos($contentType, ';');
        $type = $semicolon === false ? $contentType : substr($contentType, 0, $semicolon);
        return strtolower(trim($type));
    }

    private static function isProtectedPath(string $path): bool
    {
        $lower = strtolower($path);
        if (in_array($lower, self::PROTECTED_PATHS, true)) {
            return true;
        }
        return str_starts_with($lower, '/_next/image') || str_ends_with($lower, '.map');
    }

    private static function isSitemapPath(string $path): bool
    {
        $slash = strrpos($path, '/');
        $name = strtolower($slash === false ? $path : substr($path, $slash + 1));
        return str_starts_with($name, 'sitemap') && str_ends_with($name, '.xml');
    }

    private static function extension(string $lowerPath): string
    {
        $slash = strrpos($lowerPath, '/');
        $name = $slash === false ? $lowerPath : substr($lowerPath, $slash + 1);
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === strlen($name) - 1) {
            return '';
        }
        return substr($name, $dot + 1);
    }

    /** True when every type in an Accept header is image, video, audio or font media. */
    private static function acceptsOnlyMedia(string $accept): bool
    {
        $seen = false;
        foreach (explode(',', $accept) as $item) {
            $type = self::mediaType($item);
            if ($type === '') {
                continue;
            }
            if (preg_match('~^(?:image|video|audio|font)/~', $type) !== 1) {
                return false;
            }
            $seen = true;
        }
        return $seen;
    }
}
