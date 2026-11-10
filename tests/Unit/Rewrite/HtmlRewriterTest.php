<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Rewrite;

use MirrorGate\Rewrite\RewriteKind;

final class HtmlRewriterTest extends RewriteTestCase
{
    /**
     * @param array<string,mixed> $rewrite
     * @param array<string,mixed> $origin
     */
    private function html(string $html, array $rewrite = [], array $origin = [], string $originHost = 'overlord.team'): string
    {
        $rewriter = $this->rewriter($origin, [], $rewrite);
        return $rewriter->rewriteBody($html, RewriteKind::Html, 'text/html; charset=utf-8', $this->context($originHost))->body;
    }

    public function testUrlAttributes(): void
    {
        $in = '<a href="https://overlord.team/x?a=1&amp;b=2">overlord.team</a><form action="https://overlord.team/p"></form>'
            . '<object data="https://overlord.team/o"></object><svg><use xlink:href="https://overlord.team/s.svg#a"/></svg>'
            . '<a href=https://overlord.team/u>u</a><A HREF="https://OVERLORD.team/UP">x</A>';
        $expected = '<a href="https://ormantek.com/x?a=1&amp;b=2">overlord.team</a><form action="https://ormantek.com/p"></form>'
            . '<object data="https://ormantek.com/o"></object><svg><use xlink:href="https://ormantek.com/s.svg#a"/></svg>'
            . '<a href=https://ormantek.com/u>u</a><A HREF="https://ormantek.com/UP">x</A>';
        self::assertSame($expected, $this->html($in));
    }

    public function testSrcsetKeepsDescriptorsAndDataUris(): void
    {
        $in = '<img srcset="https://overlord.team/a.png 1x, //overlord.team/b.png 2x, data:image/png;base64,AAA 3x" src="/z.png">'
            . '<img srcset="https://overlord.team/a,b.png 1x,https://overlord.team/c.png 2x, /d.png 3x">';
        $expected = '<img srcset="https://ormantek.com/a.png 1x, //ormantek.com/b.png 2x, data:image/png;base64,AAA 3x" src="/z.png">'
            . '<img srcset="https://ormantek.com/a,b.png 1x,https://ormantek.com/c.png 2x, /d.png 3x">';
        self::assertSame($expected, $this->html($in));
    }

    public function testStyleAttributeAndStyleBlock(): void
    {
        $in = '<style>a{background:url(https://overlord.team/i.png)}</style><p style="background:url(\'https://overlord.team/q.png\')">x</p>';
        $expected = '<style>a{background:url(https://ormantek.com/i.png)}</style><p style="background:url(\'https://ormantek.com/q.png\')">x</p>';
        self::assertSame($expected, $this->html($in));
    }

    public function testMetaRefreshOpenGraphAndCanonical(): void
    {
        $in = '<link rel="canonical" href="https://overlord.team/p"><meta property="og:url" content="https://overlord.team/p">'
            . '<meta property="og:image" content="https://overlord.team/i.png"><meta http-equiv="refresh" content="5; url=https://overlord.team/x">'
            . '<meta name="description" content="see overlord.team">';
        $expected = '<link rel="canonical" href="https://ormantek.com/p"><meta property="og:url" content="https://ormantek.com/p">'
            . '<meta property="og:image" content="https://ormantek.com/i.png"><meta http-equiv="refresh" content="5; url=https://ormantek.com/x">'
            . '<meta name="description" content="see overlord.team">';
        self::assertSame($expected, $this->html($in));
    }

    public function testCanonicalOriginModeKeepsCanonicalAndOgUrl(): void
    {
        $in = '<link rel="canonical" href="https://overlord.team/p"><meta property="og:url" content="https://overlord.team/p"><a href="https://overlord.team/x">x</a>';
        $expected = '<link rel="canonical" href="https://overlord.team/p"><meta property="og:url" content="https://overlord.team/p"><a href="https://ormantek.com/x">x</a>';
        self::assertSame($expected, $this->html($in, ['seoMode' => 'canonical-origin']));
    }

