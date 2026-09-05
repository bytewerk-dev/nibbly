<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

/**
 * API endpoint for Content Management
 * Actions: load, save, backups, restore, delete-backup, events, images, audio, mails
 */

require_once NIBBLY_ADMIN_DIR . '/config.php';
require_once NIBBLY_ADMIN_DIR . '/users.php';
require_once NIBBLY_ADMIN_DIR . '/lang/i18n.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/content-loader.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/page-path.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/seo-helper.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/ai/ai-helper.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/ai/copilot-context.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/ai/image-job-runner.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/analytics-helper.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/forms.php';
ensureUsersFile();

// Prevent PHP HTML error output from corrupting JSON responses
ini_set('html_errors', '0');
ini_set('display_errors', '0');

require_once NIBBLY_ADMIN_DIR . '/../includes/session-helper.php';
nibblySessionStart();

header('Content-Type: application/json; charset=utf-8');

function isAuthenticated() {
    return nibblySessionValidate();
}

// CSRF token validation
function validateCsrfToken() {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// JSON response helper
function jsonResponse($success, $data = null, $message = '') {
    if (!$success && http_response_code() < 400) {
        http_response_code($message === 'Forbidden' ? 403 : 400);
    }
    $messageKeys = ['Forbidden' => 'api.forbidden', 'Invalid CSRF token' => 'api.csrf', 'Invalid JSON format' => 'api.invalid_json', 'Error saving' => 'api.storage', 'Error saving settings' => 'api.storage'];
    $message = isset($messageKeys[$message]) ? t($messageKeys[$message]) : $message;
    echo json_encode([
        'revision' => isset($GLOBALS['nibblyRevisionPath']) ? nibblyJsonRevision($GLOBALS['nibblyRevisionPath']) : null,
        'success' => $success,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function nibblyParseEmailList($value): array {
    $rawItems = is_array($value) ? $value : explode(',', (string)$value);
    $emails = [];

    foreach ($rawItems as $item) {
        foreach (explode(',', (string)$item) as $email) {
            $email = trim(str_replace(["\r", "\n", "\t"], '', (string)$email));
            if ($email !== '') {
                $emails[] = $email;
            }
        }
    }

    return array_values(array_unique($emails));
}

function nibblyNormalizeEmailList($value): string {
    return implode(', ', nibblyParseEmailList($value));
}

function nibblyValidateEmailList($value): bool {
    foreach (nibblyParseEmailList($value) as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
    }

    return true;
}

function nibblyApiCurrentAiUser(): string {
    return (string)($_SESSION['admin_username'] ?? ($_SESSION['admin_user_id'] ?? ''));
}

function nibblyApiCanUseImageJobs(): bool {
    return isAdmin() || (dashboardAiModuleEnabled() && nibblyCopilotCan('generateImage'));
}

function nibblyApiAssertImageJobAccess(array $job): void {
    if (isAdmin()) {
        return;
    }
    if ((string)($job['user'] ?? '') !== nibblyApiCurrentAiUser()) {
        throw new RuntimeException('Forbidden');
    }
}

/**
 * Send the JSON response now and keep this PHP process alive to finish work
 * in the background. Works on PHP-FPM (most shared hosting) without cron;
 * returns false when detaching is unavailable so callers run synchronously.
 */
function nibblyApiDetachResponse(array $payload): bool {
    if (!function_exists('fastcgi_finish_request')) {
        return false;
    }
    ignore_user_abort(true);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    fastcgi_finish_request();
    return true;
}

function nibblyApiRunImageJob(string $jobId): array {
    return nibblyAiRunImageJobCore($jobId, 'nibblyApiAssertImageJobAccess');
}

function nibblyApiTrySpawnLocalImageJobWorker(string $jobId): bool {
    if (PHP_SAPI !== 'cli-server') {
        return false;
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $jobId)) {
        return false;
    }
    $php = PHP_BINARY;
    $script = dirname(NIBBLY_ADMIN_DIR) . '/cli/ai-image-job-worker.php';
    if (!is_file($script) || $php === '') {
        return false;
    }
    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $command = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId);
        pclose(popen($command, 'r'));
    } else {
        $command = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId) . ' > /dev/null 2>&1 &';
        exec($command);
    }
    nibblyAiAudit('image-job-worker-spawned', true, ['jobId' => $jobId, 'sapi' => PHP_SAPI]);
    return true;
}

function nibblyNormalizeHexColor(string $value): string {
    return strtolower(trim($value));
}

function nibblyHexToRgb(string $hex): array {
    $hex = ltrim(nibblyNormalizeHexColor($hex), '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    ];
}

function nibblyRgbToHex(array $rgb): string {
    return sprintf(
        '#%02x%02x%02x',
        max(0, min(255, (int)round($rgb[0]))),
        max(0, min(255, (int)round($rgb[1]))),
        max(0, min(255, (int)round($rgb[2])))
    );
}

function nibblyRelativeLuminance(string $hex): float {
    $channels = array_map(function ($channel) {
        $value = $channel / 255;
        return $value <= 0.03928
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }, nibblyHexToRgb($hex));

    return ($channels[0] * 0.2126) + ($channels[1] * 0.7152) + ($channels[2] * 0.0722);
}

function nibblyContrastRatio(string $a, string $b): float {
    $l1 = nibblyRelativeLuminance($a);
    $l2 = nibblyRelativeLuminance($b);
    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);
    return ($lighter + 0.05) / ($darker + 0.05);
}

function nibblyMixHex(string $a, string $b, float $ratio): string {
    $ar = nibblyHexToRgb($a);
    $br = nibblyHexToRgb($b);
    return nibblyRgbToHex([
        ($ar[0] * $ratio) + ($br[0] * (1 - $ratio)),
        ($ar[1] * $ratio) + ($br[1] * (1 - $ratio)),
        ($ar[2] * $ratio) + ($br[2] * (1 - $ratio))
    ]);
}

function nibblyAdjustColorForContrast(string $hex, string $background, float $minimumRatio, string $direction): string {
    $hex = nibblyNormalizeHexColor($hex);
    if (nibblyContrastRatio($hex, $background) >= $minimumRatio) {
        return $hex;
    }

    $target = $direction === 'lighter' ? '#ffffff' : '#000000';
    for ($step = 1; $step <= 20; $step++) {
        $candidate = nibblyMixHex($hex, $target, 1 - ($step * 0.05));
        if (nibblyContrastRatio($candidate, $background) >= $minimumRatio) {
            return $candidate;
        }
    }

    return $target;
}

function nibblySanitizeThemeContrast(array $theme): array {
    $minReadable = 3.0;

    foreach (['primaryColor', 'accentColor'] as $key) {
        if (!empty($theme[$key])) {
            $theme[$key] = nibblyAdjustColorForContrast((string)$theme[$key], '#ffffff', $minReadable, 'darker');
        }
    }

    foreach (['darkPrimaryColor', 'darkAccentColor'] as $key) {
        if (!empty($theme[$key])) {
            $theme[$key] = nibblyAdjustColorForContrast((string)$theme[$key], '#0b0d12', $minReadable, 'lighter');
        }
    }

    if (empty($theme['darkPrimaryColor']) && !empty($theme['primaryColor']) && nibblyContrastRatio((string)$theme['primaryColor'], '#0b0d12') < $minReadable) {
        $theme['darkPrimaryColor'] = nibblyAdjustColorForContrast((string)$theme['primaryColor'], '#0b0d12', $minReadable, 'lighter');
    }

    if (empty($theme['darkAccentColor']) && !empty($theme['accentColor']) && nibblyContrastRatio((string)$theme['accentColor'], '#0b0d12') < $minReadable) {
        $theme['darkAccentColor'] = nibblyAdjustColorForContrast((string)$theme['accentColor'], '#0b0d12', $minReadable, 'lighter');
    }

    if (!empty($theme['sidebarBg'])) {
        $theme['sidebarBg'] = nibblyAdjustColorForContrast((string)$theme['sidebarBg'], '#1a1a1a', $minReadable, 'lighter');
    }

    if (!empty($theme['darkSidebarBg'])) {
        $theme['darkSidebarBg'] = nibblyAdjustColorForContrast((string)$theme['darkSidebarBg'], '#e5e5e5', $minReadable, 'darker');
    }

    return $theme;
}

