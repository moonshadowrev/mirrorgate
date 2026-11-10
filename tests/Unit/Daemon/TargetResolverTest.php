<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Daemon;

use MirrorGate\Daemon\TargetResolver;
use MirrorGate\Log\NullLogger;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DaemonTestSupport.php';

final class TargetResolverTest extends TestCase
{
    public function testHostOutsideTheAllowlistIsRefused(): void
    {
        $r = new TargetResolver(DaemonTestSupport::config(['targetAllowlist' => ['8.8.8.8']]), new NullLogger());
        $this->expectException(\RuntimeException::class);
        $r->resolve('evil.example', 80);
    }

    public function testPrivateAndMetadataLiteralsAreBlockedByDefault(): void
    {
        $r = new TargetResolver(DaemonTestSupport::config(['targetAllowlist' => ['127.0.0.1', '169.254.169.254', '8.8.8.8']]), new NullLogger());
        self::assertSame('blocked', $r->warm('127.0.0.1', 80));
        self::assertSame('blocked', $r->warm('169.254.169.254', 80));
        self::assertNull($r->warm('8.8.8.8', 53));
        self::assertSame('8.8.8.8', $r->resolve('8.8.8.8', 53));
        self::assertSame('not_allowed', $r->warm('evil.example', 80));
    }

    public function testPrivateTargetsNeedExplicitOptIn(): void
    {
        $r = new TargetResolver(DaemonTestSupport::config(['targetAllowlist' => ['127.0.0.1'], 'allowPrivateTargets' => true]), new NullLogger());
        self::assertSame('127.0.0.1', $r->resolve('127.0.0.1', 80));
    }

    public function testAlwaysBlockedFloorHoldsWithAllowPrivateTargets(): void
    {
        $r = new TargetResolver(DaemonTestSupport::config([
            'targetAllowlist' => ['169.254.169.254', '::', '0.0.0.0', 'fe80::1', '64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::', '127.0.0.1'],
            'allowPrivateTargets' => true,
        ]), new NullLogger());
        foreach (['169.254.169.254', '::', '0.0.0.0', 'fe80::1', '64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::'] as $host) {
            self::assertSame('blocked', $r->warm($host, 80), $host);
        }
        self::assertNull($r->warm('127.0.0.1', 80));
    }

    public function testFailingHostsAreBackedOffAndRefreshedOnePerRound(): void
    {
        $log = new class () implements \MirrorGate\Log\LoggerInterface {
            public int $failures = 0;

            public function log(string $level, string $message, array $context = []): void
            {
                if ($level === 'warning' && $message === 'target refresh failed') {
                    $this->failures++;
                }
            }

            public function debug(string $message, array $context = []): void
            {
            }

            public function info(string $message, array $context = []): void
            {
            }

            public function warning(string $message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function error(string $message, array $context = []): void
            {
            }
        };
        $r = new TargetResolver(DaemonTestSupport::config(['targetAllowlist' => ['a.invalid', 'b.invalid']]), $log);
        self::assertSame('unresolved', $r->warm('a.invalid', 80));
        self::assertSame('unresolved', $r->warm('b.invalid', 80));

        $r->refreshRound();
        self::assertSame(1, $log->failures, 'one lookup per round');
        $r->refreshRound();
        self::assertSame(2, $log->failures, 'the next round tries the other host');
        $r->refreshRound();
        $r->refreshRound();
        self::assertSame(2, $log->failures, 'both hosts are backed off now');
    }

    public function testLoopModeNeverPerformsALookup(): void
    {
        $r = new TargetResolver(DaemonTestSupport::config(['targetAllowlist' => ['origin.example']]), new NullLogger());
        $r->setLoopMode(true);
        $this->expectException(\RuntimeException::class);
        $r->resolve('origin.example', 80);
    }
}
