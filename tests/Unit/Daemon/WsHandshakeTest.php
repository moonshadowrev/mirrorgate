<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Daemon;

use MirrorGate\Daemon\ProxyProtocol;
use MirrorGate\Daemon\WsHandshake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DaemonTestSupport.php';

final class WsHandshakeTest extends TestCase
{
    public function testAcceptValueMatchesRfc6455(): void
    {
        self::assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', WsHandshake::acceptFor('dGhlIHNhbXBsZSBub25jZQ=='));
    }

    public function testKeyValidation(): void
    {
        self::assertTrue(WsHandshake::isValidKey('dGhlIHNhbXBsZSBub25jZQ=='));
        self::assertFalse(WsHandshake::isValidKey('abc='));
        self::assertFalse(WsHandshake::isValidKey(''));
    }

    public function testParsesARequest(): void
    {
        $req = WsHandshake::parseRequest("GET /chat?x=1 HTTP/1.1\r\nHost: a.example\r\nX-A:  v  ");
        self::assertSame('/chat?x=1', $req['target']);
        self::assertSame(['v'], $req['headers']['x-a']);
    }

    /** @return iterable<string,array{string}> */
    public static function badRequests(): iterable
    {
        yield 'bare LF' => ["GET / HTTP/1.1\r\nHost: a\nX: y"];
        yield 'bare CR' => ["GET / HTTP/1.1\r\nHost: a\rX: y"];
        yield 'NUL' => ["GET / HTTP/1.1\r\nHost: a\0b"];
        yield 'obs-fold' => ["GET / HTTP/1.1\r\nHost: a\r\n folded: x"];
        yield 'absolute-form' => ["GET http://evil.example/ HTTP/1.1\r\nHost: a"];
        yield 'POST' => ["POST / HTTP/1.1\r\nHost: a"];
        yield 'HTTP/1.0' => ["GET / HTTP/1.0\r\nHost: a"];
        yield 'space in name' => ["GET / HTTP/1.1\r\nHo st: a"];
        yield 'control char in value' => ["GET / HTTP/1.1\r\nHost: a\x01b"];
        yield 'backslash in target' => ["GET /a\\b HTTP/1.1\r\nHost: a"];
    }

    #[DataProvider('badRequests')]
    public function testRejectsMalformedRequests(string $block): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WsHandshake::parseRequest($block);
    }

    public function testDropsInternalCookies(): void
    {
        self::assertSame('a=1; b=3', WsHandshake::filterCookies('a=1; mg_session=zzz; MG_x=2; b=3'));
    }

    public function testClientIpDerivation(): void
    {
        $trusted = static fn (string $ip): bool => str_starts_with($ip, '10.');
        self::assertSame('5.6.7.8', WsHandshake::deriveClientIp('10.0.0.1', ['1.2.3.4, 5.6.7.8, 10.0.0.2'], 'x-forwarded-for', $trusted));
        self::assertSame('10.0.0.1', WsHandshake::deriveClientIp('10.0.0.1', ['not-an-ip'], 'x-forwarded-for', $trusted));
        self::assertSame('10.0.0.1', WsHandshake::deriveClientIp('10.0.0.1', [], 'x-forwarded-for', $trusted));
    }

    public function testProxyProtocolV1(): void
    {
        self::assertSame('198.51.100.7', ProxyProtocol::parseV1('PROXY TCP4 198.51.100.7 10.0.0.1 51234 443'));
        self::assertNull(ProxyProtocol::parseV1('PROXY UNKNOWN'));
        $this->expectException(\InvalidArgumentException::class);
        ProxyProtocol::parseV1('PROXY TCP4 999.1.1.1 10.0.0.1 1 2');
    }

    public function testPathPolicyNormalizesAndBlocks(): void
    {
        $blocked = ['/admin/*', '/blocked/path'];
        self::assertSame('/ws?x=1', WsHandshake::applyPathPolicy('/ws?x=1', $blocked, '/__mirrorgate'));
        self::assertSame('/admin/x', WsHandshake::applyPathPolicy('/ws/../ok/../admin/x', [], '/__mirrorgate'));
        self::assertSame([404, 'path_blocked'], WsHandshake::applyPathPolicy('/ws/../admin/x', $blocked, '/__mirrorgate'));
        self::assertSame([404, 'path_blocked'], WsHandshake::applyPathPolicy('/blocked/path', $blocked, '/__mirrorgate'));
        self::assertSame([404, 'path_blocked'], WsHandshake::applyPathPolicy('/%61dmin/x', $blocked, '/__mirrorgate'));
        self::assertSame([404, 'path_blocked'], WsHandshake::applyPathPolicy('/__mirrorgate/stats', [], '/__mirrorgate'));
        self::assertSame([400, 'bad_target'], WsHandshake::applyPathPolicy('/a%5Cb', $blocked, '/__mirrorgate'));
    }
}
