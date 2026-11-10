<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Error;

enum ErrorPage: int
{
    case BadRequest = 400;
    case Forbidden = 403;
    case NotFound = 404;
    case MethodNotAllowed = 405;
    case PayloadTooLarge = 413;
    case UriTooLong = 414;
    case MisdirectedRequest = 421;
    case TooManyRequests = 429;
    case HeaderFieldsTooLarge = 431;
    case InternalError = 500;
    case NotImplemented = 501;
    case BadGateway = 502;
    case ServiceUnavailable = 503;
    case GatewayTimeout = 504;
    case LoopDetected = 508;

    /**
     * Maps any HTTP status to the page that should be shown.
     *
     * Exact statuses map to their case, 417 and every other 4xx map to BadRequest, 5xx statuses
     * without a page map to InternalError, except the common CDN style upstream failure codes
     * 520 to 526 which map to BadGateway (524, a timeout, to GatewayTimeout). Statuses below 400
     * never describe an error and map to InternalError. 499 is never rendered by the Kernel.
     */
    public static function fromStatus(int $status): self
    {
        $exact = self::tryFrom($status);
        if ($exact !== null) {
            return $exact;
        }
        if ($status >= 400 && $status < 500) {
            return self::BadRequest;
        }
        if ($status === 524) {
            return self::GatewayTimeout;
        }
        if ($status >= 520 && $status <= 526) {
            return self::BadGateway;
        }
        return self::InternalError;
    }
}
