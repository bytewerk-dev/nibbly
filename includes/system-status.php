<?php
/** Administrative diagnostics; never exposed to visitors or editors. */
function nibblySystemStatus(string $scheme = ''): array {
    require_once __DIR__ . '/backup-helper.php';
    require_once __DIR__ . '/ai/ai-helper.php';
    require_once __DIR__ . '/security-check.php';
    require_once __DIR__ . '/seo-check.php';
    $extensions = [];
    foreach (['json', 'session', 'mbstring', 'dom', 'fileinfo', 'curl', 'zip', 'gd', 'openssl'] as $extension) {
        $extensions[] = ['name' => $extension, 'available' => extension_loaded($extension)];
    }
    $paths = [];
    foreach (['content', 'content/pages', 'content/news', 'assets/images', 'assets/audio', 'assets/videos', 'assets/documents', 'backups'] as $relative) {
        $path = dirname(__DIR__) . '/' . $relative;
        $paths[] = ['name' => $relative, 'writable' => is_dir($path) && is_writable($path)];
    }
    $backups = backupListAll();
    $jobs = array_values(array_filter(nibblyAiListImageJobs(false, 100), fn($job) => $job['status'] === 'error'));
    // No prompts, API keys, provider URLs or full server paths in the status response.
    $jobs = array_map(fn($job) => ['id' => $job['id'], 'time' => $job['updatedAt'], 'error' => $job['error']], $jobs);
    $reservations = nibblyAiReservationSummary(nibblyAiLoadUsage());
    $requests = array_map(fn($entry) => [
        'id' => $entry['id'], 'status' => $entry['status'], 'provider' => $entry['provider'],
        'time' => $entry['updatedAt'] ?? $entry['createdAt'], 'reservedCents' => $entry['reservedCents'],
        'tasks' => array_values(array_filter(array_column($entry['tasks'] ?? [], 'id'))),
        'resolvable' => strtotime($entry['updatedAt'] ?? $entry['createdAt']) <= time() - 900
    ], $reservations['unresolved']);
    // The agent: what it needs on this server. Never a key, only whether one is set.
    require_once __DIR__ . '/ai/agent/agent-config.php';
    $aiPublic = nibblyAiLoadSettings(true);
    $agentDir = dirname(__DIR__) . '/content/ai-agent';
    $agent = [
        'enabled' => !empty($aiPublic['agent']['enabled']),
        'toolCalling' => nibblyAiSupportsToolCalling(nibblyAiLoadSettings(false)),
        'gd' => extension_loaded('gd'), 'webp' => function_exists('imagewebp'),
        'research' => !empty($aiPublic['research']['hasBraveApiKey']), 'stock' => !empty($aiPublic['stock']['hasPexelsApiKey']),
        // Created on first use under content/, which the security check below proves is not public.
        'storageWritable' => is_dir($agentDir) ? is_writable($agentDir) : is_writable(dirname($agentDir)),
    ];
    // Uploads: the PHP limits cap every upload before Nibbly sees it
    require_once __DIR__ . '/media-web-version.php';
    $serverLimit = nibblyUploadServerLimit();
    $uploads = [
        'uploadMaxFilesize' => (string)ini_get('upload_max_filesize'),
        'postMaxSize' => (string)ini_get('post_max_size'),
        'memoryLimit' => (string)ini_get('memory_limit'),
        'serverMaxBytes' => $serverLimit === PHP_INT_MAX ? null : $serverLimit,
        'imageMaxBytes' => nibblyMediaImageMaxBytes(),
        'webVersion' => nibblyMediaSettings()['webVersion'],
        'webp' => nibblyMediaCanEncodeWebp(),
    ];
    return [
        'php' => PHP_VERSION, 'extensions' => $extensions, 'paths' => $paths, 'agent' => $agent, 'uploads' => $uploads,
        'lastBackup' => isset($backups[0]['mtime']) ? date('c', $backups[0]['mtime']) : null,
        'failedJobs' => $jobs, 'requests' => $requests,
        // Cached network probe only; POST action security-check refreshes it.
        'security' => nibblySecurityStatus($scheme),
        // Cached result only; POST action seo-check refreshes it.
        'seo' => nibblySeoCheckStatus($scheme)
    ];
}
