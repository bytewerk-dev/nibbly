<?php
/**
 * Generic JSON-backed form submission endpoint.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/forms.php';

$lang = (string)($_POST['lang'] ?? '');
if (!preg_match('/^[a-z]{2}$/', $lang)) {
    $lang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'de';
}
$formId = nibblyFormIdClean((string)($_POST['form_id'] ?? ''));
$formStored = $formId ? nibblyFormLoad($formId) : null;
// The visitor gets texts and messages in the page language; the stored message and the
// notification e-mail use the primary language, so the inbox reads the same for every form
$form = $formStored ? nibblyFormLocalize($formStored, $lang) : null;
$formAdmin = $formStored ? nibblyFormLocalize($formStored) : null;
$clientKey = nibblyFormClientKey();

function nibblyFormSubmitResponse(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$form || empty($form['enabled'])) {
    nibblyFormSubmitResponse(false, nibblyPublicString('form_not_found', $lang), [], 404);
}

if (!nibblyFormReserveRequest($clientKey)) {
    nibblyFormSubmitResponse(false, nibblyPublicString('form_too_many', $lang), [], 429);
}

if (!empty($_POST['website'])) {
    nibblyFormRecordAttempt($clientKey, false);
    nibblyFormSubmitResponse(true, (string)($form['submit']['successText'] ?? 'Vielen Dank.'));
}

$tokenResult = nibblyValidateFormToken($_POST['form_token'] ?? null, $form['id']);
if (empty($tokenResult['valid'])) {
    nibblyFormRecordAttempt($clientKey, false);
    nibblyFormSubmitResponse(false, nibblyPublicString('form_expired', $lang), [
            'formToken' => nibblyCreateFormToken($form['id']),
        ], 400);
}

[$errors, $values] = nibblyFormValidateSubmission($form, (string)$lang);
if ($errors) {
    nibblyFormRecordAttempt($clientKey, false);
    nibblyFormSubmitResponse(false, implode(', ', $errors), [
        'formToken' => nibblyCreateFormToken($form['id']),
    ]);
}

if (nibblyFormLooksLikeSpam($values)) {
    nibblyFormRecordAttempt($clientKey, false);
    nibblyFormSubmitResponse(false, nibblyPublicString('form_check_input', $lang), [
            'formToken' => nibblyCreateFormToken($form['id']),
        ]);
}

$subject = nibblyFormsApplySubjectTemplate((string)($formAdmin['submit']['subject'] ?? ''), $formAdmin, $values);
$body = nibblyFormsBuildBody($formAdmin, $values);
$sent = false;
if (!empty($form['submit']['email'])) {
    $sent = nibblyFormsSendNotification($formAdmin, $values, $subject, $body);
}

$store = !array_key_exists('store', $form['submit']) || !empty($form['submit']['store']);
$saved = true;
if ($store) {
    $fieldRows = [];
    foreach ($formAdmin['fields'] as $field) {
        if (in_array(($field['type'] ?? ''), ['heading', 'note', 'hidden'], true)) {
            continue;
        }
        $fieldRows[] = [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'value' => $values[$field['key']] ?? '',
        ];
    }
    $saved = nibblyFormsSaveSubmission([
        'id' => uniqid('mail_', true),
        'formId' => $form['id'],
        'formLabel' => $formAdmin['label'],
        'name' => $values['name'] ?? ($values['email'] ?? $formAdmin['label']),
        'email' => $values['email'] ?? '',
        'phone' => $values['phone'] ?? '',
        'occasion' => $subject,
        'message' => $values['message'] ?? '',
        'fields' => $fieldRows,
        'timestamp' => date('c'),
        'status' => $sent ? 'sent' : (!empty($form['submit']['email']) ? 'send_failed' : 'local_only'),
        'read' => false,
        'starred' => false,
    ]);
}

if (!$saved) {
    nibblyFormRecordAttempt($clientKey, false);
    nibblyFormSubmitResponse(false, nibblyPublicString('form_save_failed', $lang), [
            'formToken' => nibblyCreateFormToken($form['id']),
        ], 500);
}

// A successful response requires at least one actual delivery channel.
if (!$store && !$sent) {
    nibblyFormRecordAttempt($clientKey, false);
    nibblyFormSubmitResponse(false, nibblyPublicString('form_send_failed', $lang), [
            'formToken' => nibblyCreateFormToken($form['id']),
        ], 503);
}

nibblyFormRecordAttempt($clientKey, true);
nibblyFormSubmitResponse(true, (string)($form['submit']['successText'] ?? 'Vielen Dank.'), [
    'formToken' => nibblyCreateFormToken($form['id']),
]);
