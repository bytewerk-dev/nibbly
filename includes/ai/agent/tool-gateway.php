<?php
/**
 * Tool calling for the AI gateway (agent phase P1).
 *
 * nibblyAiChatWithTools() sends one model turn with tool definitions and returns
 * either requested tool calls or a final answer. It speaks the OpenAI-compatible
 * format (also OpenRouter and local servers) and the Anthropic Messages API, while
 * callers only see one internal message format:
 *
 *   {role: system|user|assistant|tool, content: string,
 *    toolCalls?: [{id, name, arguments: array}]   (assistant)
 *    toolCallId?: string, isError?: bool}          (tool)
 *
 * Assistant messages may carry opaque 'reasoningItems' (OpenAI Responses API, encrypted so nothing
 * is stored at the provider); they go back to the provider unchanged and are never shown.
 *
 * nibblyAiChat(), nibblyAiChatStream() and nibblyAiCleanMessages() are untouched.
 *
 * Accounting: every call is its own top-level request (ledger reservation). A call
 * nested inside another AI request would lose its usage, so it is refused.
 */

const NIBBLY_AGENT_MESSAGE_CHARS = 12000;
const NIBBLY_AGENT_MAX_MESSAGES = 60;
const NIBBLY_AGENT_MAX_TOOLS = 40;
const NIBBLY_AGENT_MAX_ARGUMENT_BYTES = 200000;
const NIBBLY_AGENT_MAX_REASONING_BYTES = 200000;

/** Catalog key of the provider behind the saved settings. */
function nibblyAiProviderKey(array $settings): string {
    if (nibblyAiIsKieSettings($settings)) return 'kie';
    if (nibblyAiIsAnthropicSettings($settings)) return 'anthropic';
    if (nibblyAiIsOpenRouterSettings($settings)) return 'openrouter';
    return 'openai-compatible';
}

function nibblyAiSupportsToolCalling(array $settings): bool {
    return !empty(nibblyAiModelCatalog()[nibblyAiProviderKey($settings)]['capabilities']['toolCalling']);
}

// ---------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------

function nibblyAgentValidName(string $name): bool {
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1;
}

function nibblyAgentNewCallId(): string {
    return 'call_' . bin2hex(random_bytes(8));
}

