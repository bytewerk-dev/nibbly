<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

/**
 * Comment moderation (includes/comments.php): list every stored comment, approve, hold back, mark
 * as spam, delete. Editors and admins may moderate; the public endpoint api/comment-submit.php
 * writes new entries.
 */
require_once NIBBLY_ADMIN_DIR . '/../includes/comments.php';

switch ($action) {
    case 'load-comments':
        // Title and public URL of every target once, so the dashboard can group entries by post or page
        require_once NIBBLY_ADMIN_DIR . '/../includes/news-helper.php';
        $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
        $targets = [];
        foreach (nibblyNewsAllPosts() as $post) {
            $targets['news:' . (string)($post['id'] ?? '')] = ['title' => (string)($post['title'] ?? ''), 'url' => nibblyNewsUrl($post, (string)($post['lang'] ?? $defaultLang))];
        }
        $comments = [];
        foreach (nibblyCommentsAll() as $comment) {
            unset($comment['ipHash']);
            $key = (string)($comment['target'] ?? '');
            if (!isset($targets[$key])) {
                $targets[$key] = ['title' => '', 'url' => ''];
                if ($comment['targetType'] === 'page' && ($parsed = nibblyPageParseContentKey((string)$comment['targetId'])) !== null) {
                    $file = NIBBLY_ADMIN_DIR . '/../content/pages/' . $comment['targetId'] . '.json';
                    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
                    if (is_array($data)) {
                        $targets[$key] = ['title' => (string)($data['title'] ?? ''), 'url' => nibblyPageUrlPath($parsed['lang'], $parsed['path'], $defaultLang)];
                    }
                }
            }
            $comment['targetTitle'] = $targets[$key]['title'];
            $comment['targetUrl'] = $targets[$key]['url'];
            $comments[] = $comment;
        }
        jsonResponse(true, ['comments' => $comments, 'pending' => nibblyCommentsCountPending(), 'settings' => nibblyCommentsSettings()]);
        break;

    case 'pending-comment-count':
        jsonResponse(true, ['count' => nibblyCommentsCountPending()]);
        break;

    case 'set-comment-status':
        $type = (string)($_POST['target_type'] ?? '');
        $id = (string)($_POST['target_id'] ?? '');
        $commentId = (string)($_POST['comment_id'] ?? '');
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, ['approved', 'pending', 'spam'], true) || $commentId === '') {
            jsonResponse(false, null, 'Invalid status');
        }
        if (!nibblyCommentsSetStatus($type, $id, $commentId, $status)) {
            jsonResponse(false, null, 'Comment not found');
        }
        jsonResponse(true, ['pending' => nibblyCommentsCountPending()], 'Comment updated');
        break;

    case 'delete-comment':
        $type = (string)($_POST['target_type'] ?? '');
        $id = (string)($_POST['target_id'] ?? '');
        $commentId = (string)($_POST['comment_id'] ?? '');
        if ($commentId === '' || !nibblyCommentsDelete($type, $id, $commentId)) {
            jsonResponse(false, null, 'Comment not found');
        }
        jsonResponse(true, ['pending' => nibblyCommentsCountPending()], 'Comment deleted');
        break;

    default:
        jsonResponse(false, null, 'Unknown action');
}
