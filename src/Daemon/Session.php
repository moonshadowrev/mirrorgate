<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Log\LoggerInterface;

/**
 * One proxied connection: a client socket, an optional target socket, and the byte pipe between them.
 *
 * Phases: preface (before anything is read), tls (client handshake), handshake (protocol specific),
 * connecting, upgrading, pipe. Only the pipe phase is subject to the idle timeout; every earlier phase
 * must finish within the handshake timeout.
 */
final class Session
{
    private const READ_CHUNK = 65536;
    private const MAX_READS_PER_EVENT = 16;

    public string $phase = 'preface';
    public ?string $claimedIp = null;

    private ?Connection $target = null;
    private readonly float $createdAt;
    private float $lastActivity;
    private bool $closed = false;
    /** @var (callable(Session):void)|null */
    private $clientHandler = null;
    /** @var (callable(Session):void)|null */
    private $targetHandler = null;
    private readonly \Closure $onClose;

    /**
     * @param callable(Session,string):void $onClose
     */
    public function __construct(
        public readonly int $id,
        private readonly EventLoop $loop,
        private readonly Connection $client,
        public readonly string $listenerId,
        public readonly string $peerIp,
        private readonly ConnectionLimiter $limiter,
        private readonly LoggerInterface $log,
        private readonly int $idleTimeout,
        private readonly int $highWater,
        private readonly int $maxLifetime,
        callable $onClose,
        private readonly int $fdCost = 2,
    ) {
        $this->onClose = \Closure::fromCallable($onClose);
        $this->createdAt = microtime(true);
        $this->lastActivity = $this->createdAt;
    }

    public function client(): Connection
    {
        return $this->client;
    }

