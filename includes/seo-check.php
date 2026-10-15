<?php
/**
 * Search engine self-check for the admin system status; never exposed to visitors
 * or editors.
 *
 * A staging site is blocked for search engines on purpose. When it goes live the
 * block is often forgotten, and the site never shows up in Google. This check
 * reads what the live site really delivers (robots.txt, the robots meta tag and
 * X-Robots-Tag of the home page, sitemap.xml) and compares it with the SEO
 * settings. It never changes anything; the dashboard only offers to switch the
 * block off on a click. Local hosts are not checked.
 *
 * Three layers: nibblySeoCheckRun() fetches, nibblySeoCheckFacts() condenses the
 * responses into small facts (these are cached), nibblySeoCheckEvaluate() turns
 * facts and current settings into the items shown in the dashboard. The last two
 * need no network.
 */

require_once __DIR__ . '/security-check.php';
require_once __DIR__ . '/seo-helper.php';

const NIBBLY_SEO_CHECK_BODY_LIMIT = 524288;

// ---------------------------------------------------------------------------
// Parsers (pure)
// ---------------------------------------------------------------------------

/** Groups of a robots.txt as [['agents' => [...], 'rules' => [[type, pattern], ...]], ...]. */
function nibblySeoCheckRobotsGroups(string $robots): array {
    $groups = [];
    $current = null;
    foreach (preg_split('/\r\n|\r|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $robots)) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if (!preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $match)) continue;
        $field = strtolower($match[1]);
        $value = trim($match[2]);
        if ($field === 'user-agent') {
            // Consecutive User-agent lines share one group.
            if ($current === null || $current['rules']) {
                if ($current !== null) $groups[] = $current;
                $current = ['agents' => [], 'rules' => []];
            }
            $current['agents'][] = strtolower($value);
        } elseif (in_array($field, ['allow', 'disallow'], true) && $current !== null && $value !== '') {
            $current['rules'][] = [$field, $value];
        }
    }
    if ($current !== null) $groups[] = $current;
    return $groups;
}

/** Whether a robots.txt rule pattern ("*" wildcard, "$" end anchor) matches a path. */
function nibblySeoCheckRobotsPatternMatches(string $pattern, string $path): bool {
    $regex = '#^' . str_replace('\*', '.*', preg_quote(rtrim($pattern, '$'), '#')) . (str_ends_with($pattern, '$') ? '$' : '') . '#';
    return preg_match($regex, $path) === 1;
}

/** Whether Googlebot may not fetch the home page "/": the longest matching rule wins, Allow wins a tie. */
function nibblySeoCheckRobotsBlocksRoot(string $robots): bool {
    $groups = nibblySeoCheckRobotsGroups($robots);
    // A group that names Googlebot replaces the "*" group.
    $rules = [];
    foreach ($groups as $group) {
        if (in_array('googlebot', $group['agents'], true)) $rules = array_merge($rules, $group['rules']);
    }
    if (!$rules) {
        foreach ($groups as $group) {
            if (in_array('*', $group['agents'], true)) $rules = array_merge($rules, $group['rules']);
        }
    }
    $best = ['allow', -1];
    foreach ($rules as [$type, $pattern]) {
        if (!nibblySeoCheckRobotsPatternMatches($pattern, '/')) continue;
        $length = strlen($pattern);
        if ($length > $best[1] || ($length === $best[1] && $type === 'allow')) $best = [$type, $length];
    }
    return $best[0] === 'disallow';
}

function nibblySeoCheckRobotsSitemaps(string $robots): array {
    preg_match_all('/^\s*sitemap\s*:\s*(\S+)/im', $robots, $match);
    return $match[1];
}

/** "noindex" or "none" for Googlebot, in a meta content value or an X-Robots-Tag header value. */
function nibblySeoCheckHasNoindex(string $value): bool {
    // "googlebot: noindex, nofollow" names a crawler; "unavailable_after: 25 Jun 2030 ..." is no crawler.
    $agent = null;
    foreach (explode(',', strtolower($value)) as $token) {
        $token = trim($token);
        if (preg_match('/^([a-z0-9-]*bot[a-z0-9-]*)\s*:\s*(.*)$/', $token, $match)) {
            $agent = $match[1];
            $token = trim($match[2]);
        }
        if (($agent === null || $agent === 'googlebot') && in_array($token, ['noindex', 'none'], true)) return true;
    }
    return false;
}

/** Contents of all <meta name="robots|googlebot"> tags. */
function nibblySeoCheckMetaRobots(string $html): array {
    $found = [];
    preg_match_all('/<meta\b[^>]*>/i', $html, $tags);
    foreach ($tags[0] as $tag) {
        preg_match_all('/([a-z:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $pairs, PREG_SET_ORDER);
        $attributes = [];
        foreach ($pairs as $pair) $attributes[strtolower($pair[1])] = html_entity_decode($pair[2] . ($pair[3] ?? ''), ENT_QUOTES, 'UTF-8');
        if (in_array(strtolower($attributes['name'] ?? ''), ['robots', 'googlebot'], true) && isset($attributes['content'])) {
            $found[] = $attributes['content'];
        }
    }
    return $found;
}

/** ['type' => 'urlset'|'sitemapindex', 'locs' => [...]], or null when the text is no sitemap. */
function nibblySeoCheckParseSitemap(string $xml): ?array {
    // A sitemap has no DTD; refusing it also rules out entity tricks.
    if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) return null;
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    // An empty <urlset/> is falsy in PHP, so compare with false.
    if ($document === false || !in_array($document->getName(), ['urlset', 'sitemapindex'], true)) return null;
    $locs = [];
    foreach ($document->xpath('//*[local-name()="loc"]') ?: [] as $loc) $locs[] = trim((string)$loc);
    return ['type' => $document->getName(), 'locs' => $locs];
}

/** Host without port and a leading "www.", for comparing addresses of the same site. */
function nibblySeoCheckHost(string $url): string {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

// ---------------------------------------------------------------------------
// Facts from responses (pure) and fetching
// ---------------------------------------------------------------------------

/** Why an answer cannot be judged: no connection, or an access protection in front of the site. */
function nibblySeoCheckProblem(array $response): array {
    if ($response['status'] === 401) return ['reason' => 'auth'];
    if ($response['status'] === 0) return ['reason' => 'unreachable', 'detail' => (string)($response['error'] ?? '')];
    return [];
}

/** Condense the three responses (robots, home, sitemap) into the small facts that are cached. */
function nibblySeoCheckFacts(array $responses, string $baseUrl): array {
    $facts = [];
    $baseHost = nibblySeoCheckHost($baseUrl);
    $basePlain = str_starts_with($baseUrl, 'https://');

    $robots = $responses['robots'];
    $facts['robots'] = ['status' => $robots['status'], 'problem' => nibblySeoCheckProblem($robots)];
    if ($robots['status'] >= 200 && $robots['status'] < 300) {
        $facts['robots']['blocked'] = nibblySeoCheckRobotsBlocksRoot($robots['body']);
        $sitemaps = nibblySeoCheckRobotsSitemaps($robots['body']);
        $facts['robots']['sitemaps'] = count($sitemaps);
        $foreign = array_values(array_filter($sitemaps, fn($url) => nibblySeoCheckHost($url) !== $baseHost));
        $facts['robots']['foreignSitemap'] = $foreign[0] ?? '';
    }

    $home = $responses['home'];
    $facts['home'] = ['status' => $home['status'], 'problem' => nibblySeoCheckProblem($home), 'finalUrl' => $home['finalUrl'],
        'redirected' => nibblySeoCheckHost($home['finalUrl']) !== $baseHost && $home['finalUrl'] !== ''];
    if ($home['status'] >= 200 && $home['status'] < 300) {
        $facts['home']['noindexMeta'] = (bool)array_filter(nibblySeoCheckMetaRobots($home['body']), 'nibblySeoCheckHasNoindex');
        $facts['home']['noindexHeader'] = nibblySeoCheckHasNoindex((string)($home['headers']['x-robots-tag'] ?? ''));
    }

    $sitemap = $responses['sitemap'];
    $facts['sitemap'] = ['status' => $sitemap['status'], 'problem' => nibblySeoCheckProblem($sitemap)];
    if ($sitemap['status'] >= 200 && $sitemap['status'] < 300) {
        $parsed = nibblySeoCheckParseSitemap($sitemap['body']);
        $facts['sitemap']['valid'] = $parsed !== null;
        if ($parsed !== null) {
            $foreign = array_values(array_filter($parsed['locs'], fn($url) => nibblySeoCheckHost($url) !== $baseHost));
            $plain = $basePlain ? array_values(array_filter($parsed['locs'], fn($url) => str_starts_with(strtolower($url), 'http://'))) : [];
            $facts['sitemap'] += ['count' => count($parsed['locs']), 'foreign' => count($foreign), 'foreignExample' => $foreign[0] ?? '',
                'plain' => count($plain), 'plainExample' => $plain[0] ?? ''];
        }
    }
    return $facts;
}

/** Request the three addresses; this is the only part that needs the network. */
function nibblySeoCheckRun(string $baseUrl): array {
    $result = ['version' => 1, 'baseUrl' => $baseUrl, 'checkedAt' => date('c'), 'checkedTs' => time(),
        'signature' => nibblySeoCheckSignature()];
    if (!function_exists('curl_init')) {
        return $result + ['facts' => null, 'error' => 'no_curl'];
    }
    $insecure = false;
    $responses = [];
    foreach (['robots' => '/robots.txt', 'home' => '/', 'sitemap' => '/sitemap.xml'] as $key => $path) {
        $responses[$key] = nibblySecurityHttpFetch($baseUrl . $path, $insecure, 5, NIBBLY_SEO_CHECK_BODY_LIMIT);
    }
    return $result + ['facts' => nibblySeoCheckFacts($responses, $baseUrl), 'error' => ''];
}

/** Changes to what the cached facts depend on (settings, static files) invalidate them. */
function nibblySeoCheckSignature(): string {
    $root = dirname(__DIR__);
    $seo = nibblySeoSettings()['seo'] ?? [];
    return md5(json_encode([!empty($seo['noindexSite']), (string)($seo['siteUrl'] ?? ''),
        @filemtime($root . '/robots.txt') ?: 0, @filemtime($root . '/sitemap.xml') ?: 0]));
}

// ---------------------------------------------------------------------------
// Settings (no network) and evaluation (pure)
// ---------------------------------------------------------------------------

function nibblySeoCheckSettingsFacts(): array {
    $root = dirname(__DIR__);
    $settings = nibblySeoSettings();
    $maintenance = $settings['access']['maintenance'] ?? [];
    $active = false;
    if (is_array($maintenance) && !empty($maintenance['enabled'])) {
        $until = trim((string)($maintenance['until'] ?? ''));
        $active = $until === '' || (strtotime($until) ?: PHP_INT_MAX) > time();
    }
    $total = $noindex = 0;
    foreach (glob($root . '/content/pages/[a-z][a-z]_*.json') ?: [] as $file) {
        $page = json_decode((string)file_get_contents($file), true);
        if (!is_array($page) || ($page['visibility']['status'] ?? 'public') === 'private') continue;
        $total++;
        if (stripos((string)($page['seo']['robots'] ?? ''), 'noindex') !== false) $noindex++;
    }
    return [
        'noindexSite' => !empty($settings['seo']['noindexSite']),
        'siteUrl' => trim((string)($settings['seo']['siteUrl'] ?? '')),
        'maintenance' => $active,
        'pages' => ['total' => $total, 'noindex' => $noindex],
        'robotsFile' => is_file($root . '/robots.txt'),
        'sitemapFile' => is_file($root . '/sitemap.xml'),
    ];
}

/**
 * Items for the dashboard from cached $facts (null: not checked yet) and the current settings.
 * Item states: ok, warning, note, unknown, pending.
 */
function nibblySeoCheckEvaluate(?array $facts, array $settings, string $baseUrl): array {
    $items = [];
    $blockedBySetting = $settings['noindexSite'];
    $pending = fn(string $id) => ['id' => $id, 'state' => 'pending'];
    // robots.txt, home page and sitemap share the way they can fail.
    $judge = function (string $id, string $url, ?array $part) use ($pending) {
        if ($part === null) return $pending($id);
        if ($part['problem']) return ['id' => $id, 'url' => $url, 'state' => 'unknown'] + $part['problem'];
        if ($part['status'] < 200 || $part['status'] >= 300) {
            return ['id' => $id, 'url' => $url, 'state' => 'warning', 'reason' => 'status', 'detail' => 'HTTP ' . $part['status'], 'fix' => 'status'];
        }
        return null;
    };

    $robotsUrl = $baseUrl . '/robots.txt';
    $part = $facts['robots'] ?? null;
    $item = $judge('robots_txt', $robotsUrl, $part);
    if ($item === null) {
        $item = ['id' => 'robots_txt', 'url' => $robotsUrl, 'state' => 'ok'];
        if ($part['blocked']) {
            $source = $settings['robotsFile'] ? 'file' : ($blockedBySetting ? 'setting' : 'unknown');
            $item = ['id' => 'robots_txt', 'url' => $robotsUrl, 'state' => 'warning', 'reason' => 'blocked', 'source' => $source, 'fix' => 'blocked_' . $source];
        } elseif ($part['foreignSitemap'] !== '') {
            $item = ['id' => 'robots_txt', 'url' => $robotsUrl, 'state' => 'warning', 'reason' => 'sitemap_host', 'detail' => $part['foreignSitemap'], 'fix' => 'sitemap_host'];
        } elseif ($part['sitemaps'] === 0 && !$blockedBySetting) {
            $item = ['id' => 'robots_txt', 'url' => $robotsUrl, 'state' => 'note', 'reason' => 'no_sitemap', 'fix' => 'no_sitemap'];
        }
    }
    $items[] = $item;

    $homeUrl = $baseUrl . '/';
    $part = $facts['home'] ?? null;
    $item = $judge('page_robots', $homeUrl, $part);
    if ($item === null) {
        $where = array_keys(array_filter(['meta' => $part['noindexMeta'], 'header' => $part['noindexHeader']]));
        $item = ['id' => 'page_robots', 'url' => $homeUrl, 'state' => 'ok'];
        if ($part['redirected']) {
            $item = ['id' => 'page_robots', 'url' => $homeUrl, 'state' => 'warning', 'reason' => 'redirect', 'detail' => $part['finalUrl'], 'fix' => 'redirect'];
        } elseif ($where) {
            $source = $blockedBySetting ? 'setting' : 'other';
            $item = ['id' => 'page_robots', 'url' => $homeUrl, 'state' => 'warning', 'reason' => 'noindex', 'where' => $where, 'source' => $source, 'fix' => 'noindex_' . $source];
        }
    }
    $items[] = $item;

    $sitemapUrl = $baseUrl . '/sitemap.xml';
    $part = $facts['sitemap'] ?? null;
    $item = $judge('sitemap', $sitemapUrl, $part);
    if ($item === null) {
        $base = ['id' => 'sitemap', 'url' => $sitemapUrl];
        if (!$part['valid']) {
            $item = $base + ['state' => 'warning', 'reason' => 'not_xml', 'fix' => 'not_xml'];
        } elseif ($part['count'] === 0) {
            $item = $blockedBySetting ? $base + ['state' => 'note', 'reason' => 'empty_blocked', 'fix' => 'empty_blocked']
                : $base + ['state' => 'warning', 'reason' => 'empty', 'fix' => 'empty'];
        } elseif ($part['foreign'] > 0) {
            $item = $base + ['state' => 'warning', 'reason' => 'host', 'detail' => $part['foreignExample'], 'count' => $part['foreign'], 'fix' => 'host'];
        } elseif ($part['plain'] > 0) {
            $item = $base + ['state' => 'warning', 'reason' => 'http', 'detail' => $part['plainExample'], 'count' => $part['plain'], 'fix' => 'http'];
        } else {
            $item = $base + ['state' => 'ok', 'count' => $part['count']];
        }
    }
    $items[] = $item;

    // Settings only, so these also work when the site cannot request itself.
    $siteUrl = $settings['siteUrl'];
    $item = ['id' => 'site_url', 'state' => 'ok', 'url' => $siteUrl];
    if ($siteUrl !== '' && $baseUrl !== '') {
        if (nibblySeoCheckHost($siteUrl) !== nibblySeoCheckHost($baseUrl)) {
            $item = ['id' => 'site_url', 'state' => 'warning', 'reason' => 'host', 'url' => $siteUrl, 'detail' => (string)parse_url($baseUrl, PHP_URL_HOST), 'fix' => 'host'];
        } elseif (str_starts_with($baseUrl, 'https://') && stripos($siteUrl, 'http://') === 0) {
            $item = ['id' => 'site_url', 'state' => 'warning', 'reason' => 'scheme', 'url' => $siteUrl, 'fix' => 'scheme'];
        }
    }
    $items[] = $item;

    $pages = $settings['pages'];
    $items[] = $pages['noindex'] > 0
        ? ['id' => 'page_noindex', 'state' => 'note', 'count' => $pages['noindex'], 'total' => $pages['total'], 'fix' => 'note']
        : ['id' => 'page_noindex', 'state' => 'ok'];

    $items[] = $settings['maintenance'] ? ['id' => 'maintenance', 'state' => 'warning', 'fix' => 'warning'] : ['id' => 'maintenance', 'state' => 'ok'];
    return $items;
}

// ---------------------------------------------------------------------------
// Cache and status for the dashboard
// ---------------------------------------------------------------------------

function nibblySeoCheckCachePath(): string {
    return dirname(__DIR__) . '/content/seo-check.json';
}

/** Re-check soon while something is wrong; a clean result stays valid for 12 hours. */
function nibblySeoCheckCacheTtl(array $facts): int {
    foreach (['robots', 'home', 'sitemap'] as $part) {
        if (!empty($facts[$part]['problem']) || ($facts[$part]['status'] ?? 0) >= 300 || !empty($facts[$part]['blocked'])
            || !empty($facts[$part]['noindexMeta']) || !empty($facts[$part]['noindexHeader'])) return 3600;
    }
    return 43200;
}

/** Cached facts for this address, refreshed when $run allows it. */
function nibblySeoCheckCachedResult(string $baseUrl, bool $run, bool $force): array {
    $path = nibblySeoCheckCachePath();
    $cached = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    // A restored backup or a moved site must not inherit another address's result.
    if (!is_array($cached) || ($cached['baseUrl'] ?? '') !== $baseUrl || !isset($cached['checkedTs'], $cached['signature'])) {
        $cached = null;
    }
    $stale = !$cached || $cached['signature'] !== nibblySeoCheckSignature()
        || time() - (int)$cached['checkedTs'] > (is_array($cached['facts'] ?? null) ? nibblySeoCheckCacheTtl($cached['facts']) : 3600);
    $running = false;
    if ($run && ($force || $stale)) {
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || flock($lock, LOCK_EX | LOCK_NB)) {
            try {
                $cached = nibblySeoCheckRun($baseUrl);
                nibblyJsonAtomicWrite($path, $cached);
                $stale = false;
            } finally {
                if ($lock) flock($lock, LOCK_UN);
            }
        } else {
            $running = true;
        }
        if ($lock) fclose($lock);
    }
    return ['result' => $cached, 'stale' => $stale, 'running' => $running];
}

/** SEO findings for the system status and the dashboard banner. $run allows the network requests. */
function nibblySeoCheckStatus(string $scheme = '', bool $run = false, bool $force = false): array {
    $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme : (nibblySecurityServerHttps() ? 'https' : 'http');
    $local = nibblySecurityIsLocalHost((string)($_SERVER['HTTP_HOST'] ?? ''));
    $devServer = PHP_SAPI === 'cli-server';
    $baseUrl = nibblySecurityBaseUrl($scheme);
    $settings = nibblySeoCheckSettingsFacts();
    $status = ['local' => $local, 'devServer' => $devServer, 'canRun' => false, 'stale' => false, 'running' => false,
        'checkedAt' => null, 'blocked' => false, 'noindexSite' => $settings['noindexSite'], 'items' => []];

    if ($local) {
        // Development phase: nothing to check.
        foreach (['robots_txt', 'page_robots', 'sitemap', 'site_url', 'page_noindex', 'maintenance'] as $id) {
            $status['items'][] = ['id' => $id, 'state' => 'skipped', 'reason' => 'local'];
        }
        return $status;
    }

    $facts = null;
    $unavailable = '';
    if ($devServer) {
        // The single-threaded development server cannot request itself.
        $unavailable = 'dev_server';
    } elseif ($baseUrl === '') {
        $unavailable = 'host';
    } else {
        $status['canRun'] = true;
        $cache = nibblySeoCheckCachedResult($baseUrl, $run, $force);
        $result = $cache['result'];
        $status['stale'] = $cache['stale'];
        $status['running'] = $cache['running'];
        $status['checkedAt'] = $result['checkedAt'] ?? null;
        $facts = is_array($result['facts'] ?? null) ? $result['facts'] : null;
        if ($result && ($result['error'] ?? '') === 'no_curl') $unavailable = 'no_curl';
    }

    $items = nibblySeoCheckEvaluate($facts, $settings, $baseUrl);
    if ($unavailable !== '') {
        foreach ($items as &$item) {
            if (in_array($item['id'], ['robots_txt', 'page_robots', 'sitemap'], true)) {
                $item = ['id' => $item['id'], 'state' => $unavailable === 'dev_server' ? 'skipped' : 'unknown', 'reason' => $unavailable];
            }
        }
        unset($item);
    }
    foreach ($items as $item) {
        if ($item['state'] === 'warning' && in_array($item['reason'] ?? '', ['blocked', 'noindex'], true)) $status['blocked'] = true;
    }
    $status['items'] = $items;
    return $status;
}
