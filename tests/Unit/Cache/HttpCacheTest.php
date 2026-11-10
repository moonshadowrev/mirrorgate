<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Cache;

use MirrorGate\Cache\CacheEntry;
use MirrorGate\Cache\CacheState;
use MirrorGate\Http\HeaderBag;

final class HttpCacheTest extends CacheTestCase
{
    public function testLifecycleFreshSwrStaleErrorExpiredAndLazyDelete(): void
    {
        $cache = $this->cache();
        $req = $this->request('/page');
        self::assertSame(CacheState::Miss, $cache->lookup($req)->state);

        self::assertTrue($this->storeBody($cache, $req, '<html>hello</html>', decision: $this->decision(60, 30, 3600)));
        $hit = $cache->lookup($req);
        self::assertSame(CacheState::Fresh, $hit->state);
        self::assertNotNull($hit->entry);
        self::assertSame('<html>hello</html>', $hit->entry->bodyContents());
        self::assertSame(CacheEntry::etagFor('<html>hello</html>'), $hit->entry->mirrorEtag);
        self::assertSame(['If-None-Match' => '"abc"', 'If-Modified-Since' => 'Wed, 01 Jan 2025 00:00:00 GMT'], $hit->entry->conditionalHeaders());

        $this->advance(70);
        self::assertSame(CacheState::StaleWhileRevalidate, $cache->lookup($req)->state);
        $this->advance(30);
        self::assertSame(CacheState::StaleUsableOnError, $cache->lookup($req)->state);
        $this->advance(3600);
        $expired = $cache->lookup($req);
        self::assertSame(CacheState::Expired, $expired->state, 'validators keep the entry for conditional revalidation');
        $this->advance(3600);
        self::assertSame(CacheState::Miss, $cache->lookup($req)->state);
        self::assertSame([], $this->entryFiles(), 'entry beyond retention is deleted lazily');
    }

    public function testQueryParamIgnoredAndHeadSharesGetEntry(): void
    {
        $cache = $this->cache();
        self::assertTrue($this->storeBody($cache, $this->request('/p', 'a=1&utm_source=x'), 'body'));
        self::assertSame(CacheState::Fresh, $cache->lookup($this->request('/p', 'a=1&utm_source=y'))->state);
        self::assertSame(CacheState::Fresh, $cache->lookup($this->request('/p', 'a=1', [], 'HEAD'))->state);
        self::assertSame(CacheState::Miss, $cache->lookup($this->request('/p', 'a=2'))->state);
    }

    public function testVaryAwareVariantsAndInstanceIsolation(): void
    {
        $cache = $this->cache($this->config(['varyExtra' => ['accept-language']]));
        $en = $this->request('/v', '', [['Accept-Language', 'en']]);
        $fa = $this->request('/v', '', [['Accept-Language', 'fa']]);
        self::assertTrue($this->storeBody($cache, $en, 'english', selector: ['accept-language']));
        self::assertSame(CacheState::Fresh, $cache->lookup($en)->state);
        self::assertSame(CacheState::Miss, $cache->lookup($fa)->state);
        self::assertTrue($this->storeBody($cache, $fa, 'farsi', selector: ['accept-language']));
        self::assertSame('farsi', $cache->lookup($fa)->entry?->bodyContents());
        self::assertSame('english', $cache->lookup($en)->entry?->bodyContents());

        $other = $this->cache($this->config(['varyExtra' => ['accept-language']]), 'inst2');
        self::assertSame(CacheState::Miss, $other->lookup($en)->state);
        $changed = $this->cache($this->config(['varyExtra' => ['accept-language']]), 'inst1', 'fp-other');
        self::assertSame(CacheState::Miss, $changed->lookup($en)->state, 'different rewrite fingerprint never shares variants');
    }

    public function testRefusesCookiesNonceAndOversizedBodies(): void
    {
        $cache = $this->cache($this->config(['maxObjectBytes' => 100]));
        $req = $this->request('/x');
        $key = $cache->keyFor($req, []);
        $ok = $this->decision();
        self::assertFalse($cache->store($key, 200, new HeaderBag([['Set-Cookie', 'a=b']]), 'x', $ok, null, null, []));
        self::assertFalse($cache->store($key, 200, new HeaderBag([['Content-Security-Policy', "script-src 'nonce-abc'"]]), 'x', $ok, null, null, []));
        self::assertFalse($cache->store($key, 200, new HeaderBag([]), str_repeat('a', 101), $ok, null, null, []));
        self::assertFalse($cache->store($key, 206, new HeaderBag([]), 'x', $ok, null, null, []));
        self::assertFalse($cache->store($key, 200, new HeaderBag([]), 'x', new \MirrorGate\Cache\CacheDecision(false, 0, 0, 0, false, 'no'), null, null, []));
        self::assertSame([], $this->entryFiles());
    }

