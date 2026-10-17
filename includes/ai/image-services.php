<?php
/**
 * Image services: the place images come from, chosen apart from the chat provider.
 *
 * The registry (image-services.json) lists the services, their adapter and what their models accept. An adapter
 * turns the same input (prompt, count, aspect ratio or size, scale, format, reference images) into the request of
 * one provider, runs it (synchronously or through the provider's task queue) and returns the stored image paths:
 *
 *   openai-images    OpenAI and any service with /images/generations and /images/edits ("custom")
 *   openrouter-chat  OpenRouter image models over chat completions
 *   kie-task         Kie.ai task API (create task, poll, download)
 *   fal-queue        fal.ai queue API (submit, poll status, fetch result, download)
 *   gemini-generate  Gemini generateContent route (Comet API)
 *
 * The first three live in ai-helper.php; they get the chat settings with the credentials of the service put in
 * (nibblyAiImageEffectiveSettings) and run unchanged. With the service "inherit" (default) everything behaves as it
 * did before: the chat provider's own image models are used.
 *
 * Keys sit in content/ai-secrets.json under imageServices.<id>.apiKey. A service of OpenAI, OpenRouter or Kie without
 * an own key uses the chat key of the same provider. Keys never reach the browser, the audit log or job files.
 */

require_once __DIR__ . '/agent/net.php';

const NIBBLY_AI_IMAGE_SERVICE_IDS = ['openai-compatible', 'openrouter', 'kie', 'fal', 'comet', 'custom'];
const NIBBLY_AI_IMAGE_RESERVED_PARAMS = ['prompt', 'model', 'n', 'contents', 'messages', 'input', 'image', 'images', 'image_urls', 'num_images'];

// ---------------------------------------------------------------------------
// Registry and settings
// ---------------------------------------------------------------------------

