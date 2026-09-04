<?php
/**
 * nibbly AI gateway.
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
if (!defined('NIBBLY_AI_IMAGE_JOBS_DIR')) {
    define('NIBBLY_AI_IMAGE_JOBS_DIR', dirname(__DIR__, 2) . '/content/ai-image-jobs');
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
            ],
            'anthropic' => [
                'baseUrl' => 'https://api.anthropic.com/v1',
                'apiKey' => '',
                'organization' => ''
            ],
            'kie' => [
                'baseUrl' => 'https://api.kie.ai',
                'apiKey' => '',
                'organization' => ''
            ]
        ],
        'chatModel' => 'gpt-4.1-mini',
        'textModel' => '',
        'imageModel' => 'gpt-image-2',
        'organization' => '',
        'allowLocalProvider' => false,
        'assistantForceEnglish' => false,
        'assistantSurfaces' => [
            'visualEditor' => true,
            'dashboard' => true
        ],
        'features' => [
            'backendAssistant' => true,
            'seoTextGeneration' => true,
            'imageGeneration' => true
        ],
        'limits' => [
            'monthlyBudgetCents' => 1000,
            'dailyRequests' => 100,
            'dailyTextRequests' => 80,
            'dailyImageRequests' => 10,
            'maxInputTokens' => 24000,
            'maxOutputTokens' => 4096,
            'requestTimeoutSeconds' => 300
        ],
        'pricing' => [
            'inputCentsPerMillion' => 15,
            'outputCentsPerMillion' => 60,
            'imageCentsPerRequest' => 5
        ],
        'systemPrompts' => [
            'assistant' => 'You are the nibbly CMS assistant. Answer clearly and practically. If a task needs admin access, explain where to do it in the nibbly dashboard. Do not invent settings that do not exist.',
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
    if (!in_array($provider, ['openai-compatible', 'openrouter', 'anthropic', 'kie'], true)) {
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
    $newApiKeyProvided = $apiKey !== '';
    $hadProviderApiKey = !empty($currentProviderCredentials['apiKey']);
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
    } elseif ($provider === 'kie') {
        $imageModel = nibblyAiNormalizeKieImageModel($imageModel);
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
        'assistantForceEnglish' => !empty($input['assistantForceEnglish']),
        'assistantSurfaces' => [
            'visualEditor' => !array_key_exists('assistantSurfaces', $input) || !empty($input['assistantSurfaces']['visualEditor']),
            'dashboard' => !array_key_exists('assistantSurfaces', $input) || !empty($input['assistantSurfaces']['dashboard'])
        ],
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
            'maxInputTokens' => nibblyAiClampInt($input['limits']['maxInputTokens'] ?? 24000, 100, 200000),
            'maxOutputTokens' => nibblyAiClampInt($input['limits']['maxOutputTokens'] ?? 4096, 16, 32000),
            'requestTimeoutSeconds' => nibblyAiClampInt($input['limits']['requestTimeoutSeconds'] ?? 300, 5, 600)
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

    if ($newApiKeyProvided && !$hadProviderApiKey && empty($input['clearApiKey'])) {
        $settings['enabled'] = true;
        $settings['assistantSurfaces']['visualEditor'] = true;
        $settings['assistantSurfaces']['dashboard'] = true;
        $settings['features']['backendAssistant'] = true;
        $settings['features']['seoTextGeneration'] = true;
        $settings['features']['imageGeneration'] = true;
    }

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

/**
 * Stream a chat completion, invoking $onDelta with each text fragment as it
 * arrives. Returns the same result shape as nibblyAiChat(). Falls back to a
 * buffered request (one delta with the full text) when streaming is not
 * possible, so callers never need a second code path.
 */