function redirectHtml($title, $message, $url = 'dashboard') {
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><meta http-equiv=\"refresh\" content=\"2;url={$safeUrl}\"><title>{$safeTitle}</title></head><body style=\"font-family:system-ui,sans-serif;padding:2rem\"><h1>{$safeTitle}</h1><p>{$safeMessage}</p><p><a href=\"{$safeUrl}\">Back to dashboard</a></p><script>if (window.opener) { window.opener.postMessage({type:'nibbly:backup-oauth', provider:'dropbox'}, window.location.origin); setTimeout(function(){ window.close(); }, 1200); }</script></body></html>";
    exit;
}

// Validate page name (lang_slug format, e.g. de_home, en_example)
function validatePageName($page) {
    return nibblyPageIsValidContentKey((string)$page) || in_array($page, ['sidebar', 'footer']);
}

function dashboardCopilotAdminUrl(string $hash = ''): string {
    $hash = ltrim($hash, '#');
    return '/admin/dashboard' . ($hash !== '' ? '#' . $hash : '');
}

function dashboardCopilotPageUrl(string $pageName): string {
    $page = nibblyPageParseContentKey($pageName);
    if ($page === null) {
        return '';
    }
    return nibblySeoPageUrl($page['lang'], $page['path']);
}

function dashboardCopilotNewsUrl(string $id, string $lang): string {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) || !preg_match('/^[a-z]{2}$/', $lang)) {
        return '';
    }
    $base = nibblySeoBaseUrl();
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $path = $lang === $defaultLang ? '/news/' . $id : '/' . $lang . '/news/' . $id;
    return $base . $path;
}

function dashboardCopilotCreatePageBackup(string $contentPage, bool $cleanup = true): string {
    if (!validatePageName($contentPage)) {
        throw new RuntimeException('Invalid page name');
    }
    $filepath = CONTENT_PATH . $contentPage . '.json';
    if (!is_file($filepath)) {
        return '';
    }
    for ($offset = 0; $offset < 5; $offset++) {
        $timestamp = date('Y-m-d_His', time() + $offset);
        $backupName = $contentPage . '_' . $timestamp . '.json';
        $backupPath = BACKUP_PATH . $backupName;
        if (is_file($backupPath)) {
            continue;
        }
        if (!copy($filepath, $backupPath)) {
            throw new RuntimeException('Could not create backup before AI change.');
        }
        if ($cleanup) {
            cleanupOldBackups($contentPage);
        }
        return $backupName;
    }
    throw new RuntimeException('Could not allocate a backup filename before AI change.');
}

function dashboardCopilotConfirmed(): bool {
    return (string)($_POST['confirmed'] ?? '') === '1';
}

function nibblyApiHttpGetJson(string $url, int $timeout = 12): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'nibbly-cms'
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);
        if ($raw === false) {
            throw new RuntimeException($error !== '' ? $error : 'Request failed.');
        }
    } else {
        $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nUser-Agent: nibbly-cms"]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Request failed.');
        }
        $status = 200;
        $responseHeaders = $http_response_header ?? [];
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $m)) {
            $status = (int)$m[1];
        }
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Request failed with status ' . $status . '.');
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Response is not valid JSON.');
    }
    return $data;
}

/**
 * Fetch the OpenRouter model catalog through the server (the browser never
 * talks to providers directly) with a 24h flat-file cache.
 */
function nibblyApiOpenRouterModels(bool $forceRefresh = false): array {
    $cachePath = dirname(rtrim(CONTENT_PATH, '/')) . '/openrouter-models-cache.json';
    if (!$forceRefresh && is_file($cachePath)) {
        $cache = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cache)
            && (time() - (int)($cache['fetchedAt'] ?? 0)) < 86400
            && is_array($cache['textModels'] ?? null)) {
            return $cache;
        }
    }

    $response = nibblyApiHttpGetJson('https://openrouter.ai/api/v1/models');
    $preferredVendors = ['openai', 'anthropic', 'google', 'mistralai', 'meta-llama', 'deepseek', 'x-ai', 'qwen'];
    $textModels = [];
    $imageModels = [];
    foreach ((is_array($response['data'] ?? null) ? $response['data'] : []) as $model) {
        if (!is_array($model)) {
            continue;
        }
        $id = trim((string)($model['id'] ?? ''));
        if ($id === '' || strlen($id) > 120) {
            continue;
        }
        $name = substr(trim((string)($model['name'] ?? $id)), 0, 120);
        $arch = is_array($model['architecture'] ?? null) ? $model['architecture'] : [];
        $outputs = is_array($arch['output_modalities'] ?? null) ? $arch['output_modalities'] : [];
        $modality = (string)($arch['modality'] ?? '');
        $outputPart = str_contains($modality, '->') ? explode('->', $modality, 2)[1] : '';
        $producesImages = in_array('image', $outputs, true) || str_contains($outputPart, 'image');
        if ($producesImages) {
            $pricing = is_array($model['pricing'] ?? null) ? $model['pricing'] : [];
            // Prefer an explicit per-output-image price (USD). Some models
            // report a per-image-token figure here that is far below a real
            // whole-image cost, so when it is implausibly tiny (< $0.001) fall
            // back to estimating from the completion (output token) price,
            // assuming a typical image is billed at ~1290 output tokens.
            $imageFieldUsd = (float)($pricing['image'] ?? 0);
            $completionUsd = (float)($pricing['completion'] ?? 0);
            $perImageUsd = 0.0;
            $estimated = false;
            if ($imageFieldUsd >= 0.001) {
                $perImageUsd = $imageFieldUsd;
            } elseif ($completionUsd > 0) {
                $perImageUsd = $completionUsd * 1290;
                $estimated = true;
            } elseif ($imageFieldUsd > 0) {
                $perImageUsd = $imageFieldUsd;
                $estimated = true;
            }
            // Store as a float of cents so sub-cent image prices survive
            // (e.g. $0.0004 -> 0.04 cents); the UI formats with precision.
            $imageModels[] = [
                'id' => $id,
                'name' => $name,
                'imageCostCents' => $perImageUsd > 0 ? round($perImageUsd * 100, 4) : null,
                'imageCostEstimated' => $estimated
            ];
            continue;
        }
        $vendor = strtolower((string)strtok($id, '/'));
        if (!in_array($vendor, $preferredVendors, true)) {
            continue;
        }
        $pricing = is_array($model['pricing'] ?? null) ? $model['pricing'] : [];
        // OpenRouter pricing is USD per token; convert to cents per million tokens.
        $textModels[] = [
            'id' => $id,
            'name' => $name,
            'promptCentsPerMillion' => (int)round(((float)($pricing['prompt'] ?? 0)) * 100000000),
            'completionCentsPerMillion' => (int)round(((float)($pricing['completion'] ?? 0)) * 100000000)
        ];
    }
    usort($textModels, fn(array $a, array $b) => strcmp($a['id'], $b['id']));
    usort($imageModels, fn(array $a, array $b) => strcmp($a['id'], $b['id']));
    $result = [
        'fetchedAt' => time(),
        'textModels' => array_slice($textModels, 0, 150),
        'imageModels' => array_slice($imageModels, 0, 50)
    ];
    @file_put_contents($cachePath, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $result;
}