function nibblyAiImageServiceRegistry(): array {
    static $registry;
    return $registry ??= json_decode((string)file_get_contents(__DIR__ . '/image-services.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** The registry reduced to what the settings page needs (no URLs of the test hooks, no secrets). */
function nibblyAiImageServicesForUi(): array {
    $services = [];
    foreach (nibblyAiImageServiceRegistry()['services'] as $id => $entry) {
        $models = [];
        foreach ((array)($entry['models'] ?? []) as $model) {
            $models[] = [
                'id' => (string)$model['id'], 'label' => (string)($model['label'] ?? $model['id']),
                'verified' => $model['verified'] ?? false, 'note' => (string)($model['note'] ?? ''),
                'aspects' => (array)($model['aspects'] ?? []), 'resolutions' => (array)($model['resolutions'] ?? []),
                'references' => !empty($model['references']), 'price' => $model['price'] ?? null,
            ];
        }
        $services[$id] = [
            'label' => (string)$entry['label'], 'adapter' => (string)$entry['adapter'], 'catalogModels' => !empty($entry['catalogModels']),
            'keyFrom' => $entry['keyFrom'] ?? null, 'defaultModel' => (string)($entry['defaultModel'] ?? ''), 'docs' => (string)($entry['docs'] ?? ''),
            'models' => $models, 'defaults' => ['verified' => $entry['defaults']['verified'] ?? true, 'note' => (string)($entry['defaults']['note'] ?? '')],
        ];
    }
    return ['services' => $services, 'ids' => NIBBLY_AI_IMAGE_SERVICE_IDS];
}

/** The configured image service with defaults filled in. */
function nibblyAiImageServiceConfig(array $settings): array {
    $cfg = is_array($settings['imageService'] ?? null) ? $settings['imageService'] : [];
    $provider = (string)($cfg['provider'] ?? 'inherit');
    if ($provider !== 'inherit' && !in_array($provider, NIBBLY_AI_IMAGE_SERVICE_IDS, true)) $provider = 'inherit';
    $custom = is_array($cfg['custom'] ?? null) ? $cfg['custom'] : [];
    return [
        'provider' => $provider,
        'model' => nibblyAiCleanModelAllowEmpty((string)($cfg['model'] ?? '')),
        'models' => is_array($cfg['models'] ?? null) ? $cfg['models'] : [],
        'custom' => [
            'baseUrl' => rtrim(trim((string)($custom['baseUrl'] ?? '')), '/'),
            'sizeMode' => in_array($custom['sizeMode'] ?? '', ['size', 'aspect_ratio', 'none'], true) ? $custom['sizeMode'] : 'size',
            'authStyle' => ($custom['authStyle'] ?? '') === 'x-api-key' ? 'x-api-key' : 'bearer',
            'extraParams' => is_array($custom['extraParams'] ?? null) ? $custom['extraParams'] : [],
        ],
    ];
}

/** Base URL of a service. Tests may redirect fal and Comet to a loopback mock (NIBBLY_AGENT_TEST_NET.imageBases, CLI and built-in server only). */
function nibblyAiImageServiceBaseUrl(string $id, array $settings): string {
    $test = nibblyNetTestConfig();
    if ($test !== null && isset($test['raw']['imageBases'][$id])) return rtrim((string)$test['raw']['imageBases'][$id], '/');
    if ($id === 'custom') return nibblyAiImageServiceConfig($settings)['custom']['baseUrl'];
    return rtrim((string)(nibblyAiImageServiceRegistry()['services'][$id]['baseUrl'] ?? ''), '/');
}

/**
 * The key of the chat provider that an image service of the same name may use when it has none of its own. Only
 * when that chat provider talks to the service's own host: a key for another OpenAI-compatible host (Groq, Mistral,
 * a local gateway) is never sent to api.openai.com. '' when there is none.
 */
function nibblyAiImageChatKey(array $settings, string $serviceId): string {
    $from = nibblyAiImageServiceRegistry()['services'][$serviceId]['keyFrom'] ?? null;
    if (empty($from)) return '';
    $credentials = (array)($settings['providerCredentials'][$from] ?? []);
    $key = trim((string)($credentials['apiKey'] ?? ''));
    if ($key === '') return '';
    $chatHost = nibblyAiBaseUrlHost((string)($credentials['baseUrl'] ?? ''));
    $serviceHost = nibblyAiBaseUrlHost((string)(nibblyAiImageServiceRegistry()['services'][$serviceId]['baseUrl'] ?? ''));
    return $chatHost !== '' && $chatHost === $serviceHost ? $key : '';
}

/** Spec of a model: the registry entry, or the defaults of the service for a model it does not list. */
function nibblyAiImageModelSpec(string $serviceId, string $model): array {
    $service = nibblyAiImageServiceRegistry()['services'][$serviceId] ?? [];
    foreach ((array)($service['models'] ?? []) as $entry) {
        if (strtolower((string)$entry['id']) === strtolower($model)) return $entry + ['known' => true];
    }
    return (array)($service['defaults'] ?? []) + ['known' => false];
}

/**
 * The image service in effect for these (full) settings.
 *
 * @return array{id:string,inherit:bool,adapter:string,baseUrl:string,apiKey:string,organization:string,model:string,keySource:string,supported:bool,reason:string,spec:array,authStyle:string,extraParams:array,sizeMode:string}
 */
function nibblyAiImageServiceResolve(array $settings): array {
    $cfg = nibblyAiImageServiceConfig($settings);
    $registry = nibblyAiImageServiceRegistry()['services'];
    if ($cfg['provider'] === 'inherit') {
        $id = nibblyAiProviderKey($settings);
        $adapter = ['openai-compatible' => 'openai-images', 'openrouter' => 'openrouter-chat', 'kie' => 'kie-task'][$id] ?? '';
        $key = trim((string)($settings['apiKey'] ?? ''));
        return [
            'id' => $id, 'inherit' => true, 'adapter' => $adapter, 'baseUrl' => rtrim((string)($settings['baseUrl'] ?? ''), '/'), 'apiKey' => $key,
            'organization' => (string)($settings['organization'] ?? ''), 'model' => (string)($settings['imageModel'] ?? ''),
            'keySource' => $key !== '' ? 'chat' : 'none', 'supported' => $adapter !== '', 'reason' => $adapter === '' ? 'chat_provider_without_images' : '',
            'spec' => [], 'authStyle' => 'bearer', 'extraParams' => [], 'sizeMode' => 'size',
        ];
    }
    $id = $cfg['provider'];
    $entry = $registry[$id];
    $own = trim((string)($settings['imageServices'][$id]['apiKey'] ?? ''));
    $chatKey = nibblyAiImageChatKey($settings, $id);
    $key = $own !== '' ? $own : $chatKey;
    $model = $cfg['model'] !== '' ? $cfg['model'] : nibblyAiCleanModelAllowEmpty((string)($cfg['models'][$id] ?? ''));
    if ($model === '') {
        $model = !empty($entry['catalogModels']) ? (string)(nibblyAiModelCatalog()[$id]['images'][0]['value'] ?? '') : (string)($entry['defaultModel'] ?? '');
    }
    $baseUrl = nibblyAiImageServiceBaseUrl($id, $settings);
    $reason = '';
    if ($id === 'custom' && $baseUrl === '') $reason = 'custom_base_url_missing';
    return [
        'id' => $id, 'inherit' => false, 'adapter' => (string)$entry['adapter'], 'baseUrl' => $baseUrl, 'apiKey' => $key,
        'organization' => $own === '' && $chatKey !== '' && $id === 'openai-compatible' ? (string)($settings['providerCredentials']['openai-compatible']['organization'] ?? '') : '',
        'model' => $model, 'keySource' => $own !== '' ? 'own' : ($chatKey !== '' ? 'chat' : 'none'), 'supported' => $reason === '', 'reason' => $reason,
        'spec' => !empty($entry['catalogModels']) ? [] : nibblyAiImageModelSpec($id, $model),
        'authStyle' => $id === 'custom' ? $cfg['custom']['authStyle'] : 'bearer',
        'extraParams' => in_array($id, ['fal', 'comet', 'custom'], true) ? $cfg['custom']['extraParams'] : [],
        'sizeMode' => $cfg['custom']['sizeMode'],
    ];
}

/** The chat settings with the credentials and model of the image service put in; the existing image functions run on this. */
function nibblyAiImageEffectiveSettings(array $settings, array $service): array {
    if (!$service['inherit']) {
        $settings['provider'] = $service['id'];
        $settings['baseUrl'] = $service['baseUrl'];
        $settings['apiKey'] = $service['apiKey'];
        $settings['organization'] = $service['organization'];
    }
    $settings['imageModel'] = $service['model'];
    $settings['authStyle'] = $service['authStyle'];
    return $settings;
}

/** Whether images can be generated with this service: adapter present, model and key (or a permitted local address) set. */
function nibblyAiImageServiceUsable(array $settings): bool {
    $service = nibblyAiImageServiceResolve($settings);
    if (!$service['supported'] || $service['model'] === '') return false;
    return $service['apiKey'] !== '' || (!empty($settings['allowLocalProvider']) && nibblyAiIsLocalBaseUrl($service['baseUrl']));
}

/** Whether the image service takes reference images (edit endpoint, inline data or multipart upload). */
function nibblyAiImageServiceSupportsReferences(array $service): bool {
    return match ($service['adapter']) {
        'openai-images', 'openrouter-chat', 'kie-task' => $service['inherit'] || $service['id'] !== 'custom' || !empty($service['spec']['references']),
        'fal-queue' => !empty($service['spec']['references']['editModel']),
        'gemini-generate' => !empty($service['spec']['references']),
        default => false,
    };
}

/** The effective image model for these settings (what the classic pages, the copilot and the agent report). */
function nibblyAiEffectiveImageModel(array $settings): string {
    return nibblyAiImageServiceResolve($settings)['model'];
}

/** Why images are unavailable, as a short code for the UI and messages; '' when they are available. */
function nibblyAiImageUnavailableReason(array $settings): string {
    $service = nibblyAiImageServiceResolve($settings);
    if (!$service['supported']) return $service['reason'];
    if ($service['model'] === '') return 'model_missing';
    if ($service['apiKey'] === '' && !(!empty($settings['allowLocalProvider']) && nibblyAiIsLocalBaseUrl($service['baseUrl']))) return 'key_missing';
    return '';
}

/** What the browser may know: the choice, the key source and availability of every service. Never a key. */
function nibblyAiImageServicePublic(array $settings): array {
    $cfg = nibblyAiImageServiceConfig($settings);
    $services = [];
    foreach (NIBBLY_AI_IMAGE_SERVICE_IDS as $id) {
        $entry = nibblyAiImageServiceRegistry()['services'][$id];
        $own = trim((string)($settings['imageServices'][$id]['apiKey'] ?? '')) !== '';
        $chat = nibblyAiImageChatKey($settings, $id) !== '';
        $services[$id] = ['hasApiKey' => $own, 'chatKeyAvailable' => $chat, 'keySource' => $own ? 'own' : ($chat ? 'chat' : 'none')];
    }
    $active = nibblyAiImageServiceResolve($settings);
    $priceTable = nibblyAiImagePriceTable($settings, $active, $active['model']);
    return [
        'provider' => $cfg['provider'], 'model' => $cfg['model'], 'models' => $cfg['models'], 'custom' => $cfg['custom'],
        'services' => $services,
        'active' => [
            'id' => $active['id'], 'inherit' => $active['inherit'], 'adapter' => $active['adapter'], 'model' => $active['model'],
            'keySource' => $active['keySource'], 'usable' => nibblyAiImageServiceUsable($settings), 'reason' => nibblyAiImageUnavailableReason($settings),
            'verified' => $active['spec']['verified'] ?? true, 'note' => (string)($active['spec']['note'] ?? ''), 'known' => $active['spec']['known'] ?? true,
            'priceTable' => $priceTable,
        ],
    ];
}

/** Extra request parameters from the settings: a JSON object (or array), limited in size, depth and key names. */
function nibblyAiCleanImageExtraParams($input): array {
    if (is_string($input)) {
        $input = trim($input);
        if ($input === '') return [];
        $decoded = json_decode($input, true);
        if (!is_array($decoded)) throw new RuntimeException('The custom image parameters must be a JSON object.');
        $input = $decoded;
    }
    if (!is_array($input) || !$input) return [];
    if ($input !== [] && array_is_list($input)) throw new RuntimeException('The custom image parameters must be a JSON object.');
    $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > 4000) throw new RuntimeException('The custom image parameters are too long.');
    $depth = static function ($value, int $level) use (&$depth): int {
        if (!is_array($value)) return $level;
        $max = $level;
        foreach ($value as $item) $max = max($max, $depth($item, $level + 1));
        return $max;
    };
    if ($depth($input, 1) > 4) throw new RuntimeException('The custom image parameters are nested too deeply.');
    foreach (array_keys($input) as $key) {
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $key)) throw new RuntimeException('Invalid name in the custom image parameters.');
        if (in_array(strtolower($key), NIBBLY_AI_IMAGE_RESERVED_PARAMS, true)) throw new RuntimeException('"' . $key . '" is set by Nibbly and cannot be a custom image parameter.');
    }
    return $input;
}

