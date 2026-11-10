<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Error;

use MirrorGate\Config\AppConfig;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\Response;
use MirrorGate\Security\SecurityHeaders;
use MirrorGate\Support\FileUtil;

/**
 * Renders friendly, self-contained error pages.
 *
 * Only a fixed status, a fixed message and the request id reach the page. No origin host name,
 * IP address, curl message, header or stack trace is ever passed in, and every substituted value
 * is HTML-escaped. Templates are static files with the placeholders {{lang}} {{dir}} {{status}}
 * {{title}} {{message}} {{request_id}}; substitution is a single pass, so a substituted value
 * can never introduce a further placeholder.
 */
final class ErrorRenderer
{
    private const MAX_TEMPLATE_BYTES = 65536;

    /** Used when no template file can be read, so an error page can always be produced. */
    private const FALLBACK_TEMPLATE = '<!DOCTYPE html><html lang="{{lang}}" dir="{{dir}}"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<title>{{status}} {{title}}</title><style>body{font-family:system-ui,sans-serif;margin:0;padding:48px 16px;'
        . 'text-align:center;line-height:1.6}code{font-family:monospace}</style></head><body><main><h1>{{status}} {{title}}</h1>'
        . '<p>{{message}}</p><p><small>Request ID: <code dir="ltr">{{request_id}}</code></small></p></main></body></html>';

    /** @var array<string,string|null> */
    private array $templates = [];

    public function __construct(private readonly AppConfig $app, private readonly string $templateDir)
    {
    }

    public function render(ErrorPage $page, string $requestId, ?int $retryAfter = null, bool $headOnly = false): Response
    {
        $fa = $this->app->errorPageLang === 'fa';
        [$title, $message] = $fa ? self::persianText($page) : self::englishText($page);
        $id = preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $requestId) === 1 ? $requestId : 'unknown';

        $replacements = [
            '{{lang}}' => self::escape($fa ? 'fa' : 'en'),
            '{{dir}}' => $fa ? 'rtl' : 'ltr',
            '{{status}}' => (string) $page->value,
            '{{title}}' => self::escape($title),
            '{{message}}' => self::escape($message),
            '{{request_id}}' => self::escape($id),
        ];
        $html = strtr($this->template($page, $fa), $replacements);