function nibblyAiChatStream(array $messages, array $options, callable $onDelta): array {
    $settings = nibblyAiLoadSettings(false);
    nibblyAiEnsureEnabled($settings);
    $feature = $options['feature'] ?? 'backendAssistant';
    if ($feature !== '') {
        nibblyAiEnsureFeature($settings, $feature);
    }
    if (!function_exists('curl_init')) {
        $result = nibblyAiChat($messages, $options);
        $onDelta((string)$result['text']);
        return $result;
    }

    $cleanMessages = nibblyAiCleanMessages($messages);
    if (!$cleanMessages) {
        throw new RuntimeException('No AI messages provided.');
    }
    $inputTokens = nibblyAiEstimateTokens(json_encode($cleanMessages, JSON_UNESCAPED_UNICODE));
    if ($inputTokens > (int)$settings['limits']['maxInputTokens']) {
        throw new RuntimeException('AI input is too long for the configured limit.');
    }
    $maxOutput = nibblyAiClampInt($options['maxOutputTokens'] ?? $settings['limits']['maxOutputTokens'], 16, (int)$settings['limits']['maxOutputTokens']);
    nibblyAiAssertWithinLimits($settings, 'text', nibblyAiEstimateTextCost($settings, $inputTokens, $maxOutput));

    $model = trim((string)($options['model'] ?? '')) ?: (string)$settings['chatModel'];
    $temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.3;
    if (nibblyAiIsAnthropicSettings($settings)) {
        $url = rtrim((string)$settings['baseUrl'], '/') . '/messages';
        $headers = ['Content-Type: application/json', 'Accept: text/event-stream', 'anthropic-version: 2023-06-01'];
        $key = trim((string)($settings['apiKey'] ?? ''));
        if ($key !== '') {
            $headers[] = 'x-api-key: ' . $key;
        }
        $system = [];
        $chatMessages = [];
        foreach ($cleanMessages as $message) {
            if ($message['role'] === 'system') {
                $system[] = $message['content'];
                continue;
            }
            $chatMessages[] = $message;
        }
        while ($chatMessages && $chatMessages[0]['role'] !== 'user') {
            array_shift($chatMessages);
        }
        if (!$chatMessages) {
            throw new RuntimeException('No AI messages provided.');
        }
        $body = [
            'model' => $model,
            'max_tokens' => $maxOutput,
            'messages' => $chatMessages,
            'temperature' => min(1.0, max(0.0, $temperature)),
            'stream' => true
        ];
        if ($system) {
            $body['system'] = implode("\n\n", $system);
        }
    } else {
        $url = rtrim((string)$settings['baseUrl'], '/') . '/chat/completions';
        $headers = ['Content-Type: application/json', 'Accept: text/event-stream'];
        $key = trim((string)($settings['apiKey'] ?? ''));
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        if (!empty($settings['organization'])) {
            $headers[] = 'OpenAI-Organization: ' . $settings['organization'];
        }
        $body = [
            'model' => $model,
            'messages' => $cleanMessages,
            'max_tokens' => $maxOutput,
            'temperature' => $temperature,
            'stream' => true
        ];
    }

    $timeout = nibblyAiRequestTimeout($settings);
    nibblyAiExtendExecutionTime($timeout + 10);
    $started = microtime(true);
    $state = [
        'buffer' => '',
        'raw' => '',
        'text' => '',
        'inputTokens' => 0,
        'outputTokens' => 0
    ];
    $parseLine = static function (string $line) use (&$state, $onDelta): void {
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, 'data:')) {
            return;
        }
        $payload = trim(substr($line, 5));
        if ($payload === '' || $payload === '[DONE]') {
            return;
        }
        $chunk = json_decode($payload, true);
        if (!is_array($chunk)) {
            return;
        }
        $delta = '';
        // OpenAI-compatible chunk shape.
        $choice = $chunk['choices'][0] ?? null;
        if (is_array($choice) && is_string($choice['delta']['content'] ?? null)) {
            $delta = $choice['delta']['content'];
        }
        if (is_array($chunk['usage'] ?? null)) {
            $state['inputTokens'] = (int)($chunk['usage']['prompt_tokens'] ?? $state['inputTokens']);
            $state['outputTokens'] = (int)($chunk['usage']['completion_tokens'] ?? $state['outputTokens']);
        }
        // Anthropic Messages API event shapes.
        $type = (string)($chunk['type'] ?? '');
        if ($type === 'content_block_delta' && (string)($chunk['delta']['type'] ?? '') === 'text_delta') {
            $delta = (string)($chunk['delta']['text'] ?? '');
        } elseif ($type === 'message_start') {
            $state['inputTokens'] = (int)($chunk['message']['usage']['input_tokens'] ?? $state['inputTokens']);
        } elseif ($type === 'message_delta') {
            $state['outputTokens'] = (int)($chunk['usage']['output_tokens'] ?? $state['outputTokens']);
        }
        if ($delta !== '') {
            $state['text'] .= $delta;
            $onDelta($delta);
        }
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$state, $parseLine): int {
            $state['raw'] .= $chunk;
            $state['buffer'] .= $chunk;
            while (($pos = strpos($state['buffer'], "\n")) !== false) {
                $parseLine(substr($state['buffer'], 0, $pos));
                $state['buffer'] = substr($state['buffer'], $pos + 1);
            }
            return strlen($chunk);
        }
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($state['buffer'] !== '') {
        $parseLine($state['buffer']);
    }

    if ($status < 200 || $status >= 300) {
        $data = json_decode($state['raw'], true);
        $message = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? 'AI provider error') : 'AI provider error';
        nibblyAiAudit('provider-error', false, ['status' => $status, 'message' => $message]);
        throw new RuntimeException($message);
    }
    if ($ok === false && $state['text'] === '') {
        throw new RuntimeException($curlError !== '' ? 'AI provider request failed: ' . $curlError : 'AI provider request failed.');
    }
    if ($state['text'] === '') {
        // Provider ignored stream=true and returned one JSON document.
        $data = json_decode($state['raw'], true);
        if (is_array($data)) {
            $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
            if ($text === '' && is_array($data['content'] ?? null)) {
                foreach ($data['content'] as $block) {
                    if (is_array($block) && (string)($block['type'] ?? '') === 'text') {
                        $text .= (string)($block['text'] ?? '');
                    }
                }
            }
            if ($text !== '') {
                $state['text'] = $text;
                $onDelta($text);
            }
        }
    }
    if ($state['text'] === '') {
        throw new RuntimeException('AI provider returned an empty response.');
    }

    $actualInput = $state['inputTokens'] > 0 ? $state['inputTokens'] : $inputTokens;
    $actualOutput = $state['outputTokens'] > 0 ? $state['outputTokens'] : nibblyAiEstimateTokens($state['text']);
    $actualCost = nibblyAiEstimateTextCost($settings, $actualInput, $actualOutput);
    nibblyAiRecordUsage('text', $actualInput, $actualOutput, $actualCost);
    nibblyAiAudit('chat', true, [
        'model' => $model,
        'streamed' => true,
        'inputTokens' => $actualInput,
        'outputTokens' => $actualOutput,
        'estimatedCostCents' => $actualCost,
        'durationMs' => (int)round((microtime(true) - $started) * 1000)
    ]);

    return [
        'text' => $state['text'],
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
    $model = trim((string)($options['model'] ?? ''));
    return nibblyAiChat($messages, [
        'feature' => $feature,
        'model' => $model !== '' ? $model : ($settings['textModel'] ?: $settings['chatModel']),
        'maxOutputTokens' => $options['maxOutputTokens'] ?? null,
        'temperature' => $options['temperature'] ?? 0.4
    ]);
}