/** Encrypted reasoning items of the Responses API, passed through; anything else is dropped. */
function nibblyAgentCleanReasoningItems($items): array {
    if (!is_array($items) || !array_is_list($items)) return [];
    $clean = [];
    foreach ($items as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'reasoning' || !is_string($item['encrypted_content'] ?? null) || $item['encrypted_content'] === '') continue;
        $entry = ['type' => 'reasoning', 'encrypted_content' => $item['encrypted_content'], 'summary' => is_array($item['summary'] ?? null) ? array_values($item['summary']) : []];
        if (is_string($item['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $item['id'])) $entry['id'] = $item['id'];
        $clean[] = $entry;
    }
    return strlen((string)json_encode($clean)) > NIBBLY_AGENT_MAX_REASONING_BYTES ? [] : $clean;
}

/**
 * Validate and normalise an agent transcript.
 *
 * Keeps tool roles, caps every message at about 12k characters and enforces the
 * pairing providers demand: each assistant tool call is answered by exactly one
 * tool message directly after it, and a tool message always belongs to a call.
 * Trimming to $maxMessages (system messages and the first user message stay) drops
 * whole exchanges only, so a tool result never loses its tool call or vice versa.
 *
 * @throws InvalidArgumentException when the history is inconsistent
 */
function nibblyAgentCleanMessages(array $messages, int $maxMessages = NIBBLY_AGENT_MAX_MESSAGES): array {
    $clean = [];
    foreach ($messages as $message) {
        if (!is_array($message)) continue;
        $role = (string)($message['role'] ?? 'user');
        if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) {
            throw new InvalidArgumentException("Unknown message role '{$role}'.");
        }
        $content = (string)($message['content'] ?? '');
        if ($role !== 'tool') $content = trim($content);
        if (mb_strlen($content, 'UTF-8') > NIBBLY_AGENT_MESSAGE_CHARS) {
            $content = nibblyAiUtf8Prefix($content, NIBBLY_AGENT_MESSAGE_CHARS) . "\n[truncated]";
        }
        $entry = ['role' => $role, 'content' => $content];

        if ($role === 'assistant' && !empty($message['toolCalls'])) {
            $calls = [];
            foreach ((array)$message['toolCalls'] as $call) {
                $id = is_array($call) ? (string)($call['id'] ?? '') : '';
                $name = is_array($call) ? (string)($call['name'] ?? '') : '';
                $arguments = is_array($call) && is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
                if (!nibblyAgentValidName($id) || !nibblyAgentValidName($name)) {
                    throw new InvalidArgumentException('A tool call needs a valid id and name.');
                }
                $calls[] = ['id' => $id, 'name' => $name, 'arguments' => $arguments];
            }
            $entry['toolCalls'] = $calls;
            $reasoning = nibblyAgentCleanReasoningItems($message['reasoningItems'] ?? []);
            if ($reasoning) $entry['reasoningItems'] = $reasoning;
        } elseif ($role === 'tool') {
            $id = (string)($message['toolCallId'] ?? '');
            if (!nibblyAgentValidName($id)) {
                throw new InvalidArgumentException('A tool message needs the id of its tool call.');
            }
            $entry['toolCallId'] = $id;
            $entry['isError'] = !empty($message['isError']);
            if ($content === '') $entry['content'] = '(empty)';
        } elseif ($content === '') {
            continue;
        }
        $clean[] = $entry;
    }

    // Pairing: group each assistant tool call exchange.
    $groups = [];
    $pending = [];
    $seen = [];
    foreach ($clean as $entry) {
        if ($pending) {
            if ($entry['role'] !== 'tool' || !isset($pending[$entry['toolCallId']])) {
                throw new InvalidArgumentException('Tool call history is inconsistent: a tool call has no result.');
            }
            unset($pending[$entry['toolCallId']]);
            $groups[count($groups) - 1][] = $entry;
            continue;
        }
        if ($entry['role'] === 'tool') {
            throw new InvalidArgumentException('Tool call history is inconsistent: a tool result has no tool call.');
        }
        if (!empty($entry['toolCalls'])) {
            foreach ($entry['toolCalls'] as $call) {
                if (isset($seen[$call['id']])) {
                    throw new InvalidArgumentException('Tool call history is inconsistent: duplicate tool call id.');
                }
                $seen[$call['id']] = true;
                $pending[$call['id']] = true;
            }
        }
        $groups[] = [$entry];
    }
    if ($pending) {
        throw new InvalidArgumentException('Tool call history is inconsistent: a tool call has no result.');
    }

    // Whole-exchange trimming. System messages and the first user message are pinned.
    $pinned = [];
    $firstUser = false;
    foreach ($groups as $index => $group) {
        if ($group[0]['role'] === 'system' || (!$firstUser && $group[0]['role'] === 'user')) {
            $pinned[$index] = true;
            if ($group[0]['role'] === 'user') $firstUser = true;
        }
    }
    $count = static fn(array $keep): int => array_sum(array_map(static fn($i) => count($groups[$i]), array_keys($keep)));
    $keep = array_fill_keys(array_keys($groups), true);
    if ($maxMessages > 0) {
        foreach (array_keys($groups) as $index) {
            if ($count($keep) <= $maxMessages) break;
            if (!isset($pinned[$index])) unset($keep[$index]);
        }
    }
    // Nothing but a user turn may open the conversation (after any system messages).
    $result = [];
    $opened = false;
    foreach (array_keys($keep) as $index) {
        $role = $groups[$index][0]['role'];
        if (!$opened && $role !== 'system') {
            if ($role !== 'user') continue;
            $opened = true;
        }
        foreach ($groups[$index] as $entry) $result[] = $entry;
    }
    return $result;
}

// ---------------------------------------------------------------------------
// Tool definitions and argument validation
// ---------------------------------------------------------------------------

/** @return array<string,array{name:string,description:string,parameters:array}> keyed by name */
function nibblyAgentNormalizeTools(array $tools): array {
    if (count($tools) > NIBBLY_AGENT_MAX_TOOLS) {
        throw new InvalidArgumentException('Too many tools.');
    }
    $normalized = [];
    foreach ($tools as $tool) {
        $name = is_array($tool) ? (string)($tool['name'] ?? '') : '';
        if (!nibblyAgentValidName($name)) {
            throw new InvalidArgumentException('A tool needs a name of letters, digits, "_" or "-" (max. 64).');
        }
        if (isset($normalized[$name])) {
            throw new InvalidArgumentException("Duplicate tool '{$name}'.");
        }
        $parameters = is_array($tool['parameters'] ?? null) ? $tool['parameters'] : [];
        $parameters['type'] = 'object';
        $normalized[$name] = [
            'name' => $name,
            'description' => nibblyAiUtf8Prefix(trim((string)($tool['description'] ?? '')), 1000),
            'parameters' => $parameters
        ];
    }
    return $normalized;
}

/** JSON Schema for the provider: empty "properties" must encode as {} and "required" must not be []. */
function nibblyAgentProviderSchema(array $schema) {
    if (array_key_exists('required', $schema) && !$schema['required']) {
        unset($schema['required']);
    }
    if (array_key_exists('properties', $schema)) {
        $properties = [];
        foreach ((array)$schema['properties'] as $key => $child) {
            $properties[$key] = is_array($child) ? nibblyAgentProviderSchema($child) : $child;
        }
        $schema['properties'] = $properties ?: new stdClass();
    } elseif (($schema['type'] ?? '') === 'object') {
        $schema['properties'] = new stdClass();
    }
    if (is_array($schema['items'] ?? null)) {
        $schema['items'] = nibblyAgentProviderSchema($schema['items']);
    }
    foreach (['anyOf', 'oneOf', 'allOf'] as $key) {
        if (is_array($schema[$key] ?? null)) {
            $schema[$key] = array_map(static fn($child) => is_array($child) ? nibblyAgentProviderSchema($child) : $child, $schema[$key]);
        }
    }
    return $schema;
}

function nibblyAgentIsObjectValue($value): bool {
    return is_array($value) && ($value === [] || !array_is_list($value));
}

/**
 * Small JSON Schema validator for tool arguments. Supports type, required,
 * properties, additionalProperties:false, enum, minLength/maxLength, pattern,
 * minimum/maximum, items and minItems/maxItems.
 *
 * @return string[] one message per violation (empty = valid)
 */
function nibblyAgentValidateSchema(array $schema, $value, string $path = 'arguments'): array {
    $errors = [];
    $type = $schema['type'] ?? null;
    $types = is_array($type) ? $type : ($type === null ? [] : [$type]);
    if ($types) {
        $matches = false;
        foreach ($types as $candidate) {
            $matches = $matches || match ($candidate) {
                'string' => is_string($value),
                'integer' => is_int($value) || (is_float($value) && floor($value) === $value && is_finite($value)),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'array' => is_array($value) && array_is_list($value),
                'object' => nibblyAgentIsObjectValue($value),
                default => false
            };
        }
        if (!$matches) {
            return ["{$path} must be of type " . implode('|', $types) . '.'];
        }
    }
    if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
        $errors[] = "{$path} must be one of: " . implode(', ', array_map(static fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE), array_slice($schema['enum'], 0, 12))) . '.';
    }
    if (is_string($value)) {
        $length = mb_strlen($value, 'UTF-8');
        if (isset($schema['maxLength']) && $length > (int)$schema['maxLength']) $errors[] = "{$path} is longer than {$schema['maxLength']} characters.";
        if (isset($schema['minLength']) && $length < (int)$schema['minLength']) $errors[] = "{$path} is shorter than {$schema['minLength']} characters.";
        if (isset($schema['pattern']) && @preg_match('~' . str_replace('~', '\~', (string)$schema['pattern']) . '~u', $value) !== 1) {
            $errors[] = "{$path} does not match the required pattern.";
        }
    }
    if ((is_int($value) || is_float($value)) && !is_bool($value)) {
        if (isset($schema['minimum']) && $value < $schema['minimum']) $errors[] = "{$path} must be at least {$schema['minimum']}.";
        if (isset($schema['maximum']) && $value > $schema['maximum']) $errors[] = "{$path} must be at most {$schema['maximum']}.";
    }
    if (is_array($value) && array_is_list($value) && $value !== []) {
        if (isset($schema['maxItems']) && count($value) > (int)$schema['maxItems']) $errors[] = "{$path} has more than {$schema['maxItems']} items.";
        if (is_array($schema['items'] ?? null)) {
            foreach ($value as $index => $item) {
                $errors = array_merge($errors, nibblyAgentValidateSchema($schema['items'], $item, "{$path}[{$index}]"));
            }
        }
    }
    if (is_array($value) && array_is_list($value) && isset($schema['minItems']) && count($value) < (int)$schema['minItems']) {
        $errors[] = "{$path} needs at least {$schema['minItems']} items.";
    }
    if (nibblyAgentIsObjectValue($value)) {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ((array)($schema['required'] ?? []) as $required) {
            if (!array_key_exists($required, $value)) $errors[] = "{$path}.{$required} is required.";
        }
        foreach ($value as $key => $item) {
            if (isset($properties[$key]) && is_array($properties[$key])) {
                $errors = array_merge($errors, nibblyAgentValidateSchema($properties[$key], $item, "{$path}.{$key}"));
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = "{$path}.{$key} is not an allowed argument.";
            }
        }
    }
    return $errors;
}

