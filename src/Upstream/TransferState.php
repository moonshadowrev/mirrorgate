<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

/**
 * Mutable state shared by the cURL callbacks of ONE attempt. Callbacks never
 * throw: they record what went wrong here and return a failing value, and the
 * client classifies the outcome after the transfer returns (SEC-05).
 *
 * @internal
 */
final class TransferState
{
    public ?UpstreamHead $head = null;
    public ?SinkDecision $decision = null;

    public string $buffer = '';
    public int $bufferBytes = 0;
    public int $bytesReceived = 0;

    public int $uploaded = 0;
    public float $lastTick = 0.0;

    /** onHeaders() was invoked, so the attempt can no longer be retried. */
    public bool $headersDelivered = false;
    public bool $discarded = false;
    public bool $sinkAborted = false;
    public bool $responseTooLarge = false;
    public bool $requestTooLarge = false;
    public bool $uploadShort = false;

    /** Short machine reason when the header parser rejected the response. */
    public ?string $protocolFailure = null;

    /** Short machine reason for an unexpected exception inside a callback. */
    public ?string $internalFailure = null;

    public function __construct(public readonly ResponseHeaderParser $parser)
    {
    }
}