/**
 * Validated image service settings from the settings form, and the keys that were typed.
 *
 * @return array{imageService:array,keys:array<string,string>}
 * @throws RuntimeException
 */
function nibblyAiImageServiceFromInput(array $input, array $existing, bool $allowLocal): array {
    $before = nibblyAiImageServiceConfig($existing);
    $in = is_array($input['imageService'] ?? null) ? $input['imageService'] : [];
    $provider = (string)($in['provider'] ?? $before['provider']);
    if ($provider !== 'inherit' && !in_array($provider, NIBBLY_AI_IMAGE_SERVICE_IDS, true)) throw new RuntimeException('Unsupported image service.');
    $model = array_key_exists('model', $in) ? nibblyAiCleanModelAllowEmpty((string)$in['model']) : $before['model'];
    $models = $before['models'];
    foreach ($models as $key => $value) {
        if (!in_array($key, NIBBLY_AI_IMAGE_SERVICE_IDS, true) || !is_string($value)) unset($models[$key]);
    }
    if ($provider !== 'inherit') $models[$provider] = $model;
    $inCustom = is_array($in['custom'] ?? null) ? $in['custom'] : [];
    $custom = $before['custom'];
    if (array_key_exists('baseUrl', $inCustom)) $custom['baseUrl'] = rtrim(trim((string)$inCustom['baseUrl']), '/');
    if (isset($inCustom['sizeMode']) && in_array($inCustom['sizeMode'], ['size', 'aspect_ratio', 'none'], true)) $custom['sizeMode'] = $inCustom['sizeMode'];
    if (isset($inCustom['authStyle'])) $custom['authStyle'] = $inCustom['authStyle'] === 'x-api-key' ? 'x-api-key' : 'bearer';
    if (array_key_exists('extraParams', $inCustom)) $custom['extraParams'] = nibblyAiCleanImageExtraParams($inCustom['extraParams']);
    // A stored address of a service that is not in use must not block saving other settings (for example after the
    // local-provider permission was withdrawn); it is checked when the custom service is chosen and again before every use.
    if ($provider === 'custom' && $custom['baseUrl'] !== '') {
        if (!filter_var($custom['baseUrl'], FILTER_VALIDATE_URL)) throw new RuntimeException('Invalid image service base URL.');
        nibblyAiValidateBaseUrl($custom['baseUrl'], $allowLocal);
    }
    $keys = [];
    foreach (NIBBLY_AI_IMAGE_SERVICE_IDS as $id) {
        $typed = trim((string)($input['imageServices'][$id]['apiKey'] ?? ''));
        if ($typed !== '' && !nibblyAiIsMaskedSecret($typed)) $keys[$id] = $typed;
    }
    return ['imageService' => ['provider' => $provider, 'model' => $model, 'models' => $models, 'custom' => $custom], 'keys' => $keys];
}

// ---------------------------------------------------------------------------
// Price
// ---------------------------------------------------------------------------

/** Key of a per-model image price: service id and lower-cased model id. */
function nibblyAiImagePriceKey(string $serviceId, string $model): string {
    return $serviceId . '|' . strtolower(trim($model));
}

/** Steps of the resolution and quality tables a price entry may carry. */
const NIBBLY_AI_IMAGE_PRICE_RESOLUTIONS = ['0.5K', '1K', '2K', '4K'];
const NIBBLY_AI_IMAGE_PRICE_QUALITIES = ['auto', 'low', 'medium', 'high'];

/** A price in its own currency as euro cents. */
function nibblyAiImageToCents(array $settings, float $amount, string $currency): float {
    return nibblyAiRoundCents($currency === 'EUR' ? $amount * 100 : $amount / nibblyAiFxRate($settings)['usdPerEur'] * 100);
}

/**
 * What an image of this model costs, as the table the price rests on, in euro cents: a price for any image
 * ('default'), and optional prices by resolution step and by quality, which take precedence when the request has
 * that step or quality. Order: the admin's entry for the model, the registry's, then the default price per image of
 * the settings (pricing.imageCentsPerRequest), which keeps installs from before per-model prices guarded.
 *
 * @return array{source:string,currency:string,default:?float,byResolution:array<string,float>,byQuality:array<string,float>}|null
 */
function nibblyAiImagePriceTable(array $settings, array $service, string $model): ?array {
    $candidates = [
        ['override', $settings['pricing']['imageModels'][nibblyAiImagePriceKey($service['id'], $model)] ?? null],
        ['catalog', $service['spec']['price'] ?? null],
    ];
    foreach ($candidates as [$source, $entry]) {
        if (!is_array($entry)) continue;
        $currency = ($entry['currency'] ?? 'USD') === 'EUR' ? 'EUR' : 'USD';
        $table = ['source' => $source, 'currency' => $currency, 'default' => null, 'byResolution' => [], 'byQuality' => []];
        if (isset($entry['perImage']) && is_numeric($entry['perImage'])) $table['default'] = nibblyAiImageToCents($settings, (float)$entry['perImage'], $currency);
        foreach (['byResolution' => NIBBLY_AI_IMAGE_PRICE_RESOLUTIONS, 'byQuality' => NIBBLY_AI_IMAGE_PRICE_QUALITIES] as $field => $allowed) {
            foreach ((array)($entry[$field] ?? []) as $step => $amount) {
                if (in_array($step, $allowed, true) && is_numeric($amount)) $table[$field][$step] = nibblyAiImageToCents($settings, (float)$amount, $currency);
            }
        }
        if ($table['default'] !== null || $table['byResolution'] || $table['byQuality']) return $table;
    }
    $default = (float)($settings['pricing']['imageCentsPerRequest'] ?? 0);
    return $default > 0 ? ['source' => 'default', 'currency' => 'EUR', 'default' => nibblyAiRoundCents($default), 'byResolution' => [], 'byQuality' => []] : null;
}

