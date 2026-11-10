<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Config\Config;
use MirrorGate\Log\LoggerInterface;

/**
 * Long-running supervisor: builds the listeners from DAEMON_LISTEN_n, runs the event loop and shuts down
 * gracefully on SIGTERM, SIGINT or the stop file.
 *
 * Exit codes of run(): 0 clean stop, 1 startup or configuration failure, 2 fatal runtime error, 3 memory restart.
 */
final class Daemon
{
    public const EXIT_OK = 0;
    public const EXIT_CONFIG = 1;
    public const EXIT_FATAL = 2;
    public const EXIT_MEMORY = 3;
    public const DRAIN_SECONDS = 10;

    private bool $stopRequested = false;
    private bool $draining = false;
    private float $drainDeadline = 0.0;
    private int $exitCode = self::EXIT_OK;
    private ?ConnectionLimiter $limiter = null;
    /** @var list<ForwarderInterface> */
    private array $forwarders = [];
    /** @var resource|null */
    private $pidHandle = null;

    public function __construct(private readonly Config $config, private readonly LoggerInterface $logger)
    {
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    public function run(): int
    {
        $dc = $this->config->daemon;

        try {
            $specs = $this->parseListeners();
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('invalid daemon configuration', ['reason' => $e->getMessage()]);
            return self::EXIT_CONFIG;
        }
        $problem = $this->validateEnvironment($specs);
        if ($problem !== null) {
            $this->logger->error('daemon cannot start', ['reason' => $problem]);
            return self::EXIT_CONFIG;
        }

        if (function_exists('ini_set') && $dc->memoryLimit !== '') {
            @ini_set('memory_limit', $dc->memoryLimit);
        }
        if (!$this->acquirePidFile()) {
            return self::EXIT_CONFIG;
        }
        @unlink($dc->stopFile);

        $resolver = new TargetResolver($dc, $this->logger);
        foreach (self::uniqueTargets($specs) as [$host, $port]) {
            $result = $resolver->warm($host, $port);
            if ($result === 'blocked' || $result === 'not_allowed') {
                $this->logger->error('forward target is not permitted', ['host' => $host, 'reason' => $result]);
                $this->releasePidFile();
                return self::EXIT_CONFIG;
            }
            if ($result === 'unresolved') {
                $this->logger->warning('forward target did not resolve at startup, will retry', ['host' => $host]);
            }
        }

        $loop = new EventLoop();
        $loop->setErrorHandler(function (\Throwable $e): void {
            $this->logger->error('callback failed', ['type' => $e::class, 'reason' => substr($e->getMessage(), 0, 200)]);
        });
        $limiter = new ConnectionLimiter($dc->maxConnections, $dc->maxConnPerIp, count($specs) + 8);
        $this->limiter = $limiter;
        $limiter->shareAmong(array_map(static fn (ListenerSpec $s): string => $s->id(), $specs));

        foreach ($specs as $spec) {
            try {
                $forwarder = $this->createForwarder($spec, $resolver, $limiter);
                $forwarder->start($loop);
            } catch (\Throwable $e) {
                $this->logger->error('listener failed to start', ['listener' => $spec->id(), 'reason' => $e->getMessage()]);
                $this->stopForwarders();
                $this->releasePidFile();
                return self::EXIT_CONFIG;
            }
            $this->forwarders[] = $forwarder;
            foreach ($spec->warnings() as $warning) {
                $this->logger->warning($warning);
            }
            $this->logger->info('listening', ['listener' => $spec->id(), 'target' => $spec->targetScheme . '://' . $spec->targetHost . ':' . $spec->targetPort]);
        }
        $resolver->setLoopMode(true);
        $this->installSignals();
        $this->installTimers($loop, $resolver);

        $this->logger->info('daemon started', ['pid' => getmypid(), 'listeners' => count($specs)]);
        try {
            $loop->run();
        } catch (\Throwable $e) {
            $this->logger->error('fatal runtime error', ['type' => $e::class, 'reason' => substr($e->getMessage(), 0, 200)]);
            $this->exitCode = self::EXIT_FATAL;
        }

        $this->stopForwarders();
        $this->releasePidFile();
        $this->logger->info('daemon stopped', ['exit' => $this->exitCode]);
        return $this->exitCode;
    }

    /**
     * Static validation for "mirrorgate-daemon check". Does not start listeners.
     *
     * @return list<array{0:string,1:string}> pairs of level (ok|warn|fail) and message
     */
    public function check(): array
    {
        $dc = $this->config->daemon;
        $out = [];
        $out[] = ['ok', 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')'];

        $specs = [];
        if ($dc->listeners === []) {
            $out[] = ['fail', 'no DAEMON_LISTEN_n variables are set'];
        }
        foreach ($dc->listeners as $i => $raw) {
            try {
                $spec = ListenerSpec::parse($raw, $dc);
                $specs[] = $spec;
                $out[] = ['ok', 'listener ' . ($i + 1) . ': ' . $spec->id() . ' -> ' . $spec->targetScheme . '://' . $spec->targetHost . ':' . $spec->targetPort];
                foreach ($spec->warnings() as $warning) {
                    $out[] = ['warn', $warning];
                }
            } catch (\InvalidArgumentException $e) {
                $out[] = ['fail', 'listener ' . ($i + 1) . ': ' . $e->getMessage()];
            }
        }
        $ids = [];
        foreach ($specs as $spec) {
            $key = $spec->kind === ListenerKind::Udp ? 'udp:' . $spec->bindHost . ':' . $spec->bindPort : 'tcp:' . $spec->bindHost . ':' . $spec->bindPort;
            if (isset($ids[$key])) {
                $out[] = ['fail', 'two listeners share ' . $spec->bindHost . ':' . $spec->bindPort];
            }
            $ids[$key] = true;
        }
        $problem = $this->validateEnvironment($specs);
        if ($problem !== null) {
            $out[] = ['fail', $problem];
        }

        if (!function_exists('pcntl_signal')) {
            $out[] = ['warn', 'ext-pcntl is missing: no signal handling and no background mode; stop with the stop file (mirrorgate-daemon stop) or the supervisor'];
        }
        if (!function_exists('posix_kill')) {
            $out[] = ['warn', 'ext-posix is missing: "stop" can only use the stop file, which is polled every tick'];
        }
        if (!function_exists('socket_import_stream')) {
            $out[] = ['warn', 'ext-sockets is missing: TCP_NODELAY cannot be set on forwarded connections'];
        }
        $needed = 2 * $dc->maxConnections + count($specs) + 8;
        if ($needed > ConnectionLimiter::FD_BUDGET) {
            $out[] = ['warn', 'select() cannot watch more than 1024 descriptors; each forwarded connection uses two, so at most about ' . intdiv(ConnectionLimiter::FD_BUDGET - count($specs) - 8, 2) . ' simultaneous connections are accepted regardless of DAEMON_MAX_CONNECTIONS'];
        }
        if (function_exists('posix_getrlimit')) {
            $limits = @posix_getrlimit();
            $soft = is_array($limits) ? ($limits['soft openfiles'] ?? null) : null;
            if (is_int($soft) && $soft < ConnectionLimiter::FD_BUDGET + 24) {
                $out[] = ['warn', 'open file limit is ' . $soft . '; raise it (LimitNOFILE=4096 in systemd) to use the full connection budget'];
            } elseif (is_string($soft) && $soft !== 'unlimited' && (int) $soft < ConnectionLimiter::FD_BUDGET + 24) {
                $out[] = ['warn', 'open file limit is ' . $soft . '; raise it to use the full connection budget'];
            }
        }
        if ($dc->allowPrivateTargets) {
            $out[] = ['warn', 'DAEMON_ALLOW_PRIVATE_TARGETS is on: targets may resolve to private or loopback addresses'];
        }
        if ($dc->wsAllowNoOrigin) {
            $out[] = ['warn', 'DAEMON_WS_ALLOW_NO_ORIGIN is on'];
        }

        $resolver = new TargetResolver($dc, $this->logger);
        foreach (self::uniqueTargets($specs) as [$host, $port]) {
            $result = $resolver->warm($host, $port);
            $out[] = match ($result) {
                null => ['ok', 'target ' . $host . ' resolves to a permitted address'],
                'blocked' => ['fail', 'target ' . $host . ' resolves to a blocked (non-public) address'],
                'not_allowed' => ['fail', 'target ' . $host . ' is not in DAEMON_TARGET_ALLOWLIST'],
                default => ['warn', 'target ' . $host . ' did not resolve right now'],
            };
        }

        if (!self::status($dc)['running']) {
            foreach ($specs as $spec) {
                $out[] = self::probeBind($spec);
            }
        } else {
            $out[] = ['ok', 'daemon is running, bind probe skipped'];
        }
        return $out;
    }

    /**
     * @return array{running:bool,pid:?int}
     */
    public static function status(\MirrorGate\Config\DaemonConfig $dc): array
    {
        if (!is_file($dc->pidFile)) {
            return ['running' => false, 'pid' => null];
        }
        $pidText = @file_get_contents($dc->pidFile, false, null, 0, 32);
        $pid = is_string($pidText) && preg_match('/^[0-9]+/', trim($pidText)) === 1 ? (int) trim($pidText) : null;
        $h = @fopen($dc->pidFile, 'r');
        if ($h === false) {
            return ['running' => false, 'pid' => $pid];
        }
        $free = flock($h, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);
        return ['running' => !$free, 'pid' => $free ? null : $pid];
    }

    /**
     * Asks a running daemon to stop: writes the stop file and, when ext-posix is available, sends SIGTERM.
     *
     * @return array{ok:bool,message:string}
     */
    public static function signalStop(\MirrorGate\Config\DaemonConfig $dc): array
    {
        $st = self::status($dc);
        if (!$st['running']) {
            return ['ok' => false, 'message' => 'daemon is not running'];
        }
        if (@file_put_contents($dc->stopFile, (string) time()) === false) {
            return ['ok' => false, 'message' => 'cannot write the stop file'];
        }
        if ($st['pid'] !== null && $st['pid'] > 1 && function_exists('posix_kill')) {
            @posix_kill($st['pid'], defined('SIGTERM') ? SIGTERM : 15);
            return ['ok' => true, 'message' => 'stop requested (stop file and SIGTERM)'];
        }
        return ['ok' => true, 'message' => 'stop requested (stop file; the daemon polls it every tick)'];
    }

    /**
     * @return list<ListenerSpec>
     * @throws \InvalidArgumentException
     */
    private function parseListeners(): array
    {
        $dc = $this->config->daemon;
        if ($dc->listeners === []) {
            throw new \InvalidArgumentException('no DAEMON_LISTEN_n listener is configured');
        }
        $specs = [];
        $bound = [];
        foreach ($dc->listeners as $i => $raw) {
            try {
                $spec = ListenerSpec::parse($raw, $dc);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('DAEMON_LISTEN entry ' . ($i + 1) . ': ' . $e->getMessage(), 0, $e);
            }
            $key = ($spec->kind === ListenerKind::Udp ? 'udp:' : 'tcp:') . $spec->bindHost . ':' . $spec->bindPort;
            if (isset($bound[$key])) {
                throw new \InvalidArgumentException('two listeners share the bind address ' . $spec->bindHost . ':' . $spec->bindPort);
            }
            $bound[$key] = true;
            $specs[] = $spec;
        }
        return $specs;
    }

    /**
     * @param list<ListenerSpec> $specs
     */
    private function validateEnvironment(array $specs): ?string
    {
        $needsTls = false;
        foreach ($specs as $spec) {
            if ($spec->tlsCert !== null) {
                $needsTls = true;
                if (!is_readable((string) $spec->tlsCert) || !is_readable((string) $spec->tlsKey)) {
                    return $spec->id() . ': tls_cert or tls_key is not readable';
                }
            }
            if ($spec->targetUsesTls()) {
                $needsTls = true;
            }
        }
        if ($needsTls && !extension_loaded('openssl')) {
            return 'ext-openssl is required for TLS listeners and targets';
        }
        if (!function_exists('stream_socket_server') || !function_exists('stream_select')) {
            return 'stream_socket_server and stream_select must not be disabled';
        }
        return null;
    }

    private function createForwarder(ListenerSpec $spec, TargetResolver $resolver, ConnectionLimiter $limiter): ForwarderInterface
    {
        return match ($spec->kind) {
            ListenerKind::Ws => new WebSocketProxy($spec, $this->config, $this->logger, $resolver, $limiter),
            ListenerKind::Tcp => new TcpForwarder($spec, $this->config, $this->logger, $resolver, $limiter),
            ListenerKind::Udp => new UdpForwarder($spec, $this->config, $this->logger, $resolver, $limiter),
            ListenerKind::Grpc => new GrpcForwarder($spec, $this->config, $this->logger, $resolver, $limiter),
        };
    }

    /**
     * @param list<ListenerSpec> $specs
     * @return list<array{0:string,1:int}>
     */
    private static function uniqueTargets(array $specs): array
    {
        $seen = [];
        foreach ($specs as $spec) {
            $seen[$spec->targetHost . ':' . $spec->targetPort] = [$spec->targetHost, $spec->targetPort];
        }
        return array_values($seen);
    }

    /** @return array{0:string,1:string} */
    private static function probeBind(ListenerSpec $spec): array
    {
        $errno = 0;
        $errstr = '';
        $addr = ($spec->kind === ListenerKind::Udp ? 'udp://' : 'tcp://') . $spec->bindAddress();
        $flags = $spec->kind === ListenerKind::Udp ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $s = @stream_socket_server($addr, $errno, $errstr, $flags);
        if (!is_resource($s)) {
            return ['fail', $spec->id() . ': cannot bind (error ' . $errno . ')'];
        }
        fclose($s);
        return ['ok', $spec->id() . ': bind address is available'];
    }

    private function acquirePidFile(): bool
    {
        $file = $this->config->daemon->pidFile;
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $this->logger->error('cannot create the pid directory');
            return false;
        }
        $h = @fopen($file, 'c+');
        if ($h === false) {
            $this->logger->error('cannot open the pid file');
            return false;
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            $this->logger->error('another daemon instance holds the pid file lock');
            return false;
        }
        ftruncate($h, 0);
        fwrite($h, (string) getmypid() . "\n");
        fflush($h);
        @chmod($file, 0640);
        $this->pidHandle = $h;
        return true;
    }

