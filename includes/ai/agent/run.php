<?php
/**
 * Agent runs (agent phase P4).
 *
 * The loop runs on the server, driven by short step requests from the browser. A run lives
 * in content/ai-agent/runs/{id}.json together with its transcript; the browser never
 * sends or sees the transcript, so it cannot forge tool results. One step does one thing:
 * execute the pending tool calls, or make one model turn.
 *
 *   status: running → needs_input | awaiting_review | failed | cancelled
 *
 * Limits apply per round (the start, and every user reply): turns, tool calls, runtime.
 * The cost cap is cumulative for the whole run. Every model call is its own top-level
 * ledger request "agent_{run}_{n}" so its usage is always recorded.
 */

require_once __DIR__ . '/prompt.php';

const NIBBLY_AGENT_RUN_MAX_DEFERRALS = 4;

function nibblyAgentRunPath(string $id): string {
    return nibblyAgentDir() . '/runs/' . $id . '.json';
}

function nibblyAgentValidRunId($id): bool {
    return is_string($id) && preg_match('/^run_[a-f0-9]{16}$/', $id) === 1;
}

function nibblyAgentRunLoad(string $id): ?array {
    return nibblyAgentValidRunId($id) ? nibblyAgentReadJsonFile(nibblyAgentRunPath($id)) : null;
}

function nibblyAgentRunSave(array &$run): void {
    $run['updatedAt'] = date('c');
    if (!nibblyJsonAtomicWrite(nibblyAgentRunPath($run['id']), $run)) {
        throw new RuntimeException('Could not store the agent run.');
    }
}

function nibblyAgentCanSeeRun(array $run, array $actor): bool {
    return !empty($actor['isAdmin']) || (!empty($actor['userKey']) && hash_equals((string)$run['owner'], (string)$actor['userKey']));
}

function nibblyAgentRunEvent(array &$run, string $type, array $data = []): void {
    $run['eventSeq'] = (int)($run['eventSeq'] ?? 0) + 1;
    $run['events'][] = ['seq' => $run['eventSeq'], 'type' => $type, 'at' => date('c')] + $data;
    $run['events'] = array_slice($run['events'], -200);
}

function nibblyAgentRunFail(array &$run, string $code, string $message): void {
    $run['status'] = 'failed';
    $run['errorCode'] = $code;
    $run['error'] = mb_substr($message, 0, 300, 'UTF-8');
    $run['pending'] = [];
    nibblyAgentRunEvent($run, 'error', ['code' => $code, 'text' => $run['error']]);
    nibblyAgentAudit('agent-run-failed', false, ['run' => $run['id'], 'code' => $code]);
}

// ---------------------------------------------------------------------------
// Start, reply, cancel
// ---------------------------------------------------------------------------

