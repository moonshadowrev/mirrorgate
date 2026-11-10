<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

use MirrorGate\Config\RewriteConfig;

/**
 * Lightweight HTML scanner and rewriter. No DOM is built.
 *
 * The scanner walks the document once, recognises comments, CDATA, doctype, end tags, start tags
 * (honoring quoted attribute values) and the raw-text bodies of script, style, textarea, title, xmp,
 * noembed and noframes. Only attribute values and a few data blocks are replaced; the rest of the
 * document is copied byte for byte, so malformed markup stays malformed in the same way.
 */
final class HtmlRewriter
{
    private const RAW_TEXT = [
        'script' => true, 'style' => true, 'textarea' => true, 'title' => true,
        'xmp' => true, 'noembed' => true, 'noframes' => true,
    ];

    /** URL-valued attributes (every tag). */
    private const URL_ATTRS = [
        'href' => true, 'src' => true, 'action' => true, 'formaction' => true, 'poster' => true,
        'data' => true, 'cite' => true, 'background' => true, 'longdesc' => true, 'manifest' => true,
        'ping' => true, 'codebase' => true, 'usemap' => true, 'xlink:href' => true,
        // Common lazy-loading conventions.
        'data-src' => true, 'data-href' => true, 'data-url' => true, 'data-background' => true,
        'data-bg' => true, 'data-poster' => true, 'data-lazy-src' => true, 'data-original' => true,
        'data-image' => true,
    ];

    private const SRCSET_ATTRS = ['srcset' => true, 'imagesrcset' => true, 'data-srcset' => true];

    private const JSON_SCRIPT_TYPES = [
        'application/ld+json', 'application/json', 'text/json', 'importmap', 'speculationrules',
        'application/manifest+json',
    ];

    private const MAX_TAG_ATTRIBUTES = 256;
    private const HARD_TAG_ATTRIBUTES = 1024;

    private const NOINDEX_META = '<meta name="robots" content="noindex,nofollow">';

    public function __construct(
        private readonly RewriteConfig $config,
        private readonly HostMapper $mapper,
        private readonly CssRewriter $css,
        private readonly SrcsetRewriter $srcset,
        private readonly CspRewriter $csp,
    ) {
    }

    public function rewrite(string $html, UrlMapper $urls): string
    {
        $replacer = $urls->replacer();
        $length = strlen($html);
        $hasHost = $replacer->mightContain($html);
        $noindex = $this->config->seoMode === 'noindex';
        $metaInjected = false;

        $out = '';
        $i = 0;

        while ($i < $length) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                $out .= $this->text(substr($html, $i), $replacer);
                break;
            }
            if ($lt > $i) {
                $out .= $this->text(substr($html, $i, $lt - $i), $replacer);
            }

            $next = $html[$lt + 1] ?? '';

            if ($next === '!') {
                if (substr_compare($html, '<!--', $lt, 4) === 0) {
                    $end = strpos($html, '-->', $lt + 2);
                    if ($end === false) {
                        $out .= substr($html, $lt);
                        break;
                    }
                    $end += 3;
                    $out .= $replacer->replaceUrlsOnly(substr($html, $lt, $end - $lt));
                    $i = $end;
                    continue;
                }
                // HTML5 treats "<![CDATA[" outside foreign content as a bogus comment that ends at the first ">".
                // Ending it there (also for SVG/MathML) can only cause more rewriting, never a missed URL.
                $terminator = '>';
                $end = strpos($html, $terminator, $lt + 2);
                if ($end === false) {
                    $out .= substr($html, $lt);
                    break;
                }
                $end += strlen($terminator);
                $out .= substr($html, $lt, $end - $lt);
                $i = $end;
                continue;
            }

            if ($next === '?') {
                $end = strpos($html, '>', $lt + 2);
                if ($end === false) {
                    $out .= substr($html, $lt);
                    break;
                }
                $out .= substr($html, $lt, $end + 1 - $lt);
                $i = $end + 1;
                continue;
            }

            if ($next === '/') {
                $end = strpos($html, '>', $lt + 2);
                if ($end === false) {
                    $out .= substr($html, $lt);
                    break;
                }
                if ($noindex && !$metaInjected && strncasecmp(substr($html, $lt + 2, 4), 'head', 4) === 0
                    && strcspn($html, " \t\n\r\f/>", $lt + 2) === 4) {
                    $out .= self::NOINDEX_META;
                    $metaInjected = true;
                }
                $out .= substr($html, $lt, $end + 1 - $lt);
                $i = $end + 1;
                continue;
            }

            if (!(($next >= 'a' && $next <= 'z') || ($next >= 'A' && $next <= 'Z'))) {
                // A literal "<" in text.
                $out .= '<';
                $i = $lt + 1;
                continue;
            }

