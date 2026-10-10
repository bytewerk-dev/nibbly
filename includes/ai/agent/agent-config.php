<?php
/**
 * Agent settings, rights and availability (agent phase P4/P7).
 *
 * Kept free of the change set code so every page that shows the assistant can ask
 * "is the agent available for this user?" without loading the whole agent.
 */

require_once dirname(__DIR__) . '/ai-helper.php';

const NIBBLY_AGENT_RIGHTS = ['publish', 'stockImages', 'uploadImages', 'generateImages', 'research', 'structural', 'createPages', 'createNews', 'createEvents', 'editSharedContent'];

function nibblyAgentDefaultSettings(): array {
    return [
        'enabled' => false,
        'limits' => [
            'maxTurns' => 14,
            'maxToolCalls' => 40,
            'maxCostCentsPerRun' => 100,
            'maxRuntimeSeconds' => 900,
            'maxToolFailures' => 3,
            'runsPerHour' => 10,
            'stepsPerMinute' => 60,
            'uploadsPer10Minutes' => 10,
        ],
        'roles' => ['editor' => [
            'publish' => false, 'stockImages' => true, 'uploadImages' => true, 'generateImages' => true, 'research' => true,
            'structural' => true, 'createPages' => true, 'createNews' => true, 'createEvents' => true, 'editSharedContent' => false,
        ]],
        'mediaFolder' => 'agent',
        'creditInNews' => true,
        'sourcesInNews' => true,
    ];
}

/** Raw settings with defaults applied and every value clamped to a sane range. */
function nibblyAgentNormalizeSettings(array $raw): array {
    $settings = nibblyAgentDefaultSettings();
    $settings['enabled'] = !empty($raw['enabled']);
    $ranges = ['maxTurns' => [1, 40], 'maxToolCalls' => [1, 200], 'maxCostCentsPerRun' => [1, 10000], 'maxRuntimeSeconds' => [60, 3600],
        'maxToolFailures' => [1, 10], 'runsPerHour' => [1, 200], 'stepsPerMinute' => [1, 600], 'uploadsPer10Minutes' => [1, 100]];
    foreach ($ranges as $key => [$min, $max]) {
        if (isset($raw['limits'][$key]) && is_numeric($raw['limits'][$key])) {
            $settings['limits'][$key] = max($min, min($max, (int)$raw['limits'][$key]));
        }
    }
    foreach (NIBBLY_AGENT_RIGHTS as $right) {
        if (isset($raw['roles']['editor'][$right])) {
            $settings['roles']['editor'][$right] = (bool)$raw['roles']['editor'][$right];
        }
    }
    foreach (['creditInNews', 'sourcesInNews'] as $flag) {
        if (array_key_exists($flag, $raw)) $settings[$flag] = (bool)$raw[$flag];
    }
    if (isset($raw['mediaFolder']) && is_string($raw['mediaFolder']) && preg_match('/^[a-z0-9_-]{1,40}$/', $raw['mediaFolder'])) {
        $settings['mediaFolder'] = $raw['mediaFolder'];
    }
    return $settings;
}

/** The agent settings stored in content/ai-settings.json, normalised. */
function nibblyAgentSettings(): array {
    $raw = [];
    if (is_file(NIBBLY_AI_SETTINGS_PATH)) {
        $stored = json_decode((string)file_get_contents(NIBBLY_AI_SETTINGS_PATH), true);
        $raw = is_array($stored['agent'] ?? null) ? $stored['agent'] : [];
    }
    return nibblyAgentNormalizeSettings($raw);
}

/** @return string[] lowercase host names (without scheme, path, port or a leading www.) */
function nibblyAgentResearchDomains($value): array {
    if (is_string($value)) $value = preg_split('/[\s,;]+/', $value) ?: [];
    $domains = [];
    foreach ((array)$value as $entry) {
        if (!is_string($entry)) continue;
        $entry = strtolower(trim($entry));
        $entry = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $entry) ?? '';
        $entry = preg_replace('#[/:?\#].*$#', '', $entry) ?? '';
        $entry = preg_replace('/^www\./', '', $entry) ?? '';
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $entry)) $domains[$entry] = true;
        if (count($domains) >= 100) break;
    }
    return array_keys($domains);
}

// ---------------------------------------------------------------------------
// Saving and loading through the AI settings (dashboard)
// ---------------------------------------------------------------------------

/** An API key typed into the settings form: printable ASCII without spaces, or an error. */
function nibblyAgentCleanApiKey(string $key): string {
    $key = trim($key);
    if ($key !== '' && !preg_match('/^[\x21-\x7E]{8,200}$/', $key)) {
        throw new RuntimeException('The API key looks invalid. Paste it exactly as the provider shows it.');
    }
    return $key;
}

/**
 * The sections "agent", "research" and "stock" for content/ai-settings.json. Keys that are not
 * typed again stay as they are; "clear..." flags remove them. Sections missing from the input
 * (older callers) are kept untouched.
 *
 * @param array $input    what the settings form sent
 * @param array $existing the stored settings (raw, with keys)
 * @return array<string,array>
 */
