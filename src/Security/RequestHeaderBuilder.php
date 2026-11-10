<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Config\Config;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\HttpException;
use MirrorGate\Http\RequestContext;
use MirrorGate\Version;

/**
 * Builds the origin-bound request header set. The result never contains Host,
 * Content-Length, Transfer-Encoding or Expect: the upstream client sets those itself from
 * configuration and the exact spooled body length.
 */
final class RequestHeaderBuilder
{
    /** Exact names removed from the client set (lowercase). */
    private const DROP = [
        'host' => true, 'content-length' => true, 'transfer-encoding' => true, 'expect' => true,
        'accept-encoding' => true, 'proxy' => true, 'forwarded' => true, 'x-real-ip' => true,
        'x-client-ip' => true, 'true-client-ip' => true, 'x-cluster-client-ip' => true, 'client-ip' => true,
        'x-host' => true, 'x-http-method-override' => true, 'x-http-method' => true,
        'x-method-override' => true, 'x-sendfile-type' => true, 'x-matched-path' => true,
        'via' => true, 'http2-settings' => true,
        'x-originating-ip' => true, 'x-remote-ip' => true, 'x-remote-addr' => true, 'fastly-client-ip' => true,
        'x-azure-clientip' => true, 'x-azure-socketip' => true, 'x-proxyuser-ip' => true, 'forwarded-for' => true,
        'front-end-https' => true, 'x-url-scheme' => true, 'x-arr-ssl' => true, 'x-custom-ip-authorization' => true,
        'remote-user' => true, 'x-remote-user' => true, 'x-authenticated-user' => true, 'x-forwarded' => true,
    ];

    /** Prefixes removed from the client set (lowercase). */
    private const DROP_PREFIXES = [
        'x-forwarded-', 'x-original-', 'x-rewrite-', 'x-real-', 'x-middleware-', 'x-invoke-', 'cf-', 'x-mirrorgate-',
        'x-auth-request-', 'x-amzn-', 'x-envoy-', 'x-appengine-', 'x-azure-', 'x-remote-', 'x-proxyuser-', 'x-originating-',
    ];

    /** Validators and range headers, only forwarded for passthrough requests. */
    private const CONDITIONAL = [
        'range' => true, 'if-range' => true, 'if-match' => true, 'if-none-match' => true,
        'if-modified-since' => true, 'if-unmodified-since' => true,
    ];

    private const HOP_ENTRY_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/D';
    private const MAX_HOP_ENTRIES = 8;
    private const PASSTHROUGH_ENCODING_PATTERN = '/^[A-Za-z0-9 ,;=.*-]{1,100}$/D';

    /** @var array<string,true> */
    private readonly array $varyExtra;

    /** @var list<string> operator REQUEST_HEADER_STRIP entries, lowercase; a trailing * marks a prefix */
    private readonly array $operatorStrip;

    public function __construct(private readonly Config $config)
    {
        $set = [];
        foreach ($config->cache->varyExtra as $name) {
            $set[strtolower($name)] = true;
        }
        $this->varyExtra = $set;
        $this->operatorStrip = $config->upstream->requestHeaderStrip;
    }

    /**
     * @throws HttpException 508 when the request already passed through this instance or the hop chain is too long
     */
    public function build(
        RequestContext $ctx,
        bool $conditionalPassthrough,
        ?string $acceptEncodingForPassthrough,
        bool $decodeContent,
        bool $cacheEligible,
    ): HeaderBag {
        $hop = $this->hopValue($ctx->request->headers);
        $client = HeaderSanitizer::removeHopByHop($ctx->request->headers);

        $pairs = $cacheEligible
            ? $this->anonymousPairs($client)
            : $this->passthroughPairs($client, $conditionalPassthrough);

        $upstream = $this->config->upstream;
        $mirror = $this->config->mirror;

        if (!$decodeContent && $acceptEncodingForPassthrough !== null
            && preg_match(self::PASSTHROUGH_ENCODING_PATTERN, $acceptEncodingForPassthrough) === 1) {
            $pairs[] = ['Accept-Encoding', $acceptEncodingForPassthrough];
        }
        // Cache fills are shared across clients, so they never carry a per-client address.
        if (!$cacheEligible && $upstream->forwardClientIp && Cidr::packIp($ctx->clientIp) !== null) {
            $pairs[] = ['X-Forwarded-For', $ctx->clientIp];
        }
        $pairs[] = ['X-Forwarded-Proto', $mirror->scheme];
        $pairs[] = ['X-Forwarded-Host', $mirror->hostHeader];
        $pairs[] = ['X-Forwarded-Port', (string) $mirror->port];
        $pairs[] = ['X-MirrorGate-Hop', $hop];
        if ($this->config->app->addVia) {
            $pairs[] = ['Via', '1.1 mirrorgate'];
        }
        return HeaderBag::fromPairs($pairs);
    }

