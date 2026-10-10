<?php

declare(strict_types=1);

// Agent tools, rights, context compaction and deferral of a second template render.
// Throw-away site from tests/fixtures/agent/site; the deferral runs in its own processes.
//
//   php tests/agent-tools-smoke.php
//   php tests/agent-tools-smoke.php worker <scenario> <site> [args]   (internal)

function toolAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function toolBoot(string $site): void {
    define('SITE_LANG_DEFAULT', 'en');
    define('CONTENT_BASE_PATH', $site . '/content/pages/');
    $_SESSION = ['admin_role' => 'editor', 'admin_user_id' => 'u_editor', 'admin_username' => 'editor'];
    require_once $site . '/includes/ai/agent/run.php';
}

if (($argv[1] ?? '') === 'worker') {
    [$scenario, $site] = [$argv[2], $argv[3]];
    toolBoot($site);
    $actor = nibblyAgentActor();
    $run = json_decode((string)file_get_contents($site . '/run.json'), true);
    $limits = nibblyAgentSettings()['limits'];
    nibblyAgentRunExecutePending($run, $actor, $limits, microtime(true) + 30);
    file_put_contents($site . '/run.json', json_encode($run));
    echo "RESULT:" . json_encode(['pending' => array_column($run['pending'], 'id'), 'deferrals' => array_column($run['pending'], 'deferrals', 'id'),
        'tools' => array_column(array_filter($run['transcript'], fn($m) => $m['role'] === 'tool'), 'isError', 'toolCallId'), 'status' => $run['status']]) . "\n";
    exit(0);
}

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-tools-' . bin2hex(random_bytes(4));
mkdir($site . '/tests', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
foreach (['includes', 'admin', 'VERSION'] as $item) exec('cp -R ' . escapeshellarg($root . '/' . $item) . ' ' . escapeshellarg($site . '/'));
foreach (['admin/config.php', 'admin/smtp-config.php', 'includes/nav-config.php'] as $local) @unlink($site . '/' . $local);
exec('cp -R ' . escapeshellarg($root . '/tests/fixtures/agent/site/.') . ' ' . escapeshellarg($site . '/'));
rename($site . '/lang-en', $site . '/en');
copy(__FILE__, $site . '/tests/agent-tools-smoke.php');
mkdir($site . '/assets/images/team', 0755, true);
mkdir($site . '/content/news', 0755, true);
copy($root . '/assets/images/placeholder-image.webp', $site . '/assets/images/library.webp');
copy($root . '/assets/images/placeholder-image.webp', $site . '/assets/images/team/anna.webp');
file_put_contents($site . '/content/news/2026-10-01-demo-post.json', json_encode(['id' => '2026-10-01-demo-post', 'slug' => 'demo-post', 'lang' => 'en', 'title' => 'Demo post', 'date' => '2026-10-01', 'excerpt' => 'Short', 'image' => '/assets/images/library.webp', 'content' => '<p>Body</p>', 'hidden' => false]));
file_put_contents($site . '/content/events.json', json_encode(['events' => [['id' => '2026-11-05-open-day', 'date' => '2026-11-05', 'time' => '10:00', 'title' => ['en' => 'Open day'], 'location' => ['en' => 'Hall'], 'description' => ['en' => ''], 'admission' => ['en' => ''], 'image' => '']]]));
file_put_contents($site . '/content/pages/footer.json', json_encode(['tagline' => ['en' => 'We build']]));
file_put_contents($site . '/content/pages/en_secret.json', json_encode(['page' => 'en_secret', 'lang' => 'en', 'title' => 'Board minutes', 'visibility' => ['status' => 'private', 'passwordHash' => '$2y$10$HashThatMustNeverLeak'], 'sections' => [['id' => 's', 'type' => 'text', 'title' => 'Minutes', 'content' => '<p>Confidential</p>']]]));

toolBoot($site);
$settingsFile = $site . '/content/ai-settings.json';
function toolSettings(string $file, array $agent): void {
    file_put_contents($file, json_encode(['enabled' => true, 'agent' => $agent]));
}
toolSettings($settingsFile, ['enabled' => true]);

// ---- Settings and rights -----------------------------------------------------------------------
$settings = nibblyAgentSettings();
toolAssert($settings['enabled'] === true && $settings['limits']['maxTurns'] === 14 && $settings['limits']['maxToolCalls'] === 40 && $settings['limits']['runsPerHour'] === 10 && $settings['limits']['stepsPerMinute'] === 60, 'defaults from the plan');
toolSettings($settingsFile, ['enabled' => true, 'limits' => ['maxTurns' => 9999, 'maxToolCalls' => -3, 'maxCostCentsPerRun' => 'abc'], 'roles' => ['editor' => ['structural' => false, 'publish' => 'yes', 'bogus' => true]]]);
$settings = nibblyAgentSettings();
toolAssert($settings['limits']['maxTurns'] === 40 && $settings['limits']['maxToolCalls'] === 1 && $settings['limits']['maxCostCentsPerRun'] === 100 && $settings['roles']['editor']['structural'] === false && $settings['roles']['editor']['publish'] === true && !isset($settings['roles']['editor']['bogus']), 'settings are clamped and typed, unknown keys ignored');
toolAssert(nibblyAgentCan('edit') && nibblyAgentCan('chat') && !nibblyAgentCan('structural') && nibblyAgentCan('createNews') && !nibblyAgentCan('editSharedContent') && !nibblyAgentCan('nonsense'), 'editor rights');
$_SESSION['admin_role'] = 'admin';
toolAssert(nibblyAgentCan('structural') && nibblyAgentCan('editSharedContent') && nibblyAgentCan('publish'), 'admins can do everything');
$_SESSION['admin_role'] = 'visitor';
toolAssert(!nibblyAgentCan('chat') && !nibblyAgentCan('edit'), 'no role, no rights');
$_SESSION['admin_role'] = 'editor';
toolSettings($settingsFile, ['enabled' => true]);

$names = array_column(nibblyAgentToolsForUser(), 'name');
toolAssert(count($names) === 22 && in_array('read_url', $names, true) && in_array('stage_add_section', $names, true) && in_array('generate_image', $names, true) && !in_array('search_web', $names, true) && !in_array('search_stock_images', $names, true) && !in_array('import_stock_image', $names, true), 'the tool whitelist (stock photos only with a key): ' . implode(', ', $names));
foreach (nibblyAgentToolsForUser() as $tool) {
    toolAssert(preg_match('/^[a-z_]{3,32}$/', $tool['name']) === 1 && ($tool['parameters']['additionalProperties'] ?? null) === false && $tool['description'] !== '', "{$tool['name']} has a strict schema");
    toolAssert(!preg_match('/"(file|filename|filepath|dir|directory|template|code|script)"\s*:/i', json_encode($tool['parameters'])), "{$tool['name']} takes no file, directory or code argument");
}
toolSettings($settingsFile, ['enabled' => true, 'roles' => ['editor' => ['structural' => false, 'createPages' => false]]]);
$limited = array_column(nibblyAgentToolsForUser(), 'name');
toolAssert(!array_intersect($limited, ['stage_add_list_item', 'stage_hide_list_item', 'stage_remove_list_item', 'stage_move_list_item', 'stage_add_section', 'stage_create_page']) && in_array('stage_create_news', $limited, true), 'tools without a right are not offered');
toolSettings($settingsFile, ['enabled' => true]);

// ---- Tools ----------------------------------------------------------------------------------------
$actor = nibblyAgentActor();
$run = ['id' => 'run_0123456789abcdef', 'owner' => 'u_editor', 'status' => 'running', 'transcript' => [['role' => 'user', 'content' => 'Please update the standard page. Source: https://example.org/source, mail info@example.org.']], 'changesetId' => '', 'pending' => []];
$exec = static function (string $tool, array $args) use (&$run, $actor): array {
    $outcome = nibblyAgentExecuteTool($run, ['id' => 'c_' . $tool, 'name' => $tool, 'arguments' => $args], $actor);
    return json_decode($outcome['content'], true) + ['_error' => $outcome['isError'], '_digest' => $outcome['digest']];
};
$overview = $exec('get_site_overview', []);
toolAssert($overview['today'] === date('Y-m-d') && $overview['languages'][0] === 'en' && $overview['pagesTotal'] >= 5 && $overview['yourRights']['structural'] === true && $overview['yourRights']['publish'] === false, 'site overview');
$secret = array_values(array_filter($overview['pages'], fn($p) => $p['target'] === 'page:en_secret'))[0];
toolAssert(($secret['private'] ?? false) === true && !isset($secret['title']) && !str_contains(json_encode($overview), 'Board minutes') && !str_contains(json_encode($overview), 'HashThatMustNeverLeak'), 'private pages are listed without their title');
toolAssert($overview['latestNews'][0]['target'] === 'news:2026-10-01-demo-post', 'latest news');
$list = $exec('list_content', ['type' => 'page', 'query' => 'scan', 'limit' => 2]);
toolAssert($list['count'] >= 3 && count($list['items']) === 2, 'list_content respects the limit');
toolAssert($exec('list_content', ['type' => 'news'])['items'][0]['title'] === 'Demo post' && $exec('list_content', ['type' => 'event'])['items'][0]['target'] === 'event:2026-11-05-open-day', 'news and events');
$media = $exec('list_media', ['query' => 'anna']);
toolAssert($media['total'] === 1 && $media['items'][0]['libraryPath'] === '/assets/images/team/anna.webp' && $media['items'][0]['width'] > 0, 'list_media');
toolAssert($exec('list_media', ['folder' => '../..'])['_error'] === true && $exec('list_media', ['folder' => 'team'])['total'] === 1, 'media folders cannot leave the library');
toolAssert($exec('list_run_media', [])['items'] === [], 'no run media yet');

$caps = $exec('get_page_capabilities', ['target' => 'page:en_scan-standard']);
toolAssert($caps['kind'] === 'standard-page' && count($caps['fields']) === 9, 'capabilities of a standard page');
toolAssert($exec('get_page_capabilities', ['target' => 'page:en_nope'])['_error'] === true && $exec('get_page_capabilities', ['target' => 'page:../x'])['_error'] === true, 'unknown targets are errors, not exceptions');
$private = $exec('read_content', ['target' => 'page:en_secret']);
toolAssert($private['_error'] && $private['error'] === 'This page is private.' && !str_contains(json_encode($private), 'Confidential'), 'a private page that nobody named stays closed');
toolAssert($exec('get_page_capabilities', ['target' => 'page:en_secret'])['_error'] === true && $exec('stage_set_field', ['target' => 'page:en_secret', 'path' => 'sections.0.title', 'value' => 'x'])['_error'] === true, 'also for capabilities and staging');
$run['transcript'][] = ['role' => 'user', 'content' => 'Look at the board minutes page'];
$named = $exec('read_content', ['target' => 'page:en_secret']);
toolAssert(!$named['_error'] && str_contains(json_encode($named), 'Confidential') && !str_contains(json_encode($named), 'HashThatMustNeverLeak') && !str_contains(json_encode($named), 'passwordHash'), 'named by the user: readable, but never the hash');
$read = $exec('read_content', ['target' => 'page:en_scan-standard', 'path' => 'sections.1.content']);
toolAssert($read['value'] === '<p>Body text</p>' && $read['truncated'] === false, 'read one path');
toolAssert($exec('read_content', ['target' => 'page:en_scan-custom', 'path' => 'contact.apiKey'])['_error'] && $exec('read_content', ['target' => 'page:en_scan-custom', 'path' => 'visibility.passwordHash'])['_error'], 'sensitive paths cannot be read');
$whole = $exec('read_content', ['target' => 'page:en_scan-custom']);
toolAssert(!str_contains(json_encode($whole), 'SECRET-KEY-123') && !str_contains(json_encode($whole), 'FixtureHash') && isset($whole['value']['hero']), 'reading a page leaves out secrets and visibility');
toolAssert($exec('read_content', ['target' => 'page:en_scan-standard', 'path' => 'sections.9.title'])['_error'] === true && $exec('read_content', ['target' => 'page:en_scan-standard', 'path' => '../etc'])['_error'] === true, 'bad paths are errors');

// Staging through tools: goal URLs are allowed, the list shows after each list operation
$stage = $exec('stage_set_field', ['target' => 'news:2026-10-01-demo-post', 'path' => 'excerpt', 'value' => 'Fresh excerpt']);
toolAssert($stage['ok'] === true && $stage['opId'] === 'op_1' && $run['changesetId'] !== '', 'the first stage call creates the change set');
$cs = nibblyAgentChangesetLoad($run['changesetId']);
toolAssert($cs['owner'] === 'u_editor' && $cs['runId'] === $run['id'] && $cs['allowedUrls'] === ['https://example.org/source', 'mailto:info@example.org'], 'URLs and addresses from the user\'s message become the allowed external links: ' . json_encode($cs['allowedUrls']));
toolAssert($exec('stage_set_field', ['target' => 'event:2026-11-05-open-day', 'path' => 'url', 'value' => 'https://example.org/source'])['ok'] === true && $exec('stage_set_field', ['target' => 'event:2026-11-05-open-day', 'path' => 'url', 'value' => 'https://other.example'])['_error'] === true, 'only the named external link is accepted');
$insert = $exec('stage_add_section', ['target' => 'page:en_scan-standard', 'position' => 1, 'sectionType' => 'text', 'fields' => ['title' => 'New block', 'content' => '<p>x</p>']]);
toolAssert($insert['ok'] && $insert['sections'][1]['type'] === 'text' && !empty($insert['sections'][1]['hidden']) && count($insert['sections']) === 5, 'a section operation returns the sections as they are now');
$bad = $exec('stage_set_field', ['target' => 'page:en_scan-standard', 'path' => 'sections.1.nope', 'value' => 'x']);
toolAssert($bad['_error'] && isset($bad['hint']), 'errors carry a hint');
toolAssert($exec('stage_revert_op', ['opId' => 'op_9'])['_error'] === true, 'reverting an unknown operation fails cleanly');
toolAssert($exec('stage_set_field', ['target' => 'page:footer', 'path' => 'tagline.en', 'value' => 'x'])['error'] === 'Shared content (footer, sidebar) is not available to you.', 'shared content needs its right');

// Validate, present, ask
$notYet = $exec('present_changeset', ['summary' => 'x']);
toolAssert($notYet['_error'] === false || isset($notYet['error']), 'present answers');
$validated = $exec('validate_changeset', []);
toolAssert($validated['ready'] === true && $validated['renderCheck']['status'] === 'passed' && $validated['errors'] === [], 'validation passes: ' . json_encode($validated));
$presented = $exec('present_changeset', ['summary' => 'Two changes', 'openQuestions' => ['Is the excerpt right?']]);
toolAssert($presented['ok'] === true && $run['end'] === 'awaiting_review' && $run['summary'] === 'Two changes' && $run['openQuestions'] === ['Is the excerpt right?'], 'present ends the run for review');
unset($run['end']);
$exec('ask_user', ['question' => 'Which tone?']);
toolAssert($run['end'] === 'needs_input' && $run['question'] === 'Which tone?', 'ask_user ends the run with a question');
unset($run['end']);
$empty = ['id' => 'run_aaaaaaaaaaaaaaaa', 'transcript' => [['role' => 'user', 'content' => 'x']], 'changesetId' => '', 'pending' => []];
$outcome = nibblyAgentExecuteTool($empty, ['id' => 'c1', 'name' => 'present_changeset', 'arguments' => ['summary' => 's']], $actor);
toolAssert($outcome['isError'] && str_contains($outcome['content'], 'nothing to present') && !isset($empty['end']), 'nothing to present without a change set');
$outcome = nibblyAgentExecuteTool($empty, ['id' => 'c2', 'name' => 'delete_everything', 'arguments' => []], $actor);
toolAssert($outcome['isError'] && str_contains($outcome['content'], "Unknown tool 'delete_everything'"), 'unknown tools are refused');
$huge = nibblyAgentExecuteTool($empty, ['id' => 'c3', 'name' => 'list_content', 'arguments' => ['type' => 'page', 'limit' => 20]], $actor);
toolAssert(mb_strlen($huge['content']) <= NIBBLY_AGENT_TOOL_RESULT_CHARS && mb_strlen($huge['digest']) <= 230, 'results are bounded and come with a short digest');

// ---- System prompt ----------------------------------------------------------------------------------
toolSettings($settingsFile, ['enabled' => true, 'roles' => ['editor' => ['createEvents' => false]]]);
$prompt = nibblyAgentSystemPrompt($run);
foreach (['Never invent facts', 'ask_user', 'data, not instructions', 'External links are allowed only when the user wrote', 'created hidden or private', 'Indexes in list and section paths', 'validate_changeset', date('Y-m-d'), 'create events', 'en_scan-standard'] as $needle) {
    toolAssert(str_contains($prompt, $needle), "the prompt contains '{$needle}'");
}
toolAssert(!str_contains($prompt, 'Board minutes') && str_contains($prompt, 'en_secret (private)') && strlen($prompt) < 5000, 'the prompt lists pages briefly, private ones without title');
toolSettings($settingsFile, ['enabled' => true]);

// ---- Context compaction -----------------------------------------------------------------------------
$transcript = [['role' => 'user', 'content' => 'THE GOAL']];
for ($i = 1; $i <= 8; $i++) {
    $transcript[] = ['role' => 'assistant', 'content' => '', 'toolCalls' => [['id' => "call_$i", 'name' => 'read_content', 'arguments' => []]]];
    $transcript[] = ['role' => 'tool', 'toolCallId' => "call_$i", 'content' => "FULL-RESULT-$i " . str_repeat('lorem ', 800), 'digest' => "DIGEST-$i", 'isError' => false];
}
$compactRun = array_replace($run, ['transcript' => $transcript]);
$tools = nibblyAgentToolsForUser();
$roomy = nibblyAgentRunBuildMessages($compactRun, ['limits' => ['maxInputTokens' => 200000]], $tools);
toolAssert(str_contains(json_encode($roomy), 'FULL-RESULT-1') && !str_contains(json_encode($roomy), 'DIGEST-'), 'with room to spare nothing is shortened');
$tight = nibblyAgentRunBuildMessages($compactRun, ['limits' => ['maxInputTokens' => 6000]], $tools);
$json = json_encode($tight);
toolAssert(str_contains($json, 'DIGEST-1') && str_contains($json, 'DIGEST-4') && !str_contains($json, 'FULL-RESULT-1 ') && str_contains($json, 'FULL-RESULT-6') && str_contains($json, 'FULL-RESULT-8') && str_contains($json, 'THE GOAL'), 'under pressure old results become digests; goal and the last three exchanges stay complete');
toolAssert($tight[0]['role'] === 'system' && count(array_filter($tight, fn($m) => $m['role'] === 'tool')) === 8, 'every tool result is still answered, only shorter');
toolAssert(nibblyAgentCleanMessages($tight) !== [], 'the shortened transcript still passes the pairing check');

// ---- A second custom template in one step is deferred, then finished ----------------------------------------
$pending = [['id' => 'call_a', 'name' => 'get_page_capabilities', 'arguments' => ['target' => 'page:en_scan-custom'], 'error' => null],
    ['id' => 'call_b', 'name' => 'get_page_capabilities', 'arguments' => ['target' => 'page:en_scan-second'], 'error' => null],
    ['id' => 'call_c', 'name' => 'get_site_overview', 'arguments' => [], 'error' => null]];
$deferRun = ['id' => 'run_bbbbbbbbbbbbbbbb', 'owner' => 'u_editor', 'status' => 'running', 'roundToolCalls' => 0, 'toolCalls' => 0, 'failures' => [], 'events' => [], 'eventSeq' => 0, 'changesetId' => '', 'pending' => $pending,
    'transcript' => [['role' => 'user', 'content' => 'x'], ['role' => 'assistant', 'content' => '', 'toolCalls' => array_map(fn($c) => array_intersect_key($c, array_flip(['id', 'name', 'arguments'])), $pending)]]];
file_put_contents($site . '/run.json', json_encode($deferRun));
function toolWorker(string $site, string $scenario): array {
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', $site . '/tests/agent-tools-smoke.php', 'worker', $scenario, $site], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $site);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($p);
    foreach (explode("\n", $out) as $line) if (str_starts_with($line, 'RESULT:')) return json_decode(substr($line, 7), true);
    return ['_out' => $out, '_err' => $err];
}
$first = toolWorker($site, 'pending');
toolAssert($first['pending'] === ['call_b', 'call_c'] && $first['deferrals'] === ['call_b' => 1] && $first['tools'] === ['call_a' => false], 'the second template render waits for the next step, later calls keep their order: ' . json_encode($first));
$second = toolWorker($site, 'pending');
toolAssert($second['pending'] === [] && $second['tools'] === ['call_a' => false, 'call_b' => false, 'call_c' => false], 'the next step finishes the rest: ' . json_encode($second));

echo "PASS agent tools: whitelist and rights, read tools, private pages, staging through tools, validate/present/ask, prompt, compaction, deferred template renders\n";
