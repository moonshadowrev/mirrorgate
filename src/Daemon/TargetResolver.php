<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Config\DaemonConfig;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Security\AddressFloor;
use MirrorGate\Security\IpClassifier;

/**
 * Resolves forwarding targets while enforcing the allowlist and the SSRF rules.
 *
 * Only hosts named in DAEMON_TARGET_ALLOWLIST can ever be connected to (no open relay). Every resolved address
 * must be globally routable unless DAEMON_ALLOW_PRIVATE_TARGETS is on. If any answer for a host is blocked the
 * whole host is refused, so a mixed public/private answer set cannot be used to rebind a connection.
 *
 * DNS lookups block the process (PHP has no asynchronous resolver). The daemon therefore warms the cache before it
 * starts serving and, once the loop runs (setLoopMode(true)), resolve() answers only from the cache and refreshRound()
 * renews entries in a timer. To bound the stall during a resolver outage, refreshRound() performs at most one lookup
 * per round and backs a failing host off exponentially instead of retrying it on every tick.
 */
final class TargetResolver
{
    private const STALE_FACTOR = 10;

    /** @var array<string,true> */
    private array $allow = [];
    /** @var array<string,array{ips:list<string>,expires:int,next:int,blocked:bool}> */
    private array $cache = [];
    /** @var array<string,true> */
    private array $known = [];
    private bool $loopMode = false;
    /** @var array<string,array{until:int,fails:int}> hosts whose last lookup failed */
    private array $retry = [];

    public function __construct(private readonly DaemonConfig $config, private readonly LoggerInterface $log)
    {
        foreach ($config->targetAllowlist as $entry) {
            $n = self::normalizeHost($entry);
            if ($n !== '') {
                $this->allow[$n] = true;
            }
        }
    }

    public function setLoopMode(bool $loopMode): void
    {
        $this->loopMode = $loopMode;
    }

    public function isAllowed(string $host): bool
    {
        return isset($this->allow[self::normalizeHost($host)]);
    }

    /**
     * Returns a validated IP address for an allowlisted target.
     *
     * @throws \RuntimeException when the host is not allowlisted, is blocked, or cannot be resolved
     */
    public function resolve(string $host, int $port): string
    {
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('target port out of range');
        }
        $host = self::normalizeHost($host);
        if (!isset($this->allow[$host])) {
            throw new \RuntimeException('target host is not allowlisted');
        }
        $this->known[$host] = true;
        $now = time();
        $entry = $this->cache[$host] ?? null;

