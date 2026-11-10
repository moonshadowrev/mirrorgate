<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Support\Clock;

/** Controllable clock for the security tests. */
final class FixedClock implements Clock
{
    public function __construct(public int $now = 1_700_000_000)
    {
    }

    public function time(): int
    {
        return $this->now;
    }

    public function microtime(): float
    {
        return (float) $this->now;
    }
}
