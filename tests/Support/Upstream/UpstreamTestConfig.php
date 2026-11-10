<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Support\Upstream;

use MirrorGate\Config\OriginConfig;
use MirrorGate\Config\UpstreamConfig;

/**
 * Builds config value objects for the Upstream unit tests.
 */
final class UpstreamTestConfig
{
    /**
     * @param list<int>    $ports
     * @param list<string> $extraOrigins
     */
    public static function origin(
        string $scheme = 'https',
        string $host = 'overlord.team',
        int $port = 443,
        bool $allowPrivate = false,
        ?string $resolveIp = null,
        ?string $proxy = null,
        array $ports = [80, 443],
        array $extraOrigins = [],
        bool $allowHttp = false,
        ?string $caBundle = null,
    ): OriginConfig {
        $default = $scheme === 'https' ? 443 : 80;
        $hostHeader = $port === $default ? $host : $host . ':' . $port;
        if (!\in_array($port, $ports, true)) {
            $ports[] = $port;
        }

        return new OriginConfig(
            $scheme . '://' . $hostHeader,
            $scheme,
            $host,
            $port,
            $hostHeader,
            $allowHttp,
            $allowPrivate,
            $ports,
            $resolveIp,
            $proxy,
            $caBundle,
            [],
            $extraOrigins,
            'off',
            '/__mg/s',
        );
    }

    public static function upstream(
        int $retries = 1,
        int $retryBaseMs = 10,
        int $retryCapMs = 50,
        int $timeout = 10,
        int $connectTimeout = 3,
        int $maxRequestBodyBytes = 1048576,
        bool $breakerEnabled = true,
        int $breakerThreshold = 3,
        int $breakerWindow = 30,
        int $breakerCooldown = 10,
        int $breakerMaxCooldown = 40,
        string $httpVersion = '1.1',
    ): UpstreamConfig {
        return new UpstreamConfig(
            $connectTimeout,
            $timeout,
            20,
            256,
            $retries,
            $retryBaseMs,
            $retryCapMs,
            $httpVersion,
            $maxRequestBodyBytes,
            8192,
            100,
            8192,
            0,
            $breakerEnabled,
            $breakerThreshold,
            $breakerWindow,
            $breakerCooldown,
            $breakerMaxCooldown,
            true,
            true,
            true,
            ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        );
    }
}
