<?php
/**
 * API endpoint for Content Management
 * Actions: load, save, backups, restore, delete-backup, events, images, audio, mails
 */

require_once 'config.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/lang/i18n.php';
require_once __DIR__ . '/../includes/content-loader.php';
require_once __DIR__ . '/../includes/seo-helper.php';
require_once __DIR__ . '/../includes/ai/ai-helper.php';
require_once __DIR__ . '/../includes/ai/copilot-context.php';
require_once __DIR__ . '/../includes/ai/image-job-runner.php';
require_once __DIR__ . '/../includes/analytics-helper.php';
require_once __DIR__ . '/../includes/forms.php';
ensureUsersFile();

// Prevent PHP HTML error output from corrupting JSON responses
ini_set('html_errors', '0');
ini_set('display_errors', '0');

// Secure session cookie settings
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

header('Content-Type: application/json; charset=utf-8');

// Authentication check (incl. session timeout)
function isAuthenticated() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }

    if (isset($_SESSION['admin_login_time'])) {
        $sessionAge = time() - $_SESSION['admin_login_time'];
        if ($sessionAge > SESSION_LIFETIME) {
            session_destroy();
            return false;
        }
    }

    $_SESSION['admin_login_time'] = time();
    return true;
}

// CSRF token validation
function validateCsrfToken() {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// JSON response helper
function jsonResponse($success, $data = null, $message = '') {
    echo json_encode([
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
    $script = dirname(__DIR__) . '/cli/ai-image-job-worker.php';
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
    return preg_match('/^[a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*$/', $page) || in_array($page, ['sidebar', 'footer']);
}

function dashboardCopilotAdminUrl(string $hash = ''): string {
    $hash = ltrim($hash, '#');
    return '/admin/dashboard' . ($hash !== '' ? '#' . $hash : '');
}

function dashboardCopilotPageUrl(string $pageName): string {
    if (!preg_match('/^([a-z]{2})_([a-z0-9]+(?:-[a-z0-9]+)*)$/', $pageName, $matches)) {
        return '';
    }
    return nibblySeoPageUrl($matches[1], $matches[2]);
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
        curl_close($ch);
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
    return preg_match('/^([a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*|sidebar|footer)_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', $backup);
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
    $backupHelper = __DIR__ . '/../includes/backup-helper.php';
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
    $root = dirname(__DIR__);
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
    $privacy = $settings['privacy'] ?? [];
    if (!is_array($privacy)) {
        return [];
    }
    return [
        'emailObfuscation' => !empty($privacy['emailObfuscation']),
        'rememberPublicTheme' => !array_key_exists('rememberPublicTheme', $privacy) || !empty($privacy['rememberPublicTheme']),
    ];
}

function sanitizeModuleSettings(array $settings): array {
    $modules = $settings['modules'] ?? [];
    if (!is_array($modules)) {
        return [];
    }
    return [
        'ai' => !array_key_exists('ai', $modules) || !empty($modules['ai']),
        'news' => !array_key_exists('news', $modules) || !empty($modules['news']),
        'events' => !array_key_exists('events', $modules) || !empty($modules['events']),
        'messages' => !array_key_exists('messages', $modules) || !empty($modules['messages']),
        'iconManager' => !array_key_exists('iconManager', $modules) || !empty($modules['iconManager']),
    ];
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
    return [
        'itemsPerPage' => $sanitizePageSize($dashboard['itemsPerPage'] ?? 50),
        'iconManagerItemsPerPage' => $sanitizePageSize($dashboard['iconManagerItemsPerPage'] ?? 50),
        'mediaItemsPerPage' => $sanitizePageSize($dashboard['mediaItemsPerPage'] ?? 25),
    ];
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
        curl_close($ch);
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
        'contentPage' => $lang . '_' . $slug,
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
        if (!preg_match('/^([a-z]{2})_([a-z0-9]+(?:-[a-z0-9]+)*)$/', $basename, $m)) {
            continue;
        }
        $lang = $m[1];
        $slug = $m[2];
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'keepalive':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        jsonResponse(true, ['time' => time()], 'Session refreshed');
        break;

    case 'dashboard-overview':
        $analyticsPeriod = $_GET['analytics_period'] ?? 'days';
        $analyticsCount = isset($_GET['analytics_count']) ? (int)$_GET['analytics_count'] : 30;
        if (!in_array($analyticsPeriod, ['days', 'months', 'years'], true)) {
            $analyticsPeriod = 'days';
        }
        if ($analyticsPeriod === 'months' && $analyticsCount <= 0) {
            $analyticsCount = 12;
        } elseif ($analyticsPeriod === 'years' && $analyticsCount < 0) {
            $analyticsCount = 0;
        } elseif ($analyticsPeriod === 'days' && $analyticsCount <= 0) {
            $analyticsCount = 30;
        }
        $pagesPath = defined('CONTENT_PATH') ? CONTENT_PATH : dirname(__DIR__) . '/content/pages/';
        $newsPath = dirname(__DIR__) . '/content/news/';
        $pageCount = count(glob($pagesPath . '[a-z][a-z]_*.json') ?: []);
        $newsCount = is_dir($newsPath) ? count(glob($newsPath . '*.json') ?: []) : 0;
        $response = [
            'pages' => $pageCount,
            'news' => $newsCount,
            'status' => dashboardStatusOverview($pagesPath, $newsPath),
            'analytics' => nibblyAnalyticsSummary($analyticsPeriod, $analyticsCount),
        ];
        if (dashboardAiModuleEnabled()) {
            $response['ai'] = [
                'settings' => nibblyAiLoadSettings(true),
                'usage' => nibblyAiUsageSummary()
            ];
        }
        jsonResponse(true, $response);
        break;

    // ============================================================
    // CONTENT MANAGEMENT
    // ============================================================

    case 'list-pages':
        jsonResponse(true, buildPageList());
        break;

    case 'list-icons':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        jsonResponse(true, iconManagerListData());
        break;

    // ============================================================
    // AI GATEWAY
    // ============================================================

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
            $backupName = preg_match('/^[a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*$/', $contentPage)
                ? dashboardCopilotCreatePageBackup($contentPage)
                : '';
            $written = file_put_contents(
                $filepath,
                json_encode($applied['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
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
            if (preg_match('/^([a-z]{2})_([a-z0-9]+(?:-[a-z0-9]+)*)$/', $contentPage, $pageParts)) {
                $response['seoHealth'] = buildPageSeoHealth($pageParts[1], $pageParts[2], $applied['data']);
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
            $written = file_put_contents(
                $filepath,
                json_encode($applied['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
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
            if (preg_match('/^([a-z]{2})_([a-z0-9]+(?:-[a-z0-9]+)*)$/', $contentPage, $pageParts)) {
                $response['seoHealth'] = buildPageSeoHealth($pageParts[1], $pageParts[2], $restored);
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
            if (!preg_match('/^[a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*$/', $name)) {
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
        if (!preg_match('/^[a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*$/', $contentPage)) {
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
        if (!preg_match('/^[a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*$/', $contentPage)) {
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

    case 'iconify-search':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        [$validSearch, $searchData, $searchError] = searchIconifyIcons($_GET['prefix'] ?? '', $_GET['query'] ?? '');
        if (!$validSearch) {
            jsonResponse(false, null, $searchError);
        }
        jsonResponse(true, $searchData);
        break;

    case 'iconify-import':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        [$validImport, $importData, $importError] = importIconifyIcon($_POST['icon'] ?? '');
        if (!$validImport) {
            jsonResponse(false, null, $importError);
        }
        jsonResponse(true, $importData, 'Icon imported.');
        break;

    case 'save-icon':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $oldKey = sanitizeIconKeyInput($_POST['old_key'] ?? '');
        [$validIcon, $normalizedIcon, $iconError] = normalizeIconManagerPayload(
            $_POST['key'] ?? '',
            $_POST['label'] ?? '',
            $_POST['tags'] ?? '',
            $_POST['svg'] ?? '',
            $_POST['viewBox'] ?? ''
        );
        if (!$validIcon) {
            jsonResponse(false, null, $iconError);
        }

        [$newKey, $definition] = $normalizedIcon;
        if ($oldKey === 'default' && $newKey !== 'default') {
            jsonResponse(false, null, 'The fallback icon key cannot be renamed.');
        }
        $rawIconSet = readSiteIconSetRaw();
        $customIcons = normalizeIconSet($rawIconSet);
        $availableIcons = iconManagerListData()['icons'];
        $availableKeys = array_column($availableIcons, 'key');

        if (($oldKey === '' || $oldKey !== $newKey) && in_array($newKey, $availableKeys, true)) {
            jsonResponse(false, null, 'An icon with this key already exists.');
        }

        if ($oldKey !== '' && $oldKey !== $newKey) {
            unset($rawIconSet[$oldKey]);
            if (isset(getDefaultIconSet()[$oldKey])) {
                $rawIconSet['_deleted'] = $rawIconSet['_deleted'] ?? [];
                $rawIconSet['_deleted'][] = $oldKey;
            }
        }

        $previousKey = $oldKey ?: $newKey;
        $previousDefinition = isset($rawIconSet[$previousKey]) && is_array($rawIconSet[$previousKey]) ? $rawIconSet[$previousKey] : [];
        $definition['createdAt'] = isset($previousDefinition['createdAt']) && is_string($previousDefinition['createdAt'])
            ? $previousDefinition['createdAt']
            : date('c');
        $definition['updatedAt'] = date('c');
        $rawIconSet[$newKey] = $definition;
        if (isset($rawIconSet['_deleted']) && is_array($rawIconSet['_deleted'])) {
            $rawIconSet['_deleted'] = array_values(array_filter($rawIconSet['_deleted'], function($key) use ($newKey) {
                return $key !== $newKey;
            }));
        }

        if (!writeSiteIconSetRaw($rawIconSet)) {
            jsonResponse(false, null, 'Could not write icon set.');
        }

        jsonResponse(true, iconManagerListData(), 'Icon saved.');
        break;

    case 'delete-icon':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $key = sanitizeIconKeyInput($_POST['key'] ?? '');
        if ($key === '') {
            jsonResponse(false, null, 'Invalid icon key.');
        }
        if ($key === 'default') {
            jsonResponse(false, null, 'The fallback icon cannot be deleted.');
        }

        $rawIconSet = readSiteIconSetRaw();
        unset($rawIconSet[$key]);
        if (isset(getDefaultIconSet()[$key])) {
            $rawIconSet['_deleted'] = $rawIconSet['_deleted'] ?? [];
            $rawIconSet['_deleted'][] = $key;
        }

        if (!writeSiteIconSetRaw($rawIconSet)) {
            jsonResponse(false, null, 'Could not write icon set.');
        }

        jsonResponse(true, iconManagerListData(), 'Icon deleted.');
        break;

    case 'create-page':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $lang = trim($_POST['lang'] ?? '');

        if (empty($title)) {
            jsonResponse(false, null, 'Title is required');
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            jsonResponse(false, null, 'Invalid slug (lowercase letters, numbers, hyphens only)');
        }
        if (!preg_match('/^[a-z]{2}$/', $lang)) {
            jsonResponse(false, null, 'Invalid language');
        }

        $pageName = $lang . '_' . $slug;
        $filepath = CONTENT_PATH . $pageName . '.json';
        if (file_exists($filepath)) {
            jsonResponse(false, null, 'A page with this slug already exists');
        }

        $content = [
            'page' => $pageName,
            'lang' => $lang,
            'title' => $title,
            'description' => '',
            'visibility' => [
                'status' => 'public',
                'title' => '',
                'text' => '',
            ],
            'seo' => [
                'title' => '',
                'description' => '',
                'answerSummary' => '',
                'canonical' => '',
                'robots' => 'index, follow',
                'ogTitle' => '',
                'ogDescription' => '',
                'ogImage' => '',
                'sitemap' => true,
            ],
            'lastModified' => date('c'),
            'sections' => [
                [
                    'id' => 'section_heading',
                    'type' => 'text',
                    'title' => $title,
                    'titleTag' => 'h1',
                    'content' => '<p>Page content goes here.</p>',
                ],
            ],
        ];

        $result = file_put_contents(
            $filepath,
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error creating page');
        }

        jsonResponse(true, ['page' => $pageName, 'pageList' => buildPageList()], 'Page created');
        break;

    case 'copy-page':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $sourcePage = $_POST['source'] ?? '';
        $targetLang = $_POST['targetLang'] ?? '';
        $slug = $_POST['slug'] ?? '';

        if (!validatePageName($sourcePage)) {
            jsonResponse(false, null, 'Invalid source page name');
        }
        if (!preg_match('/^[a-z]{2}$/', $targetLang)) {
            jsonResponse(false, null, 'Invalid target language');
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            jsonResponse(false, null, 'Invalid slug');
        }

        $sourceFile = CONTENT_PATH . $sourcePage . '.json';
        if (!file_exists($sourceFile)) {
            jsonResponse(false, null, 'Source page does not exist');
        }

        $targetPage = $targetLang . '_' . $slug;
        $targetFile = CONTENT_PATH . $targetPage . '.json';
        if (file_exists($targetFile)) {
            jsonResponse(false, null, 'Target page already exists');
        }

        $content = json_decode(file_get_contents($sourceFile), true);
        if ($content === null) {
            jsonResponse(false, null, 'Error reading source page');
        }

        // Update metadata for the new language
        $content['page'] = $targetPage;
        $content['lang'] = $targetLang;
        $content['lastModified'] = date('c');

        $result = file_put_contents(
            $targetFile,
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error creating page');
        }

        jsonResponse(true, ['page' => $targetPage, 'pageList' => buildPageList()], 'Page created as copy');
        break;

    case 'delete-page':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $page = $_POST['page'] ?? '';
        if (!validatePageName($page)) {
            jsonResponse(false, null, 'Invalid page name');
        }

        $filepath = CONTENT_PATH . $page . '.json';
        if (!file_exists($filepath)) {
            jsonResponse(false, null, 'Page does not exist');
        }

        // Move to trash instead of deleting
        if (!is_dir(PAGES_TRASH_PATH)) {
            mkdir(PAGES_TRASH_PATH, 0755, true);
        }

        $timestamp = date('Y-m-d_His');
        $trashName = $page . '_' . $timestamp . '.json';
        if (!rename($filepath, PAGES_TRASH_PATH . $trashName)) {
            jsonResponse(false, null, 'Error moving page to trash');
        }

        jsonResponse(true, ['pageList' => buildPageList()], 'Page moved to trash');
        break;

    case 'duplicate-page':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $sourcePage = $_POST['source'] ?? '';
        if (!validatePageName($sourcePage)) {
            jsonResponse(false, null, 'Invalid page name');
        }

        $sourceFile = CONTENT_PATH . $sourcePage . '.json';
        if (!file_exists($sourceFile)) {
            jsonResponse(false, null, 'Source page does not exist');
        }

        // Find a unique slug: append -copy, -copy-2, etc.
        // Extract lang and slug from source
        $underscorePos = strpos($sourcePage, '_');
        $lang = substr($sourcePage, 0, $underscorePos);
        $slug = substr($sourcePage, $underscorePos + 1);

        $copySlug = $slug . '-copy';
        $counter = 2;
        while (file_exists(CONTENT_PATH . $lang . '_' . $copySlug . '.json')) {
            $copySlug = $slug . '-copy-' . $counter;
            $counter++;
        }

        $newPage = $lang . '_' . $copySlug;
        $content = json_decode(file_get_contents($sourceFile), true);
        if ($content === null) {
            jsonResponse(false, null, 'Error reading source page');
        }

        $content['page'] = $newPage;
        if (isset($content['title'])) {
            $content['title'] .= ' (Copy)';
        }
        $content['lastModified'] = date('c');

        $result = file_put_contents(
            CONTENT_PATH . $newPage . '.json',
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error duplicating page');
        }

        jsonResponse(true, ['page' => $newPage, 'slug' => $copySlug, 'pageList' => buildPageList()], 'Page duplicated');
        break;

    // ============================================================
    // PAGE TRASH
    // ============================================================

    case 'list-trash':
        if (!is_dir(PAGES_TRASH_PATH)) {
            jsonResponse(true, []);
        }

        $trashItems = [];
        $files = glob(PAGES_TRASH_PATH . '*.json');
        foreach ($files as $file) {
            $filename = basename($file, '.json');
            // Parse: {lang}_{slug}_{date}_{time}
            if (!preg_match('/^([a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*)_(\d{4}-\d{2}-\d{2})_(\d{6})$/', $filename, $m)) {
                continue;
            }
            $pageName = $m[1];
            $date = $m[2];
            $time = substr($m[3], 0, 2) . ':' . substr($m[3], 2, 2) . ':' . substr($m[3], 4, 2);

            $data = json_decode(file_get_contents($file), true);
            $trashItems[] = [
                'filename' => basename($file),
                'page' => $pageName,
                'title' => $data['title'] ?? ucfirst(str_replace('-', ' ', substr($pageName, 3))),
                'lang' => $data['lang'] ?? substr($pageName, 0, 2),
                'deletedDate' => $date,
                'deletedTime' => $time,
                'timestamp' => filemtime($file),
            ];
        }

        usort($trashItems, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        jsonResponse(true, $trashItems);
        break;

    case 'restore-page':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $trashFile = $_POST['filename'] ?? '';
        if (empty($trashFile) || !preg_match('/^[a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', $trashFile)) {
            jsonResponse(false, null, 'Invalid trash filename');
        }

        $trashPath = PAGES_TRASH_PATH . $trashFile;
        if (!file_exists($trashPath)) {
            jsonResponse(false, null, 'Trash file not found');
        }

        // Extract original page name (remove timestamp suffix)
        $pageName = preg_replace('/_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', '', $trashFile);
        $targetPath = CONTENT_PATH . $pageName . '.json';

        // If a page with the same name already exists, abort
        if (file_exists($targetPath)) {
            jsonResponse(false, null, 'A page with this name already exists. Delete or rename it first.');
        }

        if (!rename($trashPath, $targetPath)) {
            jsonResponse(false, null, 'Error restoring page');
        }

        jsonResponse(true, ['page' => $pageName, 'pageList' => buildPageList()], 'Page restored');
        break;

    case 'delete-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $trashFile = $_POST['filename'] ?? '';
        if (empty($trashFile) || !preg_match('/^[a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', $trashFile)) {
            jsonResponse(false, null, 'Invalid trash filename');
        }

        $trashPath = PAGES_TRASH_PATH . $trashFile;
        if (!file_exists($trashPath)) {
            jsonResponse(false, null, 'Trash file not found');
        }

        if (!unlink($trashPath)) {
            jsonResponse(false, null, 'Error deleting permanently');
        }

        jsonResponse(true, null, 'Page permanently deleted');
        break;

    case 'empty-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        if (!is_dir(PAGES_TRASH_PATH)) {
            jsonResponse(true, null, 'Trash is already empty');
        }

        $files = glob(PAGES_TRASH_PATH . '*.json');
        $deleted = 0;
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }

        jsonResponse(true, ['deleted' => $deleted], $deleted . ' page(s) permanently deleted');
        break;

    case 'load':
        $page = $_GET['page'] ?? '';
        if (!validatePageName($page)) {
            jsonResponse(false, null, 'Invalid page name');
        }

        $filepath = CONTENT_PATH . $page . '.json';
        if (!file_exists($filepath)) {
            jsonResponse(true, [
                'page' => $page,
                'lastModified' => null,
                'sections' => []
            ]);
        }

        $content = json_decode(file_get_contents($filepath), true);
        jsonResponse(true, $content);
        break;

    case 'save':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $page = $_POST['page'] ?? '';
        if (!validatePageName($page)) {
            jsonResponse(false, null, 'Invalid page name');
        }

        $content = $_POST['content'] ?? '';
        $contentData = json_decode($content, true);
        if ($contentData === null) {
            jsonResponse(false, null, 'Invalid JSON format');
        }

        $filepath = CONTENT_PATH . $page . '.json';
        $existingData = file_exists($filepath) ? (json_decode(file_get_contents($filepath), true) ?: []) : [];
        $contentData = normalizePageVisibility($contentData, $existingData);
        $contentData = normalizePageSeo($contentData);

        // Create backup if file exists
        if (file_exists($filepath)) {
            $timestamp = date('Y-m-d_His');
            $backupPath = BACKUP_PATH . $page . '_' . $timestamp . '.json';
            copy($filepath, $backupPath);
            cleanupOldBackups($page);
        }

        $contentData['lastModified'] = date('c');

        $result = file_put_contents(
            $filepath,
            json_encode($contentData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error saving');
        }

        $responseData = ['lastModified' => $contentData['lastModified']];
        if (preg_match('/^([a-z]{2})_([a-z0-9]+(?:-[a-z0-9]+)*)$/', $page, $pageParts)) {
            $responseData['seoHealth'] = buildPageSeoHealth($pageParts[1], $pageParts[2], $contentData);
        }

        // Optional: re-render the full sections list so the client can patch
        // the .editable-content-area DOM without a full page reload (used
        // after add/delete/reorder).  Returning the whole list keeps card-grid
        // grouping and index-based data-field attributes in sync.
        if (!empty($_POST['render_sections'])) {
            require_once __DIR__ . '/../includes/content-loader.php';
            // Force admin mode for the renderer: we're already authenticated
            // via the save endpoint, but renderAllSections checks isAdminLoggedIn.
            $responseData['sectionsHtml'] = renderAllSections($page);
        }

        jsonResponse(true, $responseData, 'Saved successfully');
        break;

    // ============================================================
    // BACKUPS
    // ============================================================

    case 'backups':
        $page = $_GET['page'] ?? '';
        if (!validatePageName($page)) {
            jsonResponse(false, null, 'Invalid page name');
        }

        $backups = glob(BACKUP_PATH . $page . '_*.json');
        $backupList = [];

        foreach ($backups as $backup) {
            $filename = basename($backup);
            if (preg_match('/_(\d{4}-\d{2}-\d{2})_(\d{6})\.json$/', $filename, $matches)) {
                $date = $matches[1];
                $time = substr($matches[2], 0, 2) . ':' . substr($matches[2], 2, 2) . ':' . substr($matches[2], 4, 2);
                $backupList[] = [
                    'filename' => $filename,
                    'date' => $date,
                    'time' => $time,
                    'timestamp' => filemtime($backup)
                ];
            }
        }

        usort($backupList, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        jsonResponse(true, $backupList);
        break;

    case 'restore':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $backup = $_POST['backup'] ?? '';
        if (!validateBackupName($backup)) {
            jsonResponse(false, null, 'Invalid backup name');
        }

        $backupPath = BACKUP_PATH . $backup;
        if (!file_exists($backupPath)) {
            jsonResponse(false, null, 'Backup not found');
        }

        $page = preg_replace('/_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', '', $backup);
        $filepath = CONTENT_PATH . $page . '.json';

        // Save current state before restoring
        if (file_exists($filepath)) {
            $timestamp = date('Y-m-d_His');
            $newBackupPath = BACKUP_PATH . $page . '_' . $timestamp . '.json';
            copy($filepath, $newBackupPath);
        }

        $result = copy($backupPath, $filepath);

        if (!$result) {
            jsonResponse(false, null, 'Error restoring');
        }

        cleanupOldBackups($page);
        jsonResponse(true, null, 'Backup restored successfully');
        break;

    case 'delete-backup':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $backup = $_POST['backup'] ?? '';
        if (!validateBackupName($backup)) {
            jsonResponse(false, null, 'Invalid backup name');
        }

        $backupPath = BACKUP_PATH . $backup;
        if (!file_exists($backupPath)) {
            jsonResponse(false, null, 'Backup not found');
        }

        $result = unlink($backupPath);

        if (!$result) {
            jsonResponse(false, null, 'Error deleting');
        }

        jsonResponse(true, null, 'Backup deleted');
        break;

    case 'preview-backup':
        $backup = $_GET['backup'] ?? '';
        if (!validateBackupName($backup)) {
            jsonResponse(false, null, 'Invalid backup name');
        }

        $backupPath = BACKUP_PATH . $backup;
        if (!file_exists($backupPath)) {
            jsonResponse(false, null, 'Backup not found');
        }

        $content = json_decode(file_get_contents($backupPath), true);
        jsonResponse(true, $content);
        break;

    // ============================================================
    // EVENTS
    // ============================================================

    case 'load-events':
        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(true, ['events' => [], 'lastModified' => null]);
        }
        $content = json_decode(file_get_contents(EVENTS_PATH), true);
        jsonResponse(true, $content);
        break;

    case 'save-event':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventData = $_POST['event'] ?? '';
        $event = json_decode($eventData, true);
        if ($event === null) {
            jsonResponse(false, null, 'Invalid JSON format');
        }

        // Validate: date required, title in at least one language required
        $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
        $hasTitle = false;
        if (!empty($event['title']) && is_array($event['title'])) {
            foreach ($event['title'] as $t) {
                if (!empty($t)) { $hasTitle = true; break; }
            }
        }
        if (empty($event['date']) || !$hasTitle) {
            jsonResponse(false, null, 'Date and title are required');
        }

        $data = file_exists(EVENTS_PATH)
            ? json_decode(file_get_contents(EVENTS_PATH), true)
            : ['events' => []];

        // Create backup
        if (file_exists(EVENTS_PATH)) {
            $timestamp = date('Y-m-d_His');
            $backupPath = BACKUP_PATH . 'events_' . $timestamp . '.json';
            copy(EVENTS_PATH, $backupPath);

            $backups = glob(BACKUP_PATH . 'events_*.json');
            usort($backups, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            while (count($backups) > MAX_BACKUPS) {
                $oldBackup = array_pop($backups);
                unlink($oldBackup);
            }
        }

        if (empty($event['id'])) {
            // Use default language title for ID, fallback to first available
            $titleForId = $event['title'][$defaultLang] ?? reset($event['title']);
            $event['id'] = $event['date'] . '-' . preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $titleForId)));
        }

        $found = false;
        foreach ($data['events'] as $index => $existing) {
            if ($existing['id'] === $event['id']) {
                $data['events'][$index] = $event;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['events'][] = $event;
        }

        $data['lastModified'] = date('c');

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error saving');
        }

        jsonResponse(true, ['id' => $event['id']], $found ? 'Event updated' : 'Event created');
        break;

    case 'delete-event':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventId = $_POST['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        if (!isset($data['trash']) || !is_array($data['trash'])) {
            $data['trash'] = [];
        }

        $timestamp = date('Y-m-d_His');
        $backupPath = BACKUP_PATH . 'events_' . $timestamp . '.json';
        copy(EVENTS_PATH, $backupPath);

        // Move event to trash array (instead of deleting)
        $movedEvent = null;
        foreach ($data['events'] as $idx => $existing) {
            if ($existing['id'] === $eventId) {
                $movedEvent = $existing;
                array_splice($data['events'], $idx, 1);
                break;
            }
        }

        if ($movedEvent === null) {
            jsonResponse(false, null, 'Event not found');
        }

        $data['trash'][] = [
            'event' => $movedEvent,
            'deletedAt' => date('c'),
        ];

        $data['lastModified'] = date('c');

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error moving event to trash');
        }

        jsonResponse(true, null, 'Event moved to trash');
        break;

    case 'list-events-trash':
        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(true, []);
        }
        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        $trash = $data['trash'] ?? [];
        // Sort newest first
        usort($trash, function($a, $b) {
            return strcmp($b['deletedAt'] ?? '', $a['deletedAt'] ?? '');
        });
        jsonResponse(true, $trash);
        break;

    case 'restore-event':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventId = $_POST['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        if (!isset($data['trash']) || !is_array($data['trash'])) {
            jsonResponse(false, null, 'Event not in trash');
        }

        $restored = null;
        foreach ($data['trash'] as $idx => $item) {
            if (($item['event']['id'] ?? null) === $eventId) {
                $restored = $item['event'];
                array_splice($data['trash'], $idx, 1);
                break;
            }
        }

        if ($restored === null) {
            jsonResponse(false, null, 'Event not found in trash');
        }

        // Avoid id collision: if restored id already exists, append a suffix
        $existingIds = array_map(function($e) { return $e['id'] ?? ''; }, $data['events']);
        if (in_array($restored['id'], $existingIds, true)) {
            $restored['id'] = $restored['id'] . '-restored-' . time();
        }
        $data['events'][] = $restored;
        $data['lastModified'] = date('c');

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error restoring event');
        }

        jsonResponse(true, ['id' => $restored['id']], 'Event restored');
        break;

    case 'delete-event-permanent':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventId = $_POST['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        if (!isset($data['trash']) || !is_array($data['trash'])) {
            jsonResponse(false, null, 'Event not in trash');
        }

        $found = false;
        foreach ($data['trash'] as $idx => $item) {
            if (($item['event']['id'] ?? null) === $eventId) {
                array_splice($data['trash'], $idx, 1);
                $found = true;
                break;
            }
        }

        if (!$found) {
            jsonResponse(false, null, 'Event not found in trash');
        }

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error deleting event permanently');
        }

        jsonResponse(true, null, 'Event permanently deleted');
        break;

    case 'empty-events-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(true, null, 'Trash is empty');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        $data['trash'] = [];

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error emptying trash');
        }

        jsonResponse(true, null, 'Events trash emptied');
        break;

    case 'toggle-event-visibility':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventId = $_POST['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);

        $found = false;
        $nowHidden = false;
        foreach ($data['events'] as $index => $existing) {
            if ($existing['id'] === $eventId) {
                $nowHidden = empty($existing['hidden']);
                if ($nowHidden) {
                    $data['events'][$index]['hidden'] = true;
                } else {
                    unset($data['events'][$index]['hidden']);
                }
                $found = true;
                break;
            }
        }

        if (!$found) {
            jsonResponse(false, null, 'Event not found');
        }

        $data['lastModified'] = date('c');

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error saving');
        }

        jsonResponse(true, ['hidden' => $nowHidden], $nowHidden ? 'Event hidden' : 'Event visible');
        break;

    case 'load-event':
        $eventId = $_GET['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);

        foreach ($data['events'] as $event) {
            if ($event['id'] === $eventId) {
                jsonResponse(true, $event);
            }
        }

        jsonResponse(false, null, 'Event not found');
        break;

    // ============================================================
    // IMAGE MANAGEMENT
    // ============================================================

    case 'list-images':
        jsonResponse(true, listImageFiles(IMAGES_PATH, '../assets/images/'));
        break;

    case 'list-media':
        $requestedType = $_GET['type'] ?? 'all';
        $trash = ($_GET['trash'] ?? '0') === '1';
        $types = $requestedType === 'all'
            ? array_keys(getMediaConfig())
            : array_filter(array_map('normalizeMediaType', explode(',', $requestedType)));

        $items = [];
        foreach ($types as $type) {
            $items = array_merge($items, listMediaFiles($type, $trash));
        }
        $folders = [];
        foreach ($types as $type) {
            $folders = array_merge($folders, listMediaFolders($type, $trash));
        }
        $folders = array_values(array_unique($folders));
        natcasesort($folders);
        $folders = array_values($folders);
        usort($items, function($a, $b) {
            return ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0);
        });

        jsonResponse(true, [
            'items' => $items,
            'folders' => $folders,
            'types' => array_values(array_map(function($config) {
                return [
                    'type' => $config['type'],
                    'label' => $config['label'],
                    'extensions' => $config['extensions'],
                    'maxSize' => $config['maxSize'],
                ];
            }, getMediaConfig())),
        ]);
        break;

    case 'upload-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $config = getMediaConfig($type);
        if (!$config) {
            jsonResponse(false, null, 'Invalid media type');
        }

        $field = $config['field'];
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $field = 'file';
        }

        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, null, 'Upload error');
        }

        $file = $_FILES[$field];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $config['extensions'], true)) {
            jsonResponse(false, null, 'Invalid file extension');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mimeType, $config['mimeTypes'], true)) {
            jsonResponse(false, null, 'Invalid file type');
        }

        if ($file['size'] > $config['maxSize']) {
            jsonResponse(false, null, 'File too large');
        }

        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $safeName = preg_replace('/[^a-z0-9\-_]/i', '-', $originalName);
        $safeName = preg_replace('/-+/', '-', $safeName);
        $safeName = trim($safeName, '-');
        if ($safeName === '') {
            $safeName = $config['fallbackName'] . '-' . time();
        }

        $folder = trim((string)($_POST['folder'] ?? ''));
        if ($folder !== '' && !validateMediaFolderName($folder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }
        $targetBase = $config['path'] . ($folder !== '' ? $folder . '/' : '');
        $targetPublicBase = $config['publicPath'] . ($folder !== '' ? $folder . '/' : '');

        $filename = uniqueMediaFilename($targetBase, $safeName . '.' . $extension);
        if (!is_dir($targetBase)) {
            mkdir($targetBase, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], $targetBase . $filename)) {
            $relativeName = ($folder !== '' ? $folder . '/' : '') . $filename;
            jsonResponse(true, [
                'type' => $type,
                'name' => $relativeName,
                'path' => $targetPublicBase . $filename,
            ], 'Media uploaded');
        }

        jsonResponse(false, null, 'Error saving');
        break;

    case 'create-media-folder':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $folder = trim((string)($_POST['folder'] ?? ''));
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFolderName($folder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }

        $path = $config['path'] . $folder;
        if (is_dir($path)) {
            jsonResponse(true, ['folder' => $folder], 'Folder exists');
        }
        if (file_exists($path)) {
            jsonResponse(false, null, 'A file with this name already exists');
        }
        if (mkdir($path, 0755, true)) {
            jsonResponse(true, ['folder' => $folder], 'Folder created');
        }

        jsonResponse(false, null, 'Error creating folder');
        break;

    case 'delete-media-folder':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $folder = trim((string)($_POST['folder'] ?? ''));
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFolderName($folder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }

        $path = $config['path'] . $folder;
        if (!is_dir($path) || is_link($path)) {
            jsonResponse(false, null, 'Folder not found');
        }
        $contents = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        // OS metadata files do not count as content and are removed with the folder.
        $junkFiles = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];
        $realContents = array_values(array_diff($contents, $junkFiles));
        if (count($realContents) > 0) {
            jsonResponse(false, null, 'Folder must be empty before it can be deleted');
        }
        foreach (array_intersect($contents, $junkFiles) as $junkFile) {
            @unlink($path . '/' . $junkFile);
        }
        if (rmdir($path)) {
            jsonResponse(true, ['folder' => $folder], 'Folder deleted');
        }

        jsonResponse(false, null, 'Error deleting folder');
        break;

    case 'move-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $filename = $_POST['filename'] ?? '';
        $targetFolder = trim((string)($_POST['folder'] ?? ''));
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }
        if ($targetFolder !== '' && !validateMediaFolderName($targetFolder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }

        $sourcePath = $config['path'] . $filename;
        if (!is_file($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        $basename = basename($filename);
        $targetRelative = ($targetFolder !== '' ? $targetFolder . '/' : '') . $basename;
        if ($targetRelative === $filename) {
            jsonResponse(true, [
                'type' => $type,
                'name' => $filename,
                'path' => $config['publicPath'] . $filename,
            ], 'Media already in folder');
        }

        $targetDirectory = $config['path'] . ($targetFolder !== '' ? $targetFolder : '');
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true)) {
            jsonResponse(false, null, 'Error creating target folder');
        }

        $targetRelative = uniqueMediaRelativePath($config['path'], $targetRelative);
        if (rename($sourcePath, $config['path'] . $targetRelative)) {
            jsonResponse(true, [
                'type' => $type,
                'name' => $targetRelative,
                'path' => $config['publicPath'] . $targetRelative,
            ], 'Media moved');
        }

        jsonResponse(false, null, 'Error moving media');
        break;

    case 'rename-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $filename = $_POST['filename'] ?? '';
        $newName = trim((string)($_POST['newName'] ?? ''));
        $scanReferences = ($_POST['scanReferences'] ?? '0') === '1';
        $confirmReferences = ($_POST['confirmReferences'] ?? '0') === '1';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }
        if (!validateMediaRenameBasename($newName, $type)) {
            jsonResponse(false, null, 'Invalid file name');
        }

        $oldBasename = basename($filename);
        $oldExt = normalizeRenameExtension(pathinfo($oldBasename, PATHINFO_EXTENSION));
        $newExt = normalizeRenameExtension(pathinfo($newName, PATHINFO_EXTENSION));
        if ($oldExt === '' || $newExt === '' || $oldExt !== $newExt) {
            jsonResponse(false, null, 'File extension cannot be changed');
        }

        $folder = trim(str_replace('\\', '/', dirname($filename)), '.');
        $targetRelative = ($folder !== '' ? $folder . '/' : '') . $newName;
        if (!validateMediaFilename($targetRelative, $type)) {
            jsonResponse(false, null, 'Invalid file name');
        }
        if ($targetRelative === $filename) {
            jsonResponse(true, [
                'type' => $type,
                'name' => $filename,
                'path' => $config['publicPath'] . $filename,
                'references' => [],
            ], 'Media already has this name');
        }

        $sourcePath = $config['path'] . $filename;
        $targetPath = $config['path'] . $targetRelative;
        if (!is_file($sourcePath) || is_link($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }
        if (file_exists($targetPath)) {
            jsonResponse(false, null, 'A file with this name already exists');
        }

        $references = [];
        if ($scanReferences) {
            $referencesByPath = [];
            foreach ([
                $oldBasename,
                $filename,
                ltrim($config['publicPath'], './') . $filename,
                '/' . ltrim($config['publicPath'], './') . $filename,
            ] as $needle) {
                foreach (findMediaJsonReferences($needle) as $match) {
                    $key = $match['file'];
                    $referencesByPath[$key] = $match;
                }
            }
            $references = array_values($referencesByPath);
            if ($references && !$confirmReferences) {
                jsonResponse(false, [
                    'requiresConfirmation' => true,
                    'references' => $references,
                    'oldName' => $filename,
                    'newName' => $targetRelative,
                ], 'References found');
            }
        }

        if (rename($sourcePath, $targetPath)) {
            jsonResponse(true, [
                'type' => $type,
                'name' => $targetRelative,
                'path' => $config['publicPath'] . $targetRelative,
                'oldName' => $filename,
                'references' => $references,
            ], 'Media renamed');
        }

        jsonResponse(false, null, 'Error renaming media');
        break;

    case 'delete-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? '');
        $filename = $_POST['filename'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }

        $sourcePath = $config['path'] . $filename;
        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        if (!is_dir($config['trashPath'])) {
            mkdir($config['trashPath'], 0755, true);
        }

        $targetFilename = uniqueMediaRelativePath($config['trashPath'], $filename);
        $targetDirectory = dirname($config['trashPath'] . $targetFilename);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }
        if (rename($sourcePath, $config['trashPath'] . $targetFilename)) {
            jsonResponse(true, null, 'Media moved to trash');
        }

        jsonResponse(false, null, 'Error moving');
        break;

    case 'restore-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? '');
        $filename = $_POST['filename'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }

        $sourcePath = $config['trashPath'] . $filename;
        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        $targetFilename = uniqueMediaRelativePath($config['path'], $filename);
        $targetDirectory = dirname($config['path'] . $targetFilename);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }
        if (rename($sourcePath, $config['path'] . $targetFilename)) {
            jsonResponse(true, [
                'type' => $type,
                'name' => $targetFilename,
                'path' => $config['publicPath'] . $targetFilename,
            ], 'Media restored');
        }

        jsonResponse(false, null, 'Error restoring');
        break;

    case 'delete-media-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? '');
        $filename = $_POST['filename'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }

        $path = $config['trashPath'] . $filename;
        if (!file_exists($path)) {
            jsonResponse(false, null, 'File not found');
        }

        if (unlink($path)) {
            jsonResponse(true, null, 'Media permanently deleted');
        }

        jsonResponse(false, null, 'Error deleting');
        break;

    case 'empty-media-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $requestedType = $_POST['type'] ?? 'all';
        $types = $requestedType === 'all'
            ? array_keys(getMediaConfig())
            : array_filter(array_map('normalizeMediaType', explode(',', $requestedType)));
        $deleted = 0;
        foreach ($types as $type) {
            $config = getMediaConfig($type);
            foreach (listMediaFiles($type, true) as $media) {
                $path = $config['trashPath'] . $media['name'];
                if (is_file($path) && unlink($path)) {
                    $deleted++;
                }
            }
        }

        jsonResponse(true, ['deleted' => $deleted], 'Media trash emptied');
        break;

    case 'media-trash-file':
        $type = normalizeMediaType($_GET['type'] ?? '');
        $filename = $_GET['filename'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            http_response_code(400);
            header_remove('Content-Type');
            echo 'Invalid media file';
            exit;
        }

        $path = $config['trashPath'] . $filename;
        if (!is_file($path)) {
            http_response_code(404);
            header_remove('Content-Type');
            echo 'File not found';
            exit;
        }

        serveLocalImage($path, $filename);
        break;

    case 'list-image-trash':
        $images = listImageFiles(IMAGES_TRASH_PATH, 'api.php?action=image-trash-file&filename=');
        foreach ($images as &$image) {
            $image['path'] = 'api.php?action=image-trash-file&filename=' . rawurlencode($image['name']);
        }
        unset($image);
        jsonResponse(true, $images);
        break;

    case 'image-trash-file':
        $filename = $_GET['filename'] ?? '';
        if (!validateImageFilename($filename)) {
            http_response_code(400);
            header_remove('Content-Type');
            echo 'Invalid filename';
            exit;
        }

        $path = IMAGES_TRASH_PATH . $filename;
        if (!is_file($path)) {
            http_response_code(404);
            header_remove('Content-Type');
            echo 'File not found';
            exit;
        }

        serveLocalImage($path, $filename);
        break;

    case 'upload-image':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Upload error';
            if (isset($_FILES['image']['error'])) {
                switch ($_FILES['image']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMsg = 'File too large';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMsg = 'No file selected';
                        break;
                }
            }
            jsonResponse(false, null, $errorMsg);
        }

        $file = $_FILES['image'];

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            jsonResponse(false, null, 'Only JPG, PNG, WebP and SVG allowed');
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            jsonResponse(false, null, 'Maximum file size: 5 MB');
        }

        $explicitFilename = $_POST['filename'] ?? '';
        $replaceMode = ($_POST['replace'] ?? '0') === '1';

        if (!empty($explicitFilename)) {
            if (!validateMediaFilename($explicitFilename, 'image')) {
                jsonResponse(false, null, 'Invalid file extension');
            }

            $extension = strtolower(pathinfo($explicitFilename, PATHINFO_EXTENSION));
            $filename = $explicitFilename;

            if ($replaceMode && file_exists(IMAGES_PATH . $filename)) {
                if (!is_dir(IMAGES_TRASH_PATH)) {
                    mkdir(IMAGES_TRASH_PATH, 0755, true);
                }
                $folder = trim(str_replace('\\', '/', dirname($filename)), '.');
                $backupName = ($folder !== '' ? $folder . '/' : '') . pathinfo($filename, PATHINFO_FILENAME) . '_' . date('Y-m-d_His') . '.' . $extension;
                $backupDirectory = dirname(IMAGES_TRASH_PATH . $backupName);
                if (!is_dir($backupDirectory)) {
                    mkdir($backupDirectory, 0755, true);
                }
                rename(IMAGES_PATH . $filename, IMAGES_TRASH_PATH . $backupName);
            } elseif (!$replaceMode && file_exists(IMAGES_PATH . $filename)) {
                $filename = uniqueMediaRelativePath(IMAGES_PATH, $filename);
            }
        } else {
            $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $safeName = preg_replace('/[^a-z0-9\-_]/i', '-', $originalName);
            $safeName = preg_replace('/-+/', '-', $safeName);
            $safeName = trim($safeName, '-');

            if (empty($safeName)) {
                $safeName = 'image-' . time();
            }

            $filename = $safeName . '.' . $extension;

            $counter = 1;
            while (file_exists(IMAGES_PATH . $filename)) {
                $filename = $safeName . '-' . $counter . '.' . $extension;
                $counter++;
            }
        }

        $targetDirectory = dirname(IMAGES_PATH . $filename);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], IMAGES_PATH . $filename)) {
            jsonResponse(true, [
                'name' => $filename,
                'path' => '../assets/images/' . $filename
            ], $replaceMode ? 'Image replaced' : 'Image uploaded');
        } else {
            jsonResponse(false, null, 'Error saving');
        }
        break;

    case 'delete-image':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $filename = $_POST['filename'] ?? '';

        if (!validateImageFilename($filename)) {
            jsonResponse(false, null, 'Invalid filename');
        }

        $sourcePath = IMAGES_PATH . $filename;

        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        if (!is_dir(IMAGES_TRASH_PATH)) {
            mkdir(IMAGES_TRASH_PATH, 0755, true);
        }

        $targetFilename = $filename;
        $counter = 1;
        while (file_exists(IMAGES_TRASH_PATH . $targetFilename)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $targetFilename = $name . '-' . $counter . '.' . $ext;
            $counter++;
        }

        if (rename($sourcePath, IMAGES_TRASH_PATH . $targetFilename)) {
            jsonResponse(true, null, 'Image moved to trash');
        } else {
            jsonResponse(false, null, 'Error moving');
        }
        break;

    case 'restore-image':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $filename = $_POST['filename'] ?? '';
        if (!validateImageFilename($filename)) {
            jsonResponse(false, null, 'Invalid filename');
        }

        $sourcePath = IMAGES_TRASH_PATH . $filename;
        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        if (!is_dir(IMAGES_PATH)) {
            mkdir(IMAGES_PATH, 0755, true);
        }

        $targetFilename = $filename;
        $counter = 1;
        while (file_exists(IMAGES_PATH . $targetFilename)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $targetFilename = $name . '-' . $counter . '.' . $ext;
            $counter++;
        }

        if (rename($sourcePath, IMAGES_PATH . $targetFilename)) {
            jsonResponse(true, [
                'name' => $targetFilename,
                'path' => '../assets/images/' . $targetFilename
            ], 'Image restored');
        }

        jsonResponse(false, null, 'Error restoring');
        break;

    case 'delete-image-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $filename = $_POST['filename'] ?? '';
        if (!validateImageFilename($filename)) {
            jsonResponse(false, null, 'Invalid filename');
        }

        $path = IMAGES_TRASH_PATH . $filename;
        if (!file_exists($path)) {
            jsonResponse(false, null, 'File not found');
        }

        if (unlink($path)) {
            jsonResponse(true, null, 'Image permanently deleted');
        }

        jsonResponse(false, null, 'Error deleting');
        break;

    case 'empty-image-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $deleted = 0;
        foreach (listImageFiles(IMAGES_TRASH_PATH, '../assets/images-trash/') as $image) {
            $path = IMAGES_TRASH_PATH . $image['name'];
            if (is_file($path) && unlink($path)) {
                $deleted++;
            }
        }

        jsonResponse(true, ['deleted' => $deleted], 'Image trash emptied');
        break;

    // ============================================================
    // AUDIO MANAGEMENT
    // ============================================================

    case 'list-audio':
        if (!is_dir(AUDIO_PATH)) {
            jsonResponse(true, []);
        }

        $audioFiles = [];
        $allowedExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];

        $files = scandir(AUDIO_PATH);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExtensions)) {
                $sizeBytes = filesize(AUDIO_PATH . $file);
                $modified = filemtime(AUDIO_PATH . $file);

                if ($sizeBytes >= 1048576) {
                    $sizeFormatted = round($sizeBytes / 1048576, 1) . ' MB';
                } elseif ($sizeBytes >= 1024) {
                    $sizeFormatted = round($sizeBytes / 1024, 0) . ' KB';
                } else {
                    $sizeFormatted = $sizeBytes . ' B';
                }

                $audioFiles[] = [
                    'name' => $file,
                    'path' => '../assets/audio/' . $file,
                    'sizeBytes' => $sizeBytes,
                    'size' => $sizeFormatted,
                    'modified' => $modified,
                    'dateFormatted' => date('d.m.Y H:i', $modified)
                ];
            }
        }

        usort($audioFiles, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        jsonResponse(true, $audioFiles);
        break;

    case 'upload-audio':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Upload error';
            if (isset($_FILES['audio']['error'])) {
                switch ($_FILES['audio']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMsg = 'File too large';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMsg = 'No file selected';
                        break;
                }
            }
            jsonResponse(false, null, $errorMsg);
        }

        $file = $_FILES['audio'];

        $allowedMimeTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/aac', 'audio/flac'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            jsonResponse(false, null, 'Only MP3, WAV, OGG, M4A, AAC and FLAC allowed');
        }

        if ($file['size'] > 50 * 1024 * 1024) {
            jsonResponse(false, null, 'Maximum file size: 50 MB');
        }

        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-z0-9\-_]/i', '-', $originalName);
        $safeName = preg_replace('/-+/', '-', $safeName);
        $safeName = trim($safeName, '-');

        if (empty($safeName)) {
            $safeName = 'audio-' . time();
        }

        $filename = $safeName . '.' . $extension;

        $counter = 1;
        while (file_exists(AUDIO_PATH . $filename)) {
            $filename = $safeName . '-' . $counter . '.' . $extension;
            $counter++;
        }

        if (!is_dir(AUDIO_PATH)) {
            mkdir(AUDIO_PATH, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], AUDIO_PATH . $filename)) {
            jsonResponse(true, [
                'name' => $filename,
                'path' => '../assets/audio/' . $filename
            ], 'Audio file uploaded');
        } else {
            jsonResponse(false, null, 'Error saving');
        }
        break;

    case 'delete-audio':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $filename = $_POST['filename'] ?? '';

        if (empty($filename) || strpos($filename, '/') !== false || strpos($filename, '\\') !== false || strpos($filename, '..') !== false) {
            jsonResponse(false, null, 'Invalid filename');
        }

        $sourcePath = AUDIO_PATH . $filename;

        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        if (!is_dir(AUDIO_TRASH_PATH)) {
            mkdir(AUDIO_TRASH_PATH, 0755, true);
        }

        $targetFilename = $filename;
        $counter = 1;
        while (file_exists(AUDIO_TRASH_PATH . $targetFilename)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $targetFilename = $name . '-' . $counter . '.' . $ext;
            $counter++;
        }

        if (rename($sourcePath, AUDIO_TRASH_PATH . $targetFilename)) {
            jsonResponse(true, null, 'Audio file moved to trash');
        } else {
            jsonResponse(false, null, 'Error moving');
        }
        break;

    // ============================================================
    // NEWS / BLOG MANAGEMENT
    // ============================================================

    case 'load-news':
        $newsDir = dirname(CONTENT_PATH) . '/news/';
        if (!is_dir($newsDir)) {
            jsonResponse(true, []);
        }

        $filterLang = $_GET['lang'] ?? '';

        $posts = [];
        foreach (glob($newsDir . '*.json') as $file) {
            $post = json_decode(file_get_contents($file), true);
            if (!is_array($post)) continue;

            // Posts without lang field default to primary language
            if (empty($post['lang'])) {
                $post['lang'] = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
            }

            // Filter by language if requested
            if ($filterLang && $post['lang'] !== $filterLang) continue;

            $posts[] = $post;
        }

        // Sort by date descending
        usort($posts, function($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });

        jsonResponse(true, $posts);
        break;

    case 'save-news':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $postJson = $_POST['post'] ?? '';
        $post = json_decode($postJson, true);
        if ($post === null) {
            jsonResponse(false, null, 'Invalid JSON format');
        }

        // Validate required fields
        $title = trim($post['title'] ?? '');
        $date = trim($post['date'] ?? '');
        if (empty($title) || empty($date)) {
            jsonResponse(false, null, 'Date and title are required');
        }

        // Generate slug from title if not provided
        $slug = trim($post['slug'] ?? '');
        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
        }
        // Validate slug
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            jsonResponse(false, null, 'Invalid slug format');
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            jsonResponse(false, null, 'Invalid date format');
        }

        // Validate language
        $lang = trim($post['lang'] ?? '');
        if (empty($lang) || !preg_match('/^[a-z]{2}$/', $lang)) {
            $lang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
        }

        // Build post ID from date + slug (+ lang suffix for non-default)
        $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
        $postId = $date . '-' . $slug;
        if ($lang !== $defaultLang) {
            $postId .= '-' . $lang;
        }

        // If editing an existing post with a different ID, delete the old file
        $oldId = $post['id'] ?? '';
        $newsDir = dirname(CONTENT_PATH) . '/news/';
        if (!is_dir($newsDir)) {
            mkdir($newsDir, 0755, true);
        }

        if ($oldId && $oldId !== $postId) {
            $oldFile = $newsDir . $oldId . '.json';
            if (is_file($oldFile)) {
                unlink($oldFile);
            }
        }

        // Sanitize content
        $sanitized = [
            'id' => $postId,
            'lang' => $lang,
            'title' => $title,
            'slug' => $slug,
            'date' => $date,
            'author' => trim($post['author'] ?? ''),
            'excerpt' => trim($post['excerpt'] ?? ''),
            'image' => trim($post['image'] ?? ''),
            'content' => $post['content'] ?? '',
            'hidden' => !empty($post['hidden']),
            'lastModified' => date('c'),
        ];

        $filepath = $newsDir . $postId . '.json';
        $result = file_put_contents(
            $filepath,
            json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error saving post');
        }

        jsonResponse(true, $sanitized, 'Post saved');
        break;

    case 'toggle-news-status':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $postId = $_POST['post_id'] ?? '';
        if (empty($postId) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $postId)) {
            jsonResponse(false, null, 'Invalid post ID');
        }

        $newsDir = dirname(CONTENT_PATH) . '/news/';
        $filepath = $newsDir . $postId . '.json';

        if (!is_file($filepath)) {
            jsonResponse(false, null, 'Post not found');
        }

        $post = json_decode(file_get_contents($filepath), true);
        if (!is_array($post)) {
            jsonResponse(false, null, 'Invalid post data');
        }

        $post['hidden'] = !($post['hidden'] ?? false);
        $post['lastModified'] = date('c');

        $result = file_put_contents(
            $filepath,
            json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error updating post');
        }

        jsonResponse(true, ['hidden' => $post['hidden']]);
        break;

    case 'delete-news':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $postId = $_POST['post_id'] ?? '';
        if (empty($postId) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $postId)) {
            jsonResponse(false, null, 'Invalid post ID');
        }

        $newsDir = dirname(CONTENT_PATH) . '/news/';
        $filepath = $newsDir . $postId . '.json';

        if (!is_file($filepath)) {
            jsonResponse(false, null, 'Post not found');
        }

        if (!unlink($filepath)) {
            jsonResponse(false, null, 'Error deleting post');
        }

        jsonResponse(true, null, 'Post deleted');
        break;

    // ============================================================
    // MAIL MANAGEMENT
    // ============================================================

    case 'load-mails':
        $mailsFile = dirname(CONTENT_PATH) . '/mails.json';
        if (!file_exists($mailsFile)) {
            jsonResponse(true, ['mails' => [], 'forms' => nibblyFormsList()]);
        }

        $mails = json_decode(file_get_contents($mailsFile), true) ?: [];
        foreach ($mails as &$mail) {
            $mail['formId'] = $mail['formId'] ?? 'contact';
            $mail['formLabel'] = $mail['formLabel'] ?? 'Kontaktformular';
        }
        unset($mail);
        jsonResponse(true, ['mails' => $mails, 'forms' => nibblyFormsList()]);
        break;

    case 'list-forms':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        jsonResponse(true, nibblyFormsList());
        break;

    case 'load-form':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        $formId = $_GET['form_id'] ?? $_POST['form_id'] ?? '';
        if ($formId === '') {
            jsonResponse(false, null, 'Form ID missing');
        }
        $form = nibblyFormLoad($formId);
        if (!$form) {
            jsonResponse(false, null, 'Form not found');
        }
        jsonResponse(true, $form);
        break;

    case 'save-form':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $payload = json_decode((string)($_POST['form'] ?? ''), true);
        if (!is_array($payload)) {
            jsonResponse(false, null, 'Invalid form JSON');
        }
        $savedForm = nibblyFormSave($payload);
        jsonResponse(true, $savedForm, 'Form saved');
        break;

    case 'mark-mail-read':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $mailId = $_POST['mail_id'] ?? '';
        if (empty($mailId)) {
            jsonResponse(false, null, 'Mail ID missing');
        }

        $mailsFile = dirname(CONTENT_PATH) . '/mails.json';
        if (!file_exists($mailsFile)) {
            jsonResponse(false, null, 'No mails found');
        }

        $mails = json_decode(file_get_contents($mailsFile), true) ?: [];
        $found = false;

        foreach ($mails as &$mail) {
            if ($mail['id'] === $mailId) {
                $mail['read'] = true;
                $found = true;
                break;
            }
        }
        unset($mail);

        if (!$found) {
            jsonResponse(false, null, 'Mail not found');
        }

        file_put_contents($mailsFile, json_encode($mails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        jsonResponse(true, null, 'Mail marked as read');
        break;

    case 'update-mail-flags':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $mailId = $_POST['mail_id'] ?? '';
        if (empty($mailId)) {
            jsonResponse(false, null, 'Mail ID missing');
        }

        $allowedFlags = ['read', 'starred'];
        $updates = [];
        foreach ($allowedFlags as $flag) {
            if (array_key_exists($flag, $_POST)) {
                $updates[$flag] = filter_var($_POST[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (empty($updates)) {
            jsonResponse(false, null, 'No mail flags provided');
        }

        $mailsFile = dirname(CONTENT_PATH) . '/mails.json';
        if (!file_exists($mailsFile)) {
            jsonResponse(false, null, 'No mails found');
        }

        $mails = json_decode(file_get_contents($mailsFile), true) ?: [];
        $found = false;

        foreach ($mails as &$mail) {
            if (($mail['id'] ?? '') === $mailId) {
                foreach ($updates as $flag => $value) {
                    $mail[$flag] = $value;
                }
                $found = true;
                break;
            }
        }
        unset($mail);

        if (!$found) {
            jsonResponse(false, null, 'Mail not found');
        }

        file_put_contents($mailsFile, json_encode($mails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        jsonResponse(true, ['mail_id' => $mailId, 'updates' => $updates], 'Mail flags updated');
        break;

    case 'mark-all-mails-read':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $mailsFile = dirname(CONTENT_PATH) . '/mails.json';
        if (!file_exists($mailsFile)) {
            jsonResponse(true, null, 'No mails found');
        }

        $mails = json_decode(file_get_contents($mailsFile), true) ?: [];

        foreach ($mails as &$mail) {
            $mail['read'] = true;
        }
        unset($mail);

        file_put_contents($mailsFile, json_encode($mails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        jsonResponse(true, null, 'All mails marked as read');
        break;

    case 'delete-mail':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $mailId = $_POST['mail_id'] ?? '';
        if (empty($mailId)) {
            jsonResponse(false, null, 'Mail ID missing');
        }

        $mailsFile = dirname(CONTENT_PATH) . '/mails.json';
        if (!file_exists($mailsFile)) {
            jsonResponse(false, null, 'No mails found');
        }

        $mails = json_decode(file_get_contents($mailsFile), true) ?: [];
        $originalCount = count($mails);

        $mails = array_filter($mails, function($mail) use ($mailId) {
            return $mail['id'] !== $mailId;
        });

        if (count($mails) === $originalCount) {
            jsonResponse(false, null, 'Mail not found');
        }

        $mails = array_values($mails);

        file_put_contents($mailsFile, json_encode($mails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        jsonResponse(true, null, 'Mail deleted');
        break;

    case 'delete-read-mails':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $mailsFile = dirname(CONTENT_PATH) . '/mails.json';
        if (!file_exists($mailsFile)) {
            jsonResponse(true, ['deleted' => 0], 'No mails found');
        }

        $mails = json_decode(file_get_contents($mailsFile), true) ?: [];
        $originalCount = count($mails);
        $mails = array_values(array_filter($mails, function($mail) {
            return !($mail['read'] ?? false);
        }));
        $deletedCount = $originalCount - count($mails);

        file_put_contents($mailsFile, json_encode($mails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        jsonResponse(true, ['deleted' => $deletedCount], 'Read mails deleted');
        break;

    case 'unread-mail-count':
        $mailsFile = dirname(CONTENT_PATH) . '/mails.json';
        if (!file_exists($mailsFile)) {
            jsonResponse(true, ['count' => 0]);
        }

        $mails = json_decode(file_get_contents($mailsFile), true) ?: [];
        $unreadCount = count(array_filter($mails, function($mail) {
            return !($mail['read'] ?? false);
        }));

        jsonResponse(true, ['count' => $unreadCount]);
        break;

    // ============================================================
    // PASSWORD MANAGEMENT
    // ============================================================

    case 'change-password':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $newPasswordConfirm = $_POST['new_password_confirm'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($newPasswordConfirm)) {
            jsonResponse(false, null, 'All fields are required');
        }

        $userId = $_SESSION['admin_user_id'] ?? '';
        $currentUser = findUserById($userId);
        if (!$currentUser) {
            jsonResponse(false, null, 'User not found');
        }

        if (!password_verify($currentPassword, $currentUser['passwordHash'])) {
            jsonResponse(false, null, 'Current password is incorrect');
        }

        if ($newPassword !== $newPasswordConfirm) {
            jsonResponse(false, null, 'New passwords do not match');
        }

        if ($currentPassword === $newPassword) {
            jsonResponse(false, null, 'New password must be different from current password');
        }

        // Password strength check
        if (strlen($newPassword) < 8 ||
            !preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[a-z]/', $newPassword) ||
            !preg_match('/[0-9]/', $newPassword) ||
            !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            jsonResponse(false, null, 'Password does not meet requirements: at least 8 characters with uppercase, lowercase, digits, and special characters');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!updateUserPassword($userId, $newHash)) {
            jsonResponse(false, null, 'Could not update password');
        }

        // Clear password warning
        unset($_SESSION['password_warning']);

        jsonResponse(true, null, 'Password changed successfully');
        break;

    // ============================================================
    // SETTINGS
    // ============================================================

    case 'load-settings':
        $defaults = [
            'favicon' => defined('NIBBLY_DEFAULT_FAVICON') ? NIBBLY_DEFAULT_FAVICON : '/assets/images/favicon.svg',
            'favicon_png' => '',
            'branding' => [
                'logo' => '',
                'logoDark' => '',
                'adminLogo' => '',
                'name' => defined('SITE_NAME') ? SITE_NAME : 'CMS',
                'showBranding' => true,
                'logoDisplay' => 'both',
                'logoSize' => 'medium'
            ],
            'theme' => [
                'adminTheme' => 'light',
                'primaryColor' => '#3858e9',
                'accentColor' => '#3858e9',
                'sidebarBg' => '',
                'darkPrimaryColor' => '',
                'darkAccentColor' => '',
                'darkSidebarBg' => '',
                'buttonGlow' => true,
                'buttonRadius' => 6
            ],
            'email' => [
                'method' => 'inactive',
                'recipientEmail' => '',
                'bccEmail' => '',
                'fromEmail' => '',
                'fromName' => defined('SITE_NAME') ? SITE_NAME : '',
                'smtpHost' => '',
                'smtpPort' => 587,
                'smtpUsername' => '',
                'smtpPassword' => '',
                'smtpEncryption' => 'tls'
            ],
            'privacy' => [
                'emailObfuscation' => false,
                'rememberPublicTheme' => true
            ],
            'modules' => [
                'ai' => true,
                'news' => true,
                'events' => true,
                'messages' => true,
                'iconManager' => true
            ],
            'dashboard' => [
                'itemsPerPage' => 50,
                'iconManagerItemsPerPage' => 50,
                'mediaItemsPerPage' => 25
            ],
            'access' => [
                'maintenance' => [
                    'enabled' => false,
                    'mode' => 'maintenance',
                    'title' => t('settings.maintenance_default_title'),
                    'text' => t('settings.maintenance_default_text'),
                    'until' => '',
                    'showCountdown' => false,
                    'brandAsset' => 'none',
                    'image' => '',
                    'imageLayout' => 'none',
                    'overlayColor' => '',
                    'overlayOpacity' => 88,
                    'bypassParam' => 'preview',
                    'bypassKeyHash' => ''
                ]
            ],
            'login' => [
                'brandAsset' => 'favicon',
                'image' => '',
                'imageLayout' => 'none',
                'overlayColor' => '',
                'overlayOpacity' => 86,
                'boxStyle' => 'card',
                'boxColor' => '',
                'boxTextColor' => ''
            ],
            'seo' => [
                'defaultOgImage' => ''
            ]
        ];

        if (!defined('SETTINGS_PATH') || !file_exists(SETTINGS_PATH)) {
            $defaults['access']['maintenance']['hasBypassKey'] = false;
            unset($defaults['access']['maintenance']['bypassKeyHash']);
            jsonResponse(true, $defaults);
        }

        $settings = json_decode(file_get_contents(SETTINGS_PATH), true);
        if ($settings === null) {
            $defaults['access']['maintenance']['hasBypassKey'] = false;
            unset($defaults['access']['maintenance']['bypassKeyHash']);
            jsonResponse(true, $defaults);
        }

        // Merge with defaults to ensure all keys exist
        $merged = array_replace_recursive($defaults, $settings);
        $merged['access']['maintenance']['hasBypassKey'] = !empty($merged['access']['maintenance']['bypassKeyHash']);
        unset($merged['access']['maintenance']['bypassKeyHash']);
        jsonResponse(true, $merged);
        break;

    case 'save-settings':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!defined('SETTINGS_PATH')) {
            jsonResponse(false, null, 'Settings path not configured');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $settingsJson = $_POST['settings'] ?? '';
        $settings = json_decode($settingsJson, true);
        if ($settings === null) {
            jsonResponse(false, null, 'Invalid JSON format');
        }

        // Whitelist allowed keys
        $allowed = [
            'branding' => ['logo', 'logoDark', 'adminLogo', 'name', 'showBranding', 'logoDisplay', 'logoSize'],
            'theme' => ['adminTheme', 'publicDefault', 'primaryColor', 'accentColor', 'sidebarBg', 'darkPrimaryColor', 'darkAccentColor', 'darkSidebarBg', 'buttonGlow', 'buttonRadius'],
            'general' => ['adminLanguage', 'frontendLoginRedirect'],
            'email' => ['method', 'recipientEmail', 'bccEmail', 'fromEmail', 'fromName', 'smtpHost', 'smtpPort', 'smtpUsername', 'smtpPassword', 'smtpEncryption'],
            'seo' => ['defaultOgImage']
        ];

        // Top-level scalar settings (not nested under a group)
        $allowedScalar = ['favicon', 'favicon_png'];

        $sanitized = [];
        foreach ($allowed as $group => $keys) {
            if (!isset($settings[$group])) continue;
            $sanitized[$group] = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $settings[$group])) {
                    $value = $settings[$group][$key];

                    // Validate color values — required (must be set)
                    if (in_array($key, ['primaryColor', 'accentColor'])) {
                        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                            jsonResponse(false, null, 'Invalid color value for ' . $key);
                        }
                    }

                    // Validate optional color values — empty string means "auto"
                    if (in_array($key, ['sidebarBg', 'darkPrimaryColor', 'darkAccentColor', 'darkSidebarBg'])) {
                        $value = (string)$value;
                        if ($value !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                            jsonResponse(false, null, 'Invalid color value for ' . $key);
                        }
                    }

                    // Validate theme choices
                    if (in_array($key, ['adminTheme', 'publicDefault'], true) && !in_array($value, ['light', 'dark', 'system'], true)) {
                        jsonResponse(false, null, 'Invalid theme value');
                    }

                    // Validate buttonGlow (boolean)
                    if ($key === 'buttonGlow') {
                        $value = (bool)$value;
                    }

                    // Validate buttonRadius (integer 0-24)
                    if ($key === 'buttonRadius') {
                        $value = max(0, min(24, intval($value)));
                    }

                    // Validate frontendLoginRedirect mode
                    if ($key === 'frontendLoginRedirect' && !in_array($value, ['auto', 'dashboard'], true)) {
                        jsonResponse(false, null, 'Invalid frontendLoginRedirect value');
                    }

                    // Validate logo paths (prevent traversal and protocol injection)
                    if (in_array($key, ['logo', 'logoDark', 'adminLogo'], true)) {
                        $value = (string)$value;
                        if ($value !== '' && (
                            strpos($value, '..') !== false ||
                            !str_starts_with($value, '/assets/images/') ||
                            preg_match('#[:\x00]#', $value)
                        )) {
                            jsonResponse(false, null, 'Invalid logo path');
                        }
                    }

                    if ($group === 'seo' && $key === 'defaultOgImage') {
                        $value = normalizeOgImagePath((string)$value);
                        if (!validateOgImagePath($value)) {
                            jsonResponse(false, null, 'Default Open Graph image must be a JPG or PNG file from /assets/images/');
                        }
                    }

                    // Validate logoDisplay (3-way selector)
                    if ($key === 'logoDisplay' && !in_array($value, ['favicon', 'text', 'both'], true)) {
                        $value = 'both';
                    }

                    // Validate public logo size selector
                    if ($key === 'logoSize' && !in_array($value, ['small', 'medium', 'large'], true)) {
                        $value = 'medium';
                    }

                    // Validate name
                    if ($key === 'name') {
                        $value = trim((string)$value);
                        if (strlen($value) > 100) {
                            $value = substr($value, 0, 100);
                        }
                    }

                    // Validate adminLanguage
                    if ($key === 'adminLanguage') {
                        $value = trim((string)$value);
                        if ($value !== '' && !preg_match('/^[a-z]{2,5}$/', $value)) {
                            jsonResponse(false, null, 'Invalid language code');
                        }
                        if ($value !== '' && !is_file(__DIR__ . '/lang/' . $value . '.json')) {
                            jsonResponse(false, null, 'Language file not found');
                        }
                    }

                    // Validate boolean
                    if ($key === 'showBranding') {
                        $value = (bool)$value;
                    }

                    // Validate email settings
                    if ($group === 'email') {
                        if ($key === 'method' && !in_array($value, ['smtp', 'sendmail', 'inactive'])) {
                            $value = 'smtp';
                        }
                        if (in_array($key, ['recipientEmail', 'bccEmail'], true)) {
                            if (!nibblyValidateEmailList($value)) {
                                jsonResponse(false, null, 'Invalid email address list for ' . $key);
                            }
                            $value = nibblyNormalizeEmailList($value);
                        }
                        if ($key === 'fromEmail') {
                            $value = trim((string)$value);
                        }
                        if ($key === 'fromEmail' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            jsonResponse(false, null, 'Invalid email address for ' . $key);
                        }
                        if ($key === 'smtpPort') {
                            $value = max(1, min(65535, intval($value)));
                        }
                        if ($key === 'smtpEncryption' && !in_array($value, ['tls', 'ssl', 'none'])) {
                            $value = 'tls';
                        }
                        if (in_array($key, ['smtpHost', 'smtpUsername', 'fromName'])) {
                            $value = trim((string)$value);
                        }
                        // smtpPassword: allow empty (means "keep existing")
                        if ($key === 'smtpPassword' && $value === '') {
                            // Load existing password and keep it
                            if (file_exists(SETTINGS_PATH)) {
                                $existingSettings = json_decode(file_get_contents(SETTINGS_PATH), true) ?: [];
                                $existingPw = $existingSettings['email']['smtpPassword'] ?? '';
                                if ($existingPw !== '') {
                                    $value = $existingPw;
                                }
                            }
                        }
                    }

                    $sanitized[$group][$key] = $value;
                }
            }
        }

        // Top-level scalars (favicon, PNG fallback)
        foreach ($allowedScalar as $scalarKey) {
            if (!array_key_exists($scalarKey, $settings)) continue;
            $value = (string)$settings[$scalarKey];
            if (in_array($scalarKey, ['favicon', 'favicon_png'], true)) {
                if ($value !== '' && (
                    strpos($value, '..') !== false ||
                    !str_starts_with($value, '/assets/images/') ||
                    preg_match('#[:\x00]#', $value)
                )) {
                    jsonResponse(false, null, 'Invalid favicon path');
                }
            }
            $sanitized[$scalarKey] = $value;
        }

        if (!empty($sanitized['theme']) && is_array($sanitized['theme'])) {
            $sanitized['theme'] = nibblySanitizeThemeContrast($sanitized['theme']);
        }

        $contentDir = dirname(SETTINGS_PATH);
        if (!is_dir($contentDir)) {
            mkdir($contentDir, 0755, true);
        }

        // Merge with existing file to preserve non-whitelisted keys (e.g. favicon)
        $existing = [];
        if (file_exists(SETTINGS_PATH)) {
            $existing = json_decode(file_get_contents(SETTINGS_PATH), true) ?: [];
        }
        $accessPatch = sanitizeAccessSettings($settings, $existing);
        if ($accessPatch) {
            $sanitized['access'] = $accessPatch;
        }
        $loginPatch = sanitizeLoginVisualSettings($settings);
        if ($loginPatch) {
            $sanitized['login'] = $loginPatch;
        }
        $privacyPatch = sanitizePrivacySettings($settings);
        if ($privacyPatch) {
            $sanitized['privacy'] = $privacyPatch;
        }
        $modulesPatch = sanitizeModuleSettings($settings);
        if ($modulesPatch) {
            $sanitized['modules'] = $modulesPatch;
        }
        $dashboardPatch = sanitizeDashboardSettings($settings);
        if ($dashboardPatch) {
            $sanitized['dashboard'] = $dashboardPatch;
        }
        $merged = array_replace_recursive($existing, $sanitized);

        $result = file_put_contents(
            SETTINGS_PATH,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error saving settings');
        }

        $publicMerged = $merged;
        $publicMerged['access']['maintenance']['hasBypassKey'] = !empty($publicMerged['access']['maintenance']['bypassKeyHash']);
        unset($publicMerged['access']['maintenance']['bypassKeyHash']);
        jsonResponse(true, $publicMerged, 'Settings saved');
        break;

    case 'test-email':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $testConfig = json_decode($_POST['emailConfig'] ?? '{}', true);
        if (!$testConfig || empty($testConfig['recipientEmail'])) {
            jsonResponse(false, null, 'Recipient email is required');
        }

        if (!nibblyValidateEmailList($testConfig['recipientEmail'] ?? '')) {
            jsonResponse(false, null, 'Invalid email address list for recipientEmail');
        }
        if (!nibblyValidateEmailList($testConfig['bccEmail'] ?? '')) {
            jsonResponse(false, null, 'Invalid email address list for bccEmail');
        }

        $testToRecipients = nibblyParseEmailList($testConfig['recipientEmail'] ?? '');
        $testBccRecipients = nibblyParseEmailList($testConfig['bccEmail'] ?? '');
        if (empty($testToRecipients)) {
            jsonResponse(false, null, 'Recipient email is required');
        }

        $testTo = implode(', ', $testToRecipients);
        $testFrom = trim((string)($testConfig['fromEmail'] ?? '')) ?: $testToRecipients[0];
        if (!filter_var($testFrom, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, null, 'Invalid email address for fromEmail');
        }
        $testFromName = $testConfig['fromName'] ?: 'nibbly CMS';
        $testSubject = 'nibbly CMS — Test Email';
        $testBody = "This is a test email from nibbly CMS.\n\nIf you can read this, your email settings are working correctly.\n\nTimestamp: " . date('Y-m-d H:i:s');

        $testMethod = $testConfig['method'] ?? 'smtp';
        $testSent = false;
        $testError = '';

        if ($testMethod === 'smtp') {
            require_once __DIR__ . '/../api/SmtpMailer.php';
            $mailer = new SmtpMailer(
                $testConfig['smtpHost'] ?? '',
                intval($testConfig['smtpPort'] ?? 587),
                $testConfig['smtpUsername'] ?? '',
                $testConfig['smtpPassword'] ?? '',
                $testConfig['smtpEncryption'] ?? 'tls'
            );
            // If password is empty, try to load from saved settings
            if (empty($testConfig['smtpPassword']) && defined('SETTINGS_PATH') && file_exists(SETTINGS_PATH)) {
                $savedSettings = json_decode(file_get_contents(SETTINGS_PATH), true) ?: [];
                $savedPw = $savedSettings['email']['smtpPassword'] ?? '';
                if ($savedPw) {
                    $mailer = new SmtpMailer(
                        $testConfig['smtpHost'] ?? '',
                        intval($testConfig['smtpPort'] ?? 587),
                        $testConfig['smtpUsername'] ?? '',
                        $savedPw,
                        $testConfig['smtpEncryption'] ?? 'tls'
                    );
                }
            }
            $testSent = $mailer->send($testToRecipients, $testSubject, $testBody, $testFrom, $testFromName, '', $testBccRecipients);
            if (!$testSent) {
                $testError = $mailer->getLastError();
            }
        } elseif ($testMethod === 'sendmail') {
            $headers = [];
            $headers[] = 'From: ' . ($testFromName ? "=?UTF-8?B?" . base64_encode($testFromName) . "?= <$testFrom>" : $testFrom);
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'X-Mailer: nibbly CMS';
            $testSent = @mail($testTo, '=?UTF-8?B?' . base64_encode($testSubject) . '?=', $testBody, implode("\r\n", $headers));
            foreach ($testBccRecipients as $bccRecipient) {
                $testSent = @mail($bccRecipient, '=?UTF-8?B?' . base64_encode($testSubject) . '?=', $testBody, implode("\r\n", $headers)) && $testSent;
            }
            if (!$testSent) {
                $testError = 'PHP mail() returned false. Check server mail configuration.';
            }
        }

        if ($testSent) {
            jsonResponse(true, null, 'Test email sent successfully');
        } else {
            jsonResponse(false, null, $testError ?: 'Failed to send test email');
        }
        break;

    case 'total-reset':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $confirm = $_POST['confirm'] ?? '';
        if ($confirm !== 'DELETE') {
            jsonResponse(false, null, 'Confirmation mismatch');
        }

        $root = dirname(__DIR__);

        // Collect language directories from config
        $langDirs = [];
        if (isset($SITE_LANGUAGES) && is_array($SITE_LANGUAGES)) {
            foreach (array_keys($SITE_LANGUAGES) as $code) {
                $dir = $root . '/' . $code;
                if (is_dir($dir)) {
                    $langDirs[] = $dir;
                }
            }
        }

        // Recursive directory delete helper
        $rmdir = function($dir) use (&$rmdir) {
            if (!is_dir($dir)) return;
            $items = scandir($dir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    $rmdir($path);
                } else {
                    @unlink($path);
                }
            }
            @rmdir($dir);
        };

        // Delete content directory (pages, news, settings, events)
        $rmdir($root . '/content');

        // Delete language directories
        foreach ($langDirs as $dir) {
            $rmdir($dir);
        }

        // Delete backups
        $rmdir($root . '/backups');

        // Delete user-uploaded images (but keep favicon.svg)
        $imagesDir = $root . '/assets/images';
        if (is_dir($imagesDir)) {
            $items = scandir($imagesDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === 'favicon.svg') continue;
                $path = $imagesDir . '/' . $item;
                if (is_dir($path)) {
                    $rmdir($path);
                } else {
                    @unlink($path);
                }
            }
        }

        // Delete trash directories
        $rmdir($root . '/assets/images-trash');
        $rmdir($root . '/assets/audio-trash');

        // Clean audio directory (keep directory, remove files)
        $audioDir = $root . '/assets/audio';
        if (is_dir($audioDir)) {
            $items = scandir($audioDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $audioDir . '/' . $item;
                if (is_dir($path)) {
                    $rmdir($path);
                } else {
                    @unlink($path);
                }
            }
        }

        // Delete nav-config.php
        @unlink($root . '/includes/nav-config.php');

        // Delete config.php (must be last — triggers setup wizard)
        @unlink(__DIR__ . '/config.php');

        // Destroy session
        session_destroy();

        jsonResponse(true, null, 'Installation reset');
        break;

    // ─── Site Backup ────────────────────────────────────────────────

    case 'create-site-backup':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        require_once __DIR__ . '/../includes/backup-helper.php';

        // Tag as "manual" so the prune algorithm protects this backup
        // from automatic eviction — the admin explicitly asked for it.
        try {
            $created = backupWithLock(fn() => backupCreate('manual'));
        } catch (BackupLockException $e) {
            http_response_code(409);
            jsonResponse(false, null, $e->getMessage());
        }
        if (!$created['ok']) {
            jsonResponse(false, null, $created['message']);
        }

        // One-time download token. The file stays in the backup pool
        // after download (unlike the previous flow which deleted on
        // download) so it's still available later for restore.
        $token = bin2hex(random_bytes(32));
        $_SESSION['backup_download'] = [
            'token'   => $token,
            'file'    => $created['file'],
            'created' => time()
        ];

        jsonResponse(true, ['token' => $token, 'filename' => $created['file']], 'Backup created');
        break;

    case 'download-site-backup':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        // Validate one-time token
        if (!isset($_SESSION['backup_download'])) {
            http_response_code(403);
            jsonResponse(false, null, 'No backup download pending.');
        }

        $backupInfo = $_SESSION['backup_download'];
        $providedToken = $_GET['token'] ?? '';

        if (!hash_equals($backupInfo['token'], $providedToken)) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid download token.');
        }

        // Token expires after 5 minutes. The backup file stays — it's
        // a manual backup in the pool, the user can still download it
        // later via the scheduled-backup list (which generates a fresh
        // token).
        if (time() - $backupInfo['created'] > 300) {
            unset($_SESSION['backup_download']);
            http_response_code(410);
            jsonResponse(false, null, 'Download token expired.');
        }

        $zipPath = BACKUP_PATH . $backupInfo['file'];
        if (!is_file($zipPath)) {
            unset($_SESSION['backup_download']);
            http_response_code(404);
            jsonResponse(false, null, 'Backup file not found.');
        }

        // Consume the token (one-time use)
        $downloadName = $_GET['filename'] ?? 'site-backup.zip';
        $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '-', $downloadName);
        unset($_SESSION['backup_download']);

        // Release session lock before streaming
        session_write_close();

        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Send ZIP headers
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        // Stream file. Don't delete — the backup is now part of the
        // pool (tagged "manual") and the admin may want to keep it
        // server-side as well.
        readfile($zipPath);
        exit;

    case 'restore-site-backup':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        if (!class_exists('ZipArchive')) {
            jsonResponse(false, null, 'ZIP extension not available on this server.');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';

        // Source can be either an uploaded ZIP for off-server restore
        // or a pool file (the dashboard list
        // lets the admin restore an existing backup without uploading
        // it first). Normalise both to $uploadedFile + $maxSize.
        $poolFile = $_POST['pool_file'] ?? '';
        if ($poolFile !== '') {
            if (!backupIsPoolFilename($poolFile)) {
                jsonResponse(false, null, 'Invalid backup filename');
            }
            $uploadedFile = BACKUP_PATH . $poolFile;
            if (!is_file($uploadedFile)) {
                jsonResponse(false, null, 'Backup not found in pool');
            }
        } else {
            // Validate upload
            if (!isset($_FILES['backup_zip']) || $_FILES['backup_zip']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder missing.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                ];
                $code = $_FILES['backup_zip']['error'] ?? UPLOAD_ERR_NO_FILE;
                jsonResponse(false, null, $uploadErrors[$code] ?? 'Upload failed.');
            }
            $uploadedFile = $_FILES['backup_zip']['tmp_name'];
        }

        $mode = $_POST['restore_mode'] ?? '';
        if (!in_array($mode, ['full', 'content'])) {
            jsonResponse(false, null, 'Invalid restore mode.');
        }
        $maxSize = 500 * 1024 * 1024; // 500 MB
        if (filesize($uploadedFile) > $maxSize) {
            jsonResponse(false, null, 'File too large (max 500 MB).');
        }

        // Open and validate ZIP
        $zip = new ZipArchive();
        $result = $zip->open($uploadedFile);
        if ($result !== true) {
            jsonResponse(false, null, 'Invalid or corrupted ZIP file.');
        }

        // Collect all entries and run security checks
        $entries = [];
        $hasContentPage = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Path traversal check
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                jsonResponse(false, null, 'ZIP contains unsafe paths (path traversal detected).');
            }

            $entries[] = $name;

            // Check for content pages
            if (preg_match('#^content/pages/[a-z]{2}_[a-z0-9_-]+\.json$#i', $name)) {
                $hasContentPage = true;
            }
        }

        // Structure checks: required nibbly files must be present
        $requiredFiles = [
            'admin/api.php',
            'admin/dashboard.php',
            'admin/config.php',
            'includes/content-loader.php',
            'includes/header.php',
            'includes/footer.php',
            'router.php',
            'index.php',
            'css/style.css',
        ];
        $missingFiles = [];
        foreach ($requiredFiles as $req) {
            if ($zip->locateName($req) === false) {
                $missingFiles[] = $req;
            }
        }
        if (!empty($missingFiles)) {
            $zip->close();
            jsonResponse(false, null, 'Not a valid nibbly backup. Missing: ' . implode(', ', $missingFiles));
        }
        if (!$hasContentPage) {
            $zip->close();
            jsonResponse(false, null, 'Not a valid nibbly backup. No content pages found.');
        }

        // Allowed PHP locations (security: reject PHP files in unexpected places)
        // Root-level PHP files that are allowed
        $allowedRootPhp = ['index.php', 'router.php', 'route.php', '404.php', 'sitemap.php'];
        // Directories where PHP files are allowed
        $allowedPhpDirs = ['admin/', 'includes/', 'api/', 'cli/', 'examples/'];

        // File extension whitelist
        $allowedExtensions = [
            'php', 'json', 'css', 'js', 'html', 'htm', 'htaccess',
            'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'avif',
            'mp3', 'ogg', 'wav', 'm4a',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            'txt', 'xml', 'md',
        ];

        $rejectedPhpFiles = [];
        foreach ($entries as $entry) {
            // Skip directories
            if (str_ends_with($entry, '/')) continue;

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            // Check PHP files are in allowed locations
            if ($ext === 'php') {
                $isAllowed = false;

                // Check if it's an allowed root file
                if (!str_contains($entry, '/') && in_array($entry, $allowedRootPhp)) {
                    $isAllowed = true;
                }

                // Check if it's in an allowed directory
                foreach ($allowedPhpDirs as $dir) {
                    if (str_starts_with($entry, $dir)) {
                        $isAllowed = true;
                        break;
                    }
                }

                // Check if it's in a language directory (2-letter code)
                if (preg_match('#^[a-z]{2}/#', $entry)) {
                    $isAllowed = true;
                }

                if (!$isAllowed) {
                    $rejectedPhpFiles[] = $entry;
                }
            }

            // Check file extension whitelist (skip dirs)
            if ($ext !== '' && !in_array($ext, $allowedExtensions) && basename($entry) !== '.htaccess') {
                // Silently skip — will not extract these files
            }
        }

        if (!empty($rejectedPhpFiles)) {
            $zip->close();
            jsonResponse(false, null, 'ZIP contains PHP files in unexpected locations: ' . implode(', ', array_slice($rejectedPhpFiles, 0, 5)));
        }

        $siteRoot = realpath(__DIR__ . '/..');

        // For full restore: create automatic backup first
        if ($mode === 'full') {
            $backupDir = BACKUP_PATH;
            if (!is_dir($backupDir)) {
                @mkdir($backupDir, 0755, true);
            }

            $preRestoreZip = new ZipArchive();
            $preRestorePath = $backupDir . 'pre-restore-' . date('Y-m-d_His') . '.zip';
            if ($preRestoreZip->open($preRestorePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($siteRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $file) {
                    $filePath = $file->getRealPath();
                    if ($filePath === false) continue;
                    if ($filePath === realpath($preRestorePath)) continue;
                    $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($siteRoot) + 1));
                    if (backupShouldSkipPath($relativePath)) continue;

                    if ($file->isDir()) {
                        $preRestoreZip->addEmptyDir($relativePath);
                    } else {
                        $preRestoreZip->addFile($filePath, $relativePath);
                    }
                }
                $preRestoreZip->close();
            }
        }

        // Determine which entries to extract based on mode
        set_time_limit(300);
        $extracted = 0;
        $skipped = 0;

        // For content-only: clear existing content dirs first so deleted pages are removed
        if ($mode === 'content') {
            $clearDirs = [
                $siteRoot . '/content/pages',
                $siteRoot . '/content/news',
            ];
            foreach ($clearDirs as $clearDir) {
                if (is_dir($clearDir)) {
                    $files = glob($clearDir . '/*');
                    foreach ($files as $f) {
                        if (is_file($f)) @unlink($f);
                    }
                }
            }
        }

        // Content-only: paths that should be extracted
        // Full: everything (with extension whitelist)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            // Skip directories (they'll be created as needed)
            if (str_ends_with($entry, '/')) continue;

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            // Extension whitelist
            if ($ext !== '' && !in_array($ext, $allowedExtensions) && basename($entry) !== '.htaccess') {
                $skipped++;
                continue;
            }

            // In content-only mode, filter to user-data paths
            if ($mode === 'content') {
                $isContentFile = false;

                // content/ directory (all JSON files)
                if (str_starts_with($entry, 'content/')) $isContentFile = true;
                // assets/ (images, audio, fonts)
                if (str_starts_with($entry, 'assets/')) $isContentFile = true;
                // css/fonts.css
                if ($entry === 'css/fonts.css') $isContentFile = true;
                // Language template directories (2-letter code)
                if (preg_match('#^[a-z]{2}/#', $entry)) $isContentFile = true;
                // nav-config.php
                if ($entry === 'includes/nav-config.php') $isContentFile = true;
                // backups/ (JSON only, not ZIPs)
                if (str_starts_with($entry, 'backups/') && str_ends_with($entry, '.json')) $isContentFile = true;

                if (!$isContentFile) {
                    $skipped++;
                    continue;
                }
            }

            // Extract this file
            $targetPath = $siteRoot . '/' . $entry;
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($targetPath, $content);
                $extracted++;
            }
        }

        $zip->close();

        jsonResponse(true, [
            'extracted' => $extracted,
            'skipped' => $skipped,
            'mode' => $mode,
        ], $mode === 'full' ? 'Full site restored' : 'Content restored');
        break;

    // ============================================================
    // SCHEDULED BACKUPS — pool of *-backup-*-{tier}.zip files
    // ============================================================
    // These endpoints back the dashboard's "Automated backups" UI and
    // complement the manual create/download/restore flow above. The
    // ZIP-creation, retention, and tier logic lives in
    // includes/backup-helper.php so the cron CLI uses the exact same
    // code path as the admin UI.

    case 'backup-status':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        require_once __DIR__ . '/../includes/backup-helper.php';
        jsonResponse(true, backupStatus());
        break;

    case 'backup-list':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        require_once __DIR__ . '/../includes/backup-helper.php';
        jsonResponse(true, ['backups' => backupListAll()]);
        break;

    case 'backup-create-now':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        // Tag as "manual" — won't be auto-evicted by storage budget.
        try {
            $created = backupWithLock(fn() => backupCreate('manual'));
        } catch (BackupLockException $e) {
            http_response_code(409);
            jsonResponse(false, null, $e->getMessage());
        }
        if (!$created['ok']) {
            jsonResponse(false, null, $created['message']);
        }
        jsonResponse(true, $created, 'Backup created');
        break;

    case 'backup-prune':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        try {
            $deleted = backupWithLock(fn() => backupPrune());
        } catch (BackupLockException $e) {
            http_response_code(409);
            jsonResponse(false, null, $e->getMessage());
        }
        jsonResponse(true, ['deleted' => $deleted], count($deleted) . ' backup(s) pruned');
        break;

    case 'backup-delete':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        $file = $_POST['file'] ?? '';
        if (!backupIsPoolFilename($file)) {
            jsonResponse(false, null, 'Invalid backup filename');
        }
        $path = BACKUP_PATH . $file;
        if (!is_file($path)) {
            jsonResponse(false, null, 'Backup not found');
        }
        if (!@unlink($path)) {
            jsonResponse(false, null, 'Could not delete backup');
        }
        jsonResponse(true, null, 'Backup deleted');
        break;

    case 'backup-upload-remote':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        $file = $_POST['file'] ?? '';
        $targetId = $_POST['target_id'] ?? '';
        if (!backupIsPoolFilename($file)) {
            jsonResponse(false, null, 'Invalid backup filename');
        }
        if (!is_file(BACKUP_PATH . $file)) {
            jsonResponse(false, null, 'Backup not found');
        }
        try {
            $results = backupWithLock(fn() => backupUploadRemoteTargets($file, $targetId !== '' ? $targetId : null));
        } catch (BackupLockException $e) {
            http_response_code(409);
            jsonResponse(false, null, $e->getMessage());
        }
        $failed = array_values(array_filter($results, fn($result) => empty($result['ok'])));
        jsonResponse(empty($failed), ['results' => $results, 'status' => backupStatus()], empty($failed) ? 'Remote upload complete' : ($failed[0]['message'] ?? 'Remote upload failed'));
        break;

    case 'backup-remote-list':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        require_once __DIR__ . '/../includes/backup-helper.php';
        $target = backupRemoteTargetById($_GET['target_id'] ?? '');
        if (!$target) jsonResponse(false, null, 'Remote target not found');
        try {
            backupRemoteRefreshTarget($target);
            backupSaveRemoteTarget($target);
            $result = backupRemoteList($target);
            jsonResponse(!empty($result['ok']), ['files' => $result['files'] ?? []], $result['message'] ?? '');
        } catch (Throwable $e) {
            jsonResponse(false, ['files' => []], $e->getMessage());
        }
        break;

    case 'backup-remote-import':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        $target = backupRemoteTargetById($_POST['target_id'] ?? '');
        $file = $_POST['file'] ?? '';
        if (!$target) jsonResponse(false, null, 'Remote target not found');
        if (!backupIsPoolFilename($file)) jsonResponse(false, null, 'Invalid backup filename');
        if (!is_dir(BACKUP_PATH)) @mkdir(BACKUP_PATH, 0755, true);
        $destination = BACKUP_PATH . $file;
        if (is_file($destination)) jsonResponse(true, ['file' => $file, 'status' => backupStatus()], 'Backup already exists locally');
        try {
            backupRemoteRefreshTarget($target);
            backupSaveRemoteTarget($target);
            $result = backupRemoteDownload($file, $target, $destination);
        } catch (Throwable $e) {
            @unlink($destination);
            jsonResponse(false, null, $e->getMessage());
        }
        if (empty($result['ok'])) {
            @unlink($destination);
            jsonResponse(false, null, $result['message']);
        }
        jsonResponse(true, ['file' => $file, 'status' => backupStatus()], 'Backup imported');
        break;

    case 'backup-remote-delete':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        $target = backupRemoteTargetById($_POST['target_id'] ?? '');
        $file = $_POST['file'] ?? '';
        if (!$target) jsonResponse(false, null, 'Remote target not found');
        try {
            backupRemoteRefreshTarget($target);
            backupSaveRemoteTarget($target);
            $result = backupRemoteDelete($file, $target);
            jsonResponse(!empty($result['ok']), null, $result['message'] ?? '');
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'backup-test-remote':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        $targetId = $_POST['target_id'] ?? '';
        $config = backupConfig();
        $target = null;
        foreach ($config['remote_targets'] as $candidate) {
            if ($candidate['id'] === $targetId) {
                $target = $candidate;
                break;
            }
        }
        if (!$target) {
            jsonResponse(false, null, 'Remote target not found');
        }
        if (!is_dir(BACKUP_PATH)) @mkdir(BACKUP_PATH, 0755, true);
        $testFile = BACKUP_PATH . 'nibbly-remote-test-' . date('Ymd-His') . '.txt';
        file_put_contents($testFile, "nibbly remote backup test\n" . date('c') . "\n");
        try {
            backupRemoteRefreshTarget($target);
            $result = backupRemoteUpload($testFile, $target);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
        @unlink($testFile);
        $target['last_upload'] = date('c');
        $target['last_status'] = $result['ok'] ? 'success' : 'error';
        $target['last_message'] = $result['message'];
        $target['last_file'] = basename($testFile);
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $targetId) {
                $config['remote_targets'][$idx] = $target;
                break;
            }
        }
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        jsonResponse($result['ok'], ['status' => backupStatus()], $result['message']);
        break;

    case 'backup-dropbox-oauth-start':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        $targetId = $_GET['target_id'] ?? '';
        $config = backupConfig();
        $target = null;
        foreach ($config['remote_targets'] as $candidate) {
            if ($candidate['id'] === $targetId && $candidate['type'] === 'dropbox') {
                $target = $candidate;
                break;
            }
        }
        if (!$target) jsonResponse(false, null, 'Dropbox target not found');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), '/\\');
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        if ($brokerUrl !== '') {
            $state = bin2hex(random_bytes(24));
            $exchangeSecret = bin2hex(random_bytes(32));
            $_SESSION['backup_dropbox_broker_oauth'] = [
                'state' => $state,
                'target_id' => $targetId,
                'exchange_secret' => $exchangeSecret,
                'created' => time(),
            ];
            $returnUrl = $scheme . '://' . $host . $base . '/api.php?action=backup-dropbox-broker-callback';
            $brokerStartUrl = $brokerUrl . '/dropbox/start?' . http_build_query([
                'return_url' => $returnUrl,
                'state' => $state,
                'exchange_challenge' => hash('sha256', $exchangeSecret),
            ]);
            header_remove('Content-Type');
            header('Location: ' . $brokerStartUrl, true, 302);
            exit;
        }

        $appKey = $target['settings']['app_key'] ?? '';
        if ($appKey === '') $appKey = backupRemoteGlobalOAuthValue('dropbox', 'app_key');
        if ($appKey === '') jsonResponse(false, null, 'Dropbox app key is missing');

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(24));
        $_SESSION['backup_dropbox_oauth'] = [
            'state' => $state,
            'target_id' => $targetId,
            'code_verifier' => $verifier,
            'created' => time(),
        ];
        $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-dropbox-oauth-callback';
        $authUrl = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
            'client_id' => $appKey,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'files.content.write files.content.read files.metadata.read',
            'token_access_type' => 'offline',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        header_remove('Content-Type');
        header('Location: ' . $authUrl, true, 302);
        exit;

    case 'backup-dropbox-oauth-callback':
        if (!isAdmin()) redirectHtml('Dropbox connection failed', 'Your admin session is no longer active.');
        require_once __DIR__ . '/../includes/backup-helper.php';
        $pending = $_SESSION['backup_dropbox_oauth'] ?? null;
        if (!is_array($pending) || time() - ($pending['created'] ?? 0) > 600) {
            redirectHtml('Dropbox connection failed', 'The authorization request expired.');
        }
        if (!hash_equals($pending['state'], $_GET['state'] ?? '')) {
            redirectHtml('Dropbox connection failed', 'The authorization state did not match.');
        }
        $code = $_GET['code'] ?? '';
        if ($code === '') {
            redirectHtml('Dropbox connection failed', $_GET['error_description'] ?? ($_GET['error'] ?? 'Dropbox did not return an authorization code.'));
        }
        $config = backupConfig();
        $targetIndex = null;
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $pending['target_id'] && $candidate['type'] === 'dropbox') {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null) redirectHtml('Dropbox connection failed', 'Dropbox target no longer exists.');
        $target = $config['remote_targets'][$targetIndex];
        $appKey = $target['settings']['app_key'] ?? '';
        if ($appKey === '') $appKey = backupRemoteGlobalOAuthValue('dropbox', 'app_key');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), '/\\');
        $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-dropbox-oauth-callback';
        try {
            $tokenResult = backupRemoteCurl('https://api.dropboxapi.com/oauth2/token', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'client_id' => $appKey,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $pending['code_verifier'],
                ]),
                'timeout' => 60,
            ]);
        } catch (Throwable $e) {
            redirectHtml('Dropbox connection failed', $e->getMessage());
        }
        $tokenJson = json_decode($tokenResult['body'], true);
        if (!is_array($tokenJson) || empty($tokenJson['access_token'])) {
            redirectHtml('Dropbox connection failed', 'Dropbox token exchange failed.');
        }
        $target['settings']['access_token'] = $tokenJson['access_token'];
        if (!empty($tokenJson['refresh_token'])) {
            $target['settings']['refresh_token'] = $tokenJson['refresh_token'];
        }
        if (!empty($tokenJson['expires_in'])) {
            $target['settings']['expires_at'] = time() + (int)$tokenJson['expires_in'];
        }
        if (!empty($tokenJson['account_id'])) {
            $target['settings']['account_id'] = $tokenJson['account_id'];
        }
        $target['last_status'] = 'success';
        $target['last_message'] = 'Dropbox connected.';
        $target['last_upload'] = date('c');
        $config['remote_targets'][$targetIndex] = $target;
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        unset($_SESSION['backup_dropbox_oauth']);
        redirectHtml('Dropbox connected', 'You can close this tab and return to nibbly.', 'dashboard');

    case 'backup-dropbox-broker-callback':
        if (!isAdmin()) redirectHtml('Dropbox connection failed', 'Your admin session is no longer active.');
        require_once __DIR__ . '/../includes/backup-helper.php';
        $pending = $_SESSION['backup_dropbox_broker_oauth'] ?? null;
        if (!is_array($pending) || time() - ($pending['created'] ?? 0) > 600) {
            redirectHtml('Dropbox connection failed', 'The authorization request expired.');
        }
        if (!hash_equals($pending['state'], $_GET['state'] ?? '')) {
            redirectHtml('Dropbox connection failed', 'The authorization state did not match.');
        }
        $exchangeId = $_GET['exchange_id'] ?? '';
        if (!preg_match('/^[a-f0-9]{32,64}$/', $exchangeId)) {
            redirectHtml('Dropbox connection failed', 'The auth broker did not return a valid exchange ID.');
        }
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        try {
            $exchangeResult = backupRemoteCurl($brokerUrl . '/token/exchange', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'provider' => 'dropbox',
                    'exchange_id' => $exchangeId,
                    'exchange_secret' => $pending['exchange_secret'],
                ]),
                'timeout' => 60,
            ]);
        } catch (Throwable $e) {
            redirectHtml('Dropbox connection failed', $e->getMessage());
        }
        $exchangeJson = json_decode($exchangeResult['body'], true);
        if (!is_array($exchangeJson) || empty($exchangeJson['ok']) || empty($exchangeJson['token']['access_token'])) {
            redirectHtml('Dropbox connection failed', 'The auth broker token exchange failed.');
        }
        $config = backupConfig();
        $targetIndex = null;
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $pending['target_id'] && $candidate['type'] === 'dropbox') {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null) redirectHtml('Dropbox connection failed', 'Dropbox target no longer exists.');
        $target = $config['remote_targets'][$targetIndex];
        $tokenJson = $exchangeJson['token'];
        $target['settings']['access_token'] = $tokenJson['access_token'];
        if (!empty($tokenJson['refresh_token'])) {
            $target['settings']['refresh_token'] = $tokenJson['refresh_token'];
        }
        if (!empty($tokenJson['expires_in'])) {
            $target['settings']['expires_at'] = time() + (int)$tokenJson['expires_in'];
        }
        if (!empty($tokenJson['account_id'])) {
            $target['settings']['account_id'] = $tokenJson['account_id'];
        }
        $target['settings']['oauth_broker'] = backupRemoteOAuthBrokerUrl();
        $target['last_status'] = 'success';
        $target['last_message'] = 'Dropbox connected.';
        $target['last_upload'] = date('c');
        $config['remote_targets'][$targetIndex] = $target;
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        unset($_SESSION['backup_dropbox_broker_oauth']);
        redirectHtml('Dropbox connected', 'You can close this tab and return to nibbly.', 'dashboard');

    case 'backup-google-oauth-start':
    case 'backup-onedrive-oauth-start':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        $type = $action === 'backup-google-oauth-start' ? 'google_drive' : 'onedrive';
        $label = $type === 'google_drive' ? 'Google Drive' : 'OneDrive';
        $targetId = $_GET['target_id'] ?? '';
        $config = backupConfig();
        $target = null;
        foreach ($config['remote_targets'] as $candidate) {
            if ($candidate['id'] === $targetId && $candidate['type'] === $type) {
                $target = $candidate;
                break;
            }
        }
        if (!$target) jsonResponse(false, null, "$label target not found");
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), '/\\');
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        if (($type === 'google_drive' || $type === 'onedrive') && $brokerUrl !== '') {
            $state = bin2hex(random_bytes(24));
            $exchangeSecret = bin2hex(random_bytes(32));
            $brokerSessionKey = $type === 'google_drive' ? 'backup_google_broker_oauth' : 'backup_onedrive_broker_oauth';
            $brokerPath = $type === 'google_drive' ? 'google' : 'onedrive';
            $brokerCallbackAction = $type === 'google_drive' ? 'backup-google-broker-callback' : 'backup-onedrive-broker-callback';
            $_SESSION[$brokerSessionKey] = [
                'state' => $state,
                'target_id' => $targetId,
                'exchange_secret' => $exchangeSecret,
                'created' => time(),
            ];
            $returnUrl = $scheme . '://' . $host . $base . '/api.php?action=' . $brokerCallbackAction;
            $brokerStartUrl = $brokerUrl . '/' . $brokerPath . '/start?' . http_build_query([
                'return_url' => $returnUrl,
                'state' => $state,
                'exchange_challenge' => hash('sha256', $exchangeSecret),
            ]);
            header_remove('Content-Type');
            header('Location: ' . $brokerStartUrl, true, 302);
            exit;
        }
        $clientId = $target['settings']['client_id'] ?? '';
        if ($clientId === '') $clientId = backupRemoteGlobalOAuthValue($type, 'client_id');
        if ($clientId === '') jsonResponse(false, null, "$label client ID is missing");

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(24));
        $_SESSION['backup_oauth'] = [
            'provider' => $type,
            'state' => $state,
            'target_id' => $targetId,
            'code_verifier' => $verifier,
            'created' => time(),
        ];
        if ($type === 'google_drive') {
            $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-google-oauth-callback';
            $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $clientId,
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'scope' => 'https://www.googleapis.com/auth/drive.file',
                'access_type' => 'offline',
                'include_granted_scopes' => 'true',
                'prompt' => 'consent',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]);
        } else {
            $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-onedrive-oauth-callback';
            $authUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query([
                'client_id' => $clientId,
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
                'response_mode' => 'query',
                'state' => $state,
                'scope' => 'offline_access Files.ReadWrite.AppFolder',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]);
        }
        header_remove('Content-Type');
        header('Location: ' . $authUrl, true, 302);
        exit;

    case 'backup-google-broker-callback':
    case 'backup-onedrive-broker-callback':
        $type = $action === 'backup-google-broker-callback' ? 'google_drive' : 'onedrive';
        $label = $type === 'google_drive' ? 'Google Drive' : 'OneDrive';
        $sessionKey = $type === 'google_drive' ? 'backup_google_broker_oauth' : 'backup_onedrive_broker_oauth';
        if (!isAdmin()) redirectHtml("$label connection failed", 'Your admin session is no longer active.');
        require_once __DIR__ . '/../includes/backup-helper.php';
        $pending = $_SESSION[$sessionKey] ?? null;
        if (!is_array($pending) || time() - ($pending['created'] ?? 0) > 600) {
            redirectHtml("$label connection failed", 'The authorization request expired.');
        }
        if (!hash_equals($pending['state'], $_GET['state'] ?? '')) {
            redirectHtml("$label connection failed", 'The authorization state did not match.');
        }
        $exchangeId = $_GET['exchange_id'] ?? '';
        if (!preg_match('/^[a-f0-9]{32,64}$/', $exchangeId)) {
            redirectHtml("$label connection failed", 'The auth broker did not return a valid exchange ID.');
        }
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        try {
            $exchangeResult = backupRemoteCurl($brokerUrl . '/token/exchange', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'provider' => $type,
                    'exchange_id' => $exchangeId,
                    'exchange_secret' => $pending['exchange_secret'],
                ]),
                'timeout' => 60,
            ]);
        } catch (Throwable $e) {
            redirectHtml("$label connection failed", $e->getMessage());
        }
        $exchangeJson = json_decode($exchangeResult['body'], true);
        if (!is_array($exchangeJson) || empty($exchangeJson['ok']) || empty($exchangeJson['token']['access_token'])) {
            redirectHtml("$label connection failed", 'The auth broker token exchange failed.');
        }
        $config = backupConfig();
        $targetIndex = null;
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $pending['target_id'] && $candidate['type'] === $type) {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null) redirectHtml("$label connection failed", "$label target no longer exists.");
        $target = $config['remote_targets'][$targetIndex];
        $tokenJson = $exchangeJson['token'];
        $target['settings']['access_token'] = $tokenJson['access_token'];
        if (!empty($tokenJson['refresh_token'])) {
            $target['settings']['refresh_token'] = $tokenJson['refresh_token'];
        }
        if (!empty($tokenJson['expires_in'])) {
            $target['settings']['expires_at'] = time() + (int)$tokenJson['expires_in'];
        }
        if (!empty($tokenJson['client_id'])) {
            $target['settings']['client_id'] = $tokenJson['client_id'];
        }
        $target['settings']['oauth_broker'] = backupRemoteOAuthBrokerUrl();
        $target['last_status'] = 'success';
        $target['last_message'] = "$label connected.";
        $target['last_upload'] = date('c');
        $config['remote_targets'][$targetIndex] = $target;
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        unset($_SESSION[$sessionKey]);
        redirectHtml("$label connected", 'You can close this tab and return to nibbly.', 'dashboard');

    case 'backup-google-oauth-callback':
    case 'backup-onedrive-oauth-callback':
        $type = $action === 'backup-google-oauth-callback' ? 'google_drive' : 'onedrive';
        $label = $type === 'google_drive' ? 'Google Drive' : 'OneDrive';
        if (!isAdmin()) redirectHtml("$label connection failed", 'Your admin session is no longer active.');
        require_once __DIR__ . '/../includes/backup-helper.php';
        $pending = $_SESSION['backup_oauth'] ?? null;
        if (!is_array($pending) || ($pending['provider'] ?? '') !== $type || time() - ($pending['created'] ?? 0) > 600) {
            redirectHtml("$label connection failed", 'The authorization request expired.');
        }
        if (!hash_equals($pending['state'], $_GET['state'] ?? '')) {
            redirectHtml("$label connection failed", 'The authorization state did not match.');
        }
        $code = $_GET['code'] ?? '';
        if ($code === '') {
            redirectHtml("$label connection failed", $_GET['error_description'] ?? ($_GET['error'] ?? "$label did not return an authorization code."));
        }
        $config = backupConfig();
        $targetIndex = null;
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $pending['target_id'] && $candidate['type'] === $type) {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null) redirectHtml("$label connection failed", "$label target no longer exists.");
        $target = $config['remote_targets'][$targetIndex];
        $clientId = $target['settings']['client_id'] ?? '';
        if ($clientId === '') $clientId = backupRemoteGlobalOAuthValue($type, 'client_id');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), '/\\');
        if ($type === 'google_drive') {
            $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-google-oauth-callback';
            $tokenUrl = 'https://oauth2.googleapis.com/token';
            $tokenBody = [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $pending['code_verifier'],
            ];
            $clientSecret = $target['settings']['client_secret'] ?? backupRemoteGlobalOAuthValue('google_drive', 'client_secret');
            if ($clientSecret !== '') $tokenBody['client_secret'] = $clientSecret;
        } else {
            $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-onedrive-oauth-callback';
            $tokenUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
            $tokenBody = [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $pending['code_verifier'],
                'scope' => 'offline_access Files.ReadWrite.AppFolder',
            ];
            $clientSecret = $target['settings']['client_secret'] ?? backupRemoteGlobalOAuthValue('onedrive', 'client_secret');
            if ($clientSecret !== '') $tokenBody['client_secret'] = $clientSecret;
        }
        try {
            $tokenResult = backupRemoteCurl($tokenUrl, [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query($tokenBody),
                'timeout' => 60,
            ]);
        } catch (Throwable $e) {
            redirectHtml("$label connection failed", $e->getMessage());
        }
        $tokenJson = json_decode($tokenResult['body'], true);
        if (!is_array($tokenJson) || empty($tokenJson['access_token'])) {
            redirectHtml("$label connection failed", "$label token exchange failed.");
        }
        $target['settings']['access_token'] = $tokenJson['access_token'];
        if (!empty($tokenJson['refresh_token'])) {
            $target['settings']['refresh_token'] = $tokenJson['refresh_token'];
        }
        if (!empty($tokenJson['expires_in'])) {
            $target['settings']['expires_at'] = time() + (int)$tokenJson['expires_in'];
        }
        $target['last_status'] = 'success';
        $target['last_message'] = "$label connected.";
        $target['last_upload'] = date('c');
        $config['remote_targets'][$targetIndex] = $target;
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        unset($_SESSION['backup_oauth']);
        redirectHtml("$label connected", 'You can close this tab and return to nibbly.', 'dashboard');

    case 'backup-prepare-download':
        // Issues a one-time token for downloading an existing backup
        // from the pool. The download itself reuses the existing
        // download-site-backup endpoint — same token format.
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        $file = $_POST['file'] ?? '';
        if (!backupIsPoolFilename($file)) {
            jsonResponse(false, null, 'Invalid backup filename');
        }
        if (!is_file(BACKUP_PATH . $file)) {
            jsonResponse(false, null, 'Backup not found');
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['backup_download'] = [
            'token'   => $token,
            'file'    => $file,
            'created' => time(),
        ];
        jsonResponse(true, ['token' => $token, 'filename' => $file], 'Download token issued');
        break;

    case 'backup-update-settings':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once __DIR__ . '/../includes/backup-helper.php';
        $patch = [];
        if (isset($_POST['enabled'])) {
            $patch['enabled'] = ($_POST['enabled'] === 'true' || $_POST['enabled'] === '1');
        }
        if (isset($_POST['storage_limit_mb'])) {
            $patch['storage_limit_mb'] = max(0, (int)$_POST['storage_limit_mb']);
        }
        if (isset($_POST['cron_mode'])) {
            $patch['cron_mode'] = $_POST['cron_mode'] === 'web' ? 'web' : 'server';
        }
        $retention = [];
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $tier) {
            $key = "retention_$tier";
            if (isset($_POST[$key])) $retention[$tier] = max(0, (int)$_POST[$key]);
        }
        if (!empty($retention)) $patch['retention'] = $retention;
        if (isset($_POST['remote_targets'])) {
            $submitted = json_decode($_POST['remote_targets'], true);
            if (!is_array($submitted)) {
                jsonResponse(false, null, 'Invalid remote target settings');
            }
            $patch['remote_targets'] = backupRemoteMergeSubmittedTargets($submitted);
        }
        if (empty($patch)) {
            jsonResponse(false, null, 'No settings to update');
        }
        if (!backupSaveConfig($patch)) {
            jsonResponse(false, null, 'Could not write settings');
        }
        jsonResponse(true, backupStatus(), 'Backup settings updated');
        break;

    // (No backup-restore-from-pool — restore-site-backup now accepts
    // either a file upload OR a `pool_file` parameter referencing a
    // file in BACKUP_PATH. The dashboard uses the pool_file form.)

    // ============================================================
    // USER MANAGEMENT (admin only)
    // ============================================================

    case 'list-users':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        jsonResponse(true, getUsersForApi());
        break;

    case 'create-user':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $newUsername = trim($_POST['username'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');
        $newRole = $_POST['role'] ?? 'editor';
        $newPw = $_POST['password'] ?? '';

        if (empty($newUsername) || strlen($newUsername) < 3) {
            jsonResponse(false, null, 'Username must be at least 3 characters');
        }
        if (findUserByUsername($newUsername)) {
            jsonResponse(false, null, 'Username already exists');
        }
        if (!empty($newEmail) && findUserByEmail($newEmail)) {
            jsonResponse(false, null, 'Email already in use');
        }
        if (empty($newPw)) {
            jsonResponse(false, null, 'Password is required');
        }
        if (strlen($newPw) < 8 ||
            !preg_match('/[A-Z]/', $newPw) ||
            !preg_match('/[a-z]/', $newPw) ||
            !preg_match('/[0-9]/', $newPw) ||
            !preg_match('/[^A-Za-z0-9]/', $newPw)) {
            jsonResponse(false, null, 'Password does not meet requirements');
        }

        $createdBy = $_SESSION['admin_username'] ?? 'admin';
        $newUser = createUser($newUsername, $newEmail, $newPw, $newRole, $createdBy);
        jsonResponse(true, [
            'id' => $newUser['id'],
            'username' => $newUser['username'],
            'email' => $newUser['email'],
            'role' => $newUser['role'],
        ], 'User created');
        break;

    case 'update-user':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $editUserId = $_POST['user_id'] ?? '';
        $editUser = findUserById($editUserId);
        if (!$editUser) {
            jsonResponse(false, null, 'User not found');
        }

        $fields = [];
        if (isset($_POST['username'])) {
            $uname = trim($_POST['username']);
            if (strlen($uname) < 3) {
                jsonResponse(false, null, 'Username must be at least 3 characters');
            }
            $existing = findUserByUsername($uname);
            if ($existing && $existing['id'] !== $editUserId) {
                jsonResponse(false, null, 'Username already exists');
            }
            $fields['username'] = $uname;
        }
        if (isset($_POST['email'])) {
            $uemail = trim($_POST['email']);
            if (!empty($uemail)) {
                $existing = findUserByEmail($uemail);
                if ($existing && $existing['id'] !== $editUserId) {
                    jsonResponse(false, null, 'Email already in use');
                }
            }
            $fields['email'] = $uemail;
        }
        if (isset($_POST['role'])) {
            $newRole = $_POST['role'];
            if (!in_array($newRole, ['admin', 'editor'])) {
                jsonResponse(false, null, 'Invalid role');
            }
            // Prevent demoting the last admin
            if ($editUser['role'] === 'admin' && $newRole === 'editor' && countUsersByRole('admin') <= 1) {
                jsonResponse(false, null, 'Cannot demote the last admin');
            }
            $fields['role'] = $newRole;
        }

        if (!empty($fields)) {
            updateUser($editUserId, $fields);

            // Update session if editing self
            if ($editUserId === ($_SESSION['admin_user_id'] ?? '')) {
                if (isset($fields['username'])) $_SESSION['admin_username'] = $fields['username'];
                if (isset($fields['role'])) $_SESSION['admin_role'] = $fields['role'];
            }
        }

        jsonResponse(true, null, 'User updated');
        break;

    case 'delete-user':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $delUserId = $_POST['user_id'] ?? '';
        if ($delUserId === ($_SESSION['admin_user_id'] ?? '')) {
            jsonResponse(false, null, 'Cannot delete yourself');
        }

        $delUser = findUserById($delUserId);
        if (!$delUser) {
            jsonResponse(false, null, 'User not found');
        }

        if ($delUser['role'] === 'admin' && countUsersByRole('admin') <= 1) {
            jsonResponse(false, null, 'Cannot delete the last admin');
        }

        deleteUser($delUserId);
        jsonResponse(true, null, 'User deleted');
        break;

    case 'admin-reset-password':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $resetUserId = $_POST['user_id'] ?? '';
        $resetNewPw = $_POST['password'] ?? '';

        $resetUser = findUserById($resetUserId);
        if (!$resetUser) {
            jsonResponse(false, null, 'User not found');
        }

        if (empty($resetNewPw)) {
            jsonResponse(false, null, 'Password is required');
        }
        if (strlen($resetNewPw) < 8 ||
            !preg_match('/[A-Z]/', $resetNewPw) ||
            !preg_match('/[a-z]/', $resetNewPw) ||
            !preg_match('/[0-9]/', $resetNewPw) ||
            !preg_match('/[^A-Za-z0-9]/', $resetNewPw)) {
            jsonResponse(false, null, 'Password does not meet requirements');
        }

        $resetHash = password_hash($resetNewPw, PASSWORD_DEFAULT);
        updateUserPassword($resetUserId, $resetHash);
        jsonResponse(true, null, 'Password reset successfully');
        break;

    // ============================================================
    // MENU ORDER
    // ============================================================

    case 'get-menu-items':
        require_once __DIR__ . '/../includes/menu-helpers.php';
        if (!file_exists(__DIR__ . '/../includes/nav-config.php')) {
            $NAV_ITEMS = [];
        } else {
            include_once __DIR__ . '/../includes/nav-config.php';
            if (!isset($NAV_ITEMS)) $NAV_ITEMS = [];
        }

        $menuId = trim($_GET['menu'] ?? '');
        $lang = trim($_GET['lang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'));

        if (!$menuId) {
            jsonResponse(false, null, 'Missing menu parameter');
        }

        $allNavItems = $NAV_ITEMS[$lang] ?? [];
        $items = getMenuItems($menuId, $lang, '', $allNavItems);

        jsonResponse(true, ['items' => $items, 'menu' => $menuId, 'lang' => $lang]);
        break;

    case 'save-menu-order':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $menuId = trim($_POST['menu'] ?? '');
        $lang = trim($_POST['lang'] ?? '');
        $orderRaw = $_POST['order'] ?? '';

        if (!$menuId || !$lang) {
            jsonResponse(false, null, 'Missing menu or lang parameter');
        }

        $order = json_decode($orderRaw, true);
        if (!is_array($order)) {
            jsonResponse(false, null, 'Invalid order data');
        }

        // Sanitize: only allow valid slug characters
        $order = array_values(array_filter($order, fn($s) => is_string($s) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s)));

        $menusPath = __DIR__ . '/../content/menus.json';
        $registry = file_exists($menusPath) ? json_decode(file_get_contents($menusPath), true) : ['menus' => []];
        if (!isset($registry['menus'][$menuId])) {
            jsonResponse(false, null, 'Unknown menu: ' . $menuId);
        }

        if (!isset($registry['menus'][$menuId]['order'])) {
            $registry['menus'][$menuId]['order'] = [];
        }
        $registry['menus'][$menuId]['order'][$lang] = $order;

        file_put_contents($menusPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        jsonResponse(true, null, 'Menu order saved');
        break;

    default:
        jsonResponse(false, null, 'Unknown action');
}
