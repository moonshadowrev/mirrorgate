<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

final readonly class NormalizedTarget
{
    public function __construct(
        public string $path,          // normalized, always starts with "/", percent-encoding canonical (uppercase hex)
        public string $query,         // raw query without "?", control bytes already rejected, may be ""
    ) {
    }

    public function pathAndQuery(): string
    {
        return $this->path . ($this->query !== '' ? '?' . $this->query : '');
    }
}
