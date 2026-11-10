<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

/**
 * Strict HTTP/1.1 header block parsing for the WebSocket opening handshake.
 *
 * Parse failures throw \InvalidArgumentException whose code is the HTTP status to answer with.
 */
final class WsHandshake
{
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const TOKEN = "/^[!#$%&'*+\\-.^_\x60|~0-9A-Za-z]+$/";

    /**
     * Applies the HTTP path policy to a WebSocket request target: canonical form (dot segments removed,
     * unreserved escapes decoded), BLOCKED_PATHS / AUTH_PASSTHROUGH_BLOCK and the reserved admin prefix.
     *
     * @param list<string> $blockedPatterns
     * @return array{0:int,1:string}|string the normalized target, or [status, reason] when it must be refused
     */
    public static function applyPathPolicy(string $target, array $blockedPatterns, string $adminPrefix): array|string
    {
        try {
            $normalized = \MirrorGate\Http\PathNormalizer::normalize($target);
        } catch (\MirrorGate\Http\BadRequestException) {
            return [400, 'bad_target'];
        }
        $path = $normalized->path;
        if ($path === $adminPrefix || str_starts_with($path, $adminPrefix . '/')) {
            return [404, 'path_blocked'];
        }
        if ((new \MirrorGate\Http\Router())->isBlocked($path, $blockedPatterns)) {
            return [404, 'path_blocked'];
        }

        return $normalized->pathAndQuery();
    }

    public static function acceptFor(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    public static function isValidKey(string $key): bool
    {
        if (preg_match('#^[A-Za-z0-9+/]{22}==$#', $key) !== 1) {
            return false;
        }
        $raw = base64_decode($key, true);
        return $raw !== false && strlen($raw) === 16;
    }

    /**
     * @param string $block header block without the terminating blank line
     * @return array{target:string,headers:array<string,list<string>>}
     * @throws \InvalidArgumentException
     */
    public static function parseRequest(string $block, int $maxHeaders = 100): array
    {
        $lines = self::splitLines($block);
        $first = array_shift($lines);
        if ($first === null || preg_match('#^GET (/[\x21-\x7e]*) HTTP/1\.1$#', $first, $m) !== 1) {
            throw new \InvalidArgumentException('bad request line', 400);
        }
        $target = $m[1];
        if (str_contains($target, '\\') || str_contains($target, '#')) {
            throw new \InvalidArgumentException('bad request target', 400);
        }
        return ['target' => $target, 'headers' => self::parseHeaders($lines, $maxHeaders)];
    }

    /**
     * @return array{status:int,headers:array<string,list<string>>}
     * @throws \InvalidArgumentException
     */
    public static function parseResponse(string $block, int $maxHeaders = 100): array
    {
        $lines = self::splitLines($block);
        $first = array_shift($lines);
        if ($first === null || preg_match('#^HTTP/1\.1 ([0-9]{3})(?: [\x20-\x7e]*)?$#', $first, $m) !== 1) {
            throw new \InvalidArgumentException('bad status line', 502);
        }
        return ['status' => (int) $m[1], 'headers' => self::parseHeaders($lines, $maxHeaders, 502)];
    }

    /**
     * Removes cookies whose name starts with "mg_" (MirrorGate-internal) from a Cookie header value.
     */
    public static function filterCookies(string $cookie): string
    {
        $kept = [];
        foreach (explode(';', $cookie) as $part) {
            $part = trim($part, " \t");
            if ($part === '') {
                continue;
            }
            $name = strtolower(trim(explode('=', $part, 2)[0]));
            if (str_starts_with($name, 'mg_')) {
                continue;
            }
            $kept[] = $part;
        }
        return implode('; ', $kept);
    }

    /**
     * Chooses the client address from a forwarding header sent by a trusted proxy.
     *
     * For X-Forwarded-For the rightmost entry that is not itself a trusted proxy wins, the same rule as
     * ClientIpResolver. Other headers carry a single address.
     *
     * @param list<string> $headerValues all occurrences of the configured header
     * @param callable(string):bool $isTrusted
     */
    public static function deriveClientIp(string $peerIp, array $headerValues, string $headerName, callable $isTrusted): string
    {
        if ($headerValues === []) {
            return $peerIp;
        }
        $items = [];
        foreach ($headerValues as $value) {
            foreach (explode(',', $value) as $item) {
                $items[] = trim($item);
            }
        }
        if (strtolower($headerName) !== 'x-forwarded-for') {
            $single = $items[0];
            return filter_var($single, FILTER_VALIDATE_IP) !== false ? $single : $peerIp;
        }
        for ($i = count($items) - 1; $i >= 0; $i--) {
            $candidate = $items[$i];
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $peerIp;
            }
            if (!$isTrusted($candidate)) {
                return $candidate;
            }
        }
        return $peerIp;
    }

    /**
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private static function splitLines(string $block): array
    {
        if ($block === '' || str_contains($block, "\0")) {
            throw new \InvalidArgumentException('bad header block', 400);
        }
        $lines = explode("\r\n", $block);
        foreach ($lines as $line) {
            if (str_contains($line, "\r") || str_contains($line, "\n")) {
                throw new \InvalidArgumentException('bare CR or LF', 400);
            }
        }
        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return array<string,list<string>>
     * @throws \InvalidArgumentException
     */
    private static function parseHeaders(array $lines, int $max, int $status = 400): array
    {
        if (count($lines) > $max) {
            throw new \InvalidArgumentException('too many headers', $status === 400 ? 431 : $status);
        }
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === ' ' || $line[0] === "\t") {
                throw new \InvalidArgumentException('empty line or obsolete line folding', $status);
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                throw new \InvalidArgumentException('header without name', $status);
            }
            $name = substr($line, 0, $colon);
            if (preg_match(self::TOKEN, $name) !== 1) {
                throw new \InvalidArgumentException('bad header name', $status);
            }
            $value = trim(substr($line, $colon + 1), " \t");
            if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1) {
                throw new \InvalidArgumentException('control character in header value', $status);
            }
            $headers[strtolower($name)][] = $value;
        }
        return $headers;
    }
}
