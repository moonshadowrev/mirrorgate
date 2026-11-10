<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Config\Config;
use MirrorGate\Log\LoggerInterface;

/**
 * gRPC forwarder: an opaque layer 4 pipe for HTTP/2 traffic.
 *
 * Limits (also documented in docs/daemon.md): HTTP/2 frames are never parsed, so there is no per-RPC routing,
 * no header rewriting and no host or path validation. The listener is plain h2c unless tls_cert/tls_key are set,
 * in which case it terminates TLS with ALPN h2. A TLS target is dialled with ALPN h2 and a verified certificate.
 * Long-lived streams stay open while HTTP/2 frames (including PING) flow; the idle timeout closes silent ones.
 * HTTP/1.1 Upgrade: h2c is never honoured; this listener expects prior-knowledge h2c or TLS.
 */
final class GrpcForwarder implements ForwarderInterface
{
    private readonly TcpForwarder $inner;

    public function __construct(
        private readonly ListenerSpec $spec,
        Config $config,
        LoggerInterface $log,
        TargetResolver $resolver,
        ConnectionLimiter $limiter,
    ) {
        if ($spec->kind !== ListenerKind::Grpc) {
            throw new \InvalidArgumentException('GrpcForwarder needs a grpc listener');
        }
        $this->inner = new TcpForwarder($spec, $config, $log, $resolver, $limiter);
    }

    public function name(): string
    {
        return $this->spec->id();
    }

    public function start(EventLoop $loop): void
    {
        $this->inner->start($loop);
    }

    public function stop(): void
    {
        $this->inner->stop();
    }

    public function closeListener(): void
    {
        $this->inner->closeListener();
    }
}
