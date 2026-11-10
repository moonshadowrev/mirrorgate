<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Core;

use MirrorGate\Env\EnvParser;
use PHPUnit\Framework\TestCase;

final class EnvParserTest extends TestCase
{
    public function testParsesQuotesCommentsAndGuardLine(): void
    {
        $src = "<?php http_response_code(404); exit; ?>\n# c\nexport A=1\nB=\"x\\ny # not\" # c\nC='lit \$x'\nD=hello # c\nE=\nbad line\nF=\"unterminated\nA=2\r\n";
        $r = EnvParser::parse($src);
        self::assertSame(['A' => '2', 'B' => "x\ny # not", 'C' => 'lit $x', 'D' => 'hello', 'E' => ''], $r['values']);
        self::assertCount(2, $r['warnings']);
    }

    public function testBomAndInvalidKeys(): void
    {
        $r = EnvParser::parse("\xEF\xBB\xBFA=1\nlower=2\n1X=3\n");
        self::assertSame(['A' => '1'], $r['values']);
        self::assertCount(2, $r['warnings']);
    }
}
