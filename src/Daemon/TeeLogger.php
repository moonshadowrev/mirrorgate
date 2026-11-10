<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Log\LoggerInterface;

/**
 * Forwards every record to an inner logger and, for foreground runs, mirrors it to standard error as one
 * JSON line so that systemd, supervisord and Docker capture it.
 */
final class TeeLogger implements LoggerInterface
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private readonly int $min;

    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly bool $toStderr,
        string $minLevel = 'info',
    ) {
        $this->min = self::LEVELS[$minLevel] ?? 1;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context);
        if (!$this->toStderr || (self::LEVELS[$level] ?? 1) < $this->min || !defined('STDERR')) {
            return;
        }
        $record = ['ts' => gmdate('c'), 'level' => $level, 'msg' => self::clean($message)];
        foreach ($context as $key => $value) {
            $record[self::clean((string) $key)] = preg_match('/token|secret|password|cookie|authorization|key/i', (string) $key) === 1
                ? '[redacted]'
                : (is_string($value) ? self::clean($value) : $value);
        }
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) {
            @fwrite(STDERR, $json . "\n");
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private static function clean(string $s): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $s);
    }
}
