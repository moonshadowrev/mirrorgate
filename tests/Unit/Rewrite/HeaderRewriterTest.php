<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Rewrite;

use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\HttpException;
use MirrorGate\Rewrite\RewriteContext;
use PHPUnit\Framework\Attributes\DataProvider;

final class HeaderRewriterTest extends RewriteTestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function redirects(): array
    {
        return [
            'absolute origin' => ['https://overlord.team/x', 'https://ormantek.com/x'],
            'root relative' => ['/x', '/x'],
            'relative' => ['x/y', 'x/y'],
            'protocol relative origin' => ['//overlord.team/x', '//ormantek.com/x'],
            'extra slashes origin' => ['///overlord.team/x', '//ormantek.com/x'],
            'external passes' => ['https://evil.example/x', 'https://evil.example/x'],
            'external protocol relative' => ['//evil.example', '//evil.example'],
            'foreign port' => ['https://overlord.team:8443/x', 'https://overlord.team:8443/x'],
            'already mirror' => ['https://ormantek.com/x', 'https://ormantek.com/x'],
        ];
    }

    #[DataProvider('redirects')]
    public function testLocationRewriting(string $input, string $expected): void
    {
        $out = $this->headers($this->rewriter(), [['Location', $input]]);
        self::assertSame([$expected], $this->values($out, 'Location'));
    }

    /** @return array<string,array{0:string}> */
    public static function unsafeTargets(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'], 'data' => ['data:text/html,x'], 'slash backslash' => ['/\\evil.example'],
            'double backslash' => ['\\\\evil.example'], 'scheme backslash' => ['https:\\\\evil.example'],
            'userinfo' => ['https://a@evil.example'], 'no authority marker' => ['https:evil.example'],
            'vbscript' => ['VBScript:x'], 'padded javascript' => [' javascript:x'], 'file' => ['file:///etc/passwd'],
            'tab' => ["https://overlord.team/\tx"],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function testUnsafeLocationThrowsGeneric502(string $value): void
    {
        try {
            $this->headers($this->rewriter(), [['Location', $value]]);
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(502, $e->status);
            self::assertStringNotContainsString('evil', $e->getMessage());
        }
    }

    public function testBlockedExternalRedirectAndContentLocationAndRefresh(): void
    {
        $block = $this->rewriter([], [], ['redirectExternal' => 'block']);
        self::assertSame(['https://ormantek.com/a'], $this->values($this->headers($block, [['Location', 'https://overlord.team/a']]), 'Location'));
        self::assertSame(['0; url=https://ormantek.com/n'], $this->values($this->headers($block, [['Refresh', '0;url=https://overlord.team/n']]), 'Refresh'));

        foreach ([['Location', 'https://evil.example/'], ['Content-Location', 'https://evil.example/'], ['Refresh', '0; url=https://evil.example/']] as $pair) {
            try {
                $this->headers($block, [$pair]);
                self::fail('Expected HttpException for ' . $pair[0]);
            } catch (HttpException $e) {
                self::assertSame(502, $e->status);
            }
        }
    }

    public function testRefreshWithUnsafeTargetThrows(): void
    {
        $this->expectException(HttpException::class);
        $this->headers($this->rewriter(), [['Refresh', '0; url=javascript:x']]);
    }

    public function testDenyListAndPrefixes(): void
    {
        $in = [
            ['Server', 'nginx'], ['Host', 'origin.internal:8080'], ['X-Powered-By', 'x'], ['Via', '1.1 x'], ['X-Nextjs-Cache', 'HIT'], ['CF-Ray', '1'],
            ['X-Vercel-Id', '1'], ['X-Forwarded-For', '1.1.1.1'], ['Age', '3'], ['Date', 'x'], ['Content-Length', '5'],
            ['ETag', '"a"'], ['Content-Encoding', 'gzip'], ['Accept-Ranges', 'bytes'], ['Cache-Control', 'max-age=5'],
            ['Vary', 'Accept'], ['Content-Type', 'text/html'], ['Alt-Svc', 'h3'], ['Strict-Transport-Security', 'max-age=1'],
            ['Traceparent', 'x'], ['X-Trace-Id', '1'], ['Connection', 'close'], ['Last-Modified', 'x'], ['Server-Timing', 'a'],
            ['X-Amz-Id', '1'], ['X-Envoy-Upstream-Service-Time', '3'], ['Report-To', '{}'], ['NEL', '{}'],
        ];
        $names = array_map(static fn (array $p): string => strtolower($p[0]), $this->headers($this->rewriter(), $in, true));
        self::assertSame(['cache-control', 'vary', 'content-type', 'last-modified'], $names);
    }

    public function testBodyDependentHeadersSurviveWhenBodyIsUnchanged(): void
    {
        $out = $this->headers($this->rewriter(), [['ETag', '"a"'], ['Content-Encoding', 'br'], ['Accept-Ranges', 'bytes']], false);
        self::assertSame(['etag', 'content-encoding', 'accept-ranges'], array_map(static fn (array $p): string => strtolower($p[0]), $out));
    }

    public function testResponseHeaderStripConfigurationAppliesExactAndPrefix(): void
    {
        $rewriter = $this->rewriter([], [], ['responseHeaderStrip' => ['x-custom-strip', 'x-foo-*']]);
        $out = $this->headers($rewriter, [['Content-Type', 'a/b'], ['X-Custom-Strip', '1'], ['X-Foo-Bar', '1'], ['X-Keep', '1']]);
        self::assertSame(['content-type', 'x-keep'], array_map(static fn (array $p): string => strtolower($p[0]), $out));
    }

    public function testSetCookieVariants(): void
    {
        $out = $this->headers($this->rewriter(), [
            ['Set-Cookie', 'a=1; Domain=.overlord.team; Path=/; Expires=Wed, 21 Oct 2026 07:28:00 GMT; HttpOnly; SameSite=None; Partitioned'],
            ['Set-Cookie', '__Host-s=1; Path=/; Secure; Domain=overlord.team'],
            ['Set-Cookie', 'mg_x=1'], ['Set-Cookie', '__Host-mg_y=1'], ['Set-Cookie', 'ok=1; Secure'],
            ['Set-Cookie', "bad=1\tx"], ['Set-Cookie', str_repeat('n', 257) . '=1'],
        ]);
        self::assertSame([
            'a=1; Path=/; Expires=Wed, 21 Oct 2026 07:28:00 GMT; Secure; HttpOnly; SameSite=None; Partitioned',
            '__Host-s=1; Path=/; Secure',
            'ok=1; Secure',
        ], $this->values($out, 'Set-Cookie'));
    }

    public function testCookieDomainRewriteAndHttpMirror(): void
    {
        $rewrite = $this->rewriter([], [], ['cookieDomainMode' => 'rewrite']);
        self::assertSame(['a=1; Domain=ormantek.com; Secure'], $this->values($this->headers($rewrite, [['Set-Cookie', 'a=1; Domain=.overlord.team']]), 'Set-Cookie'));
        self::assertSame(['a=1; Secure'], $this->values($this->headers($rewrite, [['Set-Cookie', 'a=1; Domain=evil.example']]), 'Set-Cookie'));

        $http = $this->rewriter([], ['url' => 'http://ormantek.com', 'scheme' => 'http', 'port' => 80, 'isHttps' => false]);
        self::assertSame(
            ['a=1; HttpOnly; SameSite=Lax'],
            $this->values($this->headers($http, [['Set-Cookie', 'a=1; Secure; HttpOnly; SameSite=None']]), 'Set-Cookie'),
        );
    }

    public function testCookieCountIsCapped(): void
    {
        $pairs = [];
        for ($i = 0; $i < 60; $i++) {
            $pairs[] = ['Set-Cookie', 'c' . $i . '=1'];
        }
        self::assertCount(40, $this->values($this->headers($this->rewriter(), $pairs), 'Set-Cookie'));
    }

    public function testCorsMappingNeverReflectsArbitraryOrigins(): void
    {
        $rewriter = $this->rewriter();
        $mapped = $this->headers($rewriter, [['Access-Control-Allow-Origin', 'https://overlord.team'], ['Access-Control-Allow-Credentials', 'true']]);
        self::assertSame(['https://ormantek.com'], $this->values($mapped, 'Access-Control-Allow-Origin'));
        self::assertSame(['true'], $this->values($mapped, 'Access-Control-Allow-Credentials'));
        self::assertSame(['Origin'], $this->values($mapped, 'Vary'));

        $star = $this->headers($rewriter, [['Access-Control-Allow-Origin', '*'], ['Access-Control-Allow-Credentials', 'true']]);
        self::assertSame(['*'], $this->values($star, 'Access-Control-Allow-Origin'));
        self::assertSame([], $this->values($star, 'Access-Control-Allow-Credentials'));
        self::assertSame([], $this->values($star, 'Vary'));

        $evil = $this->headers($rewriter, [['Access-Control-Allow-Origin', 'https://evil.example']], false, $this->context('overlord.team', '/', 'https://evil.example'));
        self::assertSame([], $this->values($evil, 'Access-Control-Allow-Origin'));

        $own = $this->headers($rewriter, [['Access-Control-Allow-Origin', 'https://ormantek.com']], false, $this->context('overlord.team', '/', 'https://ormantek.com'));
        self::assertSame(['https://ormantek.com'], $this->values($own, 'Access-Control-Allow-Origin'));
    }

    public function testContentSecurityPolicyModes(): void
    {
        $csp = "default-src 'self' https://overlord.team https://*.overlord.team wss://overlord.team; script-src 'self' 'sha256-abc=' 'nonce-xyz' https://cdn.example.com; "
            . "report-uri https://overlord.team/r; report-to grp; img-src data: https: overlord.team:443/img; frame-ancestors 'none'";
        $rewritten = "default-src 'self' https://ormantek.com https://*.ormantek.com wss://overlord.team; script-src 'self' 'nonce-xyz' https://cdn.example.com; "
            . "report-uri https://ormantek.com/r; img-src data: https: ormantek.com/img; frame-ancestors 'none'";

        $out = $this->headers($this->rewriter(), [['Content-Security-Policy', $csp]], true);
        self::assertSame([$rewritten], $this->values($out, 'Content-Security-Policy'));

        $unchanged = $this->headers($this->rewriter(), [['Content-Security-Policy-Report-Only', $csp]], false);
        self::assertStringContainsString("'sha256-abc='", $this->values($unchanged, 'Content-Security-Policy-Report-Only')[0]);

        self::assertSame([], $this->values($this->headers($this->rewriter([], [], ['cspMode' => 'strip']), [['Content-Security-Policy', $csp]]), 'Content-Security-Policy'));
        self::assertSame([$csp], $this->values($this->headers($this->rewriter([], [], ['cspMode' => 'passthrough']), [['Content-Security-Policy', $csp]]), 'Content-Security-Policy'));
    }

    public function testLinkHeaderWithCommasInUrlsAndQuotedParameters(): void
    {
        $in = '<https://overlord.team/a.css>; rel=preload; as=style, </b,c.js>; rel=preload, <https://overlord.team/x,y>; rel="next, prev"';
        $out = $this->headers($this->rewriter(), [['Link', $in]]);
        self::assertSame(
            ['<https://ormantek.com/a.css>; rel=preload; as=style, </b,c.js>; rel=preload, <https://ormantek.com/x,y>; rel="next, prev"'],
            $this->values($out, 'Link'),
        );
    }

    public function testHstsStripOrOwn(): void
    {
        self::assertSame([], $this->values($this->headers($this->rewriter(), [['Strict-Transport-Security', 'max-age=1']]), 'Strict-Transport-Security'));
        $own = $this->rewriter([], [], ['hstsMode' => 'own', 'hstsValue' => 'max-age=31536000']);
        self::assertSame(['max-age=31536000'], $this->values($this->headers($own, []), 'Strict-Transport-Security'));
        $http = $this->rewriter([], ['url' => 'http://ormantek.com', 'scheme' => 'http', 'port' => 80, 'isHttps' => false], ['hstsMode' => 'own', 'hstsValue' => 'max-age=1']);
        self::assertSame([], $this->values($this->headers($http, []), 'Strict-Transport-Security'));
    }

    public function testSeoHeaders(): void
    {
        $noindex = $this->rewriter([], [], ['seoMode' => 'noindex']);
        self::assertSame(['noindex, nofollow'], $this->values($this->headers($noindex, []), 'X-Robots-Tag'));
        self::assertSame(['all, noindex, nofollow'], $this->values($this->headers($noindex, [['X-Robots-Tag', 'all']]), 'X-Robots-Tag'));

        $canonical = $this->rewriter([], [], ['seoMode' => 'canonical-origin']);
        $ctx = new RewriteContext("/p/q>\r\nX-Injected: 1", 'overlord.team', 200, false, null);
        $out = $canonical->rewriteResponseHeaders(HeaderBag::fromPairs([['Content-Type', 'text/html; charset=utf-8']]), 200, true, $ctx)->all();
        $link = $this->values($out, 'Link');
        self::assertCount(1, $link);
        self::assertStringStartsWith('<https://overlord.team/p/q', $link[0]);
        self::assertStringEndsWith('>; rel="canonical"', $link[0]);
        self::assertDoesNotMatchRegularExpression('/[\r\n]/', $link[0]);
        self::assertSame([], $this->values($this->headers($canonical, [['Content-Type', 'application/json']], true), 'Link'));
    }

    public function testHostileRequestValuesNeverReachHeaders(): void
    {
        $hostile = "evil.example\r\nX-Injected: 1";
        $ctx = new RewriteContext('/a' . $hostile, $hostile, 200, false, $hostile);
        $out = $this->rewriter()->rewriteResponseHeaders(HeaderBag::fromPairs([['Content-Type', 'text/html']]), 200, true, $ctx)->all();
        foreach ($out as [$name, $value]) {
            self::assertStringNotContainsString("\r", $value);
            self::assertStringNotContainsString("\n", $value);
            self::assertStringNotContainsString('X-Injected', $name);
        }
    }

    public function testPrefixModeRedirectsKeepExtraOriginPrefix(): void
    {
        $rewriter = $this->rewriter(['extraOrigins' => ['cdn.overlord.team'], 'subdomainMode' => 'prefix']);
        $ctx = new RewriteContext('/', 'cdn.overlord.team', 302, false, null);
        $out = $rewriter->rewriteResponseHeaders(HeaderBag::fromPairs([['Location', '/login']]), 302, false, $ctx)->all();
        self::assertSame(['/__mg/s/cdn.overlord.team/login'], $this->values($out, 'Location'));
    }

    public function testPrefixModeCookiesAreScopedToTheirHost(): void
    {
        $rewriter = $this->rewriter(['subdomainMode' => 'prefix', 'extraOrigins' => ['sub.overlord.team']], [], ['cookieDomainMode' => 'rewrite']);
        $ctx = $this->context('sub.overlord.team');
        $out = $this->headers($rewriter, [
            ['Set-Cookie', 'sid=evil; Path=/; Domain=overlord.team'],
            ['Set-Cookie', 'a=1; Path=/app'],
            ['Set-Cookie', 'b=1'],
            ['Set-Cookie', '__Host-c=1; Path=/; Secure'],
        ], false, $ctx);
        self::assertSame([
            'sid=evil; Path=/__mg/s/sub.overlord.team; Secure',
            'a=1; Path=/__mg/s/sub.overlord.team/app; Secure',
            'b=1; Path=/__mg/s/sub.overlord.team; Secure',
        ], $this->values($out, 'Set-Cookie'));

        $primary = $this->headers($rewriter, [['Set-Cookie', 'sid=1; Path=/']], false, $this->context('overlord.team'));
        self::assertSame(['sid=1; Path=/; Secure'], $this->values($primary, 'Set-Cookie'));
    }

    public function testCookieDomainFollowsTheRequestHost(): void
    {
        $rewriter = $this->rewriter([], ['aliases' => ['other.example'], 'allowedHosts' => ['ormantek.com', 'other.example']], ['cookieDomainMode' => 'rewrite']);
        $alias = new RewriteContext('/', 'overlord.team', 200, false, null, 'other.example');
        self::assertSame(['a=1; Secure'], $this->values($this->headers($rewriter, [['Set-Cookie', 'a=1; Domain=.overlord.team']], false, $alias), 'Set-Cookie'));
        $primary = new RewriteContext('/', 'overlord.team', 200, false, null, 'www.ormantek.com');
        self::assertSame(['a=1; Domain=ormantek.com; Secure'], $this->values($this->headers($rewriter, [['Set-Cookie', 'a=1; Domain=.overlord.team']], false, $primary), 'Set-Cookie'));
    }

    public function testOriginUrlsInOtherHeadersAreRewritten(): void
    {
        $out = $this->headers($this->rewriter(), [
            ['X-Pingback', 'https://overlord.team/xmlrpc.php'],
            ['SourceMap', 'https://overlord.team/app.js.map'],
            ['WWW-Authenticate', 'Bearer resource_metadata="https://overlord.team/.well-known/x"'],
            ['X-Plain', 'no urls here'],
        ]);
        self::assertSame(['https://ormantek.com/xmlrpc.php'], $this->values($out, 'X-Pingback'));
        self::assertSame(['https://ormantek.com/app.js.map'], $this->values($out, 'SourceMap'));
        self::assertSame(['Bearer resource_metadata="https://ormantek.com/.well-known/x"'], $this->values($out, 'WWW-Authenticate'));
        self::assertSame(['no urls here'], $this->values($out, 'X-Plain'));
    }
}
