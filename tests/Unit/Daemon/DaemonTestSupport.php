<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Daemon;

use MirrorGate\Config\DaemonConfig;

final class DaemonTestSupport
{
    /** @param array<string,mixed> $over */
    public static function config(array $over = []): DaemonConfig
    {
        return new DaemonConfig(...array_merge([
            'listeners' => [], 'allowPublicBind' => false, 'maxConnections' => 800, 'maxConnPerIp' => 50,
            'idleTimeout' => 120, 'handshakeTimeout' => 10, 'maxHeaderBytes' => 16384, 'bufferHighWater' => 262144,
            'udpSessionTtl' => 60, 'udpMaxSessions' => 1024, 'udpMaxDatagram' => 65507, 'udpRatePerSec' => 200,
            'wsMaxLifetime' => 0, 'wsOriginAllow' => ['https://mirror.example'],
            'targetAllowlist' => ['origin.example', '127.0.0.1'], 'allowPrivateTargets' => false, 'targetDnsTtl' => 60,
            'pidFile' => '/nonexistent/pid', 'logFile' => '/nonexistent/log', 'stopFile' => '/nonexistent/stop',
            'memoryLimit' => '256M', 'wsAllowNoOrigin' => false, 'udpClientAllow' => [], 'udpMaxAmplification' => 10,
        ], $over));
    }
}