/**
 * The price of one image in euro cents for a request, or null when none is known ("price not set").
 * $request: resolution ("1K", "2K", "4K") and quality ("low", "medium", "high", "auto") of the request; a price by
 * resolution comes first, then by quality, then the price for any image. A table without a match and without a
 * price for any image falls back to the default price of the settings.
 *
 * @return array{source:string,cents:float,currency:string,basis:string}|null
 */
function nibblyAiImagePrice(array $settings, array $service, string $model, array $request = []): ?array {
    $table = nibblyAiImagePriceTable($settings, $service, $model);
    $resolution = (string)($request['resolution'] ?? '');
    $quality = (string)($request['quality'] ?? '');
    if ($table !== null) {
        if ($resolution !== '' && isset($table['byResolution'][$resolution])) return ['source' => $table['source'], 'cents' => $table['byResolution'][$resolution], 'currency' => $table['currency'], 'basis' => 'resolution:' . $resolution];
        if ($quality !== '' && isset($table['byQuality'][$quality])) return ['source' => $table['source'], 'cents' => $table['byQuality'][$quality], 'currency' => $table['currency'], 'basis' => 'quality:' . $quality];
        if ($table['default'] !== null) return ['source' => $table['source'], 'cents' => $table['default'], 'currency' => $table['currency'], 'basis' => 'any'];
    }
    $default = (float)($settings['pricing']['imageCentsPerRequest'] ?? 0);
    return $default > 0 ? ['source' => 'default', 'cents' => nibblyAiRoundCents($default), 'currency' => 'EUR', 'basis' => 'any'] : null;
}

/**
 * The resolution step the request will ask the provider for ("1K", "2K", "4K"), or '' when the model has none.
 * Prices by resolution are looked up with it.
 */
function nibblyAiImageRequestResolution(array $service, string $model, array $options): string {
    $scale = $options['imageScale'] ?? null;
    switch ($service['adapter']) {
        case 'fal-queue':
        case 'gemini-generate':
            $spec = $service['spec'];
            return !empty($spec['resolutionParam']) && !empty($spec['resolutions']) ? nibblyAiImageResolutionFor($scale, (array)$spec['resolutions']) : '';
        case 'kie-task':
            return $model === 'nano-banana-2' ? nibblyAiKieResolution($scale ?? 2048) : '';
        case 'openrouter-chat':
            return (string)(nibblyAiOpenRouterImageConfig((string)($options['size'] ?? 'auto'), (string)($options['aspectRatio'] ?? 'auto'), $scale, $model)['image_size'] ?? '');
        default:
            return '';
    }
}

/** Validated per-model image prices from the settings form. A null or empty entry removes one; those not mentioned stay. */
function nibblyAiCleanImagePriceOverrides($input, array $existing): array {
    $result = $existing;
    $amount = static fn($value) => round(max(0.0, min(100000.0, (float)$value)), 6);
    foreach ((is_array($input) ? $input : []) as $key => $entry) {
        $key = strtolower((string)$key);
        if (!preg_match('/^(openai-compatible|openrouter|anthropic|kie|fal|comet|custom)\|[a-z0-9._:\/-]{1,120}$/', $key)) continue;
        if (!is_array($entry)) { unset($result[$key]); continue; }
        $clean = ['currency' => ($entry['currency'] ?? 'USD') === 'EUR' ? 'EUR' : 'USD'];
        if (isset($entry['perImage']) && $entry['perImage'] !== '' && is_numeric($entry['perImage'])) $clean = ['perImage' => $amount($entry['perImage'])] + $clean;
        foreach (['byResolution' => NIBBLY_AI_IMAGE_PRICE_RESOLUTIONS, 'byQuality' => NIBBLY_AI_IMAGE_PRICE_QUALITIES] as $field => $allowed) {
            foreach ((is_array($entry[$field] ?? null) ? $entry[$field] : []) as $step => $value) {
                if (in_array($step, $allowed, true) && $value !== '' && $value !== null && is_numeric($value)) $clean[$field][$step] = $amount($value);
            }
        }
        if (count($clean) === 1) unset($result[$key]); else $result[$key] = $clean;
    }
    return $result;
}

// ---------------------------------------------------------------------------
// Shape: aspect ratio, size, scale, format
// ---------------------------------------------------------------------------

/** The ratio a request asks for as "w:h" or 'auto', from the aspect ratio or else the pixel size. */
function nibblyAiImageRequestedRatio(array $p): string {
    $ratio = nibblyAiCleanAspectRatio((string)($p['aspectRatio'] ?? 'auto'));
    if ($ratio !== 'auto') return $ratio;
    if (preg_match('/^(\d+)x(\d+)$/', (string)($p['size'] ?? ''), $m) && (int)$m[1] > 0 && (int)$m[2] > 0) {
        $gcd = nibblyAiGcd((int)$m[1], (int)$m[2]);
        return (int)((int)$m[1] / $gcd) . ':' . (int)((int)$m[2] / $gcd);
    }
    return 'auto';
}

/** The supported ratio closest to a requested one (by logarithmic distance). */
function nibblyAiImageNearestAspect(string $ratio, array $supported): string {
    if (!$supported) return $ratio;
    if (!preg_match('/^(\d+):(\d+)$/', $ratio, $m) || (int)$m[2] === 0 || (int)$m[1] === 0) return (string)$supported[0];
    $requested = (int)$m[1] / (int)$m[2];
    $best = (string)$supported[0];
    $distance = PHP_FLOAT_MAX;
    foreach ($supported as $candidate) {
        [$w, $h] = array_map('intval', explode(':', (string)$candidate, 2));
        if ($w < 1 || $h < 1) continue;
        $d = abs(log($requested) - log($w / $h));
        if ($d < $distance) { $distance = $d; $best = (string)$candidate; }
    }
    return $best;
}

/** The resolution step ("1K", "2K", "4K") for a long-edge scale, limited to what the model offers. */
function nibblyAiImageResolutionFor($scale, array $offered): string {
    $wanted = nibblyAiKieResolution($scale ?? 2048);
    if (!$offered || in_array($wanted, $offered, true)) return $wanted;
    $order = ['0.5K' => 0, '1K' => 1, '2K' => 2, '4K' => 3];
    $best = (string)$offered[0];
    foreach ($offered as $candidate) {
        if (($order[$candidate] ?? 9) <= ($order[$wanted] ?? 0) && ($order[$candidate] ?? 0) >= ($order[$best] ?? 0)) $best = (string)$candidate;
    }
    return $best;
}

