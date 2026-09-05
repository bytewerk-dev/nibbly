<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
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

        if (!nibblyJsonAtomicWrite($mailsFile, $mails)) jsonResponse(false, null, 'Could not save inbox');
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

        if (!nibblyJsonAtomicWrite($mailsFile, $mails)) jsonResponse(false, null, 'Could not save inbox');
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

        if (!nibblyJsonAtomicWrite($mailsFile, $mails)) jsonResponse(false, null, 'Could not save inbox');
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

        if (!nibblyJsonAtomicWrite($mailsFile, $mails)) jsonResponse(false, null, 'Could not save inbox');
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

        if (!nibblyJsonAtomicWrite($mailsFile, $mails)) jsonResponse(false, null, 'Could not save inbox');
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

}
