<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Daemon;

use MirrorGate\Daemon\ListenerKind;
use MirrorGate\Daemon\ListenerSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DaemonTestSupport.php';

final class ListenerSpecTest extends TestCase
{
    public function testParsesWebSocketListener(): void
    {
        $spec = ListenerSpec::parse(
            'ws://127.0.0.1:8090->wss://origin.example:443?sni=origin.example&host=origin.example',
            DaemonTestSupport::config(),
        );
        self::assertSame(ListenerKind::Ws, $spec->kind);
        self::assertSame('wss', $spec->targetScheme);
        self::assertSame(['https://mirror.example'], $spec->originAllow);
        self::assertFalse($spec->allowNoOrigin);
        self::assertSame('ws@127.0.0.1:8090', $spec->id());
    }

    public function testGrpcDefaultsToH2Alpn(): void
    {
        $spec = ListenerSpec::parse('grpc://[::1]:50051->h2c://127.0.0.1:50052', DaemonTestSupport::config());
        self::assertSame(['h2'], $spec->alpn);
        self::assertSame('::1', $spec->bindHost);
    }

    public function testUdpUsesSessionTtlAsIdleTimeout(): void
    {
        $spec = ListenerSpec::parse('udp://127.0.0.1:5353->udp://origin.example:53', DaemonTestSupport::config(['udpSessionTtl' => 33]));
        self::assertSame(33, $spec->idleTimeout);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidListeners(): iterable
    {
        yield 'open relay target' => ['tcp://127.0.0.1:9000->tcp://evil.example:80'];
        yield 'unknown option' => ['tcp://127.0.0.1:9000->tcp://origin.example:80?bogus=1'];
        yield 'duplicate option' => ['tcp://127.0.0.1:9000->tcp://origin.example:80?idle=5&idle=6'];
        yield 'public bind without opt-in' => ['tcp://0.0.0.0:9000->tcp://origin.example:80'];
        yield 'udp public bind without client allowlist' => ['udp://0.0.0.0:9000->udp://origin.example:53'];
        yield 'scheme not valid for kind' => ['tcp://127.0.0.1:9000->ws://origin.example:80'];
        yield 'tls_cert without tls_key' => ['tcp://127.0.0.1:9000->tcp://origin.example:80?tls_cert=/a.pem'];
        yield 'decimal IP' => ['tcp://127.0.0.1:9000->tcp://2130706433:80'];
        yield 'hex IP' => ['tcp://127.0.0.1:9000->tcp://0x7f.1:80'];
        yield 'userinfo' => ['tcp://127.0.0.1:9000->tcp://a@origin.example:80'];
        yield 'whitespace' => ['tcp://127.0.0.1:9000 ->tcp://origin.example:80'];
        yield 'port zero' => ['tcp://127.0.0.1:0->tcp://origin.example:80'];
        yield 'hostname bind' => ['tcp://localhost:9000->tcp://origin.example:80'];
        yield 'missing arrow' => ['tcp://127.0.0.1:9000'];
        yield 'ws-only option on tcp' => ['tcp://127.0.0.1:9000->tcp://origin.example:80?origin_allow=https://a.example'];
        yield 'proxy_protocol on ws' => ['ws://127.0.0.1:9000->ws://origin.example:80?proxy_protocol=1'];
    }

    #[DataProvider('invalidListeners')]
    public function testRejectsInvalidListeners(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ListenerSpec::parse($raw, DaemonTestSupport::config());
    }

    public function testWebSocketListenerNeedsAnOriginPolicy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ListenerSpec::parse('ws://127.0.0.1:9000->ws://origin.example:80', DaemonTestSupport::config(['wsOriginAllow' => []]));
    }

    public function testPublicBindWithOptIn(): void
    {
        $cfg = DaemonTestSupport::config(['allowPublicBind' => true, 'udpClientAllow' => ['203.0.113.0/24']]);
        self::assertSame('0.0.0.0', ListenerSpec::parse('udp://0.0.0.0:9000->udp://origin.example:53', $cfg)->bindHost);
        self::assertNotSame([], ListenerSpec::parse('tcp://0.0.0.0:80->tcp://origin.example:80', $cfg)->warnings());
    }
}
