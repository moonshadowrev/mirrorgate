<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Config\AdminConfig;
use MirrorGate\Http\HttpException;
use MirrorGate\Http\Request;
use MirrorGate\Support\Str;

/**
 * Authorises requests to the admin surface.
 *
 * Order of checks: disabled (404), plain HTTP without ADMIN_ALLOW_HTTP (404), IP allow list (404),
 * constant time token comparison. A valid token is always accepted: failure counters and the request
 * limiter never lock out the holder of the token, because an anonymous visitor (or every visitor
 * behind a shared proxy address) could otherwise trigger the lockout on purpose. Everything else, throttled
 * or not, answers the same generic 404 as an unknown path, so the response never reveals whether the
 * admin surface exists. There is never a sleep.
 */
final class AdminAuthenticator
{
    private const MAX_TOKEN_BYTES = 512;

    private readonly CidrList $ipAllow;
    private readonly CidrList $trustedProxies;
    /** Key derivation secret: the admin token itself (never stored), so raw IPs never reach the limiter. */
    private readonly string $salt;

    /** @param list<string> $trustedProxies CIDRs whose X-Forwarded-Proto may vouch for TLS */
    public function __construct(
        private readonly AdminConfig $config,
        private readonly RateLimiterInterface $limiter,
        array $trustedProxies = [],
    ) {
        $this->ipAllow = new CidrList($config->ipAllow);
        $this->trustedProxies = new CidrList($trustedProxies);
        $this->salt = hash('sha256', 'mirrorgate-admin|' . $config->token);
    }

    /** Returns null when authorised, else the HttpException (always a generic 404) the caller must throw. */
    public function authorise(Request $request, string $clientIp): ?HttpException
    {
        $cfg = $this->config;
        if (!$cfg->enabled || $cfg->token === '') {
            return self::notFound();
        }
        if (!$cfg->allowHttp && !$this->isSecure($request)) {
            return self::notFound();
        }
        if (!$this->ipAllow->isEmpty() && !$this->ipAllow->contains($clientIp)) {
            return self::notFound();
        }

        if ($this->tokenMatches($request)) {
            return null;
        }

        // Failed attempts are still counted so that the limiter state reflects abuse, but the outcome is the same 404.
        // A limiter fault is not allowed to turn into a different answer either.
        try {
            $this->limiter->hit(
                RateLimiterFactory::keyFor('admin-fail', $clientIp, $this->salt),
                max(1, $cfg->failLimit),
                max(1, $cfg->failWindow),
            );
        } catch (\Throwable) {
            // ignored: the answer below does not depend on the counter
        }

        return self::notFound();
    }

    /** True when the request reached this server over TLS, directly or as vouched for by a trusted proxy. */
    private function isSecure(Request $request): bool
    {
        if ($request->viaTls) {
            return true;
        }
        if ($this->trustedProxies->isEmpty() || !$this->trustedProxies->contains($request->remoteAddr)) {
            return false;
        }
        $proto = $request->headers->get('x-forwarded-proto');

        return $proto !== null && strtolower(trim(explode(',', $proto)[0])) === 'https';
    }

    private function tokenMatches(Request $request): bool
    {
        $presented = self::presentedToken($request);
        if ($presented === null || $presented === '' || strlen($presented) > self::MAX_TOKEN_BYTES) {
            return false;
        }
        return Str::constantTimeEquals($this->config->token, $presented);
    }

    /** Token from `Authorization: Bearer` or `X-MirrorGate-Token` only; never from the query string or a cookie. */
    private static function presentedToken(Request $request): ?string
    {
        $auth = $request->headers->get('authorization');
        if ($auth !== null && preg_match('/^Bearer[ \t]+([^\s]+)$/iD', trim($auth), $m) === 1) {
            return $m[1];
        }
        $custom = $request->headers->get('x-mirrorgate-token');
        if ($custom !== null) {
            $custom = trim($custom);
            return $custom === '' ? null : $custom;
        }
        return null;
    }

    private static function notFound(): HttpException
    {
        return new HttpException(404, 'Not found');
    }
}
