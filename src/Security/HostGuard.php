<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Config\MirrorConfig;
use MirrorGate\Http\HttpException;
use MirrorGate\Http\Request;
use MirrorGate\Support\Hostname;

/**
 * Host header validation against MIRROR_URL and MIRROR_ALIASES. The Host header is never used to
 * build URLs; it is only checked here. The response never echoes the offending value.
 */
final class HostGuard
{
    /** @var array<string,true> */
    private array $allowed = [];

    private int $mirrorPort;

    public function __construct(MirrorConfig $mirror)
    {
        $this->mirrorPort = $mirror->port;
        foreach ($mirror->allowedHosts as $host) {
            try {
                $this->allowed[Hostname::normalize($host)] = true;
            } catch (\InvalidArgumentException) {
                // Configuration validation rejects these; skipping can only narrow the set.
            }
        }
    }

    /** @throws HttpException 421 when the Host header is missing, malformed or not allowed */
    public function assertAllowed(Request $request): void
    {
        $raw = $request->hostHeader;
        if ($raw === null || $raw === '' || strlen($raw) > 300) {
            throw new HttpException(421, 'Misdirected request');
        }
        try {
            $parts = Hostname::split($raw);
            $host = Hostname::normalize($parts['host']);
        } catch (\Throwable) {
            throw new HttpException(421, 'Misdirected request');
        }
        if (!isset($this->allowed[$host])) {
            throw new HttpException(421, 'Misdirected request');
        }
        // Only no port, a default port or the configured MIRROR_URL port is a legitimate way to address this mirror.
        $port = $parts['port'];
        if ($port !== null && $port !== 80 && $port !== 443 && $port !== $this->mirrorPort) {
            throw new HttpException(421, 'Misdirected request');
        }
    }
}
