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

/** Settings with defaults applied and every value clamped to a sane range. */
function nibblyAgentSettings(): array {
    $defaults = nibblyAgentDefaultSettings();
    $raw = [];
    if (is_file(NIBBLY_AI_SETTINGS_PATH)) {
        $stored = json_decode((string)file_get_contents(NIBBLY_AI_SETTINGS_PATH), true);
        $raw = is_array($stored['agent'] ?? null) ? $stored['agent'] : [];
    }
    $settings = $defaults;
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
