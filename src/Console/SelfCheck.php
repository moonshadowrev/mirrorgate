<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Console;

use MirrorGate\Config\Config;
use MirrorGate\Config\ConfigFactory;
use MirrorGate\Env\EnvParser;
use MirrorGate\Http\ResponseEmitter;
use MirrorGate\Security\FileRateLimiter;
use MirrorGate\Security\IpClassifier;
use MirrorGate\Support\FileUtil;
use MirrorGate\Support\Json;
use MirrorGate\Support\SystemClock;
use MirrorGate\Version;

/**
 * Operator self check. Messages are deliberately generic (no paths, no values, no origin details)
 * because the same results are served through the authenticated admin endpoint.
 */
final class SelfCheck implements SelfCheckInterface
{
    /** @var list<CheckResult> */
    private array $results = [];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return list<CheckResult>
     */
    public function run(bool $network): array
    {
        $this->results = [];

        $this->checkPhp();
        $this->checkExtensions();
        $this->checkDisabledFunctions();
        $this->checkIni();
        $this->checkStorage();
        $this->checkSecretsPlacement();
        $this->checkEnvFile();
        $this->checkConfigPosture();
        $this->checkHostMap();
        $this->checkDaemonMemoryBudget();
        $this->checkProxyHint();
        if ($network) {
            $this->checkExposure();
            $this->checkOriginReachability();
        } else {
            $this->add('network', CheckLevel::Ok, 'Network probes skipped (run with --network or through the admin /check endpoint).');
        }

        return $this->results;
    }

    private function add(string $name, CheckLevel $level, string $message): void
    {
        $this->results[] = new CheckResult($name, $level, $message);
    }

    private function checkPhp(): void
    {
        if (version_compare(PHP_VERSION, '8.3.0', '>=')) {
            $this->add('php.version', CheckLevel::Ok, 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' (MirrorGate ' . Version::VERSION . ').');
        } else {
            $this->add('php.version', CheckLevel::Fail, 'PHP 8.3 or newer is required.');
        }
        $this->add('php.sapi', CheckLevel::Ok, 'SAPI: ' . PHP_SAPI . '.');

        if (PHP_SAPI === 'apache2handler' && !$this->hasIsolationEvidence()) {
            $this->add(
                'php.sapi_isolation',
                CheckLevel::Warn,
                'PHP runs as an Apache module without evidence of per-account isolation (CageFS, suexec or suPHP). '
                . 'Other accounts on the host may be able to read the .env file through their own PHP; prefer PHP-FPM or LiteSpeed '
                . 'with per-account users, keep secrets in .env.php, and set STORAGE_FILE_MODE/STORAGE_DIR_MODE for the handler.'
            );
        }
    }