        if ($entry !== null && $entry['expires'] > $now) {
            return $this->pick($host);
        }
        if ($entry !== null && $this->loopMode && !$entry['blocked']
            && $entry['expires'] + $this->config->targetDnsTtl * self::STALE_FACTOR > $now) {
            // Stale but usable; the refresh timer will renew it without blocking this request.
            return $this->pick($host);
        }
        if ($this->loopMode) {
            throw new \RuntimeException('target is not resolved yet');
        }
        $this->lookup($host);
        return $this->pick($host);
    }

    /**
     * Blocking lookup used at startup and by the refresh timer.
     *
     * @return string|null null on success, otherwise 'not_allowed', 'blocked' or 'unresolved'
     */
    public function warm(string $host, int $port): ?string
    {
        $norm = self::normalizeHost($host);
        if (!isset($this->allow[$norm])) {
            return 'not_allowed';
        }
        $this->known[$norm] = true;
        try {
            $this->lookup($norm);
        } catch (\RuntimeException $e) {
            return str_contains($e->getMessage(), 'blocked') ? 'blocked' : 'unresolved';
        }
        return null;
    }

    /** Refreshes every known host whose cache entry is past half of its lifetime. Blocks briefly per host. */
    public function refreshRound(): void
    {
        $now = time();
        $halfLife = max(1, intdiv($this->config->targetDnsTtl, 2));
        foreach (array_keys($this->known) as $host) {
            $entry = $this->cache[$host] ?? null;
            if ($entry !== null && $entry['expires'] - $now > $this->config->targetDnsTtl - $halfLife) {
                continue;
            }
            if (($this->retry[$host]['until'] ?? 0) > $now) {
                continue;
            }
            try {
                $this->lookup($host);
                unset($this->retry[$host]);
            } catch (\RuntimeException $e) {
                $fails = ($this->retry[$host]['fails'] ?? 0) + 1;
                $this->retry[$host] = ['until' => $now + min(300, 5 * (2 ** min($fails, 6))), 'fails' => $fails];
                $this->log->warning('target refresh failed', ['host' => $host, 'reason' => $e->getMessage()]);
            }
            // One potentially blocking lookup per round; the next round continues with the other hosts.
            break;
        }
    }

    /** @throws \RuntimeException */
    private function lookup(string $host): void
    {
        $ips = self::literal($host) ?? $this->dnsLookup($host);
        if ($ips === []) {
            throw new \RuntimeException('target did not resolve');
        }
        foreach ($ips as $ip) {
            if (AddressFloor::isBlocked($ip)) {
                $this->cache[$host] = [
                    'ips' => [], 'expires' => time() + $this->config->targetDnsTtl, 'next' => 0, 'blocked' => true,
                ];
                $this->log->warning('target resolves to an always-blocked address', ['host' => $host]);
                throw new \RuntimeException('target resolves to a blocked address');
            }
        }
        if (!$this->config->allowPrivateTargets) {
            foreach ($ips as $ip) {
                if (!IpClassifier::isPublic($ip)) {
                    $this->cache[$host] = [
                        'ips' => [], 'expires' => time() + $this->config->targetDnsTtl, 'next' => 0, 'blocked' => true,
                    ];
                    $this->log->warning('target resolves to a non-public address and is blocked', ['host' => $host]);
                    throw new \RuntimeException('target resolves to a blocked address');
                }
            }
        }
        $this->cache[$host] = [
            'ips' => $ips,
            'expires' => time() + max(1, $this->config->targetDnsTtl),
            'next' => 0,
            'blocked' => false,
        ];
    }

    /** @throws \RuntimeException */
    private function pick(string $host): string
    {
        $entry = $this->cache[$host] ?? null;
        if ($entry === null || $entry['blocked'] || $entry['ips'] === []) {
            throw new \RuntimeException('target resolves to a blocked address');
        }
        $ip = $entry['ips'][$entry['next'] % count($entry['ips'])];
        $this->cache[$host]['next'] = $entry['next'] + 1;
        return $ip;
    }

    /** @return list<string>|null the address when $host is a strict IP literal */
    private static function literal(string $host): ?array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        return null;
    }

    /** @return list<string> IPv4 answers; IPv6 answers only when there is no A record */
    private function dnsLookup(string $host): array
    {
        $ips = [];
        if (function_exists('dns_get_record')) {
            $a = @dns_get_record($host, DNS_A);
            if (is_array($a)) {
                foreach ($a as $rec) {
                    if (isset($rec['ip']) && is_string($rec['ip']) && filter_var($rec['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        $ips[] = $rec['ip'];
                    }
                }
            }
            if ($ips === []) {
                $aaaa = @dns_get_record($host, DNS_AAAA);
                if (is_array($aaaa)) {
                    foreach ($aaaa as $rec) {
                        if (isset($rec['ipv6']) && is_string($rec['ipv6']) && filter_var($rec['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                            $ips[] = $rec['ipv6'];
                        }
                    }
                }
            }
        }
        if ($ips === []) {
            $list = @gethostbynamel($host);
            if (is_array($list)) {
                foreach ($list as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        $ips[] = $ip;
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host !== '' && $host[0] === '[') {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $packed = @inet_pton($host);
            $norm = $packed === false ? false : inet_ntop($packed);
            return $norm === false ? $host : strtolower($norm);
        }
        return rtrim($host, '.');
    }
}
