<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Upstream;

use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\HttpException;
use MirrorGate\Http\NormalizedTarget;
use MirrorGate\Log\NullLogger;
use MirrorGate\Tests\Support\Upstream\FakeDnsResolver;
use MirrorGate\Tests\Support\Upstream\RecordingSink;
use MirrorGate\Tests\Support\Upstream\UpstreamTestConfig;
use MirrorGate\Upstream\ErrorKind;
use MirrorGate\Upstream\OriginGuard;
use MirrorGate\Upstream\RetryPolicy;
use MirrorGate\Upstream\SinkDecision;
use MirrorGate\Upstream\UpstreamClient;
use MirrorGate\Upstream\UpstreamRequest;
use MirrorGate\Upstream\UpstreamTarget;
use MirrorGate\Upstream\UpstreamUrlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real cURL path against two local origins on 127.0.0.1:
 * a `php -S` origin (normal HTTP) and a raw socket origin (malformed replies).
 */
final class UpstreamClientTest extends TestCase
{
    /** @var list<resource> */
    private static array $processes = [];

    private static int $httpPort = 0;
    private static int $rawPort = 0;

    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable.');
        }

        self::$httpPort = self::freePort();
        self::$rawPort = self::freePort();
        $support = \dirname(__DIR__, 2) . '/Support/Upstream';
        $null = ['file', '/dev/null', 'w'];

        $pipes = [];
        $a = \proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$httpPort, $support . '/origin-router.php'], [1 => $null, 2 => $null], $pipes);
        $b = \proc_open([PHP_BINARY, $support . '/raw-origin.php', (string) self::$rawPort], [1 => $null, 2 => $null], $pipes);
        if (!\is_resource($a) || !\is_resource($b)) {
            self::markTestSkipped('Cannot start local test origins.');
        }
        self::$processes = [$a, $b];

        // Wait until both listen.
        foreach ([self::$httpPort, self::$rawPort] as $port) {
            $deadline = \microtime(true) + 5.0;
            while (\microtime(true) < $deadline) {
                $s = @\stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
                if ($s !== false) {
                    \fclose($s);
                    continue 2;
                }
                \usleep(50000);
            }
            self::markTestSkipped('Local test origin did not start.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$processes as $process) {
            \proc_terminate($process);
        }
        self::$processes = [];
    }

    private static function freePort(): int
    {
        $s = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($s);
        $name = (string) \stream_socket_get_name($s, false);
        \fclose($s);

        return (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }

    /**
     * @param array<string,scalar> $upstreamOptions
     */
    private function client(int $port, array $upstreamOptions = []): UpstreamClient
    {
        $origin = UpstreamTestConfig::origin('http', '127.0.0.1', $port, true, null, null, [$port], [], true);
        $upstream = UpstreamTestConfig::upstream(...$upstreamOptions);

        return new UpstreamClient($upstream, $origin, new OriginGuard($origin, new FakeDnsResolver(), new NullLogger()), new RetryPolicy($upstream), new NullLogger());
    }

    /**
     * @param list<array{0:string,1:string}> $headers
     * @param resource|null                  $body
     */
    private function request(int $port, string $method, string $pathAndQuery, array $headers = [], mixed $body = null, ?int $length = null, bool $decode = false, int $maxBuffer = 1048576, bool $streamingHint = false): UpstreamRequest
    {
        $origin = UpstreamTestConfig::origin('http', '127.0.0.1', $port, true, null, null, [$port], [], true);
        $parts = \explode('?', $pathAndQuery, 2);
        $target = (new UpstreamUrlBuilder($origin))->build(new NormalizedTarget($parts[0], $parts[1] ?? ''), '127.0.0.1');

        return new UpstreamRequest($method, $target, HeaderBag::fromPairs($headers), $body, $length, $decode, $maxBuffer, null, null, $streamingHint);
    }

    /**
     * @return resource
     */
    private function stream(string $data)
    {
        $h = \fopen('php://temp', 'w+');
        self::assertNotFalse($h);
        \fwrite($h, $data);
        \rewind($h);

        return $h;
    }

    public function testBufferedGetReturnsBodyAndPreservesSetCookie(): void
    {
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'GET', '/ok'), new RecordingSink());

        self::assertTrue($r->completed);
        self::assertNull($r->error);
        self::assertSame('hello', $r->body);
        self::assertSame(1, $r->attempts);
        self::assertNotNull($r->head);
        self::assertCount(2, $r->head->headers->getAll('Set-Cookie'));
    }

    public function testDecodeContentInflatesAndRecordsEncodedLength(): void
    {
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'GET', '/gz', [], null, null, true), new RecordingSink());

        self::assertTrue($r->completed);
        self::assertSame(\str_repeat('abc', 1000), $r->body);
        self::assertNotNull($r->head);
        self::assertTrue($r->head->wasDecoded);
        self::assertNull($r->head->contentLength);
        self::assertGreaterThan(0, $r->head->encodedContentLength);
        self::assertFalse($r->head->headers->has('Content-Encoding'));
    }

    public function testPostBodyIsSentWithExactContentLengthAndHostFromTarget(): void
    {
        $port = self::$httpPort;
        $r = $this->client($port)->fetch(
            $this->request($port, 'POST', '/echo?x=1&y=%20z', [['Content-Type', 'text/plain'], ['X-Custom', 'v'], ['Host', 'evil.example'], ['Content-Length', '3'], ['Connection', 'upgrade']], $this->stream('payload-123'), 11),
            new RecordingSink(),
        );

        $json = \json_decode((string) $r->body, true);
        self::assertIsArray($json);
        self::assertSame('POST', $json['method']);
        self::assertSame('payload-123', $json['body']);
        self::assertSame('x=1&y=%20z', $json['qs']);
        self::assertSame('127.0.0.1:' . $port, $json['headers']['host']);
        self::assertSame('11', $json['headers']['content-length']);
        self::assertSame('v', $json['headers']['x-custom']);
        self::assertArrayNotHasKey('expect', $json['headers']);
    }

    public function testZeroLengthPostAnnouncesContentLengthZero(): void
    {
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'POST', '/echo', [], null, 0), new RecordingSink());
        $json = \json_decode((string) $r->body, true);

        self::assertIsArray($json);
        self::assertSame('0', $json['headers']['content-length']);
    }

    public function testDeclaredOversizeBodyThrows413BeforeAnyIo(): void
    {
        $client = $this->client(1, ['maxRequestBodyBytes' => 10]);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(413);
        $client->fetch($this->request(self::$httpPort, 'POST', '/echo', [], $this->stream('x'), 11), new RecordingSink());
    }

    public function testCountedOversizeBodyIsRequestTooLarge(): void
    {
        $client = $this->client(self::$httpPort, ['maxRequestBodyBytes' => 1000]);
        $r = $client->fetch($this->request(self::$httpPort, 'POST', '/echo', [], $this->stream(\str_repeat('a', 5000)), null), new RecordingSink());

        self::assertSame(ErrorKind::RequestTooLarge, $r->error?->kind);
    }

    public function testRedirectsAreNeverFollowed(): void
    {
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'GET', '/redir'), new RecordingSink());

        self::assertNull($r->error);
        self::assertSame(302, $r->head?->status);
        self::assertSame('http://evil.example/', $r->head->headers->get('Location'));
    }

    public function testOriginErrorStatusIsNotATransportError(): void
    {
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'GET', '/five'), new RecordingSink());

        self::assertNull($r->error);
        self::assertSame(503, $r->head?->status);
    }

    public function testHeadUsesNobodyAndKeepsContentLength(): void
    {
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'HEAD', '/head'), new RecordingSink());

        self::assertTrue($r->completed);
        self::assertSame(1234, $r->head?->contentLength);
        self::assertSame('', $r->body);
    }

    public function testStreamingDeliversChunksToTheSink(): void
    {
        $sink = new RecordingSink(SinkDecision::Stream);
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'GET', '/sse', [], null, null, false, 0, true), $sink);

        self::assertTrue($r->completed);
        self::assertNull($r->body);
        self::assertSame("data: 0\n\ndata: 1\n\ndata: 2\n\n", $sink->streamed);
    }

    public function testSinkReturningFalseAbortsAsClientAborted(): void
    {
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'GET', '/big'), new RecordingSink(SinkDecision::Stream, true));

        self::assertSame(ErrorKind::ClientAborted, $r->error?->kind);
        self::assertFalse($r->completed);
    }

    public function testDiscardDecisionAbortsWithoutBody(): void
    {
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'GET', '/ok'), new RecordingSink(SinkDecision::Discard));

        self::assertSame(ErrorKind::ClientAborted, $r->error?->kind);
        self::assertNotNull($r->head);
        self::assertNull($r->body);
    }

    public function testBufferOverflowIsResponseTooLarge(): void
    {
        $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, 'GET', '/big', [], null, null, false, 1000), new RecordingSink());

        self::assertSame(ErrorKind::ResponseTooLarge, $r->error?->kind);
        self::assertNotNull($r->head);
    }

    public function testTotalTimeoutMapsToTimeout(): void
    {
        $started = \microtime(true);
        $r = $this->client(self::$httpPort, ['timeout' => 1, 'retries' => 0])->fetch($this->request(self::$httpPort, 'GET', '/slow'), new RecordingSink());

        self::assertSame(ErrorKind::Timeout, $r->error?->kind);
        self::assertLessThan(3.5, \microtime(true) - $started);
    }

    public function testConnectionRefusedIsRetriedForIdempotentMethodsOnly(): void
    {
        $dead = self::freePort();
        $client = $this->client($dead, ['retries' => 2]);

        $get = $client->fetch($this->request($dead, 'GET', '/x'), new RecordingSink());
        self::assertSame(ErrorKind::Connect, $get->error?->kind);
        self::assertSame(3, $get->attempts);

        $post = $client->fetch($this->request($dead, 'POST', '/x', [], null, 0), new RecordingSink());
        self::assertSame(1, $post->attempts);
    }

    public function testObsFoldedResponseHeaderFailsAsProtocol(): void
    {
        $r = $this->client(self::$rawPort)->fetch($this->request(self::$rawPort, 'GET', '/fold'), new RecordingSink());

        self::assertSame(ErrorKind::Protocol, $r->error?->kind);
    }

    public function testControlCharacterHeaderIsDroppedButTransferContinues(): void
    {
        $r = $this->client(self::$rawPort)->fetch($this->request(self::$rawPort, 'GET', '/ctl'), new RecordingSink());

        self::assertTrue($r->completed);
        self::assertFalse($r->head?->headers->has('X-Bad'));
        self::assertSame('ok', $r->head?->headers->get('X-Good'));
    }

    public function testTruncatedBodyIsPartial(): void
    {
        $r = $this->client(self::$rawPort)->fetch($this->request(self::$rawPort, 'GET', '/short'), new RecordingSink());

        self::assertSame(ErrorKind::Partial, $r->error?->kind);
        self::assertFalse($r->completed);
        self::assertNull($r->body);
    }

    public function testEarlyHintsAreSkipped(): void
    {
        $r = $this->client(self::$rawPort)->fetch($this->request(self::$rawPort, 'GET', '/interim'), new RecordingSink());

        self::assertTrue($r->completed);
        self::assertSame(200, $r->head?->status);
        self::assertFalse($r->head->headers->has('Link'));
        self::assertFalse($r->head->headers->has('X-Hop'));
    }

    public function testTargetsOutsideConfigurationAreBlocked(): void
    {
        $port = self::$httpPort;
        $client = $this->client($port);
        $cases = [
            'foreign host' => new UpstreamTarget('http', '10.0.0.1', $port, 'http://10.0.0.1:' . $port . '/', '10.0.0.1:' . $port, '/'),
            'url differs from host' => new UpstreamTarget('http', '127.0.0.1', $port, 'http://10.0.0.1:' . $port . '/', '127.0.0.1:' . $port, '/'),
            'crlf in url' => new UpstreamTarget('http', '127.0.0.1', $port, 'http://127.0.0.1:' . $port . "/ok\r\nX: y", '127.0.0.1:' . $port, '/ok'),
            'crlf in host header' => new UpstreamTarget('http', '127.0.0.1', $port, 'http://127.0.0.1:' . $port . '/ok', "127.0.0.1\r\nX: y", '/ok'),
            'wrong scheme' => new UpstreamTarget('https', '127.0.0.1', $port, 'https://127.0.0.1:' . $port . '/ok', '127.0.0.1:' . $port, '/ok'),
            'file scheme' => new UpstreamTarget('file', '127.0.0.1', $port, 'file:///etc/passwd', '127.0.0.1', '/etc/passwd'),
        ];

        foreach ($cases as $name => $target) {
            $r = $client->fetch(new UpstreamRequest('GET', $target, new HeaderBag(), null, null, false, 100), new RecordingSink());
            self::assertSame(ErrorKind::SsrfBlocked, $r->error?->kind, $name);
        }
    }

    public function testMethodsOutsideTheKnownSetAreUnsupported(): void
    {
        foreach (['TRACE', 'CONNECT', 'PROPFIND', 'get'] as $method) {
            $r = $this->client(self::$httpPort)->fetch($this->request(self::$httpPort, $method, '/ok'), new RecordingSink());
            self::assertSame(ErrorKind::Unsupported, $r->error?->kind, $method);
        }
    }
}
