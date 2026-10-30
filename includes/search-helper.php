<?php
/**
 * Full-text search over pages and news posts with a flat-file index.
 *
 * Settings (content/settings.json → "search", all optional):
 *   enabled    false switches the search page off (default true).
 *   base       Path of the search page, default "/search" (non-primary languages get the prefix).
 *              The path is only claimed when no template or JSON page exists there (route.php).
 *   param      Query parameter, default "q".
 *   perPage    Results per page, default 20.
 *   minLength  Shortest query that is searched, default 2.
 *
 * Files:
 *   content/search-index.json   the index; rebuilt by nibblySearchIndexRebuild() when a page or
 *                               post is saved through the dashboard API (admin/api/pages.php,
 *                               admin/api/news.php), by "php cli/search-index.php", and on demand
 *                               when it is missing or older than the newest content file.
 *
 * Ranking: a hit in the title weighs most, then excerpt/description, tags and categories, then the
 * body text. Every term of the query has to occur somewhere in a document ("AND"); a hit of the
 * whole phrase adds to the score. Umlauts and accents are folded ("Jörg" matches "joerg" and "jorg").
 *
 * Templates: includes/search.php is the core search page; a site replaces it with {lang}/search.php
 * (same variables: $searchQuery, $searchResult, $currentLang, $basePath) or a template at the base path.
 */

require_once __DIR__ . '/page-path.php';
require_once __DIR__ . '/news-helper.php';

function nibblySearchRoot(): string {
    return dirname(__DIR__);
}

function nibblySearchSettingsDefaults(): array {
    return ['enabled' => true, 'base' => '/search', 'param' => 'q', 'perPage' => 20, 'minLength' => 2];
}

/** The "search" section of settings.json with defaults applied and values validated. */
function nibblySearchSettings(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $defaults = nibblySearchSettingsDefaults();
    $path = nibblySearchRoot() . '/content/settings.json';
    $settings = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $raw = is_array($settings['search'] ?? null) ? $settings['search'] : [];
    $merged = array_replace($defaults, $raw);
    $merged['enabled'] = !isset($raw['enabled']) || !empty($raw['enabled']);
    $base = '/' . trim(preg_replace('#[^a-z0-9/-]#', '', strtolower((string)$merged['base'])), '/');
    $merged['base'] = ($base === '/' || !preg_match('#^/' . nibblyPagePathPattern() . '$#', $base)) ? $defaults['base'] : $base;
    $merged['param'] = preg_match('/^[a-z][a-z0-9_-]{0,30}$/', (string)$merged['param']) ? (string)$merged['param'] : $defaults['param'];
    $merged['perPage'] = max(1, min(200, (int)$merged['perPage']));
    $merged['minLength'] = max(1, min(10, (int)$merged['minLength']));
    return $cache = $merged;
}

function nibblySearchEnabled(): bool {
    return nibblySearchSettings()['enabled'];
}

/** Root-absolute path of the search page in a language (/search, /en/search). */
function nibblySearchUrl(?string $lang = null): string {
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = $lang ?? $defaultLang;
    $prefix = ($lang !== $defaultLang && preg_match('/^[a-z]{2}$/', $lang)) ? '/' . $lang : '';
    return $prefix . nibblySearchSettings()['base'];
}

/** URL of a search for $query (page 1); relative to $basePath when given. */
function nibblySearchQueryUrl(string $query, ?string $lang = null, string $basePath = ''): string {
    $settings = nibblySearchSettings();
    $path = $basePath === '' ? nibblySearchUrl($lang) : $basePath . ltrim(nibblySearchUrl($lang), '/');
    return $path . ($query !== '' ? '?' . http_build_query([$settings['param'] => $query]) : '');
}