function nibblyAiGenerateImage(string $prompt, array $options = []): array {
    $settings = nibblyAiLoadSettings(false);
    nibblyAiEnsureEnabled($settings);
    nibblyAiEnsureFeature($settings, 'imageGeneration');
    if (nibblyAiIsAnthropicSettings($settings)) {
        throw new RuntimeException('The Anthropic provider supports chat and text features only. Use an OpenAI-compatible or OpenRouter provider for image generation.');
    }

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
    $usesKie = nibblyAiIsKieSettings($settings);
    $model = (string)($options['model'] ?? $settings['imageModel']);
    if ($usesOpenRouter) {
        $model = nibblyAiNormalizeOpenRouterImageModel($model);
    } elseif ($usesKie) {
        $model = nibblyAiNormalizeKieImageModel($model);
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
    $outputCompression = max(0, min(100, (int)($options['outputCompression'] ?? 75)));
    $aspectRatio = nibblyAiCleanAspectRatio((string)($options['aspectRatio'] ?? 'auto'));
    $started = microtime(true);
    $referencePaths = nibblyAiCollectReferenceImagePaths($options);
    nibblyAiAudit('image-references-collected', true, [
        'usesOpenRouter' => $usesOpenRouter,
        'usesKie' => $usesKie,
        'referenceImagePathsIn' => count(is_array($options['referenceImagePaths'] ?? null) ? $options['referenceImagePaths'] : []),
        'referenceMediaPathsIn' => count(is_array($options['referenceMediaPaths'] ?? null) ? $options['referenceMediaPaths'] : []),
        'collected' => count($referencePaths)
    ]);

    if ($usesKie) {
        [$paths, $revisedPrompts] = nibblyAiGenerateKieImages($settings, [
            'model' => $model,
            'prompt' => $prompt,
            'count' => $count,
            'size' => $size,
            'aspectRatio' => $aspectRatio,
            'imageScale' => $options['imageScale'] ?? null,
            'quality' => $quality,
            'outputFormat' => $outputFormat,
            'outputCompression' => $outputCompression,
            'referencePaths' => $referencePaths,
            'filenameHint' => $options['filenameHint'] ?? 'ai-image'
        ]);
        nibblyAiRecordUsage('image', nibblyAiEstimateTokens($prompt), 0, $cost);
        $actualMeta = nibblyAiActualImageMeta($paths);
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        nibblyAiAudit('image', true, [
            'provider' => 'kie', 'model' => $model, 'count' => count($paths),
            'estimatedCostCents' => $cost, 'paths' => $paths, 'durationMs' => $durationMs
        ]);
        $historyItem = nibblyAiRecordImageHistory([
            'status' => 'success', 'model' => $model, 'prompt' => $prompt,
            'revisedPrompt' => implode("\n\n", array_values(array_unique(array_filter($revisedPrompts)))),
            'size' => $actualMeta['size'] ?? $size, 'aspectRatio' => $aspectRatio,
            'quality' => $quality, 'format' => $actualMeta['format'] ?? $outputFormat,
            'moderation' => $moderation, 'compression' => $outputCompression,
            'count' => count($paths), 'referenceImages' => nibblyAiPublicReferenceList($options),
            'outputs' => $paths, 'error' => '', 'estimatedCostCents' => $cost,
            'durationMs' => $durationMs
        ]);
        return [
            'path' => $paths[0], 'paths' => $paths, 'historyItem' => $historyItem,
            'usage' => ['estimatedCostCents' => $cost], 'limits' => nibblyAiUsageSummary()
        ];
    }

    if ($usesOpenRouter) {
        [$paths, $revisedPrompts] = nibblyAiGenerateOpenRouterImages($settings, [
            'model' => $model,
            'prompt' => $prompt,
            'count' => $count,
            'size' => $size,
            'aspectRatio' => $aspectRatio,
            'imageScale' => $options['imageScale'] ?? null,
            'quality' => $quality,
            'outputFormat' => $outputFormat,
            'outputCompression' => $outputCompression,
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
        $actualMeta = nibblyAiActualImageMeta($paths);
        $historyItem = nibblyAiRecordImageHistory([
            'status' => 'success',
            'model' => $model,
            'prompt' => $prompt,
            'revisedPrompt' => implode("\n\n", array_values(array_unique(array_filter($revisedPrompts)))),
            'size' => $actualMeta['size'] ?? $size,
            'aspectRatio' => $aspectRatio,
            'quality' => $quality,
            'format' => $actualMeta['format'] ?? $outputFormat,
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
        $paths[] = nibblyAiStoreGeneratedImage($binary, $options['filenameHint'] ?? 'ai-image', $size, count($paths) + 1, $outputFormat, $outputCompression);
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
    $actualMeta = nibblyAiActualImageMeta($paths);
    $historyItem = nibblyAiRecordImageHistory([
        'status' => 'success',
        'model' => $body['model'],
        'prompt' => $prompt,
        'revisedPrompt' => implode("\n\n", array_values(array_unique(array_filter($revisedPrompts)))),
        'size' => $actualMeta['size'] ?? $size,
        'aspectRatio' => $aspectRatio,
        'quality' => $quality,
        'format' => $actualMeta['format'] ?? $outputFormat,
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

function nibblyAiEnsureImageJobsDir(): void {
    if (!is_dir(NIBBLY_AI_IMAGE_JOBS_DIR)) {
        mkdir(NIBBLY_AI_IMAGE_JOBS_DIR, 0755, true);
    }
}

function nibblyAiImageJobPath(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        throw new RuntimeException('Invalid image job ID.');
    }
    return NIBBLY_AI_IMAGE_JOBS_DIR . '/' . $id . '.json';
}

function nibblyAiCreateImageJob(string $kind, array $payload): array {
    nibblyAiEnsureImageJobsDir();
    $id = 'job_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(5)), 0, 10);
    $jobDir = NIBBLY_AI_IMAGE_JOBS_DIR . '/' . $id;
    if (!is_dir($jobDir)) {
        mkdir($jobDir, 0755, true);
    }
    $payload = nibblyAiPersistImageJobReferences($payload, $jobDir);
    $job = [
        'id' => $id,
        'kind' => in_array($kind, ['dashboard', 'copilot'], true) ? $kind : 'dashboard',
        'status' => 'queued',
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
        'startedAt' => '',
        'finishedAt' => '',
        'attempts' => 0,
        'user' => (string)($_SESSION['admin_username'] ?? ($_SESSION['admin_user_id'] ?? '')),
        'payload' => $payload,
        'result' => null,
        'error' => ''
    ];
    nibblyAiSaveImageJob($job);
    return nibblyAiPublicImageJob($job);
}

function nibblyAiPersistImageJobReferences(array $payload, string $jobDir): array {
    $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
    $paths = $options['referenceImagePaths'] ?? [];
    if (!is_array($paths)) {
        $paths = [$paths];
    }
    $names = $options['referenceImageNames'] ?? [];
    if (!is_array($names)) {
        $names = [$names];
    }
    $savedPaths = [];
    $savedNames = [];
    foreach ($paths as $index => $path) {
        $path = (string)$path;
        if ($path === '' || !is_file($path)) {
            continue;
        }
        $info = @getimagesize($path);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            continue;
        }
        $ext = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => 'img'
        };
        $target = $jobDir . '/reference-' . ($index + 1) . '.' . $ext;
        if (@copy($path, $target)) {
            $savedPaths[] = $target;
            $name = trim((string)($names[$index] ?? basename($path)));
            $savedNames[] = substr($name !== '' ? $name : basename($target), 0, 180);
        }
    }
    $options['referenceImagePaths'] = $savedPaths;
    $options['referenceImageNames'] = $savedNames;
    $payload['options'] = $options;
    return $payload;
}

function nibblyAiSaveImageJob(array $job): void {
    nibblyAiEnsureImageJobsDir();
    $job['updatedAt'] = gmdate('c');
    file_put_contents(nibblyAiImageJobPath((string)$job['id']), json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function nibblyAiLoadImageJob(string $id): array {
    $path = nibblyAiImageJobPath($id);
    if (!is_file($path)) {
        throw new RuntimeException('Image job not found.');
    }
    $job = json_decode((string)file_get_contents($path), true);
    if (!is_array($job) || empty($job['id'])) {
        throw new RuntimeException('Invalid image job.');
    }
    return $job;
}

function nibblyAiImageJobTimestamp($value): int {
    if (!$value) {
        return 0;
    }
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? 0 : $timestamp;
}

function nibblyAiImageJobStaleAfterSeconds(): int {
    $settings = nibblyAiLoadSettings(true);
    $timeout = (int)($settings['limits']['requestTimeoutSeconds'] ?? 300);
    return nibblyAiClampInt($timeout + 60, 180, 900);
}

function nibblyAiRefreshImageJobState(array $job): array {
    if ((string)($job['status'] ?? '') !== 'running') {
        return $job;
    }
    $started = nibblyAiImageJobTimestamp($job['startedAt'] ?? ($job['updatedAt'] ?? ''));
    if ($started > 0 && time() - $started > nibblyAiImageJobStaleAfterSeconds()) {
        $job['status'] = 'error';
        $job['finishedAt'] = gmdate('c');
        $job['error'] = 'Image generation timed out. No image was created.';
        nibblyAiSaveImageJob($job);
    }
    return $job;
}

function nibblyAiListImageJobs(bool $openOnly = false, int $limit = 20, ?string $user = null): array {
    nibblyAiEnsureImageJobsDir();
    $jobs = [];
    $user = $user !== null ? trim($user) : null;
    foreach (glob(NIBBLY_AI_IMAGE_JOBS_DIR . '/job_*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (!is_array($job) || empty($job['id'])) {
            continue;
        }
        $job = nibblyAiRefreshImageJobState($job);
        if ($user !== null && (string)($job['user'] ?? '') !== $user) {
            continue;
        }
        if ($openOnly && !in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
            continue;
        }
        $jobs[] = $job;
    }
    usort($jobs, fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));
    $limit = nibblyAiClampInt($limit, 1, 100);
    return array_map('nibblyAiPublicImageJob', array_slice($jobs, 0, $limit));
}

function nibblyAiPublicImageJob(array $job): array {
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
    $result = is_array($job['result'] ?? null) ? $job['result'] : null;
    return [
        'id' => (string)($job['id'] ?? ''),
        'kind' => (string)($job['kind'] ?? ''),
        'status' => (string)($job['status'] ?? 'queued'),
        'createdAt' => (string)($job['createdAt'] ?? ''),
        'updatedAt' => (string)($job['updatedAt'] ?? ''),
        'startedAt' => (string)($job['startedAt'] ?? ''),
        'finishedAt' => (string)($job['finishedAt'] ?? ''),
        'prompt' => substr((string)($payload['prompt'] ?? $payload['instruction'] ?? ''), 0, 8000),
        'model' => substr((string)($options['model'] ?? ''), 0, 120),
        'count' => (int)($options['count'] ?? 1),
        'result' => $result,
        'error' => substr((string)($job['error'] ?? ''), 0, 1000)
    ];
}

function nibblyAiMarkImageJobRunning(string $id): array {
    $job = nibblyAiLoadImageJob($id);
    if (in_array((string)($job['status'] ?? ''), ['success', 'error'], true)) {
        return $job;
    }
    if ((string)($job['status'] ?? '') === 'running') {
        return nibblyAiRefreshImageJobState($job);
    }
    $job['status'] = 'running';
    $job['startedAt'] = $job['startedAt'] ?: gmdate('c');
    $job['attempts'] = (int)($job['attempts'] ?? 0) + 1;
    $job['error'] = '';
    nibblyAiSaveImageJob($job);
    return $job;
}

function nibblyAiFinishImageJob(string $id, array $result): array {
    $job = nibblyAiLoadImageJob($id);
    $job['status'] = 'success';
    $job['finishedAt'] = gmdate('c');
    $job['result'] = $result;
    $job['error'] = '';
    nibblyAiSaveImageJob($job);
    return nibblyAiPublicImageJob($job);
}

function nibblyAiFailImageJob(string $id, string $message): array {
    $job = nibblyAiLoadImageJob($id);
    $job['status'] = 'error';
    $job['finishedAt'] = gmdate('c');
    $job['error'] = substr($message, 0, 1000);
    nibblyAiSaveImageJob($job);
    return nibblyAiPublicImageJob($job);
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
    if (nibblyAiIsAnthropicSettings($settings)) {
        if ($path !== '/chat/completions') {
            throw new RuntimeException('The Anthropic provider supports chat and text features only. Use an OpenAI-compatible or OpenRouter provider for image generation.');
        }
        return nibblyAiAnthropicChatRequest($settings, $body);
    }
    if (nibblyAiIsKieSettings($settings)) {
        if ($path !== '/chat/completions') {
            throw new RuntimeException('Unsupported Kie.ai provider request.');
        }
        return nibblyAiKieChatRequest($settings, $body);
    }
    $url = rtrim((string)$settings['baseUrl'], '/') . $path;
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

    return nibblyAiExecuteJsonRequest($settings, $url, $headers, $body);
}

function nibblyAiIsAnthropicSettings(array $settings): bool {
    return (string)($settings['provider'] ?? '') === 'anthropic'
        || stripos((string)($settings['baseUrl'] ?? ''), 'api.anthropic.com') !== false;
}

function nibblyAiIsKieSettings(array $settings): bool {
    return (string)($settings['provider'] ?? '') === 'kie'
        || stripos((string)($settings['baseUrl'] ?? ''), 'api.kie.ai') !== false;
}

/** Translate Kie.ai's provider-specific chat APIs to Nibbly's completion shape. */
function nibblyAiKieChatRequest(array $settings, array $body): array {
    $model = trim((string)($body['model'] ?? '')) ?: 'gpt-5-6-luna';
    $baseUrl = rtrim((string)($settings['baseUrl'] ?? 'https://api.kie.ai'), '/');
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $key = trim((string)($settings['apiKey'] ?? ''));
    if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

    if ($model === 'claude-sonnet-5') {
        return nibblyAiKieClaudeChatRequest($settings, $headers, $body, $baseUrl . '/claude/v1/messages');
    }
    if ($model === 'gemini-3-5-flash') {
        $request = $body;
        $request['model'] = $model;
        $request['stream'] = false;
        return nibblyAiExecuteJsonRequest($settings, $baseUrl . '/gemini-3-5-flash-openai/v1/chat/completions', $headers, $request);
    }

    $input = [];
    foreach ((array)($body['messages'] ?? []) as $message) {
        if (!is_array($message)) continue;
        $content = [];
        foreach (is_array($message['content'] ?? null) ? $message['content'] : [['type' => 'text', 'text' => (string)($message['content'] ?? '')]] as $part) {
            if (!is_array($part)) continue;
            if (($part['type'] ?? '') === 'text') $content[] = ['type' => 'input_text', 'text' => (string)($part['text'] ?? '')];
            if (($part['type'] ?? '') === 'image_url') {
                $url = is_array($part['image_url'] ?? null) ? (string)($part['image_url']['url'] ?? '') : (string)($part['image_url'] ?? '');
                if ($url !== '') $content[] = ['type' => 'input_image', 'image_url' => $url];
            }
        }
        if ($content) $input[] = ['role' => (string)($message['role'] ?? 'user'), 'content' => $content];
    }
    $response = nibblyAiExecuteJsonRequest($settings, $baseUrl . '/codex/v1/responses', $headers, [
        'model' => $model, 'input' => $input, 'reasoning' => ['effort' => 'low'], 'stream' => false
    ]);
    $text = '';
    foreach ((array)($response['output'] ?? []) as $output) {
        if (!is_array($output) || ($output['type'] ?? '') !== 'message') continue;
        foreach ((array)($output['content'] ?? []) as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'output_text') $text .= (string)($part['text'] ?? '');
        }
    }
    return ['object' => 'chat.completion', 'model' => $model,
        'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => (int)($response['usage']['input_tokens'] ?? 0), 'completion_tokens' => (int)($response['usage']['output_tokens'] ?? 0)]];
}

function nibblyAiKieClaudeChatRequest(array $settings, array $headers, array $body, string $url): array {
    $system = [];
    $messages = [];
    foreach ((array)($body['messages'] ?? []) as $message) {
        if (!is_array($message)) continue;
        $role = (string)($message['role'] ?? '');
        $content = $message['content'] ?? '';
        if ($role === 'system') { $system[] = is_string($content) ? $content : ''; continue; }
        if (in_array($role, ['user', 'assistant'], true) && $content !== '') $messages[] = ['role' => $role, 'content' => $content];
    }
    $request = ['model' => 'claude-sonnet-5', 'messages' => $messages,
        'max_tokens' => max(1, (int)($body['max_tokens'] ?? 4096)), 'stream' => false];
    if ($system) $request['system'] = implode("\n\n", array_filter($system));
    $response = nibblyAiExecuteJsonRequest($settings, $url, $headers, $request);
    $text = '';
    foreach ((array)($response['content'] ?? []) as $part) if (is_array($part) && ($part['type'] ?? '') === 'text') $text .= (string)($part['text'] ?? '');
    return ['object' => 'chat.completion', 'model' => (string)($response['model'] ?? 'claude-sonnet-5'),
        'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => ($response['stop_reason'] ?? '') === 'max_tokens' ? 'length' : 'stop']],
        'usage' => ['prompt_tokens' => (int)($response['usage']['input_tokens'] ?? 0), 'completion_tokens' => (int)($response['usage']['output_tokens'] ?? 0)]];
}

/**
 * Translate an OpenAI-style chat request to the Anthropic Messages API and
 * map the response back, so all callers keep one response shape.
 */
function nibblyAiAnthropicChatRequest(array $settings, array $body): array {
    $url = rtrim((string)$settings['baseUrl'], '/') . '/messages';
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'anthropic-version: 2023-06-01'
    ];
    $key = trim((string)($settings['apiKey'] ?? ''));
    if ($key !== '') {
        $headers[] = 'x-api-key: ' . $key;
    }

    $system = [];
    $messages = [];
    foreach (($body['messages'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }
        $role = (string)($message['role'] ?? '');
        $content = (string)($message['content'] ?? '');
        if ($content === '') {
            continue;
        }
        if ($role === 'system') {
            $system[] = $content;
            continue;
        }
        if (in_array($role, ['user', 'assistant'], true)) {
            $messages[] = ['role' => $role, 'content' => $content];
        }
    }
    while ($messages && $messages[0]['role'] !== 'user') {
        array_shift($messages);
    }
    if (!$messages) {
        throw new RuntimeException('No AI messages provided.');
    }

    $anthropicBody = [
        'model' => (string)($body['model'] ?? ''),
        'max_tokens' => max(1, (int)($body['max_tokens'] ?? 1024)),
        'messages' => $messages
    ];
    if ($system) {
        $anthropicBody['system'] = implode("\n\n", $system);
    }
    if (isset($body['temperature'])) {
        $anthropicBody['temperature'] = min(1.0, max(0.0, (float)$body['temperature']));
    }

    $response = nibblyAiExecuteJsonRequest($settings, $url, $headers, $anthropicBody);
    $text = '';
    foreach ((is_array($response['content'] ?? null) ? $response['content'] : []) as $block) {
        if (is_array($block) && (string)($block['type'] ?? '') === 'text') {
            $text .= (string)($block['text'] ?? '');
        }
    }
    $stopReason = (string)($response['stop_reason'] ?? '');
    return [
        'object' => 'chat.completion',
        'model' => (string)($response['model'] ?? $anthropicBody['model']),
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => $text],
            'finish_reason' => $stopReason === 'max_tokens' ? 'length' : 'stop'
        ]],
        'usage' => [
            'prompt_tokens' => (int)($response['usage']['input_tokens'] ?? 0),
            'completion_tokens' => (int)($response['usage']['output_tokens'] ?? 0)
        ]
    ];
}

function nibblyAiExecuteJsonRequest(array $settings, string $url, array $headers, array $body): array {
    $timeout = nibblyAiRequestTimeout($settings);
    nibblyAiExtendExecutionTime($timeout + 10);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $attempts = 0;
    $streamed = false;
    do {
        $attempts++;
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
        if (is_array($data)) {
            return $data;
        }

        $streamed = (bool)preg_match('/(^|\n)\s*data\s*:/', (string)$raw);
        if ($streamed) {
            $salvaged = nibblyAiAssembleSseResponse((string)$raw);
            if ($salvaged !== null) {
                nibblyAiAudit('provider-sse-salvaged', true, ['status' => $status, 'attempt' => $attempts]);
                return $salvaged;
            }
        }
        nibblyAiAudit('provider-invalid-json', false, [
            'status' => $status,
            'attempt' => $attempts,
            'body' => substr((string)$raw, 0, 1000)
        ]);
    } while ($streamed && $attempts < 2);

    if ($streamed) {
        throw new RuntimeException('AI provider returned a streaming response although nibbly requested JSON. Please retry; if this persists, use one image per request or another image model.');
    }
    throw new RuntimeException('AI provider returned invalid JSON.');
}

/**
 * Rebuild a normal completion payload from an unexpected SSE stream so a
 * provider that ignores stream=false does not fail the whole request.
 */
function nibblyAiAssembleSseResponse(string $raw): ?array {
    $content = '';
    $images = [];
    $usage = null;
    $finishReason = '';
    $lastObject = null;
    $sawChunk = false;
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, 'data:')) {
            continue;
        }
        $payload = trim(substr($line, 5));
        if ($payload === '' || $payload === '[DONE]') {
            continue;
        }
        $chunk = json_decode($payload, true);
        if (!is_array($chunk)) {
            continue;
        }
        $lastObject = $chunk;
        $choice = $chunk['choices'][0] ?? null;
        if (!is_array($choice)) {
            continue;
        }
        $sawChunk = true;
        $delta = is_array($choice['delta'] ?? null)
            ? $choice['delta']
            : (is_array($choice['message'] ?? null) ? $choice['message'] : []);
        if (is_string($delta['content'] ?? null)) {
            $content .= $delta['content'];
        }
        if (is_array($delta['images'] ?? null)) {
            $images = array_merge($images, $delta['images']);
        }
        if (!empty($choice['finish_reason'])) {
            $finishReason = (string)$choice['finish_reason'];
        }
        if (is_array($chunk['usage'] ?? null)) {
            $usage = $chunk['usage'];
        }
    }
    if (!$sawChunk) {
        // Some providers stream one complete JSON object in a single data: line.
        if (is_array($lastObject) && (is_array($lastObject['choices'] ?? null) || is_array($lastObject['data'] ?? null))) {
            return $lastObject;
        }
        return null;
    }
    if ($content === '' && !$images) {
        return null;
    }
    $message = ['role' => 'assistant', 'content' => $content];
    if ($images) {
        $message['images'] = $images;
    }
    return [
        'object' => 'chat.completion',
        'choices' => [[
            'message' => $message,
            'finish_reason' => $finishReason !== '' ? $finishReason : 'stop'
        ]],
        'usage' => is_array($usage) ? $usage : []
    ];
}

function nibblyAiCurlPost(string $url, array $headers, $postFields, int $timeout): array {
    $attempts = 0;
    $lastError = '';
    $lastPartial = '';
    do {
        $attempts++;
        $buffer = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FRESH_CONNECT => $attempts > 1,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer): int {
                $buffer .= $chunk;
                return strlen($chunk);
            }
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
            return [$buffer, $status];
        }
        $lastPartial = $buffer;
    } while ($attempts < 2 && nibblyAiShouldRetryCurlError($lastError, $downloadedBytes));

    if ($lastPartial !== '') {
        nibblyAiAudit('provider-curl-partial', false, [
            'message' => $lastError,
            'partial' => substr($lastPartial, 0, 1000)
        ]);
    }
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