/**
 * Turn the provider's raw tool calls into {id, name, arguments, error}. Ids the
 * provider omitted or that are unusable get a generated one; invalid JSON, unknown
 * tools and schema violations become a per-call error the loop returns to the model.
 *
 * @param array<int,array{id:string,name:string,arguments:mixed}> $raw arguments: JSON string or decoded array
 */
function nibblyAgentFinalizeToolCalls(array $raw, array $tools): array {
    $calls = [];
    $usedIds = [];
    foreach ($raw as $call) {
        $id = (string)($call['id'] ?? '');
        if (!nibblyAgentValidName($id) || isset($usedIds[$id])) $id = nibblyAgentNewCallId();
        $usedIds[$id] = true;
        $name = (string)($call['name'] ?? '');
        $arguments = [];
        $error = null;

        $rawArguments = $call['arguments'] ?? [];
        if (is_string($rawArguments)) {
            $trimmed = trim($rawArguments);
            if (strlen($trimmed) > NIBBLY_AGENT_MAX_ARGUMENT_BYTES) {
                $error = 'Arguments are too large.';
            } elseif ($trimmed === '') {
                $rawArguments = [];
            } else {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $error = 'Arguments are not valid JSON (' . json_last_error_msg() . ').';
                } else {
                    $rawArguments = $decoded;
                }
            }
        }
        if ($error === null && !nibblyAgentIsObjectValue($rawArguments)) {
            $error = 'Arguments must be a JSON object.';
        }
        if ($error === null) {
            $arguments = $rawArguments;
            if (!nibblyAgentValidName($name) || !isset($tools[$name])) {
                $error = "Unknown tool '" . nibblyAiUtf8Prefix($name, 64) . "'. Available tools: " . implode(', ', array_keys($tools)) . '.';
            } else {
                $violations = nibblyAgentValidateSchema($tools[$name]['parameters'], $arguments);
                if ($violations) $error = 'Invalid arguments: ' . implode(' ', array_slice($violations, 0, 5));
            }
        }
        $calls[] = ['id' => $id, 'name' => nibblyAgentValidName($name) ? $name : 'invalid', 'arguments' => $error === null ? $arguments : [], 'error' => $error];
    }
    return $calls;
}

