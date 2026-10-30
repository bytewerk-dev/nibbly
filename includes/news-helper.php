<?php
/**
 * News helper: post URLs, slug routing, categories, tags, archive filters and pagination.
 *
 * Settings (content/settings.json → "news"):
 *   urlPattern   Public post URL, placeholders {yyyy} {mm} {dd} {slug}. Default "/news/{slug}".
 *                Posts of a non-primary language get the language prefix in front of it.
 *   perPage      Posts per list page (default 12).
 *   pageParam    Query parameter of the list page (default "page").
 *   archive      Archive pages: base path and segment names, e.g.
 *                { "base": "/news", "tag": "tag", "year": "year", "decade": "decade" }.
 *                "/{base}/{category}", "/{base}/{tag}/{slug}", "/{base}/{year}/2001", "/{base}/{decade}/1990".
 *
 * Files:
 *   content/news/*.json             posts; found by their "slug" field, never by file name
 *   content/news-categories.json    { "categories": [ { "slug", "name", "description", "order" } ] }
 *   content/news-aliases.json       { "old-slug": "current-slug" } (old slugs redirect, 301)
 *
 * The functions here are the only place that knows the URL pattern: templates, sitemap,
 * dashboard and editor call nibblyNewsUrl() instead of building "/news/{slug}" themselves.
 */

require_once __DIR__ . '/page-path.php';

function nibblyNewsRoot(): string {
    return dirname(__DIR__);
}

function nibblyNewsSlugPattern(): string {
    return '[a-z0-9]+(?:-[a-z0-9]+)*';
}

/** The "news" section of settings.json with defaults applied. */
function nibblyNewsSettings(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $defaults = nibblyNewsSettingsDefaults();
    $path = nibblyNewsRoot() . '/content/settings.json';
    $settings = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $news = is_array($settings['news'] ?? null) ? $settings['news'] : [];
    $merged = array_replace_recursive($defaults, $news);
    $merged['urlPattern'] = nibblyNewsNormalizeUrlPattern((string)$merged['urlPattern']) ?? $defaults['urlPattern'];
    $merged['perPage'] = max(1, min(200, (int)$merged['perPage']));
    $merged['pageParam'] = preg_match('/^[a-z][a-z0-9_-]{0,30}$/', (string)$merged['pageParam']) ? (string)$merged['pageParam'] : $defaults['pageParam'];
    $archive = is_array($merged['archive'] ?? null) ? $merged['archive'] : [];
    $base = '/' . trim(preg_replace('#[^a-z0-9/-]#', '', strtolower((string)($archive['base'] ?? ''))), '/');
    $merged['archive']['base'] = $base === '/' ? '' : $base;
    foreach (['tag', 'year', 'decade'] as $key) {
        $value = strtolower(trim((string)($archive[$key] ?? '')));
        $merged['archive'][$key] = preg_match('/^' . nibblyNewsSlugPattern() . '$/', $value) ? $value : $defaults['archive'][$key];
    }
    return $cache = $merged;
}

function nibblyNewsSettingsDefaults(): array {
    return [
        'urlPattern' => '/news/{slug}',
        'perPage' => 12,
        'pageParam' => 'page',
        'archive' => ['base' => '/news', 'tag' => 'tag', 'year' => 'year', 'decade' => 'decade'],
    ];
}

/**
 * A valid pattern: root-absolute, segments of literals or the placeholders {yyyy} {mm} {dd} {slug},
 * {slug} exactly once and as its own segment. Returns null for anything else.
 */
function nibblyNewsNormalizeUrlPattern(string $pattern): ?string {
    $pattern = '/' . trim(strtolower(trim($pattern)), '/');
    if ($pattern === '/' || substr_count($pattern, '{slug}') !== 1) return null;
    foreach (explode('/', ltrim($pattern, '/')) as $segment) {
        if (in_array($segment, ['{yyyy}', '{mm}', '{dd}', '{slug}'], true)) continue;
        if (!preg_match('/^' . nibblyNewsSlugPattern() . '$/', $segment)) return null;
    }
    return $pattern;
}

