<?php
/**
 * JSON-backed public forms for nibbly.
 *
 * Form definitions live in content/forms/*.json. The renderer intentionally
 * stays small: generated/vibecoded templates can define the structure, while
 * admins can safely edit labels, placeholders, options, required state and
 * column widths.
 */

require_once __DIR__ . '/form-protection.php';

if (!defined('NIBBLY_FORMS_DIR')) {
    define('NIBBLY_FORMS_DIR', dirname(__DIR__) . '/content/forms');
}
if (!defined('NIBBLY_MAILS_PATH')) {
    define('NIBBLY_MAILS_PATH', dirname(__DIR__) . '/content/mails.json');
}

function nibblyFormIdClean(string $id): string
{
    $id = strtolower(trim($id));
    $id = preg_replace('/[^a-z0-9_-]+/', '-', $id);
    $id = trim((string)$id, '-_');
    return $id !== '' ? substr($id, 0, 80) : 'form';
}

function nibblyFormsEnsureDir(): void
{
    if (!is_dir(NIBBLY_FORMS_DIR)) {
        mkdir(NIBBLY_FORMS_DIR, 0755, true);
    }
}

function nibblyFormPath(string $id): string
{
    return NIBBLY_FORMS_DIR . '/' . nibblyFormIdClean($id) . '.json';
}

function nibblyFormsReadJson(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function nibblyFormsWriteJson(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function nibblyFormDefaultDefinition(string $id = 'contact'): array
{
    $id = nibblyFormIdClean($id);
    return [
        'id' => $id,
        'label' => $id === 'contact' ? 'Kontaktformular' : ucfirst(str_replace('-', ' ', $id)),
        'description' => '',
        'enabled' => true,
        'submit' => [
            'store' => true,
            'email' => true,
            'subject' => '{form}: {name}',
            'successText' => 'Vielen Dank für deine Nachricht.',
        ],
        'fields' => [
            ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'width' => 6],
            ['key' => 'email', 'type' => 'email', 'label' => 'E-Mail', 'required' => true, 'width' => 6],
            ['key' => 'phone', 'type' => 'tel', 'label' => 'Telefon', 'required' => false, 'width' => 12],
            ['key' => 'message', 'type' => 'textarea', 'label' => 'Nachricht', 'required' => true, 'width' => 12],
        ],
    ];
}

function nibblyFormNormalizeField(array $field, int $index = 0): array
{
    $allowedTypes = ['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox', 'date', 'time', 'hidden', 'heading', 'note'];
    $key = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($field['key'] ?? 'field_' . ($index + 1)));
    $key = trim((string)$key, '_-') ?: 'field_' . ($index + 1);
    $type = in_array(($field['type'] ?? 'text'), $allowedTypes, true) ? $field['type'] : 'text';
    $width = (int)($field['width'] ?? 12);
    if (!in_array($width, [3, 4, 6, 8, 12], true)) {
        $width = 12;
    }
    $options = [];
    if (is_array($field['options'] ?? null)) {
        foreach ($field['options'] as $option) {
            if (is_array($option)) {
                $value = (string)($option['value'] ?? $option['label'] ?? '');
                $label = (string)($option['label'] ?? $value);
            } else {
                $value = (string)$option;
                $label = (string)$option;
            }
            if ($value !== '' || $label !== '') {
                $options[] = ['value' => $value, 'label' => $label];
            }
        }
    }

    return [
        'key' => $key,
        'type' => $type,
        'label' => (string)($field['label'] ?? $key),
        'placeholder' => (string)($field['placeholder'] ?? ''),
        'help' => (string)($field['help'] ?? ''),
        'required' => !empty($field['required']),
        'width' => $width,
        'options' => $options,
        'value' => (string)($field['value'] ?? ''),
    ];
}

function nibblyFormNormalize(array $form, string $fallbackId = 'form'): array
{
    $id = nibblyFormIdClean((string)($form['id'] ?? $fallbackId));
    $normalized = [
        'id' => $id,
        'label' => trim((string)($form['label'] ?? '')) ?: ucfirst(str_replace('-', ' ', $id)),
        'description' => (string)($form['description'] ?? ''),
        'enabled' => !array_key_exists('enabled', $form) || !empty($form['enabled']),
        'submit' => array_replace([
            'store' => true,
            'email' => true,
            'subject' => '{form}: {name}',
            'successText' => 'Vielen Dank für deine Nachricht.',
        ], is_array($form['submit'] ?? null) ? $form['submit'] : []),
        'fields' => [],
    ];
    foreach (($form['fields'] ?? []) as $index => $field) {
        if (is_array($field)) {
            $normalized['fields'][] = nibblyFormNormalizeField($field, (int)$index);
        }
    }
    if (!$normalized['fields']) {
        $normalized['fields'] = nibblyFormDefaultDefinition($id)['fields'];
    }
    return $normalized;
}

