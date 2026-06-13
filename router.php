<?php
/**
 * Router for PHP built-in development server.
 * Replicates .htaccess rewrite rules for local development.
 *
 * Usage: php -S localhost:3000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__;
$filePath = $root . $uri;


// Block access to sensitive paths BEFORE serving any files
if (preg_match('#^/(content|backups)/|-trash/#', $uri)) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}
if (preg_match('#/(config|smtp-config)\.php$#', $uri)) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

// Serve existing files directly (CSS, JS, images, etc.)
if ($uri !== '/' && is_file($filePath)) {
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

// Helper: load primary language from config
function _routerGetPrimaryLang() {
    global $root;
    if (defined('SITE_LANG_DEFAULT')) return SITE_LANG_DEFAULT;
    $configPath = $root . '/admin/config.php';
    if (is_file($configPath)) {
        require_once $configPath;
        if (defined('SITE_LANG_DEFAULT')) return SITE_LANG_DEFAULT;
    }
    return 'en';
}

$cleanUri = trim($uri, '/');

// News post URL: /en/news/slug or /news/slug
if (preg_match('#^([a-z]{2})/news/([a-z0-9-]+)$#', $cleanUri, $m)) {
    $currentLang = $m[1];
    $_GET['slug'] = $m[2];
    $basePath = '../../';
    include $root . '/includes/news-post.php';
    return true;
}
if (preg_match('#^news/([a-z0-9-]+)$#', $cleanUri, $m)) {
    $primaryLang = _routerGetPrimaryLang();
    $currentLang = $primaryLang;
    $_GET['slug'] = $m[1];
    $basePath = '../';
    include $root . '/includes/news-post.php';
    return true;
}

// Language-prefixed URL: /en/slug or /de/slug
if (preg_match('#^([a-z]{2})/([a-zA-Z0-9_-]+)$#', $cleanUri, $m)) {
    $lang = $m[1];
    $slug = $m[2];

    // 1. Physical PHP file has priority
    $langFile = $root . '/' . $lang . '/' . $slug . '.php';
    if (is_file($langFile)) {
        nibblyAccessEnforceCurrentTemplatePage($lang . '_' . $slug);
        include $langFile;
        return true;
    }

    // 2. JSON content file → front controller
    $jsonFile = $root . '/content/pages/' . $lang . '_' . $slug . '.json';
    if (is_file($jsonFile)) {
        $basePath = '../';
        include $root . '/includes/page.php';
        return true;
    }
}

// Root-level URL: /slug → primary language
if (preg_match('#^[a-zA-Z0-9_-]+$#', $cleanUri)) {
    $primaryLang = _routerGetPrimaryLang();
    $slug = $cleanUri;

    // 1. Physical PHP file has priority
    $langFile = $root . '/' . $primaryLang . '/' . $slug . '.php';
    if (is_file($langFile)) {
        $basePath = '';
        nibblyAccessEnforceCurrentTemplatePage($primaryLang . '_' . $slug);
        include $langFile;
        return true;
    }

    // 2. JSON content file → front controller
    $jsonFile = $root . '/content/pages/' . $primaryLang . '_' . $slug . '.json';
    if (is_file($jsonFile)) {
        $lang = $primaryLang;
        $basePath = '';
        include $root . '/includes/page.php';
        return true;
    }
}

// 404 fallback
http_response_code(404);
include $root . '/404.php';
return true;
