<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Rewrites the target URLs of an RFC 8288 `Link` header value.
 *
 * The value is tokenized instead of split on commas, because URLs and quoted parameters may
 * contain commas. Parameters are preserved byte for byte, except `anchor`, which is a URL too.
 */
final class LinkHeaderRewriter
{
    public function rewrite(string $value, UrlMapper $urls, bool $keepCanonical): string
    {
        $length = strlen($value);
        $out = '';
        $i = 0;

        while ($i < $length) {
            $char = $value[$i];
            if ($char !== '<') {
                $out .= $char;
                $i++;
                continue;
            }

            $close = strpos($value, '>', $i + 1);
            if ($close === false) {
                $out .= substr($value, $i);
                break;
            }
            $target = substr($value, $i + 1, $close - $i - 1);

            // Parameters run until the next comma that is not inside a quoted string.
            $j = $close + 1;
            $inQuote = false;
            while ($j < $length) {
                $c = $value[$j];
                if ($inQuote) {
                    if ($c === '\\' && $j + 1 < $length) {
                        $j += 2;
                        continue;
                    }
                    if ($c === '"') {
                        $inQuote = false;
                    }
                } elseif ($c === '"') {
                    $inQuote = true;
                } elseif ($c === ',') {
                    break;
                }
                $j++;
            }
            $params = substr($value, $close + 1, $j - $close - 1);

            $isCanonical = preg_match('~;\s*rel\s*=\s*(?:"[^"]*\bcanonical\b[^"]*"|canonical\b)~i', $params) === 1;
            $mappedTarget = ($keepCanonical && $isCanonical) ? $target : $urls->map($target);
            $mappedParams = preg_replace_callback(
                '~(;\s*anchor\s*=\s*")([^"]*)(")~i',
                static fn (array $m): string => $m[1] . $urls->map($m[2]) . $m[3],
                $params,
            ) ?? $params;

            $out .= '<' . $mappedTarget . '>' . $mappedParams;
            $i = $j;
        }

        return $out;
    }
}
