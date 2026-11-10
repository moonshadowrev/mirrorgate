<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate;

use MirrorGate\Config\Config;
use MirrorGate\Config\ConfigException;
use MirrorGate\Config\ConfigFactory;
use MirrorGate\Env\EnvParser;
use MirrorGate\Http\HttpException;
use MirrorGate\Http\Request;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Support\FileUtil;
use MirrorGate\Support\Json;

/**
 * Process bootstrap for the web front controller and the CLI tools.
 */
final class Bootstrap
{
    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    private const TITLES = [
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        421 => 'Misdirected Request',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        508 => 'Loop Detected',
    ];

    private static ?string $reserve = null;
    private static string $requestId = '';
    private static string $envFile = '';
    private static ?LoggerInterface $logger = null;
    private static bool $modesExplicit = false;
    private static ?string $fallbackSalt = null;
    private static bool $saltWarned = false;
    private static bool $handlersInstalled = false;

    /**
     * Front controller entry point.
     */
    public static function run(string $root, string $envFile): void
    {
        @ini_set('display_errors', '0');
        @ini_set('log_errors', '0');
        error_reporting(E_ALL);
        self::$reserve = str_repeat('x', 32768);
        self::$requestId = bin2hex(random_bytes(8));
        self::$envFile = $envFile;

        if (!self::$handlersInstalled) {
            self::$handlersInstalled = true;
            set_error_handler([self::class, 'handleError']);
            set_exception_handler([self::class, 'handleException']);
            register_shutdown_function([self::class, 'handleShutdown']);
        }

        // httpoxy: a client "Proxy:" header must never configure outbound proxying.
        unset($_SERVER['HTTP_PROXY'], $_ENV['HTTP_PROXY']);
        putenv('HTTP_PROXY');
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        try {
            $config = self::loadConfig($root, $envFile);
        } catch (ConfigException $e) {
            self::bootLog('config', $e->codes());
            self::fallbackResponse(500);
            return;
        } catch (\Throwable $e) {
            self::bootLog('config', ['BOOT:' . get_class($e)]);
            self::fallbackResponse(500);
            return;
        }

        try {
            date_default_timezone_set($config->app->timezone);
            if (!self::$modesExplicit && function_exists('umask') && umask() === 0022) {
                umask(0027);
            }
            $container = self::container($config);
            self::$logger = $container->logger();
        } catch (\Throwable $e) {
            self::bootLog('container', ['BOOT:' . get_class($e)]);
            self::fallbackResponse(500);
            return;
        }

        try {
            $headers = function_exists('getallheaders') ? getallheaders() : null;
            $request = Request::fromGlobals($_SERVER, is_array($headers) ? $headers : null);
        } catch (HttpException $e) {
            self::fallbackResponse($e->status, ($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
            return;
        }

        $kernel = new Kernel($container);
        $kernel->handle($request);
        $kernel->afterResponse();
    }

    /**
     * Resolves the environment file: MIRRORGATE_ENV constant, MIRRORGATE_ENV variable,
     * ROOT/.env.php when present, else ROOT/.env.
     */
    public static function resolveEnvFile(string $root): string
    {
        $root = rtrim($root, '/\\');
        $candidate = null;
        if (defined('MIRRORGATE_ENV')) {
            $v = constant('MIRRORGATE_ENV');
            if (is_string($v) && $v !== '') {
                $candidate = $v;
            }
        }
        if ($candidate === null) {
            $g = getenv('MIRRORGATE_ENV');
            if (is_string($g) && $g !== '') {
                $candidate = $g;
            }
        }
        if ($candidate !== null) {
            return (str_starts_with($candidate, '/') || preg_match('#\A[A-Za-z]:[\\\\/]#', $candidate) === 1)
                ? $candidate
                : $root . '/' . $candidate;
        }
        $php = $root . '/.env.php';
        return is_file($php) ? $php : $root . '/.env';
    }

    /**
     * Reads and validates the configuration. Performs all the IO around the pure ConfigFactory.
     *
     * @throws ConfigException
     */
    public static function loadConfig(string $projectRoot, ?string $envFile = null): Config
    {
        $projectRoot = rtrim($projectRoot, '/\\');
        $envFile ??= self::resolveEnvFile($projectRoot);
        self::$envFile = $envFile;

        if (!file_exists($envFile)) {
            throw new ConfigException(['ENV_FILE:not_found - The environment file does not exist.']);
        }
        if (!is_file($envFile) || !is_readable($envFile)) {
            throw new ConfigException(['ENV_FILE:not_readable - The environment file exists but PHP cannot read it; check file permissions and ownership.']);
        }
        $realEnv = realpath($envFile);
        if ($realEnv !== false) {
            $envFile = $realEnv;
            self::$envFile = $envFile;
        }
        $content = FileUtil::readFile($envFile, 262144);
        if ($content === null) {
            throw new ConfigException(['ENV_FILE:not_readable - The environment file could not be read (permission problem or file too large).']);
        }

        $parsed = EnvParser::parse($content);
        $values = $parsed['values'];

        // Process environment overrides file values, for known keys only.
        foreach (ConfigFactory::knownKeys() as $key) {
            $env = getenv($key);
            if (is_string($env)) {
                $values[$key] = $env;
            }
        }
        self::$modesExplicit = isset($values['STORAGE_FILE_MODE']) || isset($values['STORAGE_DIR_MODE']);

        $preErrors = [];
        $envDir = dirname($envFile);
        if (isset($values['TRUSTED_PROXIES']) && trim($values['TRUSTED_PROXIES']) !== '') {
            $values['TRUSTED_PROXIES'] = self::expandTrustedProxies($values['TRUSTED_PROXIES'], $envDir, $preErrors);
        }
        if (isset($values['CA_BUNDLE']) && trim($values['CA_BUNDLE']) !== '') {
            $ca = trim($values['CA_BUNDLE']);
            $caPath = (str_starts_with($ca, '/') || preg_match('#\A[A-Za-z]:[\\\\/]#', $ca) === 1) ? $ca : $envDir . '/' . $ca;
            if (!is_file($caPath)) {
                $preErrors[] = 'CA_BUNDLE:not_found - CA_BUNDLE does not point to an existing file.';
            }
        }

        try {
            $config = ConfigFactory::create($values, $projectRoot, $envFile);
        } catch (ConfigException $e) {
            throw new ConfigException(array_merge($preErrors, $e->getErrors()), $e);
        }
        if ($preErrors !== []) {
            throw new ConfigException($preErrors);
        }

        self::assertSecretsOutsideDocroot($config, $envFile, $content);
        return $config;
    }

    /**
     * The only supported way for bin/* and the daemon to obtain services.
     */
    public static function container(Config $config): Container
    {
        FileUtil::configureModes($config->app->storageDirMode, $config->app->storageFileMode);
        return new Container($config);
    }

    /**
     * RATE_LIMIT_SALT when set; otherwise 32 random bytes (hex) persisted at STORAGE_DIR/run/salt (0600).
     */
    public static function resolveSalt(Config $config): string
    {
        if ($config->rateLimit->salt !== '') {
            return $config->rateLimit->salt;
        }
        $file = $config->app->storageDir . '/run/salt';
        $existing = FileUtil::readFile($file, 256);
        if ($existing !== null && preg_match('/\A[0-9a-f]{64}\z/', trim($existing)) === 1) {
            return trim($existing);
        }
        try {
            FileUtil::ensureDir(dirname($file));
            $salt = bin2hex(random_bytes(32));
            FileUtil::atomicWrite($file, $salt, 0600);
            $again = FileUtil::readFile($file, 256);
            if ($again !== null && preg_match('/\A[0-9a-f]{64}\z/', trim($again)) === 1) {
                return trim($again);
            }
            return $salt;
        } catch (\Throwable) {
            self::$fallbackSalt ??= bin2hex(random_bytes(32));
            if (!self::$saltWarned) {
                self::$saltWarned = true;
                error_log('MirrorGate: unable to persist the rate-limit salt; using a per-process fallback (STORAGE_DIR is not writable).');
            }
            return self::$fallbackSalt;
        }
    }

    // ------------------------------------------------------------------ config helpers

    /**
     * @param list<string> $errors
     */
    private static function expandTrustedProxies(string $raw, string $envDir, array &$errors): string
    {
        $out = [];
        foreach (explode(',', $raw) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (str_starts_with($item, '@file:')) {
                $path = trim(substr($item, 6));
                if ($path === '') {
                    $errors[] = 'TRUSTED_PROXIES:file_unreadable - A @file: entry has no path.';
                    continue;
                }
                $full = (str_starts_with($path, '/') || preg_match('#\A[A-Za-z]:[\\\\/]#', $path) === 1) ? $path : $envDir . '/' . $path;
                self::readCidrFile($full, $out, $errors);
                continue;
            }
            $out[] = $item;
        }
        return implode(',', $out);
    }

    /**
     * @param list<string> $out
     * @param list<string> $errors
     */
    private static function readCidrFile(string $path, array &$out, array &$errors): void
    {
        $data = FileUtil::readFile($path, 1048576);
        if ($data === null) {
            $errors[] = 'TRUSTED_PROXIES:file_unreadable - A trusted-proxy list file is missing or unreadable.';
            return;
        }
        foreach (preg_split('/\r\n|\n|\r/', $data) ?: [] as $line) {
            $line = trim((string) preg_replace('/(^|\s)#.*$/', '', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }
    }

    /**
     * SEC-80: the env file and STORAGE_DIR must not live where the web server can serve them.
     *
     * @throws ConfigException
     */
    private static function assertSecretsOutsideDocroot(Config $config, string $envFile, string $envContent): void
    {
        if ($config->app->allowSecretsInDocroot) {
            return;
        }
        $docRootRaw = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!is_string($docRootRaw) || $docRootRaw === '') {
            return;
        }
        $docRoot = realpath($docRootRaw);
        if ($docRoot === false) {
            return;
        }
        $docRoot = rtrim($docRoot, '/\\');
        $under = static function (string $path) use ($docRoot): bool {
            $resolved = self::resolveFuturePath($path);
            return $resolved === $docRoot || str_starts_with($resolved, $docRoot . '/');
        };

        $errors = [];
        $firstLine = strtok(ltrim($envContent, "\xEF\xBB\xBF"), "\r\n");
        $selfProtecting = basename($envFile) === '.env.php' && $firstLine === EnvParser::PHP_GUARD_LINE;
        if (!$selfProtecting && $under($envFile)) {
            $errors[] = 'ENV_FILE:in_docroot - The environment file is inside the document root; move it above the docroot or use the self-protecting .env.php form.';
        }
        if ($under($config->app->storageDir)) {
            $errors[] = 'STORAGE_DIR:in_docroot - STORAGE_DIR is inside the document root; point it outside the docroot.';
        }
        if ($errors !== []) {
            throw new ConfigException($errors);
        }
    }

    /**
     * realpath() of the longest existing ancestor plus the remaining (not yet created) segments.
     */
    private static function resolveFuturePath(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return rtrim($real, '/\\');
        }
        $tail = [];
        $probe = $path;
        while ($probe !== '' && realpath($probe) === false) {
            $tail[] = basename($probe);
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }
        $base = realpath($probe);
        $base = $base === false ? $probe : rtrim($base, '/\\');
        return $base . '/' . implode('/', array_reverse($tail));
    }

    // ------------------------------------------------------------------ error handling

    /**
     * @internal
     */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return true;
        }
        self::logLine('warning', 'php_error', [
            'severity' => $severity,
            'message' => substr($message, 0, 300),
            'file' => basename($file),
            'line' => $line,
        ]);
        return true;
    }

