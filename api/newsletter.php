<?php
/**
 * Public endpoint of the newsletter module (includes/newsletter.php).
 *
 *   a=subscribe    POST from nibblyNewsletterForm(); JSON for the script, a result page otherwise
 *   a=confirm      link in the confirmation mail (double opt-in)
 *   a=unsubscribe  GET shows a confirm button, POST unsubscribes (also RFC 8058 one-click)
 *   a=view         browser version of a sent campaign (signed link)
 *   a=open         tracking pixel            a=click  tracked link (signed target)
 *   a=cron         send worker for a web cron: &token=...
 */

$configFile = __DIR__ . '/../admin/config.php';
if (is_file($configFile) && !defined('SITE_LANG_DEFAULT')) require_once $configFile;
require_once __DIR__ . '/../includes/newsletter.php';

$action = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$param = static fn(string $key): string => is_string($_GET[$key] ?? null) ? $_GET[$key] : '';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

if (!nibblyNewsletterModuleEnabled()) {
    http_response_code(404);
    exit;
}

/** A small standalone page for results; no site template, so it works on every site. */
function nibblyNewsletterPage(string $title, string $text, string $lang, string $extraHtml = '', int $status = 200): void {
    $settings = nibblyNewsletterSettings();
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $home = nibblyNewsletterBaseUrl();
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
    echo '<!DOCTYPE html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . $e($title) . ' – ' . $e(nibblyNewsletterSiteName()) . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:' . $settings['backgroundColor'] . ';font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;color:#374151}'
        . 'main{max-width:460px;margin:24px;padding:36px 32px;background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.08);text-align:center}'
        . 'h1{margin:0 0 12px;font-size:1.5rem;color:#111827}p{margin:0 0 20px;line-height:1.6}'
        . 'button,.b{display:inline-block;padding:12px 26px;border:0;border-radius:8px;background:' . $settings['accentColor'] . ';color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}'
        . 'a.l{color:#6b7280;font-size:.9rem}</style></head><body><main><h1>' . $e($title) . '</h1><p>' . $e($text) . '</p>' . $extraHtml
        . ($home !== '' ? '<p style="margin:20px 0 0"><a class="l" href="' . $e($home) . '/">' . $e(nibblyNewsletterString('back_home', $lang)) . '</a></p>' : '')
        . '</main></body></html>';
    exit;
}

$siteLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
$subscriberOf = static function (string $id): ?array {
    if (!preg_match('/^sub_[a-f0-9]{16}$/', $id)) return null;
    return nibblyNewsletterSubscribers()[$id] ?? null;
};

