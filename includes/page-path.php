<?php
/**
 * Helpers for public page paths and their flat-file content identifiers.
 *
 * Public paths may contain multiple SEO-friendly segments, for example
 * `products/vitamin-d`. JSON files remain flat and use a reserved double
 * underscore between segments: `en_products__vitamin-d.json`.
 */

function nibblyPagePathSegmentPattern(): string {
    return '[a-z0-9]+(?:-[a-z0-9]+)*';
}

function nibblyPagePathPattern(): string {
    $segment = nibblyPagePathSegmentPattern();
    return $segment . '(?:/' . $segment . ')*';
}

function nibblyPageContentKeyPattern(): string {
    $segment = nibblyPagePathSegmentPattern();
    return '[a-z]{2}_' . $segment . '(?:__' . $segment . ')*';
}

function nibblyPageNormalizePath(string $path): string {
    $path = trim(strtolower($path));
    $path = trim($path, '/');
    if ($path === '' || !preg_match('#^' . nibblyPagePathPattern() . '$#', $path)) {
        return '';
    }
    return $path;
}

function nibblyPageIsValidPath(string $path): bool {
    $path = trim($path);
    return $path !== ''
        && $path === trim($path, '/')
        && $path === strtolower($path)
        && (bool)preg_match('#^' . nibblyPagePathPattern() . '$#', $path);
}

function nibblyPageContentKey(string $lang, string $path): string {
    $path = nibblyPageNormalizePath($path);
    if (!preg_match('/^[a-z]{2}$/', $lang) || $path === '') {
        return '';
    }
    return $lang . '_' . str_replace('/', '__', $path);
}

/** @return array{lang:string,path:string}|null */
function nibblyPageParseContentKey(string $contentPage): ?array {
    if (!preg_match('#^([a-z]{2})_(' . nibblyPagePathSegmentPattern() . '(?:__' . nibblyPagePathSegmentPattern() . ')*)$#', $contentPage, $matches)) {
        return null;
    }
    return [
        'lang' => $matches[1],
        'path' => str_replace('__', '/', $matches[2]),
    ];
}

function nibblyPageIsValidContentKey(string $contentPage): bool {
    return nibblyPageParseContentKey($contentPage) !== null;
}

function nibblyPageJsonPath(string $root, string $lang, string $path): string {
    $key = nibblyPageContentKey($lang, $path);
    return $key === '' ? '' : rtrim($root, '/\\') . '/content/pages/' . $key . '.json';
}

function nibblyPageTemplatePath(string $root, string $lang, string $path): string {
    $path = nibblyPageNormalizePath($path);
    if (!preg_match('/^[a-z]{2}$/', $lang) || $path === '') {
        return '';
    }
    return rtrim($root, '/\\') . '/' . $lang . '/' . $path . '.php';
}

/**
 * Relative browser path from a clean page URL back to the site root.
 */
function nibblyPageBasePath(string $path, bool $languagePrefixed): string {
    $path = nibblyPageNormalizePath($path);
    if ($path === '') return '';
    $urlSegments = substr_count($path, '/') + 1 + ($languagePrefixed ? 1 : 0);
    $requestPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $directoryUrl = str_ends_with($requestPath, '/');
    return str_repeat('../', max(0, $urlSegments - ($directoryUrl ? 0 : 1)));
}

function nibblyPageUrlPath(string $lang, string $path, ?string $defaultLang = null): string {
    $path = nibblyPageNormalizePath($path);
    if ($path === '') return '';
    $defaultLang = $defaultLang ?: (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
    if ($path === 'home') {
        return $lang === $defaultLang ? '/' : '/' . $lang . '/';
    }
    return $lang === $defaultLang ? '/' . $path : '/' . $lang . '/' . $path;
}