    /**
     * @internal
     */
    public static function handleException(\Throwable $e): void
    {
        self::logLine('error', 'uncaught_exception', [
            'class' => get_class($e),
            'message' => substr($e->getMessage(), 0, 300),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]);
        if (!headers_sent()) {
            self::fallbackResponse(500);
        }
    }

    /**
     * @internal
     */
    public static function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err === null || !in_array($err['type'], self::FATAL_TYPES, true)) {
            return;
        }
        if (self::$reserve !== null) {
            // Release the memory reserve so the fatal-error log line can still be built.
            self::$reserve = null;
        }
        self::logLine('error', 'fatal_error', [
            'type' => $err['type'],
            'message' => substr($err['message'], 0, 300),
            'file' => basename($err['file']),
            'line' => $err['line'],
        ]);
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                if (!@ob_end_clean()) {
                    break;
                }
            }
            self::fallbackResponse(500);
        }
    }

    /**
     * @param array<string,scalar|null> $context
     */
    private static function logLine(string $level, string $message, array $context): void
    {
        $context['rid'] = self::$requestId;
        if (self::$logger !== null) {
            self::$logger->log($level, $message, $context);
            return;
        }
        $codes = [];
        foreach ($context as $k => $v) {
            $codes[] = $k . '=' . (is_scalar($v) ? (string) $v : '');
        }
        self::bootLog($message, $codes);
    }

    /**
     * Writes one JSON line to <env dir>/var/logs/bootstrap-<hash>.log (codes only, never values).
     *
     * @param list<string> $codes
     */
    private static function bootLog(string $phase, array $codes): void
    {
        try {
            $envFile = self::$envFile;
            $dir = dirname($envFile) . '/var/logs';
            $real = $envFile !== '' ? realpath($envFile) : false;
            $hash = substr(hash('sha256', $real !== false ? $real : $envFile), 0, 12);
            $line = Json::encode([
                'ts' => gmdate('Y-m-d\TH:i:s\Z'),
                'rid' => self::$requestId,
                'phase' => $phase,
                'codes' => array_map(static fn (string $c): string => substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $c) ?? '', 0, 200), $codes),
            ]) . "\n";
            $ok = false;
            try {
                FileUtil::ensureDir($dir);
                $logFile = $dir . '/bootstrap-' . $hash . '.log';
                $isNew = !file_exists($logFile);
                $ok = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) !== false;
                if ($ok && $isNew) {
                    @chmod($logFile, FileUtil::fileMode());
                }
            } catch (\Throwable) {
                $ok = false;
            }
            if (!$ok) {
                error_log('MirrorGate bootstrap: ' . trim($line));
            }
        } catch (\Throwable) {
            // nothing else can be done
        }
    }

    /**
     * Built-in minimal renderer used before (or without) configuration: static English HTML,
     * fixed text, request id only. Part of Bootstrap, not of the error module.
     */
    private static function fallbackResponse(int $status, bool $headOnly = false): void
    {
        if (headers_sent()) {
            return;
        }
        $title = self::TITLES[$status] ?? ($status >= 500 ? 'Internal Server Error' : 'Bad Request');
        $rid = self::$requestId !== '' ? self::$requestId : bin2hex(random_bytes(8));
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $esc((string) $status) . ' ' . $esc($title) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;margin:12vh auto;max-width:34rem;padding:0 1rem;color:#222}'
            . 'h1{font-size:1.4rem}p{color:#555}code{font-size:.85rem}</style></head><body>'
            . '<h1>' . $esc((string) $status) . ' ' . $esc($title) . '</h1>'
            . '<p>The server could not complete the request.</p>'
            . '<p><code>Request ID: ' . $esc($rid) . '</code></p></body></html>';

        header_remove();
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        header('X-MirrorGate-Request-Id: ' . $rid);
        header('Content-Length: ' . strlen($body));
        if (!$headOnly) {
            echo $body;
        }
    }
}
