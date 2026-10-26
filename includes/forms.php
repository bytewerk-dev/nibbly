<?php
/**
 * JSON-backed public forms for nibbly.
 *
 * Form definitions live in content/forms/*.json. The renderer intentionally
 * stays small: generated/vibecoded templates can define the structure, while
 * admins can safely edit labels, placeholders, options, required state and
 * column widths.
 *
 * Multilingual texts: every visitor-facing text (form label, description,
 * submit.successText, field label/placeholder/help, option labels) is either a
 * plain string (one text for all languages, as before) or a map of language
 * codes, e.g. {"de": "Nachricht", "en": "Message"}. nibblyFormLocalize() resolves
 * a form for one language: that language, else the primary language, else the
 * first text given. Field keys, option values and the e-mail subject stay single.
 */

require_once __DIR__ . '/form-protection.php';

// The public endpoints (api/form.php, api/form-submit.php) need the primary language
// for text maps; pages have loaded the config already
if (!defined('SITE_LANG_DEFAULT') && is_file(dirname(__DIR__) . '/admin/config.php')) {
    require_once dirname(__DIR__) . '/admin/config.php';
}

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

/** Language codes accepted as keys of a text map. */
function nibblyFormIsLangCode($code): bool
{
    return is_string($code) && preg_match('/^[a-z]{2}$/', $code) === 1;
}

/** A translatable text as stored: a string, or a map of language code => string (empty entries dropped). */
function nibblyFormNormalizeText($value)
{
    if (!is_array($value)) {
        return is_scalar($value) ? (string)$value : '';
    }
    $map = [];
    foreach ($value as $lang => $text) {
        if (nibblyFormIsLangCode($lang) && is_scalar($text) && trim((string)$text) !== '') {
            $map[$lang] = (string)$text;
        }
    }
    return $map ?: '';
}

/** The text of a translatable value in one language: that language, the primary language, then the first text. */
function nibblyFormText($value, ?string $lang = null): string
{
    if (!is_array($value)) {
        return is_scalar($value) ? (string)$value : '';
    }
    $primary = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    foreach ([$lang, $primary] as $candidate) {
        if ($candidate !== null && isset($value[$candidate]) && trim((string)$value[$candidate]) !== '') {
            return (string)$value[$candidate];
        }
    }
    foreach ($value as $text) {
        if (is_scalar($text) && trim((string)$text) !== '') {
            return (string)$text;
        }
    }
    return '';
}

/** A copy of a (normalized) form with every translatable text resolved for one language. */
function nibblyFormLocalize(array $form, ?string $lang = null): array
{
    foreach (['label', 'description'] as $key) {
        $form[$key] = nibblyFormText($form[$key] ?? '', $lang);
    }
    if (is_array($form['submit'] ?? null) && array_key_exists('successText', $form['submit'])) {
        $form['submit']['successText'] = nibblyFormText($form['submit']['successText'], $lang);
    }
    foreach (($form['fields'] ?? []) as $index => $field) {
        foreach (['label', 'placeholder', 'help'] as $key) {
            $form['fields'][$index][$key] = nibblyFormText($field[$key] ?? '', $lang);
        }
        foreach (($field['options'] ?? []) as $optionIndex => $option) {
            $form['fields'][$index]['options'][$optionIndex]['label'] = nibblyFormText($option['label'] ?? '', $lang) ?: (string)($option['value'] ?? '');
        }
    }
    return $form;
}