// ---------------------------------------------------------------------------
// Provider mappings
// ---------------------------------------------------------------------------

function nibblyAgentOpenAiMessages(array $messages): array {
    $mapped = [];
    foreach ($messages as $message) {
        if ($message['role'] === 'tool') {
            $mapped[] = ['role' => 'tool', 'tool_call_id' => $message['toolCallId'], 'content' => $message['content']];
        } elseif ($message['role'] === 'assistant' && !empty($message['toolCalls'])) {
            $mapped[] = [
                'role' => 'assistant',
                'content' => $message['content'] !== '' ? $message['content'] : null,
                'tool_calls' => array_map(static fn($call) => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => json_encode($call['arguments'] ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
                ], $message['toolCalls'])
            ];
        } else {
            $mapped[] = ['role' => $message['role'], 'content' => $message['content']];
        }
    }
    return $mapped;
}

function nibblyAgentOpenAiBody(array $messages, array $tools, string $model, int $maxOutput, float $temperature): array {
    return [
        'model' => $model,
        'messages' => nibblyAgentOpenAiMessages($messages),
        'tools' => array_values(array_map(static fn($tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => nibblyAgentProviderSchema($tool['parameters'])
            ]
        ], $tools)),
        'tool_choice' => 'auto',
        'max_tokens' => $maxOutput,
        'temperature' => $temperature,
        'stream' => false
    ];
}

