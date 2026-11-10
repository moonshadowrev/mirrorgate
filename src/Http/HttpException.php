<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

/**
 * An error that maps to an HTTP status. Thrown by library code; only the Kernel and the entry
 * points turn it into a response.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param list<array{0:string,1:string}> $headers same shape as HeaderBag::fromPairs
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
