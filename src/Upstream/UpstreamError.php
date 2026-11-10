<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

/**
 * Failure description. internalMessage is for logs only and NEVER reaches
 * clients (it may contain origin internals).
 */
final readonly class UpstreamError
{
    public function __construct(
        public ErrorKind $kind,
        public int $curlErrno,
        public string $internalMessage,
    ) {
    }
}
