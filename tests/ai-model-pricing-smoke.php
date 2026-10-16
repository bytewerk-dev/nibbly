<?php

declare(strict_types=1);

// Model registry, reasoning effort, per-model prices, migration of older settings, per-provider models, key handling.

function pricingAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function pricingSame($expected, $actual, string $message): void {
    pricingAssert($expected === $actual, $message . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-pricing-' . bin2hex(random_bytes(4));
mkdir($site . '/content', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
foreach (['includes', 'admin', 'VERSION'] as $item) exec('cp -R ' . escapeshellarg($root . '/' . $item) . ' ' . escapeshellarg($site . '/'));
foreach (['admin/config.php', 'admin/smtp-config.php', 'includes/nav-config.php'] as $local) @unlink($site . '/' . $local);
define('SITE_LANG_DEFAULT', 'en');
$_SESSION = ['admin_role' => 'admin', 'admin_user_id' => 'u_admin', 'admin_username' => 'admin'];
require_once $site . '/includes/ai/ai-helper.php';

$openai = ['provider' => 'openai-compatible', 'baseUrl' => 'https://api.openai.com/v1', 'pricing' => ['models' => []], 'reasoning' => ['chat' => 'recommended', 'text' => 'recommended'], 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna'];
$anthropic = ['provider' => 'anthropic', 'baseUrl' => 'https://api.anthropic.com/v1', 'pricing' => ['models' => []], 'reasoning' => ['chat' => 'recommended', 'text' => 'recommended'], 'chatModel' => 'claude-sonnet-5-5', 'textModel' => 'claude-haiku-5-5'];
$ollama = ['provider' => 'openai-compatible', 'baseUrl' => 'http://127.0.0.1:11434/v1', 'pricing' => ['models' => []], 'reasoning' => ['chat' => 'recommended', 'text' => 'recommended'], 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'llama3'];
$openrouter = ['provider' => 'openrouter', 'baseUrl' => 'https://openrouter.ai/api/v1', 'pricing' => ['models' => []], 'reasoning' => ['chat' => 'recommended', 'text' => 'recommended'], 'chatModel' => 'openai/gpt-6.1-sol', 'textModel' => 'openai/gpt-6-luna'];

// ---- Registry and effort ------------------------------------------------------------------------------------------
pricingAssert(nibblyAiModelInfo($openai, 'gpt-6.1-sol') !== null && nibblyAiModelInfo($anthropic, 'claude-haiku-5-5') !== null, 'known models are found');
pricingAssert(nibblyAiModelInfo($ollama, 'gpt-6.1-sol') === null, 'the same model id on a custom endpoint is unknown: no OpenAI rules for compatible hosts');
pricingAssert(nibblyAiModelInfo($openrouter, 'openai/gpt-6.1-sol') === null && nibblyAiModelInfo($openai, 'gpt-4.1-mini') === null, 'OpenRouter models and unknown models have no registry entry');
pricingSame('medium', nibblyAiResolveEffort($openai, 'gpt-6.1-sol', 'chat'), 'recommended chat level');
pricingSame('low', nibblyAiResolveEffort($openai, 'gpt-6-luna', 'text'), 'recommended text level');
pricingSame('medium', nibblyAiResolveEffort($anthropic, 'claude-sonnet-5-5', 'chat'), 'Anthropic chat level is set explicitly (the API default there is high)');
pricingSame('low', nibblyAiResolveEffort($anthropic, 'claude-haiku-5-5', 'text'), 'Anthropic text level');
pricingSame(null, nibblyAiResolveEffort($ollama, 'llama3', 'text'), 'unknown model: nothing is sent');
$same = $openai;
$same['textModel'] = 'gpt-6.1-sol';
$same['reasoning'] = ['chat' => 'high', 'text' => 'low'];
pricingSame('high', nibblyAiResolveEffort($same, 'gpt-6.1-sol', 'chat'), 'the same model keeps its own chat level');
pricingSame('low', nibblyAiResolveEffort($same, 'gpt-6.1-sol', 'text'), '... and its own text level');
pricingSame('medium', nibblyAiResolveEffort($same, 'gpt-6-luna', 'chat'), 'a model other than the configured one gets the recommendation of that model');
$legacy = $openai;
$legacy['reasoning'] = ['chat' => 'legacy', 'text' => 'legacy'];
pricingSame(null, nibblyAiResolveEffort($legacy, 'gpt-6.1-sol', 'chat'), 'legacy installs send nothing');

// ---- Request bodies -----------------------------------------------------------------------------------------------
$plan = nibblyAiReasoningPlan($openai, 'gpt-6.1-sol', 'chat');
$responses = nibblyAiApplyReasoning(nibblyAgentResponsesBody([['role' => 'user', 'content' => 'hi']], [], 'gpt-6.1-sol', 1000, 0.2), $plan, 'responses');
pricingSame(['effort' => 'medium'], $responses['reasoning'] ?? null, 'Responses API: reasoning.effort');
pricingAssert(!isset($responses['temperature']) && !isset($responses['reasoning_effort']), 'Responses API: no temperature, no chat parameter');
$chat = nibblyAiApplyReasoning(['model' => 'gpt-6-luna', 'messages' => [], 'max_tokens' => 10, 'temperature' => 0.3, 'stream' => false], nibblyAiReasoningPlan($openai, 'gpt-6-luna', 'text'), 'chat');
pricingSame('low', $chat['reasoning_effort'] ?? null, 'Chat Completions: reasoning_effort');
pricingAssert(!isset($chat['temperature']) && !isset($chat['reasoning']), 'Chat Completions: no temperature, no Responses parameter');
$claude = nibblyAiApplyReasoning(['model' => 'claude-sonnet-5-5', 'max_tokens' => 10, 'messages' => [], 'temperature' => 0.3], nibblyAiReasoningPlan($anthropic, 'claude-sonnet-5-5', 'chat'), 'anthropic');
pricingSame(['effort' => 'medium'], $claude['output_config'] ?? null, 'Anthropic: output_config.effort');
pricingAssert(!isset($claude['temperature']) && !isset($claude['thinking']), 'Anthropic: no temperature (not accepted on Sonnet 5.5), no thinking block');
$untouched = ['model' => 'llama3', 'messages' => [], 'max_tokens' => 10, 'temperature' => 0.3, 'stream' => false];
pricingSame($untouched, nibblyAiApplyReasoning($untouched, nibblyAiReasoningPlan($ollama, 'llama3', 'text'), 'chat'), 'unknown model: the body stays byte for byte as before');
pricingSame($untouched, nibblyAiApplyReasoning($untouched, nibblyAiReasoningPlan($legacy, 'gpt-6.1-sol', 'chat'), 'chat'), 'legacy: the body stays as before');
pricingSame(4000, $plan['headroom'], 'thinking headroom of medium effort');
pricingSame(16000, nibblyAiOutputBudget(['limits' => ['maxOutputTokens' => 16000]], ['maxOutputTokens' => 15000], $plan), 'the output limit stays the ceiling');
pricingSame(1204, nibblyAiOutputBudget(['limits' => ['maxOutputTokens' => 16000]], ['maxOutputTokens' => 1200], ['headroom' => 4]), 'headroom is added below the ceiling');

// ---- Costs --------------------------------------------------------------------------------------------------------
$fx = 1.1208;
$expectedCents = static fn(float $usd): float => round($usd / $fx * 100, 4);
pricingSame($expectedCents(0.007), nibblyAiCostForUsage($openai, 'gpt-6.1-sol', ['input' => 1000, 'output' => 500])['cents'], 'Sol: 1000 in, 500 out');
pricingSame($expectedCents(300000 * 4 / 1e6 + 1000 * 15 / 1e6), nibblyAiCostForUsage($openai, 'gpt-6.1-sol', ['input' => 300000, 'output' => 1000])['cents'], 'Sol over 272,000 input tokens: 2x input, 1.5x output for the whole request');
pricingSame($expectedCents(272000 * 2 / 1e6), nibblyAiCostForUsage($openai, 'gpt-6.1-sol', ['input' => 272000, 'output' => 0])['cents'], 'exactly 272,000 tokens is not over the threshold');
pricingSame($expectedCents(150000 * 0.5 / 1e6 + 1000 * 2.5 / 1e6), nibblyAiCostForUsage($anthropic, 'claude-haiku-5-5', ['input' => 150000, 'output' => 1000])['cents'], 'Haiku over 100,000 tokens has its own rates');
pricingSame($expectedCents(60000 * 0.5 / 1e6 + 50000 * 0.05 / 1e6 + 1000 * 2.5 / 1e6), nibblyAiCostForUsage($anthropic, 'claude-haiku-5-5', ['input' => 60000, 'cacheRead' => 50000, 'output' => 1000])['cents'], 'cache reads count toward the tier and are billed at the cache rate of that tier');
pricingSame($expectedCents(1000 * 2 / 1e6 + 2000 * 0.10 / 1e6 + 500 * 2.5 / 1e6 + 300 * 10 / 1e6), nibblyAiCostForUsage($anthropic, 'claude-sonnet-5-5', ['input' => 1000, 'cacheRead' => 2000, 'cacheWrite' => 500, 'output' => 300])['cents'], 'Sonnet with cache read and write');
// Provider reports: OpenAI cached tokens are part of the prompt; reasoning tokens are part of the output and not added twice
$openAiUsage = nibblyAiNormalizeUsage(['prompt_tokens' => 1000, 'completion_tokens' => 300, 'prompt_tokens_details' => ['cached_tokens' => 400], 'completion_tokens_details' => ['reasoning_tokens' => 100]]);
pricingSame(['input' => 600, 'cacheRead' => 400, 'cacheWrite' => 0, 'cacheWrite1h' => 0, 'output' => 300, 'reasoning' => 100, 'reported' => true], $openAiUsage, 'OpenAI usage');
$claudeUsage = nibblyAiNormalizeUsage(['input_tokens' => 50, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 80, 'cache_creation' => ['ephemeral_1h_input_tokens' => 30], 'output_tokens' => 20]);
pricingSame(['input' => 50, 'cacheRead' => 900, 'cacheWrite' => 50, 'cacheWrite1h' => 30, 'output' => 20, 'reasoning' => 0, 'reported' => true], $claudeUsage, 'Anthropic usage');
pricingSame($expectedCents(300 * 10 / 1e6 + 600 * 2 / 1e6 + 400 * 0.10 / 1e6), nibblyAiCostForUsage($openai, 'gpt-6.1-sol', $openAiUsage)['cents'], 'reasoning tokens are billed once, as part of the output');
pricingSame(nibblyAiCostForUsage($openai, 'gpt-6.1-sol', ['input' => 1000, 'output' => 1000])['cents'], nibblyAiCostForUsage(array_replace($openai, ['reasoning' => ['chat' => 'high', 'text' => 'high']]), 'gpt-6.1-sol', ['input' => 1000, 'output' => 1000])['cents'], 'no multiplier for the effort level');
// Unknown prices are never 0 and never another model's price
$unknown = nibblyAiCostForUsage($ollama, 'llama3', ['input' => 1000, 'output' => 1000]);
pricingAssert($unknown['priced'] === false && $unknown['basis']['priced'] === false, 'unknown price is reported as unknown');
pricingAssert(nibblyAiPriceFor($ollama, 'gpt-6.1-sol') === null && nibblyAiPriceFor($openrouter, 'openai/gpt-6.1-sol') === null, 'a custom endpoint or OpenRouter does not borrow the OpenAI price');
// Own prices
$own = $ollama;
$own['pricing']['models'] = ['openai-compatible|llama3' => ['input' => 1.0, 'output' => 2.0, 'currency' => 'EUR']];
pricingSame(round((1000 * 1.0 + 1000 * 2.0) / 1e6 * 100, 4), nibblyAiCostForUsage($own, 'llama3', ['input' => 1000, 'output' => 1000])['cents'], 'EUR override is not converted');
$overridden = $openai;
$overridden['pricing']['models'] = ['openai-compatible|gpt-6.1-sol' => ['input' => 4.0, 'output' => 20.0, 'currency' => 'USD']];
pricingSame($expectedCents(4000 / 1e6 + 20000 / 1e6), nibblyAiCostForUsage($overridden, 'gpt-6.1-sol', ['input' => 1000, 'output' => 1000])['cents'], 'an override wins over the catalog');
$ownRate = $openai;
$ownRate['pricing']['usdPerEur'] = 2.0;
pricingSame(round(0.007 / 2.0 * 100, 4), nibblyAiCostForUsage($ownRate, 'gpt-6.1-sol', ['input' => 1000, 'output' => 500])['cents'], 'own exchange rate');
$basis = nibblyAiCostForUsage($openai, 'gpt-6.1-sol', ['input' => 1000, 'output' => 500])['basis'];
pricingAssert($basis['usdPerEur'] == 1.1208 && $basis['fxAsOf'] !== '' && $basis['input'] == 2.0 && $basis['output'] == 10.0, 'the price basis keeps rates, exchange rate and its date');

// ---- Storage: new installs, per-provider models, reasoning, key handling ---------------------------------------------
$fresh = nibblyAiLoadSettings(false);
pricingSame(['gpt-6.1-sol', 'gpt-6-luna'], [$fresh['chatModel'], $fresh['textModel']], 'new installs: OpenAI defaults');
pricingSame(['recommended', 'recommended'], array_values($fresh['reasoning']), 'new installs: recommended levels');
$saved = nibblyAiSaveSettings(['enabled' => true, 'provider' => 'anthropic', 'apiKey' => 'sk-ant-secret-key-0001', 'chatModel' => '', 'textModel' => '']);
pricingSame(['claude-sonnet-5-5', 'claude-haiku-5-5'], [$saved['chatModel'], $saved['textModel']], 'empty models fall back to the provider presets');
$saved = nibblyAiSaveSettings(['provider' => 'openai-compatible', 'apiKey' => 'sk-openai-secret-key-0002', 'chatModel' => 'gpt-6-luna', 'textModel' => 'gpt-6-luna', 'reasoning' => ['chat' => 'high', 'text' => 'low']]);
pricingSame(['gpt-6-luna', 'high', 'low'], [$saved['chatModel'], $saved['reasoning']['chat'], $saved['reasoning']['text']], 'saved OpenAI choices');
$saved = nibblyAiSaveSettings(['provider' => 'anthropic', 'chatModel' => 'claude-sonnet-5-5', 'textModel' => 'claude-haiku-5-5', 'reasoning' => ['chat' => 'recommended', 'text' => 'medium']]);
pricingSame('medium', $saved['reasoning']['text'], 'saved Anthropic effort');
pricingSame('high', $saved['providerModels']['openai-compatible']['reasoning']['chat'], 'the other provider keeps its choices');
$back = nibblyAiSaveSettings(['provider' => 'openai-compatible', 'chatModel' => 'gpt-6-luna', 'textModel' => 'gpt-6-luna']);
pricingSame(['high', 'low'], [$back['reasoning']['chat'], $back['reasoning']['text']], 'switching back restores the effort of that provider');
pricingSame('medium', $back['providerModels']['anthropic']['reasoning']['text'], 'and the other side stays');
$invalid = nibblyAiSaveSettings(['provider' => 'openai-compatible', 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-4.1-mini', 'reasoning' => ['chat' => 'xhigh', 'text' => 'high']]);
pricingSame(['recommended', 'recommended'], [$invalid['reasoning']['chat'], $invalid['reasoning']['text']], 'unsupported levels and levels for unknown models become "recommended"');
// Keys
$stored = static fn(): array => nibblyAiReadStoredSettings();
pricingSame('sk-openai-secret-key-0002', $stored()['providerCredentials']['openai-compatible']['apiKey'], 'the key is stored');
nibblyAiSaveSettings(['provider' => 'openai-compatible', 'apiKey' => "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}", 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna']);
pricingSame('sk-openai-secret-key-0002', $stored()['providerCredentials']['openai-compatible']['apiKey'], 'a mask is never saved as a key');
nibblyAiSaveSettings(['provider' => 'openai-compatible', 'apiKey' => '', 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna']);
pricingSame('sk-openai-secret-key-0002', $stored()['providerCredentials']['openai-compatible']['apiKey'], 'an empty field keeps the key');
nibblyAiSaveSettings(['provider' => 'openai-compatible', 'apiKey' => 'sk-openai-secret-key-0003', 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna']);
pricingSame('sk-openai-secret-key-0003', $stored()['providerCredentials']['openai-compatible']['apiKey'], 'a real entry replaces the key');
$public = nibblyAiLoadSettings(true);
pricingAssert(!str_contains(json_encode($public), 'sk-openai') && !str_contains(json_encode($public), 'sk-ant'), 'no key is sent to the browser');
pricingAssert($public['providerCredentials']['openai-compatible']['hasApiKey'] === true && $public['providerCredentials']['anthropic']['hasApiKey'] === true, 'only the stored state is sent');
pricingSame(true, nibblyAiClearSecret('provider:anthropic'), 'removing a stored key reports it existed');
$public = nibblyAiLoadSettings(true);
pricingAssert($public['providerCredentials']['anthropic']['hasApiKey'] === false && $public['providerCredentials']['openai-compatible']['hasApiKey'] === true, 'removal affects only that provider');
pricingSame(false, nibblyAiClearSecret('provider:anthropic'), 'removing twice is harmless');
nibblyAiSaveSettings(['provider' => 'openai-compatible', 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna', 'research' => ['braveApiKey' => 'BSA-brave-secret-key-1234'], 'stock' => ['pexelsApiKey' => 'pexels-secret-key-ABCDEFGH']]);
pricingSame('BSA-brave-secret-key-1234', $stored()['research']['brave']['apiKey'], 'Brave key stored');
nibblyAiSaveSettings(['provider' => 'openai-compatible', 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna', 'research' => ['braveApiKey' => "\u{2022}\u{2022}\u{2022}\u{2022}"]]);
pricingSame('BSA-brave-secret-key-1234', $stored()['research']['brave']['apiKey'], 'a mask does not replace the Brave key');
pricingSame(true, nibblyAiClearSecret('brave'), 'Brave key removed');
pricingAssert(!isset($stored()['research']['brave']['apiKey']) && ($stored()['stock']['pexels']['apiKey'] ?? '') === 'pexels-secret-key-ABCDEFGH', 'only the Brave key is gone');
pricingAssert((nibblyAiLoadSettings(true)['research']['hasBraveApiKey'] ?? true) === false, 'the status says so');
try { nibblyAiClearSecret('../etc'); pricingAssert(false, 'unknown targets are refused'); } catch (InvalidArgumentException $e) { /* expected */ }
pricingAssert((fileperms(NIBBLY_AI_SECRETS_PATH) & 0777) === 0600, 'the secrets file stays owner-only');

// ---- Price overrides through the form -----------------------------------------------------------------------------------
$withPrice = nibblyAiSaveSettings(['provider' => 'openai-compatible', 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna', 'pricing' => ['models' => ['openai-compatible|gpt-6-luna' => ['input' => '0.2', 'output' => '1', 'currency' => 'EUR']], 'usdPerEur' => 1.2]]);
pricingAssert($withPrice['pricing']['models']['openai-compatible|gpt-6-luna'] == ['input' => 0.2, 'output' => 1.0, 'currency' => 'EUR'], 'a price entered in the form is stored');
pricingAssert($withPrice['pricing']['usdPerEur'] == 1.2, 'own exchange rate is stored');
pricingAssert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $withPrice['pricing']['usdPerEurAsOf']) === 1, 'with its date');
$kept = nibblyAiSaveSettings(['provider' => 'openai-compatible', 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna', 'pricing' => ['models' => []]]);
pricingAssert(isset($kept['pricing']['models']['openai-compatible|gpt-6-luna']), 'prices the form does not mention stay');
$removed = nibblyAiSaveSettings(['provider' => 'openai-compatible', 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna', 'pricing' => ['models' => ['openai-compatible|gpt-6-luna' => null], 'usdPerEur' => 0]]);
pricingAssert(!isset($removed['pricing']['models']['openai-compatible|gpt-6-luna']) && (float)$removed['pricing']['usdPerEur'] === 0.0, 'a price can be removed, and the exchange rate reset');

// ---- Migration of an older settings file -----------------------------------------------------------------------------
unlink(NIBBLY_AI_SETTINGS_PATH);
@unlink(NIBBLY_AI_SECRETS_PATH);
file_put_contents(NIBBLY_AI_SETTINGS_PATH, json_encode([
    'enabled' => true, 'provider' => 'openai-compatible', 'baseUrl' => 'https://api.openai.com/v1', 'chatModel' => 'gpt-4.1-mini', 'textModel' => 'gpt-4.1-mini',
    'pricing' => ['inputCentsPerMillion' => 20, 'outputCentsPerMillion' => 80, 'imageCentsPerRequest' => 7],
    'providerCredentials' => ['openai-compatible' => ['baseUrl' => 'https://api.openai.com/v1', 'apiKey' => 'sk-old-secret-key-0000', 'organization' => 'org-1']]
]));
$old = nibblyAiLoadSettings(false);
pricingSame(['gpt-4.1-mini', 'gpt-4.1-mini'], [$old['chatModel'], $old['textModel']], 'existing models are kept');
pricingSame(['legacy', 'legacy'], array_values($old['reasoning']), 'no stored effort: the former request behavior (nothing sent)');
pricingSame(null, nibblyAiResolveEffort($old, 'gpt-4.1-mini', 'chat'), 'no parameter is sent for them');
$migrated = $old['pricing']['models']['openai-compatible|gpt-4.1-mini'] ?? null;
pricingAssert($migrated == ['input' => 0.2, 'output' => 0.8, 'currency' => 'EUR', 'migrated' => true], 'the manual prices carry over as marked estimates (cents per million to euro per million)');
pricingSame(0.2 * 1000 / 1e6 * 100 + 0.8 * 500 / 1e6 * 100, nibblyAiCostForUsage($old, 'gpt-4.1-mini', ['input' => 1000, 'output' => 500])['cents'], 'the estimate is the same as before for the models in use (without the whole-cent rounding)');
$resaved = nibblyAiSaveSettings(['provider' => 'openai-compatible', 'chatModel' => 'gpt-4.1-mini', 'textModel' => 'gpt-4.1-mini']);
pricingSame(['legacy', 'legacy'], array_values($resaved['reasoning']), 'saving without touching the setting keeps legacy');
pricingAssert(!empty($resaved['pricing']['models']['openai-compatible|gpt-4.1-mini']['migrated']) && $resaved['pricing']['imageCentsPerRequest'] === 7, 'the carried-over price is persisted and still marked; the image price stays');
pricingAssert(!isset(json_decode((string)file_get_contents(NIBBLY_AI_SETTINGS_PATH), true)['pricing']['inputCentsPerMillion']), 'the former global pair is gone from the file after a save');
$switched = nibblyAiSaveSettings(['provider' => 'openai-compatible', 'chatModel' => 'gpt-6.1-sol', 'textModel' => 'gpt-6-luna', 'reasoning' => ['chat' => 'recommended', 'text' => 'recommended']]);
pricingSame(['recommended', 'recommended'], array_values($switched['reasoning']), 'choosing "recommended" leaves legacy');
pricingAssert(!empty($switched['pricing']['models']['openai-compatible|gpt-4.1-mini']), 'prices of models no longer in use stay');
pricingAssert(file_exists(NIBBLY_AI_SECRETS_PATH) && ($stored()['providerCredentials']['openai-compatible']['apiKey'] ?? '') === 'sk-old-secret-key-0000', 'existing credentials stay');

// ---- Sums of tiny amounts, ledger ------------------------------------------------------------------------------------------
@unlink(NIBBLY_AI_USAGE_PATH);
foreach (range(1, 1000) as $i) {
    unset($GLOBALS['nibblyAiRequestId']);
    nibblyAiRecordUsage('text', 10, 5, 0.0132, ['model' => 'gpt-6-luna', 'priced' => true]);
}
nibblyAiRecordUsage('text', 10, 5, 0.0, ['model' => 'llama3', 'priced' => false]);
$month = nibblyAiLoadUsage()['months'][gmdate('Y-m')];
pricingAssert((float)$month['estimatedCostCents'] === 13.2, '1000 requests of 0.0132 cents add up exactly (no whole-cent rounding per request)');
pricingSame([1001, 1], [$month['requests'], $month['unpricedRequests']], 'unpriced requests are counted apart, not as 0 cents');

// A settled request keeps the price basis it was priced with
$GLOBALS['nibblyAiRequestId'] = 'req_basis';
nibblyAiAssertWithinLimits($openai + ['limits' => ['monthlyBudgetCents' => 0, 'dailyRequests' => 0, 'dailyTextRequests' => 0, 'dailyImageRequests' => 0, 'maxInputTokens' => 1, 'maxOutputTokens' => 1, 'requestTimeoutSeconds' => 1], 'imageModel' => 'x'], 'text', 0.5, ['model' => 'gpt-6.1-sol', 'priced' => true]);
$settled = nibblyAiSettleText($openai, 'gpt-6.1-sol', ['prompt_tokens' => 100, 'completion_tokens' => 50], 100, 'ok');
unset($GLOBALS['nibblyAiRequestId']);
$entry = nibblyAiLoadUsage()['reservations']['req_basis'];
pricingAssert($entry['status'] === 'settled' && $entry['model'] === 'gpt-6.1-sol' && $entry['priceBasis']['input'] == 2.0 && $entry['priceBasis']['usdPerEur'] == 1.1208, 'the ledger entry stores the model and the price basis of its time');
pricingAssert($settled['estimatedCostCents'] == $entry['actualEstimatedCents'], 'and the cost it was settled with');

echo "PASS model registry, effort per purpose, request bodies, tiered prices, cache and reasoning tokens, unknown prices, migration, per-provider models, key handling, tiny sums\n";
