<?php

declare(strict_types=1);

// Change sets: staging, validation, render check, review data, apply with journal and
// rollback, undo, publish, conflicts, crash recovery. Runs against a throw-away site built
// from tests/fixtures/agent/site; custom templates and crashes run in their own processes.
//
//   php tests/agent-changeset-smoke.php
//   php tests/agent-changeset-smoke.php worker <scenario> <site> [args]   (internal)

function csAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Worker: one scenario per process
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === 'worker') {
    [$scenario, $site] = [$argv[2], $argv[3]];
    $args = array_slice($argv, 4);
    define('SITE_LANG_DEFAULT', 'en');
    define('CONTENT_BASE_PATH', $site . '/content/pages/');
    require_once $site . '/includes/ai/agent/changeset-apply.php';
    $out = static function (array $data): void {
        echo "RESULT:" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    };
    $stage = static fn(string $id, array $request): array => nibblyAgentChangesetStage($id, $request);

    if ($scenario === 'custom-stage') {
        $cs = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'run_custom', 'allowedUrls' => ['https://example.org/allowed']]);
        $id = $cs['id'];
        $t = 'page:en_scan-custom';
        $r = [];
        $r['title'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'hero.title', 'value' => 'New headline']);
        $r['titleTooLong'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'hero.title', 'value' => str_repeat('x', 100)]);
        $r['linkOk'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'hero.more', 'value' => ['text' => 'Read more', 'href' => 'https://example.org/allowed']]);
        $r['linkExternal'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'hero.more', 'value' => ['text' => 'Spam', 'href' => 'https://spam.example/buy']]);
        $r['linkJs'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'hero.more', 'value' => ['text' => 'x', 'href' => 'javascript:alert(1)']]);
        $r['linkInternal'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'hero.more', 'value' => ['text' => 'Contact', 'href' => '/contact']]);
        $r['readOnly'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'hero.cta', 'value' => ['text' => 'x', 'href' => '/x']]);
        $r['structural'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'hero.type', 'value' => 'x']);
        $r['sensitive'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'contact.apiKey', 'value' => 'x']);
        $r['imageObject'] = $stage($id, ['type' => 'set_image', 'target' => $t, 'path' => 'hero.image', 'libraryPath' => '/assets/images/second.webp', 'alt' => 'Second image']);
        $r['imageSplit'] = $stage($id, ['type' => 'set_image', 'target' => $t, 'path' => 'hero.photo', 'libraryPath' => '/assets/images/second.webp', 'alt' => 'Split alt']);
        $r['imageString'] = $stage($id, ['type' => 'set_image', 'target' => $t, 'path' => 'gallery.cover', 'libraryPath' => '/assets/images/second.webp']);
        $r['imageNoAlt'] = $stage($id, ['type' => 'set_image', 'target' => $t, 'path' => 'hero.image', 'libraryPath' => '/assets/images/second.webp']);
        // lists: the index always refers to the state after the previous operations
        $r['insert'] = $stage($id, ['type' => 'list_insert', 'target' => $t, 'listKey' => 'features.items', 'position' => 1,
            'item' => ['title' => 'Inserted', 'text' => 'Fresh text', 'icon' => 'bolt', 'image' => ['libraryPath' => '/assets/images/second.webp', 'alt' => 'Item image']]]);
        $r['insertUnknown'] = $stage($id, ['type' => 'list_insert', 'target' => $t, 'listKey' => 'features.items', 'item' => ['colour' => 'red']]);
        $r['itemTitle'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'features.items.1.title', 'value' => 'Inserted (edited)']);
        $r['itemOutOfRange'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'features.items.9.title', 'value' => 'x']);
        $r['itemUnknownField'] = $stage($id, ['type' => 'set_field', 'target' => $t, 'path' => 'features.items.1.colour', 'value' => 'x']);
        $r['hide'] = $stage($id, ['type' => 'list_hide', 'target' => $t, 'listKey' => 'features.items', 'index' => 0, 'hidden' => true]);
        $r['hideRange'] = $stage($id, ['type' => 'list_hide', 'target' => $t, 'listKey' => 'features.items', 'index' => 7, 'hidden' => true]);
        $r['removeWrong'] = $stage($id, ['type' => 'list_remove', 'target' => $t, 'listKey' => 'features.items', 'index' => 3, 'titlePrefix' => 'Nope']);
        $r['move'] = $stage($id, ['type' => 'list_move', 'target' => $t, 'listKey' => 'features.items', 'from' => 3, 'to' => 0]);
        $r['moveRange'] = $stage($id, ['type' => 'list_move', 'target' => $t, 'listKey' => 'features.items', 'from' => 0, 'to' => 9]);
        $r['notAList'] = $stage($id, ['type' => 'list_insert', 'target' => $t, 'listKey' => 'hero', 'item' => ['title' => 'x']]);
        $r['faq'] = $stage($id, ['type' => 'list_insert', 'target' => $t, 'listKey' => 'faq.items', 'item' => ['question' => 'Why?', 'answer' => 'Because.', 'link' => ['text' => 'More', 'href' => 'https://example.org/allowed']]]);
        $r['faqLink'] = $stage($id, ['type' => 'list_insert', 'target' => $t, 'listKey' => 'faq.items', 'item' => ['question' => 'Spam?', 'link' => ['text' => 'x', 'href' => 'https://spam.example']]]);
        $r['sectionOnTemplate'] = $stage($id, ['type' => 'section_insert', 'target' => $t, 'position' => 0, 'sectionType' => 'text', 'fields' => []]);
        $cs = nibblyAgentChangesetLoad($id);
        $docs = nibblyAgentReplayOps($cs['ops']);
        $items = nibblyAgentFinalDoc($docs['state'], $t)['features']['items'];
        // This process already rendered the template for the capability scan, so the render check has to wait.
        $out(['id' => $id, 'results' => $r, 'items' => array_map(static fn($i) => [$i['title'], !empty($i['hidden'])], $items), 'isList' => array_is_list($items),
            'opCount' => count($cs['ops']), 'sameProcessFinalize' => nibblyAgentChangesetFinalize($id)]);
    } elseif ($scenario === 'finalize') {
        $out(['finalize' => nibblyAgentChangesetFinalize($args[0])]);
    } elseif ($scenario === 'custom-limits') {
        $cs = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'run_limits']);
        $id = $cs['id'];
        $t = 'page:en_scan-custom';
        $over = null;
        for ($i = 0; $i < 6; $i++) {
            $res = $stage($id, ['type' => 'list_insert', 'target' => $t, 'listKey' => 'features.items', 'item' => ['title' => 'Item ' . $i]]);
            if (isset($res['error'])) { $over = [$i, $res['error']]; break; }
        }
        // removing below the site minimum is only reported when the package is presented
        $c2 = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'run_min']);
        foreach ([2 => 'Three', 1 => 'Two'] as $index => $prefix) {
            $stage($c2['id'], ['type' => 'list_remove', 'target' => $t, 'listKey' => 'features.items', 'index' => $index, 'titlePrefix' => $prefix]);
        }
        $out(['over' => $over, 'removeOps' => count(nibblyAgentChangesetLoad($c2['id'])['ops']), 'minId' => $c2['id'], 'minFinalize' => nibblyAgentChangesetFinalize($c2['id'])]);
    } elseif ($scenario === 'apply') {
        $cs = nibblyAgentChangesetLoad($args[0]);
        $out(['apply' => nibblyAgentChangesetApply($args[0], $cs['previewHash'], ['userKey' => 'u_editor', 'isAdmin' => false])]);
    } elseif ($scenario === 'crash') {
        $cs = nibblyAgentChangesetLoad($args[0]);
        $writes = 0;
        nibblyAgentChangesetApply($args[0], $cs['previewHash'], ['userKey' => 'u_editor', 'isAdmin' => false], ['testHook' => static function (string $event, string $file) use (&$writes): void {
            if (++$writes === 1) exit(9); // the process dies after the first file was written
        }]);
        echo "NOT-REACHED\n";
    } elseif ($scenario === 'manual-op') {
        // An operation naming a path the template never renders: the render check must catch it.
        $cs = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'run_manual']);
        nibblyAgentChangesetMutate($cs['id'], static function (array &$cs) {
            $cs['ops'][] = ['id' => 'op_1', 'type' => 'set_field', 'target' => 'page:en_scan-custom', 'path' => 'hero.video', 'spec' => ['type' => 'text', 'max' => 100, 'req' => false, 'options' => [], 'fmt' => null, 'altPath' => null], 'value' => 'abc'];
            $cs['base']['page:en_scan-custom'] = nibblyJsonRevision(nibblyAgentSiteRoot() . '/content/pages/en_scan-custom.json');
            return true;
        });
        $out(['id' => $cs['id'], 'finalize' => nibblyAgentChangesetFinalize($cs['id'])]);
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Orchestrator
// ---------------------------------------------------------------------------
$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-changeset-' . bin2hex(random_bytes(4));
mkdir($site . '/tests', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
foreach (['includes', 'admin', 'VERSION'] as $item) exec('cp -R ' . escapeshellarg($root . '/' . $item) . ' ' . escapeshellarg($site . '/'));
foreach (['admin/config.php', 'admin/smtp-config.php', 'includes/nav-config.php'] as $local) @unlink($site . '/' . $local);
exec('cp -R ' . escapeshellarg($root . '/tests/fixtures/agent/site/.') . ' ' . escapeshellarg($site . '/'));
rename($site . '/lang-en', $site . '/en');
copy(__FILE__, $site . '/tests/agent-changeset-smoke.php');
mkdir($site . '/assets/images', 0755, true);
mkdir($site . '/content/news', 0755, true);
copy($root . '/assets/images/placeholder-image.webp', $site . '/assets/images/library.webp');
copy($root . '/assets/images/placeholder-image.webp', $site . '/assets/images/second.webp');
file_put_contents($site . '/content/news/2026-10-01-demo-post.json', json_encode(['id' => '2026-10-01-demo-post', 'slug' => 'demo-post', 'lang' => 'en', 'title' => 'Demo post', 'date' => '2026-10-01', 'author' => 'Team', 'excerpt' => 'Short', 'image' => '/assets/images/library.webp', 'content' => '<p>Body</p>', 'hidden' => false]));
file_put_contents($site . '/content/events.json', json_encode(['events' => [[
    'id' => '2026-11-05-open-day', 'date' => '2026-11-05', 'time' => '10:00', 'end-date' => '2026-11-05', 'end-time' => '16:00', 'url' => '',
    'title' => ['en' => 'Open day', 'de' => 'Tag der offenen Tür'], 'location' => ['en' => 'Hall'], 'description' => ['en' => '<p>Come</p>'], 'admission' => ['en' => 'Free'], 'image' => '',
]]]));
file_put_contents($site . '/content/pages/footer.json', json_encode(['tagline' => ['en' => 'We build'], 'copyright' => '&copy; Fixture']));

define('SITE_LANG_DEFAULT', 'en');
define('CONTENT_BASE_PATH', $site . '/content/pages/');
require_once $site . '/includes/ai/agent/changeset-apply.php';

$owner = ['userKey' => 'u_editor', 'isAdmin' => false, 'canPublish' => false];
$publisher = ['userKey' => 'u_editor', 'isAdmin' => false, 'canPublish' => true];
$stranger = ['userKey' => 'u_other', 'isAdmin' => false, 'canPublish' => true];
$admin = ['userKey' => 'u_admin', 'isAdmin' => true, 'canPublish' => true];
$allowed = ['https://example.org/source', 'mailto:team@example.org'];

function csContent(string $site): array {
    $files = array_merge(glob($site . '/content/pages/*.json') ?: [], glob($site . '/content/news/*.json') ?: [], [$site . '/content/events.json']);
    $hashes = [];
    foreach ($files as $file) $hashes[basename($file)] = is_file($file) ? hash_file('sha256', $file) : null;
    ksort($hashes);
    return $hashes;
}

function csNew(array $meta = []): string {
    return nibblyAgentChangesetCreate($meta + ['owner' => 'u_editor', 'runId' => 'run_1', 'allowedUrls' => ['https://example.org/source', 'mailto:team@example.org']])['id'];
}

function csStage(string $id, array $request): array {
    return nibblyAgentChangesetStage($id, $request);
}

function csOk(string $id, array $request, string $what): array {
    $result = csStage($id, $request);
    csAssert(!empty($result['ok']), "{$what} must be accepted: " . json_encode($result));
    return $result;
}

function csRejected(string $id, array $request, string $needle, string $what): array {
    $before = count(nibblyAgentChangesetLoad($id)['ops']);
    $result = csStage($id, $request);
    csAssert(isset($result['error']) && stripos($result['error'], $needle) !== false, "{$what} must be rejected mentioning '{$needle}': " . json_encode($result));
    csAssert(count(nibblyAgentChangesetLoad($id)['ops']) === $before, "{$what}: a rejected operation must not be stored");
    return $result;
}

function csDoc(string $site, string $relative): array {
    return json_decode((string)file_get_contents($site . '/' . $relative), true);
}

function csWorker(string $site, string $scenario, string ...$args): array {
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', $site . '/tests/agent-changeset-smoke.php', 'worker', $scenario, $site, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $site);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $code = proc_close($process);
    foreach (explode("\n", $stdout) as $line) {
        if (str_starts_with($line, 'RESULT:')) return json_decode(substr($line, 7), true, 512, JSON_THROW_ON_ERROR) + ['_code' => $code];
    }
    return ['_stdout' => $stdout, '_stderr' => $stderr, '_code' => $code];
}

$pristine = csContent($site);
$std = 'page:en_scan-standard';

// ---- Staging: everything the agent has to correct is refused, nothing half-stored -------
$a = csNew();
csRejected($a, ['type' => 'set_field', 'target' => 'page:../x', 'path' => 'title', 'value' => 'x'], 'unknown target', 'path traversal in the target');
csRejected($a, ['type' => 'set_field', 'target' => 'page:en_missing', 'path' => 'title', 'value' => 'x'], 'unknown target', 'missing page');
csRejected($a, ['type' => 'explode', 'target' => $std], 'unknown operation', 'unknown operation');
csRejected($a, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.1.title', 'value' => 'x', 'file' => '/etc/passwd'], "'file' is not an argument", 'extra argument');
csRejected($a, ['type' => 'set_field', 'target' => $std, 'value' => 'x'], "'path' is required", 'missing path');
$err = csRejected($a, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.0.level', 'value' => 'h4'], 'not an editable field', 'structural field');
csAssert(str_contains($err['hint'], 'text, subtitle'), 'the hint names the editable fields: ' . $err['hint']);
csRejected($a, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.9.title', 'value' => 'x'], 'the page has 4 sections', 'section out of range');
csRejected($a, ['type' => 'set_field', 'target' => $std, 'path' => 'title', 'value' => 'x'], 'not an editable field', 'a path that is not rendered');
csRejected($a, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.2.src', 'value' => '/x.png'], 'is an image', 'image path with set_field');
csRejected($a, ['type' => 'set_image', 'target' => $std, 'path' => 'sections.1.title', 'libraryPath' => '/assets/images/second.webp', 'alt' => 'x'], 'is not an image', 'set_image on text');
csRejected($a, ['type' => 'set_image', 'target' => $std, 'path' => 'sections.2.src', 'libraryPath' => '/assets/images/second.webp'], 'needs an alt text', 'image without alt');
csRejected($a, ['type' => 'set_image', 'target' => $std, 'path' => 'sections.2.src', 'libraryPath' => '/assets/images/missing.webp', 'alt' => 'x'], 'not in the media library', 'unknown library image');
csRejected($a, ['type' => 'set_image', 'target' => $std, 'path' => 'sections.2.src', 'libraryPath' => '../../admin/config.php', 'alt' => 'x'], 'not in the media library', 'traversal as image path');
csRejected($a, ['type' => 'set_image', 'target' => $std, 'path' => 'sections.2.src', 'mediaRef' => 'm_nope1234', 'alt' => 'x'], 'unknown image reference', 'unknown mediaRef');
csRejected($a, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.1.content', 'value' => ['array']], 'needs an html string', 'wrong value type');
csRejected($a, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.1.content', 'value' => str_repeat('<p>word</p>', 3000)], 'longer than', 'html over the limit');
csRejected($a, ['type' => 'set_field', 'target' => 'event:2026-11-05-open-day', 'path' => 'url', 'value' => 'https://spam.example/x'], 'external link not allowed', 'external link nobody named');
csRejected($a, ['type' => 'set_field', 'target' => 'event:2026-11-05-open-day', 'path' => 'url', 'value' => 'javascript:alert(1)'], 'link is not allowed', 'javascript link');
csRejected($a, ['type' => 'set_field', 'target' => 'event:2026-11-05-open-day', 'path' => 'time', 'value' => '25:99'], 'HH:MM', 'invalid time');
csRejected($a, ['type' => 'set_field', 'target' => 'event:2026-11-05-open-day', 'path' => 'date', 'value' => '2026-12-01'], 'read-only', 'event date is identity');
csRejected($a, ['type' => 'set_field', 'target' => 'news:2026-10-01-demo-post', 'path' => 'slug', 'value' => 'other'], 'read-only', 'news slug is identity');
csRejected($a, ['type' => 'set_field', 'target' => 'news:2026-10-01-demo-post', 'path' => 'hidden', 'value' => 'true'], 'read-only', 'news visibility');
csRejected($a, ['type' => 'section_insert', 'target' => $std, 'position' => 0, 'sectionType' => 'nuke', 'fields' => []], "unknown section type 'nuke'", 'unknown section type');
csRejected($a, ['type' => 'section_insert', 'target' => $std, 'position' => 0, 'sectionType' => 'text', 'fields' => ['onclick' => 'x']], "not a field of a text section", 'unknown section field');
csRejected($a, ['type' => 'section_insert', 'target' => $std, 'position' => 0, 'sectionType' => 'image', 'fields' => ['src' => '/assets/images/second.webp']], 'needs an alt text', 'image section without alt');
csRejected($a, ['type' => 'create_news', 'lang' => 'xx', 'title' => 'T', 'date' => '2026-10-10', 'contentHtml' => '<p>x</p>'], 'not a language of this site', 'unknown language');
csRejected($a, ['type' => 'create_news', 'lang' => 'en', 'title' => 'T', 'date' => '2026-13-40', 'contentHtml' => '<p>x</p>'], 'YYYY-MM-DD', 'impossible date');
csRejected($a, ['type' => 'create_news', 'lang' => 'en', 'title' => 'Demo post', 'slug' => 'demo-post', 'date' => '2026-10-01', 'contentHtml' => '<p>x</p>'], 'already exists', 'news id collision with a stored post');
csRejected($a, ['type' => 'create_news', 'lang' => 'en', 'title' => 'T', 'date' => '2026-10-10'], "'contentHtml' is required", 'missing news body');
csRejected($a, ['type' => 'create_news', 'lang' => 'en', 'title' => 'T', 'date' => '2026-10-10', 'contentHtml' => '<p>x</p>', 'imagePath' => '/assets/images/nope.webp'], 'not in the media library', 'news image outside the library');
csRejected($a, ['type' => 'create_event', 'lang' => 'en', 'title' => 'T', 'date' => '2026-10-10', 'time' => '10:00'], "'location' is required", 'event without location');
csRejected($a, ['type' => 'create_page', 'lang' => 'en', 'slug' => 'Bad Slug', 'title' => 'T', 'sections' => [['type' => 'text', 'fields' => []]]], 'not valid', 'invalid page path');
csRejected($a, ['type' => 'create_page', 'lang' => 'en', 'slug' => 'scan-custom', 'title' => 'T', 'sections' => [['type' => 'text', 'fields' => []]]], 'template already serves', 'page that collides with a template');
csRejected($a, ['type' => 'create_page', 'lang' => 'en', 'slug' => 'scan-standard', 'title' => 'T', 'sections' => [['type' => 'text', 'fields' => []]]], 'already exists', 'page that already exists');
csRejected($a, ['type' => 'create_page', 'lang' => 'en', 'slug' => 'empty-page', 'title' => 'T', 'sections' => []], 'at least one section', 'page without sections');
csRejected($a, ['type' => 'revert_op', 'opId' => 'op_99'], 'unknown operation', 'reverting an unknown operation');
csAssert(csContent($site) === $pristine && nibblyAgentChangesetLoad($a)['ops'] === [], 'refused requests changed neither content nor the change set');
csAssert(nibblyAgentChangesetStage('cs_0000000000000000', ['type' => 'set_field'])['error'] === 'Unknown change set.' && nibblyAgentChangesetStage('../../x', ['type' => 'set_field'])['error'] === 'Unknown change set.', 'unknown or malformed change set ids');

// ---- Values are normalised ---------------------------------------------------------
$r = csOk($a, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.1.title', 'value' => "  A <b>bold</b>   headline\n with breaks " . str_repeat('x', 400)], 'long text');
$stored = nibblyAgentChangesetLoad($a)['ops'][0]['value'];
csAssert(!str_contains($stored, '<') && !str_contains($stored, "\n") && mb_strlen($stored) <= 160 && str_starts_with($stored, 'A bold headline with breaks') && $r['warnings'] !== [], 'text: tags stripped, whitespace collapsed, length capped with a warning: ' . $stored);
csOk($a, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.1.content', 'value' => '<p onclick="x()">Hello <script>alert(1)</script><strong>world</strong> <a href="javascript:alert(1)">bad</a> <a href="https://example.org/source">good</a></p><iframe src="https://evil.example"></iframe>'], 'html with active content');
$html = nibblyAgentChangesetLoad($a)['ops'][1]['value'];
csAssert(!preg_match('/onclick|script|iframe|javascript/i', $html) && str_contains($html, '<strong>world</strong>') && str_contains($html, 'https://example.org/source') && nibblySanitizeRichHtml($html) === $html, 'html: sanitised and stable under a second pass: ' . $html);
csOk($a, ['type' => 'set_image', 'target' => $std, 'path' => 'sections.2.src', 'libraryPath' => '/assets/images/second.webp', 'alt' => '  A <i>photo</i> of things  '], 'split image');
$op = nibblyAgentChangesetLoad($a)['ops'][2];
csAssert($op['src'] === '/assets/images/second.webp' && $op['alt'] === 'A photo of things' && $op['spec']['fmt'] === 'split' && $op['spec']['altPath'] === 'sections.2.alt', 'section image: library path, plain alt text, split format');
csOk($a, ['type' => 'set_field', 'target' => 'event:2026-11-05-open-day', 'path' => 'url', 'value' => 'https://example.org/source/'], 'event url named by the user');
csOk($a, ['type' => 'set_field', 'target' => 'news:2026-10-01-demo-post', 'path' => 'excerpt', 'value' => 'Fresh excerpt'], 'news excerpt');
csOk($a, ['type' => 'set_field', 'target' => 'event:2026-11-05-open-day', 'path' => 'title.de', 'value' => 'Neuer Titel'], 'event title in another language');
csOk($a, ['type' => 'set_field', 'target' => 'page:footer', 'path' => 'tagline.en', 'value' => 'We build things'], 'shared footer text');
// Section edits follow the state of the package: insert first, then edit the new section and the shifted one
csOk($a, ['type' => 'section_insert', 'target' => $std, 'position' => 0, 'sectionType' => 'text', 'fields' => ['title' => 'Lead-in', 'content' => '<p>Hello</p>']], 'section insert');
$r = csOk($a, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.0.title', 'value' => 'Lead-in (edited)'], 'editing the section inserted before');
csAssert($r['preview']['before'] === 'Lead-in', 'the index refers to the package state: ' . json_encode($r['preview']));
csRejected($a, ['type' => 'revert_op', 'opId' => 'op_8'], 'later operations depend', 'reverting an operation that later ones build on');
// Creating new content
csOk($a, ['type' => 'create_news', 'lang' => 'en', 'title' => 'Agent news: a "title"', 'date' => '2026-10-10', 'author' => 'Agent', 'excerpt' => 'Short summary', 'contentHtml' => '<h2>Heading</h2><p>Text <a href="https://example.org/source">source</a></p>', 'imagePath' => '/assets/images/library.webp'], 'news');
csOk($a, ['type' => 'create_event', 'lang' => 'en', 'title' => 'Launch party', 'date' => '2026-12-01', 'time' => '18:00', 'location' => 'Berlin', 'description' => '<p>Join us</p>', 'url' => 'mailto:team@example.org'], 'event');
csOk($a, ['type' => 'create_page', 'lang' => 'en', 'slug' => 'agent/page', 'title' => 'Agent page', 'description' => 'About the agent', 'sections' => [['type' => 'heading', 'fields' => ['text' => 'Agent page']], ['type' => 'text', 'fields' => ['title' => 'Intro', 'content' => '<p>Welcome</p>']]]], 'page');
csRejected($a, ['type' => 'create_news', 'lang' => 'en', 'title' => 'Agent news: a "title"', 'date' => '2026-10-10', 'contentHtml' => '<p>twice</p>'], 'already exists', 'creating the same news twice in one package');
$ops = nibblyAgentChangesetLoad($a)['ops'];
csAssert(count($ops) === 12 && $ops[9]['post']['id'] === '2026-10-10-agent-news-a-title' && $ops[9]['post']['hidden'] === true, 'news id from date and slug, created hidden: ' . ($ops[9]['post']['id'] ?? ''));
csAssert($ops[10]['event']['hidden'] === true && $ops[10]['event']['url'] === 'mailto:team@example.org', 'event created hidden');
$page = $ops[11]['page'];
csAssert($page['visibility']['status'] === 'private' && strlen($page['visibility']['passwordHash']) > 40 && $page['nav'] === [] && count($page['sections']) === 2, 'page created private, random password, out of the navigation');
csAssert($ops[11]['target'] === 'page:en_agent__page', 'nested path becomes a flat content key');
csAssert(csContent($site) === $pristine, 'staging never touches stored content');
csAssert(is_dir($site . '/content/ai-agent/changesets') && !glob($site . '/content/ai-agent/changesets/*.tmp'), 'change sets live under content/ai-agent');


// ---- Review: validation, render check, fingerprint ------------------------------------
$report = nibblyAgentChangesetFinalize($a);
csAssert($report['status'] === 'ready' && $report['validation']['ok'] && $report['renderCheck']['status'] === 'passed', 'the package is ready: ' . json_encode($report));
$targets = $report['renderCheck']['targets'];
foreach ([$std, 'news:2026-10-01-demo-post', 'event:2026-11-05-open-day', 'news:2026-10-10-agent-news-a-title', 'event:2026-12-01-launch-party', 'page:en_agent__page'] as $target) {
    csAssert(($targets[$target]['status'] ?? '') === 'passed', "{$target} passes the render check: " . json_encode($targets[$target] ?? null));
}
csAssert($targets['page:footer']['status'] === 'skipped' && str_contains($targets['page:footer']['messages'][0], 'not checked by rendering'), 'shared content is skipped with a visible note');
csAssert(preg_match('/^[a-f0-9]{64}$/', $report['previewHash']) === 1 && nibblyAgentChangesetFinalize($a)['previewHash'] === $report['previewHash'], 'the preview hash is stable');
csAssert(csContent($site) === $pristine, 'validating and rendering write nothing to content');
csAssert(!is_dir($site . '/content/analytics'), 'the render check is not counted as a visit');

$review = nibblyAgentChangesetPreview(nibblyAgentChangesetLoad($a));
csAssert($review['status'] === 'ready' && $review['conflicts'] === [] && $review['previewHash'] === $report['previewHash'], 'review data identity');
$first = $review['targets'][$std][0];
csAssert($first['opId'] === 'op_1' && $first['before'] === 'A text' && str_starts_with($first['after'], 'A bold headline') && $first['html'] === false, 'before/after of a text change');
csAssert(count($review['targets']) === 7 && $review['targets']['page:en_agent__page'][0]['type'] === 'create_page', 'operations are grouped by target');
$encoded = json_encode($review, JSON_UNESCAPED_SLASHES);
csAssert(str_contains($encoded, '<p>Hello <strong>world</strong>') && !preg_match('/onclick|<script|<iframe|javascript:/i', $encoded), 'HTML values in the review are the sanitised ones');

// ---- Apply ----------------------------------------------------------------------------
$beforeApply = csContent($site);
$hash = $report['previewHash'];
csAssert(str_contains(nibblyAgentChangesetApply($a, 'not-the-hash', $owner)['error'], 'changed after the preview'), 'a wrong preview hash is refused');
csAssert(nibblyAgentChangesetApply($a, $hash, $stranger)['error'] === 'Unknown change set.', 'another user cannot apply it');
csAssert(csContent($site) === $beforeApply, 'refused applies change nothing');
$applied = nibblyAgentChangesetApply($a, $hash, $owner);
csAssert($applied['ok'] && $applied['status'] === 'applied' && nibblyAgentVerifyUndoToken($a, $applied['undoToken']), 'apply: ' . json_encode($applied));

$page = csDoc($site, 'content/pages/en_scan-standard.json');
csAssert(count($page['sections']) === 5 && $page['sections'][0]['title'] === 'Lead-in (edited)' && $page['sections'][0]['hidden'] === true && str_starts_with($page['sections'][0]['id'], 'section_'), 'section inserted hidden, edited in the same package');
csAssert($page['sections'][2]['title'] === 'A bold headline with breaks' . substr($page['sections'][2]['title'], 27) && !str_contains($page['sections'][2]['content'], 'script'), 'text and html changes landed on the shifted section');
csAssert($page['sections'][3]['src'] === '/assets/images/second.webp' && $page['sections'][3]['alt'] === 'A photo of things', 'the image change followed its section');
csAssert(isset($page['lastModified']), 'modification time set');
csAssert(csDoc($site, 'content/news/2026-10-01-demo-post.json')['excerpt'] === 'Fresh excerpt', 'news edited');
$events = csDoc($site, 'content/events.json')['events'];
csAssert($events[0]['title']['de'] === 'Neuer Titel' && $events[0]['url'] === 'https://example.org/source/' && $events[1]['hidden'] === true && $events[1]['id'] === '2026-12-01-launch-party', 'events edited and created hidden');
csAssert(csDoc($site, 'content/pages/footer.json')['tagline']['en'] === 'We build things', 'shared content edited');
$post = csDoc($site, 'content/news/2026-10-10-agent-news-a-title.json');
csAssert($post['hidden'] === true && $post['image'] === '/assets/images/library.webp' && isset($post['lastModified']), 'new news post is hidden');
$newPage = csDoc($site, 'content/pages/en_agent__page.json');
csAssert($newPage['visibility']['status'] === 'private' && $newPage['nav'] === [], 'new page is private and not in the navigation');
$backups = array_map('basename', glob($site . '/backups/*.json'));
csAssert(count(array_filter($backups, fn($b) => str_starts_with($b, 'en_scan-standard_'))) === 1 && count(array_filter($backups, fn($b) => str_starts_with($b, 'news_2026-10-01-demo-post_'))) === 1
    && count(array_filter($backups, fn($b) => str_starts_with($b, 'events_'))) === 1 && count(array_filter($backups, fn($b) => str_starts_with($b, 'footer_'))) === 1 && count($backups) === 4, 'the usual backups exist for the four existing files: ' . implode(', ', $backups));
csAssert(count(glob($site . '/content/ai-agent/snapshots/' . $a . '/apply/*')) === 4 && !glob($site . '/content/ai-agent/journal/*'), 'snapshots kept, journal gone');
$stored = nibblyAgentChangesetLoad($a);
csAssert($stored['status'] === 'applied' && count($stored['applied']['items']) === 4 && array_column($stored['applied']['items'], 'kind') === ['news', 'event', 'page', 'section'], 'publishable items: ' . json_encode(array_column($stored['applied']['items'], 'kind')));
foreach ($stored['applied']['revisions'] as $relative => $revision) {
    csAssert(nibblyJsonRevision($site . '/' . $relative) === $revision, "revision of {$relative} recorded");
}
csAssert(str_contains(nibblyAgentChangesetApply($a, $hash, $owner)['error'], 'applied and cannot be applied'), 'a change set applies once');
$audit = '';
foreach (glob($site . '/content/ai-audit/*.jsonl') ?: [] as $file) $audit .= file_get_contents($file);
csAssert(str_contains($audit, '"agent-apply"') && str_contains($audit, $a) && !str_contains($audit, 'Fresh excerpt') && !str_contains($audit, 'Lead-in') && !str_contains($audit, 'Agent news'), 'audit records the apply with hashes, not content');

// ---- Publish ------------------------------------------------------------------------------
$newsItem = ['kind' => 'news', 'target' => 'news:2026-10-10-agent-news-a-title'];
csAssert(nibblyAgentChangesetPublish($a, $newsItem, $owner)['error'] === 'You are not allowed to publish.', 'publishing needs the publish right');
csAssert(nibblyAgentChangesetPublish($a, $newsItem, $stranger)['error'] === 'Unknown change set.', 'only the owner (or an admin) can publish');
csAssert(str_contains(nibblyAgentChangesetPublish($a, ['kind' => 'news', 'target' => 'news:2026-10-01-demo-post'], $publisher)['error'], 'not part of the change set'), 'only items of the change set can be published');
csAssert(nibblyAgentChangesetPublish($a, $newsItem, $publisher)['ok'] && !isset(csDoc($site, 'content/news/2026-10-10-agent-news-a-title.json')['hidden']), 'news published');
csAssert(str_contains(nibblyAgentChangesetPublish($a, $newsItem, $publisher)['error'], 'already published'), 'publishing twice is refused');
csAssert(nibblyAgentChangesetPublish($a, ['kind' => 'event', 'target' => 'event:2026-12-01-launch-party'], $publisher)['ok'] && !isset(csDoc($site, 'content/events.json')['events'][1]['hidden']), 'event published');
csAssert(nibblyAgentChangesetPublish($a, ['kind' => 'page', 'target' => 'page:en_agent__page'], $publisher)['ok'] && !isset(csDoc($site, 'content/pages/en_agent__page.json')['visibility']), 'page published (no longer private)');
$sectionId = $page['sections'][0]['id'];
csAssert(nibblyAgentChangesetPublish($a, ['kind' => 'section', 'target' => $std, 'sectionId' => $sectionId], $publisher)['ok'] && !isset(csDoc($site, 'content/pages/en_scan-standard.json')['sections'][0]['hidden']), 'section published');

// ---- Undo -----------------------------------------------------------------------------------
$token = $applied['undoToken'];
csAssert(str_contains(nibblyAgentChangesetUndo($a, $a . '.deadbeef', $owner)['error'], 'not possible'), 'a forged undo token is refused');
csAssert(str_contains(nibblyAgentChangesetUndo($a, $token, $stranger)['error'], 'not possible'), 'another user cannot undo');
$demo = $site . '/content/news/2026-10-01-demo-post.json';
$original = (string)file_get_contents($demo);
file_put_contents($demo, str_replace('Fresh excerpt', 'Edited by someone else', $original));
$refused = nibblyAgentChangesetUndo($a, $token, $owner);
csAssert(!$refused['ok'] && str_contains($refused['error'], '2026-10-01-demo-post.json was changed after'), 'undo is refused after a foreign change: ' . json_encode($refused));
csAssert(nibblyAgentChangesetLoad($a)['status'] === 'applied' && str_contains((string)file_get_contents($demo), 'Edited by someone else'), 'a refused undo changes nothing');
file_put_contents($demo, $original);
$undone = nibblyAgentChangesetUndo($a, $token, $owner);
csAssert($undone['ok'] && $undone['status'] === 'undone', 'undo after the file matches again, even after publishing: ' . json_encode($undone));
csAssert(csContent($site) === array_diff_key($beforeApply, ['en_agent__page.json' => 1]) + [], 'every existing file is byte-identical to its state before the apply, created files are gone');
csAssert(!is_file($site . '/content/news/2026-10-10-agent-news-a-title.json') && glob($site . '/content/ai-agent/snapshots/' . $a . '/undone-*'), 'created news removed, a copy kept');
csAssert(glob($site . '/content/pages-trash/en_agent__page_*.json') && !is_file($site . '/content/pages/en_agent__page.json'), 'the created page went to the pages trash');
csAssert(str_contains(nibblyAgentChangesetUndo($a, $token, $owner)['error'], 'Only an applied change set'), 'undo works once');
csAssert(nibblyAgentChangesetLoad($a)['status'] === 'undone', 'status undone');


// ---- Conflicts ------------------------------------------------------------------------------
$c = csNew();
csOk($c, ['type' => 'set_field', 'target' => 'news:2026-10-01-demo-post', 'path' => 'excerpt', 'value' => 'Excerpt v1'], 'news excerpt');
$ready = nibblyAgentChangesetFinalize($c);
csAssert($ready['status'] === 'ready', 'conflict package is ready first');
file_put_contents($demo, str_replace('"title":"Demo post"', '"title":"Renamed elsewhere"', (string)file_get_contents($demo)));
$res = nibblyAgentChangesetApply($c, $ready['previewHash'], $owner);
csAssert(!$res['ok'] && $res['status'] === 'conflict' && str_contains($res['error'], 'content changed since'), 'apply detects the changed base: ' . json_encode($res));
csAssert(nibblyAgentChangesetLoad($c)['status'] === 'conflict' && str_contains(csStage($c, ['type' => 'set_field'])['error'], 'conflict'), 'the change set is marked conflict and closed for new operations');
csAssert(!nibblyAgentChangesetRevalidate($c, $stranger)['ok'], 'only the owner can revalidate');
csAssert(nibblyAgentChangesetRevalidate($c, $owner)['ok'] && nibblyAgentChangesetLoad($c)['status'] === 'staging', 'revalidate replays on the new base');
$ready = nibblyAgentChangesetFinalize($c);
csAssert($ready['status'] === 'ready' && nibblyAgentChangesetApply($c, $ready['previewHash'], $owner)['ok'], 'and the package applies on top of the foreign change');
$news = csDoc($site, 'content/news/2026-10-01-demo-post.json');
csAssert($news['title'] === 'Renamed elsewhere' && $news['excerpt'] === 'Excerpt v1', 'the foreign edit and the agent edit are both there');
$d = csNew();
csOk($d, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.3.title', 'value' => 'Edited card'], 'card title');
$ready = nibblyAgentChangesetFinalize($d);
$stdFile = $site . '/content/pages/en_scan-standard.json';
$stdOriginal = (string)file_get_contents($stdFile);
file_put_contents($stdFile, json_encode(['page' => 'en_scan-standard', 'lang' => 'en', 'title' => 'Shrunk', 'sections' => [['id' => 'h1', 'type' => 'heading', 'text' => 'Only one']]]));
csAssert(nibblyAgentChangesetFinalize($d)['status'] === 'conflict', 'finalize reports the conflict');
$rev = nibblyAgentChangesetRevalidate($d, $owner);
csAssert(!$rev['ok'] && str_contains($rev['error'], 'no longer fit') && nibblyAgentChangesetLoad($d)['status'] === 'conflict', 'operations that no longer fit stay a conflict, with the reason: ' . json_encode($rev));
file_put_contents($stdFile, $stdOriginal);

// ---- A failing write rolls everything back ----------------------------------------------------
$f = csNew();
csOk($f, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.1.title', 'value' => 'Rollback me'], 'page edit');
csOk($f, ['type' => 'set_field', 'target' => 'news:2026-10-01-demo-post', 'path' => 'excerpt', 'value' => 'Rollback excerpt'], 'news edit');
csOk($f, ['type' => 'set_field', 'target' => 'event:2026-11-05-open-day', 'path' => 'title.de', 'value' => 'Rollback Titel'], 'event edit');
csOk($f, ['type' => 'create_news', 'lang' => 'en', 'title' => 'Rollback news', 'date' => '2026-10-11', 'contentHtml' => '<p>x</p>', 'imagePath' => '/assets/images/library.webp'], 'news create');
$ready = nibblyAgentChangesetFinalize($f);
csAssert($ready['status'] === 'ready', 'rollback package ready');
$before = csContent($site);
$writes = 0;
$res = nibblyAgentChangesetApply($f, $ready['previewHash'], $owner, ['testHook' => static function (string $event, string $file) use (&$writes): void {
    if (++$writes === 3) throw new RuntimeException('disk full (simulated)');
}]);
csAssert(!$res['ok'] && $res['status'] === 'failed' && str_contains($res['error'], 'nothing was changed') && $writes === 3, 'a write error is reported: ' . json_encode($res));
csAssert(csContent($site) === $before && !is_file($site . '/content/news/2026-10-11-rollback-news.json'), 'three files were written, then all of them were restored and the new file removed');
csAssert(!glob($site . '/content/ai-agent/journal/*') && nibblyAgentChangesetLoad($f)['status'] === 'failed' && nibblyAgentChangesetLoad($f)['applied'] === null, 'journal removed, change set failed, nothing recorded as applied');
csAssert(str_contains(nibblyAgentChangesetApply($f, $ready['previewHash'], $owner)['error'], 'failed and cannot be applied'), 'a failed change set is not retried');

// ---- A crash in the middle of an apply is repaired -----------------------------------------------
$k = csNew();
csOk($k, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.1.title', 'value' => 'Crash edit'], 'page edit');
csOk($k, ['type' => 'set_field', 'target' => 'news:2026-10-01-demo-post', 'path' => 'excerpt', 'value' => 'Crash excerpt'], 'news edit');
csOk($k, ['type' => 'create_news', 'lang' => 'en', 'title' => 'Crash news', 'date' => '2026-10-12', 'contentHtml' => '<p>x</p>', 'imagePath' => '/assets/images/library.webp'], 'news create');
csAssert(nibblyAgentChangesetFinalize($k)['status'] === 'ready', 'crash package ready');
$before = csContent($site);
$crash = csWorker($site, 'crash', $k);
csAssert($crash['_code'] === 9 && count(glob($site . '/content/ai-agent/journal/*')) === 1, 'the process died with a journal left behind');
csAssert(csContent($site) !== $before && nibblyAgentChangesetLoad($k)['status'] === 'applying', 'the files are half written');
$k2 = csNew();
csOk($k2, ['type' => 'set_field', 'target' => 'event:2026-11-05-open-day', 'path' => 'title.de', 'value' => 'Anderer Titel'], 'event edit');
$ready2 = nibblyAgentChangesetFinalize($k2);
$res = nibblyAgentChangesetApply($k2, $ready2['previewHash'], $owner);
csAssert($res['ok'], 'the next apply goes through: ' . json_encode($res));
csAssert(nibblyAgentChangesetLoad($k)['status'] === 'failed' && str_contains(nibblyAgentChangesetLoad($k)['error'], 'interrupted') && !glob($site . '/content/ai-agent/journal/*'), 'and first restored the interrupted one');
$expected = $before;
csAssert(array_diff_assoc(csContent($site), $before) === ['events.json' => hash_file('sha256', $site . '/content/events.json')] && !is_file($site . '/content/news/2026-10-12-crash-news.json'), 'only the later change is in place; the interrupted files are as before: ' . json_encode(array_keys(array_diff_assoc(csContent($site), $before))));
nibblyAgentChangesetUndo($k2, nibblyAgentUndoToken($k2), $owner);
csAssert(nibblyAgentRecoverJournals() === [], 'nothing left to recover');

// ---- Render check and validation block a package -------------------------------------------------
$m = csWorker($site, 'manual-op');
csAssert($m['finalize']['status'] === 'staging' && $m['finalize']['renderCheck']['status'] === 'failed' && str_contains(json_encode($m['finalize']['renderCheck']), 'hero.video does not appear'), 'a path the page does not render fails the render check: ' . json_encode($m));
$res = nibblyAgentChangesetApply($m['id'], 'x', $owner);
csAssert(!$res['ok'] && $res['status'] === 'staging', 'a package that is not ready cannot be applied');
$n = csNew();
csOk($n, ['type' => 'create_news', 'lang' => 'en', 'title' => 'No picture', 'date' => '2026-10-13', 'contentHtml' => '<p>text</p>'], 'news without image');
$res = nibblyAgentChangesetFinalize($n);
csAssert($res['status'] === 'staging' && !$res['validation']['ok'] && str_contains($res['validation']['errors'][0]['message'], 'requires an image') && $res['renderCheck']['status'] === 'skipped', 'site rule: every news post needs an image');
csAssert(nibblyAgentChangesetFinalize(csNew())['validation']['errors'][0]['message'] === 'The change set is empty.', 'an empty package is not ready');
$g = csNew();
nibblyAgentChangesetMutate($g, static function (array &$cs) { $cs['media']['m_test1'] = ['finalPath' => '/assets/images/library.webp']; return true; });
csOk($g, ['type' => 'set_image', 'target' => $std, 'path' => 'sections.2.src', 'mediaRef' => 'm_test1', 'alt' => 'Staged image'], 'staged image');
$ready = nibblyAgentChangesetFinalize($g);
$before = csContent($site);
$res = nibblyAgentChangesetApply($g, $ready['previewHash'], $owner);
csAssert($ready['status'] === 'ready' && !$res['ok'] && str_contains($res['error'], 'staged image m_test1 is missing') && csContent($site) === $before && nibblyAgentChangesetLoad($g)['status'] === 'failed', 'a staged image that is gone fails the apply without side effects: ' . json_encode($res));

// ---- Ownership, discard, cleanup ----------------------------------------------------------------------
$o = csNew();
csOk($o, ['type' => 'set_field', 'target' => $std, 'path' => 'sections.1.title', 'value' => 'Discard me'], 'edit');
mkdir($site . '/content/ai-agent/staging/' . $o . '/media', 0755, true);
csAssert(!nibblyAgentChangesetDiscard($o, $stranger) && nibblyAgentChangesetLoad($o)['status'] === 'staging', 'a stranger cannot discard it');
csAssert(nibblyAgentChangesetDiscard($o, $owner) && nibblyAgentChangesetLoad($o)['status'] === 'discarded' && !is_dir($site . '/content/ai-agent/staging/' . $o), 'the owner discards it, staging files go');
csAssert(str_contains(csStage($o, ['type' => 'set_field'])['error'], 'discarded'), 'a discarded change set is closed');
$o2 = csNew();
csAssert(nibblyAgentChangesetDiscard($o2, $admin), 'an admin can discard anyone\'s change set');
$stale = nibblyAgentChangesetLoad($o);
$stale['updatedAt'] = date('c', time() - 2 * 86400); // written directly: the store stamps every normal update
nibblyJsonAtomicWrite(nibblyAgentChangesetPath($o), $stale);
touch($site . '/content/ai-agent/snapshots/' . $a, time() - 40 * 86400);
$removed = nibblyAgentCleanup();
csAssert($removed['changesets'] >= 1 && !is_file(nibblyAgentChangesetPath($o)) && $removed['snapshots'] === 1 && !is_dir($site . '/content/ai-agent/snapshots/' . $a), 'old unfinished change sets and 30-day-old snapshots are cleaned up: ' . json_encode($removed));
csAssert(is_file(nibblyAgentChangesetPath($c)) && is_dir($site . '/content/ai-agent/snapshots/' . $c), 'applied change sets and recent snapshots stay');


// ---- A custom template page: fields, links, images in every format, lists ---------------------------
$customFile = $site . '/content/pages/en_scan-custom.json';
$customBefore = (string)file_get_contents($customFile);
$custom = csWorker($site, 'custom-stage');
csAssert(isset($custom['results']), 'custom staging worker ran: ' . json_encode($custom));
$R = $custom['results'];
$ok = static fn(string $key) => !empty($R[$key]['ok']);
$err = static fn(string $key, string $needle) => isset($R[$key]['error']) && stripos($R[$key]['error'], $needle) !== false;
csAssert($ok('title') && $ok('titleTooLong') && $R['titleTooLong']['warnings'] !== [], 'a text above the site limit (40) is shortened with a warning');
csAssert($ok('linkOk') && $ok('linkInternal') && $err('linkExternal', 'external link not allowed') && $err('linkJs', 'link is not allowed'), 'links: named or internal pass, others are refused: ' . json_encode([$R['linkOk'], $R['linkExternal']]));
csAssert($err('readOnly', 'read-only') && $err('structural', 'read-only') && $err('sensitive', 'read-only'), 'override, structural and sensitive fields are read-only');
csAssert($ok('imageObject') && $ok('imageSplit') && $ok('imageString') && $err('imageNoAlt', 'needs an alt text'), 'object, split and string images');
csAssert($ok('insert') && $err('insertUnknown', 'not a field of this list') && $ok('itemTitle') && $R['itemTitle']['preview']['before'] === 'Inserted', 'list item fields follow the state of the package');
csAssert($err('itemOutOfRange', 'the list has 4 items') && $err('itemUnknownField', 'not a field of') && $err('hideRange', 'out of range') && $err('moveRange', 'out of range') && $err('notAList', 'not an editable list'), 'list bounds and field names are checked: ' . json_encode([$R['itemOutOfRange'], $R['notAList']]));
csAssert($ok('hide') && $err('removeWrong', 'starts with "Three"') && $ok('move'), 'removal asks for the title prefix of the item');
csAssert($ok('faq') && $err('faqLink', 'external link not allowed') && $err('sectionOnTemplate', 'standard pages'), 'defaults-only lists, links inside list items, no sections on templates');
csAssert($custom['items'] === [['Three', false], ['One', true], ['Inserted (edited)', true], ['Two', true]] && $custom['isList'] === true && $custom['opCount'] === 12, 'resulting list (hidden flags, order) and array form: ' . json_encode($custom['items']));
csAssert(file_get_contents($customFile) === $customBefore, 'staging a template page writes nothing');
$cid = $custom['id'];
$pending = $custom['sameProcessFinalize'];
csAssert($pending['status'] === 'staging' && $pending['validation']['ok'] && $pending['renderCheck']['status'] === 'pending', 'a second template render in one process waits for the next request: ' . json_encode($pending['renderCheck']));
$fin = csWorker($site, 'finalize', $cid)['finalize'];
csAssert($fin['status'] === 'ready' && $fin['renderCheck']['targets']['page:en_scan-custom']['status'] === 'passed', 'the template is rendered with the package (shifted list paths included): ' . json_encode($fin['renderCheck']));
$res = csWorker($site, 'apply', $cid)['apply'];
csAssert($res['ok'], 'apply: ' . json_encode($res));
$doc = json_decode((string)file_get_contents($customFile), true);
csAssert($doc['hero']['title'] === str_repeat('x', 40) && $doc['hero']['more'] === ['text' => 'Contact', 'href' => '/contact', 'target' => '_blank'], 'text and link (the link keeps its target)');
csAssert($doc['hero']['image'] === ['src' => '/assets/images/second.webp', 'alt' => 'Second image'] && $doc['hero']['photo'] === '/assets/images/second.webp' && $doc['hero']['photoAlt'] === 'Split alt' && $doc['gallery']['cover'] === '/assets/images/second.webp', 'images in object, split and string format');
csAssert(array_is_list($doc['features']['items']) && array_column($doc['features']['items'], 'title') === ['Three', 'One', 'Inserted (edited)', 'Two'] && array_map(fn($i) => !empty($i['hidden']), $doc['features']['items']) === [false, true, true, true], 'list operations replayed in order, list written as an array');
csAssert($doc['features']['items'][2]['image'] === ['src' => '/assets/images/second.webp', 'alt' => 'Item image'] && $doc['features']['items'][2]['icon'] === 'bolt' && $doc['features']['items'][2]['text'] === 'Fresh text', 'inserted item merged with the template defaults');
csAssert($doc['faq']['items'] === [['question' => 'Why?', 'answer' => 'Because.', 'link' => ['text' => 'More', 'href' => 'https://example.org/allowed'], 'hidden' => true]], 'item for a list that had no items: defaults, link, hidden');
$before = json_decode($customBefore, true);
foreach (['contact', 'visibility', 'seo', 'about', 'page', 'lang'] as $untouched) csAssert($doc[$untouched] === $before[$untouched], "{$untouched} is untouched");
$stored = nibblyAgentChangesetLoad($cid);
$listItems = array_values(array_filter($stored['applied']['items'], fn($i) => $i['kind'] === 'list_item'));
csAssert(count($listItems) === 2 && array_column($listItems, 'index') === [2, 0] && array_column($listItems, 'listKey') === ['features.items', 'faq.items'], 'new list items are publishable at their final position: ' . json_encode($listItems));
csAssert(nibblyAgentChangesetPublish($cid, ['kind' => 'list_item', 'target' => 'page:en_scan-custom', 'listKey' => 'features.items', 'index' => 2], $publisher)['ok'], 'list item published');
csAssert(nibblyAgentChangesetPublish($cid, ['kind' => 'list_item', 'target' => 'page:en_scan-custom', 'listKey' => 'faq.items', 'index' => 0], $publisher)['ok'], 'second list item published');
$doc = json_decode((string)file_get_contents($customFile), true);
csAssert(array_map(fn($i) => !empty($i['hidden']), $doc['features']['items']) === [false, true, false, true] && !isset($doc['faq']['items'][0]['hidden']), 'only the published items became visible; items that were hidden before stay hidden');
csAssert(nibblyAgentChangesetUndo($cid, nibblyAgentUndoToken($cid), $owner)['ok'] && file_get_contents($customFile) === $customBefore, 'undo restores the template page byte for byte');

$limits = csWorker($site, 'custom-limits');
csAssert(isset($limits['over']), 'limits worker: ' . json_encode($limits)); csAssert($limits['over'][0] === 3 && str_contains($limits['over'][1], 'would have 7 items; at most 6'), 'the list limit of the site is enforced while staging: ' . json_encode($limits['over']));
csAssert($limits['removeOps'] === 2 && count($limits['minFinalize']['validation']['errors']) === 1 && $limits['minFinalize']['status'] === 'staging' && str_contains($limits['minFinalize']['validation']['errors'][0]['message'], 'at least 2 are required'), 'a minimum is enforced when the package is presented, not while staging: ' . json_encode($limits));
csAssert(file_get_contents($customFile) === $customBefore, 'no limit test touched the page');

echo "PASS agent changesets: staging rules, normalisation, review, apply with backups and journal, publish, undo, conflicts, rollback, crash recovery, render check, custom template pages with lists, ownership, cleanup\n";
