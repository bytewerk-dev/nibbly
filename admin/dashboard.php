<?php
define('NIBBLY_DASHBOARD', true);
define('NIBBLY_DASHBOARD_DIR', __DIR__);
/**
 * Admin Dashboard - Content Editor
 */

require_once 'config.php';
require_once __DIR__ . '/../includes/session-helper.php';
nibblySessionStart();
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/lang/i18n.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/../includes/asset-helpers.php';
require_once __DIR__ . '/../includes/ai/ai-helper.php';
ensureUsersFile();

// Validate account changes and session timeout on every request.
if (!nibblySessionValidate()) {
    header('Location: index.php?timeout=' . time());
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$csrfToken = $_SESSION['csrf_token'];
$userRole = $_SESSION['admin_role'] ?? 'editor';
$isAdminUser = ($userRole === 'admin');

// Load settings for theme
$_defaultFavicon = defined('NIBBLY_DEFAULT_FAVICON') ? NIBBLY_DEFAULT_FAVICON : '/assets/images/favicon.svg';
$siteSettings = ['favicon' => $_defaultFavicon, 'favicon_png' => '', 'branding' => ['logo' => '', 'logoDark' => '', 'adminLogo' => '', 'name' => '', 'showBranding' => true, 'logoDisplay' => 'both', 'logoSize' => 'medium'], 'theme' => ['adminTheme' => 'light', 'primaryColor' => '#3858e9', 'accentColor' => '#3858e9', 'sidebarBg' => '', 'darkPrimaryColor' => '', 'darkAccentColor' => '', 'darkSidebarBg' => '', 'buttonGlow' => true, 'buttonRadius' => 6], 'modules' => ['ai' => true, 'news' => true, 'events' => true, 'messages' => true, 'iconManager' => true], 'dashboard' => ['itemsPerPage' => 50, 'iconManagerItemsPerPage' => 50, 'mediaItemsPerPage' => 25]];
if (defined('SETTINGS_PATH') && file_exists(SETTINGS_PATH)) {
    $loadedSettings = json_decode(file_get_contents(SETTINGS_PATH), true);
    if (is_array($loadedSettings)) {
        foreach ($siteSettings as $key => $defaults) {
            if (isset($loadedSettings[$key]) && is_array($loadedSettings[$key])) {
                $siteSettings[$key] = array_replace($defaults, $loadedSettings[$key]);
            }
        }
        // Preserve top-level keys (favicon, favicon_png)
        if (!empty($loadedSettings['favicon'])) $siteSettings['favicon'] = $loadedSettings['favicon'];
        if (!empty($loadedSettings['favicon_png'])) $siteSettings['favicon_png'] = $loadedSettings['favicon_png'];
    }
}
$adminTheme = $siteSettings['theme']['adminTheme'] ?? 'light';
$dashboardModules = array_replace(['news' => true, 'events' => true, 'messages' => true, 'iconManager' => true, 'ai' => true], is_array($siteSettings['modules'] ?? null) ? $siteSettings['modules'] : []);
$aiFeaturesEnabled = !empty($dashboardModules['ai']);
$aiDashboardVisible = $aiFeaturesEnabled;
$_aiDashboardPublicSettings = $aiFeaturesEnabled && function_exists('nibblyAiLoadSettings') ? nibblyAiLoadSettings(true) : [];
$_aiDashboardCopilotAvailable = $aiFeaturesEnabled
    && !empty($_aiDashboardPublicSettings['enabled'])
    && !empty($_aiDashboardPublicSettings['hasApiKey'])
    && !empty($_aiDashboardPublicSettings['features']['backendAssistant'])
    && (!isset($_aiDashboardPublicSettings['assistantSurfaces']['dashboard']) || !empty($_aiDashboardPublicSettings['assistantSurfaces']['dashboard']));
$validDashboardTabs = ['home', 'content', 'settings'];
if (!empty($dashboardModules['news'])) $validDashboardTabs[] = 'news';
if (!empty($dashboardModules['events'])) $validDashboardTabs[] = 'events';
if (!empty($dashboardModules['messages'])) $validDashboardTabs[] = 'mails';
if ($isAdminUser) $validDashboardTabs[] = 'media';
if ($isAdminUser && !empty($dashboardModules['iconManager'])) $validDashboardTabs[] = 'icons';
if ($isAdminUser) $validDashboardTabs[] = 'backup';
if ($isAdminUser) $validDashboardTabs[] = 'system';

// SVG icon helper — keeps inline SVG paths in one place
function nbIcon(string $name, int $size = 16, string $strokeWidth = '1.5'): string {
    static $paths = [
        'hamburger' => '<path d="M3 12h18M3 6h18M3 18h18"/>',
        'home'      => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
        'edit'      => '<path d="M12 20h9M16.5 3.5a2.121 2.121 0 113 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'mail'      => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 6l-10 7L2 6"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'image'     => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
        'icons'     => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'settings'  => '<path d="M12.22 2h-.44a2 2 0 00-2 2v.18a2 2 0 01-1 1.73l-.43.25a2 2 0 01-2 0l-.15-.08a2 2 0 00-2.73.73l-.22.38a2 2 0 00.73 2.73l.15.1a2 2 0 011 1.72v.51a2 2 0 01-1 1.74l-.15.09a2 2 0 00-.73 2.73l.22.38a2 2 0 002.73.73l.15-.08a2 2 0 012 0l.43.25a2 2 0 011 1.73V20a2 2 0 002 2h.44a2 2 0 002-2v-.18a2 2 0 011-1.73l.43-.25a2 2 0 012 0l.15.08a2 2 0 002.73-.73l.22-.39a2 2 0 00-.73-2.73l-.15-.08a2 2 0 01-1-1.74v-.5a2 2 0 011-1.74l.15-.09a2 2 0 00.73-2.73l-.22-.38a2 2 0 00-2.73-.73l-.15.08a2 2 0 01-2 0l-.43-.25a2 2 0 01-1-1.73V4a2 2 0 00-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
        'trash'     => '<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/>',
        'back'      => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'undo'      => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 00-9-9 9 9 0 00-6.69 3L3 13"/>',
        'redo'      => '<path d="M21 7v6h-6"/><path d="M3 17a9 9 0 019-9 9 9 0 016.69 3L21 13"/>',
        'news'      => '<path d="M4 22h16a2 2 0 002-2V4a2 2 0 00-2-2H8a2 2 0 00-2 2v16a2 2 0 01-2 2zm0 0a2 2 0 01-2-2v-9c0-1.1.9-2 2-2h2"/><line x1="10" y1="6" x2="18" y2="6"/><line x1="10" y1="10" x2="18" y2="10"/><line x1="10" y1="14" x2="14" y2="14"/>',
        'download'  => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'upload'    => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'alert'     => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'refresh'   => '<path d="M21 12a9 9 0 11-2.64-6.36"/><path d="M21 3v6h-6"/>',
        'ai'        => '<path d="M12 2l1.6 5.2L19 9l-5.4 1.8L12 16l-1.6-5.2L5 9l5.4-1.8L12 2z"/><path d="M19 14l.8 2.7L22 17.5l-2.2.8L19 21l-.8-2.7-2.2-.8 2.2-.8L19 14z"/><path d="M5 13l.7 2.1L8 16l-2.3.9L5 19l-.7-2.1L2 16l2.3-.9L5 13z"/>',
    ];
    $p = $paths[$name] ?? '';
    return "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"{$strokeWidth}\" stroke-linecap=\"round\" stroke-linejoin=\"round\" width=\"{$size}\" height=\"{$size}\">{$p}</svg>";
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(_nbAdminLang()); ?>" data-site-theme="<?php echo htmlspecialchars($adminTheme === 'system' ? 'light' : $adminTheme); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Dashboard - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Admin'; ?></title>
    <?php
    $_dashFavicon = !empty($siteSettings['favicon']) ? $siteSettings['favicon'] : $_defaultFavicon;
    $_dashFaviconType = pathinfo($_dashFavicon, PATHINFO_EXTENSION) === 'svg' ? 'image/svg+xml' : 'image/png';
    ?>
    <link rel="icon" href="<?php echo htmlspecialchars($_dashFavicon); ?>" type="<?php echo $_dashFaviconType; ?>">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__ . '/style.css') ?: time(); ?>">
    <link rel="stylesheet" href="../css/image-manager.css?v=<?php echo @filemtime(__DIR__ . '/../css/image-manager.css') ?: time(); ?>">
    <link rel="stylesheet" href="../css/nb-select.css?v=<?php echo @filemtime(__DIR__ . '/../css/nb-select.css') ?: time(); ?>">
    <?php if ($_aiDashboardCopilotAvailable && is_file(__DIR__ . '/../css/ai-copilot.css')): ?>
    <link rel="stylesheet" href="../css/ai-copilot.css?v=<?php echo @filemtime(__DIR__ . '/../css/ai-copilot.css') ?: time(); ?>">
    <?php endif; ?>
    <?php if ($adminTheme === 'system'): ?>
    <script>
    (function() {
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.setAttribute('data-site-theme', 'dark');
        }
    })();
    </script>
    <?php endif; ?>
    <?php
    // Emit a CSS block that mirrors what applyTheme() does in JS, so styles are
    // correct on first paint (no flash). Light values fall back to Dark when the
    // dark-* override is empty.
    $_t = $siteSettings['theme'];
    $_pcLight = htmlspecialchars($_t['primaryColor']);
    $_acLight = htmlspecialchars($_t['accentColor']);
    $_sbLight = !empty($_t['sidebarBg'])
        ? htmlspecialchars($_t['sidebarBg'])
        : "color-mix(in srgb, {$_pcLight} 12%, white)";
    $_pcDark = !empty($_t['darkPrimaryColor']) ? htmlspecialchars($_t['darkPrimaryColor']) : $_pcLight;
    $_acDark = !empty($_t['darkAccentColor']) ? htmlspecialchars($_t['darkAccentColor']) : $_acLight;
    $_sbDark = !empty($_t['darkSidebarBg'])
        ? htmlspecialchars($_t['darkSidebarBg'])
        : "color-mix(in srgb, {$_pcDark} 10%, #0b0d12)";
    ?>
    <style>
    :root,
    [data-site-theme="light"] {
        --nb-primary: <?php echo $_pcLight; ?>;
        --nb-primary-subtle: color-mix(in srgb, <?php echo $_pcLight; ?> 8%, transparent);
        --nb-primary-muted: color-mix(in srgb, <?php echo $_pcLight; ?> 15%, transparent);
        --nb-primary-medium: color-mix(in srgb, <?php echo $_pcLight; ?> 30%, transparent);
        --nb-primary-btn: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcLight; ?> 70%, white) 0%, <?php echo $_pcLight; ?> 70%);
        --nb-primary-btn-hover: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcLight; ?> 50%, white) 0%, <?php echo $_pcLight; ?> 70%);
        --nb-brand: <?php echo $_acLight; ?>;
        --nb-brand-light: color-mix(in srgb, <?php echo $_acLight; ?> 65%, white);
        --nb-brand-dark: color-mix(in srgb, <?php echo $_acLight; ?> 80%, black);
        --nb-brand-subtle: color-mix(in srgb, <?php echo $_acLight; ?> 12%, transparent);
        --nb-sidebar-bg: <?php echo $_sbLight; ?>;
        --nb-bg: color-mix(in srgb, <?php echo $_sbLight; ?> 55%, white);
        --nb-bg-elevated: color-mix(in srgb, <?php echo $_sbLight; ?> 18%, white);
        --nb-bg-sunken: color-mix(in srgb, <?php echo $_sbLight; ?> 82%, white);
        --nb-bg-hover: color-mix(in srgb, <?php echo $_sbLight; ?> 68%, white);
        --nb-border: color-mix(in srgb, <?php echo $_sbLight; ?> 55%, #9ca3af);
        --nb-border-strong: color-mix(in srgb, <?php echo $_sbLight; ?> 48%, #7b8492);
    }
    [data-site-theme="dark"] {
        --nb-primary: <?php echo $_pcDark; ?>;
        --nb-primary-subtle: color-mix(in srgb, <?php echo $_pcDark; ?> 12%, transparent);
        --nb-primary-muted: color-mix(in srgb, <?php echo $_pcDark; ?> 22%, transparent);
        --nb-primary-medium: color-mix(in srgb, <?php echo $_pcDark; ?> 38%, transparent);
        --nb-primary-btn: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcDark; ?> 70%, white) 0%, <?php echo $_pcDark; ?> 70%);
        --nb-primary-btn-hover: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcDark; ?> 50%, white) 0%, <?php echo $_pcDark; ?> 70%);
        --nb-brand: <?php echo $_acDark; ?>;
        --nb-brand-light: color-mix(in srgb, <?php echo $_acDark; ?> 70%, white);
        --nb-brand-dark: color-mix(in srgb, <?php echo $_acDark; ?> 80%, black);
        --nb-brand-subtle: color-mix(in srgb, <?php echo $_acDark; ?> 18%, transparent);
        --nb-sidebar-bg: <?php echo $_sbDark; ?>;
        --nb-bg: color-mix(in srgb, <?php echo $_sbDark; ?> 78%, #202428);
        --nb-bg-elevated: color-mix(in srgb, <?php echo $_sbDark; ?> 58%, #2a2d31);
        --nb-bg-sunken: color-mix(in srgb, <?php echo $_sbDark; ?> 88%, #030405);
        --nb-bg-hover: color-mix(in srgb, <?php echo $_sbDark; ?> 42%, #34383e);
        --nb-border: color-mix(in srgb, <?php echo $_sbDark; ?> 55%, #5a6472);
        --nb-border-strong: color-mix(in srgb, <?php echo $_sbDark; ?> 44%, #717b88);
    }
    </style>
</head>
<body>
    <a class="skip-link" href="#adminMain"><?php echo t('nav.skip_content'); ?></a>
    <header class="admin-topbar">
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <?php echo nbIcon('hamburger', 24, '2'); ?>
        </button>
        <div class="topbar-brand">
            <?php if ($siteSettings['branding']['showBranding']):
                $_topbarFavicon = $siteSettings['branding']['adminLogo'] ?? '';
                if (!$_topbarFavicon) $_topbarFavicon = $siteSettings['favicon'] ?? $_defaultFavicon;
                $_brandName = $siteSettings['branding']['name'] ?? '';
                echo nibblyIconOrImg($_topbarFavicon, $_brandName, ['width' => 24, 'height' => 24, 'class' => 'topbar-logo']);
            endif; ?>
            <span class="topbar-dashboard"><?php echo t('dashboard'); ?></span>
        </div>
        <h1 class="topbar-title" id="topbarTitle"><?php echo t('pages.title'); ?></h1>
        <div class="topbar-selectors" id="topbarSelectors" style="display: none;">
            <select id="langSelect" class="topbar-select">
                <?php
                $siteLanguages = isset($SITE_LANGUAGES) ? $SITE_LANGUAGES : ['de' => 'Deutsch', 'en' => 'English'];
                foreach ($siteLanguages as $code => $name): ?>
                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="pageSelect" class="topbar-select">
                <!-- Populated via JS -->
            </select>
            <button class="btn btn-primary btn-sm" onclick="loadContent()"><?php echo t('btn.load'); ?></button>
        </div>
        <div class="topbar-actions">
            <a href=".." class="topbar-viewsite">
                <?php echo nbIcon('eye'); ?>
                <span><?php echo t('nav.view_site'); ?></span>
            </a>
        </div>
    </header>

    <div class="admin-body">
    <aside class="admin-sidebar" id="adminSidebar" aria-label="Admin navigation">
        <div class="sidebar-top">
            <nav class="sidebar-nav">
                <button class="sidebar-nav-item active" onclick="switchTab('home')" data-tab="home">
                    <?php echo nbIcon('home'); ?>
                    <span><?php echo t('dashboard_home.title'); ?></span>
                </button>
                <button class="sidebar-nav-item" onclick="switchTab('content')" data-tab="content">
                    <?php echo nbIcon('edit'); ?>
                    <span><?php echo t('nav.pages'); ?></span>
                </button>
                <?php if (!empty($dashboardModules['news'])): ?>
                <button class="sidebar-nav-item" onclick="switchTab('news')" data-tab="news">
                    <?php echo nbIcon('news'); ?>
                    <span><?php echo t('nav.news'); ?></span>
                </button>
                <?php endif; ?>
                <?php if (!empty($dashboardModules['events'])): ?>
                <button class="sidebar-nav-item" onclick="switchTab('events')" data-tab="events">
                    <?php echo nbIcon('calendar'); ?>
                    <span><?php echo t('nav.events'); ?></span>
                </button>
                <?php endif; ?>
                <?php if (!empty($dashboardModules['messages'])): ?>
                <button class="sidebar-nav-item" onclick="switchTab('mails')" data-tab="mails">
                    <?php echo nbIcon('mail'); ?>
                    <span><?php echo t('nav.messages'); ?></span>
                    <span class="mail-badge mail-badge--hidden" id="mailBadge">0</span>
                </button>
                <?php endif; ?>
                <?php if ($isAdminUser): ?>
                <button class="sidebar-nav-item" onclick="switchTab('media')" data-tab="media" type="button">
                    <?php echo nbIcon('image'); ?>
                    <span><?php echo t('nav.media_library'); ?></span>
                </button>
                <?php if (!empty($dashboardModules['iconManager'])): ?>
                <button class="sidebar-nav-item" onclick="switchTab('icons')" data-tab="icons">
                    <?php echo nbIcon('icons'); ?>
                    <span><?php echo t('nav.icon_manager'); ?></span>
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </nav>
        </div>
        <div class="sidebar-bottom">
            <button class="sidebar-nav-item" onclick="switchTab('settings')" data-tab="settings">
                <?php echo nbIcon('settings'); ?>
                <span><?php echo t('nav.settings'); ?></span>
            </button>
            <?php if ($isAdminUser): ?>
            <button class="sidebar-nav-item" onclick="switchTab('backup')" data-tab="backup">
                <?php echo nbIcon('download'); ?>
                <span><?php echo t('settings.backup'); ?></span>
            </button>
                <button class="sidebar-nav-item" data-tab="system" onclick="switchTab('system')"><?php echo nbIcon('alert'); ?><span><?php echo t('system.title'); ?></span></button>
            <?php endif; ?>
            <a href="?logout=1" class="sidebar-nav-item sidebar-nav-link sidebar-logout">
                <?php echo nbIcon('logout'); ?>
                <span><?php echo t('nav.logout'); ?></span>
            </a>
            <a class="sidebar-version" href="https://nibbly.dev" target="_blank" rel="noopener noreferrer">nibbly <?php echo htmlspecialchars(nibblyVersion(), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
    </aside>

    <div class="admin-main<?php echo !empty($_SESSION['password_warning']) ? ' has-security-warning' : ''; ?>" id="adminMain">
    <?php
    // Check if current user has no email address (only relevant when email is active)
    $currentUserId = $_SESSION['admin_user_id'] ?? '';
    $currentUserData = $currentUserId ? findUserById($currentUserId) : null;
    $siteSettings = file_exists(SETTINGS_PATH) ? json_decode(file_get_contents(SETTINGS_PATH), true) : [];
    $emailMethod = $siteSettings['email']['method'] ?? 'inactive';
    $emailMissing = $emailMethod !== 'inactive' && $currentUserData && empty($currentUserData['email']);
    ?>
    <?php if ($emailMissing): ?>
    <div class="info-banner info-banner--warning" id="emailWarning">
        <div class="info-banner__inner">
            <strong class="info-banner__title"><?php echo nbIcon('alert'); ?> <?php echo t('settings.email_missing_title'); ?></strong>
            <span class="info-banner__body">
                <?php echo t('settings.email_missing_text'); ?>
                <a href="#" class="info-banner__cta" onclick="switchTab('settings'); document.querySelector('[data-settings-tab=&quot;users&quot;]').click(); return false;"><?php echo t('settings.email_missing_link'); ?> &rarr;</a>
            </span>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['password_warning'])): ?>
    <div class="info-banner info-banner--warning mail-config-banner security-warning-banner" id="passwordWarning">
        <div class="info-banner__inner">
            <span class="info-banner__icon"><?php echo nbIcon('alert'); ?></span>
            <span class="info-banner__content">
                <strong class="info-banner__title"><?php echo t('security.warning'); ?></strong>
                <span class="info-banner__body">
                    <?php echo t('security.weak_password'); ?>
                    <strong><?php echo t('security.change_now'); ?></strong> &mdash; this is a significant security risk.
                </span>
            </span>
            <button type="button" class="info-banner__cta info-banner__cta-button" onclick="switchTab('settings'); document.querySelector('[data-settings-tab=&quot;my-account&quot;]').click(); return false;"><?php echo t('security.change_link'); ?> &rarr;</button>
        </div>
    </div>
    <?php endif; ?>
    <?php
    // One-shot frontend-edit hint: the user reached the dashboard from a public
    // page (via the footer dblclick). Offer a link back to that page so they
    // can keep editing in place. Consumed once — gone on the next dashboard load.
    $loginSourceUrl = $_SESSION['login_source_url'] ?? '';
    unset($_SESSION['login_source_url']);
    ?>
    <?php if ($loginSourceUrl !== ''): ?>
    <div class="info-banner" id="frontendEditBanner">
        <div class="info-banner__inner">
            <strong class="info-banner__title"><?php echo nbIcon('eye'); ?> <?php echo t('banner.frontend_edit_title'); ?></strong>
            <span class="info-banner__body">
                <?php echo t('banner.frontend_edit_text'); ?>
                <a href="<?php echo htmlspecialchars($loginSourceUrl); ?>" class="info-banner__cta"><?php echo t('banner.frontend_edit_cta'); ?> &rarr;</a>
            </span>
            <button type="button" class="info-banner__close" aria-label="<?php echo t('close'); ?>" onclick="document.getElementById('frontendEditBanner').remove();">&times;</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Content Tab -->
    <div class="admin-container" id="contentTab" style="display: none;">
        <!-- Mobile Page Selector (hidden on desktop) -->
        <div class="mobile-selectors" id="mobileSelectors">
            <select id="langSelectMobile" class="topbar-select" onchange="syncSelect('langSelect', this.value)">
                <?php foreach ($siteLanguages as $code => $name): ?>
                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="pageSelectMobile" class="topbar-select">
                <!-- Synced via JS -->
            </select>
            <button class="btn btn-primary btn-sm" onclick="loadContent()"><?php echo t('btn.load'); ?></button>
        </div>

        <!-- Page List -->
        <div class="page-list-container" id="pageListContainer">
            <div class="page-list-header">
                <div class="page-list-header-left">
                    <h2><?php echo t('pages.title'); ?></h2>
                    <button class="btn btn-secondary btn-sm" onclick="showNewPageModal()"><?php echo t('pages.new_page'); ?></button>
                    <button class="btn btn-secondary btn-sm page-list-trash-btn" onclick="showTrash()" id="trashToggle">
                        <?php echo nbIcon('trash', 14); ?>
                        <?php echo t('pages.trash'); ?>
                    </button>
                    <label class="page-list-search" for="pageListSearch">
                        <span class="sr-only"><?php echo t('pages.search_title'); ?></span>
                        <input
                            type="search"
                            id="pageListSearch"
                            class="page-list-search-input"
                            placeholder="Seitentitel suchen..."
                            autocomplete="off"
                            oninput="handlePageListSearch(this.value)"
                        >
                    </label>
                </div>
                <select id="pageListLang" class="topbar-select" onchange="renderPageListForLang(this.value)">
                    <?php foreach ($siteLanguages as $code => $name): ?>
                    <option value="<?php echo htmlspecialchars($code); ?>"<?php if ($code === SITE_LANG_DEFAULT) echo ' selected'; ?>><?php echo htmlspecialchars($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-list-footer admin-list-footer--top" id="pageListFooterTop"></div>
            <div class="page-list-table-wrap">
                <table class="page-list-table" id="pageListTable">
                    <thead>
                        <tr>
                            <th class="page-list-col-title"><?php echo t('pages.col_title'); ?></th>
                            <th class="page-list-col-date"><?php echo t('pages.col_date'); ?></th>
                            <!-- Language columns inserted via JS -->
                        </tr>
                    </thead>
                    <tbody id="pageListBody">
                        <!-- Rows inserted via JS -->
                    </tbody>
                </table>
            </div>
            <div class="admin-list-footer" id="pageListFooter"></div>
        </div>

        <!-- Trash -->
        <div class="page-list-container" id="trashContainer" style="display: none;">
            <div class="page-list-header">
                <div class="page-list-header-left">
                    <h2><?php echo t('trash.title'); ?></h2>
                    <button class="btn btn-secondary btn-sm" onclick="showPageList()">
                        <?php echo nbIcon('back', 14); ?>
                        <?php echo t('pages.back_to_pages'); ?>
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="emptyTrash()" id="emptyTrashBtn" style="display:none;"><?php echo t('trash.empty_trash'); ?></button>
                </div>
            </div>
            <div class="admin-list-footer admin-list-footer--top" id="trashListFooterTop"></div>
            <div class="page-list-table-wrap">
                <table class="page-list-table" id="trashTable">
                    <thead>
                        <tr>
                            <th class="page-list-col-title"><?php echo t('pages.col_title'); ?></th>
                            <th><?php echo t('trash.col_page'); ?></th>
                            <th class="page-list-col-date"><?php echo t('trash.col_deleted'); ?></th>
                            <th class="page-list-col-actions"><?php echo t('trash.col_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="trashBody">
                    </tbody>
                </table>
            </div>
            <div class="admin-list-footer" id="trashListFooter"></div>
            <p class="trash-empty-msg" id="trashEmptyMsg" style="display:none;"><?php echo t('trash.empty'); ?></p>
        </div>

        <!-- Editor -->
        <div class="editor-container" id="editorContainer" style="display: none;">
            <div class="editor-back">
                <a href="#" class="editor-back-link" onclick="showPageList(); return false;">
                    <?php echo nbIcon('back'); ?>
                    <?php echo t('pages.all_pages'); ?>
                </a>
            </div>
            <div class="editor-header">
                <div class="editor-header-left">
                    <h2 id="editorTitle"><?php echo t('editor.title'); ?></h2>
                    <span id="editorSeoHealth" class="editor-seo-health"></span>
                    <button class="btn btn-secondary btn-sm" id="toggleAllBtn" onclick="toggleAllGroups()" style="display:none;"><?php echo t('editor.expand_all'); ?></button>
                </div>
                <div class="editor-header-right">
                    <span class="last-modified" id="lastModified"></span>
                    <div class="editor-undo-redo">
                        <button class="btn btn-secondary btn-sm" id="undoBtn" onclick="editorUndo()" title="<?php echo t('editor.undo'); ?>" disabled><?php echo nbIcon('undo', 14); ?></button>
                        <button class="btn btn-secondary btn-sm" id="redoBtn" onclick="editorRedo()" title="<?php echo t('editor.redo'); ?>" disabled><?php echo nbIcon('redo', 14); ?></button>
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="saveContent()"><?php echo t('btn.save'); ?></button>
                    <a class="btn btn-secondary btn-sm" id="editorViewBtn" href="#" title="<?php echo t('pages.view'); ?>"><?php echo nbIcon('eye', 14); ?></a>
                    <button class="btn btn-secondary btn-sm editor-trash-btn" id="editorTrashBtn" onclick="trashCurrentPage()" title="<?php echo t('editor.move_to_trash'); ?>"><?php echo nbIcon('trash', 14); ?></button>
                </div>
            </div>

            <div id="sectionsContainer">
                <!-- Sections inserted via JS -->
            </div>

            <div style="margin-top: 20px;">
                <button class="btn btn-primary" onclick="saveContent()"><?php echo t('btn.save'); ?></button>
            </div>
        </div>

        <!-- Backups -->
        <div class="backup-container" id="backupContainer" style="display: none;">
            <h3><?php echo t('backups.title'); ?></h3>
            <div class="backup-list" id="backupList">
                <!-- Backups inserted via JS -->
            </div>
        </div>
    </div>

    <!-- Events Tab — list view -->
    <div class="admin-container" id="eventsTab" style="display: none;">
        <div class="page-list-container" id="eventsListView">
            <div class="page-list-header">
                <div class="page-list-header-left">
                    <h2><?php echo t('events.title'); ?></h2>
                    <button class="btn btn-secondary btn-sm" onclick="addNewEvent()"><?php echo t('events.new_event'); ?></button>
                    <button class="btn btn-secondary btn-sm page-list-trash-btn" onclick="showEventsTrash()">
                        <?php echo nbIcon('trash', 14); ?>
                        <?php echo t('pages.trash'); ?>
                    </button>
                </div>
                <span class="last-modified" id="eventsLastModified"></span>
            </div>
            <div class="admin-list-footer admin-list-footer--top" id="eventsListFooterTop"></div>
            <div class="page-list-table-wrap">
                <table class="page-list-table" id="eventsListTable">
                    <thead>
                        <tr>
                            <th class="page-list-col-title"><?php echo t('pages.col_title'); ?></th>
                            <th class="page-list-col-date"><?php echo t('events.col_date'); ?></th>
                            <th><?php echo t('events.col_location'); ?></th>
                            <th class="page-list-col-date"><?php echo t('events.col_status'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="eventsListBody">
                        <!-- Rows inserted via JS -->
                    </tbody>
                </table>
            </div>
            <div class="admin-list-footer" id="eventsListFooter"></div>
        </div>

        <!-- Events Tab — trash view -->
        <div class="page-list-container" id="eventsTrashView" style="display: none;">
            <div class="page-list-header">
                <div class="page-list-header-left">
                    <h2><?php echo t('trash.title'); ?></h2>
                    <button class="btn btn-secondary btn-sm" onclick="closeEventsTrash()">
                        <?php echo nbIcon('back', 14); ?>
                        <?php echo t('events.back_to_events'); ?>
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="emptyEventsTrash()" id="emptyEventsTrashBtn" style="display:none;"><?php echo t('trash.empty_trash'); ?></button>
                </div>
            </div>
            <div class="admin-list-footer admin-list-footer--top" id="eventsTrashFooterTop"></div>
            <div class="page-list-table-wrap">
                <table class="page-list-table" id="eventsTrashTable">
                    <thead>
                        <tr>
                            <th class="page-list-col-title"><?php echo t('pages.col_title'); ?></th>
                            <th class="page-list-col-date"><?php echo t('events.col_date'); ?></th>
                            <th class="page-list-col-date"><?php echo t('trash.col_deleted'); ?></th>
                            <th class="page-list-col-actions"><?php echo t('trash.col_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="eventsTrashBody">
                    </tbody>
                </table>
            </div>
            <div class="admin-list-footer" id="eventsTrashFooter"></div>
            <p class="trash-empty-msg" id="eventsTrashEmptyMsg" style="display:none;"><?php echo t('trash.empty'); ?></p>
        </div>

        <!-- Events Tab — single-event editor view -->
        <div class="editor-container" id="eventsEditorView" style="display: none;">
            <div class="editor-header">
                <div class="editor-header-left">
                    <button class="btn btn-secondary btn-sm" onclick="closeEventEditor()" title="<?php echo t('btn.back'); ?>">
                        <?php echo nbIcon('back', 16); ?>
                        <span><?php echo t('btn.back'); ?></span>
                    </button>
                    <h2 id="eventEditorTitle"></h2>
                </div>
                <div class="editor-header-right">
                    <button class="btn btn-secondary btn-sm" id="eventEditorDeleteBtn" onclick="deleteCurrentEvent()" title="<?php echo t('editor.move_to_trash'); ?>">
                        <?php echo nbIcon('trash', 14); ?>
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="closeEventEditor()"><?php echo t('btn.cancel'); ?></button>
                    <button class="btn btn-primary btn-sm" onclick="saveCurrentEvent()"><?php echo t('btn.save'); ?></button>
                </div>
            </div>
            <div id="eventEditorBody">
                <!-- Editor fields inserted via JS -->
            </div>
        </div>
    </div>

    <!-- News Tab -->
    <div class="admin-container" id="newsTab" style="display: none;">
        <!-- News List -->
        <div class="page-list-container" id="newsListContainer">
            <div class="page-list-header">
                <div class="page-list-header-left">
                    <h2><?php echo t('news.title'); ?></h2>
                    <button class="btn btn-secondary btn-sm" onclick="addNewPost()"><?php echo t('news.new_post'); ?></button>
                </div>
            </div>
            <div class="admin-list-footer admin-list-footer--top" id="newsListFooterTop"></div>
            <div class="page-list-table-wrap">
                <table class="page-list-table" id="newsListTable">
                    <thead>
                        <tr>
                            <th class="page-list-col-title"><?php echo t('pages.col_title'); ?></th>
                            <th class="page-list-col-date"><?php echo t('news.post_date'); ?></th>
                            <?php
                            $langCodes = array_keys($siteLanguages);
                            $otherNewsLangs = array_filter($langCodes, function($c) { return $c !== SITE_LANG_DEFAULT; });
                            foreach ($otherNewsLangs as $code): ?>
                            <th class="page-list-col-lang"><?php echo htmlspecialchars($siteLanguages[$code]); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="newsListBody">
                        <!-- Rows inserted via JS -->
                    </tbody>
                </table>
            </div>
            <div class="admin-list-footer" id="newsListFooter"></div>
        </div>

        <!-- News Post Editor -->
        <div class="editor-container" id="newsEditorContainer" style="display: none;">
            <div class="editor-back">
                <a href="#" class="editor-back-link" onclick="cancelPostEditor(); return false;">
                    <?php echo nbIcon('back'); ?>
                    <?php echo t('news.all_posts'); ?>
                </a>
            </div>
            <div class="editor-header">
                <div class="editor-header-left">
                    <h2 id="newsEditorTitle"><?php echo t('news.new_post'); ?></h2>
                </div>
                <div class="editor-header-right">
                    <span class="last-modified" id="newsLastModified"></span>
                    <button class="btn btn-primary btn-sm" onclick="savePost()"><?php echo t('btn.save'); ?></button>
                    <a class="btn btn-secondary btn-sm" id="newsViewBtn" href="#" style="display:none;" title="<?php echo t('news.view'); ?>"><?php echo nbIcon('eye', 14); ?></a>
                    <button class="btn btn-secondary btn-sm editor-trash-btn" id="newsTrashBtn" onclick="deleteCurrentPost()" style="display:none;" title="<?php echo t('news.delete'); ?>"><?php echo nbIcon('trash', 14); ?></button>
                </div>
            </div>
            <div id="newsEditorForm">
                <!-- Editor form inserted via JS -->
            </div>
        </div>
    </div>

    <!-- Mails Tab -->
    <div class="admin-container" id="mailsTab" style="display: none;">
        <!-- List view -->
        <div class="page-list-container" id="mailsListView">
            <div class="info-banner info-banner--warning mail-config-banner" id="mailConfigBanner" hidden>
                <div class="info-banner__inner">
                    <span class="info-banner__icon"><?php echo nbIcon('alert'); ?></span>
                    <span class="info-banner__content">
                        <strong class="info-banner__title"><span id="mailConfigBannerTitle"></span></strong>
                        <span class="info-banner__body" id="mailConfigBannerText"></span>
                    </span>
                    <button type="button" class="info-banner__cta info-banner__cta-button" onclick="openEmailSettings()"><?php echo t('mails.open_email_settings'); ?> &rarr;</button>
                </div>
            </div>
            <div class="page-list-header page-list-header--mails">
                <div class="page-list-header-left">
                    <div class="page-list-title-stack">
                        <h2><?php echo t('mails.title'); ?></h2>
                        <p><?php echo t('mails.source_hint'); ?></p>
                    </div>
                </div>
                <div class="page-list-header-actions">
                    <select id="mailFormFilter" class="topbar-select mail-form-filter" onchange="setMailFormFilter(this.value)" aria-label="<?php echo htmlspecialchars(t('mails.filter_form'), ENT_QUOTES, 'UTF-8'); ?>">
                        <option value=""><?php echo t('mails.all_forms'); ?></option>
                    </select>
                    <button class="btn btn-secondary btn-sm" onclick="loadMails()"><?php echo t('btn.refresh'); ?></button>
                    <button class="btn btn-secondary btn-sm" onclick="markAllMailsRead()"><?php echo t('mails.mark_all_read'); ?></button>
                    <button class="btn btn-secondary btn-sm" id="deleteReadMailsBtn" onclick="deleteReadMails()" disabled><?php echo t('mails.delete_read'); ?></button>
                </div>
            </div>
            <div class="admin-list-footer admin-list-footer--top" id="mailsListFooterTop"></div>
            <div class="page-list-table-wrap">
                <table class="page-list-table" id="mailsListTable">
                    <thead>
                        <tr>
                            <th class="mail-col-flag page-list-sortable" data-mail-sort="read" onclick="sortMails('read')" title="<?php echo t('mails.sort_read'); ?>">
                                <button type="button" class="mail-header-sort" tabindex="-1" aria-label="<?php echo t('mails.sort_read'); ?>"><span class="page-list-sort-icon"></span></button>
                            </th>
                            <th class="mail-col-flag page-list-sortable" data-mail-sort="starred" onclick="sortMails('starred')" title="<?php echo t('mails.sort_starred'); ?>">
                                <button type="button" class="mail-header-sort" tabindex="-1" aria-label="<?php echo t('mails.sort_starred'); ?>">☆<span class="page-list-sort-icon"></span></button>
                            </th>
                            <th class="page-list-sortable" data-mail-sort="form" onclick="sortMails('form')"><?php echo t('mails.col_form'); ?> <span class="page-list-sort-icon"></span></th>
                            <th class="page-list-col-title page-list-sortable" data-mail-sort="from" onclick="sortMails('from')"><?php echo t('mails.col_from'); ?> <span class="page-list-sort-icon"></span></th>
                            <th class="page-list-sortable" data-mail-sort="subject" onclick="sortMails('subject')"><?php echo t('mails.col_subject'); ?> <span class="page-list-sort-icon"></span></th>
                            <th class="page-list-col-date page-list-sortable" data-mail-sort="received" onclick="sortMails('received')"><?php echo t('mails.col_received'); ?> <span class="page-list-sort-icon"></span></th>
                        </tr>
                    </thead>
                    <tbody id="mailsList">
                        <!-- Mails inserted via JS -->
                    </tbody>
                </table>
            </div>
            <div class="admin-list-footer" id="mailsListFooter"></div>
        </div>

        <!-- Detail view -->
        <div class="editor-container" id="mailDetailView" style="display: none;">
            <div class="editor-header">
                <div class="editor-header-left">
                    <button class="btn btn-secondary btn-sm" onclick="closeMailDetail()" title="<?php echo t('btn.back'); ?>">
                        <?php echo nbIcon('back', 16); ?>
                        <span><?php echo t('btn.back'); ?></span>
                    </button>
                    <h2 id="mailDetailTitle"></h2>
                </div>
                <div class="editor-header-right">
                    <button class="btn btn-danger btn-sm" id="mailDeleteBtn">
                        <?php echo nbIcon('trash', 14); ?>
                        <span><?php echo t('btn.delete'); ?></span>
                    </button>
                </div>
            </div>
            <div class="mail-detail-content" id="mailDetailContent">
                <!-- Detail fields inserted via JS -->
            </div>
        </div>
    </div>

    <?php if ($isAdminUser): ?>
    <!-- Media Library Tab -->
    <div class="admin-container" id="mediaTab" style="display: none;">
        <div class="page-list-container media-library-page">
            <div class="page-list-header">
                <div class="page-list-header-left">
                    <h2><?php echo t('nav.media_library'); ?></h2>
                </div>
            </div>
            <div id="mediaLibraryMount" class="media-library-mount"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Dashboard Home Tab -->
    <div class="admin-container" id="homeTab">
        <div class="dashboard-home">
            <div class="page-list-header">
                <div class="page-list-header-left">
                    <h2><?php echo t('dashboard_home.title'); ?></h2>
                </div>
            </div>

            <div class="dashboard-status-strip" id="dashboardStatusStrip" aria-label="<?php echo htmlspecialchars(t('dashboard_home.site_status'), ENT_QUOTES, 'UTF-8'); ?>"></div>

            <?php if ($aiDashboardVisible): ?>
            <section class="dashboard-section dashboard-section--ai" id="dashboardAiSection" data-ai-module-enabled="<?php echo $aiFeaturesEnabled ? 'true' : 'false'; ?>">
                <div class="dashboard-section-header">
                    <div>
                        <h3><?php echo t('dashboard_home.ai_tools'); ?></h3>
                        <p id="dashboardAiStatus" class="dashboard-status-text"></p>
                    </div>
                    <div class="dashboard-section-actions">
                        <span class="ai-usage-summary" id="aiUsageSummary" hidden></span>
                        <?php if ($isAdminUser): ?>
                        <?php if ($aiFeaturesEnabled): ?>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="switchTab('settings', {settingsTab: 'ai'});"><?php echo t('ai.open_settings'); ?></button>
                        <?php else: ?>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="switchTab('settings', {settingsTab: 'modules'});"><?php echo t('settings.modules_nav'); ?></button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-banner info-banner--warning info-banner--contained" id="aiUnavailableBanner">
                    <div class="info-banner__inner">
                        <strong class="info-banner__title"><?php echo nbIcon('alert'); ?> <?php echo t('ai.not_configured_title'); ?></strong>
                        <span class="info-banner__body">
                            <span id="aiUnavailableText"><?php echo t('ai.not_configured_text'); ?></span>
                            <?php if ($isAdminUser): ?>
                            <?php if ($aiFeaturesEnabled): ?>
                            <button type="button" class="info-banner__cta info-banner__cta-button" onclick="switchTab('settings', {settingsTab: 'ai'});"><?php echo t('ai.open_settings'); ?> &rarr;</button>
                            <?php else: ?>
                            <button type="button" class="info-banner__cta info-banner__cta-button" onclick="switchTab('settings', {settingsTab: 'modules'});"><?php echo t('settings.modules_nav'); ?> &rarr;</button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </span>
                        <button type="button" class="info-banner__close" id="aiUnavailableDismiss" aria-label="<?php echo htmlspecialchars(t('ai.dismiss_notice'), ENT_QUOTES, 'UTF-8'); ?>">&times;</button>
                    </div>
                </div>

                <?php if ($aiFeaturesEnabled): ?>
                <section class="ai-image-jobs-panel" id="aiImageJobsPanel" hidden>
                    <div class="ai-image-jobs-panel__copy">
                        <h4><?php echo t('ai.image_jobs_title'); ?></h4>
                        <p id="aiImageJobsStatus"><?php echo t('ai.image_jobs_idle'); ?></p>
                    </div>
                    <div class="ai-image-jobs-panel__actions">
                        <span class="ai-image-jobs-panel__meta" id="aiImageJobsMeta" hidden></span>
                        <button type="button" class="btn btn-secondary btn-sm" id="aiImageJobsCheck">
                            <?php echo nbIcon('refresh', 14); ?>
                            <span><?php echo t('ai.image_jobs_check'); ?></span>
                        </button>
                    </div>
                </section>
                <div class="ai-grid" id="dashboardAiTools" hidden>
                    <section class="ai-card ai-card--assistant" id="aiAssistantCard" data-ai-feature="backendAssistant" hidden>
                        <h3><?php echo t('ai.assistant'); ?></h3>
                        <div class="ai-chat-log" id="aiChatLog" aria-live="polite"></div>
                        <form id="aiChatForm" class="ai-form">
                            <textarea id="aiChatPrompt" rows="4" placeholder="<?php echo htmlspecialchars(t('ai.assistant_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" disabled></textarea>
                            <div class="ai-chat-actions">
                                <span class="ai-chat-shortcut-hint" data-mac-text="<?php echo htmlspecialchars(t('ai.chat_shortcuts_mac'), ENT_QUOTES, 'UTF-8'); ?>" data-other-text="<?php echo htmlspecialchars(t('ai.chat_shortcuts_ctrl'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo t('ai.chat_shortcuts'); ?></span>
                                <span class="ai-running-indicator" id="aiChatIndicator" hidden><?php echo t('ai.answering'); ?></span>
                                <button type="submit" class="btn btn-primary" onclick="if(document.getElementById('aiChatPrompt')?.value.trim())document.getElementById('aiChatIndicator').hidden=false;" disabled><?php echo t('ai.send'); ?></button>
                            </div>
                        </form>
                    </section>

                    <section class="ai-card ai-card--tools" id="aiToolsCard" hidden>
                        <div class="ai-tool-tabs" role="tablist" aria-label="<?php echo htmlspecialchars(t('dashboard_home.ai_tools'), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="button" class="ai-tool-tab" data-ai-tool-tab="image" data-ai-feature="imageGeneration" onclick="switchAiToolTab('image')" role="tab" aria-selected="false" hidden><?php echo t('ai.image_generator'); ?></button>
                            <button type="button" class="ai-tool-tab" data-ai-tool-tab="text" data-ai-feature="seoTextGeneration" onclick="switchAiToolTab('text')" role="tab" aria-selected="false" hidden><?php echo t('ai.text_generator'); ?></button>
                            <?php if ($isAdminUser): ?>
                            <button type="button" class="ai-tool-tab" data-ai-tool-tab="audit" data-ai-feature="seoTextGeneration" onclick="switchAiToolTab('audit')" role="tab" aria-selected="false" hidden><?php echo t('ai.audit_tab'); ?></button>
                            <?php endif; ?>
                        </div>

                        <div class="ai-tool-panel" id="aiTextToolPanel" data-ai-tool-panel="text" data-ai-feature="seoTextGeneration" role="tabpanel" hidden>
                            <form id="aiTextForm" class="ai-form">
                                <textarea id="aiTextPrompt" rows="6" placeholder="<?php echo htmlspecialchars(t('ai.text_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" disabled></textarea>
                                <div class="form-row-inline">
                                    <div class="form-group">
                                        <label for="aiTextMaxTokens"><?php echo t('ai.max_output_tokens'); ?></label>
                                        <input type="number" id="aiTextMaxTokens" min="64" max="32000" value="700" disabled>
                                    </div>
                                    <div class="form-group ai-form-action">
                                        <button type="submit" class="btn btn-primary" disabled><?php echo t('ai.generate_text'); ?></button>
                                    </div>
                                </div>
                            </form>
                            <textarea id="aiTextResult" class="ai-result" rows="8" readonly></textarea>
                        </div>

                        <?php if ($isAdminUser): ?>
                        <div class="ai-tool-panel" id="aiAuditToolPanel" data-ai-tool-panel="audit" data-ai-feature="seoTextGeneration" role="tabpanel" hidden>
                            <p class="form-hint"><?php echo t('ai.audit_hint'); ?></p>
                            <div class="ai-form">
                                <button type="button" class="btn btn-primary" id="aiAuditRun"><?php echo t('ai.audit_run'); ?></button>
                            </div>
                            <div id="aiAuditResults" class="ai-audit-results" aria-live="polite"></div>
                        </div>
                        <?php endif; ?>

                        <div class="ai-tool-panel" id="aiImageToolPanel" data-ai-tool-panel="image" data-ai-feature="imageGeneration" role="tabpanel" hidden>
                            <form id="aiImageForm" class="ai-form">
                                <div class="ai-image-column ai-image-column--main">
                                    <div class="form-group ai-image-prompt-group">
                                        <label for="aiImagePrompt"><?php echo t('ai.image_prompt'); ?></label>
                                        <textarea id="aiImagePrompt" rows="4" placeholder="<?php echo htmlspecialchars(t('ai.image_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" disabled></textarea>
                                    </div>
                                    <div class="ai-image-command-row">
                                        <div class="ai-reference-control">
                                            <input type="file" id="aiImageReference" accept="image/png,image/jpeg,image/webp" multiple hidden disabled>
                                            <button type="button" class="btn btn-secondary btn-sm" id="aiImageReferenceUpload" disabled><?php echo t('ai.image_reference_upload'); ?></button>
                                            <button type="button" class="btn btn-secondary btn-sm" id="aiImageReferenceLibrary" disabled><?php echo t('ai.image_reference_library'); ?></button>
                                            <span class="ai-reference-name" id="aiImageReferenceName"><?php echo t('ai.image_reference_none'); ?></span>
                                            <button type="button" class="btn btn-secondary btn-sm ai-reference-clear" id="aiImageReferenceClear" hidden><?php echo t('btn.clear'); ?></button>
                                        </div>
                                        <button type="button" class="btn btn-secondary btn-sm" id="aiImproveImagePrompt" disabled><?php echo t('ai.improve_prompt'); ?></button>
                                    </div>
                                    <div class="ai-reference-list" id="aiImageReferenceList" hidden></div>
                                    <div class="ai-image-model-row ai-image-filename-group">
                                        <div class="form-group">
                                            <label for="aiImageFilenameHint"><?php echo t('ai.image_filename'); ?></label>
                                            <div class="ai-image-filename-row">
                                                <input type="text" id="aiImageFilenameHint" class="topbar-select" maxlength="90" placeholder="<?php echo htmlspecialchars(t('ai.image_filename_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                                <button type="button" class="btn btn-secondary btn-sm" id="aiSuggestImageFilename" disabled><?php echo t('ai.image_filename_suggest'); ?></button>
                                            </div>
                                            <small class="form-hint"><?php echo t('ai.image_filename_hint'); ?></small>
                                        </div>
                                    </div>
                                    <div class="ai-image-options-grid ai-image-options-grid--output">
                                        <div class="form-group">
                                            <label for="aiImageFormat"><?php echo t('ai.image_format'); ?></label>
                                            <select id="aiImageFormat" class="topbar-select" disabled>
                                                <option value="png">PNG</option>
                                                <option value="jpeg">JPEG</option>
                                                <option value="webp" selected>WebP</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="aiImageCompression"><?php echo t('ai.image_compression'); ?></label>
                                            <div class="fill-slider" id="aiImageCompressionSlider">
                                                <div class="fill-slider__fill" id="aiImageCompressionFill"></div>
                                                <span class="fill-slider__value" id="aiImageCompressionValue">70%</span>
                                                <input type="range" id="aiImageCompression" min="0" max="100" value="70" class="fill-slider__input" aria-label="<?php echo htmlspecialchars(t('ai.image_compression'), ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="ai-image-column ai-image-column--settings">
                                    <div class="ai-image-model-row ai-image-model-picker-row">
                                        <div class="form-group">
                                            <label for="aiImageModelPicker"><?php echo t('ai.image_model'); ?></label>
                                            <select id="aiImageModelPicker" class="topbar-select" disabled></select>
                                        </div>
                                    </div>
                                    <div class="ai-image-options-grid ai-image-options-grid--primary">
                                        <div class="form-group">
                                            <label for="aiImageSize"><?php echo t('ai.image_size'); ?></label>
                                            <div class="ai-ratio-select ai-size-picker" id="aiImageSizePicker">
                                                <input type="hidden" id="aiImageSize" value="auto" disabled>
                                                <input type="hidden" id="aiImageRatio" value="auto" disabled>
                                                <button type="button" class="ai-size-trigger" id="aiImageSizeTrigger" disabled aria-haspopup="listbox" aria-expanded="false">
                                                    <span class="ai-ratio-icon" id="aiImageRatioIcon" aria-hidden="true"></span>
                                                    <span class="ai-size-trigger-label" id="aiImageSizeLabel"><?php echo t('ai.image_size_auto'); ?></span>
                                                </button>
                                                <div class="ai-size-menu" id="aiImageSizeMenu" role="listbox" hidden></div>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label for="aiImageScale"><?php echo t('ai.image_scale'); ?></label>
                                            <select id="aiImageScale" class="topbar-select" disabled>
                                                <option value="1024">1K</option>
                                                <option value="2048" selected>2K</option>
                                                <option value="3072">3K</option>
                                                <option value="3840">4K</option>
                                            </select>
                                        </div>
                                    </div>
                                    <p class="ai-image-size-note" id="aiImageSizeNote"></p>
                                    <div class="ai-image-options-grid ai-image-options-grid--secondary">
                                        <div class="form-group">
                                            <label for="aiImageQuality"><?php echo t('ai.image_quality'); ?></label>
                                            <select id="aiImageQuality" class="topbar-select" disabled>
                                                <option value="auto"><?php echo t('ai.image_auto'); ?></option>
                                                <option value="low"><?php echo t('ai.image_quality_low'); ?></option>
                                                <option value="medium"><?php echo t('ai.image_quality_medium'); ?></option>
                                                <option value="high"><?php echo t('ai.image_quality_high'); ?></option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="aiImageModeration"><?php echo t('ai.image_moderation'); ?></label>
                                            <select id="aiImageModeration" class="topbar-select" disabled>
                                                <option value="auto"><?php echo t('ai.image_moderation_standard'); ?></option>
                                                <option value="low"><?php echo t('ai.image_moderation_low'); ?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="ai-form-actions ai-image-generate-row">
                                        <div class="ai-image-count-control">
                                            <label for="aiImageCount"><?php echo t('ai.image_count'); ?></label>
                                            <div class="ai-count-stepper">
                                                <input type="number" id="aiImageCount" min="1" max="10" value="1" disabled>
                                                <div class="ai-count-stepper-buttons">
                                                    <button type="button" class="ai-count-step" id="aiImageCountUp" aria-label="<?php echo htmlspecialchars(t('ai.image_count_increase'), ENT_QUOTES, 'UTF-8'); ?>" disabled>+</button>
                                                    <button type="button" class="ai-count-step" id="aiImageCountDown" aria-label="<?php echo htmlspecialchars(t('ai.image_count_decrease'), ENT_QUOTES, 'UTF-8'); ?>" disabled>-</button>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary" id="aiGenerateImageButton" disabled><?php echo t('ai.generate_image'); ?></button>
                                    </div>
                                    <p class="ai-image-model-note" id="aiImageModelNote" hidden><?php echo t('ai.image_model_missing_note'); ?></p>
                                </div>
                            </form>
                            <div class="ai-image-result" id="aiImageResult"></div>
                            <section class="ai-image-history" id="aiImageHistory" hidden>
                                <div class="ai-image-history__header">
                                    <div>
                                        <h4><?php echo t('ai.image_history_title'); ?></h4>
                                        <p><?php echo t('ai.image_history_hint'); ?></p>
                                    </div>
                                    <button type="button" class="btn btn-secondary btn-sm" id="aiImageHistoryClear"><?php echo t('ai.image_history_clear'); ?></button>
                                </div>
                                <div class="ai-image-history-list" id="aiImageHistoryList"></div>
                                <button type="button" class="btn btn-secondary btn-sm ai-image-history-more" id="aiImageHistoryLoadMore" hidden><?php echo t('ai.image_history_load_more'); ?></button>
                            </section>
                        </div>
                    </section>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="dashboard-section dashboard-section--analytics">
                <div class="dashboard-section-header">
                    <div>
                        <h3><?php echo t('dashboard_home.analytics'); ?></h3>
                        <p class="dashboard-status-text"><?php echo t('dashboard_home.analytics_hint'); ?></p>
                    </div>
                    <div class="dashboard-section-actions dashboard-section-actions--stacked">
                        <div class="dashboard-range-tabs" role="tablist" aria-label="<?php echo htmlspecialchars(t('dashboard_home.analytics_range'), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="button" class="dashboard-range-tab active" data-analytics-period="days" data-analytics-count="30" onclick="setDashboardAnalyticsRange('days', 30)"><?php echo t('dashboard_home.range_days'); ?></button>
                            <button type="button" class="dashboard-range-tab" data-analytics-period="months" data-analytics-count="12" onclick="setDashboardAnalyticsRange('months', 12)"><?php echo t('dashboard_home.range_months'); ?></button>
                            <button type="button" class="dashboard-range-tab" data-analytics-period="years" data-analytics-count="0" onclick="setDashboardAnalyticsRange('years', 0)"><?php echo t('dashboard_home.range_years'); ?></button>
                        </div>
                        <div class="dashboard-content-pills" aria-label="<?php echo htmlspecialchars(t('dashboard_home.content_overview'), ENT_QUOTES, 'UTF-8'); ?>">
                            <span><?php echo t('dashboard_home.pages'); ?> <strong id="dashboardPageCount">0</strong></span>
                            <?php if (!empty($dashboardModules['news'])): ?>
                            <span><?php echo t('dashboard_home.news'); ?> <strong id="dashboardNewsCount">0</strong></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="dashboard-stat-grid dashboard-stat-grid--analytics">
                    <section class="dashboard-stat-card">
                        <span><?php echo t('dashboard_home.page_views_today'); ?></span>
                        <strong id="dashboardViewsToday">0</strong>
                    </section>
                    <section class="dashboard-stat-card">
                        <span id="dashboardViewsPeriodLabel"><?php echo t('dashboard_home.page_views_30d'); ?></span>
                        <strong id="dashboardViewsPeriod">0</strong>
                    </section>
                    <section class="dashboard-stat-card">
                        <span id="dashboardVisitorsPeriodLabel"><?php echo t('dashboard_home.visitors_30d'); ?></span>
                        <strong id="dashboardVisitorsPeriod">0</strong>
                    </section>
                    <section class="dashboard-stat-card">
                        <span id="dashboardVisitsPeriodLabel"><?php echo t('dashboard_home.visits_30d'); ?></span>
                        <strong id="dashboardVisitsPeriod">0</strong>
                    </section>
                    <section class="dashboard-stat-card">
                        <span><?php echo t('dashboard_home.bots_filtered'); ?></span>
                        <strong id="dashboardBotCount">0</strong>
                    </section>
                </div>

                <section class="dashboard-panel dashboard-chart-panel">
                    <div class="dashboard-panel-header">
                        <h3><?php echo t('dashboard_home.traffic_curve'); ?></h3>
                        <span id="dashboardChartRangeLabel"><?php echo t('dashboard_home.page_views_30d'); ?></span>
                    </div>
                    <div class="dashboard-chart" id="dashboardTrafficChart" aria-label="<?php echo htmlspecialchars(t('dashboard_home.traffic_curve'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                </section>

                <section class="dashboard-panel dashboard-hour-panel">
                    <div class="dashboard-panel-header">
                        <h3><?php echo t('dashboard_home.hourly_distribution'); ?></h3>
                        <span><?php echo t('dashboard_home.today'); ?></span>
                    </div>
                    <div class="dashboard-hour-chart" id="dashboardHourlyChart" aria-label="<?php echo htmlspecialchars(t('dashboard_home.hourly_distribution'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                </section>

                <div class="dashboard-home-grid dashboard-home-grid--analytics">
                    <section class="dashboard-panel">
                        <h3><?php echo t('dashboard_home.top_pages'); ?></h3>
                        <div class="dashboard-top-pages" id="dashboardTopPages"></div>
                    </section>
                    <section class="dashboard-panel">
                        <h3><?php echo t('dashboard_home.referrers'); ?></h3>
                        <div class="dashboard-top-pages" id="dashboardReferrers"></div>
                    </section>
                </div>

                <details class="dashboard-details">
                    <summary><?php echo t('dashboard_home.analytics_details'); ?></summary>
                    <div class="dashboard-home-grid dashboard-home-grid--details">
                        <section class="dashboard-panel">
                            <h3><?php echo t('dashboard_home.devices'); ?></h3>
                            <div class="dashboard-top-pages" id="dashboardDevices"></div>
                        </section>
                        <section class="dashboard-panel">
                            <h3><?php echo t('dashboard_home.browsers_os'); ?></h3>
                            <div class="dashboard-split-lists">
                                <div id="dashboardBrowsers"></div>
                                <div id="dashboardOs"></div>
                            </div>
                        </section>
                    </div>
                </details>
            </section>
        </div>
    </div>

    <?php if ($isAdminUser): ?>
    <!-- Icon Manager Tab -->
    <div class="admin-container" id="iconsTab" style="display: none;">
        <div class="page-list-container">
            <div class="page-list-header">
                <div class="page-list-header-left">
                    <h2><?php echo t('icons.title'); ?></h2>
                    <button class="btn btn-secondary btn-sm" id="iconManagerRefreshBtn" onclick="loadIconManager()"><?php echo t('btn.refresh'); ?></button>
                    <button class="btn btn-secondary btn-sm" onclick="openIconifyImportModal()"><?php echo t('icons.import_icon'); ?></button>
                    <button class="btn btn-primary btn-sm" onclick="openIconManagerModal()"><?php echo t('icons.add_icon'); ?></button>
                </div>
            </div>
            <div class="icon-manager-toolbar">
                <label class="modal-label"><?php echo t('icons.filter'); ?>
                    <input type="search" id="iconManagerSearch" class="modal-input" placeholder="<?php echo htmlspecialchars(t('icons.filter_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label class="modal-label"><?php echo t('icons.sort'); ?>
                    <select id="iconManagerSort" class="modal-input">
                        <option value="alpha"><?php echo t('icons.sort_alpha'); ?></option>
                        <option value="newest"><?php echo t('icons.sort_newest'); ?></option>
                        <option value="oldest"><?php echo t('icons.sort_oldest'); ?></option>
                    </select>
                </label>
            </div>
            <p class="icon-manager-path" id="iconManagerPath"></p>
            <div class="admin-list-footer admin-list-footer--top admin-list-footer--grid" id="iconManagerFooterTop"></div>
            <div class="icon-manager-grid" id="iconManagerGrid">
                <!-- Icons inserted via JS -->
            </div>
            <div class="admin-list-footer admin-list-footer--grid" id="iconManagerFooter"></div>
            <p class="trash-empty-msg" id="iconManagerEmpty" style="display:none;"><?php echo t('icons.empty'); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Settings Tab -->
    <div class="admin-container" id="settingsTab" style="display: none;">
        <div class="settings-layout">
        <label class="settings-mobile-navigation" for="settingsMobileNav"><?php echo t('settings.title'); ?><select id="settingsMobileNav"></select></label>
        <nav class="settings-tabs" aria-label="<?php echo htmlspecialchars(t('settings.title'), ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($isAdminUser): ?>
            <section class="settings-nav-group">
                <h3><?php echo t('settings.group_site'); ?></h3>
                <button class="settings-tab-btn active" data-settings-tab="branding"><?php echo t('settings.branding'); ?></button>
                <button class="settings-tab-btn" data-settings-tab="theme"><?php echo t('settings.dashboard_design'); ?></button>
                <button class="settings-tab-btn" data-settings-tab="menus"><?php echo t('settings.menus'); ?></button>
                <button class="settings-tab-btn" data-settings-tab="modules"><?php echo t('settings.modules_nav'); ?></button>
                <button class="settings-tab-btn" data-settings-tab="language"><?php echo t('settings.language'); ?></button>
            </section>
            <section class="settings-nav-group">
                <h3><?php echo t('settings.group_features'); ?></h3>
                <button class="settings-tab-btn" data-settings-tab="access"><?php echo t('settings.access'); ?></button>
                <button class="settings-tab-btn" data-settings-tab="email"><?php echo t('settings.email'); ?></button>
                <button class="settings-tab-btn" data-settings-tab="privacy"><?php echo t('settings.privacy'); ?></button>
                <button class="settings-tab-btn" data-settings-tab="forms"><?php echo t('forms.title'); ?></button>
                <?php if ($aiFeaturesEnabled): ?>
                <button class="settings-tab-btn" data-settings-tab="ai"><?php echo t('ai.settings'); ?></button>
                <?php endif; ?>
            </section>
            <section class="settings-nav-group">
                <h3><?php echo t('settings.group_users'); ?></h3>
                <button class="settings-tab-btn" data-settings-tab="users"><?php echo t('settings.users'); ?></button>
                <button class="settings-tab-btn" data-settings-tab="my-account"><?php echo t('settings.my_account'); ?></button>
                <button class="settings-tab-btn" data-settings-tab="login"><?php echo t('settings.login'); ?></button>
            </section>
            <section class="settings-nav-group">
                <h3><?php echo t('settings.group_system'); ?></h3>
                <button class="settings-tab-btn" type="button" data-settings-action="backup"><?php echo t('settings.backup'); ?></button>
                <button class="settings-tab-btn settings-tab-btn--danger" data-settings-tab="danger"><?php echo t('settings.danger_zone'); ?></button>
            </section>
            <?php else: ?>
            <section class="settings-nav-group">
                <h3><?php echo t('settings.group_users'); ?></h3>
                <button class="settings-tab-btn active" data-settings-tab="my-account"><?php echo t('settings.my_account'); ?></button>
            </section>
            <?php endif; ?>
        </nav>
        <div class="settings-panels">

            <?php if ($isAdminUser): ?>
            <!-- Branding Panel -->
            <div class="settings-panel active" id="settingsPanel-branding">
                <h2><?php echo t('settings.branding'); ?></h2>
                <p class="settings-description"><?php echo t('settings.branding_desc'); ?></p>
                <form id="brandingForm" class="settings-form">
                    <fieldset class="settings-section settings-section--branding-main">
                        <legend><?php echo t('settings.site_identity'); ?></legend>
                        <div class="branding-main-grid">
                            <div class="branding-main-column branding-main-column--identity">
                                <div class="branding-identity-grid">
                                    <div class="form-group">
                                        <label for="settingsName"><?php echo t('settings.site_name'); ?></label>
                                        <small class="form-hint branding-site-name-hint"><?php echo t('settings.site_name_hint'); ?></small>
                                        <input type="text" id="settingsName" value="" placeholder="<?php echo t('settings.site_name_placeholder'); ?>" maxlength="100">
                                    </div>
                                    <div class="form-group">
                                        <label for="settingsFavicon"><?php echo t('settings.favicon'); ?></label>
                                        <small class="form-hint branding-favicon-hint"><?php echo t('settings.favicon_hint'); ?></small>
                                        <div class="logo-preview-group">
                                            <div class="logo-preview" id="faviconPreview">
                                                <img src="<?php echo htmlspecialchars($_dashFavicon); ?>" alt="<?php echo t('settings.favicon'); ?>" id="faviconPreviewImg">
                                            </div>
                                            <div class="logo-controls">
                                                <div class="logo-path-input">
                                                    <span class="input-with-clear">
                                                        <input type="text" id="settingsFavicon" value="<?php echo htmlspecialchars($_dashFavicon); ?>" placeholder="<?php echo htmlspecialchars($_defaultFavicon); ?>">
                                                        <button type="button" class="input-clear-btn" data-clear-target="settingsFavicon" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                                    </span>
                                                    <button type="button" class="btn btn-secondary btn-sm" id="browseFaviconBtn"><?php echo t('btn.browse'); ?></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="settingsFaviconPng"><?php echo t('settings.favicon_png'); ?></label>
                                        <small class="form-hint branding-favicon-hint"><?php echo t('settings.favicon_png_hint'); ?></small>
                                        <div class="logo-preview-group">
                                            <div class="logo-preview" id="faviconPngPreview">
                                                <img src="" alt="<?php echo t('settings.favicon_png'); ?>" id="faviconPngPreviewImg">
                                            </div>
                                            <div class="logo-controls">
                                                <div class="logo-path-input">
                                                    <span class="input-with-clear">
                                                        <input type="text" id="settingsFaviconPng" value="<?php echo htmlspecialchars($siteSettings['favicon_png'] ?? ''); ?>" placeholder="/assets/images/favicon.png">
                                                        <button type="button" class="input-clear-btn" data-clear-target="settingsFaviconPng" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                                    </span>
                                                    <button type="button" class="btn btn-secondary btn-sm" id="browseFaviconPngBtn"><?php echo t('btn.browse'); ?></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="branding-main-column branding-main-column--public">
                                <div class="frontend-logos-block">
                                    <h4 class="frontend-logos-block__title"><?php echo t('settings.frontend_logos'); ?></h4>
                                    <small class="form-hint frontend-logos-block__hint"><?php echo t('settings.frontend_logos_hint'); ?></small>
                                    <div class="form-group-row">
                                        <div class="form-group">
                                            <label for="settingsLogo"><?php echo t('settings.logo_light'); ?></label>
                                            <div class="logo-preview-group">
                                                <div class="logo-preview" id="logoPreview">
                                                    <img src="" alt="<?php echo t('settings.logo_light'); ?>" id="logoPreviewImg">
                                                </div>
                                                <div class="logo-controls">
                                                    <div class="logo-path-input">
                                                        <span class="input-with-clear">
                                                            <input type="text" id="settingsLogo" value="" placeholder="/assets/images/logo.png">
                                                            <button type="button" class="input-clear-btn" data-clear-target="settingsLogo" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                                        </span>
                                                        <button type="button" class="btn btn-secondary btn-sm" id="browseLogoBtn"><?php echo t('btn.browse'); ?></button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label for="settingsLogoDark"><?php echo t('settings.logo_dark'); ?></label>
                                            <div class="logo-preview-group">
                                                <div class="logo-preview logo-preview--dark" id="logoDarkPreview">
                                                    <img src="" alt="<?php echo t('settings.logo_dark'); ?>" id="logoDarkPreviewImg">
                                                </div>
                                                <div class="logo-controls">
                                                    <div class="logo-path-input">
                                                        <span class="input-with-clear">
                                                            <input type="text" id="settingsLogoDark" value="" placeholder="/assets/images/logo-dark.png">
                                                            <button type="button" class="input-clear-btn" data-clear-target="settingsLogoDark" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                                        </span>
                                                        <button type="button" class="btn btn-secondary btn-sm" id="browseLogoDarkBtn"><?php echo t('btn.browse'); ?></button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group" id="logoDisplayGroup">
                                    <label><?php echo t('settings.header_display'); ?></label>
                                    <small class="form-hint"><?php echo t('settings.header_display_hint'); ?></small>
                                    <div class="radio-group">
                                        <label class="radio-option"><input type="radio" name="settingsLogoDisplay" value="favicon"> <span><?php echo t('settings.logo_display_favicon'); ?></span></label>
                                        <label class="radio-option"><input type="radio" name="settingsLogoDisplay" value="text"> <span><?php echo t('settings.logo_display_text'); ?></span></label>
                                        <label class="radio-option"><input type="radio" name="settingsLogoDisplay" value="both" checked> <span><?php echo t('settings.logo_display_both'); ?></span></label>
                                    </div>
                                </div>
                                <div class="form-group" id="logoSizeGroup">
                                    <label><?php echo t('settings.logo_size'); ?></label>
                                    <small class="form-hint"><?php echo t('settings.logo_size_hint'); ?></small>
                                    <div class="radio-group radio-group--segmented">
                                        <label class="radio-option"><input type="radio" name="settingsLogoSize" value="small"> <span><?php echo t('settings.logo_size_small'); ?></span></label>
                                        <label class="radio-option"><input type="radio" name="settingsLogoSize" value="medium" checked> <span><?php echo t('settings.logo_size_medium'); ?></span></label>
                                        <label class="radio-option"><input type="radio" name="settingsLogoSize" value="large"> <span><?php echo t('settings.logo_size_large'); ?></span></label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="settingsDefaultOgImage"><?php echo t('settings.default_og_image'); ?></label>
                                    <small class="form-hint"><?php echo t('settings.default_og_image_hint'); ?></small>
                                    <div class="logo-preview-group logo-preview-group--with-hint">
                                        <div class="logo-preview logo-preview--wide" id="defaultOgPreview">
                                            <img src="" alt="<?php echo t('settings.default_og_image'); ?>" id="defaultOgPreviewImg">
                                        </div>
                                        <div class="logo-controls">
                                            <div class="logo-path-input">
                                                <span class="input-with-clear">
                                                    <input type="text" id="settingsDefaultOgImage" value="" placeholder="/assets/images/og-image.jpg">
                                                    <button type="button" class="input-clear-btn" data-clear-target="settingsDefaultOgImage" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                                </span>
                                                <button type="button" class="btn btn-secondary btn-sm" id="browseDefaultOgBtn"><?php echo t('btn.browse'); ?></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="settings-section">
                        <legend><?php echo t('settings.admin_interface'); ?></legend>
                        <div class="form-group">
                            <label for="settingsAdminLogo"><?php echo t('settings.admin_logo'); ?></label>
                            <small class="form-hint branding-favicon-hint"><?php echo t('settings.admin_logo_hint'); ?></small>
                            <div class="logo-preview-group">
                                <div class="logo-preview" id="adminLogoPreview">
                                    <img src="" alt="<?php echo t('settings.admin_logo'); ?>" id="adminLogoPreviewImg">
                                </div>
                                <div class="logo-controls">
                                    <div class="logo-path-input">
                                        <span class="input-with-clear">
                                            <input type="text" id="settingsAdminLogo" value="" placeholder="/assets/images/admin-logo.svg">
                                            <button type="button" class="input-clear-btn" data-clear-target="settingsAdminLogo" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                        </span>
                                        <button type="button" class="btn btn-secondary btn-sm" id="browseAdminLogoBtn"><?php echo t('btn.browse'); ?></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="toggle-label">
                                <span><?php echo t('settings.show_branding'); ?></span>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="settingsShowBranding" checked>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                            <small class="form-hint"><?php echo t('settings.branding_hint'); ?></small>
                        </div>
                    </fieldset>
                    <div class="branding-form-actions">
                        <button type="button" class="btn btn-secondary" id="resetBrandingBtn">&#x21BA; <?php echo t('settings.reset_branding'); ?></button>
                        <button type="submit" class="btn btn-primary" id="saveBrandingBtn"><?php echo t('settings.save_branding'); ?></button>
                    </div>
                </form>
            </div>

            <!-- Theme Panel -->
            <div class="settings-panel" id="settingsPanel-theme">
                <h2><?php echo t('settings.theme'); ?></h2>
                <p class="settings-description"><?php echo t('settings.theme_desc'); ?></p>
                <form id="themeForm" class="settings-form">
                    <div class="form-group">
                        <label><?php echo t('settings.admin_theme'); ?></label>
                        <div class="theme-selector">
                            <button type="button" class="theme-option selected" data-theme="light">
                                <span class="theme-swatch theme-swatch--light"></span>
                                <span><?php echo t('settings.theme_light'); ?></span>
                            </button>
                            <button type="button" class="theme-option" data-theme="dark">
                                <span class="theme-swatch theme-swatch--dark"></span>
                                <span><?php echo t('settings.theme_dark'); ?></span>
                            </button>
                            <button type="button" class="theme-option" data-theme="system">
                                <span class="theme-swatch theme-swatch--system"></span>
                                <span><?php echo t('settings.theme_system'); ?></span>
                            </button>
                        </div>
                        <input type="hidden" id="settingsAdminTheme" value="light">
                    </div>
                    <div class="theme-color-grid">
                    <div class="theme-color-preview" data-mode="light">
                    <fieldset class="theme-color-section">
                        <legend><?php echo t('settings.theme_section_light'); ?></legend>
                        <div class="form-group">
                            <label for="settingsPrimaryColor"><?php echo t('settings.primary_color'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="settingsPrimaryColorPicker" value="#3858e9" class="color-picker">
                                <input type="text" id="settingsPrimaryColor" value="#3858e9" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                            </div>
                            <small class="form-hint"><?php echo t('settings.primary_color_hint'); ?></small>
                            <small class="theme-contrast-feedback" data-contrast-for="primaryColor"></small>
                        </div>
                        <div class="form-group">
                            <label for="settingsAccentColor"><?php echo t('settings.accent_color'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="settingsAccentColorPicker" value="#3858e9" class="color-picker">
                                <input type="text" id="settingsAccentColor" value="#3858e9" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                            </div>
                            <small class="form-hint"><?php echo t('settings.accent_color_hint'); ?></small>
                            <small class="theme-contrast-feedback" data-contrast-for="accentColor"></small>
                        </div>
                        <div class="form-group">
                            <label for="settingsSidebarBg"><?php echo t('settings.sidebar_bg'); ?></label>
                            <div class="color-input-group" data-auto-field="sidebarBg">
                                <input type="color" id="settingsSidebarBgPicker" value="#eff6ff" class="color-picker">
                                <input type="text" id="settingsSidebarBg" value="#eff6ff" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                                <span class="auto-badge" data-auto-for="sidebarBg" hidden><?php echo t('settings.auto_badge'); ?></span>
                                <button type="button" class="auto-reset-btn" data-auto-reset="sidebarBg" title="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>" aria-label="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>">&#x21BA;</button>
                            </div>
                            <small class="form-hint"><?php echo t('settings.sidebar_bg_hint'); ?></small>
                            <small class="theme-contrast-feedback" data-contrast-for="sidebarBg"></small>
                        </div>
                    </fieldset>
                    </div>

                    <div class="theme-color-preview" data-mode="dark">
                    <fieldset class="theme-color-section">
                        <legend><?php echo t('settings.theme_section_dark'); ?></legend>
                        <div class="form-group">
                            <label for="settingsDarkPrimaryColor"><?php echo t('settings.primary_color'); ?></label>
                            <div class="color-input-group" data-auto-field="darkPrimaryColor">
                                <input type="color" id="settingsDarkPrimaryColorPicker" value="#3858e9" class="color-picker">
                                <input type="text" id="settingsDarkPrimaryColor" value="#3858e9" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                                <span class="auto-badge" data-auto-for="darkPrimaryColor" hidden><?php echo t('settings.auto_badge'); ?></span>
                                <button type="button" class="auto-reset-btn" data-auto-reset="darkPrimaryColor" title="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>" aria-label="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>">&#x21BA;</button>
                            </div>
                            <small class="theme-contrast-feedback" data-contrast-for="darkPrimaryColor"></small>
                        </div>
                        <div class="form-group">
                            <label for="settingsDarkAccentColor"><?php echo t('settings.accent_color'); ?></label>
                            <div class="color-input-group" data-auto-field="darkAccentColor">
                                <input type="color" id="settingsDarkAccentColorPicker" value="#3858e9" class="color-picker">
                                <input type="text" id="settingsDarkAccentColor" value="#3858e9" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                                <span class="auto-badge" data-auto-for="darkAccentColor" hidden><?php echo t('settings.auto_badge'); ?></span>
                                <button type="button" class="auto-reset-btn" data-auto-reset="darkAccentColor" title="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>" aria-label="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>">&#x21BA;</button>
                            </div>
                            <small class="theme-contrast-feedback" data-contrast-for="darkAccentColor"></small>
                        </div>
                        <div class="form-group">
                            <label for="settingsDarkSidebarBg"><?php echo t('settings.sidebar_bg'); ?></label>
                            <div class="color-input-group" data-auto-field="darkSidebarBg">
                                <input type="color" id="settingsDarkSidebarBgPicker" value="#0a0a0a" class="color-picker">
                                <input type="text" id="settingsDarkSidebarBg" value="#0a0a0a" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                                <span class="auto-badge" data-auto-for="darkSidebarBg" hidden><?php echo t('settings.auto_badge'); ?></span>
                                <button type="button" class="auto-reset-btn" data-auto-reset="darkSidebarBg" title="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>" aria-label="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>">&#x21BA;</button>
                            </div>
                            <small class="theme-contrast-feedback" data-contrast-for="darkSidebarBg"></small>
                        </div>
                        <small class="form-hint"><?php echo t('settings.dark_section_hint'); ?></small>
                    </fieldset>
                    </div>
                    </div>

                    <fieldset class="settings-section settings-section--compact settings-section--button-style">
                        <legend><?php echo t('settings.button_style'); ?></legend>
                        <div class="btn-style-row">
                            <div class="btn-style-controls">
                                <div class="range-field btn-style-radius-field">
                                    <label for="settingsButtonRadius"><?php echo t('settings.button_radius'); ?></label>
                                    <div class="range-input-group">
                                        <input type="range" id="settingsButtonRadius" min="0" max="24" value="6" class="range-input">
                                        <span class="range-value" id="settingsButtonRadiusValue">6px</span>
                                    </div>
                                </div>
                                <div class="btn-style-glow-field">
                                    <label class="toggle-label">
                                        <span><?php echo t('settings.button_glow'); ?></span>
                                        <div class="toggle-switch">
                                            <input type="checkbox" id="settingsButtonGlow" checked>
                                            <span class="toggle-slider"></span>
                                        </div>
                                    </label>
                                    <small class="form-hint"><?php echo t('settings.button_glow_hint'); ?></small>
                                </div>
                            </div>
                            <div class="btn-style-preview" id="btnStylePreview">
                                <button type="button" class="btn-preview-primary" id="previewBtnPrimary"><?php echo t('settings.preview_primary'); ?></button>
                                <button type="button" class="btn-preview-secondary" id="previewBtnSecondary"><?php echo t('settings.preview_secondary'); ?></button>
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="settings-section settings-section--compact">
                        <legend><?php echo t('settings.list_pagination'); ?></legend>
                        <p class="settings-description settings-description--compact"><?php echo t('settings.list_pagination_desc'); ?></p>
                        <div class="form-group-row form-group-row--pagination">
                            <div class="form-group">
                                <label for="settingsItemsPerPage"><?php echo t('settings.items_per_page'); ?></label>
                                <input type="number" id="settingsItemsPerPage" min="10" max="500" step="5" value="50" inputmode="numeric">
                                <small class="form-hint"><?php echo t('settings.items_per_page_hint'); ?></small>
                            </div>
                            <div class="form-group">
                                <label for="settingsIconItemsPerPage"><?php echo t('settings.icon_items_per_page'); ?></label>
                                <input type="number" id="settingsIconItemsPerPage" min="10" max="500" step="5" value="50" inputmode="numeric">
                                <small class="form-hint"><?php echo t('settings.icon_items_per_page_hint'); ?></small>
                            </div>
                            <div class="form-group">
                                <label for="settingsMediaItemsPerPage"><?php echo t('settings.media_items_per_page'); ?></label>
                                <input type="number" id="settingsMediaItemsPerPage" min="10" max="500" step="5" value="25" inputmode="numeric">
                                <small class="form-hint"><?php echo t('settings.media_items_per_page_hint'); ?></small>
                            </div>
                        </div>
                    </fieldset>
                    <div class="theme-form-actions">
                        <button type="button" class="btn btn-secondary" id="resetThemeBtn">&#x21BA; <?php echo t('settings.reset_colors'); ?></button>
                        <button type="submit" class="btn btn-primary" id="saveThemeBtn"><?php echo t('settings.save_theme'); ?></button>
                    </div>
                </form>
            </div>

            <!-- Language Panel -->
            <div class="settings-panel" id="settingsPanel-language">
                <h2><?php echo t('settings.language'); ?></h2>
                <p class="settings-description"><?php echo t('settings.admin_language_hint'); ?></p>
                <form id="languageForm" class="settings-form">
                    <div class="form-group">
                        <label for="settingsAdminLanguage"><?php echo t('settings.admin_language'); ?></label>
                        <select id="settingsAdminLanguage" class="topbar-select" style="width: auto; min-width: 200px;">
                            <option value=""><?php echo t('settings.admin_language_default', ['lang' => (isset($SITE_LANGUAGES[SITE_LANG_DEFAULT]) ? $SITE_LANGUAGES[SITE_LANG_DEFAULT] : SITE_LANG_DEFAULT)]); ?></option>
                            <?php foreach (tAvailableLanguages() as $code => $name): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" id="saveLanguageBtn"><?php echo t('settings.save_language'); ?></button>
                </form>
            </div>

            <!-- Login Panel -->
            <div class="settings-panel" id="settingsPanel-login">
                <h2><?php echo t('settings.login'); ?></h2>
                <p class="settings-description"><?php echo t('settings.frontend_login_redirect_hint'); ?></p>
                <form id="loginForm" class="settings-form">
                    <div class="form-group">
                        <span class="form-group-label"><?php echo t('settings.frontend_login_redirect'); ?></span>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="frontendLoginRedirect" value="auto" id="frontendLoginRedirectAuto">
                                <span><?php echo t('settings.frontend_login_redirect_auto'); ?></span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="frontendLoginRedirect" value="dashboard" id="frontendLoginRedirectDashboard">
                                <span><?php echo t('settings.frontend_login_redirect_dashboard'); ?></span>
                            </label>
                        </div>
                    </div>
                    <fieldset class="settings-section settings-section--compact settings-section--visual">
                        <legend><?php echo t('settings.login_visual'); ?></legend>
                        <div class="settings-visual-grid">
                            <div class="form-group">
                                <label for="loginBrandAsset"><?php echo t('settings.visual_brand_asset'); ?></label>
                                <select id="loginBrandAsset" class="topbar-select access-select">
                                    <option value="none"><?php echo t('settings.visual_brand_none'); ?></option>
                                    <option value="favicon"><?php echo t('settings.visual_brand_favicon'); ?></option>
                                    <option value="logo"><?php echo t('settings.visual_brand_logo'); ?></option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="loginImageLayout"><?php echo t('settings.visual_image_layout'); ?></label>
                                <select id="loginImageLayout" class="topbar-select access-select">
                                    <option value="none"><?php echo t('settings.visual_image_none'); ?></option>
                                    <option value="left"><?php echo t('settings.visual_image_left'); ?></option>
                                    <option value="right"><?php echo t('settings.visual_image_right'); ?></option>
                                    <option value="background"><?php echo t('settings.visual_image_background'); ?></option>
                                </select>
                            </div>
                            <div class="form-group visual-overlay-color-group" id="loginOverlayColorGroup" hidden>
                                <label for="loginOverlayColor"><?php echo t('settings.visual_overlay_color'); ?></label>
                                <div class="visual-color-opacity-row">
                                    <input type="color" id="loginOverlayColor" value="#ffffff">
                                    <div class="range-input-group visual-opacity-control">
                                        <input type="range" id="loginOverlayOpacity" class="range-input" min="0" max="100" value="86">
                                        <span class="range-value" id="loginOverlayOpacityValue">86%</span>
                                    </div>
                                </div>
                                <small class="form-hint"><?php echo t('settings.visual_overlay_color_hint'); ?></small>
                            </div>
                            <div class="form-group">
                                <label for="loginBoxStyle"><?php echo t('settings.visual_login_box'); ?></label>
                                <select id="loginBoxStyle" class="topbar-select access-select">
                                    <option value="card"><?php echo t('settings.visual_login_box_card'); ?></option>
                                    <option value="plain"><?php echo t('settings.visual_login_box_plain'); ?></option>
                                </select>
                            </div>
                            <div class="form-group visual-box-color-group" id="loginBoxColorGroup">
                                <label for="loginBoxColor"><?php echo t('settings.visual_box_color'); ?></label>
                                <div class="color-input-group">
                                    <input type="color" id="loginBoxColorPicker" value="#ffffff" class="color-picker">
                                    <input type="text" id="loginBoxColor" value="#ffffff" class="color-hex-input" maxlength="7" pattern="#[0-9a-fA-F]{6}">
                                </div>
                                <small class="form-hint"><?php echo t('settings.visual_box_color_hint'); ?></small>
                            </div>
                            <div class="form-group visual-box-color-group">
                                <label for="loginBoxTextColor"><?php echo t('settings.visual_box_text_color'); ?></label>
                                <div class="color-input-group">
                                    <input type="color" id="loginBoxTextColorPicker" value="#111827" class="color-picker">
                                    <input type="text" id="loginBoxTextColor" value="#111827" class="color-hex-input" maxlength="7" pattern="#[0-9a-fA-F]{6}">
                                </div>
                                <small class="form-hint"><?php echo t('settings.visual_box_text_color_hint'); ?></small>
                            </div>
                            <div class="form-group access-wide">
                                <label for="loginImage"><?php echo t('settings.visual_image'); ?></label>
                                <div class="logo-path-input">
                                    <span class="input-with-clear">
                                        <input type="text" id="loginImage" placeholder="/assets/images/login.jpg">
                                        <button type="button" class="input-clear-btn" data-clear-target="loginImage" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                    </span>
                                    <button type="button" class="btn btn-secondary btn-sm" id="browseLoginImageBtn"><?php echo t('btn.browse'); ?></button>
                                </div>
                                <small class="form-hint"><?php echo t('settings.visual_image_hint'); ?></small>
                            </div>
                        </div>
                    </fieldset>
                    <button type="submit" class="btn btn-primary" id="saveLoginBtn"><?php echo t('settings.save_login'); ?></button>
                </form>
            </div>

            <!-- Access Panel -->
            <div class="settings-panel" id="settingsPanel-access">
                <h2><?php echo t('settings.access'); ?></h2>
                <p class="settings-description"><?php echo t('settings.access_desc'); ?></p>
                <form id="accessForm" class="settings-form">
                    <fieldset class="settings-section settings-section--compact settings-section--maintenance">
                        <legend><?php echo t('settings.maintenance'); ?></legend>
                        <div class="access-maintenance-grid">
                        <div class="form-group access-toggle-row">
                            <label class="toggle-label">
                                <span><?php echo t('settings.maintenance_enabled'); ?></span>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="maintenanceEnabled">
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                        </div>
                        <div class="form-group">
                            <label for="maintenanceMode"><?php echo t('settings.maintenance_mode'); ?></label>
                            <select id="maintenanceMode" class="topbar-select access-select">
                                <option value="maintenance"><?php echo t('settings.maintenance_mode_maintenance'); ?></option>
                                <option value="offline"><?php echo t('settings.maintenance_mode_offline'); ?></option>
                                <option value="launch"><?php echo t('settings.maintenance_mode_launch'); ?></option>
                            </select>
                        </div>
                        <div class="form-group access-wide">
                            <label for="maintenanceTitle"><?php echo t('settings.maintenance_title'); ?></label>
                            <input type="text" id="maintenanceTitle" maxlength="160">
                        </div>
                        <div class="form-group access-wide">
                            <label for="maintenanceText"><?php echo t('settings.maintenance_text'); ?></label>
                            <textarea id="maintenanceText" rows="3"></textarea>
                        </div>
                            <div class="form-group">
                                <label for="maintenanceUntil"><?php echo t('settings.maintenance_until'); ?></label>
                                <input type="datetime-local" id="maintenanceUntil">
                            </div>
                            <div class="form-group access-toggle-row access-toggle-row--inline">
                                <label class="toggle-label">
                                    <span><?php echo t('settings.maintenance_countdown'); ?></span>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="maintenanceCountdown">
                                        <span class="toggle-slider"></span>
                                    </div>
                                </label>
                            </div>
                            <div class="form-group">
                                <label for="maintenanceImageLayout"><?php echo t('settings.visual_image_layout'); ?></label>
                                <select id="maintenanceImageLayout" class="topbar-select access-select">
                                    <option value="none"><?php echo t('settings.visual_image_none'); ?></option>
                                    <option value="left"><?php echo t('settings.visual_image_left'); ?></option>
                                    <option value="right"><?php echo t('settings.visual_image_right'); ?></option>
                                    <option value="background"><?php echo t('settings.visual_image_background'); ?></option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="maintenanceBrandAsset"><?php echo t('settings.visual_brand_asset'); ?></label>
                                <select id="maintenanceBrandAsset" class="topbar-select access-select">
                                    <option value="none"><?php echo t('settings.visual_brand_none'); ?></option>
                                    <option value="favicon"><?php echo t('settings.visual_brand_favicon'); ?></option>
                                    <option value="logo"><?php echo t('settings.visual_brand_logo'); ?></option>
                                </select>
                            </div>
                            <div class="form-group visual-overlay-color-group" id="maintenanceOverlayColorGroup" hidden>
                                <label for="maintenanceOverlayColor"><?php echo t('settings.visual_overlay_color'); ?></label>
                                <div class="visual-color-opacity-row">
                                    <input type="color" id="maintenanceOverlayColor" value="#ffffff">
                                    <div class="range-input-group visual-opacity-control">
                                        <input type="range" id="maintenanceOverlayOpacity" class="range-input" min="0" max="100" value="88">
                                        <span class="range-value" id="maintenanceOverlayOpacityValue">88%</span>
                                    </div>
                                </div>
                                <small class="form-hint"><?php echo t('settings.visual_overlay_color_hint'); ?></small>
                            </div>
                            <div class="form-group access-wide">
                                <label for="maintenanceImage"><?php echo t('settings.visual_image'); ?></label>
                                <div class="logo-path-input">
                                    <span class="input-with-clear">
                                        <input type="text" id="maintenanceImage" placeholder="/assets/images/maintenance.jpg">
                                        <button type="button" class="input-clear-btn" data-clear-target="maintenanceImage" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                    </span>
                                    <button type="button" class="btn btn-secondary btn-sm" id="browseMaintenanceImageBtn"><?php echo t('btn.browse'); ?></button>
                                </div>
                                <small class="form-hint"><?php echo t('settings.visual_image_hint'); ?></small>
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="settings-section settings-section--compact">
                        <legend><?php echo t('settings.access_bypass'); ?></legend>
                        <div class="form-row-inline">
                            <div class="form-group">
                                <label for="maintenanceBypassParam"><?php echo t('settings.access_bypass_param'); ?></label>
                                <input type="text" id="maintenanceBypassParam" placeholder="preview" maxlength="40">
                            </div>
                            <div class="form-group">
                                <label for="maintenanceBypassKey"><?php echo t('settings.access_bypass_key'); ?></label>
                                <input type="password" id="maintenanceBypassKey" autocomplete="new-password">
                                <small class="form-hint" id="maintenanceBypassHint"></small>
                            </div>
                        </div>
                    </fieldset>
                    <button type="submit" class="btn btn-primary" id="saveAccessBtn"><?php echo t('settings.save_access'); ?></button>
                </form>
            </div>

            <!-- Privacy Panel -->
            <div class="settings-panel" id="settingsPanel-privacy">
                <h2><?php echo t('settings.privacy'); ?></h2>
                <p class="settings-description"><?php echo t('settings.privacy_desc'); ?></p>
                <form id="privacyForm" class="settings-form">
                    <fieldset class="settings-section settings-section--compact">
                        <legend><?php echo t('settings.privacy'); ?></legend>
                        <div class="form-group access-toggle-row">
                            <label class="toggle-label">
                                <span><?php echo t('settings.email_obfuscation'); ?></span>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="emailObfuscation">
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                            <small class="form-hint"><?php echo t('settings.email_obfuscation_hint'); ?></small>
                        </div>
                        <div class="form-group access-toggle-row">
                            <label class="toggle-label">
                                <span><?php echo t('settings.remember_public_theme'); ?></span>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="rememberPublicTheme" checked>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                            <small class="form-hint"><?php echo t('settings.remember_public_theme_hint'); ?></small>
                        </div>
                        <div class="form-group"><label class="toggle-label"><span><?php echo t('analytics.enabled'); ?></span><span class="toggle-switch"><input type="checkbox" id="analyticsEnabled" checked><span class="toggle-slider"></span></span></label><small class="form-hint"><?php echo t('analytics.hint'); ?></small></div>
                    </fieldset>
                    <button type="submit" class="btn btn-primary" id="savePrivacyBtn"><?php echo t('settings.save_privacy'); ?></button>
                </form>
            </div>

            <!-- Email Panel -->
            <div class="settings-panel" id="settingsPanel-email">
                <h2><?php echo t('settings.email'); ?></h2>
                <p class="settings-description"><?php echo t('settings.email_desc'); ?></p>
                <form id="emailForm" class="settings-form">

                    <div class="form-group">
                        <label for="settingsEmailMethod"><?php echo t('settings.email_method'); ?></label>
                        <select id="settingsEmailMethod" class="topbar-select" style="width: auto; min-width: 200px;">
                            <option value="inactive"><?php echo t('settings.email_inactive'); ?></option>
                            <option value="smtp">SMTP</option>
                            <option value="sendmail">PHP mail() / Sendmail</option>
                        </select>
                    </div>

                    <div class="settings-hint-box" id="sendmailHint" style="display: none;">
                        <p><?php echo t('settings.sendmail_hint'); ?></p>
                    </div>

                    <div class="settings-hint-box" id="emailInactiveHint" style="display: none;">
                        <p><?php echo t('settings.email_inactive_hint'); ?></p>
                    </div>

                    <div class="form-group">
                        <label for="settingsRecipientEmail"><?php echo t('settings.recipient_email'); ?></label>
                        <input type="text" id="settingsRecipientEmail" placeholder="info@example.com, team@example.com">
                        <small class="form-hint"><?php echo t('settings.recipient_email_hint'); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="settingsBccEmail"><?php echo t('settings.bcc_email'); ?></label>
                        <input type="text" id="settingsBccEmail" placeholder="archive@example.com, backup@example.com">
                        <small class="form-hint"><?php echo t('settings.bcc_email_hint'); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="settingsFromEmail"><?php echo t('settings.from_email'); ?></label>
                        <input type="email" id="settingsFromEmail" placeholder="noreply@example.com">
                        <small class="form-hint"><?php echo t('settings.from_email_hint'); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="settingsFromName"><?php echo t('settings.from_name'); ?></label>
                        <input type="text" id="settingsFromName" placeholder="My Website">
                    </div>

                    <div id="smtpFields">
                        <hr class="settings-divider">
                        <h3 class="settings-subheading"><?php echo t('settings.smtp_settings'); ?></h3>

                        <div class="form-group">
                            <label for="settingsSmtpHost"><?php echo t('settings.smtp_host'); ?></label>
                            <input type="text" id="settingsSmtpHost" placeholder="mail.example.com">
                        </div>

                        <div class="form-row-inline">
                            <div class="form-group">
                                <label for="settingsSmtpPort"><?php echo t('settings.smtp_port'); ?></label>
                                <input type="number" id="settingsSmtpPort" value="587" min="1" max="65535" style="width: 100px;">
                            </div>
                            <div class="form-group">
                                <label for="settingsSmtpEncryption"><?php echo t('settings.smtp_encryption'); ?></label>
                                <select id="settingsSmtpEncryption" class="topbar-select" style="width: auto; min-width: 120px;">
                                    <option value="tls">STARTTLS (587)</option>
                                    <option value="ssl">SSL/TLS (465)</option>
                                    <option value="none"><?php echo t('settings.smtp_encryption_none'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="settingsSmtpUsername"><?php echo t('settings.smtp_username'); ?></label>
                            <input type="text" id="settingsSmtpUsername" placeholder="user@example.com" autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label for="settingsSmtpPassword"><?php echo t('settings.smtp_password'); ?></label>
                            <input type="password" id="settingsSmtpPassword" placeholder="<?php echo t('settings.smtp_password_placeholder'); ?>" autocomplete="new-password">
                            <small class="form-hint"><?php echo t('settings.smtp_password_hint'); ?></small>
                        </div>
                    </div>

                    <div class="settings-actions-row">
                        <button type="submit" class="btn btn-primary" id="saveEmailBtn"><?php echo t('settings.save_email'); ?></button>
                        <button type="button" class="btn btn-secondary" id="testEmailBtn">
                            <?php echo t('settings.test_email'); ?>
                        </button>
                    </div>

                    <div id="emailTestResult" class="settings-test-result" style="display: none;"></div>
                </form>
            </div>

            <!-- Forms Panel -->
            <div class="settings-panel" id="settingsPanel-forms">
                <h2><?php echo t('forms.title'); ?></h2>
                <p class="settings-description"><?php echo t('forms.desc'); ?></p>

                <div class="forms-admin-layout">
                    <aside class="forms-admin-list">
                        <div class="forms-admin-list__head">
                            <div class="form-group forms-admin-select-group">
                                <label for="formsAdminSelect"><?php echo t('forms.select_form'); ?></label>
                                <select id="formsAdminSelect" class="topbar-select"></select>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" id="newFormBtn"><?php echo t('forms.new'); ?></button>
                        </div>
                        <p id="formsAdminMeta" class="forms-admin-meta"></p>
                    </aside>

                    <form id="formsAdminForm" class="settings-form forms-admin-editor">
                        <fieldset class="settings-section">
                            <legend><?php echo t('forms.base_data'); ?></legend>
                            <div class="form-row-inline">
                                <div class="form-group">
                                    <label for="formEditorId"><?php echo t('forms.form_id'); ?></label>
                                    <input type="text" id="formEditorId" autocomplete="off">
                                    <small class="form-hint"><?php echo t('forms.form_id_hint'); ?></small>
                                </div>
                                <div class="form-group">
                                    <label for="formEditorLabel"><?php echo t('forms.label'); ?></label>
                                    <input type="text" id="formEditorLabel">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="formEditorDescription"><?php echo t('forms.description'); ?></label>
                                <textarea id="formEditorDescription" rows="3"></textarea>
                            </div>
                            <div class="forms-admin-switches">
                                <label class="checkbox-label"><input type="checkbox" id="formEditorEnabled"> <?php echo t('forms.enabled'); ?></label>
                                <label class="checkbox-label"><input type="checkbox" id="formEditorStore"> <?php echo t('forms.store'); ?></label>
                                <label class="checkbox-label"><input type="checkbox" id="formEditorEmail"> <?php echo t('forms.email'); ?></label>
                            </div>
                            <div class="form-row-inline">
                                <div class="form-group">
                                    <label for="formEditorSubject"><?php echo t('forms.subject'); ?></label>
                                    <input type="text" id="formEditorSubject" placeholder="{form}: {name}">
                                </div>
                                <div class="form-group">
                                    <label for="formEditorSuccess"><?php echo t('forms.success_text'); ?></label>
                                    <input type="text" id="formEditorSuccess">
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="settings-section">
                            <legend><?php echo t('forms.fields'); ?></legend>
                            <div class="forms-fields-toolbar">
                                <button type="button" class="btn btn-secondary btn-sm" id="addFormFieldBtn"><?php echo t('forms.add_field'); ?></button>
                            </div>
                            <div class="forms-fields-table-wrap">
                                <table class="users-table forms-fields-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo t('forms.type'); ?></th>
                                            <th><?php echo t('forms.key'); ?></th>
                                            <th><?php echo t('forms.label'); ?></th>
                                            <th><?php echo t('forms.placeholder'); ?></th>
                                            <th><?php echo t('forms.width'); ?></th>
                                            <th><?php echo t('forms.required'); ?></th>
                                            <th><?php echo t('forms.options'); ?></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="formFieldsBody"></tbody>
                                </table>
                            </div>
                            <small class="form-hint"><?php echo t('forms.options_hint'); ?></small>
                        </fieldset>

                        <div class="settings-actions-row">
                            <button type="submit" class="btn btn-primary" id="saveFormBtn"><?php echo t('forms.save'); ?></button>
                            <div id="formsAdminResult" class="settings-test-result" style="display:none;"></div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- AI Settings Panel -->
            <?php if ($aiFeaturesEnabled): ?>
            <div class="settings-panel" id="settingsPanel-ai">
                <h2><?php echo t('ai.settings'); ?></h2>
                <p class="settings-description"><?php echo t('ai.settings_desc'); ?></p>
                <form id="aiSettingsForm" class="settings-form">
                    <div class="ai-status-box" id="aiProviderStatus"></div>

                    <fieldset class="settings-section">
                        <legend><?php echo t('ai.provider'); ?></legend>
                        <div class="ai-settings-compact">
                        <div class="form-group ai-enable-row">
                            <label class="toggle-label">
                                <span><?php echo t('ai.enable_ai'); ?></span>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="aiEnabled">
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                        </div>
                            <div class="form-group">
                                <label for="aiProvider"><?php echo t('ai.provider'); ?></label>
                                <select id="aiProvider" class="topbar-select">
                                    <option value="openai-compatible"><?php echo t('ai.openai_compatible'); ?></option>
                                    <option value="openrouter"><?php echo t('ai.openrouter'); ?></option>
                                    <option value="anthropic"><?php echo t('ai.anthropic'); ?></option>
                                    <option value="kie"><?php echo t('ai.kie'); ?></option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="aiBaseUrl"><?php echo t('ai.base_url'); ?></label>
                                <input type="url" id="aiBaseUrl" placeholder="https://api.openai.com/v1">
                            </div>
                            <div class="form-group">
                                <label for="aiApiKey"><?php echo t('ai.api_key'); ?></label>
                                <input type="password" id="aiApiKey" autocomplete="new-password">
                                <small class="form-hint" id="aiApiKeyHint"></small>
                                <label class="checkbox-label"><input type="checkbox" id="aiClearApiKey"> <?php echo t('ai.clear_api_key'); ?></label>
                            </div>
                            <div class="form-group">
                                <label for="aiOrganization"><?php echo t('ai.organization'); ?></label>
                                <input type="text" id="aiOrganization" maxlength="120">
                            </div>
                        </div>
                        <div class="form-group ai-local-toggle">
                            <label class="toggle-label">
                                <span><?php echo t('ai.allow_local_provider'); ?></span>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="aiAllowLocalProvider">
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                            <small class="form-hint"><?php echo t('ai.allow_local_provider_hint'); ?></small>
                        </div>
                    </fieldset>

                    <fieldset class="settings-section" id="aiUsagePanel" hidden>
                        <legend><?php echo t('ai.usage_panel'); ?></legend>
                        <div class="ai-usage-stats" id="aiUsagePanelBody"></div>
                    </fieldset>

                    <fieldset class="settings-section">
                        <legend><?php echo t('ai.models'); ?></legend>
                        <p class="form-hint ai-section-hint"><?php echo t('ai.models_hint'); ?></p>
                        <div class="form-row-inline">
                            <div class="form-group">
                                <label for="aiChatModel"><?php echo t('ai.chat_model'); ?></label>
                                <div class="nb-combobox" data-model-combobox>
                                    <input type="text" id="aiChatModel" placeholder="gpt-4.1-mini" role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off" spellcheck="false">
                                    <button type="button" class="input-clear-btn nb-combobox__clear" data-clear-target="aiChatModel" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                    <button type="button" class="nb-combobox__toggle" aria-label="<?php echo t('ai.model_list_open'); ?>" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg></button>
                                    <div class="nb-combobox__list" role="listbox" hidden></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="aiTextModel"><?php echo t('ai.text_model'); ?></label>
                                <div class="nb-combobox" data-model-combobox>
                                    <input type="text" id="aiTextModel" placeholder="gpt-4.1-mini" role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off" spellcheck="false">
                                    <button type="button" class="input-clear-btn nb-combobox__clear" data-clear-target="aiTextModel" aria-label="<?php echo t('btn.clear'); ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                    <button type="button" class="nb-combobox__toggle" aria-label="<?php echo t('ai.model_list_open'); ?>" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg></button>
                                    <div class="nb-combobox__list" role="listbox" hidden></div>
                                </div>
                            </div>
                            <input type="hidden" id="aiImageModel" value="gpt-image-2">
                        </div>
                    </fieldset>

                    <fieldset class="settings-section">
                        <legend><?php echo t('ai.features'); ?></legend>
                        <div class="form-group access-toggle-row ai-feature-toggle-grid">
                            <label class="toggle-label"><span><?php echo t('ai.feature_assistant'); ?></span><div class="toggle-switch"><input type="checkbox" id="aiFeatureAssistant"><span class="toggle-slider"></span></div></label>
                            <label class="toggle-label"><span><?php echo t('ai.feature_seo'); ?></span><div class="toggle-switch"><input type="checkbox" id="aiFeatureSeo"><span class="toggle-slider"></span></div></label>
                            <label class="toggle-label"><span><?php echo t('ai.feature_images'); ?></span><div class="toggle-switch"><input type="checkbox" id="aiFeatureImages"><span class="toggle-slider"></span></div></label>
                            <label class="toggle-label"><span><?php echo t('ai.assistant_surface_visual_editor'); ?></span><div class="toggle-switch"><input type="checkbox" id="aiAssistantSurfaceVisualEditor"><span class="toggle-slider"></span></div></label>
                            <label class="toggle-label"><span><?php echo t('ai.assistant_surface_dashboard'); ?></span><div class="toggle-switch"><input type="checkbox" id="aiAssistantSurfaceDashboard"><span class="toggle-slider"></span></div></label>
                            <label class="toggle-label"><span><?php echo t('ai.assistant_force_english'); ?></span><div class="toggle-switch"><input type="checkbox" id="aiAssistantForceEnglish"><span class="toggle-slider"></span></div></label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-section">
                        <legend><?php echo t('ai.limits'); ?></legend>
                        <div class="form-row-inline">
                            <div class="form-group"><label for="aiMonthlyBudget"><?php echo t('ai.monthly_budget'); ?></label><input type="number" id="aiMonthlyBudget" min="0" value="1000"></div>
                            <div class="form-group"><label for="aiDailyRequests"><?php echo t('ai.daily_requests'); ?></label><input type="number" id="aiDailyRequests" min="0" value="100"></div>
                            <div class="form-group"><label for="aiDailyTextRequests"><?php echo t('ai.daily_text_requests'); ?></label><input type="number" id="aiDailyTextRequests" min="0" value="80"></div>
                            <div class="form-group"><label for="aiDailyImageRequests"><?php echo t('ai.daily_image_requests'); ?></label><input type="number" id="aiDailyImageRequests" min="0" value="10"></div>
                        </div>
                        <div class="form-row-inline">
                            <div class="form-group"><label for="aiMaxInputTokens"><?php echo t('ai.max_input_tokens'); ?></label><input type="number" id="aiMaxInputTokens" min="100" value="24000"><small class="form-hint"><?php echo t('ai.max_input_tokens_hint'); ?></small></div>
                            <div class="form-group"><label for="aiMaxOutputTokens"><?php echo t('ai.max_output_tokens'); ?></label><input type="number" id="aiMaxOutputTokens" min="16" value="4096"><small class="form-hint"><?php echo t('ai.max_output_tokens_hint'); ?></small></div>
                            <div class="form-group"><label for="aiRequestTimeout"><?php echo t('ai.request_timeout'); ?></label><input type="number" id="aiRequestTimeout" min="5" max="600" value="300"><small class="form-hint"><?php echo t('ai.request_timeout_hint'); ?></small></div>
                        </div>
                    </fieldset>

                    <fieldset class="settings-section">
                        <legend><?php echo t('ai.pricing'); ?></legend>
                        <div class="form-row-inline">
                            <div class="form-group"><label for="aiInputPrice"><?php echo t('ai.input_price'); ?></label><input type="number" id="aiInputPrice" min="0" value="15"></div>
                            <div class="form-group"><label for="aiOutputPrice"><?php echo t('ai.output_price'); ?></label><input type="number" id="aiOutputPrice" min="0" value="60"></div>
                            <div class="form-group"><label for="aiImagePrice"><?php echo t('ai.image_price'); ?></label><input type="number" id="aiImagePrice" min="0" value="5"></div>
                        </div>
                    </fieldset>

                    <div class="settings-actions-row">
                        <button type="submit" class="btn btn-primary" id="saveAiSettingsBtn"><?php echo t('ai.save_settings'); ?></button>
                        <button type="button" class="btn btn-secondary" id="testAiBtn"><?php echo t('ai.test_connection'); ?></button>
                    </div>
                    <div id="aiSettingsResult" class="settings-test-result" style="display: none;"></div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Users Panel -->
            <div class="settings-panel" id="settingsPanel-users">
                <h2><?php echo t('settings.users'); ?></h2>
                <p class="settings-description"><?php echo t('settings.users_desc'); ?></p>

                <div class="users-toolbar">
                    <button type="button" class="btn btn-primary" id="addUserBtn">+ <?php echo t('settings.add_user'); ?></button>
                </div>

                <div class="page-list-table-wrap users-table-wrap">
                    <table class="page-list-table users-table users-table--admin" id="usersTable">
                        <thead>
                            <tr>
                                <th><?php echo t('settings.user_username'); ?></th>
                                <th><?php echo t('settings.user_email'); ?></th>
                                <th><?php echo t('settings.user_role'); ?></th>
                                <th><?php echo t('settings.user_last_login'); ?></th>
                                <th><?php echo t('settings.user_actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Menus Panel -->
            <div class="settings-panel" id="settingsPanel-menus">
                <h2><?php echo t('settings.menus'); ?></h2>
                <p class="settings-description"><?php echo t('settings.menus_desc'); ?></p>

                <div class="form-group">
                    <label for="menuOrderSelect"><?php echo t('settings.menu_select'); ?></label>
                    <select id="menuOrderSelect" class="form-control" style="max-width: 300px;">
                        <?php
                        require_once __DIR__ . '/../includes/menu-helpers.php';
                        $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
                        foreach (getRegisteredMenuIds() as $mid):
                            $mlabel = getMenuLabel($mid, $adminLang ?? $defaultLang);
                        ?>
                        <option value="<?php echo htmlspecialchars($mid); ?>"><?php echo htmlspecialchars($mlabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="menuOrderList" class="menu-order-list"></div>
                <div id="menuOrderEmpty" class="menu-order-empty" style="display:none;">
                    <p><?php echo t('settings.menu_order_empty'); ?></p>
                </div>

                <div class="form-actions" style="margin-top: 1.5rem;">
                    <button type="button" class="btn btn-primary" id="saveMenuOrderBtn" disabled><?php echo t('settings.save_menus'); ?></button>
                </div>

            </div>

            <!-- Dashboard Modules Panel -->
            <div class="settings-panel" id="settingsPanel-modules">
                <h2><?php echo t('settings.modules'); ?></h2>
                <p class="settings-description"><?php echo t('settings.modules_desc'); ?></p>
                <form id="moduleForm" class="settings-form">
                    <fieldset class="settings-section settings-section--compact">
                        <legend><?php echo t('settings.modules'); ?></legend>
                        <div class="settings-toggle-grid settings-toggle-grid--modules">
                            <div class="form-group access-toggle-row access-toggle-row--module">
                                <label class="toggle-label">
                                    <span><?php echo t('settings.module_news'); ?></span>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="moduleNews" checked>
                                        <span class="toggle-slider"></span>
                                    </div>
                                </label>
                            </div>
                            <div class="form-group access-toggle-row access-toggle-row--module">
                                <label class="toggle-label">
                                    <span><?php echo t('settings.module_events'); ?></span>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="moduleEvents" checked>
                                        <span class="toggle-slider"></span>
                                    </div>
                                </label>
                            </div>
                            <div class="form-group access-toggle-row access-toggle-row--module">
                                <label class="toggle-label">
                                    <span><?php echo t('settings.module_messages'); ?></span>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="moduleMessages" checked>
                                        <span class="toggle-slider"></span>
                                    </div>
                                </label>
                            </div>
                            <div class="form-group access-toggle-row access-toggle-row--module">
                                <label class="toggle-label">
                                    <span><?php echo t('settings.module_icon_manager'); ?></span>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="moduleIconManager" checked>
                                        <span class="toggle-slider"></span>
                                    </div>
                                </label>
                            </div>
                            <div class="form-group access-toggle-row access-toggle-row--module">
                                <label class="toggle-label">
                                    <span><?php echo t('settings.module_ai'); ?></span>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="moduleAi" checked>
                                        <span class="toggle-slider"></span>
                                    </div>
                                </label>
                            </div>
                        </div>
                        <small class="form-hint"><?php echo t('settings.modules_hint'); ?></small>
                    </fieldset>
                    <button type="submit" class="btn btn-primary" id="saveModulesBtn"><?php echo t('settings.save_modules'); ?></button>
                </form>
            </div>
            <?php endif; ?>

            <!-- My Account Panel -->
            <div class="settings-panel<?php echo !$isAdminUser ? ' active' : ''; ?>" id="settingsPanel-my-account">
                <h2><?php echo t('settings.change_password'); ?></h2>
                <form id="changePasswordForm" class="change-password-form">
                    <div class="form-group">
                        <label for="currentPassword"><?php echo t('settings.current_password'); ?></label>
                        <input type="password" id="currentPassword" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label for="newPassword"><?php echo t('settings.new_password'); ?></label>
                        <input type="password" id="newPassword" name="new_password" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="newPasswordConfirm"><?php echo t('settings.confirm_password'); ?></label>
                        <input type="password" id="newPasswordConfirm" name="new_password_confirm" required autocomplete="new-password">
                    </div>
                    <div class="password-requirements" id="pwReqs">
                        <small><?php echo t('settings.pw_requirements'); ?></small>
                        <ul>
                            <li class="requirement" data-req="length"><?php echo t('settings.pw_length'); ?></li>
                            <li class="requirement" data-req="upper"><?php echo t('settings.pw_upper'); ?></li>
                            <li class="requirement" data-req="lower"><?php echo t('settings.pw_lower'); ?></li>
                            <li class="requirement" data-req="digit"><?php echo t('settings.pw_digit'); ?></li>
                            <li class="requirement" data-req="special"><?php echo t('settings.pw_special'); ?></li>
                            <li class="requirement" data-req="match"><?php echo t('settings.pw_match'); ?></li>
                        </ul>
                    </div>
                    <button type="submit" class="btn btn-primary" id="changePwBtn"><?php echo t('settings.change_password'); ?></button>
                </form>
            </div>

            <?php if ($isAdminUser): ?>
            <!-- Danger Zone Panel -->
            <div class="settings-panel" id="settingsPanel-danger">
                <h2 class="danger-zone-title"><?php echo t('settings.danger_zone'); ?></h2>
                <p class="settings-description"><?php echo t('settings.danger_zone_desc'); ?></p>

                <div class="danger-zone-card">
                    <div class="danger-zone-card__header">
                        <h3><?php echo t('settings.total_reset'); ?></h3>
                        <p><?php echo t('settings.total_reset_desc'); ?></p>
                    </div>
                    <div class="danger-zone-card__warning">
                        <strong>⚠ <?php echo t('settings.total_reset_warning'); ?></strong>
                    </div>
                    <div class="danger-zone-card__action">
                        <label for="totalResetConfirm"><?php echo t('settings.total_reset_confirm_label'); ?></label>
                        <input type="text" id="totalResetConfirm" placeholder="<?php echo t('settings.total_reset_confirm_placeholder'); ?>" autocomplete="off" spellcheck="false">
                        <button type="button" class="btn btn-danger" id="totalResetBtn" disabled><?php echo t('settings.total_reset_btn'); ?></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.settings-panels -->
        </div><!-- /.settings-layout -->
    </div>

    <!-- Backup Tab -->
    <div class="admin-container" id="backupTab" style="display: none;">
        <div class="page-list-header">
            <div class="page-list-header-left">
                <h2><?php echo t('settings.backup'); ?></h2>
            </div>
        </div>
        <p class="page-description"><?php echo t('settings.backup_desc'); ?></p>

        <div class="backup-manual-grid">
            <div class="backup-site-card">
                <div class="backup-site-card__info">
                    <h3><?php echo t('settings.backup_site'); ?></h3>
                    <p><?php echo t('settings.backup_site_desc'); ?></p>
                </div>
                <div class="backup-site-card__action">
                    <button type="button" class="btn btn-primary" id="createSiteBackupBtn">
                        <?php echo nbIcon('download', 16); ?>
                        <span><?php echo t('settings.backup_create'); ?></span>
                    </button>
                    <div class="backup-progress" id="backupProgress" style="display: none;">
                        <div class="backup-progress__spinner"></div>
                        <span id="backupProgressText"><?php echo t('settings.backup_creating'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Restore from Backup -->
            <div class="backup-site-card">
                <div class="backup-site-card__info">
                    <h3><?php echo t('settings.restore_title'); ?></h3>
                    <p><?php echo t('settings.restore_desc'); ?></p>
                </div>
                <div class="backup-site-card__action">
                    <div class="restore-upload-area" id="restoreUploadArea">
                        <input type="file" id="restoreFileInput" accept=".zip" style="display: none;">
                        <button type="button" class="btn btn-secondary" id="restoreSelectBtn">
                            <?php echo nbIcon('upload', 16); ?>
                            <span><?php echo t('settings.restore_select_file'); ?></span>
                        </button>
                        <span class="restore-filename" id="restoreFilename" style="display: none;"></span>
                    </div>

                    <div class="restore-mode-block" id="restoreModeSelector" style="display: none;">
                        <p class="restore-mode-heading"><?php echo t('settings.restore_mode_prompt'); ?></p>
                        <div class="restore-mode-selector">
                            <label class="restore-mode-option">
                                <input type="radio" name="restore_mode" value="content" checked>
                                <div class="restore-mode-card">
                                    <div class="restore-mode-card__header">
                                        <strong><?php echo t('settings.restore_content'); ?></strong>
                                        <span class="restore-mode-indicator" aria-hidden="true"></span>
                                    </div>
                                    <span><?php echo t('settings.restore_content_desc'); ?></span>
                                </div>
                            </label>
                            <label class="restore-mode-option">
                                <input type="radio" name="restore_mode" value="full">
                                <div class="restore-mode-card">
                                    <div class="restore-mode-card__header">
                                        <strong><?php echo t('settings.restore_full'); ?></strong>
                                        <span class="restore-mode-indicator" aria-hidden="true"></span>
                                    </div>
                                    <span><?php echo t('settings.restore_full_desc'); ?></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="restore-actions" id="restoreActions" style="display: none;">
                        <button type="button" class="btn btn-danger" id="restoreBtn">
                            <?php echo nbIcon('upload', 16); ?>
                            <span><?php echo t('settings.restore_btn'); ?></span>
                        </button>
                        <div class="backup-progress" id="restoreProgress" style="display: none;">
                            <div class="backup-progress__spinner"></div>
                            <span id="restoreProgressText"><?php echo t('settings.restore_uploading'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Backup list -->
        <div class="scheduled-list">
            <div class="scheduled-list__header">
                <h4><?php echo t('settings.backup_list_title'); ?></h4>
                <div class="scheduled-storage" id="scheduledStorage">
                    <strong><?php echo t('settings.storage_summary'); ?>:</strong>
                    <span id="scheduledStorageCount">—</span>
                    <span class="scheduled-storage__dates" id="scheduledStorageDates"></span>
                </div>
            </div>
            <table class="scheduled-list__table" id="scheduledList">
                <thead>
                    <tr>
                        <th><?php echo t('settings.backup_list_col_date'); ?></th>
                        <th><?php echo t('settings.backup_list_col_tier'); ?></th>
                        <th><?php echo t('settings.backup_list_col_size'); ?></th>
                        <th><?php echo t('settings.backup_list_col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="scheduledListBody">
                    <tr><td colspan="4" class="scheduled-list__empty"><?php echo t('settings.backup_list_empty'); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Automated / scheduled backups -->
        <div class="backup-site-card backup-scheduled">
            <div class="backup-site-card__info">
                <h3><?php echo t('settings.scheduled_backups'); ?></h3>
                <p><?php echo t('settings.scheduled_backups_desc'); ?></p>
            </div>

            <!-- Settings form -->
            <form id="scheduledBackupForm" class="scheduled-form">
                <label class="toggle-label scheduled-form__toggle">
                    <span><?php echo t('settings.scheduled_enabled'); ?></span>
                    <div class="toggle-switch">
                        <input type="checkbox" id="scheduledEnabled" name="enabled">
                        <span class="toggle-slider"></span>
                    </div>
                </label>
                <p class="scheduled-form__hint"><?php echo t('settings.scheduled_enabled_hint'); ?></p>

                <div class="scheduled-form__mode">
                    <label><?php echo t('settings.cron_mode'); ?></label>
                    <div class="radio-group radio-group--segmented">
                        <label class="radio-option">
                            <input type="radio" name="scheduledCronMode" value="server" checked>
                            <span><?php echo t('settings.cron_mode_server'); ?></span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="scheduledCronMode" value="web">
                            <span><?php echo t('settings.cron_mode_web'); ?></span>
                        </label>
                    </div>
                    <p class="scheduled-form__hint"><?php echo t('settings.cron_mode_hint'); ?></p>
                </div>

                <fieldset class="scheduled-form__fieldset">
                    <legend><?php echo t('settings.retention_title'); ?></legend>
                    <p class="scheduled-form__hint"><?php echo t('settings.retention_hint'); ?></p>
                    <div class="scheduled-form__grid">
                        <label>
                            <span><?php echo t('settings.retention_daily'); ?></span>
                            <input type="number" id="retentionDaily" name="retention_daily" min="0" max="365" step="1">
                        </label>
                        <label>
                            <span><?php echo t('settings.retention_weekly'); ?></span>
                            <input type="number" id="retentionWeekly" name="retention_weekly" min="0" max="52" step="1">
                        </label>
                        <label>
                            <span><?php echo t('settings.retention_monthly'); ?></span>
                            <input type="number" id="retentionMonthly" name="retention_monthly" min="0" max="60" step="1">
                        </label>
                        <label>
                            <span><?php echo t('settings.retention_yearly'); ?></span>
                            <input type="number" id="retentionYearly" name="retention_yearly" min="0" max="20" step="1">
                        </label>
                    </div>
                </fieldset>

                <!-- Status banner: last run + warning if cron stalled -->
                <div class="scheduled-status" id="scheduledStatus" data-state="loading">
                    <div class="scheduled-status__row">
                        <strong><?php echo t('settings.last_run'); ?>:</strong>
                        <span id="scheduledLastRun">—</span>
                    </div>
                    <div class="scheduled-status__message" id="scheduledStatusMessage"></div>
                </div>

                <div class="scheduled-form__side">
                    <label class="scheduled-form__limit">
                        <span><strong><?php echo t('settings.storage_limit'); ?></strong></span>
                        <input type="number" id="storageLimitMb" name="storage_limit_mb" min="0" step="1">
                    </label>
                    <p class="scheduled-form__hint"><?php echo t('settings.storage_limit_hint'); ?></p>
                </div>

                <div class="scheduled-form__actions">
                    <button type="submit" class="btn btn-primary" id="scheduledSaveBtn">
                        <?php echo t('settings.backup_settings_save'); ?>
                    </button>
                </div>
            </form>

            <div class="backup-remote">
                <div class="backup-remote__header">
                    <div>
                        <h4><?php echo t('settings.remote_backups'); ?></h4>
                        <p><?php echo t('settings.remote_backups_hint'); ?></p>
                        <p class="backup-remote__beta-note"><?php echo t('settings.remote_oauth_beta_note'); ?></p>
                    </div>
                    <div class="backup-remote__add">
                        <select id="remoteProviderSelect" aria-label="<?php echo t('settings.remote_add_target'); ?>">
                            <option value="dropbox">Dropbox</option>
                            <option value="google_drive">Google Drive</option>
                            <option value="onedrive">Microsoft OneDrive</option>
                            <option value="sftp">SFTP / SCP</option>
                            <option value="ftp">FTP / FTPS</option>
                            <option value="s3">S3-Compatible</option>
                            <option value="webdav">WebDAV</option>
                        </select>
                        <button type="button" class="btn btn-secondary btn-sm" id="remoteAddBtn">
                            <?php echo t('settings.remote_add_target'); ?>
                        </button>
                    </div>
                </div>
                <div class="backup-remote__list" id="remoteTargetList"></div>
            </div>

            <!-- Cron setup help -->
            <details class="scheduled-cron">
                <summary><?php echo t('settings.cron_setup'); ?></summary>
                <p><?php echo t('settings.cron_setup_hint'); ?></p>
                <?php
                    $sitePath = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
                    $backupScriptPath = $sitePath . '/cli/backup.php';
                ?>
                <div class="scheduled-cron__line">
                    <span><?php echo t('settings.cron_script_path'); ?></span>
                    <code id="cronScriptPath"><?php echo htmlspecialchars($backupScriptPath); ?></code>
                    <button type="button" class="btn btn-secondary btn-sm" data-copy-code="cronScriptPath">
                        <?php echo t('settings.cron_copy'); ?>
                    </button>
                </div>
                <div class="scheduled-cron__line">
                    <span><?php echo t('settings.cron_arguments'); ?></span>
                    <code id="cronArguments">--action=run</code>
                    <button type="button" class="btn btn-secondary btn-sm" data-copy-code="cronArguments">
                        <?php echo t('settings.cron_copy'); ?>
                    </button>
                </div>
                <div class="scheduled-cron__line scheduled-cron__line--fallback">
                    <span><?php echo t('settings.cron_shell_command'); ?></span>
                    <code id="cronShellCommand"><?php echo htmlspecialchars('/absolute/path/to/php ' . escapeshellarg($backupScriptPath) . ' --action=run'); ?></code>
                </div>
                <div class="scheduled-cron__line">
                    <span><?php echo t('settings.web_cron_url'); ?></span>
                    <code id="webCronUrl">—</code>
                    <button type="button" class="btn btn-secondary btn-sm" data-copy-code="webCronUrl">
                        <?php echo t('settings.cron_copy'); ?>
                    </button>
                </div>
                <p class="scheduled-cron__hint"><?php echo t('settings.web_cron_hint'); ?></p>
            </details>
        </div>
    </div>

    <?php if ($isAdminUser): ?>
    <div class="admin-container" id="systemTab" style="display: none;">
        <div class="page-list-header"><h2><?php echo t('system.title'); ?></h2>
            <button type="button" class="btn btn-secondary" onclick="loadSystemStatus()"><?php echo t('btn.refresh'); ?></button></div>
        <div id="systemStatusBody" class="system-status-grid" aria-live="polite"></div>
    </div>
    <?php endif; ?>
    </div><!-- /.admin-main -->
    </div><!-- /.admin-body -->

    <!-- Mail detail is now rendered inline within #mailsTab as #mailDetailView -->


    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="modalOverlay" style="display: none;" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-describedby="modalText">
            <h3 id="modalTitle"><?php echo t('btn.confirm'); ?></h3>
            <div id="modalText" class="modal-text"></div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()"><?php echo t('btn.cancel'); ?></button>
                <button class="btn btn-danger" id="modalConfirm"><?php echo t('btn.confirm'); ?></button>
            </div>
        </div>
    </div>

    <?php if ($isAdminUser): ?>
    <!-- Icon Manager Modal -->
    <div class="modal-overlay" id="iconManagerModalOverlay" style="display: none;">
        <div class="modal modal-large icon-manager-modal">
            <h3 id="iconManagerModalTitle"><?php echo t('icons.edit_icon'); ?></h3>
            <form id="iconManagerForm">
                <input type="hidden" id="iconManagerOldKey" value="">
                <div class="modal-form">
                    <label class="modal-label"><?php echo t('icons.key'); ?>
                        <input type="text" id="iconManagerKey" class="modal-input" required>
                    </label>
                    <p class="form-hint"><?php echo t('icons.key_hint'); ?></p>
                    <label class="modal-label"><?php echo t('icons.label'); ?>
                        <input type="text" id="iconManagerLabel" class="modal-input">
                    </label>
                    <label class="modal-label"><?php echo t('icons.tags'); ?>
                        <input type="text" id="iconManagerTags" class="modal-input" placeholder="media, file, feature">
                    </label>
                    <p class="form-hint"><?php echo t('icons.tags_hint'); ?></p>
                    <label class="modal-label"><?php echo t('icons.viewbox'); ?>
                        <input type="text" id="iconManagerViewBox" class="modal-input" placeholder="0 0 24 24">
                    </label>
                    <div class="icon-manager-svg-field">
                        <label class="modal-label"><?php echo t('icons.svg'); ?>
                            <textarea id="iconManagerSvg" class="modal-input icon-manager-svg-input" required rows="8"></textarea>
                        </label>
                        <div class="icon-manager-svg-tools">
                            <button type="button" class="btn btn-secondary btn-sm" id="iconManagerCleanupBtn"><?php echo t('icons.cleanup'); ?></button>
                            <button type="button" class="btn btn-secondary btn-sm" id="iconManagerCurrentColorBtn"><?php echo t('icons.current_color'); ?></button>
                        </div>
                        <small class="form-hint"><?php echo htmlspecialchars(t('icons.svg_hint'), ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                    <div class="icon-manager-preview-box">
                        <span><?php echo t('icons.preview'); ?></span>
                        <svg id="iconManagerPreview" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg>
                    </div>
                    <div class="info-banner info-banner--warning icon-manager-rename-warning" id="iconRenameWarning" hidden>
                        <div class="info-banner__inner">
                            <strong class="info-banner__title"><?php echo nbIcon('alert'); ?> <?php echo t('icons.rename_warning_title'); ?></strong>
                            <span class="info-banner__body"><?php echo t('icons.rename_warning'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeIconManagerModal()"><?php echo t('btn.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo t('btn.save'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Iconify Import Modal -->
    <div class="modal-overlay" id="iconifyImportModalOverlay" style="display: none;">
        <div class="modal modal-large icon-manager-modal">
            <h3><?php echo t('icons.import_icon'); ?></h3>
            <div class="modal-form">
                <div class="iconify-import-controls">
                    <label class="modal-label" id="iconifyImportSetLabel"><?php echo t('icons.icon_set'); ?>
                        <div class="icon-set-picker" id="iconifyImportSetPicker">
                            <button type="button" class="icon-set-picker__btn modal-input" id="iconifyImportSetBtn" aria-haspopup="listbox" aria-expanded="false">
                                <span id="iconifyImportSetLabel2">Lucide</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                            </button>
                            <ul class="icon-set-picker__list" id="iconifyImportSetList" role="listbox" hidden>
                                <li role="option" data-value="lucide" aria-selected="true">Lucide</li>
                                <li role="option" data-value="tabler">Tabler Icons</li>
                                <li role="option" data-value="heroicons">Heroicons</li>
                                <li role="option" data-value="ph">Phosphor</li>
                                <li role="option" data-value="bi">Bootstrap Icons</li>
                                <li role="option" data-value="iconoir">Iconoir</li>
                                <li role="option" data-value="ion">Ionicons</li>
                                <li role="option" data-value="mynaui">Myna UI</li>
                                <li role="option" data-value="mingcute">MingCute</li>
                                <li role="option" data-value="tdesign">TDesign Icons</li>
                            </ul>
                            <input type="hidden" id="iconifyImportSet" value="lucide">
                        </div>
                    </label>
                    <label class="modal-label"><?php echo t('icons.search'); ?>
                        <input type="search" id="iconifyImportQuery" class="modal-input" placeholder="search, home, calendar">
                    </label>
                    <button type="button" class="btn btn-secondary btn-sm" id="iconifyImportSearchBtn"><?php echo t('icons.search'); ?></button>
                </div>
                <p class="form-hint" id="iconifyImportLicense"><?php echo t('icons.import_license_hint'); ?></p>
                <div class="iconify-import-results" id="iconifyImportResults"></div>
                <p class="trash-empty-msg" id="iconifyImportEmpty" style="display:none;"><?php echo t('icons.import_empty'); ?></p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeIconifyImportModal()"><?php echo t('btn.close'); ?></button>
            </div>
        </div>
    </div>

    <!-- User Modal (Add/Edit) -->
    <div class="modal-overlay" id="userModalOverlay" style="display: none;">
        <div class="modal modal-large">
            <h3 id="userModalTitle"><?php echo t('settings.add_user'); ?></h3>
            <form id="userForm">
                <input type="hidden" id="userFormId" value="">
                <div class="modal-form">
                    <label class="modal-label"><?php echo t('settings.user_username'); ?>
                        <input type="text" id="userFormUsername" class="modal-input" required minlength="3">
                    </label>
                    <label class="modal-label"><?php echo t('settings.user_email'); ?>
                        <input type="email" id="userFormEmail" class="modal-input">
                    </label>
                    <label class="modal-label"><?php echo t('settings.user_role'); ?>
                        <select id="userFormRole" class="modal-input">
                            <option value="admin">Admin</option>
                            <option value="editor">Editor</option>
                        </select>
                    </label>
                    <div id="userFormPasswordGroup">
                        <label class="modal-label"><?php echo t('login.password'); ?>
                            <div class="password-field-row">
                                <input type="password" id="userFormPassword" class="modal-input" autocomplete="new-password">
                                <button type="button" class="btn btn-secondary btn-sm" id="userGenPwBtn"><?php echo t('setup.generate'); ?></button>
                            </div>
                        </label>
                        <div class="generated-password" id="userGeneratedPw" style="display: none;">
                            <code id="userGeneratedPwText"></code>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeUserModal()"><?php echo t('btn.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary" id="userFormSubmit"><?php echo t('btn.save'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal-overlay" id="resetPwModalOverlay" style="display: none;">
        <div class="modal modal-large">
            <h3 id="resetPwModalTitle"><?php echo t('settings.reset_password'); ?></h3>
            <form id="resetPwForm">
                <input type="hidden" id="resetPwUserId" value="">
                <div class="modal-form">
                    <label class="modal-label"><?php echo t('login.password'); ?>
                        <div class="password-field-row">
                            <input type="password" id="resetPwInput" class="modal-input" required minlength="8" autocomplete="new-password">
                            <button type="button" class="btn btn-secondary btn-sm" id="resetPwGenBtn"><?php echo t('setup.generate'); ?></button>
                        </div>
                    </label>
                    <div class="generated-password" id="resetPwGenerated" style="display: none;">
                        <code id="resetPwGeneratedText"></code>
                    </div>
                    <div class="password-requirements" id="resetPwReqs">
                        <small><?php echo t('settings.pw_requirements'); ?></small>
                        <ul>
                            <li class="requirement" data-req="length"><?php echo t('settings.pw_length'); ?></li>
                            <li class="requirement" data-req="upper"><?php echo t('settings.pw_upper'); ?></li>
                            <li class="requirement" data-req="lower"><?php echo t('settings.pw_lower'); ?></li>
                            <li class="requirement" data-req="digit"><?php echo t('settings.pw_digit'); ?></li>
                            <li class="requirement" data-req="special"><?php echo t('settings.pw_special'); ?></li>
                        </ul>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeResetPwModal()"><?php echo t('btn.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo t('btn.save'); ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <link rel="stylesheet" href="../css/revision-client.css?v=<?php echo filemtime(__DIR__ . '/../css/revision-client.css'); ?>">
    <script src="../js/revision-client.js?v=<?php echo filemtime(__DIR__ . '/../js/revision-client.js'); ?>"></script>
    <script src="../js/nb-select.js?v=<?php echo @filemtime(__DIR__ . '/../js/nb-select.js') ?: time(); ?>"></script>
    <script src="../js/image-manager.js?v=<?php echo @filemtime(__DIR__ . '/../js/image-manager.js') ?: time(); ?>"></script>
    <script>
    <?php require __DIR__ . '/dashboard/scripts/bootstrap.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/pages.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/media.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/page-actions.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/navigation.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/icons.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/messages.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/news.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/overview-ai.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/forms.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/settings.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/password.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/backups.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/reset.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/events.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/users-navigation.php'; ?>
    <?php require __DIR__ . '/dashboard/scripts/system.php'; ?>
    </script>
    <?php if ($_aiDashboardCopilotAvailable && is_file(__DIR__ . '/../js/ai-copilot.js')): ?>
    <script src="../js/ai-copilot.js?v=<?php echo @filemtime(__DIR__ . '/../js/ai-copilot.js') ?: time(); ?>"></script>
    <?php endif; ?>
</body>
</html>
