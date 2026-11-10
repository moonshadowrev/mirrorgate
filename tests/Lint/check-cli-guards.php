#!/usr/bin/env php
<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/*
 * Repository hygiene checks (run by "composer lint"):
 *
 *  1. Every executable entry script outside public/ and src/ refuses to run under a web SAPI.
 *  2. src/, public/ and bin/ contain none of the banned dangerous constructs.
 *
 * Exit code 0 means clean.
 */

$root = dirname(__DIR__, 2);
$problems = [];

/** @return list<string> */
function mg_files(string $dir, bool $phpOnly): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->isFile() && (!$phpOnly || $f->getExtension() === 'php')) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

// 1. CLI guards.
$entries = array_merge(
    mg_files($root . '/bin', false),
    mg_files($root . '/tools', true),
    mg_files($root . '/examples', true),
    [$root . '/tests/run.php'],
    mg_files($root . '/tests/Lint', true),
    array_filter(mg_files($root . '/tests/Security', true), static fn (string $f): bool => !str_contains($f, '/cases/')),
    array_filter(mg_files($root . '/tests/Support', true), static fn (string $f): bool => in_array(basename($f), ['origin-router.php', 'raw-origin.php'], true)),
);
foreach ($entries as $file) {
    $head = implode("\n", array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], 0, 60));
    if (preg_match("/PHP_SAPI\s*(?:!==|===|!=|==)\s*'(?:cli|cli-server)'/", $head) !== 1) {
        $problems[] = 'missing SAPI guard: ' . substr($file, strlen($root) + 1);
    }
}

// 2. Banned constructs in shipped code.
$banned = [
    '/\beval\s*\(/' => 'eval()',
    '/\bunserialize\s*\(/' => 'unserialize()',
    '/\bshell_exec\s*\(/' => 'shell_exec()',
    '/\bproc_open\s*\(/' => 'proc_open()',
    '/\bpopen\s*\(/' => 'popen()',
    '/\bpassthru\s*\(/' => 'passthru()',
    '/\bsystem\s*\(/' => 'system()',
    '/(?<![A-Za-z_>:])exec\s*\(/' => 'exec()',
    '/\bcurl_close\s*\(/' => 'curl_close() (deprecated since 8.5)',
    '/\bextract\s*\(/' => 'extract()',
    '/\$\{[A-Za-z_]/' => '${var} interpolation (deprecated in 8.2)',
    '/`[^`\n]*`/' => 'backtick operator',
];
$shipped = array_merge(mg_files($root . '/src', true), mg_files($root . '/public', true), mg_files($root . '/bin', false), [$root . '/index.php']);
foreach ($shipped as $file) {
    $code = (string) file_get_contents($file);
    // Strip comments and strings crudely by tokenising; only look at code tokens.
    $text = '';
    foreach (token_get_all($code) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text .= str_repeat(' ', strlen($t[1]));
                continue;
            }
            $text .= $t[1];
        } else {
            $text .= $t;
        }
    }
    foreach ($banned as $re => $name) {
        if ($name === 'backtick operator') {
            if (str_contains($text, '`')) {
                $problems[] = $name . ' in ' . substr($file, strlen($root) + 1);
            }
            continue;
        }
        if (preg_match($re, $text) === 1) {
            $problems[] = $name . ' in ' . substr($file, strlen($root) + 1);
        }
    }
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(1);
}
echo 'check-cli-guards: ok (' . count($entries) . ' entry scripts, ' . count($shipped) . " shipped files)\n";
