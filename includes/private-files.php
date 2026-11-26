<?php
/** Validate the private path on a recovery host even before its files have been provisioned. */
function nibblyPrivateDirectory(): ?string {
    $setting = defined('NIBBLY_PRIVATE_DIR') ? NIBBLY_PRIVATE_DIR : getenv('NIBBLY_PRIVATE_DIR');
    if ($setting === false || $setting === '') return null;
    $path = is_string($setting) ? str_replace(DIRECTORY_SEPARATOR, '/', $setting) : '';
    $absolute = str_starts_with($path, '/') || (DIRECTORY_SEPARATOR === '\\' && preg_match('~^[a-z]:/~i', $path));
    if (!$absolute || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
        throw new RuntimeException('NIBBLY_PRIVATE_DIR must name an absolute directory.');
    }

    $parent = $path;
    $tail = '';
    while (!file_exists($parent) && !is_link($parent)) {
        $tail = '/' . basename($parent) . $tail;
        $next = dirname($parent);
        if ($next === $parent) throw new RuntimeException('Invalid NIBBLY_PRIVATE_DIR.');
        $parent = $next;
    }
    $base = realpath($parent);
    if ($base === false || !is_dir($base)) throw new RuntimeException('Invalid NIBBLY_PRIVATE_DIR.');
    $dir = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $base), '/') . $tail;

    // Check the supplied name as well as the resolved destination: a symlink inside
    // a document root can still expose a private directory outside it over HTTP.
    $normalize = static function (string $value): string {
        $value = rtrim(preg_replace('~/+~', '/', str_replace(DIRECTORY_SEPARATOR, '/', $value)), '/') . '/';
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
    };
    $roots = [];
    foreach ([dirname(__DIR__), $_SERVER['DOCUMENT_ROOT'] ?? ''] as $webroot) {
        if ($webroot !== '' && is_dir($webroot)) $roots[] = $normalize(realpath($webroot));
    }
    foreach (array_unique($roots) as $root) {
        $private = $normalize($dir);
        if (str_starts_with($root, $private) || str_starts_with($private, $root)) {
            throw new RuntimeException('Use a separate private directory outside the webroot and the installation.');
        }
        // Check every existing parent: temporary and hosting directories themselves
        // often have aliases, so the literal prefix need not match the canonical root.
        for ($part = $path; $part !== dirname($part); $part = dirname($part)) {
            $resolved = realpath($part);
            if ($resolved !== false && str_starts_with($normalize($resolved), $root)) {
                throw new RuntimeException('The path to private storage must not pass through the webroot.');
            }
        }
    }
    return $dir;
}
