<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

use MirrorGate\Config\MirrorConfig;

/**
 * Single-pass, boundary-guarded replacement of origin hosts by mirror hosts inside arbitrary text.
 *
 * One regular expression covers every handled origin host at once (longest first), so a replacement
 * can never be rewritten a second time. Replacement text is assembled only from validated
 * configuration (mirror base URLs, WS_PUBLIC_URL), never from the matched input beyond the escaping
 * style of the match.
 *
 * Matched forms (host case-insensitive): `https://h`, `http://h`, `//h`, the JSON-escaped
 * `https:\/\/h`, double-escaped `https:\\/\\/h`, `/` and `\x2F` escapes, percent-encoded
 * `https%3A%2F%2Fh` and `%2F%2Fh`, HTML entity forms `&#x2F;` and `&#47;`, `ws://h` and `wss://h`,
 * and, for hosts mapped 1:1 only, the bare host. Lookalike hosts (`evil-overlord.team`,
 * `overlord.team.evil.com`, `overlord.teams`) and e-mail addresses are left alone.
 */
final class HostReplacer
{
    /**
     * Slash pairs in every supported escaping style. Each alternative consists of two identical halves.
     * PCRE source: `//`, `\/\/`, `\\/\\/`, `%2F%2F`, `&#x2F;&#x2F;`, `&#47;&#47;`, `//`, `\x2F\x2F`.
     */
    private const SLASHES = '//|\\\\/\\\\/|\\\\\\\\/\\\\\\\\/|%2F%2F|&#x2F;&#x2F;|&#47;&#47;|\\\\u002F\\\\u002F|\\\\x2F\\\\x2F';

    private const TAIL = '(?::(?<port>\d{1,5}))?(?![A-Za-z0-9_@-]|\.[A-Za-z0-9-]|:\d)';

    /** @var list<string> lowercase origin hosts for the cheap precheck */
    private readonly array $hosts;

    /** @var array<string,?string> compiled patterns, keyed 'full' and 'urls' */
    private array $patterns = [];

    private bool $failed = false;
    private readonly ?string $wsScheme;
    private readonly ?string $wsRest;

    public function __construct(
        private readonly HostMapper $mapper,
        ?string $wsPublicUrl,
        private readonly MirrorConfig $mirror,
    ) {
        $hosts = [];
        foreach ($mapper->originHosts() as $host) {
            $hosts[] = strtolower($host);
        }
        $this->hosts = $hosts;

        $scheme = null;
        $rest = null;
        if ($wsPublicUrl !== null && preg_match('#^(wss?)://([A-Za-z0-9.\-]+(?::\d{1,5})?)(/[A-Za-z0-9._~/\-]*)?$#D', $wsPublicUrl, $m) === 1) {
            $scheme = $m[1];
            $rest = $m[2] . rtrim($m[3] ?? '', '/');
        }
        $this->wsScheme = $scheme;
        $this->wsRest = $rest;
    }

