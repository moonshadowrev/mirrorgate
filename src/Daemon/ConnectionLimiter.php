<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

/**
 * Process-wide connection accounting: total connections, descriptor budget, per listener and per client IP.
 */
final class ConnectionLimiter
{
    /** stream_select() cannot watch descriptors numbered FD_SETSIZE (1024) or higher; keep headroom. */
    public const FD_BUDGET = 1000;

    private int $total = 0;
    private int $fds;
    private bool $draining = false;
    /** @var array<string,int> */
    private array $perListener = [];
    /** @var array<string,int> */
    private array $perIp = [];
    /** @var array<string,int> */
    private array $perListenerIp = [];
    /** @var array<string,int> guaranteed share of the global cap per listener id */
    private array $guarantee = [];

    /**
     * @param int $maxConnections DAEMON_MAX_CONNECTIONS (capped to 1000)
     * @param int $maxPerIp       DAEMON_MAX_CONN_PER_IP across all listeners
     * @param int $reservedFds    descriptors already used by listening sockets, log and pid files
     */
    public function __construct(
        private readonly int $maxConnections,
        private readonly int $maxPerIp,
        int $reservedFds = 0,
    ) {
        $this->fds = max(0, $reservedFds);
    }

    /**
     * Declares every listener that draws from the shared pool (TCP, WebSocket, gRPC and UDP sessions alike).
     * Each one is guaranteed half of an equal share of the global cap; beyond that it competes for the shared pool, but
     * only while the capacity that other listeners are still entitled to stays free. One flooded listener therefore cannot
     * starve the rest.
     *
     * @param list<string> $listenerIds
     */
    public function shareAmong(array $listenerIds): void
    {
        $ids = array_values(array_unique($listenerIds));
        $this->guarantee = [];
        if (count($ids) < 2) {
            return;
        }
        // Half of an equal share is reserved per listener; the other half of the cap stays one shared pool.
        $share = max(1, intdiv(min($this->maxConnections, 1000), 2 * count($ids)));
        foreach ($ids as $id) {
            $this->guarantee[$id] = $share;
        }
    }

    public function setDraining(bool $draining): void
    {
        $this->draining = $draining;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    /** Reserves a slot for a new connection. No per-IP accounting yet, see claimIp(). */
    public function admit(string $listenerId, int $listenerMax, int $fdCost = 2): bool
    {
        if ($this->draining) {
            return false;
        }
        if ($this->total >= min($this->maxConnections, 1000)) {
            return false;
        }
        if ($this->fds + $fdCost > self::FD_BUDGET) {
            return false;
        }
        if (($this->perListener[$listenerId] ?? 0) >= $listenerMax) {
            return false;
        }
        if (!$this->withinFairShare($listenerId)) {
            return false;
        }
        $this->total++;
        $this->fds += $fdCost;
        $this->perListener[$listenerId] = ($this->perListener[$listenerId] ?? 0) + 1;
        return true;
    }

    /** True unless borrowing beyond the guaranteed share would eat into what other listeners are entitled to. */
    private function withinFairShare(string $listenerId): bool
    {
        if ($this->guarantee === [] || !isset($this->guarantee[$listenerId])) {
            return true;
        }
        if (($this->perListener[$listenerId] ?? 0) < $this->guarantee[$listenerId]) {
            return true;
        }
        $reserved = 0;
        foreach ($this->guarantee as $id => $share) {
            if ($id !== $listenerId) {
                $reserved += max(0, $share - ($this->perListener[$id] ?? 0));
            }
        }

        return min($this->maxConnections, 1000) - $this->total > $reserved;
    }

    /** Charges an admitted connection to a client address. Returns false when a per-IP cap would be exceeded. */
    public function claimIp(string $listenerId, string $ip, int $listenerPerIpMax): bool
    {
        $ip = self::bucket($ip);
        $key = $listenerId . '|' . $ip;
        if (($this->perIp[$ip] ?? 0) >= $this->maxPerIp) {
            return false;
        }
        if (($this->perListenerIp[$key] ?? 0) >= $listenerPerIpMax) {
            return false;
        }
        $this->perIp[$ip] = ($this->perIp[$ip] ?? 0) + 1;
        $this->perListenerIp[$key] = ($this->perListenerIp[$key] ?? 0) + 1;
        return true;
    }

    /** Undoes claimIp() for $ip (used when a derived client address replaces an earlier one). */
    public function releaseIp(string $listenerId, string $ip): void
    {
        $ip = self::bucket($ip);
        $key = $listenerId . '|' . $ip;
        if (isset($this->perIp[$ip]) && --$this->perIp[$ip] <= 0) {
            unset($this->perIp[$ip]);
        }
        if (isset($this->perListenerIp[$key]) && --$this->perListenerIp[$key] <= 0) {
            unset($this->perListenerIp[$key]);
        }
    }

    /** Frees a slot taken with admit(); $ip is the address passed to claimIp() or null when none was claimed. */
    public function release(string $listenerId, ?string $ip, int $fdCost = 2): void
    {
        if ($ip !== null) {
            $this->releaseIp($listenerId, $ip);
        }
        if ($this->total > 0) {
            $this->total--;
        }
        $this->fds = max(0, $this->fds - $fdCost);
        if (isset($this->perListener[$listenerId]) && --$this->perListener[$listenerId] <= 0) {
            unset($this->perListener[$listenerId]);
        }
    }

    public function total(): int
    {
        return $this->total;
    }

    public function fdsInUse(): int
    {
        return $this->fds;
    }

    public function countForListener(string $listenerId): int
    {
        return $this->perListener[$listenerId] ?? 0;
    }

    public function countForIp(string $ip): int
    {
        return $this->perIp[self::bucket($ip)] ?? 0;
    }

    /**
     * Accounting key of a client address: IPv4 as is, IPv6 reduced to its /64, because a single subscriber
     * routinely controls a whole /64 (or more) and per-address limits would never trigger.
     */
    public static function bucket(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return strtolower($ip);
        }
        if (strlen($packed) === 16 && str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $packed = substr($packed, 12);
        }
        if (strlen($packed) === 16) {
            $out = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));

            return ($out === false ? strtolower($ip) : $out) . '/64';
        }
        $out = inet_ntop($packed);

        return $out === false ? strtolower($ip) : $out;
    }

    public static function normalize(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return strtolower($ip);
        }
        if (strlen($packed) === 16 && str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $packed = substr($packed, 12);
        }
        $out = inet_ntop($packed);
        return $out === false ? strtolower($ip) : $out;
    }
}
