<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($p) {
case '/ok': header('X-Test: a'); header('Set-Cookie: a=1; Path=/', false); header('Set-Cookie: b=2; Expires=Wed, 21 Oct 2026 07:28:00 GMT', false); echo "hello"; break;
case '/gz': header('Content-Encoding: gzip'); header('Vary: Accept-Encoding'); $b = gzencode(str_repeat('abc', 1000)); header('Content-Length: ' . strlen($b)); echo $b; break;
case '/echo':
    $h = []; foreach (getallheaders() as $k=>$v) $h[strtolower($k)] = $v;
    header('Content-Type: application/json');
    echo json_encode(['method'=>$_SERVER['REQUEST_METHOD'],'body'=>file_get_contents('php://input'),'headers'=>$h,'qs'=>$_SERVER['QUERY_STRING'] ?? '']);
    break;
case '/slow': sleep(4); echo 'late'; break;
case '/big': echo str_repeat('x', 300000); break;
case '/redir': http_response_code(302); header('Location: http://evil.example/'); break;
case '/five': http_response_code(503); echo 'down'; break;
case '/sse': header('Content-Type: text/event-stream'); for ($i=0;$i<3;$i++){ echo "data: $i\n\n"; flush(); usleep(200000);} break;
case '/head': header('Content-Length: 1234'); break;
case '/nobody': http_response_code(204); break;
default: http_response_code(404); echo 'nf';
}
