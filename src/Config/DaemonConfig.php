<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class DaemonConfig
{
    public function __construct(
        /** @var list<string> raw listener strings, order of DAEMON_LISTEN_n */ public array $listeners,
        public bool $allowPublicBind,
        public int $maxConnections,
        public int $maxConnPerIp,
        public int $idleTimeout,
        public int $handshakeTimeout,
        public int $maxHeaderBytes,
        public int $bufferHighWater,
        public int $udpSessionTtl,
        public int $udpMaxSessions,
        public int $udpMaxDatagram,
        public int $udpRatePerSec,
        public int $wsMaxLifetime,
        /** @var list<string> */ public array $wsOriginAllow,      // origins, e.g. "https://ormantek.com"
        /** @var list<string> */ public array $targetAllowlist,    // hosts
        public bool $allowPrivateTargets,
        public int $targetDnsTtl,
        public string $pidFile,
        public string $logFile,
        public string $stopFile,
        public string $memoryLimit,
        public bool $wsAllowNoOrigin = false,                       // DAEMON_WS_ALLOW_NO_ORIGIN
        /** @var list<string> CIDRs */ public array $udpClientAllow = [],   // DAEMON_UDP_CLIENT_ALLOW
        public int $udpMaxAmplification = 2,                        // DAEMON_UDP_MAX_AMPLIFICATION
        /** @var list<string> CIDRs */ public array $tcpClientAllow = [],   // DAEMON_TCP_CLIENT_ALLOW (tcp and grpc listeners)
    ) {
    }
}
