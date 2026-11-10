<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Config\DaemonConfig;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Security\CidrList;

/**
 * Shared listening-socket logic for the stream based forwarders (ws, tcp, grpc): bind, accept, admission control,
 * PROXY protocol, listener side TLS, session registry and timeout sweeping.
 */
final class Acceptor
{
    private const ACCEPTS_PER_EVENT = 32;

    /** @var resource|null */
    private $server = null;
    /** @var array<int,Session> */
    private array $sessions = [];
    private int $nextId = 1;
    private ?int $sweepTimer = null;
    private ?\Closure $onReady = null;
    /** @var array<string,int> */
    private array $drops = [];
    private float $lastDropLog = 0.0;
    private readonly CidrList $clientAllow;

    public function __construct(
        private readonly ListenerSpec $spec,
        private readonly DaemonConfig $daemon,
        private readonly CidrList $trustedProxies,
        private readonly EventLoop $loop,
        private readonly ConnectionLimiter $limiter,
        private readonly LoggerInterface $log,
        /** True for ws listeners: the client address comes from a forwarding header sent by a trusted proxy. */
        private readonly bool $deferIpForTrustedPeers,
    ) {
        $this->clientAllow = new CidrList($daemon->tcpClientAllow);
    }

    /**
     * Binds the listener. $onReady receives each session once PROXY and TLS preliminaries are done.
     *
     * @param callable(Session):void $onReady
     * @throws \RuntimeException
     */
    public function open(callable $onReady): void
    {
        $this->onReady = \Closure::fromCallable($onReady);
        $ssl = $this->spec->tlsCert !== null ? TlsContext::server($this->spec) : [];
        $ctx = stream_context_create($ssl);
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(
            'tcp://' . $this->spec->bindAddress(),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $ctx,
        );
        if (!is_resource($server)) {
            throw new \RuntimeException('cannot bind ' . $this->spec->id() . ' (' . $errno . ')');
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $this->loop->addReadStream($server, function (): void {
            $this->acceptBatch();
        });
        $this->sweepTimer = $this->loop->addTimer(1.0, function (): void {
            $this->sweep();
        }, true);
    }

    /** Stops accepting. Existing sessions continue. */
    public function closeListener(): void
    {
        if (is_resource($this->server)) {
            $this->loop->removeReadStream($this->server);
            @fclose($this->server);
        }
        $this->server = null;
    }

    public function closeAll(): void
    {
        $this->closeListener();
        if ($this->sweepTimer !== null) {
            $this->loop->cancelTimer($this->sweepTimer);
            $this->sweepTimer = null;
        }
        foreach ($this->sessions as $session) {
            $session->close('shutdown');
        }
        $this->sessions = [];
    }

    public function sessionCount(): int
    {
        return count($this->sessions);
    }

    private function drop(string $reason): void
    {
        $this->drops[$reason] = ($this->drops[$reason] ?? 0) + 1;
    }

    private function acceptBatch(): void
    {
        $server = $this->server;
        if (!is_resource($server)) {
            return;
        }
        for ($i = 0; $i < self::ACCEPTS_PER_EVENT; $i++) {
            $peerName = '';
            $stream = @stream_socket_accept($server, 0, $peerName);
            if (!is_resource($stream)) {
                return;
            }
            $this->handleAccepted($stream, (string) $peerName);
        }
    }

    /** @param resource $stream */
    private function handleAccepted($stream, string $peerName): void
    {
        $peerIp = self::ipFromPeerName($peerName);
        if ($peerIp === null) {
            @fclose($stream);
            $this->drop('bad_peer');
            return;
        }
        if (($this->spec->kind === ListenerKind::Tcp || $this->spec->kind === ListenerKind::Grpc)
            && !$this->clientAllow->isEmpty() && !$this->clientAllow->contains($peerIp)
        ) {
            @fclose($stream);
            $this->drop('client_not_allowed');
            return;
        }
        $trusted = $this->trustedProxies->contains($peerIp);
        if ($this->spec->proxyProtocol && !$trusted) {
            @fclose($stream);
            $this->drop('proxy_protocol_untrusted_peer');
            return;
        }
        if (!$this->limiter->admit($this->spec->id(), $this->spec->maxConn)) {
            @fclose($stream);
            $this->drop('connection_limit');
            return;
        }

        $session = new Session(
            $this->nextId++,
            $this->loop,
            new Connection($stream, false),
            $this->spec->id(),
            $peerIp,
            $this->limiter,
            $this->log,
            $this->spec->idleTimeout,
            max(4096, $this->daemon->bufferHighWater),
            $this->spec->kind === ListenerKind::Ws ? $this->daemon->wsMaxLifetime : 0,
            function (Session $s, string $reason): void {
                unset($this->sessions[$s->id]);
                $this->log->debug('session closed', ['listener' => $s->listenerId, 'peer' => $s->peerIp, 'reason' => $reason]);
            },
        );
        $this->sessions[$session->id] = $session;

        $deferred = $this->spec->proxyProtocol || ($this->deferIpForTrustedPeers && $trusted);
        if (!$deferred && !$session->claimIp($peerIp, $this->spec->maxPerIp)) {
            $this->drop('per_ip_limit');
            $session->close('per_ip_limit');
            return;
        }

        if ($this->spec->proxyProtocol) {
            $this->readProxyHeader($session);
            return;
        }
        $this->afterProxy($session);
    }

    private function readProxyHeader(Session $session): void
    {
        $lastLen = -1;
        $session->setClientHandler(function (Session $s) use (&$lastLen): void {
            $stream = $s->client()->stream();
            $peek = @stream_socket_recvfrom($stream, ProxyProtocol::MAX_LENGTH + 1, STREAM_PEEK);
            if ($peek === false || $peek === '') {
                if (@feof($stream)) {
                    $s->close('proxy_header_eof');
                }
                return;
            }
            $pos = strpos($peek, "\r\n");
            if ($pos === false) {
                if (strlen($peek) >= ProxyProtocol::MAX_LENGTH) {
                    $this->drop('proxy_header_invalid');
                    $s->close('proxy_header_invalid');
                } elseif (strlen($peek) === $lastLen) {
                    $s->snoozeClient(0.05);
                }
                $lastLen = strlen($peek);
                return;
            }
            if ($pos + 2 > ProxyProtocol::MAX_LENGTH) {
                $this->drop('proxy_header_invalid');
                $s->close('proxy_header_invalid');
                return;
            }
            // Consume exactly the header with an unbuffered read: fread() would pull following bytes (a TLS
            // ClientHello) into PHP's userland buffer where stream_socket_enable_crypto() cannot see them.
            @stream_socket_recvfrom($stream, $pos + 2);
            try {
                $client = ProxyProtocol::parseV1(substr($peek, 0, $pos));
            } catch (\InvalidArgumentException) {
                $this->drop('proxy_header_invalid');
                $s->close('proxy_header_invalid');
                return;
            }
            $s->setClientHandler(null);
            if (!$s->claimIp($client ?? $s->peerIp, $this->spec->maxPerIp)) {
                $this->drop('per_ip_limit');
                $s->close('per_ip_limit');
                return;
            }
            $this->afterProxy($s);
        });
        $session->watchClient();
    }

    private function afterProxy(Session $session): void
    {
        if ($this->spec->tlsCert !== null) {
            $session->tlsAccept(TlsContext::server($this->spec), function () use ($session): void {
                $this->ready($session);
            });
            return;
        }
        $this->ready($session);
    }

    private function ready(Session $session): void
    {
        $session->setPhase('handshake');
        if ($this->onReady !== null) {
            ($this->onReady)($session);
        }
    }

    private function sweep(): void
    {
        $now = microtime(true);
        foreach ($this->sessions as $session) {
            $session->checkTimeouts($now, $this->daemon->handshakeTimeout);
        }
        if ($this->drops !== [] && $now - $this->lastDropLog >= 60.0) {
            $this->log->warning('connections refused', ['listener' => $this->spec->id()] + $this->drops);
            $this->drops = [];
            $this->lastDropLog = $now;
        }
    }

    private static function ipFromPeerName(string $peerName): ?string
    {
        $pos = strrpos($peerName, ':');
        if ($pos === false) {
            return null;
        }
        $host = substr($peerName, 0, $pos);
        if ($host !== '' && $host[0] === '[') {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return ConnectionLimiter::normalize($host);
    }
}
