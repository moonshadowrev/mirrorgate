<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

use MirrorGate\Env\EnvReader;
use MirrorGate\Support\Hostname;

/**
 * Pure validator: turns a map of environment values into a Config object. It performs no file
 * reads, no DNS lookups, no writes and generates no random values. Every problem is collected and
 * reported through one ConfigException.
 */
final class ConfigFactory
{
    public const DAEMON_LISTEN_MAX = 32;

    private const KNOWN_KEYS = [
        // 3.1 Application
        'APP_ENV', 'APP_DEBUG', 'APP_INSTANCE_ID', 'APP_TIMEZONE', 'STORAGE_DIR', 'LOG_LEVEL', 'LOG_FILE',
        'LOG_MAX_BYTES', 'LOG_PATH_MODE', 'ALLOW_SECRETS_IN_DOCROOT', 'STORAGE_FILE_MODE', 'STORAGE_DIR_MODE',
        'ERROR_PAGE_LANG', 'ERROR_PAGE_DIR', 'ERROR_PAGE_UPSTREAM', 'EXPOSE_CACHE_HEADERS', 'ADD_VIA',
        // 3.2 Origin and mirror identity
        'ORIGIN_URL', 'MIRROR_URL', 'MIRROR_ALIASES', 'ORIGIN_ALLOW_HTTP', 'ALLOW_PRIVATE_ORIGIN',
        'ORIGIN_PORTS_ALLOW', 'ORIGIN_RESOLVE_IP', 'OUTBOUND_PROXY', 'CA_BUNDLE', 'HOST_MAP', 'EXTRA_ORIGINS',
        'SUBDOMAIN_MODE', 'SUBDOMAIN_PREFIX', 'PREFIX_MODE_ACK_SHARED_ORIGIN', 'DNS_TTL', 'DNS_STALE_TTL',
        'WS_PUBLIC_URL',
        // 3.3 Upstream, limits, resilience
        'ORIGIN_CONNECT_TIMEOUT', 'ORIGIN_TIMEOUT', 'ORIGIN_LOW_SPEED_TIME', 'ORIGIN_LOW_SPEED_LIMIT',
        'ORIGIN_RETRIES', 'ORIGIN_RETRY_BASE_MS', 'ORIGIN_RETRY_CAP_MS', 'ORIGIN_HTTP_VERSION',
        'MAX_REQUEST_BODY_BYTES', 'MAX_URL_LENGTH', 'MAX_HEADER_COUNT', 'MAX_HEADER_BYTES', 'MAX_STREAM_BYTES',
        'ALLOWED_METHODS', 'BREAKER_ENABLED', 'BREAKER_THRESHOLD', 'BREAKER_WINDOW', 'BREAKER_COOLDOWN',
        'BREAKER_MAX_COOLDOWN', 'FORWARD_COOKIES', 'FORWARD_AUTHORIZATION', 'FORWARD_CLIENT_IP',
        'REQUEST_HEADER_STRIP',
        // 3.4 Rewriting
        'REWRITE_ENABLED', 'REWRITE_HTML', 'REWRITE_CSS', 'REWRITE_JSON', 'REWRITE_XML', 'REWRITE_JS',
        'REWRITE_RSC', 'REWRITE_VISIBLE_TEXT', 'REWRITE_REQUEST_BODY', 'REWRITE_STRIP_INTEGRITY',
        'REWRITE_MAX_BYTES', 'REWRITE_SITEMAP_MAX_BYTES', 'SEO_MODE', 'CSP_MODE', 'SERVICE_WORKER_MODE',
        'HSTS_MODE', 'HSTS_VALUE', 'STRIP_ALT_SVC', 'COOKIE_DOMAIN_MODE', 'COOKIE_FORCE_SECURE',
        'REDIRECT_EXTERNAL', 'RECOMPRESS', 'RESPONSE_HEADER_STRIP',
        // 3.5 Cache
        'CACHE_ENABLED', 'CACHE_DIR', 'CACHE_MAX_BYTES', 'CACHE_MAX_OBJECT_BYTES', 'CACHE_MAX_ENTRIES',
        'CACHE_DEFAULT_TTL', 'CACHE_MIN_TTL', 'CACHE_MAX_TTL', 'CACHE_NEGATIVE_TTL', 'CACHE_SWR_SECONDS',
        'CACHE_STALE_IF_ERROR_SECONDS', 'CACHE_STATIC_EXTENSIONS', 'CACHE_RULES', 'CACHE_COOKIE_MODE',
        'CACHE_HONOR_CLIENT_NOCACHE', 'CACHE_IGNORE_MUST_REVALIDATE_ON_ERROR', 'CACHE_COALESCE_WAIT_MS',
        'CACHE_GC_PROBABILITY', 'CACHE_GC_INTERVAL', 'CACHE_LOCK_MODE', 'CACHE_IGNORE_QUERY_PARAMS',
        'CACHE_VARY_EXTRA', 'CACHE_STATUSES', 'CACHE_BYPASS_HEADERS', 'CACHE_MAX_QUERY_VARIANTS',
        // 3.6 Security, admin, rate limits, streaming
        'TRUSTED_PROXIES', 'TRUSTED_PROXY_HEADER', 'BLOCKED_PATHS', 'AUTH_PASSTHROUGH_BLOCK', 'SECURITY_HEADERS',
        'ADMIN_ENABLED', 'ADMIN_TOKEN', 'ADMIN_PATH_PREFIX', 'ADMIN_IP_ALLOW', 'ADMIN_FAIL_LIMIT',
        'ADMIN_FAIL_WINDOW', 'ADMIN_GLOBAL_FAIL_LIMIT', 'ADMIN_REQUESTS_PER_MINUTE', 'ADMIN_ALLOW_HTTP',
        'RATE_LIMIT_ENABLED', 'RATE_LIMIT_REQUESTS', 'RATE_LIMIT_WINDOW', 'RATE_LIMIT_DRIVER',
        'RATE_LIMIT_MAX_CONCURRENT', 'RATE_LIMIT_ORIGIN_FETCHES_PER_MINUTE', 'RATE_LIMIT_EXEMPT_IPS',
        'RATE_LIMIT_SALT', 'GLOBAL_MAX_CONCURRENT', 'CONCURRENCY_SHARED_DIR', 'SSE_ENABLED', 'SSE_MAX_DURATION',
        'SSE_HEARTBEAT', 'MAX_CONCURRENT_STREAMS',
        // 3.7 Daemon (DAEMON_LISTEN_n are appended by knownKeys())
        'DAEMON_ALLOW_PUBLIC_BIND', 'DAEMON_MAX_CONNECTIONS', 'DAEMON_MAX_CONN_PER_IP', 'DAEMON_IDLE_TIMEOUT',
        'DAEMON_HANDSHAKE_TIMEOUT', 'DAEMON_MAX_HEADER_BYTES', 'DAEMON_BUFFER_HIGH_WATER',
        'DAEMON_UDP_SESSION_TTL', 'DAEMON_UDP_MAX_SESSIONS', 'DAEMON_UDP_MAX_DATAGRAM', 'DAEMON_UDP_RATE_PER_SEC',
        'DAEMON_WS_MAX_LIFETIME', 'DAEMON_WS_ORIGIN_ALLOW', 'DAEMON_WS_ALLOW_NO_ORIGIN',
        'DAEMON_UDP_CLIENT_ALLOW', 'DAEMON_UDP_MAX_AMPLIFICATION', 'DAEMON_TCP_CLIENT_ALLOW', 'DAEMON_TARGET_ALLOWLIST',
        'DAEMON_ALLOW_PRIVATE_TARGETS', 'DAEMON_TARGET_DNS_TTL', 'DAEMON_PID_FILE', 'DAEMON_LOG_FILE',
        'DAEMON_STOP_FILE', 'DAEMON_MEMORY_LIMIT',
    ];