            $tag = $this->parseStartTag($html, $lt, $length);
            if ($tag === null) {
                // Unterminated tag: the browser drops it; copy the rest untouched.
                $out .= substr($html, $lt);
                break;
            }

            $out .= $this->rewriteTag($html, $tag, $lt, $urls, $hasHost);
            $i = $tag['end'];
            $name = $tag['name'];

            if (isset(self::RAW_TEXT[$name])) {
                $close = self::findRawTextEnd($html, $name, $i);
                $stop = $close === false ? $length : $close;
                $content = substr($html, $i, $stop - $i);
                $out .= $this->rewriteRawText($name, $tag, $content, $urls);
                $i = $stop;
            }
        }

        return $out;
    }

    /**
     * @param array{end:int,name:string,attrs:list<array{name:string,value:?string,vs:int,ve:int,ws:int,we:int}>} $tag
     */
    private function rewriteTag(string $html, array $tag, int $start, UrlMapper $urls, bool $hasHost): string
    {
        $attrs = $tag['attrs'];
        $name = $tag['name'];
        $original = substr($html, $start, $tag['end'] - $start);
        if ($attrs === []) {
            return $original;
        }

        /** @var array<string,string> $values first occurrence of each attribute, lowercase names */
        $values = [];
        foreach ($attrs as $attr) {
            if ($attr['value'] !== null && !isset($values[$attr['name']])) {
                $values[$attr['name']] = $attr['value'];
            }
        }

        $rel = strtolower($values['rel'] ?? '');
        $relTokens = $rel === '' ? [] : (preg_split('~[\s]+~', trim($rel)) ?: []);
        $canonicalLink = $name === 'link' && in_array('canonical', $relTokens, true);
        $keepCanonical = $this->config->seoMode === 'canonical-origin';

        $edits = [];
        $replacer = $urls->replacer();

        foreach ($attrs as $attr) {
            $attrName = $attr['name'];
            $value = $attr['value'];

            if ($attrName === 'integrity') {
                if ($this->shouldStripIntegrity($name, $relTokens, $values)) {
                    $edits[] = [$attr['ws'], $attr['we'], ''];
                }
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }

            $new = $value;

            if (isset(self::URL_ATTRS[$attrName])) {
                if (!($keepCanonical && $canonicalLink && $attrName === 'href')) {
                    $new = self::entityAware($value, static fn (string $v): string => $urls->map($v));
                }
            } elseif (isset(self::SRCSET_ATTRS[$attrName])) {
                $new = self::entityAware($value, fn (string $v): string => $this->srcset->rewrite($v, $urls));
            } elseif ($attrName === 'style') {
                $new = self::entityAware($value, fn (string $v): string => $this->css->rewrite($v, $urls));
            } elseif ($attrName === 'srcdoc') {
                $new = $replacer->replaceUrlsOnly($value);
            } elseif ($attrName === 'content' && $name === 'meta') {
                $metaResult = $this->rewriteMetaContent($value, $values, $urls, $hasHost);
                if ($metaResult === null) {
                    // Meta CSP removed (CSP_MODE=strip): drop the whole tag.
                    return '';
                }
                $new = $metaResult;
            } elseif (strncmp($attrName, 'on', 2) === 0) {
                // Event handlers: absolute origin URLs are always mapped, bare host names only with REWRITE_JS.
                $new = self::entityAware($value, fn (string $v): string => $this->config->js ? $replacer->replace($v) : $replacer->replaceUrlsOnly($v));
            }

            if ($new !== $value) {
                $edits[] = [$attr['vs'], $attr['ve'], $new];
            }
        }

        if ($edits === []) {
            return $original;
        }

        $result = '';
        $cursor = $start;
        foreach ($edits as [$from, $to, $replacement]) {
            $result .= substr($html, $cursor, $from - $cursor) . $replacement;
            $cursor = $to;
        }
        return $result . substr($html, $cursor, $tag['end'] - $cursor);
    }

    /**
     * @param array<string,string> $values
     * @return string|null new content value, or null when the whole meta tag must be removed
     */
    private function rewriteMetaContent(string $content, array $values, UrlMapper $urls, bool $hasHost): ?string
    {
        $httpEquiv = strtolower(trim($values['http-equiv'] ?? ''));

        if ($httpEquiv === 'refresh') {
            if (preg_match('~^(\s*[\d.]*\s*[;,]?\s*(?:url\s*=\s*)?[\'"]?)(.*?)([\'"]?\s*)$~is', $content, $m) === 1) {
                return $m[1] . $urls->map($m[2]) . $m[3];
            }
            return $content;
        }

        if ($httpEquiv === 'content-security-policy') {
            return match ($this->config->cspMode) {
                'strip' => null,
                'passthrough' => $content,
                default => $this->csp->rewrite($content, $hasHost),
            };
        }

        $keys = [strtolower(trim($values['property'] ?? '')), strtolower(trim($values['name'] ?? ''))];
        foreach ($keys as $key) {
            if ($key === '') {
                continue;
            }
            if ($key === 'og:url' && $this->config->seoMode === 'canonical-origin') {
                return $content;
            }
            if (self::isUrlMetaName($key)) {
                return $urls->map($content);
            }
        }
        return $content;
    }

    private static function isUrlMetaName(string $key): bool
    {
        if (str_starts_with($key, 'msapplication-')) {
            return true;
        }
        return preg_match('~^(?:og:(?:url|image|video|audio)(?::[a-z_]+)*|twitter:(?:image|image:src|url|player|player:stream))$~', $key) === 1;
    }

    /**
     * @param list<string> $relTokens
     * @param array<string,string> $values
     */
    private function shouldStripIntegrity(string $tagName, array $relTokens, array $values): bool
    {
        if (!$this->config->stripIntegrity) {
            return false;
        }

        if ($tagName === 'script') {
            if (!$this->config->js) {
                return false;
            }
            $url = $values['src'] ?? null;
        } elseif ($tagName === 'link') {
            $url = $values['href'] ?? null;
            $as = strtolower(trim($values['as'] ?? ''));
            $isStyle = in_array('stylesheet', $relTokens, true)
                || (in_array('preload', $relTokens, true) && $as === 'style');
            $isScript = in_array('modulepreload', $relTokens, true)
                || (in_array('preload', $relTokens, true) && ($as === 'script' || $as === 'worker'));
            if (!$isStyle && !($isScript && $this->config->js)) {
                return false;
            }
        } else {
            return false;
        }

        if ($url === null || trim($url) === '') {
            return false;
        }
        return $this->isOwnUrl($url);
    }

    /** True for relative URLs and for URLs on a handled origin host or a mirror host. */
    private function isOwnUrl(string $url): bool
    {
        // Browsers drop tab and newline, treat a backslash as a slash and ignore leading controls and spaces.
        $u = strtr((string) preg_replace('~[\t\r\n]+~', '', $url), '\\', '/');
        $u = ltrim($u, "\x00..\x20");
        if (preg_match('~^(?:[A-Za-z][A-Za-z0-9+.\-]*:)?/{2,}~', $u, $lead) === 1) {
            $rest = substr($u, strlen($lead[0]));
            $authority = substr($rest, 0, strcspn($rest, '/?#'));
            $at = strrpos($authority, '@');
            if ($at !== false) {
                $authority = substr($authority, $at + 1);
            }
            $host = $authority !== '' && $authority[0] === '[' ? $authority : (explode(':', $authority, 2)[0]);
            if ($host === '') {
                return false; // unparsable: fail closed and keep the integrity attribute
            }

            return $this->mapper->isOriginHost($host) || $this->mapper->isMirrorHost($host);
        }

        return preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*:~', $u) !== 1;
    }

    /**
     * @param array{end:int,name:string,attrs:list<array{name:string,value:?string,vs:int,ve:int,ws:int,we:int}>} $tag
     */
    private function rewriteRawText(string $name, array $tag, string $content, UrlMapper $urls): string
    {
        if ($content === '') {
            return $content;
        }
        $replacer = $urls->replacer();

        if ($name === 'style') {
            return $this->css->rewrite($content, $urls);
        }
        if ($name !== 'script') {
            return $content;
        }

        $type = '';
        $id = '';
        foreach ($tag['attrs'] as $attr) {
            if ($attr['name'] === 'type' && $type === '') {
                $type = strtolower(trim((string) $attr['value']));
            } elseif ($attr['name'] === 'id' && $id === '') {
                $id = (string) $attr['value'];
            }
        }

        if ($id === '__NEXT_DATA__' || in_array($type, self::JSON_SCRIPT_TYPES, true) || str_ends_with($type, '+json')) {
            return $replacer->replace($content);
        }

        $isRscChunk = str_contains($content, 'self.__next_f.push');
        if ($isRscChunk) {
            return $this->config->rsc ? $replacer->replace($content) : $content;
        }
        return $this->config->js ? $replacer->replace($content) : $content;
    }

    private function text(string $text, HostReplacer $replacer): string
    {
        if ($text === '' || !$this->config->visibleText) {
            return $text;
        }
        return $replacer->replace($text);
    }

    /**
     * Applies $map to an attribute value. Attribute values are entity-encoded in the source ("&#46;", "&sol;"),
     * so when the raw text is unchanged by $map the decoded text is tried as well; a change found there is
     * written back re-encoded.
     *
     * @param \Closure(string): string $map
     */
    private static function entityAware(string $value, \Closure $map): string
    {
        $new = $map($value);
        if ($new !== $value || !str_contains($value, '&')) {
            return $new;
        }
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) {
            return $value;
        }
        $mapped = $map($decoded);
        if ($mapped === $decoded) {
            return $value;
        }

        return htmlspecialchars($mapped, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /**
     * Bounds the attribute list of one tag: the first MAX_TAG_ATTRIBUTES are kept, after that only the first
     * occurrence of each attribute the rewriter acts on (browsers ignore later duplicates), up to a hard cap.
     *
     * @param array<string,true> $seenRelevant
     */
    private static function keepAttribute(string $name, int $count, array &$seenRelevant): bool
    {
        if ($count < self::MAX_TAG_ATTRIBUTES) {
            return true;
        }
        if ($count >= self::HARD_TAG_ATTRIBUTES || isset($seenRelevant[$name]) || !self::isRelevantAttribute($name)) {
            return false;
        }
        $seenRelevant[$name] = true;

        return true;
    }

    /** Attribute names whose value the rewriter reads or edits; only these are kept once a tag has many attributes. */
    private static function isRelevantAttribute(string $name): bool
    {
        return isset(self::URL_ATTRS[$name]) || isset(self::SRCSET_ATTRS[$name])
            || in_array($name, ['style', 'srcdoc', 'content', 'integrity', 'rel', 'as', 'type', 'id', 'http-equiv', 'property', 'name'], true)
            || strncmp($name, 'on', 2) === 0;
    }

    /** Position of the end tag that closes a raw-text element, or false when the document ends first. */
    private static function findRawTextEnd(string $html, string $name, int $from): int|false
    {
        $needle = '</' . $name;
        $needleLength = strlen($needle);
        $position = $from;
        while (($found = stripos($html, $needle, $position)) !== false) {
            $after = $html[$found + $needleLength] ?? '';
            if ($after === '' || strpbrk($after, " \t\n\r\f/>") !== false) {
                return $found;
            }
            $position = $found + $needleLength;
        }
        return false;
    }

    /**
     * Parses one start tag beginning at `$start` (the "<").
     *
     * @return array{end:int,name:string,attrs:list<array{name:string,value:?string,vs:int,ve:int,ws:int,we:int}>}|null
     *         null when the tag is not terminated before the end of the document
     */
    private function parseStartTag(string $html, int $start, int $length): ?array
    {
        $pos = $start + 1;
        $nameLength = strcspn($html, " \t\n\r\f/>", $pos);
        $name = strtolower(substr($html, $pos, $nameLength));
        $pos += $nameLength;
        $attrs = [];
        $seenRelevant = [];

        while (true) {
            $wsStart = $pos;
            $pos += strspn($html, " \t\n\r\f/", $pos);
            if ($pos >= $length) {
                return null;
            }
            if ($html[$pos] === '>') {
                return ['end' => $pos + 1, 'name' => $name, 'attrs' => $attrs];
            }

            $lead = $html[$pos] === '=' ? 1 : 0;
            $attrNameLength = $lead + strcspn($html, " \t\n\r\f/>=", $pos + $lead);
            $attrName = strtolower(substr($html, $pos, $attrNameLength));
            $pos += $attrNameLength;

            $probe = $pos + strspn($html, " \t\n\r\f", $pos);
            if ($probe < $length && $html[$probe] === '=') {
                $probe++;
                $probe += strspn($html, " \t\n\r\f", $probe);
                if ($probe >= $length) {
                    return null;
                }
                $quote = $html[$probe];
                if ($quote === '"' || $quote === "'") {
                    $close = strpos($html, $quote, $probe + 1);
                    if ($close === false) {
                        return null;
                    }
                    $valueStart = $probe + 1;
                    $valueEnd = $close;
                    $pos = $close + 1;
                } else {
                    $valueStart = $probe;
                    $valueEnd = $probe + strcspn($html, " \t\n\r\f>", $probe);
                    $pos = $valueEnd;
                }
                if (!self::keepAttribute($attrName, count($attrs), $seenRelevant)) {
                    continue;
                }
                $attrs[] = [
                    'name' => $attrName,
                    'value' => substr($html, $valueStart, $valueEnd - $valueStart),
                    'vs' => $valueStart,
                    've' => $valueEnd,
                    'ws' => $wsStart,
                    'we' => $pos,
                ];
            } elseif (self::keepAttribute($attrName, count($attrs), $seenRelevant)) {
                $attrs[] = ['name' => $attrName, 'value' => null, 'vs' => -1, 've' => -1, 'ws' => $wsStart, 'we' => $pos];
            }
        }
    }
}
