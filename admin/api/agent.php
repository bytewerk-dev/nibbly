<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// AI agent: runs (start/step/status/reply/cancel) and change sets (review/apply/undo/publish).
// The dispatcher has already checked the session and the CSRF token of every POST.
require_once NIBBLY_ADMIN_DIR . '/../includes/ai/agent/run.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/ai/agent/rate-limit.php';

if (!function_exists('nibblyAgentApiGuard')) {
    /** Module, right and (for actions that need the model) availability; returns the acting user. */
    function nibblyAgentApiGuard(bool $needsModel): array {
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyAgentCan('chat')) {
            http_response_code(403);
            jsonResponse(false, null, 'Forbidden');
        }
        if ($needsModel) {
            $reason = '';
            if (!nibblyAgentAvailable($reason)) {
                jsonResponse(false, ['available' => false], $reason);
            }
        }
        $actor = nibblyAgentActor();
        if ($actor['userKey'] === '') {
            http_response_code(403);
            jsonResponse(false, null, 'Forbidden');
        }
        return $actor;
    }

    function nibblyAgentApiRateLimit(array $actor, string $bucket): void {
        $limits = nibblyAgentSettings()['limits'];
        $config = ['runs' => ['limit' => $limits['runsPerHour'], 'window' => 3600], 'steps' => ['limit' => $limits['stepsPerMinute'], 'window' => 60]][$bucket];
        $result = nibblyAgentRateLimit($actor['userKey'], $bucket, $config);
        if (!$result['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . $result['retryAfter']);
            jsonResponse(false, ['retryAfter' => $result['retryAfter']], 'Too many agent requests. Please wait a moment and try again.');
        }
    }

    function nibblyAgentApiRunId(): string {
        $id = $_POST['runId'] ?? $_GET['runId'] ?? '';
        if (!is_string($id) || !nibblyAgentValidRunId($id)) {
            jsonResponse(false, null, 'Invalid run');
        }
        return $id;
    }

    function nibblyAgentApiChangesetId(): string {
        $id = $_POST['id'] ?? $_GET['id'] ?? '';
        if (!is_string($id) || !nibblyAgentValidChangesetId($id)) {
            jsonResponse(false, null, 'Invalid change set');
        }
        return $id;
    }
}

