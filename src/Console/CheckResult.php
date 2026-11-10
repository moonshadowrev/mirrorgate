<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Console;

final readonly class CheckResult
{
    public function __construct(
        public string $name,
        public CheckLevel $level,
        public string $message,
    ) {
    }
}
