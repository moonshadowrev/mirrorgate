<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Support;

interface Clock
{
    public function time(): int;

    public function microtime(): float;
}
