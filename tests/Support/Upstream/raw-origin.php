<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// raw socket origin: port in argv[1]; behavior by request path
$srv = stream_socket_server('tcp://127.0.0.1:' . $argv[1], $en, $es);
while ($c = stream_socket_accept($srv, 60)) {
    $req = ''; while (!str_contains($req, "\r\n\r\n")) { $d = fread($c, 4096); if ($d === '' || $d === false) break; $req .= $d; }
    preg_match('#^\w+ (\S+)#', $req, $m); $path = $m[1] ?? '/';
    switch ($path) {
    case '/fold': $r = "HTTP/1.1 200 OK\r\nX-A: one\r\n  folded\r\nContent-Length: 2\r\n\r\nhi"; break;
    case '/ctl': $r = "HTTP/1.1 200 OK\r\nX-Bad: a\x01b\r\nX-Good: ok\r\nContent-Length: 2\r\n\r\nhi"; break;
    case '/badname': $r = "HTTP/1.1 200 OK\r\nBad Name: x\r\nX-Good: ok\r\nContent-Length: 2\r\n\r\nhi"; break;
    case '/cl2': $r = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nContent-Length: 3\r\n\r\nhi"; break;
    case '/short': $r = "HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\nhi"; break;
    case '/interim': $r = "HTTP/1.1 103 Early Hints\r\nLink: </a>; rel=preload\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close, X-Hop\r\nX-Hop: 1\r\n\r\nhi"; break;
    case '/reset': fclose($c); continue 2;
    case '/upg': $r = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n\r\n"; break;
    default: $r = "HTTP/1.1 404 NF\r\nContent-Length: 0\r\n\r\n";
    }
    fwrite($c, $r); fclose($c);
}