/** Does a clean URI (no surrounding slashes) address the search page? Returns ['lang' => code] or null. */
function nibblySearchMatchUrl(string $cleanUri): ?array {
    if (!nibblySearchEnabled()) return null;
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $languages = is_array($GLOBALS['SITE_LANGUAGES'] ?? null) ? $GLOBALS['SITE_LANGUAGES'] : [$defaultLang => ''];
    $lang = $defaultLang;
    if (preg_match('#^([a-z]{2})/(.+)$#', $cleanUri, $m) && isset($languages[$m[1]]) && $m[1] !== $defaultLang) {
        $lang = $m[1];
        $cleanUri = $m[2];
    }
    return $cleanUri === trim(nibblySearchSettings()['base'], '/') ? ['lang' => $lang] : null;
}

/** The query of the current request, trimmed and capped. */
function nibblySearchRequestQuery(?array $query = null): string {
    $value = ($query ?? $_GET)[nibblySearchSettings()['param']] ?? '';
    $value = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
    return mb_substr($value, 0, 200);
}

// ------------------------------------------------------------------
// Index
// ------------------------------------------------------------------

function nibblySearchIndexPath(): string {
    return nibblySearchRoot() . '/content/search-index.json';
}

/**
 * Lowercase ASCII: accents folded, umlauts as "ae/oe/ue" (or, with $strip, as the bare vowel), ß as "ss".
 * Documents are matched in both forms, so "Jörg", "Joerg" and "Jorg" find each other.
 */
function nibblySearchNormalize(string $text, bool $strip = false): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(['ä', 'ö', 'ü', 'ß'], $strip ? ['a', 'o', 'u', 'ss'] : ['ae', 'oe', 'ue', 'ss'], $text);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && $ascii !== '') $text = strtolower($ascii);
    }
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    return trim((string)$text);
}

/** Plain text of HTML: tags and entities removed, whitespace collapsed. */
function nibblySearchPlainText(string $html): string {
    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $text = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|blockquote|figcaption|tr|td|th)>#i', ' ', $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

/**
 * Text of a page's editable content: every string field of sections and custom-layout data
 * except technical keys (ids, types, paths, links, classes).
 */
function nibblySearchPageText(array $data): string {
    $skip = ['page', 'lang', 'id', 'type', 'level', 'src', 'href', 'url', 'image', 'icon', 'class', 'style', 'path',
        'layout', 'form', 'nav', 'breadcrumb', 'seo', 'visibility', 'lastModified', 'template', 'alt', 'target', 'rel',
        'status', 'variant', 'align', 'columns', 'size', 'color', 'background', 'width', 'height', 'date'];
    $parts = [];
    $walk = function ($value, string $key = '') use (&$walk, &$parts, $skip): void {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && in_array($k, $skip, true)) continue;
                $walk($v, is_string($k) ? $k : $key);
            }
        } elseif (is_string($value) && strlen($value) > 1 && !preg_match('#^(https?:|/|\.\./|assets/|\#)#', $value)) {
            $parts[] = nibblySearchPlainText($value);
        }
    };
    foreach ($data as $key => $value) {
        if (in_array($key, ['title', 'description'], true) || in_array($key, $skip, true)) continue;
        $walk($value, (string)$key);
    }
    return trim(implode(' ', array_filter($parts)));
}