    private const DEFAULT_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const ALLOWED_CACHE_STATUSES = [200, 203, 204, 300, 301, 308, 404, 405, 410, 414, 501];
    private const DEFAULT_STATIC_EXTENSIONS = [
        'js', 'css', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf',
        'otf', 'eot', 'mp4', 'webm', 'mp3',
    ];

    /**
     * Every environment variable name the application reads (used for process-environment overrides
     * and for the "unknown key" warning of the self check).
     *
     * @return list<string>
     */
    public static function knownKeys(): array
    {
        $keys = self::KNOWN_KEYS;
        for ($i = 1; $i <= self::DAEMON_LISTEN_MAX; $i++) {
            $keys[] = 'DAEMON_LISTEN_' . $i;
        }
        return $keys;
    }

    /**
     * @param array<string,string> $values
     * @throws ConfigException
     */
    public static function create(array $values, string $projectRoot, string $envFile): Config
    {
        $r = new EnvReader($values);
        $envDir = dirname($envFile);
        $projectRoot = rtrim($projectRoot, '/\\');

        // ---- Origin and mirror identity (needed by several other sections) ----
        $origin = self::parseEndpoint($r, 'ORIGIN_URL');
        $mirror = self::parseEndpoint($r, 'MIRROR_URL');

        $allowHttp = $r->bool('ORIGIN_ALLOW_HTTP', false);
        if ($origin !== null && $origin['scheme'] === 'http' && !$allowHttp) {
            $r->error('ORIGIN_URL', 'https_required', 'must use https unless ORIGIN_ALLOW_HTTP is enabled');
        }
        if ($origin !== null && $mirror !== null) {
            if ($mirror['host'] === $origin['host'] || str_ends_with($mirror['host'], '.' . $origin['host'])) {
                $r->error('MIRROR_URL', 'loop', 'must not be the origin host or a subdomain of it');
            }
        }
        $originHost = $origin['host'] ?? 'origin.invalid';
        $mirrorHost = $mirror['host'] ?? 'mirror.invalid';

        $allowPrivate = $r->bool('ALLOW_PRIVATE_ORIGIN', false);
        $originPort = $origin['port'] ?? 443;

        $allowedPorts = [];
        $portItems = $r->list('ORIGIN_PORTS_ALLOW');
        if ($portItems === []) {
            $allowedPorts = [80, 443];
        } else {
            foreach ($portItems as $item) {
                if (preg_match('/\A\d{1,5}\z/', $item) !== 1 || (int) $item < 1 || (int) $item > 65535) {
                    $r->error('ORIGIN_PORTS_ALLOW', 'invalid_port', 'contains an invalid port number');
                    continue;
                }
                $allowedPorts[] = (int) $item;
            }
        }
        $allowedPorts[] = $originPort;
        $allowedPorts = array_values(array_unique($allowedPorts));

        $resolveIp = $r->opt('ORIGIN_RESOLVE_IP');
        if ($resolveIp !== null && filter_var($resolveIp, FILTER_VALIDATE_IP) === false) {
            $r->error('ORIGIN_RESOLVE_IP', 'invalid_ip', 'must be a valid IPv4 or IPv6 address');
            $resolveIp = null;
        }

        $outboundProxy = self::parseProxy($r, 'OUTBOUND_PROXY');

        $caBundle = $r->opt('CA_BUNDLE');
        if ($caBundle !== null) {
            if (preg_match('/[\x00-\x1F\x7F]/', $caBundle) === 1) {
                $r->error('CA_BUNDLE', 'invalid_path', 'contains control characters');
                $caBundle = null;
            } else {
                $caBundle = self::resolvePath($caBundle, $envDir);
            }
        }

        $prefixAck = $r->bool('PREFIX_MODE_ACK_SHARED_ORIGIN', false);
        $subdomainMode = $r->enum('SUBDOMAIN_MODE', 'off', ['off', 'prefix']);
        if ($subdomainMode === 'prefix' && !$prefixAck) {
            $r->error('SUBDOMAIN_MODE', 'ack_required', 'prefix mode requires PREFIX_MODE_ACK_SHARED_ORIGIN=true');
        }
        $subdomainPrefix = $r->str('SUBDOMAIN_PREFIX', '/__mg/s');
        if (
            preg_match('#\A/[A-Za-z0-9_./-]{1,64}\z#', $subdomainPrefix) !== 1
            || str_ends_with($subdomainPrefix, '/')
            || str_contains($subdomainPrefix, '..')
            || str_contains($subdomainPrefix, '//')
        ) {
            $r->error('SUBDOMAIN_PREFIX', 'invalid_path', 'must be a path such as /__mg/s without a trailing slash');
            $subdomainPrefix = '/__mg/s';
        }

        $extraOrigins = [];
        foreach ($r->list('EXTRA_ORIGINS') as $item) {
            $h = self::hostValue($item);
            if ($h === null || Hostname::isIpLiteral($h)) {
                $r->error('EXTRA_ORIGINS', 'invalid_host', 'contains an invalid host name');
                continue;
            }
            if (!Hostname::isSameOrSubdomain($h, $originHost)) {
                $r->error('EXTRA_ORIGINS', 'not_in_origin_domain', 'must list only hosts that equal the origin host or are subdomains of it');
                continue;
            }
            $extraOrigins[] = $h;
        }
        $extraOrigins = array_values(array_unique($extraOrigins));
        if (count($extraOrigins) > 32) {
            $r->error('EXTRA_ORIGINS', 'too_many', 'lists more than 32 hosts');
        }
        if ($extraOrigins !== [] && !$prefixAck) {
            $r->error('EXTRA_ORIGINS', 'ack_required', 'requires PREFIX_MODE_ACK_SHARED_ORIGIN=true');
        }

        $hostMap = self::parseHostMap($r, $originHost, $mirrorHost);

        $dnsTtl = $r->int('DNS_TTL', 60, 1, 3600);
        $dnsStaleTtl = $r->int('DNS_STALE_TTL', max(3600, $dnsTtl), 1, 86400);
        if ($dnsStaleTtl < $dnsTtl) {
            $r->error('DNS_STALE_TTL', 'below_dns_ttl', 'must not be smaller than DNS_TTL');
        }

        $aliases = [];
        foreach ($r->list('MIRROR_ALIASES') as $item) {
            $h = self::hostValue($item);
            if ($h === null || Hostname::isIpLiteral($h)) {
                $r->error('MIRROR_ALIASES', 'invalid_host', 'contains an invalid host name');
                continue;
            }
            $aliases[] = $h;
        }
        $aliases = array_values(array_unique($aliases));

        $wsPublicUrl = $r->opt('WS_PUBLIC_URL');
        if ($wsPublicUrl !== null) {
            $wsPublicUrl = self::parseWsUrl($r, $wsPublicUrl);
        }

        $originCfg = new OriginConfig(
            $origin['url'] ?? '',
            $origin['scheme'] ?? 'https',
            $originHost,
            $originPort,
            $origin['hostHeader'] ?? $originHost,
            $allowHttp,
            $allowPrivate,
            $allowedPorts,
            $resolveIp,
            $outboundProxy,
            $caBundle,
            $hostMap,
            $extraOrigins,
            $subdomainMode,
            $subdomainPrefix,
            $prefixAck,
            $dnsTtl,
            $dnsStaleTtl,
        );

        $mirrorIsHttps = ($mirror['scheme'] ?? 'https') === 'https';
        $allowedHosts = array_values(array_unique(array_merge([$mirrorHost], $aliases)));
        $mirrorCfg = new MirrorConfig(
            $mirror['url'] ?? '',
            $mirror['scheme'] ?? 'https',
            $mirrorHost,
            $mirror['port'] ?? 443,
            $mirror['hostHeader'] ?? $mirrorHost,
            $mirrorIsHttps,
            $aliases,
            $allowedHosts,
            $wsPublicUrl,
        );

        // ---- Application ----
        $storageDir = self::resolvePath($r->str('STORAGE_DIR', $envDir . '/var'), $envDir);
        if ($projectRoot !== '' && str_starts_with($storageDir . '/', $projectRoot . '/public/')) {
            $r->error('STORAGE_DIR', 'inside_public', 'must not be inside the public/ directory');
        }
        $debug = $r->bool('APP_DEBUG', false);
        $logLevel = $debug ? 'debug' : $r->enum('LOG_LEVEL', 'warning', ['debug', 'info', 'warning', 'error']);
        if ($debug) {
            // Validate the configured value anyway so that typos are still reported.
            $r->enum('LOG_LEVEL', 'warning', ['debug', 'info', 'warning', 'error']);
        }
        $instanceId = $r->str('APP_INSTANCE_ID', substr(hash('sha256', $originHost . '|' . $mirrorHost), 0, 12));
        if (preg_match('/\A[a-z0-9_-]{4,32}\z/', $instanceId) !== 1) {
            $r->error('APP_INSTANCE_ID', 'invalid_format', 'must match [a-z0-9_-] and be 4 to 32 characters long');
            $instanceId = substr(hash('sha256', $originHost . '|' . $mirrorHost), 0, 12);
        }
        $timezone = $r->str('APP_TIMEZONE', 'UTC');
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $r->error('APP_TIMEZONE', 'unknown_timezone', 'is not a valid time zone identifier');
            $timezone = 'UTC';
        }
        $logFile = self::resolvePath($r->str('LOG_FILE', $storageDir . '/logs/mirrorgate.log'), $envDir);
        $errorPageDir = $r->opt('ERROR_PAGE_DIR');
        if ($errorPageDir !== null) {
            $errorPageDir = self::resolvePath($errorPageDir, $envDir);
        }
        $logPathMode = $r->enum('LOG_PATH_MODE', 'segments', ['segments', 'hash', 'full']);
        $app = new AppConfig(
            $r->enum('APP_ENV', 'production', ['production', 'development']),
            $debug,
            $instanceId,
            $timezone,
            $storageDir,
            $logLevel,
            $logFile,
            $r->bytes('LOG_MAX_BYTES', 5 * 1024 * 1024, 64 * 1024, 1024 * 1024 * 1024),
            $r->enum('ERROR_PAGE_LANG', 'en', ['en', 'fa']),
            $errorPageDir,
            $r->enum('ERROR_PAGE_UPSTREAM', 'gateway', ['gateway', 'all', 'off']),
            $r->bool('EXPOSE_CACHE_HEADERS', true),
            $r->bool('ADD_VIA', true),
            $logPathMode,
            $r->bool('ALLOW_SECRETS_IN_DOCROOT', false),
            $r->mode('STORAGE_FILE_MODE', 0640),
            $r->mode('STORAGE_DIR_MODE', 0750),
        );

