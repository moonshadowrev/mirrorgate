<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate;

use MirrorGate\Cache\HttpCache;
use MirrorGate\Config\Config;
use MirrorGate\Console\SelfCheck;
use MirrorGate\Error\ErrorRenderer;
use MirrorGate\Http\ResponseEmitter;
use MirrorGate\Http\Router;
use MirrorGate\Log\FileLogger;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Rewrite\Rewriter;
use MirrorGate\Security\AdminAuthenticator;
use MirrorGate\Security\AdminController;
use MirrorGate\Security\ClientIpResolver;
use MirrorGate\Security\ConcurrencyLimiter;
use MirrorGate\Security\HostGuard;
use MirrorGate\Security\RateLimiterFactory;
use MirrorGate\Security\RateLimiterInterface;
use MirrorGate\Security\RequestHeaderBuilder;
use MirrorGate\Security\RequestValidator;
use MirrorGate\Support\Clock;
use MirrorGate\Support\SystemClock;
use MirrorGate\Upstream\CachingDnsResolver;
use MirrorGate\Upstream\CircuitBreaker;
use MirrorGate\Upstream\OriginGuard;
use MirrorGate\Upstream\RetryPolicy;
use MirrorGate\Upstream\SystemDnsResolver;
use MirrorGate\Upstream\UpstreamClient;
use MirrorGate\Upstream\UpstreamClientInterface;
use MirrorGate\Upstream\UpstreamUrlBuilder;

/**
 * Lazy service container. Every accessor builds its service on first use and caches it.
 */
final class Container
{
    /** @var array<string,object> */
    private array $services = [];
    private ?string $salt = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function clock(): Clock
    {
        return $this->get('clock', fn (): Clock => new SystemClock());
    }

    public function logger(): LoggerInterface
    {
        return $this->get('logger', function (): LoggerInterface {
            $app = $this->config->app;
            $file = $app->logFile;
            if ($file === $app->storageDir . '/logs/mirrorgate.log') {
                // LOG_FILE was not set explicitly: append an unguessable suffix to the default name.
                $suffix = substr(hash_hmac('sha256', 'log', $this->salt()), 0, 12);
                $file = $app->storageDir . '/logs/mirrorgate-' . $suffix . '.log';
            }
            return new FileLogger($file, $app->logLevel, $app->logMaxBytes);
        });
    }

    public function salt(): string
    {
        if ($this->salt === null) {
            $this->salt = Bootstrap::resolveSalt($this->config);
        }
        return $this->salt;
    }

    public function httpCache(): HttpCache
    {
        return $this->get('httpCache', fn (): HttpCache => new HttpCache(
            $this->config->cache,
            $this->clock(),
            $this->logger(),
            $this->config->app->instanceId,
            $this->rewriter()->fingerprint(),
            hash_hmac('sha256', 'pathmeta', $this->salt()),
        ));
    }

    public function rewriter(): Rewriter
    {
        return $this->get('rewriter', fn (): Rewriter => new Rewriter(
            $this->config->rewrite,
            $this->config->origin,
            $this->config->mirror,
            $this->logger(),
        ));
    }

    public function breaker(): CircuitBreaker
    {
        return $this->get('breaker', fn (): CircuitBreaker => new CircuitBreaker(
            $this->config->upstream,
            $this->config->app->storageDir . '/breaker',
            $this->clock(),
        ));
    }

    public function upstreamClient(): UpstreamClientInterface
    {
        return $this->get('upstreamClient', function (): UpstreamClientInterface {
            $log = $this->logger();
            $dns = new CachingDnsResolver(
                new SystemDnsResolver($log),
                $this->config->app->storageDir . '/dns',
                $this->clock(),
                $this->config->origin->dnsTtl,
                $this->config->origin->dnsStaleTtl,
                $log,
            );
            $guard = new OriginGuard($this->config->origin, $dns, $log);
            return new UpstreamClient(
                $this->config->upstream,
                $this->config->origin,
                $guard,
                new RetryPolicy($this->config->upstream),
                $log,
            );
        });
    }

    public function urlBuilder(): UpstreamUrlBuilder
    {
        return $this->get('urlBuilder', fn (): UpstreamUrlBuilder => new UpstreamUrlBuilder($this->config->origin));
    }

    public function errorRenderer(): ErrorRenderer
    {
        return $this->get('errorRenderer', fn (): ErrorRenderer => new ErrorRenderer(
            $this->config->app,
            rtrim($this->config->projectRoot, '/\\') . '/templates',
        ));
    }

    public function emitter(): ResponseEmitter
    {
        return $this->get('emitter', fn (): ResponseEmitter => new ResponseEmitter($this->logger()));
    }

    public function router(): Router
    {
        return $this->get('router', fn (): Router => new Router());
    }

    public function requestValidator(): RequestValidator
    {
        return $this->get('requestValidator', fn (): RequestValidator => new RequestValidator($this->config->upstream));
    }

    public function hostGuard(): HostGuard
    {
        return $this->get('hostGuard', fn (): HostGuard => new HostGuard($this->config->mirror));
    }

    public function clientIpResolver(): ClientIpResolver
    {
        return $this->get('clientIpResolver', fn (): ClientIpResolver => new ClientIpResolver($this->config->security));
    }

    public function rateLimiter(): RateLimiterInterface
    {
        return $this->get('rateLimiter', fn (): RateLimiterInterface => RateLimiterFactory::create(
            $this->config->rateLimit,
            $this->config->app->storageDir,
            $this->clock(),
        ));
    }

    public function concurrencyLimiter(): ConcurrencyLimiter
    {
        return $this->get('concurrencyLimiter', fn (): ConcurrencyLimiter => new ConcurrencyLimiter(
            $this->config->app->storageDir . '/locks',
            $this->clock(),
        ));
    }

    public function globalConcurrencyLimiter(): ConcurrencyLimiter
    {
        $shared = $this->config->rateLimit->sharedDir;
        if ($shared === null) {
            return $this->concurrencyLimiter();
        }
        return $this->get('globalConcurrencyLimiter', fn (): ConcurrencyLimiter => new ConcurrencyLimiter($shared, $this->clock()));
    }

    public function headerBuilder(): RequestHeaderBuilder
    {
        return $this->get('headerBuilder', fn (): RequestHeaderBuilder => new RequestHeaderBuilder($this->config));
    }

    public function adminController(): AdminController
    {
        return $this->get('adminController', fn (): AdminController => new AdminController(
            $this->config,
            new AdminAuthenticator($this->config->admin, $this->rateLimiter(), $this->config->security->trustedProxies),
            $this->httpCache(),
            $this->breaker(),
            $this->selfCheck(),
            $this->clock(),
        ));
    }

    public function selfCheck(): SelfCheck
    {
        return $this->get('selfCheck', fn (): SelfCheck => new SelfCheck($this->config));
    }

    /**
     * @template T of object
     * @param callable():T $factory
     * @return T
     */
    private function get(string $id, callable $factory): object
    {
        if (!isset($this->services[$id])) {
            $this->services[$id] = $factory();
        }
        /** @var T */
        return $this->services[$id];
    }
}