function nibblyFormExists(string $id): bool
{
    return is_file(nibblyFormPath($id));
}

function nibblyFormLoad(string $id): ?array
{
    $path = nibblyFormPath($id);
    if (!is_file($path)) {
        return null;
    }
    return nibblyFormNormalize(nibblyFormsReadJson($path), $id);
}

function nibblyFormSave(array $form): array
{
    $normalized = nibblyFormNormalize($form, (string)($form['id'] ?? 'form'));
    nibblyFormsEnsureDir();
    nibblyFormsWriteJson(nibblyFormPath($normalized['id']), $normalized);
    return $normalized;
}

function nibblyFormsList(): array
{
    nibblyFormsEnsureDir();
    $forms = [];
    foreach (glob(NIBBLY_FORMS_DIR . '/*.json') ?: [] as $file) {
        $form = nibblyFormNormalize(nibblyFormsReadJson($file), pathinfo($file, PATHINFO_FILENAME));
        $forms[] = [
            'id' => $form['id'],
            'label' => $form['label'],
            'description' => $form['description'],
            'enabled' => $form['enabled'],
            'fieldCount' => count($form['fields']),
        ];
    }
    usort($forms, fn($a, $b) => strcasecmp((string)$a['label'], (string)$b['label']));
    return $forms;
}

function nibblyFormEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function nibblyFormRenderField(array $field, string $formId): string
{
    $key = (string)$field['key'];
    $id = 'nibbly-form-' . $formId . '-' . $key;
    $label = nibblyFormEscape((string)$field['label']);
    $required = !empty($field['required']);
    $requiredAttr = $required ? ' required' : '';
    $requiredMark = $required ? ' *' : '';
    $placeholder = nibblyFormEscape((string)($field['placeholder'] ?? ''));
    $width = (int)($field['width'] ?? 12);
    $type = (string)($field['type'] ?? 'text');
    $help = trim((string)($field['help'] ?? ''));

    if ($type === 'hidden') {
        return '<input type="hidden" name="' . nibblyFormEscape($key) . '" value="' . nibblyFormEscape((string)($field['value'] ?? '')) . '">';
    }
    if ($type === 'heading') {
        return '<div class="form-group form-group--span-' . $width . ' nibbly-form-heading"><h3>' . $label . '</h3></div>';
    }
    if ($type === 'note') {
        return '<div class="form-group form-group--span-' . $width . ' nibbly-form-note"><p>' . nibblyFormEscape((string)($field['help'] ?: $field['label'])) . '</p></div>';
    }

    $html = '<div class="form-group form-group--span-' . $width . '">';
    if ($type !== 'checkbox') {
        $html .= '<label for="' . nibblyFormEscape($id) . '">' . $label . $requiredMark . '</label>';
    }

    if ($type === 'textarea') {
        $html .= '<textarea id="' . nibblyFormEscape($id) . '" name="' . nibblyFormEscape($key) . '" rows="5" placeholder="' . $placeholder . '"' . $requiredAttr . '></textarea>';
    } elseif ($type === 'select') {
        $html .= '<select id="' . nibblyFormEscape($id) . '" name="' . nibblyFormEscape($key) . '"' . $requiredAttr . '>';
        $html .= '<option value=""></option>';
        foreach (($field['options'] ?? []) as $option) {
            $html .= '<option value="' . nibblyFormEscape((string)$option['value']) . '">' . nibblyFormEscape((string)$option['label']) . '</option>';
        }
        $html .= '</select>';
    } elseif ($type === 'radio') {
        $html .= '<div class="nibbly-form-choice-list">';
        foreach (($field['options'] ?? []) as $option) {
            $html .= '<label><input type="radio" name="' . nibblyFormEscape($key) . '" value="' . nibblyFormEscape((string)$option['value']) . '"' . $requiredAttr . '> <span>' . nibblyFormEscape((string)$option['label']) . '</span></label>';
        }
        $html .= '</div>';
    } elseif ($type === 'checkbox') {
        $html .= '<label class="nibbly-form-checkbox"><input type="checkbox" id="' . nibblyFormEscape($id) . '" name="' . nibblyFormEscape($key) . '" value="1"' . $requiredAttr . '> <span>' . $label . $requiredMark . '</span></label>';
    } else {
        $inputType = in_array($type, ['email', 'tel', 'date', 'time'], true) ? $type : 'text';
        $html .= '<input type="' . $inputType . '" id="' . nibblyFormEscape($id) . '" name="' . nibblyFormEscape($key) . '" placeholder="' . $placeholder . '"' . $requiredAttr . '>';
    }
    if ($help !== '') {
        $html .= '<small class="form-hint">' . nibblyFormEscape($help) . '</small>';
    }
    $html .= '</div>';
    return $html;
}