/** Start a run for a goal the user typed. The caller checks rights and rate limits. */
function nibblyAgentRunStart(array $actor, string $goal, string $changesetId = ''): array {
    $goal = trim($goal);
    if (mb_strlen($goal, 'UTF-8') < 3) throw new InvalidArgumentException('Please describe what the agent should do.');
    $goal = mb_substr($goal, 0, 2000, 'UTF-8');
    $message = $goal;
    if ($changesetId !== '') {
        // Images the user dropped into the chat before sending were registered in a change set of their own.
        $attached = nibblyAgentChangesetLoad($changesetId);
        if ($attached === null || (string)$attached['owner'] !== (string)$actor['userKey'] || $attached['status'] !== 'staging' || $attached['runId'] !== '') {
            throw new InvalidArgumentException('The attached images are no longer available. Attach them again.');
        }
        $names = [];
        foreach ($attached['media'] as $ref => $media) {
            $names[] = $ref . ' (' . ($media['name'] !== '' ? $media['name'] . ', ' : '') . $media['width'] . '×' . $media['height'] . ')';
        }
        if ($names) $message .= "\n\n[Attached images: " . implode('; ', $names) . '. Use them with their mediaRef.]';
    }
    $run = [
        'id' => 'run_' . bin2hex(random_bytes(8)),
        'owner' => (string)$actor['userKey'],
        'status' => 'running',
        'createdAt' => date('c'),
        'roundStartedAt' => time(),
        'roundTurns' => 0, 'roundToolCalls' => 0,
        'turns' => 0, 'toolCalls' => 0, 'costCents' => 0, 'unpricedTurns' => 0, 'nextTurn' => 0,
        'failures' => [], 'transcript' => [['role' => 'user', 'content' => $message]], 'pending' => [],
        'events' => [], 'eventSeq' => 0, 'changesetId' => $changesetId, 'question' => '', 'summary' => '', 'openQuestions' => [], 'finalText' => '', 'error' => '',
    ];
    if ($run['owner'] === '') throw new InvalidArgumentException('A run needs a signed-in user.');
    nibblyAgentRunEvent($run, 'start');
    nibblyAgentRunSave($run);
    if ($changesetId !== '') {
        nibblyAgentChangesetMutate($changesetId, static function (array &$cs) use ($run) { $cs['runId'] = $run['id']; return true; });
        nibblyAgentChangesetAllowUrls($changesetId, nibblyAgentExtractUrls($goal));
    }
    nibblyAgentAudit('agent-run-start', true, ['run' => $run['id'], 'goalHash' => hash('sha256', $goal), 'goalLength' => mb_strlen($goal, 'UTF-8')]);
    return $run;
}

/** The user answers a question or asks for changes after the review. */
function nibblyAgentRunReply(string $runId, array $actor, string $message): array {
    $message = mb_substr(trim($message), 0, 2000, 'UTF-8');
    if ($message === '') return ['error' => 'Please write a message.'];
    return nibblyAgentRunLocked($runId, $actor, static function (array &$run) use ($message, $actor): array {
        if (!in_array($run['status'], ['needs_input', 'awaiting_review'], true)) {
            return ['error' => 'This run is ' . $run['status'] . ' and cannot take a reply.'];
        }
        if (!empty($run['changesetId'])) {
            nibblyAgentChangesetReopen($run['changesetId'], $actor);
            nibblyAgentChangesetAllowUrls($run['changesetId'], nibblyAgentExtractUrls($message));
        }
        $run['transcript'][] = ['role' => 'user', 'content' => $message];
        $run['status'] = 'running';
        $run['question'] = '';
        $run['summary'] = '';
        $run['openQuestions'] = [];
        $run['finalText'] = '';
        $run['roundStartedAt'] = time();
        $run['roundTurns'] = 0;
        $run['roundToolCalls'] = 0;
        $run['failures'] = [];
        nibblyAgentRunEvent($run, 'reply');
        return ['ok' => true];
    });
}

function nibblyAgentRunApplyCancel(array &$run, array $actor): array {
    if (in_array($run['status'], ['failed', 'cancelled'], true)) return ['error' => 'This run is already ' . $run['status'] . '.'];
    $run['status'] = 'cancelled';
    $run['pending'] = [];
    if (!empty($run['changesetId'])) nibblyAgentChangesetDiscard($run['changesetId'], $actor);
    nibblyAgentRunEvent($run, 'cancelled');
    nibblyAgentAudit('agent-run-cancelled', true, ['run' => $run['id']]);
    return ['ok' => true];
}

/** Cancel a run. While a step is in progress the request leaves a marker the step honours as soon as it can. */
function nibblyAgentRunCancel(string $runId, array $actor): array {
    $result = nibblyAgentRunLocked($runId, $actor, static fn(array &$run): array => nibblyAgentRunApplyCancel($run, $actor));
    if (!empty($result['busy'])) {
        @file_put_contents(nibblyAgentRunPath($runId) . '.cancel', date('c'));
        return ['ok' => true, 'status' => 'cancelling'];
    }
    return $result;
}

