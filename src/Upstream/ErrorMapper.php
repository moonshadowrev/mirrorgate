<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

final class ErrorMapper
{
    public static function httpStatus(ErrorKind $k): int
    {
        return match ($k) {
            ErrorKind::ConnectTimeout, ErrorKind::Timeout => 504,
            ErrorKind::CircuitOpen => 503,
            ErrorKind::ClientAborted => 499,
            ErrorKind::RequestTooLarge => 413,
            ErrorKind::Dns,
            ErrorKind::Connect,
            ErrorKind::Tls,
            ErrorKind::Reset,
            ErrorKind::Partial,
            ErrorKind::TooManyRedirects,
            ErrorKind::Protocol,
            ErrorKind::SsrfBlocked,
            ErrorKind::ResponseTooLarge,
            ErrorKind::Unsupported,
            ErrorKind::Unknown => 502,
        };
    }

    /**
     * Retryable failures (idempotent methods, before the sink saw headers).
     */
    public static function isRetryable(ErrorKind $k): bool
    {
        return match ($k) {
            ErrorKind::Dns,
            ErrorKind::Connect,
            ErrorKind::ConnectTimeout,
            ErrorKind::Reset,
            ErrorKind::Tls => true,
            default => false,
        };
    }

    /**
     * Whether the failure counts against the circuit breaker. RequestTooLarge
     * and ClientAborted are neutral.
     */
    public static function countsForBreaker(ErrorKind $k): bool
    {
        return match ($k) {
            ErrorKind::Dns,
            ErrorKind::Connect,
            ErrorKind::ConnectTimeout,
            ErrorKind::Timeout,
            ErrorKind::Tls,
            ErrorKind::Reset,
            ErrorKind::Partial,
            ErrorKind::Protocol => true,
            default => false,
        };
    }
}