function nibblyFormDefaultDefinition(string $id = 'contact'): array
{
    $id = nibblyFormIdClean($id);
    return [
        'id' => $id,
        'label' => $id === 'contact' ? ['en' => 'Contact form', 'de' => 'Kontaktformular', 'es' => 'Formulario de contacto'] : ucfirst(str_replace('-', ' ', $id)),
        'description' => '',
        'enabled' => true,
        'submit' => [
            'store' => true,
            'email' => true,
            'subject' => '{form}: {name}',
            'successText' => ['en' => 'Thank you for your message.', 'de' => 'Vielen Dank für deine Nachricht.', 'es' => 'Gracias por tu mensaje.'],
        ],
        'fields' => [
            ['key' => 'name', 'type' => 'text', 'label' => ['en' => 'Name', 'de' => 'Name', 'es' => 'Nombre'], 'required' => true, 'width' => 6],
            ['key' => 'email', 'type' => 'email', 'label' => ['en' => 'Email', 'de' => 'E-Mail', 'es' => 'Correo electrónico'], 'required' => true, 'width' => 6],
            ['key' => 'phone', 'type' => 'tel', 'label' => ['en' => 'Phone', 'de' => 'Telefon', 'es' => 'Teléfono'], 'required' => false, 'width' => 12],
            ['key' => 'message', 'type' => 'textarea', 'label' => ['en' => 'Message', 'de' => 'Nachricht', 'es' => 'Mensaje'], 'required' => true, 'width' => 12],
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
                $label = nibblyFormNormalizeText($option['label'] ?? '');
                $value = (string)($option['value'] ?? '');
                if ($value === '') $value = nibblyFormText($label);
                if ($label === '') $label = $value;
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
        'label' => nibblyFormNormalizeText($field['label'] ?? $key) ?: $key,
        'placeholder' => nibblyFormNormalizeText($field['placeholder'] ?? ''),
        'help' => nibblyFormNormalizeText($field['help'] ?? ''),
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
        'label' => nibblyFormNormalizeText($form['label'] ?? '') ?: ucfirst(str_replace('-', ' ', $id)),
        'description' => nibblyFormNormalizeText($form['description'] ?? ''),
        'enabled' => !array_key_exists('enabled', $form) || !empty($form['enabled']),
        'submit' => array_replace([
            'store' => true,
            'email' => true,
            'subject' => '{form}: {name}',
            'successText' => 'Vielen Dank für deine Nachricht.',
        ], is_array($form['submit'] ?? null) ? $form['submit'] : []),
        'fields' => [],
    ];
    $normalized['submit']['successText'] = nibblyFormNormalizeText($normalized['submit']['successText']) ?: 'Vielen Dank für deine Nachricht.';
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
            'label' => nibblyFormText($form['label']),
            'description' => nibblyFormText($form['description']),
            'enabled' => $form['enabled'],
            'store' => !empty($form['submit']['store']),
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

/**
 * Skeleton for a JSON form while it loads lazily (see nibblyFormSkeleton()):
 * same heading, description, grid spans and button label as nibblyFormRender(),
 * textareas tall, hidden fields left out.
 */
function nibblyFormSkeletonSpec(array $form, string $lang, array $options = []): array
{
    $form = nibblyFormLocalize($form, $lang);
    $cells = [];
    foreach ($form['fields'] as $field) {
        $type = (string)($field['type'] ?? 'text');
        if ($type === 'hidden') continue;
        $kind = in_array($type, ['textarea', 'heading', 'note'], true) ? $type : ($type === 'checkbox' ? 'check' : 'input');
        $cells[] = ['span' => (int)($field['width'] ?? 12), 'kind' => $kind];
    }
    return [
        'heading' => (string)$form['label'],
        'description' => (string)($form['description'] ?? ''),
        'button' => (string)($options['submitLabel'] ?? nibblyPublicString('form_send', $lang)),
        'cells' => $cells,
    ];
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
    $form = nibblyFormLocalize($form, $lang);

    if ($lazy && !nibblyIsLazyFormRender()) {
        return nibblyLazyFormPlaceholder($form['id'], [
            'basePath' => $basePath,
            'params' => [
                'lang' => $lang,
                'basePath' => $basePath,
            ],
            'skeleton' => nibblyFormSkeletonSpec($form, $lang, $options),
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
    $send = (string)($options['submitLabel'] ?? nibblyPublicString('form_send', $lang));
    $sending = (string)($options['sendingLabel'] ?? nibblyPublicString('form_sending', $lang));
    $success = (string)($form['submit']['successText'] ?? 'Vielen Dank.');
    $html .= '<div class="form-actions"><button type="submit" class="btn btn-gradient"><span class="btn-text">' . nibblyFormEscape($send) . '</span><span class="btn-loading" style="display:none">' . nibblyFormEscape($sending) . '</span></button></div>';
    $error = nibblyPublicString('form_send_error', $lang);
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
            $errors[] = str_replace('{field}', nibblyFormText($field['label'] ?? '', $lang) ?: (string)$field['key'], nibblyPublicString('form_field_required', $lang));
            continue;
        }
        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = nibblyPublicString('form_email_invalid', $lang);
        }
    }
    return [$errors, $values];
}

function nibblyFormsApplySubjectTemplate(string $template, array $form, array $values): string
{
    $subject = $template ?: '{form}';
    $replacements = [
        '{form}' => nibblyFormText($form['label']),
        '{name}' => (string)($values['name'] ?? ''),
        '{email}' => (string)($values['email'] ?? ''),
    ];
    return trim(strtr($subject, $replacements)) ?: nibblyFormText($form['label']);
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
    $body = 'Neue Einsendung über Formular: ' . nibblyFormText($form['label']) . "\n";
    $body .= str_repeat('-', 40) . "\n\n";
    foreach ($form['fields'] as $field) {
        if (in_array(($field['type'] ?? ''), ['heading', 'note', 'hidden'], true)) {
            continue;
        }
        $body .= (nibblyFormText($field['label'] ?? '') ?: $field['key']) . ': ' . ($values[$field['key']] ?? '') . "\n";
    }
    return $body;
}
