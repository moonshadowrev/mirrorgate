<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Cache;

use MirrorGate\Cache\CachePolicy;
use MirrorGate\Http\HeaderBag;

final class CachePolicyTest extends CacheTestCase
{
    private function policy(array $overrides = []): CachePolicy
    {
        return new CachePolicy($this->config($overrides), $this->clock);
    }

    /** @param list<array{0:string,1:string}> $headers */
    private function eval(array $headers, int $status = 200, string $path = '/p', string $query = '', array $overrides = [], ?int $length = 10): \MirrorGate\Cache\CacheDecision
    {
        return $this->policy($overrides)->evaluate($this->request($path, $query), $status, new HeaderBag($headers), $length);
    }

    public function testFreshnessPrecedence(): void
    {
        self::assertSame(120, $this->eval([['Cache-Control', 'max-age=60, s-maxage=120']])->ttl);
        self::assertSame(60, $this->eval([['Cache-Control', 'max-age=60'], ['Expires', 'Thu, 01 Jan 2099 00:00:00 GMT']])->ttl);
        self::assertSame(300, $this->eval([])->ttl, 'default ttl');
        self::assertSame(86400, $this->eval([['Cache-Control', 'max-age=999999']])->ttl, 'clamped to max ttl');
        self::assertSame(0, $this->eval([['Cache-Control', 'max-age=abc']])->ttl, 'invalid max-age is stale');
        $date = gmdate('D, d M Y H:i:s', $this->clock->time()) . ' GMT';
        self::assertSame(100, $this->eval([['Expires', gmdate('D, d M Y H:i:s', $this->clock->time() + 100) . ' GMT'], ['Date', $date]])->ttl);
        self::assertSame(0, $this->eval([['Expires', '0']])->ttl);
        $lm = gmdate('D, d M Y H:i:s', $this->clock->time() - 1000) . ' GMT';
        self::assertSame(100, $this->eval([['Last-Modified', $lm]])->ttl, 'heuristic is 10 percent of document age');
        self::assertSame(20, $this->eval([['Cache-Control', 'max-age=100'], ['Age', '80']])->ttl);
    }

    public function testNeverStorableCases(): void
    {
        self::assertFalse($this->eval([['Set-Cookie', 'a=b']])->storable);
        self::assertFalse($this->eval([['Cache-Control', 'no-store']])->storable);
        self::assertFalse($this->eval([['Cache-Control', 'private, max-age=60']])->storable);
        self::assertFalse($this->eval([['Vary', '*']])->storable);
        self::assertFalse($this->eval([['Vary', 'Accept-Encoding, Cookie']])->storable);
        self::assertFalse($this->eval([['Vary', 'authorization']])->storable);
        self::assertFalse($this->eval([['Content-Security-Policy', "default-src 'self'; script-src 'nonce-xyz'"]])->storable);
        self::assertFalse($this->eval([['Content-Security-Policy-Report-Only', "script-src 'nonce-xyz'"]])->storable);
        self::assertFalse($this->eval([['Content-Range', 'bytes 0-1/2']], 200)->storable);
        self::assertFalse($this->eval([], 206)->storable);
        self::assertFalse($this->eval([], 500)->storable);
        self::assertFalse($this->eval([], 200, '/p', '', [], 6 * 1024 * 1024)->storable);
        self::assertTrue($this->eval([], 200, '/p', '', [], null)->storable);
        self::assertFalse($this->eval([['Cache-Control', 'no-cache']])->storable, 'no-cache without validator');
        self::assertTrue($this->eval([['Cache-Control', 'no-cache'], ['ETag', '"x"']])->storable);

        $withAuth = $this->request('/p', '', [['Authorization', 'Bearer x']]);
        self::assertFalse($this->policy()->evaluate($withAuth, 200, new HeaderBag([]), 1)->storable);
        $post = $this->request('/p', '', [], 'POST');
        self::assertFalse($this->policy()->evaluate($post, 200, new HeaderBag([]), 1)->storable);
        $head = $this->request('/p', '', [], 'HEAD');
        self::assertFalse($this->policy()->evaluate($head, 200, new HeaderBag([]), 1)->storable);
    }

    public function testNegativeCachingAndRules(): void
    {
        self::assertSame(60, $this->eval([['Cache-Control', 'max-age=3000']], 404)->ttl);
        self::assertFalse($this->eval([], 404, '/p', 'a=1')->storable, '404 with query string');
        $rules = [['pattern' => '/p*', 'ttl' => 10]];
        $decision = $this->eval([], 404, '/pp', 'a=1', ['rules' => $rules]);
        self::assertTrue($decision->storable);
        self::assertSame(10, $decision->ttl);
        self::assertFalse($this->eval([], 200, '/admin/x', '', ['rules' => [['pattern' => '/admin/*', 'ttl' => -1]]])->storable);
        self::assertSame(5, $this->eval([['Cache-Control', 'max-age=100']], 200, '/static/a.css', '', ['rules' => [['pattern' => '/static/*.css', 'ttl' => 5]]])->ttl);
    }

