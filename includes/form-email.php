<?php
/** Build form notifications from the same localized fields for HTML and plain text. */
function nibblyFormsEmailValue($value): string
{
    return is_array($value) ? implode(', ', array_map('strval', $value)) : trim((string)$value);
}

function nibblyFormsEmailRows(array $form, array $values): array
{
    $rows = [];
    foreach ($form['fields'] as $field) {
        $type = $field['type'];
        if (in_array($type, ['heading', 'note', 'hidden'], true)) continue;
        $value = nibblyFormsEmailValue($values[$field['key']] ?? '');
        if ($type === 'checkbox') {
            $value = $value === '1'
                ? nibblyFormText(['en' => 'Yes', 'de' => 'Ja', 'es' => 'Sí'])
                : nibblyFormText(['en' => 'No', 'de' => 'Nein', 'es' => 'No']);
        } elseif ($value === '') {
            continue;
        }
        if (in_array($type, ['select', 'radio'], true)) {
            foreach ($field['options'] ?? [] as $option) {
                if ($option['value'] === $value) {
                    $value = nibblyFormText($option['label']) ?: $value;
                    break;
                }
            }
        }
        $rows[] = ['type' => $type, 'label' => nibblyFormText($field['label']) ?: $field['key'], 'value' => $value];
    }
    return $rows;
}

function nibblyFormsBuildBody(array $form, array $values): string
{
    $blocks = [nibblyFormText($form['label'])];
    foreach (nibblyFormsEmailRows($form, $values) as $row) {
        $blocks[] = $row['label'] . "\n" . $row['value'];
    }
    return implode("\n\n", $blocks);
}

function nibblyFormsNotificationHtml(array $form, array $values, string $brand): string
{
    // The optional site-owned template uses the existing Frydrych renderer signature.
    $template = dirname(__DIR__) . '/templates/contact-email.php';
    if (is_file($template)) {
        require_once $template;
        if (function_exists('nibblyFormsBuildHtmlBody')) {
            return nibblyFormsBuildHtmlBody($form, $values);
        }
    }
    $title = nibblyFormText($form['label']);
    $lang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ob_start();
    ?>
<!doctype html>
<html lang="<?= $escape($lang) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $escape($title) ?></title></head>
<body style="margin:0;background:#f4efe6;color:#1c2430;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4efe6;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;table-layout:fixed;background:#ffffff;border:1px solid #ddd2c0;">
<tr><td style="padding:22px 26px;background:#1b2a3a;color:#faf7f1;">
<?php if ($brand !== ''): ?>
<div style="font:14px/1.5 Arial,Helvetica,sans-serif;color:#ede6da;"><?= $escape($brand) ?></div>
<?php endif; ?>
<h1 style="margin:6px 0 0;font:normal 30px/1.2 Georgia,serif;"><?= $escape($title) ?></h1>
</td></tr>
<?php foreach (nibblyFormsEmailRows($form, $values) as $row):
    $value = $row['value'];
    $href = '';
    if ($row['type'] === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $href = 'mailto:' . str_replace('%40', '@', rawurlencode($value));
    } elseif ($row['type'] === 'tel' && preg_match('/\d/', $value)) {
        $href = 'tel:' . preg_replace('/[^+0-9]/', '', $value);
    }
    ?>
<tr><td style="padding:14px 26px;border-top:1px solid #e7e0d6;<?= $row['type'] === 'textarea' ? 'background:#faf7f1;' : '' ?>">
<div style="font:bold 13px/1.4 Arial,Helvetica,sans-serif;color:#46505d;"><?= $escape($row['label']) ?></div>
<div style="margin-top:4px;font:16px/1.5 Arial,Helvetica,sans-serif;color:#1c2430;overflow-wrap:anywhere;word-break:break-word;"><?php if ($href !== ''): ?><a href="<?= $escape($href) ?>" style="color:#a3432b;"><?= $escape($value) ?></a><?php else: ?><?= nl2br($escape($value), false) ?><?php endif; ?></div>
</td></tr>
<?php endforeach; ?>
</table>
</td></tr></table>
</body></html>
    <?php
    return trim((string)ob_get_clean());
}
