<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Support\Upstream;

use MirrorGate\Upstream\DnsResolverInterface;

final class FakeDnsResolver implements DnsResolverInterface
{
    public int $calls = 0;

    public bool $fail = false;

    /**
     * @param array<string,list<string>> $answers host => addresses
     */
    public function __construct(public array $answers = [])
    {
    }

    public function resolve(string $host): array
    {
        ++$this->calls;
        if ($this->fail) {
            throw new \RuntimeException('resolver down');
        }

        return $this->answers[\strtolower($host)] ?? [];
    }
}
