<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Http\HeaderBag;

final readonly class UpstreamRequest
{
    /**
     * @param resource|null $bodyStream
     */
    public function __construct(
        public string $method,
        public UpstreamTarget $target,
        public HeaderBag $headers,
        public mixed $bodyStream,
        public ?int $bodyLength,
        public bool $decodeContent,
        public int $maxBufferBytes,
        public ?int $connectTimeout = null,
        public ?int $timeout = null,
        public bool $isStreamingHint = false,
    ) {
    }
}