    private function releasePidFile(): void
    {
        if (is_resource($this->pidHandle)) {
            @ftruncate($this->pidHandle, 0);
            flock($this->pidHandle, LOCK_UN);
            fclose($this->pidHandle);
        }
        $this->pidHandle = null;
    }

    private function installSignals(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal') || !defined('SIGTERM')) {
            $this->logger->info('ext-pcntl not available, relying on the stop file');
            return;
        }
        pcntl_async_signals(true);
        $stop = function (): void {
            $this->requestStop();
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        if (defined('SIGHUP')) {
            // Log files are opened per write, so rotation needs no reopen; just acknowledge.
            pcntl_signal(SIGHUP, function (): void {
                $this->logger->info('SIGHUP received, log files are reopened on every write');
            });
        }
        if (defined('SIGPIPE')) {
            pcntl_signal(SIGPIPE, SIG_IGN);
        }
    }

    private function installTimers(EventLoop $loop, TargetResolver $resolver): void
    {
        $dc = $this->config->daemon;
        $lastGc = microtime(true);
        $lastMemory = $lastGc;
        $lastDns = $lastGc;
        $memoryLimit = self::parseBytes($dc->memoryLimit);

        $loop->addTimer(0.25, function () use ($loop, $resolver, &$lastGc, &$lastMemory, &$lastDns, $dc, $memoryLimit): void {
            $now = microtime(true);
            if (!$this->stopRequested && is_file($dc->stopFile)) {
                @unlink($dc->stopFile);
                $this->logger->info('stop file found');
                $this->stopRequested = true;
            }
            if ($this->stopRequested && !$this->draining) {
                $this->beginDrain($now);
            }
            if ($this->draining && ($this->limiter === null || $this->limiter->total() === 0 || $now >= $this->drainDeadline)) {
                $loop->stop();
                return;
            }
            if ($now - $lastDns >= 5.0) {
                $lastDns = $now;
                $resolver->refreshRound();
            }
            if ($now - $lastGc >= 30.0) {
                $lastGc = $now;
                gc_collect_cycles();
            }
            if ($now - $lastMemory >= 5.0) {
                $lastMemory = $now;
                if ($memoryLimit > 0 && memory_get_usage(true) > (int) ($memoryLimit * 0.9) && !$this->draining) {
                    $this->logger->warning('memory use above 90 percent of the limit, restarting', ['bytes' => memory_get_usage(true)]);
                    $this->exitCode = self::EXIT_MEMORY;
                    $this->stopRequested = true;
                }
            }
        }, true);
    }

