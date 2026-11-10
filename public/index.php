<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
 * MirrorGate front controller.
 *
 * MIRRORGATE_ROOT is the project root (the directory holding src/, templates/ and, usually, .env).
 * MIRRORGATE_ENV may be defined by a per-instance stub or set as an environment variable to point
 * at an .env file stored elsewhere.
 */
if (!defined('MIRRORGATE_ROOT')) {
    define('MIRRORGATE_ROOT', dirname(__DIR__));
}

require MIRRORGATE_ROOT . '/src/autoload.php';

\MirrorGate\Bootstrap::run(
    (string) MIRRORGATE_ROOT,
    \MirrorGate\Bootstrap::resolveEnvFile((string) MIRRORGATE_ROOT),
);
