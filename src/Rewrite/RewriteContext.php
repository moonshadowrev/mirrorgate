<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Per-response facts the rewriter needs. Nothing in here is ever copied into output except
 * where a rule says so explicitly (the normalized request path in the canonical Link header).
 */
final readonly class RewriteContext
{
    public function __construct(
        public string $requestPath,
        public string $originHostOfResponse,
        public int $status,
        public bool $isHead,
        public ?string $requestOrigin,
        /** Host name (no port) the client addressed, already validated by HostGuard; null when unknown. */
        public ?string $requestHost = null,
    ) {
    }
}
