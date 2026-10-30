<?php
/**
 * RSS 2.0 feed of the news posts.
 *
 * Settings (content/settings.json → "news"):
 *   feed        false switches the feed off (default true).
 *   feedItems   Number of posts in the feed (default 20, 1–100).
 *
 * URL: /feed.xml (primary language) and /{lang}/feed.xml; route.php calls nibblyNewsServeFeed().
 * Items carry title, link (nibblyNewsUrl()), guid, pubDate, description (excerpt, else the first
 * paragraphs of the text), categories (names) and an enclosure for the post image.
 * includes/header.php links the feed from the document head (nibblyNewsFeedHeadLink()).
 */

require_once __DIR__ . '/news-helper.php';
require_once __DIR__ . '/seo-helper.php';

function nibblyNewsFeedSettings(): array {
    $root = dirname(__DIR__);
    $settings = is_file($root . '/content/settings.json') ? json_decode((string)file_get_contents($root . '/content/settings.json'), true) : [];
    $news = is_array($settings['news'] ?? null) ? $settings['news'] : [];
    $modules = is_array($settings['modules'] ?? null) ? $settings['modules'] : [];
    $enabled = (!array_key_exists('news', $modules) || !empty($modules['news'])) && (!array_key_exists('feed', $news) || !empty($news['feed']));
    $items = is_numeric($news['feedItems'] ?? null) ? max(1, min(100, (int)$news['feedItems'])) : 20;
    return ['enabled' => $enabled, 'items' => $items, 'siteName' => (string)($settings['branding']['name'] ?? (defined('SITE_NAME') ? SITE_NAME : ''))];
}

function nibblyNewsFeedEnabled(): bool {
    return nibblyNewsFeedSettings()['enabled'];
}

/** Root-absolute feed path (/feed.xml, /en/feed.xml). */
function nibblyNewsFeedUrl(?string $lang = null): string {
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = $lang ?? $defaultLang;
    return (($lang !== $defaultLang && preg_match('/^[a-z]{2}$/', $lang)) ? '/' . $lang : '') . '/feed.xml';
}

/** Does a clean URI address the feed? Returns ['lang' => code] or null. */
function nibblyNewsFeedMatchUrl(string $cleanUri): ?array {
    if (!nibblyNewsFeedEnabled()) return null;
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $languages = is_array($GLOBALS['SITE_LANGUAGES'] ?? null) ? $GLOBALS['SITE_LANGUAGES'] : [$defaultLang => ''];
    if ($cleanUri === 'feed.xml') return ['lang' => $defaultLang];
    if (preg_match('#^([a-z]{2})/feed\.xml$#', $cleanUri, $m) && isset($languages[$m[1]]) && $m[1] !== $defaultLang) return ['lang' => $m[1]];
    return null;
}

/** <link rel="alternate" type="application/rss+xml"> for the document head, or '' when the feed is off. */
function nibblyNewsFeedHeadLink(?string $lang = null, string $basePath = ''): string {
    if (!nibblyNewsFeedEnabled()) return '';
    require_once __DIR__ . '/public-strings.php';
    $href = $basePath === '' ? nibblyNewsFeedUrl($lang) : $basePath . ltrim(nibblyNewsFeedUrl($lang), '/');
    $settings = nibblyNewsFeedSettings();
    $title = trim($settings['siteName'] . ' – ' . nibblyPublicString('feed_title', $lang), ' –');
    return '<link rel="alternate" type="application/rss+xml" title="' . htmlspecialchars($title) . '" href="' . htmlspecialchars($href) . '">' . "\n";
}

/** The feed document as a string. */
function nibblyNewsFeedXml(?string $lang = null): string {
    require_once __DIR__ . '/content-loader.php';
    require_once __DIR__ . '/public-strings.php';
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = $lang ?? $defaultLang;
    $settings = nibblyNewsFeedSettings();
    $base = rtrim(nibblySeoBaseUrl(), '/');
    $posts = loadNewsPosts($settings['items'], $lang);
    $x = static fn(string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $absolute = static function (string $path) use ($base): string {
        if ($path === '' || preg_match('#^https?://#i', $path)) return $path;
        return $base . '/' . ltrim($path, '/');
    };
    $title = trim($settings['siteName'] . ' – ' . nibblyPublicString('feed_title', $lang), ' –');
    $lastBuild = $posts ? (string)($posts[0]['lastModified'] ?? $posts[0]['date'] ?? '') : '';
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n<channel>\n";
    $xml .= '  <title>' . $x($title) . "</title>\n";
    $xml .= '  <link>' . $x($base . nibblyNewsListUrl($lang)) . "</link>\n";
    $xml .= '  <atom:link href="' . $x($base . nibblyNewsFeedUrl($lang)) . '" rel="self" type="application/rss+xml"/>' . "\n";
    $xml .= '  <description>' . $x(nibblyPublicString('feed_title', $lang)) . "</description>\n";
    $xml .= '  <language>' . $x($lang) . "</language>\n";
    $xml .= '  <generator>nibbly</generator>' . "\n";
    if ($lastBuild !== '') $xml .= '  <lastBuildDate>' . $x(date(DATE_RSS, strtotime($lastBuild) ?: time())) . "</lastBuildDate>\n";
    foreach ($posts as $post) {
        $url = $base . nibblyNewsUrl($post, $lang);
        // Plain text: the excerpt (imports may carry inline markup), else the start of the body
        $plain = static fn(string $html): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        $description = $plain((string)($post['excerpt'] ?? ''));
        if ($description === '') {
            $description = mb_substr($plain(is_string($post['content'] ?? null) ? $post['content'] : ''), 0, 400);
        }
        $xml .= "  <item>\n";
        $xml .= '    <title>' . $x((string)($post['title'] ?? '')) . "</title>\n";
        $xml .= '    <link>' . $x($url) . "</link>\n";
        $xml .= '    <guid isPermaLink="true">' . $x($url) . "</guid>\n";
        if (!empty($post['date'])) $xml .= '    <pubDate>' . $x(date(DATE_RSS, strtotime((string)$post['date'] . ' 12:00:00') ?: time())) . "</pubDate>\n";
        if ($description !== '') $xml .= '    <description>' . $x($description) . "</description>\n";
        foreach (nibblyNewsSlugList($post['categories'] ?? []) as $category) {
            $xml .= '    <category>' . $x(nibblyNewsCategoryName($category)) . "</category>\n";
        }
        $image = (string)($post['image'] ?? '');
        if ($image !== '') {
            $local = nibblySeoLocalAssetPath($image);
            if ($local === '' && str_starts_with($image, '/') && !str_contains($image, '..')) $local = dirname(__DIR__) . $image;
            $size = is_file($local) ? (int)filesize($local) : 0;
            $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
            $type = $types[strtolower(pathinfo(parse_url($image, PHP_URL_PATH) ?: $image, PATHINFO_EXTENSION))] ?? '';
            if ($type !== '') $xml .= '    <enclosure url="' . $x($absolute($image)) . '" length="' . $size . '" type="' . $type . '"/>' . "\n";
        }
        $xml .= "  </item>\n";
    }
    $xml .= "</channel>\n</rss>\n";
    return $xml;
}

/** Send the feed and exit. */
function nibblyNewsServeFeed(?string $lang = null): void {
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo nibblyNewsFeedXml($lang);
    exit;
}