/** Build the document list: public pages and published posts of every language. */
function nibblySearchIndexBuild(): array {
    $root = nibblySearchRoot();
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $documents = [];
    foreach (glob($root . '/content/pages/[a-z][a-z]_*.json') ?: [] as $file) {
        $parsed = nibblyPageParseContentKey(basename($file, '.json'));
        if ($parsed === null) continue;
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) continue;
        $visibility = is_array($data['visibility'] ?? null) ? $data['visibility'] : [];
        if (($visibility['status'] ?? 'public') === 'private') continue;
        $seo = is_array($data['seo'] ?? null) ? $data['seo'] : [];
        if (($seo['searchable'] ?? true) === false) continue;
        if (stripos((string)($seo['robots'] ?? ''), 'noindex') !== false) continue;
        $documents[] = [
            'type' => 'page',
            'id' => $parsed['lang'] . '_' . str_replace('/', '__', $parsed['path']),
            'lang' => $parsed['lang'],
            'url' => nibblyPageUrlPath($parsed['lang'], $parsed['path'], $defaultLang),
            'title' => trim((string)($data['title'] ?? ucfirst(str_replace('-', ' ', basename($parsed['path']))))),
            'excerpt' => nibblySearchPlainText((string)($data['description'] ?? '')),
            'text' => mb_substr(nibblySearchPageText($data), 0, 20000),
            'date' => substr((string)($data['lastModified'] ?? ''), 0, 10),
            'image' => (string)($data['image'] ?? ''),
            'categories' => [],
            'tags' => [],
            'path' => $parsed['path'],
        ];
    }
    foreach (nibblyNewsAllPosts(true) as $post) {
        if (!empty($post['hidden'])) continue;
        $slug = (string)($post['slug'] ?? '');
        if ($slug === '') continue;
        $lang = (string)($post['lang'] ?? $defaultLang);
        $body = '';
        if (is_string($post['content'] ?? null)) {
            $body = $post['content'];
        } elseif (is_array($post['sections'] ?? null)) {
            $body = nibblySearchPageText(['sections' => $post['sections']]);
        }
        $galleryText = [];
        foreach (is_array($post['gallery'] ?? null) ? $post['gallery'] : [] as $image) {
            if (is_array($image) && !empty($image['caption'])) $galleryText[] = (string)$image['caption'];
        }
        $categories = nibblyNewsSlugList($post['categories'] ?? []);
        $tags = nibblyNewsSlugList($post['tags'] ?? []);
        $documents[] = [
            'type' => 'post',
            'id' => (string)($post['id'] ?? $slug),
            'lang' => $lang,
            'url' => nibblyNewsUrl($post, $lang),
            'title' => trim((string)($post['title'] ?? '')),
            'excerpt' => nibblySearchPlainText((string)($post['excerpt'] ?? '')),
            'text' => mb_substr(nibblySearchPlainText($body . ' ' . implode(' ', $galleryText)), 0, 20000),
            'date' => substr((string)($post['date'] ?? ''), 0, 10),
            'image' => (string)($post['image'] ?? ''),
            'categories' => $categories,
            'tags' => $tags,
            'labels' => implode(' ', array_merge(array_map('nibblyNewsCategoryName', $categories), array_map('nibblyNewsTagName', $tags))),
        ];
    }
    return $documents;
}

/** Newest modification time of the indexed content (pages and posts, their folders included). */
function nibblySearchContentMtime(): int {
    $root = nibblySearchRoot();
    $newest = 0;
    foreach (['/content/pages', '/content/news'] as $dir) {
        $newest = max($newest, (int)@filemtime($root . $dir));
        foreach (glob($root . $dir . '/*.json') ?: [] as $file) $newest = max($newest, (int)@filemtime($file));
    }
    foreach (['/content/news-categories.json', '/content/news-tags.json', '/content/settings.json'] as $file) {
        $newest = max($newest, (int)@filemtime($root . $file));
    }
    return $newest;
}

/** Rebuild and write the index. Returns the number of documents, or false when writing failed. */
function nibblySearchIndexRebuild() {
    $documents = nibblySearchIndexBuild();
    require_once __DIR__ . '/json-store.php';
    $ok = nibblyJsonAtomicWrite(nibblySearchIndexPath(), [
        'generatedAt' => date('c'),
        'contentMtime' => nibblySearchContentMtime(),
        'count' => count($documents),
        'documents' => $documents,
    ]);
    if (!$ok) return false;
    $GLOBALS['nibblySearchIndexCache'] = $documents;
    $GLOBALS['nibblySearchIndexCacheMtime'] = nibblySearchContentMtime();
    return count($documents);
}

