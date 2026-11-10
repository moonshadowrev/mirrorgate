<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

/**
 * Immutable set of CIDR ranges. Invalid entries are ignored, which can only ever shrink the
 * trusted or allowed set (configuration validation rejects them earlier).
 */
final class CidrList
{
    /** @var list<array{bin:string, prefix:int}> */
    private array $nets = [];

    /** @param list<string> $cidrs */
    public function __construct(array $cidrs)
    {
        foreach ($cidrs as $cidr) {
            try {
                $net = Cidr::parse($cidr);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $this->nets[] = ['bin' => $net['bin'], 'prefix' => $net['prefix']];
        }
    }

    public function contains(string $ip): bool
    {
        if ($this->nets === []) {
            return false;
        }
        $bin = Cidr::packIp($ip);
        if ($bin === null) {
            return false;
        }
        foreach ($this->nets as $net) {
            if (self::covers($bin, $net['bin'], $net['prefix'])) {
                return true;
            }
        }
        return false;
    }

    public function isEmpty(): bool
    {
        return $this->nets === [];
    }

    private static function covers(string $bin, string $net, int $prefix): bool
    {
        $full = intdiv($prefix, 8);
        if ($full > 0 && substr($bin, 0, $full) !== substr($net, 0, $full)) {
            return false;
        }
        $rest = $prefix % 8;
        if ($rest === 0) {
            return true;
        }
        $m = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($bin[$full]) & $m) === ord($net[$full]);
    }
}