        $pairs = [['Content-Type', 'text/html; charset=utf-8']];
        foreach (SecurityHeaders::forGenerated(true) as $pair) {
            $pairs[] = $pair;
        }
        $pairs[] = ['X-MirrorGate-Request-Id', $id];
        if ($retryAfter !== null && $retryAfter >= 0) {
            $pairs[] = ['Retry-After', (string) min($retryAfter, 86400)];
        }
        return new Response($page->value, HeaderBag::fromPairs($pairs), $headOnly ? '' : $html);
    }

    private function template(ErrorPage $page, bool $fa): string
    {
        $dir = $this->app->errorPageDir;
        if ($dir !== null && $dir !== '') {
            $custom = $this->load(rtrim($dir, '/\\') . '/' . $page->value . '.html');
            if ($custom !== null) {
                return $custom;
            }
        }
        $default = $this->load(rtrim($this->templateDir, '/\\') . '/' . ($fa ? 'error.fa.html' : 'error.html'));
        return $default ?? self::FALLBACK_TEMPLATE;
    }

    private function load(string $path): ?string
    {
        if (!array_key_exists($path, $this->templates)) {
            $content = FileUtil::readFile($path, self::MAX_TEMPLATE_BYTES);
            $this->templates[$path] = ($content !== null && $content !== '') ? $content : null;
        }
        return $this->templates[$path];
    }

    private static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array{0:string,1:string} title and message */
    private static function englishText(ErrorPage $page): array
    {
        return match ($page) {
            ErrorPage::BadRequest => ['Bad request', 'The request could not be understood. Please check the address and try again.'],
            ErrorPage::Forbidden => ['Access denied', 'You do not have permission to open this page.'],
            ErrorPage::NotFound => ['Page not found', 'The page you are looking for does not exist or has been moved.'],
            ErrorPage::MethodNotAllowed => ['Method not allowed', 'This request method is not supported for this address.'],
            ErrorPage::PayloadTooLarge => ['Request too large', 'The data you sent is larger than the allowed size.'],
            ErrorPage::UriTooLong => ['Address too long', 'The requested address is longer than the allowed limit.'],
            ErrorPage::MisdirectedRequest => ['Misdirected request', 'This request cannot be served at this address.'],
            ErrorPage::TooManyRequests => ['Too many requests', 'You have sent too many requests in a short time. Please wait a moment and try again.'],
            ErrorPage::HeaderFieldsTooLarge => ['Request headers too large', 'The headers of your request are larger than the allowed size.'],
            ErrorPage::InternalError => ['Something went wrong', 'An unexpected error occurred. Please try again in a few minutes.'],
            ErrorPage::NotImplemented => ['Not supported', 'This type of request is not supported.'],
            ErrorPage::BadGateway => ['Bad gateway', 'The site could not be reached right now. Please try again in a few moments.'],
            ErrorPage::ServiceUnavailable => ['Temporarily unavailable', 'The service is temporarily unavailable or under maintenance. Please try again shortly.'],
            ErrorPage::GatewayTimeout => ['Gateway timeout', 'The site took too long to respond. Please try again.'],
            ErrorPage::LoopDetected => ['Loop detected', 'The request was stopped because it was routed in a loop.'],
        };
    }

    /**
     * Persian copy. "\u{200C}" is the zero width non-joiner (half space) that Persian orthography requires.
     *
     * @return array{0:string,1:string} title and message
     */
    private static function persianText(ErrorPage $page): array
    {
        $z = "\u{200C}";
        return match ($page) {
            ErrorPage::BadRequest => ['درخواست نامعتبر', 'درخواست شما قابل پردازش نیست. لطفاً نشانی را بررسی کنید و دوباره تلاش کنید.'],
            ErrorPage::Forbidden => ['دسترسی ممنوع', "شما اجازه{$z}ی دسترسی به این بخش را ندارید."],
            ErrorPage::NotFound => ['صفحه پیدا نشد', "صفحه{$z}ای که دنبال آن هستید وجود ندارد یا جابه{$z}جا شده است."],
            ErrorPage::MethodNotAllowed => ['روش درخواست مجاز نیست', "این روش برای این نشانی پشتیبانی نمی{$z}شود."],
            ErrorPage::PayloadTooLarge => ['حجم درخواست زیاد است', "حجم داده{$z}های ارسالی از حد مجاز بیشتر است."],
            ErrorPage::UriTooLong => ['نشانی بیش از حد طولانی است', "نشانی درخواستی از حد مجاز طولانی{$z}تر است."],
            ErrorPage::MisdirectedRequest => ['درخواست به مقصد نادرست رسیده است', "این درخواست برای این نشانی پذیرفته نمی{$z}شود."],
            ErrorPage::TooManyRequests => ["تعداد درخواست{$z}ها بیش از حد است", "تعداد درخواست{$z}های شما از حد مجاز گذشته است. لطفاً کمی صبر کنید و دوباره تلاش کنید."],
            ErrorPage::HeaderFieldsTooLarge => ['سرایند درخواست بیش از حد بزرگ است', "اندازه{$z}ی سرایندهای درخواست شما از حد مجاز بیشتر است."],
            ErrorPage::InternalError => ['خطای داخلی سرور', 'مشکلی پیش آمده است. لطفاً چند دقیقه دیگر دوباره تلاش کنید.'],
            ErrorPage::NotImplemented => ["درخواست پشتیبانی نمی{$z}شود", "سرور این نوع درخواست را پشتیبانی نمی{$z}کند."],
            ErrorPage::BadGateway => ['خطای دروازه', 'ارتباط با سرور اصلی برقرار نشد. لطفاً چند لحظه بعد دوباره تلاش کنید.'],
            ErrorPage::ServiceUnavailable => ['سرویس موقتاً در دسترس نیست', "سرویس در حال حاضر در دسترس نیست یا در حال به{$z}روزرسانی است. لطفاً کمی بعد دوباره تلاش کنید."],
            ErrorPage::GatewayTimeout => ["پاسخ سرور با تأخیر روبه{$z}رو شد", 'سرور اصلی در زمان مناسب پاسخ نداد. لطفاً دوباره تلاش کنید.'],
            ErrorPage::LoopDetected => ['حلقه در درخواست شناسایی شد', 'درخواست شما در یک حلقه افتاده بود و متوقف شد.'],
        };
    }
}
