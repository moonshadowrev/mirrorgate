<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Support;

final class SystemClock implements Clock
{
    public function time(): int
    {
        return time();
    }

    public function microtime(): float
    {
        return microtime(true);
    }
}
