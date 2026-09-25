<?php
/** Route-specific slash handling, mirrored by the root .htaccess rules. */
function nibblyRoutingCanonicalTarget(string $path, string $root): ?string {
    // Only local, unambiguous paths may become a Location header or filesystem lookup.
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')
        || preg_match('/[\x00-\x20\x7f\\\\?#]/', $path)
        || preg_match('#(^|/)\.\.?(/|$)#', $path)) return null;
    $clean = rtrim($path, '/');
    if ($clean === '') return null;
    if ($clean === '/login') return '/admin/';
    $file = rtrim($root, '/') . $clean;
    // A PHP endpoint wins over a same-named implementation directory. This also
    // covers future collisions, without adding a hardcoded rule for every module.
    if (is_file($file . '.php') && (is_dir($file) || str_ends_with($path, '/'))) {
        return $clean . '.php';
    }
    if (!str_ends_with($path, '/') && is_dir($file) && is_file($file . '/index.php')) {
        return $clean . '/';
    }
    // JSON-backed content routes retain their own slash convention and basePath.
    return null;
}

function nibblyRoutingCanonicalize(string $root): void {
    $path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $target = nibblyRoutingCanonicalTarget($path, $root);
    if ($target === null) return;
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . $target . ($query !== '' ? '?' . $query : ''), true, 308);
    exit;
}
