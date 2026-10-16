<?php
/**
 * Central, extendable registry of what is known about models: whether a reasoning-effort setting
 * exists, which levels are valid, the recommended level per purpose and sampling restrictions.
 * UI and backend both read model-capabilities.json, so they cannot disagree.
 *
 * Reasoning parameters are only ever sent for a model listed there on its official host. Unknown
 * models, hosts and the "legacy" mode of migrated installs send nothing and keep the provider default.
 */

/** Purposes a model call serves. chat = assistant, agent, full content drafts; text = field suggestions, rewrites, SEO. */
const NIBBLY_AI_PURPOSES = ['chat', 'text'];
/** Levels offered in the UI. none, minimal, xhigh and max exist at some providers but are not offered. */
const NIBBLY_AI_EFFORT_LEVELS = ['low', 'medium', 'high'];

function nibblyAiCapabilityCatalog(): array {
    static $catalog;
    return $catalog ??= json_decode((string)file_get_contents(__DIR__ . '/model-capabilities.json'), true, 512, JSON_THROW_ON_ERROR);
}

function nibblyAiBaseUrlHost(string $baseUrl): string {
    return strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
}

/** The registry entry of a model on the configured provider and host, or null when it is not known. */
function nibblyAiModelInfo(array $settings, string $model): ?array {
    $model = strtolower(trim($model));
    if ($model === '') return null;
    $provider = nibblyAiProviderKey($settings);
    $host = nibblyAiBaseUrlHost((string)($settings['baseUrl'] ?? ''));
    foreach (nibblyAiCapabilityCatalog()['models'] as $entry) {
        if ($entry['provider'] === $provider && strtolower($entry['host']) === $host && strtolower($entry['model']) === $model) {
            return $entry;
        }
    }
    return null;
}

/** Stored mode for a purpose: recommended, low, medium, high, or legacy (nothing sent; installs from before the setting existed). */
function nibblyAiReasoningMode(array $settings, string $purpose): string {
    $mode = (string)($settings['reasoning'][$purpose] ?? 'legacy');
    return in_array($mode, array_merge(['recommended', 'legacy'], NIBBLY_AI_EFFORT_LEVELS), true) ? $mode : 'recommended';
}

/** The model configured for a purpose. */
function nibblyAiPurposeModel(array $settings, string $purpose): string {
    $chat = (string)($settings['chatModel'] ?? '');
    return $purpose === 'text' ? ((string)($settings['textModel'] ?? '') ?: $chat) : $chat;
}

/**
 * The effort level to send for a call, or null to send nothing. A model other than the one configured
 * for the purpose (an explicit override by the caller) gets the recommended level of that model.
 */
function nibblyAiResolveEffort(array $settings, string $model, string $purpose): ?string {
    if (!in_array($purpose, NIBBLY_AI_PURPOSES, true)) return null;
    $info = nibblyAiModelInfo($settings, $model);
    if (!$info || empty($info['effort'])) return null;
    $mode = nibblyAiReasoningMode($settings, $purpose);
    if ($mode === 'legacy') return null;
    if (strtolower(trim($model)) !== strtolower(trim(nibblyAiPurposeModel($settings, $purpose)))) $mode = 'recommended';
    if ($mode === 'recommended') return (string)($info['effort']['recommended'][$purpose] ?? $info['effort']['recommended']['chat'] ?? null) ?: null;
    return in_array($mode, $info['effort']['levels'], true) ? $mode : ((string)($info['effort']['recommended'][$purpose] ?? '') ?: null);
}

/** Extra output tokens a thinking model needs on top of the visible answer, because thinking counts toward the output limit. */
function nibblyAiEffortHeadroom(?string $effort): int {
    return $effort === null ? 0 : (int)(nibblyAiCapabilityCatalog()['headroomTokens'][$effort] ?? 0);
}

/**
 * Settle the reasoning part of a request for one call.
 *
 * @return array{effort:?string,omitTemperature:bool,headroom:int,param:?string}
 */
function nibblyAiReasoningPlan(array $settings, string $model, string $purpose): array {
    $effort = nibblyAiResolveEffort($settings, $model, $purpose);
    $info = $effort !== null ? nibblyAiModelInfo($settings, $model) : null;
    return [
        'effort' => $effort,
        'omitTemperature' => $info !== null && !empty($info['omitTemperature']),
        'headroom' => nibblyAiEffortHeadroom($effort),
        'param' => $info['effort']['param'] ?? null
    ];
}

/** Add the effort parameter of the right API to an already built request body. $api: chat (Chat Completions), responses, anthropic. */
function nibblyAiApplyReasoning(array $body, array $plan, string $api): array {
    if ($plan['effort'] === null) return $body;
    if ($plan['omitTemperature']) unset($body['temperature']);
    if ($api === 'anthropic' && $plan['param'] === 'anthropic') {
        $body['output_config'] = ['effort' => $plan['effort']];
    } elseif ($api === 'responses' && $plan['param'] === 'openai') {
        $body['reasoning'] = ['effort' => $plan['effort']];
    } elseif ($api === 'chat' && $plan['param'] === 'openai') {
        $body['reasoning_effort'] = $plan['effort'];
    }
    return $body;
}

/** Recommended and default models for a provider, with the default effort setting. Custom OpenAI-compatible hosts get no preset. */
function nibblyAiProviderModelDefaults(string $provider, string $baseUrl = ''): array {
    $catalog = nibblyAiModelCatalog()[$provider] ?? nibblyAiModelCatalog()['openai-compatible'];
    $preset = ['chatModel' => (string)$catalog['text']['chat'], 'textModel' => (string)$catalog['text']['text']];
    if ($provider === 'openai-compatible' && $baseUrl !== '' && nibblyAiBaseUrlHost($baseUrl) !== 'api.openai.com') {
        $preset = ['chatModel' => '', 'textModel' => ''];
    }
    return $preset + ['reasoning' => ['chat' => 'recommended', 'text' => 'recommended']];
}

/** What the browser needs: the registry reduced to the fields the UI uses. */
function nibblyAiCapabilitiesForUi(): array {
    $models = [];
    foreach (nibblyAiCapabilityCatalog()['models'] as $entry) {
        $models[] = [
            'provider' => $entry['provider'], 'host' => $entry['host'], 'model' => $entry['model'], 'label' => $entry['label'] ?? $entry['model'],
            'tier' => $entry['tier'] ?? 'light', 'levels' => $entry['effort']['levels'] ?? [], 'recommended' => $entry['effort']['recommended'] ?? []
        ];
    }
    return ['models' => $models, 'levels' => NIBBLY_AI_EFFORT_LEVELS];
}
