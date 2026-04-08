<?php
/**
 * Nibbly CMS - Initial Setup Wizard
 * Creates config.php on first installation.
 * Self-disabling: redirects to login once config.php exists.
 */

// Already configured? Redirect to login
if (file_exists(__DIR__ . '/config.php')) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/lang/i18n.php';

$error = '';
$success = false;

// Password strength check (same rules as index.php)
function isPasswordWeak($password) {
    if (strlen($password) < 8) return true;
    if (!preg_match('/[A-Z]/', $password)) return true;
    if (!preg_match('/[a-z]/', $password)) return true;
    if (!preg_match('/[0-9]/', $password)) return true;
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return true;
    return false;
}

// Languages available for selection in setup
$setupLanguages = [
    'de' => 'Deutsch',
    'en' => 'English',
    'fr' => 'Français',
    'es' => 'Español',
    'it' => 'Italiano',
    'pt' => 'Português',
    'nl' => 'Nederlands',
    'pl' => 'Polski',
    'cs' => 'Čeština',
    'da' => 'Dansk',
    'sv' => 'Svenska',
    'no' => 'Norsk',
    'fi' => 'Suomi',
    'hu' => 'Magyar',
    'ro' => 'Română',
    'hr' => 'Hrvatski',
    'sk' => 'Slovenčina',
    'sl' => 'Slovenščina',
    'bg' => 'Български',
    'el' => 'Ελληνικά',
    'tr' => 'Türkçe',
    'ru' => 'Русский',
    'uk' => 'Українська',
    'ja' => '日本語',
    'zh' => '中文',
    'ko' => '한국어',
    'ar' => 'العربية',
];

/**
 * Generate starter site files after config is created.
 * Creates: language dirs, homepage + demo page templates, starter content,
 * nav-config, settings, news posts, .htaccess update.
 */