function nibblyFormRender(string $formId, array $options = []): string
{
    $form = nibblyFormLoad($formId);
    if (!$form || empty($form['enabled'])) {
        return '';
    }
    $basePath = nibblySafeFormBasePath((string)($options['basePath'] ?? ($GLOBALS['basePath'] ?? '')));
    $lang = (string)($options['lang'] ?? ($GLOBALS['currentLang'] ?? 'en'));
    $lazy = array_key_exists('lazy', $options) ? !empty($options['lazy']) : true;

    if ($lazy && !nibblyIsLazyFormRender()) {
        return nibblyLazyFormPlaceholder($form['id'], [
            'basePath' => $basePath,
            'params' => [
                'lang' => $lang,
                'basePath' => $basePath,
            ],
        ]);
    }

    $html = '<section class="contact-form-section nibbly-json-form-section" data-form-id="' . nibblyFormEscape($form['id']) . '">';
    $html .= '<h2>' . nibblyFormEscape((string)$form['label']) . '</h2>';
    if (!empty($form['description'])) {
        $html .= '<p class="nibbly-form-description">' . nibblyFormEscape((string)$form['description']) . '</p>';
    }
    $html .= '<form id="nibblyForm-' . nibblyFormEscape($form['id']) . '" class="contact-form nibbly-json-form" action="' . nibblyFormEscape($basePath . 'api/form-submit.php') . '" method="post">';
    $html .= '<input type="hidden" name="lang" value="' . nibblyFormEscape($lang) . '">';
    $html .= nibblyFormProtectionFields($form['id']);
    $html .= '<div class="nibbly-form-grid">';
    foreach ($form['fields'] as $field) {
        $html .= nibblyFormRenderField($field, $form['id']);
    }
    $html .= '</div>';
    $send = (string)($options['submitLabel'] ?? ($lang === 'de' ? 'Absenden' : 'Send'));
    $sending = (string)($options['sendingLabel'] ?? ($lang === 'de' ? 'Wird gesendet…' : 'Sending…'));
    $success = (string)($form['submit']['successText'] ?? 'Vielen Dank.');
    $html .= '<div class="form-actions"><button type="submit" class="btn btn-gradient"><span class="btn-text">' . nibblyFormEscape($send) . '</span><span class="btn-loading" style="display:none">' . nibblyFormEscape($sending) . '</span></button></div>';
    $error = $lang === 'de' ? 'Fehler beim Senden. Bitte versuch es später noch mal.' : 'Could not send the form. Please try again later.';
    $html .= '<div class="form-feedback" data-success="' . nibblyFormEscape($success) . '" data-error="' . nibblyFormEscape($error) . '"></div>';
    $html .= '</form></section>';
    return $html;
}

function nibblyForm(string $formId, array $options = []): string
{
    return nibblyFormRender($formId, $options);
}

function nibblyFormSubmittedValue(array $field): string
{
    $key = (string)$field['key'];
    if (($field['type'] ?? '') === 'checkbox') {
        return !empty($_POST[$key]) ? '1' : '';
    }
    $value = $_POST[$key] ?? '';
    if (is_array($value)) {
        $value = implode(', ', array_map('strval', $value));
    }
    return trim((string)$value);
}

