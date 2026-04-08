<?php
/**
 * API endpoint for Content Management
 * Actions: load, save, backups, restore, delete-backup, events, images, audio, mails
 */

require_once 'config.php';
require_once __DIR__ . '/users.php';
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

// Validate page name (lang_slug format, e.g. de_home, en_example)
function validatePageName($page) {
    return preg_match('/^[a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*$/', $page) || in_array($page, ['sidebar', 'footer']);
}

// Validate backup filename
function validateBackupName($backup) {
    return preg_match('/^([a-z]{2}_[a-z0-9]+(?:-[a-z0-9]+)*|sidebar|footer)_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', $backup);
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

    // ============================================================
    // CONTENT MANAGEMENT
    // ============================================================

    case 'list-pages':
        jsonResponse(true, buildPageList());
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

        jsonResponse(true, ['lastModified' => $contentData['lastModified']], 'Saved successfully');
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

        $timestamp = date('Y-m-d_His');
        $backupPath = BACKUP_PATH . 'events_' . $timestamp . '.json';
        copy(EVENTS_PATH, $backupPath);

        $originalCount = count($data['events']);
        $data['events'] = array_values(array_filter($data['events'], function($c) use ($eventId) {
            return $c['id'] !== $eventId;
        }));

        if (count($data['events']) === $originalCount) {
            jsonResponse(false, null, 'Event not found');
        }

        $data['lastModified'] = date('c');

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error deleting');
        }

        jsonResponse(true, null, 'Event deleted');
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
        if (!is_dir(IMAGES_PATH)) {
            jsonResponse(true, []);
        }

        $images = [];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

        $files = scandir(IMAGES_PATH);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExtensions)) {
                $sizeBytes = filesize(IMAGES_PATH . $file);
                $modified = filemtime(IMAGES_PATH . $file);

                if ($sizeBytes >= 1048576) {
                    $sizeFormatted = round($sizeBytes / 1048576, 1) . ' MB';
                } elseif ($sizeBytes >= 1024) {
                    $sizeFormatted = round($sizeBytes / 1024, 0) . ' KB';
                } else {
                    $sizeFormatted = $sizeBytes . ' B';
                }

                $images[] = [
                    'name' => $file,
                    'path' => '../assets/images/' . $file,
                    'sizeBytes' => $sizeBytes,
                    'size' => $sizeFormatted,
                    'modified' => $modified,
                    'dateFormatted' => date('d.m.Y H:i', $modified)
                ];
            }
        }

        usort($images, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        jsonResponse(true, $images);
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
            if (strpos($explicitFilename, '/') !== false || strpos($explicitFilename, '\\') !== false || strpos($explicitFilename, '..') !== false) {
                jsonResponse(false, null, 'Invalid filename');
            }

            $extension = strtolower(pathinfo($explicitFilename, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($extension, $allowedExtensions)) {
                jsonResponse(false, null, 'Invalid file extension');
            }

            $filename = $explicitFilename;

            if ($replaceMode && file_exists(IMAGES_PATH . $filename)) {
                if (!is_dir(IMAGES_TRASH_PATH)) {
                    mkdir(IMAGES_TRASH_PATH, 0755, true);
                }
                $backupName = pathinfo($filename, PATHINFO_FILENAME) . '_' . date('Y-m-d_His') . '.' . $extension;
                rename(IMAGES_PATH . $filename, IMAGES_TRASH_PATH . $backupName);
            } elseif (!$replaceMode && file_exists(IMAGES_PATH . $filename)) {
                $safeName = pathinfo($filename, PATHINFO_FILENAME);
                $counter = 1;
                while (file_exists(IMAGES_PATH . $filename)) {
                    $filename = $safeName . '-' . $counter . '.' . $extension;
                    $counter++;
                }
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

        if (!is_dir(IMAGES_PATH)) {
            mkdir(IMAGES_PATH, 0755, true);
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

        if (empty($filename) || strpos($filename, '/') !== false || strpos($filename, '\\') !== false || strpos($filename, '..') !== false) {
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
            jsonResponse(true, []);
        }

        $mails = json_decode(file_get_contents($mailsFile), true) ?: [];
        jsonResponse(true, $mails);
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
            'branding' => [
                'logo' => '/assets/images/favicon.svg',
                'name' => defined('SITE_NAME') ? SITE_NAME : 'CMS',
                'showBranding' => true
            ],
            'theme' => [
                'adminTheme' => 'light',
                'primaryColor' => '#2563eb',
                'accentColor' => '#60a5fa',
                'buttonGlow' => true,
                'buttonRadius' => 6
            ],
            'email' => [
                'method' => 'inactive',
                'recipientEmail' => '',
                'fromEmail' => '',
                'fromName' => defined('SITE_NAME') ? SITE_NAME : '',
                'smtpHost' => '',
                'smtpPort' => 587,
                'smtpUsername' => '',
                'smtpPassword' => '',
                'smtpEncryption' => 'tls'
            ]
        ];

        if (!defined('SETTINGS_PATH') || !file_exists(SETTINGS_PATH)) {
            jsonResponse(true, $defaults);
        }

        $settings = json_decode(file_get_contents(SETTINGS_PATH), true);
        if ($settings === null) {
            jsonResponse(true, $defaults);
        }

        // Merge with defaults to ensure all keys exist
        $merged = array_replace_recursive($defaults, $settings);
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
            'branding' => ['logo', 'name', 'showBranding'],
            'theme' => ['adminTheme', 'primaryColor', 'accentColor', 'buttonGlow', 'buttonRadius'],
            'general' => ['adminLanguage'],
            'email' => ['method', 'recipientEmail', 'fromEmail', 'fromName', 'smtpHost', 'smtpPort', 'smtpUsername', 'smtpPassword', 'smtpEncryption']
        ];

        $sanitized = [];
        foreach ($allowed as $group => $keys) {
            if (!isset($settings[$group])) continue;
            $sanitized[$group] = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $settings[$group])) {
                    $value = $settings[$group][$key];

                    // Validate color values
                    if (in_array($key, ['primaryColor', 'accentColor'])) {
                        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                            jsonResponse(false, null, 'Invalid color value for ' . $key);
                        }
                    }

                    // Validate adminTheme
                    if ($key === 'adminTheme' && !in_array($value, ['light', 'dark', 'system'])) {
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

                    // Validate logo path (prevent traversal and protocol injection)
                    if ($key === 'logo') {
                        $value = (string)$value;
                        if ($value !== '' && (
                            strpos($value, '..') !== false ||
                            !str_starts_with($value, '/assets/images/') ||
                            preg_match('#[:\x00]#', $value)
                        )) {
                            jsonResponse(false, null, 'Invalid logo path');
                        }
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
                        if (in_array($key, ['recipientEmail', 'fromEmail']) && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            jsonResponse(false, null, 'Invalid email address for ' . $key);
                        }
                        if ($key === 'smtpPort') {
                            $value = max(1, min(65535, intval($value)));
                        }
                        if ($key === 'smtpEncryption' && !in_array($value, ['tls', 'ssl', 'none'])) {
                            $value = 'tls';
                        }
                        if (in_array($key, ['smtpHost', 'smtpUsername', 'fromName', 'fromEmail', 'recipientEmail'])) {
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

        $contentDir = dirname(SETTINGS_PATH);
        if (!is_dir($contentDir)) {
            mkdir($contentDir, 0755, true);
        }

        // Merge with existing file to preserve non-whitelisted keys (e.g. favicon)
        $existing = [];
        if (file_exists(SETTINGS_PATH)) {
            $existing = json_decode(file_get_contents(SETTINGS_PATH), true) ?: [];
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

        jsonResponse(true, $merged, 'Settings saved');
        break;

    case 'test-email':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $testConfig = json_decode($_POST['emailConfig'] ?? '{}', true);
        if (!$testConfig || empty($testConfig['recipientEmail'])) {
            jsonResponse(false, null, 'Recipient email is required');
        }

        $testTo = $testConfig['recipientEmail'];
        $testFrom = $testConfig['fromEmail'] ?: $testTo;
        $testFromName = $testConfig['fromName'] ?: 'Nibbly CMS';
        $testSubject = 'Nibbly CMS — Test Email';
        $testBody = "This is a test email from Nibbly CMS.\n\nIf you can read this, your email settings are working correctly.\n\nTimestamp: " . date('Y-m-d H:i:s');

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
            $testSent = $mailer->send($testTo, $testSubject, $testBody, $testFrom, $testFromName);
            if (!$testSent) {
                $testError = $mailer->getLastError();
            }
        } elseif ($testMethod === 'sendmail') {
            $headers = [];
            $headers[] = 'From: ' . ($testFromName ? "=?UTF-8?B?" . base64_encode($testFromName) . "?= <$testFrom>" : $testFrom);
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'X-Mailer: Nibbly CMS';
            $testSent = @mail($testTo, '=?UTF-8?B?' . base64_encode($testSubject) . '?=', $testBody, implode("\r\n", $headers));
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

        if (!class_exists('ZipArchive')) {
            jsonResponse(false, null, 'ZIP extension not available on this server.');
        }

        $siteRoot = realpath(__DIR__ . '/..');
        $backupDir = BACKUP_PATH;

        // Ensure backup directory exists
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        // Check disk space (require at least 100 MB free)
        $freeSpace = @disk_free_space($backupDir);
        if ($freeSpace !== false && $freeSpace < 100 * 1024 * 1024) {
            jsonResponse(false, null, 'Not enough disk space to create a backup.');
        }

        // Clean up orphaned site backup ZIPs older than 1 hour
        $orphans = glob($backupDir . 'site-backup-*.zip');
        if ($orphans) {
            foreach ($orphans as $orphan) {
                if (filemtime($orphan) < time() - 3600) {
                    @unlink($orphan);
                }
            }
        }

        // Generate one-time download token and random filename
        $token = bin2hex(random_bytes(32));
        $zipFilename = 'site-backup-' . bin2hex(random_bytes(8)) . '.zip';
        $zipPath = $backupDir . $zipFilename;

        // Store token in session
        $_SESSION['backup_download'] = [
            'token' => $token,
            'file' => $zipFilename,
            'created' => time()
        ];

        // Directories to exclude (relative to site root)
        $excludeDirs = ['node_modules', '.git', 'screenshots', 'reference', '.vscode', '.idea', '.claude', 'vendor'];
        // Files to exclude by basename
        $excludeFiles = ['.DS_Store', 'Thumbs.db'];

        set_time_limit(300);

        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            jsonResponse(false, null, 'Could not create ZIP archive (error code: ' . $result . ')');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($siteRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($siteRoot) + 1);

            // Skip excluded directories
            $skip = false;
            foreach ($excludeDirs as $dir) {
                if (str_starts_with($relativePath, $dir . DIRECTORY_SEPARATOR) || $relativePath === $dir) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // Skip excluded files
            $basename = basename($relativePath);
            if (in_array($basename, $excludeFiles)) continue;
            if (str_ends_with($basename, '.tmp') || str_ends_with($basename, '.swp')) continue;

            // Skip site backup ZIPs (but keep page backup JSONs)
            if (str_starts_with($relativePath, 'backups' . DIRECTORY_SEPARATOR) &&
                str_starts_with($basename, 'site-backup-') && str_ends_with($basename, '.zip')) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();

        if (!is_file($zipPath)) {
            jsonResponse(false, null, 'ZIP file was not created.');
        }

        $siteName = defined('SITE_NAME') ? preg_replace('/[^a-zA-Z0-9_-]/', '-', SITE_NAME) : 'site';
        $downloadName = $siteName . '-backup-' . date('Y-m-d') . '.zip';

        jsonResponse(true, ['token' => $token, 'filename' => $downloadName], 'Backup created');
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

        // Token expires after 5 minutes
        if (time() - $backupInfo['created'] > 300) {
            unset($_SESSION['backup_download']);
            // Clean up the ZIP file
            $expiredZip = BACKUP_PATH . $backupInfo['file'];
            if (is_file($expiredZip)) {
                @unlink($expiredZip);
            }
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

        // Stream file and delete
        readfile($zipPath);
        @unlink($zipPath);
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

        $mode = $_POST['restore_mode'] ?? '';
        if (!in_array($mode, ['full', 'content'])) {
            jsonResponse(false, null, 'Invalid restore mode.');
        }

        $uploadedFile = $_FILES['backup_zip']['tmp_name'];
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

        // Structure checks: required Nibbly files must be present
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
            jsonResponse(false, null, 'Not a valid Nibbly backup. Missing: ' . implode(', ', $missingFiles));
        }
        if (!$hasContentPage) {
            $zip->close();
            jsonResponse(false, null, 'Not a valid Nibbly backup. No content pages found.');
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
                $excludeDirs = ['node_modules', '.git', 'screenshots', 'reference', '.vscode', '.idea', '.claude', 'vendor'];
                $excludeFiles = ['.DS_Store', 'Thumbs.db'];

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($siteRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $file) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($siteRoot) + 1);
                    $skip = false;
                    foreach ($excludeDirs as $dir) {
                        if (str_starts_with($relativePath, $dir . DIRECTORY_SEPARATOR) || $relativePath === $dir) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) continue;
                    $basename = basename($relativePath);
                    if (in_array($basename, $excludeFiles)) continue;
                    if (str_ends_with($basename, '.tmp') || str_ends_with($basename, '.swp')) continue;

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
