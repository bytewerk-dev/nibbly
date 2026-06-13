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

echo json_encode([
    'ok' => true,
    'languages' => array_map('basename', $mainLangFiles),
    'copilotKeys' => count($expectedKeys),
    'jsKeys' => count($jsKeys)
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
