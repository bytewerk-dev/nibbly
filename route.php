<?php
/**
 * Front controller for Apache (production).
 *
 * .htaccess sends all non-static requests here. This file handles:
 * - Clean URLs (strip .php extension)
 * - Primary language root access (/about → /en/about)
 * - Language-prefixed pages (/de/beispiel)
 * - News post URLs (/news/slug, /en/news/slug)
 * - Event and person pages (/events/id, /people/slug, with language prefix)
 * - JSON-based standard pages (via includes/page.php)
 *
 * Language detection uses SITE_LANG_DEFAULT from admin/config.php,
 * so .htaccess never needs to be edited for language changes.
 */

// Keep custom front-controller integrations on the same URL contract.
require_once __DIR__ . '/includes/routing-helper.php';
nibblyRoutingCanonicalize(__DIR__);

// Load config
$configPath = __DIR__ . '/admin/config.php';
if (!file_exists($configPath)) {
    header('Location: /admin/setup.php');
    exit;
}
require_once $configPath;

require_once __DIR__ . '/includes/page-path.php';
require_once __DIR__ . '/includes/access-guard.php';
nibblyAccessEnforceMaintenance();
// Owner-only preview of an AI change set: its new pages exist only as overlay, so the router must know them.
if (isset($_GET['nb_agent_preview']) && is_file(__DIR__ . '/includes/ai/agent/preview.php')) {
    require_once __DIR__ . '/includes/ai/agent/preview.php';
    nibblyAgentPreviewBoot();
}
$nibblyPreviewHas = static fn(string $key): bool => function_exists('nibblyContentOverlayFor') && nibblyContentOverlayFor($key) !== null;
require_once __DIR__ . '/includes/seo-helper.php';
// Visits are counted in includes/header.php; the router schedules a fallback
// for templates whose header does not call the tracker (see analytics-helper.php).
require_once __DIR__ . '/includes/analytics-helper.php';

// Apply migration redirects before any other routing — a redirected URL
// must never accidentally match a real page slug downstream.
require_once __DIR__ . '/includes/redirect-helper.php';
applyRedirects($_SERVER['REQUEST_URI'] ?? '/');

$primaryLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';

// Parse the clean URI
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$cleanUri = trim($uri, '/');

if ($cleanUri === 'sitemap.xml') {
    nibblySeoServeSitemap();
}
if ($cleanUri === 'robots.txt') {
    nibblySeoServeRobots();
}

// A language homepage can also be backed only by JSON.
if (preg_match('/^[a-z]{2}$/D', $cleanUri) && isset($SITE_LANGUAGES[$cleanUri])) {
    $lang = $cleanUri;
    $slug = 'home';
    $basePath = str_ends_with((string)$uri, '/') ? '../' : '';
    $langHome = __DIR__ . '/' . $lang . '/index.php';
    if (is_file($langHome)) {
        nibblyAccessEnforceCurrentTemplatePage($lang . '_home');
        nibblyAnalyticsScheduleTrack($lang . '_home', $lang, 'home');
        include $langHome;
        exit;
    }
    if (is_file(nibblyPageJsonPath(__DIR__, $lang, $slug)) || $nibblyPreviewHas(nibblyPageContentKey($lang, $slug))) {
        nibblyAnalyticsScheduleTrack(nibblyPageContentKey($lang, $slug), $lang, $slug);
        include __DIR__ . '/includes/page.php';
        exit;
    }
}

// Root URL → homepage
if ($cleanUri === '') {
    $basePath = '';
    $langHome = __DIR__ . '/' . $primaryLang . '/index.php';
    $jsonHome = __DIR__ . '/content/pages/' . $primaryLang . '_home.json';

    if (is_file($langHome)) {
        nibblyAccessEnforceCurrentTemplatePage($primaryLang . '_home');
        nibblyAnalyticsScheduleTrack($primaryLang . '_home', $primaryLang, 'home');
        include $langHome;
    } elseif (is_file($jsonHome)) {
        $lang = $primaryLang;
        $slug = 'home';
        nibblyAnalyticsScheduleTrack($primaryLang . '_home', $primaryLang, 'home');
        include __DIR__ . '/includes/page.php';
    } else {
        echo '<p>No homepage found. Run the <a href="admin/setup.php">setup wizard</a> to get started.</p>';
    }
    exit;
}