function nibblyAgentSettingsForSave(array $input, array $existing): array {
    $out = [];

    $stored = is_array($existing['agent'] ?? null) ? $existing['agent'] : [];
    if (is_array($input['agent'] ?? null)) {
        $folder = $input['agent']['mediaFolder'] ?? null;
        if ($folder !== null && (!is_string($folder) || !preg_match('/^[a-z0-9_-]{1,40}$/', $folder))) {
            throw new RuntimeException('The media folder may only contain lowercase letters, digits, - and _.');
        }
        $out['agent'] = nibblyAgentNormalizeSettings(array_replace_recursive($stored, $input['agent']));
    } elseif ($stored) {
        $out['agent'] = $stored;
    }

    $stored = is_array($existing['research'] ?? null) ? $existing['research'] : [];
    if (is_array($input['research'] ?? null)) {
        $in = $input['research'];
        $key = (string)($stored['brave']['apiKey'] ?? '');
        if (trim((string)($in['braveApiKey'] ?? '')) !== '') $key = nibblyAgentCleanApiKey((string)$in['braveApiKey']);
        if (!empty($in['clearBraveApiKey'])) $key = '';
        $out['research'] = [
            'brave' => ['apiKey' => $key],
            'allowDomains' => nibblyAgentResearchDomains(array_key_exists('allowDomains', $in) ? $in['allowDomains'] : ($stored['allowDomains'] ?? [])),
            'denyDomains' => nibblyAgentResearchDomains(array_key_exists('denyDomains', $in) ? $in['denyDomains'] : ($stored['denyDomains'] ?? [])),
            'respectRobots' => array_key_exists('respectRobots', $in) ? !empty($in['respectRobots']) : (!array_key_exists('respectRobots', $stored) || !empty($stored['respectRobots'])),
        ];
    } elseif ($stored) {
        $out['research'] = $stored;
    }

    $stored = is_array($existing['stock'] ?? null) ? $existing['stock'] : [];
    if (is_array($input['stock'] ?? null)) {
        $in = $input['stock'];
        $key = (string)($stored['pexels']['apiKey'] ?? '');
        if (trim((string)($in['pexelsApiKey'] ?? '')) !== '') $key = nibblyAgentCleanApiKey((string)$in['pexelsApiKey']);
        if (!empty($in['clearPexelsApiKey'])) $key = '';
        $out['stock'] = ['pexels' => ['apiKey' => $key]];
    } elseif ($stored) {
        $out['stock'] = $stored;
    }
    return $out;
}

/** The same sections for the browser: no keys, only whether they are set. */
function nibblyAgentSettingsForPublic(array $settings): array {
    $research = is_array($settings['research'] ?? null) ? $settings['research'] : [];
    $stock = is_array($settings['stock'] ?? null) ? $settings['stock'] : [];
    return [
        'agent' => nibblyAgentNormalizeSettings(is_array($settings['agent'] ?? null) ? $settings['agent'] : []),
        'research' => [
            'hasBraveApiKey' => trim((string)($research['brave']['apiKey'] ?? '')) !== '',
            'allowDomains' => nibblyAgentResearchDomains($research['allowDomains'] ?? []),
            'denyDomains' => nibblyAgentResearchDomains($research['denyDomains'] ?? []),
            'respectRobots' => !array_key_exists('respectRobots', $research) || !empty($research['respectRobots']),
        ],
        'stock' => ['hasPexelsApiKey' => trim((string)($stock['pexels']['apiKey'] ?? '')) !== ''],
    ];
}

/** What the settings form shows next to the agent switch (admins only). */
function nibblyAgentStatusForAdmin(): array {
    $reason = '';
    $ai = nibblyAiLoadSettings(false);
    return [
        'available' => nibblyAgentAvailable($reason),
        'reason' => $reason,
        'toolCalling' => nibblyAiSupportsToolCalling($ai),
        'gd' => extension_loaded('gd'),
        'webp' => function_exists('imagewebp'),
        'limits' => ['maxInputTokens' => (int)$ai['limits']['maxInputTokens'], 'dailyRequests' => (int)$ai['limits']['dailyRequests'], 'dailyTextRequests' => (int)$ai['limits']['dailyTextRequests']],
    ];
}

function nibblyAgentRole(): string {
    $role = (string)($_SESSION['admin_role'] ?? '');
    return in_array($role, ['admin', 'editor'], true) ? $role : '';
}

/** Whether the signed-in user holds a right ("edit" is implicit for editors and admins). */
function nibblyAgentCan(string $right): bool {
    $role = nibblyAgentRole();
    if ($role === 'admin') return true;
    if ($role !== 'editor') return false;
    if ($right === 'edit' || $right === 'chat') return true;
    return !empty(nibblyAgentSettings()['roles']['editor'][$right]);
}

/**
 * Whether the agent can run at all: switched on, AI enabled with a key, and a provider
 * that supports tool calling. Otherwise the classic Copilot stays the only assistant.
 */
function nibblyAgentAvailable(?string &$reason = null): bool {
    if (!nibblyAgentSettings()['enabled']) {
        $reason = 'The AI agent is switched off.';
        return false;
    }
    $ai = nibblyAiLoadSettings(false);
    try {
        nibblyAiEnsureEnabled($ai);
    } catch (RuntimeException $e) {
        $reason = $e->getMessage();
        return false;
    }
    if (!nibblyAiSupportsToolCalling($ai)) {
        $reason = 'The configured AI provider does not support tool calling.';
        return false;
    }
    return true;
}