/** The documents of the index; built when the file is missing or older than the content. */
function nibblySearchIndex(bool $fresh = false): array {
    $mtime = nibblySearchContentMtime();
    if (!$fresh && isset($GLOBALS['nibblySearchIndexCache']) && (int)($GLOBALS['nibblySearchIndexCacheMtime'] ?? 0) >= $mtime) {
        return $GLOBALS['nibblySearchIndexCache'];
    }
    $path = nibblySearchIndexPath();
    $index = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    $stale = !is_array($index) || !is_array($index['documents'] ?? null) || (int)($index['contentMtime'] ?? 0) < $mtime;
    if ($stale) {
        $count = nibblySearchIndexRebuild();
        if ($count === false) {
            // Unwritable content folder: search from memory for this request
            $GLOBALS['nibblySearchIndexCache'] = nibblySearchIndexBuild();
            $GLOBALS['nibblySearchIndexCacheMtime'] = $mtime;
        }
        return $GLOBALS['nibblySearchIndexCache'];
    }
    $GLOBALS['nibblySearchIndexCacheMtime'] = $mtime;
    return $GLOBALS['nibblySearchIndexCache'] = $index['documents'];
}

// ------------------------------------------------------------------
// Query
// ------------------------------------------------------------------

/**
 * Query terms as normalized words; "Jörg" yields the variants "joerg" and "jorg" so both spellings hit.
 * Returns [ ['joerg', 'jorg'], ['haider'] ]: one variant list per term.
 */
function nibblySearchTerms(string $query): array {
    $terms = [];
    foreach (preg_split('/\s+/u', trim($query)) ?: [] as $word) {
        $word = trim($word, '"\'.,;:!?()[]');
        if ($word === '') continue;
        $variants = array_unique(array_filter([
            nibblySearchNormalize($word),
            nibblySearchNormalize(str_replace(['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü'], ['a', 'o', 'u', 'a', 'o', 'u'], $word)),
        ], static fn($v) => $v !== ''));
        if ($variants) $terms[] = array_values($variants);
    }
    return $terms;
}

/** Score of one document for the terms (0 when a term is missing). */
function nibblySearchScore(array $document, array $terms, string $phrase): int {
    static $normalized = [];
    $key = $document['type'] . ':' . $document['id'] . ':' . $document['lang'];
    if (!isset($normalized[$key])) {
        // Each field in both foldings ("joerg" and "jorg"); a term hits when either form contains it
        $normalized[$key] = [];
        foreach (['title' => $document['title'], 'excerpt' => $document['excerpt'], 'labels' => (string)($document['labels'] ?? ''), 'text' => $document['text']] as $name => $value) {
            $folded = ' ' . nibblySearchNormalize((string)$value) . ' ';
            $stripped = ' ' . nibblySearchNormalize((string)$value, true) . ' ';
            $normalized[$key][$name] = $folded === $stripped ? [$folded] : [$folded, $stripped];
        }
    }
    $fields = $normalized[$key];
    $contains = static function (array $forms, string $needle): bool {
        foreach ($forms as $form) {
            if (strpos($form, $needle) !== false) return true;
        }
        return false;
    };
    $count = static function (array $forms, string $needle): int {
        $max = 0;
        foreach ($forms as $form) $max = max($max, substr_count($form, $needle));
        return $max;
    };
    $score = 0;
    foreach ($terms as $variants) {
        $termScore = 0;
        foreach ($variants as $term) {
            if ($contains($fields['title'], $term)) {
                $termScore = max($termScore, $contains($fields['title'], ' ' . $term . ' ') ? 14 : 10);
            }
            if ($contains($fields['excerpt'], $term)) $termScore = max($termScore, 5) + 1;
            if ($contains($fields['labels'], $term)) $termScore = max($termScore, 6);
            $hits = $count($fields['text'], $term);
            if ($hits > 0) $termScore = max($termScore, 1) + min(4, $hits);
        }
        if ($termScore === 0) return 0;
        $score += $termScore;
    }
    if (count($terms) > 1 && $phrase !== '') {
        if ($contains($fields['title'], $phrase)) $score += 12;
        elseif ($contains($fields['excerpt'], $phrase) || $contains($fields['text'], $phrase)) $score += 6;
    }
    return $score;
}

