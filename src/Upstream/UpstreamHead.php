<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

use MirrorGate\Http\HeaderBag;

final readonly class UpstreamHead
{
    public function __construct(
        public int $status,
        public string $httpVersion,
        public HeaderBag $headers,
        public ?int $contentLength,
        public bool $wasDecoded,
        public ?string $contentEncoding,
        public ?int $encodedContentLength = null,
    ) {
    }
}