    /**
     * Normal (non cache-fill) request: start from the client headers and remove the unsafe ones.
     *
     * @return list<array{0:string,1:string}>
     */
    private function passthroughPairs(HeaderBag $client, bool $conditionalPassthrough): array
    {
        $upstream = $this->config->upstream;
        $pairs = [];
        foreach ($client->all() as [$name, $value]) {
            // Backends commonly map "_" to "-", so both spellings are matched against the denylist.
            $lower = strtr(strtolower($name), '_', '-');
            if ($lower === 'cookie') {
                continue; // rebuilt below
            }
            if (isset(self::DROP[$lower]) || self::hasDroppedPrefix($lower) || $this->operatorStripped($lower)) {
                continue;
            }
            if (!$conditionalPassthrough && isset(self::CONDITIONAL[$lower])) {
                continue;
            }
            if ($lower === 'authorization' && !$upstream->forwardAuthorization) {
                continue;
            }
            $pairs[] = [$name, $value];
        }
        if ($upstream->forwardCookies) {
            $cookie = $this->filteredCookie($client);
            if ($cookie !== null) {
                $pairs[] = ['Cookie', $cookie];
            }
        }
        return $pairs;
    }

    /**
     * Cache-fill request: anonymous and allow-listed, so nothing keyed by the client can poison a shared entry.
     *
     * @return list<array{0:string,1:string}>
     */
    private function anonymousPairs(HeaderBag $client): array
    {
        $pairs = [];
        $accept = $client->get('accept');
        $pairs[] = ['Accept', isset($this->varyExtra['accept']) && $accept !== null && $accept !== '' ? $accept : '*/*'];

        $language = $client->get('accept-language');
        if (isset($this->varyExtra['accept-language']) && $language !== null && $language !== '') {
            $pairs[] = ['Accept-Language', $language];
        }
        $agent = $client->get('user-agent');
        $pairs[] = ['User-Agent', isset($this->varyExtra['user-agent']) && $agent !== null && $agent !== ''
            ? $agent
            : 'Mozilla/5.0 (compatible; MirrorGate/' . Version::VERSION . ')'];

        // Origin, Content-Type and Service-Worker are deliberately not forwarded: they are not part of the
        // cache key, so the stored response must not depend on them.
        return $pairs;
    }

    private function operatorStripped(string $lower): bool
    {
        foreach ($this->operatorStrip as $entry) {
            if (str_ends_with($entry, '*') ? str_starts_with($lower, substr($entry, 0, -1)) : $lower === $entry) {
                return true;
            }
        }
        return false;
    }

    private static function hasDroppedPrefix(string $lower): bool
    {
        foreach (self::DROP_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** Merges all Cookie lines and removes mirror-reserved cookies (mg_*, __Host-mg_*, __Secure-mg_*). */
    private function filteredCookie(HeaderBag $client): ?string
    {
        $kept = [];
        foreach ($client->getAll('cookie') as $line) {
            foreach (explode(';', $line) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $eq = strpos($part, '=');
                $name = strtolower(trim($eq === false ? $part : substr($part, 0, $eq)));
                if (str_starts_with($name, 'mg_') || str_starts_with($name, '__host-mg_') || str_starts_with($name, '__secure-mg_')) {
                    continue;
                }
                $kept[] = $part;
            }
        }
        return $kept === [] ? null : implode('; ', $kept);
    }

    /**
     * Existing hop chain plus this instance id.
     *
     * @throws HttpException
     */
    private function hopValue(HeaderBag $headers): string
    {
        $own = $this->config->app->instanceId;
        $entries = [];
        foreach ($headers->getAll('x-mirrorgate-hop') as $line) {
            foreach (explode(',', $line) as $part) {
                $part = trim($part);
                if ($part === '' || preg_match(self::HOP_ENTRY_PATTERN, $part) !== 1) {
                    continue;
                }
                if (strcasecmp($part, $own) === 0) {
                    throw new HttpException(508, 'Loop detected');
                }
                $entries[] = $part;
            }
        }
        if (count($entries) >= self::MAX_HOP_ENTRIES) {
            throw new HttpException(508, 'Loop detected');
        }
        $entries[] = $own;
        return implode(', ', $entries);
    }
}
