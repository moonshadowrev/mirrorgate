<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Upstream;

use MirrorGate\Log\NullLogger;
use MirrorGate\Upstream\ResponseHeaderParser;
use PHPUnit\Framework\TestCase;

final class ResponseHeaderParserTest extends TestCase
{
    /**
     * @param list<string> $lines lines WITHOUT terminators
     */
    private function feedAll(ResponseHeaderParser $p, array $lines): int
    {
        $last = ResponseHeaderParser::MORE;
        foreach ($lines as $line) {
            $last = $p->feed($line . "\r\n");
            if ($last !== ResponseHeaderParser::MORE) {
                return $last;
            }
        }

        return $last;
    }

    private function parser(bool $decode = false, bool $noBody = false): ResponseHeaderParser
    {
        return new ResponseHeaderParser(new NullLogger(), $decode, $noBody);
    }

    public function testBasicHead(): void
    {
        $p = $this->parser();
        $r = $this->feedAll($p, ['HTTP/1.1 200 OK', 'Content-Type: text/html', 'Content-Length: 12', 'Set-Cookie: a=1', 'Set-Cookie: b=2; Expires=Wed, 21 Oct 2026 07:28:00 GMT', '']);

        self::assertSame(ResponseHeaderParser::READY, $r);
        $head = $p->head();
        self::assertNotNull($head);
        self::assertSame(200, $head->status);
        self::assertSame('1.1', $head->httpVersion);
        self::assertSame(12, $head->contentLength);
        self::assertSame(12, $head->encodedContentLength);
        self::assertFalse($head->wasDecoded);
        self::assertNull($head->contentEncoding);
        self::assertCount(2, $head->headers->getAll('Set-Cookie'));
    }

    public function testHttp2StatusLineWithoutReason(): void
    {
        $p = $this->parser();
        $r = $this->feedAll($p, ['HTTP/2 204 ', 'x-a: b', '']);

        self::assertSame(ResponseHeaderParser::READY, $r);
        self::assertSame('2', $p->head()?->httpVersion);
    }

    public function testInterimResponsesAreSkipped(): void
    {
        $p = $this->parser();
        $r = $this->feedAll($p, ['HTTP/1.1 100 Continue', '', 'HTTP/1.1 103 Early Hints', 'Link: </a>; rel=preload', '', 'HTTP/1.1 200 OK', 'X-Final: yes', '']);

        self::assertSame(ResponseHeaderParser::READY, $r);
        self::assertSame(200, $p->head()?->status);
        self::assertFalse($p->head()->headers->has('Link'));
        self::assertTrue($p->head()->headers->has('X-Final'));
    }

    public function testSwitchingProtocolsFails(): void
    {
        $p = $this->parser();

        self::assertSame(ResponseHeaderParser::FAIL, $this->feedAll($p, ['HTTP/1.1 101 Switching Protocols']));
        self::assertSame('unexpected_101', $p->failure());
    }

    public function testObsFoldFails(): void
    {
        $p = $this->parser();
        $r = $this->feedAll($p, ['HTTP/1.1 200 OK', 'X-A: one', ' folded', '']);

        self::assertSame(ResponseHeaderParser::FAIL, $r);
        self::assertSame('obs_fold', $p->failure());
    }

    public function testTabFoldFails(): void
    {
        $p = $this->parser();

        self::assertSame(ResponseHeaderParser::FAIL, $this->feedAll($p, ['HTTP/1.1 200 OK', 'X-A: one', "\tfolded", '']));
    }

    public function testBadNamesAndControlCharactersAreDroppedAndTransferContinues(): void
    {
        $p = $this->parser();
        $r = $this->feedAll($p, [
            'HTTP/1.1 200 OK',
            'Bad Name: x',
            'X-Ctl: a' . "\x01" . 'b',
            'X-Nul: a' . "\0" . 'b',
            'X-Bare-Cr: a' . "\r" . 'b',
            'no colon here',
            ': empty name',
            'X-Good: ok',
            '',
        ]);

        self::assertSame(ResponseHeaderParser::READY, $r);
        self::assertSame([['X-Good', 'ok']], $p->head()?->headers->all());
    }