function nibblyAiNormalizeKieImageModel(string $model): string {
    $aliases = [
        'gpt-image-2-text-to-image' => 'gpt-image-2',
        'gpt-image-2-image-to-image' => 'gpt-image-2',
        'seedream/5-pro-text-to-image' => 'seedream-5-0-pro',
        'seedream/5-pro-image-to-image' => 'seedream-5-0-pro'
    ];
    $model = $aliases[trim($model)] ?? trim($model);
    return in_array($model, ['gpt-image-2', 'nano-banana-2', 'seedream-5-0-pro'], true) ? $model : 'gpt-image-2';
}

function nibblyAiGenerateKieImages(array $settings, array $options): array {
    $count = nibblyAiClampInt($options['count'] ?? 1, 1, 10);
    $model = nibblyAiNormalizeKieImageModel((string)($options['model'] ?? ''));
    $baseUrl = rtrim((string)($settings['baseUrl'] ?? 'https://api.kie.ai'), '/');
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $key = trim((string)($settings['apiKey'] ?? ''));
    if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
    $references = [];
    foreach (array_slice((array)($options['referencePaths'] ?? []), 0, 10) as $path) $references[] = nibblyAiKieUploadReference($settings, (string)$path);
    $tasks = [];
    for ($index = 0; $index < $count; $index++) {
        $taskModel = $model;
        $input = ['prompt' => substr((string)($options['prompt'] ?? ''), 0, 8000), 'aspect_ratio' => (string)($options['aspectRatio'] ?? 'auto')];
        if ($model === 'gpt-image-2') {
            $taskModel = $references ? 'gpt-image-2-image-to-image' : 'gpt-image-2-text-to-image';
            if ($references) $input['input_urls'] = $references;
        } elseif ($model === 'nano-banana-2') {
            $input['resolution'] = nibblyAiKieResolution($options['imageScale'] ?? 2048);
            $input['output_format'] = nibblyAiKieProviderOutputFormat($options, $model);
            $input['image_input'] = $references;
        } else {
            $taskModel = $references ? 'seedream/5-pro-image-to-image' : 'seedream/5-pro-text-to-image';
            if ($references) $input['image_urls'] = $references;
            $input['quality'] = (string)($options['quality'] ?? '') === 'high' ? 'high' : 'basic';
            $input['output_format'] = nibblyAiKieProviderOutputFormat($options, $model);
            $input['nsfw_checker'] = false;
        }
        $response = nibblyAiExecuteJsonRequest($settings, $baseUrl . '/api/v1/jobs/createTask', $headers, ['model' => $taskModel, 'input' => $input]);
        $taskId = trim((string)($response['data']['taskId'] ?? ''));
        if ($taskId === '') throw new RuntimeException((string)($response['msg'] ?? 'Kie.ai did not return a task ID.'));
        $tasks[$taskId] = true;
    }
    $deadline = microtime(true) + max(300, nibblyAiRequestTimeout($settings));
    $urls = []; $failures = [];
    while ($tasks && microtime(true) < $deadline) {
        foreach (array_keys($tasks) as $taskId) {
            $record = nibblyAiKieGetJson($settings, $baseUrl . '/api/v1/jobs/recordInfo?taskId=' . rawurlencode($taskId), $headers);
            $data = is_array($record['data'] ?? null) ? $record['data'] : [];
            $state = strtolower((string)($data['state'] ?? 'waiting'));
            if ($state === 'success') {
                $result = $data['resultJson'] ?? [];
                if (is_string($result)) $result = json_decode($result, true);
                foreach ((array)(is_array($result) ? ($result['resultUrls'] ?? $result['result_urls'] ?? []) : []) as $url) if (filter_var($url, FILTER_VALIDATE_URL)) $urls[] = $url;
                unset($tasks[$taskId]);
            } elseif (in_array($state, ['fail', 'failed', 'error'], true)) {
                $failures[] = (string)($data['failMsg'] ?? $data['errorMessage'] ?? 'Kie.ai image generation failed.');
                unset($tasks[$taskId]);
            }
        }
        if ($tasks) usleep(2000000);
    }
    if ($tasks) throw new RuntimeException('Kie.ai image generation timed out. The provider jobs may still be running.');
    if (!$urls) throw new RuntimeException($failures[0] ?? 'Kie.ai returned no usable image.');
    $paths = [];
    foreach (array_slice($urls, 0, $count) as $url) {
        $paths[] = nibblyAiStoreGeneratedImage(nibblyAiDownloadProviderImage((string)$url), (string)($options['filenameHint'] ?? 'ai-image'), (string)($options['size'] ?? 'auto'), count($paths) + 1, (string)($options['outputFormat'] ?? ''), (int)($options['outputCompression'] ?? 75));
    }
    return [$paths, []];
}

