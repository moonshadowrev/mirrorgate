<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

/**
 * Raised by OriginGuard when an allowed host cannot be resolved at all and no
 * fallback address is available. Distinct from SsrfBlockedException so the
 * client can report ErrorKind::Dns (retryable) instead of ErrorKind::SsrfBlocked.
 *
 * @internal
 */
final class DnsFailureException extends \RuntimeException
{
}