    public function testHopByHopAndConnectionTokensAreRemoved(): void
    {
        $p = $this->parser();
        $this->feedAll($p, [
            'HTTP/1.1 200 OK',
            'Connection: close, X-Hop',
            'X-Hop: 1',
            'Keep-Alive: timeout=5',
            'Transfer-Encoding: chunked',
            'Upgrade: h2c',
            'Proxy-Authenticate: Basic',
            'Trailer: X-T',
            'X-Keep: 1',
            '',
        ]);

        self::assertSame([['X-Keep', '1']], $p->head()?->headers->all());
    }

    public function testChunkedResponseHasNoContentLength(): void
    {
        $p = $this->parser();
        $this->feedAll($p, ['HTTP/1.1 200 OK', 'Transfer-Encoding: chunked', 'Content-Length: 99', '']);
        $head = $p->head();

        self::assertNotNull($head);
        self::assertNull($head->contentLength);
        self::assertNull($head->encodedContentLength);
        self::assertFalse($head->headers->has('Content-Length'));
    }

    public function testDecodedBodyStripsEncodingAndLengthButRecordsEncodedLength(): void
    {
        $p = $this->parser(decode: true);
        $this->feedAll($p, ['HTTP/1.1 200 OK', 'Content-Encoding: gzip', 'Content-Length: 321', 'Vary: Accept-Encoding', '']);
        $head = $p->head();

        self::assertNotNull($head);
        self::assertTrue($head->wasDecoded);
        self::assertNull($head->contentLength);
        self::assertSame(321, $head->encodedContentLength);
        self::assertNull($head->contentEncoding);
        self::assertFalse($head->headers->has('Content-Encoding'));
        self::assertFalse($head->headers->has('Content-Length'));
        self::assertTrue($head->headers->has('Vary'));
    }

    public function testPassthroughKeepsEncodingAndReportsIt(): void
    {
        $p = $this->parser(decode: false);
        $this->feedAll($p, ['HTTP/1.1 200 OK', 'Content-Encoding: GZip', 'Content-Length: 321', '']);
        $head = $p->head();

        self::assertNotNull($head);
        self::assertFalse($head->wasDecoded);
        self::assertSame('gzip', $head->contentEncoding);
        self::assertSame(321, $head->contentLength);
        self::assertTrue($head->headers->has('Content-Encoding'));
    }

    public function testIdentityEncodingIsNotDecoding(): void
    {
        $p = $this->parser(decode: true);
        $this->feedAll($p, ['HTTP/1.1 200 OK', 'Content-Encoding: identity', 'Content-Length: 5', '']);
        $head = $p->head();

        self::assertNotNull($head);
        self::assertFalse($head->wasDecoded);
        self::assertNull($head->contentEncoding);
        self::assertSame(5, $head->contentLength);
    }

    public function testHeadResponseKeepsLengthAndEncoding(): void
    {
        $p = $this->parser(decode: true, noBody: true);
        $this->feedAll($p, ['HTTP/1.1 200 OK', 'Content-Encoding: gzip', 'Content-Length: 50', '']);
        $head = $p->head();

        self::assertNotNull($head);
        self::assertFalse($head->wasDecoded);
        self::assertSame(50, $head->contentLength);
        self::assertSame('gzip', $head->contentEncoding);
    }

    public function testNoContentAndNotModifiedHaveNoLength(): void
    {
        foreach ([204, 304] as $status) {
            $p = $this->parser();
            $this->feedAll($p, ['HTTP/1.1 ' . $status . ' X', 'Content-Length: 10', '']);

            self::assertNull($p->head()?->contentLength);
        }
    }

