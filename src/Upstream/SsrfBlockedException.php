<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

/**
 * Raised by OriginGuard when a host, port or resolved address is not allowed
 * as an upstream connect target.
 */
final class SsrfBlockedException extends \RuntimeException
{
}
