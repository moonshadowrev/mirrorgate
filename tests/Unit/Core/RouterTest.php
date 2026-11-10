<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Core;

use MirrorGate\Config\ConfigFactory;
use MirrorGate\Http\Request;
use MirrorGate\Http\RouteKind;
use MirrorGate\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private const BASE = ['ORIGIN_URL' => 'https://overlord.team', 'MIRROR_URL' => 'https://overload.ir'];
    private const ADMIN = ['ADMIN_ENABLED' => 'true', 'ADMIN_TOKEN' => 'Zq7vK2mP9xW4nR8tL5bY3cH6jD1fG0sA'];

    /** @param array<string,string> $headers */
    private function req(string $path, array $headers = []): Request
    {
        return Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '1.1.1.1'],
            $headers + ['Host' => 'overload.ir'],
        );
    }

    public function testReservedAndBlockedRoutes(): void
    {
        $cfg = ConfigFactory::create(self::BASE + self::ADMIN + ['BLOCKED_PATHS' => '/admin*,/secret/?', 'AUTH_PASSTHROUGH_BLOCK' => '/login*', 'SERVICE_WORKER_MODE' => 'block'], '/srv', '/srv/.env');
        $r = new Router();
        self::assertSame(RouteKind::Admin, $r->route($this->req('/__mirrorgate/health'), $cfg)->kind);
        self::assertSame(RouteKind::Proxy, $r->route($this->req('/__mirrorgatex'), $cfg)->kind);
        self::assertSame(RouteKind::AcmeChallenge, $r->route($this->req('/.well-known/acme-challenge/abc'), $cfg)->kind);
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/ADMIN/x'), $cfg)->kind);
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/login/x'), $cfg)->kind);
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/lo%67in'), $cfg)->kind);
        self::assertSame(RouteKind::ServiceWorkerKill, $r->route($this->req('/sw.js', ['Service-Worker' => 'script']), $cfg)->kind);
        $proxy = $r->route($this->req('/a/b'), $cfg);
        self::assertSame(RouteKind::Proxy, $proxy->kind);
        self::assertSame('overlord.team', $proxy->originHost);
        self::assertSame('/a/b', $proxy->originPath);
    }

    public function testPrefixMode(): void
    {
        $cfg = ConfigFactory::create(self::BASE + ['SUBDOMAIN_MODE' => 'prefix', 'PREFIX_MODE_ACK_SHARED_ORIGIN' => 'true', 'EXTRA_ORIGINS' => 'api.overlord.team'], '/srv', '/srv/.env');
        $r = new Router();
        $route = $r->route($this->req('/__mg/s/api.overlord.team/v1/x'), $cfg);
        self::assertSame(RouteKind::Proxy, $route->kind);
        self::assertSame('api.overlord.team', $route->originHost);
        self::assertSame('/v1/x', $route->originPath);
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/__mg/s/evil.com/x'), $cfg)->kind);
        self::assertSame('/', $r->route($this->req('/__mg/s/api.overlord.team'), $cfg)->originPath);
    }

    public function testPrefixModeAppliesBlockedPathsToTheOriginPath(): void
    {
        $cfg = ConfigFactory::create(self::BASE + [
            'SUBDOMAIN_MODE' => 'prefix', 'PREFIX_MODE_ACK_SHARED_ORIGIN' => 'true',
            'EXTRA_ORIGINS' => 'api.overlord.team', 'BLOCKED_PATHS' => '/admin/*,/.env',
        ], '/srv', '/srv/.env');
        $r = new Router();
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/admin/x'), $cfg)->kind);
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/__mg/s/overlord.team/admin/x'), $cfg)->kind);
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/__mg/s/api.overlord.team/admin/x'), $cfg)->kind);
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/__mg/s/overlord.team/.env'), $cfg)->kind);
        self::assertSame(RouteKind::Proxy, $r->route($this->req('/__mg/s/overlord.team/ok'), $cfg)->kind);
    }

    public function testBlockedPathsSeeEncodedSlashAndPathParameters(): void
    {
        $cfg = ConfigFactory::create(self::BASE + ['BLOCKED_PATHS' => '/admin/*'], '/srv', '/srv/.env');
        $r = new Router();
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/admin%2Fx'), $cfg)->kind);
        self::assertSame(RouteKind::Blocked, $r->route($this->req('/admin;a=b/x'), $cfg)->kind);
        self::assertSame(RouteKind::Proxy, $r->route($this->req('/public/x'), $cfg)->kind);
    }

    public function testAdminPrefixIsAnOrdinaryPathWhileAdminIsDisabled(): void
    {
        $r = new Router();
        $off = ConfigFactory::create(self::BASE, '/srv', '/srv/.env');
        $route = $r->route($this->req('/__mirrorgate/health'), $off);
        self::assertSame(RouteKind::Proxy, $route->kind);
        self::assertSame('/__mirrorgate/health', $route->originPath);

        $on = ConfigFactory::create(self::BASE + self::ADMIN, '/srv', '/srv/.env');
        self::assertSame(RouteKind::Admin, $r->route($this->req('/__mirrorgate/health'), $on)->kind);
    }
}
