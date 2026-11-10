<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Rewrite;

use MirrorGate\Http\HeaderBag;
use MirrorGate\Rewrite\RewriteKind;

final class BodyRewriteTest extends RewriteTestCase
{
    public function testCssUrlForms(): void
    {
        $css = '@import "https://overlord.team/a.css"; @import url(https://overlord.team/b.css); '
            . 'a{background:url( "https://overlord.team/c.png" ) , url(\'//overlord.team/d.png\'), url(/rel.png), url(data:image/png;base64,overlord.team)} '
            . 'b{background:image-set("https://overlord.team/e.png" 1x, url(https://overlord.team/f.png) 2x)} '
            . '@font-face{src:url(https://overlord.team/g.woff2)} /*# sourceMappingURL=https://overlord.team/x.map */ .c::after{content:"overlord.team"}';
        $expected = '@import "https://overload.ir/a.css"; @import url(https://overload.ir/b.css); '
            . 'a{background:url( "https://overload.ir/c.png" ) , url(\'//overload.ir/d.png\'), url(/rel.png), url(data:image/png;base64,overlord.team)} '
            . 'b{background:image-set("https://overload.ir/e.png" 1x, url(https://overload.ir/f.png) 2x)} '
            . '@font-face{src:url(https://overload.ir/g.woff2)} /*# sourceMappingURL=https://overlord.team/x.map */ .c::after{content:"overlord.team"}';
        $result = $this->rewriter()->rewriteBody($css, RewriteKind::Css, 'text/css', $this->context());
        self::assertSame($expected, $result->body);
    }

    public function testJsonEscapedSlashesStayEscapedAndValid(): void
    {
        $json = '{"u":"https:\/\/overlord.team\/a","v":1}';
        $result = $this->rewriter()->rewriteBody($json, RewriteKind::Json, 'application/json', $this->context());
        self::assertSame('{"u":"https:\/\/overload.ir\/a","v":1}', $result->body);
        self::assertTrue(json_validate($result->body));
    }

    public function testSitemapWithCdataAndEntities(): void
    {
        $xml = '<?xml version="1.0"?><urlset><url><loc>https://overlord.team/a?x=1&amp;y=2</loc></url><url><loc><![CDATA[https://overlord.team/b]]></loc></url></urlset>';
        $result = $this->rewriter()->rewriteBody($xml, RewriteKind::Sitemap, 'application/xml', $this->context());
        self::assertSame(str_replace('overlord.team', 'overload.ir', $xml), $result->body);

        $keep = $this->rewriter([], [], ['seoMode' => 'canonical-origin']);
        self::assertSame($xml, $keep->rewriteBody($xml, RewriteKind::Sitemap, 'application/xml', $this->context())->body);
    }

    public function testRobotsOnlyTouchesSitemapAndHostLines(): void
    {
        $robots = "User-agent: *\nDisallow: /overlord.team\nSitemap: https://overlord.team/sitemap.xml\nHost: overlord.team\n";
        $result = $this->rewriter()->rewriteBody($robots, RewriteKind::Robots, 'text/plain', $this->context());
        self::assertSame("User-agent: *\nDisallow: /overlord.team\nSitemap: https://overload.ir/sitemap.xml\nHost: overload.ir\n", $result->body);
    }

    public function testRscIsRewrittenOnlyWhenKindIsRequested(): void
    {
        $body = '1:"https://overlord.team/x"';
        $result = $this->rewriter([], [], ['rsc' => true])->rewriteBody($body, RewriteKind::Rsc, 'text/x-component', $this->context());
        self::assertSame('1:"https://overload.ir/x"', $result->body);
    }

    public function testBinaryWideCharsetOversizedHeadAndEmptyBodiesAreSkipped(): void
    {
        $rewriter = $this->rewriter();
        $ctx = $this->context();
        self::assertSame('binary', $rewriter->rewriteBody("\x89PNG\r\n\x1a\n https://overlord.team", RewriteKind::Html, 'text/html', $ctx)->reason);
        self::assertSame('binary', $rewriter->rewriteBody("a\0b https://overlord.team", RewriteKind::Json, 'application/json', $ctx)->reason);
        $wide = mb_convert_encoding('<a href="https://overlord.team">', 'UTF-16LE');
        self::assertSame('charset', $rewriter->rewriteBody($wide, RewriteKind::Html, 'text/html; charset=utf-16', $ctx)->reason);
        self::assertSame('head', $rewriter->rewriteBody('abc', RewriteKind::Html, 'text/html', $this->context('overlord.team', '/', null, true))->reason);
        self::assertSame('event-stream', $rewriter->rewriteBody('data: x', RewriteKind::EventStream, 'text/event-stream', $ctx)->reason);

        $small = $this->rewriter([], [], ['maxBytes' => 65536]);
        $big = str_repeat('a', 70000) . ' https://overlord.team';
        $result = $small->rewriteBody($big, RewriteKind::Json, 'application/json', $ctx);
        self::assertSame('too-large', $result->reason);
        self::assertSame($big, $result->body);

        $empty = $rewriter->rewriteBody('', RewriteKind::Html, 'text/html', $ctx);
        self::assertFalse($empty->changed);
        self::assertFalse($empty->skipped);
    }