    public function testNoindexInjectsStaticMetaOnce(): void
    {
        $out = $this->html('<html><head><title>t</title></head><body>b</body></html>', ['seoMode' => 'noindex']);
        self::assertSame('<html><head><title>t</title><meta name="robots" content="noindex,nofollow"></head><body>b</body></html>', $out);
    }

    public function testIntegrityIsStrippedOnlyForOwnResources(): void
    {
        $in = '<link rel="stylesheet" href="/a.css" integrity="sha384-a" crossorigin>'
            . '<link rel="stylesheet" href="https://cdn.example.com/a.css" integrity="sha384-keep">'
            . '<link rel="stylesheet" href="https://overlord.team/b.css" integrity="sha384-b">'
            . '<script src="/a.js" integrity="sha384-js"></script>';
        $expected = '<link rel="stylesheet" href="/a.css" crossorigin>'
            . '<link rel="stylesheet" href="https://cdn.example.com/a.css" integrity="sha384-keep">'
            . '<link rel="stylesheet" href="https://ormantek.com/b.css">'
            . '<script src="/a.js" integrity="sha384-js"></script>';
        self::assertSame($expected, $this->html($in));
        self::assertSame(
            '<script src="https://ormantek.com/a.js"></script><script src="https://cdn.example.com/a.js" integrity="sha384-k"></script>',
            $this->html('<script src="https://overlord.team/a.js" integrity="sha384-z"></script><script src="https://cdn.example.com/a.js" integrity="sha384-k"></script>', ['js' => true]),
        );
    }

    public function testDataBlocksAndScripts(): void
    {
        $in = '<script id="__NEXT_DATA__" type="application/json">{"a":"https:\/\/overlord.team\/x"}</script>'
            . '<script type="application/ld+json">{"url":"https://overlord.team/"}</script>'
            . '<script>var u="https://overlord.team/a";</script>'
            . '<script>self.__next_f.push([1,"https://overlord.team"])</script>';
        $expected = '<script id="__NEXT_DATA__" type="application/json">{"a":"https:\/\/ormantek.com\/x"}</script>'
            . '<script type="application/ld+json">{"url":"https://ormantek.com/"}</script>'
            . '<script>var u="https://overlord.team/a";</script>'
            . '<script>self.__next_f.push([1,"https://overlord.team"])</script>';
        self::assertSame($expected, $this->html($in));

        $withJs = $this->html('<script>var u="https://overlord.team/a";</script><script>self.__next_f.push([1,"https://overlord.team"])</script>', ['js' => true]);
        self::assertSame('<script>var u="https://ormantek.com/a";</script><script>self.__next_f.push([1,"https://overlord.team"])</script>', $withJs);
    }

    public function testRawTextElementsCommentsAndLooseMarkupAreSafe(): void
    {
        $in = '<!-- c https://overlord.team/x --><p>a < b</p><textarea><a href="https://overlord.team"></textarea>'
            . '<a href="x" title=\'> https://overlord.team\'>t</a>';
        $expected = '<!-- c https://ormantek.com/x --><p>a < b</p><textarea><a href="https://overlord.team"></textarea>'
            . '<a href="x" title=\'> https://overlord.team\'>t</a>';
        self::assertSame($expected, $this->html($in));
        self::assertSame('<a href="https://overlord.team/a', $this->html('<a href="https://overlord.team/a'));
    }

    public function testVisibleTextOnlyWhenEnabled(): void
    {
        self::assertSame('<p>overlord.team</p>', $this->html('<p>overlord.team</p>'));
        self::assertSame('<p>ormantek.com</p>', $this->html('<p>overlord.team</p>', ['visibleText' => true]));
    }

    public function testCspMetaRewriteAndStrip(): void
    {
        $in = '<meta http-equiv="Content-Security-Policy" content="script-src \'self\' \'sha256-aa=\' https://overlord.team">';
        self::assertSame(
            '<meta http-equiv="Content-Security-Policy" content="script-src \'self\' https://ormantek.com">',
            $this->html($in),
        );
        self::assertSame('<head></head>', $this->html('<head>' . $in . '</head>', ['cspMode' => 'strip']));
    }