/**
 * Load a run, check the owner, hold its lock without waiting, run $work, store it.
 * A run that is being stepped elsewhere answers 'busy'.
 */
function nibblyAgentRunLocked(string $runId, array $actor, callable $work, bool $wait = false): array {
    $run = nibblyAgentRunLoad($runId);
    if ($run === null || !nibblyAgentCanSeeRun($run, $actor)) return ['error' => 'Unknown run.', 'status' => 'missing'];
    $lockFile = nibblyAgentRunPath($runId) . '.step.lock';
    $handle = @fopen($lockFile, 'c');
    // A step never queues behind another step of the same run.
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) fclose($handle);
        return ['status' => 'busy', 'busy' => true, 'runId' => $runId];
    }
    try {
        $run = nibblyAgentRunLoad($runId);
        if ($run === null) return ['error' => 'Unknown run.', 'status' => 'missing'];
        $result = $work($run);
        nibblyAgentRunSave($run);
        return $result + ['state' => nibblyAgentRunPublicState($run)];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// ---------------------------------------------------------------------------
// Public state
// ---------------------------------------------------------------------------

/** What the browser may see: status, a timeline without content, the question or summary. */
function nibblyAgentRunPublicState(array $run, int $after = 0): array {
    $limits = nibblyAgentSettings()['limits'];
    $state = [
        'runId' => $run['id'],
        'status' => $run['status'],
        'events' => array_values(array_filter($run['events'], static fn($e) => $e['seq'] > $after)),
        'cursor' => (int)($run['eventSeq'] ?? 0),
        'changesetId' => $run['changesetId'] !== '' ? $run['changesetId'] : null,
        'limitWarnings' => nibblyAiUsageSummary()['warnings'],
        'usage' => ['turns' => $run['turns'], 'toolCalls' => $run['toolCalls'], 'costCents' => $run['costCents'], 'unpricedTurns' => (int)($run['unpricedTurns'] ?? 0),
            'limits' => ['maxTurns' => $limits['maxTurns'], 'maxToolCalls' => $limits['maxToolCalls'], 'maxCostCentsPerRun' => $limits['maxCostCentsPerRun']]],
    ];
    if ($run['status'] === 'waiting_jobs') $state['jobs'] = array_values((array)($run['jobs'] ?? []));
    if ($run['status'] === 'needs_input') $state['question'] = $run['question'] !== '' ? $run['question'] : $run['finalText'];
    if ($run['status'] === 'awaiting_review') $state += ['summary' => $run['summary'], 'openQuestions' => $run['openQuestions']];
    if ($run['status'] === 'failed') $state += ['error' => $run['error'], 'errorCode' => $run['errorCode'] ?? ''];
    return $state;
}

function nibblyAgentRunStatus(string $runId, array $actor, int $after = 0): ?array {
    $run = nibblyAgentRunLoad($runId);
    return $run !== null && nibblyAgentCanSeeRun($run, $actor) ? nibblyAgentRunPublicState($run, $after) : null;
}

// ---------------------------------------------------------------------------
// Messages for the model
// ---------------------------------------------------------------------------

function nibblyAgentChangesetSummaryLine(array $run): string {
    $cs = $run['changesetId'] !== '' ? nibblyAgentChangesetLoad($run['changesetId']) : null;
    if ($cs === null || !$cs['ops']) return '';
    $lines = [];
    foreach (array_slice($cs['ops'], -15) as $op) {
        $lines[] = $op['id'] . ' ' . $op['type'] . ' ' . ($op['target'] ?? '') . ' ' . ($op['path'] ?? ($op['listKey'] ?? ''));
    }
    return 'Change set so far (' . count($cs['ops']) . ' operations, status ' . $cs['status'] . "; last ones):\n" . implode("\n", $lines);
}

/**
 * System prompt, a summary of the change set, and the transcript. When the input would exceed
 * 60% of the configured limit, results of tools from older exchanges are replaced by their
 * digest; the goal, the change set summary and the last three exchanges stay complete.
 */
function nibblyAgentRunBuildMessages(array $run, array $aiSettings, array $tools): array {
    $messages = [['role' => 'system', 'content' => nibblyAgentSystemPrompt($run)]];
    $summary = nibblyAgentChangesetSummaryLine($run);
    if ($summary !== '') $messages[] = ['role' => 'system', 'content' => $summary];

    $transcript = $run['transcript'];
    $exchangeStarts = [];
    foreach ($transcript as $index => $message) {
        if ($message['role'] === 'assistant') $exchangeStarts[] = $index;
    }
    $protectedFrom = count($exchangeStarts) > 3 ? $exchangeStarts[count($exchangeStarts) - 3] : 0;
    $budget = (int)($aiSettings['limits']['maxInputTokens'] * 0.6);
    $estimate = static fn(array $list) => nibblyAiEstimateTokens((string)json_encode([$list, $tools], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $build = static function (array $transcript, int $shortenBefore): array {
        $out = [];
        foreach ($transcript as $index => $message) {
            $entry = ['role' => $message['role'], 'content' => $message['content']];
            if ($message['role'] === 'assistant' && !empty($message['toolCalls'])) {
                $entry['toolCalls'] = $message['toolCalls'];
                if (!empty($message['reasoningItems'])) $entry['reasoningItems'] = $message['reasoningItems'];
            }
            if ($message['role'] === 'tool') {
                $entry['toolCallId'] = $message['toolCallId'];
                $entry['isError'] = !empty($message['isError']);
                if ($index < $shortenBefore && isset($message['digest'])) $entry['content'] = $message['digest'];
            }
            $out[] = $entry;
        }
        return $out;
    };
    $body = $build($transcript, 0);
    if ($estimate(array_merge($messages, $body)) > $budget) {
        // Shorten tool results of old exchanges, oldest first, until the input fits.
        for ($shortenBefore = 1; $shortenBefore <= $protectedFrom; $shortenBefore++) {
            if (($transcript[$shortenBefore - 1]['role'] ?? '') !== 'tool') continue;
            $body = $build($transcript, $shortenBefore);
            if ($estimate(array_merge($messages, $body)) <= $budget) break;
        }
    } else {
        $body = $build($transcript, -1);
    }
    return array_merge($messages, $body);
}

// ---------------------------------------------------------------------------
// The step
// ---------------------------------------------------------------------------

function nibblyAgentToolLabel(array $call): string {
    $args = (array)($call['arguments'] ?? []);
    $parts = [$call['name']];
    foreach (['target', 'path', 'listKey', 'type', 'query', 'rid', 'url'] as $key) {
        if (isset($args[$key]) && is_string($args[$key])) $parts[] = mb_substr($args[$key], 0, 60, 'UTF-8');
    }
    return implode(' ', $parts);
}

/** Execute the pending tool calls of a run, in order. */
function nibblyAgentRunExecutePending(array &$run, array $actor, array $limits, float $deadline): void {
    while ($run['pending'] && microtime(true) < $deadline) {
        $call = $run['pending'][0];
        if ($run['roundToolCalls'] >= $limits['maxToolCalls']) {
            nibblyAgentRunFail($run, 'maxToolCalls', 'The agent used up its tool calls for this round (' . $limits['maxToolCalls'] . ').');
            return;
        }
        if ($call['error'] !== null) {
            $json = (string)json_encode(['error' => $call['error'], 'hint' => 'Correct the arguments and call the tool again.'], JSON_UNESCAPED_UNICODE);
            $outcome = ['content' => $json, 'isError' => true, 'digest' => $json];
        } else {
            try {
                $outcome = nibblyAgentExecuteTool($run, $call, $actor);
            } catch (NibblyAgentToolWaiting $e) {
                $run['status'] = 'waiting_jobs';
                $run['jobs'] = [$e->jobId];
                nibblyAgentRunEvent($run, 'waiting', ['label' => 'image']);
                return; // the next step looks at the job again
            } catch (NibblyAgentToolDeferred $e) {
                $run['pending'][0]['deferrals'] = (int)($call['deferrals'] ?? 0) + 1;
                if ($run['pending'][0]['deferrals'] > NIBBLY_AGENT_RUN_MAX_DEFERRALS) {
                    $json = (string)json_encode(['error' => 'This could not be completed on this server.', 'hint' => 'Continue without it.']);
                    $outcome = ['content' => $json, 'isError' => true, 'digest' => $json];
                } else {
                    return; // continue in the next step, in a fresh process
                }
            }
        }
        array_shift($run['pending']);
        if ($run['status'] === 'waiting_jobs') {
            $run['status'] = 'running';
            $run['jobs'] = [];
        }
        $run['toolCalls']++;
        $run['roundToolCalls']++;
        $run['transcript'][] = ['role' => 'tool', 'toolCallId' => $call['id'], 'content' => $outcome['content'], 'digest' => $outcome['digest'], 'isError' => $outcome['isError']];
        nibblyAgentRunEvent($run, 'tool', ['tool' => $call['name'], 'label' => nibblyAgentToolLabel($call), 'ok' => !$outcome['isError']]);
        $args = (array)($call['arguments'] ?? []);
        nibblyAgentAudit('agent-tool', !$outcome['isError'], ['run' => $run['id'], 'tool' => $call['name'], 'target' => isset($args['target']) ? (string)$args['target'] : null,
            'path' => isset($args['path']) ? (string)$args['path'] : (isset($args['listKey']) ? (string)$args['listKey'] : null), 'argsHash' => hash('sha256', (string)json_encode($args))]);

        if ($outcome['isError']) {
            $run['failures'][$call['name']] = (int)($run['failures'][$call['name']] ?? 0) + 1;
            if ($run['failures'][$call['name']] >= $limits['maxToolFailures']) {
                nibblyAgentRunFail($run, 'toolFailures', 'The agent could not get "' . $call['name'] . '" to work (' . $limits['maxToolFailures'] . ' failures in a row).');
                return;
            }
        } else {
            $run['failures'][$call['name']] = 0;
        }
        if (!empty(nibblyAgentToolDefinitions()[$call['name']]['endsStep'])) return; // slow tools (web reads) get a step of their own
        if (!empty($run['end'])) {
            // The run stops here (a question or the review). Calls after it are answered, not executed,
            // so the transcript stays consistent for the next round.
            foreach ($run['pending'] as $skipped) {
                $json = (string)json_encode(['error' => 'Skipped: the run is waiting for the user.']);
                $run['transcript'][] = ['role' => 'tool', 'toolCallId' => $skipped['id'], 'content' => $json, 'digest' => $json, 'isError' => true];
            }
            $run['pending'] = [];
            $run['status'] = $run['end'];
            unset($run['end']);
            nibblyAgentRunEvent($run, $run['status'] === 'awaiting_review' ? 'review' : 'question');
            return;
        }
    }
}

/** One model turn. */
function nibblyAgentRunModelTurn(array &$run, array $actor, array $limits): void {
    if ($run['roundTurns'] >= $limits['maxTurns']) {
        nibblyAgentRunFail($run, 'maxTurns', 'The agent used up its turns for this round (' . $limits['maxTurns'] . ').');
        return;
    }
    if ($run['costCents'] >= $limits['maxCostCentsPerRun']) {
        nibblyAgentRunFail($run, 'maxCost', 'The cost limit of this run was reached (' . $limits['maxCostCentsPerRun'] . ' cents, estimated).');
        return;
    }
    $aiSettings = nibblyAiLoadSettings(false);
    $tools = nibblyAgentToolsForUser();
    $turn = ++$run['nextTurn'];
    nibblyAgentRunSave($run); // the ledger id is spent even if this request dies mid-call
    try {
        $result = nibblyAiChatWithTools(nibblyAgentRunBuildMessages($run, $aiSettings, $tools), $tools, ['_requestId' => 'agent_' . $run['id'] . '_' . $turn, 'temperature' => 0.2]);
    } catch (Throwable $e) {
        nibblyAgentRunFail($run, 'model', $e instanceof RuntimeException ? $e->getMessage() : 'The AI request failed.');
        return;
    }
    $run['turns']++;
    $run['roundTurns']++;
    // Every round counts with the model and price it was made with; a round without a known price adds nothing and is counted apart.
    $run['costCents'] = nibblyAiAddCents($run['costCents'] ?? 0, (float)$result['usage']['estimatedCostCents']);
    if (empty($result['usage']['priced'])) $run['unpricedTurns'] = (int)($run['unpricedTurns'] ?? 0) + 1;
    $run['transcript'][] = $result['message'];
    if ($result['kind'] === 'tool_calls') {
        $run['pending'] = array_map(static fn($call) => ['id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments'], 'error' => $call['error']], $result['toolCalls']);
        nibblyAgentRunEvent($run, 'turn', ['tools' => array_column($result['toolCalls'], 'name')]);
        return;
    }
    // A plain answer: the agent has nothing to stage and speaks to the user.
    $run['finalText'] = mb_substr($result['text'], 0, 4000, 'UTF-8');
    $run['status'] = 'needs_input';
    nibblyAgentRunEvent($run, 'message');
}

/**
 * One step of a run: execute pending tool calls, or make one model turn.
 *
 * @param array $options deadlineSeconds
 * @return array public state, or {status: 'busy'} when another step of this run is in progress
 */
function nibblyAgentRunStep(string $runId, array $actor, array $options = []): array {
    return nibblyAgentRunLocked($runId, $actor, static function (array &$run) use ($actor, $options): array {
        $marker = nibblyAgentRunPath($run['id']) . '.cancel';
        if (is_file($marker)) {
            @unlink($marker);
            return nibblyAgentRunApplyCancel($run, $actor);
        }
        if (!in_array($run['status'], ['running', 'waiting_jobs'], true)) return ['idle' => true];
        $limits = nibblyAgentSettings()['limits'];
        $max = (int)ini_get('max_execution_time');
        $seconds = (int)($options['deadlineSeconds'] ?? ($max > 0 ? min(50, $max - 15) : 50));
        $deadline = microtime(true) + max(5, $seconds);
        if (time() - (int)$run['roundStartedAt'] > $limits['maxRuntimeSeconds']) {
            nibblyAgentRunFail($run, 'timeout', 'The agent ran for too long (' . intdiv($limits['maxRuntimeSeconds'], 60) . ' minutes).');
            return ['ok' => false];
        }
        if ($run['pending']) {
            nibblyAgentRunExecutePending($run, $actor, $limits, $deadline);
        } else {
            nibblyAgentRunModelTurn($run, $actor, $limits);
        }
        if (is_file($marker)) { // cancelled while the model was thinking
            @unlink($marker);
            nibblyAgentRunApplyCancel($run, $actor);
        }
        return ['ok' => true];
    });
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------

/** Runs are kept for 24 hours after their last activity. */
function nibblyAgentRunCleanup(?int $now = null): int {
    $now ??= time();
    $removed = 0;
    foreach (glob(nibblyAgentDir() . '/runs/*.json') ?: [] as $file) {
        $run = nibblyAgentReadJsonFile($file);
        $age = $now - (int)strtotime((string)($run['updatedAt'] ?? 'now'));
        if ($run !== null && $age > NIBBLY_AGENT_STAGING_HOURS * 3600 && @unlink($file)) {
            @unlink($file . '.step.lock');
            @unlink($file . '.cancel');
            $removed++;
        }
    }
    return $removed;
}
