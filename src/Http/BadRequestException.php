<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

final class BadRequestException extends HttpException
{
    public function __construct(string $message = '', int $status = 400)
    {
        parent::__construct($status, $message);
    }
}
