<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

enum ListenerKind: string
{
    case Ws = 'ws';
    case Tcp = 'tcp';
    case Udp = 'udp';
    case Grpc = 'grpc';
}
