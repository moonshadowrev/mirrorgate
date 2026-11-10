<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Error;

use MirrorGate\Error\ErrorPage;
use MirrorGate\Error\ErrorRenderer;
use MirrorGate\Tests\Unit\Security\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorRendererTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = Fixtures::tempDir('err');
    }

    protected function tearDown(): void
    {
        Fixtures::removeTree($this->tmp);
    }

    private function renderer(string $lang = 'en', ?string $dir = null): ErrorRenderer
    {
        return new ErrorRenderer(Fixtures::config(['errorPageLang' => $lang, 'errorPageDir' => $dir])->app, dirname(__DIR__, 3) . '/templates');
    }

    public function testFromStatus(): void
    {
        self::assertSame(ErrorPage::NotFound, ErrorPage::fromStatus(404));
        self::assertSame(ErrorPage::PayloadTooLarge, ErrorPage::fromStatus(413));
        self::assertSame(ErrorPage::BadRequest, ErrorPage::fromStatus(417));
        self::assertSame(ErrorPage::BadRequest, ErrorPage::fromStatus(418));
        self::assertSame(ErrorPage::BadRequest, ErrorPage::fromStatus(415));
        self::assertSame(ErrorPage::InternalError, ErrorPage::fromStatus(599));
        self::assertSame(ErrorPage::InternalError, ErrorPage::fromStatus(505));
        self::assertSame(ErrorPage::BadGateway, ErrorPage::fromStatus(522));
        self::assertSame(ErrorPage::GatewayTimeout, ErrorPage::fromStatus(524));
        self::assertSame(ErrorPage::LoopDetected, ErrorPage::fromStatus(508));
        foreach (ErrorPage::cases() as $case) {
            self::assertSame($case, ErrorPage::fromStatus($case->value));
        }
    }

    /** @return iterable<string,array{0:ErrorPage}> */
    public static function pages(): iterable
    {
        foreach (ErrorPage::cases() as $page) {
            yield $page->name => [$page];
        }
    }

    #[DataProvider('pages')]
    public function testEveryPageRendersInBothLanguages(ErrorPage $page): void
    {
        foreach (['en', 'fa'] as $lang) {
            $r = $this->renderer($lang)->render($page, '0123456789abcdef');
            self::assertSame($page->value, $r->status);
            self::assertStringContainsString('<title>' . $page->value . ' ', $r->body);
            self::assertStringContainsString('0123456789abcdef', $r->body);
            self::assertStringNotContainsString('{{', $r->body, 'all placeholders substituted');
            self::assertStringNotContainsString('http://', $r->body);
            self::assertStringNotContainsString('https://', $r->body);
            self::assertStringNotContainsString('<script', $r->body);
        }
    }

    public function testHeaders(): void
    {
        $r = $this->renderer()->render(ErrorPage::ServiceUnavailable, 'abcdef0123456789', 7);
        self::assertSame('text/html; charset=utf-8', $r->headers->get('content-type'));
        self::assertSame('no-store', $r->headers->get('cache-control'));
        self::assertSame('nosniff', $r->headers->get('x-content-type-options'));
        self::assertSame('no-referrer', $r->headers->get('referrer-policy'));
        self::assertSame('DENY', $r->headers->get('x-frame-options'));
        self::assertSame("default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'", $r->headers->get('content-security-policy'));
        self::assertSame('abcdef0123456789', $r->headers->get('x-mirrorgate-request-id'));
        self::assertSame('7', $r->headers->get('retry-after'));
        self::assertNull($this->renderer()->render(ErrorPage::NotFound, 'abcdef0123456789')->headers->get('retry-after'));
    }

    public function testPersianPageIsRtlWithZwnj(): void
    {
        $r = $this->renderer('fa')->render(ErrorPage::NotFound, 'abcdef0123456789');
        self::assertStringContainsString('dir="rtl"', $r->body);
        self::assertStringContainsString('lang="fa"', $r->body);
        self::assertStringContainsString("صفحه\u{200C}ای", $r->body);
        self::assertStringContainsString('Vazirmatn', $r->body);
        $en = $this->renderer('en')->render(ErrorPage::NotFound, 'abcdef0123456789');
        self::assertStringContainsString('dir="ltr"', $en->body);
        self::assertStringContainsString('lang="en"', $en->body);
    }

    public function testRequestIdIsNeverReflectedUnescaped(): void
    {
        foreach (['<script>alert(1)</script>', "abc\r\nSet-Cookie: x=1", '{{title}}', str_repeat('a', 200), '', 'a"b'] as $evil) {
            $r = $this->renderer()->render(ErrorPage::BadGateway, $evil);
            self::assertStringNotContainsString('<script>alert', $r->body);
            self::assertStringNotContainsString('Set-Cookie', $r->body);
            self::assertSame('unknown', $r->headers->get('x-mirrorgate-request-id'));
            self::assertStringContainsString('<code dir="ltr">unknown</code>', $r->body);
        }
    }

    public function testHeadOnlyHasNoBody(): void
    {
        $r = $this->renderer()->render(ErrorPage::NotFound, 'abcdef0123456789', null, true);
        self::assertSame('', $r->body);
        self::assertSame(404, $r->status);
    }

    public function testCustomTemplateDirOverridesAndEscapes(): void
    {
        file_put_contents($this->tmp . '/503.html', '<p>{{status}}|{{title}}|{{message}}|{{request_id}}|{{lang}}|{{dir}}</p>');
        $r = $this->renderer('en', $this->tmp)->render(ErrorPage::ServiceUnavailable, 'abcdef0123456789');
        self::assertStringStartsWith('<p>503|Temporarily unavailable|', $r->body);
        // Other statuses still use the default template.
        self::assertStringContainsString('<!DOCTYPE html>', $this->renderer('en', $this->tmp)->render(ErrorPage::NotFound, 'abcdef0123456789')->body);
    }

    public function testMissingTemplateFallsBackToBuiltIn(): void
    {
        $renderer = new ErrorRenderer(Fixtures::config()->app, $this->tmp . '/missing');
        $r = $renderer->render(ErrorPage::InternalError, 'abcdef0123456789');
        self::assertStringContainsString('500', $r->body);
        self::assertStringContainsString('abcdef0123456789', $r->body);
        self::assertStringNotContainsString('{{', $r->body);
    }
}
