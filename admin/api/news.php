<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/includes/news-helper.php';
require_once dirname(__DIR__, 2) . '/includes/search-helper.php';

// The search index follows every change of a post (the response is sent first; the rebuild runs at shutdown)
if (in_array($action, ['save-news', 'toggle-news-status', 'trash-news', 'restore-news', 'delete-news-permanent', 'save-news-categories'], true)) {
    register_shutdown_function(static function (): void { nibblySearchIndexRebuild(); });
}

// Language default, slug lists and public URL as the dashboard expects them
function nibblyNewsApiNormalize(array $post): array {
    if (empty($post['lang'])) {
        $post['lang'] = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    }
    $post['categories'] = nibblyNewsSlugList($post['categories'] ?? []);
    $post['tags'] = nibblyNewsSlugList($post['tags'] ?? []);
    $post['flags'] = nibblyNewsSlugList($post['flags'] ?? []);
    $post['url'] = nibblyNewsUrl($post);
    return $post;
}

// Post backups: like page backups (pages.php) a copy of the previous state lands in BACKUP_PATH before
// every write, named news__{id}_{Y-m-d}_{His}_{6 hex}.json; the newest MAX_BACKUPS per post are kept.
function nibblyNewsBackupPattern(): string {
    return '/^news__([a-z0-9]+(?:-[a-z0-9]+)*)_(\d{4}-\d{2}-\d{2})_(\d{6})_[a-f0-9]{6}\.json$/D';
}

function nibblyNewsBackupCreate(string $filepath, string $postId): void {
    if (!is_file($filepath)) return;
    if (!is_dir(BACKUP_PATH)) @mkdir(BACKUP_PATH, 0755, true);
    $backupPath = BACKUP_PATH . 'news__' . $postId . '_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '.json';
    if (!copy($filepath, $backupPath)) {
        jsonResponse(false, null, 'Could not create a backup before saving');
    }
    $backups = glob(BACKUP_PATH . 'news__' . $postId . '_*.json') ?: [];
    usort($backups, static fn($a, $b) => filemtime($b) - filemtime($a));
    while (count($backups) > MAX_BACKUPS) {
        @unlink(array_pop($backups));
    }
}