function nibblyAuditCountMissingAlt($node): int {
    if (!is_array($node)) {
        return 0;
    }
    $count = 0;
    $src = $node['src'] ?? null;
    if (is_string($src) && trim($src) !== '' && trim((string)($node['alt'] ?? '')) === '') {
        $count++;
    }
    foreach ($node as $value) {
        if (is_array($value)) {
            $count += nibblyAuditCountMissingAlt($value);
        }
    }
    return $count;
}

function nibblyAuditDescriptionStatus(string $description): string {
    $length = function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description);
    if ($length === 0) {
        return 'missing';
    }
    if ($length < 50) {
        return 'short';
    }
    if ($length > 170) {
        return 'long';
    }
    return 'ok';
}

function nibblyAuditPageText(array $data, int $limit = 6000): string {
    $parts = [];
    $walk = function ($node) use (&$walk, &$parts): void {
        if (is_string($node)) {
            $text = trim(strip_tags($node));
            if ($text !== '') {
                $parts[] = $text;
            }
            return;
        }
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (in_array((string)$key, ['src', 'id', 'type', 'href', 'url', 'image'], true)) {
                    continue;
                }
                $walk($value);
            }
        }
    };
    $walk($data['sections'] ?? []);
    return substr(implode("\n", $parts), 0, $limit);
}

function dashboardCopilotUiLanguage(): string {
    $language = trim((string)($_POST['uiLanguage'] ?? $_GET['uiLanguage'] ?? ''));
    if ($language === '' && function_exists('_nbAdminLang')) {
        $language = _nbAdminLang();
    }
    if (function_exists('nibblyCopilotNormalizeLanguageCode')) {
        $language = nibblyCopilotNormalizeLanguageCode($language);
    }
    return $language !== '' ? $language : (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
}

function dashboardCopilotProposalAuditSummary(array $proposals): array {
    return nibblyCopilotProposalAuditSummary($proposals);
}

function dashboardCopilotUndoSignature(string $contentPage, string $backup, string $path): string {
    return nibblyCopilotUndoSignature($contentPage, $backup, $path);
}

function dashboardCopilotHistoryDir(): string {
    $dir = dirname(CONTENT_PATH) . '/ai-chat-history';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return rtrim($dir, '/\\') . '/';
}

function dashboardCopilotCurrentUserKey(): string {
    $user = (string)($_SESSION['admin_user_id'] ?? ($_SESSION['admin_username'] ?? 'admin'));
    $user = preg_replace('/[^a-zA-Z0-9_-]/', '-', $user);
    return trim((string)$user, '-') ?: 'admin';
}

function dashboardCopilotHistoryId(?string $id = null): string {
    $id = trim((string)$id);
    if ($id !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{7,79}$/i', $id)) {
        return $id;
    }
    return 'chat-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
}

function dashboardCopilotHistoryPath(string $id): string {
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{7,79}$/i', $id)) {
        throw new RuntimeException('Invalid chat history ID');
    }
    return dashboardCopilotHistoryDir() . $id . '.json';
}

function dashboardCopilotCleanHistoryMessages($messages): array {
    if (!is_array($messages)) {
        return [];
    }
    $clean = [];
    foreach (array_slice($messages, -80) as $message) {
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
        $clean[] = [
            'role' => $role,
            'content' => substr($content, 0, 4000)
        ];
    }
    return $clean;
}

function dashboardCopilotCleanImageResult($image): ?array {
    if (!is_array($image) || trim((string)($image['path'] ?? '')) === '') {
        return null;
    }
    return [
        'path' => substr((string)$image['path'], 0, 500),
        'alt' => substr((string)($image['alt'] ?? ''), 0, 500),
        'prompt' => substr((string)($image['prompt'] ?? ''), 0, 1000),
        'field' => substr((string)($image['field'] ?? ''), 0, 500),
        'label' => substr((string)($image['label'] ?? ''), 0, 500)
    ];
}

function dashboardCopilotHistorySummary(array $chat): array {
    $messages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
    $title = trim((string)($chat['title'] ?? ''));
    if ($title === '') {
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'user') {
                $title = trim((string)($message['content'] ?? ''));
                break;
            }
        }
    }
    if ($title === '') {
        $title = 'AI Assistant chat';
    }
    return [
        'id' => (string)($chat['id'] ?? ''),
        'title' => substr($title, 0, 90),
        'contentPage' => (string)($chat['contentPage'] ?? ''),
        'pageTitle' => substr((string)($chat['pageTitle'] ?? ''), 0, 120),
        'url' => substr((string)($chat['url'] ?? ''), 0, 500),
        'messageCount' => count($messages),
        'updatedAt' => (string)($chat['updatedAt'] ?? ''),
        'createdAt' => (string)($chat['createdAt'] ?? '')
    ];
}

function dashboardCopilotLoadOwnedHistory(string $id): array {
    $path = dashboardCopilotHistoryPath($id);
    if (!is_file($path)) {
        throw new RuntimeException('Chat history not found');
    }
    $chat = json_decode((string)file_get_contents($path), true);
    if (!is_array($chat)) {
        throw new RuntimeException('Chat history is invalid');
    }
    if ((string)($chat['user'] ?? '') !== dashboardCopilotCurrentUserKey()) {
        throw new RuntimeException('Chat history not found');
    }
    return $chat;
}

// Validate backup filename
function validateBackupName($backup) {
    if (!preg_match('/^(.+)_\d{4}-\d{2}-\d{2}_\d{6}(?:_[a-f0-9]{6})?\.json$/', (string)$backup, $matches)) return false;
    return validatePageName($matches[1]);
}

