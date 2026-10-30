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
        $comments = [];
        foreach (nibblyCommentsAll() as $comment) {
            unset($comment['ipHash']);
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