/** Public URL path of a post (root-absolute, without trailing slash), e.g. /2026/08/17/my-post. */
function nibblyNewsUrl(array $post, ?string $lang = null): string {
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = $lang ?? (string)($post['lang'] ?? $defaultLang);
    $slug = (string)($post['slug'] ?? $post['id'] ?? '');
    $date = (string)($post['date'] ?? '');
    $parts = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) ? $m : ['', '0000', '00', '00'];
    $path = str_replace(['{yyyy}', '{mm}', '{dd}', '{slug}'], [$parts[1], $parts[2], $parts[3], $slug], nibblyNewsSettings()['urlPattern']);
    return ($lang !== $defaultLang && preg_match('/^[a-z]{2}$/', $lang) ? '/' . $lang : '') . $path;
}

/** Path of the post listing in a language: the archive base, or /news when none is configured. */
function nibblyNewsListUrl(?string $lang = null): string {
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = $lang ?? $defaultLang;
    $base = nibblyNewsSettings()['archive']['base'] ?: '/news';
    return ($lang !== $defaultLang && preg_match('/^[a-z]{2}$/', $lang) ? '/' . $lang : '') . $base;
}

/** Archive page path: type "category" (value = category slug), "tag", "year" or "decade". */
function nibblyNewsArchiveUrl(string $type, string $value, ?string $lang = null): string {
    $settings = nibblyNewsSettings();
    $base = nibblyNewsListUrl($lang);
    switch ($type) {
        case 'category': return $base . '/' . $value;
        case 'tag': return $base . '/' . $settings['archive']['tag'] . '/' . $value;
        case 'year': return $base . '/' . $settings['archive']['year'] . '/' . $value;
        case 'decade': return $base . '/' . $settings['archive']['decade'] . '/' . $value;
    }
    return $base;
}

/**
 * Regex (without delimiters) for the pattern; named groups yyyy, mm, dd, slug where present.
 * Matches the clean URI without language prefix and without surrounding slashes.
 */
function nibblyNewsUrlRegex(?string $pattern = null): string {
    $pattern = $pattern ?? nibblyNewsSettings()['urlPattern'];
    $regex = '';
    foreach (explode('/', ltrim($pattern, '/')) as $segment) {
        $regex .= ($regex === '' ? '' : '/');
        $regex .= match ($segment) {
            '{yyyy}' => '(?P<yyyy>\d{4})',
            '{mm}' => '(?P<mm>\d{2})',
            '{dd}' => '(?P<dd>\d{2})',
            '{slug}' => '(?P<slug>' . nibblyNewsSlugPattern() . ')',
            default => preg_quote($segment, '#'),
        };
    }
    return $regex;
}

/**
 * Does a clean URI (no surrounding slashes) address a post? Returns
 * ['lang' => code, 'slug' => slug, 'date' => 'yyyy-mm-dd' | null, 'parts' => ['yyyy' => .., 'mm' => .., 'dd' => ..]]
 * or null. "date" is complete only when the pattern carries all three parts; "parts" holds what the
 * URL says, and nibblyNewsRoute() compares exactly those with the post's date.
 */
function nibblyNewsMatchUrl(string $cleanUri): ?array {
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $languages = is_array($GLOBALS['SITE_LANGUAGES'] ?? null) ? $GLOBALS['SITE_LANGUAGES'] : [$defaultLang => ''];
    $regex = nibblyNewsUrlRegex();
    $lang = $defaultLang;
    if (preg_match('#^([a-z]{2})/(.+)$#', $cleanUri, $m) && isset($languages[$m[1]]) && $m[1] !== $defaultLang) {
        $lang = $m[1];
        $cleanUri = $m[2];
    }
    if (!preg_match('#^' . $regex . '$#', $cleanUri, $m)) return null;
    $parts = array_filter(['yyyy' => $m['yyyy'] ?? null, 'mm' => $m['mm'] ?? null, 'dd' => $m['dd'] ?? null], static fn($v) => $v !== null);
    $date = count($parts) === 3 ? $parts['yyyy'] . '-' . $parts['mm'] . '-' . $parts['dd'] : null;
    return ['lang' => $lang, 'slug' => $m['slug'], 'date' => $date, 'parts' => $parts];
}