    /** Fast case-insensitive precheck: false means no origin host occurs in the text at all. */
    public function mightContain(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        foreach ($this->hosts as $host) {
            if (stripos($text, $host) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replaces every supported form, including the bare host of 1:1 mapped hosts.
     * Returns the input unchanged when the regular expression engine fails.
     */
    public function replace(string $text): string
    {
        return $this->run($text, true);
    }

    /**
     * Replaces only scheme-bearing and protocol-relative URL forms, never a bare host name. Used for
     * values that are URLs by construction (attributes, CSS url(), headers).
     */
    public function replaceUrlsOnly(string $text): string
    {
        return $this->run($text, false);
    }

    /** True when a regular expression failure happened since the last clearFailure(). */
    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function clearFailure(): void
    {
        $this->failed = false;
    }

    private function run(string $text, bool $bare): string
    {
        if ($text === '' || !$this->mightContain($text)) {
            return $text;
        }
        $pattern = $this->pattern($bare);
        if ($pattern === null) {
            return $text;
        }

        $previousLimit = $this->raiseBacktrackLimit(strlen($text));
        $result = preg_replace_callback(
            $pattern,
            fn (array $m): string => $this->replaceMatch($m),
            $text,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL,
        );
        $error = preg_last_error();
        if ($previousLimit !== null) {
            ini_set('pcre.backtrack_limit', $previousLimit);
        }

        if ($result === null || $error !== PREG_NO_ERROR) {
            $this->failed = true;
            return $text;
        }
        return $result;
    }

    private function raiseBacktrackLimit(int $length): ?string
    {
        if ($length < 1048576 || !function_exists('ini_set')) {
            return null;
        }
        $current = ini_get('pcre.backtrack_limit');
        if ($current === false) {
            return null;
        }
        $previous = ini_set('pcre.backtrack_limit', (string) max((int) $current, 50000000));
        return $previous === false ? null : $previous;
    }

    private function pattern(bool $bare): ?string
    {
        $key = $bare ? 'full' : 'urls';
        if (array_key_exists($key, $this->patterns)) {
            return $this->patterns[$key];
        }

        $all = implode('|', array_map(static fn (string $h): string => preg_quote($h, '~'), $this->mapper->originHosts()));
        if ($all === '') {
            return $this->patterns[$key] = null;
        }
        $oneToOne = implode('|', array_map(static fn (string $h): string => preg_quote($h, '~'), $this->mapper->oneToOneHosts()));

        $alternatives = '(?<![A-Za-z0-9@._-])(?<sch>https?|wss?)(?<col>:|%3A)(?<sl>' . self::SLASHES . ')(?<h1>' . $all . ')'
            . '|(?<![A-Za-z0-9@._:/\\\\%-])(?<sl2>' . self::SLASHES . ')(?<h2>' . $all . ')';
        if ($bare && $oneToOne !== '') {
            $alternatives .= '|(?<![A-Za-z0-9@._/\\\\-])(?<h3>' . $oneToOne . ')';
        }

        return $this->patterns[$key] = '~(?:' . $alternatives . ')' . self::TAIL . '~iD';
    }

    /** @param array<string,?string> $m */
    private function replaceMatch(array $m): string
    {
        $port = isset($m['port']) && $m['port'] !== '' ? (int) $m['port'] : null;

        if (($m['sch'] ?? null) !== null) {
            $host = strtolower((string) $m['h1']);
            if (!$this->mapper->originPortAllowed($host, $port)) {
                return $m[0] ?? '';
            }
            $slashes = (string) $m['sl'];
            $token = substr($slashes, 0, intdiv(strlen($slashes), 2));
            $scheme = strtolower((string) $m['sch']);
            $colon = (string) $m['col'];

            if ($scheme === 'ws' || $scheme === 'wss') {
                if ($this->wsScheme === null || $this->wsRest === null) {
                    return $m[0] ?? '';
                }
                return $this->wsScheme . $colon . $slashes . str_replace('/', $token, $this->wsRest);
            }
            $split = $this->splitBase($host);
            if ($split === null) {
                return $m[0] ?? '';
            }
            $mirrorScheme = $this->mirror->isHttps ? 'https' : 'http';
            return $mirrorScheme . $colon . $slashes . str_replace('/', $token, $split[1]);
        }

        if (($m['sl2'] ?? null) !== null) {
            $host = strtolower((string) $m['h2']);
            if (!$this->mapper->originPortAllowed($host, $port)) {
                return $m[0] ?? '';
            }
            $slashes = (string) $m['sl2'];
            $token = substr($slashes, 0, intdiv(strlen($slashes), 2));
            $split = $this->splitBase($host);
            if ($split === null) {
                return $m[0] ?? '';
            }
            return $slashes . str_replace('/', $token, $split[1]);
        }

        if (($m['h3'] ?? null) !== null) {
            $host = strtolower((string) $m['h3']);
            if (!$this->mapper->originPortAllowed($host, $port)) {
                return $m[0] ?? '';
            }
            $authority = $this->mapper->mirrorAuthorityFor($host);
            return $authority ?? ($m[0] ?? '');
        }

        return $m[0] ?? '';
    }

    /** @return array{0:string,1:string}|null base scheme and "authority[/prefix]" for the origin host */
    private function splitBase(string $originHost): ?array
    {
        $base = $this->mapper->mirrorBaseFor($originHost);
        if ($base === null) {
            return null;
        }
        $pos = strpos($base, '://');
        if ($pos === false) {
            return null;
        }
        return [substr($base, 0, $pos), substr($base, $pos + 3)];
    }
}