/** @return array{text:string,calls:array,stopReason:string,inputTokens:?int,outputTokens:?int} */
function nibblyAgentParseOpenAiResponse(array $response): array {
    $choice = is_array($response['choices'][0] ?? null) ? $response['choices'][0] : [];
    $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
    $raw = [];
    foreach ((is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : []) as $call) {
        if (!is_array($call)) continue;
        $function = is_array($call['function'] ?? null) ? $call['function'] : [];
        $raw[] = ['id' => (string)($call['id'] ?? ''), 'name' => (string)($function['name'] ?? ''), 'arguments' => $function['arguments'] ?? ''];
    }
    $finish = (string)($choice['finish_reason'] ?? '');
    return [
        'text' => trim((string)($message['content'] ?? '')),
        'calls' => $raw,
        'stopReason' => $finish === 'length' ? 'length' : ($raw ? 'tool_calls' : 'stop'),
        'inputTokens' => isset($response['usage']['prompt_tokens']) ? (int)$response['usage']['prompt_tokens'] : null,
        'outputTokens' => isset($response['usage']['completion_tokens']) ? (int)$response['usage']['completion_tokens'] : null,
        'reasoning' => !empty($message['reasoning'])
    ];
}

/**
 * OpenAI itself is spoken to through the Responses API (reasoning models cannot combine function tools
 * with reasoning on chat/completions). Other OpenAI-compatible hosts use chat/completions unless a
 * rejected request taught us that the model needs /responses.
 */
function nibblyAgentUsesResponsesApi(array $settings, string $model): bool {
    if (nibblyAiProviderKey($settings) !== 'openai-compatible') return false;
    if (strtolower((string)parse_url((string)($settings['baseUrl'] ?? ''), PHP_URL_HOST)) === 'api.openai.com') return true;
    return (nibblyAiKnownQuirks($settings, $model)['api'] ?? '') === 'responses';
}

