<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

enum RouteKind
{
    case Proxy;
    case Admin;
    case AcmeChallenge;
    case Blocked;
    case ServiceWorkerKill;
}
