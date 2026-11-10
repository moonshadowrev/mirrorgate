<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

interface DnsResolverInterface
{
    /**
     * Resolves a hostname to IP address strings (IPv4 first). An unresolvable
     * host yields an empty list. Implementations MAY throw \RuntimeException
     * on resolver failure; callers treat that like an empty answer.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
