<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Security;

use MirrorGate\Security\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testFullSet(): void
    {
        $map = [];
        foreach (SecurityHeaders::forGenerated(true) as [$name, $value]) {
            $map[$name] = $value;
        }
        self::assertSame('nosniff', $map['X-Content-Type-Options']);
        self::assertSame('no-referrer', $map['Referrer-Policy']);
        self::assertSame('DENY', $map['X-Frame-Options']);
        self::assertSame('no-store', $map['Cache-Control']);
        self::assertSame(
            "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            $map['Content-Security-Policy'],
        );
    }

    public function testMinimalSetWhenDisabled(): void
    {
        $names = array_map(static fn (array $p): string => $p[0], SecurityHeaders::forGenerated(false));
        self::assertSame(['X-Content-Type-Options', 'Cache-Control'], $names);
    }
}
