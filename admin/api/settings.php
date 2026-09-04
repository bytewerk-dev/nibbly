<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'load-settings':
        $defaults = [
            'favicon' => defined('NIBBLY_DEFAULT_FAVICON') ? NIBBLY_DEFAULT_FAVICON : '/assets/images/favicon.svg',
            'favicon_png' => '',
            'branding' => [
                'logo' => '',
                'logoDark' => '',
                'adminLogo' => '',
                'name' => defined('SITE_NAME') ? SITE_NAME : 'CMS',
                'showBranding' => true,
                'logoDisplay' => 'both',
                'logoSize' => 'medium'
            ],
            'theme' => [
                'adminTheme' => 'light',
                'primaryColor' => '#3858e9',
                'accentColor' => '#3858e9',
                'sidebarBg' => '',
                'darkPrimaryColor' => '',
                'darkAccentColor' => '',
                'darkSidebarBg' => '',
                'buttonGlow' => true,
                'buttonRadius' => 6
            ],
            'email' => [
                'method' => 'inactive',
                'recipientEmail' => '',
                'bccEmail' => '',
                'fromEmail' => '',
                'fromName' => defined('SITE_NAME') ? SITE_NAME : '',
                'smtpHost' => '',
                'smtpPort' => 587,
                'smtpUsername' => '',
                'smtpPassword' => '',
                'smtpEncryption' => 'tls'
            ],
            'privacy' => [
                'emailObfuscation' => false,
                'analyticsEnabled' => true,
                'rememberPublicTheme' => true
            ],
            'modules' => [
                'ai' => true,
                'news' => true,
                'events' => true,
                'messages' => true,
                'iconManager' => true
            ],
            'dashboard' => [
                'itemsPerPage' => 50,
                'iconManagerItemsPerPage' => 50,
                'mediaItemsPerPage' => 25
            ],
            'access' => [
                'maintenance' => [
                    'enabled' => false,
                    'mode' => 'maintenance',
                    'title' => t('settings.maintenance_default_title'),
                    'text' => t('settings.maintenance_default_text'),
                    'until' => '',
                    'showCountdown' => false,
                    'brandAsset' => 'none',
                    'image' => '',
                    'imageLayout' => 'none',
                    'overlayColor' => '',
                    'overlayOpacity' => 88,
                    'bypassParam' => 'preview',
                    'bypassKeyHash' => ''
                ]
            ],
            'login' => [
                'brandAsset' => 'favicon',
                'image' => '',
                'imageLayout' => 'none',
                'overlayColor' => '',
                'overlayOpacity' => 86,
                'boxStyle' => 'card',
                'boxColor' => '',
                'boxTextColor' => ''
            ],
            'seo' => [
                'siteUrl' => '',
                'organizationName' => '',
                'defaultOgImage' => '',
                'noindexSite' => false
            ]
        ];

        if (!defined('SETTINGS_PATH') || !file_exists(SETTINGS_PATH)) {
            $defaults['access']['maintenance']['hasBypassKey'] = false;
            unset($defaults['access']['maintenance']['bypassKeyHash']);
            jsonResponse(true, $defaults);
        }

        $settings = json_decode(file_get_contents(SETTINGS_PATH), true);
        if ($settings === null) {
            $defaults['access']['maintenance']['hasBypassKey'] = false;
            unset($defaults['access']['maintenance']['bypassKeyHash']);
            jsonResponse(true, $defaults);
        }

        // Merge with defaults to ensure all keys exist
        $merged = array_replace_recursive($defaults, $settings);
        $merged['access']['maintenance']['hasBypassKey'] = !empty($merged['access']['maintenance']['bypassKeyHash']);
        unset($merged['access']['maintenance']['bypassKeyHash']);
        if (!isAdmin()) { unset($merged['email'], $merged['ai'], $merged['backup']); }
        jsonResponse(true, $merged);
        break;

    case 'save-settings':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!defined('SETTINGS_PATH')) {
            jsonResponse(false, null, 'Settings path not configured');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $settingsJson = $_POST['settings'] ?? '';
        $settings = json_decode($settingsJson, true);
        if (!is_array($settings)) {
            jsonResponse(false, null, 'Invalid JSON format');
        }

        // Whitelist allowed keys
        $allowed = [
            'branding' => ['logo', 'logoDark', 'adminLogo', 'name', 'showBranding', 'logoDisplay', 'logoSize'],
            'theme' => ['adminTheme', 'publicDefault', 'primaryColor', 'accentColor', 'sidebarBg', 'darkPrimaryColor', 'darkAccentColor', 'darkSidebarBg', 'buttonGlow', 'buttonRadius'],
            'general' => ['adminLanguage', 'frontendLoginRedirect'],
            'email' => ['method', 'recipientEmail', 'bccEmail', 'fromEmail', 'fromName', 'smtpHost', 'smtpPort', 'smtpUsername', 'smtpPassword', 'smtpEncryption'],
            'seo' => ['siteUrl', 'organizationName', 'defaultOgImage', 'noindexSite']
        ];

        // Top-level scalar settings (not nested under a group)
        $allowedScalar = ['favicon', 'favicon_png'];

        $sanitized = [];
        foreach ($allowed as $group => $keys) {
            if (!isset($settings[$group])) continue;
            $sanitized[$group] = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $settings[$group])) {
                    $value = $settings[$group][$key];

                    // Validate color values — required (must be set)
                    if (in_array($key, ['primaryColor', 'accentColor'])) {
                        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                            jsonResponse(false, null, 'Invalid color value for ' . $key);
                        }
                    }

                    // Validate optional color values — empty string means "auto"
                    if (in_array($key, ['sidebarBg', 'darkPrimaryColor', 'darkAccentColor', 'darkSidebarBg'])) {
                        $value = (string)$value;
                        if ($value !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                            jsonResponse(false, null, 'Invalid color value for ' . $key);
                        }
                    }

                    // Validate theme choices
                    if (in_array($key, ['adminTheme', 'publicDefault'], true) && !in_array($value, ['light', 'dark', 'system'], true)) {
                        jsonResponse(false, null, 'Invalid theme value');
                    }

                    // Validate buttonGlow (boolean)
                    if ($key === 'buttonGlow') {
                        $value = (bool)$value;
                    }

                    // Validate buttonRadius (integer 0-24)
                    if ($key === 'buttonRadius') {
                        $value = max(0, min(24, intval($value)));
                    }

                    // Validate frontendLoginRedirect mode
                    if ($key === 'frontendLoginRedirect' && !in_array($value, ['auto', 'dashboard'], true)) {
                        jsonResponse(false, null, 'Invalid frontendLoginRedirect value');
                    }

                    // Validate logo paths (prevent traversal and protocol injection)
                    if (in_array($key, ['logo', 'logoDark', 'adminLogo'], true)) {
                        $value = (string)$value;
                        if ($value !== '' && (
                            strpos($value, '..') !== false ||
                            !str_starts_with($value, '/assets/images/') ||
                            preg_match('#[:\x00]#', $value)
                        )) {
                            jsonResponse(false, null, 'Invalid logo path');
                        }
                    }

                    if ($group === 'seo' && $key === 'defaultOgImage') {
                        $value = normalizeOgImagePath((string)$value);
                        if (!validateOgImagePath($value)) {
                            jsonResponse(false, null, 'Default Open Graph image must be a JPG or PNG file from /assets/images/');
                        }
                    }

                    if ($group === 'seo' && $key === 'siteUrl') {
                        $value = rtrim(trim((string)$value), '/');
                        if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                            jsonResponse(false, null, 'Invalid SEO site URL');
                        }
                    }

                    if ($group === 'seo' && $key === 'organizationName') {
                        $value = trim((string)$value);
                    }

                    if ($group === 'seo' && $key === 'noindexSite') {
                        $value = (bool)$value;
                    }

                    // Validate logoDisplay (3-way selector)
                    if ($key === 'logoDisplay' && !in_array($value, ['favicon', 'text', 'both'], true)) {
                        $value = 'both';
                    }

                    // Validate public logo size selector
                    if ($key === 'logoSize' && !in_array($value, ['small', 'medium', 'large'], true)) {
                        $value = 'medium';
                    }

                    // Validate name
                    if ($key === 'name') {
                        $value = trim((string)$value);
                        if (strlen($value) > 100) {
                            $value = substr($value, 0, 100);
                        }
                    }

                    // Validate adminLanguage
                    if ($key === 'adminLanguage') {
                        $value = trim((string)$value);
                        if ($value !== '' && !preg_match('/^[a-z]{2,5}$/', $value)) {
                            jsonResponse(false, null, 'Invalid language code');
                        }
                        if ($value !== '' && !is_file(NIBBLY_ADMIN_DIR . '/lang/' . $value . '.json')) {
                            jsonResponse(false, null, 'Language file not found');
                        }
                    }

                    // Validate boolean
                    if ($key === 'showBranding') {
                        $value = (bool)$value;
                    }

                    // Validate email settings
                    if ($group === 'email') {
                        if ($key === 'method' && !in_array($value, ['smtp', 'sendmail', 'inactive'])) {
                            $value = 'smtp';
                        }
                        if (in_array($key, ['recipientEmail', 'bccEmail'], true)) {
                            if (!nibblyValidateEmailList($value)) {
                                jsonResponse(false, null, 'Invalid email address list for ' . $key);
                            }
                            $value = nibblyNormalizeEmailList($value);
                        }
                        if ($key === 'fromEmail') {
                            $value = trim((string)$value);
                        }
                        if ($key === 'fromEmail' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            jsonResponse(false, null, 'Invalid email address for ' . $key);
                        }
                        if ($key === 'smtpPort') {
                            $value = max(1, min(65535, intval($value)));
                        }
                        if ($key === 'smtpEncryption' && !in_array($value, ['tls', 'ssl', 'none'])) {
                            $value = 'tls';
                        }
                        if (in_array($key, ['smtpHost', 'smtpUsername', 'fromName'])) {
                            $value = trim((string)$value);
                        }
                        // smtpPassword: allow empty (means "keep existing")
                        if ($key === 'smtpPassword' && $value === '') {
                            // Load existing password and keep it
                            if (file_exists(SETTINGS_PATH)) {
                                $existingSettings = json_decode(file_get_contents(SETTINGS_PATH), true) ?: [];
                                $existingPw = $existingSettings['email']['smtpPassword'] ?? '';
                                if ($existingPw !== '') {
                                    $value = $existingPw;
                                }
                            }
                        }
                    }

                    $sanitized[$group][$key] = $value;
                }
            }
        }

        // Top-level scalars (favicon, PNG fallback)
        foreach ($allowedScalar as $scalarKey) {
            if (!array_key_exists($scalarKey, $settings)) continue;
            $value = (string)$settings[$scalarKey];
            if (in_array($scalarKey, ['favicon', 'favicon_png'], true)) {
                if ($value !== '' && (
                    strpos($value, '..') !== false ||
                    !str_starts_with($value, '/assets/images/') ||
                    preg_match('#[:\x00]#', $value)
                )) {
                    jsonResponse(false, null, 'Invalid favicon path');
                }
            }
            $sanitized[$scalarKey] = $value;
        }

        if (!empty($sanitized['theme']) && is_array($sanitized['theme'])) {
            $sanitized['theme'] = nibblySanitizeThemeContrast($sanitized['theme']);
        }

        $contentDir = dirname(SETTINGS_PATH);
        if (!is_dir($contentDir)) {
            mkdir($contentDir, 0755, true);
        }

        // Merge with existing file to preserve non-whitelisted keys (e.g. favicon)
        $existing = [];
        if (file_exists(SETTINGS_PATH)) {
            $existing = json_decode(file_get_contents(SETTINGS_PATH), true) ?: [];
        }
        $accessInput = isset($settings['access']) ? ['access' => array_replace_recursive($existing['access'] ?? [], $settings['access'])] : [];
        $accessPatch = sanitizeAccessSettings($accessInput, $existing);
        if ($accessPatch) {
            $sanitized['access'] = $accessPatch;
        }
        $loginInput = isset($settings['login']) ? ['login' => array_replace_recursive($existing['login'] ?? [], $settings['login'])] : [];
        $loginPatch = sanitizeLoginVisualSettings($loginInput);
        if ($loginPatch) {
            $sanitized['login'] = $loginPatch;
        }
        $privacyPatch = sanitizePrivacySettings($settings);
        if ($privacyPatch) {
            $sanitized['privacy'] = $privacyPatch;
        }
        $modulesPatch = sanitizeModuleSettings($settings);
        if ($modulesPatch) {
            $sanitized['modules'] = $modulesPatch;
        }
        $dashboardPatch = sanitizeDashboardSettings($settings);
        if ($dashboardPatch) {
            $sanitized['dashboard'] = $dashboardPatch;
        }
        $merged = array_replace_recursive($existing, $sanitized);

        $result = nibblyJsonAtomicWrite(SETTINGS_PATH, $merged);

        if ($result === false) {
            jsonResponse(false, null, 'Error saving settings');
        }

        $publicMerged = $merged;
        $publicMerged['access']['maintenance']['hasBypassKey'] = !empty($publicMerged['access']['maintenance']['bypassKeyHash']);
        unset($publicMerged['access']['maintenance']['bypassKeyHash']);
        jsonResponse(true, $publicMerged, 'Settings saved');
        break;

    case 'test-email':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $testConfig = json_decode($_POST['emailConfig'] ?? '{}', true);
        if (!$testConfig || empty($testConfig['recipientEmail'])) {
            jsonResponse(false, null, 'Recipient email is required');
        }

        if (!nibblyValidateEmailList($testConfig['recipientEmail'] ?? '')) {
            jsonResponse(false, null, 'Invalid email address list for recipientEmail');
        }
        if (!nibblyValidateEmailList($testConfig['bccEmail'] ?? '')) {
            jsonResponse(false, null, 'Invalid email address list for bccEmail');
        }

        $testToRecipients = nibblyParseEmailList($testConfig['recipientEmail'] ?? '');
        $testBccRecipients = nibblyParseEmailList($testConfig['bccEmail'] ?? '');
        if (empty($testToRecipients)) {
            jsonResponse(false, null, 'Recipient email is required');
        }

        $testTo = implode(', ', $testToRecipients);
        $testFrom = trim((string)($testConfig['fromEmail'] ?? '')) ?: $testToRecipients[0];
        if (!filter_var($testFrom, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, null, 'Invalid email address for fromEmail');
        }
        $testFromName = $testConfig['fromName'] ?: 'nibbly CMS';
        $testSubject = 'nibbly CMS — Test Email';
        $testBody = "This is a test email from nibbly CMS.\n\nIf you can read this, your email settings are working correctly.\n\nTimestamp: " . date('Y-m-d H:i:s');

        $testMethod = $testConfig['method'] ?? 'smtp';
        $testSent = false;
        $testError = '';

        if ($testMethod === 'smtp') {
            require_once NIBBLY_ADMIN_DIR . '/../api/SmtpMailer.php';
            $mailer = new SmtpMailer(
                $testConfig['smtpHost'] ?? '',
                intval($testConfig['smtpPort'] ?? 587),
                $testConfig['smtpUsername'] ?? '',
                $testConfig['smtpPassword'] ?? '',
                $testConfig['smtpEncryption'] ?? 'tls'
            );
            // If password is empty, try to load from saved settings
            if (empty($testConfig['smtpPassword']) && defined('SETTINGS_PATH') && file_exists(SETTINGS_PATH)) {
                $savedSettings = json_decode(file_get_contents(SETTINGS_PATH), true) ?: [];
                $savedPw = $savedSettings['email']['smtpPassword'] ?? '';
                if ($savedPw) {
                    $mailer = new SmtpMailer(
                        $testConfig['smtpHost'] ?? '',
                        intval($testConfig['smtpPort'] ?? 587),
                        $testConfig['smtpUsername'] ?? '',
                        $savedPw,
                        $testConfig['smtpEncryption'] ?? 'tls'
                    );
                }
            }
            $testSent = $mailer->send($testToRecipients, $testSubject, $testBody, $testFrom, $testFromName, '', $testBccRecipients);
            if (!$testSent) {
                $testError = $mailer->getLastError();
            }
        } elseif ($testMethod === 'sendmail') {
            $headers = [];
            $headers[] = 'From: ' . ($testFromName ? "=?UTF-8?B?" . base64_encode($testFromName) . "?= <$testFrom>" : $testFrom);
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'X-Mailer: nibbly CMS';
            $testSent = @mail($testTo, '=?UTF-8?B?' . base64_encode($testSubject) . '?=', $testBody, implode("\r\n", $headers));
            foreach ($testBccRecipients as $bccRecipient) {
                $testSent = @mail($bccRecipient, '=?UTF-8?B?' . base64_encode($testSubject) . '?=', $testBody, implode("\r\n", $headers)) && $testSent;
            }
            if (!$testSent) {
                $testError = 'PHP mail() returned false. Check server mail configuration.';
            }
        }

        if ($testSent) {
            jsonResponse(true, null, 'Test email sent successfully');
        } else {
            jsonResponse(false, null, $testError ?: 'Failed to send test email');
        }
        break;

    case 'total-reset':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $confirm = $_POST['confirm'] ?? '';
        if ($confirm !== 'DELETE') {
            jsonResponse(false, null, 'Confirmation mismatch');
        }

        $root = dirname(NIBBLY_ADMIN_DIR);

        // Collect language directories from config
        $langDirs = [];
        if (isset($SITE_LANGUAGES) && is_array($SITE_LANGUAGES)) {
            foreach (array_keys($SITE_LANGUAGES) as $code) {
                $dir = $root . '/' . $code;
                if (is_dir($dir)) {
                    $langDirs[] = $dir;
                }
            }
        }

        // Recursive directory delete helper
        $rmdir = function($dir) use (&$rmdir) {
            if (!is_dir($dir)) return;
            $items = scandir($dir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    $rmdir($path);
                } else {
                    @unlink($path);
                }
            }
            @rmdir($dir);
        };

        // Delete content directory (pages, news, settings, events)
        $rmdir($root . '/content');

        // Delete language directories
        foreach ($langDirs as $dir) {
            $rmdir($dir);
        }

        // Delete backups
        $rmdir($root . '/backups');

        // Delete user-uploaded images (but keep favicon.svg)
        $imagesDir = $root . '/assets/images';
        if (is_dir($imagesDir)) {
            $items = scandir($imagesDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === 'favicon.svg') continue;
                $path = $imagesDir . '/' . $item;
                if (is_dir($path)) {
                    $rmdir($path);
                } else {
                    @unlink($path);
                }
            }
        }

        // Delete trash directories
        $rmdir($root . '/assets/images-trash');
        $rmdir($root . '/assets/audio-trash');

        // Clean audio directory (keep directory, remove files)
        $audioDir = $root . '/assets/audio';
        if (is_dir($audioDir)) {
            $items = scandir($audioDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $audioDir . '/' . $item;
                if (is_dir($path)) {
                    $rmdir($path);
                } else {
                    @unlink($path);
                }
            }
        }

        // Delete nav-config.php
        @unlink($root . '/includes/nav-config.php');

        // Delete config.php (must be last — triggers setup wizard)
        @unlink(NIBBLY_ADMIN_DIR . '/config.php');

        // Destroy session
        session_destroy();

        jsonResponse(true, null, 'Installation reset');
        break;

    // ─── Site Backup ────────────────────────────────────────────────

}