/** Old slug → current slug (content/news-aliases.json). Keys and values are slugs. */
function nibblyNewsAliases(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $path = nibblyNewsRoot() . '/content/news-aliases.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $aliases = [];
    foreach (is_array($data) ? (is_array($data['aliases'] ?? null) ? $data['aliases'] : $data) : [] as $old => $new) {
        if (is_string($old) && is_string($new) && preg_match('/^' . nibblyNewsSlugPattern() . '$/', $old) && preg_match('/^' . nibblyNewsSlugPattern() . '$/', $new) && $old !== $new) {
            $aliases[$old] = $new;
        }
    }
    return $cache = $aliases;
}

/** All post records of the news folder (hidden ones included), each with "_file"; cached per request. */
function nibblyNewsAllPosts(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $posts = [];
    $dir = nibblyNewsRoot() . '/content/news/';
    foreach (is_dir($dir) ? (glob($dir . '*.json') ?: []) : [] as $file) {
        $post = json_decode((string)file_get_contents($file), true);
        if (!is_array($post)) continue;
        $post['_file'] = basename($file);
        $posts[] = $post;
    }
    return $cache = $posts;
}

/**
 * The published post with this slug in this language (hidden posts count as missing),
 * including an unapplied preview change set when one is active.
 */
function nibblyNewsFindPost(string $slug, ?string $lang = null): ?array {
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = $lang ?? $defaultLang;
    $found = null;
    foreach (nibblyNewsAllPosts() as $post) {
        if (($post['slug'] ?? '') !== $slug || !empty($post['hidden'])) continue;
        if (($post['lang'] ?? $defaultLang) !== $lang) continue;
        $found = $post;
        break;
    }
    foreach (function_exists('nibblyNewsOverlay') ? nibblyNewsOverlay() : [] as $overlayPost) {
        if (is_array($overlayPost) && ($overlayPost['slug'] ?? '') === $slug && ($overlayPost['lang'] ?? $defaultLang) === $lang) {
            $overlayPost['hidden'] = false;
            $found = $overlayPost;
        }
    }
    if ($found !== null) unset($found['_file']);
    return $found;
}

/**
 * Resolve a request to a post. Returns
 *   ['post' => array, 'lang' => code]                       serve the post
 *   ['redirect' => '/canonical/url', 'lang' => code]       301 (wrong date, old slug, trailing slash)
 *   null                                                    not a post URL, or no such post
 * $requestPath is the raw path of the request (with its slashes), $cleanUri the trimmed one.
 */
function nibblyNewsRoute(string $cleanUri, string $requestPath = ''): ?array {
    $match = nibblyNewsMatchUrl($cleanUri);
    if ($match === null) return null;
    $post = nibblyNewsFindPost($match['slug'], $match['lang']);
    if ($post === null) {
        $aliases = nibblyNewsAliases();
        $target = $aliases[$match['slug']] ?? null;
        // Follow a short alias chain, never a loop.
        for ($hops = 0; $post === null && $target !== null && $hops < 5; $hops++) {
            $post = nibblyNewsFindPost($target, $match['lang']);
            $target = $aliases[$target] ?? null;
        }
        if ($post === null) return null;
        return ['redirect' => nibblyNewsUrl($post, $match['lang']), 'lang' => $match['lang']];
    }
    $canonical = nibblyNewsUrl($post, $match['lang']);
    // Only the date parts the pattern carries are compared (a /{yyyy}/{slug} pattern knows no month)
    $postDate = (string)($post['date'] ?? '');
    $actual = ['yyyy' => substr($postDate, 0, 4), 'mm' => substr($postDate, 5, 2), 'dd' => substr($postDate, 8, 2)];
    foreach ($match['parts'] as $part => $value) {
        if ($actual[$part] !== $value) return ['redirect' => $canonical, 'lang' => $match['lang']];
    }
    if ($requestPath !== '' && $requestPath !== '/' && str_ends_with($requestPath, '/')) {
        return ['redirect' => $canonical, 'lang' => $match['lang']];
    }
    return ['post' => $post, 'lang' => $match['lang']];
}

