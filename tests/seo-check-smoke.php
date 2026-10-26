<?php
/**
 * Smoke test for the search engine check (includes/seo-check.php): parsers, evaluation of the
 * findings, the site-wide block setting and the translations. No network except one local PHP server.
 *
 * Run from the repository root (best in a disposable copy, see tests/run-smoke.py):
 * php tests/seo-check-smoke.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/seo-check.php';

function seoCheckAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// --- robots.txt ---------------------------------------------------------------------------------
seoCheckAssert(nibblySeoCheckRobotsBlocksRoot("User-agent: *\nDisallow: /\n"), 'Disallow: / blocks the site');
seoCheckAssert(nibblySeoCheckRobotsBlocksRoot("\xEF\xBB\xBFUser-agent: *\r\nDisallow: /\r\n"), 'BOM and CRLF are tolerated');
seoCheckAssert(nibblySeoCheckRobotsBlocksRoot("User-agent: *\nDisallow: /*\n"), 'Disallow: /* blocks the site');
seoCheckAssert(!nibblySeoCheckRobotsBlocksRoot("User-agent: *\nDisallow: /admin/\nDisallow: /api/\n"), 'blocking only internal folders is fine');
seoCheckAssert(!nibblySeoCheckRobotsBlocksRoot("User-agent: *\nDisallow:\n"), 'an empty Disallow allows everything');
seoCheckAssert(!nibblySeoCheckRobotsBlocksRoot("User-agent: *\nDisallow: / # staging\nAllow: /\n"), 'Allow: / wins a tie with Disallow: /');
seoCheckAssert(!nibblySeoCheckRobotsBlocksRoot("User-agent: Googlebot\nAllow: /\n\nUser-agent: *\nDisallow: /\n"), 'a Googlebot group replaces the * group');
seoCheckAssert(!nibblySeoCheckRobotsBlocksRoot("User-agent: ia_archiver\nDisallow: /\n"), 'blocking another crawler does not block the site');
seoCheckAssert(nibblySeoCheckRobotsSitemaps("Sitemap: https://a.example/sitemap.xml\nsitemap: https://a.example/two.xml") === ['https://a.example/sitemap.xml', 'https://a.example/two.xml'], 'Sitemap lines are read');

// --- noindex in meta tags and headers -----------------------------------------------------------
seoCheckAssert(nibblySeoCheckHasNoindex('noindex, nofollow'), 'noindex is detected');
seoCheckAssert(nibblySeoCheckHasNoindex('NONE'), 'none means noindex');
seoCheckAssert(nibblySeoCheckHasNoindex('googlebot: noindex'), 'a Googlebot-only noindex counts');
seoCheckAssert(!nibblySeoCheckHasNoindex('bingbot: noindex'), 'noindex for another crawler does not count');
seoCheckAssert(!nibblySeoCheckHasNoindex('index, follow'), 'index, follow is no block');
seoCheckAssert(!nibblySeoCheckHasNoindex('unavailable_after: 25 Jun 2030 15:00:00 PST'), 'unavailable_after is no block');
$html = '<head><META CONTENT="noindex, nofollow" name=\'robots\'><meta name="description" content="noindex"></head>';
seoCheckAssert(nibblySeoCheckMetaRobots($html) === ['noindex, nofollow'], 'meta robots is found regardless of attribute order and case');

// --- sitemap ------------------------------------------------------------------------------------
$sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://a.example/</loc></url><url><loc>https://a.example/x/</loc></url></urlset>';
seoCheckAssert(nibblySeoCheckParseSitemap($sitemap) === ['type' => 'urlset', 'locs' => ['https://a.example/', 'https://a.example/x/']], 'a sitemap is parsed');
seoCheckAssert(nibblySeoCheckParseSitemap('<html><body>Wartung</body></html>') === null, 'an HTML page is no sitemap');
seoCheckAssert(nibblySeoCheckParseSitemap('<!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset>&x;</urlset>') === null, 'a sitemap with a DTD is refused');
seoCheckAssert(nibblySeoCheckParseSitemap('<sitemapindex><sitemap><loc>https://a.example/s.xml</loc></sitemap></sitemapindex>')['type'] === 'sitemapindex', 'a sitemap index is accepted');

// --- evaluation ---------------------------------------------------------------------------------
$base = 'https://live.example';
$response = fn(int $status = 200, string $body = '', array $headers = [], string $final = '') => [
    'status' => $status, 'body' => $body, 'headers' => $headers, 'error' => $status ? '' : 'timeout', 'finalUrl' => $final ?: $base . '/'];
$goodRobots = "User-agent: *\nDisallow: /admin/\n\nSitemap: $base/sitemap.xml\n";
$goodHome = '<html><head><meta name="robots" content="index, follow"></head></html>';
$goodMap = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . $base . '/</loc></url></urlset>';
$settings = ['noindexSite' => false, 'siteUrl' => '', 'maintenance' => false, 'pages' => ['total' => 3, 'noindex' => 0],
    'robotsFile' => false, 'sitemapFile' => false];
$evaluate = function (array $override = [], array $settingsOverride = []) use ($response, $base, $goodRobots, $goodHome, $goodMap, $settings): array {
    $responses = $override + ['robots' => $response(200, $goodRobots), 'home' => $response(200, $goodHome), 'sitemap' => $response(200, $goodMap)];
    $items = nibblySeoCheckEvaluate(nibblySeoCheckFacts($responses, $base), $settingsOverride + $settings, $base);
    return array_column($items, null, 'id');
};
$seen = [];
$state = function (array $items, string $id) use (&$seen): string {
    $seen[] = $items[$id];
    return $items[$id]['state'] . ($items[$id]['reason'] ?? '' ? ':' . $items[$id]['reason'] : '');
};

$items = $evaluate();
foreach (['robots_txt', 'page_robots', 'sitemap', 'site_url', 'page_noindex', 'maintenance'] as $id) {
    seoCheckAssert($state($items, $id) === 'ok', "a clean live site is ok: $id (" . $state($items, $id) . ')');
}

$items = $evaluate(['robots' => $response(200, "User-agent: *\nDisallow: /\n")], ['noindexSite' => true]);
seoCheckAssert($state($items, 'robots_txt') === 'warning:blocked' && $items['robots_txt']['source'] === 'setting', 'Disallow: / from the setting is found');
$items = $evaluate(['robots' => $response(200, "User-agent: *\nDisallow: /\n")], ['robotsFile' => true]);
seoCheckAssert($items['robots_txt']['source'] === 'file' && $items['robots_txt']['fix'] === 'blocked_file', 'a static robots.txt is named as the source');
$items = $evaluate(['robots' => $response(200, "User-agent: *\nDisallow: /\n")]);
seoCheckAssert($items['robots_txt']['source'] === 'unknown', 'an unknown source is reported as such');
$items = $evaluate(['robots' => $response(200, "User-agent: *\nDisallow: /admin/\nSitemap: https://staging.example/sitemap.xml\n")]);
seoCheckAssert($state($items, 'robots_txt') === 'warning:sitemap_host', 'a sitemap line for the staging domain is found');
$items = $evaluate(['robots' => $response(200, "User-agent: *\nDisallow: /admin/\n")]);
seoCheckAssert($state($items, 'robots_txt') === 'note:no_sitemap', 'a missing sitemap line is a note');
$items = $evaluate(['robots' => $response(404)]);
seoCheckAssert($state($items, 'robots_txt') === 'warning:status', 'a missing robots.txt is reported');

$items = $evaluate(['home' => $response(200, '<meta name="robots" content="noindex, nofollow">')], ['noindexSite' => true]);
seoCheckAssert($state($items, 'page_robots') === 'warning:noindex' && $items['page_robots']['where'] === ['meta'] && $items['page_robots']['source'] === 'setting', 'noindex in the meta tag is found');
$items = $evaluate(['home' => $response(200, $goodHome, ['x-robots-tag' => 'noindex'])]);
seoCheckAssert($items['page_robots']['where'] === ['header'] && $items['page_robots']['source'] === 'other', 'an X-Robots-Tag the settings do not explain is reported as other');
$items = $evaluate(['home' => $response(200, $goodHome, [], 'https://staging.example/')]);
seoCheckAssert($state($items, 'page_robots') === 'warning:redirect', 'a redirect to the staging domain is found');
$items = $evaluate(['home' => $response(200, '<meta name="robots" content="noindex">', [], 'https://www.live.example/')]);
seoCheckAssert($items['page_robots']['reason'] === 'noindex', 'www and non-www count as the same site');

$items = $evaluate(['sitemap' => $response(200, '<urlset><url><loc>https://staging.example/a</loc></url><url><loc>' . $base . '/</loc></url></urlset>')]);
seoCheckAssert($state($items, 'sitemap') === 'warning:host' && $items['sitemap']['count'] === 1, 'sitemap addresses on another domain are counted');
$items = $evaluate(['sitemap' => $response(200, '<urlset><url><loc>http://live.example/a</loc></url></urlset>')]);
seoCheckAssert($state($items, 'sitemap') === 'warning:http', 'http:// addresses in the sitemap of an https site are found');
$items = $evaluate(['sitemap' => $response(200, '<urlset></urlset>')]);
seoCheckAssert($state($items, 'sitemap') === 'warning:empty', 'an empty sitemap is reported');
$items = $evaluate(['sitemap' => $response(200, '<urlset></urlset>')], ['noindexSite' => true]);
seoCheckAssert($state($items, 'sitemap') === 'note:empty_blocked', 'an empty sitemap of a blocked site is only a note');
$items = $evaluate(['sitemap' => $response(200, '<html>Wartung</html>')]);
seoCheckAssert($state($items, 'sitemap') === 'warning:not_xml', 'HTML instead of a sitemap is found');

$items = $evaluate([], ['siteUrl' => 'https://staging.example']);
seoCheckAssert($state($items, 'site_url') === 'warning:host', 'a siteUrl on another domain is found');
$items = $evaluate([], ['siteUrl' => 'http://live.example']);
seoCheckAssert($state($items, 'site_url') === 'warning:scheme', 'an http:// siteUrl on an https site is found');
$items = $evaluate([], ['siteUrl' => 'https://www.live.example/']);
seoCheckAssert($state($items, 'site_url') === 'ok', 'the www variant of the address is accepted');
$items = $evaluate([], ['pages' => ['total' => 4, 'noindex' => 1]]);
seoCheckAssert($state($items, 'page_noindex') === 'note', 'single noindex pages are a note, never an error');
$items = $evaluate([], ['maintenance' => true]);
seoCheckAssert($state($items, 'maintenance') === 'warning', 'active maintenance mode is found');

$items = $evaluate(['robots' => $response(401), 'home' => $response(401), 'sitemap' => $response(401)]);
seoCheckAssert($items['robots_txt']['state'] === 'unknown' && $items['robots_txt']['reason'] === 'auth', 'an access protection cannot be judged');
$seen[] = $items['robots_txt'];
$items = $evaluate(['robots' => $response(0), 'home' => $response(0), 'sitemap' => $response(0)]);
seoCheckAssert($items['sitemap']['state'] === 'unknown' && $items['sitemap']['reason'] === 'unreachable', 'an unreachable own address is reported, not guessed');
$seen[] = $items['sitemap'];
$items = nibblySeoCheckEvaluate(null, $settings, $base);
seoCheckAssert($items[0]['state'] === 'pending', 'without a result the network items are pending');

// --- status: local hosts are skipped, others wait for a run -------------------------------------
$_SERVER['HTTP_HOST'] = 'localhost:3000';
$status = nibblySeoCheckStatus('http', true, true);
seoCheckAssert($status['local'] && !$status['canRun'] && !$status['blocked'], 'a local host is not checked');
seoCheckAssert(array_unique(array_column($status['items'], 'state')) === ['skipped'], 'all items of a local host are skipped');
$_SERVER['HTTP_HOST'] = 'live.example';
$status = nibblySeoCheckStatus('https');
seoCheckAssert(!$status['local'] && $status['canRun'] && $status['items'][0]['state'] === 'pending', 'a public host is checkable and starts as pending');

// --- the block setting acts everywhere -----------------------------------------------------------
$settingsFile = $root . '/content/settings.json';
$original = is_file($settingsFile) ? file_get_contents($settingsFile) : null;
@mkdir(dirname($settingsFile), 0755, true);
try {
    file_put_contents($settingsFile, json_encode(['seo' => ['noindexSite' => true, 'siteUrl' => $base]]));
    seoCheckAssert(nibblySeoRobots(['robots' => 'index, follow'], []) === 'noindex, nofollow', 'the site block overrides a page robots value');
    seoCheckAssert(nibblySeoRenderablePages() === [], 'a blocked site has an empty sitemap');
    $output = shell_exec('cd ' . escapeshellarg($root) . ' && php -r ' . escapeshellarg('require "includes/seo-helper.php"; nibblySeoServeRobots();'));
    seoCheckAssert(str_contains((string)$output, 'Disallow: /' . "\n") && !str_contains((string)$output, 'Sitemap:'), 'robots.txt of a blocked site blocks everything and names no sitemap');
    file_put_contents($settingsFile, json_encode(['seo' => ['noindexSite' => false, 'siteUrl' => $base]]));
    seoCheckAssert(nibblySeoRobots([], []) === 'index, follow', 'without the block pages stay indexable');
    $output = shell_exec('cd ' . escapeshellarg($root) . ' && php -r ' . escapeshellarg('require "includes/seo-helper.php"; nibblySeoServeRobots();'));
    seoCheckAssert(!str_contains((string)$output, "Disallow: /\n") && str_contains((string)$output, 'Sitemap: ' . $base . '/sitemap.xml'), 'robots.txt of a live site allows crawling and names the sitemap');
} finally {
    if ($original === null) @unlink($settingsFile); else file_put_contents($settingsFile, $original);
}

// --- fetching: headers of the final response, body limit ----------------------------------------
$router = sys_get_temp_dir() . '/nibbly-seo-check-router-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($router, '<?php
if ($_SERVER["REQUEST_URI"] === "/hop") { header("X-Robots-Tag: noindex-from-first-hop"); header("Location: /final"); return; }
header("X-Robots-Tag: noindex, nofollow"); header("Content-Type: text/plain"); echo str_repeat("x", 2000000);');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$port = (int)substr(strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
require_once __DIR__ . '/server-support.php';
$server = new NibblyTestServer([PHP_BINARY, '-S', "127.0.0.1:$port", $router]);
try {
    for ($wait = 0; $wait < 100 && !@fsockopen('127.0.0.1', $port); $wait++) usleep(50000);
    $insecure = false;
    $answer = nibblySecurityHttpFetch("http://127.0.0.1:$port/hop", $insecure, 5, 1000);
    seoCheckAssert($answer['status'] === 200 && $answer['headers']['x-robots-tag'] === 'noindex, nofollow', 'only the headers of the final response are kept');
    seoCheckAssert(strlen($answer['body']) === 1000 && $answer['error'] === '', 'the body is cut at the limit without an error');
    seoCheckAssert(nibblySecurityHttpFetch("http://127.0.0.1:$port/x", $insecure, 5)['body'] === '', 'without a limit no body is kept (security check)');
} finally {
    $server->close();
    @unlink($router);
}

// --- translations -------------------------------------------------------------------------------
$lang = [];
foreach (['de', 'en'] as $code) {
    $lang[$code] = json_decode((string)file_get_contents($root . "/admin/lang/$code.json"), true);
    seoCheckAssert(is_array($lang[$code]), "$code.json is valid");
}
$keys = fn(array $strings) => array_values(array_filter(array_keys($strings), fn($key) => str_starts_with((string)$key, 'seo_check.')));
seoCheckAssert(!array_diff($keys($lang['de']), $keys($lang['en'])) && !array_diff($keys($lang['en']), $keys($lang['de'])), 'German and English have the same seo_check keys');
// The same keys system.php asks for.
foreach (['robots_txt', 'page_robots', 'sitemap', 'site_url', 'page_noindex', 'maintenance'] as $id) $seen[] = ['id' => $id, 'state' => 'skipped', 'reason' => 'local'];
foreach ($seen as $item) {
    $needed = ['seo_check.' . $item['id']];
    $needed[] = 'seo_check.' . match ($item['state']) {
        'skipped' => 'skipped_' . ($item['reason'] ?? 'local'),
        'unknown' => 'unknown_' . ($item['reason'] ?? 'unreachable'),
        default => $item['id'] . '_' . ($item['reason'] ?? $item['state']),
    };
    if (in_array($item['state'], ['warning', 'note'], true)) $needed[] = 'seo_check.' . $item['id'] . '_expected';
    if (!empty($item['fix'])) $needed[] = 'seo_check.' . $item['id'] . '_fix_' . $item['fix'];
    foreach ($needed as $key) {
        foreach (['de', 'en'] as $code) seoCheckAssert(isset($lang[$code][$key]), "missing translation $key in $code.json");
    }
}
foreach (['settings.search_engines', 'settings.noindex_site', 'settings.site_url', 'system.badge', 'seo_check.banner_title',
    'seo_check.banner_text_setting', 'seo_check.banner_text_other', 'seo_check.allow', 'seo_check.allowed', 'seo_check.running',
    'seo_check.pending', 'seo_check.where_meta', 'seo_check.where_header', 'seo_check.unknown_fix'] as $key) {
    foreach (['de', 'en'] as $code) seoCheckAssert(isset($lang[$code][$key]), "missing translation $key in $code.json");
}

echo "PASS seo-check smoke\n";
