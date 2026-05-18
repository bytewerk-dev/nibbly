<?php
/**
 * Nibbly AI gateway.
 *
 * Centralizes provider configuration, usage limits, audit logging, and media
 * storage so CMS features do not talk to AI vendors directly.
 */

if (!defined('NIBBLY_AI_SETTINGS_PATH')) {
    define('NIBBLY_AI_SETTINGS_PATH', dirname(__DIR__, 2) . '/content/ai-settings.json');
}
if (!defined('NIBBLY_AI_USAGE_PATH')) {
    define('NIBBLY_AI_USAGE_PATH', dirname(__DIR__, 2) . '/content/ai-usage.json');
}
if (!defined('NIBBLY_AI_AUDIT_DIR')) {
    define('NIBBLY_AI_AUDIT_DIR', dirname(__DIR__, 2) . '/content/ai-audit');
}
if (!defined('NIBBLY_AI_IMAGE_HISTORY_PATH')) {
    define('NIBBLY_AI_IMAGE_HISTORY_PATH', dirname(__DIR__, 2) . '/content/ai-image-history.json');
}
if (!defined('NIBBLY_AI_GENERATED_IMAGE_DIR')) {
    define('NIBBLY_AI_GENERATED_IMAGE_DIR', dirname(__DIR__, 2) . '/assets/images/generated');
}

function nibblyAiDefaults(): array {
    return [
        'enabled' => false,
        'provider' => 'openai-compatible',
        'baseUrl' => 'https://api.openai.com/v1',
        'apiKey' => '',
        'providerCredentials' => [
            'openai-compatible' => [
                'baseUrl' => 'https://api.openai.com/v1',
                'apiKey' => '',
                'organization' => ''
            ],
            'openrouter' => [
                'baseUrl' => 'https://openrouter.ai/api/v1',
                'apiKey' => '',
                'organization' => ''
            ]
        ],
        'chatModel' => 'gpt-4.1-mini',
        'textModel' => '',
        'imageModel' => 'gpt-image-2',
        'organization' => '',
        'allowLocalProvider' => false,
        'features' => [
            'backendAssistant' => true,
            'seoTextGeneration' => true,
            'imageGeneration' => false
        ],
        'limits' => [
            'monthlyBudgetCents' => 1000,
            'dailyRequests' => 100,
            'dailyTextRequests' => 80,
            'dailyImageRequests' => 10,
            'maxInputTokens' => 6000,
            'maxOutputTokens' => 1200,
            'requestTimeoutSeconds' => 120
        ],
        'pricing' => [
            'inputCentsPerMillion' => 15,
            'outputCentsPerMillion' => 60,
            'imageCentsPerRequest' => 5
        ],
        'systemPrompts' => [
            'assistant' => 'You are the Nibbly CMS assistant. Answer clearly and practically. If a task needs admin access, explain where to do it in the Nibbly dashboard. Do not invent settings that do not exist.',
            'seo' => 'You write concise, search-friendly website copy. Return only the requested text unless a format is explicitly requested.'
        ]
    ];
}

function nibblyAiNormalizeProviderCredentials(array $settings, array $defaults = []): array {
    $defaults = $defaults ?: nibblyAiDefaults();
    $credentials = $defaults['providerCredentials'];
    if (is_array($settings['providerCredentials'] ?? null)) {
        $credentials = array_replace_recursive($credentials, $settings['providerCredentials']);
    }

    $activeProvider = (string)($settings['provider'] ?? $defaults['provider']);
    if (!isset($credentials[$activeProvider])) {
        $activeProvider = $defaults['provider'];
    }
    if (!empty($settings['apiKey']) && empty($credentials[$activeProvider]['apiKey'])) {
        $credentials[$activeProvider]['apiKey'] = (string)$settings['apiKey'];
    }
    if (!empty($settings['baseUrl'])) {
        $credentials[$activeProvider]['baseUrl'] = rtrim((string)$settings['baseUrl'], '/');
    }
    if (!empty($settings['organization']) && empty($credentials[$activeProvider]['organization'])) {
        $credentials[$activeProvider]['organization'] = (string)$settings['organization'];
    }

    foreach ($credentials as $provider => $providerCredentials) {
        $credentials[$provider] = [
            'baseUrl' => rtrim((string)($providerCredentials['baseUrl'] ?? $defaults['providerCredentials'][$provider]['baseUrl'] ?? ''), '/'),
            'apiKey' => (string)($providerCredentials['apiKey'] ?? ''),
            'organization' => (string)($providerCredentials['organization'] ?? '')
        ];
    }

    return $credentials;
}

function nibblyAiResolveProviderSettings(array $settings): array {
    $defaults = nibblyAiDefaults();
    $provider = (string)($settings['provider'] ?? $defaults['provider']);
    if (!isset($defaults['providerCredentials'][$provider])) {
        $provider = $defaults['provider'];
        $settings['provider'] = $provider;
    }

    $settings['providerCredentials'] = nibblyAiNormalizeProviderCredentials($settings, $defaults);
    $credentials = $settings['providerCredentials'][$provider] ?? $defaults['providerCredentials'][$provider];
    $settings['baseUrl'] = rtrim((string)($credentials['baseUrl'] ?? $defaults['providerCredentials'][$provider]['baseUrl']), '/');
    $settings['apiKey'] = (string)($credentials['apiKey'] ?? '');
    $settings['organization'] = (string)($credentials['organization'] ?? '');

    return $settings;
}

function nibblyAiLoadSettings(bool $public = false): array {
    $settings = nibblyAiDefaults();
    if (is_file(NIBBLY_AI_SETTINGS_PATH)) {
        $loaded = json_decode((string)file_get_contents(NIBBLY_AI_SETTINGS_PATH), true);
        if (is_array($loaded)) {
            $settings = array_replace_recursive($settings, $loaded);
        }
    }

    $settings = nibblyAiResolveProviderSettings($settings);
    if ($settings['textModel'] === '') {
        $settings['textModel'] = $settings['chatModel'];
    }

    if ($public) {
        $settings['hasApiKey'] = trim((string)($settings['apiKey'] ?? '')) !== '';
        foreach ($settings['providerCredentials'] as $provider => $credentials) {
            $settings['providerCredentials'][$provider] = [
                'baseUrl' => (string)($credentials['baseUrl'] ?? ''),
                'organization' => (string)($credentials['organization'] ?? ''),
                'hasApiKey' => trim((string)($credentials['apiKey'] ?? '')) !== ''
            ];
        }
        unset($settings['apiKey']);
    }

    return $settings;
}

