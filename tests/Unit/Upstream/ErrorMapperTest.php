<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Upstream;

use MirrorGate\Upstream\ErrorKind;
use MirrorGate\Upstream\ErrorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    /**
     * @return array<string,array{0:ErrorKind,1:int,2:bool,3:bool}>
     */
    public static function table(): array
    {
        return [
            'dns' => [ErrorKind::Dns, 502, true, true],
            'connect' => [ErrorKind::Connect, 502, true, true],
            'connect timeout' => [ErrorKind::ConnectTimeout, 504, true, true],
            'timeout' => [ErrorKind::Timeout, 504, false, true],
            'tls' => [ErrorKind::Tls, 502, true, true],
            'reset' => [ErrorKind::Reset, 502, true, true],
            'partial' => [ErrorKind::Partial, 502, false, true],
            'too many redirects' => [ErrorKind::TooManyRedirects, 502, false, false],
            'protocol' => [ErrorKind::Protocol, 502, false, true],
            'ssrf' => [ErrorKind::SsrfBlocked, 502, false, false],
            'circuit open' => [ErrorKind::CircuitOpen, 503, false, false],
            'response too large' => [ErrorKind::ResponseTooLarge, 502, false, false],
            'client aborted' => [ErrorKind::ClientAborted, 499, false, false],
            'unsupported' => [ErrorKind::Unsupported, 502, false, false],
            'request too large' => [ErrorKind::RequestTooLarge, 413, false, false],
            'unknown' => [ErrorKind::Unknown, 502, false, false],
        ];
    }

    #[DataProvider('table')]
    public function testMapping(ErrorKind $kind, int $status, bool $retryable, bool $breaker): void
    {
        self::assertSame($status, ErrorMapper::httpStatus($kind));
        self::assertSame($retryable, ErrorMapper::isRetryable($kind));
        self::assertSame($breaker, ErrorMapper::countsForBreaker($kind));
    }

    public function testEveryKindIsCovered(): void
    {
        self::assertCount(\count(ErrorKind::cases()), self::table());
    }
}