// List row for the dashboard: what the table, filters and language columns need, excerpt shortened
function nibblyNewsApiListItem(array $post): array {
    $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($post['excerpt'] ?? ''))) ?? '');
    if (mb_strlen($excerpt) > 160) {
        $excerpt = rtrim(mb_substr($excerpt, 0, 159)) . '…';
    }
    $item = [];
    foreach (['id', 'slug', 'lang', 'title', 'subtitle', 'date', 'author', 'image', 'categories', 'tags', 'flags', 'url', 'wpId', 'lastModified'] as $key) {
        if (array_key_exists($key, $post)) $item[$key] = $post[$key];
    }
    $item['excerpt'] = $excerpt;
    $item['hidden'] = !empty($post['hidden']);
    return $item;
}

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    // URL pattern, archive paths, categories and tag names for the editor and the settings panel
    case 'load-news-config':
        jsonResponse(true, [
            'settings' => nibblyNewsSettings(true),
            'defaults' => nibblyNewsSettingsDefaults(),
            'categories' => nibblyNewsCategories(true),
            'tagNames' => nibblyNewsTagNames(true),
            'tagCounts' => nibblyNewsTagCounts(),
        ]);
        break;

    case 'save-news-categories':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $rows = json_decode((string)($_POST['categories'] ?? ''), true);
        if (!is_array($rows)) {
            jsonResponse(false, null, 'Invalid JSON format');
        }
        $saved = nibblyNewsSaveCategories($rows);
        if ($saved === false) {
            jsonResponse(false, null, 'Error saving categories');
        }
        jsonResponse(true, $saved, 'Categories saved');
        break;

    // The list carries metadata only (no content, gallery or SEO): with hundreds of posts the full records
    // run to several megabytes. The editor loads one post with load-news-post.
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
            $post = nibblyNewsApiNormalize($post);

            // Filter by language if requested
            if ($filterLang && $post['lang'] !== $filterLang) continue;

            $posts[] = nibblyNewsApiListItem($post);
        }

        // Sort by date descending
        usort($posts, function($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });

        jsonResponse(true, $posts);
        break;

    // One complete post for the editor (also the source of a translation or copy)
    case 'load-news-post':
        $postId = $_GET['id'] ?? '';
        if (!is_string($postId) || !preg_match('/^[a-z0-9][a-z0-9-]*$/D', $postId)) {
            jsonResponse(false, null, 'Invalid post ID');
        }
        $filepath = dirname(CONTENT_PATH) . '/news/' . $postId . '.json';
        $post = is_file($filepath) ? json_decode((string)file_get_contents($filepath), true) : null;
        if (!is_array($post)) {
            http_response_code(404);
            jsonResponse(false, null, 'Post not found');
        }
        jsonResponse(true, nibblyNewsApiNormalize($post));
        break;

    case 'save-news':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $postJson = $_POST['post'] ?? '';
        $post = json_decode($postJson, true);
        if (!is_array($post)) {
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
        if (!is_string($oldId) || ($oldId !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $oldId))) {
            jsonResponse(false, null, 'Invalid news ID');
        }
        $newsDir = dirname(CONTENT_PATH) . '/news/';
        if (!is_dir($newsDir)) {
            mkdir($newsDir, 0755, true);
        }

        $filepath = $newsDir . $postId . '.json';
        if ($oldId !== $postId && is_file($filepath)) {
            jsonResponse(false, null, 'A news post with this date and slug already exists.');
        }

        // Sanitize content
        $sanitized = [
            'id' => $postId,
            'lang' => $lang,
            'title' => $title,
            'subtitle' => trim(strip_tags((string)($post['subtitle'] ?? ''))),
            'slug' => $slug,
            'date' => $date,
            'author' => trim($post['author'] ?? ''),
            'excerpt' => trim($post['excerpt'] ?? ''),
            'image' => trim($post['image'] ?? ''),
            // Title image inside the post: column (image column), full (full width), hidden (previews only)
            'imageLayout' => in_array($post['imageLayout'] ?? '', ['full', 'hidden'], true) ? $post['imageLayout'] : 'column',
            'content' => sanitizeHtml((string)($post['content'] ?? '')),
            // Categories are slugs of content/news-categories.json; tags are free (typed names become
            // slugs, their spelling is kept in content/news-tags.json); flags mark sub-lists (e.g. "column")
            'categories' => nibblyNewsSlugList($post['categories'] ?? []),
            'tags' => nibblyNewsRememberTagNames($post['tags'] ?? []),
            'flags' => nibblyNewsSlugList($post['flags'] ?? [], 20),
            'hidden' => !empty($post['hidden']),
            'lastModified' => date('c'),
        ];
        // Cover image (shown in the sidebar, e.g. the issue of a magazine) and the sidebar switch
        $cover = is_array($post['cover'] ?? null) ? $post['cover'] : [];
        $coverSrc = trim((string)($cover['src'] ?? ''));
        if ($coverSrc !== '' && preg_match('#^/?assets/[^\s"<>]+$#', $coverSrc)) {
            $sanitized['cover'] = [
                'src' => '/' . ltrim($coverSrc, '/'),
                'alt' => trim(strip_tags((string)($cover['alt'] ?? ''))),
                'label' => trim(strip_tags((string)($cover['label'] ?? ''))),
            ];
        }
        if (!empty($post['sidebar'])) {
            $sanitized['sidebar'] = true;
        }
        // Comments are open by default; "comments": false closes them for this post
        if (array_key_exists('comments', $post) && ($post['comments'] === false || $post['comments'] === 'false' || $post['comments'] === 0 || $post['comments'] === '0')) {
            $sanitized['comments'] = false;
        }
        // Block lists: "sections" is the body of a post built from blocks (it replaces the "content"
        // string, in its place), "aside" the sidebar below cover and excerpt
        if (is_array($post['sections'] ?? null)) {
            $ordered = [];
            foreach ($sanitized as $key => $value) {
                if ($key === 'content') $ordered['sections'] = nibblyNewsSanitizeBlocks($post['sections']);
                else $ordered[$key] = $value;
            }
            $sanitized = $ordered;
        }
        $aside = nibblyNewsSanitizeBlocks($post['aside'] ?? null);
        if ($aside) {
            $sanitized['aside'] = $aside;
        }
        // Fields this editor does not know (e.g. "seo" from an import) survive a save
        foreach ($post as $key => $value) {
            if (!array_key_exists($key, $sanitized) && !in_array($key, ['url', '_file', 'cover', 'sidebar', 'comments', 'sections', 'aside', 'content'], true) && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,40}$/', (string)$key)) {
                $sanitized[$key] = $value;
            }
        }

        $filepath = $newsDir . $postId . '.json';
        // previous state of the post (under its old id when the id changes) → backups/
        nibblyNewsBackupCreate($newsDir . ($oldId !== '' ? $oldId : $postId) . '.json', $postId);
        $result = nibblyJsonAtomicWrite($filepath, $sanitized);

        if ($result === false) {
            jsonResponse(false, null, 'Error saving post');
        }

        if ($oldId !== '' && $oldId !== $postId) {
            @unlink($newsDir . $oldId . '.json');
        }

        $sanitized['url'] = nibblyNewsUrl($sanitized);
        jsonResponse(true, $sanitized, 'Post saved');
        break;

    case 'list-news-backups':
        $postId = $_GET['id'] ?? '';
        if (!is_string($postId) || !preg_match('/^[a-z0-9][a-z0-9-]*$/D', $postId)) {
            jsonResponse(false, null, 'Invalid post ID');
        }
        $list = [];
        foreach (glob(BACKUP_PATH . 'news__' . $postId . '_*.json') ?: [] as $file) {
            $name = basename($file);
            if (!preg_match(nibblyNewsBackupPattern(), $name, $m) || $m[1] !== $postId) continue;
            $list[] = [
                'filename' => $name,
                'date' => $m[2],
                'time' => substr($m[3], 0, 2) . ':' . substr($m[3], 2, 2) . ':' . substr($m[3], 4, 2),
                'timestamp' => filemtime($file),
            ];
        }
        usort($list, static fn($a, $b) => $b['timestamp'] - $a['timestamp']);
        jsonResponse(true, $list);
        break;

    case 'load-news-backup':
        $backup = $_GET['backup'] ?? '';
        if (!is_string($backup) || !preg_match(nibblyNewsBackupPattern(), $backup) || !is_file(BACKUP_PATH . $backup)) {
            jsonResponse(false, null, 'Backup not found');
        }
        $data = json_decode((string)file_get_contents(BACKUP_PATH . $backup), true);
        if (!is_array($data)) {
            jsonResponse(false, null, 'Backup is not valid JSON');
        }
        jsonResponse(true, $data);
        break;

    case 'restore-news-backup':
        $backup = $_POST['backup'] ?? '';
        if (!is_string($backup) || !preg_match(nibblyNewsBackupPattern(), $backup, $m) || !is_file(BACKUP_PATH . $backup)) {
            jsonResponse(false, null, 'Backup not found');
        }
        $postId = $m[1];
        if (isset($_POST['post_id']) && $_POST['post_id'] !== $postId) {
            http_response_code(400);
            jsonResponse(false, null, 'Backup does not belong to the requested post');
        }
        $restored = json_decode((string)file_get_contents(BACKUP_PATH . $backup), true);
        if (!is_array($restored)) {
            jsonResponse(false, null, 'Backup is not valid JSON');
        }
        $newsDir = dirname(CONTENT_PATH) . '/news/';
        $filepath = $newsDir . $postId . '.json';
        // the current state is saved first, so the restore itself can be undone
        nibblyNewsBackupCreate($filepath, $postId);
        $restored['id'] = $postId;
        $restored['lastModified'] = date('c');
        if (!nibblyJsonAtomicWrite($filepath, $restored)) {
            jsonResponse(false, null, 'Error restoring');
        }
        register_shutdown_function(static function (): void { nibblySearchIndexRebuild(); });
        jsonResponse(true, nibblyNewsApiNormalize($restored), 'Backup restored successfully');
        break;

    case 'delete-news-backup':
        $backup = $_POST['backup'] ?? '';
        if (!is_string($backup) || !preg_match(nibblyNewsBackupPattern(), $backup) || !is_file(BACKUP_PATH . $backup)) {
            jsonResponse(false, null, 'Backup not found');
        }
        if (!unlink(BACKUP_PATH . $backup)) {
            jsonResponse(false, null, 'Error deleting');
        }
        jsonResponse(true, null, 'Backup deleted');
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

    // Trash: deleted posts move to content/news-trash/{id}_{date}_{time}.json, like pages
    case 'trash-news':
        $postId = $_POST['post_id'] ?? '';
        if (!is_string($postId) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $postId)) {
            jsonResponse(false, null, 'Invalid post ID');
        }
        $filepath = dirname(CONTENT_PATH) . '/news/' . $postId . '.json';
        $trashDir = dirname(CONTENT_PATH) . '/news-trash/';
        if (!is_file($filepath)) {
            jsonResponse(false, null, 'Post not found');
        }
        if (!is_dir($trashDir) && !@mkdir($trashDir, 0755, true)) {
            jsonResponse(false, null, 'Error moving post to trash');
        }
        $trashFile = $trashDir . $postId . '_' . date('Y-m-d_His') . '.json';
        if (!rename($filepath, $trashFile)) {
            jsonResponse(false, null, 'Error moving post to trash');
        }
        @touch($trashFile);
        jsonResponse(true, null, 'Post moved to trash');
        break;

    case 'list-news-trash':
        $items = [];
        foreach (glob(dirname(CONTENT_PATH) . '/news-trash/*.json') ?: [] as $file) {
            if (!preg_match('/^([a-z0-9][a-z0-9-]*)_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', basename($file), $m)) continue;
            $post = json_decode((string)file_get_contents($file), true);
            $items[] = [
                'filename' => basename($file),
                'id' => $m[1],
                'title' => is_array($post) ? (string)($post['title'] ?? $m[1]) : $m[1],
                'lang' => is_array($post) ? (string)($post['lang'] ?? '') : '',
                'date' => is_array($post) ? (string)($post['date'] ?? '') : '',
                'deletedAt' => date('c', (int)filemtime($file)),
            ];
        }
        usort($items, fn($a, $b) => strcmp($b['deletedAt'], $a['deletedAt']));
        jsonResponse(true, $items);
        break;

    case 'restore-news':
    case 'delete-news-permanent':
        $filename = $_POST['filename'] ?? '';
        if (!is_string($filename) || !preg_match('/^([a-z0-9][a-z0-9-]*)_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', $filename, $m)) {
            jsonResponse(false, null, 'Invalid file');
        }
        $trashFile = dirname(CONTENT_PATH) . '/news-trash/' . $filename;
        if (!is_file($trashFile)) {
            jsonResponse(false, null, 'Post not found in trash');
        }
        if ($action === 'delete-news-permanent') {
            if (!unlink($trashFile)) jsonResponse(false, null, 'Error deleting post');
            jsonResponse(true, null, 'Post permanently deleted');
        }
        $target = dirname(CONTENT_PATH) . '/news/' . $m[1] . '.json';
        if (is_file($target)) {
            http_response_code(409);
            jsonResponse(false, null, t('news.restore_conflict'));
        }
        if (!rename($trashFile, $target)) {
            jsonResponse(false, null, 'Error restoring post');
        }
        jsonResponse(true, ['id' => $m[1]], 'Post restored');
        break;

    case 'empty-news-trash':
        foreach (glob(dirname(CONTENT_PATH) . '/news-trash/*.json') ?: [] as $file) {
            if (!unlink($file)) jsonResponse(false, null, 'Error emptying trash');
        }
        jsonResponse(true, null, 'News trash emptied');
        break;

    // ============================================================
    // MAIL MANAGEMENT
    // ============================================================

}
