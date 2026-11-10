<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

/**
 * Decision returned by an UpstreamSink once the response headers are known.
 *
 * Discard aborts the transfer: no body is wanted.
 */
enum SinkDecision
{
    case Buffer;
    case Stream;
    case Discard;
}