function generateStarterSite($primaryLang, $secondaryLang, $siteName, $setupLanguages) {
    require_once __DIR__ . '/starter-content.php';

    $root = dirname(__DIR__);
    $languages = [$primaryLang];
    if (!empty($secondaryLang)) $languages[] = $secondaryLang;

    $i18n = getStarterI18n($siteName);
    $contentDir = $root . '/content/pages';
    if (!is_dir($contentDir)) @mkdir($contentDir, 0755, true);
    $newsDir = $root . '/content/news';
    if (!is_dir($newsDir)) @mkdir($newsDir, 0755, true);

    // 1. Create language directories + page templates
    foreach ($languages as $lang) {
        $dir = $root . '/' . $lang;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $t = $i18n[$lang] ?? $i18n['en'];

        // Homepage template (renders sections from JSON)
        $homeTpl = <<<'PHPTPL'
<?php
$pageTitle = '{SITE_NAME}';
$pageDescription = '';
$currentLang = '{LANG}';
$currentPage = 'home';
$contentPage = '{LANG}_home';
if (!isset($basePath)) $basePath = '../';

$_includeBase = dirname(__DIR__) . '/';

include $_includeBase . 'includes/header.php';
include $_includeBase . 'includes/content-loader.php';
?>

    <main class="main-content">
        <div class="content-inner">
            <?php echo renderAllSections('{LANG}_home'); ?>
        </div>
    </main>

<?php include $_includeBase . 'includes/footer.php'; ?>
PHPTPL;
        $homeTpl = str_replace(['{SITE_NAME}', '{LANG}'], [$siteName, $lang], $homeTpl);
        file_put_contents($dir . '/index.php', $homeTpl);

        // Components page template (custom layout with render functions)
        $compTpl = getComponentsTemplate($lang, $t['comp_title']);
        $compTpl = str_replace(['{TITLE}', '{LANG}'], [$t['comp_title'], $lang], $compTpl);
        file_put_contents($dir . '/components.php', $compTpl);

        // News listing template
        $newsTpl = getNewsTemplate($lang, $t['news_page_title'], $t['news_intro']);
        $newsTpl = str_replace(['{TITLE}', '{INTRO}', '{LANG}'], [$t['news_page_title'], $t['news_intro'], $lang], $newsTpl);
        file_put_contents($dir . '/news.php', $newsTpl);

        // News post detail template
        $newsPath = ($lang === $primaryLang) ? 'news' : $lang . '/news';
        $newsPostTpl = getNewsPostTemplate($lang, $t['news_back']);
        $newsPostTpl = str_replace(['{LANG}', '{NEWS_PATH}', '{BACK_LABEL}'], [$lang, $newsPath, $t['news_back']], $newsPostTpl);
        file_put_contents($dir . '/news-post.php', $newsPostTpl);

        // 2. Create content JSON files
        // Homepage
        $homeContent = getHomeContent($lang, $siteName, $t);
        file_put_contents($contentDir . '/' . $lang . '_home.json', json_encode($homeContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // About page (JSON only — front controller serves it)
        $aboutContent = getAboutContent($lang, $t);
        file_put_contents($contentDir . '/' . $lang . '_about.json', json_encode($aboutContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Block types demo (JSON only — front controller serves it)
        $blocksContent = getBlocksContent($lang, $t);
        file_put_contents($contentDir . '/' . $lang . '_blocks.json', json_encode($blocksContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Components page (JSON data for the PHP template)
        $compContent = getComponentsContent($lang, $t);
        file_put_contents($contentDir . '/' . $lang . '_components.json', json_encode($compContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Contact page (JSON only — front controller serves it + includes contact form)
        $contactSlug = $lang === 'de' ? 'kontakt' : ($lang === 'es' ? 'contacto' : 'contact');
        $contactTitle = $lang === 'de' ? 'Kontakt' : ($lang === 'es' ? 'Contacto' : 'Contact');
        $contactHeadingText = $lang === 'de' ? 'Kontakt aufnehmen' : ($lang === 'es' ? 'Ponte en contacto' : 'Get in Touch');
        $contactSubtitle = $lang === 'de' ? 'Wir freuen uns auf Ihre Nachricht' : ($lang === 'es' ? 'Nos encantaría saber de ti' : 'We\'d love to hear from you');
        $contactIntro = $lang === 'de' ? '<p>Haben Sie eine Frage, Feedback oder möchten Sie einfach Hallo sagen? Füllen Sie das Formular aus und wir melden uns so schnell wie möglich bei Ihnen.</p>' : ($lang === 'es' ? '<p>¿Tienes una pregunta, comentario o simplemente quieres saludar? Rellena el formulario y te responderemos lo antes posible.</p>' : '<p>Have a question, feedback, or just want to say hello? Fill out the form below and we\'ll get back to you as soon as possible.</p>');
        $contactContent = [
            'page' => $lang . '_' . $contactSlug,
            'lang' => $lang,
            'title' => $contactTitle,
            'description' => $contactTitle,
            'sections' => [
                ['id' => 's1', 'type' => 'heading', 'text' => $contactHeadingText, 'level' => 'h1', 'subtitle' => $contactSubtitle],
                ['id' => 's2', 'type' => 'text', 'title' => '', 'content' => $contactIntro],
            ],
        ];
        file_put_contents($contentDir . '/' . $lang . '_' . $contactSlug . '.json', json_encode($contactContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 3. Create demo news posts
        $posts = getNewsPosts($lang, $t);
        foreach ($posts as $post) {
            file_put_contents($newsDir . '/' . $post['filename'], json_encode($post['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    // 4. Create footer.json
    $footerData = [
        'page' => 'footer',
        'lastModified' => null,
        'tagline' => [],
        'services' => [],
        'claim' => [],
        'contact' => ['phone' => '', 'email' => ''],
        'credit' => ['text' => '', 'link' => '', 'linkText' => ''],
        'contactHeading' => [],
        'copyright' => '&copy; ' . date('Y') . ' ' . htmlspecialchars($siteName),
    ];
    foreach ($languages as $lang) {
        $footerData['tagline'][$lang] = $siteName;
        $footerData['services'][$lang] = '';
        $footerData['claim'][$lang] = '';
        $footerData['contactHeading'][$lang] = $lang === 'de' ? 'Kontakt' : ($lang === 'es' ? 'Contacto' : 'Contact');
    }
    file_put_contents($root . '/content/pages/footer.json', json_encode($footerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 4b. Create menus.json (menu registry)
    $menusData = ['menus' => [
        'header' => ['label' => [], 'weight' => 0],
        'footer-pages' => ['label' => [], 'weight' => 10],
        'footer-legal' => ['label' => [], 'weight' => 20],
    ]];
    foreach ($languages as $lang) {
        $menusData['menus']['header']['label'][$lang] = $lang === 'de' ? 'Kopfzeile' : ($lang === 'es' ? 'Encabezado' : 'Header');
        $menusData['menus']['footer-pages']['label'][$lang] = $lang === 'de' ? 'Seiten' : ($lang === 'es' ? 'Páginas' : 'Pages');
        $menusData['menus']['footer-legal']['label'][$lang] = $lang === 'de' ? 'Rechtliches' : 'Info';
    }
    file_put_contents($root . '/content/menus.json', json_encode($menusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 5. Create nav-config.php (includes all demo pages)
    file_put_contents($root . '/includes/nav-config.php', getNavConfig($languages, $primaryLang, $setupLanguages));

    // 6. Create settings.json
    $settings = [
        'branding' => [
            'logo' => '/assets/images/favicon.svg',
            'name' => $siteName,
            'showBranding' => true,
        ],
        'theme' => [
            'adminTheme' => 'light',
            'primaryColor' => '#2563eb',
            'accentColor' => '#60a5fa',
        ],
        'email' => [
            'method' => 'inactive',
            'recipientEmail' => '',
            'fromEmail' => '',
            'fromName' => '',
            'smtpHost' => '',
            'smtpPort' => 587,
            'smtpUsername' => '',
            'smtpPassword' => '',
            'smtpEncryption' => 'tls',
        ],
    ];
    file_put_contents($root . '/content/settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 7. Create starter events
    $starterEvents = getStarterEvents($languages);
    file_put_contents($root . '/content/events.json', json_encode($starterEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 8. Create demo contact messages
    $starterMails = getStarterMails();
    file_put_contents($root . '/content/mails.json', json_encode($starterMails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 9. Update .htaccess primary language if not 'en'
    $htaccessPath = $root . '/.htaccess';
    if ($primaryLang !== 'en' && file_exists($htaccessPath)) {
        $htaccess = file_get_contents($htaccessPath);
        $htaccess = str_replace(
            ['%{DOCUMENT_ROOT}/en/%1.php', 'en/$1.php [L]'],
            ['%{DOCUMENT_ROOT}/' . $primaryLang . '/%1.php', $primaryLang . '/$1.php [L]'],
            $htaccess
        );
        file_put_contents($htaccessPath, $htaccess);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteName = trim($_POST['site_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $primaryLang = $_POST['primary_lang'] ?? 'en';
    $secondaryLang = $_POST['secondary_lang'] ?? '';

    // Validate language selections
    if (!isset($setupLanguages[$primaryLang])) {
        $primaryLang = 'en';
    }
    if (!empty($secondaryLang) && !isset($setupLanguages[$secondaryLang])) {
        $secondaryLang = '';
    }
    if ($secondaryLang === $primaryLang) {
        $secondaryLang = '';
    }

    // Validation
    if (empty($siteName)) {
        $error = t('setup.error_site_name');
    } elseif (empty($username)) {
        $error = t('setup.error_username');
    } elseif (strlen($username) < 3) {
        $error = t('setup.error_username_short');
    } elseif (empty($password)) {
        $error = t('setup.error_password');
    } elseif ($password !== $passwordConfirm) {
        $error = t('setup.error_password_mismatch');
    } elseif (isPasswordWeak($password)) {
        $error = t('setup.error_password_weak');
    } else {
        // Generate hash and write config
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $escapedSiteName = addcslashes($siteName, "'\\");

        // Build $SITE_LANGUAGES array
        $languages = [$primaryLang => $setupLanguages[$primaryLang]];
        if (!empty($secondaryLang)) {
            $languages[$secondaryLang] = $setupLanguages[$secondaryLang];
        }

        // Build languages PHP code
        $langLines = [];
        foreach ($languages as $code => $name) {
            $langLines[] = "    '" . $code . "' => '" . addcslashes($name, "'\\") . "'";
        }
        $langArrayCode = implode(",\n", $langLines);

        $configContent = <<<CONFIGTPL
<?php
/**
 * Nibbly CMS - Configuration
 * Generated by Setup Wizard.
 * User accounts are managed in content/users.json.
 */

// ============================================================
// VERSION
// ============================================================

define('NIBBLY_VERSION', '1.0.0');

// ============================================================
// SITE SETTINGS
// ============================================================

define('SITE_NAME', '{$escapedSiteName}');

// ============================================================
// LANGUAGES
// ============================================================
// Primary language: pages live at root (no URL prefix).
// Additional languages: pages live under /{code}/ (e.g. /en/).

define('SITE_LANG_DEFAULT', '{$primaryLang}');

\$SITE_LANGUAGES = [
{$langArrayCode},
];

// ============================================================
// PATHS (relative to webroot)
// ============================================================

define('CONTENT_PATH', __DIR__ . '/../content/pages/');
define('PAGES_TRASH_PATH', __DIR__ . '/../content/pages-trash/');
define('BACKUP_PATH', __DIR__ . '/../backups/');
define('EVENTS_PATH', __DIR__ . '/../content/events.json');
define('IMAGES_PATH', __DIR__ . '/../assets/images/');
define('IMAGES_TRASH_PATH', __DIR__ . '/../assets/images-trash/');
define('AUDIO_PATH', __DIR__ . '/../assets/audio/');
define('AUDIO_TRASH_PATH', __DIR__ . '/../assets/audio-trash/');
define('SETTINGS_PATH', __DIR__ . '/../content/settings.json');
define('USERS_PATH', __DIR__ . '/../content/users.json');

// ============================================================
// LIMITS
// ============================================================

define('MAX_BACKUPS', 30);
define('SESSION_LIFETIME', 3600); // 1 hour
CONFIGTPL;

        $result = file_put_contents(__DIR__ . '/config.php', $configContent, LOCK_EX);

        if ($result === false) {
            $error = t('setup.error_write');
        } else {
            // Create users.json with the first admin user
            require_once __DIR__ . '/users.php';
            $usersData = [
                'users' => [
                    [
                        'id' => 'u_' . bin2hex(random_bytes(5)),
                        'username' => $username,
                        'email' => $email,
                        'role' => 'admin',
                        'passwordHash' => $hash,
                        'createdAt' => gmdate('c'),
                        'createdBy' => 'setup',
                        'lastLogin' => null,
                        'resetToken' => null,
                        'resetTokenExpiry' => null,
                    ]
                ]
            ];
            saveUsers($usersData);

            // Generate starter site files
            generateStarterSite($primaryLang, $secondaryLang, $siteName, $setupLanguages);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo t('setup.page_title'); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <div class="login-container setup-container">
        <?php if ($success): ?>
            <div class="login-logo">
                <img src="../assets/images/favicon.svg" alt="" width="40" height="40">
            </div>
            <h1><?php echo t('setup.complete_title'); ?></h1>
            <div class="success-message">
                <?php echo t('setup.complete_message'); ?>
            </div>
            <p class="site-name">
                <?php echo t('setup.complete_login_hint'); ?>
            </p>
            <a href="index.php" class="btn btn-primary btn-block"><?php echo t('setup.go_to_login'); ?></a>
        <?php else: ?>
            <div class="login-logo">
                <img src="../assets/images/favicon.svg" alt="" width="40" height="40">
            </div>
            <h1><?php echo t('setup.title'); ?></h1>
            <p class="site-name"><?php echo t('setup.subtitle'); ?></p>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" action="" id="setupForm">
                <div class="form-group">
                    <label for="site_name"><?php echo t('setup.site_name'); ?></label>
                    <input type="text" id="site_name" name="site_name" required
                           value="<?php echo htmlspecialchars($_POST['site_name'] ?? ''); ?>"
                           placeholder="<?php echo t('setup.site_name_placeholder'); ?>">
                </div>

                <div class="setup-lang-row">
                    <div class="form-group">
                        <label for="primary_lang"><?php echo t('setup.primary_language'); ?></label>
                        <select id="primary_lang" name="primary_lang">
                            <?php foreach ($setupLanguages as $code => $name): ?>
                            <option value="<?php echo $code; ?>"<?php echo ($code === ($_POST['primary_lang'] ?? 'en')) ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?> (<?php echo $code; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint"><?php echo t('setup.primary_language_hint'); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="secondary_lang"><?php echo t('setup.additional_language'); ?></label>
                        <select id="secondary_lang" name="secondary_lang">
                            <option value=""><?php echo t('setup.additional_language_none'); ?></option>
                            <?php foreach ($setupLanguages as $code => $name): ?>
                            <option value="<?php echo $code; ?>"<?php echo ($code === ($_POST['secondary_lang'] ?? 'de')) ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?> (<?php echo $code; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint"><?php echo t('setup.additional_language_hint'); ?></small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="username"><?php echo t('setup.admin_username'); ?></label>
                    <input type="text" id="username" name="username" required
                           value="<?php echo htmlspecialchars($_POST['username'] ?? 'admin'); ?>"
                           autocomplete="username">
                </div>

                <div class="form-group">
                    <label for="email"><?php echo t('setup.admin_email'); ?></label>
                    <input type="email" id="email" name="email"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           placeholder="<?php echo t('setup.admin_email_placeholder'); ?>"
                           autocomplete="email">
                    <small class="form-hint"><?php echo t('setup.admin_email_hint'); ?></small>
                </div>

                <div class="form-group">
                    <label for="password"><?php echo t('setup.password'); ?></label>
                    <div class="password-field-row">
                        <input type="password" id="password" name="password" required
                               autocomplete="new-password">
                        <button type="button" class="btn btn-secondary btn-sm" id="generatePwBtn" title="<?php echo t('setup.generate'); ?>"><?php echo t('setup.generate'); ?></button>
                    </div>
                    <div class="generated-password" id="generatedPw" style="display: none;">
                        <code id="generatedPwText"></code>
                        <button type="button" class="btn-copy" id="copyPwBtn" title="<?php echo t('setup.copy'); ?>" data-label-copy="<?php echo t('setup.copy'); ?>" data-label-copied="<?php echo t('setup.copied'); ?>"><?php echo t('setup.copy'); ?></button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password_confirm"><?php echo t('setup.confirm_password'); ?></label>
                    <input type="password" id="password_confirm" name="password_confirm" required
                           autocomplete="new-password">
                </div>

                <div class="password-requirements password-requirements--compact" id="passwordReqs">
                    <small><?php echo t('settings.pw_requirements'); ?></small>
                    <div class="requirement-tags">
                        <span class="requirement" data-req="length"><?php echo t('setup.pw_length'); ?></span>
                        <span class="requirement" data-req="upper"><?php echo t('setup.pw_upper'); ?></span>
                        <span class="requirement" data-req="lower"><?php echo t('setup.pw_lower'); ?></span>
                        <span class="requirement" data-req="digit"><?php echo t('setup.pw_digit'); ?></span>
                        <span class="requirement" data-req="special"><?php echo t('setup.pw_special'); ?></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="setupBtn"><?php echo t('setup.create_config'); ?></button>
            </form>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        // Language dropdowns: prevent selecting same language for both
        var primaryLang = document.getElementById('primary_lang');
        var secondaryLang = document.getElementById('secondary_lang');

        if (primaryLang && secondaryLang) {
            function updateSecondaryOptions() {
                var primaryVal = primaryLang.value;
                var options = secondaryLang.querySelectorAll('option');
                for (var i = 0; i < options.length; i++) {
                    if (options[i].value === '' ) continue;
                    options[i].disabled = (options[i].value === primaryVal);
                }
                if (secondaryLang.value === primaryVal) {
                    secondaryLang.value = '';
                }
            }
            primaryLang.addEventListener('change', updateSecondaryOptions);
            updateSecondaryOptions();
        }

        var pw = document.getElementById('password');
        var pwConfirm = document.getElementById('password_confirm');
        if (!pw) return;

        var reqs = {
            length:  function(v) { return v.length >= 8; },
            upper:   function(v) { return /[A-Z]/.test(v); },
            lower:   function(v) { return /[a-z]/.test(v); },
            digit:   function(v) { return /[0-9]/.test(v); },
            special: function(v) { return /[^A-Za-z0-9]/.test(v); }
        };

        function updateReqs() {
            var val = pw.value;
            for (var key in reqs) {
                var el = document.querySelector('[data-req="' + key + '"]');
                if (el) {
                    if (reqs[key](val)) {
                        el.classList.add('met');
                        el.classList.remove('unmet');
                    } else {
                        el.classList.remove('met');
                        el.classList.add('unmet');
                    }
                }
            }
        }

        pw.addEventListener('input', updateReqs);

        // Generate secure random password
        function generatePassword() {
            var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
            var lower = 'abcdefghjkmnpqrstuvwxyz';
            var digits = '23456789';
            var special = '!@#$%&*+-=?';
            var all = upper + lower + digits + special;

            // Ensure at least one of each category
            var password = '';
            var arr = new Uint32Array(20);
            crypto.getRandomValues(arr);

            password += upper[arr[0] % upper.length];
            password += lower[arr[1] % lower.length];
            password += digits[arr[2] % digits.length];
            password += special[arr[3] % special.length];

            for (var i = 4; i < 16; i++) {
                password += all[arr[i] % all.length];
            }

            // Shuffle
            var a = password.split('');
            var shuffleArr = new Uint32Array(a.length);
            crypto.getRandomValues(shuffleArr);
            for (var j = a.length - 1; j > 0; j--) {
                var k = shuffleArr[j] % (j + 1);
                var tmp = a[j]; a[j] = a[k]; a[k] = tmp;
            }
            return a.join('');
        }

        var genBtn = document.getElementById('generatePwBtn');
        var genDisplay = document.getElementById('generatedPw');
        var genText = document.getElementById('generatedPwText');
        var copyBtn = document.getElementById('copyPwBtn');

        genBtn.addEventListener('click', function() {
            var generated = generatePassword();
            pw.value = generated;
            pwConfirm.value = generated;
            pw.type = 'text';
            pwConfirm.type = 'text';

            genText.textContent = generated;
            genDisplay.style.display = 'flex';

            updateReqs();

            // Revert to password fields after 30 seconds
            setTimeout(function() {
                pw.type = 'password';
                pwConfirm.type = 'password';
            }, 30000);
        });

        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                navigator.clipboard.writeText(genText.textContent).then(function() {
                    copyBtn.textContent = copyBtn.dataset.labelCopied;
                    setTimeout(function() { copyBtn.textContent = copyBtn.dataset.labelCopy; }, 2000);
                });
            });
        }
    })();
    </script>
</body>
</html>