function nibblyAiSaveSettings(array $input, array $existing = []): array {
    $defaults = nibblyAiDefaults();
    $existing = $existing ?: nibblyAiLoadSettings(false);

    $provider = (string)($input['provider'] ?? $existing['provider'] ?? $defaults['provider']);
    if (!in_array($provider, ['openai-compatible', 'openrouter'], true)) {
        throw new RuntimeException('Unsupported AI provider.');
    }

    $providerCredentials = nibblyAiNormalizeProviderCredentials($existing, $defaults);
    $currentProviderCredentials = $providerCredentials[$provider] ?? $defaults['providerCredentials'][$provider];

    $baseUrl = rtrim(trim((string)($input['baseUrl'] ?? $currentProviderCredentials['baseUrl'] ?? $defaults['providerCredentials'][$provider]['baseUrl'])), '/');
    if ($baseUrl !== '' && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Invalid AI base URL.');
    }
    $allowLocal = !empty($input['allowLocalProvider']);
    if ($baseUrl !== '') {
        nibblyAiValidateBaseUrl($baseUrl, $allowLocal);
    }

    $apiKey = trim((string)($input['apiKey'] ?? ''));
    if ($apiKey === '' && !empty($currentProviderCredentials['apiKey'])) {
        $apiKey = (string)$currentProviderCredentials['apiKey'];
    }
    if (!empty($input['clearApiKey'])) {
        $apiKey = '';
    }
    $organization = substr(trim((string)($input['organization'] ?? $currentProviderCredentials['organization'] ?? '')), 0, 120);
    $providerCredentials[$provider] = [
        'baseUrl' => $baseUrl,
        'apiKey' => $apiKey,
        'organization' => $organization
    ];

    $imageModel = array_key_exists('imageModel', $input)
        ? nibblyAiCleanModelAllowEmpty((string)$input['imageModel'])
        : nibblyAiCleanModel((string)$defaults['imageModel']);
    if ($provider === 'openrouter') {
        $imageModel = nibblyAiNormalizeOpenRouterImageModel($imageModel);
    }

    $settings = [
        'enabled' => !empty($input['enabled']),
        'provider' => $provider,
        'baseUrl' => $baseUrl,
        'apiKey' => $apiKey,
        'providerCredentials' => $providerCredentials,
        'chatModel' => nibblyAiCleanModel((string)($input['chatModel'] ?? $defaults['chatModel'])),
        'textModel' => nibblyAiCleanModel((string)($input['textModel'] ?? $input['chatModel'] ?? $defaults['chatModel'])),
        'imageModel' => $imageModel,
        'organization' => $organization,
        'allowLocalProvider' => $allowLocal,
        'features' => [
            'backendAssistant' => !empty($input['features']['backendAssistant']),
            'seoTextGeneration' => !empty($input['features']['seoTextGeneration']),
            'imageGeneration' => !empty($input['features']['imageGeneration'])
        ],
        'limits' => [
            'monthlyBudgetCents' => nibblyAiClampInt($input['limits']['monthlyBudgetCents'] ?? 1000, 0, 1000000),
            'dailyRequests' => nibblyAiClampInt($input['limits']['dailyRequests'] ?? 100, 0, 10000),
            'dailyTextRequests' => nibblyAiClampInt($input['limits']['dailyTextRequests'] ?? 80, 0, 10000),
            'dailyImageRequests' => nibblyAiClampInt($input['limits']['dailyImageRequests'] ?? 10, 0, 1000),
            'maxInputTokens' => nibblyAiClampInt($input['limits']['maxInputTokens'] ?? 6000, 100, 200000),
            'maxOutputTokens' => nibblyAiClampInt($input['limits']['maxOutputTokens'] ?? 1200, 16, 32000),
            'requestTimeoutSeconds' => nibblyAiClampInt($input['limits']['requestTimeoutSeconds'] ?? 120, 5, 600)
        ],
        'pricing' => [
            'inputCentsPerMillion' => nibblyAiClampInt($input['pricing']['inputCentsPerMillion'] ?? 15, 0, 100000),
            'outputCentsPerMillion' => nibblyAiClampInt($input['pricing']['outputCentsPerMillion'] ?? 60, 0, 100000),
            'imageCentsPerRequest' => nibblyAiClampInt($input['pricing']['imageCentsPerRequest'] ?? 5, 0, 100000)
        ],
        'systemPrompts' => [
            'assistant' => substr(trim((string)($input['systemPrompts']['assistant'] ?? $defaults['systemPrompts']['assistant'])), 0, 4000),
            'seo' => substr(trim((string)($input['systemPrompts']['seo'] ?? $defaults['systemPrompts']['seo'])), 0, 4000)
        ]
    ];

    $dir = dirname(NIBBLY_AI_SETTINGS_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $ok = file_put_contents(NIBBLY_AI_SETTINGS_PATH, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($ok === false) {
        throw new RuntimeException('Could not save AI settings.');
    }

    return nibblyAiLoadSettings(true);
}

function nibblyAiUsageSummary(): array {
    $usage = nibblyAiLoadUsage();
    $today = gmdate('Y-m-d');
    $month = gmdate('Y-m');
    return [
        'today' => $usage['days'][$today] ?? nibblyAiEmptyUsageBucket(),
        'month' => $usage['months'][$month] ?? nibblyAiEmptyUsageBucket(),
        'updatedAt' => $usage['updatedAt'] ?? null
    ];
}

function nibblyAiChat(array $messages, array $options = []): array {
    $settings = nibblyAiLoadSettings(false);
    nibblyAiEnsureEnabled($settings);
    $feature = $options['feature'] ?? 'backendAssistant';
    if ($feature !== '') {
        nibblyAiEnsureFeature($settings, $feature);
    }

    $cleanMessages = nibblyAiCleanMessages($messages);
    if (!$cleanMessages) {
        throw new RuntimeException('No AI messages provided.');
    }

    $inputTokens = nibblyAiEstimateTokens(json_encode($cleanMessages, JSON_UNESCAPED_UNICODE));
    $maxInput = (int)$settings['limits']['maxInputTokens'];
    if ($inputTokens > $maxInput) {
        throw new RuntimeException('AI input is too long for the configured limit.');
    }

    $maxOutput = nibblyAiClampInt($options['maxOutputTokens'] ?? $settings['limits']['maxOutputTokens'], 16, (int)$settings['limits']['maxOutputTokens']);
    $estimatedCost = nibblyAiEstimateTextCost($settings, $inputTokens, $maxOutput);
    nibblyAiAssertWithinLimits($settings, 'text', $estimatedCost);

    $body = [
        'model' => (string)($options['model'] ?? $settings['chatModel']),
        'messages' => $cleanMessages,
        'max_tokens' => $maxOutput,
        'temperature' => isset($options['temperature']) ? (float)$options['temperature'] : 0.3,
        'stream' => false
    ];

    $started = microtime(true);
    $response = nibblyAiProviderRequest($settings, '/chat/completions', $body);
    $choice = $response['choices'][0] ?? [];
    $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
    $text = trim((string)($message['content'] ?? ''));
    if ($text === '') {
        if (!empty($message['reasoning']) || (($choice['finish_reason'] ?? '') === 'length')) {
            throw new RuntimeException('AI provider did not return a final answer before the output limit. Increase the max output tokens or use a non-reasoning model for this feature.');
        }
        throw new RuntimeException('AI provider returned an empty response.');
    }

    $actualInput = (int)($response['usage']['prompt_tokens'] ?? $inputTokens);
    $actualOutput = (int)($response['usage']['completion_tokens'] ?? nibblyAiEstimateTokens($text));
    $actualCost = nibblyAiEstimateTextCost($settings, $actualInput, $actualOutput);
    nibblyAiRecordUsage('text', $actualInput, $actualOutput, $actualCost);
    nibblyAiAudit('chat', true, [
        'model' => $body['model'],
        'inputTokens' => $actualInput,
        'outputTokens' => $actualOutput,
        'estimatedCostCents' => $actualCost,
        'durationMs' => (int)round((microtime(true) - $started) * 1000)
    ]);

    return [
        'text' => $text,
        'usage' => [
            'inputTokens' => $actualInput,
            'outputTokens' => $actualOutput,
            'estimatedCostCents' => $actualCost
        ],
        'limits' => nibblyAiUsageSummary()
    ];
}

function nibblyAiGenerateText(string $prompt, array $options = []): array {
    $settings = nibblyAiLoadSettings(false);
    $feature = $options['feature'] ?? 'seoTextGeneration';
    $system = (string)($options['system'] ?? $settings['systemPrompts']['seo']);
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $prompt]
    ];
    return nibblyAiChat($messages, [
        'feature' => $feature,
        'model' => $settings['textModel'] ?: $settings['chatModel'],
        'maxOutputTokens' => $options['maxOutputTokens'] ?? null,
        'temperature' => $options['temperature'] ?? 0.4
    ]);
}

