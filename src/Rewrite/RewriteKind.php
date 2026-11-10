<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Body classes the rewriter knows how to handle. `None` means "pass through untouched".
 */
enum RewriteKind
{
    case None;
    case Html;
    case Css;
    case Json;
    case Xml;
    case Js;
    case Manifest;
    case Robots;
    case Sitemap;
    case Rsc;
    case EventStream;
}
