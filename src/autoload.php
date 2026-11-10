<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
 * Minimal PSR-4 autoloader for the MirrorGate\ namespace (src/ directory).
 * No Composer and no vendor/ directory are required at runtime.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'MirrorGate\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if ($relative === '' || preg_match('/\A[A-Za-z0-9_\\\\]+\z/', $relative) !== 1) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