/**
 * Does a clean URI address an archive page? Returns
 * ['type' => 'list'|'category'|'tag'|'year'|'decade', 'value' => string, 'lang' => code] or null.
 * The list itself ("/news") is only claimed when no template or JSON page serves it (route.php decides).
 */
function nibblyNewsMatchArchiveUrl(string $cleanUri): ?array {
    $settings = nibblyNewsSettings();
    $base = trim($settings['archive']['base'], '/');
    if ($base === '') return null;
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $languages = is_array($GLOBALS['SITE_LANGUAGES'] ?? null) ? $GLOBALS['SITE_LANGUAGES'] : [$defaultLang => ''];
    $lang = $defaultLang;
    if (preg_match('#^([a-z]{2})/(.+)$#', $cleanUri, $m) && isset($languages[$m[1]]) && $m[1] !== $defaultLang) {
        $lang = $m[1];
        $cleanUri = $m[2];
    }
    if ($cleanUri === $base) return ['type' => 'list', 'value' => '', 'lang' => $lang];
    if (!str_starts_with($cleanUri, $base . '/')) return null;
    $rest = substr($cleanUri, strlen($base) + 1);
    $slug = nibblyNewsSlugPattern();
    if (preg_match('#^' . preg_quote($settings['archive']['tag'], '#') . '/(' . $slug . ')$#', $rest, $m)) {
        return ['type' => 'tag', 'value' => $m[1], 'lang' => $lang];
    }
    if (preg_match('#^' . preg_quote($settings['archive']['year'], '#') . '/(\d{4})$#', $rest, $m)) {
        return ['type' => 'year', 'value' => $m[1], 'lang' => $lang];
    }
    if (preg_match('#^' . preg_quote($settings['archive']['decade'], '#') . '/(\d{3}0)$#', $rest, $m)) {
        return ['type' => 'decade', 'value' => $m[1], 'lang' => $lang];
    }
    if (preg_match('#^(' . $slug . ')$#', $rest, $m) && nibblyNewsCategory($m[1]) !== null) {
        return ['type' => 'category', 'value' => $m[1], 'lang' => $lang];
    }
    return null;
}

// ------------------------------------------------------------------
// Categories and tags
// ------------------------------------------------------------------

/** Category definitions in display order: [ ['slug','name','description','order'], ... ]. */
function nibblyNewsCategories(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $path = nibblyNewsRoot() . '/content/news-categories.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $list = [];
    foreach (is_array($data['categories'] ?? null) ? $data['categories'] : [] as $index => $row) {
        $normalized = nibblyNewsNormalizeCategory($row, $index);
        if ($normalized !== null && !isset($list[$normalized['slug']])) $list[$normalized['slug']] = $normalized;
    }
    $list = array_values($list);
    usort($list, static fn($a, $b) => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);
    return $cache = $list;
}

/** A category row as stored: slug [a-z0-9-], name, description, numeric order. Null when the slug is invalid. */
function nibblyNewsNormalizeCategory($row, int $fallbackOrder = 0): ?array {
    if (!is_array($row)) return null;
    $slug = nibblyNewsSlugify((string)($row['slug'] ?? ''));
    if ($slug === '') return null;
    $name = trim(strip_tags((string)($row['name'] ?? '')));
    return [
        'slug' => $slug,
        'name' => $name !== '' ? $name : $slug,
        'description' => trim(strip_tags((string)($row['description'] ?? ''))),
        'order' => is_numeric($row['order'] ?? null) ? (int)$row['order'] : $fallbackOrder,
    ];
}

function nibblyNewsCategory(string $slug): ?array {
    foreach (nibblyNewsCategories() as $category) {
        if ($category['slug'] === $slug) return $category;
    }
    return null;
}

/** Display name of a category slug (the slug itself when undefined). */
function nibblyNewsCategoryName(string $slug): string {
    return nibblyNewsCategory($slug)['name'] ?? $slug;
}

