<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

use MirrorGate\Log\LoggerInterface;

final class ResponseEmitter
{
    private bool $begun = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Emits a complete buffered response. With $headOnly the headers (including Content-Length when
     * the body is known) are sent and the body is withheld.
     */
    public function emit(Response $response, bool $headOnly = false): void
    {
        $status = $response->status;
        $bodyless = $status === 204 || $status === 304 || ($status >= 100 && $status < 200);
        $headers = $response->headers;
        $body = $response->body;

        if ($bodyless) {
            $headers = $headers->remove('Content-Length', 'Transfer-Encoding');
            $body = '';
        } elseif (!$headers->has('Content-Length')) {
            if (!$headOnly || $body !== '') {
                $headers = $headers->set('Content-Length', (string) strlen($body));
            }
        }

        $this->sendHead($status, $headers);
        $this->begun = true;
        if (!$headOnly && !$bodyless && $body !== '') {
            echo $body;
        }
        $this->flushOutput();
    }

    /**
     * Streaming API: sends status and headers exactly once.
     *
     * @throws \LogicException when a response was already begun
     */
    public function begin(int $status, HeaderBag $headers, bool $streaming): void
    {
        if ($this->begun) {
            throw new \LogicException('The response has already begun.');
        }
        $this->sendHead($status, $headers);
        $this->begun = true;
        if ($streaming) {
            $this->flushOutput();
        }
    }

    /**
     * Writes a body chunk and flushes. Returns false when the client connection is gone.
     */
    public function write(string $chunk): bool
    {
        if (connection_aborted() === 1) {
            return false;
        }
        if ($chunk !== '') {
            echo $chunk;
        }
        $this->flushOutput();
        return connection_aborted() !== 1;
    }

    public function finish(): void
    {
        $this->flushOutput();
    }

    public function hasBegun(): bool
    {
        return $this->begun;
    }

    /**
     * Prepares the process for a long-lived streaming response and returns the number of seconds the
     * request may still safely run (PHP_INT_MAX when no ceiling applies).
     */
    public function prepareStreaming(): int
    {
        $guard = 0;
        while (ob_get_level() > 0 && $guard++ < 32) {
            $before = ob_get_level();
            @ob_end_clean();
            if (ob_get_level() >= $before) {
                break;
            }
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
        }
        ob_implicit_flush(true);
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(false);
        }
        if (self::canSetTimeLimit()) {
            @set_time_limit(0);
        }
        $ceiling = self::runtimeCeiling();
        if ($ceiling >= PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_float($_SERVER['REQUEST_TIME_FLOAT'])
            ? $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);
        return max(0, (int) floor($ceiling - (microtime(true) - $start)));
    }

    /**
     * True when set_time_limit() exists and is not disabled.
     */
    public static function canSetTimeLimit(): bool
    {
        if (!function_exists('set_time_limit')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
        return !in_array('set_time_limit', $disabled, true);
    }

    /**
     * Seconds a request may run in total; PHP_INT_MAX means "no ceiling".
     */
    public static function runtimeCeiling(): int
    {
        $litespeed = PHP_SAPI === 'litespeed';
        if (self::canSetTimeLimit() && !$litespeed) {
            return PHP_INT_MAX;
        }
        $max = (int) ini_get('max_execution_time');
        $ceiling = max(5, ($max > 0 ? $max : 30) - 5);
        if ($litespeed) {
            $ceiling = min($ceiling, 25);
        }
        return $ceiling;
    }

    /**
     * Pure feature detection.
     */
    public static function canDetach(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /**
     * Flushes everything and detaches the client connection. Never throws.
     */
    public function detach(): bool
    {
        try {
            while (ob_get_level() > 0) {
                if (!@ob_end_flush()) {
                    break;
                }
            }
            $this->flushOutput();
            if (function_exists('fastcgi_finish_request')) {
                return (bool) fastcgi_finish_request();
            }
            if (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
                return true;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('detach_failed', ['exception' => get_class($e)]);
        }
        return false;
    }

    private function sendHead(int $status, HeaderBag $headers): void
    {
        if (headers_sent()) {
            $this->logger->warning('headers_already_sent');
            return;
        }
        header_remove();
        http_response_code($status);
        foreach ($headers->all() as [$name, $value]) {
            header($name . ': ' . $value, false);
        }
    }

    private function flushOutput(): void
    {
        if (function_exists('flush')) {
            flush();
        }
    }
}
