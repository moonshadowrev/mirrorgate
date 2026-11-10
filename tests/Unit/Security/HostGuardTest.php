<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Http\HttpException;
use MirrorGate\Security\HostGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostGuardTest extends TestCase
{
    /** @return iterable<string,array{0:?string,1:bool}> */
    public static function hosts(): iterable
    {
        yield 'exact' => ['ormantek.com', true];
        yield 'uppercase' => ['ORMANTEK.COM', true];
        yield 'default https port' => ['ormantek.com:443', true];
        yield 'default http port' => ['ormantek.com:80', true];
        yield 'odd port' => ['ormantek.com:1', false];
        yield 'high port' => ['ormantek.com:65535', false];
        yield 'alias' => ['www.ormantek.com', true];
        yield 'trailing dot' => ['ormantek.com.', true];
        yield 'origin host' => ['overlord.team', false];
        yield 'evil' => ['evil.com', false];
        yield 'suffix trick' => ['ormantek.com.evil.com', false];
        yield 'prefix trick' => ['evil-ormantek.com', false];
        yield 'userinfo' => ['ormantek.com@evil.com', false];
        yield 'backslash' => ['evil.com\\@ormantek.com', false];
        yield 'comma list' => ['ormantek.com,evil.com', false];
        yield 'space' => ['ormantek.com evil.com', false];
        yield 'ip literal' => ['127.0.0.1', false];
        yield 'empty' => ['', false];
        yield 'missing' => [null, false];
        yield 'too long' => [str_repeat('a', 400) . '.ormantek.com', false];
    }

    #[DataProvider('hosts')]
    public function testHostValidation(?string $host, bool $allowed): void
    {
        $guard = new HostGuard(Fixtures::config()->mirror);
        $request = Fixtures::request(host: $host);
        if ($allowed) {
            $guard->assertAllowed($request);
            $this->addToAssertionCount(1);
            return;
        }
        try {
            $guard->assertAllowed($request);
            self::fail('Expected 421');
        } catch (HttpException $e) {
            self::assertSame(421, $e->status);
            if ($host !== null && $host !== '') {
                self::assertStringNotContainsString($host, $e->getMessage(), 'offending host is never echoed');
            }
        }
    }
}
