<?php
/**
 * Trusted public address of the installation.
 *
 * Links that leave the current request (password reset mails) must never be
 * built from the Host header: a client can send any value there, and the mail
 * would then carry a valid token to the attacker's domain (CWE-640).
 */

/** scheme://host[:port] from a configured URL, or '' when it is not a usable http(s) origin. */
function nibblySiteUrlOrigin(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    $parts = parse_url($url);
    if (!is_array($parts)) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) return '';
    if (!preg_match('/^(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/', $host)) return '';
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return $scheme . '://' . $host . $port;
}

/**
 * Canonical origin from configuration only: NIBBLY_SITE_URL in admin/config.php,
 * otherwise the site address in the settings (Settings → Search engines).
 */
function nibblyConfiguredSiteOrigin(?string $settingsPath = null): string {
    if (defined('NIBBLY_SITE_URL')) {
        $origin = nibblySiteUrlOrigin((string)NIBBLY_SITE_URL);
        if ($origin !== '') return $origin;
    }
    $settingsPath ??= defined('SETTINGS_PATH') ? SETTINGS_PATH : dirname(__DIR__) . '/content/settings.json';
    if (!is_file($settingsPath)) return '';
    $settings = json_decode((string)file_get_contents($settingsPath), true);
    return is_array($settings) ? nibblySiteUrlOrigin((string)($settings['seo']['siteUrl'] ?? '')) : '';
}

/**
 * Origin for links in outgoing mail. Without a configured address only a purely
 * local request (loopback client and loopback host) may use its own Host header.
 */
function nibblyTrustedSiteOrigin(?string $settingsPath = null): string {
    $origin = nibblyConfiguredSiteOrigin($settingsPath);
    if ($origin !== '') return $origin;
    require_once __DIR__ . '/session-helper.php';
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!nibblySessionIsLoopback((string)($_SERVER['REMOTE_ADDR'] ?? '')) || !nibblySessionIsLoopback($host)) return '';
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    return nibblySiteUrlOrigin(($https ? 'https' : 'http') . '://' . $host);
}

/** Password reset link, or '' when no trusted address is known. */
function nibblyPasswordResetUrl(string $token, ?string $settingsPath = null): string {
    $origin = nibblyTrustedSiteOrigin($settingsPath);
    if ($origin === '') return '';
    // SCRIPT_NAME comes from the server, not from the client; it keeps installs in a subfolder working.
    $adminPath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'))), '/');
    if ($adminPath === '' || $adminPath === '.' || !preg_match('#^(?:/[A-Za-z0-9._~%!$&\'()*+,;=:@-]+)*$#', $adminPath)) {
        $adminPath = '/admin';
    }
    return $origin . $adminPath . '/index.php?action=reset&token=' . rawurlencode($token);
}
