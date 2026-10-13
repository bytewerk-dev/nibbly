<?php
/**
 * Smoke tests for frontend AI Assistant translation coverage.
 *
 * Run from the repository root:
 * php tests/copilot-i18n-smoke.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$langDir = $root . '/admin/lang';

function fail(string $message): void {
    throw new RuntimeException($message);
}

function loadLangJson(string $file): array {
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) {
        fail(basename($file) . ' is not valid JSON.');
    }
    return $data;
}

$englishFile = $langDir . '/en.json';
$english = loadLangJson($englishFile);
$expectedKeys = array_values(array_filter(array_keys($english), fn($key) => str_starts_with((string)$key, 'copilot.')));
sort($expectedKeys);
if (!$expectedKeys) {
    fail('No copilot.* keys found in en.json.');
}

$mainLangFiles = glob($langDir . '/??.json') ?: [];
sort($mainLangFiles);
foreach ($mainLangFiles as $file) {
    $data = loadLangJson($file);
    $keys = array_values(array_filter(array_keys($data), fn($key) => str_starts_with((string)$key, 'copilot.')));
    sort($keys);
    $missing = array_values(array_diff($expectedKeys, $keys));
    $extra = array_values(array_diff($keys, $expectedKeys));
    if ($missing || $extra) {
        fail(basename($file) . ' copilot key mismatch. Missing: ' . implode(', ', $missing) . ' Extra: ' . implode(', ', $extra));
    }
    foreach ($expectedKeys as $key) {
        if (!is_string($data[$key] ?? null) || trim((string)$data[$key]) === '') {
            fail(basename($file) . ' has empty value for ' . $key);
        }
    }
}

$js = (string)file_get_contents($root . '/js/ai-copilot.js');
preg_match_all("/label\\(\\s*['\"](copilot\\.[a-z0-9_]+)['\"]/", $js, $matches);
$jsKeys = array_values(array_unique($matches[1] ?? []));
sort($jsKeys);
$missingInEnglish = array_values(array_diff($jsKeys, $expectedKeys));
if ($missingInEnglish) {
    fail('Copilot JS references missing en.json keys: ' . implode(', ', $missingInEnglish));
}

// ---- Agent texts (js/ai-agent.js) ---------------------------------------------------------------------
$expectedAgent = array_values(array_filter(array_keys($english), fn($key) => str_starts_with((string)$key, 'agent.')));
sort($expectedAgent);
if (!$expectedAgent) {
    fail('No agent.* keys found in en.json.');
}
$placeholders = static function (string $text): array {
    preg_match_all('/\{[a-z]+\}/', $text, $found);
    $list = array_values(array_unique($found[0]));
    sort($list);
    return $list;
};
foreach ($mainLangFiles as $file) {
    $data = loadLangJson($file);
    $keys = array_values(array_filter(array_keys($data), fn($key) => str_starts_with((string)$key, 'agent.')));
    sort($keys);
    $missing = array_values(array_diff($expectedAgent, $keys));
    $extra = array_values(array_diff($keys, $expectedAgent));
    if ($missing || $extra) {
        fail(basename($file) . ' agent key mismatch. Missing: ' . implode(', ', $missing) . ' Extra: ' . implode(', ', $extra));
    }
    foreach ($expectedAgent as $key) {
        if (!is_string($data[$key] ?? null) || trim((string)$data[$key]) === '') {
            fail(basename($file) . ' has empty value for ' . $key);
        }
        if ($placeholders($data[$key]) !== $placeholders($english[$key])) {
            fail(basename($file) . ' changes the placeholders of ' . $key . ': ' . $data[$key]);
        }
    }
}
$german = loadLangJson($langDir . '/de.json');
foreach ($expectedAgent as $key) {
    $text = (string)$german[$key];
    if (preg_match('/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihnen)\b/u', $text)) fail('German agent text must use "du": ' . $key . ' = ' . $text);
    if (preg_match('/\p{L}[:*_]innen|\p{L}[:*_]in\b|\p{Ll}Innen\b/u', $text)) fail('German agent text must not use gender special characters: ' . $key . ' = ' . $text);
}
$agentJs = (string)file_get_contents($root . '/js/ai-agent.js');
preg_match_all("/['\"](agent\.[a-z0-9_.]+)['\"]/", $agentJs, $agentMatches);
$agentJsKeys = array_values(array_unique(array_filter($agentMatches[1] ?? [], fn($key) => $key !== 'agent.step.')));
sort($agentJsKeys);
$unknownAgentKeys = array_values(array_diff($agentJsKeys, $expectedAgent));
if ($unknownAgentKeys) {
    fail('Agent JS references missing en.json keys: ' . implode(', ', $unknownAgentKeys));
}
$unusedAgentKeys = array_values(array_filter(array_diff($expectedAgent, $agentJsKeys), fn($key) => !str_starts_with($key, 'agent.step.')));
if ($unusedAgentKeys) {
    fail('Agent texts that no code uses: ' . implode(', ', $unusedAgentKeys));
}
// Every tool has a timeline text; unknown tools fall back to agent.step.unknown.
$toolSource = (string)file_get_contents($root . '/includes/ai/agent/tools.php');
preg_match_all("/^        '([a-z_]+)' => \['description'/m", $toolSource, $toolMatches);
$toolNames = $toolMatches[1] ?? [];
if (count($toolNames) < 25) {
    fail('Could not read the tool names from tools.php (' . count($toolNames) . ').');
}
foreach ($toolNames as $tool) {
    if (!isset($english['agent.step.' . $tool])) fail('No timeline text for tool ' . $tool);
    if (!str_contains($agentJs, "'" . $tool . "'")) fail('js/ai-agent.js does not list tool ' . $tool . ' in TOOL_STEPS.');
}

// ---- Agent settings and system status in the dashboard ----------------------------------------------------------------
$dashboardKeys = array_values(array_filter(array_keys($english), fn($key) => str_starts_with((string)$key, 'ai.agent_') || str_starts_with((string)$key, 'ai.limit') || str_starts_with((string)$key, 'ai.models_') || str_starts_with((string)$key, 'system.agent') || in_array($key, ['system.configured', 'system.not_configured'], true)));
sort($dashboardKeys);
if (count($dashboardKeys) < 50) fail('The agent settings texts are missing from en.json.');
foreach ($mainLangFiles as $file) {
    $data = loadLangJson($file);
    foreach ($dashboardKeys as $key) {
        if (!is_string($data[$key] ?? null) || trim((string)$data[$key]) === '') fail(basename($file) . ' lacks ' . $key);
        if ($placeholders($data[$key]) !== $placeholders($english[$key])) fail(basename($file) . ' changes the placeholders of ' . $key);
    }
}
foreach ($dashboardKeys as $key) {
    $text = (string)$german[$key];
    if (preg_match('/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihnen)\b/u', $text)) fail('German settings text must use "du": ' . $key . ' = ' . $text);
    if (preg_match('/\p{L}[:*_]innen|\p{L}[:*_]in\b|\p{Ll}Innen\b/u', $text)) fail('German settings text must not use gender special characters: ' . $key . ' = ' . $text);
}
$dashboardSources = (string)file_get_contents($root . '/js/ai-copilot.js') . (string)file_get_contents($root . '/admin/dashboard.php') . (string)file_get_contents($root . '/admin/dashboard/scripts/overview-ai.php') . (string)file_get_contents($root . '/admin/dashboard/scripts/system.php');
preg_match_all("/['\"]((?:ai\.agent_|ai\.limit|ai\.models_|system\.agent)[a-zA-Z_]*|system\.configured|system\.not_configured)['\"]/", $dashboardSources, $usedMatches);
$usedDashboardKeys = array_values(array_unique(array_filter($usedMatches[1] ?? [], fn($key) => !in_array($key, ['ai.agent_right_', 'ai.limit_kind_'], true)))); // the rights are looped over below
$unknownDashboardKeys = array_values(array_diff($usedDashboardKeys, $dashboardKeys));
if ($unknownDashboardKeys) fail('The dashboard uses texts that are missing: ' . implode(', ', $unknownDashboardKeys));
foreach (['publish', 'stockImages', 'uploadImages', 'generateImages', 'research', 'structural', 'createPages', 'createNews', 'createEvents', 'editSharedContent'] as $right) {
    if (!isset($english['ai.agent_right_' . $right])) fail('No settings text for the right ' . $right);
}
foreach (['month', 'requests', 'text', 'images'] as $kind) {
    if (!isset($english['ai.limit_kind_' . $kind])) fail('No text for the limit kind ' . $kind);
}
$unusedDashboardKeys = array_values(array_filter(array_diff($dashboardKeys, $usedDashboardKeys), fn($key) => !str_starts_with($key, 'ai.agent_right_') && !str_starts_with($key, 'ai.limit_kind_')));
if ($unusedDashboardKeys) fail('Settings texts that nothing uses: ' . implode(', ', $unusedDashboardKeys));

echo json_encode([
    'ok' => true,
    'languages' => array_map('basename', $mainLangFiles),
    'copilotKeys' => count($expectedKeys),
    'jsKeys' => count($jsKeys),
    'agentKeys' => count($expectedAgent),
    'agentJsKeys' => count($agentJsKeys),
    'dashboardKeys' => count($dashboardKeys)
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
