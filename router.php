<?php
/**
 * Router for PHP built-in development server.
 * Replicates .htaccess rewrite rules for local development.
 *
 * Usage: php -S localhost:3000 router.php
 */

$uri = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (preg_match('/[\x00-\x1f\x7f\\\\]/', $uri) || preg_match('#(^|/)\.\.?(/|$)#', $uri)) {
    http_response_code(403);
    return true;
}
$root = __DIR__;
$filePath = $root . $uri;

require_once $root . '/includes/page-path.php';

/**
 * PHP's built-in server does not reliably honor byte-range requests for media.
 * Scroll-scrubbed videos need ranges so the browser can seek to an arbitrary
 * frame without downloading the entire file first.
 */
function _routerServeSeekableMedia(string $filePath): bool {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
    ];
    if (!isset($mimeTypes[$extension])) return false;

    $size = filesize($filePath);
    if ($size === false || $size < 1) return false;

    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));

    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            return true;
        }

        if ($matches[1] === '' && $matches[2] !== '') {
            $suffixLength = min((int)$matches[2], $size);
            $start = $size - $suffixLength;
        } else {
            $start = (int)$matches[1];
            if ($matches[2] !== '') $end = min((int)$matches[2], $end);
        }

        if ($start < 0 || $start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            return true;
        }
        $status = 206;
    }

    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: ' . $mimeTypes[$extension]);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    if ($status === 206) header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return true;

    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        http_response_code(500);
        return true;
    }
    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    return true;
}


// Block access to sensitive paths BEFORE serving any files
if (preg_match('#^/(content|backups|tests|cli)(/|$)|-trash(/|$)|(^|/)\.(?!well-known(?:/|$))#i', $uri)) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}
if (preg_match('#/(config|smtp-config)\.php$#i', $uri)) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

// Serve existing files directly (CSS, JS, images, etc.)
if ($uri !== '/' && is_file($filePath)) {
    if (_routerServeSeekableMedia($filePath)) return true;
    return false;
}

if ($uri === '/sitemap.xml' || $uri === '/robots.txt') {
    if (!is_file($root . '/admin/config.php')) {
        http_response_code(404);
        return true;
    }
    require_once $root . '/admin/config.php';
    require_once $root . '/includes/seo-helper.php';
    if ($uri === '/sitemap.xml') {
        nibblySeoServeSitemap();
    }
    nibblySeoServeRobots();
}

// Apply migration redirects before slug-based routing.
require_once $root . '/includes/redirect-helper.php';
applyRedirects($_SERVER['REQUEST_URI'] ?? '/');

require_once $root . '/includes/access-guard.php';
nibblyAccessEnforceMaintenance();

// Serve existing directories with index.php
if (is_dir($filePath)) {
    $index = rtrim($filePath, '/') . '/index.php';
    if (is_file($index)) {
        include $index;
        return true;
    }
    // Don't return false yet — a directory name might collide with a
    // primary-language page slug (e.g. /docs dir vs en/docs.php).
    // Fall through to the slug-based routing below.
}

// Clean URLs: try appending .php
$phpFile = $filePath . '.php';
if (is_file($phpFile)) {
    include $phpFile;
    return true;
}

// Share dynamic routing with Apache so language and page behavior stays identical.
require $root . '/route.php';
return true;
