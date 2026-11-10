<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Core;

use MirrorGate\Http\BadRequestException;
use MirrorGate\Http\PathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathNormalizerTest extends TestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function validProvider(): array
    {
        return [
            'dot segments' => ['/a/../b', '/b'],
            'leading slashes collapse' => ['//evil.com/x', '/evil.com/x'],
            'encoded dots' => ['/%2e%2e/etc', '/etc'],
            'encoded slash stays' => ['/a%2fb', '/a%2Fb'],
            'unreserved decoded' => ['/a%7ebc', '/a~bc'],
            'trailing dot segment' => ['/a/./b/.', '/a/b/'],
            'root' => ['/', '/'],
            'raw utf8 encoded' => ['/é', '/%C3%A9'],
            'no double decode' => ['/%252e', '/%252e'],
        ];
    }

    #[DataProvider('validProvider')]
    public function testNormalizesPaths(string $raw, string $expected): void
    {
        self::assertSame($expected, PathNormalizer::normalize($raw)->path);
    }

    /** @return array<string,array{0:string}> */
    public static function invalidProvider(): array
    {
        return [
            'nul' => ["/a\0"], 'encoded nul' => ['/a%00'], 'space' => ['/a b'], 'backslash' => ['/a\\b'],
            'encoded backslash' => ['/a%5c'], 'encoded cr' => ['/a%0d'], 'encoded lf' => ['/a%0A'],
            'absolute form' => ['http://x/'], 'asterisk' => ['*'], 'fragment' => ['/a#f'], 'bad percent' => ['/a%zz'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsHostileTargets(string $raw): void
    {
        $this->expectException(BadRequestException::class);
        PathNormalizer::normalize($raw);
    }

    public function testQueryPassesThroughByteExact(): void
    {
        $t = PathNormalizer::normalize('/p?a=%2e%2e&b=%41');
        self::assertSame('a=%2e%2e&b=%41', $t->query);
        self::assertSame('/p?a=%2e%2e&b=%41', $t->pathAndQuery());
    }

    public function testQueryRejectsControlBytes(): void
    {
        $this->expectException(BadRequestException::class);
        PathNormalizer::normalize("/p?a=1\r\nX: y");
    }
}
