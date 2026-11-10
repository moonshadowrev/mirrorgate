<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Log;

interface LoggerInterface
{
    /**
     * @param array<string,scalar|null> $context
     */
    public function log(string $level, string $message, array $context = []): void;

    /** @param array<string,scalar|null> $context */
    public function debug(string $message, array $context = []): void;

    /** @param array<string,scalar|null> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string,scalar|null> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string,scalar|null> $context */
    public function error(string $message, array $context = []): void;
}
