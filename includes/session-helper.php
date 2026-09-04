<?php
/** Shared authentication state for the dashboard, API and inline editor. */

function nibblySessionStart(): void {
    if (session_status() !== PHP_SESSION_NONE || headers_sent()) return;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function nibblySessionIsLoopback(string $host): bool {
    $host = strtolower(trim($host));
    if (str_starts_with($host, '[')) {
        $host = substr($host, 1, strpos($host, ']') - 1);
    } elseif (substr_count($host, ':') === 1) {
        $host = explode(':', $host, 2)[0];
    }
    return in_array($host, ['localhost', '::1', '0:0:0:0:0:0:0:1'], true)
        || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($host, '127.'));
}

function nibblySessionDevLoginAllowed(): bool {
    return (!defined('NIBBLY_DEV_LOGIN') || NIBBLY_DEV_LOGIN === true)
        && nibblySessionIsLoopback((string)($_SERVER['REMOTE_ADDR'] ?? ''))
        && nibblySessionIsLoopback((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
}

function nibblySessionForgetLogin(): void {
    foreach (['admin_logged_in', 'admin_login_time', 'admin_user_id', 'admin_username',
        'admin_role', 'admin_password_fingerprint', 'nibbly_dev_login', 'csrf_token',
        'password_warning', 'email_missing'] as $key) {
        unset($_SESSION[$key]);
    }
}

function nibblySessionValidate(bool $refresh = false): bool {
    if (($_SESSION['admin_logged_in'] ?? false) !== true) return false;
    $config = dirname(__DIR__) . '/admin/config.php';
    if (!defined('SESSION_LIFETIME') && is_file($config)) require_once $config;
    $lastActivity = (int)($_SESSION['admin_login_time'] ?? 0);
    $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600;
    if ($lastActivity <= 0 || time() - $lastActivity > $lifetime) {
        nibblySessionForgetLogin();
        return false;
    }

    $id = (string)($_SESSION['admin_user_id'] ?? '');
    if (!empty($_SESSION['nibbly_dev_login']) && !nibblySessionDevLoginAllowed()) {
        nibblySessionForgetLogin();
        return false;
    }
    if ($id === 'dev_admin' && !empty($_SESSION['nibbly_dev_login'])) {
        $_SESSION['admin_role'] = 'admin';
        $_SESSION['admin_login_time'] = time();
        return true;
    }

    require_once dirname(__DIR__) . '/admin/users.php';
    // Cache only within this request; the next request re-reads the account.
    static $users = [];
    if ($refresh || !array_key_exists($id, $users)) $users[$id] = findUserById($id);
    $user = $users[$id];
    $fingerprint = (string)($_SESSION['admin_password_fingerprint'] ?? '');
    if (!$user || !in_array($user['role'] ?? '', ['admin', 'editor'], true)
        || $fingerprint === ''
        || !hash_equals(hash('sha256', (string)($user['passwordHash'] ?? '')), $fingerprint)) {
        nibblySessionForgetLogin();
        return false;
    }
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_role'] = $user['role'];
    $_SESSION['admin_login_time'] = time();
    return true;
}

/** Return a site-relative login destination; reject browser URL ambiguities. */
function nibblySessionRedirectUrl(string $url): string {
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return '/';
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) return '/';
    if (isset($parts['scheme']) || isset($parts['host'])) {
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return '/';
        $origin = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''));
        if (strtolower($parts['host'] ?? '') !== strtolower($origin['host'] ?? '')
            || (int)($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80))
                !== (int)($origin['port'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 443 : 80))) return '/';
        $url = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
    $path = rawurldecode((string)($parts['path'] ?? ''));
    if (str_starts_with($url, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $path)
        || preg_match('#(^|/)\.\.?(/|$)#', $path)) return '/';
    $path = '/' . ltrim($path, '/');
    if ($path === '/admin' || str_starts_with($path, '/admin/')) return '/';
    return str_starts_with($url, '/') || str_starts_with($url, '#') ? $url : '/' . $url;
}
