<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

/*
 * Root shim for hosts whose document root cannot be changed to public/.
 *
 * Use it only when you must: the whole project then sits inside the web root and protection
 * of the env file and the runtime directory must not depend on .htaccess alone. Use the
 * self-protecting ".env.php" form (see .env.example) and set STORAGE_DIR to a directory
 * outside the web root, otherwise MirrorGate refuses to boot.
 *
 * Preferred layouts (see docs/multi-instance.md) point the docroot at public/.
 */

if (!defined('MIRRORGATE_ROOT')) {
    define('MIRRORGATE_ROOT', __DIR__);
}

require __DIR__ . '/public/index.php';