    public function testGzipStorageRoundTripAndHeaderAllowList(): void
    {
        $cache = $this->cache();
        $req = $this->request('/big');
        $body = str_repeat('<p>repeat me</p>', 400);
        $headers = [['Content-Type', 'text/html'], ['Set-Cookie-Not', 'kept'], ['ETag', 'W/"origin"'], ['Content-Length', '5'], ['X-MirrorGate-Cache', 'HIT'], ['Link', '</a>; rel=preload']];
        self::assertTrue($this->storeBody($cache, $req, $body, $headers));
        $entry = $cache->lookup($req)->entry;
        self::assertNotNull($entry);
        if (function_exists('gzencode')) {
            self::assertSame('gzip', $entry->storedEncoding);
            self::assertLessThan(strlen($body), $entry->bodyLength);
        }
        self::assertSame($body, $entry->bodyContents());
        self::assertFalse($entry->headers->has('etag'));
        self::assertFalse($entry->headers->has('content-length'));
        self::assertFalse($entry->headers->has('x-mirrorgate-cache'));
        self::assertTrue($entry->headers->has('link'));
        $handle = $entry->openBody();
        self::assertIsResource($handle);
        fclose($handle);
    }

    public function testCorruptFileIsMissAndUnlinked(): void
    {
        $cache = $this->cache();
        $req = $this->request('/c');
        self::assertTrue($this->storeBody($cache, $req, 'good body'));
        $files = $this->entryFiles();
        self::assertCount(1, $files);
        $data = (string) file_get_contents($files[0]);
        file_put_contents($files[0], substr($data, 0, -3) . 'XYZ');
        self::assertSame(CacheState::Miss, $cache->lookup($req)->state);
        self::assertSame([], $this->entryFiles());

        self::assertTrue($this->storeBody($cache, $req, 'good body'));
        $files = $this->entryFiles();
        file_put_contents($files[0], 'garbage');
        self::assertSame(CacheState::Miss, $cache->lookup($req)->state);
        self::assertSame([], $this->entryFiles());
    }