        // ---- Upstream ----
        $connectTimeout = $r->int('ORIGIN_CONNECT_TIMEOUT', 5, 1, 60);
        $timeout = $r->int('ORIGIN_TIMEOUT', 30, 1, 300);
        if ($timeout < $connectTimeout) {
            $r->error('ORIGIN_TIMEOUT', 'below_connect_timeout', 'must not be smaller than ORIGIN_CONNECT_TIMEOUT');
        }
        $lowSpeedTime = 20;
        if ($r->has('ORIGIN_LOW_SPEED_TIME')) {
            $lowSpeedTime = $r->int('ORIGIN_LOW_SPEED_TIME', 20, 0, 300);
            if ($lowSpeedTime !== 0 && $lowSpeedTime < 5) {
                $r->error('ORIGIN_LOW_SPEED_TIME', 'out_of_range', 'must be 0 (disabled) or between 5 and 300');
                $lowSpeedTime = 20;
            }
        }
        $retryBase = $r->int('ORIGIN_RETRY_BASE_MS', 100, 10, 5000);
        $retryCap = $r->int('ORIGIN_RETRY_CAP_MS', max(1000, $retryBase), 1, 10000);
        if ($retryCap < $retryBase) {
            $r->error('ORIGIN_RETRY_CAP_MS', 'below_base', 'must not be smaller than ORIGIN_RETRY_BASE_MS');
        }
        $breakerCooldown = $r->int('BREAKER_COOLDOWN', 30, 5, 3600);
        $breakerMax = $r->int('BREAKER_MAX_COOLDOWN', max(300, $breakerCooldown), 1, 86400);
        if ($breakerMax < $breakerCooldown) {
            $r->error('BREAKER_MAX_COOLDOWN', 'below_cooldown', 'must not be smaller than BREAKER_COOLDOWN');
        }
        $methods = [];
        foreach ($r->list('ALLOWED_METHODS') as $m) {
            $m = strtoupper($m);
            if (!in_array($m, self::DEFAULT_METHODS, true)) {
                $r->error('ALLOWED_METHODS', 'unsupported_method', 'contains a method that cannot be allowed (TRACE, CONNECT or unknown)');
                continue;
            }
            $methods[] = $m;
        }
        $methods = $methods === [] ? self::DEFAULT_METHODS : array_values(array_unique($methods));
        $requestHeaderStrip = [];
        foreach ($r->list('REQUEST_HEADER_STRIP') as $item) {
            $item = strtolower($item);
            if (preg_match('/\A[a-z0-9!#$%&\'+.^`|~-]+\*?\z/', $item) !== 1) {
                $r->error('REQUEST_HEADER_STRIP', 'invalid_name', 'contains an invalid header name or prefix');
                continue;
            }
            $requestHeaderStrip[] = $item;
        }

