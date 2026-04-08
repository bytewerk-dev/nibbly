<?php
/**
 * Admin Login
 */

// Redirect to setup if not yet configured
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: setup.php');
    exit;
}

require_once 'config.php';
require_once __DIR__ . '/lang/i18n.php';
require_once __DIR__ . '/users.php';
ensureUsersFile();

// Secure session cookie settings
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

$error = '';
$lockoutWait = 0; // seconds remaining for countdown

// ============================================================
// BRUTE FORCE PROTECTION (IP-based, file-backed)
// ============================================================

define('LOGIN_ATTEMPTS_FILE', __DIR__ . '/../content/login_attempts.json');
define('BRUTE_FORCE_DELAY_STEP', 15);     // seconds per failed attempt after threshold
define('BRUTE_FORCE_THRESHOLD', 3);       // free attempts before delay kicks in
define('BRUTE_FORCE_MAX_ATTEMPTS', 20);   // hard lockout after this many
define('BRUTE_FORCE_LOCKOUT_TIME', 86400); // 24 hours hard lockout

function getIpHash() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $ip . 'nibbly');
}

function loadAttempts() {
    if (!file_exists(LOGIN_ATTEMPTS_FILE)) {
        return [];
    }
    $data = json_decode(file_get_contents(LOGIN_ATTEMPTS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveAttempts($data) {
    // Cleanup entries older than 24 hours
    $now = time();
    foreach ($data as $hash => $entry) {
        if ($now - ($entry['last'] ?? 0) > BRUTE_FORCE_LOCKOUT_TIME) {
            unset($data[$hash]);
        }
    }
    $dir = dirname(LOGIN_ATTEMPTS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(LOGIN_ATTEMPTS_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Check if login is allowed for this IP.
 * Returns ['allowed' => bool, 'wait' => seconds, 'locked' => bool]
 */
function checkBruteForce() {
    $ipHash = getIpHash();
    $data = loadAttempts();
    $entry = $data[$ipHash] ?? null;

    if ($entry === null) {
        return ['allowed' => true, 'wait' => 0, 'locked' => false];
    }

    $now = time();
    $count = $entry['count'] ?? 0;
    $lastAttempt = $entry['last'] ?? 0;

    // Hard lockout after MAX_ATTEMPTS
    if ($count >= BRUTE_FORCE_MAX_ATTEMPTS) {
        $remaining = BRUTE_FORCE_LOCKOUT_TIME - ($now - $lastAttempt);
        if ($remaining > 0) {
            return ['allowed' => false, 'wait' => $remaining, 'locked' => true];
        }
        // Lockout expired, clean up
        unset($data[$ipHash]);
        saveAttempts($data);
        return ['allowed' => true, 'wait' => 0, 'locked' => false];
    }

    // Progressive delay after threshold
    if ($count >= BRUTE_FORCE_THRESHOLD) {
        $delay = ($count - BRUTE_FORCE_THRESHOLD + 1) * BRUTE_FORCE_DELAY_STEP;
        $remaining = $delay - ($now - $lastAttempt);
        if ($remaining > 0) {
            return ['allowed' => false, 'wait' => $remaining, 'locked' => false];
        }
    }

    return ['allowed' => true, 'wait' => 0, 'locked' => false];
}

function recordFailedAttempt() {
    $ipHash = getIpHash();
    $data = loadAttempts();
    $now = time();

    if (!isset($data[$ipHash])) {
        $data[$ipHash] = ['count' => 0, 'first' => $now, 'last' => $now];
    }

    $data[$ipHash]['count']++;
    $data[$ipHash]['last'] = $now;

    saveAttempts($data);
}

function resetAttempts() {
    $ipHash = getIpHash();
    $data = loadAttempts();
    unset($data[$ipHash]);
    saveAttempts($data);
}

/**
 * Separate rate-limiting for password reset requests.
 * Uses a "reset_" prefix to avoid sharing counters with login attempts.
 * More lenient: 5 attempts per hour.
 */
function checkResetRateLimit() {
    $key = 'reset_' . getIpHash();
    $data = loadAttempts();
    $entry = $data[$key] ?? null;

    if ($entry === null) {
        return ['allowed' => true];
    }

    $now = time();
    $count = $entry['count'] ?? 0;
    $firstAttempt = $entry['first'] ?? $now;

    // Reset counter after 1 hour
    if ($now - $firstAttempt > 3600) {
        unset($data[$key]);
        saveAttempts($data);
        return ['allowed' => true];
    }

    // Allow 5 attempts per hour
    if ($count >= 5) {
        return ['allowed' => false];
    }

    return ['allowed' => true];
}

function recordResetAttempt() {
    $key = 'reset_' . getIpHash();
    $data = loadAttempts();
    $now = time();

    if (!isset($data[$key])) {
        $data[$key] = ['count' => 0, 'first' => $now, 'last' => $now];
    }

    $data[$key]['count']++;
    $data[$key]['last'] = $now;

    saveAttempts($data);
}

// ============================================================
// PASSWORD STRENGTH CHECK
// ============================================================

function isPasswordWeak($password) {
    if (strlen($password) < 8) return true;
    if (!preg_match('/[A-Z]/', $password)) return true;
    if (!preg_match('/[a-z]/', $password)) return true;
    if (!preg_match('/[0-9]/', $password)) return true;
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return true;
    return false;
}

// ============================================================
// REDIRECT VALIDATION
// ============================================================

function validateRedirectUrl($url) {
    if (empty($url)) {
        return '/';
    }

    $parsed = parse_url($url);

    // Only allow relative URLs or URLs to own domain
    if (isset($parsed['host'])) {
        $ownHost = $_SERVER['HTTP_HOST'] ?? '';
        if ($parsed['host'] !== $ownHost) {
            return '/';
        }
    }

    // Don't redirect back to admin area
    if (strpos($url, '/admin/') !== false) {
        return '/';
    }

    return $url;
}

// ============================================================
// LOGOUT
// ============================================================

if (isset($_GET['logout'])) {
    $redirect = validateRedirectUrl($_SERVER['HTTP_REFERER'] ?? '/');
    session_destroy();
    header('Location: ' . $redirect);
    exit;
}

// Save redirect URL (where to go after login)
// When the user logs in via /admin/index.php they always land on the dashboard.
// Only inline-editor logins (with ?redirect=… param) should return to the page.
if (!isset($_SESSION['redirect_after_login'])) {
    if (!empty($_GET['redirect'])) {
        $_SESSION['redirect_after_login'] = validateRedirectUrl($_GET['redirect']);
    } else {
        $_SESSION['redirect_after_login'] = 'dashboard.php';
    }
}

// Already logged in? Redirect to saved page
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $redirect = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
    unset($_SESSION['redirect_after_login']);
    header('Location: ' . $redirect);
    exit;
}

// ============================================================
// LOGIN ATTEMPT
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bruteCheck = checkBruteForce();

    if (!$bruteCheck['allowed']) {
        $lockoutWait = $bruteCheck['wait'];
        if ($bruteCheck['locked']) {
            $hours = ceil($lockoutWait / 3600);
            $error = t('login.error_locked', ['time' => $hours . ' ' . t('login.hour')]);
        } else {
            $error = t('login.error_too_many');
        }
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        // Dev bypass: fixed password for localhost only
        $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        $devPassword = 'dev';

        $user = verifyUserPassword($username, $password);

        // Dev bypass fallback
        if (!$user && $isLocalhost && $password === $devPassword) {
            $user = findUserByUsername($username);
        }

        if ($user) {
            resetAttempts();
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_login_time'] = time();
            $_SESSION['admin_user_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            updateUserLastLogin($user['id']);

            // Check password strength and set warning
            if ($password !== $devPassword && isPasswordWeak($password)) {
                $_SESSION['password_warning'] = true;
            }

            // Prompt to add email if missing
            if (empty($user['email'])) {
                $_SESSION['email_missing'] = true;
            }

            $redirect = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect);
            exit;
        } else {
            recordFailedAttempt();

            // Re-check to show countdown if threshold now exceeded
            $bruteCheck = checkBruteForce();
            if (!$bruteCheck['allowed']) {
                $lockoutWait = $bruteCheck['wait'];
                $error = t('login.error_wait');
            } else {
                $error = t('login.error_invalid');
            }
        }
    }
}

// ============================================================
// PASSWORD RESET FLOW
// ============================================================

$resetAction = $_GET['action'] ?? '';
$resetSuccess = '';
$resetError = '';

// Handle reset request (email form submission)
if ($resetAction === 'reset-request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $resetRateCheck = checkResetRateLimit();
    if (!$resetRateCheck['allowed']) {
        $resetError = t('login.reset_rate_limit');
    } else {
        $resetEmail = trim($_POST['reset_email'] ?? '');
        // Always show same message to prevent user enumeration
        $resetSuccess = t('login.reset_sent');

        if (!empty($resetEmail) && filter_var($resetEmail, FILTER_VALIDATE_EMAIL)) {
            $user = findUserByEmail($resetEmail);
            if ($user) {
                $rawToken = generateResetToken($user['id']);
                if ($rawToken) {
                    // Send reset email
                    $settingsPath = defined('SETTINGS_PATH') ? SETTINGS_PATH : __DIR__ . '/../content/settings.json';
                    $emailConfig = null;
                    if (file_exists($settingsPath)) {
                        $s = json_decode(file_get_contents($settingsPath), true);
                        if (!empty($s['email']) && ($s['email']['method'] ?? 'inactive') !== 'inactive') {
                            $emailConfig = $s['email'];
                        }
                    }

                    if ($emailConfig) {
                        require_once __DIR__ . '/../api/SmtpMailer.php';
                        $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                            . dirname($_SERVER['SCRIPT_NAME']) . '/index.php?action=reset&token=' . urlencode($rawToken);

                        $subject = t('login.reset_email_subject');
                        $body = t('login.reset_email_body', ['url' => $resetUrl, 'username' => $user['username']]);

                        $fromEmail = $emailConfig['fromEmail'] ?? $emailConfig['recipientEmail'] ?? '';
                        $fromName = $emailConfig['fromName'] ?? '';

                        if ($emailConfig['method'] === 'smtp') {
                            $mailer = new SmtpMailer(
                                $emailConfig['smtpHost'] ?? '',
                                intval($emailConfig['smtpPort'] ?? 587),
                                $emailConfig['smtpUsername'] ?? '',
                                $emailConfig['smtpPassword'] ?? '',
                                $emailConfig['smtpEncryption'] ?? 'tls'
                            );
                            $mailer->send($resetEmail, $subject, $body, $fromEmail, $fromName);
                        } elseif ($emailConfig['method'] === 'sendmail') {
                            $headers = [];
                            $headers[] = 'From: ' . ($fromName ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>" : $fromEmail);
                            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                            @mail($resetEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
                        }
                    }
                }
            }
        }
        // Record attempt to rate-limit (separate counter from login)
        recordResetAttempt();
    }
}

// Handle password reset (new password form submission)
if ($resetAction === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $user = validateResetToken($token);
    if (!$user) {
        $resetError = t('login.reset_invalid_token');
    } elseif (empty($newPassword) || $newPassword !== $confirmPassword) {
        $resetError = t('login.reset_password_mismatch');
    } elseif (isPasswordWeak($newPassword)) {
        $resetError = t('login.reset_password_weak');
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        updateUserPassword($user['id'], $newHash);
        clearResetToken($user['id']);
        $resetSuccess = t('login.reset_success');
        $resetAction = ''; // Show login form with success message
    }
}

// Check if email is active (for showing forgot password link)
$emailActive = false;
$settingsForEmail = null;
if (defined('SETTINGS_PATH') && file_exists(SETTINGS_PATH)) {
    $settingsForEmail = json_decode(file_get_contents(SETTINGS_PATH), true);
    if (!empty($settingsForEmail['email']['method']) && $settingsForEmail['email']['method'] !== 'inactive') {
        $emailActive = true;
    }
}

// Load settings for branding/theme
$siteSettings = ['branding' => ['logo' => '/assets/images/favicon.svg', 'name' => '', 'showBranding' => true], 'theme' => ['adminTheme' => 'light', 'primaryColor' => '#2563eb', 'accentColor' => '#60a5fa']];
if (defined('SETTINGS_PATH') && file_exists(SETTINGS_PATH)) {
    $loadedSettings = json_decode(file_get_contents(SETTINGS_PATH), true);
    if (is_array($loadedSettings)) {
        // Only merge sub-arrays; skip old-format scalar values (e.g. "theme": "dark")
        foreach ($siteSettings as $key => $defaults) {
            if (isset($loadedSettings[$key]) && is_array($loadedSettings[$key])) {
                $siteSettings[$key] = array_replace($defaults, $loadedSettings[$key]);
            }
        }
        if (!empty($loadedSettings['favicon'])) $siteSettings['favicon'] = $loadedSettings['favicon'];
    }
}
$adminTheme = $siteSettings['theme']['adminTheme'] ?? 'light';
$showBranding = $siteSettings['branding']['showBranding'] ?? true;
$brandLogo = !empty($siteSettings['branding']['logo']) ? $siteSettings['branding']['logo'] : '/assets/images/favicon.svg';
$brandName = $siteSettings['branding']['name'] ?? (defined('SITE_NAME') ? SITE_NAME : 'CMS');
?>
<!DOCTYPE html>
<html lang="en" data-site-theme="<?php echo htmlspecialchars($adminTheme === 'system' ? 'light' : $adminTheme); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login - <?php echo defined('SITE_NAME') ? SITE_NAME : 'CMS'; ?></title>
    <?php
    $_loginFavicon = $siteSettings['favicon'] ?? $brandLogo;
    $_loginFaviconType = pathinfo($_loginFavicon, PATHINFO_EXTENSION) === 'svg' ? 'image/svg+xml' : 'image/png';
    ?>
    <link rel="icon" href="<?php echo htmlspecialchars($_loginFavicon); ?>" type="<?php echo $_loginFaviconType; ?>">
    <link rel="stylesheet" href="style.css">
    <?php if ($adminTheme === 'system'): ?>
    <script>
    (function() {
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.setAttribute('data-site-theme', 'dark');
        }
    })();
    </script>
    <?php endif; ?>
    <?php if ($siteSettings['theme']['primaryColor'] !== '#2563eb' || $siteSettings['theme']['accentColor'] !== '#60a5fa'): ?>
    <style>
    :root {
        <?php $pc = htmlspecialchars($siteSettings['theme']['primaryColor']); ?>
        <?php if ($siteSettings['theme']['primaryColor'] !== '#2563eb'): ?>
        --nb-primary: <?php echo $pc; ?>;
        --nb-primary-btn: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $pc; ?> 70%, white) 0%, <?php echo $pc; ?> 70%);
        --nb-primary-btn-hover: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $pc; ?> 50%, white) 0%, <?php echo $pc; ?> 70%);
        <?php endif; ?>
        <?php if ($siteSettings['theme']['accentColor'] !== '#60a5fa'): ?>
        --nb-brand: <?php echo htmlspecialchars($siteSettings['theme']['accentColor']); ?>;
        <?php endif; ?>
    }
    </style>
    <?php endif; ?>
</head>
<body class="login-page">
    <div class="login-container">
        <?php if ($showBranding): ?>
        <div class="login-logo">
            <img src="<?php echo htmlspecialchars($brandLogo); ?>" alt="<?php echo htmlspecialchars($brandName); ?>" width="40" height="40">
        </div>
        <?php endif; ?>
        <h1><?php echo t('login.title'); ?></h1>
        <p class="site-name"><?php echo defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'CMS'; ?></p>

        <?php if ($resetSuccess && $resetAction === ''): ?>
            <div class="success-message"><?php echo htmlspecialchars($resetSuccess); ?></div>
        <?php endif; ?>

        <?php if ($resetAction === 'reset-request'): ?>
            <!-- Forgot Password: enter email -->
            <?php if ($resetSuccess): ?>
                <div class="success-message"><?php echo htmlspecialchars($resetSuccess); ?></div>
                <p class="back-link"><a href="index.php">&larr; <?php echo t('login.back_to_login'); ?></a></p>
            <?php else: ?>
                <?php if ($resetError): ?>
                    <div class="error-message"><?php echo htmlspecialchars($resetError); ?></div>
                <?php endif; ?>
                <p class="reset-desc"><?php echo t('login.reset_desc'); ?></p>
                <form method="post" action="?action=reset-request">
                    <div class="form-group">
                        <label for="reset_email"><?php echo t('login.reset_email_label'); ?></label>
                        <input type="email" id="reset_email" name="reset_email" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><?php echo t('login.reset_send'); ?></button>
                </form>
                <p class="back-link"><a href="index.php">&larr; <?php echo t('login.back_to_login'); ?></a></p>
            <?php endif; ?>

        <?php elseif ($resetAction === 'reset'): ?>
            <!-- Reset Password: enter new password -->
            <?php
            $tokenParam = $_GET['token'] ?? $_POST['token'] ?? '';
            $tokenUser = validateResetToken($tokenParam);
            ?>
            <?php if (!$tokenUser): ?>
                <div class="error-message"><?php echo t('login.reset_invalid_token'); ?></div>
                <p class="back-link"><a href="index.php">&larr; <?php echo t('login.back_to_login'); ?></a></p>
            <?php else: ?>
                <?php if ($resetError): ?>
                    <div class="error-message"><?php echo htmlspecialchars($resetError); ?></div>
                <?php endif; ?>
                <p class="reset-desc"><?php echo t('login.reset_new_password_desc'); ?></p>
                <form method="post" action="?action=reset">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($tokenParam); ?>">
                    <div class="form-group">
                        <label for="new_password"><?php echo t('settings.new_password'); ?></label>
                        <input type="password" id="new_password" name="new_password" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password"><?php echo t('settings.confirm_password'); ?></label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><?php echo t('login.reset_set_password'); ?></button>
                </form>
                <p class="back-link"><a href="index.php">&larr; <?php echo t('login.back_to_login'); ?></a></p>
            <?php endif; ?>

        <?php else: ?>
            <!-- Normal Login Form -->
            <?php if (isset($_GET['timeout'])): ?>
                <div class="info-message"><?php echo t('login.session_expired'); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                    <?php if ($lockoutWait > 0): ?>
                        <div class="lockout-countdown" id="lockoutCountdown">
                            <span id="countdownSeconds"><?php echo $lockoutWait; ?></span>s remaining
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="loginForm">
                <div class="form-group">
                    <label for="username"><?php echo t('login.username'); ?></label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password"><?php echo t('login.password'); ?></label>
                    <div class="password-input-wrap">
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="password-toggle" id="passwordToggle" aria-label="<?php echo t('login.show_password'); ?>" data-label-show="<?php echo t('login.show_password'); ?>" data-label-hide="<?php echo t('login.hide_password'); ?>">
                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                    <?php if ($emailActive): ?>
                    <p class="forgot-password"><a href="?action=reset-request"><?php echo t('login.forgot_password'); ?></a></p>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="loginBtn"<?php echo $lockoutWait > 0 ? ' disabled' : ''; ?>><?php echo t('login.button'); ?></button>
            </form>

            <p class="back-link"><a href="..">&larr; <?php echo t('login.back_to_site'); ?></a></p>
        <?php endif; ?>
        <p class="login-version">Nibbly <?php echo defined('NIBBLY_VERSION') ? NIBBLY_VERSION : 'dev'; ?></p>
    </div>

    <script>
    (function() {
        var toggle = document.getElementById('passwordToggle');
        if (toggle) {
            toggle.addEventListener('click', function() {
                var input = document.getElementById('password');
                var isVisible = input.type === 'text';
                input.type = isVisible ? 'password' : 'text';
                this.classList.toggle('visible', !isVisible);
                this.setAttribute('aria-label', isVisible ? this.dataset.labelShow : this.dataset.labelHide);
            });
        }
    })();
    </script>

    <?php if ($lockoutWait > 0): ?>
    <script>
    (function() {
        var remaining = <?php echo (int)$lockoutWait; ?>;
        var countdownEl = document.getElementById('countdownSeconds');
        var loginBtn = document.getElementById('loginBtn');

        var labelHour = <?php echo json_encode(t('login.hour')); ?>;
        var labelMin = <?php echo json_encode(t('login.minute')); ?>;
        function formatTime(seconds) {
            if (seconds >= 3600) {
                var h = Math.floor(seconds / 3600);
                var m = Math.ceil((seconds % 3600) / 60);
                return h + labelHour + ' ' + m + labelMin;
            }
            if (seconds >= 60) {
                var m = Math.floor(seconds / 60);
                var s = seconds % 60;
                return m + ':' + (s < 10 ? '0' : '') + s;
            }
            return seconds;
        }

        function tick() {
            remaining--;
            if (remaining <= 0) {
                countdownEl.parentElement.parentElement.style.display = 'none';
                loginBtn.disabled = false;
                loginBtn.textContent = <?php echo json_encode(t('login.button')); ?>;
                return;
            }
            countdownEl.textContent = formatTime(remaining);
            setTimeout(tick, 1000);
        }

        countdownEl.textContent = formatTime(remaining);
        loginBtn.disabled = true;
        setTimeout(tick, 1000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
