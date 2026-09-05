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

}
