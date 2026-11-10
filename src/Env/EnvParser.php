<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Env;

/**
 * Minimal .env parser. No variable expansion, no side effects: values are never written to
 * $_ENV, $_SERVER or putenv().
 */
final class EnvParser
{
    /** First line of the self-protecting `.env.php` form. */
    public const PHP_GUARD_LINE = '<?php http_response_code(404); exit; ?>';

    /**
     * @return array{values: array<string,string>, warnings: list<string>}
     */
    public static function parse(string $content): array
    {
        $values = [];
        $warnings = [];

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if ($lines === false) {
            return ['values' => [], 'warnings' => ['Unable to split the environment file into lines.']];
        }

        foreach ($lines as $i => $rawLine) {
            $lineNo = $i + 1;
            if ($i === 0 && rtrim($rawLine) === self::PHP_GUARD_LINE) {
                continue;
            }
            $line = trim($rawLine);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ') || str_starts_with($line, "export\t")) {
                $line = ltrim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                $warnings[] = sprintf('Line %d is not a KEY=VALUE pair.', $lineNo);
                continue;
            }
            $key = rtrim(substr($line, 0, $eq));
            if (preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) !== 1) {
                $warnings[] = sprintf('Line %d has an invalid variable name.', $lineNo);
                continue;
            }
            $rest = ltrim(substr($line, $eq + 1));
            $parsed = self::parseValue($rest);
            if ($parsed === null) {
                $warnings[] = sprintf('Line %d has an unterminated or malformed quoted value.', $lineNo);
                continue;
            }
            $values[$key] = $parsed;
        }

        return ['values' => $values, 'warnings' => $warnings];
    }

    private static function parseValue(string $rest): ?string
    {
        if ($rest === '') {
            return '';
        }
        $first = $rest[0];
        if ($first === '"') {
            $out = '';
            $len = strlen($rest);
            for ($i = 1; $i < $len; $i++) {
                $c = $rest[$i];
                if ($c === '\\') {
                    if ($i + 1 >= $len) {
                        return null;
                    }
                    $n = $rest[$i + 1];
                    $i++;
                    $out .= match ($n) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        '"' => '"',
                        '\\' => '\\',
                        '$' => '$',
                        default => '\\' . $n,
                    };
                    continue;
                }
                if ($c === '"') {
                    $tail = trim(substr($rest, $i + 1));
                    if ($tail !== '' && $tail[0] !== '#') {
                        return null;
                    }
                    return $out;
                }
                $out .= $c;
            }
            return null;
        }
        if ($first === "'") {
            $end = strpos($rest, "'", 1);
            if ($end === false) {
                return null;
            }
            $tail = trim(substr($rest, $end + 1));
            if ($tail !== '' && $tail[0] !== '#') {
                return null;
            }
            return substr($rest, 1, $end - 1);
        }
        // Unquoted: a comment starts at whitespace followed by '#'.
        if (preg_match('/\s#/', $rest, $m, PREG_OFFSET_CAPTURE) === 1) {
            $rest = substr($rest, 0, $m[0][1]);
        }
        return trim($rest);
    }
}
