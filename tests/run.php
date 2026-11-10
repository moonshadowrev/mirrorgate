<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
 * Dependency-free test runner. Works on plain PHP 8.3+ without Composer or PHPUnit.
 *
 *   php tests/run.php [--filter=substring] [--list] [path ...]
 *
 * It discovers every *Test.php under tests/Unit (or the given paths), runs each public test*
 * method, supports #[DataProvider], and treats every PHP warning, notice and deprecation as a
 * failure. The same classes also run under vendor/bin/phpunit (composer test).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// Always use the built-in shim here, even when vendor/ exists, so behaviour is identical everywhere.
define('MIRRORGATE_TESTS_NO_VENDOR', true);
require __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;

$filter = null;
$list = false;
$paths = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    } elseif ($arg === '--list') {
        $list = true;
    } else {
        $paths[] = $arg;
    }
}
if ($paths === []) {
    $paths = [__DIR__ . '/Unit'];
}

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if ((error_reporting() & $no) === 0) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

/** @return list<string> */
function collect(string $path): array
{
    if (is_file($path)) {
        return [$path];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

$files = [];
foreach ($paths as $p) {
    foreach (collect($p) as $f) {
        $files[] = $f;
    }
}

$before = get_declared_classes();
foreach ($files as $f) {
    require_once $f;
}
$classes = array_values(array_diff(get_declared_classes(), $before));

$pass = 0;
$fail = 0;
$skip = 0;
$failures = [];
$started = microtime(true);

foreach ($classes as $class) {
    $rc = new ReflectionClass($class);
    if ($rc->isAbstract() || !$rc->isSubclassOf(TestCase::class) || !str_ends_with($rc->getName(), 'Test')) {
        continue;
    }
    $methods = [];
    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if (str_starts_with($m->getName(), 'test') && $m->getDeclaringClass()->getName() === $rc->getName()
            || str_starts_with($m->getName(), 'test') && !$m->isStatic()) {
            $methods[$m->getName()] = $m;
        }
    }
    $selected = [];
    foreach ($methods as $name => $m) {
        $label = $rc->getShortName() . '::' . $name;
        if ($filter === null || stripos($rc->getName() . '::' . $name, $filter) !== false) {
            $selected[$name] = $m;
        }
    }
    if ($selected === []) {
        continue;
    }
    if ($list) {
        foreach (array_keys($selected) as $n) {
            echo $rc->getName() . '::' . $n . "\n";
        }
        continue;
    }
    try {
        $class::setUpBeforeClass();
    } catch (Throwable $e) {
        $fail++;
        $failures[] = [$rc->getName() . '::setUpBeforeClass', $e];
        continue;
    }
    foreach ($selected as $name => $m) {
        $sets = [[]];
        foreach ($m->getAttributes(DataProvider::class) as $attr) {
            $provider = $attr->newInstance()->methodName;
            $sets = [];
            foreach ($class::$provider() as $key => $args) {
                $sets[(string) $key] = is_array($args) ? array_values($args) : [$args];
            }
        }
        foreach ($sets as $key => $args) {
            $label = $rc->getName() . '::' . $name . ($key !== '' && $key !== 0 ? ' [' . $key . ']' : '');
            $obj = new $class($name);
            try {
                ob_start();
                try {
                    $obj->runBare($name, $args);
                } finally {
                    $out = (string) ob_get_clean();
                }
                if ($out !== '') {
                    throw new AssertionFailedError('Test produced unexpected output: ' . substr($out, 0, 200));
                }
                $pass++;
                echo '.';
            } catch (SkippedTest) {
                $skip++;
                echo 'S';
            } catch (Throwable $e) {
                $fail++;
                $failures[] = [$label, $e];
                echo 'F';
            }
        }
    }
    try {
        $class::tearDownAfterClass();
    } catch (Throwable $e) {
        $fail++;
        $failures[] = [$rc->getName() . '::tearDownAfterClass', $e];
    }
}

if ($list) {
    exit(0);
}

echo "\n\n";
foreach ($failures as $i => [$label, $e]) {
    echo ($i + 1) . ') ' . $label . "\n   " . get_class($e) . ': ' . str_replace("\n", "\n   ", $e->getMessage()) . "\n   at "
        . $e->getFile() . ':' . $e->getLine() . "\n\n";
}
printf(
    "%s: %d passed, %d failed, %d skipped, %d assertions in %.1fs\n",
    $fail === 0 ? 'OK' : 'FAILED',
    $pass,
    $fail,
    $skip,
    TestCase::$assertions,
    microtime(true) - $started,
);
exit($fail === 0 ? 0 : 1);