/**
 * Run a search. Options: lang (page language; '' = every language), type ('page'|'post'|''),
 * page (1-based), perPage. Returns
 * ['query' => string, 'terms' => [...], 'results' => [...], 'total' => n, 'page' => n, 'pages' => n, 'tooShort' => bool].
 * Each result is the index document plus 'score' and 'snippet' (HTML with <mark>).
 */
function nibblySearchQuery(string $query, array $options = []): array {
    $settings = nibblySearchSettings();
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = array_key_exists('lang', $options) ? (string)$options['lang'] : $defaultLang;
    $perPage = max(1, (int)($options['perPage'] ?? $settings['perPage']));
    $page = max(1, (int)($options['page'] ?? 1));
    $type = (string)($options['type'] ?? '');
    $terms = nibblySearchTerms($query);
    $result = ['query' => $query, 'terms' => $terms, 'results' => [], 'total' => 0, 'page' => $page, 'pages' => 0, 'tooShort' => false];
    if (!$terms || mb_strlen(trim($query)) < $settings['minLength']) {
        $result['tooShort'] = trim($query) !== '';
        return $result;
    }
    $phrase = nibblySearchNormalize($query);
    $matches = [];
    foreach (nibblySearchIndex() as $document) {
        if ($lang !== '' && ($document['lang'] ?? $defaultLang) !== $lang) continue;
        if ($type !== '' && ($document['type'] ?? '') !== $type) continue;
        $score = nibblySearchScore($document, $terms, $phrase);
        if ($score === 0) continue;
        $document['score'] = $score;
        $matches[] = $document;
    }
    usort($matches, static fn($a, $b) => [$b['score'], $b['date'] ?? '', $a['title']] <=> [$a['score'], $a['date'] ?? '', $b['title']]);
    $result['total'] = count($matches);
    $result['pages'] = (int)ceil(count($matches) / $perPage);
    if ($page > $result['pages'] && $result['pages'] > 0) $result['page'] = $page = $result['pages'];
    foreach (array_slice($matches, ($page - 1) * $perPage, $perPage) as $document) {
        $document['snippet'] = nibblySearchSnippet($document, $terms);
        $result['results'][] = $document;
    }
    return $result;
}

/** Wrap every term occurrence in <mark>; the text is escaped first. */
function nibblySearchHighlight(string $text, array $terms): string {
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $patterns = [];
    foreach ($terms as $variants) {
        foreach ($variants as $term) {
            // Match the folded term against the original text: umlauts may stand for "ae" or "a" etc.
            $pattern = '';
            foreach (preg_split('//u', $term, -1, PREG_SPLIT_NO_EMPTY) as $i => $char) {
                $map = ['a' => '[aäà-å]', 'o' => '[oöò-ø]', 'u' => '[uüù-û]', 'e' => '[eéè-ë]', 'i' => '[iìíîï]', 's' => '(?:s|ß)', 'c' => '[cç]', 'n' => '[nñ]'];
                $pattern .= $map[$char] ?? preg_quote($char, '/');
            }
            if ($pattern !== '') $patterns[] = $pattern;
        }
    }
    if (!$patterns) return $escaped;
    // "ae" in the folded term may be a single "ä" in the text; allow "e" to be optional after a/o/u
    $patterns = array_map(static fn($p) => preg_replace('/\[aäà-å\]\[eéè-ë\]/', '(?:[aäà-å][eéè-ë]|ä)', preg_replace('/\[oöò-ø\]\[eéè-ë\]/', '(?:[oöò-ø][eéè-ë]|ö)', preg_replace('/\[uüù-û\]\[eéè-ë\]/', '(?:[uüù-û][eéè-ë]|ü)', $p))), $patterns);
    usort($patterns, static fn($a, $b) => strlen($b) <=> strlen($a));
    return preg_replace('/(' . implode('|', $patterns) . ')/iu', '<mark>$1</mark>', $escaped) ?? $escaped;
}

