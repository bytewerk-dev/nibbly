<?php

declare(strict_types=1);

// Agent settings in the AI settings: save, load, masking of keys, clamping, sections kept for older callers.

function settingsAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function settingsThrows(callable $work, string $contains, string $message): void {
    try {
        $work();
    } catch (RuntimeException $e) {
        settingsAssert(str_contains($e->getMessage(), $contains), $message . ' (got: ' . $e->getMessage() . ')');
        return;
    }
    settingsAssert(false, $message . ' (no error)');
}

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-agent-settings-' . bin2hex(random_bytes(4));
mkdir($site . '/content', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
foreach (['includes', 'admin', 'VERSION'] as $item) exec('cp -R ' . escapeshellarg($root . '/' . $item) . ' ' . escapeshellarg($site . '/'));
foreach (['admin/config.php', 'admin/smtp-config.php', 'includes/nav-config.php'] as $local) @unlink($site . '/' . $local);
define('SITE_LANG_DEFAULT', 'en');
$_SESSION = ['admin_role' => 'admin', 'admin_user_id' => 'u_admin', 'admin_username' => 'admin'];
require_once $site . '/includes/ai/agent/research.php';
require_once $site . '/includes/ai/agent/media.php';

const BRAVE = 'BSA-brave-secret-key-1234567890';
const PEXELS = 'pexels-secret-key-ABCDEFGH123456';
$base = ['enabled' => true, 'provider' => 'openai-compatible', 'baseUrl' => 'http://127.0.0.1:9', 'allowLocalProvider' => true, 'apiKey' => 'provider-secret-key-0000', 'chatModel' => 'gpt-4.1-mini',
    'features' => ['backendAssistant' => true, 'seoTextGeneration' => true, 'imageGeneration' => true]];
$file = NIBBLY_AI_SETTINGS_PATH;
$stored = static fn(): array => json_decode((string)file_get_contents($file), true);

// ---- Defaults ------------------------------------------------------------------------------------------
$public = nibblyAiLoadSettings(true);
settingsAssert($public['agent']['enabled'] === false && $public['agent']['roles']['editor']['publish'] === false && $public['agent']['roles']['editor']['createNews'] === true, 'defaults: off, editors may not publish');
settingsAssert($public['research']['hasBraveApiKey'] === false && $public['stock']['hasPexelsApiKey'] === false && $public['research']['respectRobots'] === true, 'defaults: no keys, robots.txt respected');

// ---- Save and load ----------------------------------------------------------------------------------------
$input = $base + [
    'agent' => ['enabled' => true, 'limits' => ['runsPerHour' => 0, 'maxTurns' => 999, 'maxToolCalls' => 25, 'maxCostCentsPerRun' => 50000, 'maxRuntimeSeconds' => 10, 'uploadsPer10Minutes' => 5, 'evil' => 1],
        'roles' => ['editor' => ['publish' => true, 'research' => false, 'superuser' => true]], 'mediaFolder' => 'team-photos', 'creditInNews' => false, 'sourcesInNews' => true, 'extra' => 'x'],
    'research' => ['braveApiKey' => BRAVE, 'allowDomains' => "News.Example.org\nhttps://www.good.test/path\nnot a domain", 'denyDomains' => ['blocked.test'], 'respectRobots' => false],
    'stock' => ['pexelsApiKey' => PEXELS],
];
$saved = nibblyAiSaveSettings($input);
$agent = $saved['agent'];
settingsAssert($agent['enabled'] === true && $agent['limits']['runsPerHour'] === 1 && $agent['limits']['maxTurns'] === 40 && $agent['limits']['maxToolCalls'] === 25
    && $agent['limits']['maxCostCentsPerRun'] === 10000 && $agent['limits']['maxRuntimeSeconds'] === 60 && $agent['limits']['uploadsPer10Minutes'] === 5, 'limits are clamped: ' . json_encode($agent['limits']));
settingsAssert($agent['roles']['editor']['publish'] === true && $agent['roles']['editor']['research'] === false && $agent['roles']['editor']['createPages'] === true && !isset($agent['roles']['editor']['superuser']), 'rights: only known ones, others keep their defaults');
settingsAssert($agent['mediaFolder'] === 'team-photos' && $agent['creditInNews'] === false && $agent['sourcesInNews'] === true && !isset($agent['extra']) && !isset($agent['limits']['evil']), 'only known settings are stored');
settingsAssert($saved['research']['allowDomains'] === ['news.example.org', 'good.test'] && $saved['research']['denyDomains'] === ['blocked.test'] && $saved['research']['respectRobots'] === false, 'domains are normalised: ' . json_encode($saved['research']));
settingsAssert($saved['research']['hasBraveApiKey'] === true && $saved['stock']['hasPexelsApiKey'] === true, 'the keys are reported as set');
$encoded = json_encode($saved);
settingsAssert(!str_contains($encoded, BRAVE) && !str_contains($encoded, PEXELS) && !str_contains($encoded, 'provider-secret'), 'the browser never gets a key');
$loaded = json_encode(nibblyAiLoadSettings(true));
settingsAssert(!str_contains($loaded, BRAVE) && !str_contains($loaded, PEXELS) && !str_contains($loaded, 'provider-secret') && !str_contains($loaded, 'apiKey'), 'neither does a later load');
settingsAssert($stored()['research']['brave']['apiKey'] === BRAVE && $stored()['stock']['pexels']['apiKey'] === PEXELS, 'the keys are stored for the server');

// The agent code reads what was saved
settingsAssert(nibblyAgentSettings()['limits']['maxToolCalls'] === 25 && nibblyAgentSettings()['mediaFolder'] === 'team-photos', 'nibblyAgentSettings() sees the saved values');
settingsAssert(nibblyAgentResearchSettings()['available'] === true && nibblyAgentResearchSettings()['apiKey'] === BRAVE && nibblyAgentResearchSettings()['respectRobots'] === false, 'research settings see the key');
settingsAssert(nibblyAgentStockSettings()['available'] === true && nibblyAgentStockSettings()['apiKey'] === PEXELS, 'stock settings see the key');
$_SESSION['admin_role'] = 'editor';
settingsAssert(nibblyAgentCan('publish') === true && nibblyAgentCan('research') === false && nibblyAgentCan('createNews') === true, 'rights follow the saved settings');
$_SESSION['admin_role'] = 'admin';

// ---- Keys stay when the fields are empty, go when cleared ----------------------------------------------
$again = nibblyAiSaveSettings($base + ['apiKey' => '', 'agent' => ['enabled' => true], 'research' => ['braveApiKey' => '', 'allowDomains' => 'news.example.org'], 'stock' => ['pexelsApiKey' => '']]);
settingsAssert($stored()['research']['brave']['apiKey'] === BRAVE && $stored()['stock']['pexels']['apiKey'] === PEXELS && $again['research']['hasBraveApiKey'] && $again['stock']['hasPexelsApiKey'], 'empty key fields keep the saved keys');
settingsAssert($again['research']['allowDomains'] === ['news.example.org'] && $again['research']['denyDomains'] === ['blocked.test'] && $again['research']['respectRobots'] === false, 'fields that were not sent keep their values');
settingsAssert($again['agent']['limits']['maxToolCalls'] === 25 && $again['agent']['roles']['editor']['publish'] === true && $again['agent']['mediaFolder'] === 'team-photos', 'agent values that were not sent stay as they were');
$replaced = nibblyAiSaveSettings($base + ['apiKey' => '', 'research' => ['braveApiKey' => 'BSA-another-brave-key-999'], 'stock' => []]);
settingsAssert($stored()['research']['brave']['apiKey'] === 'BSA-another-brave-key-999' && $stored()['stock']['pexels']['apiKey'] === PEXELS, 'a new key replaces the old one, the other stays');
$cleared = nibblyAiSaveSettings($base + ['apiKey' => '', 'research' => ['clearBraveApiKey' => true], 'stock' => ['clearPexelsApiKey' => true]]);
settingsAssert($stored()['research']['brave']['apiKey'] === '' && $stored()['stock']['pexels']['apiKey'] === '' && !$cleared['research']['hasBraveApiKey'] && !$cleared['stock']['hasPexelsApiKey'], 'keys can be removed');
settingsAssert(!nibblyAgentResearchSettings()['available'] && !nibblyAgentStockSettings()['available'], 'without keys there is no research and no stock photos');

// ---- Sections survive callers that know nothing about them -----------------------------------------------------
nibblyAiSaveSettings($base + ['apiKey' => '', 'agent' => ['enabled' => true, 'mediaFolder' => 'kept'], 'research' => ['braveApiKey' => BRAVE], 'stock' => ['pexelsApiKey' => PEXELS]]);
$before = $stored();
nibblyAiSaveSettings(array_replace($base, ['apiKey' => '', 'chatModel' => 'gpt-4.1']));
$after = $stored();
settingsAssert($after['agent'] === $before['agent'] && $after['research'] === $before['research'] && $after['stock'] === $before['stock'] && $after['chatModel'] === 'gpt-4.1', 'a save without the agent sections keeps them (the classic settings form of older versions)');

// ---- Refusals ------------------------------------------------------------------------------------------------------------------
foreach ([['research' => ['braveApiKey' => 'short']], ['research' => ['braveApiKey' => 'has spaces in it 12345']], ['stock' => ['pexelsApiKey' => "line\nbreak-key-12345678"]], ['stock' => ['pexelsApiKey' => str_repeat('k', 201)]]] as $bad) {
    settingsThrows(static fn() => nibblyAiSaveSettings($base + ['apiKey' => ''] + $bad), 'API key', 'an invalid key is refused: ' . json_encode($bad));
}
foreach (['Bad Folder', '../up', 'UPPER', '', str_repeat('a', 41), 5] as $folder) {
    settingsThrows(static fn() => nibblyAiSaveSettings($base + ['apiKey' => '', 'agent' => ['mediaFolder' => $folder]]), 'media folder', 'an invalid media folder is refused: ' . json_encode($folder));
}
settingsAssert($stored()['agent']['mediaFolder'] === 'kept', 'a refused save changes nothing');

// ---- Admin status ---------------------------------------------------------------------------------------------------------------
$status = nibblyAgentStatusForAdmin();
settingsAssert(is_bool($status['available']) && is_bool($status['gd']) && is_bool($status['webp']) && isset($status['limits']['maxInputTokens']) && !str_contains(json_encode($status), BRAVE), 'the status tells what the agent needs, without keys');

echo "PASS agent settings: save and load, keys masked and kept, clamping, domains, rights, sections kept for older callers, refusals\n";