function nibblyAiKieResolution($scale): string { $scale = (int)$scale; return $scale >= 3072 ? '4K' : ($scale >= 1536 ? '2K' : '1K'); }
function nibblyAiKieProviderOutputFormat(array $options, string $model): string {
    $format = strtolower(trim((string)($options['outputFormat'] ?? 'webp')));
    if ($format === 'webp') return 'png';
    return $format === 'png' ? 'png' : (nibblyAiNormalizeKieImageModel($model) === 'seedream-5-0-pro' ? 'jpeg' : 'jpg');
}
function nibblyAiKieUploadReference(array $settings, string $path): string {
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $key = trim((string)($settings['apiKey'] ?? ''));
    if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
    $response = nibblyAiExecuteJsonRequest($settings, 'https://kieai.redpandaai.co/api/file-base64-upload', $headers, ['base64Data' => nibblyAiReferenceImageDataUrl($path), 'uploadPath' => 'nibbly-references', 'fileName' => 'reference-' . bin2hex(random_bytes(6)) . '.png']);
    $url = trim((string)($response['data']['fileUrl'] ?? $response['data']['downloadUrl'] ?? ''));
    if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('Kie.ai could not upload the reference image.');
    return $url;
}
function nibblyAiKieGetJson(array $settings, string $url, array $headers): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Kie.ai requires cURL support.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => min(30, nibblyAiRequestTimeout($settings))]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new RuntimeException($error ?: 'Kie.ai task request failed.');
    $data = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) throw new RuntimeException((string)($data['msg'] ?? $data['message'] ?? 'Kie.ai returned invalid task data.'));
    return $data;
}