function nibblyAiGenerateImage(string $prompt, array $options = []): array {
    $settings = nibblyAiLoadSettings(false);
    nibblyAiEnsureEnabled($settings);
    nibblyAiEnsureFeature($settings, 'imageGeneration');

    $prompt = trim($prompt);
    if ($prompt === '') {
        throw new RuntimeException('Image prompt is required.');
    }
    if (nibblyAiEstimateTokens($prompt) > (int)$settings['limits']['maxInputTokens']) {
        throw new RuntimeException('Image prompt is too long for the configured limit.');
    }

    $count = nibblyAiClampInt($options['count'] ?? 1, 1, 10);
    $cost = (int)$settings['pricing']['imageCentsPerRequest'] * $count;
    nibblyAiAssertWithinLimits($settings, 'image', $cost);

    $usesOpenRouter = nibblyAiIsOpenRouterSettings($settings);
    $model = (string)($options['model'] ?? $settings['imageModel']);
    if ($usesOpenRouter) {
        $model = nibblyAiNormalizeOpenRouterImageModel($model);
    }
    if (trim($model) === '') {
        throw new RuntimeException('Image model is missing.');
    }
    $isGptImageModel = (bool)preg_match('/^(gpt-image|chatgpt-image)/i', $model);
    $isGptImage2Model = (bool)preg_match('/^gpt-image-2/i', $model);
    $size = (string)($options['size'] ?? 'auto');
    $allowedSizes = ($isGptImageModel && !$isGptImage2Model)
        ? ['auto', '1024x1024', '1024x1536', '1536x1024']
        : ['1024x1024', '1024x1536', '1536x1024', '512x512', '256x256', '1792x1024', '1024x1792'];
    if ($usesOpenRouter && !nibblyAiIsValidGptImage2Size($size)) {
        $size = 'auto';
    } elseif ($isGptImage2Model && !nibblyAiIsValidGptImage2Size($size)) {
        $size = 'auto';
    } elseif (!$isGptImage2Model && !in_array($size, $allowedSizes, true)) {
        $size = $isGptImageModel ? 'auto' : '1024x1024';
    }

    $outputFormat = strtolower((string)($options['outputFormat'] ?? 'webp'));
    if (!in_array($outputFormat, ['png', 'jpeg', 'webp'], true)) {
        $outputFormat = 'webp';
    }
    $quality = strtolower((string)($options['quality'] ?? 'auto'));
    if (!in_array($quality, ['auto', 'low', 'medium', 'high'], true)) {
        $quality = 'auto';
    }
    $moderation = strtolower((string)($options['moderation'] ?? 'auto'));
    if (!in_array($moderation, ['auto', 'low'], true)) {
        $moderation = 'auto';
    }
    $outputCompression = max(0, min(100, (int)($options['outputCompression'] ?? 100)));
    $aspectRatio = nibblyAiCleanAspectRatio((string)($options['aspectRatio'] ?? 'auto'));
    $started = microtime(true);
    $referencePaths = nibblyAiCollectReferenceImagePaths($options);

    if ($usesOpenRouter) {
        [$paths, $revisedPrompts] = nibblyAiGenerateOpenRouterImages($settings, [
            'model' => $model,
            'prompt' => $prompt,
            'count' => $count,
            'size' => $size,
            'aspectRatio' => $aspectRatio,
            'imageScale' => $options['imageScale'] ?? null,
            'quality' => $quality,
            'referencePaths' => $referencePaths,
            'filenameHint' => $options['filenameHint'] ?? 'ai-image'
        ]);
        nibblyAiRecordUsage('image', nibblyAiEstimateTokens($prompt), 0, $cost);
        nibblyAiAudit('image', true, [
            'provider' => 'openrouter',
            'model' => $model,
            'count' => count($paths),
            'estimatedCostCents' => $cost,
            'paths' => $paths,
            'durationMs' => (int)round((microtime(true) - $started) * 1000)
        ]);
        $historyItem = nibblyAiRecordImageHistory([
            'status' => 'success',
            'model' => $model,
            'prompt' => $prompt,
            'revisedPrompt' => implode("\n\n", array_values(array_unique(array_filter($revisedPrompts)))),
            'size' => $size,
            'aspectRatio' => $aspectRatio,
            'quality' => $quality,
            'format' => $outputFormat,
            'moderation' => $moderation,
            'compression' => $outputCompression,
            'count' => count($paths),
            'referenceImages' => nibblyAiPublicReferenceList($options),
            'outputs' => $paths,
            'error' => '',
            'estimatedCostCents' => $cost,
            'durationMs' => (int)round((microtime(true) - $started) * 1000)
        ]);

        return [
            'path' => $paths[0],
            'paths' => $paths,
            'historyItem' => $historyItem,
            'usage' => ['estimatedCostCents' => $cost],
            'limits' => nibblyAiUsageSummary()
        ];
    }

    $body = [
        'model' => $model,
        'prompt' => substr($prompt, 0, 8000),
        'n' => $count,
        'size' => $size
    ];
    if ($isGptImageModel) {
        $body['output_format'] = $outputFormat;
        $body['quality'] = $quality;
        $body['moderation'] = $moderation;
        if (in_array($outputFormat, ['jpeg', 'webp'], true)) {
            $body['output_compression'] = $outputCompression;
        }
    }

    if ($referencePaths) {
        $files = [];
        foreach (array_slice($referencePaths, 0, 16) as $index => $referencePath) {
            $files[count($referencePaths) > 1 ? 'image[' . $index . ']' : 'image'] = $referencePath;
        }
        $response = nibblyAiProviderMultipartRequest($settings, '/images/edits', $body, $files);
    } else {
        $response = nibblyAiProviderRequest($settings, '/images/generations', $body);
    }
    $items = is_array($response['data'] ?? null) ? $response['data'] : [];
    if (!$items) {
        throw new RuntimeException('AI provider returned no image.');
    }

    $paths = [];
    $revisedPrompts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (!empty($item['revised_prompt'])) {
            $revisedPrompts[] = trim((string)$item['revised_prompt']);
        }
        if (!empty($item['b64_json'])) {
            $binary = base64_decode((string)$item['b64_json'], true);
            if ($binary === false) {
                throw new RuntimeException('AI provider returned invalid image data.');
            }
        } elseif (!empty($item['url'])) {
            $binary = nibblyAiDownloadProviderImage((string)$item['url']);
        } else {
            continue;
        }
        $paths[] = nibblyAiStoreGeneratedImage($binary, $options['filenameHint'] ?? 'ai-image', $size, count($paths) + 1);
    }
    if (!$paths) {
        throw new RuntimeException('AI provider returned no usable image data.');
    }

    nibblyAiRecordUsage('image', nibblyAiEstimateTokens($prompt), 0, $cost);
    nibblyAiAudit('image', true, [
        'model' => $body['model'],
        'count' => count($paths),
        'estimatedCostCents' => $cost,
        'paths' => $paths,
        'durationMs' => (int)round((microtime(true) - $started) * 1000)
    ]);
    $historyItem = nibblyAiRecordImageHistory([
        'status' => 'success',
        'model' => $body['model'],
        'prompt' => $prompt,
        'revisedPrompt' => implode("\n\n", array_values(array_unique(array_filter($revisedPrompts)))),
        'size' => $size,
        'aspectRatio' => $aspectRatio,
        'quality' => $quality,
        'format' => $outputFormat,
        'moderation' => $moderation,
        'compression' => $outputCompression,
        'count' => count($paths),
        'referenceImages' => nibblyAiPublicReferenceList($options),
        'outputs' => $paths,
        'error' => '',
        'estimatedCostCents' => $cost,
        'durationMs' => (int)round((microtime(true) - $started) * 1000)
    ]);

    return [
        'path' => $paths[0],
        'paths' => $paths,
        'historyItem' => $historyItem,
        'usage' => ['estimatedCostCents' => $cost],
        'limits' => nibblyAiUsageSummary()
    ];
}