switch ($action) {
    case 'ai-agent-start':
        $actor = nibblyAgentApiGuard(true);
        try {
            nibblyCopilotAssertBurstLimit('agent', 120, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        nibblyAgentApiRateLimit($actor, 'runs');
        try {
            $attached = (string)($_POST['changesetId'] ?? '');
            if ($attached !== '' && !nibblyAgentValidChangesetId($attached)) {
                jsonResponse(false, null, 'Invalid change set');
            }
            $run = nibblyAgentRunStart($actor, (string)($_POST['goal'] ?? ''), $attached);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        // Housekeeping is cheap and rare: once an hour is enough.
        $marker = nibblyAgentDir() . '/cleanup.stamp';
        if (!is_file($marker) || filemtime($marker) < time() - 3600) {
            @touch($marker);
            nibblyAgentCleanup();
        }
        jsonResponse(true, nibblyAgentRunPublicState($run), 'Run started');
        break;

    case 'ai-agent-step':
        $actor = nibblyAgentApiGuard(true);
        $runId = nibblyAgentApiRunId();
        try {
            nibblyCopilotAssertBurstLimit('agent', 120, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        nibblyAgentApiRateLimit($actor, 'steps');
        // A step can take a while; do not hold the session lock (other tabs, the editor) meanwhile.
        session_write_close();
        $result = nibblyAgentRunStep($runId, $actor);
        if (($result['status'] ?? '') === 'missing') {
            http_response_code(404);
            jsonResponse(false, null, 'Unknown run');
        }
        if (!empty($result['busy'])) {
            jsonResponse(true, ['runId' => $runId, 'status' => 'busy'], 'Another step of this run is in progress');
        }
        jsonResponse(true, $result['state'], 'OK');
        break;

    case 'ai-agent-upload':
        $actor = nibblyAgentApiGuard(true);
        if (!nibblyAgentCan('uploadImages')) {
            http_response_code(403);
            jsonResponse(false, null, 'Forbidden');
        }
        $limits = nibblyAgentSettings()['limits'];
        $rate = nibblyAgentRateLimit($actor['userKey'], 'uploads', ['limit' => $limits['uploadsPer10Minutes'], 'window' => 600]);
        if (!$rate['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . $rate['retryAfter']);
            jsonResponse(false, ['retryAfter' => $rate['retryAfter']], 'Too many uploads. Please wait a moment and try again.');
        }
        $file = $_FILES['image'] ?? $_FILES['file'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            jsonResponse(false, null, 'Upload error');
        }
        $imageConfig = getMediaConfig('image');
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            jsonResponse(false, null, 'Only JPEG, PNG and WebP images are accepted.');
        }
        if ((int)$file['size'] > (int)$imageConfig['maxSize']) {
            jsonResponse(false, null, 'File too large');
        }
        $attached = (string)($_POST['changesetId'] ?? '');
        if ($attached !== '') {
            $target = nibblyAgentValidChangesetId($attached) ? nibblyAgentChangesetLoad($attached) : null;
            if ($target === null || (string)$target['owner'] !== $actor['userKey'] || $target['status'] !== 'staging') {
                jsonResponse(false, null, 'Unknown change set');
            }
        } else {
            $attached = nibblyAgentChangesetCreate(['owner' => $actor['userKey']])['id'];
        }
        try {
            $image = nibblyAgentIngestImage($file['tmp_name'], ['maxBytes' => (int)$imageConfig['maxSize']]);
            $record = nibblyAgentMediaRegister($attached, $image, ['source' => 'upload', 'name' => (string)$file['name'], 'ai' => $image['ai']]);
        } catch (RuntimeException $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        nibblyAgentAudit('agent-upload', true, ['cs' => $attached, 'ref' => $record['ref'], 'bytes' => $record['bytes']]);
        jsonResponse(true, ['changesetId' => $attached, 'media' => nibblyAgentMediaSummary($record['ref'], $record),
            'thumbnail' => 'admin/api.php?action=ai-agent-media&id=' . $attached . '&ref=' . $record['ref']], 'Uploaded');
        break;

    case 'ai-agent-clear-cache':
        // The cached scan of the page templates; rebuilt on demand. Settings area, administrators only.
        if (!dashboardAiModuleEnabled()) jsonResponse(false, null, 'AI module is disabled');
        if (!isAdmin()) {
            http_response_code(403);
            jsonResponse(false, null, 'Forbidden');
        }
        $removed = nibblyAgentClearCapabilityCache();
        nibblyAgentAudit('agent-cache-clear', true, ['files' => $removed]);
        jsonResponse(true, ['removed' => $removed], 'Cache cleared');
        break;

    case 'ai-agent-media':
        // Staged images (uploads, stock photos, generated images) for the review panel; owner and admins only.
        $actor = nibblyAgentApiGuard(false);
        $cs = nibblyAgentChangesetLoad(nibblyAgentApiChangesetId());
        $ref = (string)($_GET['ref'] ?? '');
        $media = ($cs !== null && preg_match('/^m_[a-f0-9]{8}$/', $ref)) ? ($cs['media'][$ref] ?? null) : null;
        if ($cs === null || $media === null || !nibblyAgentCanSeeChangeset($cs, $actor)) {
            http_response_code(404);
            jsonResponse(false, null, 'Unknown image');
        }
        $stagingDir = realpath(nibblyAgentMediaStagingDir($cs['id']));
        $file = realpath(nibblyAgentAbsolute((string)$media['stagingFile']));
        if ($stagingDir === false || $file === false || !str_starts_with($file, $stagingDir . DIRECTORY_SEPARATOR) || !is_file($file)) {
            http_response_code(404);
            jsonResponse(false, null, 'The image is no longer staged');
        }
        header_remove('Content-Type');
        header('Content-Type: ' . ($media['mime'] ?? 'image/webp'));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;

    case 'ai-agent-status':
        $actor = nibblyAgentApiGuard(false);
        $state = nibblyAgentRunStatus(nibblyAgentApiRunId(), $actor, (int)($_GET['after'] ?? 0));
        if ($state === null) {
            http_response_code(404);
            jsonResponse(false, null, 'Unknown run');
        }
        jsonResponse(true, $state, 'OK');
        break;

    case 'ai-agent-reply':
        $actor = nibblyAgentApiGuard(true);
        $result = nibblyAgentRunReply(nibblyAgentApiRunId(), $actor, (string)($_POST['message'] ?? ''));
        if (($result['status'] ?? '') === 'missing') {
            http_response_code(404);
            jsonResponse(false, null, 'Unknown run');
        }
        if (!empty($result['busy'])) {
            jsonResponse(true, ['status' => 'busy'], 'The agent is working; try again in a moment');
        }
        if (isset($result['error'])) {
            jsonResponse(false, null, $result['error']);
        }
        jsonResponse(true, $result['state'], 'OK');
        break;

    case 'ai-agent-cancel':
        $actor = nibblyAgentApiGuard(false);
        $result = nibblyAgentRunCancel(nibblyAgentApiRunId(), $actor);
        if (($result['status'] ?? '') === 'missing') {
            http_response_code(404);
            jsonResponse(false, null, 'Unknown run');
        }
        if (isset($result['error'])) {
            jsonResponse(false, null, $result['error']);
        }
        jsonResponse(true, $result['state'] ?? ['status' => $result['status'] ?? 'cancelling'], 'OK');
        break;

    case 'ai-agent-changeset':
        $actor = nibblyAgentApiGuard(false);
        $cs = nibblyAgentChangesetLoad(nibblyAgentApiChangesetId());
        if ($cs === null || !nibblyAgentCanSeeChangeset($cs, $actor)) {
            http_response_code(404);
            jsonResponse(false, null, 'Unknown change set');
        }
        // After the apply the owner needs the items to publish and the undo token, also after a reload.
        $applied = $cs['status'] === 'applied' && !empty($cs['applied'])
            ? ['items' => array_values((array)$cs['applied']['items']), 'undoToken' => nibblyAgentUndoToken($cs['id'])] : null;
        jsonResponse(true, nibblyAgentChangesetPreview($cs) + ['canPublish' => $actor['canPublish'], 'applied' => $applied], 'OK');
        break;

    case 'ai-agent-revalidate':
        $actor = nibblyAgentApiGuard(false);
        $id = nibblyAgentApiChangesetId();
        $result = nibblyAgentChangesetRevalidate($id, $actor);
        if (empty($result['ok'])) {
            // The status of a change set is only for its owner (and admins).
            $current = nibblyAgentChangesetLoad($id);
            $visible = $current !== null && nibblyAgentCanSeeChangeset($current, $actor);
            jsonResponse(false, ['status' => $visible ? $current['status'] : 'missing'], (string)($result['error'] ?? 'Revalidation failed'));
        }
        $report = nibblyAgentChangesetFinalize($id);
        jsonResponse(true, ['status' => $report['status'], 'renderCheck' => $report['renderCheck']['status'] ?? 'skipped', 'previewHash' => $report['previewHash']], 'OK');
        break;

    case 'ai-agent-apply':
        $actor = nibblyAgentApiGuard(false);
        $id = nibblyAgentApiChangesetId();
        if (($_POST['confirmed'] ?? '') !== '1') {
            jsonResponse(false, null, 'Confirmation required');
        }
        $result = nibblyAgentChangesetApply($id, (string)($_POST['previewHash'] ?? ''), $actor);
        if (empty($result['ok'])) {
            if (($result['status'] ?? '') === 'conflict') {
                http_response_code(409);
            }
            jsonResponse(false, ['status' => $result['status'] ?? 'failed'], (string)$result['error']);
        }
        jsonResponse(true, ['status' => 'applied', 'undoToken' => $result['undoToken'], 'items' => $result['items'], 'files' => $result['files']], 'Applied');
        break;

    case 'ai-agent-discard':
        $actor = nibblyAgentApiGuard(false);
        if (!nibblyAgentChangesetDiscard(nibblyAgentApiChangesetId(), $actor)) {
            jsonResponse(false, null, 'This change set cannot be discarded');
        }
        jsonResponse(true, ['status' => 'discarded'], 'Discarded');
        break;

    case 'ai-agent-undo':
        $actor = nibblyAgentApiGuard(false);
        $result = nibblyAgentChangesetUndo(nibblyAgentApiChangesetId(), (string)($_POST['token'] ?? ''), $actor);
        if (empty($result['ok'])) {
            jsonResponse(false, ['status' => $result['status'] ?? 'failed'], (string)$result['error']);
        }
        jsonResponse(true, ['status' => 'undone'], 'Undone');
        break;

    case 'ai-agent-publish':
        $actor = nibblyAgentApiGuard(false);
        $id = nibblyAgentApiChangesetId();
        $item = json_decode((string)($_POST['item'] ?? ''), true);
        if (!is_array($item) || !is_string($item['kind'] ?? null) || !is_string($item['target'] ?? null)) {
            jsonResponse(false, null, 'Invalid item');
        }
        $item = array_intersect_key($item, array_flip(['kind', 'target', 'listKey', 'index', 'sectionId']));
        $result = nibblyAgentChangesetPublish($id, $item, $actor);
        if (empty($result['ok'])) {
            if (str_contains((string)$result['error'], 'not allowed to publish')) {
                http_response_code(403);
            }
            jsonResponse(false, null, (string)$result['error']);
        }
        jsonResponse(true, ['status' => 'published'], 'Published');
        break;
}
