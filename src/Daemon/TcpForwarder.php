<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Config\Config;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Security\CidrList;

/**
 * Layer 4 TCP forwarder: accepts a client, connects to the one configured target, and pipes bytes both ways.
 *
 * Also the engine behind GrpcForwarder, which only changes the defaults (ALPN h2, TCP_NODELAY) and wording.
 */
final class TcpForwarder implements ForwarderInterface
{
    private ?Acceptor $acceptor = null;
    private readonly CidrList $trusted;

    public function __construct(
        private readonly ListenerSpec $spec,
        private readonly Config $config,
        private readonly LoggerInterface $log,
        private readonly TargetResolver $resolver,
        private readonly ConnectionLimiter $limiter,
    ) {
        $this->trusted = new CidrList($config->security->trustedProxies);
    }

    public function name(): string
    {
        return $this->spec->id();
    }

    public function start(EventLoop $loop): void
    {
        $this->acceptor = new Acceptor($this->spec, $this->config->daemon, $this->trusted, $loop, $this->limiter, $this->log, false);
        $this->acceptor->open(function (Session $s): void {
            if ($this->waitsForClientFirst()) {
                $this->awaitFirstBytes($s);
                return;
            }
            $this->connect($s, '');
        });
    }

    public function stop(): void
    {
        $this->acceptor?->closeAll();
        $this->acceptor = null;
    }

    public function closeListener(): void
    {
        $this->acceptor?->closeListener();
    }

    /**
     * gRPC always speaks client first, and an outbound TLS handshake is the expensive part of an idle
     * connection. For those the origin connection is only opened once the client has sent something
     * (within DAEMON_HANDSHAKE_TIMEOUT), so idle clients cost a socket here and nothing at the origin.
     * Plain TCP targets are connected at once because many such protocols let the server speak first.
     */
    private function waitsForClientFirst(): bool
    {
        return $this->spec->kind === ListenerKind::Grpc || $this->spec->targetUsesTls();
    }

    private function awaitFirstBytes(Session $s): void
    {
        $s->setClientHandler(function (Session $s): void {
            $data = $s->client()->read(65536);
            if ($data === '') {
                if ($s->client()->eof) {
                    $s->close('client_eof');
                }
                return;
            }
            $s->setClientHandler(null);
            $this->connect($s, $data);
        });
        $s->watchClient();
    }

    private function connect(Session $s, string $firstBytes): void
    {
        $spec = $this->spec;
        $s->setPhase('connecting');
        // Client bytes wait in the kernel buffer until the pipe exists (Session stops watching an unwanted socket).
        try {
            $ip = $this->resolver->resolve($spec->targetHost, $spec->targetPort);
        } catch (\RuntimeException $e) {
            $this->log->warning('forward target unavailable', ['listener' => $spec->id(), 'reason' => $e->getMessage()]);
            $s->close('target_unresolved');
            return;
        }
        $ssl = $spec->targetUsesTls() ? TlsContext::client($spec, $this->config->origin->caBundle) : null;
        $s->connectTarget(
            $ip,
            $spec->targetPort,
            $ssl,
            function () use ($s, $firstBytes): void {
                self::tuneSocket($s->client());
                if ($s->target() !== null) {
                    self::tuneSocket($s->target());
                }
                $s->startPipe($firstBytes);
            },
            function (string $reason) use ($s): void {
                $this->log->info('forward target connect failed', ['listener' => $this->spec->id(), 'reason' => $reason]);
                $s->close($reason);
            },
        );
    }

    /** Disables Nagle where ext-sockets allows it; harmless otherwise. */
    private static function tuneSocket(Connection $c): void
    {
        if (!function_exists('socket_import_stream') || !defined('TCP_NODELAY') || !$c->isOpen()) {
            return;
        }
        $sock = @socket_import_stream($c->stream());
        if ($sock !== false) {
            @socket_set_option($sock, SOL_TCP, TCP_NODELAY, 1);
        }
    }
}