    public function testTraversalLookingPathsOnlyProduceHexFileNames(): void
    {
        $cache = $this->cache();
        foreach (['/../../etc/passwd', '/%2e%2e/%2e%2e/x', "/a\0b", '/' . str_repeat('a', 5000)] as $path) {
            self::assertTrue($this->storeBody($cache, $this->request($path), 'x'));
        }
        $count = 0;
        $root = realpath($this->dir);
        self::assertIsString($root);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $real = $file->getRealPath();
            self::assertIsString($real);
            self::assertStringStartsWith($root, $real);
            $relative = substr($file->getPathname(), strlen($this->dir));
            self::assertDoesNotMatchRegularExpression('#\.\.|%2e|\x00|passwd#i', $relative);
            $count++;
        }
        self::assertGreaterThan(0, $count);
        self::assertCount(4, $this->entryFiles());
    }

    public function testPurgeUrlHardAndSoft(): void
    {
        $cache = $this->cache();
        $req = $this->request('/u', 'q=1');
        $this->storeBody($cache, $req, 'one');
        self::assertSame(1, $cache->purgeUrl('/u?q=1', true));
        $soft = $cache->lookup($req);
        self::assertSame(CacheState::Expired, $soft->state, 'soft purge forces revalidation but keeps the entry');
        self::assertNotNull($soft->entry);

        $this->advance(5);
        $this->storeBody($cache, $req, 'two');
        self::assertSame(CacheState::Fresh, $cache->lookup($req)->state);
        self::assertSame(1, $cache->purgeUrl('/u?q=1', false));
        self::assertSame(CacheState::Miss, $cache->lookup($req)->state);
        self::assertSame(0, $cache->purgeUrl('/u?q=1', false));
        self::assertSame(0, $cache->purgeUrl('no-slash', false));
    }

    public function testPurgePrefixIsSegmentAligned(): void
    {
        $cache = $this->cache();
        foreach (['/blog', '/blog/a', '/blog/a/b', '/blogger', '/other'] as $path) {
            $this->storeBody($cache, $this->request($path), 'x');
        }
        self::assertSame(3, $cache->purgePrefix('/blog/', false));
        self::assertSame(CacheState::Miss, $cache->lookup($this->request('/blog/a'))->state);
        self::assertSame(CacheState::Fresh, $cache->lookup($this->request('/blogger'))->state);
        self::assertSame(CacheState::Fresh, $cache->lookup($this->request('/other'))->state);

        self::assertSame(1, $cache->purgePrefix('/other', true));
        self::assertSame(CacheState::Expired, $cache->lookup($this->request('/other'))->state);
    }

    public function testPurgeAllHardAndSoft(): void
    {
        $cache = $this->cache();
        $a = $this->request('/a');
        $b = $this->request('/b');
        $this->storeBody($cache, $a, 'a');
        $this->storeBody($cache, $b, 'b');
        self::assertSame(2, $cache->stats()->entries);

        self::assertSame(2, $cache->purgeAll(true));
        self::assertSame(CacheState::Expired, $cache->lookup($a)->state);

        $before = $cache->stats()->generation;
        self::assertSame(2, $cache->purgeAll(false));
        self::assertSame($before + 1, $cache->stats()->generation);
        self::assertSame(CacheState::Miss, $cache->lookup($a)->state);
        self::assertSame(CacheState::Miss, $cache->lookup($b)->state);
        self::assertSame(0, $cache->stats()->entries);
    }

    public function testRefreshExtendsFreshnessWithoutChangingBody(): void
    {
        $cache = $this->cache();
        $req = $this->request('/r');
        $this->storeBody($cache, $req, 'refresh me', decision: $this->decision(10, 0, 3600));
        $this->advance(20);
        $lookup = $cache->lookup($req);
        self::assertSame(CacheState::StaleUsableOnError, $lookup->state);
        self::assertTrue($cache->refresh($lookup->key, $this->decision(100, 0, 3600), new HeaderBag([['ETag', '"new"']])));
        $after = $cache->lookup($req);
        self::assertSame(CacheState::Fresh, $after->state);
        self::assertSame('refresh me', $after->entry?->bodyContents());
        self::assertSame('"new"', $after->entry->originEtag);
    }

    public function testRefreshWithUnstorableDecisionDeletesEntry(): void
    {
        $cache = $this->cache();
        $req = $this->request('/r2');
        $this->storeBody($cache, $req, 'x');
        $lookup = $cache->lookup($req);
        self::assertFalse($cache->refresh($lookup->key, new \MirrorGate\Cache\CacheDecision(false, 0, 0, 0, false, 'no_store'), null));
        self::assertSame(CacheState::Miss, $cache->lookup($req)->state);
    }

    public function testRefreshLockCoalescing(): void
    {
        $cache = $this->cache();
        $req = $this->request('/lock');
        $key = $cache->lookup($req)->key;
        $lock = $cache->tryLockRefresh($key);
        self::assertNotNull($lock);
        self::assertNull($cache->tryLockRefresh($key), 'second request cannot take the refresh lock');
        $lock->release();
        $lock->release();
        $again = $cache->tryLockRefresh($key);
        self::assertNotNull($again);
        $again->release();
        // No holder and no entry: waitForEntry returns immediately instead of burning the budget.
        self::assertNull($cache->waitForEntry($key, 1000));

        $this->storeBody($cache, $req, 'published');
        self::assertSame('published', $cache->waitForEntry($key, 50)?->bodyContents());
    }

    public function testGcRemovesExpiredAndOrphans(): void
    {
        $cache = $this->cache();
        $this->storeBody($cache, $this->request('/gc1'), 'x', decision: $this->decision(1, 0, 0));
        $this->storeBody($cache, $this->request('/gc2'), 'y', decision: $this->decision(100000, 0, 0));
        self::assertCount(2, $this->entryFiles());
        $this->advance(10000);
        $report = $cache->gc(5000);
        self::assertGreaterThanOrEqual(1, $report->deleted);
        self::assertCount(1, $this->entryFiles());
        self::assertSame(1, $cache->stats()->entries);
        self::assertSame(CacheState::Fresh, $cache->lookup($this->request('/gc2'))->state);
    }

    public function testMaxEntriesAdmissionControl(): void
    {
        $cache = $this->cache($this->config(['maxEntries' => 100]));
        $stored = 0;
        for ($i = 0; $i < 130; $i++) {
            if ($this->storeBody($cache, $this->request('/n' . $i), 'x')) {
                $stored++;
            }
        }
        self::assertLessThanOrEqual(105, count($this->entryFiles()), 'cap enforced by eviction or refusal');
        self::assertGreaterThan(80, $stored);
    }

    public function testDisabledCacheNeverStores(): void
    {
        $cache = $this->cache($this->config(['enabled' => false]));
        $req = $this->request('/d');
        self::assertFalse($cache->isEnabled());
        self::assertFalse($cache->isRequestEligible($req));
        self::assertFalse($this->storeBody($cache, $req, 'x'));
        self::assertSame(CacheState::Miss, $cache->lookup($req)->state);
    }

    public function testMkdirLockMode(): void
    {
        $cache = $this->cache($this->config(['lockMode' => 'mkdir']));
        $key = $cache->lookup($this->request('/m'))->key;
        $lock = $cache->tryLockRefresh($key);
        self::assertNotNull($lock);
        self::assertNull($cache->tryLockRefresh($key));
        $lock->release();
        self::assertNotNull($cache->tryLockRefresh($key));
    }

    private function normalizedRequest(string $rawTarget): \MirrorGate\Http\Request
    {
        $target = \MirrorGate\Http\PathNormalizer::normalize($rawTarget);

        return new \MirrorGate\Http\Request('GET', $target, new HeaderBag([]), '127.0.0.1', 'ormantek.com', true, null, false, $target->pathAndQuery());
    }

    public function testPurgeUsesTheSameCanonicalFormAsLookup(): void
    {
        $cache = $this->cache();
        foreach (['/caf%C3%A9', '/a%41', '/x/y', '/b/c?q=%2f'] as $stored) {
            $this->storeBody($cache, $this->normalizedRequest($stored), 'body');
        }
        self::assertSame(1, $cache->purgeUrl("/caf\u{e9}", false), 'raw UTF-8 input');
        self::assertSame(1, $cache->purgeUrl('/aA', false), 'unreserved escape decoded');
        self::assertSame(1, $cache->purgeUrl('/x/./z/../y', false), 'dot segments removed');
        self::assertSame(1, $cache->purgeUrl('/b/c?q=%2f', true), 'query is kept exactly as stored');
        self::assertSame(0, $cache->purgeUrl('/b/c?q=%2F', true), 'a different query is a different key');
    }

    public function testUpstreamQueryDropsIgnoredParameters(): void
    {
        $cache = $this->cache();
        self::assertSame('a=1', $cache->upstreamQuery('a=1&utm_source=%3Cscript%3E'));
        self::assertSame('', $cache->upstreamQuery('utm_source=x'));
        self::assertSame('a=1&b=2', $cache->upstreamQuery('a=1&b=2'));
    }

    public function testQueryVariantsPerPathAreCappedAndEvictOldestFirst(): void
    {
        $cache = $this->cache($this->config(['maxQueryVariants' => 3]));
        $plain = $this->request('/q');
        $this->storeBody($cache, $plain, 'plain');
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->advance(1);
            self::assertTrue($this->storeBody($cache, $this->request('/q', 'x=' . $n), 'v' . $n));
        }
        self::assertSame(CacheState::Fresh, $cache->lookup($plain)->state, 'the bare path is never displaced');
        self::assertSame(CacheState::Miss, $cache->lookup($this->request('/q', 'x=1'))->state);
        self::assertSame(CacheState::Miss, $cache->lookup($this->request('/q', 'x=2'))->state);
        foreach ([3, 4, 5] as $n) {
            self::assertSame(CacheState::Fresh, $cache->lookup($this->request('/q', 'x=' . $n))->state);
        }
        self::assertCount(4, $this->entryFiles());
    }

    public function testStoreIsRefusedWhenTheIndexLockCannotBeTaken(): void
    {
        $config = $this->config();
        $cache = new \MirrorGate\Cache\HttpCache($config, new \MirrorGate\Support\SystemClock(), new \MirrorGate\Log\NullLogger(), 'inst1', 'fp1', 'pathkey');
        $req = $this->request('/locked');
        $key = $cache->keyFor($req, []);
        $this->storeBody($cache, $this->request('/warm'), 'warm'); // creates the cache directories
        $locks = glob($this->dir . '/*/locks') ?: glob($this->dir . '/locks') ?: [];
        self::assertNotSame([], $locks);
        $path = $locks[0] . '/ix' . substr($key->primaryHash, 0, 2) . '.lock';
        $handle = fopen($path, 'cb');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            $before = count($this->entryFiles());
            self::assertFalse($cache->store($key, 200, new HeaderBag([['Content-Type', 'text/html']]), 'body', $this->decision(), null, null, []));
            self::assertCount($before, $this->entryFiles(), 'the unindexed entry is removed again');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        self::assertTrue($cache->store($key, 200, new HeaderBag([['Content-Type', 'text/html']]), 'body', $this->decision(), null, null, []));
    }
}
