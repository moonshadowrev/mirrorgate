#!/usr/bin/env php
<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * Self-test for the MirrorGate daemon (module F).
 *
 *   php tools/daemon-selftest.php             unit checks, then an end-to-end run against a real daemon process
 *   php tools/daemon-selftest.php --unit      unit checks only (no sockets except a local socket pair)
 *
 * The end-to-end part starts bin/mirrorgate-daemon with a temporary env file and local echo servers on 127.0.0.1
 * (TCP echo, UDP echo, a UDP amplifier, a WebSocket origin). It never contacts the network and removes its
 * temporary directory when done. Exit code 0 means every check passed.
 */

use MirrorGate\Config\DaemonConfig;
use MirrorGate\Daemon\ConnectionLimiter;
use MirrorGate\Daemon\EventLoop;
use MirrorGate\Daemon\ListenerKind;
use MirrorGate\Daemon\ListenerSpec;
use MirrorGate\Daemon\ProxyProtocol;
use MirrorGate\Daemon\TargetResolver;
use MirrorGate\Daemon\WsHandshake;
use MirrorGate\Log\NullLogger;

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

final class SelfTest
{
    public int $passed = 0;
    public int $failed = 0;

    public function check(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            fwrite(STDOUT, "ok   - $name\n");
        } else {
            $this->failed++;
            fwrite(STDOUT, "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n");
        }
    }

    public function throws(string $name, callable $fn, ?string $class = null): void
    {
        try {
            $fn();
            $this->check($name, false, 'no exception');
        } catch (\Throwable $e) {
            $this->check($name, $class === null || $e instanceof $class, $e::class);
        }
    }
}

function selftest_defaults(array $over = []): DaemonConfig
{
    $a = array_merge([
        'listeners' => [], 'allowPublicBind' => false, 'maxConnections' => 800, 'maxConnPerIp' => 50,
        'idleTimeout' => 120, 'handshakeTimeout' => 10, 'maxHeaderBytes' => 16384, 'bufferHighWater' => 262144,
        'udpSessionTtl' => 60, 'udpMaxSessions' => 1024, 'udpMaxDatagram' => 65507, 'udpRatePerSec' => 200,
        'wsMaxLifetime' => 0, 'wsOriginAllow' => ['https://mirror.example'], 'targetAllowlist' => ['origin.example', '127.0.0.1'],
        'allowPrivateTargets' => false, 'targetDnsTtl' => 60, 'pidFile' => '/nonexistent/pid', 'logFile' => '/nonexistent/log',
        'stopFile' => '/nonexistent/stop', 'memoryLimit' => '256M', 'wsAllowNoOrigin' => false, 'udpClientAllow' => [],
        'udpMaxAmplification' => 10,
    ], $over);
    return new DaemonConfig(...$a);
}

// ---------------------------------------------------------------------------------------------------------------
// Part 1: unit checks
// ---------------------------------------------------------------------------------------------------------------