function dashboardReadJsonFile(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function dashboardAiModuleEnabled(): bool {
    if (!defined('SETTINGS_PATH') || !is_file(SETTINGS_PATH)) {
        return true;
    }
    $settings = dashboardReadJsonFile(SETTINGS_PATH);
    $modules = $settings['modules'] ?? [];
    if (!is_array($modules)) {
        return true;
    }
    return !array_key_exists('ai', $modules) || !empty($modules['ai']);
}

function dashboardStatusOverview(string $pagesPath, string $newsPath): array {
    $contentRoot = dirname($pagesPath);
    $mails = dashboardReadJsonFile($contentRoot . '/mails.json');
    $unreadMessages = count(array_filter($mails, fn($mail) => empty($mail['read'])));

    $backup = ['enabled' => false, 'lastRun' => null, 'lastStatus' => '', 'newest' => null, 'count' => 0];
    $backupHelper = NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
    if (is_file($backupHelper)) {
        require_once $backupHelper;
        if (function_exists('backupStatus')) {
            $status = backupStatus();
            $backup = [
                'enabled' => (bool)($status['enabled'] ?? false),
                'lastRun' => $status['last_run'] ?? null,
                'lastStatus' => $status['last_status'] ?? '',
                'newest' => $status['newest'] ?? null,
                'count' => (int)($status['count'] ?? 0)
            ];
        }
    }

    $recent = [];
    foreach (glob($pagesPath . '[a-z][a-z]_*.json') ?: [] as $file) {
        $data = dashboardReadJsonFile($file);
        $modified = strtotime((string)($data['lastModified'] ?? '')) ?: filemtime($file);
        $pageId = pathinfo($file, PATHINFO_FILENAME);
        $recent[] = [
            'type' => 'page',
            'id' => $pageId,
            'title' => $data['title'] ?? $pageId,
            'modified' => $modified,
            'url' => '#page/' . $pageId
        ];
    }
    foreach (glob($newsPath . '*.json') ?: [] as $file) {
        $data = dashboardReadJsonFile($file);
        $modified = strtotime((string)($data['lastModified'] ?? '')) ?: filemtime($file);
        $recent[] = [
            'type' => 'news',
            'id' => $data['id'] ?? pathinfo($file, PATHINFO_FILENAME),
            'title' => $data['title'] ?? ($data['slug'] ?? pathinfo($file, PATHINFO_FILENAME)),
            'modified' => $modified,
            'url' => '#news'
        ];
    }
    usort($recent, fn($a, $b) => ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));
    $recent = array_slice($recent, 0, 3);

    $usersData = loadUsers();
    $currentUserId = $_SESSION['admin_user_id'] ?? '';
    $currentUser = $currentUserId ? findUserById($currentUserId) : null;
    $lastLoginUser = null;
    foreach (($usersData['users'] ?? []) as $user) {
        if (empty($user['lastLogin'])) {
            continue;
        }
        if (!$lastLoginUser || strtotime((string)$user['lastLogin']) > strtotime((string)$lastLoginUser['lastLogin'])) {
            $lastLoginUser = $user;
        }
    }

    return [
        'messages' => ['unread' => $unreadMessages],
        'backup' => $backup,
        'recentEdits' => $recent,
        'users' => [
            'current' => $currentUser ? [
                'id' => $currentUser['id'] ?? '',
                'username' => $currentUser['username'] ?? '',
                'role' => $currentUser['role'] ?? ''
            ] : null,
            'lastLogin' => $lastLoginUser ? [
                'id' => $lastLoginUser['id'] ?? '',
                'username' => $lastLoginUser['username'] ?? '',
                'lastLogin' => $lastLoginUser['lastLogin'] ?? null
            ] : null
        ]
    ];
}

// Cleanup old backups (keep max MAX_BACKUPS)
function cleanupOldBackups($pagePrefix) {
    $backups = glob(BACKUP_PATH . $pagePrefix . '_*.json');
    usort($backups, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    while (count($backups) > MAX_BACKUPS) {
        $oldBackup = array_pop($backups);
        unlink($oldBackup);
    }
}

function validateImageFilename(string $filename): bool {
    if ($filename === '' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false || strpos($filename, '..') !== false) {
        return false;
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true);
}

function normalizeOgImagePath(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (str_starts_with($path, '../assets/images/')) {
        $path = '/assets/images/' . substr($path, strlen('../assets/images/'));
    } elseif (str_starts_with($path, 'assets/images/')) {
        $path = '/' . $path;
    }
    return $path;
}

function validateOgImagePath(string $path): bool {
    $path = normalizeOgImagePath($path);
    if ($path === '') return true;
    if (
        strpos($path, '..') !== false ||
        !str_starts_with($path, '/assets/images/') ||
        preg_match('#[:\x00]#', $path)
    ) {
        return false;
    }

    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png'], true);
}

function getMediaConfig(?string $type = null): array {
    $root = dirname(NIBBLY_ADMIN_DIR);
    $configs = [
        'image' => [
            'type' => 'image',
            'label' => 'Images',
            'field' => 'image',
            'path' => defined('IMAGES_PATH') ? IMAGES_PATH : $root . '/assets/images/',
            'trashPath' => defined('IMAGES_TRASH_PATH') ? IMAGES_TRASH_PATH : $root . '/assets/images-trash/',
            'publicPath' => '../assets/images/',
            'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'],
            'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'],
            'maxSize' => 5 * 1024 * 1024,
            'fallbackName' => 'image',
        ],
        'audio' => [
            'type' => 'audio',
            'label' => 'Audio',
            'field' => 'audio',
            'path' => defined('AUDIO_PATH') ? AUDIO_PATH : $root . '/assets/audio/',
            'trashPath' => defined('AUDIO_TRASH_PATH') ? AUDIO_TRASH_PATH : $root . '/assets/audio-trash/',
            'publicPath' => '../assets/audio/',
            'extensions' => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'],
            'mimeTypes' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/mp4', 'audio/aac', 'audio/flac'],
            'maxSize' => 50 * 1024 * 1024,
            'fallbackName' => 'audio',
        ],
        'video' => [
            'type' => 'video',
            'label' => 'Video',
            'field' => 'media',
            'path' => defined('VIDEO_PATH') ? VIDEO_PATH : $root . '/assets/videos/',
            'trashPath' => defined('VIDEO_TRASH_PATH') ? VIDEO_TRASH_PATH : $root . '/assets/videos-trash/',
            'publicPath' => '../assets/videos/',
            'extensions' => ['mp4', 'webm', 'mov', 'm4v'],
            'mimeTypes' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v'],
            'maxSize' => 250 * 1024 * 1024,
            'fallbackName' => 'video',
        ],
        'document' => [
            'type' => 'document',
            'label' => 'Documents',
            'field' => 'media',
            'path' => defined('DOCUMENTS_PATH') ? DOCUMENTS_PATH : $root . '/assets/documents/',
            'trashPath' => defined('DOCUMENTS_TRASH_PATH') ? DOCUMENTS_TRASH_PATH : $root . '/assets/documents-trash/',
            'publicPath' => '../assets/documents/',
            'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf'],
            'mimeTypes' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'application/rtf',
                'text/rtf',
            ],
            'maxSize' => 50 * 1024 * 1024,
            'fallbackName' => 'document',
        ],
    ];

    return $type === null ? $configs : ($configs[$type] ?? []);
}

function normalizeMediaType(string $type): string {
    return array_key_exists($type, getMediaConfig()) ? $type : '';
}

function validateMediaFilename(string $filename, string $type): bool {
    $config = getMediaConfig($type);
    if (!$config || $filename === '' || strpos($filename, '\\') !== false || str_starts_with($filename, '/') || str_contains($filename, '..')) {
        return false;
    }

    $segments = explode('/', $filename);
    if (count($segments) > 2) {
        return false;
    }

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $config['extensions'], true);
}

function validateMediaFolderName(string $folder): bool {
    return $folder !== ''
        && strlen($folder) <= 64
        && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $folder)
        && !str_contains($folder, '..')
        && !str_contains($folder, '/')
        && !str_contains($folder, '\\');
}

function listMediaFolders(string $type, bool $trash = false): array {
    $config = getMediaConfig($type);
    if (!$config) {
        return [];
    }

    $directory = $trash ? $config['trashPath'] : $config['path'];
    if (!is_dir($directory)) {
        return [];
    }

    $folders = [];
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !validateMediaFolderName($entry)) {
            continue;
        }
        if (is_dir($directory . $entry) && !is_link($directory . $entry)) {
            $folders[] = $entry;
        }
    }
    natcasesort($folders);
    return array_values($folders);
}

function uniqueMediaRelativePath(string $directory, string $relativePath): string {
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $folder = trim(str_replace('\\', '/', dirname($relativePath)), '.');
    $basename = basename($relativePath);
    $name = pathinfo($basename, PATHINFO_FILENAME);
    $ext = pathinfo($basename, PATHINFO_EXTENSION);
    $prefix = $folder !== '' ? $folder . '/' : '';
    $targetPath = $prefix . $basename;
    $counter = 1;

    while (file_exists($directory . $targetPath)) {
        $targetPath = $prefix . $name . '-' . $counter . ($ext !== '' ? '.' . $ext : '');
        $counter++;
    }

    return $targetPath;
}

function normalizeRenameExtension(string $extension): string {
    $extension = strtolower(trim($extension));
    return $extension === 'jpeg' ? 'jpg' : $extension;
}

