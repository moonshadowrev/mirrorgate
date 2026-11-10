<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Config\AdminConfig;
use MirrorGate\Config\AppConfig;
use MirrorGate\Config\CacheConfig;
use MirrorGate\Config\Config;
use MirrorGate\Config\DaemonConfig;
use MirrorGate\Config\MirrorConfig;
use MirrorGate\Config\OriginConfig;
use MirrorGate\Config\RateLimitConfig;
use MirrorGate\Config\RewriteConfig;
use MirrorGate\Config\SecurityConfig;
use MirrorGate\Config\StreamConfig;
use MirrorGate\Config\UpstreamConfig;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\NormalizedTarget;
use MirrorGate\Http\Request;
use MirrorGate\Http\RequestContext;

/** Builders for configuration and request objects used by the module E tests. */
final class Fixtures
{
    /**
     * @param array<string,mixed> $overrides keys: storageDir, trustedProxies, trustedProxyHeader, adminEnabled,
     *        adminToken, adminIpAllow, forwardCookies, forwardAuthorization, forwardClientIp, varyExtra,
     *        maxUrlLength, maxHeaderCount, maxHeaderBytes, maxBody, allowedMethods, errorPageLang, errorPageDir,
     *        addVia, securityHeaders, failLimit, globalFailLimit
     */
    public static function config(array $overrides = []): Config
    {
        $o = $overrides;
        $storage = (string) ($o['storageDir'] ?? sys_get_temp_dir() . '/mg-test-storage');
        return new Config(
            projectRoot: '/srv/mirrorgate-root',
            envFile: '/srv/mirrorgate-root/.env',
            app: new AppConfig(
                env: 'production',
                debug: false,
                instanceId: 'inst0123abcd',
                timezone: 'UTC',
                storageDir: $storage,
                logLevel: 'info',
                logFile: $storage . '/logs/app.log',
                logMaxBytes: 1048576,
                errorPageLang: (string) ($o['errorPageLang'] ?? 'en'),
                errorPageDir: $o['errorPageDir'] ?? null,
                errorPageUpstream: 'gateway',
                exposeCacheHeaders: true,
                addVia: (bool) ($o['addVia'] ?? true),
            ),
            origin: new OriginConfig(
                url: 'https://overlord.team',
                scheme: 'https',
                host: 'overlord.team',
                port: 443,
                hostHeader: 'overlord.team',
                allowHttp: false,
                allowPrivate: false,
                allowedPorts: [443],
                resolveIp: null,
                outboundProxy: null,
                caBundle: null,
                hostMap: [],
                extraOrigins: [],
                subdomainMode: 'off',
                subdomainPrefix: '/__mg/s',
            ),
            mirror: new MirrorConfig(
                url: 'https://overload.ir',
                scheme: 'https',
                host: 'overload.ir',
                port: 443,
                hostHeader: 'overload.ir',
                isHttps: true,
                aliases: ['www.overload.ir'],
                allowedHosts: ['overload.ir', 'www.overload.ir'],
                wsPublicUrl: null,
            ),
            upstream: self::upstream($o),
            rewrite: new RewriteConfig(
                enabled: true, html: true, css: true, json: true, xml: true, js: false, rsc: false,
                visibleText: false, requestBody: false, stripIntegrity: true, maxBytes: 5242880,
                sitemapMaxBytes: 5242880, seoMode: 'mirror', cspMode: 'rewrite', serviceWorkerMode: 'passthrough',
                hstsMode: 'strip', hstsValue: null, stripAltSvc: true, cookieDomainMode: 'drop',
                cookieForceSecure: true, redirectExternal: 'pass', recompress: true,
            ),
            cache: new CacheConfig(
                enabled: true, dir: $storage . '/cache', maxBytes: 104857600, maxObjectBytes: 2097152,
                defaultTtl: 300, minTtl: 0, maxTtl: 86400, negativeTtl: 60, swrSeconds: 30,
                staleIfErrorSeconds: 3600, staticExtensions: ['css', 'js'], rules: [], cookieMode: 'bypass',
                honorClientNoCache: false, ignoreMustRevalidateOnError: false, coalesceWaitMs: 1500,
                gcProbability: 1, gcIntervalSeconds: 300, lockMode: 'flock', ignoreQueryParams: [],
                varyExtra: $o['varyExtra'] ?? [], statuses: [200],
            ),
            security: new SecurityConfig(
                trustedProxies: $o['trustedProxies'] ?? [],
                trustedProxyHeader: (string) ($o['trustedProxyHeader'] ?? 'x-forwarded-for'),
                blockedPaths: [],
                securityHeaders: (bool) ($o['securityHeaders'] ?? true),
                loopHeader: 'X-MirrorGate-Hop',
            ),
            rateLimit: new RateLimitConfig(
                enabled: true, requests: 120, window: 60, driver: 'file', maxConcurrent: 4,
                originFetchesPerMinute: 600, exemptIps: [], salt: 'unit-test-salt',
            ),
            admin: new AdminConfig(
                enabled: (bool) ($o['adminEnabled'] ?? true),
                token: (string) ($o['adminToken'] ?? 'Zq7vK2mP9xW4nR8tL5bY3cH6jD1fG0sA'),
                pathPrefix: '/__mirrorgate',
                ipAllow: $o['adminIpAllow'] ?? [],
                failLimit: (int) ($o['failLimit'] ?? 3),
                failWindow: 900,
                globalFailLimit: (int) ($o['globalFailLimit'] ?? 50),
            ),
            stream: new StreamConfig(sseEnabled: true, sseMaxDuration: 60, sseHeartbeat: 20, maxConcurrentStreams: 3),
            daemon: new DaemonConfig(
                listeners: [], allowPublicBind: false, maxConnections: 100, maxConnPerIp: 10, idleTimeout: 60,
                handshakeTimeout: 10, maxHeaderBytes: 8192, bufferHighWater: 65536, udpSessionTtl: 60,
                udpMaxSessions: 100, udpMaxDatagram: 1400, udpRatePerSec: 100, wsMaxLifetime: 3600,
                wsOriginAllow: [], targetAllowlist: [], allowPrivateTargets: false, targetDnsTtl: 60,
                pidFile: '/tmp/x.pid', logFile: '/tmp/x.log', stopFile: '/tmp/x.stop', memoryLimit: '128M',
            ),
        );
    }