function selftest_unit(SelfTest $t): void
{
    $d = selftest_defaults();

    // Listener grammar
    $ws = ListenerSpec::parse('ws://127.0.0.1:8090->wss://origin.example:443?sni=origin.example&host=origin.example', $d);
    $t->check('ws listener parses', $ws->kind === ListenerKind::Ws && $ws->targetPort === 443 && $ws->targetScheme === 'wss' && $ws->hostOverride === 'origin.example');
    $t->check('ws listener inherits origin allowlist', $ws->originAllow === ['https://mirror.example'] && $ws->allowNoOrigin === false);
    $tcp = ListenerSpec::parse('tcp://127.0.0.1:9000->tls://origin.example:9443?idle=30&max_conn=5&max_per_ip=2', $d);
    $t->check('tcp listener options', $tcp->idleTimeout === 30 && $tcp->maxConn === 5 && $tcp->maxPerIp === 2 && $tcp->targetUsesTls());
    $grpc = ListenerSpec::parse('grpc://[::1]:50051->h2c://127.0.0.1:50052', $d);
    $t->check('grpc listener defaults to ALPN h2', $grpc->alpn === ['h2'] && $grpc->bindHost === '::1');
    $udp = ListenerSpec::parse('udp://127.0.0.1:5353->udp://origin.example:53', $d);
    $t->check('udp listener uses the session TTL as idle timeout', $udp->idleTimeout === 60);
    $t->check('listener id', $ws->id() === 'ws@127.0.0.1:8090');

    $bad = [
        'target not allowlisted (open relay)' => 'tcp://127.0.0.1:9000->tcp://evil.example:80',
        'unknown option' => 'tcp://127.0.0.1:9000->tcp://origin.example:80?bogus=1',
        'duplicate option' => 'tcp://127.0.0.1:9000->tcp://origin.example:80?idle=5&idle=6',
        'public bind without opt-in' => 'tcp://0.0.0.0:9000->tcp://origin.example:80',
        'udp public bind without client allowlist' => 'udp://0.0.0.0:9000->udp://origin.example:53',
        'scheme does not match kind' => 'tcp://127.0.0.1:9000->ws://origin.example:80',
        'tls_cert without tls_key' => 'tcp://127.0.0.1:9000->tcp://origin.example:80?tls_cert=/a.pem',
        'ws-only option on tcp' => 'tcp://127.0.0.1:9000->tcp://origin.example:80?origin_allow=https://a.example',
        'decimal IP target' => 'tcp://127.0.0.1:9000->tcp://2130706433:80',
        'hex IP target' => 'tcp://127.0.0.1:9000->tcp://0x7f.1:80',
        'userinfo in target' => 'tcp://127.0.0.1:9000->tcp://a@origin.example:80',
        'whitespace' => 'tcp://127.0.0.1:9000 ->tcp://origin.example:80',
        'port zero' => 'tcp://127.0.0.1:0->tcp://origin.example:80',
        'port too large' => 'tcp://127.0.0.1:9000->tcp://origin.example:70000',
        'hostname bind' => 'tcp://localhost:9000->tcp://origin.example:80',
        'missing arrow' => 'tcp://127.0.0.1:9000',
        'sni without TLS target' => 'tcp://127.0.0.1:9000->tcp://origin.example:80?sni=origin.example',
        'proxy_protocol on ws' => 'ws://127.0.0.1:9000->ws://origin.example:80?proxy_protocol=1',
    ];
    foreach ($bad as $name => $raw) {
        $t->throws('rejects: ' . $name, static fn () => ListenerSpec::parse($raw, $d), \InvalidArgumentException::class);
    }
    $noOrigins = selftest_defaults(['wsOriginAllow' => []]);
    $t->throws('ws listener without any origin policy is rejected', static fn () => ListenerSpec::parse('ws://127.0.0.1:9000->ws://origin.example:80', $noOrigins), \InvalidArgumentException::class);
    $t->check('ws allow_no_origin option', ListenerSpec::parse('ws://127.0.0.1:9000->ws://origin.example:80?allow_no_origin=1', $noOrigins)->allowNoOrigin);
    $public = selftest_defaults(['allowPublicBind' => true, 'udpClientAllow' => ['203.0.113.0/24']]);
    $t->check('public bind with opt-in', ListenerSpec::parse('udp://0.0.0.0:9000->udp://origin.example:53', $public)->bindHost === '0.0.0.0');
    $t->check('warnings for public plain listener', ListenerSpec::parse('tcp://0.0.0.0:80->tcp://origin.example:80', $public)->warnings() !== []);

    // WebSocket handshake parsing
    $t->check('RFC 6455 accept example', WsHandshake::acceptFor('dGhlIHNhbXBsZSBub25jZQ==') === 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=');
    $t->check('valid key accepted', WsHandshake::isValidKey('dGhlIHNhbXBsZSBub25jZQ=='));
    $t->check('short key rejected', !WsHandshake::isValidKey('abc='));
    $ok = WsHandshake::parseRequest("GET /chat?x=1 HTTP/1.1\r\nHost: a.example\r\nUpgrade: websocket\r\nX-A:  v  ");
    $t->check('request parsed', $ok['target'] === '/chat?x=1' && $ok['headers']['x-a'] === ['v'] && $ok['headers']['host'] === ['a.example']);
    $badReq = [
        'bare LF' => "GET / HTTP/1.1\r\nHost: a\nX: y",
        'bare CR' => "GET / HTTP/1.1\r\nHost: a\rX: y",
        'NUL byte' => "GET / HTTP/1.1\r\nHost: a\0b",
        'obs-fold' => "GET / HTTP/1.1\r\nHost: a\r\n folded: x",
        'absolute-form target' => "GET http://evil.example/ HTTP/1.1\r\nHost: a",
        'POST method' => "POST / HTTP/1.1\r\nHost: a",
        'HTTP/1.0' => "GET / HTTP/1.0\r\nHost: a",
        'space in header name' => "GET / HTTP/1.1\r\nHo st: a",
        'name with trailing space' => "GET / HTTP/1.1\r\nHost : a",
        'control char in value' => "GET / HTTP/1.1\r\nHost: a\x01b",
        'backslash in target' => "GET /a\\b HTTP/1.1\r\nHost: a",
    ];
    foreach ($badReq as $name => $block) {
        $t->throws('handshake rejects: ' . $name, static fn () => WsHandshake::parseRequest($block), \InvalidArgumentException::class);
    }
    $t->check('mg_ cookies are dropped', WsHandshake::filterCookies('a=1; mg_session=zzz; MG_x=2; b=3') === 'a=1; b=3');
    $trusted = static fn (string $ip): bool => str_starts_with($ip, '10.');
    $t->check('XFF: rightmost untrusted wins', WsHandshake::deriveClientIp('10.0.0.1', ['1.2.3.4, 5.6.7.8, 10.0.0.2'], 'x-forwarded-for', $trusted) === '5.6.7.8');
    $t->check('XFF: spoofed leftmost ignored', WsHandshake::deriveClientIp('10.0.0.1', ['9.9.9.9', '5.6.7.8'], 'x-forwarded-for', $trusted) === '5.6.7.8');
    $t->check('XFF: garbage falls back to the peer', WsHandshake::deriveClientIp('10.0.0.1', ['not-an-ip'], 'x-forwarded-for', $trusted) === '10.0.0.1');
    $t->check('XFF: absent header uses the peer', WsHandshake::deriveClientIp('10.0.0.1', [], 'x-forwarded-for', $trusted) === '10.0.0.1');

    // PROXY protocol v1
    $t->check('PROXY TCP4', ProxyProtocol::parseV1('PROXY TCP4 198.51.100.7 10.0.0.1 51234 443') === '198.51.100.7');
    $t->check('PROXY TCP6', ProxyProtocol::parseV1('PROXY TCP6 2001:db8::1 2001:db8::2 51234 443') === '2001:db8::1');
    $t->check('PROXY UNKNOWN', ProxyProtocol::parseV1('PROXY UNKNOWN') === null);
    foreach (['PROXY TCP4 999.1.1.1 10.0.0.1 1 2', 'PROXY TCP4 1.1.1.1 10.0.0.1 99999 2', 'PROXY TCP5 1.1.1.1 1.1.1.2 1 2', "PROXY TCP4 1.1.1.1 1.1.1.2 1 2\x01", 'GET / HTTP/1.1', 'PROXY TCP4 1.1.1.1 1.1.1.2 1'] as $i => $line) {
        $t->throws('PROXY rejects malformed line ' . $i, static fn () => ProxyProtocol::parseV1($line), \InvalidArgumentException::class);
    }

    // Connection limiter
    $l = new ConnectionLimiter(3, 2, 0);
    $t->check('limiter admits up to the cap', $l->admit('a', 10) && $l->admit('a', 10) && $l->admit('b', 10) && !$l->admit('b', 10));
    $t->check('limiter per-IP cap', $l->claimIp('a', '1.2.3.4', 5) && $l->claimIp('a', '1.2.3.4', 5) && !$l->claimIp('a', '1.2.3.4', 5));
    $t->check('limiter normalises IPv4-mapped IPv6', $l->countForIp('::ffff:1.2.3.4') === 2);
    $l->release('a', '1.2.3.4');
    $t->check('limiter release frees the slot', $l->total() === 2 && $l->countForIp('1.2.3.4') === 1);
    $l->setDraining(true);
    $t->check('draining limiter refuses new connections', !$l->admit('a', 10));
    $fdLimited = new ConnectionLimiter(1000, 1000, 0);
    $admitted = 0;
    while ($fdLimited->admit('x', 10000)) {
        $admitted++;
    }
    $t->check('descriptor budget caps connections below the select limit', $admitted === intdiv(ConnectionLimiter::FD_BUDGET, 2), (string) $admitted);

    // Target resolver: allowlist and SSRF rules
    $res = new TargetResolver(selftest_defaults(['targetAllowlist' => ['127.0.0.1', '169.254.169.254', '8.8.8.8']]), new NullLogger());
    $t->throws('resolver refuses a host outside the allowlist', static fn () => $res->resolve('evil.example', 80), \RuntimeException::class);
    $t->throws('resolver blocks loopback literal by default', static fn () => $res->resolve('127.0.0.1', 80), \RuntimeException::class);
    $t->throws('resolver blocks the metadata address', static fn () => $res->resolve('169.254.169.254', 80), \RuntimeException::class);
    $t->check('resolver passes a public literal', $res->resolve('8.8.8.8', 53) === '8.8.8.8');
    $t->check('warm reports blocked', $res->warm('127.0.0.1', 80) === 'blocked');
    $t->check('warm reports not_allowed', $res->warm('evil.example', 80) === 'not_allowed');
    $perm = new TargetResolver(selftest_defaults(['targetAllowlist' => ['127.0.0.1'], 'allowPrivateTargets' => true]), new NullLogger());
    $t->check('private targets work when explicitly allowed', $perm->resolve('127.0.0.1', 80) === '127.0.0.1');
    $perm->setLoopMode(true);
    $t->throws('loop mode never resolves unknown hosts', static fn () => (new TargetResolver(selftest_defaults(['targetAllowlist' => ['origin.example']]), new NullLogger()))->resolve('origin.example', 80), \RuntimeException::class);

    // Event loop: timers and stream callbacks
    $loop = new EventLoop();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $got = '';
    $ticks = 0;
    stream_set_blocking($pair[0], false);
    stream_set_blocking($pair[1], false);
    $loop->addReadStream($pair[1], static function ($s) use (&$got, $loop): void {
        $got .= (string) fread($s, 100);
        $loop->stop();
    });
    $loop->addTimer(0.05, static function () use ($pair): void {
        fwrite($pair[0], 'ping');
    });
    $periodic = $loop->addTimer(0.01, static function () use (&$ticks): void {
        $ticks++;
    }, true);
    $started = microtime(true);
    $loop->run();
    $loop->cancelTimer($periodic);
    $t->check('event loop delivers readable events and timers', $got === 'ping' && $ticks >= 2 && microtime(true) - $started < 2.0);
    $loop2 = new EventLoop();
    $errors = 0;
    $loop2->setErrorHandler(static function () use (&$errors): void {
        $errors++;
    });
    $loop2->addTimer(0.0, static function (): void {
        throw new \RuntimeException('boom');
    });
    $loop2->addTimer(0.05, static function () use ($loop2): void {
        $loop2->stop();
    });
    $loop2->run();
    $t->check('event loop contains callback exceptions via the error handler', $errors === 1);
}

// ---------------------------------------------------------------------------------------------------------------
// Part 2: end-to-end against a real daemon process
// ---------------------------------------------------------------------------------------------------------------

final class Hub
{
    /** @var array<string,resource> */
    public array $servers = [];
    /** @var array<int,array{kind:string,sock:resource,buf:string,state:string}> */
    public array $conns = [];
    public string $lastWsRequest = '';
    /** @var array<string,resource> */
    public array $udp = [];
    public int $tcpEchoed = 0;

    public function listenTcp(string $name): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($s === false) {
            throw new RuntimeException('cannot listen: ' . $errstr);
        }
        stream_set_blocking($s, false);
        $this->servers[$name] = $s;
        return (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
    }

    public function listenUdp(string $name): int
    {
        $s = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($s === false) {
            throw new RuntimeException('cannot bind: ' . $errstr);
        }
        stream_set_blocking($s, false);
        $this->udp[$name] = $s;
        return (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
    }

    public function pump(): void
    {
        $read = array_values($this->servers + $this->udp);
        foreach ($this->conns as $c) {
            $read[] = $c['sock'];
        }
        if ($read === []) {
            return;
        }
        $w = null;
        $e = null;
        if (@stream_select($read, $w, $e, 0, 5000) === false) {
            return;
        }
        foreach ($read as $s) {
            foreach ($this->servers as $name => $srv) {
                if ($srv === $s) {
                    while (($c = @stream_socket_accept($srv, 0)) !== false) {
                        stream_set_blocking($c, false);
                        $this->conns[get_resource_id($c)] = ['kind' => $name, 'sock' => $c, 'buf' => '', 'state' => $name === 'ws' ? 'head' : 'echo'];
                    }
                    continue 2;
                }
            }
            foreach ($this->udp as $name => $srv) {
                if ($srv === $s) {
                    while (($data = @stream_socket_recvfrom($srv, 70000, 0, $peer)) !== false && ($data !== '' || $peer !== '')) {
                        if ($name === 'amp') {
                            stream_socket_sendto($srv, str_repeat('A', 8000), 0, $peer);
                        } else {
                            stream_socket_sendto($srv, $data, 0, $peer);
                        }
                    }
                    continue 2;
                }
            }
            $id = get_resource_id($s);
            if (isset($this->conns[$id])) {
                $this->serve($id);
            }
        }
    }

    private function serve(int $id): void
    {
        $c = &$this->conns[$id];
        $data = @fread($c['sock'], 65536);
        if ($data === false || ($data === '' && feof($c['sock']))) {
            @fclose($c['sock']);
            unset($this->conns[$id]);
            return;
        }
        if ($data === '') {
            return;
        }
        if ($c['state'] === 'echo') {
            $this->tcpEchoed += strlen($data);
            $this->writeAll($c['sock'], $data);
            return;
        }
        if ($c['state'] === 'head') {
            $c['buf'] .= $data;
            $end = strpos($c['buf'], "\r\n\r\n");
            if ($end === false) {
                return;
            }
            $head = substr($c['buf'], 0, $end);
            $this->lastWsRequest = $head;
            $rest = substr($c['buf'], $end + 4);
            preg_match('/^GET (\S+) /', $head, $m);
            preg_match('/^Sec-WebSocket-Key: (\S+)/mi', $head, $k);
            $path = $m[1] ?? '/';
            if ($path === '/refuse') {
                $this->writeAll($c['sock'], "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n");
                $c['state'] = 'done';
                return;
            }
            $accept = WsHandshake::acceptFor($k[1] ?? '');
            if ($path === '/bad-accept') {
                $accept = WsHandshake::acceptFor('different-key');
            }
            $resp = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\nServer: secret-origin/9.9\r\nX-Powered-By: internals\r\n";
            if ($path === '/proto' && preg_match('/^Sec-WebSocket-Protocol: (\S+)/mi', $head, $p) === 1) {
                $resp .= 'Sec-WebSocket-Protocol: ' . $p[1] . "\r\n";
            }
            $this->writeAll($c['sock'], $resp . "\r\n" . 'hello-from-origin');
            $c['state'] = 'echo';
            if ($rest !== '') {
                $this->writeAll($c['sock'], $rest);
            }
        }
    }

    /** @param resource $s */
    private function writeAll($s, string $data): void
    {
        $deadline = microtime(true) + 3.0;
        while ($data !== '' && microtime(true) < $deadline) {
            $n = @fwrite($s, $data);
            if ($n === false) {
                return;
            }
            $data = (string) substr($data, $n);
            if ($data !== '') {
                usleep(1000);
            }
        }
    }
}

function selftest_make_cert(string $cert, string $key): bool
{
    if (!function_exists('proc_open')) {
        return false;
    }
    $proc = @proc_open(
        ['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $key, '-out', $cert, '-days', '1', '-subj', '/CN=localhost'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($proc)) {
        return false;
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    return proc_close($proc) === 0 && is_file($cert) && is_file($key);
}

function selftest_free_port(string $proto = 'tcp'): int
{
    $s = $proto === 'udp'
        ? stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND)
        : stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($s === false) {
        throw new RuntimeException('no free port');
    }
    $name = (string) stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/** Reads from $sock (pumping the hub) until $until($buffer) is true or $timeout elapses. */
function selftest_read(Hub $hub, $sock, callable $until, float $timeout = 3.0): string
{
    $buf = '';
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $hub->pump();
        $r = [$sock];
        $w = null;
        $e = null;
        if (@stream_select($r, $w, $e, 0, 10000) > 0) {
            $d = @fread($sock, 65536);
            if ($d === false || ($d === '' && feof($sock))) {
                return $buf;
            }
            $buf .= $d;
        }
        if ($until($buf)) {
            return $buf;
        }
    }
    return $buf;
}

/** True if the peer closed the connection within $timeout seconds. */
function selftest_closed(Hub $hub, $sock, float $timeout): bool
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $hub->pump();
        $r = [$sock];
        $w = null;
        $e = null;
        if (@stream_select($r, $w, $e, 0, 20000) > 0) {
            $d = @fread($sock, 65536);
            if ($d === false || ($d === '' && feof($sock))) {
                return true;
            }
        }
    }
    return false;
}

function selftest_connect(int $port)
{
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2.0);
    if ($s === false) {
        return false;
    }
    stream_set_blocking($s, false);
    return $s;
}