function nibblyAiEnsureEnabled(array $settings): void {
    if (empty($settings['enabled'])) {
        throw new RuntimeException('AI features are disabled.');
    }
    if (trim((string)($settings['apiKey'] ?? '')) === '' && !nibblyAiIsLocalBaseUrl((string)$settings['baseUrl'])) {
        throw new RuntimeException('AI API key is missing.');
    }
}

function nibblyAiEnsureFeature(array $settings, string $feature): void {
    if (empty($settings['features'][$feature])) {
        throw new RuntimeException('This AI feature is disabled.');
    }
}

function nibblyAiAssertWithinLimits(array $settings, string $type, int $estimatedCostCents): void {
    $usage = nibblyAiLoadUsage();
    $today = gmdate('Y-m-d');
    $month = gmdate('Y-m');
    $dayBucket = $usage['days'][$today] ?? nibblyAiEmptyUsageBucket();
    $monthBucket = $usage['months'][$month] ?? nibblyAiEmptyUsageBucket();
    $limits = $settings['limits'];

    if ((int)$limits['dailyRequests'] > 0 && ($dayBucket['requests'] + 1) > (int)$limits['dailyRequests']) {
        throw new RuntimeException('Daily AI request limit reached.');
    }
    if ($type === 'text' && (int)$limits['dailyTextRequests'] > 0 && ($dayBucket['textRequests'] + 1) > (int)$limits['dailyTextRequests']) {
        throw new RuntimeException('Daily AI text request limit reached.');
    }
    if ($type === 'image' && (int)$limits['dailyImageRequests'] > 0 && ($dayBucket['imageRequests'] + 1) > (int)$limits['dailyImageRequests']) {
        throw new RuntimeException('Daily AI image request limit reached.');
    }
    if ((int)$limits['monthlyBudgetCents'] > 0 && ($monthBucket['estimatedCostCents'] + $estimatedCostCents) > (int)$limits['monthlyBudgetCents']) {
        throw new RuntimeException('Monthly AI budget limit reached.');
    }
}

function nibblyAiRecordUsage(string $type, int $inputTokens, int $outputTokens, int $costCents): void {
    $usage = nibblyAiLoadUsage();
    $today = gmdate('Y-m-d');
    $month = gmdate('Y-m');
    foreach ([['days', $today], ['months', $month]] as $ref) {
        [$group, $key] = $ref;
        if (!isset($usage[$group][$key])) {
            $usage[$group][$key] = nibblyAiEmptyUsageBucket();
        }
        $usage[$group][$key]['requests']++;
        $usage[$group][$key][$type === 'image' ? 'imageRequests' : 'textRequests']++;
        $usage[$group][$key]['inputTokens'] += $inputTokens;
        $usage[$group][$key]['outputTokens'] += $outputTokens;
        $usage[$group][$key]['estimatedCostCents'] += $costCents;
    }
    $usage['updatedAt'] = gmdate('c');
    nibblyAiPruneUsage($usage);
    nibblyAiSaveUsage($usage);
}

