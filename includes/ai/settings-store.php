<?php
/**
 * Where the AI settings live on disk.
 *
 *   content/ai-settings.json  everything except the keys (models, limits, rights, domains): site configuration,
 *                             safe to keep in version control
 *   content/ai-secrets.json   the API keys only: the provider keys, the Brave Search key and the Pexels key;
 *                             never commit it (it is in .gitignore) and never serve it
 *
 * Readers get one array with both merged, so the rest of the code does not know about the split. Writers hand
 * the whole settings array to nibblyAiWriteStoredSettings(), which sends the keys to the secrets file. A file
 * from an older version that still holds the keys is read as before and moved by nibblyAiMigrateSecrets().
 */

require_once dirname(__DIR__) . '/json-store.php';

if (!defined('NIBBLY_AI_SETTINGS_PATH')) {
    define('NIBBLY_AI_SETTINGS_PATH', dirname(__DIR__, 2) . '/content/ai-settings.json');
}
if (!defined('NIBBLY_AI_SECRETS_PATH')) {
    define('NIBBLY_AI_SECRETS_PATH', dirname(NIBBLY_AI_SETTINGS_PATH) . '/ai-secrets.json');
}

function nibblyAiReadJsonObject(string $path): array {
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/** The stored settings with the keys merged in (keys from a legacy settings file stay valid until they are moved). */
function nibblyAiReadStoredSettings(): array {
    $settings = nibblyAiReadJsonObject(NIBBLY_AI_SETTINGS_PATH);
    $secrets = nibblyAiReadJsonObject(NIBBLY_AI_SECRETS_PATH);
    return $secrets ? array_replace_recursive($settings, $secrets) : $settings;
}

/**
 * The keys of a settings array in the shape of the secrets file. Empty keys are left out.
 *
 * @return array{providerCredentials?:array,research?:array,stock?:array}
 */
function nibblyAiExtractSecrets(array $settings): array {
    $secrets = [];
    $credentials = is_array($settings['providerCredentials'] ?? null) ? $settings['providerCredentials'] : [];
    // A key at the top level (older files) belongs to the provider that was active.
    $active = (string)($settings['provider'] ?? '');
    $top = trim((string)($settings['apiKey'] ?? ''));
    if ($top !== '' && $active !== '' && trim((string)($credentials[$active]['apiKey'] ?? '')) === '') {
        $credentials[$active]['apiKey'] = $top;
    }
    foreach ($credentials as $provider => $entry) {
        $key = is_array($entry) ? trim((string)($entry['apiKey'] ?? '')) : '';
        if ($key !== '') $secrets['providerCredentials'][(string)$provider]['apiKey'] = $key;
    }
    $brave = trim((string)($settings['research']['brave']['apiKey'] ?? ''));
    if ($brave !== '') $secrets['research']['brave']['apiKey'] = $brave;
    $pexels = trim((string)($settings['stock']['pexels']['apiKey'] ?? ''));
    if ($pexels !== '') $secrets['stock']['pexels']['apiKey'] = $pexels;
    return $secrets;
}

/** The settings array without any key. */
function nibblyAiStripSecrets(array $settings): array {
    unset($settings['apiKey']);
    if (is_array($settings['providerCredentials'] ?? null)) {
        foreach ($settings['providerCredentials'] as $provider => $entry) {
            if (is_array($entry)) unset($settings['providerCredentials'][$provider]['apiKey']);
        }
    }
    if (is_array($settings['research']['brave'] ?? null)) {
        unset($settings['research']['brave']['apiKey']);
        if (!$settings['research']['brave']) unset($settings['research']['brave']);
    }
    if (is_array($settings['stock']['pexels'] ?? null)) {
        unset($settings['stock']['pexels']['apiKey']);
        if (!$settings['stock']['pexels']) unset($settings['stock']['pexels']);
        if (!$settings['stock']) unset($settings['stock']);
    }
    return $settings;
}

/** Write the secrets file: owner-only where the system allows it, replaced atomically. */
function nibblyAiWriteSecrets(array $secrets): bool {
    if (!$secrets && !is_file(NIBBLY_AI_SECRETS_PATH)) return true;
    if (!nibblyJsonAtomicWrite(NIBBLY_AI_SECRETS_PATH, $secrets)) return false;
    @chmod(NIBBLY_AI_SECRETS_PATH, 0600);
    return true;
}

/**
 * Store the settings: the keys in the secrets file, the rest in the settings file. The secrets go first, so a
 * failure never leaves a settings file without the keys it used to hold.
 */
function nibblyAiWriteStoredSettings(array $settings): bool {
    if (!nibblyAiWriteSecrets(nibblyAiExtractSecrets($settings))) return false;
    $dir = dirname(NIBBLY_AI_SETTINGS_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $json = json_encode(nibblyAiStripSecrets($settings), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents(NIBBLY_AI_SETTINGS_PATH, $json, LOCK_EX) !== false;
}

/**
 * Move keys that an older version left in the settings file into the secrets file. Does nothing when there
 * are none. Safe to call often.
 */
function nibblyAiMigrateSecrets(): bool {
    $raw = nibblyAiReadJsonObject(NIBBLY_AI_SETTINGS_PATH);
    if (!$raw || nibblyAiExtractSecrets($raw) === [] && !isset($raw['apiKey'])) return false;
    $moved = false;
    $done = nibblyJsonUpdate(NIBBLY_AI_SETTINGS_PATH, static function (array &$data) use (&$moved): bool {
        $secrets = nibblyAiExtractSecrets($data);
        if ($secrets === [] && !isset($data['apiKey'])) return false;
        // Keys that are already in the secrets file win; the file may have been written by a newer save.
        $secrets = array_replace_recursive($secrets, nibblyAiReadJsonObject(NIBBLY_AI_SECRETS_PATH));
        if (!nibblyAiWriteSecrets($secrets)) return false;
        $data = nibblyAiStripSecrets($data);
        $moved = true;
        return true;
    });
    return $done && $moved;
}

/**
 * Delete one stored key right away. $target: "provider:<provider>", "brave" or "pexels". Anything else is refused.
 * Returns whether a key existed.
 */
function nibblyAiClearSecret(string $target): bool {
    nibblyAiMigrateSecrets(); // a key an older version left in the settings file must go too
    $secrets = nibblyAiReadJsonObject(NIBBLY_AI_SECRETS_PATH);
    $existed = false;
    if (str_starts_with($target, 'provider:')) {
        $provider = substr($target, 9);
        if (!in_array($provider, ['openai-compatible', 'openrouter', 'anthropic', 'kie'], true)) throw new InvalidArgumentException('Unknown provider.');
        $existed = trim((string)($secrets['providerCredentials'][$provider]['apiKey'] ?? '')) !== '';
        unset($secrets['providerCredentials'][$provider]['apiKey']);
        if (empty($secrets['providerCredentials'][$provider])) unset($secrets['providerCredentials'][$provider]);
        if (empty($secrets['providerCredentials'])) unset($secrets['providerCredentials']);
    } elseif ($target === 'brave') {
        $existed = trim((string)($secrets['research']['brave']['apiKey'] ?? '')) !== '';
        unset($secrets['research']);
    } elseif ($target === 'pexels') {
        $existed = trim((string)($secrets['stock']['pexels']['apiKey'] ?? '')) !== '';
        unset($secrets['stock']);
    } else {
        throw new InvalidArgumentException('Unknown key.');
    }
    if (!nibblyAiWriteSecrets($secrets)) throw new RuntimeException('Could not remove the key.');
    return $existed;
}
