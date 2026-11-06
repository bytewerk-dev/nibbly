<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/includes/search-helper.php';

// The search index follows every change of a page (the response is sent first; the rebuild runs at shutdown)
if (in_array($action, ['save', 'create-page', 'copy-page', 'delete-page', 'duplicate-page', 'restore-page', 'restore', 'move-page'], true)) {
    register_shutdown_function(static function (): void { nibblySearchIndexRebuild(); });
}

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'list-pages':
        jsonResponse(true, buildPageList());
        break;

    // Content search of the page list (dashboard, scope "content"): pages whose title, description or
    // editable content contains ?q= (folded, see nibblySearchContains()), as "lang|path" keys
    case 'list-page-matches':
        $query = trim((string)($_GET['q'] ?? ''));
        $matches = [];
        if ($query !== '') {
            foreach (glob(CONTENT_PATH . '*.json') ?: [] as $file) {
                $page = nibblyPageParseContentKey(basename($file, '.json'));
                if ($page === null) continue;
                $data = json_decode((string)file_get_contents($file), true);
                if (!is_array($data)) continue;
                $text = (string)($data['title'] ?? '') . ' ' . (string)($data['description'] ?? '') . ' ' . nibblySearchPageText($data);
                if (nibblySearchContains($text, $query)) $matches[] = $page['lang'] . '|' . $page['path'];
            }
        }
        jsonResponse(true, $matches);
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

        // Managed menus (Menus & Footer) take the new page like the derived ones always did.
        require_once NIBBLY_ADMIN_DIR . '/../includes/menu-helpers.php';
        nibblyMenusSyncPageNav($lang, $slug, [], getNavLocations($content));

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

    case 'move-page':
        // Change the address of a page: rename its file and those of its subpages, rewrite internal
        // links, redirect the old address and update managed menus. One language version at a time.
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $page = $_POST['page'] ?? '';
        if (!validatePageName($page) || ($parts = nibblyPageParseContentKey($page)) === null) {
            jsonResponse(false, null, 'Invalid page name');
        }
        $lang = $parts['lang'];
        $oldPath = $parts['path'];
        $newPath = trim($_POST['slug'] ?? '');
        if (!nibblyPageIsValidPath($newPath)) {
            jsonResponse(false, null, 'Invalid path (use lowercase URL segments separated by slashes)');
        }
        if ($oldPath === 'home' || $newPath === 'home') {
            jsonResponse(false, null, 'The home page cannot be moved');
        }
        if ($newPath === $oldPath) {
            jsonResponse(false, null, 'The page already has this address');
        }
        if (str_starts_with($newPath, $oldPath . '/')) {
            jsonResponse(false, null, 'A page cannot be moved below itself');
        }
        if (!file_exists(CONTENT_PATH . $page . '.json')) {
            jsonResponse(false, null, 'Page does not exist');
        }

        // Pages to rename: the page and all subpages (same language), old key → new key
        $oldPrefix = nibblyPageContentKey($lang, $oldPath);
        $newPrefix = nibblyPageContentKey($lang, $newPath);
        $moves = [$oldPrefix => $newPrefix];
        foreach (glob(CONTENT_PATH . $oldPrefix . '__*.json') ?: [] as $childFile) {
            $childKey = basename($childFile, '.json');
            $moves[$childKey] = $newPrefix . substr($childKey, strlen($oldPrefix));
        }
        foreach ($moves as $fromKey => $toKey) {
            if (file_exists(CONTENT_PATH . $toKey . '.json')) {
                jsonResponse(false, null, 'A page with this address already exists: ' . str_replace('__', '/', substr($toKey, 3)));
            }
        }

        require_once NIBBLY_ADMIN_DIR . '/../includes/json-store.php';
        // Backups of every file that moves, then rename and update page/path inside
        $stamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(3));
        foreach ($moves as $fromKey => $toKey) {
            if (!copy(CONTENT_PATH . $fromKey . '.json', BACKUP_PATH . $fromKey . '_' . $stamp . '.json')) {
                jsonResponse(false, null, 'Could not create a backup before moving');
            }
            cleanupOldBackups($fromKey);
        }
        $renamed = [];
        foreach ($moves as $fromKey => $toKey) {
            if (!rename(CONTENT_PATH . $fromKey . '.json', CONTENT_PATH . $toKey . '.json')) {
                foreach ($renamed as $doneTo => $doneFrom) @rename(CONTENT_PATH . $doneTo . '.json', CONTENT_PATH . $doneFrom . '.json');
                jsonResponse(false, null, 'Error moving page files');
            }
            $renamed[$toKey] = $fromKey;
            @unlink(CONTENT_PATH . $fromKey . '.json.lock');
            $data = json_decode((string)file_get_contents(CONTENT_PATH . $toKey . '.json'), true);
            if (is_array($data)) {
                $data['page'] = $toKey;
                $data['path'] = str_replace('__', '/', substr($toKey, 3));
                $data['lastModified'] = date('c');
                nibblyJsonAtomicWrite(CONTENT_PATH . $toKey . '.json', $data);
            }
        }

        // Internal links: "/old", "/old/sub", "/xx/old" (language prefix) inside any string of the
        // content files become the new address. Strings are matched at their start or after a quote,
        // "=", "(" or whitespace, so "/old-other" and "/foo/old" stay as they are.
        $quoted = preg_quote($oldPath, '~');
        $linkPattern = '~(^|(?<=["\'=(\s]))(/[a-z]{2})?/' . $quoted . '(?=$|[/"\'#?)\s])~';
        $rewriteStrings = static function ($value) use (&$rewriteStrings, $linkPattern, $oldPath, $newPath, &$changed) {
            if (is_string($value)) {
                if (!str_contains($value, '/' . $oldPath)) return $value;
                $out = preg_replace($linkPattern, '$1$2/' . $newPath, $value);
                if ($out === null) return $value;
                if ($out !== $value) $changed = true;
                return $out;
            }
            if (is_array($value)) {
                foreach ($value as $k => $v) $value[$k] = $rewriteStrings($v);
            }
            return $value;
        };
        $rewritten = 0;
        $contentRoot = dirname(CONTENT_PATH);
        $files = array_merge(
            glob(CONTENT_PATH . '*.json') ?: [],
            glob($contentRoot . '/news/*.json') ?: [],
            array_filter([$contentRoot . '/footer.json', $contentRoot . '/sidebar.json'], 'is_file')
        );
        foreach ($files as $file) {
            $raw = (string)file_get_contents($file);
            if (!str_contains($raw, '/' . $oldPath)) continue;
            $data = json_decode($raw, true);
            if (!is_array($data)) continue;
            $changed = false;
            $data = $rewriteStrings($data);
            if ($changed && nibblyJsonAtomicWrite($file, $data)) $rewritten++;
        }

        // Managed menus (content/menus.json): page items of this language follow the page and its subpages
        require_once NIBBLY_ADMIN_DIR . '/../includes/menu-helpers.php';
        foreach ($moves as $fromKey => $toKey) {
            nibblyMenusRenamePage($lang, str_replace('__', '/', substr($fromKey, 3)), str_replace('__', '/', substr($toKey, 3)));
        }

        // 301 from the old address (and its subpages, with or without language prefix) to the new one;
        // the rule goes first so it wins over older migration rules
        $redirectsFile = $contentRoot . '/redirects.json';
        $redirects = is_file($redirectsFile) ? json_decode((string)file_get_contents($redirectsFile), true) : null;
        if (!is_array($redirects)) $redirects = [];
        if (!isset($redirects['redirects']) || !is_array($redirects['redirects'])) $redirects['redirects'] = [];
        // Older rules that pointed at the old address now point at the new one (no redirect chains);
        // targets of earlier moves start with "$1" (the language prefix of the matched request)
        $rulePattern = '~^((?:\\$1)?(?:/[a-z]{2})?)/' . $quoted . '(?=$|[/$"\'#?)\\s])~';
        foreach ($redirects['redirects'] as &$rule) {
            if (!is_string($rule['to'] ?? null)) continue;
            $rule['to'] = preg_replace($rulePattern, '$1/' . $newPath, $rule['to']) ?? $rule['to'];
        }
        unset($rule);
        array_unshift($redirects['redirects'], [
            'from' => '^(/[a-z]{2})?/' . preg_quote($oldPath, '#') . '(/.+?)?/?$',
            'to' => '$1/' . $newPath . '$2',
            'status' => 301,
            'note' => 'Page moved on ' . date('Y-m-d') . ': /' . $oldPath . ' -> /' . $newPath,
        ]);
        nibblyJsonAtomicWrite($redirectsFile, $redirects);

        jsonResponse(true, [
            'page' => $newPrefix,
            'slug' => $newPath,
            'moved' => count($moves),
            'rewritten' => $rewritten,
            'pageList' => buildPageList(),
        ], 'Page moved');
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

        // Page settings → menu checkboxes reach managed menus (Menus & Footer).
        // The dashboard form sends nav_sync=1: its checkboxes show the managed
        // state, so they decide; other savers only pass on a changed "nav".
        if (array_key_exists('nav', $contentData) && ($pageParts = nibblyPageParseContentKey($page)) !== null) {
            require_once NIBBLY_ADMIN_DIR . '/../includes/menu-helpers.php';
            nibblyMenusSyncPageNav($pageParts['lang'], $pageParts['path'],
                $existingData ? getNavLocations($existingData) : [], (array)$contentData['nav'], !empty($_POST['nav_sync']));
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
            if (preg_match('/^' . preg_quote($page, '/') . '_(\d{4}-\d{2}-\d{2})_(\d{6})(?:_[a-f0-9]{6})?\.json$/', $filename, $matches)) {
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

        if (isset($_POST['page']) && $_POST['page'] !== $page) {
            http_response_code(400);
            jsonResponse(false, null, 'Backup does not belong to the requested page');
        }

        // Save current state before restoring
        if (file_exists($filepath)) {
            $timestamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(3));
            $newBackupPath = BACKUP_PATH . $page . '_' . $timestamp . '.json';
            if (!copy($filepath, $newBackupPath)) {
                jsonResponse(false, null, 'Could not create a backup before restoring');
            }
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