function nibblyAiAudit(string $action, bool $success, array $meta = []): void {
    if (!is_dir(NIBBLY_AI_AUDIT_DIR)) {
        mkdir(NIBBLY_AI_AUDIT_DIR, 0755, true);
    }
    $file = NIBBLY_AI_AUDIT_DIR . '/' . gmdate('Y-m-d') . '.jsonl';
    $entry = [
        'time' => gmdate('c'),
        'action' => $action,
        'success' => $success,
        'user' => $_SESSION['admin_username'] ?? ($_SESSION['admin_user_id'] ?? ''),
        'meta' => $meta
    ];
    file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function nibblyAiLoadImageHistory(int $offset = 0, int $limit = 12): array {
    $history = ['items' => []];
    if (is_file(NIBBLY_AI_IMAGE_HISTORY_PATH)) {
        $loaded = json_decode((string)file_get_contents(NIBBLY_AI_IMAGE_HISTORY_PATH), true);
        if (is_array($loaded) && is_array($loaded['items'] ?? null)) {
            $history = ['items' => $loaded['items']];
        }
    }
    $offset = max(0, $offset);
    $limit = nibblyAiClampInt($limit, 1, 48);
    $items = array_slice($history['items'], $offset, $limit);
    return [
        'items' => $items,
        'offset' => $offset,
        'limit' => $limit,
        'total' => count($history['items']),
        'hasMore' => ($offset + count($items)) < count($history['items'])
    ];
}

function nibblyAiRecordImageHistory(array $entry): array {
    $history = ['items' => []];
    if (is_file(NIBBLY_AI_IMAGE_HISTORY_PATH)) {
        $loaded = json_decode((string)file_get_contents(NIBBLY_AI_IMAGE_HISTORY_PATH), true);
        if (is_array($loaded) && is_array($loaded['items'] ?? null)) {
            $history = ['items' => $loaded['items']];
        }
    }
    $item = [
        'id' => 'img_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8),
        'createdAt' => gmdate('c'),
        'status' => in_array(($entry['status'] ?? ''), ['success', 'error'], true) ? $entry['status'] : 'success',
        'model' => substr((string)($entry['model'] ?? ''), 0, 120),
        'prompt' => substr((string)($entry['prompt'] ?? ''), 0, 8000),
        'revisedPrompt' => substr((string)($entry['revisedPrompt'] ?? ''), 0, 8000),
        'size' => substr((string)($entry['size'] ?? ''), 0, 40),
        'aspectRatio' => substr((string)($entry['aspectRatio'] ?? ''), 0, 20),
        'quality' => substr((string)($entry['quality'] ?? ''), 0, 40),
        'format' => substr((string)($entry['format'] ?? ''), 0, 20),
        'moderation' => substr((string)($entry['moderation'] ?? ''), 0, 20),
        'compression' => isset($entry['compression']) ? (int)$entry['compression'] : null,
        'count' => nibblyAiClampInt($entry['count'] ?? 0, 0, 100),
        'referenceImages' => nibblyAiCleanPublicPathList($entry['referenceImages'] ?? []),
        'outputs' => nibblyAiCleanPublicPathList($entry['outputs'] ?? []),
        'error' => substr((string)($entry['error'] ?? ''), 0, 1000),
        'estimatedCostCents' => max(0, (int)($entry['estimatedCostCents'] ?? 0)),
        'durationMs' => max(0, (int)($entry['durationMs'] ?? 0)),
        'user' => (string)($_SESSION['admin_username'] ?? ($_SESSION['admin_user_id'] ?? ''))
    ];
    array_unshift($history['items'], $item);
    nibblyAiSaveImageHistory($history);
    return $item;
}

function nibblyAiClearImageHistory(): void {
    nibblyAiSaveImageHistory(['items' => []]);
}

function nibblyAiSaveImageHistory(array $history): void {
    $dir = dirname(NIBBLY_AI_IMAGE_HISTORY_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(NIBBLY_AI_IMAGE_HISTORY_PATH, json_encode([
        'items' => array_values(is_array($history['items'] ?? null) ? $history['items'] : [])
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function nibblyAiCleanPublicPathList($paths): array {
    if (!is_array($paths)) {
        $paths = [$paths];
    }
    $clean = [];
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path === '' || strpos($path, '..') !== false || preg_match('#[:\x00]#', $path)) {
            continue;
        }
        if (str_starts_with($path, 'upload:')) {
            $clean[] = substr($path, 0, 200);
            continue;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (str_starts_with($path, '/assets/images/')) {
            $clean[] = substr($path, 0, 400);
        }
    }
    return array_values(array_unique($clean));
}

function nibblyAiPublicReferenceList(array $options): array {
    $mediaPaths = $options['referenceMediaPaths'] ?? [];
    if (!is_array($mediaPaths)) {
        $mediaPaths = [$mediaPaths];
    }
    if (!empty($options['referenceMediaPath'])) {
        $mediaPaths[] = $options['referenceMediaPath'];
    }
    $references = nibblyAiCleanPublicPathList($mediaPaths);

    $uploadedNames = $options['referenceImageNames'] ?? [];
    if (!is_array($uploadedNames)) {
        $uploadedNames = [$uploadedNames];
    }
    foreach ($uploadedNames as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $references[] = 'upload:' . substr(basename($name), 0, 180);
        }
    }
    return array_values(array_unique($references));
}

function nibblyAiProviderRequest(array $settings, string $path, array $body): array {
    if (trim((string)($settings['baseUrl'] ?? '')) === '') {
        throw new RuntimeException('AI base URL is missing.');
    }
    $url = rtrim((string)$settings['baseUrl'], '/') . $path;
    $timeout = nibblyAiRequestTimeout($settings);
    nibblyAiExtendExecutionTime($timeout + 10);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    $key = trim((string)($settings['apiKey'] ?? ''));
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }
    if (!empty($settings['organization'])) {
        $headers[] = 'OpenAI-Organization: ' . $settings['organization'];
    }

    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => $timeout,
                'ignore_errors' => true
            ]
        ]);
        $raw = file_get_contents($url, false, $context);
        $status = 0;
        $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $m)) {
            $status = (int)$m[1];
        }
    } else {
        [$raw, $status] = nibblyAiCurlPost($url, $headers, $payload, $timeout);
    }

    $data = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? 'AI provider error') : 'AI provider error';
        nibblyAiAudit('provider-error', false, ['status' => $status, 'message' => $message]);
        throw new RuntimeException($message);
    }
    if (!is_array($data)) {
        throw new RuntimeException('AI provider returned invalid JSON.');
    }

    return $data;
}

function nibblyAiCurlPost(string $url, array $headers, $postFields, int $timeout): array {
    $attempts = 0;
    $lastError = '';
    do {
        $attempts++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FRESH_CONNECT => $attempts > 1,
            CURLOPT_FORBID_REUSE => true
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $downloadedBytes = 0.0;
        if (defined('CURLINFO_SIZE_DOWNLOAD_T')) {
            $downloadedBytes = (float)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T);
        }
        if ($downloadedBytes <= 0) {
            $downloadedBytes = (float)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        }
        $lastError = curl_error($ch);
        curl_close($ch);
        if ($raw !== false) {
            return [$raw, $status];
        }
    } while ($attempts < 2 && nibblyAiShouldRetryCurlError($lastError, $downloadedBytes));

    throw new RuntimeException($lastError !== '' ? 'AI provider request failed: ' . $lastError : 'AI provider request failed.');
}

