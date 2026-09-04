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

$lang = $_POST['lang'] ?? 'de';
$formId = nibblyFormIdClean((string)($_POST['form_id'] ?? ''));
$form = $formId ? nibblyFormLoad($formId) : null;
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
    nibblyFormSubmitResponse(false, $lang === 'de' ? 'Formular nicht gefunden.' : 'Form not found.', [], 404);
}

if (!nibblyFormReserveRequest($clientKey)) {
    nibblyFormSubmitResponse(false, $lang === 'de' ? 'Zu viele Versuche. Bitte versuchen Sie es später erneut.' : 'Too many attempts. Please try again later.', [], 429);
}

if (!empty($_POST['website'])) {
    nibblyFormRecordAttempt($clientKey, false);
    nibblyFormSubmitResponse(true, (string)($form['submit']['successText'] ?? 'Vielen Dank.'));
}

$tokenResult = nibblyValidateFormToken($_POST['form_token'] ?? null, $form['id']);
if (empty($tokenResult['valid'])) {
    nibblyFormRecordAttempt($clientKey, false);
    nibblyFormSubmitResponse(false, $lang === 'de'
        ? 'Das Formular ist abgelaufen oder wurde zu schnell gesendet. Bitte versuchen Sie es erneut.'
        : 'The form expired or was submitted too quickly. Please try again.', [
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
    nibblyFormSubmitResponse(false, $lang === 'de'
        ? 'Die Nachricht konnte nicht gesendet werden. Bitte prüfen Sie Ihre Eingaben.'
        : 'The message could not be sent. Please check your input.', [
            'formToken' => nibblyCreateFormToken($form['id']),
        ]);
}

$subject = nibblyFormsApplySubjectTemplate((string)($form['submit']['subject'] ?? ''), $form, $values);
$body = nibblyFormsBuildBody($form, $values);
$sent = false;
if (!empty($form['submit']['email'])) {
    $sent = nibblyFormsSendNotification($form, $values, $subject, $body);
}

$store = !array_key_exists('store', $form['submit']) || !empty($form['submit']['store']);
$saved = true;
if ($store) {
    $fieldRows = [];
    foreach ($form['fields'] as $field) {
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
        'formLabel' => $form['label'],
        'name' => $values['name'] ?? ($values['email'] ?? $form['label']),
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
    nibblyFormSubmitResponse(false, $lang === 'de'
        ? 'Die Nachricht konnte nicht gespeichert werden. Bitte versuchen Sie es später erneut.'
        : 'The message could not be saved. Please try again later.', [
            'formToken' => nibblyCreateFormToken($form['id']),
        ], 500);
}

// A successful response requires at least one actual delivery channel.
if (!$store && !$sent) {
    nibblyFormRecordAttempt($clientKey, false);
    nibblyFormSubmitResponse(false, $lang === 'de'
        ? 'Die Nachricht konnte nicht gesendet werden. Bitte versuchen Sie es später erneut.'
        : 'The message could not be sent. Please try again later.', [
            'formToken' => nibblyCreateFormToken($form['id']),
        ], 503);
}

nibblyFormRecordAttempt($clientKey, true);
nibblyFormSubmitResponse(true, (string)($form['submit']['successText'] ?? 'Vielen Dank.'), [
    'formToken' => nibblyCreateFormToken($form['id']),
]);