function nibblyAiGenerateOpenRouterImages(array $settings, array $options): array {
    $count = nibblyAiClampInt($options['count'] ?? 1, 1, 10);
    $settings['limits']['requestTimeoutSeconds'] = max(480, nibblyAiRequestTimeout($settings));
    $paths = [];
    $revisedPrompts = [];

    for ($i = 0; $i < $count; $i++) {
        try {
            $requestBody = nibblyAiBuildOpenRouterImageBody($options);
            $refPartCount = 0;
            foreach (($requestBody['messages'][0]['content'] ?? []) as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'image_url') {
                    $refPartCount++;
                }
            }
            nibblyAiAudit('openrouter-image-request', true, [
                'model' => (string)($requestBody['model'] ?? ''),
                'referencePathsIn' => count($options['referencePaths'] ?? []),
                'imageContentParts' => $refPartCount,
                'modalities' => $requestBody['modalities'] ?? null,
                'aspectRatioIn' => (string)($options['aspectRatio'] ?? ''),
                'sizeIn' => (string)($options['size'] ?? ''),
                'imageConfig' => $requestBody['image_config'] ?? null
            ]);
            $response = nibblyAiProviderRequest($settings, '/chat/completions', $requestBody);
            [$payloads, $texts] = nibblyAiExtractOpenRouterImagePayloads($response);
            foreach ($texts as $text) {
                if ($text !== '') {
                    $revisedPrompts[] = $text;
                }
            }
            foreach ($payloads as $payload) {
                $binary = nibblyAiProviderImagePayloadToBinary($payload);
                $paths[] = nibblyAiStoreGeneratedImage($binary, (string)($options['filenameHint'] ?? 'ai-image'), (string)($options['size'] ?? 'auto'), count($paths) + 1, (string)($options['outputFormat'] ?? ''), (int)($options['outputCompression'] ?? 70));
                if (count($paths) >= $count) {
                    break 2;
                }
            }
        } catch (Throwable $e) {
            if (!$paths) {
                throw $e;
            }
            nibblyAiAudit('image-partial', false, [
                'requested' => $count,
                'created' => count($paths),
                'message' => $e->getMessage()
            ]);
            break;
        }
    }

    if (!$paths) {
        throw new RuntimeException('OpenRouter returned no usable image data.');
    }

    return [$paths, $revisedPrompts];
}