function nibblyAiShouldRetryCurlError(string $error, float $downloadedBytes): bool {
    if ($downloadedBytes > 0) {
        return false;
    }
    $error = strtolower($error);
    return str_contains($error, 'ssl_read')
        || str_contains($error, 'bad record mac')
        || str_contains($error, 'connection reset')
        || str_contains($error, 'server returned nothing')
        || str_contains($error, 'empty reply');
}

function nibblyAiProviderMultipartRequest(array $settings, string $path, array $fields, array $files): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Image-to-image requires cURL support.');
    }
    if (trim((string)($settings['baseUrl'] ?? '')) === '') {
        throw new RuntimeException('AI base URL is missing.');
    }
    $url = rtrim((string)$settings['baseUrl'], '/') . $path;
    $timeout = nibblyAiRequestTimeout($settings);
    nibblyAiExtendExecutionTime($timeout + 10);
    $headers = ['Accept: application/json'];
    $key = trim((string)($settings['apiKey'] ?? ''));
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }
    if (!empty($settings['organization'])) {
        $headers[] = 'OpenAI-Organization: ' . $settings['organization'];
    }
    foreach ($files as $field => $pathToFile) {
        $info = @getimagesize((string)$pathToFile);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Reference image is not a supported image.');
        }
        if (filesize((string)$pathToFile) > 15 * 1024 * 1024) {
            throw new RuntimeException('Reference image is too large.');
        }
        $mime = image_type_to_mime_type($info[2]);
        $fields[$field] = new CURLFile((string)$pathToFile, $mime, basename((string)$pathToFile));
    }
    [$raw, $status] = nibblyAiCurlPost($url, $headers, $fields, $timeout);
    $data = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? 'AI provider error') : 'AI provider error';
        nibblyAiAudit('provider-error', false, ['status' => $status, 'message' => $message]);
        throw new RuntimeException($message);
    }
    if (!is_array($data)) {
        throw new RuntimeException('AI provider returned invalid JSON.');
    }
    return $data;
}

function nibblyAiIsOpenRouterSettings(array $settings): bool {
    return ($settings['provider'] ?? '') === 'openrouter'
        || str_contains((string)($settings['baseUrl'] ?? ''), 'openrouter.ai');
}

function nibblyAiGenerateOpenRouterImages(array $settings, array $options): array {
    $count = nibblyAiClampInt($options['count'] ?? 1, 1, 10);
    $settings['limits']['requestTimeoutSeconds'] = max(300, nibblyAiRequestTimeout($settings));
    $paths = [];
    $revisedPrompts = [];

    for ($i = 0; $i < $count; $i++) {
        $response = nibblyAiProviderRequest($settings, '/chat/completions', nibblyAiBuildOpenRouterImageBody($options));
        [$payloads, $texts] = nibblyAiExtractOpenRouterImagePayloads($response);
        foreach ($texts as $text) {
            if ($text !== '') {
                $revisedPrompts[] = $text;
            }
        }
        foreach ($payloads as $payload) {
            $binary = nibblyAiProviderImagePayloadToBinary($payload);
            $paths[] = nibblyAiStoreGeneratedImage($binary, (string)($options['filenameHint'] ?? 'ai-image'), (string)($options['size'] ?? 'auto'), count($paths) + 1);
            if (count($paths) >= $count) {
                break 2;
            }
        }
    }

    if (!$paths) {
        throw new RuntimeException('OpenRouter returned no usable image data.');
    }

    return [$paths, $revisedPrompts];
}

function nibblyAiBuildOpenRouterImageBody(array $options): array {
    $imageConfig = nibblyAiOpenRouterImageConfig(
        (string)($options['size'] ?? 'auto'),
        (string)($options['aspectRatio'] ?? 'auto'),
        $options['imageScale'] ?? null
    );
    $prompt = substr((string)($options['prompt'] ?? ''), 0, 8000);
    if (!empty($imageConfig['aspect_ratio'])) {
        $prompt .= "\n\nRequired output aspect ratio: " . $imageConfig['aspect_ratio'] . ". Do not return a square image unless the requested ratio is 1:1.";
    }
    $content = [
        ['type' => 'text', 'text' => $prompt]
    ];
    foreach (($options['referencePaths'] ?? []) as $path) {
        $content[] = [
            'type' => 'image_url',
            'image_url' => ['url' => nibblyAiReferenceImageDataUrl((string)$path)]
        ];
    }

    $body = [
        'model' => nibblyAiNormalizeOpenRouterImageModel((string)$options['model']),
        'messages' => [
            ['role' => 'user', 'content' => $content]
        ],
        'modalities' => ['image', 'text']
    ];

    if ($imageConfig) {
        $body['image_config'] = $imageConfig;
    }

    return $body;
}

function nibblyAiOpenRouterImageConfig(string $size, string $aspectRatio = 'auto', $imageScale = null): array {
    $config = [];
    $aspectRatio = nibblyAiCleanAspectRatio($aspectRatio);
    if ($aspectRatio !== 'auto') {
        [$rw, $rh] = array_map('intval', explode(':', $aspectRatio, 2));
        $config['aspect_ratio'] = nibblyAiNearestOpenRouterAspectRatio($rw, $rh);
        $scale = nibblyAiClampInt($imageScale ?? 2048, 1024, 3840);
        $config['image_size'] = $scale <= 1024 ? '1K' : ($scale <= 2048 ? '2K' : '4K');
        return $config;
    }
    if (preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
        $width = max(1, (int)$m[1]);
        $height = max(1, (int)$m[2]);
        $gcd = nibblyAiGcd($width, $height);
        $config['aspect_ratio'] = nibblyAiNearestOpenRouterAspectRatio((int)($width / $gcd), (int)($height / $gcd));
        $longEdge = max($width, $height);
        $config['image_size'] = $longEdge <= 1024 ? '1K' : ($longEdge <= 2048 ? '2K' : '4K');
    }
    return $config;
}

function nibblyAiCleanAspectRatio(string $ratio): string {
    $ratio = trim(strtolower($ratio));
    if ($ratio === '' || $ratio === 'auto') {
        return 'auto';
    }
    if (!preg_match('/^([1-9]\d?):([1-9]\d?)$/', $ratio, $m)) {
        return 'auto';
    }
    $width = (int)$m[1];
    $height = (int)$m[2];
    if ($width <= 0 || $height <= 0 || max($width / $height, $height / $width) > 3) {
        return 'auto';
    }
    return $width . ':' . $height;
}

function nibblyAiNearestOpenRouterAspectRatio(int $widthRatio, int $heightRatio): string {
    $requested = $heightRatio > 0 ? $widthRatio / $heightRatio : 1.0;
    $supported = ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];
    $best = '1:1';
    $bestDistance = PHP_FLOAT_MAX;
    foreach ($supported as $ratio) {
        [$w, $h] = array_map('intval', explode(':', $ratio, 2));
        $distance = abs(log($requested) - log($w / $h));
        if ($distance < $bestDistance) {
            $best = $ratio;
            $bestDistance = $distance;
        }
    }
    return $best;
}

