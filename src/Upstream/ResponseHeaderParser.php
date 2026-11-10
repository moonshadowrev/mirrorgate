<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Http\HeaderBag;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Security\HeaderSanitizer;
use MirrorGate\Support\Str;

/**
 * Incremental parser for the raw header lines delivered by
 * CURLOPT_HEADERFUNCTION.
 *
 * - Every status line starts a fresh block, so 1xx interim responses (and the
 *   proxy CONNECT reply, should it ever be forwarded) are skipped.
 * - Header lines are validated before they reach a HeaderBag: a bad name or a
 *   value with CR, LF, NUL or another control character is dropped and logged
 *   by NAME only (SEC-15). An obs-fold continuation line fails the transfer.
 * - Hop-by-hop headers (fixed list plus every token named in Connection) are
 *   removed from the final head.
 * - Lines delivered after the final header block (trailers) are ignored.
 *
 * @internal
 */
final class ResponseHeaderParser
{
    public const MORE = 0;
    public const READY = 1;
    public const FAIL = 2;

    private const MAX_LINE_BYTES = 65536;
    private const MAX_TOTAL_BYTES = 262144;
    private const MAX_HEADERS = 200;

    private const HOP_BY_HOP = [
        'connection', 'keep-alive', 'proxy-connection', 'proxy-authenticate',
        'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade',
    ];

    private ?int $status = null;
    private string $version = '1.1';

    /** @var list<array{0:string,1:string}> */
    private array $pairs = [];

    private int $totalBytes = 0;
    private bool $done = false;
    private ?UpstreamHead $head = null;
    private ?string $failure = null;

    /**
     * @param bool $decodeContent the request asked libcurl to decode Content-Encoding
     * @param bool $noBody        the response can carry no body (HEAD request)
     */
    public function __construct(
        private readonly LoggerInterface $log,
        private readonly bool $decodeContent,
        private readonly bool $noBody,
    ) {
    }

    /**
     * Feeds one raw header line (including its line terminator).
     *
     * @return int self::MORE, self::READY (final head available) or self::FAIL
     */
    public function feed(string $line): int
    {
        if ($this->done) {
            return self::MORE;
        }
        if ($this->failure !== null) {
            return self::FAIL;
        }

        $length = \strlen($line);
        $this->totalBytes += $length;
        if ($length > self::MAX_LINE_BYTES || $this->totalBytes > self::MAX_TOTAL_BYTES) {
            return $this->fail('header_too_large');
        }

        if (\str_ends_with($line, "\r\n")) {
            $text = \substr($line, 0, -2);
        } elseif (\str_ends_with($line, "\n")) {
            $text = \substr($line, 0, -1);
        } else {
            $text = $line;
        }

        if ($text === '') {
            return $this->endOfBlock();
        }

        if (\str_starts_with($text, 'HTTP/')) {
            return $this->statusLine($text);
        }

        if ($this->status === null) {
            // Noise before any status line (cannot be a valid header block).
            return self::MORE;
        }

        if ($text[0] === ' ' || $text[0] === "\t") {
            return $this->fail('obs_fold');
        }

        return $this->headerLine($text);
    }

    public function head(): ?UpstreamHead
    {
        return $this->head;
    }

    /**
     * Short machine reason for a FAIL result; never reaches clients.
     */
    public function failure(): ?string
    {
        return $this->failure;
    }

    private function statusLine(string $text): int
    {
        if (\preg_match('#^HTTP/(\d(?:\.\d)?) (\d{3})(?: .*)?$#D', $text, $m) !== 1) {
            return $this->fail('status_line');
        }

        $status = (int) $m[2];
        if ($status < 100 || $status > 599) {
            return $this->fail('status_range');
        }
        if ($status === 101) {
            // MirrorGate never sends Upgrade; a switching-protocols reply is a protocol violation.
            return $this->fail('unexpected_101');
        }

        $this->status = $status;
        $this->version = match ($m[1]) {
            '1.0' => '1.0',
            '1.1' => '1.1',
            default => '2',
        };
        $this->pairs = [];

        return self::MORE;
    }

