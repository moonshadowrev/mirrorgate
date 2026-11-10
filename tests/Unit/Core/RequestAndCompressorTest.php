<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Core;

use MirrorGate\Http\Compressor;
use MirrorGate\Http\HttpException;
use MirrorGate\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestAndCompressorTest extends TestCase
{
    public function testHeaderNamesWithUnderscoresAreDropped(): void
    {
        $req = \MirrorGate\Http\Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '1.1.1.1'],
            ['Host' => 'ormantek.com', 'X_Forwarded_For' => '127.0.0.1', 'X_Http_Method_Override' => 'DELETE', 'Content_Length' => '5', 'X-Ok' => '1'],
        );
        self::assertFalse($req->headers->has('X_Forwarded_For'));
        self::assertFalse($req->headers->has('X_Http_Method_Override'));
        self::assertFalse($req->headers->has('Content_Length'));
        self::assertSame('1', $req->headers->get('X-Ok'));
    }

    public function testFromGlobals(): void
    {
        $r = Request::fromGlobals([
            'REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/x/../y?q=1', 'REMOTE_ADDR' => '1.2.3.4',
            'HTTP_HOST' => 'Ormantek.com', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9', 'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '12', 'HTTPS' => 'on',
        ]);
        self::assertSame('POST', $r->method);
        self::assertSame('/y?q=1', $r->target->pathAndQuery());
        self::assertSame('9.9.9.9', $r->headers->get('X-Forwarded-For'));
        self::assertSame(12, $r->contentLength);
        self::assertTrue($r->viaTls);
        self::assertSame('Ormantek.com', $r->hostHeader);
    }

    public function testRejectsHostileInput(): void
    {
        foreach ([
            [['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => 'nope'], null],
            [['REQUEST_METHOD' => 'GE T', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '1.1.1.1'], null],
            [['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '1.1.1.1'], ['X-Evil' => "a\r\nSet-Cookie: x"]],
        ] as [$server, $headers]) {
            try {
                Request::fromGlobals($server, $headers);
                self::fail('expected HttpException');
            } catch (HttpException $e) {
                self::assertSame(400, $e->status);
            }
        }
    }

    public function testDecodeIsBounded(): void
    {
        $big = str_repeat('hello world ', 5000);
        self::assertSame($big, Compressor::decode(gzencode($big), 'gzip', 1000000));
        self::assertNull(Compressor::decode(gzencode($big), 'gzip', 1000));
        self::assertNull(Compressor::decode('garbage', 'gzip', 1000));
        self::assertSame($big, Compressor::decode(gzdeflate($big), 'deflate', 1000000));
        self::assertSame($big, Compressor::decode(gzcompress($big), 'deflate', 1000000));
        self::assertNull(Compressor::decode('x', 'unknown', 10));
    }

    public function testNegotiate(): void
    {
        self::assertSame('gzip', Compressor::negotiate('gzip, br'));
        self::assertSame('identity', Compressor::negotiate('gzip;q=0'));
        self::assertSame('identity', Compressor::negotiate(null));
        self::assertSame('gzip', Compressor::negotiate('*;q=0.5'));
        self::assertSame('identity', Compressor::negotiate('br'));
    }
}