/** Write the category list (dashboard). Returns the stored list or false. */
function nibblyNewsSaveCategories(array $rows) {
    $list = [];
    foreach ($rows as $index => $row) {
        $normalized = nibblyNewsNormalizeCategory($row, $index);
        if ($normalized !== null && !isset($list[$normalized['slug']])) $list[$normalized['slug']] = $normalized;
    }
    $list = array_values($list);
    usort($list, static fn($a, $b) => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);
    require_once __DIR__ . '/json-store.php';
    $ok = nibblyJsonAtomicWrite(nibblyNewsRoot() . '/content/news-categories.json', ['categories' => $list]);
    if ($ok === false) return false;
    nibblyNewsCategories(true);
    return $list;
}

/** "Jörg Haider" → "joerg-haider"; empty when nothing usable remains. */
function nibblyNewsSlugify(string $text): string {
    $text = mb_strtolower(trim($text));
    $text = str_replace(['ä', 'ö', 'ü', 'ß', 'é', 'è', 'à', 'á', 'ç', 'ñ', 'ø', 'æ', 'å'], ['ae', 'oe', 'ue', 'ss', 'e', 'e', 'a', 'a', 'c', 'n', 'o', 'ae', 'a'], $text);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && $ascii !== '') $text = $ascii;
    }
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim((string)$text, '-');
}

/** A list of slugs from a stored array or a comma-separated string; invalid entries dropped, no repeats. */
function nibblyNewsSlugList($value, int $max = 50): array {
    if (is_string($value)) $value = [$value];
    if (!is_array($value)) return [];
    $items = [];
    foreach ($value as $item) {
        if (is_scalar($item)) $items = array_merge($items, preg_split('/[,\n;]+/', (string)$item) ?: []);
    }
    $list = [];
    foreach ($items as $item) {
        $slug = nibblyNewsSlugify((string)$item);
        if ($slug !== '' && !in_array($slug, $list, true)) $list[] = $slug;
        if (count($list) >= $max) break;
    }
    return $list;
}

/** Display names of tags (content/news-tags.json: slug → name); tags without an entry are shown as "Word Word". */
function nibblyNewsTagNames(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $path = nibblyNewsRoot() . '/content/news-tags.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $names = [];
    foreach (is_array($data) ? (is_array($data['tags'] ?? null) ? $data['tags'] : $data) : [] as $slug => $name) {
        if (is_string($slug) && is_string($name) && preg_match('/^' . nibblyNewsSlugPattern() . '$/', $slug) && trim($name) !== '') {
            $names[$slug] = trim(strip_tags($name));
        }
    }
    return $cache = $names;
}

function nibblyNewsTagName(string $slug): string {
    return nibblyNewsTagNames()[$slug] ?? ucwords(str_replace('-', ' ', $slug));
}

/**
 * Remember display names for tags typed in the editor ("HOSI Wien" → hosi-wien = "HOSI Wien").
 * The typed spelling wins over a stored one; a label that only repeats the slug or the automatic
 * "Word Word" form is not stored. Returns the slugs.
 */
function nibblyNewsRememberTagNames($typed): array {
    if (is_string($typed)) $typed = [$typed];
    if (!is_array($typed)) return [];
    // Items may carry several names ("Jörg Haider, ÖVP"): one tag per comma, semicolon or line
    $labels = [];
    foreach ($typed as $item) {
        if (is_scalar($item)) $labels = array_merge($labels, preg_split('/[,\n;]+/', (string)$item) ?: []);
    }
    $names = nibblyNewsTagNames();
    $slugs = [];
    $added = false;
    foreach ($labels as $item) {
        $label = trim((string)$item);
        $slug = nibblyNewsSlugify($label);
        if ($slug === '' || in_array($slug, $slugs, true)) continue;
        $slugs[] = $slug;
        $automatic = ucwords(str_replace('-', ' ', $slug));
        if ($label !== $slug && $label !== $automatic && ($names[$slug] ?? '') !== strip_tags($label)) {
            $names[$slug] = strip_tags($label);
            $added = true;
        }
    }
    if ($added) {
        require_once __DIR__ . '/json-store.php';
        ksort($names);
        if (nibblyJsonAtomicWrite(nibblyNewsRoot() . '/content/news-tags.json', ['tags' => $names])) nibblyNewsTagNames(true);
    }
    return $slugs;
}

