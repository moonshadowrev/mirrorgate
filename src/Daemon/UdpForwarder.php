<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Config\Config;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Security\CidrList;

/**
 * UDP forwarder with a per-client session table.
 *
 * UDP source addresses can be spoofed, so a non-loopback listener only serves clients inside
 * DAEMON_UDP_CLIENT_ALLOW, every client is rate limited, and the bytes sent back to a client are capped at
 * DAEMON_UDP_MAX_AMPLIFICATION times the bytes it sent plus a 4 KiB allowance, counted per client address.
 */
final class UdpForwarder implements ForwarderInterface
{
    private const DATAGRAMS_PER_EVENT = 64;
    private const AMPLIFICATION_ALLOWANCE = 4096;

    /** @var resource|null */
    private $server = null;
    private ?EventLoop $loop = null;
    private ?int $sweepTimer = null;
    private readonly CidrList $clientAllow;
    private readonly bool $enforceAllow;
    /** @var array<string,array{sock:resource,ip:string,peer:string,last:float,in:int,out:int}> */
    private array $sessions = [];
    /** @var array<string,int> */
    private array $sessionsPerIp = [];
    /** @var array<string,array{0:int,1:int}> client bucket => [window second, count] */
    private array $rate = [];
    /** @var array<string,array{in:int,out:int,last:float}> client bucket => bytes received from / sent to it */
    private array $budget = [];
    /** @var array<string,int> */
    private array $drops = [];
    private float $lastDropLog = 0.0;

    public function __construct(
        private readonly ListenerSpec $spec,
        private readonly Config $config,
        private readonly LoggerInterface $log,
        private readonly TargetResolver $resolver,
        private readonly ConnectionLimiter $limiter,
    ) {
        $this->clientAllow = new CidrList($config->daemon->udpClientAllow);
        $this->enforceAllow = $config->daemon->udpClientAllow !== [];
        if (!$spec->isLoopbackBind() && !$this->enforceAllow) {
            throw new \InvalidArgumentException('udp listener on a non-loopback address requires DAEMON_UDP_CLIENT_ALLOW');
        }
    }

    public function name(): string
    {
        return $this->spec->id();
    }

