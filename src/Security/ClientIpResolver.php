<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Config\SecurityConfig;
use MirrorGate\Http\Request;

/**
 * Resolves the real client address. Forwarded headers are honoured only when the TCP peer
 * (REMOTE_ADDR) is a trusted proxy. The chain is walked right to left; trusted hops are skipped and
 * the first untrusted valid address wins. Any invalid entry stops the walk and falls back to REMOTE_ADDR.
 */
final class ClientIpResolver
{
    private const MAX_HOPS = 32;

    private readonly CidrList $trusted;

    public function __construct(private readonly SecurityConfig $config)
    {
        $this->trusted = new CidrList($config->trustedProxies);
    }

    public function resolve(Request $request): string
    {
        $remote = $request->remoteAddr;
        if ($this->trusted->isEmpty() || !$this->trusted->contains($remote)) {
            return $remote;
        }
        $header = strtolower($this->config->trustedProxyHeader);
        if ($header === 'x-forwarded-for') {
            return $this->walkChain($request, $header, $remote);
        }
        // cf-connecting-ip and x-real-ip carry exactly one address.
        $values = $request->headers->getAll($header);
        if (count($values) !== 1) {
            return $remote;
        }
        $ip = Cidr::normalizeIp(trim($values[0]));
        return $ip ?? $remote;
    }

    private function walkChain(Request $request, string $header, string $remote): string
    {
        $entries = [];
        foreach ($request->headers->getAll($header) as $line) {
            foreach (explode(',', $line) as $part) {
                $entries[] = trim($part);
                if (count($entries) > self::MAX_HOPS) {
                    return $remote;
                }
            }
        }
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $ip = Cidr::normalizeIp($entries[$i]);
            if ($ip === null) {
                return $remote;
            }
            if ($this->trusted->contains($ip)) {
                continue;
            }
            return $ip;
        }
        return $remote;
    }
}