/** @return array{0:string,1:array} instructions and input items */
function nibblyAgentResponsesInput(array $messages): array {
    $instructions = [];
    $input = [];
    foreach ($messages as $message) {
        if ($message['role'] === 'system') {
            $instructions[] = $message['content'];
        } elseif ($message['role'] === 'tool') {
            $input[] = ['type' => 'function_call_output', 'call_id' => $message['toolCallId'], 'output' => $message['content']];
        } elseif ($message['role'] === 'assistant') {
            foreach ($message['reasoningItems'] ?? [] as $item) $input[] = $item;
            if ($message['content'] !== '') $input[] = ['role' => 'assistant', 'content' => $message['content']];
            foreach ($message['toolCalls'] ?? [] as $call) {
                $input[] = [
                    'type' => 'function_call',
                    'call_id' => $call['id'],
                    'name' => $call['name'],
                    'arguments' => json_encode($call['arguments'] ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ];
            }
        } else {
            $input[] = ['role' => 'user', 'content' => $message['content']];
        }
    }
    return [implode("\n\n", $instructions), $input];
}

function nibblyAgentResponsesBody(array $messages, array $tools, string $model, int $maxOutput, float $temperature): array {
    [$instructions, $input] = nibblyAgentResponsesInput($messages);
    $body = ['model' => $model, 'input' => $input];
    if ($instructions !== '') $body['instructions'] = $instructions;
    $body += [
        'tools' => array_values(array_map(static fn($tool) => [
            'type' => 'function',
            'name' => $tool['name'],
            'description' => $tool['description'],
            'parameters' => nibblyAgentProviderSchema($tool['parameters']),
            'strict' => false
        ], $tools)),
        'tool_choice' => 'auto',
        'max_output_tokens' => $maxOutput,
        'temperature' => $temperature,
        // Nothing is kept at the provider; the reasoning comes back encrypted and goes into the next turn.
        'store' => false,
        'include' => ['reasoning.encrypted_content'],
        'stream' => false
    ];
    return $body;
}

function nibblyAgentParseResponsesResponse(array $response): array {
    $text = '';
    $raw = [];
    $reasoning = [];
    $sawReasoning = false;
    foreach ((is_array($response['output'] ?? null) ? $response['output'] : []) as $item) {
        if (!is_array($item)) continue;
        $type = (string)($item['type'] ?? '');
        if ($type === 'message') {
            foreach ((is_array($item['content'] ?? null) ? $item['content'] : []) as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'output_text') $text .= (string)($part['text'] ?? '');
            }
        } elseif ($type === 'function_call') {
            $raw[] = ['id' => (string)($item['call_id'] ?? ''), 'name' => (string)($item['name'] ?? ''), 'arguments' => $item['arguments'] ?? ''];
        } elseif ($type === 'reasoning') {
            $sawReasoning = true;
            $reasoning[] = $item;
        }
    }
    $incomplete = ($response['status'] ?? '') === 'incomplete' && ($response['incomplete_details']['reason'] ?? '') === 'max_output_tokens';
    return [
        'text' => trim($text),
        'calls' => $raw,
        'stopReason' => $incomplete ? 'length' : ($raw ? 'tool_calls' : 'stop'),
        'inputTokens' => isset($response['usage']['input_tokens']) ? (int)$response['usage']['input_tokens'] : null,
        'outputTokens' => isset($response['usage']['output_tokens']) ? (int)$response['usage']['output_tokens'] : null,
        'reasoning' => $sawReasoning,
        'reasoningItems' => nibblyAgentCleanReasoningItems($reasoning)
    ];
}

function nibblyAgentAnthropicBody(array $messages, array $tools, string $model, int $maxOutput, float $temperature): array {
    $system = [];
    $mapped = [];
    foreach ($messages as $message) {
        $role = $message['role'];
        if ($role === 'system') {
            $system[] = $message['content'];
            continue;
        }
        $last = count($mapped) - 1;
        $lastIsBlocks = $last >= 0 && $mapped[$last]['role'] === 'user' && is_array($mapped[$last]['content']);
        if ($role === 'tool') {
            $block = ['type' => 'tool_result', 'tool_use_id' => $message['toolCallId'], 'content' => $message['content']];
            if (!empty($message['isError'])) $block['is_error'] = true;
            // Consecutive results share one user message, as the Messages API requires.
            if ($lastIsBlocks) {
                $mapped[$last]['content'][] = $block;
            } else {
                $mapped[] = ['role' => 'user', 'content' => [$block]];
            }
        } elseif ($role === 'assistant') {
            $blocks = [];
            if ($message['content'] !== '') $blocks[] = ['type' => 'text', 'text' => $message['content']];
            foreach ($message['toolCalls'] ?? [] as $call) {
                $blocks[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => $call['arguments'] ?: new stdClass()];
            }
            $mapped[] = ['role' => 'assistant', 'content' => $blocks];
        } else {
            if ($lastIsBlocks) {
                $mapped[$last]['content'][] = ['type' => 'text', 'text' => $message['content']];
            } else {
                $mapped[] = ['role' => 'user', 'content' => $message['content']];
            }
        }
    }
    while ($mapped && $mapped[0]['role'] !== 'user') {
        array_shift($mapped);
    }
    if (!$mapped) {
        throw new RuntimeException('No AI messages provided.');
    }
    $body = [
        'model' => $model,
        'max_tokens' => $maxOutput,
        'messages' => $mapped,
        'tools' => array_values(array_map(static fn($tool) => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'input_schema' => nibblyAgentProviderSchema($tool['parameters'])
        ], $tools)),
        'tool_choice' => ['type' => 'auto'],
        'temperature' => min(1.0, max(0.0, $temperature))
    ];
    if ($system) {
        $body['system'] = implode("\n\n", $system);
    }
    return $body;
}