    private function headerLine(string $text): int
    {
        $colon = \strpos($text, ':');
        if ($colon === false || $colon === 0) {
            $this->log->warning('dropped malformed upstream header line');

            return self::MORE;
        }

        $name = \substr($text, 0, $colon);
        if (!Str::isHeaderToken($name)) {
            $this->log->warning('dropped upstream header with invalid name');

            return self::MORE;
        }

        $value = \trim(\substr($text, $colon + 1), " \t");
        if (Str::hasControlChars($value)) {
            $this->log->warning('dropped upstream header with control characters', ['header' => $name]);

            return self::MORE;
        }

        if (\count($this->pairs) >= self::MAX_HEADERS) {
            return $this->fail('too_many_headers');
        }

        $this->pairs[] = [$name, $value];

        return self::MORE;
    }

    private function endOfBlock(): int
    {
        $status = $this->status;
        if ($status === null) {
            return self::MORE;
        }

        if ($status < 200) {
            // Interim response (100 Continue, 103 Early Hints): discard and wait for the final one.
            $this->status = null;
            $this->pairs = [];

            return self::MORE;
        }

        $head = $this->buildHead($status);
        if ($head === null) {
            return self::FAIL;
        }

        $this->head = $head;
        $this->done = true;

        return self::READY;
    }

    private function buildHead(int $status): ?UpstreamHead
    {
        $bag = new HeaderBag();
        foreach ($this->pairs as [$name, $value]) {
            try {
                $bag = $bag->add($name, $value);
            } catch (\InvalidArgumentException) {
                $this->log->warning('dropped upstream header rejected by HeaderBag', ['header' => $name]);
            }
        }

        // Content-Length: all values must be identical digit strings.
        $encodedLength = null;
        $lengthValues = $bag->getAll('Content-Length');
        if ($lengthValues !== []) {
            $encodedLength = $this->parseContentLength($lengthValues);
            if ($encodedLength === false) {
                $this->fail('content_length');

                return null;
            }
        }

        $hasTransferEncoding = $bag->has('Transfer-Encoding');
        if ($hasTransferEncoding) {
            // Transfer-Encoding overrides Content-Length (RFC 9112 6.3).
            $encodedLength = null;
        }

        $encodings = \strtolower(\trim(\implode(', ', $bag->getAll('Content-Encoding'))));
        $isEncoded = $encodings !== '' && $encodings !== 'identity';
        $bodyless = $this->noBody || $status === 204 || $status === 304;
        $wasDecoded = $this->decodeContent && !$bodyless && $isEncoded;

        // Hop-by-hop removal: fixed list plus every token named in Connection.
        $strip = self::HOP_BY_HOP;
        foreach ($bag->getAll('Connection') as $connection) {
            foreach (\explode(',', $connection) as $token) {
                $token = \strtolower(\trim($token));
                if ($token !== '' && Str::isHeaderToken($token) && !\in_array($token, HeaderSanitizer::PROTECTED, true)) {
                    $strip[] = $token;
                }
            }
        }
        if ($wasDecoded) {
            $strip[] = 'content-encoding';
            $strip[] = 'content-length';
        }
        if ($hasTransferEncoding) {
            $strip[] = 'content-length';
        }
        $bag = $bag->withoutNames($strip);

        $contentLength = ($wasDecoded || $hasTransferEncoding || $status === 204 || $status === 304)
            ? null
            : $encodedLength;

        return new UpstreamHead(
            $status,
            $this->version,
            $bag,
            $contentLength,
            $wasDecoded,
            (!$wasDecoded && $isEncoded) ? $encodings : null,
            $encodedLength,
        );
    }

    /**
     * @param list<string> $values
     * @return int|false false when the header is invalid or ambiguous
     */
    private function parseContentLength(array $values): int|false
    {
        $found = null;
        foreach ($values as $value) {
            foreach (\explode(',', $value) as $part) {
                $part = \trim($part, " \t");
                if ($part === '' || \preg_match('/^\d{1,18}$/D', $part) !== 1) {
                    return false;
                }
                $n = (int) $part;
                if ($found !== null && $found !== $n) {
                    return false;
                }
                $found = $n;
            }
        }

        return $found ?? false;
    }

    private function fail(string $reason): int
    {
        $this->failure = $reason;

        return self::FAIL;
    }
}
