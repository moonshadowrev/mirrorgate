<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Http\HttpException;
use MirrorGate\Security\RequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestValidatorTest extends TestCase
{
    /**
     * @param list<array{0:string,1:string}> $headers
     */
    private function statusOf(string $method, array $headers = [], string $path = '/', array $cfg = []): int
    {
        $validator = new RequestValidator(Fixtures::upstream($cfg));
        try {
            $validator->validate(Fixtures::request($method, $path, $headers));
        } catch (HttpException $e) {
            return $e->status;
        }
        return 0;
    }

    public function testPlainRequestsPass(): void
    {
        self::assertSame(0, $this->statusOf('GET', [['Accept', '*/*'], ['User-Agent', 'x']]));
        self::assertSame(0, $this->statusOf('POST', [['Content-Length', '10'], ['Content-Type', 'text/plain']]));
        self::assertSame(0, $this->statusOf('POST', [['Content-Length', '10, 10']]));
        self::assertSame(0, $this->statusOf('POST', [['Transfer-Encoding', 'Chunked']]));
        self::assertSame(0, $this->statusOf('GET', [['Upgrade', 'h2c']]));
        self::assertSame(0, $this->statusOf('POST', [['Expect', '100-continue']]));
    }

    /** @return iterable<string,array{0:string,1:list<array{0:string,1:string}>,2:int}> */
    public static function rejections(): iterable
    {
        yield 'TRACE' => ['TRACE', [], 405];
        yield 'CONNECT' => ['CONNECT', [], 405];
        yield 'PUT not allowed' => ['PUT', [], 405];
        yield 'unknown method' => ['PROPFIND', [], 501];
        yield 'lowercase garbage method' => ['G E T', [], 501];
        yield 'CL and TE' => ['POST', [['Content-Length', '5'], ['Transfer-Encoding', 'chunked']], 400];
        yield 'conflicting CL' => ['POST', [['Content-Length', '5'], ['Content-Length', '6']], 400];
        yield 'conflicting CL list' => ['POST', [['Content-Length', '5, 6']], 400];
        yield 'non numeric CL' => ['POST', [['Content-Length', '5a']], 400];
        yield 'negative CL' => ['POST', [['Content-Length', '-1']], 400];
        yield 'plus CL' => ['POST', [['Content-Length', '+5']], 400];
        yield 'empty CL' => ['POST', [['Content-Length', '']], 400];
        yield 'huge CL' => ['POST', [['Content-Length', '99999999999999999999999']], 413];
        yield 'oversize CL' => ['POST', [['Content-Length', '1048577']], 413];
        yield 'TE gzip' => ['POST', [['Transfer-Encoding', 'gzip']], 501];
        yield 'TE gzip chunked' => ['POST', [['Transfer-Encoding', 'gzip, chunked']], 501];
        yield 'TE chunked twice' => ['POST', [['Transfer-Encoding', 'chunked'], ['Transfer-Encoding', 'chunked']], 501];
        yield 'upgrade websocket' => ['GET', [['Upgrade', 'websocket'], ['Connection', 'Upgrade']], 501];
        yield 'upgrade mixed' => ['GET', [['Upgrade', 'h2c, websocket']], 501];
        yield 'duplicate host' => ['GET', [['Host', 'a.test'], ['Host', 'b.test']], 400];
        yield 'host comma' => ['GET', [['Host', 'a.test,b.test']], 400];
        yield 'expect other' => ['POST', [['Expect', 'fancy']], 417];
        yield 'bad header name' => ['GET', [['Bad Name', 'x']], 400];
    }

    /**
     * @param list<array{0:string,1:string}> $headers
     */
    #[DataProvider('rejections')]
    public function testRejections(string $method, array $headers, int $expected): void
    {
        if ($method === 'G E T' || $headers !== [] && $headers[0][0] === 'Bad Name') {
            // HeaderBag implementations reject such names at construction time already.
            try {
                $status = $this->statusOf($method, $headers, '/', ['allowedMethods' => ['GET', 'HEAD', 'POST', 'OPTIONS']]);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
                return;
            }
            self::assertSame($expected, $status);
            return;
        }
        self::assertSame($expected, $this->statusOf($method, $headers));
    }

    public function testAllowHeaderOn405(): void
    {
        $validator = new RequestValidator(Fixtures::upstream(['allowedMethods' => ['GET', 'HEAD', 'TRACE', 'get', 'BOGUS']]));
        try {
            $validator->validate(Fixtures::request('DELETE'));
            self::fail('Expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame([['Allow', 'GET, HEAD']], $e->headers);
        }
    }

    public function testLengthLimits(): void
    {
        self::assertSame(414, $this->statusOf('GET', [], '/' . str_repeat('a', 100), ['maxUrlLength' => 50]));
        $many = [];
        for ($i = 0; $i < 10; $i++) {
            $many[] = ['X-H' . $i, 'v'];
        }
        self::assertSame(431, $this->statusOf('GET', $many, '/', ['maxHeaderCount' => 5]));
        self::assertSame(431, $this->statusOf('GET', [['X-Big', str_repeat('a', 5000)]]));
    }
}