/** A short text window around the first hit (excerpt first, then body), terms marked. */
function nibblySearchSnippet(array $document, array $terms, int $length = 220): string {
    $candidates = array_filter([(string)($document['excerpt'] ?? ''), (string)($document['text'] ?? '')]);
    $pick = '';
    $offset = 0;
    foreach ($candidates as $text) {
        foreach ([nibblySearchNormalize($text), nibblySearchNormalize($text, true)] as $folded) {
        foreach ($terms as $variants) {
            foreach ($variants as $term) {
                $pos = strpos($folded, $term);
                if ($pos !== false) {
                    $pick = $text;
                    // The folded text has the same word count; locate the word in the original text
                    $wordIndex = substr_count(substr($folded, 0, $pos), ' ');
                    $words = preg_split('/\s+/u', $text) ?: [];
                    $offset = mb_strlen(implode(' ', array_slice($words, 0, $wordIndex)));
                    break 4;
                }
            }
        }
        }
    }
    if ($pick === '') $pick = (string)(reset($candidates) ?: '');
    $start = max(0, $offset - (int)($length / 3));
    if ($start > 0) {
        $space = mb_strpos($pick, ' ', $start);
        $start = $space !== false && $space < $offset ? $space + 1 : $start;
    }
    $window = mb_substr($pick, $start, $length);
    if ($start + $length < mb_strlen($pick)) {
        $cut = mb_strrpos($window, ' ');
        if ($cut !== false && $cut > $length * 0.6) $window = mb_substr($window, 0, $cut);
        $window .= ' …';
    }
    if ($start > 0) $window = '… ' . $window;
    return nibblySearchHighlight($window, $terms);
}

/** Query URL for page $page of the current search (other query keys kept). */
function nibblySearchPageUrl(string $baseUrl, string $query, int $page): string {
    $settings = nibblySearchSettings();
    $parts = parse_url($baseUrl);
    $path = (string)($parts['path'] ?? $baseUrl);
    $params = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $params);
    $params[$settings['param']] = $query;
    unset($params[nibblyNewsSettings()['pageParam']]);
    if ($page > 1) $params[nibblyNewsSettings()['pageParam']] = $page;
    return $path . '?' . http_build_query($params);
}

/** Accessible search form (GET). $options: placeholder, label, button, class, id, value, basePath, lang. */
function nibblySearchForm(array $options = []): string {
    require_once __DIR__ . '/public-strings.php';
    $lang = $options['lang'] ?? null;
    $settings = nibblySearchSettings();
    $basePath = (string)($options['basePath'] ?? '');
    $action = $basePath === '' ? nibblySearchUrl($lang) : $basePath . ltrim(nibblySearchUrl($lang), '/');
    $id = (string)($options['id'] ?? 'site-search-' . substr(md5($action . ($options['class'] ?? '')), 0, 6));
    $label = (string)($options['label'] ?? nibblyPublicString('search_label', $lang));
    $placeholder = (string)($options['placeholder'] ?? nibblyPublicString('search_placeholder', $lang));
    $button = (string)($options['button'] ?? nibblyPublicString('search_button', $lang));
    $value = (string)($options['value'] ?? '');
    $class = trim('search-form ' . (string)($options['class'] ?? ''));
    return '<form class="' . htmlspecialchars($class) . '" role="search" method="get" action="' . htmlspecialchars($action) . '">'
        . '<label class="search-form__label" for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>'
        . '<input class="search-form__input" type="search" id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($settings['param']) . '"'
        . ' value="' . htmlspecialchars($value) . '" placeholder="' . htmlspecialchars($placeholder) . '" autocomplete="off" minlength="' . (int)$settings['minLength'] . '" required>'
        . '<button class="search-form__button" type="submit"><span class="search-form__button-text">' . htmlspecialchars($button) . '</span>'
        . '<svg class="search-form__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></button>'
        . '</form>';
}
