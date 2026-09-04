<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
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
        if (!nibblyPageIsValidPath($slug)) {
            jsonResponse(false, null, 'Invalid path (use lowercase URL segments separated by slashes)');
        }
        if (!preg_match('/^[a-z]{2}$/', $lang)) {
            jsonResponse(false, null, 'Invalid language');
        }

        $pageName = nibblyPageContentKey($lang, $slug);
        $filepath = CONTENT_PATH . $pageName . '.json';
        if (file_exists($filepath)) {
            jsonResponse(false, null, 'A page with this slug already exists');
        }

        $content = [
            'page' => $pageName,
            'path' => $slug,
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
        if (!nibblyPageIsValidPath($slug)) {
            jsonResponse(false, null, 'Invalid slug');
        }

        $sourceFile = CONTENT_PATH . $sourcePage . '.json';
        if (!file_exists($sourceFile)) {
            jsonResponse(false, null, 'Source page does not exist');
        }

        $targetPage = nibblyPageContentKey($targetLang, $slug);
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
        $content['path'] = $slug;
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
        while (file_exists(CONTENT_PATH . nibblyPageContentKey($lang, $copySlug) . '.json')) {
            $copySlug = $slug . '-copy-' . $counter;
            $counter++;
        }

        $newPage = nibblyPageContentKey($lang, $copySlug);
        $content = json_decode(file_get_contents($sourceFile), true);
        if ($content === null) {
            jsonResponse(false, null, 'Error reading source page');
        }

        $content['page'] = $newPage;
        $content['path'] = $copySlug;
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
            if (!preg_match('/^(.+)_(\d{4}-\d{2}-\d{2})_(\d{6})$/', $filename, $m) || !validatePageName($m[1])) {
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
        if (empty($trashFile) || !validateBackupName($trashFile)) {
            jsonResponse(false, null, 'Invalid trash filename');
        }

        $trashPath = PAGES_TRASH_PATH . $trashFile;
        if (!file_exists($trashPath)) {
            jsonResponse(false, null, 'Trash file not found');
        }

        // Extract original page name (remove timestamp suffix)
        $pageName = preg_replace('/_\d{4}-\d{2}-\d{2}_\d{6}(?:_[a-f0-9]{6})?\.json$/', '', $trashFile);
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
        if (empty($trashFile) || !validateBackupName($trashFile)) {
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
        $page = $_GET['page'] ?? $_POST['page'] ?? '';
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
        if (!is_array($contentData)) {
            jsonResponse(false, null, 'Invalid JSON format');
        }

        $filepath = CONTENT_PATH . $page . '.json';
        $existingData = file_exists($filepath) ? (json_decode(file_get_contents($filepath), true) ?: []) : [];
        $contentData = normalizePageVisibility($contentData, $existingData);
        $contentData = normalizePageSeo($contentData);

        // Create backup if file exists
        if (file_exists($filepath)) {
            $timestamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(3));
            $backupPath = BACKUP_PATH . $page . '_' . $timestamp . '.json';
            if (!copy($filepath, $backupPath)) jsonResponse(false, null, 'Could not create a backup before saving');
            cleanupOldBackups($page);
        }

        $contentData['lastModified'] = date('c');

        $result = nibblyJsonAtomicWrite($filepath, $contentData);

        if ($result === false) {
            jsonResponse(false, null, 'Error saving');
        }

        $responseData = ['lastModified' => $contentData['lastModified']];
        if (($pageParts = nibblyPageParseContentKey($page)) !== null) {
            $responseData['seoHealth'] = buildPageSeoHealth($pageParts['lang'], $pageParts['path'], $contentData);
        }

        // Optional: re-render the full sections list so the client can patch
        // the .editable-content-area DOM without a full page reload (used
        // after add/delete/reorder).  Returning the whole list keeps card-grid
        // grouping and index-based data-field attributes in sync.
        if (isset($GLOBALS['nibblyRevisionLock']) && is_resource($GLOBALS['nibblyRevisionLock'])) {
            flock($GLOBALS['nibblyRevisionLock'], LOCK_UN); fclose($GLOBALS['nibblyRevisionLock']);
        }
        if (!empty($_POST['render_sections'])) {
            require_once NIBBLY_ADMIN_DIR . '/../includes/content-loader.php';
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
            if (preg_match('/_(\d{4}-\d{2}-\d{2})_(\d{6})(?:_[a-f0-9]{6})?\.json$/', $filename, $matches)) {
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

        $page = preg_replace('/_\d{4}-\d{2}-\d{2}_\d{6}(?:_[a-f0-9]{6})?\.json$/', '', $backup);
        $filepath = CONTENT_PATH . $page . '.json';

        // Save current state before restoring
        if (file_exists($filepath)) {
            $timestamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(3));
            $newBackupPath = BACKUP_PATH . $page . '_' . $timestamp . '.json';
            copy($filepath, $newBackupPath);
        }

        $restoredData = json_decode((string)file_get_contents($backupPath), true);
        $result = is_array($restoredData) && nibblyJsonAtomicWrite($filepath, $restoredData);

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

}
