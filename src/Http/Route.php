<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

final readonly class Route
{
    /**
     * @param ?string $originHost non-null only for RouteKind::Proxy
     * @param string $originPath path on the origin host; meaningful only for RouteKind::Proxy
     */
    public function __construct(
        public RouteKind $kind,
        public ?string $originHost,
        public string $originPath,
    ) {
    }
}