        $upstream = new UpstreamConfig(
            $connectTimeout,
            $timeout,
            $lowSpeedTime,
            $r->int('ORIGIN_LOW_SPEED_LIMIT', 256, 1, 100000000),
            $r->int('ORIGIN_RETRIES', 1, 0, 3),
            $retryBase,
            $retryCap,
            $r->enum('ORIGIN_HTTP_VERSION', '1.1', ['1.1', '2']),
            $r->bytes('MAX_REQUEST_BODY_BYTES', 10 * 1024 * 1024, 0, 2 * 1024 * 1024 * 1024),
            $r->int('MAX_URL_LENGTH', 8192, 256, 65536),
            $r->int('MAX_HEADER_COUNT', 100, 10, 500),
            $r->bytes('MAX_HEADER_BYTES', 8192, 1024, 65536),
            $r->bytes('MAX_STREAM_BYTES', 100 * 1024 * 1024, 0, PHP_INT_MAX),
            $r->bool('BREAKER_ENABLED', true),
            $r->int('BREAKER_THRESHOLD', 5, 1, 100),
            $r->int('BREAKER_WINDOW', 30, 5, 600),
            $breakerCooldown,
            $breakerMax,
            $r->bool('FORWARD_COOKIES', true),
            $r->bool('FORWARD_AUTHORIZATION', true),
            $r->bool('FORWARD_CLIENT_IP', true),
            $methods,
            $requestHeaderStrip,
        );

        // ---- Rewriting ----
        $rewriteMax = $r->bytes('REWRITE_MAX_BYTES', 8 * 1024 * 1024, 64 * 1024, 64 * 1024 * 1024);
        $hstsMode = $r->enum('HSTS_MODE', 'strip', ['strip', 'own']);
        $hstsValue = $r->str('HSTS_VALUE', 'max-age=31536000');
        if (preg_match('/\A[A-Za-z0-9=;,\- _"]{1,200}\z/', $hstsValue) !== 1) {
            $r->error('HSTS_VALUE', 'invalid_value', 'contains characters that are not allowed in a Strict-Transport-Security value');
            $hstsValue = 'max-age=31536000';
        }
        $strip = [];
        foreach ($r->list('RESPONSE_HEADER_STRIP') as $item) {
            $item = strtolower($item);
            if (preg_match('/\A[a-z0-9!#$%&\'+.^_`|~-]+\*?\z/', $item) !== 1) {
                $r->error('RESPONSE_HEADER_STRIP', 'invalid_name', 'contains an invalid header name or prefix');
                continue;
            }
            $strip[] = $item;
        }
        $rewrite = new RewriteConfig(
            $r->bool('REWRITE_ENABLED', true),
            $r->bool('REWRITE_HTML', true),
            $r->bool('REWRITE_CSS', true),
            $r->bool('REWRITE_JSON', true),
            $r->bool('REWRITE_XML', true),
            $r->bool('REWRITE_JS', false),
            $r->bool('REWRITE_RSC', false),
            $r->bool('REWRITE_VISIBLE_TEXT', false),
            $r->bool('REWRITE_REQUEST_BODY', false),
            $r->bool('REWRITE_STRIP_INTEGRITY', true),
            $rewriteMax,
            $r->bytes('REWRITE_SITEMAP_MAX_BYTES', 16 * 1024 * 1024, 64 * 1024, 256 * 1024 * 1024),
            $r->enum('SEO_MODE', 'mirror', ['mirror', 'canonical-origin', 'noindex']),
            $r->enum('CSP_MODE', 'rewrite', ['rewrite', 'strip', 'passthrough']),
            $r->enum('SERVICE_WORKER_MODE', 'passthrough', ['passthrough', 'rewrite', 'block']),
            $hstsMode,
            $hstsMode === 'own' ? $hstsValue : null,
            $r->bool('STRIP_ALT_SVC', true),
            $r->enum('COOKIE_DOMAIN_MODE', 'drop', ['drop', 'rewrite']),
            $r->bool('COOKIE_FORCE_SECURE', true),
            $r->enum('REDIRECT_EXTERNAL', 'pass', ['pass', 'block']),
            $r->bool('RECOMPRESS', true),
            $strip,
        );