function nibblyAiBuildOpenRouterImageBody(array $options): array {
    $model = nibblyAiNormalizeOpenRouterImageModel((string)$options['model']);
    $imageConfig = nibblyAiOpenRouterImageConfig(
        (string)($options['size'] ?? 'auto'),
        (string)($options['aspectRatio'] ?? 'auto'),
        $options['imageScale'] ?? null,
        $model
    );
    $prompt = substr((string)($options['prompt'] ?? ''), 0, 8000);
    $hasReference = !empty($options['referencePaths']);
    // image_config.aspect_ratio is the structured control, but image-capable
    // models (e.g. Gemini) tend to copy a reference image's dimensions and
    // ignore it. Reinforce the requested ratio in the prompt and, when a
    // reference is present, explicitly tell the model to override the
    // reference's framing so the user's selected ratio wins.
    if (!empty($imageConfig['aspect_ratio'])) {
        if ($hasReference) {
            $prompt .= "\n\nOutput the final image in a " . $imageConfig['aspect_ratio']
                . " aspect ratio, recomposing the scene to fill that frame. Use the reference image only for subject, style, and content — do not copy its aspect ratio or framing.";
        } elseif ($imageConfig['aspect_ratio'] !== '1:1') {
            $prompt .= "\n\nUse a " . $imageConfig['aspect_ratio'] . " aspect ratio for the output image.";
        }
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
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $content]
        ],
        'modalities' => ['image', 'text'],
        'stream' => false
    ];

    if ($imageConfig) {
        $body['image_config'] = $imageConfig;
    }

    return $body;
}