    private function hasIsolationEvidence(): bool
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && !in_array($info['name'], ['nobody', 'www-data', 'apache', 'daemon', 'www'], true)) {
                return true;
            }
        }
        foreach (['CAGEFS', 'SUEXEC_ENABLED'] as $key) {
            if (getenv($key) !== false) {
                return true;
            }
        }
        return false;
    }

    private function checkExtensions(): void
    {
        foreach (['curl', 'json', 'mbstring'] as $ext) {
            if (extension_loaded($ext)) {
                $this->add('ext.' . $ext, CheckLevel::Ok, 'Required extension ' . $ext . ' is loaded.');
            } else {
                $this->add('ext.' . $ext, CheckLevel::Fail, 'Required extension ' . $ext . ' is missing.');
            }
        }
        $optional = [
            'zlib' => 'responses cannot be recompressed and gzip bodies cannot be rewritten',
            'apcu' => 'rate limiting uses the slower file driver',
            'pcntl' => 'the daemon cannot handle signals (it can still be stopped with the stop file)',
            'posix' => 'the daemon cannot report the process owner',
            'intl' => 'IDN host names use the built-in Punycode encoder',
            'brotli' => 'Brotli-encoded bodies cannot be decoded locally',
        ];
        foreach ($optional as $ext => $effect) {
            if (extension_loaded($ext)) {
                $this->add('ext.' . $ext, CheckLevel::Ok, 'Optional extension ' . $ext . ' is loaded.');
            } else {
                $this->add('ext.' . $ext, CheckLevel::Ok, 'Optional extension ' . $ext . ' is not loaded: ' . $effect . '.');
            }
        }
        if (extension_loaded('apcu') && function_exists('apcu_enabled') && !apcu_enabled()) {
            $this->add('ext.apcu_enabled', CheckLevel::Warn, 'APCu is installed but not enabled for this SAPI (apc.enabled / apc.enable_cli).');
        }
    }

    private function checkDisabledFunctions(): void
    {
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        $needed = ['curl_init', 'curl_setopt', 'curl_exec', 'curl_multi_exec'];
        $hit = array_values(array_intersect($needed, $disabled));
        if ($hit !== []) {
            $this->add('php.disable_functions', CheckLevel::Fail, 'disable_functions blocks required cURL functions: ' . implode(', ', $hit) . '.');
        } else {
            $this->add('php.disable_functions', CheckLevel::Ok, 'No required function is disabled.');
        }
        $basedir = (string) ini_get('open_basedir');
        if ($basedir !== '') {
            $this->add('php.open_basedir', CheckLevel::Warn, 'open_basedir is active: make sure STORAGE_DIR and CONCURRENCY_SHARED_DIR are inside it.');
        }
    }

    private function checkIni(): void
    {
        $epdr = filter_var(ini_get('enable_post_data_reading'), FILTER_VALIDATE_BOOLEAN);
        if ($epdr) {
            $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            $form = str_contains($ct, 'multipart/form-data') || str_contains($ct, 'application/x-www-form-urlencoded');
            $level = (PHP_SAPI !== 'cli' && $form) ? CheckLevel::Fail : CheckLevel::Warn;
            $this->add(
                'ini.enable_post_data_reading',
                $level,
                'enable_post_data_reading is on: PHP consumes form bodies and uploads would be proxied empty. '
                . 'Set it to 0 in .user.ini (FPM/CGI) or .htaccess (mod_php). The value shown by the CLI may differ from the web SAPI.'
            );
        } else {
            $this->add('ini.enable_post_data_reading', CheckLevel::Ok, 'enable_post_data_reading is 0.');
        }

        $post = (string) ini_get('post_max_size');
        $postBytes = 0;
        try {
            $postBytes = \MirrorGate\Support\Str::parseBytes($post === '' ? '0' : $post);
        } catch (\InvalidArgumentException) {
            $postBytes = 0;
        }
        $maxBody = $this->config->upstream->maxRequestBodyBytes;
        if ($postBytes > 0 && $postBytes < $maxBody) {
            $this->add('ini.post_max_size', CheckLevel::Warn, 'post_max_size is smaller than MAX_REQUEST_BODY_BYTES; uploads above post_max_size are rejected with 413.');
        } else {
            $this->add('ini.post_max_size', CheckLevel::Ok, 'post_max_size is compatible with MAX_REQUEST_BODY_BYTES.');
        }

        if (!ResponseEmitter::canSetTimeLimit()) {
            $this->add('ini.set_time_limit', CheckLevel::Warn, 'set_time_limit() is unavailable: streaming and background work are limited by max_execution_time.');
        }
        $ceiling = ResponseEmitter::runtimeCeiling();
        if ($ceiling < PHP_INT_MAX) {
            $this->add('ini.runtime_ceiling', CheckLevel::Warn, 'Requests are limited to about ' . $ceiling . ' seconds (SSE and long transfers are clamped to it).');
        } else {
            $this->add('ini.runtime_ceiling', CheckLevel::Ok, 'No finite runtime ceiling for streaming paths.');
        }

        if (ResponseEmitter::canDetach()) {
            $this->add('sapi.detach', CheckLevel::Ok, 'The client connection can be detached (background refresh and cache GC run after the response).');
        } else {
            $this->add('sapi.detach', CheckLevel::Warn, 'No fastcgi_finish_request/litespeed_finish_request: stale-while-revalidate is treated as expired and no background work runs.');
        }

        if ((string) ini_get('output_buffering') !== '' && (string) ini_get('output_buffering') !== '0' && strtolower((string) ini_get('output_buffering')) !== 'off') {
            $this->add('ini.output_buffering', CheckLevel::Warn, 'output_buffering is enabled; streaming disables it per request but upstream layers may still buffer (use /streamtest).');
        }
        if (filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)) {
            $this->add('ini.zlib_output_compression', CheckLevel::Warn, 'zlib.output_compression is on; it is disabled per streaming request when ini_set is allowed.');
        }
    }

    private function checkStorage(): void
    {
        $storage = $this->config->app->storageDir;
        $this->add('storage.writable', $this->isWritableOrCreatable($storage) ? CheckLevel::Ok : CheckLevel::Fail, $this->isWritableOrCreatable($storage) ? 'STORAGE_DIR is writable.' : 'STORAGE_DIR is not writable and cannot be created.');
        if ($this->config->cache->enabled) {
            $ok = $this->isWritableOrCreatable($this->config->cache->dir);
            $this->add('cache.writable', $ok ? CheckLevel::Ok : CheckLevel::Fail, $ok ? 'CACHE_DIR is writable.' : 'CACHE_DIR is not writable and cannot be created.');
        }
        $fileMode = $this->config->app->storageFileMode;
        $dirMode = $this->config->app->storageDirMode;
        if (($fileMode & 0007) !== 0 || ($dirMode & 0007) !== 0) {
            $this->add('storage.modes', CheckLevel::Warn, 'STORAGE_FILE_MODE/STORAGE_DIR_MODE grant access to other users; use 0640/0750 where PHP-FPM or suPHP runs per account.');
        } else {
            $this->add('storage.modes', CheckLevel::Ok, 'Storage permissions are restrictive.');
        }
        if ($this->config->rateLimit->enabled && $this->config->rateLimit->driver !== 'apcu') {
            $limiter = new FileRateLimiter(rtrim($storage, '/\\') . '/ratelimit', new SystemClock());
            if ($limiter->probe()) {
                $this->add('ratelimit.storage', CheckLevel::Ok, 'Rate-limit storage is usable.');
            } else {
                $this->add('ratelimit.storage', CheckLevel::Fail, 'The rate-limit storage under STORAGE_DIR cannot be opened or locked: request limits are silently disabled.');
            }
        }
        if ($this->config->admin->enabled && $this->config->security->trustedProxies === []) {
            $this->add(
                'admin.proxy',
                CheckLevel::Warn,
                'ADMIN_ENABLED is on and TRUSTED_PROXIES is empty: behind a CDN or host reverse proxy every client shares the proxy address, so ADMIN_IP_ALLOW cannot tell clients apart. Set TRUSTED_PROXIES or restrict the admin path at the proxy.'
            );
        }
        if ($this->config->rateLimit->sharedDir !== null && !$this->isWritableOrCreatable($this->config->rateLimit->sharedDir)) {
            $this->add('storage.shared_dir', CheckLevel::Fail, 'CONCURRENCY_SHARED_DIR is not writable.');
        }
    }

    private function isWritableOrCreatable(string $dir): bool
    {
        $probe = $dir;
        while ($probe !== '' && !file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return false;
            }
            $probe = $parent;
        }
        return is_dir($probe) && is_writable($probe);
    }

    private function checkSecretsPlacement(): void
    {
        $docroot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
            ? realpath($_SERVER['DOCUMENT_ROOT'])
            : false;
        if ($this->config->app->allowSecretsInDocroot) {
            $this->add('secrets.docroot_override', CheckLevel::Warn, 'ALLOW_SECRETS_IN_DOCROOT is enabled: boot protection against web-served secrets is off.');
        }
        if ($docroot === false) {
            $this->add('secrets.docroot', CheckLevel::Ok, 'No document root to compare against (CLI).');
            return;
        }
        $docroot = rtrim($docroot, '/\\');
        $inside = static function (string $path) use ($docroot): bool {
            $real = realpath($path);
            if ($real === false) {
                $real = realpath(dirname($path));
                if ($real === false) {
                    return false;
                }
                $real .= '/' . basename($path);
            }
            return $real === $docroot || str_starts_with($real, $docroot . '/');
        };
        if ($inside($this->config->app->storageDir)) {
            $this->add('secrets.storage_in_docroot', CheckLevel::Fail, 'STORAGE_DIR is inside the document root.');
        } else {
            $this->add('secrets.storage_in_docroot', CheckLevel::Ok, 'STORAGE_DIR is outside the document root.');
        }
        if ($this->config->cache->enabled && $inside($this->config->cache->dir)) {
            $this->add('secrets.cache_in_docroot', CheckLevel::Fail, 'CACHE_DIR is inside the document root.');
        }
        $selfProtecting = basename($this->config->envFile) === '.env.php';
        if ($inside($this->config->envFile) && !$selfProtecting) {
            $this->add('secrets.env_in_docroot', CheckLevel::Fail, 'The environment file is inside the document root and is not the self-protecting .env.php form.');
        } else {
            $this->add('secrets.env_in_docroot', CheckLevel::Ok, 'The environment file is not web-readable by placement.');
        }
    }

    private function checkEnvFile(): void
    {
        $content = FileUtil::readFile($this->config->envFile, 262144);
        if ($content === null) {
            $this->add('env.file', CheckLevel::Warn, 'The environment file could not be re-read for linting.');
            return;
        }
        $parsed = EnvParser::parse($content);
        foreach ($parsed['warnings'] as $w) {
            $this->add('env.syntax', CheckLevel::Warn, $w);
        }
        $known = array_flip(ConfigFactory::knownKeys());
        foreach (array_keys($parsed['values']) as $key) {
            if (!isset($known[$key])) {
                $this->add('env.unknown_key', CheckLevel::Warn, 'Unknown variable ' . $key . ' is ignored.');
            }
        }
        $perms = @fileperms($this->config->envFile);
        if ($perms !== false && ($perms & 0004) !== 0 && PHP_SAPI !== 'apache2handler') {
            $this->add('env.permissions', CheckLevel::Warn, 'The environment file is world-readable; use mode 0640 or 0600 where the PHP handler runs as the account user.');
        }
    }

    private function checkConfigPosture(): void
    {
        $c = $this->config;

        if ($c->origin->allowPrivate) {
            $this->add('origin.private', CheckLevel::Warn, 'ALLOW_PRIVATE_ORIGIN is enabled: the SSRF block for private addresses is disabled for the origin.');
        }
        if ($c->origin->allowHttp) {
            $this->add('origin.http', CheckLevel::Warn, 'The origin is reached over plain http.');
        }
        $this->add('tls.verify', CheckLevel::Ok, 'TLS certificate verification is always enabled and cannot be disabled.');

        $proxy = $c->origin->outboundProxy;
        if ($proxy !== null) {
            $scheme = strtolower((string) parse_url($proxy, PHP_URL_SCHEME));
            if ($scheme === 'socks5') {
                $this->add('origin.proxy', CheckLevel::Warn, 'OUTBOUND_PROXY uses socks5:// which resolves host names locally; use socks5h:// so the proxy resolves them.');
            } else {
                $this->add('origin.proxy', CheckLevel::Ok, 'An outbound proxy is configured; the proxy is the SSRF boundary (only host and port allowlists apply locally).');
            }
        }

        if ($c->origin->subdomainMode === 'prefix' || $c->origin->extraOrigins !== [] || $c->origin->prefixModeAck) {
            $this->add(
                'origin.prefix_mode',
                CheckLevel::Fail,
                'Prefix mode or EXTRA_ORIGINS is active: all listed hosts share one browser origin (cookies, storage, script access). Prefer one instance per subdomain with HOST_MAP.'
            );
        }

        if ($c->admin->enabled) {
            if (!$c->mirror->isHttps) {
                $this->add('admin.https', CheckLevel::Warn, 'The admin endpoint is enabled over http (ADMIN_ALLOW_HTTP): the token travels in clear text.');
            } else {
                $this->add('admin.https', CheckLevel::Ok, 'The admin endpoint requires https.');
            }
            if ($c->admin->ipAllow === []) {
                $this->add('admin.ip_allow', CheckLevel::Warn, 'ADMIN_IP_ALLOW is empty: the admin endpoint is reachable from any address.');
            }
            $this->add('admin.token', CheckLevel::Ok, 'The admin token passed the strength checks.');
        } else {
            $this->add('admin.disabled', CheckLevel::Ok, 'The admin endpoint is disabled.');
        }

        if ($c->rewrite->hstsMode === 'own' && $c->rewrite->hstsValue !== null && stripos($c->rewrite->hstsValue, 'preload') !== false) {
            $this->add('hsts.preload', CheckLevel::Warn, 'HSTS_VALUE contains preload; preloading is hard to undo.');
        }

        if ($c->upstream->forwardCookies || $c->upstream->forwardAuthorization) {
            if ($c->security->blockedPaths === []) {
                $this->add(
                    'privacy.credentials',
                    CheckLevel::Warn,
                    'Cookies and Authorization headers are forwarded and no login paths are blocked: the mirror operator can read every credential that passes through. '
                    . 'Do not mirror authenticated applications, or set AUTH_PASSTHROUGH_BLOCK.'
                );
            }
        }

        if ($c->security->trustedProxies === []) {
            $this->add('proxy.trusted', CheckLevel::Ok, 'TRUSTED_PROXIES is empty: forwarded client IP headers are ignored.');
        }

        if ($c->rateLimit->enabled && $c->rateLimit->driver === 'apcu' && !(function_exists('apcu_enabled') && apcu_enabled())) {
            $this->add('ratelimit.driver', CheckLevel::Fail, 'RATE_LIMIT_DRIVER=apcu but APCu is not available.');
        }

        if ($c->app->debug) {
            $this->add('app.debug', CheckLevel::Warn, 'APP_DEBUG is on: verbose logging only; client output is unchanged.');
        }
    }

    private function checkHostMap(): void
    {
        $o = $this->config->origin;
        $m = $this->config->mirror;
        if ($o->hostMap === []) {
            return;
        }
        $originHosts = array_merge([$o->host], array_keys($o->hostMap));
        $seenMirror = [];
        foreach ($o->hostMap as $originHost => $mirrorHost) {
            if (in_array($mirrorHost, $originHosts, true)) {
                $this->add('hostmap.collision', CheckLevel::Fail, 'HOST_MAP maps an origin host to a host that is itself an origin host.');
            }
            if (isset($seenMirror[$mirrorHost])) {
                $this->add('hostmap.duplicate_mirror', CheckLevel::Warn, 'HOST_MAP maps two origin hosts to the same mirror host.');
            }
            $seenMirror[$mirrorHost] = true;
            $parent = $this->commonParent($mirrorHost, $m->host);
            if ($parent === null) {
                $this->add('hostmap.parent', CheckLevel::Warn, 'A HOST_MAP mirror host does not share a parent domain with MIRROR_URL; cookies shared between siblings will not work.');
            }
        }
        if (PHP_SAPI === 'cli') {
            foreach ($o->hostMap as $originHost => $mirrorHost) {
                $this->add(
                    'hostmap.reciprocal',
                    CheckLevel::Ok,
                    'The sibling instance that mirrors ' . $originHost . ' as ' . $mirrorHost . ' should list in HOST_MAP: ' . $o->host . '=' . $m->host
                );
            }
        } else {
            $this->add('hostmap.reciprocal', CheckLevel::Ok, 'HOST_MAP must be reciprocal across sibling instances; run bin/mirrorgate check to print the exact lines.');
        }
    }

    private function commonParent(string $a, string $b): ?string
    {
        $pa = explode('.', $a);
        $pb = explode('.', $b);
        $common = [];
        while ($pa !== [] && $pb !== [] && end($pa) === end($pb)) {
            array_unshift($common, array_pop($pa));
            array_pop($pb);
        }
        return count($common) >= 2 ? implode('.', $common) : null;
    }

    private function checkDaemonMemoryBudget(): void
    {
        $dc = $this->config->daemon;
        if ($dc->listeners === [] || preg_match('/\A(\d{1,9})([KMGkmg]?)\z/', $dc->memoryLimit, $m) !== 1) {
            return;
        }
        $limit = (int) $m[1] * match (strtoupper($m[2])) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1,
        };
        // Each session can hold the high-water mark plus one 64 KiB read in both directions.
        $worstCase = 2 * $dc->maxConnections * ($dc->bufferHighWater + 65536);
        if ($worstCase > $limit) {
            $this->add(
                'daemon.memory_budget',
                CheckLevel::Warn,
                'DAEMON_MAX_CONNECTIONS x 2 x (DAEMON_BUFFER_HIGH_WATER + 64K) exceeds DAEMON_MEMORY_LIMIT: slow readers could exhaust memory and restart the daemon. '
                . 'Lower DAEMON_MAX_CONNECTIONS or DAEMON_BUFFER_HIGH_WATER, or raise DAEMON_MEMORY_LIMIT.'
            );
        }
    }

    private function checkProxyHint(): void
    {
        if ($this->config->security->trustedProxies !== []) {
            return;
        }
        $raw = FileUtil::readFile($this->config->app->storageDir . '/run/proxyhint.json', 65536);
        if ($raw === null) {
            return;
        }
        try {
            $data = Json::decode($raw);
        } catch (\JsonException) {
            return;
        }
        if (!is_array($data)) {
            return;
        }
        foreach ($data as $entry) {
            if (is_array($entry) && isset($entry['h']) && is_array($entry['h']) && count($entry['h']) >= 2) {
                $this->add(
                    'proxy.hint',
                    CheckLevel::Warn,
                    'Requests from one connecting address carry different forwarded client addresses: the site is probably behind a CDN or reverse proxy. '
                    . 'Every visitor currently shares one rate-limit bucket; set TRUSTED_PROXIES to your proxy or CDN ranges (literal CIDRs or @file:).'
                );
                return;
            }
        }
    }

    private function checkExposure(): void
    {
        if (!function_exists('curl_init')) {
            $this->add('probe.exposure', CheckLevel::Warn, 'Exposure probes skipped: cURL is unavailable.');
            return;
        }
        $base = $this->config->mirror->url;
        foreach (['/.env', '/.env.php', '/src/autoload.php'] as $path) {
            [$status, $body, $err] = $this->probe($base . $path);
            $name = 'probe.' . ltrim(str_replace(['/', '.'], '_', $path), '_');
            if ($status === null) {
                $this->add($name, CheckLevel::Warn, 'Probe of a sensitive path could not connect (' . $err . '): result unknown.');
                continue;
            }
            $looksEnv = preg_match('/^[A-Z][A-Z0-9_]*\s*=/m', $body) === 1
                || str_contains($body, 'ORIGIN_URL')
                || str_contains($body, 'ADMIN_TOKEN');
            $looksPhp = str_contains($body, '<?php');
            if ($status === 200 && ($looksEnv || $looksPhp)) {
                $this->add($name, CheckLevel::Fail, 'A sensitive path is publicly readable and returns configuration or source code.');
            } elseif ($status === 200 && $path === '/src/autoload.php' && $body === '') {
                $this->add($name, CheckLevel::Ok, 'The file executed and printed nothing (not readable as source); still, deny src/ in the web server.');
            } else {
                $this->add($name, CheckLevel::Ok, 'Not exposed (HTTP ' . $status . ').');
            }
        }
    }

    /**
     * @return array{0:?int,1:string,2:string}
     */
    private function probe(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [null, '', 'init'];
        }
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_WRITEFUNCTION => static function ($c, string $data) use (&$body): int {
                if (strlen($body) < 8192) {
                    $body .= substr($data, 0, 8192 - strlen($body));
                }
                return strlen($data);
            },
        ]);
        if ($this->config->origin->caBundle !== null && $this->config->origin->caBundle !== '' && is_file($this->config->origin->caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->config->origin->caBundle);
        }
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $errno !== 0) {
            $class = match (true) {
                in_array($errno, [6], true) => 'dns',
                in_array($errno, [7, 28], true) => 'connect',
                in_array($errno, [35, 51, 58, 60, 77, 83], true) => 'tls',
                default => 'error',
            };
            return [null, '', $class];
        }
        return [$status, $body, ''];
    }

    private function checkOriginReachability(): void
    {
        $o = $this->config->origin;
        if ($o->outboundProxy !== null) {
            $this->add('origin.reachability', CheckLevel::Warn, 'Origin reachability is not tested directly because an outbound proxy is configured.');
            return;
        }
        $ips = $this->resolveHost($o->host);
        if ($ips === []) {
            if ($o->resolveIp !== null) {
                $this->add('origin.dns', CheckLevel::Warn, 'origin unreachable (dns); ORIGIN_RESOLVE_IP will be used as a fallback.');
                $ips = [$o->resolveIp];
            } else {
                $this->add('origin.dns', CheckLevel::Fail, 'origin unreachable (dns).');
                return;
            }
        } else {
            $this->add('origin.dns', CheckLevel::Ok, 'The origin host name resolves.');
        }
        if (!$o->allowPrivate && class_exists(IpClassifier::class)) {
            foreach ($ips as $ip) {
                if (!IpClassifier::isPublic($ip)) {
                    $this->add('origin.address', CheckLevel::Fail, 'origin resolves to a non-public address; requests will be blocked (SSRF protection).');
                    return;
                }
            }
        }
        $ip = $ips[0];
        $target = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('tcp://' . $target . ':' . $o->port, $errno, $errstr, 5);
        if ($sock === false) {
            $this->add('origin.tcp', CheckLevel::Fail, 'origin unreachable (connect).');
            return;
        }
        fclose($sock);
        $this->add('origin.tcp', CheckLevel::Ok, 'A TCP connection to the origin succeeded.');

        if ($o->scheme === 'https') {
            $context = stream_context_create(['ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $o->host,
                'SNI_enabled' => true,
                'SNI_server_name' => $o->host,
            ] + ($o->caBundle !== null && is_file($o->caBundle) ? ['cafile' => $o->caBundle] : [])]);
            $tls = @stream_socket_client('ssl://' . $target . ':' . $o->port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
            if ($tls === false) {
                $this->add(
                    'origin.tls',
                    CheckLevel::Fail,
                    'origin unreachable (tls). If this host lacks a current CA store, set CA_BUNDLE to a current PEM CA bundle you download and verify yourself.'
                );
                return;
            }
            fclose($tls);
            $this->add('origin.tls', CheckLevel::Ok, 'The TLS handshake with the origin succeeded.');
        }
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (isset($r['ip']) && is_string($r['ip'])) {
                        $ips[] = $r['ip'];
                    }
                }
            }
            if ($ips === []) {
                $records = @dns_get_record($host, DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $r) {
                        if (isset($r['ipv6']) && is_string($r['ipv6'])) {
                            $ips[] = $r['ipv6'];
                        }
                    }
                }
            }
        }
        if ($ips === [] && function_exists('gethostbynamel')) {
            $list = @gethostbynamel($host);
            if (is_array($list)) {
                $ips = $list;
            }
        }
        return $ips;
    }
}
