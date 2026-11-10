<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Rewrite;

use MirrorGate\Rewrite\RewriteKind;
use PHPUnit\Framework\Attributes\DataProvider;

final class HostReplacerTest extends RewriteTestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function forms(): array
    {
        return [
            'https' => ['https://overlord.team/a', 'https://ormantek.com/a'],
            'http becomes mirror scheme' => ['http://overlord.team/a', 'https://ormantek.com/a'],
            'protocol relative' => ['//overlord.team/a', '//ormantek.com/a'],
            'json escaped slashes' => ['https:\/\/overlord.team\/a', 'https:\/\/ormantek.com\/a'],
            'double escaped slashes' => ['https:\\\\/\\\\/overlord.team', 'https:\\\\/\\\\/ormantek.com'],
            'percent encoded' => ['https%3A%2F%2Foverlord.team%2Fa', 'https%3A%2F%2Formantek.com%2Fa'],
            'percent encoded lowercase' => ['https%3a%2f%2fOVERLORD.team', 'https%3a%2f%2formantek.com'],
            'percent encoded relative scheme' => ['%2F%2Foverlord.team', '%2F%2Formantek.com'],
            'hex entity' => ['https:&#x2F;&#x2F;overlord.team/x', 'https:&#x2F;&#x2F;ormantek.com/x'],
            'decimal entity' => ['https:&#47;&#47;overlord.team/x', 'https:&#47;&#47;ormantek.com/x'],
            'unicode escape' => ['https://overlord.team', 'https://ormantek.com'],
            'uppercase host' => ['HTTPS://OverLord.Team/x', 'https://ormantek.com/x'],
            'origin port dropped' => ['https://overlord.team:443/x', 'https://ormantek.com/x'],
            'foreign port kept' => ['https://overlord.team:8080/x', 'https://overlord.team:8080/x'],
            'trailing dot fqdn' => ['https://overlord.team./x', 'https://ormantek.com./x'],
            'bare host in data' => ['"host":"overlord.team"', '"host":"ormantek.com"'],
            'lookalike prefix' => ['https://evil-overlord.team/', 'https://evil-overlord.team/'],
            'lookalike suffix' => ['https://overlord.team.evil.com/', 'https://overlord.team.evil.com/'],
            'lookalike bare prefix' => ['notoverlord.team', 'notoverlord.team'],
            'lookalike tld' => ['overlord.teams', 'overlord.teams'],
            'email address' => ['admin@overlord.team', 'admin@overlord.team'],
            'userinfo url' => ['https://user@overlord.team/', 'https://user@overlord.team/'],
            'unknown subdomain' => ['https://api.overlord.team/x', 'https://api.overlord.team/x'],
            'other scheme' => ['ftp://overlord.team/x', 'ftp://overlord.team/x'],
            'ws untouched without daemon' => ['wss://overlord.team/ws', 'wss://overlord.team/ws'],
            'ws untouched, escaped' => ['ws:\/\/overlord.team\/ws', 'ws:\/\/overlord.team\/ws'],
        ];
    }

    #[DataProvider('forms')]
    public function testForms(string $input, string $expected): void
    {
        $result = $this->rewriter()->rewriteBody($input, RewriteKind::Json, 'application/json', $this->context());
        self::assertSame($expected, $result->body);
    }

    public function testWebSocketUrlsUseDaemonBaseWhenConfigured(): void
    {
        $rewriter = $this->rewriter([], ['wsPublicUrl' => 'wss://ws.ormantek.com:8443']);
        $plain = $rewriter->rewriteBody('wss://overlord.team/x', RewriteKind::Json, 'application/json', $this->context());
        $escaped = $rewriter->rewriteBody('wss:\/\/overlord.team\/x', RewriteKind::Json, 'application/json', $this->context());
        self::assertSame('wss://ws.ormantek.com:8443/x', $plain->body);
        self::assertSame('wss:\/\/ws.ormantek.com:8443\/x', $escaped->body);
    }

    public function testHostMapSiblingAndPrefixHosts(): void
    {
        $rewriter = $this->rewriter([
            'hostMap' => ['api.overlord.team' => 'api.ormantek.com'],
            'extraOrigins' => ['cdn.overlord.team'],
            'subdomainMode' => 'prefix',
        ]);
        $result = $rewriter->rewriteBody(
            'https://api.overlord.team/a https://cdn.overlord.team/b cdn.overlord.team api.overlord.team',
            RewriteKind::Json,
            'application/json',
            $this->context(),
        );
        self::assertSame('https://api.ormantek.com/a https://ormantek.com/__mg/s/cdn.overlord.team/b cdn.overlord.team api.ormantek.com', $result->body);
    }

    public function testTextWithoutHostIsReturnedUnchanged(): void
    {
        $result = $this->rewriter()->rewriteBody('{"a":1}', RewriteKind::Json, 'application/json', $this->context());
        self::assertFalse($result->changed);
        self::assertFalse($result->skipped);
    }

    public function testRegexFailureReturnsOriginalBody(): void
    {
        $previousLimit = ini_get('pcre.backtrack_limit');
        $previousJit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $input = '{"a":"https://overlord.team/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}';
            $result = $this->rewriter()->rewriteBody($input, RewriteKind::Json, 'application/json', $this->context());
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
            ini_set('pcre.jit', (string) $previousJit);
        }
        self::assertTrue($result->skipped);
        self::assertSame($input, $result->body);
    }

    public function testLargeBodyIsRewrittenQuickly(): void
    {
        $rewriter = $this->rewriter([], [], ['maxBytes' => 64 * 1048576]);
        $body = str_repeat('{"u":"https:\/\/overlord.team\/a","pad":"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"},', 120000);
        $body = '[' . rtrim($body, ',') . ']';
        $started = microtime(true);
        $result = $rewriter->rewriteBody($body, RewriteKind::Json, 'application/json', $this->context());
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertTrue($result->changed);
        self::assertStringNotContainsString('overlord.team', $result->body);
    }
}
