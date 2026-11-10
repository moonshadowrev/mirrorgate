<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Rewrites hosts in XML documents: RSS and Atom feeds, sitemaps (`<loc>`, `image:loc`, `xhtml:link`),
 * CDATA sections and attribute values. The raw text is processed, so entities such as `&amp;` and
 * the document structure are preserved byte for byte.
 */
final class XmlRewriter
{
    public function __construct(private readonly HostReplacer $replacer)
    {
    }

    public function rewrite(string $xml): string
    {
        return $this->replacer->replace($xml);
    }
}
