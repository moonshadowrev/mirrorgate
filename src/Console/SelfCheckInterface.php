<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Console;

interface SelfCheckInterface
{
    /**
     * @return list<CheckResult>
     */
    public function run(bool $network): array;
}
