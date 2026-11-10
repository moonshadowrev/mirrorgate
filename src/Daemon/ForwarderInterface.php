<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

interface ForwarderInterface
{
    /** Binds the listener and registers it with the loop. Throws \RuntimeException when the bind fails. */
    public function start(EventLoop $loop): void;

    /** Closes the listener and every connection or session it owns. Idempotent. */
    public function stop(): void;

    /** Short human readable identifier used in logs, for example "ws@127.0.0.1:8090". */
    public function name(): string;
}