        // ---- Cache ----
        $cacheMaxBytes = $r->bytes('CACHE_MAX_BYTES', 256 * 1024 * 1024, 1024 * 1024, PHP_INT_MAX);
        $cacheObjMax = $r->bytes('CACHE_MAX_OBJECT_BYTES', min(5 * 1024 * 1024, $cacheMaxBytes), 1, PHP_INT_MAX);
        if ($cacheObjMax > $cacheMaxBytes) {
            $r->error('CACHE_MAX_OBJECT_BYTES', 'above_cache_max', 'must not exceed CACHE_MAX_BYTES');
        }
        $minTtl = $r->int('CACHE_MIN_TTL', 0, 0, 31536000);
        $maxTtl = $r->int('CACHE_MAX_TTL', max(86400, $minTtl), 0, 31536000);
        if ($maxTtl < $minTtl) {
            $r->error('CACHE_MAX_TTL', 'below_min_ttl', 'must not be smaller than CACHE_MIN_TTL');
        }
        $staticExt = [];
        if ($r->has('CACHE_STATIC_EXTENSIONS')) {
            foreach ($r->list('CACHE_STATIC_EXTENSIONS') as $e) {
                $e = strtolower(ltrim($e, '.'));
                if (preg_match('/\A[a-z0-9]{1,16}\z/', $e) !== 1) {
                    $r->error('CACHE_STATIC_EXTENSIONS', 'invalid_extension', 'contains an invalid extension');
                    continue;
                }
                $staticExt[] = $e;
            }
        } else {
            $staticExt = self::DEFAULT_STATIC_EXTENSIONS;
        }
        $rules = [];
        foreach ($r->list('CACHE_RULES') as $item) {
            $eq = strrpos($item, '=');
            if ($eq === false || $eq === 0) {
                $r->error('CACHE_RULES', 'invalid_rule', 'contains an entry that is not glob=ttl');
                continue;
            }
            $pattern = substr($item, 0, $eq);
            $ttl = substr($item, $eq + 1);
            if (
                preg_match('/\A[\x21-\x7E]+\z/', $pattern) !== 1
                || ($pattern[0] !== '/' && $pattern[0] !== '*')
                || preg_match('/\A-?\d{1,9}\z/', $ttl) !== 1
                || (int) $ttl < -1
            ) {
                $r->error('CACHE_RULES', 'invalid_rule', 'contains an entry that is not glob=ttl');
                continue;
            }
            $rules[] = ['pattern' => $pattern, 'ttl' => (int) $ttl];
        }
        $statuses = [];
        if ($r->has('CACHE_STATUSES')) {
            foreach ($r->list('CACHE_STATUSES') as $s) {
                if (preg_match('/\A\d{3}\z/', $s) !== 1 || !in_array((int) $s, self::ALLOWED_CACHE_STATUSES, true)) {
                    $r->error('CACHE_STATUSES', 'unsupported_status', 'contains a status that cannot be cached');
                    continue;
                }
                $statuses[] = (int) $s;
            }
            $statuses = array_values(array_unique($statuses));
        } else {
            $statuses = [200, 301, 308, 404, 410];
        }
        $varyExtra = [];
        foreach ($r->list('CACHE_VARY_EXTRA') as $h) {
            $h = strtolower($h);
            if (preg_match('/\A[a-z0-9-]{1,64}\z/', $h) !== 1) {
                $r->error('CACHE_VARY_EXTRA', 'invalid_name', 'contains an invalid header name');
                continue;
            }
            $varyExtra[] = $h;
        }
        $bypassHeaders = ['x-api-key', 'x-auth-token', 'x-access-token', 'proxy-authorization', 'x-csrf-token', 'x-xsrf-token'];
        if ($r->has('CACHE_BYPASS_HEADERS')) {
            $bypassHeaders = [];
            foreach ($r->list('CACHE_BYPASS_HEADERS') as $h) {
                $h = strtolower($h);
                if (preg_match('/\A[a-z0-9-]{1,64}\z/', $h) !== 1) {
                    $r->error('CACHE_BYPASS_HEADERS', 'invalid_name', 'contains an invalid header name');
                    continue;
                }
                $bypassHeaders[] = $h;
            }
        }
        $ignoreQuery = [];
        foreach ($r->list('CACHE_IGNORE_QUERY_PARAMS') as $p) {
            if (preg_match('/\A[A-Za-z0-9_.\-\[\]]{1,64}\z/', $p) !== 1) {
                $r->error('CACHE_IGNORE_QUERY_PARAMS', 'invalid_name', 'contains an invalid parameter name');
                continue;
            }
            $ignoreQuery[] = $p;
        }
        $cacheDir = self::resolvePath($r->str('CACHE_DIR', $storageDir . '/cache'), $envDir);
        $cache = new CacheConfig(
            $r->bool('CACHE_ENABLED', true),
            $cacheDir,
            $cacheMaxBytes,
            $cacheObjMax,
            $r->int('CACHE_DEFAULT_TTL', 300, 0, 31536000),
            $minTtl,
            $maxTtl,
            $r->int('CACHE_NEGATIVE_TTL', 60, 0, 31536000),
            $r->int('CACHE_SWR_SECONDS', 60, 0, 31536000),
            $r->int('CACHE_STALE_IF_ERROR_SECONDS', 604800, 0, 31536000),
            $staticExt,
            $rules,
            $r->enum('CACHE_COOKIE_MODE', 'static-only', ['bypass', 'static-only']),
            $r->bool('CACHE_HONOR_CLIENT_NOCACHE', false),
            $r->bool('CACHE_IGNORE_MUST_REVALIDATE_ON_ERROR', true),
            $r->int('CACHE_COALESCE_WAIT_MS', 1500, 0, 30000),
            $r->int('CACHE_GC_PROBABILITY', 1, 0, 100),
            $r->int('CACHE_GC_INTERVAL', 300, 1, 86400),
            $r->enum('CACHE_LOCK_MODE', 'flock', ['flock', 'mkdir']),
            $ignoreQuery,
            $varyExtra,
            $statuses,
            $r->int('CACHE_MAX_ENTRIES', 20000, 100, 1000000),
            $logPathMode === 'full',
            $bypassHeaders,
            $r->int('CACHE_MAX_QUERY_VARIANTS', 20, 1, 1000),
        );

