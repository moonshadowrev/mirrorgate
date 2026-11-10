<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Tests\Unit\Upstream;

use MirrorGate\Upstream\CurlCapabilities;
use PHPUnit\Framework\TestCase;

final class CurlCapabilitiesTest extends TestCase
{
    public function testProtocolsAreRestrictedToHttpAndHttps(): void
    {
        $options = CurlCapabilities::protocolsOption();

        self::assertCount(2, $options);
        foreach ($options as $value) {
            if (\is_string($value)) {
                self::assertSame('http,https', $value);
            } else {
                self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $value);
            }
        }
    }

    public function testFeatureProbesReturnBooleans(): void
    {
        self::assertIsBool(CurlCapabilities::supportsHttp2());
        self::assertIsBool(CurlCapabilities::supportsBrotli());
    }

    public function testHttp2IsNeverRequestedForPlainHttp(): void
    {
        self::assertSame(CURL_HTTP_VERSION_1_1, CurlCapabilities::httpVersionValue('2', 'http'));
        self::assertSame(CURL_HTTP_VERSION_1_1, CurlCapabilities::httpVersionValue('1.1', 'https'));
    }
}