function validateMediaRenameBasename(string $filename, string $type): bool {
    if ($filename === ''
        || strlen($filename) > 180
        || basename($filename) !== $filename
        || strpos($filename, '\\') !== false
        || strpos($filename, '/') !== false
        || str_contains($filename, '..')
        || preg_match('/[\x00-\x1F\x7F]/', $filename)
    ) {
        return false;
    }

    $name = pathinfo($filename, PATHINFO_FILENAME);
    return trim($name) !== '' && validateMediaFilename($filename, $type);
}

function findMediaJsonReferences(string $needle): array {
    $needle = trim($needle);
    if ($needle === '' || !is_dir(dirname(CONTENT_PATH))) {
        return [];
    }

    $contentRoot = realpath(dirname(CONTENT_PATH));
    if ($contentRoot === false) {
        return [];
    }

    $matches = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($contentRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || $fileInfo->isLink() || strtolower($fileInfo->getExtension()) !== 'json') {
            continue;
        }
        $path = $fileInfo->getPathname();
        $raw = (string)file_get_contents($path);
        if ($raw === '' || strpos($raw, $needle) === false) {
            continue;
        }
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen($contentRoot))), '/');
        $lines = preg_split('/\R/', $raw);
        $lineMatches = [];
        foreach ($lines as $index => $line) {
            if (strpos($line, $needle) === false) {
                continue;
            }
            $snippet = trim(preg_replace('/\s+/', ' ', $line));
            $lineMatches[] = [
                'line' => $index + 1,
                'snippet' => substr($snippet, 0, 220)
            ];
            if (count($lineMatches) >= 5) {
                break;
            }
        }
        $matches[] = [
            'file' => $relative,
            'matches' => $lineMatches,
            'count' => substr_count($raw, $needle)
        ];
        if (count($matches) >= 50) {
            break;
        }
    }

    return $matches;
}

function formatAssetSize(int $sizeBytes): string {
    if ($sizeBytes >= 1048576) {
        return round($sizeBytes / 1048576, 1) . ' MB';
    }

    if ($sizeBytes >= 1024) {
        return round($sizeBytes / 1024, 0) . ' KB';
    }

    return $sizeBytes . ' B';
}

function sanitizeVisualImageLayout(string $layout): string {
    if ($layout === 'split') {
        return 'left';
    }
    return in_array($layout, ['none', 'background', 'left', 'right'], true) ? $layout : 'none';
}

function sanitizeVisualOverlayColor(string $color): string {
    $color = trim($color);
    if ($color === '') {
        return '';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        jsonResponse(false, null, 'Invalid overlay color');
    }
    return $color;
}

function sanitizeVisualOverlayOpacity($opacity, int $default): int {
    if ($opacity === null || $opacity === '') {
        return $default;
    }
    return max(0, min(100, (int)$opacity));
}

function sanitizeAccessSettings(array $settings, array $existing): array {
    if (!array_key_exists('access', $settings)) return [];
    $access = $settings['access'] ?? [];
    if (!is_array($access)) {
        return [];
    }

    $maintenance = $access['maintenance'] ?? [];
    if (!is_array($maintenance)) {
        return [];
    }

    $mode = $maintenance['mode'] ?? 'maintenance';
    if (!in_array($mode, ['maintenance', 'offline', 'launch'], true)) {
        $mode = 'maintenance';
    }

    $param = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($maintenance['bypassParam'] ?? 'preview'));
    if ($param === '') {
        $param = 'preview';
    }

    $existingHash = (string)($existing['access']['maintenance']['bypassKeyHash'] ?? '');
    $newKey = trim((string)($maintenance['bypassKey'] ?? ''));
    $hash = $existingHash;
    if ($newKey !== '') {
        $hash = password_hash($newKey, PASSWORD_DEFAULT);
    } elseif (!empty($maintenance['clearBypassKey'])) {
        $hash = '';
    }

    $until = trim((string)($maintenance['until'] ?? ''));
    if ($until !== '' && strtotime($until) === false) {
        jsonResponse(false, null, 'Invalid maintenance date');
    }

    $brandAsset = (string)($maintenance['brandAsset'] ?? 'none');
    if (!in_array($brandAsset, ['none', 'favicon', 'logo'], true)) {
        $brandAsset = 'none';
    }

    $imageLayout = sanitizeVisualImageLayout((string)($maintenance['imageLayout'] ?? 'none'));
    $overlayColor = sanitizeVisualOverlayColor((string)($maintenance['overlayColor'] ?? ''));
    $overlayOpacity = sanitizeVisualOverlayOpacity($maintenance['overlayOpacity'] ?? null, 88);

    $image = trim((string)($maintenance['image'] ?? ''));
    if ($image !== '' && (
        strpos($image, '..') !== false ||
        !str_starts_with($image, '/assets/images/') ||
        preg_match('#[:\x00]#', $image)
    )) {
        jsonResponse(false, null, 'Invalid maintenance image path');
    }

    return [
        'maintenance' => [
            'enabled' => !empty($maintenance['enabled']),
            'mode' => $mode,
            'title' => substr(trim((string)($maintenance['title'] ?? '')), 0, 160),
            'text' => substr(trim((string)($maintenance['text'] ?? '')), 0, 2000),
            'until' => $until,
            'showCountdown' => !empty($maintenance['showCountdown']),
            'brandAsset' => $brandAsset,
            'image' => $image,
            'imageLayout' => $imageLayout,
            'overlayColor' => $overlayColor,
            'overlayOpacity' => $overlayOpacity,
            'bypassParam' => substr($param, 0, 40),
            'bypassKeyHash' => $hash,
        ],
    ];
}

function sanitizeLoginVisualSettings(array $settings): array {
    if (!array_key_exists('login', $settings)) return [];
    $login = $settings['login'] ?? [];
    if (!is_array($login)) {
        return [];
    }

    $brandAsset = (string)($login['brandAsset'] ?? 'favicon');
    if (!in_array($brandAsset, ['none', 'favicon', 'logo'], true)) {
        $brandAsset = 'favicon';
    }

    $imageLayout = sanitizeVisualImageLayout((string)($login['imageLayout'] ?? 'none'));
    $overlayColor = sanitizeVisualOverlayColor((string)($login['overlayColor'] ?? ''));
    $overlayOpacity = sanitizeVisualOverlayOpacity($login['overlayOpacity'] ?? null, 86);
    $boxStyle = (string)($login['boxStyle'] ?? 'card');
    if (!in_array($boxStyle, ['card', 'plain'], true)) {
        $boxStyle = 'card';
    }
    $boxColor = sanitizeVisualOverlayColor((string)($login['boxColor'] ?? ''));
    $boxTextColor = sanitizeVisualOverlayColor((string)($login['boxTextColor'] ?? ''));

    $image = trim((string)($login['image'] ?? ''));
    if ($image !== '' && (
        strpos($image, '..') !== false ||
        !str_starts_with($image, '/assets/images/') ||
        preg_match('#[:\x00]#', $image)
    )) {
        jsonResponse(false, null, 'Invalid login image path');
    }

    return [
        'brandAsset' => $brandAsset,
        'image' => $image,
        'imageLayout' => $imageLayout,
        'overlayColor' => $overlayColor,
        'overlayOpacity' => $overlayOpacity,
        'boxStyle' => $boxStyle,
        'boxColor' => $boxColor,
        'boxTextColor' => $boxTextColor,
    ];
}