/** Pixel size for a generic OpenAI-style endpoint from a ratio. */
function nibblyAiImageCustomSize(string $ratio): string {
    if (!preg_match('/^(\d+):(\d+)$/', $ratio, $m) || (int)$m[2] === 0) return '1024x1024';
    $value = (int)$m[1] / (int)$m[2];
    return $value > 1.2 ? '1536x1024' : ($value < 0.83 ? '1024x1536' : '1024x1024');
}

function nibblyAiImageAuthHeader(array $settings): string {
    $key = trim((string)($settings['apiKey'] ?? ''));
    if ($key === '') return '';
    return ($settings['authStyle'] ?? 'bearer') === 'x-api-key' ? 'x-api-key: ' . $key : 'Authorization: Bearer ' . $key;
}

/** Poll interval of the queue adapters; tests shorten it (NIBBLY_AGENT_TEST_NET.imagePollMs). */
function nibblyAiImagePollMicros(): int {
    $test = nibblyNetTestConfig();
    return $test !== null && isset($test['raw']['imagePollMs']) ? max(1, (int)$test['raw']['imagePollMs']) * 1000 : 2000000;
}

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

/** The message of a provider error body in any of the usual shapes (never the request, so never a key). */
function nibblyAiProviderErrorMessage($data, string $fallback = 'AI provider error'): string {
    if (!is_array($data)) return $fallback;
    $candidate = $data['error']['message'] ?? $data['message'] ?? $data['detail'] ?? $data['error'] ?? $data['msg'] ?? null;
    if (is_array($candidate)) {
        $first = $candidate[0] ?? $candidate;
        $candidate = is_array($first) ? ($first['msg'] ?? $first['message'] ?? null) : $first;
    }
    $text = is_string($candidate) ? trim($candidate) : '';
    return $text !== '' ? mb_substr($text, 0, 1000, 'UTF-8') : $fallback;
}

/** GET a JSON document from a provider. Throws a RuntimeException carrying the HTTP status as code. */
function nibblyAiImageGetJson(array $settings, string $url, array $headers): array {
    if (!function_exists('curl_init')) throw new RuntimeException('This image service requires cURL support.');
    $ch = curl_init($url);
    $buffer = '';
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => min(60, nibblyAiRequestTimeout($settings)),
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOSIGNAL => true, CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer): int {
            $buffer .= $chunk;
            return strlen($buffer) > 8 * 1024 * 1024 ? 0 : strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    unset($ch);
    if ($ok === false) throw new RuntimeException($error !== '' ? 'The image service did not answer: ' . $error : 'The image service did not answer.');
    $data = json_decode($buffer, true);
    if ($status < 200 || $status >= 300) throw new RuntimeException(nibblyAiProviderErrorMessage($data, 'The image service answered with HTTP ' . $status . '.'), $status);
    if (!is_array($data)) throw new RuntimeException('The image service returned invalid data.');
    return $data;
}

/** Whether a URL the provider handed back points at the service itself (same scheme, host and port): only then does the key go with it. */
function nibblyAiImageTrustedUrl(string $url, string $baseUrl): bool {
    $a = parse_url($url);
    $b = parse_url($baseUrl);
    if (!is_array($a) || !is_array($b) || isset($a['user']) || isset($a['pass'])) return false;
    $origin = static fn(array $u): array => [strtolower((string)($u['scheme'] ?? '')), strtolower((string)($u['host'] ?? '')), (int)($u['port'] ?? (($u['scheme'] ?? '') === 'https' ? 443 : 80))];
    return $origin($a) === $origin($b);
}

/** The bytes of an image the provider returned as a data URI or an address. Addresses are fetched safely (public hosts only). */
function nibblyAiImageFetchBinary(array $img, string $source): string {
    if (preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $source)) {
        $binary = base64_decode(substr($source, strpos($source, ',') + 1), true);
        if ($binary === false || $binary === '') throw new RuntimeException('The image service returned invalid image data.');
        return $binary;
    }
    return nibblyAiDownloadProviderImage($source, (string)($img['baseUrl'] ?? ''));
}

// ---------------------------------------------------------------------------
// Live model lists (fal.ai, Comet API)
// ---------------------------------------------------------------------------

const NIBBLY_AI_IMAGE_LIVE_SERVICES = ['fal', 'comet'];
const NIBBLY_AI_IMAGE_LIVE_TTL = 86400;

function nibblyAiImageModelsCachePath(): string {
    return dirname(NIBBLY_AI_SETTINGS_PATH) . '/ai-image-models-cache.json';
}

/**
 * The image models a service lists itself, for the model suggestions. fal.ai: the public model search of its platform API
 * (category text-to-image, active, up to 3 pages); Comet API: the public model catalog, entries of model_type "image".
 * Cached for a day in content/ai-image-models-cache.json; a stale cache is served when the service does not answer.
 * The browser never talks to the provider; fal.ai gets the service's key when one is saved (it only raises the rate limit).
 *
 * @return array{service:string,models:array<int,array{id:string,label:string}>,fetchedAt:int,cached:bool}
 * @throws RuntimeException
 */
function nibblyAiImageLiveModels(array $settings, string $service, bool $refresh = false): array {
    if (!in_array($service, NIBBLY_AI_IMAGE_LIVE_SERVICES, true)) throw new RuntimeException('This image service has no model list.');
    $path = nibblyAiImageModelsCachePath();
    $cache = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $entry = is_array($cache[$service] ?? null) ? $cache[$service] : null;
    $fresh = $entry !== null && (time() - (int)($entry['fetchedAt'] ?? 0)) < NIBBLY_AI_IMAGE_LIVE_TTL && is_array($entry['models'] ?? null);
    if ($fresh && !$refresh) return ['service' => $service, 'models' => $entry['models'], 'fetchedAt' => (int)$entry['fetchedAt'], 'cached' => true];
    try {
        $models = $service === 'fal' ? nibblyAiImageLiveFal($settings) : nibblyAiImageLiveComet();
    } catch (RuntimeException $e) {
        if ($entry !== null && is_array($entry['models'] ?? null)) return ['service' => $service, 'models' => $entry['models'], 'fetchedAt' => (int)($entry['fetchedAt'] ?? 0), 'cached' => true];
        throw $e;
    }
    $cache = is_array($cache) ? $cache : [];
    $cache[$service] = ['fetchedAt' => time(), 'models' => $models];
    nibblyJsonAtomicWrite($path, $cache);
    return ['service' => $service, 'models' => $models, 'fetchedAt' => $cache[$service]['fetchedAt'], 'cached' => false];
}

