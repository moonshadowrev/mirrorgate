<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

/**
 * Classification of an upstream failure. Drives HTTP status mapping, retry
 * eligibility and circuit breaker accounting (see ErrorMapper).
 */
enum ErrorKind
{
    case Dns;
    case Connect;
    case ConnectTimeout;
    case Timeout;
    case Tls;
    case Reset;
    case Partial;
    case TooManyRedirects;
    case Protocol;
    case SsrfBlocked;
    case CircuitOpen;
    case ResponseTooLarge;
    case ClientAborted;
    case Unsupported;
    case RequestTooLarge;
    case Unknown;
}
