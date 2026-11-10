<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace PHPUnit\Framework\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class DataProvider
{
    public function __construct(public readonly string $methodName)
    {
    }
}