function sanitizePrivacySettings(array $settings): array {
    if (!array_key_exists('privacy', $settings)) return [];
    $privacy = $settings['privacy'] ?? [];
    if (!is_array($privacy)) {
        return [];
    }
    return array_intersect_key([
        'emailObfuscation' => !empty($privacy['emailObfuscation']),
        'analyticsEnabled' => !array_key_exists('analyticsEnabled', $privacy) || !empty($privacy['analyticsEnabled']),
        'rememberPublicTheme' => !array_key_exists('rememberPublicTheme', $privacy) || !empty($privacy['rememberPublicTheme']),
    ], $privacy);
}

function sanitizeModuleSettings(array $settings): array {
    if (!array_key_exists('modules', $settings)) return [];
    $modules = $settings['modules'] ?? [];
    if (!is_array($modules)) {
        return [];
    }
    return array_intersect_key([
        'ai' => !array_key_exists('ai', $modules) || !empty($modules['ai']),
        'news' => !array_key_exists('news', $modules) || !empty($modules['news']),
        'events' => !array_key_exists('events', $modules) || !empty($modules['events']),
        'messages' => !array_key_exists('messages', $modules) || !empty($modules['messages']),
        'iconManager' => !array_key_exists('iconManager', $modules) || !empty($modules['iconManager']),
    ], $modules);
}

function sanitizeDashboardSettings(array $settings): array {
    if (!isset($settings['dashboard']) || !is_array($settings['dashboard'])) {
        return [];
    }
    $dashboard = $settings['dashboard'];
    $sanitizePageSize = function($value): int {
        $value = is_numeric($value) ? (int)$value : 50;
        return max(10, min(500, $value));
    };
    return array_intersect_key([
        'itemsPerPage' => $sanitizePageSize($dashboard['itemsPerPage'] ?? 50),
        'iconManagerItemsPerPage' => $sanitizePageSize($dashboard['iconManagerItemsPerPage'] ?? 50),
        'mediaItemsPerPage' => $sanitizePageSize($dashboard['mediaItemsPerPage'] ?? 25),
    ], $dashboard);
}

function normalizePageVisibility(array $contentData, ?array $existingData = null): array {
    $visibility = $contentData['visibility'] ?? null;
    if (!is_array($visibility)) {
        unset($contentData['visibility']);
        return $contentData;
    }

    $status = $visibility['status'] ?? 'public';
    if (!in_array($status, ['public', 'private'], true)) {
        $status = 'public';
    }

    $normalized = [
        'status' => $status,
        'title' => substr(trim((string)($visibility['title'] ?? '')), 0, 160),
        'text' => substr(trim((string)($visibility['text'] ?? '')), 0, 1000),
    ];

    $password = trim((string)($visibility['password'] ?? ''));
    $existingHash = (string)($existingData['visibility']['passwordHash'] ?? ($visibility['passwordHash'] ?? ''));
    if ($password !== '') {
        $normalized['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
    } elseif ($status === 'private' && $existingHash !== '') {
        $normalized['passwordHash'] = $existingHash;
    } elseif ($status === 'private') {
        jsonResponse(false, null, 'Private pages require a password');
    }

    if ($status === 'public' && empty($normalized['title']) && empty($normalized['text'])) {
        unset($contentData['visibility']);
        return $contentData;
    }

    $contentData['visibility'] = $normalized;
    return $contentData;
}

function normalizePageSeo(array $contentData): array {
    $seo = $contentData['seo'] ?? [];
    if (!is_array($seo)) {
        unset($contentData['seo']);
        return $contentData;
    }

    $robots = trim((string)($seo['robots'] ?? 'index, follow'));
    if (!in_array($robots, ['index, follow', 'noindex, follow', 'noindex, nofollow'], true)) {
        $robots = 'index, follow';
    }

    $canonical = trim((string)($seo['canonical'] ?? ''));
    if ($canonical !== '' && !filter_var($canonical, FILTER_VALIDATE_URL)) {
        jsonResponse(false, null, 'Invalid canonical URL');
    }

    $ogImage = normalizeOgImagePath((string)($seo['ogImage'] ?? ''));
    if (!validateOgImagePath($ogImage)) {
        jsonResponse(false, null, 'Open Graph image must be a JPG or PNG file from /assets/images/');
    }

    $contentData['seo'] = [
        'title' => substr(trim((string)($seo['title'] ?? '')), 0, 120),
        'description' => substr(trim((string)($seo['description'] ?? '')), 0, 260),
        'answerSummary' => substr(trim((string)($seo['answerSummary'] ?? '')), 0, 500),
        'canonical' => $canonical,
        'robots' => $robots,
        'ogTitle' => substr(trim((string)($seo['ogTitle'] ?? '')), 0, 120),
        'ogDescription' => substr(trim((string)($seo['ogDescription'] ?? '')), 0, 260),
        'ogImage' => $ogImage,
        'sitemap' => ($seo['sitemap'] ?? true) !== false,
    ];

    return $contentData;
}

function listImageFiles(string $directory, string $publicBasePath): array {
    if (!is_dir($directory)) {
        return [];
    }

    $images = [];
    $files = scandir($directory);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || !validateImageFilename($file)) {
            continue;
        }

        $path = $directory . $file;
        if (!is_file($path)) {
            continue;
        }

        $sizeBytes = filesize($path);
        $modified = filemtime($path);
        $images[] = [
            'name' => $file,
            'path' => $publicBasePath . $file,
            'sizeBytes' => $sizeBytes,
            'size' => formatAssetSize($sizeBytes),
            'modified' => $modified,
            'dateFormatted' => date('d.m.Y H:i', $modified)
        ];
    }

    usort($images, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    return $images;
}

function listMediaFiles(string $type, bool $trash = false): array {
    $config = getMediaConfig($type);
    if (!$config) {
        return [];
    }

    $directory = $trash ? $config['trashPath'] : $config['path'];
    if (!is_dir($directory)) {
        return [];
    }

    $media = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || $fileInfo->isLink()) {
            continue;
        }

        $path = $fileInfo->getPathname();
        $relativePath = ltrim(str_replace('\\', '/', substr($path, strlen($directory))), '/');
        if (!validateMediaFilename($relativePath, $type)) {
            continue;
        }

        $sizeBytes = filesize($path);
        $modified = filemtime($path);
        $folder = trim(str_replace('\\', '/', dirname($relativePath)), '.');
        $media[] = [
            'type' => $type,
            'name' => $relativePath,
            'basename' => basename($relativePath),
            'folder' => $folder,
            'path' => $trash
                ? 'api.php?action=media-trash-file&type=' . rawurlencode($type) . '&filename=' . rawurlencode($relativePath)
                : $config['publicPath'] . $relativePath,
            'sizeBytes' => $sizeBytes,
            'size' => formatAssetSize($sizeBytes),
            'modified' => $modified,
            'dateFormatted' => date('d.m.Y H:i', $modified),
            'extension' => strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)),
        ];
    }

    usort($media, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    return $media;
}

function uniqueMediaFilename(string $directory, string $filename): string {
    $targetFilename = $filename;
    $counter = 1;
    while (file_exists($directory . $targetFilename)) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $targetFilename = $name . '-' . $counter . ($ext !== '' ? '.' . $ext : '');
        $counter++;
    }
    return $targetFilename;
}

function serveLocalImage(string $path, string $filename): void {
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'm4v' => 'video/x-m4v',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'rtf' => 'application/rtf',
    ];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    header_remove('Content-Type');
    header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

function readSiteIconSetRaw() {
    $path = getIconSetPath();
    if (!is_file($path)) {
        return [];
    }

    $raw = json_decode(file_get_contents($path), true);
    return is_array($raw) ? $raw : [];
}