    /**
     * @return array<string,array{0:list<string>}>
     */
    public static function badContentLengths(): array
    {
        return [
            'differing duplicates' => [['Content-Length: 2', 'Content-Length: 3']],
            'differing list' => [['Content-Length: 2, 3']],
            'non digit' => [['Content-Length: 2x']],
            'signed' => [['Content-Length: -1']],
            'hex' => [['Content-Length: 0x10']],
            'empty' => [['Content-Length: ']],
            'huge' => [['Content-Length: 99999999999999999999']],
        ];
    }

    /**
     * @param list<string> $lines
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badContentLengths')]
    public function testInvalidContentLengthFails(array $lines): void
    {
        $p = $this->parser();

        self::assertSame(ResponseHeaderParser::FAIL, $this->feedAll($p, ['HTTP/1.1 200 OK', ...$lines, '']));
        self::assertSame('content_length', $p->failure());
    }

    public function testIdenticalDuplicateContentLengthIsAccepted(): void
    {
        $p = $this->parser();
        $r = $this->feedAll($p, ['HTTP/1.1 200 OK', 'Content-Length: 4', 'Content-Length: 4, 4', '']);

        self::assertSame(ResponseHeaderParser::READY, $r);
        self::assertSame(4, $p->head()?->contentLength);
    }

    public function testGarbageStatusLineFails(): void
    {
        foreach (['HTTP/1.1 abc OK', 'HTTP/1.1 99 X', 'HTTP/1.1 600 X', 'HTTP/x 200 OK', 'HTTP/1.1200 OK'] as $line) {
            $p = $this->parser();

            self::assertSame(ResponseHeaderParser::FAIL, $this->feedAll($p, [$line]), $line);
        }
    }

    public function testTrailersAfterTheFinalBlockAreIgnored(): void
    {
        $p = $this->parser();
        $this->feedAll($p, ['HTTP/1.1 200 OK', 'X-A: 1', '']);

        self::assertSame(ResponseHeaderParser::MORE, $p->feed("X-Trailer: 1\r\n"));
        self::assertSame(ResponseHeaderParser::MORE, $p->feed("\r\n"));
        self::assertSame(200, $p->head()?->status);
    }

    public function testOversizedHeaderBlockFails(): void
    {
        $p = $this->parser();
        $p->feed("HTTP/1.1 200 OK\r\n");

        self::assertSame(ResponseHeaderParser::FAIL, $p->feed('X-Big: ' . \str_repeat('a', 70000) . "\r\n"));
    }

    public function testTooManyHeadersFail(): void
    {
        $p = $this->parser();
        $p->feed("HTTP/1.1 200 OK\r\n");
        $result = ResponseHeaderParser::MORE;
        for ($i = 0; $i < 250 && $result === ResponseHeaderParser::MORE; ++$i) {
            $result = $p->feed('X-H' . $i . ": v\r\n");
        }

        self::assertSame(ResponseHeaderParser::FAIL, $result);
    }

    public function testBareLfTerminatorsAreAccepted(): void
    {
        $p = $this->parser();
        $p->feed("HTTP/1.1 200 OK\n");
        $p->feed("X-A: b\n");

        self::assertSame(ResponseHeaderParser::READY, $p->feed("\n"));
        self::assertSame('b', $p->head()?->headers->get('X-A'));
    }

    public function testConnectionTokensCannotStripProtectedHeaders(): void
    {
        $p = $this->parser();
        $this->feedAll($p, [
            'HTTP/1.1 200 OK', 'Connection: Content-Type, Set-Cookie, Location, Content-Encoding, Content-Length, X-Hop',
            'Content-Type: text/html', 'Set-Cookie: a=1', 'Location: /x', 'Content-Encoding: gzip', 'Content-Length: 4', 'X-Hop: 1', '',
        ]);
        $head = $p->head();
        self::assertNotNull($head);
        self::assertSame('text/html', $head->headers->get('Content-Type'));
        self::assertSame('gzip', $head->headers->get('Content-Encoding'));
        self::assertSame('/x', $head->headers->get('Location'));
        self::assertTrue($head->headers->has('Set-Cookie'));
        self::assertFalse($head->headers->has('X-Hop'));
    }
}
