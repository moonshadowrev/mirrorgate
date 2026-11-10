<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Env;

use MirrorGate\Support\Str;

/**
 * Typed accessors over a map of environment values. Invalid values do not throw: the error is
 * recorded (as a machine code plus an English sentence that never contains the value) and the
 * default is returned, so that the caller can report every problem at once.
 */
final class EnvReader
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * @param array<string,string> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && trim($this->values[$key]) !== '';
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->values;
    }

    public function str(string $key, string $default = ''): string
    {
        return $this->has($key) ? trim($this->values[$key]) : $default;
    }

    public function opt(string $key): ?string
    {
        return $this->has($key) ? trim($this->values[$key]) : null;
    }

    public function bool(string $key, bool $default): bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        $v = strtolower(trim($this->values[$key]));
        if (in_array($v, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($v, ['false', '0', 'no', 'off'], true)) {
            return false;
        }
        $this->error($key, 'not_boolean', 'must be a boolean (true/false, 1/0, yes/no, on/off)');
        return $default;
    }

    public function int(string $key, int $default, int $min, int $max): int
    {
        if (!$this->has($key)) {
            return $default;
        }
        $v = trim($this->values[$key]);
        if (preg_match('/\A-?\d{1,15}\z/', $v) !== 1) {
            $this->error($key, 'not_integer', 'must be an integer');
            return $default;
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            $this->error($key, 'out_of_range', sprintf('must be between %d and %d', $min, $max));
            return $default;
        }
        return $n;
    }

    public function bytes(string $key, int $default, int $min, int $max): int
    {
        if (!$this->has($key)) {
            return $default;
        }
        try {
            $n = Str::parseBytes($this->values[$key]);
        } catch (\InvalidArgumentException) {
            $this->error($key, 'not_bytes', 'must be a size such as 512K, 10M or 1G');
            return $default;
        }
        if ($n < $min || $n > $max) {
            $this->error($key, 'out_of_range', sprintf('must be between %d and %d bytes', $min, $max));
            return $default;
        }
        return $n;
    }

    /**
     * @param list<string> $allowed
     */
    public function enum(string $key, string $default, array $allowed): string
    {
        if (!$this->has($key)) {
            return $default;
        }
        $v = strtolower(trim($this->values[$key]));
        if (!in_array($v, $allowed, true)) {
            $this->error($key, 'invalid_choice', 'must be one of: ' . implode(', ', $allowed));
            return $default;
        }
        return $v;
    }

    /**
     * Comma separated list; items trimmed, empty items dropped.
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        if (!$this->has($key)) {
            return [];
        }
        $out = [];
        foreach (explode(',', $this->values[$key]) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * File mode in octal notation, for example 0640.
     */
    public function mode(string $key, int $default): int
    {
        if (!$this->has($key)) {
            return $default;
        }
        $v = trim($this->values[$key]);
        if (preg_match('/\A0?[0-7]{3,4}\z/', $v) !== 1) {
            $this->error($key, 'not_octal_mode', 'must be an octal file mode such as 0640');
            return $default;
        }
        return (int) octdec($v);
    }

    public function error(string $key, string $reason, string $message): void
    {
        $this->errors[] = sprintf('%s:%s - %s %s.', $key, $reason, $key, $message);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
