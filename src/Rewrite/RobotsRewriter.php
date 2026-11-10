<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Rewrites robots.txt. Only `Sitemap:` and `Host:` lines change; every other line, including
 * all `Allow` and `Disallow` rules, passes through byte for byte.
 */
final class RobotsRewriter
{
    public function __construct(private readonly HostReplacer $replacer)
    {
    }

    public function rewrite(string $robots): string
    {
        if (!$this->replacer->mightContain($robots)) {
            return $robots;
        }
        $result = preg_replace_callback(
            '~^([ \t]*(?:sitemap|host)[ \t]*:[ \t]*)([^\r\n]*)~im',
            fn (array $m): string => $m[1] . $this->replacer->replace($m[2]),
            $robots,
        );
        return $result ?? $robots;
    }
}