function nibblyAgentParseAnthropicResponse(array $response): array {
    $text = '';
    $raw = [];
    foreach ((is_array($response['content'] ?? null) ? $response['content'] : []) as $block) {
        if (!is_array($block)) continue;
        $type = (string)($block['type'] ?? '');
        if ($type === 'text') {
            $text .= (string)($block['text'] ?? '');
        } elseif ($type === 'tool_use') {
            $raw[] = ['id' => (string)($block['id'] ?? ''), 'name' => (string)($block['name'] ?? ''), 'arguments' => $block['input'] ?? []];
        }
    }
    $stop = (string)($response['stop_reason'] ?? '');
    return [
        'text' => trim($text),
        'calls' => $raw,
        'stopReason' => $stop === 'max_tokens' ? 'length' : ($raw ? 'tool_calls' : 'stop'),
        'inputTokens' => isset($response['usage']['input_tokens']) ? (int)$response['usage']['input_tokens'] : null,
        'outputTokens' => isset($response['usage']['output_tokens']) ? (int)$response['usage']['output_tokens'] : null,
        'reasoning' => false
    ];
}

// ---------------------------------------------------------------------------
// One model turn
// ---------------------------------------------------------------------------

/**
 * One model turn with tools.
 *
 * Options: model, feature (default backendAssistant, '' = none), maxOutputTokens,
 * temperature, maxMessages, _requestId (ledger id, e.g. agent_{run}_{n}).
 *
 * @param array $tools [{name, description, parameters: JSON Schema object}]
 * @return array{kind:string,text:string,toolCalls:array,stopReason:string,usage:array,message:array,requestId:string}
 *         kind is 'tool_calls' or 'final'; message is the assistant message to append to the transcript;
 *         every tool call carries an 'error' (null or a text to hand back to the model).
 */
function nibblyAiChatWithTools(array $messages, array $tools, array $options = []): array {
    if (!empty($GLOBALS['nibblyAiRequestId'])) {
        throw new LogicException('nibblyAiChatWithTools() must be its own top-level AI request; nested calls would lose their usage.');
    }
    return nibblyAiWithRequest(fn() => nibblyAiChatWithToolsExecute($messages, $tools, $options), $options['_requestId'] ?? null);
}

