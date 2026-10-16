<?php
/**
 * Model-dependent cost estimation.
 *
 * Prices live in pricing-catalog.json (USD per 1M tokens, with source and date) and can be overridden
 * per provider and model in the settings (USD or EUR). A model without a price is never priced as 0 or
 * with another model's price: the cost is reported as unknown.
 *
 * Costs are kept in cents with four decimals (one millionth of a euro), summed with rounding to that
 * precision, and only rounded to whole cents for display.
 */

const NIBBLY_AI_COST_DECIMALS = 4;

function nibblyAiPricingCatalog(): array {
    static $catalog;
    return $catalog ??= json_decode((string)file_get_contents(__DIR__ . '/pricing-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** Key of a price override: provider key and lower-cased model id. */
function nibblyAiPriceKey(array $settings, string $model): string {
    return nibblyAiProviderKey($settings) . '|' . strtolower(trim($model));
}

/** USD per EUR in use: the admin's own rate when set, otherwise the one of the price catalog (with date). */
function nibblyAiFxRate(array $settings): array {
    $own = (float)($settings['pricing']['usdPerEur'] ?? 0);
    if ($own > 0) {
        return ['usdPerEur' => $own, 'asOf' => (string)($settings['pricing']['usdPerEurAsOf'] ?? ''), 'source' => 'settings'];
    }
    $fx = nibblyAiPricingCatalog()['fx'];
    return ['usdPerEur' => (float)$fx['usdPerEur'], 'asOf' => (string)$fx['asOf'], 'source' => (string)$fx['source']];
}

function nibblyAiRoundCents(float $cents): float {
    return round($cents, NIBBLY_AI_COST_DECIMALS);
}

/** Add costs without floating point drift. */
function nibblyAiAddCents(float|int $a, float|int $b): float {
    return nibblyAiRoundCents((float)$a + (float)$b);
}

/**
 * The price of a model, or null when none is known.
 *
 * @return array{source:string,currency:string,input:float,output:float,cacheRead:?float,cacheWrite:?float,cacheWrite1h:?float,tiers:array,migrated:bool,label:string,url:string,updatedAt:string,model:string,provider:string}|null
 */
function nibblyAiPriceFor(array $settings, string $model): ?array {
    $model = trim($model);
    if ($model === '') return null;
    $provider = nibblyAiProviderKey($settings);
    $override = $settings['pricing']['models'][nibblyAiPriceKey($settings, $model)] ?? null;
    if (is_array($override) && isset($override['input'], $override['output'])) {
        return [
            'source' => 'override', 'currency' => ($override['currency'] ?? 'USD') === 'EUR' ? 'EUR' : 'USD',
            'input' => (float)$override['input'], 'output' => (float)$override['output'],
            'cacheRead' => isset($override['cacheRead']) ? (float)$override['cacheRead'] : null,
            'cacheWrite' => isset($override['cacheWrite']) ? (float)$override['cacheWrite'] : null,
            'cacheWrite1h' => isset($override['cacheWrite1h']) ? (float)$override['cacheWrite1h'] : null,
            'tiers' => [], 'migrated' => !empty($override['migrated']), 'label' => $model, 'url' => '', 'updatedAt' => '',
            'model' => $model, 'provider' => $provider
        ];
    }
    $host = nibblyAiBaseUrlHost((string)($settings['baseUrl'] ?? ''));
    foreach (nibblyAiPricingCatalog()['models'] as $entry) {
        if ($entry['provider'] === $provider && strtolower($entry['host']) === $host && strtolower($entry['model']) === strtolower($model)) {
            return [
                'source' => 'catalog', 'currency' => nibblyAiPricingCatalog()['currency'],
                'input' => (float)$entry['input'], 'output' => (float)$entry['output'],
                'cacheRead' => isset($entry['cacheRead']) ? (float)$entry['cacheRead'] : null,
                'cacheWrite' => isset($entry['cacheWrite']) ? (float)$entry['cacheWrite'] : null,
                'cacheWrite1h' => isset($entry['cacheWrite1h']) ? (float)$entry['cacheWrite1h'] : null,
                'tiers' => (array)($entry['tiers'] ?? []), 'migrated' => false, 'label' => (string)($entry['label'] ?? $model),
                'url' => (string)($entry['source'] ?? ''), 'updatedAt' => (string)($entry['updatedAt'] ?? ''),
                'model' => $model, 'provider' => $provider
            ];
        }
    }
    return null;
}

/** Input tokens that count for the context tiers: everything in the prompt, cache reads and writes included. */
function nibblyAiPromptTokens(array $usage): int {
    return (int)($usage['input'] ?? 0) + (int)($usage['cacheRead'] ?? 0) + (int)($usage['cacheWrite'] ?? 0) + (int)($usage['cacheWrite1h'] ?? 0);
}

/** The rates for a request of this size (a tier over a threshold applies to the whole request). */
function nibblyAiTierRates(array $price, int $promptTokens): array {
    $rates = [
        'input' => $price['input'], 'output' => $price['output'],
        'cacheRead' => $price['cacheRead'] ?? $price['input'], 'cacheWrite' => $price['cacheWrite'] ?? $price['input'],
        'cacheWrite1h' => $price['cacheWrite1h'] ?? ($price['cacheWrite'] ?? $price['input'])
    ];
    $applied = null;
    foreach ($price['tiers'] as $tier) {
        if ($promptTokens > (int)$tier['aboveInputTokens'] && (!$applied || $tier['aboveInputTokens'] > $applied['aboveInputTokens'])) $applied = $tier;
    }
    if ($applied) {
        foreach (['input', 'output', 'cacheRead', 'cacheWrite', 'cacheWrite1h'] as $key) {
            if (isset($applied[$key])) $rates[$key] = (float)$applied[$key];
        }
        foreach (['input' => 'inputMultiplier', 'output' => 'outputMultiplier'] as $key => $multiplier) {
            if (isset($applied[$multiplier])) $rates[$key] *= (float)$applied[$multiplier];
        }
        if (isset($applied['cacheMultiplier'])) {
            foreach (['cacheRead', 'cacheWrite', 'cacheWrite1h'] as $key) $rates[$key] *= (float)$applied['cacheMultiplier'];
        }
    }
    $rates['tier'] = $applied ? (int)$applied['aboveInputTokens'] : 0;
    return $rates;
}

/**
 * Cost in cents of one request.
 *
 * $usage: input (normal input tokens, without cache reads and writes), cacheRead, cacheWrite, cacheWrite1h and output.
 * Output already contains the reasoning tokens, so they are not added again. There is no multiplier for the effort level.
 */
function nibblyAiComputeCents(array $settings, array $price, array $usage): float {
    $rates = nibblyAiTierRates($price, nibblyAiPromptTokens($usage));
    $native = ((int)($usage['input'] ?? 0) * $rates['input']
        + (int)($usage['cacheRead'] ?? 0) * $rates['cacheRead']
        + (int)($usage['cacheWrite'] ?? 0) * $rates['cacheWrite']
        + (int)($usage['cacheWrite1h'] ?? 0) * $rates['cacheWrite1h']
        + (int)($usage['output'] ?? 0) * $rates['output']) / 1000000;
    $eur = $price['currency'] === 'EUR' ? $native : $native / nibblyAiFxRate($settings)['usdPerEur'];
    return nibblyAiRoundCents($eur * 100);
}

/** What a cost was based on; stored with every ledger entry so later price changes do not rewrite history. */
function nibblyAiPriceBasis(array $settings, ?array $price, array $usage): array {
    if ($price === null) return ['priced' => false];
    $rates = nibblyAiTierRates($price, nibblyAiPromptTokens($usage));
    $basis = [
        'priced' => true, 'source' => $price['source'], 'currency' => $price['currency'], 'provider' => $price['provider'], 'model' => $price['model'],
        'input' => $rates['input'], 'output' => $rates['output'], 'cacheRead' => $rates['cacheRead'], 'cacheWrite' => $rates['cacheWrite'], 'tierAbove' => $rates['tier']
    ];
    if ($price['migrated']) $basis['migrated'] = true;
    if ($price['currency'] === 'USD') {
        $fx = nibblyAiFxRate($settings);
        $basis['usdPerEur'] = $fx['usdPerEur'];
        $basis['fxAsOf'] = $fx['asOf'];
    }
    return $basis;
}

/**
 * Cost of a finished request.
 *
 * @return array{cents:float,priced:bool,basis:array}
 */
function nibblyAiCostForUsage(array $settings, string $model, array $usage): array {
    $price = nibblyAiPriceFor($settings, $model);
    if ($price === null) return ['cents' => 0.0, 'priced' => false, 'basis' => ['priced' => false, 'provider' => nibblyAiProviderKey($settings), 'model' => $model]];
    return ['cents' => nibblyAiComputeCents($settings, $price, $usage), 'priced' => true, 'basis' => nibblyAiPriceBasis($settings, $price, $usage)];
}

/**
 * Worst case for the reservation before a request: the estimated input and the full output limit (plus the
 * thinking headroom), at the rates of the tier the input falls into.
 *
 * @return array{cents:float,priced:bool,basis:array}
 */
function nibblyAiReservationCost(array $settings, string $model, int $inputTokens, int $maxOutput): array {
    return nibblyAiCostForUsage($settings, $model, ['input' => $inputTokens, 'output' => $maxOutput]);
}

/**
 * Read the token counts of any provider response into normalized usage.
 * OpenAI: cached tokens are part of prompt_tokens / input_tokens, reasoning tokens part of the output.
 * Anthropic: input_tokens excludes cache reads and writes, which have their own fields.
 *
 * @return array{input:int,cacheRead:int,cacheWrite:int,cacheWrite1h:int,output:int,reasoning:int,reported:bool}|null null when the response reports no usage
 */
function nibblyAiNormalizeUsage(array $usage): ?array {
    if (!$usage) return null;
    $anthropic = array_key_exists('cache_read_input_tokens', $usage) || array_key_exists('cache_creation_input_tokens', $usage) || array_key_exists('cache_creation', $usage);
    if ($anthropic) {
        $write1h = (int)($usage['cache_creation']['ephemeral_1h_input_tokens'] ?? 0);
        $writeAll = (int)($usage['cache_creation_input_tokens'] ?? 0);
        return ['input' => (int)($usage['input_tokens'] ?? 0), 'cacheRead' => (int)($usage['cache_read_input_tokens'] ?? 0),
            'cacheWrite' => max(0, $writeAll - $write1h), 'cacheWrite1h' => min($write1h, $writeAll),
            'output' => (int)($usage['output_tokens'] ?? 0), 'reasoning' => 0, 'reported' => true];
    }
    $prompt = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
    $completion = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
    if ($prompt === null && $completion === null) return null;
    $cached = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? 0);
    $cached = max(0, min($cached, (int)$prompt));
    return ['input' => (int)$prompt - $cached, 'cacheRead' => $cached, 'cacheWrite' => 0, 'cacheWrite1h' => 0, 'output' => (int)$completion,
        'reasoning' => (int)($usage['completion_tokens_details']['reasoning_tokens'] ?? $usage['output_tokens_details']['reasoning_tokens'] ?? 0), 'reported' => true];
}

/** Total tokens of a normalized usage as stored in the usage buckets (input side includes cache). */
function nibblyAiUsageInputTotal(array $usage): int {
    return nibblyAiPromptTokens($usage);
}

/** Overrides migrated from the former global price pair (cents per 1M tokens, EUR) for the models in use. */
function nibblyAiMigrateLegacyPricing(array $stored, string $providerKey, array $models): array {
    $pricing = (array)($stored['pricing'] ?? []);
    if (isset($pricing['models']) || !isset($pricing['inputCentsPerMillion'])) return (array)($pricing['models'] ?? []);
    $overrides = [];
    foreach (array_unique(array_filter(array_map(static fn($m) => strtolower(trim((string)$m)), $models))) as $model) {
        $overrides[$providerKey . '|' . $model] = [
            'input' => (float)$pricing['inputCentsPerMillion'] / 100, 'output' => (float)($pricing['outputCentsPerMillion'] ?? 0) / 100,
            'currency' => 'EUR', 'migrated' => true
        ];
    }
    return $overrides;
}

/** Validated overrides from the settings form. Existing ones not mentioned stay; a null entry removes one. */
function nibblyAiCleanPriceOverrides($input, array $existing): array {
    $result = $existing;
    foreach ((is_array($input) ? $input : []) as $key => $entry) {
        $key = strtolower((string)$key);
        if (!preg_match('/^(openai-compatible|openrouter|anthropic|kie)\|[a-z0-9._:\/-]{1,120}$/', $key)) continue;
        if ($entry === null || !is_array($entry)) { unset($result[$key]); continue; }
        if (!isset($entry['input']) || !isset($entry['output']) || $entry['input'] === '' || $entry['output'] === '') { unset($result[$key]); continue; }
        $clean = ['input' => round(max(0.0, min(100000.0, (float)$entry['input'])), 6), 'output' => round(max(0.0, min(100000.0, (float)$entry['output'])), 6),
            'currency' => ($entry['currency'] ?? 'USD') === 'EUR' ? 'EUR' : 'USD'];
        foreach (['cacheRead', 'cacheWrite', 'cacheWrite1h'] as $field) {
            if (isset($entry[$field]) && $entry[$field] !== '') $clean[$field] = round(max(0.0, min(100000.0, (float)$entry[$field])), 6);
        }
        $before = $existing[$key] ?? null;
        if (!empty($before['migrated']) && $before['input'] == $clean['input'] && $before['output'] == $clean['output'] && $before['currency'] === $clean['currency']) $clean['migrated'] = true;
        $result[$key] = $clean;
    }
    return $result;
}

/** What the settings page shows about prices: catalog entries, the rate with its date and the unit. */
function nibblyAiPricingForUi(array $settings): array {
    $catalog = nibblyAiPricingCatalog();
    $models = [];
    foreach ($catalog['models'] as $entry) {
        $models[] = array_intersect_key($entry, array_flip(['provider', 'host', 'model', 'label', 'input', 'output', 'cacheRead', 'cacheWrite', 'tiers', 'source', 'updatedAt']));
    }
    return ['currency' => $catalog['currency'], 'updatedAt' => $catalog['updatedAt'], 'fx' => nibblyAiFxRate($settings), 'catalogFx' => $catalog['fx'], 'models' => $models];
}