function writeSiteIconSetRaw(array $iconSet) {
    $path = getIconSetPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }

    if (isset($iconSet['_deleted']) && is_array($iconSet['_deleted'])) {
        $iconSet['_deleted'] = array_values(array_unique(array_filter($iconSet['_deleted'], function($key) {
            return is_string($key) && preg_match('/^[a-zA-Z0-9_-]+$/', $key);
        })));
        if (empty($iconSet['_deleted'])) {
            unset($iconSet['_deleted']);
        }
    }

    ksort($iconSet);
    return file_put_contents(
        $path,
        json_encode($iconSet, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

function sanitizeIconKeyInput($key) {
    $key = trim((string)$key);
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key)) {
        return '';
    }
    return $key;
}

function getIconifyAllowedSets() {
    return [
        'lucide' => [
            'label' => 'Lucide',
            'license' => 'ISC',
            'licenseUrl' => 'https://github.com/lucide-icons/lucide/blob/main/LICENSE',
            'defaultWidth' => 24,
            'defaultHeight' => 24,
        ],
        'tabler' => [
            'label' => 'Tabler Icons',
            'license' => 'MIT',
            'licenseUrl' => 'https://github.com/tabler/tabler-icons/blob/master/LICENSE',
            'defaultWidth' => 24,
            'defaultHeight' => 24,
        ],
        'heroicons' => [
            'label' => 'Heroicons',
            'license' => 'MIT',
            'licenseUrl' => 'https://github.com/tailwindlabs/heroicons/blob/master/LICENSE',
            'defaultWidth' => 24,
            'defaultHeight' => 24,
        ],
        'bi' => [
            'label' => 'Bootstrap Icons',
            'license' => 'MIT',
            'licenseUrl' => 'https://github.com/twbs/icons/blob/main/LICENSE.md',
            'style' => 'fill',
            'defaultWidth' => 16,
            'defaultHeight' => 16,
        ],
        'ph' => [
            'label' => 'Phosphor',
            'license' => 'MIT',
            'licenseUrl' => 'https://github.com/phosphor-icons/core/blob/main/LICENSE',
            'defaultWidth' => 256,
            'defaultHeight' => 256,
        ],
        'iconoir' => [
            'label' => 'Iconoir',
            'license' => 'MIT',
            'licenseUrl' => 'https://github.com/iconoir-icons/iconoir/blob/main/LICENSE',
            'defaultWidth' => 24,
            'defaultHeight' => 24,
        ],
        'ion' => [
            'label' => 'Ionicons',
            'license' => 'MIT',
            'licenseUrl' => 'https://github.com/ionic-team/ionicons/blob/main/LICENSE',
            'defaultWidth' => 512,
            'defaultHeight' => 512,
        ],
        'mynaui' => [
            'label' => 'Myna UI',
            'license' => 'MIT',
            'licenseUrl' => 'https://github.com/MynaUI/icons/blob/main/LICENSE',
            'defaultWidth' => 24,
            'defaultHeight' => 24,
        ],
        'mingcute' => [
            'label' => 'MingCute',
            'license' => 'Apache-2.0',
            'licenseUrl' => 'https://github.com/mingcute-design/mingcute-icons/blob/main/LICENSE',
            'defaultWidth' => 24,
            'defaultHeight' => 24,
        ],
        'tdesign' => [
            'label' => 'TDesign Icons',
            'license' => 'MIT',
            'licenseUrl' => 'https://github.com/Tencent/tdesign-icons/blob/main/LICENSE',
            'defaultWidth' => 24,
            'defaultHeight' => 24,
        ],
    ];
}

function fetchIconifyJson($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "User-Agent: nibbly-CMS/1.0\r\nAccept: application/json\r\n",
        ],
    ]);

    $json = @file_get_contents($url, false, $context);
    if ($json === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'nibbly-CMS/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $json = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($json === false || $status >= 400) {
            return null;
        }
    }

    $data = is_string($json) ? json_decode($json, true) : null;
    return is_array($data) ? $data : null;
}

function iconifyIconToDefinition($prefix, $name, array $data, array $setInfo) {
    $icon = $data['icons'][$name] ?? null;
    if (!$icon && isset($data['aliases'][$name]['parent'])) {
        $parent = $data['aliases'][$name]['parent'];
        $icon = $data['icons'][$parent] ?? null;
    }
    if (!is_array($icon) || empty($icon['body']) || !is_string($icon['body'])) {
        return null;
    }

    $width = $icon['width'] ?? $data['width'] ?? $setInfo['defaultWidth'] ?? 24;
    $height = $icon['height'] ?? $data['height'] ?? $setInfo['defaultHeight'] ?? 24;
    $left = $icon['left'] ?? 0;
    $top = $icon['top'] ?? 0;
    $viewBox = normalizeIconViewBox($left . ' ' . $top . ' ' . $width . ' ' . $height);
    $label = ucwords(str_replace(['-', '_'], ' ', $name));
    $style = $setInfo['style'] ?? 'stroke';

    $normalized = normalizeIconSet([
        $prefix . '-' . $name => [
            'label' => $label,
            'tags' => [$setInfo['label'], $prefix],
            'svg' => $icon['body'],
            'viewBox' => $viewBox,
            'style' => $style,
        ],
    ]);
    $key = array_key_first($normalized);
    if (!$key) {
        return null;
    }

    return [
        'key' => $key,
        'prefix' => $prefix,
        'name' => $name,
        'full' => $prefix . ':' . $name,
        'label' => $normalized[$key]['label'],
        'tags' => $normalized[$key]['tags'],
        'svg' => $normalized[$key]['svg'],
        'viewBox' => $normalized[$key]['viewBox'],
        'style' => $normalized[$key]['style'] ?? $style,
        'license' => $setInfo['license'],
        'licenseUrl' => $setInfo['licenseUrl'],
        'setLabel' => $setInfo['label'],
    ];
}

function searchIconifyIcons($prefix, $query) {
    $allowedSets = getIconifyAllowedSets();
    if (!isset($allowedSets[$prefix])) {
        return [false, null, 'Icon set is not allowed.'];
    }
    $query = trim((string)$query);
    if (strlen($query) < 2) {
        return [false, null, 'Enter at least 2 characters.'];
    }

    $searchUrl = 'https://api.iconify.design/search?query=' . rawurlencode($query) . '&prefix=' . rawurlencode($prefix) . '&limit=48';
    $search = fetchIconifyJson($searchUrl);
    if (!$search || empty($search['icons']) || !is_array($search['icons'])) {
        return [true, ['icons' => [], 'sets' => $allowedSets], ''];
    }

    $names = [];
    foreach ($search['icons'] as $iconName) {
        if (is_string($iconName) && str_starts_with($iconName, $prefix . ':')) {
            $names[] = substr($iconName, strlen($prefix) + 1);
        }
    }
    $names = array_slice(array_values(array_unique($names)), 0, 48);
    if (!$names) {
        return [true, ['icons' => [], 'sets' => $allowedSets], ''];
    }

    $dataUrl = 'https://api.iconify.design/' . rawurlencode($prefix) . '.json?icons=' . rawurlencode(implode(',', $names));
    $data = fetchIconifyJson($dataUrl);
    if (!$data) {
        return [false, null, 'Could not load icon data from Iconify.'];
    }

    $icons = [];
    foreach ($names as $name) {
        $definition = iconifyIconToDefinition($prefix, $name, $data, $allowedSets[$prefix]);
        if ($definition) {
            $icons[] = $definition;
        }
    }

    return [true, ['icons' => $icons, 'sets' => $allowedSets], ''];
}

