<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

enum BreakerState
{
    case Closed;
    case Open;
    case HalfOpen;
}