    public function start(EventLoop $loop): void
    {
        $this->loop = $loop;
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server('udp://' . $this->spec->bindAddress(), $errno, $errstr, STREAM_SERVER_BIND);
        if (!is_resource($server)) {
            throw new \RuntimeException('cannot bind ' . $this->spec->id() . ' (' . $errno . ')');
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $loop->addReadStream($server, function (): void {
            $this->onClientDatagrams();
        });
        $this->sweepTimer = $loop->addTimer(1.0, function (): void {
            $this->sweep();
        }, true);
    }

    public function stop(): void
    {
        if ($this->loop !== null) {
            if (is_resource($this->server)) {
                $this->loop->removeReadStream($this->server);
            }
            if ($this->sweepTimer !== null) {
                $this->loop->cancelTimer($this->sweepTimer);
                $this->sweepTimer = null;
            }
        }
        foreach (array_keys($this->sessions) as $key) {
            $this->closeSession($key);
        }
        if (is_resource($this->server)) {
            @fclose($this->server);
        }
        $this->server = null;
    }

    public function closeListener(): void
    {
        // UDP has no accept queue to drain; sessions simply expire.
    }

    private function drop(string $reason): void
    {
        $this->drops[$reason] = ($this->drops[$reason] ?? 0) + 1;
    }

    private function onClientDatagrams(): void
    {
        $max = $this->config->daemon->udpMaxDatagram;
        for ($i = 0; $i < self::DATAGRAMS_PER_EVENT; $i++) {
            if (!is_resource($this->server)) {
                return;
            }
            $peer = '';
            $data = @stream_socket_recvfrom($this->server, $max + 1, 0, $peer);
            if ($data === false || ($data === '' && $peer === '')) {
                return;
            }
            $this->handleDatagram($data, (string) $peer, $max);
        }
    }

    private function handleDatagram(string $data, string $peer, int $max): void
    {
        $ip = self::ipFromPeer($peer);
        if ($ip === null) {
            $this->drop('bad_peer');
            return;
        }
        if ($this->enforceAllow && !$this->clientAllow->contains($ip)) {
            $this->drop('client_not_allowed');
            return;
        }
        if (strlen($data) > $max) {
            $this->drop('datagram_too_large');
            return;
        }
        if ($data === '') {
            // An empty datagram carries nothing to forward and must not cost a session and a socket.
            $this->drop('empty_datagram');
            return;
        }
        if (!$this->rateAllows($ip)) {
            $this->drop('rate_limit');
            return;
        }
        $key = $peer;
        $session = $this->sessions[$key] ?? null;
        if ($session === null) {
            $session = $this->openSession($key, $ip, $peer);
            if ($session === null) {
                return;
            }
        }
        $session['last'] = microtime(true);
        $session['in'] += strlen($data);
        $this->sessions[$key] = $session;
        $bucket = ConnectionLimiter::bucket($ip);
        $this->budget[$bucket] ??= ['in' => 0, 'out' => 0, 'last' => 0.0];
        $this->budget[$bucket]['in'] += strlen($data);
        $this->budget[$bucket]['last'] = $session['last'];
        if (@fwrite($session['sock'], $data) === false) {
            $this->drop('target_send_failed');
        }
    }

    /**
     * @return array{sock:resource,ip:string,peer:string,last:float,in:int,out:int}|null
     */
    private function openSession(string $key, string $ip, string $peer): ?array
    {
        $id = $this->spec->id();
        $cap = min($this->spec->maxConn, $this->config->daemon->udpMaxSessions);
        $ipKey = ConnectionLimiter::bucket($ip);
        if (($this->sessionsPerIp[$ipKey] ?? 0) >= $this->spec->maxPerIp) {
            $this->drop('per_ip_sessions');
            return null;
        }
        if ($this->limiter->countForListener($id) >= $cap || !$this->limiter->admit($id, $cap, 1)) {
            $this->drop('session_limit');
            return null;
        }
        try {
            $target = $this->resolver->resolve($this->spec->targetHost, $this->spec->targetPort);
        } catch (\RuntimeException $e) {
            $this->limiter->release($id, null, 1);
            $this->drop('target_unresolved');
            return null;
        }
        $addr = (str_contains($target, ':') ? 'udp://[' . $target . ']:' : 'udp://' . $target . ':') . $this->spec->targetPort;
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($addr, $errno, $errstr, 1.0);
        if (!is_resource($sock) || $this->loop === null) {
            $this->limiter->release($id, null, 1);
            $this->drop('target_connect_failed');
            return null;
        }
        stream_set_blocking($sock, false);
        $this->sessions[$key] = ['sock' => $sock, 'ip' => $ip, 'peer' => $peer, 'last' => microtime(true), 'in' => 0, 'out' => 0];
        $this->sessionsPerIp[$ipKey] = ($this->sessionsPerIp[$ipKey] ?? 0) + 1;
        $this->loop->addReadStream($sock, function () use ($key): void {
            $this->onTargetDatagrams($key);
        });
        return $this->sessions[$key];
    }

    private function onTargetDatagrams(string $key): void
    {
        $max = $this->config->daemon->udpMaxDatagram;
        $ratio = max(1, $this->config->daemon->udpMaxAmplification);
        for ($i = 0; $i < self::DATAGRAMS_PER_EVENT; $i++) {
            $session = $this->sessions[$key] ?? null;
            if ($session === null || !is_resource($this->server)) {
                return;
            }
            $from = '';
            $data = @stream_socket_recvfrom($session['sock'], $max + 1, 0, $from);
            if ($data === false) {
                // ICMP port unreachable and similar errors end the session.
                $this->closeSession($key);
                return;
            }
            if ($data === '' && $from === '') {
                return;
            }
            if (strlen($data) > $max) {
                $this->drop('reply_too_large');
                continue;
            }
            // The reply budget belongs to the client address, not to the source port: a spoofer can pick a
            // fresh port per datagram, but all of them aim at the same victim address.
            $bucket = ConnectionLimiter::bucket($session['ip']);
            $budget = $this->budget[$bucket] ?? ['in' => 0, 'out' => 0, 'last' => 0.0];
            $allowed = $ratio * $budget['in'] + self::AMPLIFICATION_ALLOWANCE;
            if ($budget['out'] + strlen($data) > $allowed) {
                $this->drop('amplification_cap');
                continue;
            }
            $this->budget[$bucket] = ['in' => $budget['in'], 'out' => $budget['out'] + strlen($data), 'last' => microtime(true)];
            $this->sessions[$key]['out'] += strlen($data);
            $this->sessions[$key]['last'] = microtime(true);
            @stream_socket_sendto($this->server, $data, 0, $session['peer']);
        }
    }

    private function rateAllows(string $ip): bool
    {
        $second = time();
        $ip = ConnectionLimiter::bucket($ip);
        $entry = $this->rate[$ip] ?? null;
        if ($entry === null) {
            if (count($this->rate) >= 4096) {
                // Make room instead of refusing: a full table must not lock out every new legitimate source.
                foreach ($this->rate as $known => $row) {
                    if ($row[0] !== $second) {
                        unset($this->rate[$known]);
                    }
                }
                if (count($this->rate) >= 4096) {
                    unset($this->rate[(string) array_key_first($this->rate)]);
                }
            }
            $this->rate[$ip] = [$second, 1];
            return true;
        }
        if ($entry[0] !== $second) {
            $this->rate[$ip] = [$second, 1];
            return true;
        }
        if ($entry[1] >= $this->config->daemon->udpRatePerSec) {
            return false;
        }
        $this->rate[$ip][1] = $entry[1] + 1;
        return true;
    }

    private function sweep(): void
    {
        $now = microtime(true);
        $ttl = $this->spec->idleTimeout;
        foreach ($this->sessions as $key => $session) {
            if ($now - $session['last'] > $ttl) {
                $this->closeSession($key);
            }
        }
        foreach ($this->budget as $bucket => $row) {
            if ($now - $row['last'] > $ttl) {
                unset($this->budget[$bucket]);
            }
        }
        $second = time();
        foreach ($this->rate as $ip => $entry) {
            if ($second - $entry[0] > 2) {
                unset($this->rate[$ip]);
            }
        }
        if ($this->drops !== [] && $now - $this->lastDropLog >= 60.0) {
            $this->log->warning('datagrams dropped', ['listener' => $this->spec->id()] + $this->drops);
            $this->drops = [];
            $this->lastDropLog = $now;
        }
    }

    private function closeSession(string $key): void
    {
        $session = $this->sessions[$key] ?? null;
        if ($session === null) {
            return;
        }
        unset($this->sessions[$key]);
        $this->loop?->removeReadStream($session['sock']);
        @fclose($session['sock']);
        $ipKey = ConnectionLimiter::bucket($session['ip']);
        if (isset($this->sessionsPerIp[$ipKey]) && --$this->sessionsPerIp[$ipKey] <= 0) {
            unset($this->sessionsPerIp[$ipKey]);
        }
        $this->limiter->release($this->spec->id(), null, 1);
    }

    private static function ipFromPeer(string $peer): ?string
    {
        $pos = strrpos($peer, ':');
        if ($pos === false) {
            return null;
        }
        $host = substr($peer, 0, $pos);
        if ($host !== '' && $host[0] === '[') {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return ConnectionLimiter::normalize($host);
    }
}
