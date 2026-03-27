<?php
/**
 * Contact Form Handler
 * Processes form submissions and sends emails via SMTP or PHP mail()
 * Also saves all inquiries locally as backup
 */

header('Content-Type: application/json; charset=utf-8');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/SmtpMailer.php';

// Load email configuration from settings.json or fallback to smtp-config.php
$emailConfig = null;
$settingsPath = __DIR__ . '/../content/settings.json';

if (file_exists($settingsPath)) {
    $settings = json_decode(file_get_contents($settingsPath), true);
    if (!empty($settings['email'])) {
        $emailConfig = $settings['email'];
    }
}

// Fallback: legacy smtp-config.php (for existing installations)
if (!$emailConfig && file_exists(__DIR__ . '/smtp-config.php')) {
    require_once __DIR__ . '/smtp-config.php';
    $emailConfig = [
        'method' => 'smtp',
        'recipientEmail' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '',
        'fromEmail' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '',
        'fromName' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '',
        'smtpHost' => defined('SMTP_HOST') ? SMTP_HOST : '',
        'smtpPort' => defined('SMTP_PORT') ? SMTP_PORT : 587,
        'smtpUsername' => defined('SMTP_USERNAME') ? SMTP_USERNAME : '',
        'smtpPassword' => defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '',
        'smtpEncryption' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls',
    ];
}

if (!$emailConfig || empty($emailConfig['recipientEmail']) || ($emailConfig['method'] ?? '') === 'inactive') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Email not configured']);
    exit;
}

// Recipient email
$to = $emailConfig['recipientEmail'];

// Path to mail backup file
$mailsFile = __DIR__ . '/../content/mails.json';

/**
 * Remove newlines from string (header injection protection)
 */
function sanitizeHeaderValue($value) {
    return str_replace(["\r", "\n", "\t"], '', trim($value));
}

/**
 * Save email locally as backup
 */
function saveMailBackup($filePath, $mailData) {
    $mails = [];

    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        $mails = json_decode($content, true) ?: [];
    }

    // Insert new mail at beginning (newest first)
    array_unshift($mails, $mailData);

    // Keep max 500 mails
    if (count($mails) > 500) {
        $mails = array_slice($mails, 0, 500);
    }

    file_put_contents($filePath, json_encode($mails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Get and sanitize form data
$lang = $_POST['lang'] ?? 'de';
$name = sanitizeHeaderValue($_POST['name'] ?? '');
$email = sanitizeHeaderValue($_POST['email'] ?? '');
$phone = sanitizeHeaderValue($_POST['phone'] ?? '');
$occasion = sanitizeHeaderValue($_POST['occasion'] ?? '');
$date = sanitizeHeaderValue($_POST['date'] ?? '');
$message = trim($_POST['message'] ?? '');

// Validation
$errors = [];

if (empty($name)) {
    $errors[] = $lang === 'de' ? 'Name ist erforderlich' : 'Name is required';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = $lang === 'de' ? 'Gültige E-Mail ist erforderlich' : 'Valid email is required';
}

if (empty($occasion)) {
    $errors[] = $lang === 'de' ? 'Anlass ist erforderlich' : 'Occasion is required';
}

if (empty($message)) {
    $errors[] = $lang === 'de' ? 'Nachricht ist erforderlich' : 'Message is required';
}

// Honeypot check
if (!empty($_POST['website'])) {
    echo json_encode([
        'success' => true,
        'message' => $lang === 'de'
            ? 'Vielen Dank für Ihre Nachricht!'
            : 'Thank you for your message!'
    ]);
    exit;
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

// Compose email
$subject = ($lang === 'de' ? 'Anfrage über Website: ' : 'Website inquiry: ') . $occasion;

$body = $lang === 'de'
    ? "Neue Anfrage über das Kontaktformular\n"
    : "New inquiry via contact form\n";
$body .= str_repeat('-', 40) . "\n\n";

$body .= ($lang === 'de' ? "Name: " : "Name: ") . $name . "\n";
$body .= ($lang === 'de' ? "E-Mail: " : "Email: ") . $email . "\n";

if ($phone) {
    $body .= ($lang === 'de' ? "Telefon: " : "Phone: ") . $phone . "\n";
}

$body .= ($lang === 'de' ? "Anlass: " : "Occasion: ") . $occasion . "\n";

if ($date) {
    $formattedDate = date('d.m.Y', strtotime($date));
    $body .= ($lang === 'de' ? "Gewünschtes Datum: " : "Preferred date: ") . $formattedDate . "\n";
}

$body .= "\n" . ($lang === 'de' ? "Nachricht:\n" : "Message:\n");
$body .= str_repeat('-', 40) . "\n";
$body .= $message . "\n";
$body .= str_repeat('-', 40) . "\n";

// Send email
$sent = false;
$method = $emailConfig['method'] ?? 'smtp';
$fromEmail = $emailConfig['fromEmail'] ?? $emailConfig['recipientEmail'];
$fromName = $emailConfig['fromName'] ?? '';

if ($method === 'smtp') {
    $mailer = new SmtpMailer(
        $emailConfig['smtpHost'] ?? '',
        intval($emailConfig['smtpPort'] ?? 587),
        $emailConfig['smtpUsername'] ?? '',
        $emailConfig['smtpPassword'] ?? '',
        $emailConfig['smtpEncryption'] ?? 'tls'
    );

    $sent = $mailer->send(
        $to,
        $subject,
        $body,
        $fromEmail,
        $fromName,
        $email  // Reply-To
    );
} elseif ($method === 'sendmail') {
    // PHP mail() — requires server IP to be whitelisted for sending
    $headers = [];
    $headers[] = 'From: ' . ($fromName ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>" : $fromEmail);
    $headers[] = 'Reply-To: ' . $email;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: Nibbly CMS';

    $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

if ($sent) {
    saveMailBackup($mailsFile, [
        'id' => uniqid('mail_'),
        'timestamp' => date('c'),
        'lang' => $lang,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'occasion' => $occasion,
        'date' => $date,
        'message' => $message,
        'status' => 'sent',
        'read' => false
    ]);

    echo json_encode([
        'success' => true,
        'message' => $lang === 'de'
            ? 'Vielen Dank für Ihre Nachricht!'
            : 'Thank you for your message!'
    ]);
} else {
    // Still save locally even if sending failed
    saveMailBackup($mailsFile, [
        'id' => uniqid('mail_'),
        'timestamp' => date('c'),
        'lang' => $lang,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'occasion' => $occasion,
        'date' => $date,
        'message' => $message,
        'status' => 'send_failed',
        'read' => false
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $lang === 'de'
            ? 'Fehler beim Senden. Bitte versuchen Sie es später erneut.'
            : 'Error sending. Please try again later.'
    ]);
}