function nibblyAiOpenRouterImageConfig(string $size, string $aspectRatio = 'auto', $imageScale = null, string $model = ''): array {
    $config = [];
    $aspectRatio = nibblyAiCleanAspectRatio($aspectRatio);
    $maxImageScale = nibblyAiOpenRouterImageModelMaxScale($model);
    if ($aspectRatio !== 'auto') {
        [$rw, $rh] = array_map('intval', explode(':', $aspectRatio, 2));
        $config['aspect_ratio'] = nibblyAiNearestOpenRouterAspectRatio($rw, $rh);
        $scale = nibblyAiClampInt($imageScale ?? 2048, 1024, $maxImageScale);
        $config['image_size'] = $scale <= 1024 ? '1K' : ($scale <= 2048 ? '2K' : '4K');
        return $config;
    }
    if (preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
        $width = max(1, (int)$m[1]);
        $height = max(1, (int)$m[2]);
        $gcd = nibblyAiGcd($width, $height);
        $config['aspect_ratio'] = nibblyAiNearestOpenRouterAspectRatio((int)($width / $gcd), (int)($height / $gcd));
        $longEdge = min(max($width, $height), $maxImageScale);
        $config['image_size'] = $longEdge <= 1024 ? '1K' : ($longEdge <= 2048 ? '2K' : '4K');
    }
    return $config;
}

function nibblyAiOpenRouterImageModelMaxScale(string $model): int {
    $model = nibblyAiNormalizeOpenRouterImageModel($model);
    if (preg_match('/(^|\/)gpt-5\.4-image-2(?:$|-)|^gpt-image-2(?:$|-)/i', $model)) {
        return 2048;
    }
    return 3840;
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
    return nibblyAiClampInt($limits['requestTimeoutSeconds'] ?? 300, 5, 600);
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

/**
 * Re-encode raw image bytes into the requested type using GD. Returns null
 * (caller keeps the original) when GD or the target encoder is unavailable or
 * decoding fails. JPEG has no alpha, so transparency is flattened onto white.
 */
function nibblyAiConvertImageBinary(string $binary, int $targetType, int $quality): ?string {
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $encoders = [
        IMAGETYPE_JPEG => 'imagejpeg',
        IMAGETYPE_WEBP => 'imagewebp',
        IMAGETYPE_PNG => 'imagepng'
    ];
    if (!isset($encoders[$targetType]) || !function_exists($encoders[$targetType])) {
        return null;
    }
    $image = @imagecreatefromstring($binary);
    if ($image === false) {
        return null;
    }
    $quality = max(0, min(100, $quality));
    try {
        if ($targetType === IMAGETYPE_JPEG) {
            $width = imagesx($image);
            $height = imagesy($image);
            $flattened = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($flattened, 255, 255, 255);
            imagefilledrectangle($flattened, 0, 0, $width, $height, $white);
            imagecopy($flattened, $image, 0, 0, 0, 0, $width, $height);
            imagedestroy($image);
            $image = $flattened;
        } elseif (function_exists('imagealphablending')) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        ob_start();
        if ($targetType === IMAGETYPE_JPEG) {
            imagejpeg($image, null, $quality);
        } elseif ($targetType === IMAGETYPE_WEBP) {
            imagewebp($image, null, $quality);
        } else {
            // PNG quality is a 0-9 compression level (inverse of percentage).
            imagepng($image, null, (int)round((100 - $quality) / 100 * 9));
        }
        $out = ob_get_clean();
    } finally {
        if ($image instanceof GdImage) {
            imagedestroy($image);
        }
    }
    return is_string($out) && $out !== '' ? $out : null;
}

function nibblyAiStoreGeneratedImage(string $binary, string $hint, string $size = 'auto', int $index = 1, string $requestedFormat = '', int $quality = 70): string {
    if (strlen($binary) > 15 * 1024 * 1024) {
        throw new RuntimeException('Generated image is too large.');
    }
    $info = @getimagesizefromstring($binary);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Generated file is not a supported image.');
    }

    // Providers (e.g. OpenRouter) ignore the requested output format and often
    // return PNG. Convert to the format the admin selected so the saved file
    // matches the UI choice. Conversion only runs when the target differs and
    // GD supports it; otherwise the original bytes are kept.
    $requestedFormat = strtolower(trim($requestedFormat));
    $currentType = $info[2];
    $targetType = match ($requestedFormat) {
        'jpeg', 'jpg' => IMAGETYPE_JPEG,
        'webp' => IMAGETYPE_WEBP,
        'png' => IMAGETYPE_PNG,
        default => $currentType
    };
    if ($targetType !== $currentType) {
        $converted = nibblyAiConvertImageBinary($binary, $targetType, $quality);
        if ($converted !== null) {
            $binary = $converted;
            $info = @getimagesizefromstring($binary) ?: $info;
        }
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

/**
 * Inspect the actually-stored output images and return their real dimensions
 * and format, so history reflects what the provider delivered rather than what
 * was requested (providers may ignore the requested size/format, e.g.
 * OpenRouter returning PNG at provider-chosen pixel dimensions).
 */
function nibblyAiActualImageMeta(array $publicPaths): array {
    $root = dirname(__DIR__, 2);
    foreach ($publicPaths as $publicPath) {
        $publicPath = (string)$publicPath;
        if ($publicPath === '') {
            continue;
        }
        $file = $root . '/' . ltrim($publicPath, '/');
        if (!is_file($file)) {
            continue;
        }
        $info = @getimagesize($file);
        if ($info === false) {
            continue;
        }
        $format = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF => 'gif',
            default => strtolower((string)pathinfo($file, PATHINFO_EXTENSION))
        };
        return [
            'size' => (int)$info[0] . 'x' . (int)$info[1],
            'format' => $format
        ];
    }
    return [];
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