function importIconifyIcon($fullName) {
    $allowedSets = getIconifyAllowedSets();
    $fullName = trim((string)$fullName);
    if (!preg_match('/^([a-z0-9-]+):([a-z0-9-]+)$/', $fullName, $m)) {
        return [false, null, 'Invalid Iconify icon name.'];
    }

    $prefix = $m[1];
    $name = $m[2];
    if (!isset($allowedSets[$prefix])) {
        return [false, null, 'Icon set is not allowed.'];
    }

    $dataUrl = 'https://api.iconify.design/' . rawurlencode($prefix) . '.json?icons=' . rawurlencode($name);
    $data = fetchIconifyJson($dataUrl);
    if (!$data) {
        return [false, null, 'Could not load icon data from Iconify.'];
    }

    $definition = iconifyIconToDefinition($prefix, $name, $data, $allowedSets[$prefix]);
    if (!$definition) {
        return [false, null, 'Icon could not be imported.'];
    }

    $rawIconSet = readSiteIconSetRaw();
    $availableKeys = array_column(iconManagerListData()['icons'], 'key');
    if (in_array($definition['key'], $availableKeys, true)) {
        return [false, null, 'An icon with this key already exists.'];
    }

    $rawIconSet[$definition['key']] = [
        'label' => $definition['label'],
        'tags' => array_values(array_unique(array_merge($definition['tags'], ['iconify', $definition['full']]))),
        'svg' => $definition['svg'],
        'viewBox' => $definition['viewBox'],
        'style' => $definition['style'],
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'source' => [
            'type' => 'iconify',
            'icon' => $definition['full'],
            'license' => $definition['license'],
            'licenseUrl' => $definition['licenseUrl'],
        ],
    ];

    if (!writeSiteIconSetRaw($rawIconSet)) {
        return [false, null, 'Could not write icon set.'];
    }

    return [true, iconManagerListData(), ''];
}

function normalizeIconManagerPayload($key, $label, $tags, $svg, $viewBox = '') {
    $key = sanitizeIconKeyInput($key);
    if ($key === '') {
        return [false, null, 'Invalid icon key. Use lowercase letters, numbers, hyphens, and underscores.'];
    }

    $tagsArray = [];
    if (is_string($tags) && trim($tags) !== '') {
        $tagsArray = array_values(array_filter(array_map('trim', explode(',', $tags)), 'strlen'));
    } elseif (is_array($tags)) {
        $tagsArray = array_values(array_filter($tags, 'is_string'));
    }

    $extractedViewBox = extractIconViewBoxFromSvg((string)$svg);
    $rawViewBox = trim((string)$viewBox);

    $definition = [
        'label' => trim((string)$label) ?: ucwords(str_replace(['-', '_'], ' ', $key)),
        'tags' => $tagsArray,
        'svg' => (string)$svg,
        'viewBox' => ($extractedViewBox && ($rawViewBox === '' || normalizeIconViewBox($rawViewBox) === '0 0 24 24'))
            ? $extractedViewBox
            : normalizeIconViewBox($rawViewBox),
    ];
    $normalized = normalizeIconSet([$key => $definition]);
    if (empty($normalized[$key])) {
        return [false, null, 'SVG code is empty or contains no supported SVG elements.'];
    }

    return [true, [$key, $normalized[$key]], ''];
}

function iconManagerListData() {
    $core = getDefaultIconSet();
    $raw = readSiteIconSetRaw();
    $custom = normalizeIconSet($raw);
    $deleted = getDeletedIconKeys($raw);
    $merged = $core;

    foreach ($deleted as $deletedKey) {
        unset($merged[$deletedKey]);
    }
    $merged = array_merge($merged, $custom);
    if (empty($merged['default'])) {
        $fallback = getEmergencyDefaultIconSet();
        $merged['default'] = $fallback['default'];
    }

    $icons = [];
    foreach ($merged as $key => $definition) {
        $icons[] = [
            'key' => $key,
            'label' => $definition['label'] ?? ucwords(str_replace(['-', '_'], ' ', $key)),
            'tags' => $definition['tags'] ?? [],
            'svg' => $definition['svg'] ?? '',
            'viewBox' => $definition['viewBox'] ?? '0 0 24 24',
            'style' => $definition['style'] ?? 'stroke',
            'createdAt' => $definition['createdAt'] ?? '',
            'updatedAt' => $definition['updatedAt'] ?? '',
            'source' => isset($custom[$key]) ? 'custom' : 'core',
            'canDelete' => $key !== 'default',
        ];
    }
    usort($icons, function($a, $b) {
        return strcasecmp($a['key'], $b['key']);
    });

    return [
        'icons' => $icons,
        'path' => getIconSetPath(),
        'deleted' => $deleted,
    ];
}


// Not authenticated?
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => 'Session expired',
        'session_expired' => true
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Build page list by scanning content/pages/*.json
function buildPageSeoHealth($lang, $slug, array $data) {
    if (!function_exists('nibblySeoContext') || !function_exists('nibblySeoHealth')) {
        return null;
    }

    return nibblySeoHealth(nibblySeoContext([
        'contentPage' => nibblyPageContentKey($lang, $slug),
        'currentLang' => $lang,
        'currentPage' => $slug,
        'pageTitle' => $data['title'] ?? '',
        'pageDescription' => $data['description'] ?? '',
        'data' => $data,
    ]));
}

function buildPageList() {
    global $SITE_LANGUAGES;
    $allLangs = array_keys($SITE_LANGUAGES);
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : $allLangs[0];

    // Scan filesystem for page JSON files (pattern: {lang}_{slug}.json)
    $slugsByLang = [];
    $files = glob(CONTENT_PATH . '*.json');
    foreach ($files as $file) {
        $basename = basename($file, '.json');
        // Only match {2-letter-lang}_{slug} pattern
        $page = nibblyPageParseContentKey($basename);
        if ($page === null) {
            continue;
        }
        $lang = $page['lang'];
        $slug = $page['path'];
        // Only include languages defined in config
        if (!isset($SITE_LANGUAGES[$lang])) {
            continue;
        }
        $data = json_decode(file_get_contents($file), true);
        $title = $data['title'] ?? ucfirst(str_replace('-', ' ', $slug));
        // Use JSON lastModified, fall back to file modification time
        $lastModified = $data['lastModified'] ?? date('c', filemtime($file));
        $slugsByLang[$slug][$lang] = [
            'title' => $title,
            'lastModified' => $lastModified,
            'seoHealth' => buildPageSeoHealth($lang, $slug, is_array($data) ? $data : []),
        ];
    }

    $pages = [];
    foreach ($slugsByLang as $slug => $langData) {
        $pageInfo = [
            'slug' => $slug,
            'title' => reset($langData)['title'],
            'languages' => [],
        ];

        foreach ($allLangs as $lang) {
            if (isset($langData[$lang])) {
                $pageInfo['languages'][$lang] = [
                    'exists' => true,
                    'title' => $langData[$lang]['title'],
                    'lastModified' => $langData[$lang]['lastModified'],
                    'seoHealth' => $langData[$lang]['seoHealth'],
                ];
            }
        }

        $dates = array_filter(array_column($pageInfo['languages'], 'lastModified'));
        $pageInfo['lastModified'] = !empty($dates) ? max($dates) : null;

        if (isset($pageInfo['languages'][$defaultLang]['title'])) {
            $pageInfo['title'] = $pageInfo['languages'][$defaultLang]['title'];
        }

        $pages[] = $pageInfo;
    }

    usort($pages, function($a, $b) {
        return strcasecmp($a['title'], $b['title']);
    });

    return [
        'pages' => $pages,
        'languages' => $SITE_LANGUAGES,
        'defaultLang' => $defaultLang,
    ];
}
