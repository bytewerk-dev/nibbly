<?php
// Loopback fixture for tests/agent-net-smoke.php (php -S, started from a disposable copy).
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$port = (int)($_SERVER['SERVER_PORT'] ?? 0);

if ($path === '/ok') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>ok</p>';
} elseif ($path === '/host') {
    header('Content-Type: text/plain');
    echo $_SERVER['HTTP_HOST'] ?? '';
} elseif ($path === '/cookie') {
    header('Content-Type: text/plain');
    header('Set-Cookie: probe=1');
    echo $_SERVER['HTTP_COOKIE'] ?? 'none';
} elseif ($path === '/pdf') {
    header('Content-Type: application/pdf');
    echo '%PDF-1.4 fixture';
} elseif ($path === '/no-type') {
    ini_set('default_mimetype', '');
    header_remove('Content-Type');
    echo 'no type';
} elseif ($path === '/big-length') {
    header('Content-Type: text/html');
    header('Content-Length: ' . (3 * 1024 * 1024));
    echo str_repeat('a', 3 * 1024 * 1024);
} elseif ($path === '/big-stream') {
    // No Content-Length: the limit has to hold while the body streams.
    header('Content-Type: text/html');
    for ($i = 0; $i < 48; $i++) {
        echo str_repeat('b', 64 * 1024);
        flush();
    }
} elseif ($path === '/image') {
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
} elseif ($path === '/gone') {
    http_response_code(404);
    header('Content-Type: text/html');
    echo str_repeat('x', 3 * 1024 * 1024);
} elseif ($path === '/redirect-ok') {
    header('Location: /ok', true, 302);
} elseif ($path === '/redirect-absolute') {
    header('Location: http://public.test:' . $port . '/ok', true, 301);
} elseif ($path === '/redirect-loop') {
    header('Location: /redirect-loop', true, 302);
} elseif (preg_match('#^/chain/(\d+)$#', $path, $match)) {
    $left = (int)$match[1];
    header('Location: ' . ($left > 0 ? '/chain/' . ($left - 1) : '/ok'), true, 302);
} elseif ($path === '/redirect-loopback') {
    header('Location: http://127.0.0.1:' . $port . '/ok', true, 302);
} elseif ($path === '/redirect-metadata') {
    header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
} elseif ($path === '/redirect-ipv6') {
    header('Location: http://[::ffff:7f00:1]/', true, 302);
} elseif ($path === '/redirect-internal-name') {
    header('Location: http://internal.test:' . $port . '/ok', true, 302);
} elseif ($path === '/redirect-scheme') {
    header('Location: ftp://public.test/secret', true, 302);
} else {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'not found';
}