/** GET a JSON list from a service's public API through the hardened fetch (public hosts, size and type limits). */
function nibblyAiImageLiveGet(string $url, array $headers = []): array {
    $test = nibblyNetTestConfig();
    $host = (string)parse_url($url, PHP_URL_HOST);
    try {
        $response = nibblyNetSafeFetch($url, ($test['options'] ?? []) + [
            'allowedHosts' => [$host], 'allowedContentTypes' => ['application/json'], 'maxBytes' => 3 * 1024 * 1024, 'timeout' => 20,
            'accept' => 'application/json', 'userAgent' => 'nibbly-cms/1.0', 'headers' => $headers,
        ]);
    } catch (NibblyNetException $e) {
        throw new RuntimeException($e->httpStatus === 429 ? 'The service is busy. Try again later.' : 'The model list could not be loaded.');
    }
    $data = json_decode($response['body'], true);
    if (!is_array($data)) throw new RuntimeException('The service returned an invalid model list.');
    return $data;
}

/** Base URL of a model list. Tests redirect it (NIBBLY_AGENT_TEST_NET.imageListBases, CLI and built-in server only). */
function nibblyAiImageLiveBase(string $service): string {
    $test = nibblyNetTestConfig();
    if ($test !== null && isset($test['raw']['imageListBases'][$service])) return rtrim((string)$test['raw']['imageListBases'][$service], '/');
    return $service === 'fal' ? 'https://api.fal.ai' : 'https://api.cometapi.com';
}

function nibblyAiImageLiveFal(array $settings): array {
    $key = trim((string)($settings['imageServices']['fal']['apiKey'] ?? ''));
    if ($key === '') return nibblyAiImageLiveFalPages([]);
    try {
        return nibblyAiImageLiveFalPages(['Authorization: Key ' . $key]);
    } catch (RuntimeException $e) {
        // The key only raises the rate limit: a refused key must not take the list away.
        return nibblyAiImageLiveFalPages([]);
    }
}

function nibblyAiImageLiveFalPages(array $headers): array {
    $models = [];
    $cursor = '';
    for ($page = 0; $page < 3; $page++) {
        $query = ['category' => 'text-to-image', 'status' => 'active', 'limit' => 100] + ($cursor !== '' ? ['cursor' => $cursor] : []);
        $data = nibblyAiImageLiveGet(nibblyAiImageLiveBase('fal') . '/v1/models?' . http_build_query($query), $headers);
        foreach ((array)($data['models'] ?? []) as $model) {
            $id = is_array($model) ? (string)($model['endpoint_id'] ?? '') : '';
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,60}(/[A-Za-z0-9._-]{1,60}){1,3}$#', $id)) continue;
            $name = trim(strip_tags((string)($model['metadata']['display_name'] ?? '')));
            $models[$id] = ['id' => $id, 'label' => 'fal.ai: ' . ($name !== '' ? mb_substr($name, 0, 80, 'UTF-8') : $id)];
        }
        $cursor = !empty($data['has_more']) && is_string($data['next_cursor'] ?? null) ? (string)$data['next_cursor'] : '';
        if ($cursor === '') break;
    }
    return nibblyAiImageLiveSorted($models);
}

function nibblyAiImageLiveComet(): array {
    $data = nibblyAiImageLiveGet(nibblyAiImageLiveBase('comet') . '/api/models');
    $models = [];
    foreach ((array)($data['data'] ?? []) as $model) {
        if (!is_array($model) || strtolower((string)($model['model_type'] ?? '')) !== 'image') continue;
        $id = (string)($model['id'] ?? '');
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,119}$#', $id)) continue;
        $models[$id] = ['id' => $id, 'label' => 'Comet: ' . $id];
    }
    return nibblyAiImageLiveSorted($models);
}

function nibblyAiImageLiveSorted(array $models): array {
    $list = array_values($models);
    usort($list, static fn($a, $b) => strcasecmp($a['id'], $b['id']));
    return array_slice($list, 0, 300);
}

// ---------------------------------------------------------------------------
// Adapters
// ---------------------------------------------------------------------------

/**
 * Run the adapter of the service.
 *
 * @param array $img     the effective settings (nibblyAiImageEffectiveSettings)
 * @param array $service nibblyAiImageServiceResolve()
 * @param array $p       prompt, model, count, size, aspectRatio, imageScale, quality, outputFormat, outputCompression, moderation, referencePaths, filenameHint, storageDir
 * @return array{0:string[],1:string[]} stored image paths and the texts the provider returned alongside
 */
function nibblyAiImageAdapterRun(array $img, array $service, array $p): array {
    return match ($service['adapter']) {
        'openai-images' => nibblyAiImageAdapterOpenAi($img, $service, $p),
        'openrouter-chat' => nibblyAiGenerateOpenRouterImages($img, $p),
        'kie-task' => nibblyAiGenerateKieImages($img, $p),
        'fal-queue' => nibblyAiImageAdapterFal($img, $service, $p),
        'gemini-generate' => nibblyAiImageAdapterGemini($img, $service, $p),
        default => throw new RuntimeException('This image service is not supported.'),
    };
}

function nibblyAiImageStore(string $binary, array $p, int $index): string {
    return nibblyAiStoreGeneratedImage($binary, (string)($p['filenameHint'] ?? 'ai-image'), (string)($p['size'] ?? 'auto'), $index, (string)($p['outputFormat'] ?? ''), (int)($p['outputCompression'] ?? 75), (string)($p['storageDir'] ?? ''));
}