/** Tags in use by published posts of a language: slug → count, most used first. */
function nibblyNewsTagCounts(?string $lang = null): array {
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = $lang ?? $defaultLang;
    $counts = [];
    foreach (nibblyNewsAllPosts() as $post) {
        if (!empty($post['hidden']) || ($post['lang'] ?? $defaultLang) !== $lang) continue;
        foreach (nibblyNewsSlugList($post['tags'] ?? []) as $tag) $counts[$tag] = ($counts[$tag] ?? 0) + 1;
    }
    arsort($counts);
    return $counts;
}

// ------------------------------------------------------------------
// Filters and pagination
// ------------------------------------------------------------------

/**
 * Does a post pass the filters? Keys: category, tag, year, decade, flag (slugs / 4-digit strings),
 * each a single value or a list (any match); flagNot excludes posts carrying one of the flags
 * (e.g. the columns of a category: flagNot => 'column'). Unknown keys are ignored.
 */
function nibblyNewsPostMatchesFilters(array $post, array $filters): bool {
    $anyOf = static function ($wanted, array $have): bool {
        foreach ((array)$wanted as $value) {
            if (in_array((string)$value, $have, true)) return true;
        }
        return false;
    };
    $date = (string)($post['date'] ?? '');
    if (!empty($filters['category']) && !$anyOf($filters['category'], nibblyNewsSlugList($post['categories'] ?? []))) return false;
    if (!empty($filters['tag']) && !$anyOf($filters['tag'], nibblyNewsSlugList($post['tags'] ?? []))) return false;
    if (!empty($filters['flag']) && !$anyOf($filters['flag'], nibblyNewsSlugList($post['flags'] ?? []))) return false;
    if (!empty($filters['flagNot']) && $anyOf($filters['flagNot'], nibblyNewsSlugList($post['flags'] ?? []))) return false;
    if (!empty($filters['year']) && !$anyOf($filters['year'], [substr($date, 0, 4)])) return false;
    if (!empty($filters['decade']) && !$anyOf($filters['decade'], [substr($date, 0, 3) . '0'])) return false;
    return true;
}

/** Current list page from the request (1-based), never below 1. */
function nibblyNewsCurrentPage(?array $query = null): int {
    $param = nibblyNewsSettings()['pageParam'];
    $value = ($query ?? $_GET)[$param] ?? 1;
    return is_numeric($value) ? max(1, (int)$value) : 1;
}

/** URL of a list page: page 1 is the bare URL, others get ?{pageParam}=N (other query keys kept). */
function nibblyNewsPageUrl(string $baseUrl, int $page): string {
    $param = nibblyNewsSettings()['pageParam'];
    $parts = parse_url($baseUrl);
    $path = (string)($parts['path'] ?? $baseUrl);
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    unset($query[$param]);
    if ($page > 1) $query[$param] = $page;
    return $path . ($query ? '?' . http_build_query($query) : '');
}

/**
 * Accessible pagination markup. Empty for a single page.
 * Labels follow the page language; rel="prev"/"next" on the neighbours.
 */
