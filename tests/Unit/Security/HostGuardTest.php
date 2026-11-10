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
        yield 'exact' => ['overload.ir', true];
        yield 'uppercase' => ['OVERLOAD.IR', true];
        yield 'default https port' => ['overload.ir:443', true];
        yield 'default http port' => ['overload.ir:80', true];
        yield 'odd port' => ['overload.ir:1', false];
        yield 'high port' => ['overload.ir:65535', false];
        yield 'alias' => ['www.overload.ir', true];
        yield 'trailing dot' => ['overload.ir.', true];
        yield 'origin host' => ['overlord.team', false];
        yield 'evil' => ['evil.com', false];
        yield 'suffix trick' => ['overload.ir.evil.com', false];
        yield 'prefix trick' => ['evil-overload.ir', false];
        yield 'userinfo' => ['overload.ir@evil.com', false];
        yield 'backslash' => ['evil.com\\@overload.ir', false];
        yield 'comma list' => ['overload.ir,evil.com', false];
        yield 'space' => ['overload.ir evil.com', false];
        yield 'ip literal' => ['127.0.0.1', false];
        yield 'empty' => ['', false];
        yield 'missing' => [null, false];
        yield 'too long' => [str_repeat('a', 400) . '.overload.ir', false];
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