/** OpenAI and compatible services: /images/generations, or /images/edits (multipart) with reference images. */
function nibblyAiImageAdapterOpenAi(array $img, array $service, array $p): array {
    $custom = !$service['inherit'] && $service['id'] === 'custom';
    $model = (string)$p['model'];
    $count = (int)$p['count'];
    $isGptImage = (bool)preg_match('/^(gpt-image|chatgpt-image)/i', $model);
    $body = ['model' => $model, 'prompt' => substr((string)$p['prompt'], 0, 8000), 'n' => $count];
    $ratio = nibblyAiImageRequestedRatio($p);
    if ($custom) {
        if ($service['sizeMode'] === 'size') $body['size'] = nibblyAiImageCustomSize($ratio);
        elseif ($service['sizeMode'] === 'aspect_ratio' && $ratio !== 'auto') $body['aspect_ratio'] = nibblyAiImageNearestAspect($ratio, (array)(nibblyAiImageServiceRegistry()['services']['custom']['defaults']['aspects'] ?? []));
        $body += $service['extraParams'];
    } else {
        $body['size'] = (string)$p['size'];
        if ($isGptImage) {
            $body['output_format'] = (string)$p['outputFormat'];
            $body['quality'] = (string)$p['quality'];
            $body['moderation'] = (string)$p['moderation'];
            if (in_array($p['outputFormat'], ['jpeg', 'webp'], true)) $body['output_compression'] = (int)$p['outputCompression'];
        }
    }
    $references = (array)$p['referencePaths'];
    if ($references) {
        $files = [];
        foreach (array_slice($references, 0, 16) as $index => $path) $files[count($references) > 1 ? 'image[' . $index . ']' : 'image'] = $path;
        $response = nibblyAiProviderMultipartRequest($img, '/images/edits', $body, $files);
    } elseif ($custom) {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $auth = nibblyAiImageAuthHeader($img);
        if ($auth !== '') $headers[] = $auth;
        $response = nibblyAiCompatRequest($img, rtrim($img['baseUrl'], '/') . '/images/generations', $headers, $body);
    } else {
        $response = nibblyAiProviderRequest($img, '/images/generations', $body);
    }
    $items = is_array($response['data'] ?? null) ? $response['data'] : [];
    if (!$items) throw new RuntimeException('AI provider returned no image.');
    $paths = [];
    $revised = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (!empty($item['revised_prompt'])) $revised[] = trim((string)$item['revised_prompt']);
        if (!empty($item['b64_json'])) {
            $binary = base64_decode((string)$item['b64_json'], true);
            if ($binary === false) throw new RuntimeException('AI provider returned invalid image data.');
        } elseif (!empty($item['url'])) {
            $binary = nibblyAiDownloadProviderImage((string)$item['url'], (string)$img['baseUrl']);
        } else {
            continue;
        }
        $paths[] = nibblyAiImageStore($binary, $p, count($paths) + 1);
    }
    if (!$paths) throw new RuntimeException('AI provider returned no usable image data.');
    return [$paths, $revised];
}

/** The input of one fal request. Parameters come from the spec of the model; custom parameters of the settings are added. */
function nibblyAiFalInput(array $spec, array $service, array $p, array $references, int $n): array {
    $input = ['prompt' => nibblyAiUtf8Prefix((string)$p['prompt'], 8000)];
    $ratio = nibblyAiImageRequestedRatio($p);
    $aspects = (array)($spec['aspects'] ?? []);
    if ($ratio !== 'auto' && $aspects) {
        $nearest = nibblyAiImageNearestAspect($ratio, $aspects);
        if (!empty($spec['aspectParam'])) $input[$spec['aspectParam']] = $nearest;
        elseif (!empty($spec['sizeParam']) && !empty($spec['sizePresets'][$nearest])) $input[$spec['sizeParam']] = $spec['sizePresets'][$nearest];
    }
    if (!empty($spec['countParam']) && $n > 1) $input[$spec['countParam']] = $n;
    if (!empty($spec['formatParam']) && !empty($spec['formats'])) {
        $format = (string)$p['outputFormat'];
        $input[$spec['formatParam']] = in_array($format, $spec['formats'], true) ? $format : (in_array('png', $spec['formats'], true) ? 'png' : (string)$spec['formats'][0]);
    }
    if (!empty($spec['resolutionParam']) && !empty($spec['resolutions'])) $input[$spec['resolutionParam']] = nibblyAiImageResolutionFor($p['imageScale'] ?? null, (array)$spec['resolutions']);
    if ($references) {
        $input[(string)($spec['references']['param'] ?? 'image_urls')] = array_map('nibblyAiReferenceImageDataUrl', $references);
    }
    return $input + $service['extraParams'];
}

/** fal.ai queue: submit, poll the status URL, fetch the result URL, download. Tasks are kept in the request ledger so a stopped job resumes. */
function nibblyAiImageAdapterFal(array $img, array $service, array $p): array {
    $spec = $service['spec'];
    $base = rtrim((string)$img['baseUrl'], '/');
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Key ' . $img['apiKey']];
    $getHeaders = ['Accept: application/json', 'Authorization: Key ' . $img['apiKey']];
    $references = array_slice((array)$p['referencePaths'], 0, max(1, (int)($spec['references']['max'] ?? 8)));
    $endpoint = (string)$p['model'];
    if ((array)$p['referencePaths']) {
        if (empty($spec['references']['editModel'])) throw new RuntimeException('This image model does not take reference images. Choose a model with an edit endpoint or remove the reference images.');
        $endpoint = (string)$spec['references']['editModel'];
    }
    $requestId = $GLOBALS['nibblyAiRequestId'] ?? '';
    $entry = nibblyAiLoadUsage()['reservations'][$requestId] ?? [];
    if ($entry && (($entry['provider'] ?? '') !== ($img['provider'] ?? '') || ($entry['baseUrl'] ?? '') !== ($img['baseUrl'] ?? ''))) {
        throw new RuntimeException('Restore the original AI provider settings to resume this request.');
    }
    if (!empty($entry['outputs']) && !array_filter($entry['outputs'], fn($path) => !is_file(dirname(__DIR__, 2) . $path))) return [$entry['outputs'], []];
    $perTask = max(1, (int)($spec['maxCount'] ?? 1));
    $batches = [];
    for ($left = (int)$p['count']; $left > 0; $left -= $perTask) $batches[] = min($perTask, $left);
    $saved = $entry['tasks'] ?? [];
    $tasks = [];
    foreach ($batches as $index => $n) {
        if (isset($saved[$index])) {
            if (empty($saved[$index]['id'])) throw new RuntimeException('Provider submission is uncertain. Check the provider before starting another request.');
            if (!in_array($saved[$index]['state'] ?? '', ['success', 'fail'], true)) $tasks[$index] = true;
            continue;
        }
        $saved[$index] = ['state' => 'submitting', 'model' => $endpoint];
        if ($requestId !== '') nibblyAiRequestPatch($requestId, ['tasks' => $saved]);
        $response = nibblyAiExecuteJsonRequest($img, $base . '/' . $endpoint, $headers, nibblyAiFalInput($spec, $service, $p, $references, $n));
        $id = trim((string)($response['request_id'] ?? ''));
        if ($id === '') throw new RuntimeException('The image service did not return a request ID.');
        $statusUrl = (string)($response['status_url'] ?? '');
        $resultUrl = (string)($response['response_url'] ?? '');
        $root = preg_replace('#^(fal-ai/[^/]+|[^/]+/[^/]+).*$#', '$1', $endpoint);
        // Only addresses of the service itself are followed (the key goes with them); otherwise use the documented pattern.
        if (!nibblyAiImageTrustedUrl($statusUrl, $base)) $statusUrl = $base . '/' . $root . '/requests/' . rawurlencode($id) . '/status';
        if (!nibblyAiImageTrustedUrl($resultUrl, $base)) $resultUrl = $base . '/' . $root . '/requests/' . rawurlencode($id);
        $saved[$index] = ['id' => $id, 'state' => 'waiting', 'model' => $endpoint, 'statusUrl' => $statusUrl, 'responseUrl' => $resultUrl];
        if ($requestId !== '') nibblyAiRequestPatch($requestId, ['tasks' => $saved]);
        $tasks[$index] = true;
    }
    $deadline = microtime(true) + nibblyAiRequestTimeout($img);
    $urls = [];
    $failures = [];
    foreach ($saved as $index => $task) {
        if (($task['state'] ?? '') === 'success' && !empty($task['urls'])) array_push($urls, ...$task['urls']);
    }
    while ($tasks && microtime(true) < $deadline) {
        foreach (array_keys($tasks) as $index) {
            $task = $saved[$index];
            try {
                $status = nibblyAiImageGetJson($img, (string)$task['statusUrl'], $getHeaders);
                $state = strtoupper((string)($status['status'] ?? ''));
                if ($state === 'COMPLETED' && empty($status['error'])) {
                    $result = nibblyAiImageGetJson($img, (string)$task['responseUrl'], $getHeaders);
                    $found = [];
                    foreach ((array)($result['images'] ?? (isset($result['image']) ? [$result['image']] : [])) as $image) {
                        $url = is_array($image) ? (string)($image['url'] ?? '') : (string)$image;
                        if (preg_match('#^(https?://|data:image/)#i', $url)) $found[] = $url;
                    }
                    if (!$found) throw new RuntimeException('The image service returned no image.', 422);
                    $saved[$index]['state'] = 'success';
                    $saved[$index]['urls'] = $found;
                    array_push($urls, ...$found);
                    unset($tasks[$index]);
                } elseif ($state === 'COMPLETED') {
                    throw new RuntimeException(nibblyAiProviderErrorMessage($status, 'The image service reported an error.'), 422);
                } else {
                    $saved[$index]['state'] = $state === 'IN_PROGRESS' ? 'running' : 'waiting';
                }
            } catch (RuntimeException $e) {
                // A failed request is final; a hiccup of the connection is tried again until the deadline.
                $code = (int)$e->getCode();
                if ($code >= 400 && $code < 500 && !in_array($code, [408, 429], true)) {
                    $saved[$index]['state'] = 'fail';
                    $failures[] = $e->getMessage();
                    unset($tasks[$index]);
                }
            }
            if ($requestId !== '') nibblyAiRequestPatch($requestId, ['tasks' => $saved, 'updatedAt' => gmdate('c')]);
        }
        if ($tasks) usleep(nibblyAiImagePollMicros());
    }
    if ($tasks) throw new RuntimeException('The image service did not finish in time. The provider jobs may still be running.');
    if (!$urls) throw new RuntimeException($failures[0] ?? 'The image service returned no usable image.');
    $paths = [];
    foreach (array_slice($urls, 0, (int)$p['count']) as $url) $paths[] = nibblyAiImageStore(nibblyAiImageFetchBinary($img, $url), $p, count($paths) + 1);
    if ($requestId !== '') {
        // The signed result addresses are of no use once the images are stored: do not keep them in the ledger.
        foreach ($saved as $index => $task) unset($saved[$index]['urls']);
        nibblyAiRequestPatch($requestId, ['outputs' => $paths, 'tasks' => $saved]);
    }
    return [$paths, []];
}