function selftest_ws_request(array $over = [], array $drop = []): string
{
    $h = array_merge([
        'Host' => 'mirror.example',
        'Upgrade' => 'websocket',
        'Connection' => 'Upgrade',
        'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        'Sec-WebSocket-Version' => '13',
        'Origin' => 'https://mirror.example',
    ], $over);
    foreach ($drop as $name) {
        unset($h[$name]);
    }
    $lines = ['GET ' . ($over['__path'] ?? '/ws') . ' HTTP/1.1'];
    unset($h['__path']);
    foreach ($h as $k => $v) {
        $lines[] = $k . ': ' . $v;
    }
    return implode("\r\n", $lines) . "\r\n\r\n";
}

function selftest_e2e(SelfTest $t, string $root): void
{
    if (!function_exists('proc_open')) {
        fwrite(STDOUT, "skip - proc_open is disabled, end-to-end part skipped\n");
        return;
    }
    $tmp = sys_get_temp_dir() . '/mg-daemon-selftest-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/var/run', 0700, true);
    mkdir($tmp . '/var/logs', 0700, true);

    $hub = new Hub();
    $oWs = $hub->listenTcp('ws');
    $oTcp = $hub->listenTcp('echo');
    $oUdp = $hub->listenUdp('udpecho');
    $oAmp = $hub->listenUdp('amp');
    $p = [];
    foreach (['ws', 'tcp', 'udp', 'grpc', 'amp', 'idle', 'perip'] as $name) {
        $p[$name] = selftest_free_port($name === 'udp' || $name === 'amp' ? 'udp' : 'tcp');
    }
    $p['proxy'] = selftest_free_port();
    $p['tls'] = selftest_free_port();
    $p['tlsTarget'] = selftest_free_port();
    $cert = $tmp . '/cert.pem';
    $key = $tmp . '/key.pem';
    $haveTls = extension_loaded('openssl') && selftest_make_cert($cert, $key);

    $env = [
        'ORIGIN_URL=https://origin.example',
        'MIRROR_URL=https://mirror.example',
        'STORAGE_DIR=' . $tmp . '/var',
        'LOG_LEVEL=debug',
        'TRUSTED_PROXIES=203.0.113.0/24,127.0.0.1/32',
        'DAEMON_LISTEN_1=ws://127.0.0.1:' . $p['ws'] . '->ws://127.0.0.1:' . $oWs,
        'DAEMON_LISTEN_2=tcp://127.0.0.1:' . $p['tcp'] . '->tcp://127.0.0.1:' . $oTcp,
        'DAEMON_LISTEN_3=udp://127.0.0.1:' . $p['udp'] . '->udp://127.0.0.1:' . $oUdp,
        'DAEMON_LISTEN_4=grpc://127.0.0.1:' . $p['grpc'] . '->h2c://127.0.0.1:' . $oTcp,
        'DAEMON_LISTEN_5=udp://127.0.0.1:' . $p['amp'] . '->udp://127.0.0.1:' . $oAmp,
        'DAEMON_LISTEN_6=tcp://127.0.0.1:' . $p['idle'] . '->tcp://127.0.0.1:' . $oTcp . '?idle=1',
        'DAEMON_LISTEN_7=tcp://127.0.0.1:' . $p['perip'] . '->tcp://127.0.0.1:' . $oTcp . '?max_per_ip=2',
        ...($haveTls ? [
            'DAEMON_LISTEN_8=tcp://127.0.0.1:' . $p['tls'] . '->tcp://127.0.0.1:' . $oTcp . '?tls_cert=' . rawurlencode($cert) . '&tls_key=' . rawurlencode($key),
            'DAEMON_LISTEN_9=tcp://127.0.0.1:' . $p['tlsTarget'] . '->tls://127.0.0.1:' . $p['tls'],
        ] : []),
        'DAEMON_LISTEN_10=tcp://127.0.0.1:' . $p['proxy'] . '->tcp://127.0.0.1:' . $oTcp . '?proxy_protocol=1',
        'DAEMON_TARGET_ALLOWLIST=127.0.0.1',
        'DAEMON_ALLOW_PRIVATE_TARGETS=true',
        'DAEMON_WS_ORIGIN_ALLOW=https://mirror.example',
        'DAEMON_HANDSHAKE_TIMEOUT=2',
        'DAEMON_PID_FILE=' . $tmp . '/var/run/daemon.pid',
        'DAEMON_LOG_FILE=' . $tmp . '/var/logs/daemon.log',
        'DAEMON_STOP_FILE=' . $tmp . '/var/run/daemon.stop',
    ];
    $envFile = $tmp . '/.env';
    file_put_contents($envFile, implode("\n", $env) . "\n");

    $php = PHP_BINARY;
    $bin = $root . '/bin/mirrorgate-daemon';
    $args = [$php, $bin];

    $run = static function (array $extra) use ($args): array {
        $proc = proc_open(array_merge($args, $extra), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [99, ''];
        }
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        return [proc_close($proc), $out];
    };

    [$code, $out] = $run(['check', '--env=' . $envFile]);
    $t->check('daemon check passes on a valid configuration', $code === 0, trim($out));

    $proc = proc_open(array_merge($args, ['start', '--foreground', '--env=' . $envFile]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        $t->check('daemon process starts', false);
        return;
    }
    stream_set_blocking($pipes[2], false);
    stream_set_blocking($pipes[1], false);

    $ready = false;
    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline) {
        $hub->pump();
        $c = @stream_socket_client('tcp://127.0.0.1:' . $p['tcp'], $e1, $e2, 0.2);
        if ($c !== false) {
            fclose($c);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    $t->check('daemon starts and listens', $ready);
    if (!$ready) {
        $t->check('daemon stderr', false, trim((string) stream_get_contents($pipes[2])));
        proc_terminate($proc);
        return;
    }

    // Second instance must refuse to start (pid file lock).
    [$code2] = $run(['start', '--foreground', '--env=' . $envFile]);
    $t->check('second instance exits with code 1', $code2 === 1, (string) $code2);

    // TCP echo
    $c = selftest_connect($p['tcp']);
    fwrite($c, 'hello tcp');
    $t->check('tcp forwarder echoes', selftest_read($hub, $c, static fn (string $b): bool => $b === 'hello tcp') === 'hello tcp');

    // TCP bulk transfer with backpressure
    $total = 3 * 1024 * 1024;
    $payload = random_bytes(65536);
    $sent = 0;
    $recv = 0;
    $hash = hash_init('sha256');
    $sendHash = hash_init('sha256');
    $deadline = microtime(true) + 20.0;
    while (($sent < $total || $recv < $total) && microtime(true) < $deadline) {
        $hub->pump();
        $r = [$c];
        $w = $sent < $total ? [$c] : null;
        $e = null;
        if (@stream_select($r, $w, $e, 0, 10000) > 0) {
            if ($r !== []) {
                $d = @fread($c, 262144);
                if ($d !== false && $d !== '') {
                    $recv += strlen($d);
                    hash_update($hash, $d);
                }
            }
            if ($w) {
                $n = @fwrite($c, substr($payload, 0, min(65536, $total - $sent)));
                if ($n) {
                    hash_update($sendHash, substr($payload, 0, $n));
                    $sent += $n;
                }
            }
        }
    }
    $t->check('tcp bulk transfer of 3 MiB round-trips intact', $recv === $total && hash_final($hash) === hash_final($sendHash), "sent=$sent recv=$recv");
    fclose($c);

    // gRPC (h2c) listener is an opaque pipe
    $g = selftest_connect($p['grpc']);
    $preface = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    fwrite($g, $preface);
    $t->check('grpc h2c listener passes bytes through unchanged', selftest_read($hub, $g, static fn (string $b): bool => strlen($b) >= strlen($preface)) === $preface);
    fclose($g);

    // UDP
    $u = stream_socket_client('udp://127.0.0.1:' . $p['udp'], $e1, $e2, 1.0);
    stream_set_blocking($u, false);
    fwrite($u, 'datagram-1');
    $t->check('udp forwarder echoes', selftest_read($hub, $u, static fn (string $b): bool => $b === 'datagram-1', 2.0) === 'datagram-1');

    // UDP amplification cap: 4 bytes in, 8000 bytes out is dropped
    $a = stream_socket_client('udp://127.0.0.1:' . $p['amp'], $e1, $e2, 1.0);
    stream_set_blocking($a, false);
    fwrite($a, 'ping');
    $t->check('udp amplification beyond the cap is dropped', selftest_read($hub, $a, static fn (string $b): bool => $b !== '', 1.0) === '');

    // Idle timeout
    $i = selftest_connect($p['idle']);
    $t->check('idle connection is closed by the idle timeout', selftest_closed($hub, $i, 5.0));

    // Per-IP limit: third simultaneous connection is dropped
    $keep = [selftest_connect($p['perip']), selftest_connect($p['perip'])];
    foreach ($keep as $k) {
        fwrite($k, 'x');
        selftest_read($hub, $k, static fn (string $b): bool => $b === 'x', 1.0);
    }
    $third = selftest_connect($p['perip']);
    $t->check('per-IP connection cap closes the extra connection', selftest_closed($hub, $third, 2.0));
    foreach ($keep as $k) {
        fclose($k);
    }

    // PROXY protocol v1 from a trusted peer (127.0.0.1 is trusted in this run)
    $pp = selftest_connect($p['proxy']);
    fwrite($pp, "PROXY TCP4 198.51.100.7 10.0.0.1 51234 443\r\nhello via proxy protocol");
    $t->check('PROXY protocol header is consumed and the payload forwarded', selftest_read($hub, $pp, static fn (string $b): bool => $b === 'hello via proxy protocol', 3.0) === 'hello via proxy protocol');
    fclose($pp);
    $pbad = selftest_connect($p['proxy']);
    fwrite($pbad, "GET / HTTP/1.1\r\n\r\n");
    $t->check('a connection without a PROXY header is dropped', selftest_closed($hub, $pbad, 3.0));

    if ($haveTls) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $tc = @stream_socket_client('tls://127.0.0.1:' . $p['tls'], $e1, $e2, 5.0, STREAM_CLIENT_CONNECT, $ctx);
        $t->check('listener-side TLS termination completes a handshake', $tc !== false, (string) ($e2 ?? ''));
        if ($tc !== false) {
            stream_set_blocking($tc, false);
            fwrite($tc, 'over tls');
            $t->check('TLS listener forwards to a plain target', selftest_read($hub, $tc, static fn (string $b): bool => $b === 'over tls', 4.0) === 'over tls');
            fclose($tc);
        }
        // The target leg verifies certificates: a self-signed target must be refused, so no echo comes back.
        $vc = selftest_connect($p['tlsTarget']);
        fwrite($vc, 'should not pass');
        $got = selftest_read($hub, $vc, static fn (string $b): bool => $b !== '', 3.0);
        $t->check('TLS target with an untrusted certificate is refused', $got === '' && selftest_closed($hub, $vc, 2.0));
    } else {
        fwrite(STDOUT, "skip - openssl is unavailable, TLS checks skipped\n");
    }

    // Handshake timeout (silent client on the ws listener)
    $silent = selftest_connect($p['ws']);
    $t->check('silent websocket client is dropped at the handshake timeout', selftest_closed($hub, $silent, 5.0));

    // WebSocket: success path
    $w = selftest_connect($p['ws']);
    fwrite($w, selftest_ws_request(['Cookie' => 'sid=abc; mg_internal=1', 'X-Forwarded-For' => '9.9.9.9, 6.6.6.6, 127.0.0.1', 'Sec-WebSocket-Protocol' => 'chat', '__path' => '/proto']));
    $resp = selftest_read($hub, $w, static fn (string $b): bool => str_contains($b, 'hello-from-origin'));
    $t->check('websocket handshake answered with 101', str_starts_with($resp, 'HTTP/1.1 101 '));
    $t->check('websocket accept value is correct', str_contains($resp, 'Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo='));
    $t->check('origin headers are not leaked to the client', !str_contains(strtolower($resp), 'secret-origin') && !str_contains(strtolower($resp), 'x-powered-by'));
    $t->check('negotiated subprotocol is relayed', str_contains($resp, 'Sec-WebSocket-Protocol: chat'));
    $t->check('bytes after the 101 are relayed', str_contains($resp, "\r\n\r\nhello-from-origin"));
    $req = $hub->lastWsRequest;
    $t->check('origin sees the configured Host', preg_match('/^Host: 127\.0\.0\.1:' . $oWs . '\r?$/m', $req) === 1, $req);
    $t->check('origin sees Origin rewritten to ORIGIN_URL', preg_match('/^Origin: https:\/\/origin\.example\r?$/m', $req) === 1);
    $t->check('X-Forwarded-For carries only the derived client address', preg_match_all('/^X-Forwarded-For: /mi', $req) === 1 && preg_match('/^X-Forwarded-For: 6\.6\.6\.6\r?$/m', $req) === 1 && !str_contains($req, '9.9.9.9'));
    $t->check('mg_ cookies are not forwarded', str_contains($req, 'sid=abc') && !str_contains($req, 'mg_internal'));
    fwrite($w, "frame-bytes-\x81\x05hello");
    $t->check('websocket pipe is bidirectional', str_contains(selftest_read($hub, $w, static fn (string $b): bool => str_contains($b, 'frame-bytes-')), "frame-bytes-\x81\x05hello"));
    fclose($w);

    // WebSocket: rejections
    $expect = static function (string $label, string $request, string $status) use ($hub, $p, $t): void {
        $s = selftest_connect($p['ws']);
        fwrite($s, $request);
        $r = selftest_read($hub, $s, static fn (string $b): bool => str_contains($b, "\r\n\r\n"), 2.0);
        $t->check($label, str_starts_with($r, 'HTTP/1.1 ' . $status . ' '), substr($r, 0, 40));
        fclose($s);
    };
    $expect('foreign Origin is rejected with 403', selftest_ws_request(['Origin' => 'https://evil.example']), '403');
    $expect('Origin "null" is rejected with 403', selftest_ws_request(['Origin' => 'null']), '403');
    $expect('missing Origin is rejected with 403', selftest_ws_request([], ['Origin']), '403');
    $expect('foreign Host is rejected with 421', selftest_ws_request(['Host' => 'evil.example']), '421');
    $expect('bad Sec-WebSocket-Key is rejected with 400', selftest_ws_request(['Sec-WebSocket-Key' => 'short']), '400');
    $expect('wrong version is rejected with 400', selftest_ws_request(['Sec-WebSocket-Version' => '8']), '400');
    $expect('h2c upgrade is not honoured', selftest_ws_request(['Upgrade' => 'h2c']), '400');
    $expect('Content-Length on the handshake is rejected', selftest_ws_request(['Content-Length' => '5']), '400');
    $expect('Transfer-Encoding on the handshake is rejected', selftest_ws_request(['Transfer-Encoding' => 'chunked']), '400');
    $expect('duplicate Host is rejected', str_replace("Host: mirror.example\r\n", "Host: mirror.example\r\nHost: evil.example\r\n", selftest_ws_request()), '400');
    $expect('bare LF header injection is rejected', str_replace("\r\nUpgrade", "\nX-Injected: 1\r\nUpgrade", selftest_ws_request()), '400');
    $expect('request line smuggling is rejected', str_replace('GET /ws HTTP/1.1', 'GET http://evil.example/ws HTTP/1.1', selftest_ws_request()), '400');
    $expect('bytes after the handshake are rejected', selftest_ws_request() . 'GET / HTTP/1.1', '400');
    $expect('origin answering with a wrong accept becomes 502', selftest_ws_request(['__path' => '/bad-accept']), '502');
    $expect('origin refusing the upgrade becomes 502', selftest_ws_request(['__path' => '/refuse']), '502');
    $expect('oversized handshake is rejected with 431', selftest_ws_request(['X-Big' => str_repeat('a', 20000)]), '431');

    // Graceful stop
    [$stopCode, $stopOut] = $run(['stop', '--env=' . $envFile]);
    $t->check('stop command reports success', $stopCode === 0, trim($stopOut));
    $exit = null;
    $deadline = microtime(true) + 15.0;
    while (microtime(true) < $deadline) {
        $hub->pump();
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        usleep(50000);
    }
    $t->check('daemon exits with code 0 after a graceful stop', $exit === 0, var_export($exit, true));
    if ($exit === null) {
        proc_terminate($proc, 9);
    }
    $log = (string) @file_get_contents($tmp . '/var/logs/daemon.log');
    $t->check('log contains no cookie or payload data', !str_contains($log, 'sid=abc') && !str_contains($log, 'frame-bytes'));
    $t->check('pid file lock is released', !\MirrorGate\Daemon\Daemon::status(\MirrorGate\Bootstrap::loadConfig($root, $envFile)->daemon)['running']);

    // Cleanup
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
}

$t = new SelfTest();
$unitOnly = in_array('--unit', $argv, true);
selftest_unit($t);
if (!$unitOnly) {
    selftest_e2e($t, $root);
}
fwrite(STDOUT, sprintf("\n%d passed, %d failed\n", $t->passed, $t->failed));
exit($t->failed === 0 ? 0 : 1);
