<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Config\DaemonConfig;

final readonly class ListenerSpec
{
    private const KIND_SCHEMES = [
        'ws' => ['ws', 'wss'],
        'tcp' => ['tcp', 'tls'],
        'udp' => ['udp'],
        'grpc' => ['h2c', 'tls'],
    ];

    private const OPTIONS = [
        'idle', 'max_conn', 'max_per_ip', 'tls_cert', 'tls_key', 'alpn', 'sni', 'host',
        'origin_allow', 'allow_no_origin', 'proxy_protocol',
    ];

    /**
     * @param list<string> $alpn
     * @param list<string> $originAllow
     */
    public function __construct(
        public ListenerKind $kind,
        public string $bindHost,
        public int $bindPort,
        public string $targetScheme,       // ws|wss|tcp|tls|udp|h2c
        public string $targetHost,
        public int $targetPort,
        public int $idleTimeout,
        public int $maxConn,
        public int $maxPerIp,
        public ?string $tlsCert,
        public ?string $tlsKey,
        public array $alpn,
        public ?string $sni,
        public ?string $hostOverride,
        public array $originAllow,
        public bool $allowNoOrigin = false,
        public bool $proxyProtocol = false,
    ) {
    }

    /**
     * Parses one DAEMON_LISTEN_n value.
     *
     * Error messages are safe to print: they never repeat the raw value.
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $raw, DaemonConfig $defaults): self
    {
        if ($raw === '' || strlen($raw) > 2048) {
            throw new \InvalidArgumentException('listener definition is empty or too long');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $raw) === 1) {
            throw new \InvalidArgumentException('listener definition contains whitespace or control characters');
        }
        $sep = strpos($raw, '->');
        if ($sep === false) {
            throw new \InvalidArgumentException('listener definition needs a "->" between bind and target');
        }
        $left = substr($raw, 0, $sep);
        $right = substr($raw, $sep + 2);

        if (preg_match('#^(ws|tcp|udp|grpc)://(\[[0-9A-Fa-f:.]+\]|[0-9.]+):([0-9]{1,5})$#', $left, $m) !== 1) {
            throw new \InvalidArgumentException('bind side must look like kind://ip:port with kind ws, tcp, udp or grpc');
        }
        $kind = ListenerKind::from($m[1]);
        $bindHost = self::stripBrackets($m[2]);
        $bindPort = (int) $m[3];
        if (filter_var($bindHost, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('bind host must be an IP literal');
        }
        $bindHost = self::normalizeIp($bindHost);
        if ($bindPort < 1 || $bindPort > 65535) {
            throw new \InvalidArgumentException('bind port must be between 1 and 65535');
        }
        $loopback = self::isLoopback($bindHost);
        if (!$loopback && !$defaults->allowPublicBind) {
            throw new \InvalidArgumentException('binding a non-loopback address requires DAEMON_ALLOW_PUBLIC_BIND=true');
        }

        if (preg_match('#^(ws|wss|tcp|tls|udp|h2c)://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+):([0-9]{1,5})(?:\?(.*))?$#', $right, $t) !== 1) {
            throw new \InvalidArgumentException('target side must look like scheme://host:port with scheme ws, wss, tcp, tls, udp or h2c');
        }
        $scheme = $t[1];
        if (!in_array($scheme, self::KIND_SCHEMES[$kind->value], true)) {
            throw new \InvalidArgumentException('target scheme is not valid for this listener kind');
        }
        $targetPort = (int) $t[3];
        if ($targetPort < 1 || $targetPort > 65535) {
            throw new \InvalidArgumentException('target port must be between 1 and 65535');
        }
        $targetHost = self::normalizeTargetHost($t[2]);
        if (!self::hostAllowed($targetHost, $defaults->targetAllowlist)) {
            throw new \InvalidArgumentException('target host is not in DAEMON_TARGET_ALLOWLIST');
        }

        $opts = self::parseOptions($t[4] ?? '');

        $idle = $kind === ListenerKind::Udp ? $defaults->udpSessionTtl : $defaults->idleTimeout;
        $maxConn = $kind === ListenerKind::Udp ? min(1000, $defaults->udpMaxSessions) : $defaults->maxConnections;
        $maxPerIp = $defaults->maxConnPerIp;
        $tlsCert = null;
        $tlsKey = null;
        $alpn = $kind === ListenerKind::Grpc ? ['h2'] : [];
        $sni = null;
        $hostOverride = null;
        $originAllow = array_values(array_unique(array_map('strtolower', $defaults->wsOriginAllow)));
        $allowNoOrigin = $defaults->wsAllowNoOrigin;
        $proxyProtocol = false;
        $alpnGiven = false;

        foreach ($opts as $key => $value) {
            switch ($key) {
                case 'idle':
                    $idle = self::intOption($key, $value, 1, 86400);
                    break;
                case 'max_conn':
                    $maxConn = self::intOption($key, $value, 1, 1000);
                    break;
                case 'max_per_ip':
                    $maxPerIp = self::intOption($key, $value, 1, 1000);
                    break;
                case 'tls_cert':
                    $tlsCert = self::pathOption($key, $value);
                    break;
                case 'tls_key':
                    $tlsKey = self::pathOption($key, $value);
                    break;
                case 'alpn':
                    $alpn = self::alpnOption($value);
                    $alpnGiven = true;
                    break;
                case 'sni':
                    $sni = self::hostOption($key, $value, false);
                    break;
                case 'host':
                    if ($kind !== ListenerKind::Ws) {
                        throw new \InvalidArgumentException('option host is only valid for ws listeners');
                    }
                    $hostOverride = self::hostOption($key, $value, true);
                    break;
                case 'origin_allow':
                    if ($kind !== ListenerKind::Ws) {
                        throw new \InvalidArgumentException('option origin_allow is only valid for ws listeners');
                    }
                    $originAllow = self::originListOption($value);
                    break;
                case 'allow_no_origin':
                    if ($kind !== ListenerKind::Ws) {
                        throw new \InvalidArgumentException('option allow_no_origin is only valid for ws listeners');
                    }
                    $allowNoOrigin = self::boolOption($key, $value);
                    break;
                case 'proxy_protocol':
                    if ($kind !== ListenerKind::Tcp && $kind !== ListenerKind::Grpc) {
                        throw new \InvalidArgumentException('option proxy_protocol is only valid for tcp and grpc listeners');
                    }
                    $proxyProtocol = self::boolOption($key, $value);
                    break;
            }
        }

        if (($tlsCert === null) !== ($tlsKey === null)) {
            throw new \InvalidArgumentException('tls_cert and tls_key must be given together');
        }
        if ($tlsCert !== null && $kind === ListenerKind::Udp) {
            throw new \InvalidArgumentException('TLS termination is not available for udp listeners');
        }
        if ($sni !== null && !in_array($scheme, ['wss', 'tls'], true)) {
            throw new \InvalidArgumentException('option sni needs a TLS target (wss or tls)');
        }
        if ($alpnGiven && $tlsCert === null && !in_array($scheme, ['wss', 'tls'], true)) {
            throw new \InvalidArgumentException('option alpn needs tls_cert or a TLS target');
        }
        if ($kind === ListenerKind::Ws && $originAllow === [] && !$allowNoOrigin) {
            throw new \InvalidArgumentException('ws listener needs a non-empty origin allowlist or allow_no_origin=1');
        }
        if ($kind === ListenerKind::Udp && !$loopback && $defaults->udpClientAllow === []) {
            throw new \InvalidArgumentException('udp listener on a non-loopback address requires DAEMON_UDP_CLIENT_ALLOW');
        }

        return new self(
            $kind, $bindHost, $bindPort, $scheme, $targetHost, $targetPort,
            $idle, $maxConn, $maxPerIp, $tlsCert, $tlsKey, $alpn, $sni, $hostOverride,
            $kind === ListenerKind::Ws ? $originAllow : [],
            $kind === ListenerKind::Ws ? $allowNoOrigin : false,
            $proxyProtocol,
        );
    }

    /** Stable identifier such as "ws@127.0.0.1:8090". */
    public function id(): string
    {
        $host = str_contains($this->bindHost, ':') ? '[' . $this->bindHost . ']' : $this->bindHost;
        return $this->kind->value . '@' . $host . ':' . $this->bindPort;
    }

    /** Socket address string for stream_socket_server(), without scheme. */
    public function bindAddress(): string
    {
        $host = str_contains($this->bindHost, ':') ? '[' . $this->bindHost . ']' : $this->bindHost;
        return $host . ':' . $this->bindPort;
    }

    public function isLoopbackBind(): bool
    {
        return self::isLoopback($this->bindHost);
    }

    public function targetUsesTls(): bool
    {
        return $this->targetScheme === 'wss' || $this->targetScheme === 'tls';
    }

    /** @return list<string> non-fatal observations for the check command and startup logs */
    public function warnings(): array
    {
        $w = [];
        if ($this->bindPort < 1024) {
            $w[] = $this->id() . ': port below 1024 usually needs elevated privileges';
        }
        if (!$this->isLoopbackBind()) {
            $w[] = $this->id() . ': listener is reachable from the network; put a firewall or reverse proxy in front';
        }
        if ($this->kind === ListenerKind::Ws && $this->allowNoOrigin) {
            $w[] = $this->id() . ': allow_no_origin is on, non-browser clients can connect without an Origin header';
        }
        if ($this->tlsCert === null && !$this->isLoopbackBind() && $this->kind !== ListenerKind::Udp) {
            $w[] = $this->id() . ': public listener without TLS termination';
        }
        return $w;
    }

    private static function stripBrackets(string $h): string
    {
        return $h !== '' && $h[0] === '[' ? substr($h, 1, -1) : $h;
    }

    private static function normalizeIp(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return strtolower($ip);
        }
        $out = inet_ntop($packed);
        return $out === false ? strtolower($ip) : strtolower($out);
    }

    private static function isLoopback(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return ord($packed[0]) === 127;
        }
        return $packed === inet_pton('::1');
    }

    /** @throws \InvalidArgumentException */
    private static function normalizeTargetHost(string $host): string
    {
        $host = strtolower($host);
        if ($host !== '' && $host[0] === '[') {
            $inner = substr($host, 1, -1);
            if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('target host is not a valid IPv6 literal');
            }
            return self::normalizeIp($inner);
        }
        if (preg_match('/^[0-9.]+$/', $host) === 1 || preg_match('/^0x/i', $host) === 1) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new \InvalidArgumentException('target host is an ambiguous numeric address');
            }
            return $host;
        }
        $host = rtrim($host, '.');
        if ($host === '' || strlen($host) > 253) {
            throw new \InvalidArgumentException('target host is empty or too long');
        }
        foreach (explode('.', $host) as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                throw new \InvalidArgumentException('target host contains an invalid label');
            }
        }
        return $host;
    }

    /** @param list<string> $allowlist */
    private static function hostAllowed(string $host, array $allowlist): bool
    {
        foreach ($allowlist as $entry) {
            $entry = strtolower(rtrim(trim($entry), '.'));
            if ($entry !== '' && $entry[0] === '[') {
                $entry = substr($entry, 1, -1);
            }
            if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
                $entry = self::normalizeIp($entry);
            }
            if ($entry === $host) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string,string>
     * @throws \InvalidArgumentException
     */
    private static function parseOptions(string $query): array
    {
        $out = [];
        if ($query === '') {
            return $out;
        }
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $key = $eq === false ? $pair : substr($pair, 0, $eq);
            $value = $eq === false ? '' : rawurldecode(substr($pair, $eq + 1));
            if (!in_array($key, self::OPTIONS, true)) {
                throw new \InvalidArgumentException('unknown listener option');
            }
            if (isset($out[$key])) {
                throw new \InvalidArgumentException('listener option given twice');
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new \InvalidArgumentException('listener option contains control characters');
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /** @throws \InvalidArgumentException */
    private static function intOption(string $name, string $v, int $min, int $max): int
    {
        if (preg_match('/^[0-9]{1,9}$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
            throw new \InvalidArgumentException('option ' . $name . ' must be an integer between ' . $min . ' and ' . $max);
        }
        return (int) $v;
    }

    /** @throws \InvalidArgumentException */
    private static function boolOption(string $name, string $v): bool
    {
        if ($v === '1') {
            return true;
        }
        if ($v === '0') {
            return false;
        }
        throw new \InvalidArgumentException('option ' . $name . ' must be 0 or 1');
    }

    /** @throws \InvalidArgumentException */
    private static function pathOption(string $name, string $v): string
    {
        if ($v === '' || strlen($v) > 1024) {
            throw new \InvalidArgumentException('option ' . $name . ' needs a file path');
        }
        return $v;
    }

    /**
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private static function alpnOption(string $v): array
    {
        $out = [];
        foreach (explode(',', $v) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('#^[A-Za-z0-9./+-]{1,32}$#', $item) !== 1) {
                throw new \InvalidArgumentException('option alpn contains an invalid protocol name');
            }
            $out[] = $item;
        }
        if ($out === [] || count($out) > 8) {
            throw new \InvalidArgumentException('option alpn needs between 1 and 8 protocol names');
        }
        return $out;
    }

    /** @throws \InvalidArgumentException */
    private static function hostOption(string $name, string $v, bool $allowPort): string
    {
        $pattern = $allowPort ? '/^[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?$/' : '/^[A-Za-z0-9.-]{1,253}$/';
        if (preg_match($pattern, $v) !== 1) {
            throw new \InvalidArgumentException('option ' . $name . ' is not a valid host name');
        }
        return strtolower($v);
    }

    /**
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private static function originListOption(string $v): array
    {
        $out = [];
        foreach (explode(',', $v) as $item) {
            $item = strtolower(trim($item));
            if ($item === '') {
                continue;
            }
            if (preg_match('#^https?://[a-z0-9.-]{1,253}(:[0-9]{1,5})?$#', $item) !== 1) {
                throw new \InvalidArgumentException('option origin_allow needs exact scheme://host[:port] origins');
            }
            $out[] = $item;
        }
        return array_values(array_unique($out));
    }
}
