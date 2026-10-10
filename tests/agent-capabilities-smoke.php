<?php

declare(strict_types=1);

// Capability map: render scan, merge with JSON, hard filters, restricting overrides, cache.
// The orchestrator builds a throw-away site from tests/fixtures/agent/site and runs every
// scenario in its own PHP process (a custom template renders once per process):
//
//   php tests/agent-capabilities-smoke.php
//   php tests/agent-capabilities-smoke.php worker <scenario> <site> [args]   (internal)

function capAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Worker
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === 'worker') {
    [$scenario, $site] = [$argv[2], $argv[3]];
    $args = array_slice($argv, 4);
    define('SITE_LANG_DEFAULT', 'en');
    define('CONTENT_BASE_PATH', $site . '/content/pages/');
    require_once $site . '/includes/ai/agent/capabilities.php';
    $out = static function (array $data): void {
        echo "RESULT:" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    };
    $summary = static fn(array $r): array => ['cache' => $r['cache'], 'kind' => $r['kind'], 'descriptor' => $r['descriptor'], 'map' => $r['map'], 'warnings' => $r['warnings']];

    if ($scenario === 'caps') {
        ob_start();
        $cwd = getcwd();
        $get = $_GET;
        $_GET = ['probe' => '1'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $before = ['status' => http_response_code(), 'headers' => headers_list()];
        $result = nibblyAgentCapabilities($args[0], ['forceScan' => ($args[1] ?? '') === 'force']);
        $state = [
            'cwd' => getcwd() === $cwd, 'get' => $_GET === ['probe' => '1'], 'method' => $_SERVER['REQUEST_METHOD'] === 'POST',
            'scanOff' => !nibblyContentScanActive(), 'session' => session_status() === PHP_SESSION_NONE,
            'status' => http_response_code() === $before['status'], 'headers' => headers_list() === $before['headers'],
            'buffers' => ob_get_level() === 1, 'stray' => ob_get_clean(),
        ];
        $out($summary($result) + ['state' => $state]);
    } elseif ($scenario === 'standard-twice') {
        $first = nibblyAgentCapabilities('page:en_scan-standard');
        $second = nibblyAgentCapabilities('page:en_scan-standard');
        $third = nibblyAgentCapabilities('page:en_scan-standard', ['forceScan' => true]);
        $out(['caches' => [$first['cache'], $second['cache'], $third['cache']], 'same' => $first['descriptor'] === $second['descriptor'] && $second['descriptor'] === $third['descriptor']]);
    } elseif ($scenario === 'deferred') {
        $first = nibblyAgentCapabilities('page:en_scan-custom', ['forceScan' => true]);
        $deferred = false;
        try {
            nibblyAgentCapabilities('page:en_scan-second', ['forceScan' => true]);
        } catch (NibblyAgentScanDeferred $e) {
            $deferred = true;
        }
        $standard = nibblyAgentCapabilities('page:en_scan-standard', ['forceScan' => true]);
        $cachedAgain = nibblyAgentCapabilities('page:en_scan-custom');
        $out(['first' => $first['cache'], 'deferred' => $deferred, 'standardWorks' => $standard['cache'] === 'rescan' || $standard['cache'] === 'miss', 'cachedStillServed' => $cachedAgain['cache'] === 'hit']);
    } elseif ($scenario === 'exit') {
        echo "BEFORE\n";
        nibblyAgentCapabilities('page:en_scan-exit', ['onExit' => static function (string $reason): void { echo "EXIT-HANDLED:" . $reason . "\n"; }]);
        echo "NOT-REACHED\n";
    } elseif ($scenario === 'targets') {
        $invalid = [];
        foreach (['page:../x', 'page:en_home/../x', 'page:EN_home', 'page:en_', 'news:../a', 'news:UP', 'event:a/b', 'file:en_home', 'page:', 'en_home', 'page:en_home.json', "page:en_home\n", 'page:__proto__', 'news:a--b'] as $target) {
            try {
                nibblyAgentCapabilities($target);
                $invalid[$target] = 'accepted';
            } catch (InvalidArgumentException $e) {
                $invalid[$target] = 'rejected';
            }
        }
        $news = nibblyAgentCapabilities('news:2026-10-01-demo-post');
        $event = nibblyAgentCapabilities('event:2026-11-05-open-day');
        $footer = nibblyAgentCapabilities('page:footer');
        $out(['invalid' => $invalid, 'news' => $summary($news), 'event' => $summary($event), 'footer' => $summary($footer),
            'parsed' => [nibblyAgentParseTarget('page:en_a__b-c')['file'] ?? null, nibblyAgentParseTarget('page:sidebar')['kind'] ?? null]]);
    } elseif ($scenario === 'clear') {
        $out(['removed' => nibblyAgentClearCapabilityCache()]);
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Orchestrator
// ---------------------------------------------------------------------------
$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-capabilities-' . bin2hex(random_bytes(4));
mkdir($site . '/tests', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
foreach (['includes', 'admin', 'VERSION'] as $item) {
    exec('cp -R ' . escapeshellarg($root . '/' . $item) . ' ' . escapeshellarg($site . '/'));
}
foreach (['admin/config.php', 'admin/smtp-config.php', 'includes/nav-config.php'] as $local) {
    @unlink($site . '/' . $local); // never inherit a developer's local configuration
}
exec('cp -R ' . escapeshellarg($root . '/tests/fixtures/agent/site/.') . ' ' . escapeshellarg($site . '/'));
rename($site . '/lang-en', $site . '/en'); // the repository ignores en/, so the fixture is stored as lang-en/
copy(__FILE__, $site . '/tests/agent-capabilities-smoke.php');
mkdir($site . '/content/news', 0755, true);
file_put_contents($site . '/content/news/2026-10-01-demo-post.json', json_encode([
    'id' => '2026-10-01-demo-post', 'slug' => 'demo-post', 'lang' => 'en', 'title' => 'Demo post', 'date' => '2026-10-01',
    'author' => 'Team', 'excerpt' => 'Short', 'image' => '/assets/images/news.webp', 'content' => '<p>Body</p>', 'hidden' => false,
]));
file_put_contents($site . '/content/events.json', json_encode(['events' => [[
    'id' => '2026-11-05-open-day', 'date' => '2026-11-05', 'time' => '10:00', 'end-date' => '2026-11-05', 'end-time' => '16:00', 'url' => '',
    'title' => ['en' => 'Open day', 'de' => 'Tag der offenen Tür'], 'location' => ['en' => 'Hall'], 'description' => ['en' => '<p>Come</p>'],
    'admission' => ['en' => 'Free'], 'image' => '',
]]]));
file_put_contents($site . '/content/pages/footer.json', json_encode([
    'tagline' => ['en' => 'We build'], 'contact' => ['email' => 'info@example.org'], 'copyright' => '&copy; Fixture',
    'adminToken' => 'FOOTER-SECRET-TOKEN',
]));

function capRun(string $site, string $scenario, string ...$args): array {
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', $site . '/tests/agent-capabilities-smoke.php', 'worker', $scenario, $site, ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $site);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($process);
    foreach (explode("\n", $stdout) as $line) {
        if (str_starts_with($line, 'RESULT:')) {
            return json_decode(substr($line, 7), true, 512, JSON_THROW_ON_ERROR);
        }
    }
    return ['_stdout' => $stdout, '_stderr' => $stderr];
}

function capSnapshot(string $site): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site . '/content', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($site) + 1);
        if ($file->isFile() && !str_starts_with($relative, 'content/ai-agent/')) $files[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return $files;
}

function capRenders(string $site, string $name): int {
    return (int)@filesize($site . '/render-count-' . $name . '.txt');
}

function capField(array $descriptor, string $path): ?array {
    foreach ($descriptor['fields'] as $field) if ($field['p'] === $path) return $field;
    return null;
}

function capList(array $descriptor, string $key): ?array {
    foreach ($descriptor['lists'] ?? [] as $list) if ($list['key'] === $key) return $list;
    return null;
}

function capEditJson(string $file, callable $edit): void {
    $data = json_decode((string)file_get_contents($file), true);
    $edit($data);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$pageFile = $site . '/content/pages/en_scan-custom.json';
$content = capSnapshot($site);

// ---- First scan: structure, types, filters, overrides ---------------------------
$one = capRun($site, 'caps', 'page:en_scan-custom');
capAssert(isset($one['descriptor']), 'custom page scan failed: ' . json_encode($one));
capAssert($one['cache'] === 'miss' && $one['kind'] === 'custom-template' && capRenders($site, 'custom') === 1, 'first scan renders the template once (' . $one['cache'] . ')');
$d = $one['descriptor'];
capAssert($d['target'] === 'page:en_scan-custom' && $d['lang'] === 'en', 'descriptor identity');
capAssert(capSnapshot($site) === $content, 'scanning must leave every content file byte-identical (no auto-write of missing fields)');
capAssert(!is_dir($site . '/content/analytics') && !is_dir($site . '/content/ai-image-jobs'), 'scanning is not a visit: no analytics, no other state');
$stray = $one['state']['stray'];
unset($one['state']['stray']);
capAssert(array_filter($one['state']) === $one['state'] && $stray === '', 'globals, cwd, headers, status, buffers and scan mode are restored and nothing leaks into the output: ' . json_encode($one['state']));
capAssert($one['warnings'] === [], 'the stock header and footer render without warnings: ' . json_encode($one['warnings']));

$title = capField($d, 'hero.title');
capAssert($title && $title['t'] === 'text' && $title['v'] === 'Welcome' && $title['max'] === 40 && !empty($title['req']), 'text field with override limits');
$tagline = capField($d, 'hero.tagline');
capAssert($tagline && $tagline['v'] === 'Default tagline' && $tagline['max'] === 25, 'a field missing from the JSON shows its template default');
$body = capField($d, 'hero.body');
capAssert($body && $body['t'] === 'html' && $body['v'] === 'Hello world' && $body['max'] === 20000 && empty($body['req']), 'html field; an override can neither raise a limit nor lift a requirement');
$image = capField($d, 'hero.image');
capAssert($image && $image['t'] === 'image' && $image['fmt'] === 'object' && $image['aspect'] === '16:9' && $image['credit'] === 'hero.credit', 'object image with aspect and credit path');
$split = capField($d, 'hero.photo');
capAssert($split && $split['fmt'] === 'split' && $split['alt'] === 'hero.photoAlt', 'split image carries its alt path');
$cover = capField($d, 'gallery.cover');
capAssert($cover && $cover['fmt'] === 'string' && !isset($cover['aspect']), 'a JSON string image stays a string; an invalid aspect is dropped');
$note = capField($d, 'hero.note');
capAssert($note && !empty($note['hid']), 'hidden fields are marked');
capAssert(capField($d, 'hero.cta') === null && capField($d, 'hero.icon') === null, 'fields made read-only by overrides leave the editable list');
foreach (['hero.cta', 'hero.icon', 'contact.email'] as $path) capAssert(in_array($path, $d['readOnly'], true), "{$path} is read-only");
foreach (['hero.type' => 'structural', 'seo.title' => 'protected', 'contact.apiKey' => 'sensitive', 'hero.mirror' => 'mirrored (data-nb-bind)'] as $path => $why) {
    capAssert(capField($d, $path) === null && in_array($path, $d['readOnly'], true), "{$path} ({$why}) is never editable");
}
capAssert(capField($d, 'does.not.exist') === null && !in_array('does.not.exist', $d['readOnly'], true), 'overrides cannot add fields');
capAssert($d['shared'] === [['page' => 'sidebar', 'path' => 'promo.title']], 'fields of another page are marked as shared');
capAssert(capField($d, 'promo.title') === null, 'shared fields are not editable on this page');

$features = capList($d, 'features.items');
capAssert($features && $features['count'] === 3 && $features['min'] === 2 && $features['max'] === 6 && $features['hideSupported'] === true, 'list limits from the overrides');
capAssert($features['item'] === ['title' => 'text', 'text' => 'textarea', 'image' => 'image:object', 'icon' => 'icon'], 'item schema from the group schema: ' . json_encode($features['item']));
$about = capList($d, 'about.paragraphs');
capAssert($about && $about['count'] === 2 && $about['max'] === 24 && $about['min'] === 0 && $about['item'] === ['content' => 'html'], 'text list: a higher maxItems and a negative minItems are ignored');
$faq = capList($d, 'faq.items');
capAssert($faq && $faq['count'] === 0 && $faq['max'] === 3 && $faq['min'] === 0 && $faq['item'] === ['question' => 'text', 'answer' => 'text', 'link' => 'link'], 'empty list: item schema from the defaults, contradictory minimum ignored: ' . json_encode($faq));
capAssert(capList($d, 'missing.list') === null, 'overrides cannot add lists');
capAssert(array_slice($d['hints'], 0, 2) === ['Write in a friendly tone', 'Never mention competitors'] && in_array('Titles have at most four words', $d['hints'], true), 'hints from _site.json and the page file');
capAssert(!in_array('contact.apiKey', array_column($d['fields'], 'p'), true), 'sensitive field not listed as editable');
$json = json_encode($d);
foreach (['SECRET-KEY-123', 'FixtureHashValueThatMustNeverLeak', 'team@example.org', 'passwordHash'] as $secret) {
    capAssert(!str_contains($json, $secret), "the descriptor must not leak {$secret}");
}
capAssert(strlen($json) < 5000, 'the descriptor stays compact (' . strlen($json) . ' bytes)');

// ---- Overrides only restrict ---------------------------------------------------
$plain = $site . '/content/ai-schema';
rename($plain, $site . '/ai-schema-off');
$free = capRun($site, 'caps', 'page:en_scan-custom');
rename($site . '/ai-schema-off', $plain);
capAssert($free['cache'] === 'hit' && ($free['descriptor']['hints'] ?? []) === [], 'without override files nothing is restricted');
$freeFields = array_column($free['descriptor']['fields'], null, 'p');
capAssert(isset($freeFields['hero.cta'], $freeFields['hero.icon']) && $freeFields['hero.title']['max'] === 160 && empty($freeFields['hero.title']['req']), 'defaults without overrides');
foreach ($d['fields'] as $field) {
    $base = $freeFields[$field['p']] ?? null;
    capAssert($base !== null, "{$field['p']} exists without overrides too");
    capAssert(($field['max'] ?? PHP_INT_MAX) <= ($base['max'] ?? PHP_INT_MAX) && (!empty($field['req']) || empty($base['req'])), "{$field['p']} is not less restricted than the default");
}
capAssert(array_diff(array_column($d['fields'], 'p'), array_column($free['descriptor']['fields'], 'p')) === [], 'overrides never add a field');
capAssert(!array_diff($free['descriptor']['readOnly'], $d['readOnly']), 'read-only paths only grow with overrides');
file_put_contents($plain . '/page_en_scan-custom.json', '{not json');
$broken = capRun($site, 'caps', 'page:en_scan-custom');
capAssert(isset($broken['descriptor']) && capField($broken['descriptor'], 'hero.title')['max'] === 160 && str_contains(implode(' ', $broken['warnings']), 'invalid AI schema'), 'an invalid override file is ignored with a warning');
copy($root . '/tests/fixtures/agent/site/content/ai-schema/page_en_scan-custom.json', $plain . '/page_en_scan-custom.json');

// ---- Cache ------------------------------------------------------------------------
$two = capRun($site, 'caps', 'page:en_scan-custom');
capAssert($two['cache'] === 'hit' && capRenders($site, 'custom') === 1 && $two['descriptor'] === $d, 'unchanged page: cache hit, template not rendered again');

capEditJson($pageFile, static function (&$data) { $data['hero']['title'] = 'Changed headline'; });
$content = capSnapshot($site);
$three = capRun($site, 'caps', 'page:en_scan-custom');
capAssert($three['cache'] === 'remix' && capField($three['descriptor'], 'hero.title')['v'] === 'Changed headline' && capRenders($site, 'custom') === 1, 'edited value: values re-mixed from the JSON without a new scan');

capEditJson($pageFile, static function (&$data) { $data['features']['items'][3] = ['title' => 'Four', 'text' => 'More', 'image' => ['src' => '', 'alt' => ''], 'icon' => 'star']; });
$four = capRun($site, 'caps', 'page:en_scan-custom');
capAssert($four['cache'] === 'rescan' && capList($four['descriptor'], 'features.items')['count'] === 4 && capRenders($site, 'custom') === 2, 'a list that no longer matches the scan is scanned again');
capAssert(capRun($site, 'caps', 'page:en_scan-custom')['cache'] === 'hit', 'and cached afterwards');

capEditJson($pageFile, static function (&$data) { $data['hero']['video'] = 'abc'; });
capAssert(capRun($site, 'caps', 'page:en_scan-custom')['cache'] === 'rescan' && capRenders($site, 'custom') === 3, 'a new key can change what the template renders: scan again');

touch($site . '/en/scan-custom.php', time() + 30);
$five = capRun($site, 'caps', 'page:en_scan-custom');
capAssert($five['cache'] === 'rescan' && capRenders($site, 'custom') === 4, 'touching the template invalidates the cache');
capAssert(capRun($site, 'caps', 'page:en_scan-custom')['cache'] === 'hit', 'cache valid again');

file_put_contents($site . '/VERSION', "9.9.9\n");
capAssert(capRun($site, 'caps', 'page:en_scan-custom')['cache'] === 'rescan' && capRenders($site, 'custom') === 5, 'a new core version invalidates the cache');
capAssert(capRun($site, 'caps', 'page:en_scan-custom', 'force')['cache'] === 'rescan' && capRenders($site, 'custom') === 6, 'forceScan always scans');
$cleared = capRun($site, 'clear');
capAssert($cleared['removed'] >= 1, 'cache can be cleared');
capAssert(capRun($site, 'caps', 'page:en_scan-custom')['cache'] === 'miss' && capRenders($site, 'custom') === 7, 'first scan after clearing');
capAssert(capSnapshot($site) === array_replace($content, capSnapshot($site)) && capSnapshot($site)['content/pages/en_scan-custom.json'] === hash_file('sha256', $pageFile), 'content untouched by all those scans');

// ---- Standard pages ---------------------------------------------------------------
$std = capRun($site, 'caps', 'page:en_scan-standard');
$sd = $std['descriptor'];
capAssert($std['kind'] === 'standard-page' && $std['cache'] === 'miss', 'standard page');
$expected = ['sections.0.text' => 'text', 'sections.0.subtitle' => 'text', 'sections.1.title' => 'text', 'sections.1.content' => 'html', 'sections.2.src' => 'image', 'sections.2.caption' => 'text', 'sections.3.image' => 'image', 'sections.3.title' => 'text', 'sections.3.content' => 'textarea'];
capAssert(array_column($sd['fields'], 't', 'p') === $expected, 'section fields: ' . json_encode(array_column($sd['fields'], 't', 'p')));
capAssert(capField($sd, 'sections.2.src')['fmt'] === 'split' && capField($sd, 'sections.2.src')['alt'] === 'sections.2.alt', 'image block uses split src/alt');
capAssert(array_column($sd['sections'], 'type', 'i') === [0 => 'heading', 1 => 'text', 2 => 'image', 3 => 'card'] && $sd['sections'][3]['hidden'] === true && !$sd['sections'][0]['hidden'], 'section overview with hidden state');
capAssert(in_array('text', $sd['addable'], true) && in_array('card', $sd['addable'], true), 'addable block types');
capAssert(!isset($d['sections']) && !isset($d['addable']), 'a template page without sections has no section overview');
foreach (['sections.0.level', 'sections.1.titleTag', 'sections.0.id', 'sections.0.type', 'sections.2.width'] as $structural) {
    capAssert(capField($sd, $structural) === null, "{$structural} is not editable");
}
$again = capRun($site, 'standard-twice');
capAssert($again['caches'] === ['hit', 'hit', 'rescan'] && $again['same'], 'standard pages scan repeatedly in one process: ' . json_encode($again));

// ---- One custom template per process, failures, warnings ---------------------------
$deferred = capRun($site, 'deferred');
capAssert($deferred['first'] === 'rescan' && $deferred['deferred'] === true && $deferred['standardWorks'] && $deferred['cachedStillServed'], 'a second template in one process is deferred; standard pages and cached templates still work: ' . json_encode($deferred));
$second = capRun($site, 'caps', 'page:en_scan-second');
capAssert($second['kind'] === 'custom-template' && capField($second['descriptor'], 'title')['v'] === 'Second', 'second template scanned in its own process');
capAssert(str_contains(implode(' ', $second['warnings']), 'Undefined variable'), 'PHP warnings of the template are captured: ' . json_encode($second['warnings']));

$exit = capRun($site, 'exit');
capAssert(!isset($exit['descriptor']) && str_contains($exit['_stdout'], "BEFORE\n") && str_contains($exit['_stdout'], 'EXIT-HANDLED:the template ended the request') && !str_contains($exit['_stdout'], 'partial output') && !str_contains($exit['_stdout'], 'NOT-REACHED'), 'exit() in a template is handled: output discarded, caller told: ' . json_encode($exit));
$afterExit = capRun($site, 'caps', 'page:en_scan-exit');
capAssert($afterExit['cache'] === 'failed' && $afterExit['descriptor']['fields'] === [] && str_contains(implode(' ', $afterExit['warnings']), 'cannot be scanned') && capRenders($site, 'exit') === 1, 'a template that exits is remembered, not executed again');
$throws = capRun($site, 'caps', 'page:en_scan-throws');
capAssert($throws['cache'] === 'failed' && str_contains(implode(' ', $throws['warnings']), 'fixture template failure'), 'a throwing template yields no fields and a warning');
capRun($site, 'caps', 'page:en_scan-throws');
capAssert(capRenders($site, 'throws') === 1, 'a failing template is not executed on every request');
touch($site . '/en/scan-exit.php', time() + 60);
$retry = capRun($site, 'exit');
capAssert(str_contains($retry['_stdout'], 'EXIT-HANDLED') && capRenders($site, 'exit') === 2, 'a changed template gets another chance');

// ---- Targets, news, events, shared content --------------------------------------------
$targets = capRun($site, 'targets');
foreach ($targets['invalid'] as $target => $verdict) capAssert($verdict === 'rejected', "target '" . addcslashes($target, "\n") . "' must be rejected");
$newsDescriptor = $targets['news']['descriptor'];
capAssert($newsDescriptor['kind'] === 'news' && $newsDescriptor['lang'] === 'en', 'news descriptor');
capAssert(array_column($newsDescriptor['fields'], 't', 'p') === ['title' => 'text', 'excerpt' => 'textarea', 'content' => 'html', 'author' => 'text', 'image' => 'image'], 'news fields: ' . json_encode(array_column($newsDescriptor['fields'], 't', 'p')));
capAssert(capField($newsDescriptor, 'image')['fmt'] === 'string' && capField($newsDescriptor, 'title')['req'] === true, 'news cover image is a string path; title required');
foreach (['date', 'slug', 'lang', 'id', 'hidden', 'lastModified'] as $identity) capAssert(in_array($identity, $newsDescriptor['readOnly'], true), "news {$identity} is read-only");
capAssert(!empty($newsDescriptor['requireImage']) && in_array('Write in a friendly tone', $newsDescriptor['hints'], true), 'news.requireImage and hints from _site.json');
$eventFields = array_column($targets['event']['descriptor']['fields'], 't', 'p');
capAssert($eventFields['title.en'] === 'text' && $eventFields['title.de'] === 'text' && $eventFields['description.en'] === 'html' && $eventFields['time'] === 'time' && $eventFields['url'] === 'link' && !isset($eventFields['date']), 'event fields are language maps: ' . json_encode($eventFields));
$footer = $targets['footer']['descriptor'];
capAssert($footer['kind'] === 'shared' && array_column($footer['fields'], 't', 'p') === ['tagline.en' => 'text', 'copyright' => 'text'] && in_array('contact.email', $footer['readOnly'], true), 'shared content fields (the site-wide readOnly pattern contact.* applies to every target): ' . json_encode(array_column($footer['fields'], 't', 'p')));
capAssert(!str_contains(json_encode($footer), 'FOOTER-SECRET-TOKEN') && in_array('adminToken', $footer['readOnly'], true), 'tokens in shared content stay hidden');
capAssert($targets['parsed'][0] === realpath($site) . '/content/pages/en_a__b-c.json' && $targets['parsed'][1] === 'shared', 'targets resolve to keys, never to caller-supplied paths');

echo "PASS agent capabilities: scan (all field types, lists, groups, shared, hidden), hard filters, restricting overrides, cache hit/remix/rescan/invalidate, standard pages, one template per process, exit/throw/warnings, news/event/shared targets, no writes\n";