    public function testStaleWindowsAndRevalidation(): void
    {
        $d = $this->eval([['Cache-Control', 'max-age=10, stale-while-revalidate=5, stale-if-error=7']]);
        self::assertSame([10, 5, 7, false], [$d->ttl, $d->swr, $d->staleIfError, $d->mustRevalidate]);
        $d = $this->eval([['Cache-Control', 'max-age=10, must-revalidate']]);
        self::assertSame([0, true], [$d->swr, $d->mustRevalidate]);
        $d = $this->eval([['Cache-Control', 'max-age=10']]);
        self::assertSame([60, 604800], [$d->swr, $d->staleIfError], 'config defaults');
    }

    public function testVarySelector(): void
    {
        $policy = $this->policy(['varyExtra' => ['Accept-Language']]);
        self::assertSame(['accept-language'], $policy->varySelector(new HeaderBag([['Vary', 'Accept-Encoding, Origin, X-Other']])));
        self::assertSame(['accept-language'], $policy->varySelector(new HeaderBag([])));
    }

    public function testBodyCspNonceDetection(): void
    {
        $policy = $this->policy();
        self::assertTrue($policy->bodyContainsCspNonce('<html><meta http-equiv="Content-Security-Policy" content="script-src \'nonce-abc\'"></html>'));
        self::assertTrue($policy->bodyContainsCspNonce("<META HTTP-EQUIV='content-security-policy' CONTENT=\"script-src 'NONCE-abc'\">"));
        self::assertFalse($policy->bodyContainsCspNonce('<html><meta http-equiv="Content-Security-Policy" content="default-src \'self\'"><script nonce="x"></script></html>'));
        self::assertFalse($policy->bodyContainsCspNonce('<p>no nonce</p>'));
    }

    public function testRequestEligibility(): void
    {
        $policy = $this->policy();
        self::assertTrue($policy->isRequestEligible($this->request('/a')));
        self::assertTrue($policy->isRequestEligible($this->request('/a', '', [], 'HEAD')));
        self::assertFalse($policy->isRequestEligible($this->request('/a', '', [], 'POST')));
        foreach (['Authorization', 'Range', 'Upgrade', 'RSC', 'Next-Router-State-Tree', 'Next-Router-Prefetch', 'Next-Url', 'Next-Action'] as $name) {
            self::assertFalse($policy->isRequestEligible($this->request('/a', '', [[$name, '1']])), $name);
        }
        self::assertFalse($policy->isRequestEligible($this->request('/a', '', [['Cookie', 'a=b']])), 'static-only: html with cookie');
        self::assertTrue($policy->isRequestEligible($this->request('/a.css', '', [['Cookie', 'a=b']])));
        self::assertFalse($this->policy(['cookieMode' => 'bypass'])->isRequestEligible($this->request('/a.css', '', [['Cookie', 'a=b']])));
        self::assertTrue($policy->isRequestEligible($this->request('/a', '', [['Cache-Control', 'no-cache']])), 'client no-cache ignored by default');
        self::assertFalse($this->policy(['honorClientNoCache' => true])->isRequestEligible($this->request('/a', '', [['Cache-Control', 'no-cache']])));
        self::assertFalse($this->policy(['rules' => [['pattern' => '/live/*', 'ttl' => -1]]])->isRequestEligible($this->request('/live/x')));
    }

    public function testCredentialHeadersBypassTheCache(): void
    {
        $policy = $this->policy();
        foreach (['X-API-Key', 'X-Auth-Token', 'X-Access-Token', 'Proxy-Authorization', 'X-CSRF-Token'] as $name) {
            self::assertFalse($policy->isRequestEligible($this->request('/api/me', '', [[$name, 'k1']])), $name);
        }
        self::assertTrue($this->policy(['bypassHeaders' => []])->isRequestEligible($this->request('/api/me', '', [['X-API-Key', 'k1']])));
        self::assertFalse($this->policy(['bypassHeaders' => ['x-tenant']])->isRequestEligible($this->request('/a', '', [['X-Tenant', 'a']])));
    }

    public function testServiceWorkerAndCrossSiteOriginBypassTheCache(): void
    {
        $policy = $this->policy();
        self::assertFalse($policy->isRequestEligible($this->request('/sw.js', '', [['Service-Worker', 'script']])));
        self::assertFalse($policy->isRequestEligible($this->request('/api/data', '', [['Origin', 'https://evil.example']])));
        self::assertTrue($policy->isRequestEligible($this->request('/font.woff2', '', [['Origin', 'https://overload.ir']])));
        self::assertTrue($policy->isRequestEligible($this->request('/font.woff2', '', [['Origin', 'http://OVERLOAD.ir:8080']])));
    }

    public function testVaryOnHeadersTheFillNeverSendsIsNotStorable(): void
    {
        foreach (['Accept', 'Accept-Language', 'User-Agent', 'Accept-Encoding, X-Tenant'] as $vary) {
            $d = $this->eval([['Cache-Control', 'max-age=60'], ['Vary', $vary]]);
            self::assertFalse($d->storable, $vary);
            self::assertSame('vary_unsupported', $d->reason, $vary);
        }
        self::assertTrue($this->eval([['Cache-Control', 'max-age=60'], ['Vary', 'Accept-Encoding, Origin, RSC, Next-Url']])->storable);
        self::assertTrue($this->eval([['Cache-Control', 'max-age=60'], ['Vary', 'Accept']], 200, '/p', '', ['varyExtra' => ['accept']])->storable);
    }
}