    public function testRequestHeadersAndBodiesGoBackToTheOrigin(): void
    {
        $rewriter = $this->rewriter([], [], ['requestBody' => true]);
        $headers = $rewriter->rewriteRequestHeaders(HeaderBag::fromPairs([
            ['Origin', 'https://overload.ir'], ['Referer', 'https://overload.ir/a?b=1'], ['X-Other', 'y'],
        ]));
        self::assertSame('https://overlord.team', $headers->get('Origin'));
        self::assertSame('https://overlord.team/a?b=1', $headers->get('Referer'));
        self::assertSame('y', $headers->get('X-Other'));

        $foreign = $rewriter->rewriteRequestHeaders(HeaderBag::fromPairs([['Origin', 'https://evil.example'], ['Referer', 'https://evil.example/x']]));
        self::assertSame('https://evil.example', $foreign->get('Origin'));

        self::assertSame(
            '{"u":"https:\/\/overlord.team\/a","n":"overload.ir"}',
            $rewriter->rewriteRequestBody('{"u":"https:\/\/overload.ir\/a","n":"overload.ir"}', 'application/json'),
        );
        self::assertSame(
            'a=1&u=https%3A%2F%2Foverlord.team%2Fa&c=b',
            $rewriter->rewriteRequestBody('a=1&u=https%3A%2F%2Foverload.ir%2Fa&c=b', 'application/x-www-form-urlencoded'),
        );
        $disabled = $this->rewriter();
        self::assertSame('{"u":"https://overload.ir"}', $disabled->rewriteRequestBody('{"u":"https://overload.ir"}', 'application/json'));
    }

    public function testFingerprintIsStableAndSensitive(): void
    {
        $base = $this->rewriter();
        self::assertSame(16, strlen($base->fingerprint()));
        self::assertSame($base->fingerprint(), $this->rewriter()->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->rewriter([], [], ['seoMode' => 'noindex'])->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->rewriter(['hostMap' => ['api.overlord.team' => 'api.overload.ir']])->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->rewriter([], ['wsPublicUrl' => 'wss://ws.overload.ir'])->fingerprint());
    }

    public function testServiceWorkerKillScriptIsStatic(): void
    {
        $script = $this->rewriter()->serviceWorkerKillScript();
        self::assertStringContainsString('registration.unregister', $script);
        self::assertStringNotContainsString('overlord.team', $script);
    }

    public function testLargeQuotedStringsDoNotDisableCssRewriting(): void
    {
        $inline = 'data:font/woff2;base64,' . str_repeat('A', 200000);
        $css = '@font-face{src:url("' . $inline . '")}a{background:url(https://overlord.team/x.png)} b{background:image-set("data:image/png;base64,' . str_repeat('B', 20000) . '" 1x, "https://overlord.team/y.png" 2x)}';
        $result = $this->rewriter()->rewriteBody($css, RewriteKind::Css, 'text/css', $this->context());
        self::assertFalse($result->skipped, (string) $result->reason);
        self::assertStringContainsString('url(https://overload.ir/x.png)', $result->body);
        self::assertStringContainsString('"https://overload.ir/y.png" 2x', $result->body);

        $html = '<style>' . $css . '</style><a href="https://overlord.team/">x</a>';
        $page = $this->rewriter()->rewriteBody($html, RewriteKind::Html, 'text/html', $this->context());
        self::assertFalse($page->skipped, (string) $page->reason);
        self::assertStringNotContainsString('overlord.team', $page->body);
    }

    public function testUnterminatedImageSetRunsInLinearTime(): void
    {
        $css = str_repeat('image-set(', 300000) . 'https://overlord.team';
        $started = microtime(true);
        $result = $this->rewriter()->rewriteBody($css, RewriteKind::Css, 'text/css', $this->context());
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertFalse($result->skipped);
    }

    public function testBodyThatCannotFitInMemoryIsPassedThroughAndFlagged(): void
    {
        $body = str_repeat('<loc>https://overlord.team/a</loc>', 200000);
        $previous = (string) ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 24 * 1024 * 1024));
        try {
            $result = $this->rewriter([], [], ['sitemapMaxBytes' => 64 * 1024 * 1024])->rewriteBody($body, RewriteKind::Sitemap, 'application/xml', $this->context());
        } finally {
            ini_set('memory_limit', $previous);
        }
        self::assertTrue($result->skipped);
        self::assertSame('memory', $result->reason);
        self::assertSame($body, $result->body);
    }
}
