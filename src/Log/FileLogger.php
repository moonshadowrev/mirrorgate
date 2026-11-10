<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Log;

use MirrorGate\Support\FileUtil;

/**
 * JSON-lines file logger. Never throws and never prints: an unwritable log file is silently ignored.
 */
final class FileLogger implements LoggerInterface
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    private const REDACT_PATTERN = '/token|secret|password|cookie|authorization|key/i';

    private readonly int $minRank;
    private bool $dirChecked = false;

    public function __construct(
        private readonly string $file,
        string $minLevel,
        private readonly int $maxBytes,
    ) {
        $this->minRank = self::LEVELS[strtolower($minLevel)] ?? self::LEVELS['warning'];
    }

    public function log(string $level, string $message, array $context = []): void
    {
        try {
            $level = strtolower($level);
            $rank = self::LEVELS[$level] ?? self::LEVELS['info'];
            if (!isset(self::LEVELS[$level])) {
                $level = 'info';
            }
            if ($rank < $this->minRank) {
                return;
            }
            $record = [
                'ts' => gmdate('Y-m-d\TH:i:s\Z'),
                'level' => $level,
                'msg' => $this->clean($message),
            ];
            foreach ($context as $k => $v) {
                $key = $this->clean((string) $k);
                if ($key === '' || isset($record[$key])) {
                    $key = 'ctx_' . $key;
                }
                if (preg_match(self::REDACT_PATTERN, $key) === 1) {
                    $record[$key] = '[redacted]';
                    continue;
                }
                $record[$key] = is_string($v) ? $this->clean($v) : $v;
            }
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($line === false) {
                return;
            }
            $this->write($line . "\n");
        } catch (\Throwable) {
            // Logging must never break a request.
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

    private function clean(string $s): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '';
        if (strlen($s) > 2048) {
            $s = substr($s, 0, 2048);
        }
        return $s;
    }

    private function write(string $line): void
    {
        if (!$this->dirChecked) {
            $this->dirChecked = true;
            $dir = dirname($this->file);
            if (!is_dir($dir)) {
                try {
                    FileUtil::ensureDir($dir);
                } catch (\RuntimeException) {
                    return;
                }
            }
        }
        $existed = file_exists($this->file);
        $h = @fopen($this->file, 'ab');
        if ($h === false) {
            return;
        }
        if (!$existed) {
            @chmod($this->file, FileUtil::fileMode());
        }
        if (!@flock($h, LOCK_EX)) {
            fclose($h);
            return;
        }
        $stat = fstat($h);
        if ($stat !== false && $this->maxBytes > 0 && $stat['size'] > $this->maxBytes) {
            @rename($this->file, $this->file . '.1');
            flock($h, LOCK_UN);
            fclose($h);
            $h = @fopen($this->file, 'ab');
            if ($h === false) {
                return;
            }
            @chmod($this->file, FileUtil::fileMode());
            if (!@flock($h, LOCK_EX)) {
                fclose($h);
                return;
            }
        }
        @fwrite($h, $line);
        @fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
    }
}
