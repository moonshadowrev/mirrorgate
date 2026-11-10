<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Support;

final class Json
{
    /**
     * @throws \JsonException
     */
    public static function encode(mixed $v): string
    {
        return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws \JsonException
     */
    public static function decode(string $s, int $maxDepth = 32): mixed
    {
        return json_decode($s, true, max(1, $maxDepth), JSON_THROW_ON_ERROR);
    }
}
