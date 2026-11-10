<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Support\Upstream;

use MirrorGate\Upstream\SinkDecision;
use MirrorGate\Upstream\UpstreamHead;
use MirrorGate\Upstream\UpstreamSink;

final class RecordingSink implements UpstreamSink
{
    public ?UpstreamHead $head = null;

    public string $streamed = '';

    public int $ticks = 0;

    public int $headerCalls = 0;

    public function __construct(
        private readonly SinkDecision $decision = SinkDecision::Buffer,
        private readonly bool $abortOnChunk = false,
    ) {
    }

    public function onHeaders(UpstreamHead $head): SinkDecision
    {
        ++$this->headerCalls;
        $this->head = $head;

        return $this->decision;
    }

    public function onChunk(string $chunk): bool
    {
        $this->streamed .= $chunk;

        return !$this->abortOnChunk;
    }

    public function onTick(float $now): bool
    {
        ++$this->ticks;

        return true;
    }
}
