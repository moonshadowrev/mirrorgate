<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Rewrites full host literals in JavaScript. The code is never tokenized or parsed: only complete
 * host occurrences with safe boundaries are replaced, which keeps identifiers, regular expressions
 * and template syntax intact. Used for scripts (REWRITE_JS) and service workers (SERVICE_WORKER_MODE=rewrite).
 */
final class JsRewriter
{
    public function __construct(private readonly HostReplacer $replacer)
    {
    }

    public function rewrite(string $js): string
    {
        return $this->replacer->replace($js);
    }
}