    public function testPrefixModeAddsPrefixToRootRelativeUrlsOfExtraOrigins(): void
    {
        $origin = ['extraOrigins' => ['cdn.overlord.team'], 'subdomainMode' => 'prefix'];
        $in = '<img src="/i.png"><img src="//cdn.overlord.team/j.png"><img src="rel.png">';
        self::assertSame(
            '<img src="/__mg/s/cdn.overlord.team/i.png"><img src="//ormantek.com/__mg/s/cdn.overlord.team/j.png"><img src="rel.png">',
            $this->html($in, [], $origin, 'cdn.overlord.team'),
        );
        self::assertSame('<img src="/i.png">', $this->html('<img src="/i.png">', [], $origin));
    }

    public function testWindows1256PageWithMalformedUtf8SurvivesByteForByte(): void
    {
        $in = "<a href=\"https://overlord.team/\xff\xfe\">\xc7\xe1\xe3\xcd</a>";
        $result = $this->rewriter()->rewriteBody($in, RewriteKind::Html, 'text/html; charset=windows-1256', $this->context());
        self::assertSame("<a href=\"https://ormantek.com/\xff\xfe\">\xc7\xe1\xe3\xcd</a>", $result->body);
    }

    public function testLargeDocumentTiming(): void
    {
        $rewriter = $this->rewriter([], [], ['maxBytes' => 64 * 1048576]);
        $html = str_repeat('<p><a href="https://overlord.team/page?x=1&amp;y=2">link</a> some padding text for the line</p>' . "\n", 100000);
        $started = microtime(true);
        $result = $rewriter->rewriteBody($html, RewriteKind::Html, 'text/html', $this->context());
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertTrue($result->changed);
        self::assertStringNotContainsString('overlord.team', $result->body);
    }

    public function testHugeAttributeListsStayWithinMemory(): void
    {
        $html = '<a ' . str_repeat('a ', 400000) . 'href="https://overlord.team/x"> overlord.team </a><a href="https://overlord.team/y">y</a>';
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $out = $this->html($html);
        self::assertLessThan(48 * 1024 * 1024, memory_get_peak_usage() - $before);
        self::assertStringContainsString('href="https://ormantek.com/x"', $out);
        self::assertStringContainsString('href="https://ormantek.com/y"', $out);
    }

    public function testIntegrityIsKeptForCrossOriginUrlsBrowsersTreatAsAbsolute(): void
    {
        $rewrite = ['js' => true];
        foreach (['\\\\evil.com/x.js', '/\\evil.com/x.js', "/\t/evil.com/x.js", "/\n/evil.com/x.js", '//evil.com/x.js', 'https://evil.com/x.js', '//overlord.team@evil.com/x.js', '///evil.com/x.js'] as $src) {
            $out = $this->html('<script src="' . $src . '" integrity="sha384-AAA"></script>', $rewrite);
            self::assertStringContainsString('integrity="sha384-AAA"', $out, $src);
        }
        $own = $this->html('<script src="/app.js" integrity="sha384-AAA"></script><script src="https://overlord.team/b.js" integrity="sha384-BBB"></script>', $rewrite);
        self::assertStringNotContainsString('integrity', $own);
    }

    public function testEntityEncodedUrlsAreRewritten(): void
    {
        $in = '<a href="https://overlord&#46;team/x">a</a><a href="https:&sol;&sol;overlord.team/y">b</a><a href="https&colon;//overlord.team/z?a=1&amp;b=2">c</a>';
        $out = $this->html($in);
        self::assertStringNotContainsString('overlord', $out);
        self::assertStringContainsString('ormantek.com/x', $out);
        self::assertStringContainsString('ormantek.com/y', $out);
        self::assertStringContainsString('ormantek.com/z?a=1&amp;b=2', $out);
    }

    public function testCdataIsABogusCommentEndingAtTheFirstGreaterThan(): void
    {
        $out = $this->html('<![CDATA[ > <a href="https://overlord.team/x">x</a> ]]>');
        self::assertStringNotContainsString('overlord.team', $out);
    }

    public function testEventHandlersMapAbsoluteUrlsEvenWithoutJsRewriting(): void
    {
        $out = $this->html('<a onclick="location=\'https://overlord.team/go\'">x</a>');
        self::assertStringContainsString('https://ormantek.com/go', $out);
    }
}
