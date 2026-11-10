<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

/**
 * PROXY protocol version 1 (text) header parser.
 */
final class ProxyProtocol
{
    /** Longest legal v1 line including CRLF. */
    public const MAX_LENGTH = 107;

    /**
     * Parses one header line without its trailing CRLF.
     *
     * @return string|null the original client address, or null for "PROXY UNKNOWN" (use the TCP peer)
     * @throws \InvalidArgumentException when the line is not a valid v1 header
     */
    public static function parseV1(string $line): ?string
    {
        if (strlen($line) > self::MAX_LENGTH - 2 || preg_match('/^[\x20-\x7e]+$/', $line) !== 1) {
            throw new \InvalidArgumentException('malformed PROXY header');
        }
        if ($line === 'PROXY UNKNOWN' || str_starts_with($line, 'PROXY UNKNOWN ')) {
            return null;
        }
        $parts = explode(' ', $line);
        if (count($parts) !== 6 || $parts[0] !== 'PROXY') {
            throw new \InvalidArgumentException('malformed PROXY header');
        }
        [, $proto, $src, $dst, $sport, $dport] = $parts;
        $flag = $proto === 'TCP4' ? FILTER_FLAG_IPV4 : ($proto === 'TCP6' ? FILTER_FLAG_IPV6 : null);
        if ($flag === null
            || filter_var($src, FILTER_VALIDATE_IP, $flag) === false
            || filter_var($dst, FILTER_VALIDATE_IP, $flag) === false
            || preg_match('/^[0-9]{1,5}$/', $sport) !== 1
            || preg_match('/^[0-9]{1,5}$/', $dport) !== 1
            || (int) $sport > 65535
            || (int) $dport > 65535) {
            throw new \InvalidArgumentException('malformed PROXY header');
        }
        return $src;
    }
}
