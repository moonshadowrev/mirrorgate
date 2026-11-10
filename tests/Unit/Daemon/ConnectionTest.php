<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Daemon;

use MirrorGate\Daemon\Connection;
use MirrorGate\Daemon\ConnectionLimiter;
use MirrorGate\Daemon\EventLoop;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DaemonTestSupport.php';

final class ConnectionTest extends TestCase
{
    public function testLimiterCapsAndReleases(): void
    {
        $l = new ConnectionLimiter(3, 2, 0);
        self::assertTrue($l->admit('a', 10));
        self::assertTrue($l->admit('a', 10));
        self::assertTrue($l->admit('b', 10));
        self::assertFalse($l->admit('b', 10));
        self::assertTrue($l->claimIp('a', '1.2.3.4', 5));
        self::assertTrue($l->claimIp('a', '1.2.3.4', 5));
        self::assertFalse($l->claimIp('a', '1.2.3.4', 5));
        self::assertSame(2, $l->countForIp('::ffff:1.2.3.4'));
        $l->release('a', '1.2.3.4');
        self::assertSame(2, $l->total());
        $l->setDraining(true);
        self::assertFalse($l->admit('a', 10));
    }

    public function testPerIpCapsAggregateIpv6ToThe64(): void
    {
        $l = new ConnectionLimiter(100, 2, 0);
        self::assertTrue($l->claimIp('a', '2001:db8:1:2::1', 10));
        self::assertTrue($l->claimIp('a', '2001:db8:1:2:aaaa::2', 10));
        self::assertFalse($l->claimIp('a', '2001:db8:1:2:ffff:ffff:ffff:ffff', 10), 'same /64 shares one budget');
        self::assertTrue($l->claimIp('a', '2001:db8:1:3::1', 10), 'a different /64 has its own');
        self::assertSame(2, $l->countForIp('2001:db8:1:2::99'));
        $l->releaseIp('a', '2001:db8:1:2::1');
        self::assertTrue($l->claimIp('a', '2001:db8:1:2:1::1', 10));
    }

    public function testOneListenerCannotStarveTheOthers(): void
    {
        $l = new ConnectionLimiter(10, 100, 0);
        $l->shareAmong(['tcp', 'ws']);
        $admitted = 0;
        while ($l->admit('tcp', 10, 1)) {
            $admitted++;
        }
        self::assertSame(8, $admitted, 'the flooded listener leaves the other one its reserved share');
        self::assertTrue($l->admit('ws', 10, 1));
        self::assertTrue($l->admit('ws', 10, 1));
        self::assertFalse($l->admit('ws', 10, 1), 'the global cap still holds');
    }

    public function testWithoutSharingTheWholePoolIsOpen(): void
    {
        $l = new ConnectionLimiter(10, 100, 0);
        $n = 0;
        while ($l->admit('tcp', 10, 1)) {
            $n++;
        }
        self::assertSame(10, $n);
    }

    public function testDescriptorBudgetStaysBelowSelectLimit(): void
    {
        $l = new ConnectionLimiter(1000, 1000, 0);
        $n = 0;
        while ($l->admit('x', 10000)) {
            $n++;
        }
        self::assertSame(intdiv(ConnectionLimiter::FD_BUDGET, 2), $n);
    }

    public function testStalledPeerIsDroppedAfterThreeZeroWrites(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $conn = new Connection($pair[0]);
        $conn->queue(str_repeat('x', 8 * 1024 * 1024));
        $result = 0;
        for ($i = 0; $i < 200 && $result !== false; $i++) {
            $result = $conn->flush(true);
        }
        self::assertFalse($result, 'a peer that never reads must eventually be dropped');
        self::assertGreaterThan(0, $conn->bufferedBytes());
        $conn->close();
        fclose($pair[1]);
    }

    public function testEventLoopRunsTimersAndStreamCallbacks(): void
    {
        $loop = new EventLoop();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $got = '';
        $loop->addReadStream($pair[1], static function ($s) use (&$got, $loop): void {
            $got .= (string) fread($s, 100);
            $loop->stop();
        });
        $loop->addTimer(0.02, static function () use ($pair): void {
            fwrite($pair[0], 'ping');
        });
        $loop->run();
        self::assertSame('ping', $got);
        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testEventLoopRoutesCallbackExceptionsToTheErrorHandler(): void
    {
        $loop = new EventLoop();
        $errors = 0;
        $loop->setErrorHandler(static function () use (&$errors): void {
            $errors++;
        });
        $loop->addTimer(0.0, static function (): void {
            throw new \RuntimeException('boom');
        });
        $loop->addTimer(0.03, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        self::assertSame(1, $errors);
    }
}
