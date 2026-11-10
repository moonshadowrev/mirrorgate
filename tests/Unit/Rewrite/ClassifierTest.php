<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Rewrite;

use MirrorGate\Rewrite\RewriteKind;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClassifierTest extends RewriteTestCase
{
    /** @return array<string,array{0:?string,1:string,2:int,3:RewriteKind}> */
    public static function responses(): array
    {
        return [
            'html' => ['text/html; charset=utf-8', '/', 200, RewriteKind::Html],
            'xhtml' => ['application/xhtml+xml', '/', 200, RewriteKind::Html],
            'css' => ['text/css', '/a.css', 200, RewriteKind::Css],
            'json' => ['application/json', '/api', 200, RewriteKind::Json],
            'vendor json' => ['application/vnd.api+json', '/api', 200, RewriteKind::Json],
            'manifest' => ['application/manifest+json', '/m', 200, RewriteKind::Manifest],
            'rss' => ['application/rss+xml', '/feed', 200, RewriteKind::Xml],
            'sitemap' => ['application/xml', '/sitemap-1.xml', 200, RewriteKind::Sitemap],
            'svg passes' => ['image/svg+xml', '/a.svg', 200, RewriteKind::None],
            'robots' => ['text/plain', '/robots.txt', 200, RewriteKind::Robots],
            'other text' => ['text/plain', '/a.txt', 200, RewriteKind::None],
            'js disabled' => ['text/javascript', '/a.js', 200, RewriteKind::None],
            'rsc disabled' => ['text/x-component', '/a', 200, RewriteKind::None],
            'sse' => ['text/event-stream', '/s', 200, RewriteKind::EventStream],
            'not modified' => ['text/html', '/', 304, RewriteKind::None],
            'partial' => ['text/html', '/', 206, RewriteKind::None],
            'no content' => ['application/json', '/', 204, RewriteKind::None],
            'assetlinks' => ['application/json', '/.well-known/assetlinks.json', 200, RewriteKind::None],
            'next image' => ['text/html', '/_next/image', 200, RewriteKind::None],
            'source map' => ['application/json', '/a.js.map', 200, RewriteKind::None],
            'binary' => ['application/octet-stream', '/a.bin', 200, RewriteKind::None],
            'missing type' => [null, '/', 200, RewriteKind::None],
        ];
    }

    #[DataProvider('responses')]
    public function testClassify(?string $type, string $path, int $status, RewriteKind $expected): void
    {
        self::assertSame($expected, $this->rewriter()->classify($type, $path, $status));
    }

    public function testJavaScriptNeedsRewriteJsOrServiceWorkerRewrite(): void
    {
        self::assertSame(RewriteKind::Js, $this->rewriter([], [], ['js' => true])->classify('text/javascript', '/a.js', 200));
        $worker = $this->rewriter([], [], ['serviceWorkerMode' => 'rewrite']);
        self::assertSame(RewriteKind::Js, $worker->classify('application/javascript', '/sw.js', 200, true));
        self::assertSame(RewriteKind::None, $worker->classify('application/javascript', '/sw.js', 200, false));
    }

    public function testDisabledKindsAndMasterSwitch(): void
    {
        self::assertSame(RewriteKind::None, $this->rewriter([], [], ['html' => false])->classify('text/html', '/', 200));
        self::assertSame(RewriteKind::None, $this->rewriter([], [], ['enabled' => false])->classify('text/html', '/', 200));
        self::assertSame(RewriteKind::Rsc, $this->rewriter([], [], ['rsc' => true])->classify('text/x-component', '/a', 200));
    }

    public function testLikelyRewritable(): void
    {
        $rewriter = $this->rewriter();
        self::assertFalse($rewriter->likelyRewritable('/video.mp4', null));
        self::assertFalse($rewriter->likelyRewritable('/a.js', null));
        self::assertFalse($rewriter->likelyRewritable('/a.png', null));
        self::assertFalse($rewriter->likelyRewritable('/img/x', 'image/webp,image/*'));
        self::assertTrue($rewriter->likelyRewritable('/', 'text/html'));
        self::assertTrue($rewriter->likelyRewritable('/api/users', '*/*'));
        self::assertTrue($rewriter->likelyRewritable('/style.css', null));
        self::assertTrue($rewriter->likelyRewritable('/robots.txt', null));
        self::assertTrue($this->rewriter([], [], ['js' => true])->likelyRewritable('/a.js', null));
    }
}
