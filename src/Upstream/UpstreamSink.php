<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

interface UpstreamSink
{
    /**
     * Called once, when the status line and headers of the FINAL (non-1xx)
     * response are complete and before any body byte is delivered.
     */
    public function onHeaders(UpstreamHead $head): SinkDecision;

    /**
     * Called for each body chunk when the decision was Stream. Return false to
     * abort the transfer (client gone or a limit was hit).
     */
    public function onChunk(string $chunk): bool;

    /**
     * Called about once per second from the cURL progress callback (also while
     * idle). Return false to abort. $now is microtime(true).
     */
    public function onTick(float $now): bool;
}
