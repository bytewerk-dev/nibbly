<?php
/**
 * Front controller for Apache (production).
 *
 * .htaccess sends all non-static requests here. This file handles:
 * - Clean URLs (strip .php extension)
 * - Primary language root access (/about → /en/about)
 * - Language-prefixed pages (/de/beispiel)
 * - News post URLs following settings news.urlPattern (/news/slug, /2026/08/17/slug, /en/news/slug)
 * - News archive pages (/news/{category}, /news/tag/{tag}, /news/year/2001, /news/decade/1990)
 * - RSS feed (/feed.xml) and the search page (settings search.base, default /search)
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

// RSS feed of the posts: /feed.xml, /{lang}/feed.xml (settings news.feed, default on)
require_once __DIR__ . '/includes/feed.php';
$feedRoute = nibblyNewsFeedMatchUrl($cleanUri);
if ($feedRoute !== null) {
    nibblyNewsServeFeed($feedRoute['lang']);
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
// News post URLs follow settings news.urlPattern (default /news/{slug}; e.g. /{yyyy}/{mm}/{dd}/{slug}),
// with the language prefix for non-primary languages. The slug decides: a wrong date part, an old
// slug (content/news-aliases.json) or a trailing slash redirects (301) to the canonical URL.
// A matching pattern without a post falls through, so pages at the same path still work.
// ------------------------------------------------------------------
require_once __DIR__ . '/includes/content-loader.php';
$newsRoute = nibblyNewsRoute($cleanUri, (string)$uri);
if ($newsRoute !== null) {
    if (isset($newsRoute['redirect'])) {
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        header('Location: ' . $newsRoute['redirect'] . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
    $currentLang = $newsRoute['lang'];
    $post = $newsRoute['post'];
    $_GET['slug'] = $post['slug'];
    $basePath = nibblyPageBasePath(ltrim(nibblyNewsUrl($post, $currentLang), '/'), false);
    nibblyAnalyticsScheduleTrack(null, $currentLang, 'news');
    // A site template {lang}/news-post.php replaces the core post page (it receives $post, $currentLang, $basePath)
    $siteTemplate = __DIR__ . '/' . $currentLang . '/news-post.php';
    include is_file($siteTemplate) ? $siteTemplate : __DIR__ . '/includes/news-post.php';
    exit;
}

// ------------------------------------------------------------------
// News archive pages below settings news.archive.base: /{base}/{category}, /{base}/{tag}/{slug},
// /{base}/{year}/2001, /{base}/{decade}/1990. A site template {lang}/news-archive.php replaces
// the core page includes/news-archive.php. The base itself (/news, /blog) stays a normal page
// when a template or JSON page exists there; otherwise it lists every post.
// ------------------------------------------------------------------
$newsArchive = nibblyNewsMatchArchiveUrl($cleanUri);
if ($newsArchive !== null) {
    $archiveLang = $newsArchive['lang'];
    $archivePath = ltrim(nibblyNewsListUrl($archiveLang), '/');
    $archiveHasPage = $newsArchive['type'] === 'list' && (
        is_file(nibblyPageTemplatePath(__DIR__, $archiveLang, substr($archivePath, $archiveLang === $primaryLang ? 0 : 3)))
        || is_file(nibblyPageJsonPath(__DIR__, $archiveLang, substr($archivePath, $archiveLang === $primaryLang ? 0 : 3))));
    if (!$archiveHasPage) {
        if (str_ends_with((string)$uri, '/') && $cleanUri !== '') {
            $query = (string)($_SERVER['QUERY_STRING'] ?? '');
            header('Location: /' . $cleanUri . ($query !== '' ? '?' . $query : ''), true, 301);
            exit;
        }
        $currentLang = $archiveLang;
        $basePath = nibblyPageBasePath($cleanUri, false);
        nibblyAnalyticsScheduleTrack(null, $currentLang, 'news');
        $siteTemplate = __DIR__ . '/' . $currentLang . '/news-archive.php';
        include is_file($siteTemplate) ? $siteTemplate : __DIR__ . '/includes/news-archive.php';
        exit;
    }
}

// ------------------------------------------------------------------
// Search page at settings search.base (/search, /{lang}/search) unless a template or JSON page
// lives there. A site template {lang}/search.php replaces the core page includes/search.php.
// ------------------------------------------------------------------
require_once __DIR__ . '/includes/search-helper.php';
$searchRoute = nibblySearchMatchUrl($cleanUri);
if ($searchRoute !== null) {
    $searchLang = $searchRoute['lang'];
    $searchPath = trim(nibblySearchSettings()['base'], '/');
    $searchHasPage = is_file(nibblyPageTemplatePath(__DIR__, $searchLang, $searchPath)) || is_file(nibblyPageJsonPath(__DIR__, $searchLang, $searchPath));
    if (!$searchHasPage) {
        if (str_ends_with((string)$uri, '/')) {
            $query = (string)($_SERVER['QUERY_STRING'] ?? '');
            header('Location: /' . $cleanUri . ($query !== '' ? '?' . $query : ''), true, 301);
            exit;
        }
        $currentLang = $searchLang;
        $basePath = nibblyPageBasePath($cleanUri, false);
        nibblyAnalyticsScheduleTrack(null, $currentLang, 'search');
        $siteTemplate = __DIR__ . '/' . $currentLang . '/search.php';
        include is_file($siteTemplate) ? $siteTemplate : __DIR__ . '/includes/search.php';
        exit;
    }
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