function nibblyAiGcd(int $a, int $b): int {
    while ($b !== 0) {
        $tmp = $b;
        $b = $a % $b;
        $a = $tmp;
    }
    return max(1, abs($a));
}

function nibblyAiExtractOpenRouterImagePayloads(array $response): array {
    $payloads = [];
    $texts = [];
    foreach (($response['choices'] ?? []) as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        foreach (nibblyAiExtractOpenRouterMessageImages($message) as $payload) {
            $payloads[] = $payload;
        }
        foreach (nibblyAiExtractOpenRouterMessageTexts($message) as $text) {
            $texts[] = $text;
        }
    }
    return [array_values(array_unique($payloads)), array_values(array_unique($texts))];
}

function nibblyAiExtractOpenRouterMessageImages(array $message): array {
    $payloads = [];
    foreach (($message['images'] ?? []) as $image) {
        if (!is_array($image)) {
            continue;
        }
        $url = $image['image_url']['url'] ?? $image['imageUrl']['url'] ?? $image['url'] ?? '';
        if (is_string($url) && $url !== '') {
            $payloads[] = $url;
        }
    }
    $content = $message['content'] ?? null;
    if (is_array($content)) {
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $url = $part['image_url']['url'] ?? $part['imageUrl']['url'] ?? $part['url'] ?? '';
            if (is_string($url) && $url !== '') {
                $payloads[] = $url;
            }
        }
    }
    return $payloads;
}

function nibblyAiExtractOpenRouterMessageTexts(array $message): array {
    $texts = [];
    if (is_string($message['content'] ?? null)) {
        $texts[] = trim((string)$message['content']);
    } elseif (is_array($message['content'] ?? null)) {
        foreach ($message['content'] as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $texts[] = trim((string)$part['text']);
            }
        }
    }
    return array_values(array_filter($texts));
}

function nibblyAiProviderImagePayloadToBinary(string $payload): string {
    if (preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $payload)) {
        $base64 = substr($payload, strpos($payload, ',') + 1);
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            throw new RuntimeException('OpenRouter returned invalid image data.');
        }
        return $binary;
    }
    return nibblyAiDownloadProviderImage($payload);
}

function nibblyAiIsValidGptImage2Size(string $size): bool {
    if ($size === 'auto') return true;
    if (!preg_match('/^(\d{3,4})x(\d{3,4})$/', $size, $m)) return false;
    $w = (int)$m[1];
    $h = (int)$m[2];
    if ($w % 16 !== 0 || $h % 16 !== 0 || $w > 3840 || $h > 3840) return false;
    $pixels = $w * $h;
    if ($pixels < 655360 || $pixels > 8294400) return false;
    $ratio = max($w / $h, $h / $w);
    return $ratio <= 3;
}

function nibblyAiRequestTimeout(array $settings): int {
    $limits = is_array($settings['limits'] ?? null) ? $settings['limits'] : [];
    return nibblyAiClampInt($limits['requestTimeoutSeconds'] ?? 120, 5, 600);
}

function nibblyAiExtendExecutionTime(int $seconds): void {
    if (!function_exists('set_time_limit')) {
        return;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('set_time_limit', $disabled, true)) {
        return;
    }
    @set_time_limit(max(5, $seconds));
}

function nibblyAiCleanMessages(array $messages): array {
    $clean = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $role = (string)($message['role'] ?? 'user');
        if (!in_array($role, ['system', 'user', 'assistant'], true)) {
            $role = 'user';
        }
        $content = trim((string)($message['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        $clean[] = ['role' => $role, 'content' => substr($content, 0, 24000)];
    }
    return array_slice($clean, -20);
}

function nibblyAiLoadUsage(): array {
    if (!is_file(NIBBLY_AI_USAGE_PATH)) {
        return ['days' => [], 'months' => [], 'updatedAt' => null];
    }
    $data = json_decode((string)file_get_contents(NIBBLY_AI_USAGE_PATH), true);
    return is_array($data) ? array_replace(['days' => [], 'months' => [], 'updatedAt' => null], $data) : ['days' => [], 'months' => [], 'updatedAt' => null];
}

function nibblyAiSaveUsage(array $usage): void {
    $dir = dirname(NIBBLY_AI_USAGE_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(NIBBLY_AI_USAGE_PATH, json_encode($usage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function nibblyAiPruneUsage(array &$usage): void {
    $minDay = gmdate('Y-m-d', time() - 90 * 86400);
    foreach ($usage['days'] as $day => $_bucket) {
        if ($day < $minDay) {
            unset($usage['days'][$day]);
        }
    }
    $minMonth = gmdate('Y-m', strtotime('-24 months'));
    foreach ($usage['months'] as $month => $_bucket) {
        if ($month < $minMonth) {
            unset($usage['months'][$month]);
        }
    }
}

function nibblyAiEmptyUsageBucket(): array {
    return [
        'requests' => 0,
        'textRequests' => 0,
        'imageRequests' => 0,
        'inputTokens' => 0,
        'outputTokens' => 0,
        'estimatedCostCents' => 0
    ];
}

function nibblyAiEstimateTokens(string $text): int {
    $chars = mb_strlen($text, 'UTF-8');
    return max(1, (int)ceil($chars / 4));
}

function nibblyAiEstimateTextCost(array $settings, int $inputTokens, int $outputTokens): int {
    $pricing = $settings['pricing'];
    $cost = ($inputTokens / 1000000) * (int)$pricing['inputCentsPerMillion'];
    $cost += ($outputTokens / 1000000) * (int)$pricing['outputCentsPerMillion'];
    return (int)ceil($cost);
}

function nibblyAiStoreGeneratedImage(string $binary, string $hint, string $size = 'auto', int $index = 1): string {
    if (strlen($binary) > 15 * 1024 * 1024) {
        throw new RuntimeException('Generated image is too large.');
    }
    $info = @getimagesizefromstring($binary);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Generated file is not a supported image.');
    }
    $ext = match ($info[2]) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        default => 'png'
    };
    if (!is_dir(NIBBLY_AI_GENERATED_IMAGE_DIR)) {
        mkdir(NIBBLY_AI_GENERATED_IMAGE_DIR, 0755, true);
    }
    $slugSource = trim((string)$hint);
    $slugSource = strtr($slugSource, [
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
        'ß' => 'ss'
    ]);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slugSource);
        if (is_string($converted) && $converted !== '') {
            $slugSource = $converted;
        }
    }
    $slugSource = strtolower($slugSource);
    $slug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $slugSource));
    $slug = trim($slug, '-') ?: 'ai-image';
    $slug = substr($slug, 0, 72);
    $slug = trim($slug, '-') ?: 'ai-image';
    $indexSlug = $index > 1 ? '-v' . min(99, $index) : '';
    $baseName = $slug . $indexSlug;
    $filename = $baseName . '.' . $ext;
    $path = NIBBLY_AI_GENERATED_IMAGE_DIR . '/' . $filename;
    $counter = 2;
    while (file_exists($path)) {
        $filename = $baseName . '-' . $counter . '.' . $ext;
        $path = NIBBLY_AI_GENERATED_IMAGE_DIR . '/' . $filename;
        $counter++;
    }
    if (file_put_contents($path, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Could not save generated image.');
    }
    return '/assets/images/generated/' . $filename;
}

function nibblyAiDownloadProviderImage(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
        throw new RuntimeException('Invalid provider image URL.');
    }
    if (nibblyAiIsPrivateHost((string)($parts['host'] ?? ''))) {
        throw new RuntimeException('Provider image URL is not allowed.');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_MAXFILESIZE => 15 * 1024 * 1024
        ]);
        $binary = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($binary === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Could not download provider image.');
        }
        return $binary;
    }

    $context = stream_context_create(['http' => ['timeout' => 45, 'ignore_errors' => false]]);
    $binary = file_get_contents($url, false, $context, 0, 15 * 1024 * 1024);
    if ($binary === false) {
        throw new RuntimeException('Could not download provider image.');
    }
    return $binary;
}