    private function beginDrain(float $now): void
    {
        $this->draining = true;
        $this->drainDeadline = $now + self::DRAIN_SECONDS;
        $this->limiter?->setDraining(true);
        $this->logger->info('draining connections', ['seconds' => self::DRAIN_SECONDS]);
        foreach ($this->forwarders as $forwarder) {
            if ($forwarder instanceof UdpForwarder) {
                $forwarder->stop();
            } elseif ($forwarder instanceof WebSocketProxy || $forwarder instanceof TcpForwarder || $forwarder instanceof GrpcForwarder) {
                $forwarder->closeListener();
            }
        }
    }

    private function stopForwarders(): void
    {
        foreach ($this->forwarders as $forwarder) {
            try {
                $forwarder->stop();
            } catch (\Throwable) {
                // Shutting down; nothing useful left to do with the error.
            }
        }
        $this->forwarders = [];
    }

    private static function parseBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1' || preg_match('/^([0-9]+)([KMG]?)$/i', $v, $m) !== 1) {
            return 0;
        }
        $n = (int) $m[1];
        return match (strtoupper($m[2])) {
            'K' => $n * 1024,
            'M' => $n * 1024 * 1024,
            'G' => $n * 1024 * 1024 * 1024,
            default => $n,
        };
    }
}
