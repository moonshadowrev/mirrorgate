<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Http\HttpException;
use MirrorGate\Security\RequestHeaderBuilder;
use PHPUnit\Framework\TestCase;

final class RequestHeaderBuilderTest extends TestCase
{
    /** @param list<array{0:string,1:string}> $headers */
    private function build(array $headers, array $cfg = [], bool $cond = false, ?string $ae = null, bool $decode = false, bool $eligible = false, string $ip = '203.0.113.9'): \MirrorGate\Http\HeaderBag
    {
        $builder = new RequestHeaderBuilder(Fixtures::config($cfg));
        return $builder->build(Fixtures::context(Fixtures::request('GET', '/', $headers), $ip), $cond, $ae, $decode, $eligible);
    }

    public function testStripsDangerousClientHeaders(): void
    {
        $out = $this->build([
            ['Host', 'evil.com'], ['X-Forwarded-For', '6.6.6.6'], ['X-Forwarded-Host', 'evil.com'], ['X-Forwarded-Proto', 'http'],
            ['Forwarded', 'for=6.6.6.6'], ['X-Real-IP', '6.6.6.6'], ['True-Client-IP', '6.6.6.6'], ['CF-Connecting-IP', '6.6.6.6'],
            ['CF-Ray', 'abc'], ['X-Original-URL', '/admin'], ['X-Rewrite-URL', '/admin'], ['X-HTTP-Method-Override', 'DELETE'],
            ['X-Middleware-Subrequest', 'middleware'], ['X-Invoke-Path', '/x'], ['X-Matched-Path', '/x'], ['Proxy', 'http://evil'],
            ['Via', '1.1 evil'], ['Expect', '100-continue'], ['Content-Length', '5'], ['Transfer-Encoding', 'chunked'],
            ['Accept-Encoding', 'gzip'], ['X-MirrorGate-Evil', '1'], ['Connection', 'close, X-Secret'], ['X-Secret', 'x'],
            ['Upgrade', 'h2c'], ['HTTP2-Settings', 'AAA'], ['X-Keep', 'yes'], ['User-Agent', 'UA/1'],
        ]);
        $names = $out->names();
        foreach (['host', 'forwarded', 'x-real-ip', 'true-client-ip', 'cf-connecting-ip', 'cf-ray', 'x-original-url', 'x-rewrite-url',
            'x-http-method-override', 'x-middleware-subrequest', 'x-invoke-path', 'x-matched-path', 'proxy', 'expect', 'content-length',
            'transfer-encoding', 'accept-encoding', 'x-mirrorgate-evil', 'connection', 'x-secret', 'upgrade', 'http2-settings'] as $gone) {
            self::assertNotContains($gone, $names, $gone);
        }
        self::assertSame('yes', $out->get('x-keep'));
        self::assertSame('UA/1', $out->get('user-agent'));
        self::assertSame(['203.0.113.9'], $out->getAll('x-forwarded-for'));
        self::assertSame(['https'], $out->getAll('x-forwarded-proto'));
        self::assertSame(['ormantek.com'], $out->getAll('x-forwarded-host'));
        self::assertSame(['443'], $out->getAll('x-forwarded-port'));
        self::assertSame(['1.1 mirrorgate'], $out->getAll('via'));
        self::assertSame(['inst0123abcd'], $out->getAll('x-mirrorgate-hop'));
    }

    public function testClientIpAndViaAreOptional(): void
    {
        $out = $this->build([], ['forwardClientIp' => false, 'addVia' => false]);
        self::assertFalse($out->has('x-forwarded-for'));
        self::assertFalse($out->has('via'));
    }

    public function testConditionalHeadersOnlyForPassthrough(): void
    {
        $h = [['Range', 'bytes=0-1'], ['If-None-Match', '"a"'], ['If-Modified-Since', 'x'], ['If-Range', 'y'], ['If-Match', 'z'], ['If-Unmodified-Since', 'q']];
        $off = $this->build($h);
        foreach (['range', 'if-none-match', 'if-modified-since', 'if-range', 'if-match', 'if-unmodified-since'] as $n) {
            self::assertFalse($off->has($n), $n);
        }
        $on = $this->build($h, [], true);
        self::assertSame('bytes=0-1', $on->get('range'));
        self::assertSame('"a"', $on->get('if-none-match'));
    }

    public function testAuthorizationAndCookiePolicy(): void
    {
        $h = [['Authorization', 'Bearer abc'], ['Cookie', 'a=1; mg_state=2; __Host-mg_x=3; b=4'], ['Cookie', 'c=5; MG_other=6']];
        $out = $this->build($h);
        self::assertSame('Bearer abc', $out->get('authorization'));
        self::assertSame(['a=1; b=4; c=5'], $out->getAll('cookie'));

        $out = $this->build($h, ['forwardCookies' => false, 'forwardAuthorization' => false]);
        self::assertFalse($out->has('authorization'));
        self::assertFalse($out->has('cookie'));

        $out = $this->build([['Cookie', 'mg_only=1']]);
        self::assertFalse($out->has('cookie'));
    }

    public function testAcceptEncodingPassthroughOnly(): void
    {
        self::assertSame('gzip, br', $this->build([], [], false, 'gzip, br')->get('accept-encoding'));
        self::assertFalse($this->build([], [], false, null)->has('accept-encoding'));
        self::assertFalse($this->build([], [], false, "gzip\r\nX-Evil: 1")->has('accept-encoding'));
        self::assertFalse($this->build([['Accept-Encoding', 'gzip']], [], false, 'gzip', true)->has('accept-encoding'));
    }

