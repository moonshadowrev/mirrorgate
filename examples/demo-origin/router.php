<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
 * MirrorGate demo origin: a tiny application that exercises everything the mirror must handle.
 * Run it with the built-in server (router script):
 *
 *   php -S 127.0.0.1:9101 examples/demo-origin/router.php
 *
 * Routes: / (HTML with absolute, protocol-relative, escaped, srcset and subdomain links),
 * /style.css, /api/data.json, /feed.xml, /robots.txt, /sse, /redirect, /redirect-external,
 * /cookie, /cached, /reflect, /mustreval, /pingback, /error500, /slow?sec=N, /echo (request headers and body), /big.
 * It is for local testing only and must never be deployed.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$hostOnly = preg_replace('/:\d+$/', '', $host) ?? $host;
$port = (string) ($_SERVER['SERVER_PORT'] ?? '80');
$base = 'http://' . $host;
// Subdomain-style link on the default HTTP port, as sibling hosts normally are.
$api = 'http://api.' . $hostOnly;
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

switch ($path) {
    case '/':
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=60');
        header('X-Powered-By: DemoOrigin/1.0 (' . $host . ')');
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Demo origin</title>
<link rel="canonical" href="{$base}/">
<link rel="stylesheet" href="{$base}/style.css">
<meta property="og:url" content="{$base}/">
</head>
<body>
<h1 id="home">Welcome to {$hostOnly}</h1>
<a id="abs" href="{$base}/about">absolute link</a>
<a id="rel" href="/about">relative link</a>
<a id="proto" href="//{$host}/about">protocol relative</a>
<a id="sub" href="{$api}/data">subdomain link</a>
<a id="ext" href="https://example.org/keep">external link</a>
<img id="img" src="{$base}/logo.png" srcset="{$base}/logo.png 1x, {$base}/logo@2x.png 2x" alt="">
<form action="{$base}/echo" method="post"><input name="q"></form>
<script type="application/json" id="data">{"home":"{$base}/","escaped":"http:\/\/{$host}\/escaped"}</script>
</body>
</html>
HTML;
        break;

    case '/style.css':
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=60');
        echo "body { background: url({$base}/bg.png); }\n@import url(\"{$base}/more.css\");\n";
        break;

    case '/api/data.json':
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: ' . $base);
        echo json_encode(['self' => $base . '/api/data.json', 'next' => $base . '/api/next', 'plain' => 'hello'], JSON_THROW_ON_ERROR);
        break;

    case '/feed.xml':
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo "<?xml version=\"1.0\"?><rss version=\"2.0\"><channel><link>{$base}/</link><item><link>{$base}/post/1</link></item></channel></rss>";
        break;

    case '/robots.txt':
        header('Content-Type: text/plain');
        echo "User-agent: *\nDisallow: /private\nSitemap: {$base}/sitemap.xml\n";
        break;

    case '/sse':
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
        for ($i = 1; $i <= 5; $i++) {
            echo "id: {$i}\ndata: chunk-{$i} " . number_format(microtime(true), 3, '.', '') . "\n\n";
            flush();
            usleep(500000);
        }
        break;

    case '/redirect':
        header('Location: ' . $base . '/final', true, 302);
        break;

    case '/redirect-external':
        header('Location: https://example.org/elsewhere', true, 302);
        break;

    case '/final':
        header('Content-Type: text/plain');
        echo "final destination\n";
        break;

    case '/cookie':
        header('Content-Type: text/plain');
        header('Set-Cookie: sid=abc123; Domain=' . $hostOnly . '; Path=/; HttpOnly; SameSite=Lax');
        header('Set-Cookie: pref=dark; Path=/', false);
        echo "cookies set\n";
        break;

    case '/cached':
        header('Content-Type: text/plain');
        header('Cache-Control: public, max-age=1');
        echo 'generated-at ' . number_format(microtime(true), 6, '.', '') . "\n";
        break;

    case '/reflect':
        // Echoes the query string it received, so tests can see what the mirror forwarded for a cache fill.
        header('Content-Type: text/plain');
        header('Cache-Control: public, max-age=60');
        echo 'query=' . (string) ($_SERVER['QUERY_STRING'] ?? '') . "\n";
        break;

    case '/mustreval':
        header('Content-Type: text/plain');
        header('Cache-Control: public, max-age=1, must-revalidate');
        echo "must revalidate\n";
        break;

    case '/pingback':
        header('Content-Type: text/plain');
        header('X-Pingback: ' . $base . '/xmlrpc.php');
        header('Link-Template: <' . $base . '/x>');
        echo "pingback\n";
        break;

    case '/error500':
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "SECRET internal stack trace at /srv/app/secret.php\n";
        break;

    case '/slow':
        $sec = max(0, min(60, (int) ($_GET['sec'] ?? 5)));
        sleep($sec);
        header('Content-Type: text/plain');
        echo "slow {$sec}\n";
        break;

    case '/echo':
        header('Content-Type: application/json');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (($_GET['format'] ?? '') === 'text') {
            // text/plain is never rewritten, so tests can see the exact headers the origin received.
            header('Content-Type: text/plain');
            foreach ($headers as $k => $v) {
                echo $k . ': ' . $v . "\n";
            }
            break;
        }
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'headers' => $headers,
            'body' => file_get_contents('php://input'),
        ], JSON_THROW_ON_ERROR);
        break;

    case '/big':
        header('Content-Type: application/octet-stream');
        header('Cache-Control: no-store');
        echo str_repeat('A', 200000);
        break;

    default:
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "not found\n";
}
