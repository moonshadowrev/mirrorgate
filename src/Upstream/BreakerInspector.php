<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

/**
 * Read-only view of the circuit breaker, used by the admin surface.
 */
interface BreakerInspector
{
    public function state(): BreakerState;

    /**
     * @return array{state:string,failures:int,openUntil:int,cooldown:int}
     */
    public function snapshot(): array;
}
