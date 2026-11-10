<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Upstream;

use MirrorGate\Http\NormalizedTarget;
use MirrorGate\Tests\Support\Upstream\UpstreamTestConfig;
use MirrorGate\Upstream\UpstreamUrlBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpstreamUrlBuilderTest extends TestCase
{
    public function testBuildsFromConfigurationOnly(): void
    {
        $b = new UpstreamUrlBuilder(UpstreamTestConfig::origin());
        $t = $b->build(new NormalizedTarget('/a/b', 'x=1&y=%20'), 'overlord.team');

        self::assertSame('https://overlord.team/a/b?x=1&y=%20', $t->url);
        self::assertSame('https', $t->scheme);
        self::assertSame('overlord.team', $t->host);
        self::assertSame(443, $t->port);
        self::assertSame('overlord.team', $t->hostHeader);
        self::assertSame('/a/b?x=1&y=%20', $t->pathAndQuery);
    }

    public function testNonDefaultPortAppearsInUrlAndHostHeader(): void
    {
        $b = new UpstreamUrlBuilder(UpstreamTestConfig::origin('https', 'overlord.team', 8443));
        $t = $b->build(new NormalizedTarget('/', ''), 'OVERLORD.TEAM.');

        self::assertSame('https://overlord.team:8443/', $t->url);
        self::assertSame('overlord.team:8443', $t->hostHeader);
    }

    public function testExtraOriginIsAcceptedAndGetsItsOwnHostHeader(): void
    {
        $b = new UpstreamUrlBuilder(UpstreamTestConfig::origin(extraOrigins: ['api.overlord.team']));
        $t = $b->build(new NormalizedTarget('/v1', ''), 'api.overlord.team');

        self::assertSame('https://api.overlord.team/v1', $t->url);
        self::assertSame('api.overlord.team', $t->hostHeader);
    }

    public function testUnknownHostIsRejected(): void
    {
        $b = new UpstreamUrlBuilder(UpstreamTestConfig::origin());
        $this->expectException(\InvalidArgumentException::class);
        $b->build(new NormalizedTarget('/', ''), 'evil.example');
    }

    public function testIpv6OriginIsBracketed(): void
    {
        $b = new UpstreamUrlBuilder(UpstreamTestConfig::origin('http', '::1', 8080, true, null, null, [8080], [], true));
        $t = $b->build(new NormalizedTarget('/x', ''), '::1');

        self::assertSame('http://[::1]:8080/x', $t->url);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function hostileTargets(): array
    {
        return [
            'space in path' => ['/a b', ''],
            'CR in path' => ["/a\rb", ''],
            'LF in query' => ['/a', "x=1\ny=2"],
            'NUL in path' => ["/a\0b", ''],
            'fragment in path' => ['/a#b', ''],
            'fragment in query' => ['/a', 'x=1#frag'],
            'backslash in path' => ['/a\\b', ''],
            'relative path' => ['a/b', ''],
            'empty path' => ['', ''],
            'DEL in query' => ['/a', "x=\x7f"],
        ];
    }

    #[DataProvider('hostileTargets')]
    public function testHostileTargetsAreRejected(string $path, string $query): void
    {
        $b = new UpstreamUrlBuilder(UpstreamTestConfig::origin());
        $this->expectException(\InvalidArgumentException::class);
        $b->build(new NormalizedTarget($path, $query), 'overlord.team');
    }
}
