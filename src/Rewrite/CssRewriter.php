<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Rewrites URLs in CSS text: `url()` in all quoting styles, `@import "..."` and the string forms
 * inside `image-set()`. Relative URLs, `data:` URIs and `sourceMappingURL` comments are untouched,
 * and no bare host name inside CSS is ever replaced.
 */
final class CssRewriter
{
    /** Quoted string body that honors backslash escapes. */
    private const DQ = '"([^"\\\\\r\n]*+(?:\\\\.[^"\\\\\r\n]*+)*+)"';
    private const SQ = "'([^'\\\\\r\n]*+(?:\\\\.[^'\\\\\r\n]*+)*+)'";

    /** Longest image-set() argument list that is scanned; a longer or unterminated one is left alone. */
    private const IMAGE_SET_MAX = 65536;

    private bool $failed = false;

    public function rewrite(string $css, UrlMapper $urls): string
    {
        if ($css === '' || !$urls->replacer()->mightContain($css) && !$this->needsPrefix($urls)) {
            return $css;
        }

        $pattern = '~'
            . '(?<url>url\(\s*(?:' . self::DQ . '|' . self::SQ . '|([^)\s"\']*))\s*\))'
            . '|(?<imp>@import\s*(?:' . self::DQ . '|' . self::SQ . '))'
            . '~i';

        // image-set() string arguments first (url() tokens inside it are left to the pass below).
        $css = $this->rewriteImageSets($css, $urls);

        $result = preg_replace_callback(
            $pattern,
            fn (array $m): string => $this->replaceToken($m, $urls),
            $css,
        );
        if ($result === null || preg_last_error() !== PREG_NO_ERROR) {
            $this->failed = true;
            return $css;
        }
        return $result;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function clearFailure(): void
    {
        $this->failed = false;
    }

    private function needsPrefix(UrlMapper $urls): bool
    {
        // A mapper with a root prefix maps root-relative URLs even when no origin host is present.
        return $urls->map('/x') !== '/x';
    }

    /** @param array<int|string,string> $m */
    private function replaceToken(array $m, UrlMapper $urls): string
    {
        if (($m['imp'] ?? '') !== '') {
            return $this->replaceImport($m[0], $urls);
        }
        return $this->replaceUrlFunction($m[0], $urls);
    }

    private function replaceUrlFunction(string $token, UrlMapper $urls): string
    {
        $pattern = '~^(url\(\s*)(?:"([^"\\\\\r\n]*+(?:\\\\.[^"\\\\\r\n]*+)*+)"|\'([^\'\\\\\r\n]*+(?:\\\\.[^\'\\\\\r\n]*+)*+)\'|([^)\s"\']*+))(\s*\))$~is';
        if (preg_match($pattern, $token, $p) !== 1) {
            return $token;
        }
        $open = $p[1];
        $close = $p[5];
        if ($p[2] !== '' || $this->isQuoted($token, '"')) {
            return $open . '"' . $urls->map($p[2]) . '"' . $close;
        }
        if ($p[3] !== '' || $this->isQuoted($token, "'")) {
            return $open . "'" . $urls->map($p[3]) . "'" . $close;
        }
        return $open . $urls->map($p[4]) . $close;
    }

    private function isQuoted(string $token, string $quote): bool
    {
        $inner = ltrim(substr($token, 4));
        return $inner !== '' && $inner[0] === $quote;
    }

    private function replaceImport(string $token, UrlMapper $urls): string
    {
        if (preg_match('~^(@import\s*)(["\'])(.*)\2$~is', $token, $p) !== 1) {
            return $token;
        }
        return $p[1] . $p[2] . $urls->map($p[3]) . $p[2];
    }

    /**
     * Rewrites the bare quoted strings of every image-set() with a linear scan. A regular expression
     * would backtrack quadratically on an unterminated image-set(, so the scan is bounded instead and
     * gives up on the remainder of the document after the first region it cannot close.
     */
    private function rewriteImageSets(string $css, UrlMapper $urls): string
    {
        $out = '';
        $pos = 0;
        $length = strlen($css);
        while ($pos < $length && preg_match('~(?:-webkit-)?image-set\(~i', $css, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $start = $m[0][1] + strlen($m[0][0]); // first byte after "("
            $end = $this->imageSetEnd($css, $start, $length);
            if ($end === null) {
                break;
            }
            $out .= substr($css, $pos, $start - $pos) . $this->mapImageSetArguments(substr($css, $start, $end - $start), $urls);
            $pos = $end;
        }

        return $pos === 0 ? $css : $out . substr($css, $pos);
    }

    /** Offset of the ")" that closes an image-set( whose arguments start at $start, or null. */
    private function imageSetEnd(string $css, int $start, int $length): ?int
    {
        $limit = min($length, $start + self::IMAGE_SET_MAX);
        $depth = 1;
        $p = $start;
        while ($p < $limit) {
            $p += strcspn($css, "\"'()", $p, $limit - $p);
            if ($p >= $limit) {
                return null;
            }
            $c = $css[$p];
            if ($c === '"' || $c === "'") {
                $close = $this->stringEnd($css, $p, $limit);
                if ($close === null) {
                    return null;
                }
                $p = $close + 1;
            } elseif ($c === '(') {
                $depth++;
                $p++;
            } else {
                $depth--;
                if ($depth === 0) {
                    return $p;
                }
                $p++;
            }
        }

        return null;
    }

    /** Offset of the quote that closes the string opened at $open, or null (newline or limit reached first). */
    private function stringEnd(string $css, int $open, int $limit): ?int
    {
        $quote = $css[$open];
        $p = $open + 1;
        while ($p < $limit) {
            $p += strcspn($css, $quote . "\\\r\n", $p, $limit - $p);
            if ($p >= $limit) {
                return null;
            }
            $c = $css[$p];
            if ($c === $quote) {
                return $p;
            }
            if ($c === '\\') {
                $p += 2;
                continue;
            }

            return null;
        }

        return null;
    }

    /** Maps the quoted strings of an image-set() argument list; url(...) tokens are skipped. */
    private function mapImageSetArguments(string $inner, UrlMapper $urls): string
    {
        $out = '';
        $p = 0;
        $length = strlen($inner);
        while ($p < $length) {
            $q = $p + strcspn($inner, "\"'uU", $p);
            if ($q >= $length) {
                break;
            }
            $c = $inner[$q];
            if ($c === 'u' || $c === 'U') {
                if (strncasecmp(substr($inner, $q, 4), 'url(', 4) === 0 && ($q === 0 || !ctype_alnum($inner[$q - 1]))) {
                    $close = strpos($inner, ')', $q);
                    $q = $close === false ? $length : $close + 1;
                    $out .= substr($inner, $p, $q - $p);
                } else {
                    $out .= substr($inner, $p, $q + 1 - $p);
                    $q++;
                }
                $p = $q;
                continue;
            }
            $close = $this->stringEnd($inner, $q, $length);
            if ($close === null) {
                break;
            }
            $out .= substr($inner, $p, $q + 1 - $p) . $urls->map(substr($inner, $q + 1, $close - $q - 1)) . $c;
            $p = $close + 1;
        }

        return $out . substr($inner, $p);
    }
}