function nibblyFormValidateSubmission(array $form, string $lang = 'en'): array
{
    $errors = [];
    $values = [];
    foreach ($form['fields'] as $field) {
        $type = (string)($field['type'] ?? 'text');
        if (in_array($type, ['heading', 'note', 'hidden'], true)) {
            continue;
        }
        $value = nibblyFormSubmittedValue($field);
        $values[$field['key']] = $value;
        if (!empty($field['required']) && $value === '') {
            $errors[] = ($field['label'] ?? $field['key']) . ($lang === 'de' ? ' ist erforderlich' : ' is required');
            continue;
        }
        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $lang === 'de' ? 'Gültige E-Mail ist erforderlich' : 'Valid email is required';
        }
    }
    return [$errors, $values];
}

function nibblyFormsApplySubjectTemplate(string $template, array $form, array $values): string
{
    $subject = $template ?: '{form}';
    $replacements = [
        '{form}' => (string)$form['label'],
        '{name}' => (string)($values['name'] ?? ''),
        '{email}' => (string)($values['email'] ?? ''),
    ];
    return trim(strtr($subject, $replacements)) ?: (string)$form['label'];
}

function nibblyFormsSaveSubmission(array $submission): bool
{
    return nibblyJsonUpdate(NIBBLY_MAILS_PATH, function (array &$mails) use ($submission): void {
        array_unshift($mails, $submission);
        $mails = array_slice($mails, 0, 500);
    });
}

function nibblyFormsLoadEmailSettings(): array
{
    $settingsPath = dirname(__DIR__) . '/content/settings.json';
    $settings = nibblyFormsReadJson($settingsPath);
    return is_array($settings['email'] ?? null) ? $settings['email'] : [];
}

function nibblyFormsParseEmailList($value): array
{
    $items = is_array($value) ? $value : explode(',', (string)$value);
    $emails = [];
    foreach ($items as $item) {
        foreach (explode(',', (string)$item) as $email) {
            $email = trim(str_replace(["\r", "\n", "\t"], '', (string)$email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
    }
    return array_values(array_unique($emails));
}

function nibblyFormsSendNotification(array $form, array $values, string $subject, string $body): bool
{
    $emailConfig = nibblyFormsLoadEmailSettings();
    $method = $emailConfig['method'] ?? 'inactive';
    $toRecipients = nibblyFormsParseEmailList($emailConfig['recipientEmail'] ?? '');
    if ($method === 'inactive' || !$toRecipients) {
        return false;
    }
    require_once dirname(__DIR__) . '/api/SmtpMailer.php';
    $fromEmail = trim((string)($emailConfig['fromEmail'] ?? '')) ?: ($toRecipients[0] ?? '');
    $fromName = trim(str_replace(["\r", "\n", "\t"], '', (string)($emailConfig['fromName'] ?? '')));
    $replyTo = filter_var(($values['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string)$values['email'] : '';
    $bccRecipients = nibblyFormsParseEmailList($emailConfig['bccEmail'] ?? '');
    if ($method === 'smtp') {
        $mailer = new SmtpMailer(
            $emailConfig['smtpHost'] ?? '',
            (int)($emailConfig['smtpPort'] ?? 587),
            $emailConfig['smtpUsername'] ?? '',
            $emailConfig['smtpPassword'] ?? '',
            $emailConfig['smtpEncryption'] ?? 'tls'
        );
        return $mailer->send($toRecipients, $subject, $body, $fromEmail, $fromName, $replyTo, $bccRecipients);
    }
    if ($method === 'sendmail') {
        $headers = [];
        $headers[] = 'From: ' . ($fromName ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>" : $fromEmail);
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'X-Mailer: nibbly CMS';
        $sent = @mail(implode(', ', $toRecipients), '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
        foreach ($bccRecipients as $bccRecipient) {
            $sent = @mail($bccRecipient, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers)) && $sent;
        }
        return $sent;
    }
    return false;
}

function nibblyFormsBuildBody(array $form, array $values): string
{
    $body = 'Neue Einsendung über Formular: ' . $form['label'] . "\n";
    $body .= str_repeat('-', 40) . "\n\n";
    foreach ($form['fields'] as $field) {
        if (in_array(($field['type'] ?? ''), ['heading', 'note', 'hidden'], true)) {
            continue;
        }
        $body .= ($field['label'] ?? $field['key']) . ': ' . ($values[$field['key']] ?? '') . "\n";
    }
    return $body;
}