/** The body of one Gemini generateContent request (Comet API). */
function nibblyAiGeminiBody(array $spec, array $service, array $p, array $references): array {
    $parts = [['text' => nibblyAiUtf8Prefix((string)$p['prompt'], 8000)]];
    foreach (array_slice($references, 0, max(1, (int)($spec['references']['max'] ?? 8))) as $path) {
        $dataUrl = nibblyAiReferenceImageDataUrl((string)$path);
        [$meta, $data] = explode(',', $dataUrl, 2);
        $parts[] = ['inline_data' => ['mime_type' => substr($meta, 5, strpos($meta, ';') - 5), 'data' => $data]];
    }
    $image = [];
    $ratio = nibblyAiImageRequestedRatio($p);
    if ($ratio !== 'auto' && !empty($spec['aspectParam'])) $image[$spec['aspectParam']] = nibblyAiImageNearestAspect($ratio, (array)($spec['aspects'] ?? []));
    if (!empty($spec['resolutionParam']) && !empty($spec['resolutions'])) $image[$spec['resolutionParam']] = nibblyAiImageResolutionFor($p['imageScale'] ?? null, (array)$spec['resolutions']);
    $config = ['responseModalities' => ['TEXT', 'IMAGE']];
    if ($image) $config['imageConfig'] = $image;
    return ['contents' => [['role' => 'user', 'parts' => $parts]], 'generationConfig' => $config] + $service['extraParams'];
}

/** The image and texts of a Gemini response. Thought parts are skipped; the last remaining image part is the result. */
function nibblyAiGeminiExtract(array $response): array {
    $binary = null;
    $texts = [];
    foreach ((array)($response['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (!is_array($part) || !empty($part['thought'])) continue;
        $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
        if (is_array($inline) && !empty($inline['data'])) {
            $decoded = base64_decode((string)$inline['data'], true);
            if ($decoded === false || $decoded === '') throw new RuntimeException('The image service returned invalid image data.');
            $binary = $decoded;
        } elseif (is_string($part['text'] ?? null) && trim($part['text']) !== '') {
            $texts[] = trim($part['text']);
        }
    }
    return [$binary, $texts];
}

/** Gemini generateContent (Comet API): one request per image. A later failure after the first image ends the run with what exists. */
function nibblyAiImageAdapterGemini(array $img, array $service, array $p): array {
    $spec = $service['spec'];
    $url = rtrim((string)$img['baseUrl'], '/') . '/v1beta/models/' . rawurlencode((string)$p['model']) . ':generateContent';
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $img['apiKey']];
    $references = (array)$p['referencePaths'];
    if ($references && empty($spec['references'])) throw new RuntimeException('This image model does not take reference images.');
    $body = nibblyAiGeminiBody($spec, $service, $p, $references);
    $paths = [];
    $texts = [];
    $img['limits']['requestTimeoutSeconds'] = max(300, nibblyAiRequestTimeout($img));
    for ($i = 0; $i < (int)$p['count']; $i++) {
        try {
            $response = nibblyAiExecuteJsonRequest($img, $url, $headers, $body);
            [$binary, $found] = nibblyAiGeminiExtract($response);
            if ($binary === null) throw new RuntimeException($found ? 'The image service answered with text only: ' . nibblyAiUtf8Prefix($found[0], 200) : 'The image service returned no image.');
            $paths[] = nibblyAiImageStore($binary, $p, count($paths) + 1);
            $texts = array_merge($texts, $found);
        } catch (Throwable $e) {
            if (!$paths) throw $e;
            nibblyAiAudit('image-partial', false, ['requested' => (int)$p['count'], 'created' => count($paths), 'message' => $e->getMessage()]);
            break;
        }
    }
    return [$paths, $texts];
}