function nibblyNewsPagination(int $total, int $page, int $perPage, string $baseUrl, ?string $lang = null): string {
    $pages = (int)ceil(max(0, $total) / max(1, $perPage));
    if ($pages < 2) return '';
    $page = min(max(1, $page), $pages);
    require_once __DIR__ . '/public-strings.php';
    $label = static fn(string $key, array $params = []): string => strtr(nibblyPublicString($key, $lang), array_combine(array_map(static fn($k) => '{' . $k . '}', array_keys($params)), array_values($params)));
    $link = static fn(int $target, string $text, string $class, string $rel = '', string $aria = ''): string =>
        '<a class="pagination__link ' . $class . '" href="' . htmlspecialchars(nibblyNewsPageUrl($baseUrl, $target)) . '"'
        . ($rel !== '' ? ' rel="' . $rel . '"' : '') . ($aria !== '' ? ' aria-label="' . htmlspecialchars($aria) . '"' : '') . '>' . $text . '</a>';

    // Page numbers: first, last, the current one and its neighbours; gaps as ellipses.
    $show = [1, $pages, $page - 1, $page, $page + 1];
    if ($page <= 3) $show = array_merge($show, [2, 3, 4]);
    if ($page >= $pages - 2) $show = array_merge($show, [$pages - 1, $pages - 2, $pages - 3]);
    $show = array_values(array_unique(array_filter($show, static fn($n) => $n >= 1 && $n <= $pages)));
    sort($show);

    $html = '<nav class="pagination" aria-label="' . htmlspecialchars($label('pagination_label')) . '">';
    $html .= '<ul class="pagination__list">';
    $html .= '<li class="pagination__item pagination__item--prev">';
    $html .= $page > 1
        ? $link($page - 1, '&larr; <span>' . htmlspecialchars($label('pagination_prev')) . '</span>', 'pagination__link--prev', 'prev')
        : '<span class="pagination__link pagination__link--prev pagination__link--disabled" aria-disabled="true">&larr; <span>' . htmlspecialchars($label('pagination_prev')) . '</span></span>';
    $html .= '</li>';
    $previous = 0;
    foreach ($show as $number) {
        if ($number - $previous > 1) $html .= '<li class="pagination__item pagination__item--gap" aria-hidden="true">&hellip;</li>';
        $previous = $number;
        $aria = $label('pagination_page', ['page' => (string)$number]);
        if ($number === $page) {
            $html .= '<li class="pagination__item"><span class="pagination__link pagination__link--current" aria-current="page" aria-label="' . htmlspecialchars($aria) . '">' . $number . '</span></li>';
        } else {
            $rel = $number === $page - 1 ? 'prev' : ($number === $page + 1 ? 'next' : '');
            $html .= '<li class="pagination__item">' . $link($number, (string)$number, 'pagination__link--number', $rel, $aria) . '</li>';
        }
    }
    $html .= '<li class="pagination__item pagination__item--next">';
    $html .= $page < $pages
        ? $link($page + 1, '<span>' . htmlspecialchars($label('pagination_next')) . '</span> &rarr;', 'pagination__link--next', 'next')
        : '<span class="pagination__link pagination__link--next pagination__link--disabled" aria-disabled="true"><span>' . htmlspecialchars($label('pagination_next')) . '</span> &rarr;</span>';
    $html .= '</li></ul>';
    $html .= '<p class="pagination__summary">' . htmlspecialchars($label('pagination_summary', ['page' => (string)$page, 'pages' => (string)$pages])) . '</p>';
    $html .= '</nav>';
    return $html;
}

/** <link rel="prev"/"next"> for the document head of a paginated list. */
function nibblyNewsPaginationHeadLinks(int $total, int $page, int $perPage, string $baseUrl): string {
    $pages = (int)ceil(max(0, $total) / max(1, $perPage));
    $html = '';
    if ($page > 1 && $page <= $pages) $html .= '<link rel="prev" href="' . htmlspecialchars(nibblyNewsPageUrl($baseUrl, $page - 1)) . '">' . "\n";
    if ($page < $pages) $html .= '<link rel="next" href="' . htmlspecialchars(nibblyNewsPageUrl($baseUrl, $page + 1)) . '">' . "\n";
    return $html;
}

/** Heading of an archive page in the page language ("Rubrik: Artikel", "Thema: HOSI Wien", "Jahr 1998"). */
function nibblyNewsArchiveTitle(string $type, string $value, ?string $lang = null): string {
    require_once __DIR__ . '/public-strings.php';
    switch ($type) {
        case 'category': return nibblyNewsCategoryName($value);
        case 'tag': return str_replace('{tag}', nibblyNewsTagName($value), nibblyPublicString('news_archive_tag', $lang));
        case 'year': return str_replace('{year}', $value, nibblyPublicString('news_archive_year', $lang));
        case 'decade': return str_replace('{decade}', $value, nibblyPublicString('news_archive_decade', $lang));
    }
    return nibblyPublicString('news_archive_all', $lang);
}
