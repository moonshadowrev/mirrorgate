<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Log\LoggerInterface;

/**
 * Resolver backed by the operating system. IPv4 answers are preferred: AAAA
 * records are queried only when no A record exists (SEC-87).
 *
 * The system resolver offers no per-call timeout. $timeoutSeconds bounds the
 * total budget across record types (no further lookup is started once it is
 * spent); the DNS cache and stale reuse in CachingDnsResolver are the real
 * protection against a resolver stall.
 */
final class SystemDnsResolver implements DnsResolverInterface
{
    private const MAX_ANSWERS = 16;

    public function __construct(
        private readonly LoggerInterface $log,
        private readonly int $timeoutSeconds = 3,
    ) {
    }

    public function resolve(string $host): array
    {
        $host = \strtolower(\rtrim($host, '.'));
        if ($host === '') {
            return [];
        }

        if (\filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $deadline = \microtime(true) + \max(1, $this->timeoutSeconds);

        if (!\function_exists('dns_get_record')) {
            return $this->viaGethostbyname($host);
        }

        // A trailing dot makes the name fully qualified so resolv.conf search
        // domains are never appended.
        $fqdn = $host . '.';

        $v4 = $this->lookup($fqdn, \DNS_A, 'ip');
        if ($v4 === null) {
            // Resolver error (not "no such record"): try the libc path once.
            $fallback = $this->viaGethostbyname($host);
            if ($fallback !== []) {
                return $fallback;
            }
            $this->log->debug('dns lookup failed', ['type' => 'A']);

            return [];
        }
        if ($v4 !== []) {
            return $v4;
        }

        if (\microtime(true) >= $deadline) {
            $this->log->debug('dns budget exhausted before AAAA lookup');

            return [];
        }

        $v6 = $this->lookup($fqdn, \DNS_AAAA, 'ipv6');

        return $v6 ?? [];
    }

    /**
     * @return list<string>|null null when the resolver itself failed
     */
    private function lookup(string $fqdn, int $type, string $field): ?array
    {
        \set_error_handler(static fn (): bool => true);
        try {
            $records = \dns_get_record($fqdn, $type);
        } finally {
            \restore_error_handler();
        }

        if ($records === false) {
            return null;
        }

        $out = [];
        foreach ($records as $record) {
            $ip = $record[$field] ?? null;
            if (!\is_string($ip) || \filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!\in_array($ip, $out, true)) {
                $out[] = $ip;
            }
            if (\count($out) >= self::MAX_ANSWERS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function viaGethostbyname(string $host): array
    {
        if (!\function_exists('gethostbynamel')) {
            return [];
        }

        \set_error_handler(static fn (): bool => true);
        try {
            $ips = \gethostbynamel($host);
        } finally {
            \restore_error_handler();
        }

        if ($ips === false) {
            return [];
        }

        $out = [];
        foreach ($ips as $ip) {
            if (\filter_var($ip, FILTER_VALIDATE_IP) !== false && !\in_array($ip, $out, true)) {
                $out[] = $ip;
            }
            if (\count($out) >= self::MAX_ANSWERS) {
                break;
            }
        }

        return $out;
    }
}