        // ---- Security ----
        $trusted = self::cidrList($r, 'TRUSTED_PROXIES');
        $blocked = [];
        foreach (['BLOCKED_PATHS', 'AUTH_PASSTHROUGH_BLOCK'] as $key) {
            foreach ($r->list($key) as $pattern) {
                if (preg_match('/\A[\x21-\x7E]+\z/', $pattern) !== 1 || ($pattern[0] !== '/' && $pattern[0] !== '*')) {
                    $r->error($key, 'invalid_pattern', 'contains a pattern that is not a path glob starting with /');
                    continue;
                }
                $blocked[] = $pattern;
            }
        }
        $security = new SecurityConfig(
            $trusted,
            $r->enum('TRUSTED_PROXY_HEADER', 'x-forwarded-for', ['x-forwarded-for', 'cf-connecting-ip', 'x-real-ip']),
            array_values(array_unique($blocked)),
            $r->bool('SECURITY_HEADERS', true),
            'X-MirrorGate-Hop',
        );

        $salt = $r->str('RATE_LIMIT_SALT', '');
        if ($salt !== '' && (strlen($salt) < 16 || preg_match('/[\x00-\x1F\x7F]/', $salt) === 1)) {
            $r->error('RATE_LIMIT_SALT', 'invalid_value', 'must be at least 16 characters without control characters');
            $salt = '';
        }
        $sharedDir = $r->opt('CONCURRENCY_SHARED_DIR');
        if ($sharedDir !== null) {
            if (!self::isAbsolute($sharedDir) || preg_match('/[\x00-\x1F\x7F]/', $sharedDir) === 1) {
                $r->error('CONCURRENCY_SHARED_DIR', 'not_absolute', 'must be an absolute path');
                $sharedDir = null;
            } else {
                $sharedDir = self::resolvePath($sharedDir, $envDir);
            }
        }
        $rateLimit = new RateLimitConfig(
            $r->bool('RATE_LIMIT_ENABLED', true),
            $r->int('RATE_LIMIT_REQUESTS', 120, 1, 100000),
            $r->int('RATE_LIMIT_WINDOW', 60, 1, 3600),
            $r->enum('RATE_LIMIT_DRIVER', 'auto', ['auto', 'apcu', 'file']),
            $r->int('RATE_LIMIT_MAX_CONCURRENT', 4, 0, 1000),
            $r->int('RATE_LIMIT_ORIGIN_FETCHES_PER_MINUTE', 600, 0, 1000000),
            self::cidrList($r, 'RATE_LIMIT_EXEMPT_IPS'),
            $salt,
            $r->int('GLOBAL_MAX_CONCURRENT', 8, 0, 1000),
            $sharedDir,
        );

        $adminEnabled = $r->bool('ADMIN_ENABLED', false);
        $adminAllowHttp = $r->bool('ADMIN_ALLOW_HTTP', false);
        $adminToken = $r->str('ADMIN_TOKEN', '');
        $adminPrefix = $r->str('ADMIN_PATH_PREFIX', '/__mirrorgate');
        if (preg_match('#\A/[A-Za-z0-9_-]{3,32}\z#', $adminPrefix) !== 1) {
            $r->error('ADMIN_PATH_PREFIX', 'invalid_path', 'must match /[A-Za-z0-9_-]{3,32}');
            $adminPrefix = '/__mirrorgate';
        }
        if ($adminEnabled) {
            if ($adminToken === '') {
                $r->error('ADMIN_TOKEN', 'missing', 'is required when the admin endpoint is enabled');
            } elseif (strlen($adminToken) < 32) {
                $r->error('ADMIN_TOKEN', 'too_short', 'must be at least 32 characters long');
            } elseif (
                preg_match('/[\x00-\x20\x7F]/', $adminToken) === 1
            ) {
                $r->error('ADMIN_TOKEN', 'invalid_characters', 'must not contain whitespace or control characters');
            } elseif (
                stripos($adminToken, 'change-me') !== false
                || stripos($adminToken, 'changeme') !== false
                || stripos($adminToken, 'example') !== false
                || stripos($adminToken, 'token') !== false
            ) {
                $r->error('ADMIN_TOKEN', 'placeholder', 'looks like a placeholder value');
            } elseif (count(array_unique(str_split($adminToken))) === 1) {
                $r->error('ADMIN_TOKEN', 'repeated_character', 'must not be a single repeated character');
            }
            if (!$mirrorIsHttps && !$adminAllowHttp) {
                $r->error('ADMIN_ENABLED', 'requires_https', 'needs an https MIRROR_URL or ADMIN_ALLOW_HTTP=true');
            }
        }
        $failLimit = $r->int('ADMIN_FAIL_LIMIT', 5, 1, 1000);
        $admin = new AdminConfig(
            $adminEnabled,
            $adminToken,
            $adminPrefix,
            self::cidrList($r, 'ADMIN_IP_ALLOW'),
            $failLimit,
            $r->int('ADMIN_FAIL_WINDOW', 900, 1, 86400),
            $r->int('ADMIN_REQUESTS_PER_MINUTE', 60, 1, 10000),
            $r->int('ADMIN_GLOBAL_FAIL_LIMIT', 50, 1, 100000),
            $adminAllowHttp,
        );

        $heartbeat = 20;
        if ($r->has('SSE_HEARTBEAT')) {
            $heartbeat = $r->int('SSE_HEARTBEAT', 20, 0, 120);
            if ($heartbeat !== 0 && $heartbeat < 5) {
                $r->error('SSE_HEARTBEAT', 'out_of_range', 'must be 0 (disabled) or between 5 and 120');
                $heartbeat = 20;
            }
        }
        $stream = new StreamConfig(
            $r->bool('SSE_ENABLED', true),
            $r->int('SSE_MAX_DURATION', 60, 10, 3600),
            $heartbeat,
            $r->int('MAX_CONCURRENT_STREAMS', 3, 1, 100),
        );