    public function testCacheFillRequestsAreAnonymousAndAllowListed(): void
    {
        $out = $this->build([
            ['Cookie', 'sid=1'], ['Authorization', 'Bearer x'], ['Accept', 'text/html'], ['Accept-Language', 'fa'],
            ['User-Agent', 'Evil/1'], ['Cache-Control', 'no-cache'], ['Pragma', 'no-cache'], ['Referer', 'https://x.test/'],
            ['Origin', 'https://ormantek.com'], ['X-Custom', 'y'], ['Range', 'bytes=0-1'], ['Content-Type', 'text/plain'],
            ['Service-Worker', 'script'],
        ], [], true, 'gzip', true, true);
        foreach (['cookie', 'authorization', 'accept-language', 'cache-control', 'pragma', 'referer', 'x-custom', 'range'] as $gone) {
            self::assertFalse($out->has($gone), $gone);
        }
        self::assertSame('*/*', $out->get('accept'));
        self::assertSame('Mozilla/5.0 (compatible; MirrorGate/' . \MirrorGate\Version::VERSION . ')', $out->get('user-agent'));
        foreach (['origin', 'content-type', 'service-worker', 'x-forwarded-for'] as $gone) {
            self::assertFalse($out->has($gone), $gone);
        }
        self::assertFalse($out->has('accept-encoding'));
    }

    public function testCacheFillHonoursVaryExtra(): void
    {
        $out = $this->build(
            [['Accept', 'text/html'], ['Accept-Language', 'fa'], ['User-Agent', 'Browser/1']],
            ['varyExtra' => ['accept', 'accept-language', 'user-agent']],
            false, null, true, true,
        );
        self::assertSame('text/html', $out->get('accept'));
        self::assertSame('fa', $out->get('accept-language'));
        self::assertSame('Browser/1', $out->get('user-agent'));
    }

    public function testHopChainAndLoopDetection(): void
    {
        $out = $this->build([['X-MirrorGate-Hop', 'other1, other2']]);
        self::assertSame(['other1, other2, inst0123abcd'], $out->getAll('x-mirrorgate-hop'));

        $out = $this->build([['X-MirrorGate-Hop', 'bad entry!, ok']]);
        self::assertSame(['ok, inst0123abcd'], $out->getAll('x-mirrorgate-hop'));

        foreach ([[['X-MirrorGate-Hop', 'a, INST0123ABCD']], [['X-MirrorGate-Hop', 'a,b,c,d,e,f,g,h']]] as $headers) {
            try {
                $this->build($headers);
                self::fail('Expected 508');
            } catch (HttpException $e) {
                self::assertSame(508, $e->status);
            }
        }
    }

    public function testExtendedDenylistAndUnderscoreSpellings(): void
    {
        $out = $this->build([
            ['X-Originating-IP', '127.0.0.1'], ['X-Remote-IP', '127.0.0.1'], ['X-Remote-Addr', '127.0.0.1'],
            ['Fastly-Client-IP', '127.0.0.1'], ['X-Azure-ClientIP', '127.0.0.1'], ['X-ProxyUser-Ip', '127.0.0.1'],
            ['Forwarded-For', '127.0.0.1'], ['Front-End-Https', 'on'], ['X-Url-Scheme', 'https'], ['X-Arr-Ssl', '1'],
            ['X-Custom-IP-Authorization', '127.0.0.1'], ['Remote-User', 'admin'], ['X-Remote-User', 'admin'],
            ['X-Authenticated-User', 'admin'], ['X-Auth-Request-User', 'admin'], ['X-Amzn-Oidc-Data', 'x'],
            ['X-Envoy-Internal', 'true'], ['X-Appengine-User-Ip', '127.0.0.1'],
            ['X_Forwarded_For', '127.0.0.1'], ['X_Http_Method_Override', 'DELETE'], ['Client_Ip', '127.0.0.1'],
            ['X-Keep', 'yes'],
        ]);
        foreach ($out->names() as $name) {
            self::assertFalse(str_contains($name, '_'), $name);
        }
        foreach (['x-originating-ip', 'x-remote-ip', 'x-remote-addr', 'fastly-client-ip', 'x-azure-clientip', 'x-proxyuser-ip',
            'forwarded-for', 'front-end-https', 'x-url-scheme', 'x-arr-ssl', 'x-custom-ip-authorization', 'remote-user',
            'x-remote-user', 'x-authenticated-user', 'x-auth-request-user', 'x-amzn-oidc-data', 'x-envoy-internal',
            'x-appengine-user-ip', 'x-http-method-override', 'client-ip'] as $gone) {
            self::assertFalse($out->has($gone), $gone);
        }
        self::assertSame(['203.0.113.9'], $out->getAll('x-forwarded-for'));
        self::assertSame('yes', $out->get('x-keep'));
    }

    public function testOperatorStripList(): void
    {
        $out = $this->build([['X-Tenant-Id', '1'], ['X-Internal-Flag', '1'], ['X-Keep', 'y']], ['requestHeaderStrip' => ['x-tenant-id', 'x-internal-*']]);
        self::assertFalse($out->has('x-tenant-id'));
        self::assertFalse($out->has('x-internal-flag'));
        self::assertSame('y', $out->get('x-keep'));
    }

    public function testCacheFillCarriesNoClientAddress(): void
    {
        $out = $this->build([['Accept', 'text/html']], [], false, null, true, true);
        self::assertFalse($out->has('x-forwarded-for'));
        self::assertSame(['203.0.113.9'], $this->build([], [], false, null, true, false)->getAll('x-forwarded-for'));
    }
}