    /** @param array<string,mixed> $o */
    public static function upstream(array $o = []): UpstreamConfig
    {
        return new UpstreamConfig(
            connectTimeout: 5, timeout: 30, lowSpeedTime: 20, lowSpeedLimit: 1, retries: 1, retryBaseMs: 100,
            retryCapMs: 1000, httpVersion: '1.1',
            maxRequestBodyBytes: (int) ($o['maxBody'] ?? 1048576),
            maxUrlLength: (int) ($o['maxUrlLength'] ?? 2048),
            maxHeaderCount: (int) ($o['maxHeaderCount'] ?? 50),
            maxHeaderBytes: (int) ($o['maxHeaderBytes'] ?? 4096),
            maxStreamBytes: 0, breakerEnabled: true, breakerThreshold: 5, breakerWindow: 60, breakerCooldown: 30,
            breakerMaxCooldown: 300,
            forwardCookies: (bool) ($o['forwardCookies'] ?? true),
            forwardAuthorization: (bool) ($o['forwardAuthorization'] ?? true),
            forwardClientIp: (bool) ($o['forwardClientIp'] ?? true),
            allowedMethods: $o['allowedMethods'] ?? ['GET', 'HEAD', 'POST', 'OPTIONS'],
            requestHeaderStrip: $o['requestHeaderStrip'] ?? [],
        );
    }

    /**
     * @param list<array{0:string,1:string}> $headers
     */
    public static function request(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        string $remote = '203.0.113.9',
        ?string $host = 'overload.ir',
        string $query = '',
        mixed $body = null,
        bool $tls = true,
    ): Request {
        $raw = $path . ($query !== '' ? '?' . $query : '');
        return new Request(
            method: $method,
            target: new NormalizedTarget($path, $query),
            headers: HeaderBag::fromPairs($headers),
            remoteAddr: $remote,
            hostHeader: $host,
            viaTls: $tls,
            contentLength: null,
            chunkedBody: false,
            rawTarget: $raw,
            spooledBody: $body,
        );
    }

    public static function context(Request $request, string $clientIp = '203.0.113.9', bool $isHead = false): RequestContext
    {
        return new RequestContext($request, 'aaaaaaaaaaaaaaaa', $clientIp, 0.0, $isHead);
    }

    public static function tempDir(string $label): string
    {
        $dir = sys_get_temp_dir() . '/mg-' . $label . '-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        return $dir;
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
