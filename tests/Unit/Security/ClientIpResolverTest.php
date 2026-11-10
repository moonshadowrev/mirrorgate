<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Security\ClientIpResolver;
use PHPUnit\Framework\TestCase;

final class ClientIpResolverTest extends TestCase
{
    private function resolver(array $trusted, string $header = 'x-forwarded-for'): ClientIpResolver
    {
        return new ClientIpResolver(Fixtures::config(['trustedProxies' => $trusted, 'trustedProxyHeader' => $header])->security);
    }

    public function testForwardedHeadersIgnoredWithoutTrustedProxies(): void
    {
        $r = Fixtures::request(headers: [['X-Forwarded-For', '1.2.3.4']], remote: '198.51.100.7');
        self::assertSame('198.51.100.7', $this->resolver([])->resolve($r));
    }

    public function testForwardedHeadersIgnoredFromUntrustedPeer(): void
    {
        $r = Fixtures::request(headers: [['X-Forwarded-For', '1.2.3.4']], remote: '198.51.100.7');
        self::assertSame('198.51.100.7', $this->resolver(['10.0.0.0/8'])->resolve($r));
    }

    public function testRightmostUntrustedEntryWins(): void
    {
        $r = Fixtures::request(headers: [['X-Forwarded-For', '9.9.9.9, 203.0.113.50, 10.0.0.2']], remote: '10.0.0.1');
        self::assertSame('203.0.113.50', $this->resolver(['10.0.0.0/8'])->resolve($r));
    }

    public function testSpoofedLeftEntriesAreNotTrusted(): void
    {
        // The client prepends 6.6.6.6; the real client address was appended by the trusted proxy.
        $r = Fixtures::request(headers: [['X-Forwarded-For', '6.6.6.6'], ['X-Forwarded-For', '203.0.113.50']], remote: '10.0.0.1');
        self::assertSame('203.0.113.50', $this->resolver(['10.0.0.0/8'])->resolve($r));
    }

    public function testInvalidEntryFallsBackToRemoteAddr(): void
    {
        $r = Fixtures::request(headers: [['X-Forwarded-For', '203.0.113.50, not-an-ip']], remote: '10.0.0.1');
        self::assertSame('10.0.0.1', $this->resolver(['10.0.0.0/8'])->resolve($r));
        $r = Fixtures::request(headers: [['X-Forwarded-For', '203.0.113.50:8080']], remote: '10.0.0.1');
        self::assertSame('10.0.0.1', $this->resolver(['10.0.0.0/8'])->resolve($r));
    }

    public function testAllTrustedFallsBackToRemoteAddr(): void
    {
        $r = Fixtures::request(headers: [['X-Forwarded-For', '10.0.0.5, 10.0.0.6']], remote: '10.0.0.1');
        self::assertSame('10.0.0.1', $this->resolver(['10.0.0.0/8'])->resolve($r));
    }

    public function testIpv6AndMappedHandling(): void
    {
        $r = Fixtures::request(headers: [['X-Forwarded-For', '2001:4860:4860:0:0:0:0:8888']], remote: '10.0.0.1');
        self::assertSame('2001:4860:4860::8888', $this->resolver(['10.0.0.0/8'])->resolve($r));
        $r = Fixtures::request(headers: [['X-Forwarded-For', '::ffff:203.0.113.4']], remote: '10.0.0.1');
        self::assertSame('203.0.113.4', $this->resolver(['10.0.0.0/8'])->resolve($r));
        $r = Fixtures::request(headers: [['X-Forwarded-For', '203.0.113.4']], remote: '::ffff:10.0.0.1');
        self::assertSame('203.0.113.4', $this->resolver(['10.0.0.0/8'])->resolve($r));
    }

    public function testSingleValueHeaders(): void
    {
        $r = Fixtures::request(headers: [['CF-Connecting-IP', '203.0.113.77']], remote: '10.0.0.1');
        self::assertSame('203.0.113.77', $this->resolver(['10.0.0.0/8'], 'cf-connecting-ip')->resolve($r));
        $r = Fixtures::request(headers: [['CF-Connecting-IP', '203.0.113.77'], ['CF-Connecting-IP', '1.1.1.1']], remote: '10.0.0.1');
        self::assertSame('10.0.0.1', $this->resolver(['10.0.0.0/8'], 'cf-connecting-ip')->resolve($r));
        $r = Fixtures::request(headers: [['X-Real-IP', 'garbage']], remote: '10.0.0.1');
        self::assertSame('10.0.0.1', $this->resolver(['10.0.0.0/8'], 'x-real-ip')->resolve($r));
        // A header other than the configured one is ignored.
        $r = Fixtures::request(headers: [['X-Real-IP', '203.0.113.77']], remote: '10.0.0.1');
        self::assertSame('10.0.0.1', $this->resolver(['10.0.0.0/8'], 'cf-connecting-ip')->resolve($r));
    }
}
