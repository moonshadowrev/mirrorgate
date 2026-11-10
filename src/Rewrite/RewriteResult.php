<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Outcome of Rewriter::rewriteBody().
 *
 * When `skipped` is true the body is the unmodified input and `reason` is a short machine
 * token such as `binary`, `charset`, `too-large`, `head`, `json-invalid` or `error`.
 */
final readonly class RewriteResult
{
    public function __construct(
        public string $body,
        public bool $changed,
        public bool $skipped,
        public ?string $reason,
    ) {
    }
}