        // ---- Daemon ----
        $listeners = [];
        for ($i = 1; $i <= self::DAEMON_LISTEN_MAX; $i++) {
            $key = 'DAEMON_LISTEN_' . $i;
            if ($r->has($key)) {
                $v = $r->str($key);
                if (preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
                    $r->error($key, 'invalid_value', 'contains control characters');
                    continue;
                }
                $listeners[] = $v;
            }
        }
        $defaultWsOrigins = [];
        foreach ($allowedHosts as $h) {
            $defaultWsOrigins[] = $mirrorCfg->scheme . '://' . $h
                . (self::isDefaultPort($mirrorCfg->scheme, $mirrorCfg->port) ? '' : ':' . $mirrorCfg->port);
        }
        $wsOrigins = [];
        if ($r->has('DAEMON_WS_ORIGIN_ALLOW')) {
            foreach ($r->list('DAEMON_WS_ORIGIN_ALLOW') as $o) {
                if (preg_match('#\Ahttps?://[A-Za-z0-9.\-\[\]:]+\z#', $o) !== 1) {
                    $r->error('DAEMON_WS_ORIGIN_ALLOW', 'invalid_origin', 'contains a value that is not scheme://host[:port]');
                    continue;
                }
                $wsOrigins[] = strtolower($o);
            }
        } else {
            $wsOrigins = $defaultWsOrigins;
        }
        $targetAllow = [];
        if ($r->has('DAEMON_TARGET_ALLOWLIST')) {
            foreach ($r->list('DAEMON_TARGET_ALLOWLIST') as $h) {
                $nh = self::hostValue($h, true);
                if ($nh === null) {
                    $r->error('DAEMON_TARGET_ALLOWLIST', 'invalid_host', 'contains an invalid host');
                    continue;
                }
                $targetAllow[] = $nh;
            }
        } else {
            $targetAllow = array_values(array_unique(array_merge([$originHost], $extraOrigins)));
        }
        $memoryLimit = $r->str('DAEMON_MEMORY_LIMIT', '256M');
        if (preg_match('/\A(-1|\d{1,9}[KMGkmg]?)\z/', $memoryLimit) !== 1) {
            $r->error('DAEMON_MEMORY_LIMIT', 'invalid_value', 'must be a PHP memory size such as 256M');
            $memoryLimit = '256M';
        }
        $maxConnections = $r->int('DAEMON_MAX_CONNECTIONS', 800, 1, 1000);
        // Worst case per session is two directions, each holding the high-water mark plus one 64 KiB read.
        // Unless the operator chose a value, derive one that keeps the whole daemon inside 60 percent of its memory limit.
        $highWaterDefault = 256 * 1024;
        $memoryBytes = self::memoryBytes($memoryLimit);
        if ($memoryBytes !== null) {
            $highWaterDefault = max(16 * 1024, min($highWaterDefault, intdiv((int) ($memoryBytes * 0.6), 2 * $maxConnections) - 65536));
        }
        $daemon = new DaemonConfig(
            $listeners,
            $r->bool('DAEMON_ALLOW_PUBLIC_BIND', false),
            $maxConnections,
            $r->int('DAEMON_MAX_CONN_PER_IP', 50, 1, 1000),
            $r->int('DAEMON_IDLE_TIMEOUT', 120, 1, 86400),
            $r->int('DAEMON_HANDSHAKE_TIMEOUT', 10, 1, 120),
            $r->bytes('DAEMON_MAX_HEADER_BYTES', 16 * 1024, 1024, 1024 * 1024),
            $r->bytes('DAEMON_BUFFER_HIGH_WATER', $highWaterDefault, 4096, 64 * 1024 * 1024),
            $r->int('DAEMON_UDP_SESSION_TTL', 60, 1, 3600),
            $r->int('DAEMON_UDP_MAX_SESSIONS', 1024, 1, 100000),
            $r->bytes('DAEMON_UDP_MAX_DATAGRAM', 65507, 512, 65507),
            $r->int('DAEMON_UDP_RATE_PER_SEC', 200, 1, 100000),
            $r->int('DAEMON_WS_MAX_LIFETIME', 0, 0, 2592000),
            array_values(array_unique($wsOrigins)),
            $targetAllow,
            $r->bool('DAEMON_ALLOW_PRIVATE_TARGETS', $allowPrivate),
            $r->int('DAEMON_TARGET_DNS_TTL', 60, 1, 3600),
            self::resolvePath($r->str('DAEMON_PID_FILE', $storageDir . '/run/daemon.pid'), $envDir),
            self::resolvePath($r->str('DAEMON_LOG_FILE', $storageDir . '/logs/daemon.log'), $envDir),
            self::resolvePath($r->str('DAEMON_STOP_FILE', $storageDir . '/run/daemon.stop'), $envDir),
            $memoryLimit,
            $r->bool('DAEMON_WS_ALLOW_NO_ORIGIN', false),
            self::cidrList($r, 'DAEMON_UDP_CLIENT_ALLOW', true),
            $r->int('DAEMON_UDP_MAX_AMPLIFICATION', 2, 1, 1000),
            self::cidrList($r, 'DAEMON_TCP_CLIENT_ALLOW'),
        );

        if ($r->hasErrors()) {
            throw new ConfigException($r->errors());
        }

