<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
 * Test bootstrap shared by PHPUnit (phpunit.xml.dist) and the dependency-free runner (tests/run.php).
 *
 * Loads the MirrorGate autoloader, maps the MirrorGate\Tests\ namespace to tests/, and, when PHPUnit
 * is not installed, loads a minimal compatible subset from tests/lib so that the same test classes
 * run on a plain PHP install.
 */

$root = dirname(__DIR__);

require_once $root . '/src/autoload.php';

if (!defined('MIRRORGATE_TESTS_NO_VENDOR') && is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'MirrorGate\\Tests\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = $root . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    foreach (['AssertionFailedError', 'SkippedTest', 'Attributes/DataProvider', 'TestCase'] as $f) {
        require_once __DIR__ . '/lib/PHPUnit/Framework/' . $f . '.php';
    }
}

date_default_timezone_set('UTC');
error_reporting(-1);
