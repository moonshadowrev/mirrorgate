<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Daemon;

use MirrorGate\Config\ConfigFactory;
use MirrorGate\Daemon\ConnectionLimiter;
use MirrorGate\Daemon\EventLoop;
use MirrorGate\Daemon\ListenerSpec;
use MirrorGate\Daemon\TargetResolver;
use MirrorGate\Daemon\UdpForwarder;
use MirrorGate\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Drives the UDP forwarder in-process: a loopback listener, an amplifying UDP target and several client sockets.
 */
final class UdpForwarderTest extends TestCase
{
    private function freeUdpPort(): int
    {
        $s = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        self::assertNotFalse($s);
        $name = (string) stream_socket_get_name($s, false);
        fclose($s);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * @param list<string> $payloads one datagram per fresh client socket (source port)
     * @return array{replies:int,sessions:int}
     */
    private function exchange(array $payloads, int $replyBytes): array
    {
        $targetPort = $this->freeUdpPort();
        $listenPort = $this->freeUdpPort();
        $config = ConfigFactory::create(
            ['ORIGIN_URL' => 'https://overlord.team', 'MIRROR_URL' => 'https://overload.ir', 'DAEMON_ALLOW_PRIVATE_TARGETS' => 'true',
                'DAEMON_TARGET_ALLOWLIST' => '127.0.0.1', 'DAEMON_UDP_MAX_AMPLIFICATION' => '2'],
            '/srv',
            '/srv/.env',
        );
        $spec = ListenerSpec::parse('udp://127.0.0.1:' . $listenPort . '->udp://127.0.0.1:' . $targetPort, $config->daemon);
        $limiter = new ConnectionLimiter(100, 100, 0);
        $resolver = new TargetResolver($config->daemon, new NullLogger());
        $forwarder = new UdpForwarder($spec, $config, new NullLogger(), $resolver, $limiter);
        $loop = new EventLoop();
        $forwarder->start($loop);

        $target = stream_socket_server('udp://127.0.0.1:' . $targetPort, $errno, $errstr, STREAM_SERVER_BIND);
        self::assertNotFalse($target);
        stream_set_blocking($target, false);
        $loop->addReadStream($target, static function ($srv) use ($replyBytes): void {
            $peer = '';
            $got = @stream_socket_recvfrom($srv, 65535, 0, $peer);
            if ($got !== false && $got !== '') {
                @stream_socket_sendto($srv, str_repeat('r', $replyBytes), 0, $peer);
            }
        });

        $clients = [];
        foreach ($payloads as $payload) {
            $c = stream_socket_client('udp://127.0.0.1:' . $listenPort, $errno, $errstr, 1.0);
            self::assertNotFalse($c);
            stream_set_blocking($c, false);
            @fwrite($c, $payload);
            $clients[] = $c;
        }
        $replies = 0;
        foreach ($clients as $c) {
            $loop->addReadStream($c, static function ($sock) use (&$replies): void {
                while (($d = @fread($sock, 65535)) !== false && $d !== '') {
                    $replies++;
                }
            });
        }
        $loop->addTimer(0.6, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $sessions = $limiter->total();
        $forwarder->stop();
        foreach ($clients as $c) {
            fclose($c);
        }
        fclose($target);

        return ['replies' => $replies, 'sessions' => $sessions];
    }

    public function testReplyBudgetIsPerClientAddressNotPerSourcePort(): void
    {
        // Ten source ports from one address, 10 bytes each: the address may receive 2 * 100 + 4096 bytes in total.
        $result = $this->exchange(array_fill(0, 10, '0123456789'), 1000);
        self::assertGreaterThan(0, $result['replies']);
        self::assertLessThanOrEqual(4, $result['replies'], 'a per-port budget would have allowed about forty replies');
    }

    public function testEmptyDatagramsOpenNoSession(): void
    {
        $result = $this->exchange(['', '', ''], 10);
        self::assertSame(0, $result['sessions']);
        self::assertSame(0, $result['replies']);
    }
}
