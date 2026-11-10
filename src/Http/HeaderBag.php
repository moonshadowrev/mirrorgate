<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

use MirrorGate\Support\Str;

/**
 * Ordered, duplicate-preserving, case-insensitive, immutable header collection. A HeaderBag can
 * never hold a name that is not an RFC 9110 token or a value containing CR, LF or NUL.
 */
final class HeaderBag implements \Countable
{
    /** @var list<array{0:string,1:string}> */
    private array $pairs = [];

    /**
     * @param list<array{0:string,1:string}> $pairs
     * @throws \InvalidArgumentException
     */
    public function __construct(array $pairs = [])
    {
        foreach ($pairs as $pair) {
            $name = (string) ($pair[0] ?? '');
            $value = (string) ($pair[1] ?? '');
            self::assertValid($name, $value);
            $this->pairs[] = [$name, $value];
        }
    }

    /**
     * @param list<array{0:string,1:string}> $pairs
     * @throws \InvalidArgumentException
     */
    public static function fromPairs(array $pairs): self
    {
        return new self($pairs);
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public function all(): array
    {
        return $this->pairs;
    }

    public function get(string $name): ?string
    {
        $lc = strtolower($name);
        foreach ($this->pairs as [$n, $v]) {
            if (strtolower($n) === $lc) {
                return $v;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    public function getAll(string $name): array
    {
        $lc = strtolower($name);
        $out = [];
        foreach ($this->pairs as [$n, $v]) {
            if (strtolower($n) === $lc) {
                $out[] = $v;
            }
        }
        return $out;
    }

    public function has(string $name): bool
    {
        $lc = strtolower($name);
        foreach ($this->pairs as [$n]) {
            if (strtolower($n) === $lc) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replaces all occurrences (the new value takes the position of the first occurrence).
     *
     * @throws \InvalidArgumentException
     */
    public function set(string $name, string $value): self
    {
        self::assertValid($name, $value);
        $lc = strtolower($name);
        $out = [];
        $placed = false;
        foreach ($this->pairs as $pair) {
            if (strtolower($pair[0]) === $lc) {
                if (!$placed) {
                    $out[] = [$name, $value];
                    $placed = true;
                }
                continue;
            }
            $out[] = $pair;
        }
        if (!$placed) {
            $out[] = [$name, $value];
        }
        return self::fromTrusted($out);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function add(string $name, string $value): self
    {
        self::assertValid($name, $value);
        $pairs = $this->pairs;
        $pairs[] = [$name, $value];
        return self::fromTrusted($pairs);
    }

    public function remove(string ...$names): self
    {
        return $this->withoutNames(array_values($names));
    }

    /**
     * @param list<string> $names
     */
    public function withoutNames(array $names): self
    {
        if ($names === []) {
            return $this;
        }
        $drop = [];
        foreach ($names as $n) {
            $drop[strtolower($n)] = true;
        }
        $out = [];
        foreach ($this->pairs as $pair) {
            if (!isset($drop[strtolower($pair[0])])) {
                $out[] = $pair;
            }
        }
        return self::fromTrusted($out);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $seen = [];
        foreach ($this->pairs as [$n]) {
            $seen[strtolower($n)] = true;
        }
        return array_map('strval', array_keys($seen));
    }

    public function count(): int
    {
        return count($this->pairs);
    }

    /**
     * @param list<array{0:string,1:string}> $pairs already validated
     */
    private static function fromTrusted(array $pairs): self
    {
        $bag = new self();
        $bag->pairs = $pairs;
        return $bag;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function assertValid(string $name, string $value): void
    {
        if (!Str::isHeaderToken($name)) {
            throw new \InvalidArgumentException('Invalid header name.');
        }
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new \InvalidArgumentException('Invalid header value.');
        }
    }
}