    public function target(): ?Connection
    {
        return $this->target;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function setPhase(string $phase): void
    {
        $this->phase = $phase;
    }

    /** @param (callable(Session):void)|null $handler */
    public function setClientHandler(?callable $handler): void
    {
        $this->clientHandler = $handler;
    }

    /** @param (callable(Session):void)|null $handler */
    public function setTargetHandler(?callable $handler): void
    {
        $this->targetHandler = $handler;
    }

    public function touch(): void
    {
        $this->lastActivity = microtime(true);
    }

    /**
     * Charges this session to a client address. Replaces a previous claim.
     */
    public function claimIp(string $ip, int $perIpMax): bool
    {
        $previous = $this->claimedIp;
        if ($previous !== null) {
            $this->limiter->releaseIp($this->listenerId, $previous);
            $this->claimedIp = null;
        }
        if (!$this->limiter->claimIp($this->listenerId, $ip, $perIpMax)) {
            return false;
        }
        $this->claimedIp = $ip;
        return true;
    }

    /** Starts watching the client socket for readability. */
    public function watchClient(): void
    {
        $this->loop->addReadStream($this->client->stream(), function (): void {
            $this->readable($this->client);
        });
    }

    public function watchTarget(): void
    {
        if ($this->target === null) {
            return;
        }
        $target = $this->target;
        $this->loop->addReadStream($target->stream(), function () use ($target): void {
            $this->readable($target);
        });
    }

    /**
     * Pauses the client read watcher for $seconds (used to avoid spinning on a half-received preface).
     */
    public function snoozeClient(float $seconds): void
    {
        $this->loop->removeReadStream($this->client->stream());
        $this->loop->addTimer($seconds, function (): void {
            if (!$this->closed && $this->client->isOpen()) {
                $this->watchClient();
            }
        });
    }

    /**
     * Performs the server side TLS handshake on the client socket without blocking.
     *
     * @param array{ssl:array<string,mixed>} $options
     * @param callable():void $done
     */
    public function tlsAccept(array $options, callable $done): void
    {
        $this->phase = 'tls';
        $stream = $this->client->stream();
        @stream_context_set_option($stream, $options);
        $attempt = function () use ($stream, $done): void {
            if ($this->closed) {
                return;
            }
            $r = @stream_socket_enable_crypto($stream, true, TlsContext::serverMethod());
            if ($r === true) {
                $this->client->markTls();
                $this->clientHandler = null;
                $done();
            } elseif ($r === false) {
                $this->close('client_tls_failed');
            }
        };
        $this->clientHandler = static function () use ($attempt): void {
            $attempt();
        };
        $this->watchClient();
    }

    /**
     * Opens a non-blocking connection to $ip:$port, optionally upgrading it to verified TLS.
     *
     * @param array{ssl:array<string,mixed>}|null $sslContext null for a plain TCP target
     * @param callable():void $ready
     * @param callable(string):void $fail receives a short reason code
     */
    public function connectTarget(string $ip, int $port, ?array $sslContext, callable $ready, callable $fail): void
    {
        $this->phase = 'connecting';
        $addr = (str_contains($ip, ':') ? 'tcp://[' . $ip . ']:' : 'tcp://' . $ip . ':') . $port;
        $ctx = stream_context_create($sslContext ?? []);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($addr, $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $ctx);
        if (!is_resource($stream)) {
            $fail('connect_error');
            return;
        }
        $conn = new Connection($stream, false);
        $this->target = $conn;
        $this->loop->addWriteStream($stream, function () use ($conn, $stream, $sslContext, $ready, $fail): void {
            $this->loop->removeWriteStream($stream);
            if ($this->closed) {
                return;
            }
            if (@stream_socket_get_name($stream, true) === false) {
                $fail('connect_refused');
                return;
            }
            if ($sslContext === null) {
                $ready();
                return;
            }
            $this->negotiateClientTls($conn, $ready, $fail);
        });
    }

    /**
     * Queues bytes for the target and tries to send them immediately.
     */
    public function queueToTarget(string $data): void
    {
        if ($this->target !== null && $data !== '') {
            $this->target->queue($data);
            $this->flush($this->target, false);
        }
    }

    public function queueToClient(string $data): void
    {
        if ($data !== '') {
            $this->client->queue($data);
            $this->flush($this->client, false);
        }
    }

    /**
     * Switches to opaque piping. Optional initial bytes are delivered first.
     */
    public function startPipe(string $initialToTarget = '', string $initialToClient = ''): void
    {
        if ($this->closed || $this->target === null) {
            return;
        }
        $this->phase = 'pipe';
        $this->clientHandler = null;
        $this->targetHandler = null;
        $this->touch();
        $this->watchClient();
        $this->watchTarget();
        $this->queueToTarget($initialToTarget);
        $this->queueToClient($initialToClient);
        $target = $this->target;
        if (!$this->closed) {
            // The origin may already have sent bytes that the TLS layer holds.
            if ($target !== null && $target->hasPending()) {
                $this->pump($target);
            }
            if (!$this->closed && $this->client->hasPending()) {
                $this->pump($this->client);
            }
        }
    }

    /**
     * Sends a final best-effort payload to the client and closes the session.
     */
    public function sendAndClose(string $payload, string $reason): void
    {
        if (!$this->closed && $payload !== '' && $this->client->isOpen()) {
            @fwrite($this->client->stream(), $payload);
        }
        $this->close($reason);
    }

    public function checkTimeouts(float $now, int $handshakeTimeout): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->phase !== 'pipe') {
            if ($now - $this->createdAt > $handshakeTimeout) {
                $this->close('handshake_timeout');
            }
            return;
        }
        if ($now - $this->lastActivity > $this->idleTimeout) {
            $this->close('idle_timeout');
            return;
        }
        if ($this->maxLifetime > 0 && $now - $this->createdAt > $this->maxLifetime) {
            $this->close('max_lifetime');
        }
    }

    public function close(string $reason): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->clientHandler = null;
        $this->targetHandler = null;
        foreach ([$this->client, $this->target] as $conn) {
            if ($conn !== null && $conn->isOpen()) {
                $this->loop->removeReadStream($conn->stream());
                $this->loop->removeWriteStream($conn->stream());
                $conn->close();
            }
        }
        $this->limiter->release($this->listenerId, $this->claimedIp, $this->fdCost);
        $this->claimedIp = null;
        ($this->onClose)($this, $reason);
    }

    /**
     * @param callable():void $ready
     * @param callable(string):void $fail
     */
    private function negotiateClientTls(Connection $conn, callable $ready, callable $fail): void
    {
        $stream = $conn->stream();
        $attempt = function () use ($conn, $stream, $ready, $fail): void {
            if ($this->closed) {
                return;
            }
            $r = @stream_socket_enable_crypto($stream, true, TlsContext::clientMethod());
            if ($r === true) {
                $conn->markTls();
                $this->loop->removeReadStream($stream);
                $ready();
            } elseif ($r === false) {
                $last = error_get_last();
                $detail = is_array($last) ? preg_replace('/[^\x20-\x7e]/', '?', substr((string) $last['message'], 0, 200)) : '';
                $this->log->warning('target TLS handshake failed', ['listener' => $this->listenerId, 'detail' => $detail]);
                $fail('target_tls_failed');
            }
        };
        $this->loop->addReadStream($stream, static function () use ($attempt): void {
            $attempt();
        });
        $attempt();
    }

    private function readable(Connection $c): void
    {
        if ($this->closed) {
            return;
        }
        $handler = $c === $this->client ? $this->clientHandler : $this->targetHandler;
        if ($handler !== null) {
            $handler($this);
            return;
        }
        if ($this->phase === 'pipe') {
            $this->pump($c);
            return;
        }
        // Data arrived while nobody wants it; stop watching to avoid a busy loop.
        $this->loop->removeReadStream($c->stream());
    }

    private function other(Connection $c): ?Connection
    {
        return $c === $this->client ? $this->target : $this->client;
    }

    private function pump(Connection $from): void
    {
        $to = $this->other($from);
        if ($to === null || $from->readClosed || $this->closed) {
            return;
        }
        for ($i = 0; $i < self::MAX_READS_PER_EVENT; $i++) {
            if ($to->bufferedBytes() >= $this->highWater) {
                $this->pause($from);
                return;
            }
            $data = $from->read(self::READ_CHUNK);
            if ($data === '') {
                break;
            }
            $this->lastActivity = microtime(true);
            $to->queue($data);
            if (!$this->flush($to, false)) {
                return;
            }
        }
        if ($from->eof) {
            $this->onEof($from);
            return;
        }
        if ($from->hasPending()) {
            // select() cannot see bytes buffered inside the TLS layer; come back for them.
            $this->loop->addTimer(0.0, function () use ($from): void {
                if (!$this->closed && !$from->paused) {
                    $this->pump($from);
                }
            });
        }
    }

    private function pause(Connection $c): void
    {
        if (!$c->paused) {
            $c->paused = true;
            $this->loop->removeReadStream($c->stream());
        }
    }

    private function resume(Connection $c): void
    {
        if ($c->paused && !$c->readClosed && $c->isOpen()) {
            $c->paused = false;
            $stream = $c->stream();
            $this->loop->addReadStream($stream, function () use ($c): void {
                $this->readable($c);
            });
            $this->pump($c);
        }
    }

    private function onEof(Connection $from): void
    {
        $from->readClosed = true;
        if ($from->isOpen()) {
            $this->loop->removeReadStream($from->stream());
        }
        $this->settle();
    }

    /**
     * Propagates end-of-stream: once one side stopped sending and its bytes are flushed, half-close the other
     * side (plain sockets only; a TLS leg cannot be half-closed), and close the session when both sides are done.
     */
    private function settle(): void
    {
        $target = $this->target;
        if ($target === null || $this->closed) {
            return;
        }
        foreach ([[$this->client, $target], [$target, $this->client]] as [$source, $sink]) {
            if ($source->readClosed && !$sink->writeShutdown && $sink->bufferedBytes() === 0 && !$sink->isTls() && $sink->isOpen()) {
                @stream_socket_shutdown($sink->stream(), STREAM_SHUT_WR);
                $sink->writeShutdown = true;
            }
        }
        if ($this->client->readClosed && $target->readClosed
            && $this->client->bufferedBytes() === 0 && $target->bufferedBytes() === 0) {
            $this->close('eof');
        }
    }

    /**
     * @return bool false when the session was closed because of a write failure
     */
    private function flush(Connection $c, bool $writableEvent): bool
    {
        $written = $c->flush($writableEvent);
        if ($written === false) {
            $this->close('write_failed');
            return false;
        }
        if ($written > 0) {
            $this->lastActivity = microtime(true);
        }
        $stream = $c->stream();
        if ($c->bufferedBytes() > 0) {
            if (!$c->writeWatched) {
                $c->writeWatched = true;
                $this->loop->addWriteStream($stream, function () use ($c): void {
                    if (!$this->closed) {
                        $this->flush($c, true);
                    }
                });
            }
        } elseif ($c->writeWatched) {
            $c->writeWatched = false;
            $this->loop->removeWriteStream($stream);
        }
        if ($this->closed) {
            return false;
        }
        $source = $this->other($c);
        if ($source !== null && $source->paused && $c->bufferedBytes() < intdiv($this->highWater, 2)) {
            $this->resume($source);
        }
        if ($this->closed) {
            return false;
        }
        if ($c->bufferedBytes() === 0) {
            $this->settle();
        }
        return !$this->closed;
    }
}
