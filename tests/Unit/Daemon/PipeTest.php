<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Daemon;

use MirrorGate\Daemon\Acceptor;
use MirrorGate\Daemon\ConnectionLimiter;
use MirrorGate\Daemon\EventLoop;
use MirrorGate\Daemon\ListenerKind;
use MirrorGate\Daemon\ListenerSpec;
use MirrorGate\Daemon\Session;
use MirrorGate\Log\NullLogger;
use MirrorGate\Security\CidrList;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DaemonTestSupport.php';

/**
 * Drives Acceptor and Session in-process against local sockets: byte pipe, backpressure and idle timeout.
 */
final class PipeTest extends TestCase
{
    private function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($s);
        $name = (string) stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** @return array{0:resource,1:int} listening socket and its port */
    private function listen(): array
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($s);
        stream_set_blocking($s, false);
        $name = (string) stream_socket_get_name($s, false);
        return [$s, (int) substr($name, strrpos($name, ':') + 1)];
    }

    /** @return array{0:Acceptor,1:int} */
    private function proxy(EventLoop $loop, int $targetPort, int $idle, int $highWater): array
    {
        $port = $this->freePort();
        $cfg = DaemonTestSupport::config(['bufferHighWater' => $highWater, 'idleTimeout' => $idle]);
        $spec = new ListenerSpec(ListenerKind::Tcp, '127.0.0.1', $port, 'tcp', '127.0.0.1', $targetPort, $idle, 100, 100, null, null, [], null, null, []);
        $acceptor = new Acceptor($spec, $cfg, new CidrList([]), $loop, new ConnectionLimiter(100, 100, 0), new NullLogger(), false);
        $acceptor->open(static function (Session $s) use ($targetPort): void {
            $s->connectTarget(
                '127.0.0.1',
                $targetPort,
                null,
                static function () use ($s): void {
                    $s->startPipe();
                },
                static function (string $reason) use ($s): void {
                    $s->close($reason);
                },
            );
        });
        return [$acceptor, $port];
    }

    public function testBackpressureHoldsTheSenderBackAndNoBytesAreLost(): void
    {
        $total = 48 * 1024 * 1024;
        $loop = new EventLoop();
        [$sinkServer, $sinkPort] = $this->listen();
        [$acceptor, $proxyPort] = $this->proxy($loop, $sinkPort, 30, 4096);

        $received = 0;
        $sinkReading = false;
        $sinkConn = null;
        $attach = static function ($conn) use ($loop, &$received, $total): void {
            $loop->addReadStream($conn, static function ($c) use ($loop, &$received, $total): void {
                $d = fread($c, 262144);
                if (is_string($d)) {
                    $received += strlen($d);
                }
                if ($received >= $total) {
                    $loop->stop();
                }
            });
        };
        $loop->addReadStream($sinkServer, static function ($srv) use (&$sinkConn, &$sinkReading, $attach): void {
            $c = @stream_socket_accept($srv, 0);
            if ($c !== false) {
                stream_set_blocking($c, false);
                $sinkConn = $c;
                if ($sinkReading) {
                    $attach($c);
                }
            }
        });

        $client = stream_socket_client('tcp://127.0.0.1:' . $proxyPort, $errno, $errstr, 2.0);
        self::assertNotFalse($client);
        stream_set_blocking($client, false);
        $chunk = str_repeat('z', 65536);
        $sent = 0;
        $loop->addWriteStream($client, static function ($c) use ($loop, &$sent, $total, $chunk): void {
            $n = @fwrite($c, substr($chunk, 0, min(65536, $total - $sent)));
            if (is_int($n)) {
                $sent += $n;
            }
            if ($sent >= $total) {
                $loop->removeWriteStream($c);
            }
        });

        $sentWhenSinkStarted = -1;
        $loop->addTimer(0.7, static function () use (&$sinkReading, &$sinkConn, $attach, &$sent, &$sentWhenSinkStarted): void {
            $sentWhenSinkStarted = $sent;
            $sinkReading = true;
            if ($sinkConn !== null) {
                $attach($sinkConn);
            }
        });
        $loop->addTimer(40.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $acceptor->closeAll();

        self::assertSame($total, $received, 'every byte must arrive');
        self::assertGreaterThan(-1, $sentWhenSinkStarted);
        self::assertLessThan($total, $sentWhenSinkStarted, 'a sink that does not read must stall the sender');
    }

    public function testIdleSessionIsClosed(): void
    {
        $loop = new EventLoop();
        [$sinkServer, $sinkPort] = $this->listen();
        [$acceptor, $proxyPort] = $this->proxy($loop, $sinkPort, 1, 65536);
        $loop->addReadStream($sinkServer, static function ($srv): void {
            $c = @stream_socket_accept($srv, 0);
            if ($c !== false) {
                stream_set_blocking($c, false);
            }
        });

        $client = stream_socket_client('tcp://127.0.0.1:' . $proxyPort, $errno, $errstr, 2.0);
        self::assertNotFalse($client);
        stream_set_blocking($client, false);
        $started = microtime(true);
        $closedAfter = null;
        $loop->addReadStream($client, static function ($c) use ($loop, $started, &$closedAfter): void {
            $d = fread($c, 100);
            if ($d === false || ($d === '' && feof($c))) {
                $closedAfter = microtime(true) - $started;
                $loop->stop();
            }
        });
        $loop->addTimer(8.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $acceptor->closeAll();

        self::assertNotNull($closedAfter, 'the idle timeout must close the session');
        self::assertLessThan(5.0, $closedAfter);
    }

    public function testEchoRoundTripThroughTheProxy(): void
    {
        $loop = new EventLoop();
        [$echoServer, $echoPort] = $this->listen();
        [$acceptor, $proxyPort] = $this->proxy($loop, $echoPort, 30, 65536);
        $loop->addReadStream($echoServer, static function ($srv) use ($loop): void {
            $c = @stream_socket_accept($srv, 0);
            if ($c === false) {
                return;
            }
            stream_set_blocking($c, false);
            $loop->addReadStream($c, static function ($conn) use ($loop): void {
                $d = fread($conn, 65536);
                if (is_string($d) && $d !== '') {
                    fwrite($conn, $d);
                } elseif ($d === false || feof($conn)) {
                    $loop->removeReadStream($conn);
                    fclose($conn);
                }
            });
        });
        $client = stream_socket_client('tcp://127.0.0.1:' . $proxyPort, $errno, $errstr, 2.0);
        self::assertNotFalse($client);
        stream_set_blocking($client, false);
        fwrite($client, 'hello through the pipe');
        $got = '';
        $loop->addReadStream($client, static function ($c) use ($loop, &$got): void {
            $got .= (string) fread($c, 1000);
            if (strlen($got) >= 22) {
                $loop->stop();
            }
        });
        $loop->addTimer(5.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $acceptor->closeAll();
        self::assertSame('hello through the pipe', $got);
    }

    public function testClientAllowListRefusesOtherAddresses(): void
    {
        $loop = new EventLoop();
        $port = $this->freePort();
        $cfg = DaemonTestSupport::config(['tcpClientAllow' => ['10.0.0.0/8']]);
        $spec = new ListenerSpec(ListenerKind::Tcp, '127.0.0.1', $port, 'tcp', '127.0.0.1', 9, 30, 100, 100, null, null, [], null, null, []);
        $opened = 0;
        $acceptor = new Acceptor($spec, $cfg, new CidrList([]), $loop, new ConnectionLimiter(100, 100, 0), new NullLogger(), false);
        $acceptor->open(static function (Session $s) use (&$opened): void {
            $opened++;
        });
        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2.0);
        self::assertNotFalse($client);
        $loop->addTimer(0.3, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        self::assertSame(0, $opened, 'a client outside DAEMON_TCP_CLIENT_ALLOW never reaches the forwarder');
        self::assertSame(0, $acceptor->sessionCount());
        $acceptor->closeAll();
        fclose($client);
    }

    public function testGrpcForwarderConnectsToTheOriginOnlyAfterTheFirstClientBytes(): void
    {
        $loop = new EventLoop();
        [$origin, $originPort] = $this->listen();
        $listenPort = $this->freePort();
        $config = \MirrorGate\Config\ConfigFactory::create(
            ['ORIGIN_URL' => 'https://overlord.team', 'MIRROR_URL' => 'https://overload.ir', 'DAEMON_ALLOW_PRIVATE_TARGETS' => 'true',
                'DAEMON_TARGET_ALLOWLIST' => '127.0.0.1'],
            '/srv',
            '/srv/.env',
        );
        $spec = ListenerSpec::parse('grpc://127.0.0.1:' . $listenPort . '->h2c://127.0.0.1:' . $originPort, $config->daemon);
        $resolver = new \MirrorGate\Daemon\TargetResolver($config->daemon, new NullLogger());
        $forwarder = new \MirrorGate\Daemon\GrpcForwarder($spec, $config, new NullLogger(), $resolver, new ConnectionLimiter(100, 100, 0));
        $forwarder->start($loop);

        $originAccepts = 0;
        $originGot = '';
        $conns = [];
        $loop->addReadStream($origin, static function ($srv) use ($loop, &$originAccepts, &$originGot, &$conns): void {
            $c = @stream_socket_accept($srv, 0);
            if ($c === false) {
                return;
            }
            $originAccepts++;
            stream_set_blocking($c, false);
            $conns[] = $c;
            $loop->addReadStream($c, static function ($sock) use (&$originGot): void {
                $d = @fread($sock, 65535);
                if (is_string($d)) {
                    $originGot .= $d;
                }
            });
        });

        $client = stream_socket_client('tcp://127.0.0.1:' . $listenPort, $errno, $errstr, 2.0);
        self::assertNotFalse($client);
        $acceptsWhileIdle = -1;
        $loop->addTimer(0.3, static function () use (&$acceptsWhileIdle, &$originAccepts, $client): void {
            $acceptsWhileIdle = $originAccepts;
            fwrite($client, 'PRI * HTTP/2.0');
        });
        $loop->addTimer(0.8, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $forwarder->stop();
        self::assertSame(0, $acceptsWhileIdle, 'an idle client must not cost an origin connection');
        self::assertSame(1, $originAccepts);
        self::assertSame('PRI * HTTP/2.0', $originGot, 'the first bytes are forwarded, not lost');
        foreach ($conns as $c) {
            fclose($c);
        }
        fclose($client);
        fclose($origin);
    }
}
