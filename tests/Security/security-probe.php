#!/usr/bin/env php
<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/*
 * Sends the raw requests from tests/Security/cases/*.php to a running MirrorGate and checks the
 * status line of each answer. Each case is [name, rawRequest, expected] where expected is an int
 * status or a callable receiving the full raw response and returning bool.
 *
 *   php tests/Security/security-probe.php 127.0.0.1:8080 [mirror-host:port]
 *
 * The optional second argument replaces "{host}" in the requests (default: the connect address).
 * Used by scripts/e2e.sh; exit code 0 means every probe behaved.
 */

$target = $argv[1] ?? '';
if (preg_match('/^([\w.\-]+):(\d{1,5})$/', $target, $m) !== 1) {
    fwrite(STDERR, "usage: security-probe.php host:port [mirror-host:port]\n");
    exit(2);
}
$hostHeader = $argv[2] ?? $target;
$pass = 0;
$fail = 0;

foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $file) {
    /** @var list<array{0:string,1:string,2:int|callable}> $cases */
    $cases = require $file;
    foreach ($cases as [$name, $raw, $expected]) {
        $req = str_replace('{host}', $hostHeader, $raw);
        $sock = @stream_socket_client('tcp://' . $m[1] . ':' . $m[2], $errno, $errstr, 3);
        if ($sock === false) {
            $fail++;
            echo "  FAIL $name (connect: $errstr)\n";
            continue;
        }
        stream_set_timeout($sock, 5);
        fwrite($sock, $req);
        $resp = '';
        while (!feof($sock)) {
            $chunk = fread($sock, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $resp .= $chunk;
            if (strlen($resp) > 65536) {
                break;
            }
        }
        fclose($sock);
        $status = preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $resp, $sm) === 1 ? (int) $sm[1] : 0;
        $ok = is_callable($expected) ? (bool) $expected($resp) : $status === $expected;
        if ($ok) {
            $pass++;
            echo "  ok   $name\n";
        } else {
            $fail++;
            echo "  FAIL $name (got $status" . (is_int($expected) ? ", expected $expected" : '') . ")\n";
        }
    }
}

echo "security-probe: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