function nibblyAiCollectReferenceImagePaths(array $options): array {
    $paths = [];
    $uploaded = $options['referenceImagePaths'] ?? [];
    if (!is_array($uploaded)) {
        $uploaded = [$uploaded];
    }
    if (!empty($options['referenceImagePath'])) {
        array_unshift($uploaded, (string)$options['referenceImagePath']);
    }
    foreach ($uploaded as $path) {
        $path = trim((string)$path);
        if ($path !== '') {
            $paths[] = $path;
        }
    }

    $mediaPaths = $options['referenceMediaPaths'] ?? [];
    if (!is_array($mediaPaths)) {
        $mediaPaths = [$mediaPaths];
    }
    if (!empty($options['referenceMediaPath'])) {
        $mediaPaths[] = (string)$options['referenceMediaPath'];
    }
    foreach ($mediaPaths as $publicPath) {
        $publicPath = trim((string)$publicPath);
        if ($publicPath !== '') {
            $paths[] = nibblyAiResolveReferenceMediaPath($publicPath);
        }
    }

    $paths = array_values(array_unique($paths));
    if (count($paths) > 16) {
        throw new RuntimeException('A maximum of 16 reference images is allowed.');
    }
    $totalBytes = 0;
    foreach ($paths as $path) {
        nibblyAiValidateReferenceImageFile($path);
        $totalBytes += (int)filesize($path);
    }
    if ($totalBytes > 64 * 1024 * 1024) {
        throw new RuntimeException('Reference images are too large in total.');
    }
    return $paths;
}

function nibblyAiValidateReferenceImageFile(string $path): void {
    $info = @getimagesize($path);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Reference image is not a supported image.');
    }
    if (filesize($path) > 15 * 1024 * 1024) {
        throw new RuntimeException('Reference image is too large.');
    }
}

function nibblyAiReferenceImageDataUrl(string $path): string {
    nibblyAiValidateReferenceImageFile($path);
    $info = getimagesize($path);
    $mime = image_type_to_mime_type($info[2]);
    $binary = file_get_contents($path);
    if ($binary === false) {
        throw new RuntimeException('Could not read reference image.');
    }
    return 'data:' . $mime . ';base64,' . base64_encode($binary);
}

function nibblyAiResolveReferenceMediaPath(string $publicPath): string {
    $publicPath = trim($publicPath);
    $publicPath = preg_replace('#^(\.\./)+#', '/', $publicPath);
    if ($publicPath === '' || strpos($publicPath, '..') !== false || preg_match('#[:\x00]#', $publicPath)) {
        throw new RuntimeException('Invalid reference image path.');
    }
    if ($publicPath[0] !== '/') {
        $publicPath = '/' . $publicPath;
    }
    if (!str_starts_with($publicPath, '/assets/images/')) {
        throw new RuntimeException('Reference image must come from the media library.');
    }
    $relative = substr($publicPath, strlen('/assets/images/'));
    if ($relative === '' || str_contains($relative, '\\')) {
        throw new RuntimeException('Invalid reference image path.');
    }
    $path = dirname(__DIR__, 2) . '/assets/images/' . $relative;
    $realBase = realpath(dirname(__DIR__, 2) . '/assets/images');
    $realPath = realpath($path);
    if ($realBase === false || $realPath === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Reference image not found.');
    }
    return $realPath;
}

function nibblyAiValidateBaseUrl(string $url, bool $allowLocal): void {
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
        throw new RuntimeException('Invalid AI base URL.');
    }
    if (!$allowLocal && nibblyAiIsPrivateHost((string)$parts['host'])) {
        throw new RuntimeException('Local/private AI provider URLs must be explicitly allowed.');
    }
}

function nibblyAiIsLocalBaseUrl(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && nibblyAiIsPrivateHost($host);
}

function nibblyAiIsPrivateHost(string $host): bool {
    $host = strtolower(trim($host, '[] '));
    if ($host === 'localhost' || $host === '::1') {
        return true;
    }
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function nibblyAiCleanModel(string $model): string {
    $model = trim($model);
    if ($model === '') {
        return 'gpt-4.1-mini';
    }
    return substr(preg_replace('/[^A-Za-z0-9._:\/-]/', '', $model), 0, 120);
}

function nibblyAiCleanModelAllowEmpty(string $model): string {
    $model = trim($model);
    if ($model === '') {
        return '';
    }
    return substr(preg_replace('/[^A-Za-z0-9._:\/-]/', '', $model), 0, 120);
}

function nibblyAiNormalizeOpenRouterImageModel(string $model): string {
    $model = nibblyAiCleanModelAllowEmpty($model);
    if ($model === '') {
        return '';
    }

    $aliases = [
        'gpt-image-2' => 'openai/gpt-5.4-image-2',
        'gpt-5.4' => 'openai/gpt-5.4-image-2',
        'openai/gpt-5.4' => 'openai/gpt-5.4-image-2',
        'gpt-5.4-2026-03-05' => 'openai/gpt-5.4-image-2',
        'openai/gpt-5.4-2026-03-05' => 'openai/gpt-5.4-image-2',
        'gemini-3.1-flash-image-preview' => 'google/gemini-3.1-flash-image-preview',
        'gemini-3-pro-image-preview' => 'google/gemini-3-pro-image-preview'
    ];

    return $aliases[$model] ?? $model;
}

function nibblyAiClampInt($value, int $min, int $max): int {
    return max($min, min($max, (int)$value));
}
