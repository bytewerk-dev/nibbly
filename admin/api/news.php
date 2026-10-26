<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
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
            'slug' => $slug,
            'date' => $date,
            'author' => trim($post['author'] ?? ''),
            'excerpt' => trim($post['excerpt'] ?? ''),
            'image' => trim($post['image'] ?? ''),
            'content' => sanitizeHtml((string)($post['content'] ?? '')),
            'hidden' => !empty($post['hidden']),
            'lastModified' => date('c'),
        ];

        $filepath = $newsDir . $postId . '.json';
        $result = nibblyJsonAtomicWrite($filepath, $sanitized);

        if ($result === false) {
            jsonResponse(false, null, 'Error saving post');
        }

        if ($oldId !== '' && $oldId !== $postId) {
            @unlink($newsDir . $oldId . '.json');
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
