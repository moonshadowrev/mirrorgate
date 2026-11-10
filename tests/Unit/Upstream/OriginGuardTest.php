<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Upstream;

use MirrorGate\Log\NullLogger;
use MirrorGate\Tests\Support\Upstream\FakeDnsResolver;
use MirrorGate\Tests\Support\Upstream\UpstreamTestConfig;
use MirrorGate\Upstream\DnsFailureException;
use MirrorGate\Upstream\OriginGuard;
use MirrorGate\Upstream\SsrfBlockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginGuardTest extends TestCase
{
    private function guard(FakeDnsResolver $dns, bool $allowPrivate = false, ?string $resolveIp = null, ?string $proxy = null, string $host = 'overlord.team'): OriginGuard
    {
        return new OriginGuard(
            UpstreamTestConfig::origin(host: $host, allowPrivate: $allowPrivate, resolveIp: $resolveIp, proxy: $proxy, extraOrigins: ['api.overlord.team']),
            $dns,
            new NullLogger(),
        );
    }

    public function testPinsOnePublicAddress(): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => ['93.184.216.34', '2606:2800:220:1::1']]);
        $r = $this->guard($dns)->resolve('Overlord.Team.', 443);

        self::assertSame('overlord.team', $r->host);
        self::assertSame(443, $r->port);
        self::assertSame('93.184.216.34', $r->ip);
        self::assertSame('overlord.team:443:93.184.216.34', $r->curlResolveEntry);
    }

    public function testPrefersIpv4EvenWhenListedAfterIpv6(): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => ['2606:2800:220:1::1', '93.184.216.34']]);

        self::assertSame('93.184.216.34', $this->guard($dns)->resolve('overlord.team', 443)->ip);
    }

    public function testIpv6OnlyHostIsPinnedBracketless(): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => ['2606:2800:220:1::1']]);
        $r = $this->guard($dns)->resolve('overlord.team', 443);

        self::assertSame('overlord.team:443:2606:2800:220:1::1', $r->curlResolveEntry);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function blockedAnswers(): array
    {
        return [
            'metadata' => ['169.254.169.254'],
            'loopback' => ['127.0.0.1'],
            'private 10' => ['10.0.0.1'],
            'private 172' => ['172.16.5.5'],
            'private 192' => ['192.168.1.1'],
            'cgnat' => ['100.64.0.1'],
            'unspecified' => ['0.0.0.0'],
            'multicast' => ['224.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'v6 loopback' => ['::1'],
            'v6 ipv4-compatible' => ['::7f00:1'],
            'v4-mapped private' => ['::ffff:10.0.0.1'],
            '6to4' => ['2002:7f00:1::1'],
            'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
            'ula' => ['fd00::1'],
            'link-local v6' => ['fe80::1'],
            'doc v6' => ['2001:db8::1'],
        ];
    }

    #[DataProvider('blockedAnswers')]
    public function testAnyBlockedAnswerBlocksTheHost(string $ip): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => ['93.184.216.34', $ip]]);

        $this->expectException(SsrfBlockedException::class);
        $this->guard($dns)->resolve('overlord.team', 443);
    }

    #[DataProvider('blockedAnswers')]
    public function testBlockedAnswerAloneIsBlocked(string $ip): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => [$ip]]);

        $this->expectException(SsrfBlockedException::class);
        $this->guard($dns)->resolve('overlord.team', 443);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function alwaysBlockedForms(): array
    {
        return [
            'nat64 metadata' => ['64:ff9b::a9fe:a9fe'],
            '6to4 metadata' => ['2002:a9fe:a9fe::'],
            'compat metadata' => ['::a9fe:a9fe'],
            'mapped metadata' => ['::ffff:169.254.169.254'],
            'link-local v6' => ['fe80::1'],
            'imds v6' => ['fd00:ec2::254'],
            'unspecified' => ['::'],
        ];
    }

    #[DataProvider('alwaysBlockedForms')]
    public function testAlwaysBlockedFloorHoldsWithAllowPrivate(string $ip): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => [$ip]]);

        $this->expectException(SsrfBlockedException::class);
        $this->guard($dns, allowPrivate: true)->resolve('overlord.team', 443);
    }

    public function testAllowPrivateStillAcceptsOrdinaryPrivateAddress(): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => ['10.0.0.5']]);

        self::assertSame('10.0.0.5', $this->guard($dns, allowPrivate: true)->resolve('overlord.team', 443)->ip);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function hostileHosts(): array
    {
        return [
            'metadata ip' => ['169.254.169.254'],
            'v6 loopback bracketed' => ['[::1]'],
            'v6 loopback' => ['::1'],
            'v6 compat' => ['::7f00:1'],
            'mapped' => ['::ffff:10.0.0.1'],
            'decimal' => ['2130706433'],
            'hex' => ['0x7f.1'],
            'octal' => ['0177.0.0.1'],
            'localhost upper trailing dot' => ['LOCALHOST.'],
            'userinfo' => ['overlord.team@evil.example'],
            'userinfo reverse' => ['evil.example@overlord.team'],
            'backslash' => ['evil.example\\@overlord.team'],
            'slash' => ['overlord.team/evil'],
            'space' => ['overlord.team evil'],
            'crlf' => ["overlord.team\r\nX: y"],
            'nul' => ["overlord.team\0"],
            'percent' => ['overlord.team%2f'],
            'empty' => [''],
            'not allowlisted public' => ['example.org'],
            'lookalike suffix' => ['evil-overlord.team'],
            'lookalike prefix' => ['overlord.team.evil.example'],
        ];
    }

    #[DataProvider('hostileHosts')]
    public function testHostileHostsAreBlocked(string $host): void
    {
        $dns = new FakeDnsResolver([
            'localhost' => ['127.0.0.1'],
            'example.org' => ['93.184.216.34'],
            'evil-overlord.team' => ['93.184.216.34'],
        ]);

        $this->expectException(SsrfBlockedException::class);
        $this->guard($dns)->resolve($host, 443);
    }

    public function testPortOutsideAllowlistIsBlocked(): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => ['93.184.216.34']]);

        $this->expectException(SsrfBlockedException::class);
        $this->guard($dns)->resolve('overlord.team', 6379);
    }

    public function testAllowPrivateOriginPermitsPrivateButNeverMetadata(): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => ['10.1.2.3']]);
        self::assertSame('10.1.2.3', $this->guard($dns, allowPrivate: true)->resolve('overlord.team', 443)->ip);

        $dns = new FakeDnsResolver(['overlord.team' => ['127.0.0.1']]);
        self::assertSame('127.0.0.1', $this->guard($dns, allowPrivate: true)->resolve('overlord.team', 443)->ip);

        foreach (['169.254.169.254', '::ffff:169.254.169.254', 'fe80::1', '0.0.0.0', '::'] as $ip) {
            $dns = new FakeDnsResolver(['overlord.team' => [$ip]]);
            try {
                $this->guard($dns, allowPrivate: true)->resolve('overlord.team', 443);
                self::fail('metadata/link-local address must stay blocked: ' . $ip);
            } catch (SsrfBlockedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testIpLiteralOriginNeedsAllowPrivateWhenPrivate(): void
    {
        $guard = new OriginGuard(
            UpstreamTestConfig::origin('http', '127.0.0.1', 8081, false, null, null, [8081], [], true),
            new FakeDnsResolver(),
            new NullLogger(),
        );

        $this->expectException(SsrfBlockedException::class);
        $guard->resolve('127.0.0.1', 8081);
    }

    public function testIpLiteralOriginWorksWithAllowPrivate(): void
    {
        $guard = new OriginGuard(
            UpstreamTestConfig::origin('http', '127.0.0.1', 8081, true, null, null, [8081], [], true),
            new FakeDnsResolver(),
            new NullLogger(),
        );
        $r = $guard->resolve('127.0.0.1', 8081);

        self::assertSame('127.0.0.1', $r->ip);
        self::assertSame('', $r->curlResolveEntry);
    }

    public function testResolveIpPinsPrimaryOriginWithoutDns(): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => ['10.0.0.1']]);
        $r = $this->guard($dns, resolveIp: '93.184.216.34')->resolve('overlord.team', 443);

        self::assertSame('93.184.216.34', $r->ip);
        self::assertSame(0, $dns->calls);
    }

    public function testResolveIpStillMustBePublic(): void
    {
        $dns = new FakeDnsResolver();

        $this->expectException(SsrfBlockedException::class);
        $this->guard($dns, resolveIp: '10.0.0.5')->resolve('overlord.team', 443);
    }

    public function testResolveIpIsFallbackForExtraOriginWhenDnsFails(): void
    {
        $dns = new FakeDnsResolver();
        $dns->fail = true;
        $r = $this->guard($dns, resolveIp: '93.184.216.34')->resolve('api.overlord.team', 443);

        self::assertSame('93.184.216.34', $r->ip);
    }

    public function testUnresolvableHostWithoutFallbackIsDnsFailureNotSsrf(): void
    {
        $dns = new FakeDnsResolver();

        $this->expectException(DnsFailureException::class);
        $this->guard($dns)->resolve('overlord.team', 443);
    }

    public function testOutboundProxyDisablesLocalDnsAndPinning(): void
    {
        $dns = new FakeDnsResolver(['overlord.team' => ['10.0.0.1']]);
        $r = $this->guard($dns, proxy: 'socks5h://proxy.example:1080')->resolve('overlord.team', 443);

        self::assertSame('', $r->ip);
        self::assertSame('', $r->curlResolveEntry);
        self::assertSame(0, $dns->calls);
    }

    public function testOutboundProxyStillEnforcesHostAndPortAllowlist(): void
    {
        $dns = new FakeDnsResolver();
        $guard = $this->guard($dns, proxy: 'http://proxy.example:3128');

        try {
            $guard->resolve('evil.example', 443);
            self::fail('host allowlist must apply with a proxy');
        } catch (SsrfBlockedException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(SsrfBlockedException::class);
        $guard->resolve('overlord.team', 25);
    }

    public function testIsAllowedHost(): void
    {
        $guard = $this->guard(new FakeDnsResolver());

        self::assertTrue($guard->isAllowedHost('overlord.team'));
        self::assertTrue($guard->isAllowedHost('API.overlord.team.'));
        self::assertFalse($guard->isAllowedHost('x.overlord.team'));
        self::assertFalse($guard->isAllowedHost("overlord.team\r\n"));
    }
}