function nibblyAiChatWithToolsExecute(array $messages, array $tools, array $options): array {
    $settings = nibblyAiLoadSettings(false);
    nibblyAiEnsureEnabled($settings);
    $feature = $options['feature'] ?? 'backendAssistant';
    if ($feature !== '') {
        nibblyAiEnsureFeature($settings, $feature);
    }
    if (!nibblyAiSupportsToolCalling($settings)) {
        throw new RuntimeException('The configured AI provider does not support tool calling.');
    }

    $tools = nibblyAgentNormalizeTools($tools);
    if (!$tools) {
        throw new InvalidArgumentException('At least one tool is required.');
    }
    $clean = nibblyAgentCleanMessages($messages, (int)($options['maxMessages'] ?? NIBBLY_AGENT_MAX_MESSAGES));
    if (!$clean) {
        throw new RuntimeException('No AI messages provided.');
    }

    $inputTokens = nibblyAiEstimateTokens((string)json_encode([$clean, $tools], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($inputTokens > (int)$settings['limits']['maxInputTokens']) {
        throw new RuntimeException('AI input is too long for the configured limit.');
    }
    $maxOutput = nibblyAiClampInt($options['maxOutputTokens'] ?? $settings['limits']['maxOutputTokens'], 16, (int)$settings['limits']['maxOutputTokens']);
    nibblyAiAssertWithinLimits($settings, 'text', nibblyAiEstimateTextCost($settings, $inputTokens, $maxOutput));

    $model = trim((string)($options['model'] ?? '')) ?: (string)$settings['chatModel'];
    $temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.3;
    $anthropic = nibblyAiProviderKey($settings) === 'anthropic';

    $started = microtime(true);
    if ($anthropic) {
        [$url, $headers] = nibblyAiAnthropicRequestTarget($settings);
        $response = nibblyAiExecuteJsonRequest($settings, $url, $headers, nibblyAgentAnthropicBody($clean, $tools, $model, $maxOutput, $temperature));
        $parsed = nibblyAgentParseAnthropicResponse($response);
    } else {
        $responsesApi = nibblyAgentUsesResponsesApi($settings, $model);
        if (!$responsesApi) {
            try {
                $response = nibblyAiProviderRequest($settings, '/chat/completions', nibblyAgentOpenAiBody($clean, $tools, $model, $maxOutput, $temperature));
                $parsed = nibblyAgentParseOpenAiResponse($response);
            } catch (NibblyAiProviderRejected $error) {
                // "use /v1/responses": the model does not take tools on chat/completions. Nothing was generated; ask again there.
                if (!in_array($error->status, [400, 404, 422], true) || !preg_match('~/v1/responses|/responses~i', $error->getMessage()) || nibblyAiProviderKey($settings) !== 'openai-compatible') {
                    throw $error;
                }
                $responsesApi = true;
                nibblyAiStoreQuirk($settings, $model, ['api' => 'responses']);
            }
        }
        if ($responsesApi) {
            $response = nibblyAiProviderRequest($settings, '/responses', nibblyAgentResponsesBody($clean, $tools, $model, $maxOutput, $temperature));
            $parsed = nibblyAgentParseResponsesResponse($response);
        }
    }

    $calls = nibblyAgentFinalizeToolCalls($parsed['calls'], $tools);
    if ($parsed['text'] === '' && !$calls) {
        if ($parsed['reasoning'] || $parsed['stopReason'] === 'length') {
            throw new RuntimeException('AI provider did not return a final answer before the output limit. Increase the max output tokens or use a non-reasoning model for this feature.');
        }
        throw new RuntimeException('AI provider returned an empty response.');
    }

    $actualInput = $parsed['inputTokens'] ?? $inputTokens;
    $actualOutput = $parsed['outputTokens'] ?? nibblyAiEstimateTokens($parsed['text'] . json_encode(array_column($calls, 'arguments')));
    $actualCost = nibblyAiEstimateTextCost($settings, $actualInput, $actualOutput);
    nibblyAiRecordUsage('text', $actualInput, $actualOutput, $actualCost);
    nibblyAiAudit('agent-turn', true, [
        'model' => $model,
        'provider' => nibblyAiProviderKey($settings),
        'inputTokens' => $actualInput,
        'outputTokens' => $actualOutput,
        'estimatedCostCents' => $actualCost,
        'stopReason' => $parsed['stopReason'],
        'tools' => array_column($calls, 'name'),
        'durationMs' => (int)round((microtime(true) - $started) * 1000)
    ]);

    $message = ['role' => 'assistant', 'content' => $parsed['text']];
    if ($calls) {
        $message['toolCalls'] = array_map(static fn($call) => ['id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments']], $calls);
        if (!empty($parsed['reasoningItems'])) $message['reasoningItems'] = $parsed['reasoningItems'];
    }
    return [
        'kind' => $calls ? 'tool_calls' : 'final',
        'text' => $parsed['text'],
        'toolCalls' => $calls,
        'stopReason' => $parsed['stopReason'],
        'usage' => ['inputTokens' => $actualInput, 'outputTokens' => $actualOutput, 'estimatedCostCents' => $actualCost],
        'message' => $message
    ];
}
