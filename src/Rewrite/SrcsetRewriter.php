<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Rewrites the URLs inside a `srcset` or `imagesrcset` attribute value.
 *
 * The value is tokenized like the HTML specification does: candidates are separated by commas, a URL
 * is the next run of non-whitespace characters (so commas inside a URL survive unless they end it),
 * and descriptors (`2x`, `640w`) are kept byte for byte. `data:` URIs are skipped.
 */
final class SrcsetRewriter
{
    public function rewrite(string $value, UrlMapper $urls): string
    {
        $length = strlen($value);
        $out = '';
        $i = 0;

        while ($i < $length) {
            // Whitespace and commas between candidates.
            $gap = strspn($value, " \t\n\r\f,", $i);
            $out .= substr($value, $i, $gap);
            $i += $gap;
            if ($i >= $length) {
                break;
            }

            // The URL: a run of non-whitespace characters.
            $urlLength = strcspn($value, " \t\n\r\f", $i);
            $url = substr($value, $i, $urlLength);
            $i += $urlLength;

            // Trailing commas terminate the candidate and are not part of the URL.
            $trailing = '';
            $trimmed = rtrim($url, ',');
            if ($trimmed !== $url) {
                $trailing = substr($url, strlen($trimmed));
                $url = $trimmed;
            }
            $out .= (strncasecmp($url, 'data:', 5) === 0) ? $url : $urls->map($url);
            $out .= $trailing;

            if ($trailing !== '') {
                continue;
            }

            // Descriptors run until the next comma outside parentheses.
            $depth = 0;
            $start = $i;
            while ($i < $length) {
                $char = $value[$i];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')' && $depth > 0) {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    break;
                }
                $i++;
            }
            $out .= substr($value, $start, $i - $start);
        }

        return $out;
    }
}
