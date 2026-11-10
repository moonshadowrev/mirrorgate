<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Rewrites hosts inside JSON documents (also manifests and `+json` types).
 *
 * The text is never decoded and re-encoded: the single-pass host replacer works on the raw bytes, so
 * escaped slashes (`https:\/\/host`), unicode escapes and key order stay exactly as the origin wrote them.
 * When the input was valid JSON, the output must be valid JSON as well; otherwise the original is kept.
 */
final class JsonRewriter
{
    public function __construct(private readonly HostReplacer $replacer)
    {
    }

    /**
     * @return array{0:string,1:?string} rewritten body and a skip reason (null when nothing went wrong)
     */
    public function rewrite(string $json): array
    {
        if (!$this->replacer->mightContain($json)) {
            return [$json, null];
        }
        $out = $this->replacer->replace($json);
        if ($out === $json) {
            return [$json, null];
        }
        if (function_exists('json_validate') && json_validate($json) && !json_validate($out)) {
            return [$json, 'json-invalid'];
        }
        return [$out, null];
    }
}
