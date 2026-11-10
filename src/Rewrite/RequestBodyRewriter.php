<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Request-direction rewriting (mirror host to origin host) for form-urlencoded and JSON bodies.
 *
 * Only URL forms are rewritten (`https://mirror`, `//mirror`, `https:\/\/mirror`); bare host names in
 * user input are never touched. The same boundary guards as in HostReplacer apply, the replacement
 * is built from configuration only, and any regular expression failure returns the input unchanged.
 */
final class RequestBodyRewriter
{
    private ?string $pattern = null;
    private bool $patternBuilt = false;

    public function __construct(private readonly HostMapper $mapper)
    {
    }

    public function rewriteJson(string $json): string
    {
        return $this->replace($json);
    }

    public function rewriteForm(string $form): string
    {
        if ($form === '' || $this->pattern() === null) {
            return $form;
        }
        $pairs = explode('&', $form);
        foreach ($pairs as $index => $pair) {
            $equals = strpos($pair, '=');
            if ($equals === false) {
                continue;
            }
            $decoded = urldecode(substr($pair, $equals + 1));
            $replaced = $this->replace($decoded);
            if ($replaced !== $decoded) {
                $pairs[$index] = substr($pair, 0, $equals + 1) . urlencode($replaced);
            }
        }
        return implode('&', $pairs);
    }

    private function replace(string $text): string
    {
        $pattern = $this->pattern();
        if ($pattern === null || $text === '') {
            return $text;
        }
        $result = preg_replace_callback($pattern, function (array $m): string {
            $base = $this->mapper->unmapAbsoluteUrl(strtolower($m['scheme']) . '://' . $m['host'] . (($m['port'] ?? '') !== '' ? ':' . $m['port'] : ''));
            if ($base === null) {
                return $m[0];
            }
            $pos = strpos($base, '://');
            if ($pos === false) {
                return $m[0];
            }
            return substr($base, 0, $pos) . $m['colon'] . $m['slashes'] . substr($base, $pos + 3);
        }, $text, -1, $count, PREG_UNMATCHED_AS_NULL);

        if ($result === null || preg_last_error() !== PREG_NO_ERROR) {
            return $text;
        }
        return $result;
    }

    private function pattern(): ?string
    {
        if ($this->patternBuilt) {
            return $this->pattern;
        }
        $this->patternBuilt = true;
        $hosts = implode('|', array_map(static fn (string $h): string => preg_quote($h, '~'), $this->mapper->mirrorHosts()));
        if ($hosts === '') {
            return null;
        }
        return $this->pattern = '~(?<![A-Za-z0-9@._-])(?<scheme>https?)(?<colon>:)(?<slashes>//|\\\\/\\\\/|\\\\u002F\\\\u002F)(?<host>' . $hosts . ')'
            . '(?::(?<port>\d{1,5}))?(?![A-Za-z0-9_@-]|\.[A-Za-z0-9-]|:\d)~iD';
    }
}