switch ($action) {
    case 'subscribe':
        require_once __DIR__ . '/../includes/form-protection.php';
        $lang = preg_match('/^[a-z]{2}$/', (string)($_POST['lang'] ?? '')) ? $_POST['lang'] : $siteLang;
        $json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $answer = static function (bool $ok, string $key, int $status = 200, array $extra = []) use ($json, $lang): void {
            $message = nibblyNewsletterString($key . ($ok ? '_text' : ''), $lang);
            if ($json) {
                http_response_code($status);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => $ok, 'message' => $message] + $extra);
                exit;
            }
            nibblyNewsletterPage($ok ? nibblyNewsletterString($key . '_title', $lang) : nibblyNewsletterString('invalid_title', $lang), $message, $lang, '', $status);
        };
        if ($method !== 'POST') { header('Allow: POST'); $answer(false, 'try_again', 405); }
        $client = nibblyFormClientKey();
        if (!nibblyFormReserveRequest($client)) $answer(false, 'too_many', 429);
        // Honeypot: bots get the same friendly answer, nothing is stored.
        if (trim((string)($_POST['website'] ?? '')) !== '') { nibblyFormRecordAttempt($client, false); $answer(true, 'pending'); }
        $token = nibblyValidateFormToken($_POST['form_token'] ?? null, 'newsletter');
        if (!$token['valid']) {
            nibblyFormRecordAttempt($client, false);
            $answer(false, 'try_again', 400, ['token' => nibblyCreateFormToken('newsletter')]);
        }
        $email = nibblyNewsletterNormalizeEmail((string)($_POST['email'] ?? ''));
        if ($email === '' || empty($_POST['consent'])) {
            nibblyFormRecordAttempt($client, false);
            $answer(false, $email === '' ? 'email_invalid' : 'consent_required', 400, ['token' => nibblyCreateFormToken('newsletter')]);
        }
        try {
            $result = nibblyNewsletterSubscribe($email, [
                'firstName' => $_POST['firstName'] ?? '', 'lastName' => $_POST['lastName'] ?? '',
                'lists' => array_filter(explode(',', (string)($_POST['lists'] ?? ''))),
            ], $lang);
        } catch (Throwable $error) {
            nibblyFormRecordAttempt($client, false);
            $answer(false, 'try_again', 500, ['token' => nibblyCreateFormToken('newsletter')]);
        }
        nibblyFormRecordAttempt($client, true);
        if ($result['result'] === 'pending') {
            $error = nibblyNewsletterSendConfirmation($result['subscriber']);
            if ($error !== '') error_log('Nibbly newsletter: confirmation mail not sent: ' . $error);
        }
        // An address that is already subscribed gets the same answer: nobody can probe the list.
        $answer(true, $result['result'] === 'subscribed' ? 'subscribed' : 'pending', 200, ['token' => nibblyCreateFormToken('newsletter')]);
        break;

    case 'confirm':
        $subscriber = $subscriberOf($param('s'));
        $lang = (string)(($subscriber['lang'] ?? '') ?: $siteLang);
        if (!$subscriber || !nibblyNewsletterConfirm($subscriber['id'], $param('t'))) {
            nibblyNewsletterPage(nibblyNewsletterString('invalid_title', $lang), nibblyNewsletterString('invalid_text', $lang), $lang, '', 404);
        }
        nibblyNewsletterPage(nibblyNewsletterString('subscribed_title', $lang), nibblyNewsletterString('subscribed_text', $lang), $lang);
        break;

    case 'unsubscribe':
        $subscriber = $subscriberOf($param('s'));
        $lang = (string)(($subscriber['lang'] ?? '') ?: $siteLang);
        if (!$subscriber || !hash_equals((string)$subscriber['token'], $param('t'))) {
            nibblyNewsletterPage(nibblyNewsletterString('invalid_title', $lang), nibblyNewsletterString('invalid_text', $lang), $lang, '', 404);
        }
        if ($method === 'POST') {
            nibblyNewsletterUnsubscribe($subscriber['id'], $param('t'), nibblyNewsletterValidId($param('c')) ? $param('c') : '');
            nibblyNewsletterPage(nibblyNewsletterString('unsubscribed_title', $lang), nibblyNewsletterString('unsubscribed_text', $lang), $lang);
        }
        if ($subscriber['status'] === 'unsubscribed') {
            nibblyNewsletterPage(nibblyNewsletterString('unsubscribed_title', $lang), nibblyNewsletterString('unsubscribed_text', $lang), $lang);
        }
        // A GET never unsubscribes: mail scanners follow links.
        nibblyNewsletterPage(nibblyNewsletterString('unsubscribe_title', $lang), nibblyNewsletterString('unsubscribe_text', $lang, ['email' => $subscriber['email']]), $lang,
            '<form method="post"><button type="submit">' . htmlspecialchars(nibblyNewsletterString('unsubscribe_button', $lang), ENT_QUOTES, 'UTF-8') . '</button></form>');
        break;

    case 'view':
        $campaign = nibblyNewsletterCampaign($param('c'));
        $settings = nibblyNewsletterSettings();
        if (!$campaign || !$settings['webVersion'] || !in_array($campaign['status'], ['sending', 'paused', 'sent'], true)
            || !hash_equals(nibblyNewsletterSign('view', $campaign['id']), $param('h'))) {
            nibblyNewsletterPage(nibblyNewsletterString('invalid_title', $siteLang), nibblyNewsletterString('not_available', $siteLang), $siteLang, '', 404);
        }
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
        echo nibblyNewsletterRender($campaign, null, ['web' => true, 'settings' => $settings])['html'];
        break;

    case 'open':
        if (nibblyNewsletterValidId($param('c')) && hash_equals(nibblyNewsletterSign('open', $param('c'), $param('s')), $param('h'))) {
            nibblyNewsletterRecordEvent($param('c'), $param('s'), 'o');
        }
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, private');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        break;

    case 'click':
        $target = (string)base64_decode(strtr($param('u'), '-_', '+/'), true);
        // Only targets this installation signed itself: no open redirect.
        if (!preg_match('#^https?://#i', $target) || !nibblyNewsletterValidId($param('c'))
            || !hash_equals(nibblyNewsletterSign('click', $param('c'), $param('s'), $target), $param('h'))) {
            nibblyNewsletterPage(nibblyNewsletterString('invalid_title', $siteLang), nibblyNewsletterString('invalid_text', $siteLang), $siteLang, '', 404);
        }
        nibblyNewsletterRecordEvent($param('c'), $param('s'), 'c', $target);
        header('Location: ' . str_replace(["\r", "\n"], '', $target), true, 302);
        break;

    case 'cron':
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!hash_equals(nibblyNewsletterCronToken(), $param('token'))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            break;
        }
        $summary = nibblyNewsletterRunQueue();
        if ($summary['busy']) http_response_code(423);
        echo json_encode(['success' => !$summary['busy'], 'data' => $summary]);
        break;

    default:
        http_response_code(404);
}
