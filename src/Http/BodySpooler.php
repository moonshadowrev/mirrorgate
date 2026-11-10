<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

use MirrorGate\Support\Str;

/**
 * Copies the request body from php://input into a counted temporary stream, so that the
 * exact length is known before any network I/O and a truncated body is never forwarded.
 */
final class BodySpooler
{
    public function __construct(private readonly int $maxBytes)
    {
    }

    /**
     * @throws HttpException 413 when the body exceeds the limit, 500 when it cannot be read or verified
     */
    public function spool(Request $request): Request
    {
        $carriesBody = ($request->contentLength !== null && $request->contentLength > 0)
            || $request->chunkedBody
            || in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if (!$carriesBody) {
            return $request;
        }

        $limit = $this->maxBytes;
        $postMax = self::postMaxSize();
        if ($postMax > 0) {
            $limit = min($limit, $postMax);
        }

        $declared = $request->contentLength;
        if ($declared !== null && $declared > $limit) {
            throw new HttpException(413, 'BODY:declared_too_large');
        }

        $in = @fopen('php://input', 'rb');
        if ($in === false) {
            throw new HttpException(500, 'BODY:input_unavailable');
        }
        if ($declared === null) {
            $stat = @fstat($in);
            if (is_array($stat) && isset($stat['size']) && $stat['size'] > $limit) {
                fclose($in);
                throw new HttpException(413, 'BODY:input_too_large');
            }
        }

        $out = @fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($out === false) {
            fclose($in);
            throw new HttpException(500, 'BODY:temp_unavailable');
        }

        $count = 0;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) {
                fclose($in);
                fclose($out);
                throw new HttpException(500, 'BODY:read_failed');
            }
            if ($chunk === '') {
                if (feof($in)) {
                    break;
                }
                continue;
            }
            $count += strlen($chunk);
            if ($count > $limit) {
                fclose($in);
                fclose($out);
                throw new HttpException(413, 'BODY:counted_too_large');
            }
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                fclose($in);
                fclose($out);
                throw new HttpException(500, 'BODY:write_failed');
            }
        }
        fclose($in);

        if ($declared !== null && $declared > 0 && $count !== $declared) {
            fclose($out);
            if ($postMax > 0 && $declared > $postMax) {
                throw new HttpException(413, 'BODY:post_max_size');
            }
            throw new HttpException(500, 'BODY:length_mismatch');
        }

        rewind($out);
        return $request->withSpooledBody($out, $count);
    }

    private static function postMaxSize(): int
    {
        $raw = ini_get('post_max_size');
        if ($raw === false || trim($raw) === '' || trim($raw) === '0') {
            return 0;
        }
        try {
            return Str::parseBytes($raw);
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }
}
