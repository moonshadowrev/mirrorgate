<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Support;

final class Hostname
{
    /**
     * Lowercases, strips one trailing dot and converts IDNs to their ASCII form.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $host): string
    {
        $host = trim($host);
        if ($host === '' || preg_match('/[\x00-\x20\x7F\\\\\/@]/', $host) === 1) {
            throw new \InvalidArgumentException('Invalid host name.');
        }
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        if ($host === '') {
            throw new \InvalidArgumentException('Invalid host name.');
        }

        if (preg_match('/\A[\x00-\x7F]+\z/', $host) === 1) {
            $ascii = strtolower($host);
        } else {
            $ascii = null;
            if (function_exists('idn_to_ascii') && defined('INTL_IDNA_VARIANT_UTS46') && defined('IDNA_NONTRANSITIONAL_TO_ASCII')) {
                $converted = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                if (is_string($converted) && $converted !== '') {
                    $ascii = strtolower($converted);
                } else {
                    throw new \InvalidArgumentException('Invalid internationalised host name.');
                }
            }
            if ($ascii === null) {
                $ascii = Punycode::toAscii($host);
            }
        }

        if (!self::isValid($ascii) && !self::isIpLiteral($ascii)) {
            throw new \InvalidArgumentException('Invalid host name.');
        }
        return $ascii;
    }

    public static function isValid(string $host): bool
    {
        $len = strlen($host);
        if ($len === 0 || $len > 253) {
            return false;
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            $l = strlen($label);
            if ($l < 1 || $l > 63) {
                return false;
            }
            if (preg_match('/\A[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?\z/', $label) !== 1) {
                return false;
            }
        }
        $tld = $labels[count($labels) - 1];
        if (ctype_digit($tld)) {
            return false;
        }
        return true;
    }

    /**
     * True for IPv4/IPv6 literals and also for the obscure numeric forms (decimal, hex, octal,
     * short dotted forms) that some resolvers accept, so that callers can reject them.
     */
    public static function isIpLiteral(string $host): bool
    {
        $h = $host;
        if (str_starts_with($h, '[') && str_ends_with($h, ']')) {
            $h = substr($h, 1, -1);
        }
        if ($h === '') {
            return false;
        }
        if (filter_var($h, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (str_contains($h, ':')) {
            // Anything with a colon that is not a valid IPv6 literal is not a hostname either;
            // treat it as an (invalid) literal so it is rejected rather than resolved.
            return preg_match('/\A[0-9A-Fa-f:.%]+\z/', $h) === 1;
        }
        return preg_match('/\A(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+)){0,3}\z/i', rtrim($h, '.')) === 1;
    }

    public static function isSameOrSubdomain(string $host, string $parent): bool
    {
        $host = strtolower($host);
        $parent = strtolower($parent);
        if ($parent === '') {
            return false;
        }
        return $host === $parent || str_ends_with($host, '.' . $parent);
    }

    /**
     * @return array{host:string, port:?int}
     * @throws \InvalidArgumentException
     */
    public static function split(string $hostPort): array
    {
        if ($hostPort === '' || preg_match('/[\x00-\x20\x7F\\\\@\/?#]/', $hostPort) === 1) {
            throw new \InvalidArgumentException('Invalid host and port.');
        }
        $port = null;
        if ($hostPort[0] === '[') {
            $end = strpos($hostPort, ']');
            if ($end === false) {
                throw new \InvalidArgumentException('Invalid host and port.');
            }
            $host = substr($hostPort, 1, $end - 1);
            $rest = substr($hostPort, $end + 1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Invalid IPv6 literal.');
            }
            if ($rest !== '') {
                if ($rest[0] !== ':') {
                    throw new \InvalidArgumentException('Invalid host and port.');
                }
                $port = self::parsePort(substr($rest, 1));
            }
            return ['host' => strtolower($host), 'port' => $port];
        }
        $colons = substr_count($hostPort, ':');
        if ($colons > 1) {
            throw new \InvalidArgumentException('Unbracketed IPv6 literal.');
        }
        if ($colons === 1) {
            [$host, $p] = explode(':', $hostPort, 2);
            $port = self::parsePort($p);
        } else {
            $host = $hostPort;
        }
        if ($host === '') {
            throw new \InvalidArgumentException('Empty host.');
        }
        return ['host' => strtolower($host), 'port' => $port];
    }

    private static function parsePort(string $p): int
    {
        if ($p === '' || strlen($p) > 5 || !ctype_digit($p)) {
            throw new \InvalidArgumentException('Invalid port.');
        }
        $n = (int) $p;
        if ($n < 1 || $n > 65535) {
            throw new \InvalidArgumentException('Invalid port.');
        }
        return $n;
    }
}
