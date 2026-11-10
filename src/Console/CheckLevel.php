<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Console;

enum CheckLevel
{
    case Ok;
    case Warn;
    case Fail;
}
