<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'ai-resolve-request':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        try {
            nibblyAiResolveReservation((string)($_POST['request_id'] ?? ''), (string)($_POST['resolution'] ?? ''));
            jsonResponse(true, nibblyAiUsageSummary());
        } catch (Throwable $error) { jsonResponse(false, null, $error->getMessage()); }
        break;
    case 'load-ai-settings':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(true, [
                'settings' => null,
                'usage' => null
            ]);
        }
        jsonResponse(true, [
            'settings' => nibblyAiLoadSettings(true),
            'usage' => nibblyAiUsageSummary()
        ]);
        break;

    case 'ai-image-history':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        jsonResponse(true, nibblyAiLoadImageHistory((int)($_GET['offset'] ?? 0), (int)($_GET['limit'] ?? 12)));
        break;

    case 'ai-image-jobs':
        if (!nibblyApiCanUseImageJobs()) {
            jsonResponse(false, null, 'Forbidden');
        }
        $openOnly = !empty($_GET['open_only']) || !empty($_POST['open_only']);
        $userFilter = isAdmin() ? null : nibblyApiCurrentAiUser();
        jsonResponse(true, ['jobs' => nibblyAiListImageJobs($openOnly, (int)($_GET['limit'] ?? $_POST['limit'] ?? 20), $userFilter)]);
        break;

    case 'ai-image-job-run':
        if (!nibblyApiCanUseImageJobs()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $jobId = trim((string)($_POST['job_id'] ?? $_GET['job_id'] ?? ''));
        if ($jobId === '') {
            jsonResponse(false, null, 'Image job ID is required');
        }
        try {
            $pendingJob = nibblyAiRefreshImageJobState(nibblyAiLoadImageJob($jobId));
            nibblyApiAssertImageJobAccess($pendingJob);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if ((string)($pendingJob['status'] ?? '') === 'queued' && nibblyApiTrySpawnLocalImageJobWorker($jobId)) {
                jsonResponse(true, [
                    'job' => nibblyAiPublicImageJob($pendingJob),
                    'worker' => 'cli'
                ]);
            }
            // Detach for queued jobs so generation survives closed tabs and
            // page navigation; clients pick up the result via job polling.
            $detached = (string)($pendingJob['status'] ?? '') === 'queued' && nibblyApiDetachResponse([
                'success' => true,
                'data' => ['job' => nibblyAiPublicImageJob($pendingJob)],
                'message' => ''
            ]);
            $job = nibblyApiRunImageJob($jobId);
            if ($detached) {
                exit;
            }
            jsonResponse(true, ['job' => $job]);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'clear-ai-image-history':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        nibblyAiClearImageHistory();
        jsonResponse(true, nibblyAiLoadImageHistory(0, 12), 'AI image history cleared');
        break;

    case 'save-ai-settings':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $settings = json_decode($_POST['settings'] ?? '{}', true);
        if (!is_array($settings)) {
            jsonResponse(false, null, 'Invalid AI settings JSON');
        }
        try {
            $saved = nibblyAiSaveSettings($settings);
            jsonResponse(true, [
                'settings' => $saved,
                'usage' => nibblyAiUsageSummary()
            ], 'AI settings saved');
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-test':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            $result = nibblyAiGenerateText('Reply with exactly: nibbly AI connection OK', [
                'feature' => '',
                'maxOutputTokens' => 256,
                'temperature' => 0
            ]);
            jsonResponse(true, $result, 'AI connection works');
        } catch (Throwable $e) {
            nibblyAiAudit('test', false, ['message' => $e->getMessage()]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-chat':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $messages = json_decode($_POST['messages'] ?? '[]', true);
        if (!is_array($messages)) {
            jsonResponse(false, null, 'Invalid messages JSON');
        }
        try {
            $settings = nibblyAiLoadSettings(false);
            $system = $settings['systemPrompts']['assistant'] ?? nibblyAiDefaults()['systemPrompts']['assistant'];
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
            $result = nibblyAiChat($messages, [
                'feature' => 'backendAssistant',
                'maxOutputTokens' => $_POST['maxOutputTokens'] ?? null
            ]);
            jsonResponse(true, $result);
        } catch (Throwable $e) {
            nibblyAiAudit('chat', false, ['message' => $e->getMessage()]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-context':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? $_GET['contentPage'] ?? ''));
        $settings = nibblyAiLoadSettings(true);
        $settings['assistantUiLanguage'] = dashboardCopilotUiLanguage();
        jsonResponse(true, nibblyCopilotBuildContext($contentPage, $settings));
        break;

    case 'ai-copilot-history-list':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('chat')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('history-list', 30, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $userKey = dashboardCopilotCurrentUserKey();
        $items = [];
        foreach (glob(dashboardCopilotHistoryDir() . '*.json') ?: [] as $file) {
            $chat = json_decode((string)file_get_contents($file), true);
            if (!is_array($chat) || (string)($chat['user'] ?? '') !== $userKey) {
                continue;
            }
            $items[] = dashboardCopilotHistorySummary($chat);
        }
        usort($items, fn($a, $b) => strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? '')));
        jsonResponse(true, ['items' => array_slice($items, 0, 80)]);
        break;

    case 'ai-copilot-history-load':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('chat')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('history-load', 30, 60);
            $chat = dashboardCopilotLoadOwnedHistory(trim((string)($_POST['id'] ?? '')));
            jsonResponse(true, [
                'chat' => [
                    'id' => (string)($chat['id'] ?? ''),
                    'title' => (string)($chat['title'] ?? ''),
                    'contentPage' => (string)($chat['contentPage'] ?? ''),
                    'pageTitle' => (string)($chat['pageTitle'] ?? ''),
                    'url' => (string)($chat['url'] ?? ''),
                    'messages' => dashboardCopilotCleanHistoryMessages($chat['messages'] ?? []),
                    'lastInstruction' => (string)($chat['lastInstruction'] ?? ''),
                    'lastImageResult' => dashboardCopilotCleanImageResult($chat['lastImageResult'] ?? null),
                    'createdAt' => (string)($chat['createdAt'] ?? ''),
                    'updatedAt' => (string)($chat['updatedAt'] ?? '')
                ]
            ]);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-history-save':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('chat')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('history-save', 60, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $messages = dashboardCopilotCleanHistoryMessages(json_decode((string)($_POST['messages'] ?? '[]'), true));
        if (!$messages) {
            jsonResponse(false, null, 'No chat messages to archive');
        }
        try {
            $requestedId = trim((string)($_POST['id'] ?? ''));
            $id = dashboardCopilotHistoryId($requestedId);
            $path = dashboardCopilotHistoryPath($id);
            $existing = [];
            if ($requestedId !== '' && is_file($path)) {
                $existing = dashboardCopilotLoadOwnedHistory($id);
            }
            $now = date('c');
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') {
                foreach ($messages as $message) {
                    if (($message['role'] ?? '') === 'user') {
                        $title = substr((string)$message['content'], 0, 90);
                        break;
                    }
                }
            }
            $chat = [
                'id' => $id,
                'user' => dashboardCopilotCurrentUserKey(),
                'title' => $title,
                'contentPage' => substr(trim((string)($_POST['contentPage'] ?? '')), 0, 120),
                'pageTitle' => substr(trim((string)($_POST['pageTitle'] ?? '')), 0, 160),
                'url' => substr(trim((string)($_POST['url'] ?? '')), 0, 500),
                'messages' => $messages,
                'lastInstruction' => substr((string)($_POST['lastInstruction'] ?? ''), 0, 3000),
                'lastImageResult' => dashboardCopilotCleanImageResult(json_decode((string)($_POST['lastImageResult'] ?? 'null'), true)),
                'createdAt' => (string)($existing['createdAt'] ?? $now),
                'updatedAt' => $now
            ];
            if (file_put_contents($path, json_encode($chat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                throw new RuntimeException('Could not save chat history');
            }
            jsonResponse(true, ['chat' => dashboardCopilotHistorySummary($chat)]);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-history-delete':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('chat')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('history-delete', 20, 60);
            $id = trim((string)($_POST['id'] ?? ''));
            dashboardCopilotLoadOwnedHistory($id);
            $path = dashboardCopilotHistoryPath($id);
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Could not delete chat history');
            }
            jsonResponse(true, ['id' => $id], 'Chat history deleted');
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-chat':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('chat')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('chat', 20, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $messages = json_decode((string)($_POST['messages'] ?? '[]'), true);
        if (!is_array($messages)) {
            jsonResponse(false, null, 'Invalid messages JSON');
        }
        $cleanMessages = [];
        foreach (array_slice($messages, -8) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string)($message['role'] ?? '');
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $cleanMessages[] = [
                'role' => $role,
                'content' => substr($content, 0, 2200)
            ];
        }
        if (!$cleanMessages) {
            jsonResponse(false, null, 'Message is required');
        }
        try {
            $contentPage = trim((string)($_POST['contentPage'] ?? ''));
            $settings = nibblyAiLoadSettings(true);
            $settings['assistantUiLanguage'] = dashboardCopilotUiLanguage();
            $context = nibblyCopilotBuildContext($contentPage, $settings);
            $system = nibblyCopilotSystemPrompt($context);
            array_unshift($cleanMessages, ['role' => 'system', 'content' => $system]);
            $result = nibblyAiChat($cleanMessages, [
                'feature' => 'backendAssistant',
                'maxOutputTokens' => $_POST['maxOutputTokens'] ?? 900,
                'temperature' => 0.25
            ]);
            nibblyAiAudit('copilot-chat', true, [
                'contentPage' => $contentPage,
                'messages' => count($cleanMessages) - 1
            ]);
            jsonResponse(true, [
                'reply' => (string)($result['text'] ?? ''),
                'usage' => $result['usage'] ?? null,
                'limits' => $result['limits'] ?? null,
                'context' => $context
            ]);
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-chat', false, ['message' => $e->getMessage()]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-chat-stream':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('chat')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('chat', 20, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $messages = json_decode((string)($_POST['messages'] ?? '[]'), true);
        if (!is_array($messages)) {
            jsonResponse(false, null, 'Invalid messages JSON');
        }
        $cleanMessages = [];
        foreach (array_slice($messages, -8) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string)($message['role'] ?? '');
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $cleanMessages[] = [
                'role' => $role,
                'content' => substr($content, 0, 2200)
            ];
        }
        if (!$cleanMessages) {
            jsonResponse(false, null, 'Message is required');
        }
        try {
            $contentPage = trim((string)($_POST['contentPage'] ?? ''));
            $settings = nibblyAiLoadSettings(true);
            $settings['assistantUiLanguage'] = dashboardCopilotUiLanguage();
            $context = nibblyCopilotBuildContext($contentPage, $settings);
            $system = nibblyCopilotSystemPrompt($context);
            array_unshift($cleanMessages, ['role' => 'system', 'content' => $system]);

            header_remove('Content-Type');
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            $emitEvent = static function (array $payload): void {
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "\n\n";
                flush();
            };

            $result = nibblyAiChatStream($cleanMessages, [
                'feature' => 'backendAssistant',
                'maxOutputTokens' => $_POST['maxOutputTokens'] ?? 900,
                'temperature' => 0.25
            ], static function (string $delta) use ($emitEvent): void {
                $emitEvent(['delta' => $delta]);
            });
            nibblyAiAudit('copilot-chat', true, [
                'contentPage' => $contentPage,
                'streamed' => true,
                'messages' => count($cleanMessages) - 1
            ]);
            $emitEvent([
                'done' => true,
                'reply' => (string)($result['text'] ?? ''),
                'usage' => $result['usage'] ?? null,
                'limits' => $result['limits'] ?? null,
                'context' => $context
            ]);
            exit;
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-chat', false, ['message' => $e->getMessage()]);
            if (headers_sent()) {
                echo 'data: ' . json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "\n\n";
                flush();
                exit;
            }
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-suggest':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('suggestField')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('suggest', 12, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        $instruction = trim((string)($_POST['instruction'] ?? ''));
        $fieldRef = trim((string)($_POST['fieldRef'] ?? ''));
        if ($instruction === '') {
            jsonResponse(false, null, 'Instruction is required');
        }
        try {
            $settings = nibblyAiLoadSettings(true);
            $context = nibblyCopilotBuildContext($contentPage, $settings);
            if (empty($context['page']['exists'])) {
                jsonResponse(false, null, 'Content page not found');
            }
            $fields = nibblyCopilotAllowedSuggestionFields($context, $fieldRef);
            if (!$fields) {
                jsonResponse(false, null, 'No editable text fields are available for AI suggestions on this page.');
            }
            $pageData = nibblyCopilotLoadPageData($contentPage);
            $prompt = nibblyCopilotBuildSuggestionPrompt($context, $fields, substr($instruction, 0, 2200));
            $result = nibblyAiGenerateText($prompt, [
                'feature' => 'backendAssistant',
                'maxOutputTokens' => 1200,
                'temperature' => 0.2,
                'system' => 'You produce safe draft content changes for nibbly CMS. Return strict JSON only.'
            ]);
            $raw = nibblyCopilotExtractJsonObject((string)($result['text'] ?? ''));
            $proposals = nibblyCopilotValidateProposals($raw, $context, $pageData);
            nibblyAiAudit('copilot-suggest', true, [
                'contentPage' => $contentPage,
                'fieldRef' => $fieldRef,
                'proposalCount' => count($proposals),
                'proposals' => dashboardCopilotProposalAuditSummary($proposals)
            ]);
            jsonResponse(true, [
                'proposals' => $proposals,
                'usage' => $result['usage'] ?? null,
                'limits' => $result['limits'] ?? null,
                'context' => $context
            ]);
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-suggest', false, ['message' => $e->getMessage(), 'contentPage' => $contentPage]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-translate':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('suggestField')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('translate', 6, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        $instruction = trim((string)($_POST['instruction'] ?? ''));
        $fieldRef = trim((string)($_POST['fieldRef'] ?? ''));
        $targetLang = strtolower(trim((string)($_POST['targetLang'] ?? '')));
        if ($contentPage === '') {
            jsonResponse(false, null, 'Content page is required');
        }
        try {
            $sourceLang = preg_match('/^([a-z]{2})_/', $contentPage, $langMatch) ? $langMatch[1] : '';
            if ($targetLang === '') {
                $targetLang = nibblyCopilotDetectTargetLanguage($instruction, $sourceLang);
            }
            if ($targetLang === '' || $targetLang === $sourceLang) {
                jsonResponse(false, null, 'Please name the target language for the translation (for example "translate this page to English").');
            }
            if (!array_key_exists($targetLang, nibblyCopilotSiteLanguages())) {
                jsonResponse(false, null, 'The language "' . $targetLang . '" is not configured for this site.');
            }
            $targetContentPage = nibblyCopilotTranslationCounterpart($contentPage, $targetLang);
            if ($targetContentPage === '') {
                jsonResponse(false, null, 'Translation drafts are only available for regular pages.');
            }
            $settings = nibblyAiLoadSettings(true);
            $targetContext = nibblyCopilotBuildContext($targetContentPage, $settings);
            if (empty($targetContext['page']['exists'])) {
                jsonResponse(false, null, 'The ' . strtoupper($targetLang) . ' version of this page does not exist yet. Create it in the dashboard first.');
            }
            $sourceData = nibblyCopilotLoadPageData($contentPage);
            $targetData = nibblyCopilotLoadPageData($targetContentPage);
            $fields = nibblyCopilotTranslationFields($targetContext, $sourceData, $fieldRef !== '' ? $fieldRef : null);
            if (!$fields) {
                jsonResponse(false, null, 'No translatable fields with source content were found for this page.');
            }
            $prompt = nibblyCopilotBuildTranslatePrompt($fields, $sourceLang, $targetLang, substr($instruction, 0, 1200));
            $result = nibblyAiGenerateText($prompt, [
                'feature' => 'backendAssistant',
                'maxOutputTokens' => 3000,
                'temperature' => 0.2,
                'system' => 'You translate website content faithfully for nibbly CMS. Return strict JSON only.'
            ]);
            $raw = nibblyCopilotExtractJsonObject((string)($result['text'] ?? ''));
            $proposals = nibblyCopilotValidateProposals($raw, $targetContext, $targetData, count($fields));
            foreach ($proposals as $index => $proposal) {
                $proposals[$index]['contentPage'] = $targetContentPage;
                $proposals[$index]['label'] = strtoupper($targetLang) . ' · ' . (string)($proposal['label'] ?? $proposal['path']);
            }
            nibblyAiAudit('copilot-translate', true, [
                'contentPage' => $contentPage,
                'targetContentPage' => $targetContentPage,
                'targetLang' => $targetLang,
                'proposalCount' => count($proposals),
                'proposals' => dashboardCopilotProposalAuditSummary($proposals)
            ]);
            jsonResponse(true, [
                'proposals' => $proposals,
                'targetContentPage' => $targetContentPage,
                'targetLang' => $targetLang,
                'usage' => $result['usage'] ?? null,
                'limits' => $result['limits'] ?? null
            ]);
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-translate', false, ['message' => $e->getMessage(), 'contentPage' => $contentPage]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-format-html':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('suggestField')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('format-html', 20, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        $fieldRef = trim((string)($_POST['fieldRef'] ?? ''));
        $format = trim((string)($_POST['format'] ?? ''));
        $instruction = trim((string)($_POST['instruction'] ?? ''));
        if ($contentPage === '' || $fieldRef === '' || $format === '') {
            jsonResponse(false, null, 'Content page, HTML field, and format action are required');
        }
        try {
            $settings = nibblyAiLoadSettings(true);
            $context = nibblyCopilotBuildContext($contentPage, $settings);
            $proposal = nibblyCopilotBuildHtmlFormatProposal($contentPage, $fieldRef, $format, $instruction);
            nibblyAiAudit('copilot-format-html', true, [
                'contentPage' => $contentPage,
                'fieldRef' => $fieldRef,
                'format' => nibblyCopilotNormalizeFormatOperation($format),
                'proposals' => dashboardCopilotProposalAuditSummary([$proposal])
            ]);
            jsonResponse(true, [
                'proposals' => [$proposal],
                'context' => $context
            ]);
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-format-html', false, ['message' => $e->getMessage(), 'contentPage' => $contentPage, 'fieldRef' => $fieldRef]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-visibility':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('toggleVisibility')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('visibility', 20, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        $fieldRef = trim((string)($_POST['fieldRef'] ?? ''));
        $visibilityAction = trim((string)($_POST['visibilityAction'] ?? $_POST['actionValue'] ?? ''));
        $instruction = trim((string)($_POST['instruction'] ?? ''));
        if ($contentPage === '' || $fieldRef === '' || $visibilityAction === '') {
            jsonResponse(false, null, 'Content page, field, and visibility action are required');
        }
        try {
            $settings = nibblyAiLoadSettings(true);
            $context = nibblyCopilotBuildContext($contentPage, $settings);
            $proposal = nibblyCopilotBuildVisibilityProposal($contentPage, $fieldRef, $visibilityAction, $instruction);
            nibblyAiAudit('copilot-visibility', true, [
                'contentPage' => $contentPage,
                'fieldRef' => $fieldRef,
                'visibilityAction' => nibblyCopilotNormalizeVisibilityAction($visibilityAction),
                'proposals' => dashboardCopilotProposalAuditSummary([$proposal])
            ]);
            jsonResponse(true, [
                'proposals' => [$proposal],
                'context' => $context
            ]);
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-visibility', false, ['message' => $e->getMessage(), 'contentPage' => $contentPage, 'fieldRef' => $fieldRef]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-apply':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('applyField')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        if (!dashboardCopilotConfirmed()) {
            jsonResponse(false, null, 'AI write action requires explicit confirmation');
        }
        try {
            nibblyCopilotAssertBurstLimit('apply', 30, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        $path = trim((string)($_POST['path'] ?? ''));
        $value = (string)($_POST['value'] ?? '');
        $altValue = (string)($_POST['altValue'] ?? '');
        $currentHash = trim((string)($_POST['currentHash'] ?? ''));
        $allowedValueHashes = json_decode((string)($_POST['allowedValueHashes'] ?? '[]'), true);
        if (!is_array($allowedValueHashes)) {
            $allowedValueHashes = [];
        }
        $proposalSignature = trim((string)($_POST['proposalSignature'] ?? ''));
        if ($contentPage === '' || $path === '') {
            jsonResponse(false, null, 'Missing field target');
        }
        try {
            $applied = nibblyCopilotApplyFieldUpdate($contentPage, $path, $value, $currentHash, $altValue, $allowedValueHashes, $proposalSignature);
            $filepath = function_exists('nibblyCopilotContentPath') ? nibblyCopilotContentPath($contentPage) : '';
            if ($filepath === '') {
                throw new RuntimeException('Unsupported AI field update target.');
            }
            $backupName = nibblyPageIsValidContentKey($contentPage)
                ? dashboardCopilotCreatePageBackup($contentPage)
                : '';
            $written = nibblyJsonAtomicWrite($filepath, $applied['data']);
            if ($written === false) {
                throw new RuntimeException('Could not save AI field update.');
            }
            nibblyAiAudit('copilot-apply', true, [
                'contentPage' => $contentPage,
                'path' => $path,
                'type' => $applied['field']['type'] ?? '',
                'oldHash' => hash('sha256', (string)$applied['oldValue']),
                'newHash' => hash('sha256', (string)$applied['newValue'])
            ]);
            $response = [
                'contentPage' => $contentPage,
                'path' => $path,
                'value' => $applied['newValue'],
                'altValue' => $applied['altValue'] ?? '',
                'lastModified' => $applied['data']['lastModified'] ?? null,
            ];
            if ($backupName !== '') {
                $response['undo'] = [
                    'contentPage' => $contentPage,
                    'backup' => $backupName,
                    'path' => $path,
                    'undoSignature' => dashboardCopilotUndoSignature($contentPage, $backupName, $path)
                ];
            }
            if (($pageParts = nibblyPageParseContentKey($contentPage)) !== null) {
                $response['seoHealth'] = buildPageSeoHealth($pageParts['lang'], $pageParts['path'], $applied['data']);
            }
            jsonResponse(true, $response, 'AI field update applied');
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-apply', false, ['message' => $e->getMessage(), 'contentPage' => $contentPage, 'path' => $path]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-apply-visibility':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('toggleVisibility')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        if (!dashboardCopilotConfirmed()) {
            jsonResponse(false, null, 'AI visibility action requires explicit confirmation');
        }
        try {
            nibblyCopilotAssertBurstLimit('apply-visibility', 30, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        $path = trim((string)($_POST['path'] ?? ''));
        $visibilityAction = trim((string)($_POST['value'] ?? $_POST['visibilityAction'] ?? ''));
        $currentHash = trim((string)($_POST['currentHash'] ?? ''));
        $visibilitySignature = trim((string)($_POST['visibilitySignature'] ?? ''));
        if ($contentPage === '' || $path === '' || $visibilityAction === '') {
            jsonResponse(false, null, 'Missing visibility target');
        }
        try {
            $applied = nibblyCopilotApplyVisibilityUpdate($contentPage, $path, $visibilityAction, $currentHash, $visibilitySignature);
            $filepath = CONTENT_PATH . $contentPage . '.json';
            $backupName = dashboardCopilotCreatePageBackup($contentPage);
            $written = nibblyJsonAtomicWrite($filepath, $applied['data']);
            if ($written === false) {
                throw new RuntimeException('Could not save AI visibility update.');
            }
            nibblyAiAudit('copilot-apply-visibility', true, [
                'contentPage' => $contentPage,
                'path' => $path,
                'hiddenPath' => $applied['hiddenPath'] ?? '',
                'oldHidden' => !empty($applied['oldHidden']),
                'newHidden' => !empty($applied['newHidden'])
            ]);
            $undoPath = (string)($applied['hiddenPath'] ?? ($path . '__hidden'));
            jsonResponse(true, [
                'contentPage' => $contentPage,
                'path' => $path,
                'hiddenPath' => $undoPath,
                'value' => $applied['newValue'],
                'hidden' => !empty($applied['newHidden']),
                'lastModified' => $applied['data']['lastModified'] ?? null,
                'undo' => [
                    'contentPage' => $contentPage,
                    'backup' => $backupName,
                    'path' => $undoPath,
                    'undoSignature' => dashboardCopilotUndoSignature($contentPage, $backupName, $undoPath)
                ]
            ], 'AI visibility update applied');
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-apply-visibility', false, ['message' => $e->getMessage(), 'contentPage' => $contentPage, 'path' => $path]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-undo':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('undoField')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        if (!dashboardCopilotConfirmed()) {
            jsonResponse(false, null, 'AI undo action requires explicit confirmation');
        }
        try {
            nibblyCopilotAssertBurstLimit('undo', 20, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        $backup = trim((string)($_POST['backup'] ?? ''));
        $undoPath = trim((string)($_POST['path'] ?? ''));
        $undoSignature = trim((string)($_POST['undoSignature'] ?? ''));
        if (!validatePageName($contentPage) || !validateBackupName($backup)) {
            jsonResponse(false, null, 'Invalid undo target');
        }
        if ($undoPath === '' || $undoSignature === '' || !hash_equals(dashboardCopilotUndoSignature($contentPage, $backup, $undoPath), $undoSignature)) {
            jsonResponse(false, null, 'Undo signature is missing or invalid');
        }
        $expectedPrefix = $contentPage . '_';
        if (!str_starts_with($backup, $expectedPrefix)) {
            jsonResponse(false, null, 'Backup does not belong to this page');
        }
        $backupPath = BACKUP_PATH . $backup;
        $filepath = CONTENT_PATH . $contentPage . '.json';
        if (!is_file($backupPath) || !is_file($filepath)) {
            jsonResponse(false, null, 'Undo backup not found');
        }
        try {
            $currentBackup = dashboardCopilotCreatePageBackup($contentPage, false);
            if (!copy($backupPath, $filepath)) {
                throw new RuntimeException('Could not restore AI backup.');
            }
            $restored = json_decode((string)file_get_contents($filepath), true);
            if (!is_array($restored)) {
                throw new RuntimeException('Restored backup is not valid JSON.');
            }
            $restored['lastModified'] = date('c');
            if (file_put_contents($filepath, json_encode($restored, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
                throw new RuntimeException('Could not update restored page metadata.');
            }
            cleanupOldBackups($contentPage);
            nibblyAiAudit('copilot-undo', true, [
                'contentPage' => $contentPage,
                'backup' => $backup,
                'currentBackup' => $currentBackup
            ]);
            $response = [
                'contentPage' => $contentPage,
                'backup' => $backup,
                'lastModified' => $restored['lastModified'] ?? null
            ];
            if (($pageParts = nibblyPageParseContentKey($contentPage)) !== null) {
                $response['seoHealth'] = buildPageSeoHealth($pageParts['lang'], $pageParts['path'], $restored);
            }
            jsonResponse(true, $response, 'AI change restored from backup');
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-undo', false, ['message' => $e->getMessage(), 'contentPage' => $contentPage, 'backup' => $backup]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-draft-content':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('createPage') && !nibblyCopilotCan('createNews') && !nibblyCopilotCan('createEvent')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('draft-content', 8, 60);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $instruction = trim((string)($_POST['instruction'] ?? ''));
        $contentType = trim((string)($_POST['contentType'] ?? ''));
        if ($instruction === '') {
            jsonResponse(false, null, 'Instruction is required');
        }
        try {
            $settings = nibblyAiLoadSettings(true);
            $context = nibblyCopilotBuildContext(trim((string)($_POST['contentPage'] ?? '')), $settings);
            $existingDraftPayload = json_decode((string)($_POST['existingDraft'] ?? '[]'), true);
            $existingDraft = [];
            if (is_array($existingDraftPayload) && isset($existingDraftPayload['contentType'], $existingDraftPayload['draft'])) {
                $existingDraft = [
                    'contentType' => (string)$existingDraftPayload['contentType'],
                    'missing' => array_values(array_filter(array_map('strval', is_array($existingDraftPayload['missing'] ?? null) ? $existingDraftPayload['missing'] : []))),
                    'draft' => is_array($existingDraftPayload['draft'] ?? null) ? $existingDraftPayload['draft'] : []
                ];
                if ($contentType === '') {
                    $contentType = $existingDraft['contentType'];
                }
            }
            $prompt = nibblyCopilotBuildCreatePrompt($context, substr($instruction, 0, 2400), $contentType, $existingDraft);
            $result = nibblyAiGenerateText($prompt, [
                'feature' => 'backendAssistant',
                // Full content drafts are quality-sensitive: use the chat
                // model instead of the (typically cheaper) text model.
                'model' => (string)($settings['chatModel'] ?? ''),
                'maxOutputTokens' => 1200,
                'temperature' => 0.15,
                'system' => 'You extract safe nibbly CMS content drafts. Return strict JSON only.'
            ]);
            $raw = nibblyCopilotExtractJsonObject((string)($result['text'] ?? ''));
            $draft = nibblyCopilotSignCreateDraft(nibblyCopilotNormalizeCreateDraft($raw, $context));
            nibblyAiAudit('copilot-draft-content', true, [
                'contentType' => $draft['contentType'],
                'canCreate' => $draft['canCreate'],
                'missingCount' => count($draft['missing']),
                'missing' => $draft['missing'],
                'draftHash' => (string)($draft['draftHash'] ?? ''),
                'signed' => !empty($draft['draftSignature'])
            ]);
            jsonResponse(true, [
                'draft' => $draft,
                'usage' => $result['usage'] ?? null,
                'limits' => $result['limits'] ?? null
            ]);
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-draft-content', false, ['message' => $e->getMessage()]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-create-content':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        if (!dashboardCopilotConfirmed()) {
            jsonResponse(false, null, 'AI content creation requires explicit confirmation');
        }
        try {
            nibblyCopilotAssertBurstLimit('create-content', 15, 300);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $draftPayload = json_decode((string)($_POST['draft'] ?? ''), true);
        if (!is_array($draftPayload)) {
            jsonResponse(false, null, 'Invalid draft JSON');
        }
        try {
            $type = (string)($draftPayload['contentType'] ?? '');
            $permissionMap = ['page' => 'createPage', 'news' => 'createNews', 'event' => 'createEvent'];
            if (empty($permissionMap[$type]) || !nibblyCopilotCan($permissionMap[$type])) {
                throw new RuntimeException('You do not have permission to create this content type.');
            }
            if (!nibblyCopilotVerifyCreateDraftSignature($draftPayload)) {
                throw new RuntimeException('Draft signature is missing or invalid. Generate a fresh preview before creating content.');
            }
            $draft = is_array($draftPayload['draft'] ?? null) ? $draftPayload['draft'] : [];
            $expectedHash = trim((string)($draftPayload['draftHash'] ?? ''));
            $actualHash = hash('sha256', json_encode([$type, $draft], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
                throw new RuntimeException('Draft changed after preview. Generate a fresh draft before creating content.');
            }
            $created = nibblyCopilotBuildCreatedContent($type, $draft);
            if ($type === 'event') {
                $eventsPath = defined('EVENTS_PATH') ? EVENTS_PATH : dirname(CONTENT_PATH) . '/events.json';
                $data = is_file($eventsPath) ? (json_decode((string)file_get_contents($eventsPath), true) ?: ['events' => []]) : ['events' => []];
                foreach ($data['events'] ?? [] as $event) {
                    if (($event['id'] ?? '') === ($created['id'] ?? '')) {
                        throw new RuntimeException('An event with this ID already exists.');
                    }
                }
                if (is_file($eventsPath)) {
                    $timestamp = date('Y-m-d_His');
                    copy($eventsPath, BACKUP_PATH . 'events_' . $timestamp . '.json');
                }
                $data['events'][] = $created;
                $data['lastModified'] = date('c');
                if (file_put_contents($eventsPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                    throw new RuntimeException('Could not save event draft.');
                }
                $response = [
                    'contentType' => 'event',
                    'id' => $created['id'],
                    'hidden' => true,
                    'publishable' => nibblyCopilotCan('publishEvent'),
                    'adminUrl' => dashboardCopilotAdminUrl('events')
                ];
            } elseif ($type === 'news') {
                $newsDir = dirname(CONTENT_PATH) . '/news/';
                if (!is_dir($newsDir)) {
                    mkdir($newsDir, 0755, true);
                }
                $filepath = $newsDir . $created['id'] . '.json';
                if (is_file($filepath)) {
                    throw new RuntimeException('A news post with this ID already exists.');
                }
                if (file_put_contents($filepath, json_encode($created, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                    throw new RuntimeException('Could not save news draft.');
                }
                $response = [
                    'contentType' => 'news',
                    'id' => $created['id'],
                    'hidden' => true,
                    'publishable' => nibblyCopilotCan('publishNews'),
                    'adminUrl' => dashboardCopilotAdminUrl('news'),
                    'publicUrl' => dashboardCopilotNewsUrl($created['id'], (string)($created['lang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en')))
                ];
            } elseif ($type === 'page') {
                $pageName = $created['pageName'] ?? '';
                if (!validatePageName($pageName)) {
                    throw new RuntimeException('Invalid page draft name.');
                }
                $filepath = CONTENT_PATH . $pageName . '.json';
                if (is_file($filepath)) {
                    throw new RuntimeException('A page with this slug already exists.');
                }
                if (file_put_contents($filepath, json_encode($created['content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                    throw new RuntimeException('Could not save page draft.');
                }
                $response = [
                    'contentType' => 'page',
                    'id' => $pageName,
                    'private' => true,
                    'publishable' => nibblyCopilotCan('publishPage'),
                    'adminUrl' => dashboardCopilotAdminUrl('page/' . $pageName),
                    'publicUrl' => dashboardCopilotPageUrl($pageName)
                ];
            } else {
                throw new RuntimeException('Unsupported content type.');
            }
            nibblyAiAudit('copilot-create-content', true, $response);
            jsonResponse(true, $response, 'AI content draft created');
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-create-content', false, ['message' => $e->getMessage()]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-publish-content':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        if (!dashboardCopilotConfirmed()) {
            jsonResponse(false, null, 'AI publish action requires explicit confirmation');
        }
        try {
            nibblyCopilotAssertBurstLimit('publish-content', 15, 300);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $type = trim((string)($_POST['contentType'] ?? ''));
        $id = trim((string)($_POST['id'] ?? ''));
        if (!in_array($type, ['page', 'news', 'event'], true) || $id === '') {
            jsonResponse(false, null, 'Invalid publish target');
        }
        $permissionMap = ['page' => 'publishPage', 'news' => 'publishNews', 'event' => 'publishEvent'];
        if (!nibblyCopilotCan($permissionMap[$type])) {
            jsonResponse(false, null, 'Forbidden');
        }
        try {
            if ($type === 'page') {
                if (!validatePageName($id)) {
                    throw new RuntimeException('Invalid page name.');
                }
                $filepath = CONTENT_PATH . $id . '.json';
                if (!is_file($filepath)) {
                    throw new RuntimeException('Page not found.');
                }
                $backupName = dashboardCopilotCreatePageBackup($id);
                $data = json_decode((string)file_get_contents($filepath), true);
                if (!is_array($data)) {
                    throw new RuntimeException('Invalid page JSON.');
                }
                $data = nibblyCopilotPublishPageData($data);
                if (file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                    throw new RuntimeException('Could not publish page.');
                }
                $response = [
                    'contentType' => 'page',
                    'id' => $id,
                    'private' => false,
                    'published' => true,
                    'backup' => $backupName,
                    'adminUrl' => dashboardCopilotAdminUrl('page/' . $id),
                    'publicUrl' => dashboardCopilotPageUrl($id)
                ];
            } elseif ($type === 'news') {
                if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) {
                    throw new RuntimeException('Invalid news ID.');
                }
                $newsDir = dirname(CONTENT_PATH) . '/news/';
                $filepath = $newsDir . $id . '.json';
                if (!is_file($filepath)) {
                    throw new RuntimeException('News post not found.');
                }
                $backupPath = BACKUP_PATH . 'news_' . $id . '_' . date('Y-m-d_His') . '.json';
                copy($filepath, $backupPath);
                $post = json_decode((string)file_get_contents($filepath), true);
                if (!is_array($post)) {
                    throw new RuntimeException('Invalid news JSON.');
                }
                $post = nibblyCopilotPublishNewsData($post);
                if (file_put_contents($filepath, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                    throw new RuntimeException('Could not publish news post.');
                }
                $response = [
                    'contentType' => 'news',
                    'id' => $id,
                    'hidden' => false,
                    'published' => true,
                    'adminUrl' => dashboardCopilotAdminUrl('news'),
                    'publicUrl' => dashboardCopilotNewsUrl($id, (string)($post['lang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en')))
                ];
            } else {
                $eventsPath = defined('EVENTS_PATH') ? EVENTS_PATH : dirname(CONTENT_PATH) . '/events.json';
                if (!is_file($eventsPath)) {
                    throw new RuntimeException('Events file not found.');
                }
                $data = json_decode((string)file_get_contents($eventsPath), true);
                if (!is_array($data) || !is_array($data['events'] ?? null)) {
                    throw new RuntimeException('Invalid events JSON.');
                }
                $timestamp = date('Y-m-d_His');
                copy($eventsPath, BACKUP_PATH . 'events_' . $timestamp . '.json');
                $data = nibblyCopilotPublishEventData($data, $id);
                if (file_put_contents($eventsPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                    throw new RuntimeException('Could not publish event.');
                }
                $response = [
                    'contentType' => 'event',
                    'id' => $id,
                    'hidden' => false,
                    'published' => true,
                    'adminUrl' => dashboardCopilotAdminUrl('events')
                ];
            }
            nibblyAiAudit('copilot-publish-content', true, $response);
            jsonResponse(true, $response, 'AI-created content published');
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-publish-content', false, ['message' => $e->getMessage(), 'contentType' => $type, 'id' => $id]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-copilot-generate-image':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!nibblyCopilotCan('generateImage')) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            nibblyCopilotAssertBurstLimit('generate-image', 6, 300);
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        $fieldRef = trim((string)($_POST['fieldRef'] ?? ''));
        $instruction = trim((string)($_POST['instruction'] ?? ''));
        $imageMode = trim((string)($_POST['imageMode'] ?? ''));
        if (!in_array($imageMode, ['generate', 'edit'], true)) {
            $imageMode = !empty($_POST['useCurrentAsReference']) ? 'edit' : 'generate';
        }
        $useCurrentAsReference = $imageMode === 'edit' && !empty($_POST['useCurrentAsReference']);
        if ($contentPage === '' || $fieldRef === '' || $instruction === '') {
            jsonResponse(false, null, 'Content page, image field, and prompt are required');
        }
        try {
            $settings = nibblyAiLoadSettings(false);
            nibblyAiEnsureEnabled($settings);
            nibblyAiEnsureFeature($settings, 'imageGeneration');
            if (trim((string)($settings['imageModel'] ?? '')) === '') {
                throw new RuntimeException('Image model is missing.');
            }

            $publicSettings = nibblyAiLoadSettings(true);
            $context = nibblyCopilotBuildContext($contentPage, $publicSettings);
            if (empty($context['page']['exists'])) {
                jsonResponse(false, null, 'Content page not found');
            }
            $fields = nibblyCopilotAllowedImageFields($context, $fieldRef);
            if (!$fields) {
                jsonResponse(false, null, 'Target field does not accept AI image generation.');
            }
            $field = $fields[0];
            $pageData = nibblyCopilotLoadPageData($contentPage);
            $current = getNestedValue($pageData, $field['path']);
            $currentPath = is_array($current) ? (string)($current['src'] ?? '') : (string)$current;
            $referenceMediaPaths = [];
            $referenceImagePaths = [];
            $referenceImageNames = [];
            $temporaryReferencePaths = [];
            if ($useCurrentAsReference && trim($currentPath) !== '') {
                if (nibblyCopilotIsExternalImageUrl($currentPath)) {
                    $externalReference = nibblyCopilotDownloadExternalReferenceImage($currentPath);
                    $referenceImagePaths[] = $externalReference;
                    $referenceImageNames[] = basename(parse_url($currentPath, PHP_URL_PATH) ?: 'external-reference-image');
                    $temporaryReferencePaths[] = $externalReference;
                } else {
                    $referenceMediaPaths[] = nibblyCopilotNormalizeImagePath($currentPath);
                }
            }
            $size = trim((string)($_POST['size'] ?? 'auto'));
            if (!in_array($size, ['auto', '1024x1024', '1536x1024', '1024x1536'], true)) {
                $size = 'auto';
            }
            $count = max(1, min(4, (int)($_POST['count'] ?? 3)));
            $outputFormat = strtolower(trim((string)($_POST['outputFormat'] ?? 'webp')));
            if (!in_array($outputFormat, ['webp', 'png', 'jpeg', 'jpg'], true)) {
                $outputFormat = 'webp';
            }
            if ($outputFormat === 'jpg') {
                $outputFormat = 'jpeg';
            }
            $quality = strtolower(trim((string)($_POST['quality'] ?? 'auto')));
            if (!in_array($quality, ['auto', 'low', 'medium', 'high'], true)) {
                $quality = 'auto';
            }
            $prompt = nibblyCopilotBuildImagePrompt($context, $field, substr($instruction, 0, 1800), $imageMode);
            $job = nibblyAiCreateImageJob('copilot', [
                'contentPage' => $contentPage,
                'fieldRef' => $field['id'],
                'instruction' => $instruction,
                'prompt' => $prompt,
                'imageMode' => $imageMode,
                'options' => [
                    'size' => $size,
                    'aspectRatio' => 'auto',
                    'imageScale' => $_POST['imageScale'] ?? 2048,
                    'count' => $count,
                    'outputFormat' => $outputFormat,
                    'quality' => $quality,
                    'moderation' => 'auto',
                    'outputCompression' => $_POST['outputCompression'] ?? 100,
                    'referenceImagePaths' => $referenceImagePaths,
                    'referenceImageNames' => $referenceImageNames,
                    'referenceMediaPaths' => $referenceMediaPaths,
                    'filenameHint' => nibblyCopilotSlugify($context['page']['slug'] . '-' . $field['path'], 'copilot-image')
                ]
            ]);
            try {
                nibblyAiAudit('copilot-generate-image-queued', true, [
                    'jobId' => $job['id'],
                    'contentPage' => $contentPage,
                    'path' => $field['path'],
                    'imageMode' => $imageMode,
                    'requestedCount' => $count
                ]);
                jsonResponse(true, [
                    'job' => $job,
                    'context' => $context
                ], 'Image generation queued');
            } finally {
                foreach ($temporaryReferencePaths as $temporaryReferencePath) {
                    @unlink($temporaryReferencePath);
                }
            }
        } catch (Throwable $e) {
            nibblyAiAudit('copilot-generate-image', false, ['message' => $e->getMessage(), 'contentPage' => $contentPage, 'fieldRef' => $fieldRef]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-generate-text':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $prompt = trim((string)($_POST['prompt'] ?? ''));
        if ($prompt === '') {
            jsonResponse(false, null, 'Prompt is required');
        }
        try {
            $result = nibblyAiGenerateText($prompt, [
                'feature' => 'seoTextGeneration',
                'maxOutputTokens' => $_POST['maxOutputTokens'] ?? null
            ]);
            jsonResponse(true, $result);
        } catch (Throwable $e) {
            nibblyAiAudit('text', false, ['message' => $e->getMessage()]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-generate-seo':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $context = json_decode((string)($_POST['context'] ?? '{}'), true);
        if (!is_array($context)) {
            jsonResponse(false, null, 'Invalid page context JSON');
        }
        $field = trim((string)($_POST['field'] ?? 'all'));
        $allowedFields = ['all', 'title', 'description', 'answerSummary', 'ogTitle', 'ogDescription'];
        if (!in_array($field, $allowedFields, true)) {
            jsonResponse(false, null, 'Invalid SEO field');
        }
        $context = [
            'lang' => substr((string)($context['lang'] ?? ''), 0, 8),
            'slug' => substr((string)($context['slug'] ?? ''), 0, 120),
            'title' => substr((string)($context['title'] ?? ''), 0, 180),
            'description' => substr((string)($context['description'] ?? ''), 0, 500),
            'seo' => is_array($context['seo'] ?? null) ? array_intersect_key($context['seo'], array_flip(['title', 'description', 'answerSummary', 'ogTitle', 'ogDescription'])) : [],
            'contentText' => substr((string)($context['contentText'] ?? ''), 0, 9000)
        ];
        $fieldInstruction = $field === 'all'
            ? 'Fill every JSON field.'
            : 'Fill only the JSON field "' . $field . '" and still return a JSON object with that single key.';
        $prompt = "Create practical SEO/AEO metadata for this nibbly CMS page.\n"
            . "Return strict JSON only, no Markdown and no prose.\n"
            . "Allowed keys: title, description, answerSummary, ogTitle, ogDescription.\n"
            . "Constraints: title <= 70 characters; description <= 160 characters; answerSummary <= 320 characters; ogTitle <= 70 characters; ogDescription <= 180 characters.\n"
            . "Use the page language if possible. Do not invent facts, names, offers, prices, certifications, or locations that are not implied by the content.\n"
            . $fieldInstruction . "\n\n"
            . "Page context:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $result = nibblyAiGenerateText($prompt, [
                'feature' => 'seoTextGeneration',
                'maxOutputTokens' => 900,
                'temperature' => 0.25,
                'system' => 'You generate SEO and answer-engine metadata for a CMS. Return valid compact JSON only.'
            ]);
            $text = trim((string)($result['text'] ?? ''));
            $json = $text;
            if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m)) {
                $json = trim($m[1]);
            } elseif (preg_match('/\{.*\}/s', $text, $m)) {
                $json = $m[0];
            }
            $data = json_decode($json, true);
            if (!is_array($data)) {
                throw new RuntimeException('AI did not return valid SEO JSON.');
            }
            $clean = [];
            $limits = [
                'title' => 90,
                'description' => 180,
                'answerSummary' => 420,
                'ogTitle' => 90,
                'ogDescription' => 220
            ];
            foreach ($limits as $key => $limit) {
                if (($field === 'all' || $field === $key) && isset($data[$key])) {
                    $clean[$key] = substr(trim((string)$data[$key]), 0, $limit);
                }
            }
            jsonResponse(true, ['fields' => $clean, 'limits' => $result['limits'] ?? null]);
        } catch (Throwable $e) {
            nibblyAiAudit('seo-generate', false, ['message' => $e->getMessage(), 'field' => $field]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-openrouter-models':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        try {
            jsonResponse(true, nibblyApiOpenRouterModels(!empty($_POST['refresh']) || !empty($_GET['refresh'])));
        } catch (Throwable $e) {
            jsonResponse(false, null, 'Could not load the OpenRouter model list: ' . $e->getMessage());
        }
        break;

    case 'ai-content-audit':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $rows = [];
        foreach (glob(rtrim(CONTENT_PATH, '/') . '/*.json') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (!nibblyPageIsValidContentKey($name)) {
                continue;
            }
            $data = dashboardReadJsonFile($file);
            if (!$data) {
                continue;
            }
            $description = trim((string)(($data['seo']['description'] ?? '') ?: ($data['description'] ?? '')));
            $rows[] = [
                'contentPage' => $name,
                'lang' => (string)($data['lang'] ?? substr($name, 0, 2)),
                'title' => substr((string)($data['title'] ?? $name), 0, 120),
                'descriptionStatus' => nibblyAuditDescriptionStatus($description),
                'descriptionLength' => function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description),
                'missingAlt' => nibblyAuditCountMissingAlt($data['sections'] ?? [])
            ];
        }
        usort($rows, function (array $a, array $b): int {
            $rank = fn(array $row): int => ($row['descriptionStatus'] === 'ok' ? 0 : 2) + ($row['missingAlt'] > 0 ? 1 : 0);
            return $rank($b) <=> $rank($a) ?: strcmp($a['contentPage'], $b['contentPage']);
        });
        jsonResponse(true, ['pages' => $rows]);
        break;

    case 'ai-content-audit-suggest':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        if (!nibblyPageIsValidContentKey($contentPage)) {
            jsonResponse(false, null, 'Invalid content page');
        }
        $data = dashboardReadJsonFile(CONTENT_PATH . $contentPage . '.json');
        if (!$data) {
            jsonResponse(false, null, 'Content page not found');
        }
        try {
            $lang = (string)($data['lang'] ?? substr($contentPage, 0, 2));
            $prompt = "Write one SEO meta description (45-160 characters) in the language \"{$lang}\" for this website page.\n"
                . "Return only the description text without quotes, labels, or Markdown.\n"
                . "Do not invent facts, names, offers, prices, certifications, or locations that are not implied by the content.\n\n"
                . 'Page title: ' . (string)($data['title'] ?? $contentPage) . "\n"
                . "Page content:\n" . nibblyAuditPageText($data);
            $result = nibblyAiGenerateText($prompt, [
                'feature' => 'seoTextGeneration',
                'maxOutputTokens' => 220,
                'temperature' => 0.3
            ]);
            $description = trim((string)($result['text'] ?? ''), " \t\n\r\0\x0B\"'");
            $description = substr(preg_replace('/\s+/', ' ', strip_tags($description)) ?? '', 0, 300);
            if ($description === '') {
                throw new RuntimeException('AI returned no description.');
            }
            jsonResponse(true, [
                'contentPage' => $contentPage,
                'description' => $description,
                'limits' => $result['limits'] ?? null
            ]);
        } catch (Throwable $e) {
            nibblyAiAudit('content-audit-suggest', false, ['contentPage' => $contentPage, 'message' => $e->getMessage()]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-content-audit-apply':
        if (!dashboardAiModuleEnabled()) {
            jsonResponse(false, null, 'AI module is disabled');
        }
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        if (!dashboardCopilotConfirmed()) {
            jsonResponse(false, null, 'AI write action requires explicit confirmation');
        }
        $contentPage = trim((string)($_POST['contentPage'] ?? ''));
        if (!nibblyPageIsValidContentKey($contentPage)) {
            jsonResponse(false, null, 'Invalid content page');
        }
        $description = trim((string)($_POST['description'] ?? ''));
        $description = substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', strip_tags($description)) ?? '', 0, 300);
        if ($description === '') {
            jsonResponse(false, null, 'Description is required');
        }
        try {
            $filepath = CONTENT_PATH . $contentPage . '.json';
            $data = dashboardReadJsonFile($filepath);
            if (!$data) {
                jsonResponse(false, null, 'Content page not found');
            }
            $backup = dashboardCopilotCreatePageBackup($contentPage);
            $data['description'] = $description;
            if (is_array($data['seo'] ?? null) && array_key_exists('description', $data['seo'])) {
                $data['seo']['description'] = $description;
            }
            $data['lastModified'] = date('c');
            if (file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                throw new RuntimeException('Could not save the page.');
            }
            nibblyAiAudit('content-audit-apply', true, [
                'contentPage' => $contentPage,
                'backup' => $backup,
                'descriptionHash' => hash('sha256', $description)
            ]);
            jsonResponse(true, ['contentPage' => $contentPage, 'backup' => $backup]);
        } catch (Throwable $e) {
            nibblyAiAudit('content-audit-apply', false, ['contentPage' => $contentPage, 'message' => $e->getMessage()]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'ai-generate-image':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $prompt = trim((string)($_POST['prompt'] ?? ''));
        try {
            $referenceImagePaths = [];
            $referenceImageNames = [];
            if (!empty($_FILES['referenceImages']['tmp_name']) && is_array($_FILES['referenceImages']['tmp_name'])) {
                foreach ($_FILES['referenceImages']['tmp_name'] as $idx => $tmpName) {
                    if (is_string($tmpName) && $tmpName !== '') {
                        $referenceImagePaths[] = $tmpName;
                        $referenceImageNames[] = (string)($_FILES['referenceImages']['name'][$idx] ?? 'reference-image');
                    }
                }
            } elseif (!empty($_FILES['referenceImage']['tmp_name'])) {
                $referenceImagePaths[] = (string)$_FILES['referenceImage']['tmp_name'];
                $referenceImageNames[] = (string)($_FILES['referenceImage']['name'] ?? 'reference-image');
            }
            $referenceMediaPaths = $_POST['referenceMediaPaths'] ?? ($_POST['referenceMediaPath'] ?? []);
            if (!is_array($referenceMediaPaths)) {
                $referenceMediaPaths = [$referenceMediaPaths];
            }
            $imageOptions = [
                'size' => $_POST['size'] ?? '1024x1024',
                'aspectRatio' => $_POST['aspectRatio'] ?? 'auto',
                'imageScale' => $_POST['imageScale'] ?? 2048,
                'model' => $_POST['model'] ?? null,
                'count' => $_POST['count'] ?? 1,
                'outputFormat' => $_POST['outputFormat'] ?? 'webp',
                'quality' => $_POST['quality'] ?? 'auto',
                'moderation' => $_POST['moderation'] ?? 'auto',
                'outputCompression' => $_POST['outputCompression'] ?? 100,
                'referenceImagePaths' => $referenceImagePaths,
                'referenceImageNames' => $referenceImageNames,
                'referenceMediaPaths' => $referenceMediaPaths,
                'filenameHint' => $_POST['filenameHint'] ?? 'ai-image'
            ];
            $job = nibblyAiCreateImageJob('dashboard', [
                'prompt' => $prompt,
                'options' => $imageOptions
            ]);
            nibblyAiAudit('image-queued', true, [
                'jobId' => $job['id'],
                'model' => (string)($imageOptions['model'] ?? ''),
                'count' => (int)($imageOptions['count'] ?? 1)
            ]);
            jsonResponse(true, ['job' => $job], 'Image generation queued');
        } catch (Throwable $e) {
            nibblyAiAudit('image', false, ['message' => $e->getMessage()]);
            $settings = nibblyAiLoadSettings(false);
            nibblyAiRecordImageHistory([
                'status' => 'error',
                'model' => (string)($_POST['model'] ?? $settings['imageModel'] ?? ''),
                'prompt' => $prompt,
                'size' => (string)($_POST['size'] ?? ''),
                'aspectRatio' => (string)($_POST['aspectRatio'] ?? ''),
                'quality' => (string)($_POST['quality'] ?? ''),
                'format' => (string)($_POST['outputFormat'] ?? ''),
                'moderation' => (string)($_POST['moderation'] ?? ''),
                'compression' => (int)($_POST['outputCompression'] ?? 0),
                'count' => (int)($_POST['count'] ?? 0),
                'referenceImages' => nibblyAiPublicReferenceList([
                    'referenceImageNames' => $referenceImageNames ?? [],
                    'referenceMediaPaths' => $referenceMediaPaths ?? []
                ]),
                'outputs' => [],
                'error' => $e->getMessage(),
                'estimatedCostCents' => 0
            ]);
            jsonResponse(false, null, $e->getMessage());
        }
        break;

}
