<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Config\OriginConfig;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Security\AddressFloor;
use MirrorGate\Security\IpClassifier;
use MirrorGate\Support\Hostname;

/**
 * SSRF guard for every upstream connection.
 *
 * - Only the origin host and EXTRA_ORIGINS hosts are allowed, only on ports in
 *   ORIGIN_PORTS_ALLOW.
 * - Every resolved A/AAAA answer must be a globally routable address
 *   (IpClassifier::isPublic) unless ALLOW_PRIVATE_ORIGIN is on; ONE validated
 *   address is then pinned through CURLOPT_RESOLVE so the connection cannot be
 *   rebound between check and use (SEC-03).
 * - Cloud metadata and link-local ranges stay blocked even with
 *   ALLOW_PRIVATE_ORIGIN.
 * - With OUTBOUND_PROXY the proxy is the SSRF boundary: no local DNS and no
 *   pinning; only the host and port allowlists are enforced (SEC-86).
 */
final class OriginGuard
{
    /** @var array<string,true> canonical allowed hosts */
    private array $allowedHosts = [];

    public function __construct(
        private readonly OriginConfig $origin,
        private readonly DnsResolverInterface $dns,
        private readonly LoggerInterface $log,
    ) {
        $this->allowedHosts[self::canonical($origin->host)] = true;
        foreach ($origin->extraOrigins as $extra) {
            $this->allowedHosts[self::canonical($extra)] = true;
        }
    }

    /**
     * @throws SsrfBlockedException when the host, port or any resolved address is not allowed
     * @throws DnsFailureException  when the host cannot be resolved and no fallback exists
     */
    public function resolve(string $host, int $port): ResolvedTarget
    {
        $canonical = $this->normalizeHost($host);

        if (!isset($this->allowedHosts[$canonical])) {
            throw new SsrfBlockedException('host_not_allowed');
        }
        if (!\in_array($port, $this->origin->allowedPorts, true)) {
            throw new SsrfBlockedException('port_not_allowed');
        }

        if ($this->origin->outboundProxy !== null && $this->origin->outboundProxy !== '') {
            return new ResolvedTarget($canonical, $port, '', '');
        }

        $isLiteral = Hostname::isIpLiteral($canonical);
        if ($isLiteral) {
            if (\filter_var($canonical, FILTER_VALIDATE_IP) === false) {
                // Decimal, hex, octal and other non-canonical spellings.
                throw new SsrfBlockedException('non_canonical_ip_literal');
            }
            $this->assertAddressAllowed($canonical);

            // A literal needs no pinning: there is no name to rebind.
            return new ResolvedTarget($canonical, $port, $canonical, '');
        }

        $candidates = $this->candidates($canonical);
        if ($candidates === []) {
            throw new DnsFailureException('dns_unresolved');
        }

        // ALL answers must pass: one private record blocks the host.
        foreach ($candidates as $ip) {
            $this->assertAddressAllowed($ip);
        }

        $ip = self::pick($candidates);

        return new ResolvedTarget($canonical, $port, $ip, $canonical . ':' . $port . ':' . $ip);
    }

    public function isAllowedHost(string $host): bool
    {
        try {
            return isset($this->allowedHosts[$this->normalizeHost($host)]);
        } catch (SsrfBlockedException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function candidates(string $host): array
    {
        $pin = $this->origin->resolveIp;
        $isPrimary = $host === self::canonical($this->origin->host);

        if ($pin !== null && $pin !== '' && $isPrimary) {
            return [self::canonicalIp($pin)];
        }

        $answers = [];
        try {
            $answers = $this->dns->resolve($host);
        } catch (\Throwable) {
            $this->log->warning('dns resolver threw');
        }

        $clean = [];
        foreach ($answers as $ip) {
            if (\filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new SsrfBlockedException('invalid_dns_answer');
            }
            $clean[] = $ip;
        }

        if ($clean === [] && $pin !== null && $pin !== '') {
            $this->log->info('dns unresolved, using ORIGIN_RESOLVE_IP fallback');

            return [self::canonicalIp($pin)];
        }

        return $clean;
    }

    private function assertAddressAllowed(string $ip): void
    {
        if (AddressFloor::isBlocked($ip)) {
            throw new SsrfBlockedException('address_blocked');
        }
        if ($this->origin->allowPrivate) {
            return;
        }
        if (!IpClassifier::isPublic($ip)) {
            throw new SsrfBlockedException('address_not_public');
        }
    }

    /**
     * @throws SsrfBlockedException
     */
    private function normalizeHost(string $host): string
    {
        if ($host === '' || \strlen($host) > 255) {
            throw new SsrfBlockedException('host_invalid');
        }

        // Controls, whitespace and DEL are never part of a host name.
        if (\preg_match('/[\x00-\x20\x7F]/', $host) === 1) {
            throw new SsrfBlockedException('host_invalid');
        }

        // Non-ASCII input is only acceptable as an IDN that converts to a
        // clean ASCII (punycode) name.
        if (\preg_match('/[\x80-\xFF]/', $host) === 1) {
            try {
                $host = Hostname::normalize($host);
            } catch (\InvalidArgumentException) {
                throw new SsrfBlockedException('host_invalid');
            }
        }

        $host = \strtolower($host);
        if (\str_ends_with($host, '.')) {
            $host = \substr($host, 0, -1);
        }
        if ($host !== '' && $host[0] === '[' && \str_ends_with($host, ']')) {
            $host = \substr($host, 1, -1);
        }

        // Rejects userinfo, backslashes, slashes, zone ids, percent escapes,
        // whitespace and anything else outside the hostname/IP alphabet.
        if ($host === '' || \preg_match('/^[a-z0-9._:-]+$/D', $host) !== 1) {
            throw new SsrfBlockedException('host_invalid');
        }

        return $host;
    }

    private static function canonical(string $host): string
    {
        $host = \strtolower($host);
        if (\str_ends_with($host, '.')) {
            $host = \substr($host, 0, -1);
        }
        if ($host !== '' && $host[0] === '[' && \str_ends_with($host, ']')) {
            $host = \substr($host, 1, -1);
        }

        return $host;
    }

    private static function canonicalIp(string $ip): string
    {
        $ip = \trim($ip, '[]');
        $bin = @\inet_pton($ip);
        if ($bin === false) {
            throw new SsrfBlockedException('invalid_resolve_ip');
        }
        $text = \inet_ntop($bin);

        return $text === false ? $ip : $text;
    }

    /**
     * Prefer IPv4; fall back to the first IPv6 address.
     *
     * @param list<string> $ips non-empty
     */
    private static function pick(array $ips): string
    {
        foreach ($ips as $ip) {
            if (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }
        }

        return $ips[0];
    }
}