// ------------------------------------------------------------------
// News post URLs: /{lang}/news/{slug} or /news/{slug}
// ------------------------------------------------------------------
if (preg_match('#^([a-z]{2})/news/([a-z0-9-]+)$#', $cleanUri, $m)) {
    $currentLang = $m[1];
    $_GET['slug'] = $m[2];
    $basePath = nibblyPageBasePath('news/' . $m[2], true);
    nibblyAnalyticsScheduleTrack(null, $currentLang, 'news');
    include __DIR__ . '/includes/news-post.php';
    exit;
}
if (preg_match('#^news/([a-z0-9-]+)$#', $cleanUri, $m)) {
    $currentLang = $primaryLang;
    $_GET['slug'] = $m[1];
    $basePath = nibblyPageBasePath('news/' . $m[1], false);
    nibblyAnalyticsScheduleTrack(null, $currentLang, 'news');
    include __DIR__ . '/includes/news-post.php';
    exit;
}

// ------------------------------------------------------------------
// Event and person pages: /{lang}/events/{id}, /events/{id}, /{lang}/people/{slug}, /people/{slug}.
// Only when the event or published profile exists; otherwise a page at that path still wins.
// ------------------------------------------------------------------
if (preg_match('#^(?:([a-z]{2})/)?(events|people)/([a-z0-9-]+)$#', $cleanUri, $m) && ($m[1] === '' || isset($SITE_LANGUAGES[$m[1]]))) {
    $currentLang = $m[1] !== '' ? $m[1] : $primaryLang;
    if ($m[2] === 'events') {
        include_once __DIR__ . '/includes/content-loader.php';
        $event = nibblyFindEvent($m[3]);
    } else {
        require_once __DIR__ . '/includes/people.php';
        $person = nibblyPersonBySlug($m[3]);
    }
    if (!empty($event) || !empty($person)) {
        $basePath = nibblyPageBasePath($m[2] . '/' . $m[3], $m[1] !== '');
        nibblyAnalyticsScheduleTrack(null, $currentLang, $m[2]);
        include __DIR__ . '/includes/' . ($m[2] === 'events' ? 'event-page.php' : 'person-page.php');
        exit;
    }
}

// ------------------------------------------------------------------
// Language-prefixed URL: /{lang}/{path}
// ------------------------------------------------------------------
if (preg_match('#^([a-z]{2})/(' . nibblyPagePathPattern() . ')$#', $cleanUri, $m)) {
    $lang = $m[1];
    $slug = $m[2];
    $contentPage = nibblyPageContentKey($lang, $slug);

    // 1. Physical PHP file
    $phpFile = nibblyPageTemplatePath(__DIR__, $lang, $slug);
    if (is_file($phpFile)) {
        $basePath = nibblyPageBasePath($slug, true);
        nibblyAccessEnforceCurrentTemplatePage($contentPage);
        nibblyAnalyticsScheduleTrack($contentPage, $lang, $slug);
        include $phpFile;
        exit;
    }

    // 2. JSON content → front controller
    $jsonFile = nibblyPageJsonPath(__DIR__, $lang, $slug);
    if (is_file($jsonFile) || $nibblyPreviewHas($contentPage)) {
        $basePath = nibblyPageBasePath($slug, true);
        nibblyAnalyticsScheduleTrack($contentPage, $lang, $slug);
        include __DIR__ . '/includes/page.php';
        exit;
    }
}

// ------------------------------------------------------------------
// Root-level path: /{path} → primary language
// ------------------------------------------------------------------
if (preg_match('#^' . nibblyPagePathPattern() . '$#', $cleanUri)) {
    $lang = $primaryLang;
    $slug = $cleanUri;
    $contentPage = nibblyPageContentKey($lang, $slug);

    // 1. Physical PHP file (with .php extension)
    $phpFile = nibblyPageTemplatePath(__DIR__, $lang, $slug);
    if (is_file($phpFile)) {
        $basePath = nibblyPageBasePath($slug, false);
        nibblyAccessEnforceCurrentTemplatePage($contentPage);
        nibblyAnalyticsScheduleTrack($contentPage, $lang, $slug);
        include $phpFile;
        exit;
    }

    // 2. JSON content → front controller
    $jsonFile = nibblyPageJsonPath(__DIR__, $lang, $slug);
    if (is_file($jsonFile) || $nibblyPreviewHas($contentPage)) {
        $basePath = nibblyPageBasePath($slug, false);
        nibblyAnalyticsScheduleTrack($contentPage, $lang, $slug);
        include __DIR__ . '/includes/page.php';
        exit;
    }
}

// ------------------------------------------------------------------
// Nothing matched → 404
// ------------------------------------------------------------------
http_response_code(404);
include __DIR__ . '/404.php';
