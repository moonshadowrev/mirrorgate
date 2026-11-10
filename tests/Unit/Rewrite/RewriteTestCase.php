<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Rewrite;

use MirrorGate\Config\MirrorConfig;
use MirrorGate\Config\OriginConfig;
use MirrorGate\Config\RewriteConfig;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Log\NullLogger;
use MirrorGate\Rewrite\RewriteContext;
use MirrorGate\Rewrite\Rewriter;
use PHPUnit\Framework\TestCase;

/**
 * Shared factories for the rewriter tests. Defaults mirror the examples of the specification:
 * origin overlord.team, mirror overload.ir, everything on except JS/RSC/visible-text rewriting.
 */
abstract class RewriteTestCase extends TestCase
{
    /**
     * @param array<string,mixed> $origin overrides for OriginConfig constructor arguments
     * @param array<string,mixed> $mirror overrides for MirrorConfig constructor arguments
     * @param array<string,mixed> $rewrite overrides for RewriteConfig constructor arguments
     */
    protected function rewriter(array $origin = [], array $mirror = [], array $rewrite = []): Rewriter
    {
        $originConfig = new OriginConfig(...array_merge([
            'url' => 'https://overlord.team', 'scheme' => 'https', 'host' => 'overlord.team', 'port' => 443,
            'hostHeader' => 'overlord.team', 'allowHttp' => false, 'allowPrivate' => false, 'allowedPorts' => [443],
            'resolveIp' => null, 'outboundProxy' => null, 'caBundle' => null, 'hostMap' => [], 'extraOrigins' => [],
            'subdomainMode' => 'off', 'subdomainPrefix' => '/__mg/s',
        ], $origin));
        $mirrorConfig = new MirrorConfig(...array_merge([
            'url' => 'https://overload.ir', 'scheme' => 'https', 'host' => 'overload.ir', 'port' => 443,
            'hostHeader' => 'overload.ir', 'isHttps' => true, 'aliases' => [], 'allowedHosts' => ['overload.ir'],
            'wsPublicUrl' => null,
        ], $mirror));
        $rewriteConfig = new RewriteConfig(...array_merge([
            'enabled' => true, 'html' => true, 'css' => true, 'json' => true, 'xml' => true, 'js' => false,
            'rsc' => false, 'visibleText' => false, 'requestBody' => false, 'stripIntegrity' => true,
            'maxBytes' => 8388608, 'sitemapMaxBytes' => 52428800, 'seoMode' => 'mirror', 'cspMode' => 'rewrite',
            'serviceWorkerMode' => 'passthrough', 'hstsMode' => 'strip', 'hstsValue' => null, 'stripAltSvc' => true,
            'cookieDomainMode' => 'drop', 'cookieForceSecure' => true, 'redirectExternal' => 'pass', 'recompress' => true,
        ], $rewrite));

        return new Rewriter($rewriteConfig, $originConfig, $mirrorConfig, new NullLogger());
    }

    protected function context(string $originHost = 'overlord.team', string $path = '/', ?string $requestOrigin = null, bool $head = false): RewriteContext
    {
        return new RewriteContext($path, $originHost, 200, $head, $requestOrigin);
    }

    /**
     * @param list<array{0:string,1:string}> $pairs
     * @return list<array{0:string,1:string}>
     */
    protected function headers(Rewriter $rewriter, array $pairs, bool $bodyChanged = false, ?RewriteContext $ctx = null): array
    {
        return $rewriter->rewriteResponseHeaders(HeaderBag::fromPairs($pairs), 200, $bodyChanged, $ctx ?? $this->context())->all();
    }

    /**
     * @param list<array{0:string,1:string}> $headers
     * @return list<string>
     */
    protected function values(array $headers, string $name): array
    {
        $values = [];
        foreach ($headers as [$headerName, $value]) {
            if (strcasecmp($headerName, $name) === 0) {
                $values[] = $value;
            }
        }
        return $values;
    }
}