        return new Config(
            $projectRoot,
            $envFile,
            $app,
            $originCfg,
            $mirrorCfg,
            $upstream,
            $rewrite,
            $cache,
            $security,
            $rateLimit,
            $admin,
            $stream,
            $daemon,
        );
    }

    /**
     * @return array{url:string,scheme:string,host:string,port:int,hostHeader:string}|null
     */
    private static function parseEndpoint(EnvReader $r, string $key): ?array
    {
        $raw = $r->opt($key);
        if ($raw === null) {
            $r->error($key, 'missing', 'is required');
            return null;
        }
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $raw) === 1) {
            $r->error($key, 'invalid_url', 'is not a valid URL');
            return null;
        }
        $p = parse_url($raw);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            $r->error($key, 'invalid_url', 'is not a valid URL');
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            $r->error($key, 'invalid_scheme', 'must use http or https');
            return null;
        }
        if (isset($p['user']) || isset($p['pass'])) {
            $r->error($key, 'userinfo_not_allowed', 'must not contain credentials');
            return null;
        }
        if (isset($p['query']) || isset($p['fragment']) || (isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/')) {
            $r->error($key, 'path_not_allowed', 'must not contain a path, query or fragment');
            return null;
        }
        $hostRaw = $p['host'];
        $isV6 = false;
        if (str_starts_with($hostRaw, '[') && str_ends_with($hostRaw, ']')) {
            $hostRaw = substr($hostRaw, 1, -1);
            if (filter_var($hostRaw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                $r->error($key, 'invalid_host', 'has an invalid host');
                return null;
            }
            $isV6 = true;
            $host = strtolower($hostRaw);
        } else {
            $host = self::hostValue($hostRaw, true);
            if ($host === null) {
                $r->error($key, 'invalid_host', 'has an invalid host');
                return null;
            }
            if (Hostname::isIpLiteral($host) && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $r->error($key, 'invalid_host', 'has an invalid host (non-canonical IP literal)');
                return null;
            }
        }
        $default = $scheme === 'https' ? 443 : 80;
        $port = $p['port'] ?? $default;
        if ($port < 1 || $port > 65535) {
            $r->error($key, 'invalid_port', 'has an invalid port');
            return null;
        }
        $hostHeader = ($isV6 ? '[' . $host . ']' : $host) . ($port !== $default ? ':' . $port : '');
        return [
            'url' => $scheme . '://' . $hostHeader,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'hostHeader' => $hostHeader,
        ];
    }

    private static function parseProxy(EnvReader $r, string $key): ?string
    {
        $raw = $r->opt($key);
        if ($raw === null) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $raw) === 1) {
            $r->error($key, 'invalid_url', 'is not a valid proxy URL');
            return null;
        }
        $p = parse_url($raw);
        if (
            $p === false
            || !isset($p['scheme'], $p['host'])
            || !in_array(strtolower($p['scheme']), ['http', 'https', 'socks5', 'socks5h'], true)
            || isset($p['query']) || isset($p['fragment'])
            || (isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/')
        ) {
            $r->error($key, 'invalid_url', 'must be an http://, https://, socks5:// or socks5h:// URL');
            return null;
        }
        if (isset($p['port']) && ($p['port'] < 1 || $p['port'] > 65535)) {
            $r->error($key, 'invalid_port', 'has an invalid port');
            return null;
        }
        return $raw;
    }

    private static function parseWsUrl(EnvReader $r, string $raw): ?string
    {
        $p = parse_url($raw);
        if (
            preg_match('/[\x00-\x20\x7F\\\\]/', $raw) === 1
            || $p === false
            || !isset($p['scheme'], $p['host'])
            || !in_array(strtolower($p['scheme']), ['ws', 'wss'], true)
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || (isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/')
        ) {
            $r->error('WS_PUBLIC_URL', 'invalid_url', 'must be a ws:// or wss:// URL with host and optional port only');
            return null;
        }
        $host = self::hostValue($p['host'], true);
        if ($host === null) {
            $r->error('WS_PUBLIC_URL', 'invalid_host', 'has an invalid host');
            return null;
        }
        if (isset($p['port']) && ($p['port'] < 1 || $p['port'] > 65535)) {
            $r->error('WS_PUBLIC_URL', 'invalid_port', 'has an invalid port');
            return null;
        }
        return strtolower($p['scheme']) . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /**
     * @return array<string,string>
     */
    private static function parseHostMap(EnvReader $r, string $originHost, string $mirrorHost): array
    {
        $map = [];
        foreach ($r->list('HOST_MAP') as $pair) {
            if (substr_count($pair, '=') !== 1) {
                $r->error('HOST_MAP', 'invalid_pair', 'contains an entry that is not originhost=mirrorhost');
                continue;
            }
            [$o, $m] = explode('=', $pair, 2);
            $oh = self::hostValue(trim($o));
            $mh = self::hostValue(trim($m));
            if ($oh === null || $mh === null || Hostname::isIpLiteral($oh) || Hostname::isIpLiteral($mh)) {
                $r->error('HOST_MAP', 'invalid_host', 'contains an invalid host name');
                continue;
            }
            $parentOfOrigin = str_ends_with($originHost, '.' . $oh) && substr_count($oh, '.') >= 1;
            if (!Hostname::isSameOrSubdomain($oh, $originHost) && !$parentOfOrigin) {
                $r->error('HOST_MAP', 'origin_side_not_related', 'contains an origin host that is neither the origin, a subdomain of it, nor a parent domain with two labels');
                continue;
            }
            if (isset($map[$oh])) {
                $r->error('HOST_MAP', 'duplicate_origin', 'maps the same origin host twice');
                continue;
            }
            if ($oh === $originHost && $mh !== $mirrorHost) {
                $r->error('HOST_MAP', 'conflicts_with_mirror_url', 'maps the primary origin host to a host other than MIRROR_URL');
                continue;
            }
            $map[$oh] = $mh;
        }
        return $map;
    }

    /**
     * @return list<string>
     */
    private static function cidrList(EnvReader $r, string $key, bool $rejectWide = false): array
    {
        $out = [];
        foreach ($r->list($key) as $item) {
            if (!self::validCidr($item)) {
                $r->error($key, 'invalid_cidr', 'contains an invalid IP address or CIDR range');
                continue;
            }
            if ($rejectWide && self::isTooWide($item)) {
                $r->error($key, 'range_too_wide', 'contains a range that is too wide to be a client allowlist (an open range turns a UDP listener into a reflector)');
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /** True for IPv4 ranges shorter than /8 and IPv6 ranges shorter than /32 (including 0.0.0.0/0 and ::/0). */
    private static function isTooWide(string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (!isset($parts[1])) {
            return false;
        }

        return (int) $parts[1] < (str_contains($parts[0], ':') ? 32 : 8);
    }

    /** @return int|null bytes, null for -1 (unlimited) */
    private static function memoryBytes(string $value): ?int
    {
        if ($value === '-1' || preg_match('/\A(\d{1,9})([KMGkmg]?)\z/', $value, $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];

        return match (strtoupper($m[2])) {
            'K' => $n * 1024,
            'M' => $n * 1048576,
            'G' => $n * 1073741824,
            default => $n,
        };
    }

    private static function validCidr(string $c): bool
    {
        $parts = explode('/', $c, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (!isset($parts[1])) {
            return true;
        }
        if ($parts[1] === '' || strlen($parts[1]) > 3 || !ctype_digit($parts[1])) {
            return false;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;
        return (int) $parts[1] <= $max;
    }

    /**
     * Normalises a host value; returns null when invalid. With $allowIp, IP literals are accepted.
     */
    private static function hostValue(string $raw, bool $allowIp = false): ?string
    {
        try {
            $h = Hostname::normalize($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (!$allowIp && Hostname::isIpLiteral($h)) {
            return null;
        }
        return $h;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }

    private static function isAbsolute(string $p): bool
    {
        return str_starts_with($p, '/') || preg_match('#\A[A-Za-z]:[\\\\/]#', $p) === 1;
    }

    /**
     * Resolves a path against a base directory and normalises "." and ".." segments lexically.
     */
    private static function resolvePath(string $path, string $base): string
    {
        if (!self::isAbsolute($path)) {
            $path = rtrim($base, '/\\') . '/' . $path;
        }
        $prefix = '';
        if (preg_match('#\A([A-Za-z]:)[\\\\/]#', $path, $m) === 1) {
            $prefix = $m[1];
            $path = substr($path, 2);
        }
        $segments = [];
        foreach (preg_split('#[\\\\/]+#', $path) ?: [] as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }
        return $prefix . '/' . implode('/', $segments);
    }
}
