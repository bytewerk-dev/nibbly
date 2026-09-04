<?php
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
<html lang="en" data-site-theme="<?php echo htmlspecialchars($adminTheme === 'system' ? 'light' : $adminTheme); ?>">
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
    <a class="skip-link" href="#adminMain">Skip to main content</a>
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
                        <span class="sr-only">Seitentitel suchen</span>
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

    <script src="../js/nb-select.js?v=<?php echo @filemtime(__DIR__ . '/../js/nb-select.js') ?: time(); ?>"></script>
    <script src="../js/image-manager.js?v=<?php echo @filemtime(__DIR__ . '/../js/image-manager.js') ?: time(); ?>"></script>
    <script>
    // Block Type Registry
    window.BlockTypeRegistry = <?php
        require_once dirname(__DIR__) . '/includes/content-loader.php';
        require_once dirname(__DIR__) . '/includes/menu-helpers.php';
        echo json_encode(getBlockTypes(), JSON_UNESCAPED_UNICODE);
    ?>;

    // Admin translations for JS
    const NB_LANG = <?php echo json_encode(array_merge(tEditorAll(), tAll()), JSON_UNESCAPED_UNICODE); ?>;
    window.NB_LANG = NB_LANG;
    window.NB_AI_FEATURES_ENABLED = <?php echo json_encode($aiFeaturesEnabled); ?>;
    window.NB_AI_COPILOT_AVAILABLE = <?php echo json_encode($_aiDashboardCopilotAvailable); ?>;
    window.NB_AI_COPILOT_MODE = 'dashboard';
    window.NB_AI_ASSISTANT_LANGUAGE = <?php echo json_encode(function_exists('_nbAdminLang') ? _nbAdminLang() : (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en')); ?>;
    window.NB_ADMIN_API_URL = 'api.php';
    window.NB_AI_COPILOT_GET_CONTENT_PAGE = function() {
        if (typeof currentPage === 'string' && currentPage) return currentPage;
        var select = document.getElementById('pageSelect');
        return select && select.value ? select.value : '';
    };
    // Menu registry for Page Settings nav checkboxes
    window.NB_MENUS = <?php echo json_encode(getMenuRegistry()['menus'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    const DASHBOARD_MODULES = <?php echo json_encode($dashboardModules, JSON_UNESCAPED_UNICODE); ?>;
    const AI_FEATURES_ENABLED = DASHBOARD_MODULES.ai !== false;
    const AI_NOTICE_DISMISSED_KEY = 'nibbly.aiUnavailableNotice.dismissed';
    const AI_FEATURE_DEFAULTS = {
        backendAssistant: true,
        seoTextGeneration: true,
        imageGeneration: true
    };
    function t(key, params) {
        let s = NB_LANG[key] || key;
        if (params) { for (const [k, v] of Object.entries(params)) { s = s.replace('{' + k + '}', v); } }
        return s;
    }

    function isDashboardModuleEnabled(tab) {
        const keyByTab = { news: 'news', events: 'events', mails: 'messages', icons: 'iconManager', ai: 'ai' };
        const key = keyByTab[tab];
        if (!key) return true;
        return DASHBOARD_MODULES[key] !== false;
    }

    // SVG icon paths (viewBox 0 0 24 24)
    const ICONS = {
        edit:      '<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        eye:       '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        duplicate: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>',
        trash:     '<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/>',
        'eye-off': '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
        back:      '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        ai:        '<path d="M12 2l1.6 5.2L19 9l-5.4 1.8L12 16l-1.6-5.2L5 9l5.4-1.8L12 2z"/><path d="M19 14l.8 2.7 2.2.8-2.2.8L19 21l-.8-2.7-2.2-.8 2.2-.8L19 14z"/>',
    };

    function icon(name, size = 16, strokeWidth = '1.5') {
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round" width="${size}" height="${size}">${ICONS[name]}</svg>`;
    }

    // Configuration
    const CSRF_TOKEN = '<?php echo $csrfToken; ?>';
    const USER_ROLE = '<?php echo htmlspecialchars($userRole); ?>';

    // Intercept all fetch calls — redirect to login on session expiry
    const _origFetch = window.fetch;
    window.fetch = async function(...args) {
        const response = await _origFetch.apply(this, args);
        if (response.status === 401) {
            try {
                const clone = response.clone();
                const data = await clone.json();
                if (data.session_expired) {
                    window.location.href = 'index.php?timeout=' + Math.floor(Date.now() / 1000);
                    return response;
                }
            } catch(e) {}
        }
        return response;
    };

    let currentPage = null;
    let currentContent = null;
    let sectionCounter = 0;
    const DASHBOARD_LIST_DEFAULT_PAGE_SIZE = 50;
    const dashboardListPages = {
        pages: 1,
        trash: 1,
        events: 1,
        eventsTrash: 1,
        news: 1,
        mails: 1,
        icons: 1
    };

    function clampDashboardPageSize(value) {
        const parsed = parseInt(value, 10);
        if (!Number.isFinite(parsed)) return DASHBOARD_LIST_DEFAULT_PAGE_SIZE;
        return Math.max(10, Math.min(500, parsed));
    }

    function clampMediaPageSize(value) {
        const parsed = parseInt(value, 10);
        if (!Number.isFinite(parsed)) return 25;
        return Math.max(10, Math.min(500, parsed));
    }

    function getDashboardPageSize(kind = 'default') {
        const dashboardSettings = currentSettings?.dashboard || {};
        return kind === 'icons'
            ? clampDashboardPageSize(dashboardSettings.iconManagerItemsPerPage)
            : clampDashboardPageSize(dashboardSettings.itemsPerPage);
    }

    function pageSlice(items, pageKey, pageSize) {
        const list = Array.isArray(items) ? items : [];
        const pages = Math.max(1, Math.ceil(list.length / pageSize));
        const current = Math.max(1, Math.min(dashboardListPages[pageKey] || 1, pages));
        dashboardListPages[pageKey] = current;
        const start = (current - 1) * pageSize;
        return {
            items: list.slice(start, start + pageSize),
            total: list.length,
            current,
            pages,
            from: list.length ? start + 1 : 0,
            to: Math.min(start + pageSize, list.length)
        };
    }

    function renderAdminListFooter(footerId, pageKey, total, pageSize, renderCallback, topFooterId = '') {
        const bottomFooter = document.getElementById(footerId);
        const topFooter = topFooterId ? document.getElementById(topFooterId) : null;
        if (!bottomFooter && !topFooter) return;
        const page = Math.max(1, Math.min(dashboardListPages[pageKey] || 1, Math.max(1, Math.ceil(total / pageSize))));
        const pages = Math.max(1, Math.ceil(total / pageSize));
        const from = total ? ((page - 1) * pageSize) + 1 : 0;
        const to = total ? Math.min(page * pageSize, total) : 0;
        const summary = total
            ? t('lists.summary_range', {from, to, total})
            : t('lists.summary_empty');
        const hasPagination = total > pageSize;

        function paginationIcon(direction) {
            const path = direction === 'prev' ? 'M15 18l-6-6 6-6' : 'M9 18l6-6-6-6';
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' + path + '"/></svg>';
        }

        function addButton(nav, label, targetPage, disabled, currentPage, direction = '') {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'admin-pagination__btn' + (currentPage ? ' is-current' : '');
            if (direction) {
                button.classList.add('admin-pagination__btn--arrow');
                button.innerHTML = paginationIcon(direction);
                button.setAttribute('aria-label', direction === 'prev' ? t('lists.previous_page') : t('lists.next_page'));
            } else {
                button.textContent = label;
            }
            button.disabled = !!disabled;
            if (currentPage) button.setAttribute('aria-current', 'page');
            button.addEventListener('click', function() {
                if (disabled || currentPage) return;
                dashboardListPages[pageKey] = targetPage;
                renderCallback();
            });
            nav.appendChild(button);
        }

        function buildNav() {
            const nav = document.createElement('nav');
            nav.className = 'admin-pagination';
            nav.setAttribute('aria-label', t('lists.pagination'));
            addButton(nav, '', page - 1, page <= 1, false, 'prev');
            const pageNumbers = [];
            for (let i = 1; i <= pages; i++) {
                if (i === 1 || i === pages || Math.abs(i - page) <= 1) pageNumbers.push(i);
            }
            let previous = 0;
            pageNumbers.forEach(number => {
                if (previous && number - previous > 1) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'admin-pagination__ellipsis';
                    ellipsis.textContent = '...';
                    nav.appendChild(ellipsis);
                }
                addButton(nav, String(number), number, false, number === page);
                previous = number;
            });
            addButton(nav, '', page + 1, page >= pages, false, 'next');
            return nav;
        }

        function renderFooter(footer, showOnlyWhenPaginated) {
            if (!footer) return;
            if (showOnlyWhenPaginated && !hasPagination) {
                footer.innerHTML = '';
                footer.hidden = true;
                return;
            }
            footer.hidden = false;
            footer.innerHTML = '<span class="admin-list-summary">' + escapeHtml(summary) + '</span>';
            if (hasPagination) footer.appendChild(buildNav());
        }

        renderFooter(topFooter, true);
        renderFooter(bottomFooter, false);
    }

    // Update page dropdown from pageListCache (both desktop and mobile)
    function updatePageSelect() {
        const lang = document.getElementById('langSelect').value;
        const pageSelect = document.getElementById('pageSelect');
        const pageSelectMobile = document.getElementById('pageSelectMobile');
        pageSelect.innerHTML = '';
        if (pageSelectMobile) pageSelectMobile.innerHTML = '';

        if (!pageListCache) return;

        for (const page of pageListCache.pages) {
            const langInfo = page.languages[lang];
            if (!langInfo || !langInfo.exists) continue;
            const option = document.createElement('option');
            option.value = page.slug;
            option.textContent = langInfo.title || page.title;
            pageSelect.appendChild(option);
            if (pageSelectMobile) pageSelectMobile.appendChild(option.cloneNode(true));
        }
    }

    // Sync selectors between desktop and mobile
    function syncSelect(targetId, value) {
        const target = document.getElementById(targetId);
        if (target) target.value = value;
        updatePageSelect();
    }

    // Page list
    let pageListCache = null;
    let currentSeoHealth = null;
    let pageListSearchQuery = '';

    function pageContentKey(lang, path) {
        return lang + '_' + String(path || '').replaceAll('/', '__');
    }

    async function loadPageList() {
        try {
            const response = await fetch('api.php?action=list-pages&_=' + Date.now());
            const result = await response.json();
            if (result.success) {
                applyPageList(result.data);
            }
        } catch (error) {
            console.error('Error loading page list:', error);
        }
    }

    function normalizePageListSearchValue(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLowerCase();
    }

    function handlePageListSearch(value) {
        pageListSearchQuery = normalizePageListSearchValue(value);
        dashboardListPages.pages = 1;
        if (pageListCache) {
            const viewLang = document.getElementById('pageListLang').value;
            renderPageList(pageListCache, viewLang);
        }
    }

    function applyPageList(pageListData) {
        pageListCache = pageListData;
        const viewLang = document.getElementById('pageListLang').value;
        renderPageList(pageListData, viewLang);
        updatePageSelect();
    }

    function renderPageListForLang(lang) {
        dashboardListPages.pages = 1;
        if (pageListCache) {
            renderPageList(pageListCache, lang);
        }
    }

    function renderPageList(data, viewLang) {
        const { pages, languages } = data;
        const langs = Object.keys(languages);
        const otherLangs = langs.filter(l => l !== viewLang);
        const thead = document.querySelector('#pageListTable thead tr');
        const tbody = document.getElementById('pageListBody');

        // Build header: Title | Date | lang columns...
        thead.innerHTML = '';
        const thTitle = document.createElement('th');
        thTitle.className = 'page-list-col-title page-list-sortable';
        thTitle.dataset.sort = 'title';
        thTitle.innerHTML = t('pages.col_title') + ' <span class="page-list-sort-icon"></span>';
        thTitle.onclick = () => sortPageList('title');
        thead.appendChild(thTitle);

        const thDate = document.createElement('th');
        thDate.className = 'page-list-col-date page-list-sortable';
        thDate.dataset.sort = 'date';
        thDate.innerHTML = t('pages.col_date') + ' <span class="page-list-sort-icon"></span>';
        thDate.onclick = () => sortPageList('date');
        thead.appendChild(thDate);

        otherLangs.forEach(lang => {
            const th = document.createElement('th');
            th.className = 'page-list-col-lang';
            th.textContent = languages[lang];
            thead.appendChild(th);
        });

        // Build rows — only show pages that exist in the view language (or are defined for it)
        tbody.innerHTML = '';
        const visiblePages = pages.filter(page => {
            const viewInfo = page.languages[viewLang];
            // Skip pages that have no entry at all for this language
            if (!viewInfo) return false;
            const titleText = viewInfo.title || page.slug;
            if (pageListSearchQuery && !normalizePageListSearchValue(titleText).includes(pageListSearchQuery)) {
                return false;
            }
            return true;
        });
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(visiblePages, 'pages', pageSize);
        renderAdminListFooter('pageListFooter', 'pages', visiblePages.length, pageSize, function() {
            renderPageList(data, viewLang);
        }, 'pageListFooterTop');

        paged.items.forEach(page => {
            const viewInfo = page.languages[viewLang];
            const tr = document.createElement('tr');
            tr.className = 'page-list-row';

            // Title cell — slug above, title below, hover actions underneath
            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';

            const slugSpan = document.createElement('span');
            slugSpan.className = 'page-list-slug';
            slugSpan.textContent = '/' + page.slug;
            tdTitle.appendChild(slugSpan);

            const titleLink = document.createElement('a');
            titleLink.href = '#';
            titleLink.className = 'page-list-title-link';
            titleLink.textContent = viewInfo.title || page.slug;
            titleLink.onclick = (e) => {
                e.preventDefault();
                openPageFromList(viewLang, page.slug);
            };
            const titleRow = document.createElement('div');
            titleRow.className = 'page-list-title-row';
            titleRow.appendChild(titleLink);
            const seoBadge = createSeoHealthBadge(viewInfo.seoHealth, 'page-list');
            if (seoBadge) titleRow.appendChild(seoBadge);
            tdTitle.appendChild(titleRow);

            // Hover action row (WordPress-style)
            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';

            if (viewInfo.exists) {
                // Edit
                const editLink = document.createElement('a');
                editLink.href = '#';
                editLink.className = 'page-list-row-action';
                editLink.innerHTML = icon('edit', 12, '2') + ' ' + t('pages.edit');
                editLink.onclick = (e) => { e.preventDefault(); openPageFromList(viewLang, page.slug); };
                actions.appendChild(editLink);

                // View
                const sep1 = document.createElement('span');
                sep1.className = 'page-list-row-action-sep';
                sep1.textContent = '|';
                actions.appendChild(sep1);

                const viewLink = document.createElement('a');
                const frontendPath = page.slug === 'home'
                    ? ((viewLang === DEFAULT_LANG) ? '../' : '../' + viewLang + '/')
                    : ((viewLang === DEFAULT_LANG) ? '../' + page.slug : '../' + viewLang + '/' + page.slug);
                viewLink.href = frontendPath;
                viewLink.target = '_blank';
                viewLink.className = 'page-list-row-action';
                viewLink.innerHTML = icon('eye', 12, '2') + ' ' + t('pages.view');
                actions.appendChild(viewLink);

                // Duplicate
                const sep2 = document.createElement('span');
                sep2.className = 'page-list-row-action-sep';
                sep2.textContent = '|';
                actions.appendChild(sep2);

                const dupLink = document.createElement('a');
                dupLink.href = '#';
                dupLink.className = 'page-list-row-action';
                dupLink.innerHTML = icon('duplicate', 12, '2') + ' ' + t('pages.duplicate');
                dupLink.onclick = async (e) => {
                    e.preventDefault();
                    dupLink.classList.add('disabled');
                    try {
                        const result = await duplicatePage(viewLang, page.slug);
                        showToast(t('toast.page_duplicated', {slug: result.slug}), 'success');
                        if (result.pageList) applyPageList(result.pageList);
                    } catch (err) {
                        showToast(err.message, 'error');
                        dupLink.classList.remove('disabled');
                    }
                };
                actions.appendChild(dupLink);

                // Trash
                const sep3 = document.createElement('span');
                sep3.className = 'page-list-row-action-sep';
                sep3.textContent = '|';
                actions.appendChild(sep3);

                const trashLink = document.createElement('a');
                trashLink.href = '#';
                trashLink.className = 'page-list-row-action page-list-row-action--danger';
                trashLink.innerHTML = icon('trash', 12, '2') + ' ' + t('pages.trash');
                trashLink.onclick = async (e) => {
                    e.preventDefault();
                    const pageName = pageContentKey(viewLang, page.slug);
                    try {
                        const result = await deletePage(pageName);
                        showToast(t('toast.page_trashed'), 'success');
                        if (result.pageList) applyPageList(result.pageList);
                    } catch (err) {
                        showToast(err.message, 'error');
                    }
                };
                actions.appendChild(trashLink);
            }

            tdTitle.appendChild(actions);
            tr.appendChild(tdTitle);

            // Date cell — show date for this language
            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            if (viewInfo.lastModified) {
                const d = new Date(viewInfo.lastModified);
                tdDate.textContent = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            } else {
                tdDate.textContent = '—';
            }
            tr.appendChild(tdDate);

            // Other language columns
            otherLangs.forEach(lang => {
                const td = document.createElement('td');
                td.className = 'page-list-cell-lang';
                const langInfo = page.languages[lang];
                if (langInfo && langInfo.exists) {
                    const editBtn = document.createElement('a');
                    editBtn.href = '#';
                    editBtn.className = 'btn btn-secondary btn-sm page-list-lang-btn';
                    editBtn.innerHTML = icon('edit', 12) + ' ' + t('pages.edit');
                    editBtn.onclick = (e) => {
                        e.preventDefault();
                        openPageFromList(lang, page.slug);
                    };
                    td.appendChild(editBtn);
                } else if (viewInfo.exists) {
                    // Page doesn't exist in this language — offer to create as copy from view language
                    const createLink = document.createElement('a');
                    createLink.href = '#';
                    createLink.className = 'page-list-create-link';
                    createLink.textContent = t('pages.create');
                    createLink.onclick = async (e) => {
                        e.preventDefault();
                        createLink.classList.add('disabled');
                        createLink.textContent = '...';
                        try {
                            const result = await copyPageToLang(viewLang, page.slug, lang, page.slug);
                            showToast(t('toast.page_created', {title: lang + '_' + page.slug}), 'success');
                            if (result.data?.pageList) applyPageList(result.data.pageList);
                        } catch (err) {
                            showToast(err.message, 'error');
                            createLink.classList.remove('disabled');
                            createLink.textContent = t('pages.create');
                        }
                    };
                    td.appendChild(createLink);
                } else {
                    td.textContent = '—';
                }
                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });

        if (visiblePages.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.className = 'page-list-empty';
            td.colSpan = 2 + otherLangs.length;
            td.textContent = pageListSearchQuery
                ? 'Keine Seiten mit diesem Titel gefunden.'
                : 'Keine Seiten vorhanden.';
            tr.appendChild(td);
            tbody.appendChild(tr);
        }

        // Update sort indicators
        updateSortIndicators();
    }

    // Sort state
    let pageListSortField = 'title';
    let pageListSortDir = 'asc';

    function sortPageList(field) {
        if (pageListSortField === field) {
            pageListSortDir = pageListSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            pageListSortField = field;
            pageListSortDir = 'asc';
        }
        if (pageListCache) {
            const viewLang = document.getElementById('pageListLang').value;
            // Sort the pages array in place
            pageListCache.pages.sort((a, b) => {
                let valA, valB;
                if (field === 'title') {
                    const aInfo = a.languages[viewLang];
                    const bInfo = b.languages[viewLang];
                    valA = (aInfo?.title || a.slug).toLowerCase();
                    valB = (bInfo?.title || b.slug).toLowerCase();
                } else {
                    const aInfo = a.languages[viewLang];
                    const bInfo = b.languages[viewLang];
                    valA = aInfo?.lastModified || '';
                    valB = bInfo?.lastModified || '';
                }
                let cmp = valA < valB ? -1 : valA > valB ? 1 : 0;
                return pageListSortDir === 'asc' ? cmp : -cmp;
            });
            renderPageList(pageListCache, viewLang);
        }
    }

    function updateSortIndicators() {
        document.querySelectorAll('.page-list-sortable').forEach(th => {
            const icon = th.querySelector('.page-list-sort-icon');
            if (th.dataset.sort === pageListSortField) {
                th.classList.add('sorted');
                icon.textContent = pageListSortDir === 'asc' ? '▲' : '▼';
            } else {
                th.classList.remove('sorted');
                icon.textContent = '';
            }
        });
    }

    function openPageFromList(lang, slug) {
        // Set selectors and load
        document.getElementById('langSelect').value = lang;
        updatePageSelect();
        document.getElementById('pageSelect').value = slug;
        const m = document.getElementById('langSelectMobile');
        if (m) m.value = lang;
        const pm = document.getElementById('pageSelectMobile');
        if (pm) pm.value = slug;
        loadContent();
    }

    function getPageSeoHealth(lang, slug) {
        const pageData = pageListCache?.pages?.find(p => p.slug === slug);
        return pageData?.languages?.[lang]?.seoHealth || null;
    }

    function setPageSeoHealth(lang, slug, seoHealth) {
        if (!pageListCache || !seoHealth) return;
        const pageData = pageListCache.pages?.find(p => p.slug === slug);
        if (pageData?.languages?.[lang]) {
            pageData.languages[lang].seoHealth = seoHealth;
        }
    }

    function createSeoHealthBadge(health, context = '') {
        if (!health) return null;
        const status = ['green', 'yellow', 'red'].includes(health.status) ? health.status : 'yellow';
        const score = Number.isFinite(Number(health.score)) ? Number(health.score) : 0;
        const label = health.label || 'SEO';
        const issues = Array.isArray(health.issues) ? health.issues : [];
        const badge = document.createElement('span');
        badge.className = `seo-health-badge seo-health-badge--${status}${context ? ' seo-health-badge--' + context : ''}`;
        badge.tabIndex = 0;
        badge.setAttribute('role', 'status');
        badge.setAttribute('aria-label', [label + ' ' + score + '/100'].concat(issues).join('. '));
        badge.title = [label + ' ' + score + '/100'].concat(issues).join('\n');
        badge.innerHTML = `
            <span class="seo-health-badge__dot" aria-hidden="true"></span>
            <span class="seo-health-badge__text">${escapeHtml(label)}</span>
            <span class="seo-health-badge__score">${score}/100</span>
        `;
        if (issues.length) {
            const popover = document.createElement('span');
            popover.className = 'seo-health-badge__popover';
            popover.innerHTML = `<strong>${escapeHtml(label)}</strong><ul>${issues.map(issue => `<li>${escapeHtml(issue)}</li>`).join('')}</ul>`;
            badge.appendChild(popover);
        }
        return badge;
    }

    function updateEditorSeoHealth(health) {
        const holder = document.getElementById('editorSeoHealth');
        if (!holder) return;
        holder.innerHTML = '';
        const badge = createSeoHealthBadge(health, 'editor');
        if (badge) holder.appendChild(badge);
    }

    async function copyPageToLang(sourceLang, sourceSlug, targetLang, targetSlug) {
        const formData = new FormData();
        formData.append('action', 'copy-page');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('source', pageContentKey(sourceLang, sourceSlug));
        formData.append('targetLang', targetLang);
        formData.append('slug', targetSlug);

        const response = await fetch('api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Error creating page');
        }
        return result;
    }

    async function duplicatePage(lang, slug) {
        const formData = new FormData();
        formData.append('action', 'duplicate-page');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('source', pageContentKey(lang, slug));

        const response = await fetch('api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Error duplicating page');
        }
        return result.data;
    }

    async function deletePage(pageName) {
        const formData = new FormData();
        formData.append('action', 'delete-page');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('page', pageName);

        const response = await fetch('api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Error deleting page');
        }
        return result.data;
    }

    async function trashCurrentPage() {
        if (!currentPage) return;
        try {
            const result = await deletePage(currentPage);
            showToast(t('toast.page_trashed'), 'success');
            if (result.pageList) applyPageList(result.pageList);
            showPageList();
        } catch (err) {
            showToast(err.message, 'error');
        }
    }

    function showPageList(pushHistory = true) {
        document.getElementById('pageListContainer').style.display = 'block';
        document.getElementById('trashContainer').style.display = 'none';
        document.getElementById('editorContainer').style.display = 'none';
        document.getElementById('backupContainer').style.display = 'none';
        currentPage = null;
        currentContent = null;
        // Update topbar
        const topbarTitle = document.getElementById('topbarTitle');
        if (topbarTitle) topbarTitle.textContent = t('pages.title');
        // Hide topbar selectors, show them only when editing
        const topbarSelectors = document.getElementById('topbarSelectors');
        if (topbarSelectors) topbarSelectors.style.display = 'none';
        loadPageList();
        if (pushHistory) {
            updateDashboardHash('content');
        }
    }

    // ============================================================
    // TRASH
    // ============================================================

    async function showTrash() {
        document.getElementById('pageListContainer').style.display = 'none';
        document.getElementById('editorContainer').style.display = 'none';
        document.getElementById('backupContainer').style.display = 'none';
        document.getElementById('trashContainer').style.display = 'block';
        const topbarTitle = document.getElementById('topbarTitle');
        if (topbarTitle) topbarTitle.textContent = t('trash.title');
        const topbarSelectors = document.getElementById('topbarSelectors');
        if (topbarSelectors) topbarSelectors.style.display = 'none';
        await loadTrash();
    }

    async function loadTrash() {
        try {
            const response = await fetch('api.php?action=list-trash&_=' + Date.now());
            const result = await response.json();
            if (result.success) {
                renderTrash(result.data);
            }
        } catch (error) {
            console.error('Error loading trash:', error);
        }
    }

    function renderTrash(items) {
        const tbody = document.getElementById('trashBody');
        const emptyMsg = document.getElementById('trashEmptyMsg');
        const emptyBtn = document.getElementById('emptyTrashBtn');
        const table = document.getElementById('trashTable');
        tbody.innerHTML = '';

        if (!items || items.length === 0) {
            table.style.display = 'none';
            emptyMsg.style.display = 'block';
            emptyBtn.style.display = 'none';
            renderAdminListFooter('trashListFooter', 'trash', 0, getDashboardPageSize(), function() {
                renderTrash(items);
            }, 'trashListFooterTop');
            return;
        }

        table.style.display = '';
        emptyMsg.style.display = 'none';
        emptyBtn.style.display = '';

        const pageSize = getDashboardPageSize();
        const paged = pageSlice(items, 'trash', pageSize);
        renderAdminListFooter('trashListFooter', 'trash', items.length, pageSize, function() {
            renderTrash(items);
        }, 'trashListFooterTop');

        paged.items.forEach(item => {
            const tr = document.createElement('tr');

            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';
            tdTitle.textContent = item.title;
            tr.appendChild(tdTitle);

            const tdPage = document.createElement('td');
            tdPage.textContent = item.page;
            tdPage.className = 'page-list-cell-slug';
            tr.appendChild(tdPage);

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            tdDate.textContent = item.deletedDate + ' ' + item.deletedTime;
            tr.appendChild(tdDate);

            const tdActions = document.createElement('td');
            tdActions.className = 'page-list-cell-actions';

            const restoreBtn = document.createElement('button');
            restoreBtn.className = 'btn btn-primary btn-sm';
            restoreBtn.textContent = t('btn.restore');
            restoreBtn.onclick = async () => {
                restoreBtn.disabled = true;
                restoreBtn.textContent = '...';
                try {
                    const formData = new FormData();
                    formData.append('action', 'restore-page');
                    formData.append('csrf_token', CSRF_TOKEN);
                    formData.append('filename', item.filename);
                    const response = await fetch('api.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        showToast(t('toast.page_restored'), 'success');
                        if (result.data?.pageList) applyPageList(result.data.pageList);
                        loadTrash();
                    } else {
                        showToast(result.message || 'Error', 'error');
                        restoreBtn.disabled = false;
                        restoreBtn.textContent = t('btn.restore');
                    }
                } catch (err) {
                    showToast(err.message, 'error');
                    restoreBtn.disabled = false;
                    restoreBtn.textContent = t('btn.restore');
                }
            };
            tdActions.appendChild(restoreBtn);

            const delBtn = document.createElement('button');
            delBtn.className = 'btn btn-danger btn-sm';
            delBtn.textContent = t('btn.delete');
            delBtn.onclick = () => {
                showModal(t('modal.delete_permanently'), t('modal.delete_confirm', {title: item.title, page: item.page}), async () => {
                    closeModal();
                    try {
                        const formData = new FormData();
                        formData.append('action', 'delete-trash');
                        formData.append('csrf_token', CSRF_TOKEN);
                        formData.append('filename', item.filename);
                        const response = await fetch('api.php', { method: 'POST', body: formData });
                        const result = await response.json();
                        if (result.success) {
                            showToast(t('toast.page_deleted'), 'success');
                            loadTrash();
                        } else {
                            showToast(result.message || 'Error', 'error');
                        }
                    } catch (err) {
                        showToast(err.message, 'error');
                    }
                });
            };
            tdActions.appendChild(delBtn);

            tr.appendChild(tdActions);
            tbody.appendChild(tr);
        });
    }

    async function emptyTrash() {
        showModal(t('modal.empty_trash'), t('modal.empty_trash_confirm'), async () => {
            closeModal();
            try {
                const formData = new FormData();
                formData.append('action', 'empty-trash');
                formData.append('csrf_token', CSRF_TOKEN);
                const response = await fetch('api.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message || t('toast.trash_emptied'), 'success');
                    loadTrash();
                } else {
                    showToast(result.message || 'Error', 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        });
    }

    // New page modal
    function showNewPageModal() {
        const lang = document.getElementById('pageListLang').value;
        const overlay = document.getElementById('modalOverlay');
        const title = document.getElementById('modalTitle');
        const text = document.getElementById('modalText');
        const confirmBtn = document.getElementById('modalConfirm');

        title.textContent = t('modal.new_page');
        text.innerHTML =
            '<div class="modal-form">' +
                '<label class="modal-label">' + t('modal.new_page_title') + '<input type="text" id="newPageTitle" class="modal-input" placeholder="' + t('modal.new_page_title_placeholder') + '" autofocus></label>' +
                '<label class="modal-label">' + t('modal.new_page_slug') + '<input type="text" id="newPageSlug" class="modal-input" placeholder="' + t('modal.new_page_slug_placeholder') + '"><span class="modal-hint">' + t('modal.new_page_slug_hint') + '</span></label>' +
            '</div>';

        confirmBtn.textContent = t('modal.create_page');
        confirmBtn.className = 'btn btn-primary';
        confirmBtn.style.display = '';
        overlay.style.display = 'flex';
        overlay.setAttribute('aria-hidden', 'false');

        const titleInput = document.getElementById('newPageTitle');
        const slugInput = document.getElementById('newPageSlug');
        let slugManuallyEdited = false;

        titleInput.addEventListener('input', () => {
            if (!slugManuallyEdited) {
                slugInput.value = titleInput.value
                    .toLowerCase()
                    .replace(/[äöüß]/g, m => ({ä:'ae',ö:'oe',ü:'ue',ß:'ss'})[m])
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '');
            }
        });
        slugInput.addEventListener('input', () => { slugManuallyEdited = true; });

        confirmBtn.onclick = async () => {
            const pageTitle = titleInput.value.trim();
            const pageSlug = slugInput.value.trim();
            if (!pageTitle) { titleInput.focus(); return; }
            if (!pageSlug || !/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/.test(pageSlug)) {
                slugInput.focus();
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = '...';
            try {
                const result = await createPage(lang, pageTitle, pageSlug);
                closeModal();
                showToast(t('toast.page_created', {title: pageTitle}), 'success');
                if (result.pageList) applyPageList(result.pageList);
            } catch (err) {
                showToast(err.message, 'error');
                confirmBtn.disabled = false;
                confirmBtn.textContent = t('modal.create_page');
            }
        };

        setTimeout(() => titleInput.focus(), 100);
    }

    async function createPage(lang, title, slug) {
        const formData = new FormData();
        formData.append('action', 'create-page');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('lang', lang);
        formData.append('title', title);
        formData.append('slug', slug);

        const response = await fetch('api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Error creating page');
        }
        return result.data;
    }

    // Load content
    async function loadContent(pushHistory = true) {
        const lang = document.getElementById('langSelect').value;
        const page = document.getElementById('pageSelect').value;
        currentPage = pageContentKey(lang, page);

        try {
            const response = await fetch(`api.php?action=load&page=${currentPage}`);
            const result = await response.json();

            if (result.success) {
                currentContent = result.data;
                currentSeoHealth = getPageSeoHealth(lang, page);
                clearUndoHistory();
                renderEditor();
                loadBackups();
                // Hide page list, show editor
                document.getElementById('pageListContainer').style.display = 'none';
                document.getElementById('editorContainer').style.display = 'block';
                document.getElementById('backupContainer').style.display = 'block';
                document.getElementById('toggleAllBtn').style.display = '';
                allExpanded = false;
                document.getElementById('toggleAllBtn').textContent = t('editor.expand_all');
                // Show topbar selectors when editing
                const topbarSelectors = document.getElementById('topbarSelectors');
                if (topbarSelectors) topbarSelectors.style.display = 'flex';
                // Update topbar title
                const topbarTitle = document.getElementById('topbarTitle');
                if (topbarTitle) topbarTitle.textContent = t('editor.title');
                // Update View button URL
                const _defLang = '<?php echo SITE_LANG_DEFAULT; ?>';
                const _viewUrl = (lang === _defLang) ? '../' + page : '../' + lang + '/' + page;
                const viewBtn = document.getElementById('editorViewBtn');
                if (viewBtn) viewBtn.href = _viewUrl;

                // Push history state so browser back button returns to page list
                if (pushHistory) {
                    history.pushState({ view: 'editor', page: currentPage }, '', DASHBOARD_PATH + '#page/' + currentPage);
                }

            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    // Read-only meta keys that should not be editable
    const META_KEYS = new Set(['page', 'lang', 'lastModified']);
    // Keys that get their own special renderer
    const SPECIAL_KEYS = new Set(['sections']);
    // Keys rendered in the dedicated "Page Settings" panel
    const PAGE_SETTINGS_KEYS = new Set(['title', 'description', 'nav', 'breadcrumb', 'visibility', 'seo']);

    function isEditableTopLevelContentKey(key) {
        return !META_KEYS.has(key) && !PAGE_SETTINGS_KEYS.has(key) && !SPECIAL_KEYS.has(key);
    }

    function createEditorShell(container) {
        const shell = document.createElement('div');
        shell.className = 'ce-editor-shell';

        const tabs = document.createElement('div');
        tabs.className = 'ce-editor-tabs';
        const tabDefs = [
            ['content', t('editor.tab_content')],
            ['settings', t('editor.page_settings')],
            ['seo', t('editor.seo')],
            ['access', t('editor.settings_access')]
        ];
        tabDefs.forEach(([id, label], index) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ce-editor-tab' + (index === 0 ? ' active' : '');
            btn.dataset.editorTab = id;
            btn.textContent = label;
            btn.addEventListener('click', () => activateEditorTab(id));
            tabs.appendChild(btn);
        });
        shell.appendChild(tabs);

        const panelsWrap = document.createElement('div');
        panelsWrap.className = 'ce-editor-panels';
        const panels = {};
        tabDefs.forEach(([id], index) => {
            const panel = document.createElement('div');
            panel.className = 'ce-editor-panel' + (index === 0 ? ' active' : '');
            panel.dataset.editorPanel = id;
            panels[id] = panel;
            panelsWrap.appendChild(panel);
        });
        shell.appendChild(panelsWrap);
        container.appendChild(shell);
        return panels;
    }

    function activateEditorTab(tabId) {
        document.querySelectorAll('.ce-editor-tab').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.editorTab === tabId);
        });
        document.querySelectorAll('.ce-editor-panel').forEach(panel => {
            panel.classList.toggle('active', panel.dataset.editorPanel === tabId);
        });
    }

    // Render the page metadata panels (basics, SEO, access, navigation)
    function renderPageSettings(panels) {
        const createSettingsSection = (panel, className, title, subtitle = '') => {
            const section = document.createElement('section');
            section.className = `ce-settings-card ${className}`;
            const header = document.createElement('div');
            header.className = 'ce-settings-card__header';
            header.innerHTML = `<h4>${title}</h4>${subtitle ? `<p>${subtitle}</p>` : ''}`;
            section.appendChild(header);
            const sectionBody = document.createElement('div');
            sectionBody.className = 'ce-settings-card__body';
            section.appendChild(sectionBody);
            panel.appendChild(section);
            return sectionBody;
        };

        const basicsBody = createSettingsSection(panels.settings, 'ce-settings-card--basics', t('editor.settings_basics'));
        const navBody = createSettingsSection(panels.settings, 'ce-settings-card--navigation', t('editor.settings_navigation'));
        const seoBody = createSettingsSection(panels.seo, 'ce-settings-card--seo', t('editor.seo'), t('editor.seo_hint'));
        const accessBody = createSettingsSection(panels.access, 'ce-settings-card--access', t('editor.settings_access'));

        // Title
        const titleField = document.createElement('div');
        titleField.className = 'ce-field';
        titleField.innerHTML = `<label class="ce-field-label">${t('editor.meta_title')}</label>`;
        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'ce-input';
        titleInput.value = currentContent.title || '';
        titleInput.dataset.path = 'title';
        titleInput.addEventListener('input', () => markDirty());
        titleField.appendChild(titleInput);
        basicsBody.appendChild(titleField);

        // Description
        const descField = document.createElement('div');
        descField.className = 'ce-field';
        descField.innerHTML = `<label class="ce-field-label">${t('editor.meta_description')}</label>`;
        const descInput = document.createElement('textarea');
        descInput.className = 'ce-textarea';
        descInput.rows = 2;
        descInput.value = currentContent.description || '';
        descInput.dataset.path = 'description';
        descInput.addEventListener('input', () => markDirty());
        descField.appendChild(descInput);
        basicsBody.appendChild(descField);

        const seo = currentContent.seo || {};
        const seoField = document.createElement('div');
        seoField.className = 'ce-field ce-field--stacked';
        const seoWrap = document.createElement('div');
        seoWrap.className = 'ce-seo-grid';
        seoWrap.innerHTML = `
            ${AI_FEATURES_ENABLED ? `<div class="ce-ai-seo-actions ce-form-tile--wide">
                <button type="button" class="btn btn-secondary btn-sm ce-ai-fill-all" onclick="generateSeoFields('all')">${icon('ai', 14)} ${t('editor.ai_fill_seo')}</button>
                <span class="ce-ai-status" id="seoAiStatus"></span>
            </div>` : ''}
            <label class="ce-form-tile ce-form-tile--wide"><span class="ce-label-with-action">${t('editor.seo_title')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('title')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><input type="text" class="ce-input" id="seoTitle" value="${escapeHtml(seo.title || '')}"></label>
            <label class="ce-form-tile ce-form-tile--wide ce-form-tile--resizable"><span class="ce-label-with-action">${t('editor.seo_description')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('description')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><textarea class="ce-textarea ce-textarea--manual-resize" id="seoDescription" rows="2">${escapeHtml(seo.description || '')}</textarea><span class="ce-textarea-resize-handle" aria-hidden="true"></span></label>
            <label class="ce-form-tile ce-form-tile--wide ce-form-tile--resizable"><span class="ce-label-with-action">${t('editor.seo_answer_summary')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('answerSummary')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><textarea class="ce-textarea ce-textarea--manual-resize" id="seoAnswerSummary" rows="2">${escapeHtml(seo.answerSummary || '')}</textarea><span class="ce-textarea-resize-handle" aria-hidden="true"></span></label>
            <label class="ce-form-tile"><span>${t('editor.seo_canonical')}</span><input type="url" class="ce-input" id="seoCanonical" value="${escapeHtml(seo.canonical || '')}"></label>
            <label class="ce-form-tile"><span>Robots</span><select class="ce-input" id="seoRobots">
                    <option value="index, follow">${t('editor.seo_robots_index')}</option>
                    <option value="noindex, follow">${t('editor.seo_robots_noindex')}</option>
                    <option value="noindex, nofollow">${t('editor.seo_robots_private')}</option>
                </select></label>
            <label class="ce-form-tile"><span class="ce-label-with-action">${t('editor.seo_og_title')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('ogTitle')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><input type="text" class="ce-input" id="seoOgTitle" value="${escapeHtml(seo.ogTitle || '')}"></label>
            <label class="ce-form-tile ce-form-tile--resizable"><span class="ce-label-with-action">${t('editor.seo_og_description')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('ogDescription')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><textarea class="ce-textarea ce-textarea--manual-resize" id="seoOgDescription" rows="2">${escapeHtml(seo.ogDescription || '')}</textarea><span class="ce-textarea-resize-handle" aria-hidden="true"></span></label>
            <div class="ce-form-tile ce-form-tile--wide">
                <span>${t('editor.seo_og_image')}</span>
                <div class="ce-image-input-row">
                    <input type="text" class="ce-input" id="seoOgImage" placeholder="/assets/images/og-image.jpg" value="${escapeHtml(seo.ogImage || '')}">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="browseSeoOgImage()">${t('btn.browse')}</button>
                </div>
                ${AI_FEATURES_ENABLED ? `<p class="ai-field-hint">${t('ai.image_field_hint')} <button type="button" class="btn btn-secondary btn-sm" onclick="openAiImageGenerator(getSeoOgImagePrompt(), '16:9')">${t('ai.open_image_generator')}</button></p>` : ''}
            </div>
            <label class="ce-nav-check ce-nav-check--standalone"><input type="checkbox" id="seoSitemap"> ${t('editor.seo_sitemap')}</label>
        `;
        seoWrap.querySelector('#seoRobots').value = seo.robots || 'index, follow';
        seoWrap.querySelector('#seoSitemap').checked = seo.sitemap !== false;
        seoWrap.querySelectorAll('input, textarea, select').forEach(inp => inp.addEventListener('input', () => markDirty()));
        seoWrap.querySelectorAll('input[type="checkbox"]').forEach(inp => inp.addEventListener('change', () => markDirty()));
        seoField.appendChild(seoWrap);
        seoBody.appendChild(seoField);
        refreshSeoAiButtons();
        if (AI_FEATURES_ENABLED && !currentAiSettings) {
            loadAiSettings().then(refreshSeoAiButtons).catch(refreshSeoAiButtons);
        }

        // Nav locations
        const navField = document.createElement('div');
        navField.className = 'ce-field ce-field--stacked';
        navField.innerHTML = `<label class="ce-card-field-label">${t('editor.nav_locations')}</label>`;
        const navRow = document.createElement('div');
        navRow.className = 'ce-nav-checkboxes';
        const navLocations = currentContent.nav || ['header'];
        const registeredMenus = window.NB_MENUS || {};
        const menuIds = Object.keys(registeredMenus);
        const customLocations = navLocations.filter(l => !menuIds.includes(l));
        const adminLang = document.getElementById('langSelect')?.value || document.documentElement.lang || 'en';

        menuIds.forEach(menuId => {
            const menu = registeredMenus[menuId];
            const label = document.createElement('label');
            label.className = 'ce-nav-check';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.dataset.navLocation = menuId;
            cb.checked = navLocations.includes(menuId);
            cb.addEventListener('change', () => markDirty());
            label.appendChild(cb);
            const displayName = menu.label[adminLang] || menu.label['en'] || menuId;
            label.appendChild(document.createTextNode(' ' + displayName));
            navRow.appendChild(label);
        });

        navField.appendChild(navRow);

        // Custom locations (add-row pattern)
        const customContainer = document.createElement('div');
        customContainer.id = 'navCustomContainer';
        customContainer.className = 'ce-breadcrumb-editor ce-nav-custom-editor';
        customLocations.forEach(loc => {
            customContainer.appendChild(createNavCustomRow(loc));
        });
        const addNavBtn = document.createElement('button');
        addNavBtn.type = 'button';
        addNavBtn.className = 'btn btn-secondary btn-sm';
        addNavBtn.textContent = '+ ' + t('editor.nav_add_custom');
        addNavBtn.addEventListener('click', () => {
            customContainer.insertBefore(createNavCustomRow(''), addNavBtn);
            markDirty();
        });
        customContainer.appendChild(addNavBtn);
        navField.appendChild(customContainer);
        navBody.appendChild(navField);

        // Breadcrumb editor
        const bcField = document.createElement('div');
        bcField.className = 'ce-field ce-field--stacked';
        bcField.innerHTML = `<label class="ce-card-field-label">${t('editor.breadcrumb')}</label>
            <small class="form-hint ce-card-field-hint">${t('editor.breadcrumb_hint')}</small>`;
        const bcContainer = document.createElement('div');
        bcContainer.id = 'breadcrumbEditor';
        bcContainer.className = 'ce-breadcrumb-editor';

        const crumbs = currentContent.breadcrumb || [];
        crumbs.forEach((crumb, i) => bcContainer.appendChild(createBreadcrumbRow(crumb, i)));

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn btn-secondary btn-sm';
        addBtn.textContent = '+ ' + t('editor.breadcrumb_add');
        addBtn.addEventListener('click', () => {
            bcContainer.insertBefore(createBreadcrumbRow({label: '', href: ''}, bcContainer.children.length), addBtn);
            markDirty();
        });
        bcContainer.appendChild(addBtn);
        bcField.appendChild(bcContainer);
        navBody.appendChild(bcField);

        // Visibility / password protection
        const visibility = currentContent.visibility || {};
        const visField = document.createElement('div');
        visField.className = 'ce-field ce-field--stacked';
        const visWrap = document.createElement('div');
        visWrap.className = 'ce-visibility-grid';
        visWrap.innerHTML = `
            <label class="ce-form-tile"><span>${t('editor.visibility')}</span><select class="ce-input" id="pageVisibilityStatus">
                    <option value="public">${t('editor.visibility_public')}</option>
                    <option value="private">${t('editor.visibility_private')}</option>
                </select></label>
            <label class="ce-form-tile"><span>${t('editor.visibility_password')}</span><input type="password" class="ce-input" id="pageVisibilityPassword"></label>
            <label class="ce-form-tile"><span>${t('editor.visibility_title')}</span><input type="text" class="ce-input" id="pageVisibilityTitle" value="${escapeHtml(visibility.title || '')}"></label>
            <label class="ce-form-tile ce-form-tile--wide"><span>${t('editor.visibility_text')}</span><textarea class="ce-textarea" id="pageVisibilityText" rows="2">${escapeHtml(visibility.text || '')}</textarea></label>
            <small class="form-hint">${visibility.passwordHash ? t('editor.visibility_password_set') : t('editor.visibility_password_hint')}</small>
        `;
        visWrap.querySelector('#pageVisibilityStatus').value = visibility.status || 'public';
        visWrap.querySelectorAll('input, textarea, select').forEach(inp => inp.addEventListener('input', () => markDirty()));
        visField.appendChild(visWrap);
        accessBody.appendChild(visField);

    }

    function seoAiIsUsable() {
        var settings = currentAiSettings || {};
        return !!settings.enabled && !!(settings.features && settings.features.seoTextGeneration) && aiProviderIsConfigured(settings);
    }

    function refreshSeoAiButtons() {
        var usable = seoAiIsUsable();
        document.querySelectorAll('.ce-ai-fill-all, .ce-ai-field-btn').forEach(function(btn) {
            btn.disabled = !usable;
            btn.title = usable ? t('editor.ai_fill_field') : t('ai.not_configured_text');
        });
        document.querySelectorAll('.ai-field-hint').forEach(function(hint) {
            hint.hidden = !dashboardAiImageUsable;
        });
        var status = document.getElementById('seoAiStatus');
        if (status) status.textContent = usable ? '' : t('editor.ai_unavailable');
    }

    function collectSeoAiContext() {
        collectPageSettings();
        var selectedLang = document.getElementById('langSelect')?.value || currentContent.lang || '';
        var selectedSlug = document.getElementById('pageSelect')?.value || currentPage || '';
        return {
            lang: selectedLang,
            slug: selectedSlug,
            title: currentContent.title || '',
            description: currentContent.description || '',
            seo: currentContent.seo || {},
            contentText: extractContentText(currentContent).slice(0, 9000)
        };
    }

    function extractContentText(value) {
        var chunks = [];
        var seen = new WeakSet();
        function walk(node, key) {
            if (node == null) return;
            if (typeof node === 'string') {
                var clean = node.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                if (clean && !/^(src|href|url|image|icon|id|slug|class|style)$/i.test(String(key || ''))) {
                    chunks.push(clean);
                }
                return;
            }
            if (typeof node === 'number' || typeof node === 'boolean') return;
            if (typeof node !== 'object' || seen.has(node)) return;
            seen.add(node);
            if (Array.isArray(node)) {
                node.forEach(function(item) { walk(item, key); });
                return;
            }
            Object.keys(node).forEach(function(childKey) {
                if (['seo', 'nav', 'breadcrumb', 'visibility', 'lastModified'].includes(childKey)) return;
                walk(node[childKey], childKey);
            });
        }
        walk(value, '');
        return chunks.join('\n').slice(0, 12000);
    }

    async function generateSeoFields(field) {
        if (!seoAiIsUsable()) {
            showToast(t('ai.not_configured_text'), 'error');
            refreshSeoAiButtons();
            return;
        }
        var buttons = Array.from(document.querySelectorAll('.ce-ai-fill-all, .ce-ai-field-btn'));
        var status = document.getElementById('seoAiStatus');
        buttons.forEach(function(btn) { btn.disabled = true; });
        if (status) status.textContent = t('editor.ai_generating');
        try {
            var formData = new FormData();
            formData.append('action', 'ai-generate-seo');
            formData.append('field', field || 'all');
            formData.append('context', JSON.stringify(collectSeoAiContext()));
            formData.append('csrf_token', CSRF_TOKEN);
            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();
            if (!result.success) throw new Error(result.message || t('toast.error'));
            applySeoAiFields(result.data && result.data.fields ? result.data.fields : {});
            updateAiUsage(result.data ? result.data.limits : null);
            markDirty();
            if (status) status.textContent = t('editor.ai_done');
        } catch (error) {
            if (status) status.textContent = '';
            showToast(error.message, 'error');
        } finally {
            refreshSeoAiButtons();
        }
    }

    function applySeoAiFields(fields) {
        var map = {
            title: 'seoTitle',
            description: 'seoDescription',
            answerSummary: 'seoAnswerSummary',
            ogTitle: 'seoOgTitle',
            ogDescription: 'seoOgDescription'
        };
        Object.keys(map).forEach(function(key) {
            if (typeof fields[key] !== 'string') return;
            var el = document.getElementById(map[key]);
            if (!el) return;
            el.value = fields[key];
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function createNavCustomRow(value) {
        const row = document.createElement('div');
        row.className = 'ce-breadcrumb-row';
        row.innerHTML = `
            <input type="text" class="ce-input ce-input--sm" placeholder="${t('editor.nav_custom_hint')}" value="${escapeHtml(value)}" data-nav-custom>
            <button type="button" class="btn btn-secondary btn-sm ce-breadcrumb-remove" onclick="this.parentElement.remove(); markDirty();">&times;</button>
        `;
        row.querySelector('input').addEventListener('input', () => markDirty());
        return row;
    }

    function createBreadcrumbRow(crumb, index) {
        const row = document.createElement('div');
        row.className = 'ce-breadcrumb-row';
        row.innerHTML = `
            <input type="text" class="ce-input ce-input--sm" placeholder="${t('editor.breadcrumb_label')}" value="${escapeHtml(crumb.label || '')}" data-bc-label>
            <input type="text" class="ce-input ce-input--sm" placeholder="${t('editor.breadcrumb_href')}" value="${escapeHtml(crumb.href || '')}" data-bc-href>
            <button type="button" class="btn btn-secondary btn-sm ce-breadcrumb-remove" onclick="this.parentElement.remove(); markDirty();">&times;</button>
        `;
        row.querySelectorAll('input').forEach(inp => inp.addEventListener('input', () => markDirty()));
        return row;
    }

    // Collect nav locations and breadcrumb from the page settings panel
    function collectPageSettings() {
        // Nav locations
        const registeredIds = Object.keys(window.NB_MENUS || {});
        const navLocs = [];
        registeredIds.forEach(menuId => {
            const cb = document.querySelector(`[data-nav-location="${menuId}"]`);
            if (cb && cb.checked) navLocs.push(menuId);
        });
        document.querySelectorAll('#navCustomContainer [data-nav-custom]').forEach(input => {
            const loc = input.value.trim();
            if (loc && !navLocs.includes(loc)) navLocs.push(loc);
        });
        currentContent.nav = navLocs;

        // Breadcrumb
        const rows = document.querySelectorAll('#breadcrumbEditor .ce-breadcrumb-row');
        if (rows.length > 0) {
            const crumbs = [];
            rows.forEach(row => {
                const label = row.querySelector('[data-bc-label]')?.value?.trim();
                const href = row.querySelector('[data-bc-href]')?.value?.trim();
                if (label) {
                    const crumb = { label };
                    if (href) crumb.href = href;
                    crumbs.push(crumb);
                }
            });
            if (crumbs.length > 0) {
                currentContent.breadcrumb = crumbs;
            } else {
                delete currentContent.breadcrumb;
            }
        } else {
            delete currentContent.breadcrumb;
        }

        const visStatus = document.getElementById('pageVisibilityStatus')?.value || 'public';
        const visPassword = document.getElementById('pageVisibilityPassword')?.value || '';
        const visTitle = document.getElementById('pageVisibilityTitle')?.value?.trim() || '';
        const visText = document.getElementById('pageVisibilityText')?.value?.trim() || '';
        if (visStatus === 'private') {
            currentContent.visibility = Object.assign({}, currentContent.visibility || {}, {
                status: 'private',
                title: visTitle,
                text: visText
            });
            if (visPassword) currentContent.visibility.password = visPassword;
        } else {
            currentContent.visibility = { status: 'public', title: visTitle, text: visText };
        }

        currentContent.seo = {
            title: document.getElementById('seoTitle')?.value?.trim() || '',
            description: document.getElementById('seoDescription')?.value?.trim() || '',
            answerSummary: document.getElementById('seoAnswerSummary')?.value?.trim() || '',
            canonical: document.getElementById('seoCanonical')?.value?.trim() || '',
            robots: document.getElementById('seoRobots')?.value || 'index, follow',
            ogTitle: document.getElementById('seoOgTitle')?.value?.trim() || '',
            ogDescription: document.getElementById('seoOgDescription')?.value?.trim() || '',
            ogImage: document.getElementById('seoOgImage')?.value?.trim() || '',
            sitemap: document.getElementById('seoSitemap')?.checked !== false
        };
    }

    function getSectionPreview(section, maxLength = 72) {
        const formatPreview = (value) => {
            const clean = value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            if (!maxLength || clean.length <= maxLength) return clean;
            return clean.slice(0, maxLength) + '…';
        };
        if (!section || typeof section !== 'object') return '';
        const preferred = ['title', 'heading', 'headline', 'text', 'content', 'caption', 'src', 'image', 'url'];
        for (const key of preferred) {
            const value = section[key];
            if (typeof value === 'string' && value.trim()) {
                return formatPreview(value);
            }
        }
        for (const value of Object.values(section)) {
            if (typeof value === 'string' && value.trim() && value.length <= 120) {
                return formatPreview(value);
            }
        }
        return '';
    }

    let sectionObserver = null;
    let activeSectionIndex = 0;
    let sectionScrollLockIndex = null;
    let sectionScrollLockTimer = null;
    let sectionDragIndex = null;
    let sectionCompactMode = localStorage.getItem('nibblySectionCompactMode') === '1';

    function getSectionTypeLabel(section) {
        const def = window.BlockTypeRegistry?.[section?.type];
        return def?.label || section?.type || 'Section';
    }

    function getSectionHeading(section, index) {
        const typeLabel = getSectionTypeLabel(section);
        const preview = getSectionPreview(section);
        return preview ? `${index + 1}. ${typeLabel} - ${preview}` : `${index + 1}. ${typeLabel}`;
    }

    function getSectionSearchText(section, index) {
        return [
            index + 1,
            section?.type || '',
            getSectionTypeLabel(section),
            getSectionPreview(section, 0),
            section?.id || ''
        ].join(' ').toLowerCase();
    }

    function getSectionIssues(section, index, sections) {
        const issues = [];
        const def = window.BlockTypeRegistry?.[section?.type];
        if (def?.fields) {
            def.fields.forEach(field => {
                const value = section?.[field.key];
                if ((field.type === 'image' || field.type === 'audio') && (!value || !String(value).trim())) {
                    issues.push(t('editor.missing_media'));
                }
            });
        }
        if (section?.type === 'image' && section.src && !String(section.alt || '').trim()) {
            issues.push(t('editor.missing_alt'));
        }
        const level = String(section?.level || section?.titleTag || '').toLowerCase();
        if (level === 'h1') {
            const h1Before = sections.slice(0, index).some(item => String(item?.level || item?.titleTag || '').toLowerCase() === 'h1');
            if (h1Before) issues.push(t('editor.duplicate_h1'));
        }
        return issues;
    }

    function renderSectionInsertControls(index) {
        const wrap = document.createElement('div');
        wrap.className = 'ce-section-insert';
        wrap.dataset.insertIndex = index;
        let html = `<button type="button" class="ce-section-insert__trigger" onclick="toggleSectionInsertMenu(this)">${t('editor.insert_here')}</button>`;
        html += '<div class="ce-section-insert__menu" hidden>';
        if (window.BlockTypeRegistry) {
            for (const [type, def] of Object.entries(window.BlockTypeRegistry)) {
                html += `<button type="button" onclick="addSection('${type}', ${index})">+ ${escapeHtml(def.label || type)}</button>`;
            }
        }
        html += '</div>';
        wrap.innerHTML = html;
        return wrap;
    }

    function toggleSectionInsertMenu(btn) {
        const menu = btn.parentElement?.querySelector('.ce-section-insert__menu');
        if (!menu) return;
        const nextHidden = !menu.hidden;
        document.querySelectorAll('.ce-section-insert__menu').forEach(el => { el.hidden = true; });
        menu.hidden = nextHidden;
    }

    function getEditorSectionItems() {
        return document.querySelectorAll('[data-editor-section-kind]');
    }

    function applySectionFilter() {
        const query = (document.getElementById('sectionSearchInput')?.value || '').trim().toLowerCase();
        const type = document.getElementById('sectionTypeFilter')?.value || '';
        let visible = 0;
        getEditorSectionItems().forEach(item => {
            const matchesQuery = !query || (item.dataset.search || '').includes(query);
            const matchesType = !type || item.dataset.type === type;
            const show = matchesQuery && matchesType;
            item.hidden = !show;
            const navItem = document.querySelector(`.ce-section-nav__item[data-editor-section-id="${item.dataset.editorSectionId}"]`);
            if (navItem) {
                navItem.hidden = false;
                navItem.classList.toggle('is-filtered-out', !show);
                navItem.setAttribute('aria-disabled', show ? 'false' : 'true');
            }
            if (show) visible++;
        });
        document.querySelectorAll('.ce-section-insert').forEach(el => { el.hidden = !!query || !!type; });
        const empty = document.getElementById('sectionFilterEmpty');
        if (empty) empty.hidden = visible !== 0;
    }

    function setActiveSection(index) {
        const items = Array.from(getEditorSectionItems());
        if (!items.length) return;
        activeSectionIndex = Math.max(0, Math.min(index, items.length - 1));
        items.forEach(item => {
            const isActive = Number(item.dataset.index) === activeSectionIndex;
            item.classList.toggle('is-active', isActive);
            if (sectionCompactMode && isActive) item.classList.add('is-open');
        });
        document.querySelectorAll('.ce-section-nav__item').forEach(item => {
            item.classList.toggle('is-active', Number(item.dataset.sectionIndex) === activeSectionIndex);
        });
    }

    function initSectionObserver() {
        if (sectionObserver) sectionObserver.disconnect();
        const items = getEditorSectionItems();
        if (!items.length) return;
        sectionObserver = new IntersectionObserver(entries => {
            if (sectionScrollLockIndex !== null) {
                setActiveSection(sectionScrollLockIndex);
                return;
            }
            const visible = entries
                .filter(entry => entry.isIntersecting)
                .sort((a, b) => Math.abs(a.boundingClientRect.top - 110) - Math.abs(b.boundingClientRect.top - 110));
            if (visible[0]) setActiveSection(Number(visible[0].target.dataset.index));
        }, { rootMargin: '-110px 0px -55% 0px', threshold: [0, 0.1, 0.35] });
        items.forEach(item => sectionObserver.observe(item));
        setActiveSection(activeSectionIndex);
    }

    function scrollToSection(index) {
        const target = Array.from(getEditorSectionItems()).find(item => Number(item.dataset.index) === index);
        if (!target) return;
        sectionScrollLockIndex = index;
        if (sectionScrollLockTimer) clearTimeout(sectionScrollLockTimer);
        sectionScrollLockTimer = setTimeout(() => {
            sectionScrollLockIndex = null;
            sectionScrollLockTimer = null;
        }, 800);
        setActiveSection(index);
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function toggleCompactMode(force) {
        sectionCompactMode = typeof force === 'boolean' ? force : !sectionCompactMode;
        localStorage.setItem('nibblySectionCompactMode', sectionCompactMode ? '1' : '0');
        document.querySelectorAll('.ce-content-layout').forEach(layout => {
            layout.classList.toggle('ce-content-layout--compact', sectionCompactMode);
        });
        const btn = document.getElementById('sectionCompactToggle');
        if (btn) {
            btn.classList.toggle('active', sectionCompactMode);
            btn.setAttribute('aria-pressed', sectionCompactMode ? 'true' : 'false');
        }
        if (sectionCompactMode) {
            document.querySelectorAll('.section-item').forEach(item => {
                item.classList.toggle('is-open', Number(item.dataset.index) === activeSectionIndex);
                const sectionBtn = item.querySelector('.section-toggle-btn');
                if (sectionBtn) {
                    const open = item.classList.contains('is-open');
                    sectionBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    sectionBtn.title = open ? t('editor.collapse_section') : t('editor.expand_section');
                }
            });
        } else {
            document.querySelectorAll('.section-item').forEach(item => item.classList.add('is-open'));
        }
    }

    function toggleSectionOpen(index) {
        const item = document.getElementById(`section-${index}`);
        if (!item) return;
        item.classList.toggle('is-open');
        const btn = item.querySelector('.section-toggle-btn');
        if (btn) {
            const open = item.classList.contains('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.title = open ? t('editor.collapse_section') : t('editor.expand_section');
        }
    }

    function reorderSection(fromIndex, toIndex) {
        if (!currentContent?.sections || fromIndex === toIndex) return;
        if (toIndex < 0 || toIndex >= currentContent.sections.length) return;
        pushUndoSnapshot();
        const openTypes = sectionCompactMode
            ? Array.from(document.querySelectorAll('.section-item.is-open')).map(item => Number(item.dataset.index))
            : [];
        const [moved] = currentContent.sections.splice(fromIndex, 1);
        currentContent.sections.splice(toIndex, 0, moved);
        renderEditor();
        if (sectionCompactMode) {
            openTypes.forEach(oldIndex => {
                let next = oldIndex;
                if (oldIndex === fromIndex) next = toIndex;
                else if (fromIndex < toIndex && oldIndex > fromIndex && oldIndex <= toIndex) next = oldIndex - 1;
                else if (fromIndex > toIndex && oldIndex >= toIndex && oldIndex < fromIndex) next = oldIndex + 1;
                document.getElementById(`section-${next}`)?.classList.add('is-open');
            });
        }
        setActiveSection(toIndex);
        markDirty();
    }

    function getGroupSearchText(key, value) {
        const parts = [key];
        const seen = new WeakSet();
        function walk(node) {
            if (node == null) return;
            if (typeof node === 'string' || typeof node === 'number' || typeof node === 'boolean') {
                parts.push(String(node));
                return;
            }
            if (typeof node !== 'object' || seen.has(node)) return;
            seen.add(node);
            if (Array.isArray(node)) {
                node.slice(0, 20).forEach(walk);
                return;
            }
            Object.entries(node).slice(0, 30).forEach(([childKey, childValue]) => {
                parts.push(childKey);
                walk(childValue);
            });
        }
        walk(value);
        return parts.join(' ').toLowerCase();
    }

    function getGroupPreview(value) {
        if (value === null || typeof value !== 'object') return '';
        for (const key of ['title', 'heading', 'eyebrow', 'intro', 'text']) {
            if (typeof value[key] === 'string' && value[key].trim()) {
                return value[key].trim().slice(0, 160);
            }
        }
        return getObjectPreview(value);
    }

    function createSectionNavLink({index, id, label, preview, type, draggable = false, onClick, onDragStart, onDrop}) {
        const link = document.createElement('a');
        link.href = `#${id}`;
        link.className = 'ce-section-nav__item';
        link.dataset.sectionIndex = index;
        link.dataset.editorSectionId = id;
        link.dataset.type = type || '';
        if (draggable) link.setAttribute('draggable', 'true');
        link.innerHTML = `<span>${index + 1}. ${escapeHtml(label)}</span>${preview ? `<small title="${escapeHtml(preview)}">${escapeHtml(preview)}</small>` : ''}`;
        link.addEventListener('click', (e) => {
            e.preventDefault();
            if (link.classList.contains('is-filtered-out')) return;
            if (typeof onClick === 'function') onClick(e);
            else scrollToSection(index);
        });
        if (draggable) {
            link.addEventListener('dragstart', e => {
                if (typeof onDragStart === 'function') onDragStart(e);
                link.classList.add('is-dragging');
            });
            link.addEventListener('dragend', () => {
                sectionDragIndex = null;
                document.querySelectorAll('.is-dragging, .is-drop-target').forEach(el => el.classList.remove('is-dragging', 'is-drop-target'));
            });
            link.addEventListener('dragover', e => {
                e.preventDefault();
                link.classList.add('is-drop-target');
            });
            link.addEventListener('dragleave', () => link.classList.remove('is-drop-target'));
            link.addEventListener('drop', e => {
                e.preventDefault();
                link.classList.remove('is-drop-target');
                if (typeof onDrop === 'function') onDrop(e);
            });
        }
        return link;
    }

    function renderContentPanel(panel, customContentKeys = []) {
        panel.innerHTML = '';
        const sections = Array.isArray(currentContent.sections) ? currentContent.sections : [];
        const customGroups = customContentKeys.map(key => ({
            key,
            value: currentContent[key],
            isNavigable: currentContent[key] !== null && typeof currentContent[key] === 'object'
        }));
        const navigableGroups = customGroups.filter(group => group.isNavigable);
        const hasLegacySections = sections.length > 0;
        const itemCount = hasLegacySections ? sections.length : navigableGroups.length;

        const layout = document.createElement('div');
        layout.className = 'ce-content-layout';
        layout.classList.toggle('ce-content-layout--compact', sectionCompactMode);

        const nav = document.createElement('aside');
        nav.className = 'ce-section-nav';
        nav.innerHTML = `<div class="ce-section-nav__title">${t('editor.sections')}</div>
            <div class="ce-section-nav__tools">
                <input type="search" id="sectionSearchInput" class="ce-section-nav__search" placeholder="${t('editor.search_sections')}">
                <select id="sectionTypeFilter" class="ce-section-nav__select">
                    <option value="">${t('editor.filter_all_types')}</option>
                </select>
            </div>`;
        const typeFilter = nav.querySelector('#sectionTypeFilter');
        const seenTypes = new Set();
        sections.forEach(section => {
            if (!section?.type || seenTypes.has(section.type)) return;
            seenTypes.add(section.type);
            const option = document.createElement('option');
            option.value = section.type;
            option.textContent = getSectionTypeLabel(section);
            typeFilter.appendChild(option);
        });
        if (!hasLegacySections && navigableGroups.length) {
            const option = document.createElement('option');
            option.value = 'field-group';
            option.textContent = t('editor.field_groups');
            typeFilter.appendChild(option);
        }
        const navList = document.createElement('div');
        navList.className = 'ce-section-nav__list';
        nav.appendChild(navList);
        const navEmpty = document.createElement('p');
        navEmpty.id = 'sectionFilterEmpty';
        navEmpty.className = 'ce-section-nav__empty';
        navEmpty.hidden = true;
        navEmpty.textContent = t('editor.no_section_matches');
        nav.appendChild(navEmpty);

        const editor = document.createElement('div');
        editor.className = 'ce-section-editor';
        const header = document.createElement('div');
        header.className = 'ce-section-editor__header';
        header.innerHTML = `<h3>${t('editor.sections')}</h3>
            <div class="ce-section-editor__tools">
                <span>${hasLegacySections ? t('editor.items', {count: itemCount}) : t('editor.field_groups_count', {count: itemCount})}</span>
                ${hasLegacySections ? `<button type="button" class="btn btn-secondary btn-sm" id="sectionCompactToggle" aria-pressed="${sectionCompactMode ? 'true' : 'false'}" onclick="toggleCompactMode()">${t('editor.compact_mode')}</button>` : ''}
            </div>`;
        editor.appendChild(header);

        const legacyContainer = document.createElement('div');
        legacyContainer.id = 'sectionsLegacyContainer';
        legacyContainer.className = 'ce-section-list';
        editor.appendChild(legacyContainer);

        if (sections.length > 0) {
            legacyContainer.appendChild(renderSectionInsertControls(0));
            sections.forEach((section, index) => {
                const issues = getSectionIssues(section, index, sections);
                addSectionElement(section, index, legacyContainer);
                legacyContainer.appendChild(renderSectionInsertControls(index + 1));
                const typeLabel = getSectionTypeLabel(section);
                const preview = getSectionPreview(section, 240);
                const link = createSectionNavLink({
                    index,
                    id: `section-${index}`,
                    label: typeLabel,
                    preview,
                    type: section.type || '',
                    draggable: true,
                    onDragStart: e => {
                        sectionDragIndex = index;
                        e.dataTransfer.effectAllowed = 'move';
                    },
                    onDrop: () => reorderSection(sectionDragIndex, index)
                });
                if (issues.length) {
                    link.insertAdjacentHTML('beforeend', `<em title="${escapeHtml(issues.join('\n'))}">${issues.length}</em>`);
                }
                navList.appendChild(link);
            });
        } else if (navigableGroups.length > 0) {
            legacyContainer.hidden = true;
            navigableGroups.forEach((group, index) => {
                navList.appendChild(createSectionNavLink({
                    index,
                    id: `custom-section-${group.key}`,
                    label: group.key,
                    preview: getGroupPreview(group.value),
                    type: 'field-group'
                }));
            });
        } else {
            navList.innerHTML = `<p class="ce-section-nav__empty">${t('editor.no_sections')}</p>`;
            legacyContainer.hidden = true;
        }

        if (hasLegacySections || customGroups.length === 0) {
            const addBtns = document.createElement('div');
            addBtns.className = 'add-section-container';
            let addBtnsHtml = '<p>' + t('editor.add_section') + '</p><div class="add-section-buttons">';
            if (window.BlockTypeRegistry) {
                for (const [type, def] of Object.entries(window.BlockTypeRegistry)) {
                    addBtnsHtml += `<button class="btn btn-secondary btn-sm" onclick="addSection('${type}')">+ ${def.label}</button>`;
                }
            }
            addBtnsHtml += '</div>';
            addBtns.innerHTML = addBtnsHtml;
            editor.appendChild(addBtns);
        }

        customGroups.forEach(group => {
            const groupEl = renderJsonGroup(group.key, group.value, group.key);
            if (group.isNavigable) {
                const groupIndex = navigableGroups.findIndex(item => item.key === group.key);
                groupEl.id = `custom-section-${group.key}`;
                groupEl.classList.add('ce-custom-section');
                groupEl.dataset.index = groupIndex;
                groupEl.dataset.type = 'field-group';
                groupEl.dataset.search = getGroupSearchText(group.key, group.value);
                groupEl.dataset.editorSectionId = groupEl.id;
                groupEl.dataset.editorSectionKind = 'field-group';
            }
            editor.appendChild(groupEl);
        });

        layout.appendChild(nav);
        layout.appendChild(editor);
        panel.appendChild(layout);
        nav.querySelector('#sectionSearchInput')?.addEventListener('input', applySectionFilter);
        nav.querySelector('#sectionTypeFilter')?.addEventListener('change', applySectionFilter);
        if (hasLegacySections) toggleCompactMode(sectionCompactMode);
        initSectionObserver();
    }

    // Render editor — generic JSON-to-form
    function renderEditor() {
        const container = document.getElementById('sectionsContainer');
        container.innerHTML = '';
        sectionCounter = 0;

        const lang = document.getElementById('langSelect').value;
        const page = document.getElementById('pageSelect').value;
        const pageData = pageListCache?.pages?.find(p => p.slug === page);
        document.getElementById('editorTitle').textContent = pageData?.languages?.[lang]?.title || pageData?.title || page;
        updateEditorSeoHealth(currentSeoHealth || pageData?.languages?.[lang]?.seoHealth || null);

        if (currentContent.lastModified) {
            document.getElementById('lastModified').textContent =
                t('editor.last_saved', {date: formatDateShort(currentContent.lastModified)});
        } else {
            document.getElementById('lastModified').textContent = t('editor.not_saved_yet');
        }

        // Render meta info (read-only)
        const metaDiv = document.createElement('div');
        metaDiv.className = 'ce-meta';
        metaDiv.innerHTML = `<span class="ce-meta-item"><strong>${t('editor.meta_page')}</strong> ${escapeHtml(currentContent.page || currentPage)}</span>
            <span class="ce-meta-item"><strong>${t('editor.meta_lang')}</strong> ${escapeHtml(currentContent.lang || lang)}</span>`;
        container.appendChild(metaDiv);

        const panels = createEditorShell(container);
        renderPageSettings(panels);
        const customContentKeys = Object.keys(currentContent).filter(isEditableTopLevelContentKey);
        renderContentPanel(panels.content, customContentKeys);

        // Auto-resize all textareas
        container.querySelectorAll('textarea.ce-textarea:not(.ce-textarea--manual-resize)').forEach(autoResizeTextarea);
        initManualTextareaResize(container);
    }

    // Save/restore open state of groups across re-renders
    function getOpenGroupPaths() {
        const open = new Set();
        document.querySelectorAll('.ce-group--open[data-group-path], .ce-array-item[data-group-path]').forEach(el => {
            // For array items, check if their body is visible
            if (el.classList.contains('ce-array-item')) {
                const body = el.querySelector('.ce-array-item-body');
                if (body && body.style.display !== 'none') open.add(el.dataset.groupPath);
            } else {
                open.add(el.dataset.groupPath);
            }
        });
        return open;
    }

    function restoreOpenGroupPaths(openPaths) {
        if (!openPaths || !openPaths.size) return;
        // Restore groups
        document.querySelectorAll('.ce-group[data-group-path]').forEach(el => {
            if (openPaths.has(el.dataset.groupPath)) {
                const header = el.querySelector('.ce-group-header');
                if (header) toggleGroup(header);
            }
        });
        // Restore array items
        document.querySelectorAll('.ce-array-item[data-group-path]').forEach(el => {
            if (openPaths.has(el.dataset.groupPath)) {
                const header = el.querySelector('.ce-array-item-header');
                if (header) toggleArrayItemBody(header);
            }
        });
    }

    // Toggle a collapsible group
    function toggleGroup(header) {
        const body = header.nextElementSibling;
        const chevron = header.querySelector('.ce-chevron');
        const isOpen = body.style.display !== 'none';
        body.style.display = isOpen ? 'none' : 'block';
        chevron.textContent = isOpen ? '▶' : '▼';
        header.parentElement.classList.toggle('ce-group--open', !isOpen);
    }

    // Toggle all groups open/closed
    let allExpanded = false;
    function toggleAllGroups() {
        allExpanded = !allExpanded;
        const btn = document.getElementById('toggleAllBtn');
        btn.textContent = allExpanded ? t('editor.collapse_all') : t('editor.expand_all');

        document.querySelectorAll('#sectionsContainer .ce-group-header').forEach(header => {
            const body = header.nextElementSibling;
            if (!body) return;
            const chevron = header.querySelector('.ce-chevron');
            body.style.display = allExpanded ? 'block' : 'none';
            if (chevron) chevron.textContent = allExpanded ? '▼' : '▶';
            header.parentElement.classList.toggle('ce-group--open', allExpanded);
        });

        // Also toggle array item bodies
        document.querySelectorAll('#sectionsContainer .ce-array-item-header').forEach(header => {
            const body = header.nextElementSibling;
            if (!body || !body.classList.contains('ce-array-item-body')) return;
            const chevron = header.querySelector('.ce-chevron');
            body.style.display = allExpanded ? 'block' : 'none';
            if (chevron) chevron.textContent = allExpanded ? '▼' : '▶';
        });
    }

    // Render a top-level or nested group
    function renderJsonGroup(key, value, path) {
        const div = document.createElement('div');
        div.className = 'ce-group';
        div.dataset.groupPath = path;

        const isArray = Array.isArray(value);
        const isObject = value !== null && typeof value === 'object' && !isArray;
        let countLabel = '';
        if (isArray) countLabel = `<span class="ce-group-count">${t('editor.items', {count: value.length})}</span>`;
        else if (isObject) countLabel = `<span class="ce-group-count">${t('editor.fields', {count: Object.keys(value).length})}</span>`;

        div.innerHTML = `<div class="ce-group-header" onclick="toggleGroup(this)">
            <span class="ce-chevron">▶</span>
            <span class="ce-group-title">${escapeHtml(key)}</span>
            ${countLabel}
        </div>
        <div class="ce-group-body" style="display:none;"></div>`;

        const body = div.querySelector('.ce-group-body');

        if (isArray) {
            renderArrayField(body, value, path);
        } else if (isObject) {
            renderObjectFields(body, value, path);
        } else {
            // Primitive at top level (rare)
            body.appendChild(renderPrimitiveField(key, value, path));
        }

        return div;
    }

    // Render object fields (key-value pairs)
    function renderObjectFields(container, obj, basePath) {
        for (const [k, v] of Object.entries(obj)) {
            if (v !== null && typeof v === 'object') {
                // Nested object or array — render as sub-group
                const subGroup = renderJsonGroup(k, v, basePath + '.' + k);
                subGroup.classList.add('ce-group--nested');
                container.appendChild(subGroup);
            } else {
                container.appendChild(renderPrimitiveField(k, v, basePath + '.' + k));
            }
        }
    }

    // Render an array field with add/remove/reorder
    function renderArrayField(container, arr, basePath) {
        const list = document.createElement('div');
        list.className = 'ce-array-list';
        list.dataset.path = basePath;

        arr.forEach((item, index) => {
            const itemEl = renderArrayItem(item, index, basePath, arr.length);
            list.appendChild(itemEl);
        });

        container.appendChild(list);

        // Add button
        const addBtn = document.createElement('button');
        addBtn.className = 'btn btn-secondary btn-sm ce-array-add';
        addBtn.textContent = t('editor.add_item');
        addBtn.onclick = function() { addArrayItem(basePath); };
        container.appendChild(addBtn);
    }

    // Render a single array item
    function renderArrayItem(item, index, basePath, totalCount) {
        const div = document.createElement('div');
        div.className = 'ce-array-item';
        div.dataset.index = index;
        div.dataset.groupPath = basePath + '.' + index;

        const isObject = item !== null && typeof item === 'object' && !Array.isArray(item);

        // Header with controls
        const header = document.createElement('div');
        header.className = 'ce-array-item-header';

        if (isObject) {
            // Show a preview of the first string value
            const preview = getObjectPreview(item);
            header.innerHTML = `<span class="ce-chevron" style="cursor:pointer;" onclick="toggleArrayItemBody(this.closest('.ce-array-item-header'))">▶</span>
                <span class="ce-array-item-label" onclick="toggleArrayItemBody(this.closest('.ce-array-item-header'))">${index} — <span class="ce-preview-text">${escapeHtml(preview)}</span></span>`;
        } else {
            header.innerHTML = `<span class="ce-array-item-label">${index}</span>`;
        }

        // Action buttons
        const actions = document.createElement('div');
        actions.className = 'ce-array-item-actions';
        actions.innerHTML = `<button class="btn btn-sm btn-secondary" onclick="moveArrayItem('${basePath}', ${index}, -1)" ${index === 0 ? 'disabled' : ''}>↑</button>
            <button class="btn btn-sm btn-secondary" onclick="moveArrayItem('${basePath}', ${index}, 1)" ${index === totalCount - 1 ? 'disabled' : ''}>↓</button>
            <button class="btn btn-sm btn-danger" onclick="removeArrayItem('${basePath}', ${index})">${icon('trash', 14)}</button>`;
        header.appendChild(actions);
        div.appendChild(header);

        if (isObject) {
            // Collapsible body
            const body = document.createElement('div');
            body.className = 'ce-array-item-body';
            body.style.display = 'none';
            renderObjectFields(body, item, basePath + '.' + index);
            div.appendChild(body);
        } else {
            // Inline primitive
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'ce-input';
            input.value = item != null ? String(item) : '';
            input.dataset.path = basePath + '.' + index;
            input.addEventListener('input', function() { markDirty(); });
            div.appendChild(input);
        }

        return div;
    }

    // Toggle array item body (object items)
    function toggleArrayItemBody(header) {
        const body = header.nextElementSibling;
        if (!body || !body.classList.contains('ce-array-item-body')) return;
        const chevron = header.querySelector('.ce-chevron');
        const isOpen = body.style.display !== 'none';
        body.style.display = isOpen ? 'none' : 'block';
        if (chevron) chevron.textContent = isOpen ? '▶' : '▼';
    }

    // Get a preview string from an object (first short string value)
    function getObjectPreview(obj) {
        for (const v of Object.values(obj)) {
            if (typeof v === 'string' && v.length > 0 && v.length <= 80) {
                return v.length > 50 ? v.substring(0, 50) + '…' : v;
            }
        }
        return '';
    }

    // Render a primitive field (string, number, boolean)
    function renderPrimitiveField(label, value, path) {
        const row = document.createElement('div');
        row.className = 'ce-field';

        const labelEl = document.createElement('label');
        labelEl.className = 'ce-field-label';
        labelEl.textContent = label;
        row.appendChild(labelEl);

        if (typeof value === 'boolean') {
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'ce-checkbox';
            cb.checked = value;
            cb.dataset.path = path;
            cb.dataset.type = 'boolean';
            cb.addEventListener('change', function() { markDirty(); });
            row.appendChild(cb);
        } else if (typeof value === 'number') {
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'ce-input';
            input.value = value;
            input.dataset.path = path;
            input.dataset.type = 'number';
            input.addEventListener('input', function() { markDirty(); });
            row.appendChild(input);
        } else {
            // String
            const strVal = value != null ? String(value) : '';
            const keyParts = path.split('.');
            const fieldName = keyParts[keyParts.length - 1];
            const isImage = /\.(jpg|jpeg|png|webp|svg|gif)(\?.*)?$/i.test(strVal)
                || /^(src|image|logo|icon|avatar|photo|thumbnail|cover|hero|poster|og_image)$/i.test(fieldName);
            const isLong = strVal.length > 80 || strVal.includes('\n');

            if (isImage) {
                const preview = document.createElement('div');
                preview.className = 'ce-image-preview';
                preview.innerHTML = `<img src="${escapeHtml(strVal.startsWith('/') ? '..' + strVal : strVal)}" alt="preview" onerror="this.style.display='none'">`;
                row.appendChild(preview);

                // Image field: input + browse button in a row
                const inputRow = document.createElement('div');
                inputRow.className = 'ce-image-input-row';
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'ce-input';
                input.value = strVal;
                input.dataset.path = path;
                input.addEventListener('input', function() {
                    markDirty();
                    // Update preview
                    const img = preview.querySelector('img');
                    if (img) {
                        const v = input.value;
                        img.src = v.startsWith('/') ? '..' + v : v;
                        img.style.display = '';
                    }
                });
                inputRow.appendChild(input);

                const browseBtn = document.createElement('button');
                browseBtn.type = 'button';
                browseBtn.className = 'btn btn-secondary btn-sm';
                browseBtn.textContent = t('btn.browse');
                browseBtn.addEventListener('click', function() {
                    browseImageForField(input, preview);
                });
                inputRow.appendChild(browseBtn);
                row.appendChild(inputRow);
            } else if (isLong) {
                const ta = document.createElement('textarea');
                ta.className = 'ce-textarea';
                ta.value = strVal;
                ta.dataset.path = path;
                ta.addEventListener('input', function() {
                    markDirty();
                    autoResizeTextarea(ta);
                });
                row.appendChild(ta);
            } else {
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'ce-input';
                input.value = strVal;
                input.dataset.path = path;
                input.addEventListener('input', function() { markDirty(); });
                row.appendChild(input);
            }
        }

        return row;
    }

    // Auto-resize textarea
    function autoResizeTextarea(ta) {
        ta.style.height = 'auto';
        ta.style.height = Math.max(60, ta.scrollHeight + 2) + 'px';
    }

    function initManualTextareaResize(container) {
        container.querySelectorAll('.ce-form-tile--resizable').forEach(tile => {
            const textarea = tile.querySelector('textarea.ce-textarea--manual-resize');
            const handle = tile.querySelector('.ce-textarea-resize-handle');
            if (!textarea || !handle || handle.dataset.resizeReady === '1') return;
            handle.dataset.resizeReady = '1';
            handle.addEventListener('pointerdown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const startY = e.clientY;
                const startHeight = textarea.getBoundingClientRect().height;
                const minHeight = 72;
                const maxHeight = Math.max(260, Math.round(window.innerHeight * 0.55));

                function move(ev) {
                    const nextHeight = Math.max(minHeight, Math.min(maxHeight, startHeight + ev.clientY - startY));
                    textarea.style.height = nextHeight + 'px';
                }

                function stop() {
                    document.removeEventListener('pointermove', move);
                    document.removeEventListener('pointerup', stop);
                    document.body.classList.remove('ce-resizing-textarea');
                }

                document.body.classList.add('ce-resizing-textarea');
                document.addEventListener('pointermove', move);
                document.addEventListener('pointerup', stop);
            });
        });
    }

    // ============================================================
    // IMAGE MANAGER — thin wrappers around NbImageManager (js/image-manager.js)
    // ============================================================

    // Initialize the shared image manager component with dashboard dependencies.
    // (Deferred to end of script where CSRF_TOKEN and t() are defined.)
    window.addEventListener('DOMContentLoaded', function() {
        initDashboardImageManager();
    });

    function initDashboardImageManager() {
        if (!window.NbImageManager) return;
        NbImageManager.init({
            apiUrl: 'api.php',
            csrfToken: CSRF_TOKEN,
            t: function(key, params) {
                let s = NB_LANG[key] || key;
                if (params) {
                    for (const [k, v] of Object.entries(params)) {
                        s = s.replace('{' + k + '}', v);
                    }
                }
                return s;
            },
            showToast: function(msg, type) {
                if (typeof showToast === 'function') showToast(msg, type);
            },
            showConfirm: null,
            canGenerateImages: function() {
                return dashboardAiImageUsable === true;
            },
            openImageGenerator: function(prompt, aspectRatio) {
                openAiImageGenerator(prompt || '', aspectRatio || 'auto');
            },
            itemsPerPage: clampMediaPageSize(currentSettings?.dashboard?.mediaItemsPerPage)
        });
    }

    function browseImageForField(inputEl, previewEl) {
        NbImageManager.open(function(path) {
            inputEl.value = path;
            inputEl.dispatchEvent(new Event('input'));
            if (previewEl) {
                const img = previewEl.querySelector('img');
                if (img) { img.src = path.startsWith('/') ? '..' + path : path; img.style.display = ''; }
            }
            markDirty();
        }, inputEl ? inputEl.value : null, { types: ['image'], type: 'image' });
    }

    // Backward-compat globals (in case any onclick attribute still references them)
    window.openImageManager = function() { NbImageManager.open(null, null, { types: ['image', 'audio', 'video', 'document'] }); };
    window.closeImageManager = function() { NbImageManager.close(); };
    function normalizeOgImageInputPath(path) {
        path = (path || '').trim();
        if (path.startsWith('../assets/images/')) {
            return '/assets/images/' + path.substring('../assets/images/'.length);
        }
        if (path.startsWith('assets/images/')) {
            return '/' + path;
        }
        return path;
    }
    function isSupportedOgImagePath(path) {
        path = normalizeOgImageInputPath(path);
        if (!path) return true;
        var cleanPath = path.split('?')[0].split('#')[0].toLowerCase();
        return /^\/assets\/images\/.+\.(jpe?g|png)$/.test(cleanPath);
    }
    function setOgImageInputValue(input, path) {
        var normalized = normalizeOgImageInputPath(path);
        if (!isSupportedOgImagePath(normalized)) {
            showToast(t('editor.seo_og_image_format'), 'error');
            return false;
        }
        input.value = normalized;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        return true;
    }
    window.browseSeoOgImage = function() {
        const input = document.getElementById('seoOgImage');
        if (!input) return;
        NbImageManager.open(function(path) {
            if (setOgImageInputValue(input, path)) {
                markDirty();
            }
        }, input.value || null, { types: ['image'], type: 'image' });
    };
    window.browseSectionMedia = function(btn, type) {
        type = type || 'image';
        const input = btn.parentElement.querySelector('.section-field');
        const preview = btn.closest('.form-group').querySelector('.ce-image-preview');
        NbImageManager.open(function(path) {
            if (path && input) {
                input.value = path;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                if (type === 'image' && preview) {
                    const src = path.startsWith('/') ? '..' + path : path;
                    preview.innerHTML = '<img src="' + escapeHtml(src) + '" alt="preview" onerror="this.style.display=\'none\'">';
                } else if (type === 'image') {
                    const previewDiv = document.createElement('div');
                    previewDiv.className = 'ce-image-preview';
                    const src = path.startsWith('/') ? '..' + path : path;
                    previewDiv.innerHTML = '<img src="' + escapeHtml(src) + '" alt="preview">';
                    input.parentElement.before(previewDiv);
                }
                markDirty();
            }
        }, input ? input.value : null, { types: [type], type: type });
    };
    window.browseSectionImage = function(btn) {
        window.browseSectionMedia(btn, 'image');
    };

    // Track unsaved changes
    let isDirty = false;
    function markDirty() {
        isDirty = true;
    }

    // Undo/Redo system — snapshot-based
    const MAX_UNDO = 50;
    let undoStack = [];
    let redoStack = [];

    function pushUndoSnapshot() {
        collectAllContent();
        undoStack.push(JSON.stringify(currentContent));
        if (undoStack.length > MAX_UNDO) undoStack.shift();
        redoStack = [];
        updateUndoRedoButtons();
    }

    function editorUndo() {
        if (undoStack.length === 0) return;
        collectAllContent();
        redoStack.push(JSON.stringify(currentContent));
        const snapshot = undoStack.pop();
        currentContent = JSON.parse(snapshot);
        const openPaths = getOpenGroupPaths();
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
        updateUndoRedoButtons();
    }

    function editorRedo() {
        if (redoStack.length === 0) return;
        collectAllContent();
        undoStack.push(JSON.stringify(currentContent));
        const snapshot = redoStack.pop();
        currentContent = JSON.parse(snapshot);
        const openPaths = getOpenGroupPaths();
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
        updateUndoRedoButtons();
    }

    function updateUndoRedoButtons() {
        const undoBtn = document.getElementById('undoBtn');
        const redoBtn = document.getElementById('redoBtn');
        if (undoBtn) undoBtn.disabled = undoStack.length === 0;
        if (redoBtn) redoBtn.disabled = redoStack.length === 0;
    }

    function clearUndoHistory() {
        undoStack = [];
        redoStack = [];
        updateUndoRedoButtons();
    }

    function isDashboardElementVisible(el) {
        if (!el || el.hidden) return false;
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    }

    function submitDashboardForm(form) {
        if (!form) return false;
        const submitter = form.querySelector('button[type="submit"]:not([disabled]), input[type="submit"]:not([disabled])');
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter || undefined);
        } else {
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
        return true;
    }

    function triggerDashboardSaveShortcut() {
        const pageEditor = document.getElementById('editorContainer');
        if (currentContent && isDashboardElementVisible(pageEditor)) {
            saveContent();
            return true;
        }

        const newsEditor = document.getElementById('newsEditorContainer');
        if (isDashboardElementVisible(newsEditor)) {
            savePost();
            return true;
        }

        const eventEditor = document.getElementById('eventsEditorView');
        if (isDashboardElementVisible(eventEditor)) {
            saveCurrentEvent();
            return true;
        }

        const settingsTab = document.getElementById('settingsTab');
        if (isDashboardElementVisible(settingsTab)) {
            const activePanel = settingsTab.querySelector('.settings-panel.active');
            if (!activePanel) return false;

            if (activePanel.id === 'settingsPanel-menus') {
                const menuSaveBtn = document.getElementById('saveMenuOrderBtn');
                if (menuSaveBtn && !menuSaveBtn.disabled) {
                    menuSaveBtn.click();
                    return true;
                }
                return false;
            }

            return submitDashboardForm(activePanel.querySelector('form.settings-form'));
        }

        const iconsTab = document.getElementById('iconsTab');
        if (isDashboardElementVisible(iconsTab)) {
            return submitDashboardForm(document.getElementById('iconManagerForm'));
        }

        return false;
    }

    // Keyboard shortcuts for save and undo/redo
    document.addEventListener('keydown', function(e) {
        const tag = document.activeElement?.tagName;
        const isTyping = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement?.isContentEditable;
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            triggerDashboardSaveShortcut();
            return;
        }
        if ((e.metaKey || e.ctrlKey) && e.key === 'z') {
            if (!currentContent) return;
            // Ignore when typing in input/textarea
            if (isTyping) return;
            e.preventDefault();
            if (e.shiftKey) {
                editorRedo();
            } else {
                editorUndo();
            }
            return;
        }
        if (!currentContent || isTyping) return;
        if (e.key === '/') {
            const search = document.getElementById('sectionSearchInput');
            if (search) {
                e.preventDefault();
                search.focus();
            }
            return;
        }
        if (e.key === 'j' || e.key === 'k') {
            e.preventDefault();
            const direction = e.key === 'j' ? 1 : -1;
            const next = Math.max(0, Math.min(activeSectionIndex + direction, (currentContent.sections || []).length - 1));
            scrollToSection(next);
            return;
        }
        if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            const direction = e.key === 'ArrowDown' ? 1 : -1;
            reorderSection(activeSectionIndex, activeSectionIndex + direction);
        }
    });

    // Array manipulation
    function getNestedValue(obj, path) {
        return path.split('.').reduce((o, k) => (o && o[k] !== undefined) ? o[k] : undefined, obj);
    }

    function setNestedValue(obj, path, value) {
        const keys = path.split('.');
        let current = obj;
        for (let i = 0; i < keys.length - 1; i++) {
            const k = keys[i];
            if (current[k] === undefined) {
                current[k] = isNaN(keys[i + 1]) ? {} : [];
            }
            current = current[k];
        }
        current[keys[keys.length - 1]] = value;
    }

    function addArrayItem(basePath) {
        pushUndoSnapshot();
        const openPaths = getOpenGroupPaths();
        const arr = getNestedValue(currentContent, basePath);
        if (!Array.isArray(arr)) return;

        // Clone the structure of the first item or create an empty string
        if (arr.length > 0 && typeof arr[0] === 'object' && arr[0] !== null) {
            const template = {};
            for (const k of Object.keys(arr[0])) {
                template[k] = '';
            }
            arr.push(template);
        } else {
            arr.push('');
        }
        // Auto-open the new item
        openPaths.add(basePath + '.' + (arr.length - 1));
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
    }

    function removeArrayItem(basePath, index) {
        pushUndoSnapshot();
        const openPaths = getOpenGroupPaths();
        const arr = getNestedValue(currentContent, basePath);
        if (!Array.isArray(arr)) return;
        arr.splice(index, 1);
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
    }

    function moveArrayItem(basePath, index, direction) {
        pushUndoSnapshot();
        const openPaths = getOpenGroupPaths();
        const arr = getNestedValue(currentContent, basePath);
        if (!Array.isArray(arr)) return;
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= arr.length) return;
        const temp = arr[index];
        arr[index] = arr[newIndex];
        arr[newIndex] = temp;
        // Swap the open state of moved items
        const pathA = basePath + '.' + index;
        const pathB = basePath + '.' + newIndex;
        const hadA = openPaths.has(pathA);
        const hadB = openPaths.has(pathB);
        openPaths.delete(pathA);
        openPaths.delete(pathB);
        if (hadA) openPaths.add(pathB);
        if (hadB) openPaths.add(pathA);
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
    }

    // Collect all content from the form back into currentContent
    function collectAllContent() {
        // Collect page settings (title, description, nav, breadcrumb)
        collectPageSettings();

        // Collect generic fields
        document.querySelectorAll('[data-path]').forEach(el => {
            const path = el.dataset.path;
            let value;
            if (el.dataset.type === 'boolean') {
                value = el.checked;
            } else if (el.dataset.type === 'number') {
                value = Number(el.value);
            } else {
                value = el.value;
            }
            setNestedValue(currentContent, path, value);
        });

        // Collect sections (legacy)
        collectSectionData();
    }

    // Add section element (registry-driven)
    function addSectionElement(section, index, container) {
        if (!container) container = document.getElementById('sectionsLegacyContainer');
        const div = document.createElement('div');
        div.className = 'section-item is-open';
        div.id = `section-${index}`;
        div.dataset.index = index;
        div.dataset.type = section.type;
        div.dataset.search = getSectionSearchText(section, index);
        div.dataset.editorSectionId = div.id;
        div.dataset.editorSectionKind = 'section';
        div.setAttribute('draggable', 'true');

        const def = window.BlockTypeRegistry?.[section.type];
        const typeLabel = getSectionTypeLabel(section);
        const preview = getSectionPreview(section);
        const fullTitle = getSectionHeading(section, index);
        const issues = getSectionIssues(section, index, currentContent.sections || []);
        const issueLabel = issues.length === 1
            ? t('editor.section_issue', {count: issues.length})
            : t('editor.section_issues', {count: issues.length});

        // Build form fields from registry
        let content = '';
        if (def && def.fields) {
            for (const field of def.fields) {
                const val = escapeHtml(section[field.key] ?? '');
                const fieldClass = 'section-field-group section-field-group--' + String(field.key || 'field').replace(/[^a-z0-9_-]/gi, '-').toLowerCase();
                switch (field.type) {
                    case 'input':
                    case 'url':
                    case 'number':
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            <input type="${field.type === 'input' ? 'text' : field.type}" class="section-field" data-key="${field.key}" value="${val}" placeholder="${field.label}...">
                            ${field.hint ? `<small style="color: #666;">${field.hint}</small>` : ''}
                        </div>`;
                        break;
                    case 'textarea':
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            <textarea class="section-field" data-key="${field.key}" placeholder="${field.label}...">${val}</textarea>
                        </div>`;
                        break;
                    case 'wysiwyg':
                        content += `<div class="form-group html-editor ${fieldClass}">
                            <label>${field.label} (HTML)</label>
                            <textarea class="section-field" data-key="${field.key}">${val}</textarea>
                        </div>`;
                        break;
                    case 'select':
                        const opts = (field.options || []).map(o =>
                            `<option value="${o.value}"${section[field.key] === o.value ? ' selected' : ''}>${o.label}</option>`
                        ).join('');
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            <select class="section-field" data-key="${field.key}">${opts}</select>
                        </div>`;
                        break;
                    case 'checkbox':
                        content += `<div class="form-group ${fieldClass}">
                            <label><input type="checkbox" class="section-field" data-key="${field.key}"${section[field.key] ? ' checked' : ''}> ${field.label}</label>
                        </div>`;
                        break;
                    case 'image':
                        const imgSrc = val ? (val.startsWith('/') ? '..' + val : val) : '';
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            ${imgSrc ? `<div class="ce-image-preview"><img src="${escapeHtml(imgSrc)}" alt="preview" onerror="this.style.display='none'"></div>` : ''}
                            <div class="ce-image-input-row">
                                <input type="text" class="section-field ce-input" data-key="${field.key}" value="${val}" placeholder="Path to image...">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="browseSectionImage(this)">${t('btn.browse')}</button>
                            </div>
                        </div>`;
                        break;
                    case 'audio':
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            <div class="ce-image-input-row">
                                <input type="text" class="section-field ce-input" data-key="${field.key}" value="${val}" placeholder="Path to audio file...">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="browseSectionMedia(this, 'audio')">${t('btn.browse')}</button>
                            </div>
                        </div>`;
                        break;
                }
            }
        }

        div.innerHTML = `
            <div class="section-header">
                <div class="section-heading">
                    <button type="button" class="section-drag-handle" draggable="true" title="${t('editor.drag_section')}" aria-label="${t('editor.drag_section')}">⋮⋮</button>
                    <button type="button" class="section-toggle-btn" onclick="toggleSectionOpen(${index})" aria-expanded="true" title="${t('editor.collapse_section')}">▾</button>
                    <span class="section-index-label">${index + 1}</span>
                    <span class="section-type ${section.type}">${typeLabel}</span>
                    <span class="section-title-preview" title="${escapeHtml(fullTitle)}">${preview ? escapeHtml(preview) : 'Section ' + (index + 1)}</span>
                    ${issues.length ? `<span class="section-issue-badge" title="${escapeHtml(issues.join('\n'))}">${escapeHtml(issueLabel)}</span>` : ''}
                </div>
                <div class="section-actions">
                    <button class="btn btn-sm btn-secondary" onclick="moveSection(${index}, -1)">&#8593;</button>
                    <button class="btn btn-sm btn-secondary" onclick="moveSection(${index}, 1)">&#8595;</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteSection(${index})">${icon('trash', 14)}</button>
                </div>
            </div>
            <div class="section-fields">${content}</div>
        `;

        container.appendChild(div);
        div.addEventListener('dragstart', e => {
            if (!e.target.closest('.section-drag-handle') && !e.target.closest('.section-header')) {
                e.preventDefault();
                return;
            }
            sectionDragIndex = index;
            e.dataTransfer.effectAllowed = 'move';
            div.classList.add('is-dragging');
        });
        div.addEventListener('dragend', () => {
            sectionDragIndex = null;
            document.querySelectorAll('.is-dragging, .is-drop-target').forEach(el => el.classList.remove('is-dragging', 'is-drop-target'));
        });
        div.addEventListener('dragover', e => {
            e.preventDefault();
            div.classList.add('is-drop-target');
        });
        div.addEventListener('dragleave', () => div.classList.remove('is-drop-target'));
        div.addEventListener('drop', e => {
            e.preventDefault();
            div.classList.remove('is-drop-target');
            reorderSection(sectionDragIndex, index);
        });
        sectionCounter++;
    }

    // Add new section (registry-driven)
    function addSection(type, insertIndex) {
        pushUndoSnapshot();
        if (!currentContent) {
            currentContent = { sections: [] };
        }
        if (!currentContent.sections) {
            currentContent.sections = [];
        }

        const def = window.BlockTypeRegistry?.[type];
        const defaults = def?.defaults ? JSON.parse(JSON.stringify(def.defaults)) : {};
        const newSection = {
            id: 'section_' + Date.now(),
            type: type,
            ...defaults
        };

        const targetIndex = Number.isInteger(insertIndex)
            ? Math.max(0, Math.min(insertIndex, currentContent.sections.length))
            : currentContent.sections.length;
        currentContent.sections.splice(targetIndex, 0, newSection);
        renderEditor();
        setActiveSection(targetIndex);
        requestAnimationFrame(() => scrollToSection(targetIndex));
        markDirty();
    }

    // Move section
    function moveSection(index, direction) {
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= currentContent.sections.length) return;
        reorderSection(index, newIndex);
    }

    // Delete section
    function deleteSection(index) {
        pushUndoSnapshot();
        currentContent.sections.splice(index, 1);
        renderEditor();
        markDirty();
    }

    // Collect form data (registry-driven)
    function collectSectionData() {
        const sectionElements = document.querySelectorAll('.section-item');

        sectionElements.forEach((el, index) => {
            const section = currentContent.sections[index];
            if (!section) return;

            // Read all fields with data-key attributes
            el.querySelectorAll('.section-field').forEach(fieldEl => {
                const key = fieldEl.dataset.key;
                if (!key) return;

                if (fieldEl.type === 'checkbox') {
                    if (fieldEl.checked) {
                        section[key] = fieldEl.checked;
                    } else {
                        delete section[key];
                    }
                } else {
                    section[key] = fieldEl.value || '';
                }
            });
        });
    }

    // Save content
    async function saveContent() {
        collectAllContent();

        currentContent.page = currentPage;
        currentContent.lang = document.getElementById('langSelect').value;

        try {
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('page', currentPage);
            formData.append('content', JSON.stringify(currentContent));
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showToast(t('toast.saved'), 'success');
                isDirty = false;
                currentContent.lastModified = result.data.lastModified;
                if (result.data.seoHealth) {
                    const lang = document.getElementById('langSelect').value;
                    const page = document.getElementById('pageSelect').value;
                    currentSeoHealth = result.data.seoHealth;
                    setPageSeoHealth(lang, page, result.data.seoHealth);
                    updateEditorSeoHealth(result.data.seoHealth);
                }
                document.getElementById('lastModified').textContent =
                    t('editor.last_saved', {date: formatDateShort(result.data.lastModified)});
                loadBackups();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_saving', {message: error.message}), 'error');
        }
    }

    // Load backups
    async function loadBackups() {
        try {
            const response = await fetch(`api.php?action=backups&page=${currentPage}`);
            const result = await response.json();

            if (result.success) {
                renderBackups(result.data);
            }
        } catch (error) {
            console.error('Error loading backups:', error);
        }
    }

    function renderBackups(backups) {
        const container = document.getElementById('backupList');

        if (backups.length === 0) {
            container.innerHTML = '<p style="color: #666;">' + t('backups.no_backups') + '</p>';
            return;
        }

        container.innerHTML = backups.map(backup => `
            <div class="backup-item">
                <div class="backup-info">
                    <span class="backup-date">${backup.date}</span>
                    <span class="backup-time">${backup.time}</span>
                </div>
                <div class="backup-actions">
                    <button class="btn btn-sm btn-secondary" onclick="previewBackup('${backup.filename}')">${t('backups.view')}</button>
                    <button class="btn btn-sm btn-primary" onclick="restoreBackup('${backup.filename}')">${t('backups.restore')}</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteBackup('${backup.filename}')">${t('backups.delete')}</button>
                </div>
            </div>
        `).join('');
    }

    async function previewBackup(filename) {
        try {
            const response = await fetch(`api.php?action=preview-backup&backup=${filename}`);
            const result = await response.json();

            if (result.success) {
                showModal(
                    t('backups.view'),
                    renderBackupPreview(result.data),
                    closeModal,
                    {
                        html: true,
                        modalClass: 'modal-backup-preview',
                        confirmText: 'Schließen',
                        confirmClass: 'btn btn-primary'
                    }
                );
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }

    function flattenBackupPreviewFields(value, prefix, rows) {
        if (Array.isArray(value)) {
            if (value.length === 0) {
                rows.push({ name: prefix || '[]', value: '[]' });
                return;
            }
            var allScalar = value.every(function(item) {
                return item === null || typeof item !== 'object';
            });
            if (allScalar) {
                rows.push({
                    name: prefix || '[]',
                    value: value.map(formatBackupPreviewValue).join(', ')
                });
                return;
            }
            value.forEach(function(item, index) {
                flattenBackupPreviewFields(item, (prefix ? prefix : '') + '[' + index + ']', rows);
            });
            return;
        }

        if (value && typeof value === 'object') {
            var keys = Object.keys(value);
            if (keys.length === 0) {
                rows.push({ name: prefix || '{}', value: '{}' });
                return;
            }
            keys.forEach(function(key) {
                flattenBackupPreviewFields(value[key], prefix ? prefix + '.' + key : key, rows);
            });
            return;
        }

        rows.push({ name: prefix || 'value', value: formatBackupPreviewValue(value) });
    }

    function formatBackupPreviewValue(value) {
        if (value === null) return 'null';
        if (value === undefined) return '';
        if (typeof value === 'boolean') return value ? 'true' : 'false';
        if (typeof value === 'number') return String(value);
        var text = String(value);
        return text === '' ? '—' : text;
    }

    function renderBackupPreview(data) {
        var rows = [];
        flattenBackupPreviewFields(data, '', rows);
        if (!rows.length) {
            return '<div class="backup-preview-modal"><p class="backup-preview-empty">Keine Inhalte im Backup gefunden.</p></div>';
        }
        return '<div class="backup-preview-modal">' + rows.map(function(row) {
            return '<article class="backup-preview-field">' +
                '<div class="backup-preview-field__name" title="' + escapeHtml(row.name) + '">' + escapeHtml(row.name) + '</div>' +
                '<div class="backup-preview-field__value">' + escapeHtml(row.value) + '</div>' +
            '</article>';
        }).join('') + '</div>';
    }

    function restoreBackup(filename) {
        showModal(t('modal.restore_backup'),
            t('modal.restore_backup_confirm'),
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'restore');
                    formData.append('backup', filename);
                    formData.append('csrf_token', CSRF_TOKEN);

                    const response = await fetch('api.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showToast(t('toast.backup_restored'), 'success');
                        closeModal();
                        loadContent();
                    } else {
                        showToast(result.message, 'error');
                    }
                } catch (error) {
                    showToast(t('toast.error_generic', {message: error.message}), 'error');
                }
            }
        );
    }

    function deleteBackup(filename) {
        showModal(t('modal.delete_backup'),
            t('modal.delete_backup_confirm'),
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete-backup');
                    formData.append('backup', filename);
                    formData.append('csrf_token', CSRF_TOKEN);

                    const response = await fetch('api.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showToast(t('toast.backup_deleted'), 'success');
                        closeModal();
                        loadBackups();
                    } else {
                        showToast(result.message, 'error');
                    }
                } catch (error) {
                    showToast(t('toast.error_generic', {message: error.message}), 'error');
                }
            }
        );
    }

    // Modal
    function closeAllComboboxes() {
        document.querySelectorAll('.nb-combobox__list:not([hidden])').forEach(function(list) {
            list.hidden = true;
            var root = list.closest('.nb-combobox');
            if (root) {
                var input = root.querySelector('input[role="combobox"]');
                if (input) input.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function showModal(title, text, onConfirm, options) {
        options = options || {};
        closeAllComboboxes();
        var modalEl = document.querySelector('#modalOverlay .modal');
        if (modalEl) {
            modalEl.className = 'modal' + (options.modalClass ? ' ' + options.modalClass : '');
        }
        document.getElementById('modalTitle').textContent = title;
        var modalText = document.getElementById('modalText');
        if (options.html) {
            modalText.innerHTML = text;
        } else {
            modalText.textContent = text;
        }
        document.getElementById('modalOverlay').style.display = 'flex';
        document.getElementById('modalOverlay').setAttribute('aria-hidden', 'false');
        var confirmBtn = document.getElementById('modalConfirm');
        confirmBtn.onclick = onConfirm || closeModal;
        if (options.confirmText) confirmBtn.textContent = options.confirmText;
        if (options.confirmClass) confirmBtn.className = options.confirmClass;
        if (options.hideConfirm) confirmBtn.style.display = 'none';
        setTimeout(() => {
            var focusTarget = options.focusSelector ? document.querySelector(options.focusSelector) : confirmBtn;
            (focusTarget || confirmBtn).focus();
        }, 0);
    }

    function closeModal() {
        const overlay = document.getElementById('modalOverlay');
        overlay.style.display = 'none';
        overlay.setAttribute('aria-hidden', 'true');
        const modalEl = overlay.querySelector('.modal');
        if (modalEl) modalEl.className = 'modal';
        // Reset confirm button to default state
        const btn = document.getElementById('modalConfirm');
        btn.textContent = t('btn.confirm');
        btn.className = 'btn btn-danger';
        btn.disabled = false;
        btn.style.display = '';
    }

    // Format date without seconds
    function formatDateShort(dateStr) {
        const d = new Date(dateStr);
        return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    // Toast
    function showToast(message, type = 'success') {
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.textContent = message;
        document.body.appendChild(toast);

        const duration = type === 'error' ? 6000 : 4000;
        setTimeout(() => {
            toast.classList.add('toast-fade-out');
            toast.addEventListener('animationend', () => toast.remove());
        }, duration);
    }

    // HTML escape
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Init
    document.getElementById('langSelect').addEventListener('change', function() {
        updatePageSelect();
        const m = document.getElementById('langSelectMobile');
        if (m) m.value = this.value;
    });
    const langMobile = document.getElementById('langSelectMobile');
    if (langMobile) {
        langMobile.addEventListener('change', function() {
            document.getElementById('langSelect').value = this.value;
            updatePageSelect();
        });
    }
    const pageMobile = document.getElementById('pageSelectMobile');
    if (pageMobile) {
        pageMobile.addEventListener('change', function() {
            document.getElementById('pageSelect').value = this.value;
        });
    }
    document.getElementById('pageSelect').addEventListener('change', function() {
        if (pageMobile) pageMobile.value = this.value;
    });

    const DASHBOARD_PATH = window.location.pathname.replace(/\/dashboard\.php$/, '/dashboard');
    const VALID_DASHBOARD_TABS = <?php echo json_encode($validDashboardTabs, JSON_UNESCAPED_UNICODE); ?>;
    const DASHBOARD_HASH_ALIASES = { dashboard: 'home', ai: 'home', pages: 'content', messages: 'mails' };
    let dashboardRouteApplying = false;
    let settingsLoaded = false;
    let currentSettings = null;
    let currentAiSettings = null;
    let aiChatMessages = [];
    let dashboardAiImageUsable = false;
    let currentDashboardAnalyticsRange = { period: 'days', count: 30 };
    let formsAdminLoaded = false;

    function dashboardHashFor(tab, subtab) {
        const publicTab = tab === 'home' ? 'dashboard' : (tab === 'content' ? 'pages' : (tab === 'mails' ? 'messages' : tab));
        return '#' + publicTab + (subtab ? '/' + subtab : '');
    }

    function parseDashboardHash() {
        const raw = (window.location.hash || '').replace(/^#/, '');
        if (!raw) return { tab: 'home', subtab: '' };
        const parts = raw.split('/').filter(Boolean);
        const first = parts[0] || 'home';
        const tab = DASHBOARD_HASH_ALIASES[first] || first;
        return {
            tab: VALID_DASHBOARD_TABS.indexOf(tab) !== -1 ? tab : 'home',
            subtab: parts[1] || ''
        };
    }

    function updateDashboardHash(tab, subtab, replace) {
        if (dashboardRouteApplying) return;
        const next = DASHBOARD_PATH + dashboardHashFor(tab, subtab);
        if (window.location.pathname + window.location.hash === next) return;
        history[replace ? 'replaceState' : 'pushState']({ view: 'dashboard', tab: tab, subtab: subtab || '' }, '', next);
    }

    async function applyDashboardRoute(replace) {
        dashboardRouteApplying = true;
        const params = new URLSearchParams(window.location.search);
        const pageParam = params.get('page');
        const tabParam = params.get('tab');
        const postParam = params.get('post');
        const route = parseDashboardHash();
        const hashParts = (window.location.hash || '').replace(/^#/, '').split('/').filter(Boolean);
        const needsPageList = hashParts[0] === 'page'
            || !!pageParam
            || tabParam === 'news'
            || route.tab === 'content';

        if (needsPageList) {
            try {
                const response = await fetch('api.php?action=list-pages&_=' + Date.now());
                const result = await response.json();
                if (result.success) {
                    applyPageList(result.data);
                }
            } catch (e) {
                console.error('Error loading page list:', e);
            }
        }

        let canonicalTab = route.tab || 'home';
        let canonicalSubtab = route.subtab || '';
        let canonicalUrl = null;

        if (hashParts[0] === 'page' && hashParts[1] && hashParts[1].includes('_')) {
            const page = hashParts[1];
            const lang = page.substring(0, page.indexOf('_'));
            const slug = page.substring(page.indexOf('_') + 1);
            switchTab('content', { replace: true });
            canonicalTab = 'content';
            canonicalSubtab = '';
            const langSelect = document.getElementById('langSelect');
            if (langSelect) {
                langSelect.value = lang;
                updatePageSelect();
                const pageSelect = document.getElementById('pageSelect');
                if (pageSelect) {
                    pageSelect.value = slug;
                    await loadContent(false);
                }
            }
            canonicalUrl = DASHBOARD_PATH + '#page/' + page;
        } else if (route.tab !== 'home' || route.subtab) {
            switchTab(route.tab, { settingsTab: route.subtab, replace: !!replace });
            canonicalTab = route.tab;
            canonicalSubtab = route.subtab;
        } else if (tabParam === 'news' && isDashboardModuleEnabled('news')) {
            switchTab('news');
            canonicalTab = 'news';
            canonicalSubtab = '';
            if (postParam) {
                // Wait for news to load, then open the post editor
                await loadNews();
                newsLoaded = true;
                editPost(postParam);
            }
        } else if (pageParam && pageParam.includes('_')) {
            const lang = pageParam.substring(0, pageParam.indexOf('_'));
            const slug = pageParam.substring(pageParam.indexOf('_') + 1);
            switchTab('content', { replace: true });
            canonicalTab = 'content';
            canonicalSubtab = '';
            const langSelect = document.getElementById('langSelect');
            if (langSelect) {
                langSelect.value = lang;
                updatePageSelect();
                const pageSelect = document.getElementById('pageSelect');
                if (pageSelect) {
                    pageSelect.value = slug;
                    await loadContent(false);
                }
            }
            canonicalUrl = DASHBOARD_PATH + '#page/' + pageParam;
        } else {
            switchTab('home', { replace: !!replace });
            canonicalTab = 'home';
            canonicalSubtab = '';
        }
        dashboardRouteApplying = false;
        if (canonicalUrl) {
            history.replaceState({ view: 'editor', page: hashParts[1] || pageParam }, '', canonicalUrl);
        } else if (window.location.pathname.endsWith('/dashboard.php') || replace) {
            updateDashboardHash(canonicalTab, canonicalSubtab, true);
        }
    }

    // Auto-load page/hash route, otherwise show page list.
    applyDashboardRoute(true);

    // Browser back/forward navigation
    window.addEventListener('popstate', async (e) => {
        if (e.state && e.state.view === 'editor' && e.state.page) {
            const lang = e.state.page.substring(0, e.state.page.indexOf('_'));
            const slug = e.state.page.substring(e.state.page.indexOf('_') + 1);
            document.getElementById('langSelect').value = lang;
            updatePageSelect();
            document.getElementById('pageSelect').value = slug;
            loadContent(false);
        } else {
            await applyDashboardRoute(true);
        }
    });

    window.addEventListener('hashchange', function() {
        applyDashboardRoute(true);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal();
            closeMailDetail();
        }
    });

    // ============================================================
    // TAB NAVIGATION
    // ============================================================

    function dismissFrontendEditBanner() {
        const banner = document.getElementById('frontendEditBanner');
        if (banner) banner.remove();
    }

    function switchTab(tab, options) {
        options = options || {};
        if (!isDashboardModuleEnabled(tab)) {
            tab = 'home';
            options = Object.assign({}, options, { replace: true });
        }
        if (tab !== 'content') {
            dismissFrontendEditBanner();
        }

        document.getElementById('homeTab').style.display = tab === 'home' ? 'block' : 'none';
        document.getElementById('contentTab').style.display = tab === 'content' ? 'block' : 'none';
        document.getElementById('newsTab').style.display = tab === 'news' ? 'block' : 'none';
        document.getElementById('eventsTab').style.display = tab === 'events' ? 'block' : 'none';
        document.getElementById('mailsTab').style.display = tab === 'mails' ? 'block' : 'none';
        const mediaTab = document.getElementById('mediaTab');
        if (mediaTab) mediaTab.style.display = tab === 'media' ? 'block' : 'none';
        const iconsTab = document.getElementById('iconsTab');
        if (iconsTab) iconsTab.style.display = tab === 'icons' ? 'block' : 'none';
        document.getElementById('settingsTab').style.display = tab === 'settings' ? 'block' : 'none';
        document.getElementById('backupTab').style.display = tab === 'backup' ? 'block' : 'none';

        // Show/hide topbar selectors — only when editing a page (not on page list)
        const topbarSelectors = document.getElementById('topbarSelectors');
        if (topbarSelectors) {
            if (tab === 'content' && currentPage) {
                topbarSelectors.style.display = 'flex';
            } else {
                topbarSelectors.style.display = 'none';
            }
        }

        // When switching to content tab, always show page list
        if (tab === 'content') {
            showPageList();
        }

        // Update sidebar active state
        document.querySelectorAll('.sidebar-nav-item[data-tab]').forEach(btn => btn.classList.remove('active'));
        const activeNavItem = document.querySelector(`.sidebar-nav-item[data-tab="${tab}"]`);
        if (activeNavItem) activeNavItem.classList.add('active');

        // Update topbar title
        const titles = { home: t('dashboard_home.title'), content: currentPage ? t('editor.title') : t('pages.title'), news: t('news.title'), mails: t('mails.title'), events: t('events.title'), media: t('nav.media_library'), icons: t('icons.title'), settings: t('settings.title'), backup: t('settings.backup') };
        const topbarTitle = document.getElementById('topbarTitle');
        if (topbarTitle) topbarTitle.textContent = titles[tab] || 'Dashboard';

        // Close sidebar on mobile after tab switch
        document.getElementById('adminSidebar').classList.remove('open');

        if (tab === 'mails') {
            // Always start on the list view when switching into Mails
            const mailsList = document.getElementById('mailsListView');
            const mailDetail = document.getElementById('mailDetailView');
            if (mailsList) mailsList.style.display = '';
            if (mailDetail) mailDetail.style.display = 'none';
            loadMails();
        }
        if (tab === 'media' && window.NbImageManager) {
            initDashboardImageManager();
            const mount = document.getElementById('mediaLibraryMount');
            if (mount) {
                NbImageManager.mount(mount, { types: ['image', 'audio', 'video', 'document'] });
            }
        }
        if (tab === 'home') {
            loadDashboardOverview();
        }
        if (tab === 'news') {
            showNewsList();
            if (!newsLoaded) {
                newsLoaded = true;
                loadNews();
            }
        }
        if (tab === 'events') {
            // Always start on the list view when switching into Events
            const listView = document.getElementById('eventsListView');
            const editorView = document.getElementById('eventsEditorView');
            const trashView = document.getElementById('eventsTrashView');
            if (listView) listView.style.display = '';
            if (editorView) editorView.style.display = 'none';
            if (trashView) trashView.style.display = 'none';
            currentEventIndex = null;
            if (!eventsLoaded) {
                eventsLoaded = true;
                loadEventsEditor();
            }
        }
        if (tab === 'icons' && typeof loadIconManager === 'function') {
            loadIconManager();
        }
        if (tab === 'settings') {
            activateSettingsTab(options.settingsTab || getActiveSettingsTab(), { silent: true });
        }

        if (tab === 'settings' && !settingsLoaded) {
            loadSettings();
        }

        updateDashboardHash(tab, tab === 'settings' ? (options.settingsTab || getActiveSettingsTab()) : '', !!options.replace);
    }

    <?php if ($isAdminUser): ?>
    // ============================================================
    // ICON MANAGER
    // ============================================================

    let iconManagerData = [];
    let iconManagerPath = '';
    let iconManagerSortMode = 'alpha';
    let iconManagerSearchTerm = '';
    let iconManagerHighlightedKeys = new Set();
    let iconManagerHighlightTimer = null;
    const ICONIFY_IMPORT_SETS = {
        lucide:   { label: 'Lucide',         license: 'ISC', licenseUrl: 'https://github.com/lucide-icons/lucide/blob/main/LICENSE' },
        tabler:   { label: 'Tabler Icons',   license: 'MIT', licenseUrl: 'https://github.com/tabler/tabler-icons/blob/master/LICENSE' },
        heroicons:{ label: 'Heroicons',      license: 'MIT', licenseUrl: 'https://github.com/tailwindlabs/heroicons/blob/master/LICENSE' },
        ph:       { label: 'Phosphor',       license: 'MIT', licenseUrl: 'https://github.com/phosphor-icons/core/blob/main/LICENSE' },
        bi:       { label: 'Bootstrap Icons',license: 'MIT', licenseUrl: 'https://github.com/twbs/icons/blob/main/LICENSE.md', style: 'fill' },
        iconoir:  { label: 'Iconoir',        license: 'MIT', licenseUrl: 'https://github.com/iconoir-icons/iconoir/blob/main/LICENSE' },
        ion:      { label: 'Ionicons',       license: 'MIT', licenseUrl: 'https://github.com/ionic-team/ionicons/blob/main/LICENSE' },
        mynaui:   { label: 'Myna UI',        license: 'MIT', licenseUrl: 'https://github.com/MynaUI/icons/blob/main/LICENSE' },
        mingcute: { label: 'MingCute',       license: 'Apache-2.0', licenseUrl: 'https://github.com/mingcute-design/mingcute-icons/blob/main/LICENSE' },
        tdesign:  { label: 'TDesign Icons',  license: 'MIT', licenseUrl: 'https://github.com/Tencent/tdesign-icons/blob/main/LICENSE' }
    };

    async function fetchJsonWithTimeout(url, options = {}, timeoutMs = 12000) {
        const controller = new AbortController();
        const timer = setTimeout(function() { controller.abort(); }, timeoutMs);
        try {
            const response = await fetch(url, Object.assign({}, options, {
                signal: controller.signal,
                cache: 'no-store',
            }));
            return await response.json();
        } finally {
            clearTimeout(timer);
        }
    }

    function cleanSvgAttributes(attrs) {
        if (!attrs) return '';
        const allowedAttrs = new Set([
            'd', 'points', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
            'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap',
            'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
            'fill-rule', 'clip-rule', 'opacity', 'fill-opacity', 'stroke-opacity',
            'transform', 'id', 'clip-path', 'mask', 'offset', 'stop-color', 'stop-opacity',
            'gradientUnits', 'gradientTransform'
        ]);
        let cleaned = '';
        attrs.replace(/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("[^"]*"|'[^']*'|[^\s>\/]+))?/g, function(match, rawName, rawValue) {
            const name = rawName.replace(/^xlink:/i, '').trim();
            const lower = name.toLowerCase();
            if (lower.startsWith('on') || lower === 'style' || lower === 'class') return '';
            if (!allowedAttrs.has(name) && !allowedAttrs.has(lower)) return '';

            const attrName = allowedAttrs.has(name) ? name : lower;
            let value = rawValue || '';
            if (value) {
                const unquoted = value.replace(/^["']|["']$/g, '').trim();
                if ((lower === 'clip-path' || lower === 'mask') && unquoted && !/^url\(#[-_a-zA-Z0-9]+\)$/.test(unquoted)) return '';
                if ((lower === 'fill' || lower === 'stroke') && /^url\(/i.test(unquoted) && !/^url\(#[-_a-zA-Z0-9]+\)$/i.test(unquoted)) return '';
                value = '="' + escapeHtml(unquoted) + '"';
            }
            cleaned += ' ' + attrName + value;
            return '';
        });
        return cleaned;
    }

    function sanitizeIconSvgClient(svg) {
        svg = (svg || '').trim()
            .replace(/<\?xml[\s\S]*?\?>/gi, '')
            .replace(/<!DOCTYPE[\s\S]*?>/gi, '')
            .replace(/<\s*\/?\s*svg\b[^>]*>/gi, '')
            .replace(/<!--[\s\S]*?-->/g, '')
            .replace(/<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/gi, '')
            .replace(/<\s*(metadata|desc|style|sodipodi:namedview)\b[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
            .replace(/<\s*defs\b[^>]*>\s*(?:<\s*style\b[^>]*>[\s\S]*?<\s*\/\s*style\s*>\s*)*<\s*\/\s*defs\s*>/gi, '')
            .replace(/\s+on[a-z]+\s*=\s*(["']).*?\1/gi, '')
            .replace(/\s+(href|xlink:href)\s*=\s*(["'])\s*(?!#)[^"']*\2/gi, '');
        const allowed = new Set(['path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'g', 'defs', 'clipPath', 'mask', 'linearGradient', 'radialGradient', 'stop', 'title']);
        svg = svg.replace(/<\s*(\/?)([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>/g, function(match, closing, tag, attrs) {
            if (!allowed.has(tag)) return '';
            if (closing) return `</${tag}>`;
            const selfClosing = /\/\s*$/.test(attrs || '');
            const cleanedAttrs = cleanSvgAttributes(attrs || '');
            return `<${tag}${cleanedAttrs}${selfClosing ? '/' : ''}>`;
        });
        svg = svg.replace(/^\s*(?:\.[\w-]+\s*\{[^}]*\}\s*)+/gm, '');
        svg = removeEmptySvgContainers(svg);
        return svg.trim();
    }

    function normalizeIconViewBoxClient(viewBox) {
        const raw = (viewBox || '').trim();
        if (!raw) return '0 0 24 24';
        const parts = raw.split(/[\s,]+/);
        if (parts.length !== 4) return '0 0 24 24';
        const numbers = parts.map(Number);
        if (numbers.some(function(value) { return !Number.isFinite(value); }) || numbers[2] <= 0 || numbers[3] <= 0) {
            return '0 0 24 24';
        }
        return numbers.map(function(value) {
            return Number.isInteger(value) ? String(value) : String(parseFloat(value.toFixed(6)));
        }).join(' ');
    }

    function extractIconViewBoxClient(svg) {
        const markup = String(svg || '');
        const viewBoxMatch = markup.match(/<\s*svg\b[^>]*\sviewBox\s*=\s*(["'])(.*?)\1/i);
        if (viewBoxMatch) return normalizeIconViewBoxClient(viewBoxMatch[2]);

        const widthMatch = markup.match(/<\s*svg\b[^>]*\swidth\s*=\s*(["'])([0-9.]+)(?:px)?\1/i);
        const heightMatch = markup.match(/<\s*svg\b[^>]*\sheight\s*=\s*(["'])([0-9.]+)(?:px)?\1/i);
        if (widthMatch && heightMatch) return normalizeIconViewBoxClient('0 0 ' + widthMatch[2] + ' ' + heightMatch[2]);

        return '';
    }

    function removeEmptySvgContainers(svg) {
        let previous = '';
        let cleaned = svg || '';
        while (cleaned !== previous) {
            previous = cleaned;
            cleaned = cleaned
                .replace(/<defs\b[^>]*>\s*<\/defs>/gi, '')
                .replace(/<g\b[^>]*>\s*<\/g>/gi, '')
                .replace(/<clipPath\b[^>]*>\s*<\/clipPath>/gi, '')
                .replace(/<mask\b[^>]*>\s*<\/mask>/gi, '');
        }
        return cleaned;
    }

    function forceIconCurrentColor(svg) {
        let normalized = sanitizeIconSvgClient(svg)
            .replace(/\s(fill|stroke)\s*=\s*(["'])(?!none\2|currentColor\2|url\(#)[^"']*\2/gi, ' $1="currentColor"')
            .replace(/\sstop-color\s*=\s*(["'])(?!currentColor\1|none\1)[^"']*\1/gi, ' stop-color="currentColor"');
        normalized = normalized.replace(/<\s*(polygon|circle|ellipse|rect)\b([^>]*)\/?>/gi, function(match, tag, attrs) {
            if (/\s(?:fill|stroke)\s*=/i.test(attrs)) return match;
            const selfClosing = /\/\s*>$/.test(match);
            const cleanedAttrs = (attrs || '').replace(/\/\s*$/, '');
            return `<${tag}${cleanedAttrs} fill="currentColor"${selfClosing ? '/>' : '>'}`;
        });
        return normalized;
    }

    async function loadIconManager() {
        const button = document.getElementById('iconManagerRefreshBtn');
        if (button) button.disabled = true;
        try {
            const result = await fetchJsonWithTimeout('api.php?action=list-icons&csrf_token=' + encodeURIComponent(CSRF_TOKEN) + '&_=' + Date.now());
            if (result.success) {
                iconManagerData = result.data.icons || [];
                iconManagerPath = result.data.path || 'content/settings/iconset.json';
                renderIconManager(result.data);
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('icons.load_error', {message: error.message}), 'error');
        } finally {
            if (button) button.disabled = false;
        }
    }

    function getFilteredIconManagerIcons() {
        const term = iconManagerSearchTerm.toLowerCase();
        let icons = iconManagerData.slice();
        if (term) {
            icons = icons.filter(function(iconItem) {
                const haystack = [
                    iconItem.key || '',
                    iconItem.label || '',
                    (iconItem.tags || []).join(' '),
                    iconItem.source || ''
                ].join(' ').toLowerCase();
                return haystack.includes(term);
            });
        }

        icons.sort(function(a, b) {
            if (iconManagerSortMode === 'newest' || iconManagerSortMode === 'oldest') {
                const aTime = Date.parse(a.createdAt || a.updatedAt || '') || 0;
                const bTime = Date.parse(b.createdAt || b.updatedAt || '') || 0;
                if (aTime !== bTime) {
                    return iconManagerSortMode === 'newest' ? bTime - aTime : aTime - bTime;
                }
            }
            return String(a.key || '').localeCompare(String(b.key || ''), undefined, { sensitivity: 'base' });
        });

        return icons;
    }

    function iconSvgAttributes(iconItem) {
        return (iconItem.style || 'stroke') === 'fill'
            ? 'fill="currentColor"'
            : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    }

    function iconSvgMarkup(iconItem) {
        const svg = iconItem.svg || '';
        return (iconItem.style || 'stroke') === 'fill'
            ? svg.replace(/\s+stroke\s*=\s*(["'])currentColor\1/gi, '')
            : svg;
    }

    function highlightIconManagerCard(key) {
        if (key) iconManagerHighlightedKeys.add(key);
        if (iconManagerHighlightTimer) clearTimeout(iconManagerHighlightTimer);
        if (iconManagerHighlightedKeys.size) {
            iconManagerHighlightTimer = setTimeout(function() {
                iconManagerHighlightedKeys.clear();
                renderIconManager();
            }, 60000);
        }
    }

    function renderIconManager(data = null) {
        const grid = document.getElementById('iconManagerGrid');
        const empty = document.getElementById('iconManagerEmpty');
        const path = document.getElementById('iconManagerPath');
        if (!grid) return;

        if (data) {
            iconManagerData = data.icons || [];
            iconManagerPath = data.path || iconManagerPath;
        }

        grid.innerHTML = '';
        if (path) path.textContent = t('icons.path', {path: iconManagerPath || 'content/settings/iconset.json'});

        const icons = getFilteredIconManagerIcons();
        if (!icons.length) {
            if (empty) empty.style.display = '';
            renderAdminListFooter('iconManagerFooter', 'icons', 0, getDashboardPageSize('icons'), renderIconManager, 'iconManagerFooterTop');
            return;
        }
        if (empty) empty.style.display = 'none';

        const pageSize = getDashboardPageSize('icons');
        const paged = pageSlice(icons, 'icons', pageSize);
        renderAdminListFooter('iconManagerFooter', 'icons', icons.length, pageSize, renderIconManager, 'iconManagerFooterTop');

        paged.items.forEach(function(iconItem) {
            const card = document.createElement('article');
            card.className = 'icon-manager-card';
            if (iconManagerHighlightedKeys.has(iconItem.key)) {
                card.classList.add('icon-manager-card--highlight');
            }
            const sourceLabel = iconItem.source === 'custom' ? t('icons.source_custom') : t('icons.source_core');
            card.title = sourceLabel;
            card.dataset.iconKey = iconItem.key || '';
            card.dataset.iconTags = (iconItem.tags || []).join(' ');
            card.innerHTML = `
                <div class="icon-manager-card__preview">
                    <svg viewBox="${escapeHtml(iconItem.viewBox || '0 0 24 24')}" ${iconSvgAttributes(iconItem)}>${iconSvgMarkup(iconItem)}</svg>
                </div>
                <div class="icon-manager-card__body">
                    <strong>${escapeHtml(iconItem.key)}</strong>
                    <span>${escapeHtml(iconItem.label || '')}</span>
                </div>
                <div class="icon-manager-card__actions">
                    <button type="button" class="icon-manager-action-btn" data-icon-edit="${escapeHtml(iconItem.key)}" title="${t('pages.edit')}" aria-label="${t('pages.edit')}">${icon('edit', 14, '2')}</button>
                    <button type="button" class="icon-manager-action-btn icon-manager-action-btn--danger" data-icon-delete="${escapeHtml(iconItem.key)}" title="${t('btn.delete')}" aria-label="${t('btn.delete')}"${iconItem.canDelete ? '' : ' disabled'}>${icon('trash', 14, '2')}</button>
                </div>
            `;
            grid.appendChild(card);
        });

        grid.querySelectorAll('[data-icon-edit]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const iconItem = iconManagerData.find(function(item) { return item.key === btn.dataset.iconEdit; });
                if (iconItem) openIconManagerModal(iconItem);
            });
        });
        grid.querySelectorAll('[data-icon-delete]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const iconItem = iconManagerData.find(function(item) { return item.key === btn.dataset.iconDelete; });
                if (iconItem) deleteIconManagerIcon(iconItem);
            });
        });
    }

    function openIconManagerModal(iconItem) {
        const isEdit = !!iconItem;
        document.getElementById('iconManagerModalTitle').textContent = isEdit ? t('icons.edit_icon') : t('icons.add_icon');
        document.getElementById('iconManagerOldKey').value = isEdit ? iconItem.key : '';
        document.getElementById('iconManagerKey').value = isEdit ? iconItem.key : '';
        document.getElementById('iconManagerLabel').value = isEdit ? (iconItem.label || '') : '';
        document.getElementById('iconManagerTags').value = isEdit ? (iconItem.tags || []).join(', ') : '';
        document.getElementById('iconManagerViewBox').value = isEdit ? (iconItem.viewBox || '0 0 24 24') : '0 0 24 24';
        document.getElementById('iconManagerSvg').value = isEdit ? (iconItem.svg || '') : '';
        document.getElementById('iconRenameWarning').hidden = true;
        updateIconManagerPreview();
        closeAllComboboxes();
        document.getElementById('iconManagerModalOverlay').style.display = 'flex';
    }

    function closeIconManagerModal() {
        document.getElementById('iconManagerModalOverlay').style.display = 'none';
    }

    // Custom icon-set picker (replaces native <select> to avoid modal overlay clipping)
    (function() {
        var picker = document.getElementById('iconifyImportSetPicker');
        if (!picker) return;
        var btn = document.getElementById('iconifyImportSetBtn');
        var label = document.getElementById('iconifyImportSetLabel2');
        var list = document.getElementById('iconifyImportSetList');
        var hidden = document.getElementById('iconifyImportSet');

        function openPicker() {
            var rect = btn.getBoundingClientRect();
            list.style.top = (rect.bottom + 4) + 'px';
            list.style.left = rect.left + 'px';
            list.style.width = rect.width + 'px';
            list.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
        }
        function closePicker() {
            list.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            list.hidden ? openPicker() : closePicker();
        });
        list.addEventListener('click', function(e) {
            var opt = e.target.closest('[role="option"]');
            if (!opt) return;
            list.querySelectorAll('[role="option"]').forEach(function(o) { o.setAttribute('aria-selected', 'false'); });
            opt.setAttribute('aria-selected', 'true');
            hidden.value = opt.dataset.value;
            label.textContent = opt.textContent;
            closePicker();
            updateIconifyImportLicense();
        });
        document.addEventListener('click', function(e) {
            if (!picker.contains(e.target)) closePicker();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !list.hidden) closePicker();
        });
    })();

    function openIconifyImportModal() {
        const overlay = document.getElementById('iconifyImportModalOverlay');
        if (!overlay) return;
        closeAllComboboxes();
        // Also close icon-set picker if open
        var setList = document.getElementById('iconifyImportSetList');
        if (setList) setList.hidden = true;
        overlay.style.display = 'flex';
        updateIconifyImportLicense();
        setTimeout(function() {
            document.getElementById('iconifyImportQuery')?.focus();
        }, 50);
    }

    function closeIconifyImportModal() {
        document.getElementById('iconifyImportModalOverlay').style.display = 'none';
        var setList = document.getElementById('iconifyImportSetList');
        if (setList) setList.hidden = true;
    }

    function updateIconifyImportLicense() {
        const prefix = document.getElementById('iconifyImportSet')?.value || 'lucide';
        const setInfo = ICONIFY_IMPORT_SETS[prefix] || ICONIFY_IMPORT_SETS.lucide;
        const license = document.getElementById('iconifyImportLicense');
        if (license) {
            license.innerHTML = escapeHtml(t('icons.import_license_hint', {
                set: setInfo.label,
                license: setInfo.license
            }));
        }
    }

    async function searchIconifyImport() {
        const prefix = document.getElementById('iconifyImportSet')?.value || 'lucide';
        const query = document.getElementById('iconifyImportQuery')?.value.trim() || '';
        const results = document.getElementById('iconifyImportResults');
        const empty = document.getElementById('iconifyImportEmpty');
        const button = document.getElementById('iconifyImportSearchBtn');
        if (!results || query.length < 2) {
            showToast(t('icons.import_query_short'), 'error');
            return;
        }

        results.innerHTML = '<p class="form-hint">' + escapeHtml(t('icons.import_loading')) + '</p>';
        if (empty) empty.style.display = 'none';
        if (button) button.disabled = true;

        try {
            const url = 'api.php?action=iconify-search&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                + '&prefix=' + encodeURIComponent(prefix)
                + '&query=' + encodeURIComponent(query);
            const result = await fetchJsonWithTimeout(url, {}, 15000);
            if (!result.success) {
                showToast(result.message, 'error');
                results.innerHTML = '';
                return;
            }
            renderIconifyImportResults(result.data.icons || []);
        } catch (error) {
            showToast(t('icons.import_error', {message: error.message}), 'error');
            results.innerHTML = '';
        } finally {
            if (button) button.disabled = false;
        }
    }

    function renderIconifyImportResults(icons) {
        const results = document.getElementById('iconifyImportResults');
        const empty = document.getElementById('iconifyImportEmpty');
        if (!results) return;
        results.innerHTML = '';
        if (!icons.length) {
            if (empty) empty.style.display = '';
            return;
        }
        if (empty) empty.style.display = 'none';

        icons.forEach(function(iconItem) {
            const card = document.createElement('article');
            card.className = 'iconify-import-card';
            card.innerHTML = `
                <div class="iconify-import-card__preview">
                    <svg viewBox="${escapeHtml(iconItem.viewBox || '0 0 24 24')}" ${iconSvgAttributes(iconItem)}>${iconSvgMarkup(iconItem)}</svg>
                </div>
                <div class="iconify-import-card__body">
                    <strong>${escapeHtml(iconItem.name || '')}</strong>
                    <span>${escapeHtml(iconItem.setLabel || iconItem.prefix || '')} · ${escapeHtml(iconItem.license || '')}</span>
                    <code>${escapeHtml(iconItem.key || '')}</code>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" data-iconify-import="${escapeHtml(iconItem.full || '')}" data-iconify-key="${escapeHtml(iconItem.key || '')}">${iconManagerData.some(item => item.key === iconItem.key) ? t('icons.imported_short') : t('icons.import')}</button>
            `;
            if (iconManagerData.some(item => item.key === iconItem.key)) {
                card.querySelector('[data-iconify-import]').disabled = true;
                card.classList.add('iconify-import-card--imported');
            }
            results.appendChild(card);
        });

        results.querySelectorAll('[data-iconify-import]').forEach(function(button) {
            button.addEventListener('click', function() {
                importIconifyIcon(button.dataset.iconifyImport, button);
            });
        });
    }

    async function importIconifyIcon(fullName, button) {
        const formData = new FormData();
        formData.append('action', 'iconify-import');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('icon', fullName);

        if (button) button.disabled = true;
        try {
            const result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, 15000);
            if (!result.success) {
                showToast(result.message, 'error');
                return;
            }
            iconManagerSortMode = 'newest';
            const sortInput = document.getElementById('iconManagerSort');
            if (sortInput) sortInput.value = 'newest';
            const importedKey = String(fullName || '').replace(':', '-');
            highlightIconManagerCard(importedKey);
            renderIconManager(result.data);
            showToast(t('icons.imported'), 'success');
            if (button) {
                button.textContent = t('icons.imported_short');
                button.disabled = true;
                button.closest('.iconify-import-card')?.classList.add('iconify-import-card--imported');
            }
        } catch (error) {
            showToast(t('icons.import_error', {message: error.message}), 'error');
        } finally {
            if (button && button.textContent !== t('icons.imported_short')) button.disabled = false;
        }
    }

    function updateIconManagerPreview() {
        const preview = document.getElementById('iconManagerPreview');
        const svgInput = document.getElementById('iconManagerSvg');
        const viewBoxInput = document.getElementById('iconManagerViewBox');
        if (preview) {
            preview.setAttribute('viewBox', normalizeIconViewBoxClient(viewBoxInput?.value || ''));
            preview.innerHTML = sanitizeIconSvgClient(svgInput.value);
        }
        const oldKey = document.getElementById('iconManagerOldKey').value;
        const newKey = document.getElementById('iconManagerKey').value;
        const warning = document.getElementById('iconRenameWarning');
        if (warning) warning.hidden = !(oldKey && newKey && oldKey !== newKey);
    }

    document.getElementById('iconManagerSvg')?.addEventListener('input', function() {
        const viewBoxInput = document.getElementById('iconManagerViewBox');
        const extractedViewBox = extractIconViewBoxClient(this.value);
        if (extractedViewBox && viewBoxInput && (!viewBoxInput.value.trim() || normalizeIconViewBoxClient(viewBoxInput.value) === '0 0 24 24')) {
            viewBoxInput.value = extractedViewBox;
        }
        updateIconManagerPreview();
    });
    document.getElementById('iconManagerViewBox')?.addEventListener('input', updateIconManagerPreview);
    document.getElementById('iconManagerKey')?.addEventListener('input', updateIconManagerPreview);
    document.getElementById('iconManagerCleanupBtn')?.addEventListener('click', function() {
        const input = document.getElementById('iconManagerSvg');
        const extractedViewBox = extractIconViewBoxClient(input.value);
        if (extractedViewBox) document.getElementById('iconManagerViewBox').value = extractedViewBox;
        input.value = sanitizeIconSvgClient(input.value);
        updateIconManagerPreview();
        showToast(t('icons.cleanup_done'), 'success');
    });
    document.getElementById('iconManagerCurrentColorBtn')?.addEventListener('click', function() {
        const input = document.getElementById('iconManagerSvg');
        input.value = forceIconCurrentColor(input.value);
        updateIconManagerPreview();
        showToast(t('icons.current_color_done'), 'success');
    });
    document.getElementById('iconManagerForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const oldKey = document.getElementById('iconManagerOldKey').value;
        const newKey = document.getElementById('iconManagerKey').value.trim();
        if (!/^[a-z0-9][a-z0-9_-]{0,63}$/.test(newKey)) {
            showToast('Invalid icon key. Use lowercase letters, numbers, hyphens, and underscores.', 'error');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'save-icon');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('old_key', oldKey);
        formData.append('key', newKey);
        formData.append('label', document.getElementById('iconManagerLabel').value);
        formData.append('tags', document.getElementById('iconManagerTags').value);
        formData.append('viewBox', document.getElementById('iconManagerViewBox').value);
        formData.append('svg', document.getElementById('iconManagerSvg').value);

        try {
            const result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, 15000);
            if (result.success) {
                closeIconManagerModal();
                iconManagerSortMode = 'newest';
                const sortInput = document.getElementById('iconManagerSort');
                if (sortInput) sortInput.value = 'newest';
                highlightIconManagerCard(newKey);
                renderIconManager(result.data);
                showToast(t('icons.saved'), 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('icons.save_error', {message: error.message}), 'error');
        }
    });
    document.getElementById('iconifyImportSet')?.addEventListener('change', function() {
        updateIconifyImportLicense();
        const query = document.getElementById('iconifyImportQuery')?.value.trim() || '';
        if (query.length >= 2) searchIconifyImport();
    });
    document.getElementById('iconManagerSearch')?.addEventListener('input', function() {
        iconManagerSearchTerm = this.value.trim();
        dashboardListPages.icons = 1;
        renderIconManager();
    });
    document.getElementById('iconManagerSort')?.addEventListener('change', function() {
        iconManagerSortMode = this.value || 'alpha';
        dashboardListPages.icons = 1;
        renderIconManager();
    });
    document.getElementById('iconifyImportQuery')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchIconifyImport();
        }
    });
    document.getElementById('iconifyImportSearchBtn')?.addEventListener('click', searchIconifyImport);

    function deleteIconManagerIcon(iconItem) {
        showModal(t('icons.delete_icon'), t('icons.delete_confirm', {key: iconItem.key}), async function() {
            closeModal();
            const formData = new FormData();
            formData.append('action', 'delete-icon');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('key', iconItem.key);

            try {
                const result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, 15000);
                if (result.success) {
                    renderIconManager(result.data);
                    showToast(t('icons.deleted'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('icons.delete_error', {message: error.message}), 'error');
            }
        });
    }
    <?php endif; ?>

    // ============================================================
    // MAIL MANAGEMENT
    // ============================================================

    let mailsData = [];
    let mailFormsData = [];
    let mailFormFilter = '';
    let mailSortField = 'received';
    let mailSortDir = 'desc';

    async function loadMails() {
        try {
            if (!currentSettings) {
                await loadSettings();
            }
            const response = await fetch('api.php?action=load-mails');
            const result = await response.json();

            if (result.success) {
                if (Array.isArray(result.data)) {
                    mailsData = result.data;
                    mailFormsData = [];
                } else {
                    mailsData = result.data.mails || [];
                    mailFormsData = result.data.forms || [];
                }
                populateMailFormFilter();
                renderMails();
                updateMailBadge();
                updateMailConfigBanner();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    function renderMails() {
        const tbody = document.getElementById('mailsList');
        if (!tbody) return;
        tbody.innerHTML = '';
        const visibleMails = getFilteredMails();

        if (!visibleMails || visibleMails.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="6" style="color: var(--nb-text-muted); text-align: center; padding: var(--nb-space-6);">${escapeHtml(t('mails.no_messages'))}</td>`;
            tbody.appendChild(tr);
            renderAdminListFooter('mailsListFooter', 'mails', 0, getDashboardPageSize(), renderMails, 'mailsListFooterTop');
            updateMailBulkActions();
            updateMailSortIndicators();
            return;
        }

        const sortedMails = getSortedMails(visibleMails);
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(sortedMails, 'mails', pageSize);
        renderAdminListFooter('mailsListFooter', 'mails', sortedMails.length, pageSize, renderMails, 'mailsListFooterTop');

        paged.items.forEach(mail => {
            const date = new Date(mail.timestamp);
            const dateStr = date.toLocaleDateString();
            const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const isUnread = !mail.read;
            const isStarred = !!mail.starred;
            const formLabel = mail.formLabel || mail.formId || t('forms.contact_default');

            const tr = document.createElement('tr');
            tr.className = 'page-list-row';
            if (isUnread) tr.classList.add('mail-row-unread');

            const tdRead = document.createElement('td');
            tdRead.className = 'mail-cell-flag';
            const readBtn = document.createElement('button');
            readBtn.type = 'button';
            readBtn.className = 'mail-flag-btn mail-read-toggle' + (isUnread ? ' is-active' : '');
            readBtn.title = isUnread ? t('mails.mark_read') : t('mails.mark_unread');
            readBtn.setAttribute('aria-label', readBtn.title);
            readBtn.innerHTML = `<span class="mail-read-dot" aria-hidden="true"></span>`;
            readBtn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                setMailFlags(mail.id, { read: isUnread });
            };
            tdRead.appendChild(readBtn);

            const tdStar = document.createElement('td');
            tdStar.className = 'mail-cell-flag';
            const starBtn = document.createElement('button');
            starBtn.type = 'button';
            starBtn.className = 'mail-flag-btn mail-star-toggle' + (isStarred ? ' is-active' : '');
            starBtn.title = isStarred ? t('mails.unstar') : t('mails.star');
            starBtn.setAttribute('aria-label', starBtn.title);
            starBtn.textContent = isStarred ? '★' : '☆';
            starBtn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                setMailFlags(mail.id, { starred: !isStarred });
            };
            tdStar.appendChild(starBtn);

            const tdForm = document.createElement('td');
            tdForm.className = 'mail-cell-form';
            tdForm.textContent = formLabel;

            const tdFrom = document.createElement('td');
            tdFrom.className = 'page-list-cell-title';

            const fromLink = document.createElement('a');
            fromLink.href = '#';
            fromLink.className = 'page-list-title-link';
            fromLink.textContent = mail.name;
            fromLink.onclick = (e) => { e.preventDefault(); openMailDetail(mail.id); };
            tdFrom.appendChild(fromLink);

            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';

            const viewLink = document.createElement('a');
            viewLink.href = '#';
            viewLink.className = 'page-list-row-action';
            viewLink.innerHTML = icon('eye', 12, '2') + ' ' + t('pages.view');
            viewLink.onclick = (e) => { e.preventDefault(); openMailDetail(mail.id); };
            actions.appendChild(viewLink);

            const sep = document.createElement('span');
            sep.className = 'page-list-row-action-sep';
            sep.textContent = '|';
            actions.appendChild(sep);

            const trashLink = document.createElement('a');
            trashLink.href = '#';
            trashLink.className = 'page-list-row-action page-list-row-action--danger';
            trashLink.innerHTML = icon('trash', 12, '2') + ' ' + t('btn.delete');
            trashLink.onclick = (e) => { e.preventDefault(); deleteMail(mail.id); };
            actions.appendChild(trashLink);

            tdFrom.appendChild(actions);

            const tdSubject = document.createElement('td');
            tdSubject.textContent = mail.occasion || '';

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            tdDate.textContent = `${dateStr} ${timeStr}`;

            tr.appendChild(tdRead);
            tr.appendChild(tdStar);
            tr.appendChild(tdForm);
            tr.appendChild(tdFrom);
            tr.appendChild(tdSubject);
            tr.appendChild(tdDate);
            tbody.appendChild(tr);
        });
        updateMailBulkActions();
        updateMailSortIndicators();
    }

    function populateMailFormFilter() {
        const select = document.getElementById('mailFormFilter');
        if (!select) return;
        const current = mailFormFilter;
        const byId = new Map();
        (mailFormsData || []).forEach(form => {
            if (form.id) byId.set(form.id, form.label || form.id);
        });
        (mailsData || []).forEach(mail => {
            const id = mail.formId || 'contact';
            if (!byId.has(id)) byId.set(id, mail.formLabel || id);
        });
        select.innerHTML = `<option value="">${escapeHtml(t('mails.all_forms'))}</option>`;
        Array.from(byId.entries())
            .sort((a, b) => String(a[1]).localeCompare(String(b[1]), undefined, { sensitivity: 'base' }))
            .forEach(([id, label]) => {
                const option = document.createElement('option');
                option.value = id;
                option.textContent = label;
                select.appendChild(option);
            });
        select.value = byId.has(current) ? current : '';
        mailFormFilter = select.value;
    }

    function setMailFormFilter(value) {
        mailFormFilter = value || '';
        dashboardListPages.mails = 1;
        renderMails();
    }

    function getFilteredMails() {
        const list = Array.isArray(mailsData) ? mailsData : [];
        if (!mailFormFilter) return list;
        return list.filter(mail => (mail.formId || 'contact') === mailFormFilter);
    }

    function getSortedMails(list) {
        return [...(list || mailsData)].sort((a, b) => {
            let cmp = 0;
            if (mailSortField === 'read') {
                cmp = Number(!!a.read) - Number(!!b.read);
            } else if (mailSortField === 'starred') {
                cmp = Number(!!a.starred) - Number(!!b.starred);
            } else if (mailSortField === 'form') {
                cmp = String(a.formLabel || a.formId || '').localeCompare(String(b.formLabel || b.formId || ''), undefined, { sensitivity: 'base' });
            } else if (mailSortField === 'from') {
                cmp = String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
            } else if (mailSortField === 'subject') {
                cmp = String(a.occasion || '').localeCompare(String(b.occasion || ''), undefined, { sensitivity: 'base' });
            } else {
                cmp = new Date(a.timestamp || 0).getTime() - new Date(b.timestamp || 0).getTime();
            }
            return mailSortDir === 'asc' ? cmp : -cmp;
        });
    }

    function sortMails(field) {
        if (mailSortField === field) {
            mailSortDir = mailSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            mailSortField = field;
            mailSortDir = field === 'read' || field === 'from' || field === 'subject' || field === 'form' ? 'asc' : 'desc';
        }
        dashboardListPages.mails = 1;
        renderMails();
    }

    function updateMailSortIndicators() {
        document.querySelectorAll('#mailsListTable .page-list-sortable').forEach(th => {
            const iconEl = th.querySelector('.page-list-sort-icon');
            if (!iconEl) return;
            if (th.dataset.mailSort === mailSortField) {
                th.classList.add('sorted');
                iconEl.textContent = mailSortDir === 'asc' ? '▲' : '▼';
            } else {
                th.classList.remove('sorted');
                iconEl.textContent = '';
            }
        });
    }

    function updateMailBadge() {
        const unreadCount = mailsData.filter(m => !m.read).length;
        const badge = document.getElementById('mailBadge');
        if (!badge) return;

        if (unreadCount > 0) {
            badge.textContent = unreadCount;
            badge.classList.remove('mail-badge--hidden');
        } else {
            badge.classList.add('mail-badge--hidden');
        }
    }

    function updateMailBulkActions() {
        const btn = document.getElementById('deleteReadMailsBtn');
        if (!btn) return;
        const readCount = (mailsData || []).filter(m => m.read).length;
        btn.disabled = readCount === 0;
        btn.textContent = readCount > 0
            ? t('mails.delete_read_count', {count: readCount})
            : t('mails.delete_read');
    }

    function updateMailConfigBanner() {
        const banner = document.getElementById('mailConfigBanner');
        if (!banner) return;
        const title = document.getElementById('mailConfigBannerTitle');
        const text = document.getElementById('mailConfigBannerText');
        const email = (currentSettings && currentSettings.email) || {};
        const method = email.method || 'inactive';
        let titleText = '';
        let bodyText = '';

        if (method === 'inactive') {
            titleText = t('mails.email_inactive_title');
            bodyText = t('mails.email_inactive_text');
        } else if (!email.recipientEmail) {
            titleText = t('mails.email_recipient_missing_title');
            bodyText = t('mails.email_recipient_missing_text');
        }

        if (!titleText) {
            banner.hidden = true;
            return;
        }

        title.textContent = titleText;
        text.textContent = bodyText;
        banner.hidden = false;
    }

    function openEmailSettings() {
        switchTab('settings');
        const emailTab = document.querySelector('[data-settings-tab="email"]');
        if (emailTab) emailTab.click();
    }

    async function loadUnreadCount() {
        try {
            const response = await fetch('api.php?action=unread-mail-count');
            const result = await response.json();

            if (result.success) {
                const badge = document.getElementById('mailBadge');
                if (!badge) return;
                if (result.data.count > 0) {
                    badge.textContent = result.data.count;
                    badge.classList.remove('mail-badge--hidden');
                }
            }
        } catch (error) {
            // Badge is non-critical; ignore transient fetch aborts during navigation/tests.
        }
    }

    function openMailDetail(mailId) {
        const mail = mailsData.find(m => m.id === mailId);
        if (!mail) return;

        const date = new Date(mail.timestamp);
        const dateStr = date.toLocaleDateString();
        const timeStr = date.toLocaleTimeString();
        const formLabel = mail.formLabel || mail.formId || t('forms.contact_default');
        const fields = Array.isArray(mail.fields) ? mail.fields.filter(field => field && field.value !== '') : [];
        const fieldsHtml = fields.length ? `
            <div class="mail-detail-row mail-detail-fields">
                <label>${t('mails.form_fields')}</label>
                <div class="mail-fields-list">
                    ${fields.map(field => `
                        <div class="mail-field-item">
                            <span>${escapeHtml(field.label || field.key || '')}</span>
                            <strong>${escapeHtml(field.value || '').replace(/\n/g, '<br>')}</strong>
                        </div>
                    `).join('')}
                </div>
            </div>
        ` : '';

        document.getElementById('mailDetailTitle').textContent = mail.occasion;
        document.getElementById('mailDetailContent').innerHTML = `
            <div class="mail-detail-row">
                <label>${t('mails.form')}</label>
                <span>${escapeHtml(formLabel)}</span>
            </div>
            <div class="mail-detail-row">
                <label>${t('mails.date')}</label>
                <span>${dateStr} ${timeStr}</span>
            </div>
            <div class="mail-detail-row">
                <label>${t('mails.name')}</label>
                <span>${escapeHtml(mail.name)}</span>
            </div>
            <div class="mail-detail-row">
                <label>${t('mails.email')}</label>
                <span><a href="mailto:${escapeHtml(mail.email)}">${escapeHtml(mail.email)}</a></span>
            </div>
            ${mail.phone ? `
            <div class="mail-detail-row">
                <label>${t('mails.phone')}</label>
                <span><a href="tel:${escapeHtml(mail.phone)}">${escapeHtml(mail.phone)}</a></span>
            </div>
            ` : ''}
            ${mail.date ? `
            <div class="mail-detail-row">
                <label>${t('mails.preferred_date')}</label>
                <span>${new Date(mail.date).toLocaleDateString()}</span>
            </div>
            ` : ''}
            <div class="mail-detail-row mail-detail-message">
                <label>${t('mails.message')}</label>
                <div class="mail-message-text">${escapeHtml(mail.message).replace(/\n/g, '<br>')}</div>
            </div>
            ${fieldsHtml}
        `;

        document.getElementById('mailDeleteBtn').onclick = () => deleteMail(mailId);
        document.getElementById('mailsListView').style.display = 'none';
        document.getElementById('mailDetailView').style.display = '';

        if (!mail.read) {
            markMailRead(mailId);
        }
    }

    function closeMailDetail() {
        document.getElementById('mailDetailView').style.display = 'none';
        document.getElementById('mailsListView').style.display = '';
    }

    async function markMailRead(mailId) {
        return setMailFlags(mailId, { read: true }, { silent: true });
    }

    async function setMailFlags(mailId, flags, options = {}) {
        try {
            const formData = new FormData();
            formData.append('action', 'update-mail-flags');
            formData.append('mail_id', mailId);
            formData.append('csrf_token', CSRF_TOKEN);
            Object.entries(flags).forEach(([key, value]) => {
                formData.append(key, value ? '1' : '0');
            });

            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                const mail = mailsData.find(m => m.id === mailId);
                if (mail) {
                    Object.assign(mail, flags);
                }
                renderMails();
                updateMailBadge();
            } else if (!options.silent) {
                showToast(result.message, 'error');
            }
        } catch (error) {
            if (options.silent) {
                console.error('Error updating mail flags:', error);
            } else {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            }
        }
    }

    async function markAllMailsRead() {
        try {
            const formData = new FormData();
            formData.append('action', 'mark-all-mails-read');
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                mailsData.forEach(m => m.read = true);
                renderMails();
                updateMailBadge();
                showToast(t('toast.all_read'), 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }

    function deleteReadMails() {
        const readCount = (mailsData || []).filter(m => m.read).length;
        if (readCount === 0) return;

        showModal(t('modal.delete_read_messages'), t('modal.delete_read_messages_confirm', {count: readCount}), async () => {
            try {
                const formData = new FormData();
                formData.append('action', 'delete-read-mails');
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch('api.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    mailsData = mailsData.filter(m => !m.read);
                    renderMails();
                    updateMailBadge();
                    closeModal();
                    showToast(t('toast.read_messages_deleted', {count: result.data?.deleted || readCount}), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            }
        });
    }

    function deleteMail(mailId) {
        showModal(t('modal.delete_message'), t('modal.delete_message_confirm'), async () => {
            try {
                const formData = new FormData();
                formData.append('action', 'delete-mail');
                formData.append('mail_id', mailId);
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch('api.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    mailsData = mailsData.filter(m => m.id !== mailId);
                    renderMails();
                    updateMailBadge();
                    closeMailDetail();
                    closeModal();
                    showToast(t('toast.message_deleted'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            }
        });
    }

    // Load badge on startup
    loadUnreadCount();

    // ============================================================
    // NEWS / BLOG MANAGEMENT
    // ============================================================

    let newsLoaded = false;
    let newsData = [];
    let editingPostId = null;
    let newsHtmlMode = false;

    async function loadNews() {
        try {
            const response = await fetch('api.php?action=load-news');
            const result = await response.json();

            if (result.success) {
                newsData = result.data;
                renderNewsList();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading_news', {message: error.message}), 'error');
        }
    }

    function renderNewsList() {
        const tbody = document.getElementById('newsListBody');
        const defaultLang = '<?php echo SITE_LANG_DEFAULT; ?>';
        const otherLangs = <?php $defLang = SITE_LANG_DEFAULT; echo json_encode(array_values(array_filter(array_keys($siteLanguages), function($c) use ($defLang) { return $c !== $defLang; }))); ?>;
        const colCount = 2 + otherLangs.length;

        if (newsData.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center;padding:2rem;">${t('news.no_posts')}</td></tr>`;
            renderAdminListFooter('newsListFooter', 'news', 0, getDashboardPageSize(), renderNewsList, 'newsListFooterTop');
            return;
        }

        // Group posts by slug — primary language row is the "main" entry
        const slugGroups = {};
        newsData.forEach(post => {
            const slug = post.slug || post.id;
            if (!slugGroups[slug]) slugGroups[slug] = {};
            const lang = post.lang || defaultLang;
            slugGroups[slug][lang] = post;
        });

        // Sort groups by date descending (use primary lang post date, or any available)
        const sortedSlugs = Object.keys(slugGroups).sort((a, b) => {
            const aPost = slugGroups[a][defaultLang] || Object.values(slugGroups[a])[0];
            const bPost = slugGroups[b][defaultLang] || Object.values(slugGroups[b])[0];
            return (bPost.date || '').localeCompare(aPost.date || '');
        });

        tbody.innerHTML = '';
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(sortedSlugs, 'news', pageSize);
        renderAdminListFooter('newsListFooter', 'news', sortedSlugs.length, pageSize, renderNewsList, 'newsListFooterTop');

        paged.items.forEach(slug => {
            const group = slugGroups[slug];
            // Primary post = default lang or first available
            const post = group[defaultLang] || Object.values(group)[0];
            const postLang = post.lang || defaultLang;

            const tr = document.createElement('tr');
            tr.className = 'page-list-row';

            // Title cell
            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';

            const slugSpan = document.createElement('span');
            slugSpan.className = 'page-list-slug';
            slugSpan.textContent = '/news/' + slug;
            tdTitle.appendChild(slugSpan);

            const titleLink = document.createElement('a');
            titleLink.href = '#';
            titleLink.className = 'page-list-title-link';
            titleLink.textContent = post.title || t('news.untitled');
            titleLink.onclick = (e) => { e.preventDefault(); editPost(post.id); };
            tdTitle.appendChild(titleLink);

            // Status badge
            if (post.hidden) {
                const badge = document.createElement('span');
                badge.className = 'news-status news-draft';
                badge.textContent = t('news.draft');
                tdTitle.appendChild(badge);
            }

            // Hover actions
            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';

            const editLink = document.createElement('a');
            editLink.href = '#';
            editLink.className = 'page-list-row-action';
            editLink.innerHTML = icon('edit', 12, '2') + ' ' + t('news.edit');
            editLink.onclick = (e) => { e.preventDefault(); editPost(post.id); };
            actions.appendChild(editLink);

            const sep1 = document.createElement('span');
            sep1.className = 'page-list-row-action-sep';
            sep1.textContent = '|';
            actions.appendChild(sep1);

            const langPrefix = postLang === defaultLang ? '' : postLang + '/';
            const viewLink = document.createElement('a');
            viewLink.href = '../' + langPrefix + 'news/' + slug;
            viewLink.target = '_blank';
            viewLink.className = 'page-list-row-action';
            viewLink.innerHTML = icon('eye', 12, '2') + ' ' + t('news.view');
            actions.appendChild(viewLink);

            const sep2 = document.createElement('span');
            sep2.className = 'page-list-row-action-sep';
            sep2.textContent = '|';
            actions.appendChild(sep2);

            const toggleLink = document.createElement('a');
            toggleLink.href = '#';
            toggleLink.className = 'page-list-row-action';
            toggleLink.innerHTML = post.hidden
                ? icon('eye', 12, '2') + ' ' + t('news.publish')
                : icon('eye-off', 12, '2') + ' ' + t('news.unpublish');
            toggleLink.onclick = (e) => { e.preventDefault(); toggleNewsStatus(post.id); };
            actions.appendChild(toggleLink);

            const sep3 = document.createElement('span');
            sep3.className = 'page-list-row-action-sep';
            sep3.textContent = '|';
            actions.appendChild(sep3);

            const deleteLink = document.createElement('a');
            deleteLink.href = '#';
            deleteLink.className = 'page-list-row-action page-list-row-action--danger';
            deleteLink.innerHTML = icon('trash', 12, '2') + ' ' + t('btn.delete');
            deleteLink.onclick = (e) => { e.preventDefault(); deletePost(post.id); };
            actions.appendChild(deleteLink);

            tdTitle.appendChild(actions);
            tr.appendChild(tdTitle);

            // Date cell
            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            if (post.date) {
                const d = new Date(post.date + 'T00:00:00');
                tdDate.textContent = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            } else {
                tdDate.textContent = '—';
            }
            tr.appendChild(tdDate);

            // Other language columns
            otherLangs.forEach(lang => {
                const td = document.createElement('td');
                td.className = 'page-list-cell-lang';
                const langPost = group[lang];
                if (langPost) {
                    const editBtn = document.createElement('a');
                    editBtn.href = '#';
                    editBtn.className = 'btn btn-secondary btn-sm page-list-lang-btn';
                    editBtn.innerHTML = icon('edit', 12) + ' ' + t('news.edit');
                    editBtn.onclick = (e) => { e.preventDefault(); editPost(langPost.id); };
                    td.appendChild(editBtn);
                } else {
                    const createLink = document.createElement('a');
                    createLink.href = '#';
                    createLink.className = 'page-list-create-link';
                    createLink.textContent = t('pages.create');
                    createLink.onclick = (e) => {
                        e.preventDefault();
                        createNewsTranslation(post, lang, createLink);
                    };
                    td.appendChild(createLink);
                }
                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });
    }

    function showNewsList() {
        document.getElementById('newsListContainer').style.display = 'block';
        document.getElementById('newsEditorContainer').style.display = 'none';
    }

    function addNewPost() {
        const lang = '<?php echo SITE_LANG_DEFAULT; ?>';
        editingPostId = null;
        showPostEditor({
            id: '',
            lang: lang,
            title: '',
            slug: '',
            date: new Date().toISOString().split('T')[0],
            author: '',
            excerpt: '',
            image: '',
            content: '',
            hidden: false
        });
    }

    function editPost(postId) {
        const post = newsData.find(p => p.id === postId);
        if (!post) return;
        editingPostId = postId;
        showPostEditor(post);
    }

    function showPostEditor(post) {
        const isNew = !editingPostId;
        newsHtmlMode = false;

        // Hide list, show editor
        document.getElementById('newsListContainer').style.display = 'none';
        document.getElementById('newsEditorContainer').style.display = 'block';

        // Update title
        const editorTitle = document.getElementById('newsEditorTitle');
        if (editorTitle) editorTitle.textContent = isNew ? t('news.new_post') : (post.title || t('news.edit'));

        // Build language options
        const languages = <?php echo json_encode($siteLanguages); ?>;
        const langOpts = Object.entries(languages).map(([code, name]) =>
            `<option value="${code}"${code === (post.lang || '<?php echo SITE_LANG_DEFAULT; ?>') ? ' selected' : ''}>${escapeHtml(name)}</option>`
        ).join('');

        const container = document.getElementById('newsEditorForm');
        container.innerHTML = `
            <div class="news-editor">
                <div class="editor-form-grid editor-form-grid--news">
                    <div class="editor-form-row editor-form-row--span-2">
                        <label for="newsTitle">${t('news.post_title')}</label>
                        <input type="text" id="newsTitle" class="editor-input" value="${escapeHtml(post.title)}" placeholder="${t('news.post_title')}">
                    </div>
                    <div class="editor-form-row-half editor-form-row--span-2">
                        <div class="editor-form-row">
                            <label for="newsSlug">${t('news.post_slug')}</label>
                            <input type="text" id="newsSlug" class="editor-input" value="${escapeHtml(post.slug)}" placeholder="my-post-slug">
                        </div>
                        <div class="editor-form-row">
                            <label for="newsDate">${t('news.post_date')}</label>
                            <input type="date" id="newsDate" class="editor-input" value="${escapeHtml(post.date)}">
                        </div>
                    </div>
                    <div class="editor-form-row-half editor-form-row--span-2">
                        <div class="editor-form-row">
                            <label for="newsAuthor">${t('news.post_author')}</label>
                            <input type="text" id="newsAuthor" class="editor-input" value="${escapeHtml(post.author)}">
                        </div>
                        <div class="editor-form-row">
                            <label for="newsLang">${t('news.post_language')}</label>
                            <select id="newsLang" class="editor-input">${langOpts}</select>
                        </div>
                    </div>
                    <div class="editor-form-row editor-form-row--cover">
                        <label for="newsImage">${t('news.post_image')}</label>
                        <div class="ce-image-input-row">
                            <input type="text" id="newsImage" class="editor-input" value="${escapeHtml(post.image)}" placeholder="/assets/images/cover.jpg">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="browseNewsImage()">${t('btn.browse')}</button>
                        </div>
                        ${AI_FEATURES_ENABLED ? `<p class="ai-field-hint">${t('ai.image_field_hint')} <button type="button" class="btn btn-secondary btn-sm" onclick="openAiImageGenerator(getNewsImagePrompt(), '16:9')">${t('ai.open_image_generator')}</button></p>` : ''}
                        <div class="ce-image-preview" id="newsImagePreview">
                            <img src="${post.image ? (post.image.startsWith('/') ? '..' + escapeHtml(post.image) : escapeHtml(post.image)) : ''}" alt="" style="${post.image ? '' : 'display:none;'}">
                        </div>
                    </div>
                    <div class="editor-form-row editor-form-row--full">
                        <label for="newsExcerpt">${t('news.post_excerpt')}</label>
                        <textarea id="newsExcerpt" class="editor-textarea" rows="3">${escapeHtml(post.excerpt)}</textarea>
                    </div>
                    <div class="editor-form-row editor-form-row--full">
                        <label>${t('news.post_content')}</label>
                        <div class="news-wysiwyg-toolbar">
                            <button type="button" onclick="newsExecCmd('bold')" title="Bold"><b>B</b></button>
                            <button type="button" onclick="newsExecCmd('italic')" title="Italic"><i>I</i></button>
                            <button type="button" onclick="newsInsertLink()" title="Link">🔗</button>
                            <button type="button" onclick="newsInsertHeading()" title="Heading">H3</button>
                            <button type="button" onclick="newsExecCmd('insertUnorderedList')" title="List">☰</button>
                            <button type="button" onclick="newsCleanHtml()" title="Clean formatting">✕</button>
                            <span class="news-toolbar-sep"></span>
                            <label class="news-html-toggle">
                                <input type="checkbox" id="newsHtmlToggle" onchange="newsToggleHtml()">
                                <span>HTML</span>
                            </label>
                        </div>
                        <div id="newsContentWysiwyg" class="news-wysiwyg-editor" contenteditable="true"></div>
                        <textarea id="newsContentHtml" class="editor-textarea editor-textarea-large" style="display:none;" rows="16"></textarea>
                    </div>
                    <div class="editor-form-row">
                        <label class="editor-checkbox-label">
                            <input type="checkbox" id="newsHidden" ${post.hidden ? 'checked' : ''}>
                            ${t('news.draft')}
                        </label>
                    </div>
                </div>
                <div class="editor-form-actions">
                    <button class="btn btn-secondary" onclick="cancelPostEditor()">${t('btn.cancel')}</button>
                    <button class="btn btn-primary" onclick="savePost()">${t('btn.save')}</button>
                </div>
            </div>
        `;

        // Set WYSIWYG content (not escaped — it's HTML)
        document.getElementById('newsContentWysiwyg').innerHTML = post.content || '';

        // Update view + trash buttons (after form is built, so newsSlug/newsLang exist)
        updateNewsEditorButtons();

        // Auto-generate slug from title for new posts
        document.getElementById('newsTitle').addEventListener('input', function() {
            if (!editingPostId) {
                const slug = this.value.toLowerCase().trim()
                    .replace(/[äöüß]/g, m => ({ä:'ae',ö:'oe',ü:'ue',ß:'ss'})[m])
                    .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
                document.getElementById('newsSlug').value = slug;
            }
        });
    }

    // WYSIWYG helpers for news editor
    function newsExecCmd(cmd) {
        document.execCommand(cmd, false, null);
        document.getElementById('newsContentWysiwyg').focus();
    }

    function newsInsertLink() {
        showModal(
            'Link einfügen',
            '<label class="modal-field-label" for="newsLinkUrlInput">URL</label>' +
            '<input class="form-control" id="newsLinkUrlInput" type="url" value="https://" placeholder="https://...">',
            function() {
                const input = document.getElementById('newsLinkUrlInput');
                const url = input ? input.value.trim() : '';
                if (!url) return;
                closeModal();
                document.getElementById('newsContentWysiwyg').focus();
                document.execCommand('createLink', false, url);
            },
            {
                html: true,
                confirmText: 'Einfügen',
                confirmClass: 'btn btn-primary',
                focusSelector: '#newsLinkUrlInput'
            }
        );
        setTimeout(function() {
            const input = document.getElementById('newsLinkUrlInput');
            if (input) {
                input.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        document.getElementById('modalConfirm').click();
                    }
                });
                input.select();
            }
        }, 0);
    }

    function newsInsertHeading() {
        document.execCommand('formatBlock', false, 'h3');
        document.getElementById('newsContentWysiwyg').focus();
    }

    function newsCleanHtml() {
        document.execCommand('removeFormat', false, null);
        document.getElementById('newsContentWysiwyg').focus();
    }

    function newsToggleHtml() {
        const wysiwyg = document.getElementById('newsContentWysiwyg');
        const html = document.getElementById('newsContentHtml');
        newsHtmlMode = !newsHtmlMode;

        if (newsHtmlMode) {
            html.value = wysiwyg.innerHTML;
            wysiwyg.style.display = 'none';
            html.style.display = 'block';
            html.focus();
        } else {
            wysiwyg.innerHTML = html.value;
            html.style.display = 'none';
            wysiwyg.style.display = 'block';
            wysiwyg.focus();
        }
    }

    function updateNewsEditorButtons() {
        const viewBtn = document.getElementById('newsViewBtn');
        const trashBtn = document.getElementById('newsTrashBtn');
        if (editingPostId) {
            const defLang = '<?php echo SITE_LANG_DEFAULT; ?>';
            const lang = document.getElementById('newsLang')?.value || defLang;
            const slug = document.getElementById('newsSlug')?.value || editingPostId;
            const prefix = (lang === defLang) ? '../' : '../' + lang + '/';
            if (viewBtn) { viewBtn.href = prefix + 'news/' + slug; viewBtn.style.display = ''; }
            if (trashBtn) trashBtn.style.display = '';
        } else {
            if (viewBtn) viewBtn.style.display = 'none';
            if (trashBtn) trashBtn.style.display = 'none';
        }
    }

    function deleteCurrentPost() {
        if (!editingPostId) return;
        const title = document.getElementById('newsTitle')?.value || editingPostId;
        showModal(
            t('modal.delete_post'),
            t('modal.delete_post_confirm', { title }),
            async function() {
                closeModal();
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete-news');
                    formData.append('slug', editingPostId);
                    formData.append('csrf_token', CSRF_TOKEN);
                    const response = await fetch('api.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        showToast(t('toast.news_deleted'), 'success');
                        editingPostId = null;
                        showNewsList();
                        loadNews();
                    } else {
                        showToast(result.message, 'error');
                    }
                } catch (e) {
                    showToast(t('toast.error_generic', {message: e.message}), 'error');
                }
            }
        );
    }

    function getNewsContent() {
        if (newsHtmlMode) {
            return document.getElementById('newsContentHtml').value;
        }
        return document.getElementById('newsContentWysiwyg').innerHTML;
    }

    function browseNewsImage() {
        const inputEl = document.getElementById('newsImage');
        const previewEl = document.getElementById('newsImagePreview');
        browseImageForField(inputEl, previewEl);
    }

    function cancelPostEditor() {
        editingPostId = null;
        showNewsList();
        renderNewsList();
    }

    async function savePost() {
        const title = document.getElementById('newsTitle').value.trim();
        const date = document.getElementById('newsDate').value;

        if (!title || !date) {
            showToast(t('toast.news_date_required'), 'error');
            return;
        }

        const post = {
            id: editingPostId || '',
            lang: document.getElementById('newsLang').value,
            title: title,
            slug: document.getElementById('newsSlug').value.trim(),
            date: date,
            author: document.getElementById('newsAuthor').value.trim(),
            excerpt: document.getElementById('newsExcerpt').value.trim(),
            image: document.getElementById('newsImage').value.trim(),
            content: getNewsContent(),
            hidden: document.getElementById('newsHidden').checked
        };

        try {
            const formData = new FormData();
            formData.append('action', 'save-news');
            formData.append('post', JSON.stringify(post));
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                const wasNew = !editingPostId;
                showToast(wasNew ? t('toast.news_created') : t('toast.news_saved'), 'success');
                // Stay in editor — update slug reference for subsequent saves
                if (result.data?.slug) {
                    editingPostId = result.data.slug;
                    document.getElementById('newsSlug').value = result.data.slug;
                }
                // Update view + trash buttons
                updateNewsEditorButtons();
                loadNews();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }

    function deletePost(postId) {
        const post = newsData.find(p => p.id === postId);
        const title = post ? post.title : postId;

        showModal(t('modal.delete_post'), t('modal.delete_post_confirm', {title: title}), async () => {
            try {
                const formData = new FormData();
                formData.append('action', 'delete-news');
                formData.append('post_id', postId);
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch('api.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    newsData = newsData.filter(p => p.id !== postId);
                    renderNewsList();
                    closeModal();
                    showToast(t('toast.news_deleted'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            }
        });
    }

    async function createNewsTranslation(sourcePost, targetLang, linkEl) {
        linkEl.classList.add('disabled');
        linkEl.textContent = '...';
        try {
            // Clone the source post with the new language
            const newPost = Object.assign({}, sourcePost, {
                id: '',
                lang: targetLang,
                lastModified: new Date().toISOString()
            });

            const formData = new FormData();
            formData.append('action', 'save-news');
            formData.append('post', JSON.stringify(newPost));
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                showToast(t('toast.news_created'), 'success');
                await loadNews();
                editPost(result.data.id);
            } else {
                showToast(result.message, 'error');
                linkEl.classList.remove('disabled');
                linkEl.textContent = t('pages.create');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
            linkEl.classList.remove('disabled');
            linkEl.textContent = t('pages.create');
        }
    }

    async function toggleNewsStatus(postId) {
        try {
            const formData = new FormData();
            formData.append('action', 'toggle-news-status');
            formData.append('post_id', postId);
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                const post = newsData.find(p => p.id === postId);
                if (post) post.hidden = result.data.hidden;
                renderNewsList();
                showToast(result.data.hidden ? t('news.draft') : t('news.published'), 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }

    // ============================================================
    // SETTINGS MANAGEMENT
    // ============================================================

    function formatAiCents(cents) {
        return ((parseInt(cents || 0, 10) / 100).toFixed(2)) + ' EUR';
    }

    function updateAiUsage(usage) {
        var el = document.getElementById('aiUsageSummary');
        if (!AI_FEATURES_ENABLED || !aiServiceIsUsable(currentAiSettings || {})) {
            if (el) el.hidden = true;
            renderAiUsagePanel(null);
            return;
        }
        if (!el || !usage) return;
        el.hidden = false;
        var today = usage.today || {};
        var month = usage.month || {};
        el.textContent = t('ai.usage_summary', {
            today: today.requests || 0,
            cost: formatAiCents(month.estimatedCostCents || 0)
        });
        renderAiUsagePanel(usage);
    }

    function aiUsageStatTile(value, label, sub) {
        var tile = document.createElement('div');
        tile.className = 'ai-usage-stat';
        var valueEl = document.createElement('span');
        valueEl.className = 'ai-usage-stat__value';
        valueEl.textContent = value;
        var labelEl = document.createElement('span');
        labelEl.className = 'ai-usage-stat__label';
        labelEl.textContent = label;
        tile.appendChild(valueEl);
        tile.appendChild(labelEl);
        if (sub) {
            var subEl = document.createElement('span');
            subEl.className = 'ai-usage-stat__sub';
            subEl.textContent = sub;
            tile.appendChild(subEl);
        }
        return tile;
    }

    function renderAiUsagePanel(usage) {
        var panel = document.getElementById('aiUsagePanel');
        var body = document.getElementById('aiUsagePanelBody');
        if (!panel || !body) return;
        if (!usage) {
            panel.hidden = true;
            return;
        }
        panel.hidden = false;
        var today = usage.today || {};
        var month = usage.month || {};
        var budgetCents = parseInt((currentAiSettings && currentAiSettings.limits && currentAiSettings.limits.monthlyBudgetCents) || 0, 10);

        body.innerHTML = '';
        var grid = document.createElement('div');
        grid.className = 'ai-usage-grid';
        grid.appendChild(aiUsageStatTile(
            String(today.requests || 0),
            t('ai.usage_requests_today'),
            t('ai.usage_text_image_split', { text: today.textRequests || 0, images: today.imageRequests || 0 })
        ));
        grid.appendChild(aiUsageStatTile(
            formatAiCents(month.estimatedCostCents || 0),
            t('ai.usage_cost_month'),
            t('ai.usage_requests_month', { requests: month.requests || 0 })
        ));
        grid.appendChild(aiUsageStatTile(
            ((month.inputTokens || 0) + (month.outputTokens || 0)).toLocaleString(),
            t('ai.usage_tokens_month'),
            t('ai.usage_tokens_split', {
                input: (month.inputTokens || 0).toLocaleString(),
                output: (month.outputTokens || 0).toLocaleString()
            })
        ));
        body.appendChild(grid);

        if (budgetCents > 0) {
            var spent = month.estimatedCostCents || 0;
            var ratio = spent / budgetCents;
            var budget = document.createElement('div');
            budget.className = 'ai-usage-budget';
            var header = document.createElement('div');
            header.className = 'ai-usage-budget__header';
            var label = document.createElement('span');
            label.className = 'ai-usage-budget__label';
            label.textContent = t('ai.usage_budget');
            var value = document.createElement('span');
            value.className = 'ai-usage-budget__value';
            value.textContent = t('ai.usage_budget_value', {
                spent: formatAiCents(spent),
                budget: formatAiCents(budgetCents),
                percent: Math.min(999, Math.round(ratio * 100))
            });
            header.appendChild(label);
            header.appendChild(value);
            var bar = document.createElement('div');
            bar.className = 'ai-usage-budget__bar';
            var fill = document.createElement('div');
            fill.className = 'ai-usage-budget__fill'
                + (ratio >= 1 ? ' ai-usage-budget__fill--over' : (ratio >= 0.8 ? ' ai-usage-budget__fill--warn' : ''));
            fill.style.width = Math.min(100, Math.max(ratio > 0 ? 2 : 0, Math.round(ratio * 100))) + '%';
            bar.appendChild(fill);
            budget.appendChild(header);
            budget.appendChild(bar);
            body.appendChild(budget);
        }
    }

    function switchAiToolTab(tool) {
        var requestedTool = ['image', 'text', 'audit'].indexOf(tool) !== -1 ? tool : 'text';
        var availableTabs = Array.from(document.querySelectorAll('.ai-tool-tab')).filter(function(tab) {
            return !tab.hidden;
        });
        if (!availableTabs.length) return;
        var activeTool = availableTabs.some(function(tab) {
            return tab.dataset.aiToolTab === requestedTool;
        }) ? requestedTool : availableTabs[0].dataset.aiToolTab;
        document.querySelectorAll('.ai-tool-tab').forEach(function(tab) {
            var isActive = tab.dataset.aiToolTab === activeTool;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        document.querySelectorAll('.ai-tool-panel').forEach(function(panel) {
            panel.hidden = panel.dataset.aiToolPanel !== activeTool;
            panel.classList.toggle('active', panel.dataset.aiToolPanel === activeTool);
        });
        // Opening the image tool under OpenRouter loads live image prices so
        // the estimated per-image cost can be shown.
        if (activeTool === 'image' && aiSavedProviderKey() === 'openrouter') {
            loadOpenRouterModels();
        }
    }

    function openAiImageGenerator(promptText, aspectRatio) {
        if (!dashboardAiImageUsable) {
            showToast(t('ai.image_generator_disabled'), 'warning');
            return;
        }
        switchTab('home');
        switchAiToolTab('image');
        var prompt = document.getElementById('aiImagePrompt');
        if (prompt && promptText) {
            prompt.value = promptText;
        }
        if (aspectRatio) {
            setAiImageRatio(aspectRatio);
        }
        if (prompt) {
            prompt.focus();
        }
    }

    function setAiImageRatio(aspectRatio) {
        var ratioInput = document.getElementById('aiImageRatio');
        if (ratioInput) ratioInput.value = aspectRatio || 'auto';
        if (typeof updateAiImageRatioIcon === 'function') {
            updateAiImageRatioIcon();
        }
    }

    function getNewsImagePrompt() {
        var title = document.getElementById('newsTitle')?.value.trim() || '';
        var excerpt = document.getElementById('newsExcerpt')?.value.trim() || '';
        var content = document.getElementById('newsContentWysiwyg')?.innerText.trim() || '';
        var language = document.getElementById('newsLang')?.value.trim() || '';
        var parts = [title, excerpt, content].filter(Boolean).join('\n\n').slice(0, 900);
        return [
            'Create a 16:9 editorial cover image for a website news post.',
            title ? 'Article title: ' + title : '',
            language ? 'Content language: ' + language : '',
            parts ? 'Context:\n' + parts : '',
            'Use the article context to choose a fitting subject, mood, and setting.',
            'No text, no logo, no UI, no watermark. Natural composition with a clear subject and enough negative space for cropping.'
        ].filter(Boolean).join('\n\n');
    }

    function getSeoOgImagePrompt() {
        var title = document.getElementById('seoOgTitle')?.value.trim()
            || document.getElementById('seoTitle')?.value.trim()
            || currentContent?.title
            || currentPage
            || '';
        var description = document.getElementById('seoOgDescription')?.value.trim()
            || document.getElementById('seoDescription')?.value.trim()
            || currentContent?.description
            || '';
        var pageText = extractContentText(currentContent || {}).slice(0, 700);
        return [
            'Create a 16:9 landscape Open Graph/social sharing image for this page. It should work well when cropped to a 1200x630 preview.',
            title ? 'Page title: ' + title : '',
            description ? 'Description: ' + description : '',
            pageText ? 'Page context: ' + pageText : '',
            'No embedded text, no logos unless explicitly part of the brand, no UI mockup. Clean social-sharing composition with a strong visual focal point.'
        ].filter(Boolean).join('\n\n');
    }

    function dashboardAnalyticsRangeLabel(period, count) {
        if (period === 'months') return t('dashboard_home.range_label_months', {count: count || 12});
        if (period === 'years') return t('dashboard_home.range_label_years');
        return t('dashboard_home.range_label_days', {count: count || 30});
    }

    function updateDashboardAnalyticsRangeUi(period, count) {
        var label = dashboardAnalyticsRangeLabel(period, count);
        var labelMap = {
            dashboardViewsPeriodLabel: t('dashboard_home.views_period', {period: label}),
            dashboardVisitorsPeriodLabel: t('dashboard_home.visitors_period', {period: label}),
            dashboardVisitsPeriodLabel: t('dashboard_home.visits_period', {period: label}),
            dashboardChartRangeLabel: t('dashboard_home.views_period', {period: label})
        };
        Object.keys(labelMap).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.textContent = labelMap[id];
        });
        document.querySelectorAll('.dashboard-range-tab').forEach(function(tab) {
            var active = tab.dataset.analyticsPeriod === period && parseInt(tab.dataset.analyticsCount || '0', 10) === count;
            tab.classList.toggle('active', active);
        });
    }

    function setDashboardAnalyticsRange(period, count) {
        currentDashboardAnalyticsRange = {
            period: ['days', 'months', 'years'].includes(period) ? period : 'days',
            count: parseInt(count || '0', 10)
        };
        updateDashboardAnalyticsRangeUi(currentDashboardAnalyticsRange.period, currentDashboardAnalyticsRange.count);
        loadDashboardOverview();
    }

    function formatDashboardDate(value) {
        if (!value) return '—';
        var date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
        if (isNaN(date.getTime())) return '—';
        return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    async function openDashboardRecentEdit(item) {
        if (!item) return;
        if (item.type === 'news') {
            switchTab('news');
            if (!newsLoaded) {
                newsLoaded = true;
                await loadNews();
            }
            editPost(item.id);
            return;
        }
        if (item.type === 'page' && item.id) {
            window.location.href = '#page/' + item.id;
            applyDashboardRoute(true);
        }
    }

    function renderDashboardStatus(status) {
        var target = document.getElementById('dashboardStatusStrip');
        if (!target || !status) return;

        var recent = (status.recentEdits || [])[0] || null;
        window.dashboardRecentStatusItems = status.recentEdits || [];
        var backup = status.backup || {};
        var users = status.users || {};
        var currentUser = users.current || {};
        var chips = [];

        if (isDashboardModuleEnabled('mails')) {
            var unreadCount = status.messages?.unread || 0;
            chips.push('<button type="button" class="dashboard-status-chip' + (unreadCount > 0 ? ' dashboard-status-chip--accent' : '') + '" onclick="switchTab(&quot;mails&quot;)"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.unread_messages')) + '</span><strong class="dashboard-status-chip__value">' + unreadCount + '</strong></button>');
        }

        var backupText = backup.newest ? formatDashboardDate(backup.newest) : t('dashboard_home.no_backup');
        if (VALID_DASHBOARD_TABS.indexOf('backup') !== -1) {
            chips.push('<button type="button" class="dashboard-status-chip" onclick="switchTab(&quot;backup&quot;)"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.backup_status')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(backupText) + '</strong></button>');

            chips.push('<button type="button" class="dashboard-status-chip" onclick="switchTab(&quot;backup&quot;)"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.auto_backup')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(backup.enabled ? t('dashboard_home.active') : t('dashboard_home.inactive')) + '</strong></button>');
        }

        if (recent) {
            var recentTitle = recent.title || recent.id || '';
            chips.push('<button type="button" class="dashboard-status-chip dashboard-status-chip--wide" title="' + escapeHtml(recentTitle) + '" onclick="openDashboardRecentEdit(window.dashboardRecentStatusItems[0])"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.recent_edit')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(recentTitle) + '</strong></button>');
        }

        if (currentUser.username) {
            chips.push('<button type="button" class="dashboard-status-chip" onclick="switchTab(&quot;settings&quot;, {settingsTab: &quot;users&quot;});"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.current_user')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(currentUser.username) + '</strong></button>');
        }

        target.innerHTML = chips.join('');
    }

    async function loadDashboardOverview() {
        try {
            updateDashboardAnalyticsRangeUi(currentDashboardAnalyticsRange.period, currentDashboardAnalyticsRange.count);
            var params = new URLSearchParams({
                action: 'dashboard-overview',
                analytics_period: currentDashboardAnalyticsRange.period,
                analytics_count: String(currentDashboardAnalyticsRange.count),
                _: String(Date.now())
            });
            var response = await fetch('api.php?' + params.toString());
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Could not load dashboard overview');
            var data = result.data || {};
            var analytics = data.analytics || {};

            var viewsToday = document.getElementById('dashboardViewsToday');
            var viewsPeriod = document.getElementById('dashboardViewsPeriod');
            var visitorsPeriod = document.getElementById('dashboardVisitorsPeriod');
            var visitsPeriod = document.getElementById('dashboardVisitsPeriod');
            var botCount = document.getElementById('dashboardBotCount');
            var pageCount = document.getElementById('dashboardPageCount');
            var newsCount = document.getElementById('dashboardNewsCount');
            if (viewsToday) viewsToday.textContent = analytics.todayViews || 0;
            if (viewsPeriod) viewsPeriod.textContent = analytics.periodViews || 0;
            if (visitorsPeriod) visitorsPeriod.textContent = analytics.periodVisitors || 0;
            if (visitsPeriod) visitsPeriod.textContent = analytics.periodVisits || 0;
            if (botCount) botCount.textContent = analytics.botRequests || 0;
            if (pageCount) pageCount.textContent = data.pages || 0;
            if (newsCount) newsCount.textContent = data.news || 0;
            renderDashboardStatus(data.status || {});

            renderDashboardTopPages(analytics.topPages || []);
            renderDashboardBreakdown('dashboardReferrers', analytics.referrers || []);
            renderDashboardBreakdown('dashboardDevices', analytics.devices || []);
            renderDashboardBreakdown('dashboardBrowsers', analytics.browsers || []);
            renderDashboardBreakdown('dashboardOs', analytics.os || []);
            renderDashboardTrafficChart(analytics.series || []);
            renderDashboardHourlyChart(analytics.hourlyToday || []);

            if (AI_FEATURES_ENABLED) {
                currentAiSettings = (data.ai && data.ai.settings) || {};
                populateAiSettings(currentAiSettings);
                updateAiUsage(data.ai ? data.ai.usage : null);
                updateDashboardAiPanel(currentAiSettings);
            } else {
                updateDashboardAiPanel({});
            }
        } catch (error) {
            var target = document.getElementById('dashboardTopPages');
            if (target) target.textContent = error.message;
        }
    }

    function renderDashboardTopPages(pages) {
        var target = document.getElementById('dashboardTopPages');
        if (!target) return;
        if (!pages.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_views') + '</p>';
            return;
        }
        target.innerHTML = pages.map(function(page) {
            var visitors = page.visitors || 0;
            var visits = page.visits || 0;
            return '<div class="dashboard-top-page">' +
                '<span><strong>' + escapeHtml(page.title || page.key || '') + '</strong><small>' + t('dashboard_home.views_detail', { views: page.views || 0, visitors: visitors, visits: visits }) + '</small></span>' +
                '<strong>' + (page.views || 0) + '</strong>' +
                '</div>';
        }).join('');
    }

    function renderDashboardTrafficChart(series) {
        var target = document.getElementById('dashboardTrafficChart');
        if (!target) return;
        if (!series.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_views') + '</p>';
            return;
        }

        var width = 720;
        var height = 220;
        var pad = { top: 16, right: 18, bottom: 34, left: 42 };
        var chartWidth = width - pad.left - pad.right;
        var chartHeight = height - pad.top - pad.bottom;
        var values = series.map(function(item) { return Math.max(0, parseInt(item.views || 0, 10)); });
        var maxValue = Math.max.apply(null, values.concat([1]));
        // Four integer intervals keep low traffic from repeating rounded ticks.
        var yMax = Math.max(4, Math.ceil(maxValue * 1.2 / 4) * 4);
        var stepX = series.length > 1 ? chartWidth / (series.length - 1) : chartWidth;

        function x(index) {
            return pad.left + index * stepX;
        }
        function y(value) {
            return pad.top + chartHeight - (value / yMax) * chartHeight;
        }
        function point(index, value) {
            return x(index).toFixed(2) + ',' + y(value).toFixed(2);
        }

        var line = values.map(function(value, index) {
            return (index === 0 ? 'M ' : 'L ') + point(index, value);
        }).join(' ');
        var area = line + ' L ' + x(values.length - 1).toFixed(2) + ',' + (pad.top + chartHeight).toFixed(2) +
            ' L ' + pad.left + ',' + (pad.top + chartHeight).toFixed(2) + ' Z';
        var grid = [0, 0.25, 0.5, 0.75, 1].map(function(factor) {
            var yy = pad.top + chartHeight - chartHeight * factor;
            var label = Math.round(yMax * factor);
            return '<g><line x1="' + pad.left + '" y1="' + yy.toFixed(2) + '" x2="' + (width - pad.right) + '" y2="' + yy.toFixed(2) + '"></line>' +
                '<text x="' + (pad.left - 10) + '" y="' + (yy + 4).toFixed(2) + '">' + label + '</text></g>';
        }).join('');
        var labelIndexes = [0, Math.floor((series.length - 1) / 2), series.length - 1].filter(function(value, index, arr) {
            return arr.indexOf(value) === index;
        });
        var xLabels = labelIndexes.map(function(index) {
            var label = series[index].label || '';
            if (!label) {
                var date = new Date((series[index].date || '') + 'T00:00:00');
                label = isNaN(date.getTime()) ? (series[index].date || '') : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            }
            return '<text x="' + x(index).toFixed(2) + '" y="' + (height - 8) + '" text-anchor="middle">' + escapeHtml(label) + '</text>';
        }).join('');
        var points = values.map(function(value, index) {
            var date = new Date((series[index].date || '') + 'T00:00:00');
            var label = series[index].label || (isNaN(date.getTime()) ? (series[index].date || '') : date.toLocaleDateString());
            return '<circle cx="' + x(index).toFixed(2) + '" cy="' + y(value).toFixed(2) + '" r="3"><title>' +
                escapeHtml(label + ': ' + value + ' ' + t('dashboard_home.views_label')) + '</title></circle>';
        }).join('');

        target.innerHTML = '<svg class="dashboard-traffic-svg" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + escapeHtml(t('dashboard_home.traffic_curve')) + '">' +
            '<g class="dashboard-chart-grid">' + grid + '</g>' +
            '<path class="dashboard-chart-area" d="' + area + '"></path>' +
            '<path class="dashboard-chart-line" d="' + line + '"></path>' +
            '<g class="dashboard-chart-points">' + points + '</g>' +
            '<g class="dashboard-chart-xlabels">' + xLabels + '</g>' +
            '</svg>';
    }

    function renderDashboardHourlyChart(hours) {
        var target = document.getElementById('dashboardHourlyChart');
        if (!target) return;
        if (!hours.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_views') + '</p>';
            return;
        }
        var maxValue = Math.max.apply(null, hours.map(function(item) { return Math.max(0, parseInt(item.views || 0, 10)); }).concat([1]));
        target.innerHTML = hours.map(function(item, index) {
            var views = Math.max(0, parseInt(item.views || 0, 10));
            var height = Math.max(3, Math.round((views / maxValue) * 100));
            var label = item.label || String(index).padStart(2, '0') + ':00';
            var showLabel = index % 3 === 0;
            return '<div class="dashboard-hour-bar" title="' + escapeHtml(label + ': ' + views + ' ' + t('dashboard_home.views_label')) + '">' +
                '<span class="dashboard-hour-bar__track"><span class="dashboard-hour-bar__fill" style="height:' + height + '%"></span></span>' +
                '<span class="dashboard-hour-bar__label">' + (showLabel ? escapeHtml(String(index).padStart(2, '0')) : '') + '</span>' +
                '</div>';
        }).join('');
    }

    function renderDashboardBreakdown(targetId, items) {
        var target = document.getElementById(targetId);
        if (!target) return;
        if (!items.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_data') + '</p>';
            return;
        }
        target.innerHTML = items.slice(0, 8).map(function(item) {
            return '<div class="dashboard-breakdown-row">' +
                '<span>' + escapeHtml(item.label || item.key || '') + '</span>' +
                '<strong>' + (item.views || item.count || 0) + '</strong>' +
                '</div>';
        }).join('');
    }

    function updateDashboardAiStatus(settings) {
        var target = document.getElementById('dashboardAiStatus');
        if (!target) return;
        if (!AI_FEATURES_ENABLED) {
            target.textContent = t('ai.module_disabled_status');
            return;
        }
        var configured = aiProviderIsConfigured(settings);
        if (!settings.enabled) {
            target.textContent = t('ai.disabled_status');
        } else if (!configured) {
            target.textContent = t('ai.not_configured_text');
        } else {
            target.textContent = t('ai.configured_status');
        }
    }

    function aiFeatureEnabled(settings, feature) {
        settings = settings || currentAiSettings || {};
        var features = settings.features || {};
        if (Object.prototype.hasOwnProperty.call(features, feature)) {
            return !!features[feature];
        }
        return !!AI_FEATURE_DEFAULTS[feature];
    }

    function aiServiceIsUsable(settings) {
        settings = settings || currentAiSettings || {};
        return AI_FEATURES_ENABLED && !!settings.enabled && aiProviderIsConfigured(settings);
    }

    function aiUnavailableNoticeDismissed() {
        try {
            return localStorage.getItem(AI_NOTICE_DISMISSED_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function dismissAiUnavailableNotice() {
        try {
            localStorage.setItem(AI_NOTICE_DISMISSED_KEY, '1');
        } catch (e) {}
        var section = document.getElementById('dashboardAiSection');
        var usable = aiServiceIsUsable(currentAiSettings || {});
        if (section && !usable) section.hidden = true;
    }

    function updateDashboardAiPanel(settings) {
        settings = settings || currentAiSettings || {};
        var section = document.getElementById('dashboardAiSection');
        if (!section) return;

        var configured = AI_FEATURES_ENABLED && aiProviderIsConfigured(settings);
        var serviceEnabled = AI_FEATURES_ENABLED && !!settings.enabled;
        var usable = configured && serviceEnabled;
        var unavailableDismissed = aiUnavailableNoticeDismissed();
        var banner = document.getElementById('aiUnavailableBanner');
        var bannerText = document.getElementById('aiUnavailableText');
        var tools = document.getElementById('dashboardAiTools');
        var usage = document.getElementById('aiUsageSummary');
        var assistantEnabled = usable && aiFeatureEnabled(settings, 'backendAssistant');
        var textEnabled = usable && aiFeatureEnabled(settings, 'seoTextGeneration');
        var imageEnabled = usable && aiFeatureEnabled(settings, 'imageGeneration');
        var imageJobsPanel = document.getElementById('aiImageJobsPanel');

        dashboardAiImageUsable = imageEnabled;
        if (window.NbImageManager && typeof NbImageManager.refresh === 'function') {
            NbImageManager.refresh();
        }
        section.hidden = !AI_FEATURES_ENABLED || (!usable && unavailableDismissed);
        updateDashboardAiStatus(settings);

        if (usage && !usable) usage.hidden = true;

        if (banner) {
            banner.hidden = usable;
        }
        if (bannerText) {
            if (!AI_FEATURES_ENABLED) {
                bannerText.textContent = t('ai.module_disabled_status');
            } else if (!serviceEnabled) {
                bannerText.textContent = t('ai.disabled_status');
            } else {
                bannerText.textContent = t('ai.not_configured_text');
            }
        }

        if (tools) tools.hidden = !usable || (!assistantEnabled && !textEnabled && !imageEnabled);
        if (imageJobsPanel) imageJobsPanel.hidden = !imageEnabled;

        var assistantCard = document.getElementById('aiAssistantCard');
        if (assistantCard) assistantCard.hidden = !assistantEnabled;

        var toolsCard = document.getElementById('aiToolsCard');
        if (toolsCard) toolsCard.hidden = !textEnabled && !imageEnabled;

        document.querySelectorAll('.ai-tool-tab, .ai-tool-panel').forEach(function(el) {
            var feature = el.dataset.aiFeature;
            var visible = feature === 'imageGeneration' ? imageEnabled : (feature === 'seoTextGeneration' ? textEnabled : true);
            el.hidden = !visible;
            if (!visible) {
                el.classList.remove('active');
                if (el.classList.contains('ai-tool-tab')) el.setAttribute('aria-selected', 'false');
            }
        });

        if (textEnabled || imageEnabled) {
            var currentActive = document.querySelector('.ai-tool-tab.active:not([hidden])');
            switchAiToolTab(currentActive ? currentActive.dataset.aiToolTab : (imageEnabled ? 'image' : 'text'));
        }
    }

    document.getElementById('aiUnavailableDismiss')?.addEventListener('click', dismissAiUnavailableNotice);

    function aiIsLocalProviderUrl(baseUrl) {
        try {
            var host = new URL(baseUrl || '').hostname.toLowerCase();
            return host === 'localhost' || host === '127.0.0.1' || host === '::1' || host.startsWith('192.168.') || host.startsWith('10.') || /^172\\.(1[6-9]|2\\d|3[0-1])\\./.test(host);
        } catch (e) {
            return false;
        }
    }

    function aiProviderIsConfigured(settings) {
        settings = settings || currentAiSettings || {};
        var provider = document.getElementById('aiProvider')?.value || settings.provider || 'openai-compatible';
        var credentials = aiProviderCredentials(settings, provider);
        var activeProvider = settings.provider || 'openai-compatible';
        var hasKey = !!document.getElementById('aiApiKey')?.value.trim()
            || (provider === activeProvider ? !!settings.hasApiKey : false)
            || !!credentials.hasApiKey;
        var baseUrl = document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '';
        var allowLocal = !!settings.allowLocalProvider || !!document.getElementById('aiAllowLocalProvider')?.checked;
        return hasKey || (allowLocal && aiIsLocalProviderUrl(baseUrl));
    }

    function aiProviderCredentials(settings, provider) {
        settings = settings || currentAiSettings || {};
        provider = provider || settings.provider || document.getElementById('aiProvider')?.value || 'openai-compatible';
        var defaults = {
            'openai-compatible': { baseUrl: 'https://api.openai.com/v1', organization: '', hasApiKey: false },
            openrouter: { baseUrl: 'https://openrouter.ai/api/v1', organization: '', hasApiKey: false },
            anthropic: { baseUrl: 'https://api.anthropic.com/v1', organization: '', hasApiKey: false },
            kie: { baseUrl: 'https://api.kie.ai', organization: '', hasApiKey: false }
        };
        var credentials = settings.providerCredentials && settings.providerCredentials[provider]
            ? settings.providerCredentials[provider]
            : {};
        return Object.assign({}, defaults[provider] || defaults['openai-compatible'], credentials);
    }

    function aiUsesOpenRouter(settings) {
        settings = settings || currentAiSettings || {};
        var provider = String(document.getElementById('aiProvider')?.value || settings.provider || '').trim();
        var credentials = aiProviderCredentials(settings, provider);
        var baseUrl = String(document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '').trim();
        return provider === 'openrouter' || baseUrl.indexOf('openrouter.ai') !== -1;
    }

    function aiUsesAnthropic(settings) {
        settings = settings || currentAiSettings || {};
        var provider = String(document.getElementById('aiProvider')?.value || settings.provider || '').trim();
        var credentials = aiProviderCredentials(settings, provider);
        var baseUrl = String(document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '').trim();
        return provider === 'anthropic' || baseUrl.indexOf('api.anthropic.com') !== -1;
    }

    function aiUsesKie(settings) {
        settings = settings || currentAiSettings || {};
        var provider = String(document.getElementById('aiProvider')?.value || settings.provider || '').trim();
        var credentials = aiProviderCredentials(settings, provider);
        var baseUrl = String(document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '').trim();
        return provider === 'kie' || baseUrl.indexOf('api.kie.ai') !== -1;
    }

    var AI_TEXT_MODEL_PRESETS = {
        'openai-compatible': {
            chat: 'gpt-4.1-mini',
            text: 'gpt-4.1-mini',
            suggestions: ['gpt-4.1-mini', 'gpt-4.1']
        },
        openrouter: {
            chat: 'openai/gpt-4.1-mini',
            text: 'openai/gpt-4.1-mini',
            suggestions: ['openai/gpt-4.1-mini', 'openai/gpt-4.1', 'anthropic/claude-sonnet-4-6', 'anthropic/claude-haiku-4-5', 'google/gemini-3.1-flash']
        },
        anthropic: {
            chat: 'claude-sonnet-4-6',
            text: 'claude-haiku-4-5',
            suggestions: ['claude-sonnet-4-6', 'claude-haiku-4-5', 'claude-opus-4-8']
        },
        kie: {
            chat: 'gpt-5-6-luna',
            text: 'gpt-5-6-luna',
            suggestions: ['gpt-5-6-luna', 'gpt-5-6-terra', 'gpt-5-6-sol', 'claude-sonnet-5', 'gemini-3-5-flash']
        }
    };

    var aiOpenRouterModelsCache = null;
    var aiOpenRouterModelsLoading = false;

    // Load the live OpenRouter catalog through the server cache; the static
    // preset list stays as fallback when the request fails or is pending.
    function loadOpenRouterModels() {
        if (aiOpenRouterModelsCache || aiOpenRouterModelsLoading) return;
        aiOpenRouterModelsLoading = true;
        var formData = new FormData();
        formData.append('action', 'ai-openrouter-models');
        formData.append('csrf_token', CSRF_TOKEN);
        fetch('api.php', { method: 'POST', body: formData, cache: 'no-store' })
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (!result.success || !result.data) return;
                aiOpenRouterModelsCache = result.data;
                applyOpenRouterModels();
            })
            .catch(function() { /* keep static fallback */ })
            .finally(function() { aiOpenRouterModelsLoading = false; });
    }

    function applyOpenRouterModels() {
        var data = aiOpenRouterModelsCache;
        if (!data) return;
        if (Array.isArray(data.textModels) && data.textModels.length) {
            AI_TEXT_MODEL_PRESETS.openrouter.suggestions = data.textModels.map(function(model) { return model.id; });
        }
        if (Array.isArray(data.imageModels) && data.imageModels.length) {
            AI_IMAGE_MODEL_OPTIONS.openrouter = data.imageModels.map(function(model) {
                return { value: model.id, label: model.name || model.id };
            });
        }
        var provider = document.getElementById('aiProvider')?.value || (currentAiSettings && currentAiSettings.provider) || '';
        if (provider === 'openrouter') {
            updateAiModelPlaceholders('openrouter');
            updateAiImageModelControl(currentAiSettings || {});
        }
        // Refresh the size/cost note now that live image prices are available.
        if (typeof updateAiImageRatioIcon === 'function') updateAiImageRatioIcon();
    }

    function maybeAutofillOpenRouterPricing() {
        var provider = document.getElementById('aiProvider')?.value;
        if (provider !== 'openrouter' || !aiOpenRouterModelsCache) return;
        var model = String(document.getElementById('aiChatModel')?.value || '').trim();
        var match = (aiOpenRouterModelsCache.textModels || []).find(function(entry) { return entry.id === model; });
        if (!match || (!match.promptCentsPerMillion && !match.completionCentsPerMillion)) return;
        var inputPrice = document.getElementById('aiInputPrice');
        var outputPrice = document.getElementById('aiOutputPrice');
        if (inputPrice && match.promptCentsPerMillion > 0) inputPrice.value = match.promptCentsPerMillion;
        if (outputPrice && match.completionCentsPerMillion > 0) outputPrice.value = match.completionCentsPerMillion;
        if (typeof showToast === 'function') showToast(t('ai.pricing_autofilled'), 'info');
    }

    function aiModelIsPresetDefault(value) {
        value = String(value || '').trim();
        if (!value) return true;
        return Object.keys(AI_TEXT_MODEL_PRESETS).some(function(provider) {
            var preset = AI_TEXT_MODEL_PRESETS[provider];
            return preset.chat === value || preset.text === value;
        });
    }

    // Keep the model inputs aligned with the selected provider: adjust
    // placeholders and suggestions always, and swap the values only when they
    // still hold another provider's default (never a custom model).
    function updateAiModelPlaceholders(provider) {
        var preset = AI_TEXT_MODEL_PRESETS[provider] || AI_TEXT_MODEL_PRESETS['openai-compatible'];
        var chatInput = document.getElementById('aiChatModel');
        var textInput = document.getElementById('aiTextModel');
        if (chatInput) {
            chatInput.placeholder = preset.chat;
            if (aiModelIsPresetDefault(chatInput.value)) chatInput.value = preset.chat;
            updateClearButton(chatInput);
        }
        if (textInput) {
            textInput.placeholder = preset.text;
            if (aiModelIsPresetDefault(textInput.value)) textInput.value = preset.text;
            updateClearButton(textInput);
        }
        aiModelComboboxes.forEach(function(combobox) { combobox.refresh(); });
        if (provider === 'openrouter') {
            loadOpenRouterModels();
        }
    }

    function aiModelSuggestions() {
        var provider = document.getElementById('aiProvider')?.value
            || (currentAiSettings && currentAiSettings.provider) || 'openai-compatible';
        var preset = AI_TEXT_MODEL_PRESETS[provider] || AI_TEXT_MODEL_PRESETS['openai-compatible'];
        return preset.suggestions.slice();
    }

    // Custom combobox: identical rendering in every browser (incl. Safari and
    // embedded webviews) instead of the unstylable native datalist popup.
    var aiModelComboboxes = [];
    function setupModelCombobox(root) {
        var input = root.querySelector('input');
        var toggle = root.querySelector('.nb-combobox__toggle');
        var list = root.querySelector('.nb-combobox__list');
        if (!input || !list) return null;
        var activeIndex = -1;
        var items = [];

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function select(value) {
            input.value = value;
            close();
            updateClearButton(input);
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        }

        function highlight(index) {
            activeIndex = index;
            Array.from(list.children).forEach(function(item, i) {
                item.classList.toggle('is-active', i === index);
                item.setAttribute('aria-selected', i === index ? 'true' : 'false');
                if (i === index) item.scrollIntoView({ block: 'nearest' });
            });
        }

        function open(filterText) {
            var all = aiModelSuggestions();
            var filter = String(filterText || '').trim().toLowerCase();
            items = filter && all.indexOf(input.value.trim()) === -1
                ? all.filter(function(model) { return model.toLowerCase().indexOf(filter) !== -1; })
                : all;
            if (!items.length) { close(); return; }
            list.innerHTML = '';
            items.forEach(function(model, index) {
                var item = document.createElement('div');
                item.className = 'nb-combobox__option';
                item.setAttribute('role', 'option');
                item.textContent = model;
                item.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    select(model);
                });
                list.appendChild(item);
            });
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            highlight(-1);
        }

        input.addEventListener('input', function() {
            updateClearButton(input);
            open(input.value);
        });
        input.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (list.hidden) { open(input.value); return; }
                var count = list.children.length;
                if (!count) return;
                var next = event.key === 'ArrowDown'
                    ? (activeIndex + 1) % count
                    : (activeIndex - 1 + count) % count;
                highlight(next);
            } else if (event.key === 'Enter') {
                if (!list.hidden && activeIndex >= 0 && items[activeIndex] !== undefined) {
                    event.preventDefault();
                    select(items[activeIndex]);
                }
            } else if (event.key === 'Escape' && !list.hidden) {
                event.stopPropagation();
                close();
            }
        });
        input.addEventListener('blur', function() {
            window.setTimeout(close, 120);
        });
        if (toggle) {
            toggle.addEventListener('mousedown', function(event) {
                event.preventDefault();
                if (list.hidden) {
                    input.focus();
                    open('');
                } else {
                    close();
                }
            });
        }

        return { refresh: function() { if (!list.hidden) open(input.value); } };
    }

    document.querySelectorAll('[data-model-combobox]').forEach(function(root) {
        var combobox = setupModelCombobox(root);
        if (combobox) aiModelComboboxes.push(combobox);
    });

    document.getElementById('aiChatModel')?.addEventListener('change', maybeAutofillOpenRouterPricing);

    var AI_IMAGE_MODEL_OPTIONS = {
        'openai-compatible': [
            { value: 'gpt-image-2', label: t('ai.openai_image_gpt') }
        ],
        openrouter: [
            { value: 'openai/gpt-5.4-image-2', label: t('ai.openrouter_image_gpt') },
            { value: 'google/gemini-3.1-flash-image-preview', label: t('ai.openrouter_image_gemini_flash') },
            { value: 'google/gemini-3-pro-image-preview', label: t('ai.openrouter_image_gemini_pro') }
        ],
        kie: [
            { value: 'gpt-image-2', label: 'Kie.ai: GPT Image 2' },
            { value: 'nano-banana-2', label: 'Kie.ai: Nano Banana 2' },
            { value: 'seedream-5-0-pro', label: 'Kie.ai: Seedream 5.0 Pro' }
        ]
    };

    function normalizeOpenRouterImageModel(model) {
        model = String(model || '').trim();
        var aliases = {
            'gpt-image-2': 'openai/gpt-5.4-image-2',
            'gpt-5.4': 'openai/gpt-5.4-image-2',
            'openai/gpt-5.4': 'openai/gpt-5.4-image-2',
            'gpt-5.4-2026-03-05': 'openai/gpt-5.4-image-2',
            'openai/gpt-5.4-2026-03-05': 'openai/gpt-5.4-image-2',
            'gemini-3.1-flash-image-preview': 'google/gemini-3.1-flash-image-preview',
            'gemini-3-pro-image-preview': 'google/gemini-3-pro-image-preview'
        };
        var valid = AI_IMAGE_MODEL_OPTIONS.openrouter.map(function(option) {
            return option.value;
        });
        if (valid.indexOf(model) !== -1) {
            return model;
        }
        return aliases[model] || 'openai/gpt-5.4-image-2';
    }

    function normalizeOpenAiImageModel(model) {
        model = String(model || '').trim();
        return model === 'gpt-image-2' ? model : 'gpt-image-2';
    }

    function normalizeKieImageModel(model) {
        model = String(model || '').trim();
        var aliases = {
            'gpt-image-2-text-to-image': 'gpt-image-2',
            'gpt-image-2-image-to-image': 'gpt-image-2',
            'seedream/5-pro-text-to-image': 'seedream-5-0-pro',
            'seedream/5-pro-image-to-image': 'seedream-5-0-pro'
        };
        model = aliases[model] || model;
        return AI_IMAGE_MODEL_OPTIONS.kie.some(function(option) { return option.value === model; })
            ? model : 'gpt-image-2';
    }

    function currentAiProviderKey(settings) {
        settings = settings || currentAiSettings || {};
        var provider = document.getElementById('aiProvider')?.value || settings.provider || 'openai-compatible';
        if (provider === 'kie' || aiUsesKie(settings)) return 'kie';
        return provider === 'openrouter' || aiUsesOpenRouter(settings) ? 'openrouter' : 'openai-compatible';
    }

    function updateAiImageModelControl(settings) {
        settings = settings || currentAiSettings || {};
        var providerKey = currentAiProviderKey(settings);
        var input = document.getElementById('aiImageModel');
        var picker = document.getElementById('aiImageModelPicker');
        if (!input) return;
        var options = AI_IMAGE_MODEL_OPTIONS[providerKey] || AI_IMAGE_MODEL_OPTIONS['openai-compatible'];
        var selected = providerKey === 'openrouter'
            ? normalizeOpenRouterImageModel(settings.imageModel || input.value || '')
            : (providerKey === 'kie'
                ? normalizeKieImageModel(settings.imageModel || input.value || '')
                : normalizeOpenAiImageModel(settings.imageModel || input.value || ''));
        if (picker) {
            picker.innerHTML = options.map(function(option) {
                return '<option value="' + escapeHtml(option.value) + '">' + escapeHtml(option.label) + '</option>';
            }).join('');
            if (!options.some(function(option) { return option.value === selected; })) {
                selected = options[0] ? options[0].value : '';
            }
            picker.value = selected;
        }
        input.value = selected;
        updateAiImageScaleOptions();
    }

    function aiSelectedImageModelSupports4K() {
        var model = String(document.getElementById('aiImageModelPicker')?.value || document.getElementById('aiImageModel')?.value || '').trim();
        return !/(^|\/)gpt-5\.4-image-2(?:$|-)|^gpt-image-2(?:$|-)/i.test(model);
    }

    function updateAiImageScaleOptions() {
        var scale = document.getElementById('aiImageScale');
        if (!scale) return;
        var current = parseInt(scale.value || '2048', 10) || 2048;
        var supports4K = aiSelectedImageModelSupports4K();
        var options = [
            { value: '1024', label: '1K' },
            { value: '2048', label: '2K' }
        ];
        if (supports4K) {
            options.push({ value: '3072', label: '3K' }, { value: '3840', label: '4K' });
        }
        scale.innerHTML = options.map(function(option) {
            return '<option value="' + option.value + '">' + option.label + '</option>';
        }).join('');
        var maxScale = supports4K ? 3840 : 2048;
        scale.value = String(Math.min(current, maxScale));
        if (!scale.value) {
            scale.value = supports4K && current > 2048 ? '3840' : '2048';
        }
        if (typeof updateAiImageRatioIcon === 'function') updateAiImageRatioIcon();
    }

    function updateAiAvailability(settings) {
        settings = settings || currentAiSettings || {};
        updateDashboardAiPanel(settings);
        if (!AI_FEATURES_ENABLED) return;
        updateAiImageModelControl(settings);
        // Refresh the size note so the estimated per-image cost reflects the
        // current pricing once settings have loaded.
        if (typeof updateAiImageRatioIcon === 'function') updateAiImageRatioIcon();
        var configured = aiProviderIsConfigured(settings);
        var enabled = !!settings.enabled;
        var usable = configured && enabled;
        var assistantUsable = usable && aiFeatureEnabled(settings, 'backendAssistant');
        var textUsable = usable && aiFeatureEnabled(settings, 'seoTextGeneration');
        var imageUsable = usable && aiFeatureEnabled(settings, 'imageGeneration') && !aiUsesAnthropic(settings);
        dashboardAiImageUsable = imageUsable;
        var status = document.getElementById('aiProviderStatus');
        if (status) {
            status.className = 'ai-status-box ' + (usable ? 'ai-status-box--ok' : 'ai-status-box--warning');
            status.textContent = usable
                ? t('ai.configured_status')
                : (configured ? t('ai.disabled_status') : t('ai.not_configured_text'));
        }

        var banner = document.getElementById('aiUnavailableBanner');
        if (banner) banner.hidden = usable;

        document.querySelectorAll('#aiChatForm textarea, #aiChatForm button').forEach(function(el) {
            el.disabled = !assistantUsable;
        });
        document.querySelectorAll('#aiTextForm textarea, #aiTextForm input, #aiTextForm button').forEach(function(el) {
            el.disabled = !textUsable;
        });
        document.querySelectorAll('#aiImageForm textarea, #aiImageForm select, #aiImageForm input, #aiImageForm button').forEach(function(el) {
            el.disabled = !imageUsable;
        });

        var imageModel = String(document.getElementById('aiImageModelPicker')?.value || settings.imageModel || document.getElementById('aiImageModel')?.value || '').trim();
        var imageModelMissing = imageUsable && !imageModel;
        var imageModelNote = document.getElementById('aiImageModelNote');
        var imageButton = document.getElementById('aiGenerateImageButton');
        if (imageButton) {
            imageButton.disabled = !imageUsable || imageModelMissing;
        }
        if (imageModelNote) {
            imageModelNote.textContent = aiUsesAnthropic(settings)
                ? t('ai.image_anthropic_note')
                : (aiUsesOpenRouter(settings) ? t('ai.image_openrouter_note') : t('ai.image_model_missing_note'));
            imageModelNote.hidden = !imageUsable || (!imageModelMissing && !aiUsesOpenRouter(settings));
        }

        document.querySelectorAll('#aiFeatureAssistant, #aiFeatureSeo, #aiFeatureImages').forEach(function(el) {
            el.disabled = !configured;
        });
        refreshSeoAiButtons();
    }

    async function loadAiSettings() {
        if (!AI_FEATURES_ENABLED) return;
        try {
            var response = await fetch('api.php?action=load-ai-settings');
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Could not load AI settings');
            currentAiSettings = result.data.settings || {};
            populateAiSettings(currentAiSettings);
            updateAiUsage(result.data.usage);
            updateDashboardAiPanel(currentAiSettings);
        } catch (error) {
            var usage = document.getElementById('aiUsageSummary');
            if (usage) usage.textContent = error.message;
        }
    }

    function populateAiSettings(settings) {
        settings = settings || {};
        var provider = settings.provider || 'openai-compatible';
        var providerCredentials = aiProviderCredentials(settings, provider);
        var fields = {
            aiEnabled: !!settings.enabled,
            aiAllowLocalProvider: !!settings.allowLocalProvider,
            aiAssistantForceEnglish: !!settings.assistantForceEnglish,
            aiAssistantSurfaceVisualEditor: !settings.assistantSurfaces || settings.assistantSurfaces.visualEditor !== false,
            aiAssistantSurfaceDashboard: !settings.assistantSurfaces || settings.assistantSurfaces.dashboard !== false,
            aiFeatureAssistant: !!(settings.features && settings.features.backendAssistant),
            aiFeatureSeo: !!(settings.features && settings.features.seoTextGeneration),
            aiFeatureImages: !!(settings.features && settings.features.imageGeneration)
        };
        Object.keys(fields).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.checked = fields[id];
        });
        var values = {
            aiProvider: provider,
            aiBaseUrl: Object.prototype.hasOwnProperty.call(providerCredentials, 'baseUrl') ? (providerCredentials.baseUrl || '') : (settings.baseUrl || ''),
            aiOrganization: Object.prototype.hasOwnProperty.call(providerCredentials, 'organization') ? (providerCredentials.organization || '') : (settings.organization || ''),
            aiChatModel: settings.chatModel || 'gpt-4.1-mini',
            aiTextModel: settings.textModel || settings.chatModel || 'gpt-4.1-mini',
            aiImageModel: Object.prototype.hasOwnProperty.call(settings, 'imageModel') ? (settings.imageModel || '') : 'gpt-image-2',
            aiMonthlyBudget: settings.limits?.monthlyBudgetCents ?? 1000,
            aiDailyRequests: settings.limits?.dailyRequests ?? 100,
            aiDailyTextRequests: settings.limits?.dailyTextRequests ?? 80,
            aiDailyImageRequests: settings.limits?.dailyImageRequests ?? 10,
            aiMaxInputTokens: settings.limits?.maxInputTokens ?? 24000,
            aiMaxOutputTokens: settings.limits?.maxOutputTokens ?? 4096,
            aiRequestTimeout: settings.limits?.requestTimeoutSeconds ?? 300,
            aiInputPrice: settings.pricing?.inputCentsPerMillion ?? 15,
            aiOutputPrice: settings.pricing?.outputCentsPerMillion ?? 60,
            aiImagePrice: settings.pricing?.imageCentsPerRequest ?? 5
        };
        Object.keys(values).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = values[id];
        });
        updateAiImageModelControl(settings);
        var keyInput = document.getElementById('aiApiKey');
        if (keyInput) keyInput.value = '';
        var clearKey = document.getElementById('aiClearApiKey');
        if (clearKey) clearKey.checked = false;
        updateAiApiKeyHint(provider);
        updateAiModelPlaceholders(provider);
        updateAiAvailability(settings);
    }

    function updateAiApiKeyHint(provider) {
        var keyHint = document.getElementById('aiApiKeyHint');
        if (!keyHint) return;
        var credentials = aiProviderCredentials(currentAiSettings || {}, provider || document.getElementById('aiProvider')?.value || 'openai-compatible');
        keyHint.textContent = credentials.hasApiKey ? t('ai.api_key_saved') : t('ai.api_key_missing');
    }

    function applyAiDefaultsForNewApiKey() {
        var keyInput = document.getElementById('aiApiKey');
        if (!keyInput || !keyInput.value.trim()) return;
        var provider = document.getElementById('aiProvider')?.value || (currentAiSettings && currentAiSettings.provider) || 'openai-compatible';
        var credentials = aiProviderCredentials(currentAiSettings || {}, provider);
        if ((currentAiSettings && currentAiSettings.hasApiKey) || credentials.hasApiKey) return;
        [
            'aiEnabled',
            'aiAssistantSurfaceVisualEditor',
            'aiAssistantSurfaceDashboard',
            'aiFeatureAssistant',
            'aiFeatureSeo',
            'aiFeatureImages'
        ].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.checked = true;
        });
    }

    function collectAiSettingsForm() {
        applyAiDefaultsForNewApiKey();
        var provider = document.getElementById('aiProvider').value;
        var imagePicker = document.getElementById('aiImageModelPicker');
        if (imagePicker) {
            document.getElementById('aiImageModel').value = imagePicker.value;
        }
        return {
            enabled: document.getElementById('aiEnabled').checked,
            provider: provider,
            baseUrl: document.getElementById('aiBaseUrl').value.trim(),
            apiKey: document.getElementById('aiApiKey').value.trim(),
            clearApiKey: document.getElementById('aiClearApiKey').checked,
            organization: document.getElementById('aiOrganization').value.trim(),
            allowLocalProvider: document.getElementById('aiAllowLocalProvider').checked,
            assistantForceEnglish: document.getElementById('aiAssistantForceEnglish').checked,
            assistantSurfaces: {
                visualEditor: document.getElementById('aiAssistantSurfaceVisualEditor').checked,
                dashboard: document.getElementById('aiAssistantSurfaceDashboard').checked
            },
            chatModel: document.getElementById('aiChatModel').value.trim(),
            textModel: document.getElementById('aiTextModel').value.trim(),
            imageModel: document.getElementById('aiImageModel').value.trim(),
            features: {
                backendAssistant: document.getElementById('aiFeatureAssistant').checked,
                seoTextGeneration: document.getElementById('aiFeatureSeo').checked,
                imageGeneration: document.getElementById('aiFeatureImages').checked
            },
            limits: {
                monthlyBudgetCents: parseInt(document.getElementById('aiMonthlyBudget').value || '0', 10),
                dailyRequests: parseInt(document.getElementById('aiDailyRequests').value || '0', 10),
                dailyTextRequests: parseInt(document.getElementById('aiDailyTextRequests').value || '0', 10),
                dailyImageRequests: parseInt(document.getElementById('aiDailyImageRequests').value || '0', 10),
                maxInputTokens: parseInt(document.getElementById('aiMaxInputTokens').value || '0', 10),
                maxOutputTokens: parseInt(document.getElementById('aiMaxOutputTokens').value || '0', 10),
                requestTimeoutSeconds: parseInt(document.getElementById('aiRequestTimeout').value || '0', 10)
            },
            pricing: {
                inputCentsPerMillion: parseInt(document.getElementById('aiInputPrice').value || '0', 10),
                outputCentsPerMillion: parseInt(document.getElementById('aiOutputPrice').value || '0', 10),
                imageCentsPerRequest: parseInt(document.getElementById('aiImagePrice').value || '0', 10)
            }
        };
    }

    document.getElementById('aiProvider')?.addEventListener('change', function() {
        var baseUrl = document.getElementById('aiBaseUrl');
        var credentials = aiProviderCredentials(currentAiSettings || {}, this.value);
        if (baseUrl) baseUrl.value = credentials.baseUrl || '';
        var organization = document.getElementById('aiOrganization');
        if (organization) organization.value = credentials.organization || '';
        var keyInput = document.getElementById('aiApiKey');
        if (keyInput) keyInput.value = '';
        var clearKey = document.getElementById('aiClearApiKey');
        if (clearKey) clearKey.checked = false;
        updateAiApiKeyHint(this.value);
        updateAiModelPlaceholders(this.value);
        updateAiImageModelControl({
            provider: this.value,
            baseUrl: baseUrl ? baseUrl.value : '',
            imageModel: document.getElementById('aiImageModel')?.value || ''
        });
        var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
        draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
        updateAiAvailability(draft);
    });

    document.getElementById('aiImageModelPicker')?.addEventListener('change', function() {
        var imageInput = document.getElementById('aiImageModel');
        if (imageInput) imageInput.value = this.value;
        updateAiImageScaleOptions();
        var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
        draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
        updateAiAvailability(draft);
    });

    ['aiApiKey', 'aiBaseUrl', 'aiAllowLocalProvider', 'aiEnabled', 'aiAssistantForceEnglish', 'aiAssistantSurfaceVisualEditor', 'aiAssistantSurfaceDashboard', 'aiFeatureAssistant', 'aiFeatureSeo', 'aiFeatureImages', 'aiImageModel', 'aiImageModelPicker'].forEach(function(id) {
        document.addEventListener('input', function(e) {
            if (e.target && e.target.id === id) {
                if (id === 'aiApiKey') applyAiDefaultsForNewApiKey();
                var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
                draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
                updateAiAvailability(draft);
            }
        });
        document.addEventListener('change', function(e) {
            if (e.target && e.target.id === id) {
                if (id === 'aiApiKey') applyAiDefaultsForNewApiKey();
                var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
                draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
                updateAiAvailability(draft);
            }
        });
    });

    function appendAiChat(role, text) {
        var log = document.getElementById('aiChatLog');
        if (!log) return;
        var item = document.createElement('div');
        item.className = 'ai-chat-message ai-chat-message--' + role;
        if (role === 'assistant') {
            item.innerHTML = window.renderSimpleMarkup(text);
        } else {
            item.textContent = text;
        }
        log.appendChild(item);
        log.scrollTop = log.scrollHeight;
    }

    window.renderSimpleMarkup = function(text) {
        var escaped = escapeHtml(String(text || ''));
        return escaped
            .replace(/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)/g, function(match, label, url) {
                var safeUrl = url.replace(/&amp;/g, '&');
                return '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            })
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\\n]+)\*/g, '$1<em>$2</em>')
            .replace(/`([^`]+)`/g, '<code>$1</code>');
    };

    // Form definitions
    let formsAdminData = [];
    let currentFormEditorId = '';

    function defaultFormDefinition(id) {
        return {
            id: id || 'kontakt',
            label: t('forms.contact_default'),
            description: '',
            enabled: true,
            submit: {
                store: true,
                email: true,
                subject: '{form}: {name}',
                successText: t('forms.default_success')
            },
            fields: [
                { type: 'text', key: 'name', label: t('mails.name').replace(':', ''), placeholder: '', required: true, width: 6, options: [] },
                { type: 'email', key: 'email', label: t('mails.email').replace(':', ''), placeholder: '', required: true, width: 6, options: [] },
                { type: 'textarea', key: 'message', label: t('mails.message').replace(':', ''), placeholder: '', required: true, width: 12, options: [] }
            ]
        };
    }

    async function loadFormsAdmin() {
        try {
            const response = await fetch('api.php?action=list-forms');
            const result = await response.json();
            if (!result.success) {
                showToast(result.message || t('forms.load_error'), 'error');
                return;
            }
            formsAdminData = result.data || [];
            renderFormsAdminList();
            if (formsAdminData.length && !currentFormEditorId) {
                await loadFormEditor(formsAdminData[0].id);
            } else if (!formsAdminData.length && !currentFormEditorId) {
                populateFormEditor(defaultFormDefinition('kontakt'));
            }
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    function renderFormsAdminList() {
        const select = document.getElementById('formsAdminSelect');
        const meta = document.getElementById('formsAdminMeta');
        if (!select) return;
        const current = currentFormEditorId;
        select.innerHTML = '';
        if (!formsAdminData.length) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = t('forms.empty');
            select.appendChild(option);
            select.disabled = true;
            if (meta) meta.textContent = '';
            return;
        }
        select.disabled = false;
        formsAdminData.forEach(form => {
            const option = document.createElement('option');
            option.value = form.id;
            option.textContent = `${form.label || form.id} (${form.id})`;
            select.appendChild(option);
        });
        if (formsAdminData.some(form => form.id === current)) {
            select.value = current;
        }
        const selected = formsAdminData.find(form => form.id === select.value);
        if (meta) {
            meta.textContent = selected
                ? `${selected.id} · ${t('forms.field_count', {count: selected.fieldCount || 0})}`
                : '';
        }
    }

    async function loadFormEditor(formId) {
        try {
            const response = await fetch('api.php?action=load-form&form_id=' + encodeURIComponent(formId));
            const result = await response.json();
            if (!result.success) {
                showToast(result.message || t('forms.load_error'), 'error');
                return;
            }
            populateFormEditor(result.data);
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    function populateFormEditor(form) {
        currentFormEditorId = form.id || '';
        document.getElementById('formEditorId').value = form.id || '';
        document.getElementById('formEditorLabel').value = form.label || '';
        document.getElementById('formEditorDescription').value = form.description || '';
        document.getElementById('formEditorEnabled').checked = form.enabled !== false;
        document.getElementById('formEditorStore').checked = !form.submit || form.submit.store !== false;
        document.getElementById('formEditorEmail').checked = !form.submit || form.submit.email !== false;
        document.getElementById('formEditorSubject').value = (form.submit && form.submit.subject) || '{form}: {name}';
        document.getElementById('formEditorSuccess').value = (form.submit && form.submit.successText) || t('forms.default_success');
        renderFormFields(form.fields || []);
        renderFormsAdminList();
    }

    function renderFormFields(fields) {
        const tbody = document.getElementById('formFieldsBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        fields.forEach(field => tbody.appendChild(createFormFieldRow(field)));
    }

    function createFormFieldRow(field) {
        const tr = document.createElement('tr');
        tr.className = 'forms-field-row';
        const optionsText = (field.options || []).map(option => {
            if (typeof option === 'string') return option;
            return option.value === option.label ? option.value : `${option.value}|${option.label}`;
        }).join('\n');
        tr.innerHTML = `
            <td data-label="${escapeHtml(t('forms.type'))}"><select data-field-prop="type">
                ${['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox', 'date', 'time', 'heading', 'note', 'hidden'].map(type => `<option value="${type}"${field.type === type ? ' selected' : ''}>${type}</option>`).join('')}
            </select></td>
            <td data-label="${escapeHtml(t('forms.key'))}"><input type="text" data-field-prop="key" value="${escapeHtml(field.key || '')}"></td>
            <td data-label="${escapeHtml(t('forms.label'))}"><input type="text" data-field-prop="label" value="${escapeHtml(field.label || '')}"></td>
            <td data-label="${escapeHtml(t('forms.placeholder'))}"><input type="text" data-field-prop="placeholder" value="${escapeHtml(field.placeholder || '')}"></td>
            <td data-label="${escapeHtml(t('forms.width'))}"><select data-field-prop="width">
                ${[3, 4, 6, 8, 12].map(width => `<option value="${width}"${Number(field.width || 12) === width ? ' selected' : ''}>${width}/12</option>`).join('')}
            </select></td>
            <td class="forms-field-check" data-label="${escapeHtml(t('forms.required'))}"><input type="checkbox" data-field-prop="required"${field.required ? ' checked' : ''}></td>
            <td data-label="${escapeHtml(t('forms.options'))}"><textarea data-field-prop="options" rows="2">${escapeHtml(optionsText)}</textarea></td>
            <td class="users-table__actions"><button type="button" class="btn-icon btn-icon--danger" title="${escapeHtml(t('btn.delete'))}">${icon('trash', 14, '2')}</button></td>
        `;
        tr.querySelector('.btn-icon--danger').addEventListener('click', () => tr.remove());
        return tr;
    }

    function parseFormOptions(text) {
        return String(text || '').split(/\r?\n/)
            .map(line => line.trim())
            .filter(Boolean)
            .map(line => {
                const parts = line.split('|');
                const value = (parts[0] || '').trim();
                const label = (parts[1] || parts[0] || '').trim();
                return { value, label };
            });
    }

    function collectFormEditor() {
        const fields = Array.from(document.querySelectorAll('#formFieldsBody tr')).map(row => {
            const get = prop => row.querySelector(`[data-field-prop="${prop}"]`);
            return {
                type: get('type').value,
                key: get('key').value,
                label: get('label').value,
                placeholder: get('placeholder').value,
                required: get('required').checked,
                width: Number(get('width').value) || 12,
                options: parseFormOptions(get('options').value)
            };
        });
        return {
            id: document.getElementById('formEditorId').value,
            label: document.getElementById('formEditorLabel').value,
            description: document.getElementById('formEditorDescription').value,
            enabled: document.getElementById('formEditorEnabled').checked,
            submit: {
                store: document.getElementById('formEditorStore').checked,
                email: document.getElementById('formEditorEmail').checked,
                subject: document.getElementById('formEditorSubject').value,
                successText: document.getElementById('formEditorSuccess').value
            },
            fields
        };
    }

    async function saveFormEditor(event) {
        event.preventDefault();
        const resultBox = document.getElementById('formsAdminResult');
        try {
            const formData = new FormData();
            formData.append('action', 'save-form');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('form', JSON.stringify(collectFormEditor()));
            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) {
                showToast(result.message || t('forms.save_error'), 'error');
                return;
            }
            populateFormEditor(result.data);
            await loadFormsAdmin();
            if (resultBox) {
                resultBox.className = 'settings-test-result settings-test-result--success';
                resultBox.textContent = t('forms.saved');
                resultBox.style.display = '';
            }
            showToast(t('forms.saved'), 'success');
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }

    document.getElementById('formsAdminForm')?.addEventListener('submit', saveFormEditor);
    document.getElementById('formsAdminSelect')?.addEventListener('change', function() {
        if (this.value) loadFormEditor(this.value);
    });
    document.getElementById('addFormFieldBtn')?.addEventListener('click', function() {
        document.getElementById('formFieldsBody')?.appendChild(createFormFieldRow({
            type: 'text',
            key: 'field_' + (document.querySelectorAll('#formFieldsBody tr').length + 1),
            label: t('forms.new_field'),
            placeholder: '',
            required: false,
            width: 12,
            options: []
        }));
    });
    document.getElementById('newFormBtn')?.addEventListener('click', function() {
        currentFormEditorId = '';
        populateFormEditor(defaultFormDefinition('formular-' + (formsAdminData.length + 1)));
    });

    // Settings sub-tabs
    function getActiveSettingsTab() {
        var active = document.querySelector('.settings-tab-btn.active');
        return active ? active.getAttribute('data-settings-tab') : 'branding';
    }

    function loadSettingsTabData(tab) {
        if (tab === 'users' && typeof loadUsers === 'function' && typeof _usersLoaded !== 'undefined' && !_usersLoaded) {
            _usersLoaded = true;
            loadUsers();
        }
        if (tab === 'menus' && typeof loadMenuOrder === 'function' && typeof _menuOrderLoaded !== 'undefined' && !_menuOrderLoaded) {
            _menuOrderLoaded = true;
            loadMenuOrder();
        }
        if (tab === 'forms' && typeof loadFormsAdmin === 'function' && !formsAdminLoaded) {
            formsAdminLoaded = true;
            loadFormsAdmin();
        }
        if (tab === 'ai' && AI_FEATURES_ENABLED && typeof loadAiSettings === 'function') {
            loadAiSettings();
        }
    }

    function activateSettingsTab(tab, options) {
        options = options || {};
        var btn = document.querySelector('.settings-tab-btn[data-settings-tab="' + tab + '"]');
        if (!btn) return;
        document.querySelectorAll('.settings-tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.settings-panel').forEach(function(p) { p.classList.remove('active'); });
        btn.classList.add('active');
        var panel = document.getElementById('settingsPanel-' + tab);
        if (panel) panel.classList.add('active');
        loadSettingsTabData(tab);
        if (!options.silent) updateDashboardHash('settings', tab, !!options.replace);
    }

    document.querySelectorAll('.settings-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var action = this.getAttribute('data-settings-action');
            if (action === 'backup') {
                switchTab('backup');
                return;
            }
            var tab = this.getAttribute('data-settings-tab');
            if (!tab) return;
            activateSettingsTab(tab);
        });
    });

    async function loadSettings() {
        try {
            const response = await fetch('api.php?action=load-settings');
            const result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                populateSettings(currentSettings);
                applyTheme(currentSettings.theme || {});
                settingsLoaded = true;
            } else {
                showToast(t('toast.error_loading_settings', {message: result.message}), 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading_settings', {message: error.message}), 'error');
        }
    }

    function populateSettings(settings) {
        // Branding
        var faviconPath = settings.favicon || '/assets/images/favicon.svg';
        document.getElementById('settingsFavicon').value = faviconPath;
        updateFaviconPreview(faviconPath);
        updateClearButton(document.getElementById('settingsFavicon'));

        var faviconPngPath = settings.favicon_png || '';
        document.getElementById('settingsFaviconPng').value = faviconPngPath;
        updateFaviconPngPreview(faviconPngPath);
        updateClearButton(document.getElementById('settingsFaviconPng'));

        var logoPath = settings.branding.logo || '';
        document.getElementById('settingsLogo').value = logoPath;
        document.getElementById('settingsName').value = settings.branding.name || '';
        document.getElementById('settingsShowBranding').checked = settings.branding.showBranding !== false;
        updateLogoPreview(logoPath);
        updateClearButton(document.getElementById('settingsLogo'));

        var adminLogoPath = settings.branding.adminLogo || '';
        document.getElementById('settingsAdminLogo').value = adminLogoPath;
        updateAdminLogoPreview(adminLogoPath);
        updateClearButton(document.getElementById('settingsAdminLogo'));

        var logoDarkPath = settings.branding.logoDark || '';
        document.getElementById('settingsLogoDark').value = logoDarkPath;
        updateLogoDarkPreview(logoDarkPath);
        updateClearButton(document.getElementById('settingsLogoDark'));

        var logoDisplay = settings.branding.logoDisplay || 'both';
        var displayRadio = document.querySelector('input[name="settingsLogoDisplay"][value="' + logoDisplay + '"]');
        if (displayRadio) displayRadio.checked = true;
        updateLogoDisplayVisibility();

        var logoSize = settings.branding.logoSize || 'medium';
        var sizeRadio = document.querySelector('input[name="settingsLogoSize"][value="' + logoSize + '"]');
        if (sizeRadio) sizeRadio.checked = true;

        var defaultOgPath = settings.seo?.defaultOgImage || '';
        var defaultOgInput = document.getElementById('settingsDefaultOgImage');
        if (defaultOgInput) {
            defaultOgInput.value = defaultOgPath;
            updateDefaultOgPreview(defaultOgPath);
            updateClearButton(defaultOgInput);
        }

        // Theme
        document.getElementById('settingsAdminTheme').value = settings.theme.adminTheme || 'light';
        document.querySelectorAll('.theme-option').forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.theme === settings.theme.adminTheme);
        });

        // Colors — Light mode
        var primary = settings.theme.primaryColor || '#3858e9';
        var accent = settings.theme.accentColor || '#3858e9';
        document.getElementById('settingsPrimaryColor').value = primary;
        document.getElementById('settingsPrimaryColorPicker').value = primary;
        document.getElementById('settingsAccentColor').value = accent;
        document.getElementById('settingsAccentColorPicker').value = accent;

        // Optional fields — empty means "auto". Show the derived value but mark it as auto.
        var sidebarLight = settings.theme.sidebarBg || '';
        setAutoField('sidebarBg', sidebarLight, deriveSidebarLight(primary));

        // Colors — Dark mode
        var darkPrimary = settings.theme.darkPrimaryColor || '';
        var darkAccent = settings.theme.darkAccentColor || '';
        var darkSidebar = settings.theme.darkSidebarBg || '';
        setAutoField('darkPrimaryColor', darkPrimary, primary);
        setAutoField('darkAccentColor', darkAccent, accent);
        // Dark sidebar derives from the resolved dark primary
        var resolvedDarkPrimary = darkPrimary || primary;
        setAutoField('darkSidebarBg', darkSidebar, deriveSidebarDark(resolvedDarkPrimary));

        // Button style
        var glowCheckbox = document.getElementById('settingsButtonGlow');
        if (glowCheckbox) glowCheckbox.checked = settings.theme.buttonGlow !== false;
        var radiusSlider = document.getElementById('settingsButtonRadius');
        var radiusValue = settings.theme.buttonRadius != null ? settings.theme.buttonRadius : 6;
        if (radiusSlider) {
            radiusSlider.value = radiusValue;
            document.getElementById('settingsButtonRadiusValue').textContent = radiusValue + 'px';
        }
        var dashboardSettings = settings.dashboard || {};
        var itemsPerPageEl = document.getElementById('settingsItemsPerPage');
        if (itemsPerPageEl) itemsPerPageEl.value = clampDashboardPageSize(dashboardSettings.itemsPerPage);
        var iconItemsPerPageEl = document.getElementById('settingsIconItemsPerPage');
        if (iconItemsPerPageEl) iconItemsPerPageEl.value = clampDashboardPageSize(dashboardSettings.iconManagerItemsPerPage);
        var mediaItemsPerPageEl = document.getElementById('settingsMediaItemsPerPage');
        if (mediaItemsPerPageEl) mediaItemsPerPageEl.value = clampMediaPageSize(dashboardSettings.mediaItemsPerPage);

        // Language
        var langSelect = document.getElementById('settingsAdminLanguage');
        if (langSelect) langSelect.value = settings.general?.adminLanguage || '';

        // Frontend-login redirect mode (default: 'auto')
        var loginMode = (settings.general && settings.general.frontendLoginRedirect) || 'auto';
        var modeRadio = document.querySelector('input[name="frontendLoginRedirect"][value="' + loginMode + '"]');
        if (modeRadio) modeRadio.checked = true;
        var loginVisual = settings.login || {};
        var loginBrandEl = document.getElementById('loginBrandAsset');
        if (loginBrandEl) loginBrandEl.value = loginVisual.brandAsset || 'favicon';
        var loginImageLayoutEl = document.getElementById('loginImageLayout');
        if (loginImageLayoutEl) loginImageLayoutEl.value = normalizeVisualImageLayout(loginVisual.imageLayout || 'none');
        var loginOverlayColorEl = document.getElementById('loginOverlayColor');
        if (loginOverlayColorEl) loginOverlayColorEl.value = loginVisual.overlayColor || '#ffffff';
        setOverlayOpacity('loginOverlayOpacity', 'loginOverlayOpacityValue', loginVisual.overlayOpacity, 86);
        updateVisualOverlayVisibility('loginImageLayout', 'loginOverlayColorGroup');
        var loginBoxStyleEl = document.getElementById('loginBoxStyle');
        if (loginBoxStyleEl) loginBoxStyleEl.value = loginVisual.boxStyle || 'card';
        setLoginColorPair('loginBoxColor', loginVisual.boxColor || '#ffffff');
        setLoginColorPair('loginBoxTextColor', loginVisual.boxTextColor || '#111827');
        updateLoginBoxColorVisibility();
        var loginImageEl = document.getElementById('loginImage');
        if (loginImageEl) {
            loginImageEl.value = loginVisual.image || '';
            updateClearButton(loginImageEl);
        }

        // Access / privacy
        var access = settings.access || {};
        var maintenance = access.maintenance || {};
        var enabledEl = document.getElementById('maintenanceEnabled');
        if (enabledEl) enabledEl.checked = !!maintenance.enabled;
        var maintModeEl = document.getElementById('maintenanceMode');
        if (maintModeEl) maintModeEl.value = maintenance.mode || 'maintenance';
        var maintTitleEl = document.getElementById('maintenanceTitle');
        if (maintTitleEl) maintTitleEl.value = maintenance.title || '';
        var maintTextEl = document.getElementById('maintenanceText');
        if (maintTextEl) maintTextEl.value = maintenance.text || '';
        var maintUntilEl = document.getElementById('maintenanceUntil');
        if (maintUntilEl) maintUntilEl.value = (maintenance.until || '').slice(0, 16);
        var maintCountdownEl = document.getElementById('maintenanceCountdown');
        if (maintCountdownEl) maintCountdownEl.checked = !!maintenance.showCountdown;
        var maintBrandEl = document.getElementById('maintenanceBrandAsset');
        if (maintBrandEl) maintBrandEl.value = maintenance.brandAsset || 'none';
        var maintImageLayoutEl = document.getElementById('maintenanceImageLayout');
        if (maintImageLayoutEl) maintImageLayoutEl.value = normalizeVisualImageLayout(maintenance.imageLayout || 'none');
        var maintOverlayColorEl = document.getElementById('maintenanceOverlayColor');
        if (maintOverlayColorEl) maintOverlayColorEl.value = maintenance.overlayColor || '#ffffff';
        setOverlayOpacity('maintenanceOverlayOpacity', 'maintenanceOverlayOpacityValue', maintenance.overlayOpacity, 88);
        updateVisualOverlayVisibility('maintenanceImageLayout', 'maintenanceOverlayColorGroup');
        var maintImageEl = document.getElementById('maintenanceImage');
        if (maintImageEl) {
            maintImageEl.value = maintenance.image || '';
            updateClearButton(maintImageEl);
        }
        var bypassParamEl = document.getElementById('maintenanceBypassParam');
        if (bypassParamEl) bypassParamEl.value = maintenance.bypassParam || 'preview';
        var bypassKeyEl = document.getElementById('maintenanceBypassKey');
        if (bypassKeyEl) bypassKeyEl.value = '';
        var bypassHintEl = document.getElementById('maintenanceBypassHint');
        if (bypassHintEl) bypassHintEl.textContent = maintenance.hasBypassKey ? t('settings.access_bypass_key_set') : t('settings.access_bypass_key_hint');
        var modules = settings.modules || {};
        var moduleAiEl = document.getElementById('moduleAi');
        if (moduleAiEl) moduleAiEl.checked = modules.ai !== false;
        var moduleNewsEl = document.getElementById('moduleNews');
        if (moduleNewsEl) moduleNewsEl.checked = modules.news !== false;
        var moduleEventsEl = document.getElementById('moduleEvents');
        if (moduleEventsEl) moduleEventsEl.checked = modules.events !== false;
        var moduleMessagesEl = document.getElementById('moduleMessages');
        if (moduleMessagesEl) moduleMessagesEl.checked = modules.messages !== false;
        var moduleIconManagerEl = document.getElementById('moduleIconManager');
        if (moduleIconManagerEl) moduleIconManagerEl.checked = modules.iconManager !== false;
        var obfuscationEl = document.getElementById('emailObfuscation');
        if (obfuscationEl) obfuscationEl.checked = !!(settings.privacy && settings.privacy.emailObfuscation);
        var rememberThemeEl = document.getElementById('rememberPublicTheme');
        if (rememberThemeEl) rememberThemeEl.checked = !(settings.privacy && settings.privacy.rememberPublicTheme === false);

        updateColorPreview(primary, accent);
        updateBtnStylePreview();

        // Email
        var email = settings.email || {};
        var methodSelect = document.getElementById('settingsEmailMethod');
        if (methodSelect) methodSelect.value = email.method || 'inactive';
        document.getElementById('settingsRecipientEmail').value = email.recipientEmail || '';
        document.getElementById('settingsBccEmail').value = email.bccEmail || '';
        document.getElementById('settingsFromEmail').value = email.fromEmail || '';
        document.getElementById('settingsFromName').value = email.fromName || '';
        document.getElementById('settingsSmtpHost').value = email.smtpHost || '';
        document.getElementById('settingsSmtpPort').value = email.smtpPort || 587;
        document.getElementById('settingsSmtpUsername').value = email.smtpUsername || '';
        document.getElementById('settingsSmtpPassword').value = '';
        document.getElementById('settingsSmtpEncryption').value = email.smtpEncryption || 'tls';
        // Show/hide SMTP fields based on method
        toggleSmtpFields(email.method || 'inactive');
        // Mark password field if saved
        if (email.smtpPassword) {
            document.getElementById('settingsSmtpPassword').placeholder = '••••••••';
        }
    }

    function updateLogoPreview(path) {
        var img = document.getElementById('logoPreviewImg');
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
        updateLogoDisplayVisibility();
    }

    function updateFaviconPreview(path) {
        var img = document.getElementById('faviconPreviewImg');
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    function updateFaviconPngPreview(path) {
        var img = document.getElementById('faviconPngPreviewImg');
        if (!img) return;
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    function updateAdminLogoPreview(path) {
        var img = document.getElementById('adminLogoPreviewImg');
        if (!img) return;
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    function updateLogoDarkPreview(path) {
        var img = document.getElementById('logoDarkPreviewImg');
        if (!img) return;
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    function updateDefaultOgPreview(path) {
        var img = document.getElementById('defaultOgPreviewImg');
        if (!img) return;
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    // 3-way logo display selector is only relevant when no logo is set
    function updateLogoDisplayVisibility() {
        var logoVal = document.getElementById('settingsLogo').value.trim();
        var group = document.getElementById('logoDisplayGroup');
        if (group) group.style.display = logoVal ? 'none' : '';
    }

    function normalizeVisualImageLayout(layout) {
        return layout === 'split' ? 'left' : (['none', 'background', 'left', 'right'].includes(layout) ? layout : 'none');
    }

    function updateVisualOverlayVisibility(layoutSelectId, groupId) {
        var select = document.getElementById(layoutSelectId);
        var group = document.getElementById(groupId);
        if (!select || !group) return;
        group.hidden = normalizeVisualImageLayout(select.value) !== 'background';
    }

    function setOverlayOpacity(inputId, valueId, value, fallback) {
        var input = document.getElementById(inputId);
        var valueEl = document.getElementById(valueId);
        if (!input) return;
        var numeric = Number.isFinite(Number(value)) ? Number(value) : fallback;
        numeric = Math.max(0, Math.min(100, Math.round(numeric)));
        input.value = numeric;
        if (valueEl) valueEl.textContent = numeric + '%';
    }

    function syncOverlayOpacity(inputId, valueId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        setOverlayOpacity(inputId, valueId, input.value, Number(input.value) || 0);
    }

    function updateLoginBoxColorVisibility() {
        var select = document.getElementById('loginBoxStyle');
        var group = document.getElementById('loginBoxColorGroup');
        if (!select || !group) return;
        group.hidden = select.value !== 'card';
    }

    // ============================================================
    // THEME COLORS — auto-derivation, auto-badge, live preview
    // ============================================================

    // Defaults — kept in sync with server-side ($defaults in api.php load-settings)
    var THEME_DEFAULTS = {
        adminTheme: 'light',
        primaryColor: '#3858e9',
        accentColor: '#3858e9',
        sidebarBg: '',
        darkPrimaryColor: '',
        darkAccentColor: '',
        darkSidebarBg: '',
        buttonGlow: true,
        buttonRadius: 6
    };

    var BRANDING_DEFAULTS = {
        favicon: <?php echo json_encode($_defaultFavicon); ?>,
        favicon_png: '',
        logo: '',
        logoDark: '',
        adminLogo: '',
        name: <?php echo json_encode(defined('SITE_NAME') ? SITE_NAME : 'CMS'); ?>,
        showBranding: true,
        logoDisplay: 'both',
        logoSize: 'medium',
        defaultOgImage: ''
    };

    // Sidebar bg derivations — match the CSS color-mix() on first paint
    function deriveSidebarLight(primary) {
        return mixColors(primary, '#ffffff', 0.12);
    }
    function deriveSidebarDark(primary) {
        return mixColors(primary, '#0b0d12', 0.10);
    }

    // Mix two hex colors (sRGB approximation of CSS color-mix(in srgb, a X%, b))
    function mixColors(a, b, ratio) {
        a = a.replace('#', '');
        b = b.replace('#', '');
        var ar = parseInt(a.substring(0, 2), 16);
        var ag = parseInt(a.substring(2, 4), 16);
        var ab = parseInt(a.substring(4, 6), 16);
        var br = parseInt(b.substring(0, 2), 16);
        var bg = parseInt(b.substring(2, 4), 16);
        var bb = parseInt(b.substring(4, 6), 16);
        var r = Math.round(ar * ratio + br * (1 - ratio));
        var g = Math.round(ag * ratio + bg * (1 - ratio));
        var bl = Math.round(ab * ratio + bb * (1 - ratio));
        return '#' + [r, g, bl].map(function(c) { return c.toString(16).padStart(2, '0'); }).join('');
    }

    function normalizeHexColor(value) {
        return String(value || '').trim().toLowerCase();
    }

    function hexToRgb(hex) {
        hex = normalizeHexColor(hex).replace('#', '');
        return [
            parseInt(hex.substring(0, 2), 16),
            parseInt(hex.substring(2, 4), 16),
            parseInt(hex.substring(4, 6), 16)
        ];
    }

    function relativeLuminance(hex) {
        return hexToRgb(hex).map(function(channel) {
            var value = channel / 255;
            return value <= 0.03928
                ? value / 12.92
                : Math.pow((value + 0.055) / 1.055, 2.4);
        }).reduce(function(total, channel, index) {
            return total + channel * [0.2126, 0.7152, 0.0722][index];
        }, 0);
    }

    function contrastRatio(a, b) {
        var l1 = relativeLuminance(a);
        var l2 = relativeLuminance(b);
        var lighter = Math.max(l1, l2);
        var darker = Math.min(l1, l2);
        return (lighter + 0.05) / (darker + 0.05);
    }

    function adjustColorForContrast(hex, background, minimumRatio, direction) {
        hex = normalizeHexColor(hex);
        if (contrastRatio(hex, background) >= minimumRatio) return hex;
        var target = direction === 'lighter' ? '#ffffff' : '#000000';
        for (var step = 1; step <= 20; step++) {
            var candidate = mixColors(hex, target, 1 - (step * 0.05));
            if (contrastRatio(candidate, background) >= minimumRatio) {
                return candidate;
            }
        }
        return target;
    }

    function sanitizeThemeContrast(theme) {
        var result = Object.assign({}, theme);
        var warnings = [];
        var minReadable = 3.0;

        function enforce(key, background, direction, labelKey) {
            if (!result[key]) return;
            var original = normalizeHexColor(result[key]);
            var adjusted = adjustColorForContrast(original, background, minReadable, direction);
            result[key] = adjusted;
            if (adjusted !== original) {
                warnings.push(t('settings.theme_color_adjusted', {field: t(labelKey), value: adjusted}));
            }
        }

        enforce('primaryColor', '#ffffff', 'darker', 'settings.primary_color');
        enforce('accentColor', '#ffffff', 'darker', 'settings.accent_color');
        enforce('darkPrimaryColor', '#0b0d12', 'lighter', 'settings.primary_color');
        enforce('darkAccentColor', '#0b0d12', 'lighter', 'settings.accent_color');

        if (!result.darkPrimaryColor && result.primaryColor && contrastRatio(result.primaryColor, '#0b0d12') < minReadable) {
            result.darkPrimaryColor = adjustColorForContrast(result.primaryColor, '#0b0d12', minReadable, 'lighter');
            warnings.push(t('settings.theme_color_adjusted', {field: t('settings.primary_color'), value: result.darkPrimaryColor}));
        }

        if (!result.darkAccentColor && result.accentColor && contrastRatio(result.accentColor, '#0b0d12') < minReadable) {
            result.darkAccentColor = adjustColorForContrast(result.accentColor, '#0b0d12', minReadable, 'lighter');
            warnings.push(t('settings.theme_color_adjusted', {field: t('settings.accent_color'), value: result.darkAccentColor}));
        }

        enforce('sidebarBg', '#1a1a1a', 'lighter', 'settings.sidebar_bg');
        enforce('darkSidebarBg', '#e5e5e5', 'darker', 'settings.sidebar_bg');

        return { theme: result, warnings: warnings };
    }

    function updateThemeContrastFeedback() {
        var minReadable = 3.0;
        var values = {
            primaryColor: document.getElementById('settingsPrimaryColor').value,
            accentColor: document.getElementById('settingsAccentColor').value,
            sidebarBg: document.getElementById('settingsSidebarBg').value,
            darkPrimaryColor: document.getElementById('settingsDarkPrimaryColor').value,
            darkAccentColor: document.getElementById('settingsDarkAccentColor').value,
            darkSidebarBg: document.getElementById('settingsDarkSidebarBg').value
        };
        var checks = {
            primaryColor: {background: '#ffffff', textContrast: false},
            accentColor: {background: '#ffffff', textContrast: false},
            sidebarBg: {background: '#1a1a1a', textContrast: true},
            darkPrimaryColor: {background: '#0b0d12', textContrast: false},
            darkAccentColor: {background: '#0b0d12', textContrast: false},
            darkSidebarBg: {background: '#e5e5e5', textContrast: true}
        };
        var hex = /^#[0-9a-fA-F]{6}$/;

        Object.keys(checks).forEach(function(key) {
            var el = document.querySelector('.theme-contrast-feedback[data-contrast-for="' + key + '"]');
            if (!el) return;
            var value = values[key];
            if (!hex.test(value)) {
                el.dataset.state = 'error';
                el.textContent = t('settings.contrast_invalid');
                return;
            }
            var ratio = contrastRatio(value, checks[key].background);
            var state = ratio < minReadable ? 'error' : 'ok';
            var keyLabel = checks[key].textContrast
                ? (ratio < minReadable ? 'settings.text_contrast_error' : 'settings.text_contrast_ok')
                : (ratio < minReadable ? 'settings.contrast_error' : 'settings.contrast_ok');
            el.dataset.state = state;
            el.textContent = t(keyLabel, {
                ratio: ratio.toFixed(1)
            });
        });
    }

    // Track which optional fields are in "auto" mode (empty in JSON, derived for display)
    var AUTO_STATE = {
        sidebarBg: true,
        darkPrimaryColor: true,
        darkAccentColor: true,
        darkSidebarBg: true
    };

    // Set an optional field's value: empty stored value = auto (show derived, badge on)
    function setAutoField(name, storedValue, derivedValue) {
        var hex = document.getElementById('settings' + capitalize(name));
        var picker = document.getElementById('settings' + capitalize(name) + 'Picker');
        var badge = document.querySelector('.auto-badge[data-auto-for="' + name + '"]');
        var isAuto = !storedValue;
        AUTO_STATE[name] = isAuto;
        var displayValue = isAuto ? derivedValue : storedValue;
        if (hex) hex.value = displayValue;
        if (picker) picker.value = displayValue;
        if (badge) badge.hidden = !isAuto;
    }

    function capitalize(s) {
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    // Read current theme state from the form (returns the same shape as settings.theme)
    function readThemeFormState() {
        return {
            adminTheme: document.getElementById('settingsAdminTheme').value,
            primaryColor: normalizeHexColor(document.getElementById('settingsPrimaryColor').value),
            accentColor: normalizeHexColor(document.getElementById('settingsAccentColor').value),
            sidebarBg: AUTO_STATE.sidebarBg ? '' : normalizeHexColor(document.getElementById('settingsSidebarBg').value),
            darkPrimaryColor: AUTO_STATE.darkPrimaryColor ? '' : normalizeHexColor(document.getElementById('settingsDarkPrimaryColor').value),
            darkAccentColor: AUTO_STATE.darkAccentColor ? '' : normalizeHexColor(document.getElementById('settingsDarkAccentColor').value),
            darkSidebarBg: AUTO_STATE.darkSidebarBg ? '' : normalizeHexColor(document.getElementById('settingsDarkSidebarBg').value),
            buttonGlow: document.getElementById('settingsButtonGlow').checked,
            buttonRadius: parseInt(document.getElementById('settingsButtonRadius').value, 10)
        };
    }

    function syncThemeFormColors(theme) {
        function setPair(id, value) {
            var input = document.getElementById(id);
            var picker = document.getElementById(id + 'Picker');
            if (input && value) input.value = value;
            if (picker && value) picker.value = value;
        }

        setPair('settingsPrimaryColor', theme.primaryColor);
        setPair('settingsAccentColor', theme.accentColor);
        if (!AUTO_STATE.sidebarBg) setPair('settingsSidebarBg', theme.sidebarBg);
        if (!AUTO_STATE.darkPrimaryColor) setPair('settingsDarkPrimaryColor', theme.darkPrimaryColor);
        if (!AUTO_STATE.darkAccentColor) setPair('settingsDarkAccentColor', theme.darkAccentColor);
        if (!AUTO_STATE.darkSidebarBg) setPair('settingsDarkSidebarBg', theme.darkSidebarBg);
        refreshAutoDisplays();
    }

    // Re-derive auto-fields when their source colors change (cascading display)
    function refreshAutoDisplays() {
        var primary = document.getElementById('settingsPrimaryColor').value;
        var accent = document.getElementById('settingsAccentColor').value;
        var hex = /^#[0-9a-fA-F]{6}$/;

        if (AUTO_STATE.sidebarBg && hex.test(primary)) {
            var v = deriveSidebarLight(primary);
            document.getElementById('settingsSidebarBg').value = v;
            document.getElementById('settingsSidebarBgPicker').value = v;
        }
        if (AUTO_STATE.darkPrimaryColor && hex.test(primary)) {
            document.getElementById('settingsDarkPrimaryColor').value = primary;
            document.getElementById('settingsDarkPrimaryColorPicker').value = primary;
        }
        if (AUTO_STATE.darkAccentColor && hex.test(accent)) {
            document.getElementById('settingsDarkAccentColor').value = accent;
            document.getElementById('settingsDarkAccentColorPicker').value = accent;
        }
        // Dark sidebar derives from resolved dark primary (which may itself be auto)
        var darkPrimary = AUTO_STATE.darkPrimaryColor
            ? primary
            : document.getElementById('settingsDarkPrimaryColor').value;
        if (AUTO_STATE.darkSidebarBg && hex.test(darkPrimary)) {
            var sd = deriveSidebarDark(darkPrimary);
            document.getElementById('settingsDarkSidebarBg').value = sd;
            document.getElementById('settingsDarkSidebarBgPicker').value = sd;
        }
    }

    // Live-update CSS variables on the <html> element (for both themes)
    function updateColorPreview() {
        refreshAutoDisplays();
        var theme = readThemeFormState();
        applyTheme(theme);
        updateThemeContrastFeedback();
    }

    // Combined button preview — uses primary color of the currently active theme
    function updateBtnStylePreview() {
        var glow = document.getElementById('settingsButtonGlow').checked;
        var radius = parseInt(document.getElementById('settingsButtonRadius').value, 10);
        var activeTheme = document.documentElement.getAttribute('data-site-theme') || 'light';
        var primary;
        if (activeTheme === 'dark') {
            primary = AUTO_STATE.darkPrimaryColor
                ? document.getElementById('settingsPrimaryColor').value
                : document.getElementById('settingsDarkPrimaryColor').value;
        } else {
            primary = document.getElementById('settingsPrimaryColor').value;
        }
        var btnPrimary = document.getElementById('previewBtnPrimary');
        var btnSecondary = document.getElementById('previewBtnSecondary');
        if (btnPrimary) {
            var primaryGlow = adjustColor(primary, 30);
            btnPrimary.style.background = glow
                ? 'radial-gradient(ellipse at 50% 0%, ' + primaryGlow + ' 0%, ' + primary + ' 70%)'
                : primary;
            btnPrimary.style.borderRadius = radius + 'px';
            if (glow) {
                btnPrimary.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15), 0 4px 20px ' + hexToRgba(primary, 0.35);
            } else {
                btnPrimary.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
            }
        }
        if (btnSecondary) {
            btnSecondary.style.borderRadius = radius + 'px';
        }
    }

    document.getElementById('settingsButtonGlow').addEventListener('change', updateBtnStylePreview);
    document.getElementById('settingsButtonRadius').addEventListener('input', function() {
        document.getElementById('settingsButtonRadiusValue').textContent = this.value + 'px';
        updateBtnStylePreview();
    });

    // Theme-aware browser favicon (recolors SVG currentColor on theme change)
    var THEME_FAVICON_COLORS = { light: '#0a0a0a', dark: '#e5e5e5' };
    var faviconSvgCache = null;
    function updateAdminBrowserFavicon(theme) {
        var link = document.querySelector('link[rel="icon"]');
        if (!link) return;
        var href = link.getAttribute('data-original-href') || link.getAttribute('href');
        if (!href || !/\.svg(\?|#|$)/i.test(href)) return;
        if (!link.getAttribute('data-original-href')) link.setAttribute('data-original-href', href);
        function apply() {
            if (!faviconSvgCache) return;
            var color = THEME_FAVICON_COLORS[theme] || THEME_FAVICON_COLORS.light;
            var patched = faviconSvgCache
                .replace(/<svg\b/, '<svg data-theme="' + theme + '"')
                .replace(/currentColor/g, color);
            link.setAttribute('href', 'data:image/svg+xml;utf8,' + encodeURIComponent(patched));
        }
        if (faviconSvgCache === null) {
            fetch(href).then(function(r){ return r.ok ? r.text() : null; }).then(function(svg){
                if (svg) { faviconSvgCache = svg; apply(); }
            }).catch(function(){});
        } else {
            apply();
        }
    }
    // Initial favicon apply
    updateAdminBrowserFavicon(document.documentElement.getAttribute('data-site-theme') || 'light');

    // Theme selector buttons — instant preview on click
    document.querySelectorAll('.theme-option').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.theme-option').forEach(function(b) { b.classList.remove('selected'); });
            this.classList.add('selected');
            var themeValue = this.dataset.theme;
            document.getElementById('settingsAdminTheme').value = themeValue;
            // Instant preview
            var resolved = themeValue;
            if (resolved === 'system') {
                resolved = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-site-theme', resolved);
            updateAdminBrowserFavicon(resolved);
            updateBtnStylePreview();
        });
    });

    // Bind a hex/picker pair. `optional` = field can be in auto mode; manual edits exit auto.
    function bindColorPair(name, optional) {
        var cap = capitalize(name);
        var hex = document.getElementById('settings' + cap);
        var picker = document.getElementById('settings' + cap + 'Picker');
        if (!hex || !picker) return;

        function onPicker() {
            hex.value = this.value;
            if (optional) {
                AUTO_STATE[name] = false;
                var badge = document.querySelector('.auto-badge[data-auto-for="' + name + '"]');
                if (badge) badge.hidden = true;
            }
            updateColorPreview();
        }
        function onHex() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                picker.value = this.value;
                if (optional) {
                    AUTO_STATE[name] = false;
                    var badge = document.querySelector('.auto-badge[data-auto-for="' + name + '"]');
                    if (badge) badge.hidden = true;
                }
                updateColorPreview();
            }
        }
        picker.addEventListener('input', onPicker);
        picker.addEventListener('change', updateThemeContrastFeedback);
        hex.addEventListener('input', onHex);
        hex.addEventListener('blur', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                this.value = normalizeHexColor(this.value);
                picker.value = this.value;
                updateColorPreview();
            } else {
                updateThemeContrastFeedback();
            }
        });
        hex.addEventListener('change', updateThemeContrastFeedback);
    }

    bindColorPair('primaryColor', false);
    bindColorPair('accentColor', false);
    bindColorPair('sidebarBg', true);
    bindColorPair('darkPrimaryColor', true);
    bindColorPair('darkAccentColor', true);
    bindColorPair('darkSidebarBg', true);

    function setLoginColorPair(id, value) {
        var normalized = /^#[0-9a-fA-F]{6}$/.test(value || '') ? normalizeHexColor(value) : '';
        var hex = document.getElementById(id);
        var picker = document.getElementById(id + 'Picker');
        if (hex && normalized) hex.value = normalized;
        if (picker && normalized) picker.value = normalized;
    }

    function bindLoginColorPair(id) {
        var hex = document.getElementById(id);
        var picker = document.getElementById(id + 'Picker');
        if (!hex || !picker) return;

        picker.addEventListener('input', function() {
            hex.value = this.value;
        });
        hex.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                picker.value = this.value;
            }
        });
        hex.addEventListener('blur', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                this.value = normalizeHexColor(this.value);
                picker.value = this.value;
            }
        });
    }

    bindLoginColorPair('loginBoxColor');
    bindLoginColorPair('loginBoxTextColor');

    // Auto-reset buttons — return a field to "auto" (empty stored, derived display)
    document.querySelectorAll('.auto-reset-btn[data-auto-reset]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var name = btn.dataset.autoReset;
            AUTO_STATE[name] = true;
            var badge = document.querySelector('.auto-badge[data-auto-for="' + name + '"]');
            if (badge) badge.hidden = false;
            updateColorPreview();
        });
    });

    // Reset all colors button — restores defaults (without saving)
    document.getElementById('resetThemeBtn').addEventListener('click', function() {
        document.getElementById('settingsPrimaryColor').value = THEME_DEFAULTS.primaryColor;
        document.getElementById('settingsPrimaryColorPicker').value = THEME_DEFAULTS.primaryColor;
        document.getElementById('settingsAccentColor').value = THEME_DEFAULTS.accentColor;
        document.getElementById('settingsAccentColorPicker').value = THEME_DEFAULTS.accentColor;
        // All optional fields back to auto
        setAutoField('sidebarBg', '', deriveSidebarLight(THEME_DEFAULTS.primaryColor));
        setAutoField('darkPrimaryColor', '', THEME_DEFAULTS.primaryColor);
        setAutoField('darkAccentColor', '', THEME_DEFAULTS.accentColor);
        setAutoField('darkSidebarBg', '', deriveSidebarDark(THEME_DEFAULTS.primaryColor));
        updateColorPreview();
    });

    // Browse logo button — opens the image manager
    document.getElementById('browseLogoBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsLogo');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Browse dark-logo button — opens the image manager
    document.getElementById('browseLogoDarkBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsLogoDark');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Browse admin-logo button — opens the image manager
    document.getElementById('browseAdminLogoBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsAdminLogo');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Browse favicon button — opens the image manager
    document.getElementById('browseFaviconBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsFavicon');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Browse PNG favicon button — opens the image manager
    document.getElementById('browseFaviconPngBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsFaviconPng');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    document.getElementById('browseDefaultOgBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsDefaultOgImage');
        NbImageManager.open(function(path) {
            setOgImageInputValue(input, path);
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    var browseLoginImageBtn = document.getElementById('browseLoginImageBtn');
    if (browseLoginImageBtn) browseLoginImageBtn.addEventListener('click', function() {
        var input = document.getElementById('loginImage');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    var browseMaintenanceImageBtn = document.getElementById('browseMaintenanceImageBtn');
    if (browseMaintenanceImageBtn) browseMaintenanceImageBtn.addEventListener('click', function() {
        var input = document.getElementById('maintenanceImage');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Manual edits to the logo path also toggle the 3-way selector
    document.getElementById('settingsLogo').addEventListener('input', function() {
        updateLogoPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsLogoDark').addEventListener('input', function() {
        updateLogoDarkPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsAdminLogo').addEventListener('input', function() {
        updateAdminLogoPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsFavicon').addEventListener('input', function() {
        updateFaviconPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsFaviconPng').addEventListener('input', function() {
        updateFaviconPngPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsDefaultOgImage').addEventListener('input', function() {
        updateDefaultOgPreview(this.value.trim());
        updateClearButton(this);
    });
    var loginImageInput = document.getElementById('loginImage');
    if (loginImageInput) loginImageInput.addEventListener('input', function() {
        updateClearButton(this);
    });
    var loginImageLayoutSelect = document.getElementById('loginImageLayout');
    if (loginImageLayoutSelect) loginImageLayoutSelect.addEventListener('change', function() {
        updateVisualOverlayVisibility('loginImageLayout', 'loginOverlayColorGroup');
    });
    var loginOverlayOpacityInput = document.getElementById('loginOverlayOpacity');
    if (loginOverlayOpacityInput) loginOverlayOpacityInput.addEventListener('input', function() {
        syncOverlayOpacity('loginOverlayOpacity', 'loginOverlayOpacityValue');
    });
    var loginBoxStyleSelect = document.getElementById('loginBoxStyle');
    if (loginBoxStyleSelect) loginBoxStyleSelect.addEventListener('change', updateLoginBoxColorVisibility);
    var maintenanceImageInput = document.getElementById('maintenanceImage');
    if (maintenanceImageInput) maintenanceImageInput.addEventListener('input', function() {
        updateClearButton(this);
    });
    var maintenanceImageLayoutSelect = document.getElementById('maintenanceImageLayout');
    if (maintenanceImageLayoutSelect) maintenanceImageLayoutSelect.addEventListener('change', function() {
        updateVisualOverlayVisibility('maintenanceImageLayout', 'maintenanceOverlayColorGroup');
    });
    var maintenanceOverlayOpacityInput = document.getElementById('maintenanceOverlayOpacity');
    if (maintenanceOverlayOpacityInput) maintenanceOverlayOpacityInput.addEventListener('input', function() {
        syncOverlayOpacity('maintenanceOverlayOpacity', 'maintenanceOverlayOpacityValue');
    });

    document.getElementById('resetBrandingBtn').addEventListener('click', function() {
        document.getElementById('settingsFavicon').value = BRANDING_DEFAULTS.favicon;
        document.getElementById('settingsFaviconPng').value = BRANDING_DEFAULTS.favicon_png;
        document.getElementById('settingsLogo').value = BRANDING_DEFAULTS.logo;
        document.getElementById('settingsLogoDark').value = BRANDING_DEFAULTS.logoDark;
        document.getElementById('settingsAdminLogo').value = BRANDING_DEFAULTS.adminLogo;
        document.getElementById('settingsDefaultOgImage').value = BRANDING_DEFAULTS.defaultOgImage;
        document.getElementById('settingsName').value = BRANDING_DEFAULTS.name;
        document.getElementById('settingsShowBranding').checked = BRANDING_DEFAULTS.showBranding;
        var displayRadio = document.querySelector('input[name="settingsLogoDisplay"][value="' + BRANDING_DEFAULTS.logoDisplay + '"]');
        if (displayRadio) displayRadio.checked = true;
        var sizeRadio = document.querySelector('input[name="settingsLogoSize"][value="' + BRANDING_DEFAULTS.logoSize + '"]');
        if (sizeRadio) sizeRadio.checked = true;
        updateFaviconPreview(BRANDING_DEFAULTS.favicon);
        updateFaviconPngPreview(BRANDING_DEFAULTS.favicon_png);
        updateLogoPreview(BRANDING_DEFAULTS.logo);
        updateLogoDarkPreview(BRANDING_DEFAULTS.logoDark);
        updateAdminLogoPreview(BRANDING_DEFAULTS.adminLogo);
        updateDefaultOgPreview(BRANDING_DEFAULTS.defaultOgImage);
        document.querySelectorAll('.input-clear-btn[data-clear-target]').forEach(function(btn) {
            var input = document.getElementById(btn.dataset.clearTarget);
            if (input) updateClearButton(input);
        });
    });

    // Generic clear-X handler for image-path inputs
    function updateClearButton(input) {
        var btn = document.querySelector('.input-clear-btn[data-clear-target="' + input.id + '"]');
        if (btn) btn.hidden = !input.value.trim();
    }
    document.querySelectorAll('.input-clear-btn[data-clear-target]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = document.getElementById(btn.dataset.clearTarget);
            if (!input) return;
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        });
        // Initialize visibility
        var input = document.getElementById(btn.dataset.clearTarget);
        if (input) btn.hidden = !input.value.trim();
    });

    // Save branding
    document.getElementById('brandingForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var btn = document.getElementById('saveBrandingBtn');
        btn.disabled = true;
        btn.textContent = t('btn.saving');

        try {
            var settings = Object.assign({}, currentSettings || {});
            settings.favicon = document.getElementById('settingsFavicon').value.trim();
            settings.favicon_png = document.getElementById('settingsFaviconPng').value.trim();
            var displayRadio = document.querySelector('input[name="settingsLogoDisplay"]:checked');
            var sizeRadio = document.querySelector('input[name="settingsLogoSize"]:checked');
            settings.branding = {
                logo: document.getElementById('settingsLogo').value.trim(),
                logoDark: document.getElementById('settingsLogoDark').value.trim(),
                adminLogo: document.getElementById('settingsAdminLogo').value.trim(),
                name: document.getElementById('settingsName').value.trim(),
                showBranding: document.getElementById('settingsShowBranding').checked,
                logoDisplay: displayRadio ? displayRadio.value : 'both',
                logoSize: sizeRadio ? sizeRadio.value : 'medium'
            };
            settings.seo = Object.assign({}, settings.seo || {}, {
                defaultOgImage: document.getElementById('settingsDefaultOgImage').value.trim()
            });

            var formData = new FormData();
            formData.append('action', 'save-settings');
            formData.append('settings', JSON.stringify(settings));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                showToast(t('toast.branding_saved'), 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.save_branding');
        }
    });

    // Save theme
    document.getElementById('themeForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var btn = document.getElementById('saveThemeBtn');
        btn.disabled = true;
        btn.textContent = t('btn.saving');

        var primaryColor = document.getElementById('settingsPrimaryColor').value;
        var accentColor = document.getElementById('settingsAccentColor').value;

        if (!/^#[0-9a-fA-F]{6}$/.test(primaryColor) || !/^#[0-9a-fA-F]{6}$/.test(accentColor)) {
            showToast(t('settings.invalid_color'), 'error');
            btn.disabled = false;
            btn.textContent = t('settings.save_theme');
            return;
        }

        try {
            var settings = Object.assign({}, currentSettings || {});
            var contrastResult = sanitizeThemeContrast(readThemeFormState());
            settings.theme = contrastResult.theme;
            settings.dashboard = Object.assign({}, settings.dashboard || {}, {
                itemsPerPage: clampDashboardPageSize(document.getElementById('settingsItemsPerPage')?.value),
                iconManagerItemsPerPage: clampDashboardPageSize(document.getElementById('settingsIconItemsPerPage')?.value),
                mediaItemsPerPage: clampMediaPageSize(document.getElementById('settingsMediaItemsPerPage')?.value)
            });
            syncThemeFormColors(settings.theme);
            updateThemeContrastFeedback();

            var formData = new FormData();
            formData.append('action', 'save-settings');
            formData.append('settings', JSON.stringify(settings));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                applyTheme(currentSettings.theme);
                if (window.NbImageManager) {
                    NbImageManager.init({
                        itemsPerPage: clampMediaPageSize(currentSettings?.dashboard?.mediaItemsPerPage)
                    });
                    if (typeof NbImageManager.refresh === 'function') {
                        NbImageManager.refresh();
                    }
                }
                var serverTheme = sanitizeThemeContrast(currentSettings.theme || {});
                if (serverTheme.warnings.length) {
                    syncThemeFormColors(serverTheme.theme);
                    updateThemeContrastFeedback();
                    showToast(serverTheme.warnings[0], 'warning');
                } else if (contrastResult.warnings.length) {
                    updateThemeContrastFeedback();
                    showToast(contrastResult.warnings[0], 'warning');
                } else {
                    updateThemeContrastFeedback();
                    showToast(t('toast.theme_saved'), 'success');
                }
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.save_theme');
        }
    });

    // Resolve theme into light + dark color sets (dark falls back to light when empty)
    function resolveThemeColors(theme) {
        var light = {
            primary: theme.primaryColor || THEME_DEFAULTS.primaryColor,
            accent: theme.accentColor || THEME_DEFAULTS.accentColor,
            sidebar: theme.sidebarBg || ''
        };
        var dark = {
            primary: theme.darkPrimaryColor || light.primary,
            accent: theme.darkAccentColor || light.accent,
            sidebar: theme.darkSidebarBg || ''
        };
        if (!light.sidebar) light.sidebar = deriveSidebarLight(light.primary);
        if (!dark.sidebar) dark.sidebar = deriveSidebarDark(dark.primary);
        return { light: light, dark: dark };
    }

    // Inject a per-theme stylesheet; replaces the one we ship server-side on save/preview
    function injectThemeStyles(colors, glow) {
        var existing = document.getElementById('nb-theme-runtime');
        if (existing) existing.remove();
        var style = document.createElement('style');
        style.id = 'nb-theme-runtime';

        function block(selector, c) {
            var pcLight = adjustColor(c.primary, 30);
            var btnGradient = glow === false
                ? c.primary
                : 'radial-gradient(ellipse at 50% 0%, ' + pcLight + ' 0%, ' + c.primary + ' 70%)';
            var btnHover = glow === false
                ? adjustColor(c.primary, -15)
                : 'radial-gradient(ellipse at 50% 0%, ' + adjustColor(pcLight, 20) + ' 0%, ' + c.primary + ' 70%)';
            // Subtle/muted/medium tints derived from primary so hover/active
            // states pick up the user's branding instead of the static blue
            // defaults in nibbly-admin-tokens.css.
            var isDark = selector.indexOf('dark') !== -1;
            var subtleAlpha = isDark ? 0.12 : 0.08;
            var mutedAlpha = isDark ? 0.22 : 0.15;
            var mediumAlpha = isDark ? 0.38 : 0.30;
            var bg = isDark ? adjustColor(c.sidebar, 8) : adjustColor(c.sidebar, 8);
            var bgElevated = isDark ? adjustColor(c.sidebar, 18) : adjustColor(c.sidebar, 18);
            var bgSunken = isDark ? adjustColor(c.sidebar, -2) : adjustColor(c.sidebar, -6);
            var bgHover = isDark ? adjustColor(c.sidebar, 28) : adjustColor(c.sidebar, 4);
            var border = isDark ? adjustColor(c.sidebar, 42) : adjustColor(c.sidebar, -20);
            var borderStrong = isDark ? adjustColor(c.sidebar, 58) : adjustColor(c.sidebar, -34);
            return selector + ' {' +
                '--nb-primary: ' + c.primary + ';' +
                '--nb-primary-hover: ' + adjustColor(c.primary, -15) + ';' +
                '--nb-primary-active: ' + adjustColor(c.primary, -25) + ';' +
                '--nb-primary-subtle: ' + hexToRgba(c.primary, subtleAlpha) + ';' +
                '--nb-primary-muted: ' + hexToRgba(c.primary, mutedAlpha) + ';' +
                '--nb-primary-medium: ' + hexToRgba(c.primary, mediumAlpha) + ';' +
                '--nb-primary-btn: ' + btnGradient + ';' +
                '--nb-primary-btn-hover: ' + btnHover + ';' +
                '--nb-brand: ' + c.accent + ';' +
                '--nb-brand-light: ' + adjustColor(c.accent, 20) + ';' +
                '--nb-brand-dark: ' + adjustColor(c.accent, -20) + ';' +
                '--nb-brand-subtle: ' + hexToRgba(c.accent, isDark ? 0.18 : 0.12) + ';' +
                '--nb-sidebar-bg: ' + c.sidebar + ';' +
                '--nb-bg: ' + bg + ';' +
                '--nb-bg-elevated: ' + bgElevated + ';' +
                '--nb-bg-sunken: ' + bgSunken + ';' +
                '--nb-bg-hover: ' + bgHover + ';' +
                '--nb-border: ' + border + ';' +
                '--nb-border-strong: ' + borderStrong + ';' +
            '}';
        }

        style.textContent =
            block(':root, [data-site-theme="light"]', colors.light) +
            block('[data-site-theme="dark"]', colors.dark);
        document.head.appendChild(style);
    }

    // Apply theme live
    function applyTheme(theme) {
        var themeValue = theme.adminTheme || 'light';
        if (themeValue === 'system') {
            themeValue = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-site-theme', themeValue);
        localStorage.setItem('site-admin-theme', theme.adminTheme);

        var colors = resolveThemeColors(theme);
        injectThemeStyles(colors, theme.buttonGlow);

        // Button radius — affects both admin and frontend editor buttons
        if (theme.buttonRadius != null) {
            document.documentElement.style.setProperty('--editor-btn-radius', theme.buttonRadius + 'px');
        }

        // Flat button classes (glow disabled)
        document.documentElement.classList.toggle('editor-flat', theme.buttonGlow === false);
        document.documentElement.classList.toggle('nb-flat-buttons', theme.buttonGlow === false);

        updateBtnStylePreview();
    }

    function adjustColor(hex, amount) {
        hex = hex.replace('#', '');
        var r = Math.max(0, Math.min(255, parseInt(hex.substring(0, 2), 16) + amount));
        var g = Math.max(0, Math.min(255, parseInt(hex.substring(2, 4), 16) + amount));
        var b = Math.max(0, Math.min(255, parseInt(hex.substring(4, 6), 16) + amount));
        return '#' + [r, g, b].map(function(c) { return c.toString(16).padStart(2, '0'); }).join('');
    }

    function hexToRgba(hex, alpha) {
        hex = hex.replace('#', '');
        var r = parseInt(hex.substring(0, 2), 16);
        var g = parseInt(hex.substring(2, 4), 16);
        var b = parseInt(hex.substring(4, 6), 16);
        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
    }

    // Apply saved theme immediately on page load (server-rendered)
    applyTheme(<?php echo json_encode($siteSettings['theme'] ?? []); ?>);

    var aiSettingsForm = document.getElementById('aiSettingsForm');
    if (aiSettingsForm) {
        aiSettingsForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('saveAiSettingsBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');
            try {
                var formData = new FormData();
                formData.append('action', 'save-ai-settings');
                formData.append('settings', JSON.stringify(collectAiSettingsForm()));
                formData.append('csrf_token', CSRF_TOKEN);
                var configuredTimeout = parseInt(document.getElementById('aiRequestTimeout')?.value || '300', 10);
                var imageTimeoutMs = Math.max(300000, (Number.isFinite(configuredTimeout) ? configuredTimeout : 300) * 1000 + 30000);
                var result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, imageTimeoutMs);
                if (!result.success) throw new Error(result.message);
                currentAiSettings = result.data.settings;
                populateAiSettings(currentAiSettings);
                updateAiUsage(result.data.usage);
                showToast(t('ai.settings_saved'), 'success');
            } catch (error) {
                showToast(error.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('ai.save_settings');
            }
        });
    }

    var testAiBtn = document.getElementById('testAiBtn');
    if (testAiBtn) {
        testAiBtn.addEventListener('click', async function() {
            testAiBtn.disabled = true;
            testAiBtn.textContent = t('ai.testing');
            try {
                var formData = new FormData();
                formData.append('action', 'ai-test');
                formData.append('csrf_token', CSRF_TOKEN);
                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();
                if (!result.success) throw new Error(result.message);
                showToast(t('ai.connection_ok'), 'success');
                updateAiUsage(result.data.limits);
            } catch (error) {
                showToast(error.message, 'error');
            } finally {
                testAiBtn.disabled = false;
                testAiBtn.textContent = t('ai.test_connection');
            }
        });
    }

    var aiChatForm = document.getElementById('aiChatForm');
    if (aiChatForm) {
        var aiChatShortcutHint = document.querySelector('.ai-chat-shortcut-hint');
        if (aiChatShortcutHint) {
            var nav = window.navigator || {};
            var platform = (nav.userAgentData && nav.userAgentData.platform ? nav.userAgentData.platform : nav.platform || '').toLowerCase();
            var isMac = platform.indexOf('mac') !== -1 || platform.indexOf('iphone') !== -1 || platform.indexOf('ipad') !== -1;
            aiChatShortcutHint.textContent = isMac ? aiChatShortcutHint.dataset.macText : aiChatShortcutHint.dataset.otherText;
        }
        var aiChatPrompt = document.getElementById('aiChatPrompt');
        if (aiChatPrompt) {
            aiChatPrompt.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                if (e.metaKey || e.ctrlKey) {
                    var start = aiChatPrompt.selectionStart || 0;
                    var end = aiChatPrompt.selectionEnd || 0;
                    var value = aiChatPrompt.value;
                    aiChatPrompt.value = value.slice(0, start) + '\n' + value.slice(end);
                    aiChatPrompt.selectionStart = aiChatPrompt.selectionEnd = start + 1;
                    return;
                }
                if (aiChatPrompt.value.trim()) {
                    aiChatForm.requestSubmit();
                }
            });
        }
        aiChatForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var input = document.getElementById('aiChatPrompt');
            var prompt = input.value.trim();
            if (!prompt) return;
            input.value = '';
            aiChatMessages.push({ role: 'user', content: prompt });
            appendAiChat('user', prompt);
            var btn = aiChatForm.querySelector('button[type="submit"]');
            var indicator = document.getElementById('aiChatIndicator');
            btn.disabled = true;
            if (indicator) indicator.hidden = false;
            try {
                var formData = new FormData();
                formData.append('action', 'ai-chat');
                formData.append('messages', JSON.stringify(aiChatMessages.slice(-10)));
                formData.append('csrf_token', CSRF_TOKEN);
                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();
                if (!result.success) throw new Error(result.message);
                aiChatMessages.push({ role: 'assistant', content: result.data.text });
                appendAiChat('assistant', result.data.text);
                updateAiUsage(result.data.limits);
            } catch (error) {
                appendAiChat('error', error.message);
            } finally {
                btn.disabled = false;
                if (indicator) indicator.hidden = true;
            }
        });
    }

    var aiTextForm = document.getElementById('aiTextForm');
    if (aiTextForm) {
        aiTextForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var resultBox = document.getElementById('aiTextResult');
            var prompt = document.getElementById('aiTextPrompt').value.trim();
            if (!prompt) return;
            var btn = aiTextForm.querySelector('button[type="submit"]');
            btn.disabled = true;
            resultBox.value = t('ai.generating');
            try {
                var formData = new FormData();
                formData.append('action', 'ai-generate-text');
                formData.append('prompt', prompt);
                formData.append('maxOutputTokens', document.getElementById('aiTextMaxTokens').value);
                formData.append('csrf_token', CSRF_TOKEN);
                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();
                if (!result.success) throw new Error(result.message);
                resultBox.value = result.data.text;
                updateAiUsage(result.data.limits);
            } catch (error) {
                resultBox.value = error.message;
            } finally {
                btn.disabled = false;
            }
        });
    }

    var aiAuditRunButton = document.getElementById('aiAuditRun');
    if (aiAuditRunButton) {
        aiAuditRunButton.addEventListener('click', runAiContentAudit);
    }

    async function aiAuditPost(action, payload) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', CSRF_TOKEN);
        Object.keys(payload || {}).forEach(function(key) {
            formData.append(key, payload[key]);
        });
        var response = await fetch('api.php', { method: 'POST', body: formData, cache: 'no-store' });
        var result = await response.json();
        if (!result.success) throw new Error(result.message || 'Error');
        return result.data;
    }

    function aiAuditStatusBadge(row) {
        var key = 'ai.audit_status_' + row.descriptionStatus;
        var cls = row.descriptionStatus === 'ok' ? 'ai-audit-badge--ok' : 'ai-audit-badge--warn';
        return '<span class="ai-audit-badge ' + cls + '">' + escapeHtml(t(key)) + ' (' + row.descriptionLength + ')</span>';
    }

    async function runAiContentAudit() {
        var results = document.getElementById('aiAuditResults');
        if (!results) return;
        aiAuditRunButton.disabled = true;
        results.textContent = t('ai.audit_running');
        try {
            var data = await aiAuditPost('ai-content-audit', {});
            var pages = Array.isArray(data.pages) ? data.pages : [];
            if (!pages.length) {
                results.textContent = t('ai.audit_empty');
                return;
            }
            var html = '<table class="ai-audit-table"><thead><tr>'
                + '<th>' + escapeHtml(t('ai.audit_col_page')) + '</th>'
                + '<th>' + escapeHtml(t('ai.audit_col_description')) + '</th>'
                + '<th>' + escapeHtml(t('ai.audit_col_alt')) + '</th>'
                + '<th></th>'
                + '</tr></thead><tbody>';
            pages.forEach(function(row, index) {
                var needsDescription = row.descriptionStatus !== 'ok';
                html += '<tr data-audit-page="' + escapeHtml(row.contentPage) + '">'
                    + '<td><strong>' + escapeHtml(row.title) + '</strong><br><small>' + escapeHtml(row.contentPage) + '</small></td>'
                    + '<td data-audit-cell="description">' + aiAuditStatusBadge(row) + '</td>'
                    + '<td>' + (row.missingAlt > 0
                        ? '<span class="ai-audit-badge ai-audit-badge--warn">' + escapeHtml(t('ai.audit_alt_missing', { count: row.missingAlt })) + '</span>'
                        : '<span class="ai-audit-badge ai-audit-badge--ok">' + escapeHtml(t('ai.audit_status_ok')) + '</span>') + '</td>'
                    + '<td data-audit-cell="actions">' + (needsDescription
                        ? '<button type="button" class="btn btn-secondary btn-sm" data-audit-suggest="' + escapeHtml(row.contentPage) + '">' + escapeHtml(t('ai.audit_suggest')) + '</button>'
                        : '') + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table>';
            results.innerHTML = html;
            results.querySelectorAll('[data-audit-suggest]').forEach(function(button) {
                button.addEventListener('click', function() {
                    aiAuditSuggestDescription(button.getAttribute('data-audit-suggest'), button);
                });
            });
        } catch (error) {
            results.textContent = error.message;
        } finally {
            aiAuditRunButton.disabled = false;
        }
    }

    async function aiAuditSuggestDescription(contentPage, button) {
        var row = document.querySelector('[data-audit-page="' + (window.CSS && CSS.escape ? CSS.escape(contentPage) : contentPage) + '"]');
        if (!row) return;
        var actionsCell = row.querySelector('[data-audit-cell="actions"]');
        button.disabled = true;
        button.textContent = t('ai.generating');
        try {
            var data = await aiAuditPost('ai-content-audit-suggest', { contentPage: contentPage });
            updateAiUsage(data.limits);
            var descriptionCell = row.querySelector('[data-audit-cell="description"]');
            if (descriptionCell) {
                descriptionCell.innerHTML = '<em>' + escapeHtml(data.description) + '</em>';
            }
            actionsCell.innerHTML = '';
            var applyButton = document.createElement('button');
            applyButton.type = 'button';
            applyButton.className = 'btn btn-primary btn-sm';
            applyButton.textContent = t('ai.audit_apply');
            applyButton.addEventListener('click', function() {
                showModal(t('ai.audit_apply'), t('ai.audit_apply_confirm', { page: contentPage }), async function() {
                    closeModal();
                applyButton.disabled = true;
                try {
                    await aiAuditPost('ai-content-audit-apply', {
                        contentPage: contentPage,
                        description: data.description,
                        confirmed: '1'
                    });
                    actionsCell.innerHTML = '<span class="ai-audit-badge ai-audit-badge--ok">' + escapeHtml(t('ai.audit_applied')) + '</span>';
                } catch (error) {
                    applyButton.disabled = false;
                    showToast(error.message, 'error');
                }
                }, {
                    confirmText: t('ai.audit_apply'),
                    confirmClass: 'btn btn-primary'
                });
            });
            actionsCell.appendChild(applyButton);
        } catch (error) {
            button.disabled = false;
            button.textContent = t('ai.audit_suggest');
            showToast(error.message, 'error');
        }
    }

	    var aiImageForm = document.getElementById('aiImageForm');
	    var aiImageBusy = false;
	    var AI_IMAGE_REFERENCE_LIMIT = 16;
	    var aiImageReferences = [];
	    var aiImageHistoryOffset = 0;
	    var AI_IMAGE_HISTORY_LIMIT = 12;
	    var aiImageJobPollTimer = null;
	    var aiImageKnownFinishedJobs = new Set();
	    var aiImageRunningJobs = new Set();
	    var AI_IMAGE_JOB_NOTICE_STORAGE_KEY = 'nibbly.aiImageJobNotices.v1';
	    var AI_IMAGE_JOB_NOTICE_LIMIT = 80;

    function aiImageJobNoticeKey(job) {
        if (!job || !job.id) return '';
        return [job.id, job.status || '', job.finishedAt || job.updatedAt || ''].join(':');
    }

    function readAiImageJobNotices() {
        try {
            var raw = window.localStorage ? window.localStorage.getItem(AI_IMAGE_JOB_NOTICE_STORAGE_KEY) : '';
            var notices = raw ? JSON.parse(raw) : {};
            return notices && typeof notices === 'object' ? notices : {};
        } catch (error) {
            return {};
        }
    }

    function writeAiImageJobNotices(notices) {
        if (!window.localStorage) return;
        try {
            var entries = Object.entries(notices || {})
                .sort(function(a, b) { return Number(b[1] || 0) - Number(a[1] || 0); })
                .slice(0, AI_IMAGE_JOB_NOTICE_LIMIT);
            window.localStorage.setItem(AI_IMAGE_JOB_NOTICE_STORAGE_KEY, JSON.stringify(Object.fromEntries(entries)));
        } catch (error) {
            // Notification history is only a UI convenience.
        }
    }

    function aiImageJobNoticeWasShown(job) {
        var key = aiImageJobNoticeKey(job);
        if (!key) return false;
        return !!readAiImageJobNotices()[key];
    }

    function markAiImageJobNoticeShown(job) {
        var key = aiImageJobNoticeKey(job);
        if (!key) return;
        var notices = readAiImageJobNotices();
        notices[key] = Date.now();
        writeAiImageJobNotices(notices);
    }

    function setAiImageBusy(isBusy, activeButton, loadingTextKey) {
        aiImageBusy = isBusy;
        var buttons = [
            document.querySelector('#aiImageForm button[type="submit"]'),
            document.getElementById('aiImproveImagePrompt')
        ];
        buttons.forEach(function(button) {
            if (!button) return;
            button.disabled = isBusy || !dashboardAiImageUsable;
            if (button === activeButton) {
                if (isBusy) {
                    button.dataset.originalHtml = button.dataset.originalHtml || button.innerHTML;
                    button.classList.add('is-loading');
                    button.setAttribute('aria-busy', 'true');
                    button.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span>' + escapeHtml(t(loadingTextKey || 'ai.generating')) + '</span>';
                } else {
                    button.classList.remove('is-loading');
                    button.setAttribute('aria-busy', 'false');
                    if (button.dataset.originalHtml) {
                        button.innerHTML = button.dataset.originalHtml;
                        delete button.dataset.originalHtml;
                    }
                }
            }
        });
    }

    function renderAiImageResult(result, target) {
        if (!target || !result) return;
        var paths = Array.isArray(result.paths) ? result.paths : [result.path];
        paths = paths.filter(Boolean);
        if (!paths.length) {
            target.innerHTML = '<div class="ai-image-message ai-image-message--error" role="alert"><strong>Error</strong><span>' + escapeHtml(t('ai.image_history_error')) + '</span></div>';
            return;
        }
        target.innerHTML = '<div class="ai-image-gallery">' + paths.map(function(path) {
            var filename = (path || '').split('/').pop();
            return '<figure class="ai-image-figure"><button type="button" class="ai-image-preview-btn" data-ai-preview="' + escapeHtml(path) + '" data-ai-preview-name="' + escapeHtml(filename) + '"><img src="' + escapeHtml(path) + '" alt=""></button><figcaption><button type="button" class="btn btn-secondary btn-sm" onclick="switchTab(&quot;media&quot;)">' + escapeHtml(t('nav.media_library')) + '</button></figcaption></figure>';
        }).join('') + '</div>';
    }

    function setAiImageJobMessage(target, job, messageKey) {
        if (!target) return;
        var prompt = String((job && job.prompt) || '').trim();
        target.innerHTML = '<div class="ai-image-message" role="status">'
            + '<strong>' + escapeHtml(t(messageKey || 'ai.image_job_queued')) + '</strong>'
            + (prompt ? '<span class="ai-image-message__prompt">' + escapeHtml(prompt) + '</span>' : '')
            + '</div>';
    }

    function setAiImageJobsChecking(isChecking) {
        var button = document.getElementById('aiImageJobsCheck');
        if (!button) return;
        button.disabled = isChecking || !dashboardAiImageUsable;
        button.classList.toggle('is-loading', !!isChecking);
        button.setAttribute('aria-busy', isChecking ? 'true' : 'false');
    }

    function formatAiImageJobTime(value) {
        if (!value) return '';
        var date = new Date(value);
        return isNaN(date.getTime()) ? '' : date.toLocaleString();
    }

    function updateAiImageJobsPanel(jobs, isChecking) {
        var panel = document.getElementById('aiImageJobsPanel');
        var status = document.getElementById('aiImageJobsStatus');
        var meta = document.getElementById('aiImageJobsMeta');
        if (!panel || !status) return;
        panel.hidden = !dashboardAiImageUsable;
        setAiImageJobsChecking(!!isChecking);
        jobs = Array.isArray(jobs) ? jobs : [];
        var openJobs = jobs.filter(function(job) {
            return job && (job.status === 'queued' || job.status === 'running');
        });
        var latest = jobs[0] || null;
        if (openJobs.length) {
            status.textContent = t('ai.image_jobs_open', { count: openJobs.length });
            if (meta) {
                meta.hidden = false;
                meta.textContent = openJobs[0].prompt || '';
            }
            return;
        }
        if (latest && latest.status === 'success') {
            status.textContent = t('ai.image_jobs_finished');
            if (meta) {
                meta.hidden = false;
                meta.textContent = formatAiImageJobTime(latest.finishedAt || latest.updatedAt);
            }
            return;
        }
        if (latest && latest.status === 'error') {
            status.textContent = t('ai.image_jobs_failed');
            if (meta) {
                meta.hidden = false;
                meta.textContent = latest.error || formatAiImageJobTime(latest.finishedAt || latest.updatedAt);
            }
            return;
        }
        status.textContent = t('ai.image_jobs_idle');
        if (meta) {
            meta.hidden = true;
            meta.textContent = '';
        }
    }

    async function runAiImageJob(job) {
        if (!job || !job.id || aiImageRunningJobs.has(job.id)) return;
        aiImageRunningJobs.add(job.id);
        try {
            var formData = new FormData();
            formData.append('action', 'ai-image-job-run');
            formData.append('job_id', job.id);
            formData.append('csrf_token', CSRF_TOKEN);
            await fetch('api.php', { method: 'POST', body: formData, cache: 'no-store' });
        } finally {
            aiImageRunningJobs.delete(job.id);
        }
    }

    async function pollAiImageJobs(activeJobId, target, options) {
        options = options || {};
        updateAiImageJobsPanel(null, !!options.manual);
        try {
            var params = new URLSearchParams({
                action: 'ai-image-jobs',
                open_only: '0',
                limit: '30',
                csrf_token: CSRF_TOKEN
            });
            var response = await fetch('api.php?' + params.toString(), { cache: 'no-store' });
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Error');
            var jobs = Array.isArray(result.data.jobs) ? result.data.jobs : [];
            var openJobs = jobs.filter(function(job) {
                return job.status === 'queued' || job.status === 'running';
            });
            updateAiImageJobsPanel(jobs, false);
            var runningJobs = openJobs.filter(function(job) { return job.status === 'running'; });
            var queuedJobs = openJobs.filter(function(job) { return job.status === 'queued'; });
            if (!runningJobs.length && queuedJobs.length) {
                runAiImageJob(queuedJobs[queuedJobs.length - 1]);
            }

            jobs.forEach(function(job) {
                if (!job || !job.id || (job.status !== 'success' && job.status !== 'error')) return;
                var noticeAlreadyShown = aiImageKnownFinishedJobs.has(job.id) || aiImageJobNoticeWasShown(job);
                var isActiveJob = job.id === activeJobId;
                var isPassivePoll = !activeJobId && !target && !options.manual;
                aiImageKnownFinishedJobs.add(job.id);
                if (job.status === 'success') {
                    if (!noticeAlreadyShown && !isPassivePoll) {
                        showToast(t('ai.image_job_finished'), 'success');
                    }
                    if (isActiveJob && target) {
                        renderAiImageResult(job.result, target);
                        updateAiUsage(job.result && job.result.limits ? job.result.limits : null);
                    }
                    loadAiImageHistory(true);
                } else {
                    if (!noticeAlreadyShown && !isPassivePoll) {
                        showToast(job.error || t('ai.image_job_failed'), 'error');
                    }
                    if (isActiveJob && target) {
                        target.innerHTML = '<div class="ai-image-message ai-image-message--error" role="alert"><strong>Error</strong><span>' + escapeHtml(job.error || t('ai.image_job_failed')) + '</span></div>';
                    }
                    loadAiImageHistory(true);
                }
                markAiImageJobNoticeShown(job);
            });

            if (openJobs.length) {
                aiImageJobPollTimer = window.setTimeout(function() {
                    pollAiImageJobs(activeJobId, target);
                }, 12000);
            } else {
                aiImageJobPollTimer = null;
            }
        } catch (error) {
            setAiImageJobsChecking(false);
            aiImageJobPollTimer = window.setTimeout(function() {
                pollAiImageJobs(activeJobId, target);
            }, 15000);
        }
    }

    function startAiImageJobPolling(activeJobId, target, options) {
        if (aiImageJobPollTimer) {
            window.clearTimeout(aiImageJobPollTimer);
            aiImageJobPollTimer = null;
        }
        pollAiImageJobs(activeJobId || '', target || null, options || {});
    }

    document.getElementById('aiImageJobsCheck')?.addEventListener('click', function() {
        startAiImageJobPolling('', null, { manual: true });
    });

    if (aiImageForm) {
        aiImageForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var prompt = document.getElementById('aiImagePrompt').value.trim();
            if (!prompt || aiImageBusy) return;
            var target = document.getElementById('aiImageResult');
            var btn = aiImageForm.querySelector('button[type="submit"]');
            setAiImageBusy(true, btn, 'ai.generating');
            target.textContent = t('ai.generating');
            try {
                var formData = new FormData();
                formData.append('action', 'ai-generate-image');
                formData.append('prompt', prompt);
                formData.append('model', document.getElementById('aiImageModelPicker')?.value || document.getElementById('aiImageModel')?.value || '');
                formData.append('size', document.getElementById('aiImageSize').value);
                formData.append('aspectRatio', document.getElementById('aiImageRatio')?.value || 'auto');
                formData.append('imageScale', document.getElementById('aiImageScale')?.value || '2048');
                formData.append('count', document.getElementById('aiImageCount').value);
                formData.append('outputFormat', document.getElementById('aiImageFormat').value);
                formData.append('quality', document.getElementById('aiImageQuality').value);
                formData.append('moderation', document.getElementById('aiImageModeration').value);
                formData.append('outputCompression', document.getElementById('aiImageCompression').value);
	                aiImageReferences.slice(0, AI_IMAGE_REFERENCE_LIMIT).forEach(function(reference) {
	                    if (reference.type === 'file' && reference.file) {
	                        formData.append('referenceImages[]', reference.file, reference.name || reference.file.name || 'reference-image');
	                    } else if (reference.type === 'media' && reference.path) {
	                        formData.append('referenceMediaPaths[]', reference.path);
	                    }
	                });
                formData.append('filenameHint', aiImageFilenameHintValue());
                formData.append('csrf_token', CSRF_TOKEN);
                var result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, 45000);
                if (!result.success) throw new Error(result.message);
                if (result.data && result.data.job) {
                    setAiImageJobMessage(target, result.data.job, 'ai.image_job_queued');
                    runAiImageJob(result.data.job);
                    startAiImageJobPolling(result.data.job.id, target);
                } else {
                    renderAiImageResult(result.data, target);
                    updateAiUsage(result.data.limits);
                    loadAiImageHistory(true);
                }
            } catch (error) {
                var message = error && error.name === 'AbortError'
                    ? t('copilot.image_request_timeout')
                    : (error.message || t('toast.error'));
                target.innerHTML = '<div class="ai-image-message ai-image-message--error" role="alert"><strong>Error</strong><span>' + escapeHtml(message) + '</span></div>';
            } finally {
                setAiImageBusy(false, btn);
            }
        });
    }

    var aiImageResult = document.getElementById('aiImageResult');
    if (aiImageResult) {
        aiImageResult.addEventListener('click', function(e) {
            var trigger = e.target.closest('[data-ai-preview]');
            if (!trigger || !aiImageResult.contains(trigger)) return;
            if (window.NbImageManager && typeof NbImageManager.preview === 'function') {
                NbImageManager.preview(trigger.dataset.aiPreview, trigger.dataset.aiPreviewName || '');
            } else {
                window.open(trigger.dataset.aiPreview, '_blank', 'noopener');
            }
        });
    }

    function formatAiHistoryDate(value) {
        if (!value) return '';
        var date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleString();
    }

    function renderAiImageHistory(items, append, hasMore) {
        var container = document.getElementById('aiImageHistory');
        var list = document.getElementById('aiImageHistoryList');
        var more = document.getElementById('aiImageHistoryLoadMore');
        if (!container || !list) return;
        var safeItems = Array.isArray(items) ? items : [];
        if (!append) {
            list.innerHTML = '';
        }
        if (!append && !safeItems.length) {
            list.innerHTML = '<p class="ai-image-history-empty">' + escapeHtml(t('ai.image_history_empty')) + '</p>';
        } else {
            safeItems.forEach(function(item) {
                var outputs = Array.isArray(item.outputs) ? item.outputs : [];
                var firstOutput = outputs[0] || '';
                var prompt = item.prompt || '';
                var status = item.status === 'error' ? 'error' : 'success';
                var statusLabel = status === 'error' ? t('ai.image_history_status_error') : t('ai.image_history_status_success');
                var multiThumb = outputs.length > 1;
                var thumb;
                if (multiThumb) {
                    thumb = '<div class="ai-image-history-card__thumbs">' +
                        outputs.map(function(path) {
                            return '<button type="button" class="ai-image-history-card__thumb" data-ai-preview="' + escapeHtml(path) + '" data-ai-preview-name="' + escapeHtml(path.split('/').pop()) + '"><img src="' + escapeHtml(path) + '" alt=""></button>';
                        }).join('') +
                        '</div>';
                } else if (firstOutput) {
                    thumb = '<button type="button" class="ai-image-history-card__thumb" data-ai-preview="' + escapeHtml(firstOutput) + '" data-ai-preview-name="' + escapeHtml(firstOutput.split('/').pop()) + '"><img src="' + escapeHtml(firstOutput) + '" alt=""></button>';
                } else {
                    thumb = '<div class="ai-image-history-card__thumb ai-image-history-card__thumb--empty">' + escapeHtml(t('ai.image_history_error')) + '</div>';
                }
                var ratioMeta = item.aspectRatio && item.aspectRatio !== 'auto' ? item.aspectRatio : '';
                // Display pixel dimensions with a proper multiplication sign (e.g. 2560×1440).
                var sizeMeta = /^\d+x\d+$/.test(String(item.size || '')) ? String(item.size).replace('x', '×') : (item.size || '');
                var formatMeta = item.format ? String(item.format).toUpperCase() : '';
                var meta = [item.model, ratioMeta, sizeMeta, formatMeta, item.quality].filter(Boolean).join(' · ');
                var html =
                    '<article class="ai-image-history-card ai-image-history-card--' + status + (multiThumb ? ' ai-image-history-card--multi' : '') + '">' +
                        thumb +
                        '<div class="ai-image-history-card__body">' +
                            '<div class="ai-image-history-card__top">' +
                                '<span class="ai-image-history-status">' + escapeHtml(statusLabel) + '</span>' +
                                '<time>' + escapeHtml(formatAiHistoryDate(item.createdAt)) + '</time>' +
                            '</div>' +
                            '<p class="ai-image-history-prompt">' + escapeHtml(prompt || (item.error || '')) + '</p>' +
                            '<p class="ai-image-history-meta">' + escapeHtml(meta) + '</p>' +
                            '<div class="ai-image-history-actions">' +
                                '<button type="button" class="btn btn-secondary btn-sm" data-ai-history-prompt="' + escapeHtml(prompt) + '">' + escapeHtml(t('ai.image_history_use_prompt')) + '</button>' +
                                (firstOutput ? '<button type="button" class="btn btn-secondary btn-sm" onclick="switchTab(&quot;media&quot;)">' + escapeHtml(t('nav.media_library')) + '</button>' : '') +
                            '</div>' +
                        '</div>' +
                    '</article>';
                list.insertAdjacentHTML('beforeend', html);
            });
        }
        container.hidden = false;
        if (more) {
            more.hidden = !hasMore;
        }
    }

    async function loadAiImageHistory(reset) {
        if (!document.getElementById('aiImageHistoryList')) return;
        var offset = reset ? 0 : aiImageHistoryOffset;
        try {
            var params = new URLSearchParams({
                action: 'ai-image-history',
                offset: String(offset),
                limit: String(AI_IMAGE_HISTORY_LIMIT),
                csrf_token: CSRF_TOKEN
            });
            var response = await fetch('api.php?' + params.toString());
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Error');
            aiImageHistoryOffset = (result.data.offset || 0) + (Array.isArray(result.data.items) ? result.data.items.length : 0);
            renderAiImageHistory(result.data.items || [], !reset, !!result.data.hasMore);
        } catch (error) {
            showToast(error.message, 'error');
        }
    }

    var aiImageHistoryList = document.getElementById('aiImageHistoryList');
    if (aiImageHistoryList) {
        aiImageHistoryList.addEventListener('click', function(e) {
            var preview = e.target.closest('[data-ai-preview]');
            if (preview && aiImageHistoryList.contains(preview)) {
                if (window.NbImageManager && typeof NbImageManager.preview === 'function') {
                    NbImageManager.preview(preview.dataset.aiPreview, preview.dataset.aiPreviewName || '');
                } else {
                    window.open(preview.dataset.aiPreview, '_blank', 'noopener');
                }
                return;
            }
            var promptButton = e.target.closest('[data-ai-history-prompt]');
            if (promptButton && aiImageHistoryList.contains(promptButton)) {
                var promptField = document.getElementById('aiImagePrompt');
                if (promptField) {
                    promptField.value = promptButton.dataset.aiHistoryPrompt || '';
                    promptField.focus();
                }
            }
        });
    }

    var aiImageHistoryLoadMore = document.getElementById('aiImageHistoryLoadMore');
    if (aiImageHistoryLoadMore) {
        aiImageHistoryLoadMore.addEventListener('click', function() {
            loadAiImageHistory(false);
        });
    }

    var aiImageHistoryClear = document.getElementById('aiImageHistoryClear');
    if (aiImageHistoryClear) {
        aiImageHistoryClear.addEventListener('click', function() {
            showModal(t('ai.image_history_clear'), t('ai.image_history_clear_confirm'), async function() {
                closeModal();
            try {
                var formData = new FormData();
                formData.append('action', 'clear-ai-image-history');
                formData.append('csrf_token', CSRF_TOKEN);
                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();
                if (!result.success) throw new Error(result.message || 'Error');
                aiImageHistoryOffset = 0;
                renderAiImageHistory([], false, false);
                showToast(t('ai.image_history_cleared'), 'success');
            } catch (error) {
                showToast(error.message, 'error');
            }
            }, {
                confirmText: t('ai.image_history_clear'),
                confirmClass: 'btn btn-danger'
            });
        });
    }

    if (document.getElementById('aiImageHistoryList')) {
        loadAiImageHistory(true);
        startAiImageJobPolling('', null);
    }

	    var aiImageReference = document.getElementById('aiImageReference');
	    var aiImageReferenceUpload = document.getElementById('aiImageReferenceUpload');
	    var aiImageReferenceLibrary = document.getElementById('aiImageReferenceLibrary');
	    var aiImageReferenceClear = document.getElementById('aiImageReferenceClear');
	    if (aiImageReferenceUpload && aiImageReference) {
	        aiImageReferenceUpload.addEventListener('click', function() {
	            aiImageReference.click();
	        });
	        aiImageReference.addEventListener('change', function() {
	            addAiImageReferenceFiles(Array.from(aiImageReference.files || []));
	            aiImageReference.value = '';
	        });
	    }
	    if (aiImageReferenceLibrary) {
	        aiImageReferenceLibrary.addEventListener('click', function() {
	            if (!window.NbImageManager) return;
	            NbImageManager.open(function(paths) {
	                if (aiImageReference) aiImageReference.value = '';
	                addAiImageReferenceMedia(Array.isArray(paths) ? paths : [paths]);
	            }, aiImageReferences.filter(function(item) {
	                return item.type === 'media';
	            }).map(function(item) {
	                return item.path;
	            }), { types: ['image'], type: 'image', multiple: true });
	        });
	    }
	    if (aiImageReferenceClear) {
	        aiImageReferenceClear.addEventListener('click', function() {
	            if (aiImageReference) aiImageReference.value = '';
	            aiImageReferences = [];
	            updateAiImageReferences();
	        });
	    }

	    function addAiImageReferenceFiles(files) {
	        files.forEach(function(file) {
	            if (!file || aiImageReferences.length >= AI_IMAGE_REFERENCE_LIMIT) return;
	            aiImageReferences.push({
	                type: 'file',
	                file: file,
	                name: file.name || t('ai.image_reference_file')
	            });
	        });
	        updateAiImageReferences();
	    }

	    function addAiImageReferenceMedia(paths) {
	        paths.forEach(function(path) {
	            if (!path || aiImageReferences.length >= AI_IMAGE_REFERENCE_LIMIT) return;
	            path = String(path);
	            var duplicate = aiImageReferences.some(function(item) {
	                return item.type === 'media' && item.path === path;
	            });
	            if (duplicate) return;
	            aiImageReferences.push({
	                type: 'media',
	                path: path,
	                name: path.split('/').pop() || path
	            });
	        });
	        updateAiImageReferences();
	    }

	    function removeAiImageReference(index) {
	        aiImageReferences.splice(index, 1);
	        updateAiImageReferences();
	    }

	    function updateAiImageReferences() {
	        var label = document.getElementById('aiImageReferenceName');
	        var clear = document.getElementById('aiImageReferenceClear');
	        var list = document.getElementById('aiImageReferenceList');
	        var count = aiImageReferences.length;
	        if (label) {
	            label.textContent = count
	                ? t('ai.image_reference_count', { count: count, max: AI_IMAGE_REFERENCE_LIMIT })
	                : t('ai.image_reference_none');
	        }
	        if (clear) clear.hidden = count === 0;
	        if (!list) return;
	        list.hidden = count === 0;
	        list.innerHTML = aiImageReferences.map(function(reference, index) {
	            var preview = '';
	            if (reference.type === 'file' && reference.file) {
	                preview = URL.createObjectURL(reference.file);
	            } else if (reference.type === 'media') {
	                preview = reference.path;
	            }
	            return '<span class="ai-reference-chip">' +
	                (preview ? '<span class="ai-reference-chip__thumb" style="background-image:url(&quot;' + escapeHtml(preview) + '&quot;)"></span>' : '') +
	                '<span class="ai-reference-chip__name">' + escapeHtml(reference.name || t('ai.image_reference_file')) + '</span>' +
	                '<button type="button" class="ai-reference-chip__remove" data-reference-index="' + index + '" aria-label="' + escapeHtml(t('ai.image_reference_remove')) + '">&times;</button>' +
	            '</span>';
	        }).join('');
	    }

	    document.getElementById('aiImageReferenceList')?.addEventListener('click', function(e) {
	        var button = e.target.closest('[data-reference-index]');
	        if (!button) return;
	        removeAiImageReference(parseInt(button.dataset.referenceIndex, 10));
	    });

	    updateAiImageReferences();

    // Curated per-image prices (cents) for OpenAI image models, since the
    // OpenAI-compatible endpoint has no model price API. Declared before the
    // size picker initialises (which computes the estimated cost on load).
    var AI_OPENAI_IMAGE_COST_CENTS = {
        'gpt-image-2': 4
    };

    initAiImageSizePicker();
    document.getElementById('aiImageSize')?.addEventListener('change', updateAiImageRatioIcon);
    updateAiImageRatioIcon();

    var aiCompressionSlider = document.getElementById('aiImageCompression');
    var aiCompressionValue = document.getElementById('aiImageCompressionValue');
    var aiCompressionFill = document.getElementById('aiImageCompressionFill');
    if (aiCompressionSlider && aiCompressionValue && aiCompressionFill) {
        var syncAiCompression = function() {
            var pct = Math.max(0, Math.min(100, parseInt(aiCompressionSlider.value || '0', 10)));
            aiCompressionFill.style.width = pct + '%';
            aiCompressionValue.textContent = pct + '%';
            // Value sits inside the filled area; below 40% the fill is too
            // narrow, so flip the label to the empty (right) side.
            aiCompressionValue.classList.toggle('fill-slider__value--right', pct < 40);
        };
        aiCompressionSlider.addEventListener('input', syncAiCompression);
        syncAiCompression();
    }

    function initAiImageSizePicker() {
        var select = document.getElementById('aiImageSize');
        var ratioInput = document.getElementById('aiImageRatio');
        var trigger = document.getElementById('aiImageSizeTrigger');
        var menu = document.getElementById('aiImageSizeMenu');
        if (!select || !trigger || !menu) return;
        var options = [
            { group: t('ai.image_group_auto'), value: 'auto', ratio: 'auto', name: t('ai.image_size_auto') },
            { group: t('ai.image_group_square'), value: '1:1', ratio: '1:1', name: 'Square' },
            { group: t('ai.image_group_landscape'), value: '5:4', ratio: '5:4', name: 'Classic' },
            { group: t('ai.image_group_landscape'), value: '4:3', ratio: '4:3', name: 'Classic' },
            { group: t('ai.image_group_landscape'), value: '3:2', ratio: '3:2', name: 'Standard' },
            { group: t('ai.image_group_landscape'), value: '16:9', ratio: '16:9', name: 'Widescreen' },
            { group: t('ai.image_group_landscape'), value: '21:9', ratio: '21:9', name: 'Ultrawide' },
            { group: t('ai.image_group_portrait'), value: '4:5', ratio: '4:5', name: 'Classic' },
            { group: t('ai.image_group_portrait'), value: '3:4', ratio: '3:4', name: 'Traditional' },
            { group: t('ai.image_group_portrait'), value: '2:3', ratio: '2:3', name: 'Portrait' },
            { group: t('ai.image_group_portrait'), value: '9:16', ratio: '9:16', name: 'Social story' }
        ];
        var currentGroup = '';
        menu.innerHTML = options.map(function(option) {
            var groupHtml = '';
            if (option.group !== currentGroup) {
                currentGroup = option.group;
                groupHtml = '<div class="ai-size-group">' + escapeHtml(option.group) + '</div>';
            }
            return groupHtml + '<button type="button" class="ai-size-option" role="option" data-ratio="' + escapeHtml(option.value) + '">' +
                '<span class="ai-size-option-icon" data-ratio="' + escapeHtml(option.value) + '" aria-hidden="true"></span>' +
                '<span class="ai-size-option-ratio">' + escapeHtml(option.ratio) + '</span>' +
                '<span class="ai-size-option-name">' + escapeHtml(option.name) + '</span>' +
            '</button>';
        }).join('');
        menu.querySelectorAll('.ai-size-option-icon').forEach(function(iconEl) {
            applyAiRatioIcon(iconEl, iconEl.dataset.ratio || 'auto');
        });
        trigger.addEventListener('click', function() {
            if (trigger.disabled) return;
            var isOpen = !menu.hidden;
            menu.hidden = isOpen;
            trigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
        menu.addEventListener('click', function(e) {
            var option = e.target.closest('.ai-size-option');
            if (!option) return;
            if (ratioInput) ratioInput.value = option.dataset.ratio || 'auto';
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            updateAiImageRatioIcon();
        });
        document.addEventListener('click', function(e) {
            if (!menu.hidden && !document.getElementById('aiImageSizePicker')?.contains(e.target)) {
                menu.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
        updateAiImageRatioIcon();
    }

    function updateAiImageRatioIcon() {
        var select = document.getElementById('aiImageSize');
        var ratioInput = document.getElementById('aiImageRatio');
        var icon = document.getElementById('aiImageRatioIcon');
        var label = document.getElementById('aiImageSizeLabel');
        var menu = document.getElementById('aiImageSizeMenu');
        var scale = document.getElementById('aiImageScale');
        var note = document.getElementById('aiImageSizeNote');
        if (!select || !icon) return;
        var ratioValue = ratioInput ? ratioInput.value : 'auto';
        var computedSize = computeAiImageSize(ratioValue, scale ? scale.value : '2048');
        select.value = computedSize.size;
        if (label) {
            var selected = null;
            if (menu) {
                menu.querySelectorAll('.ai-size-option').forEach(function(option) {
                    if (option.dataset.ratio === ratioValue) selected = option;
                });
            }
            if (selected) {
                var ratioText = selected.querySelector('.ai-size-option-ratio')?.textContent || '';
                var nameText = selected.querySelector('.ai-size-option-name')?.textContent || '';
                label.innerHTML = ratioText === 'auto'
                    ? '<span>' + escapeHtml(nameText) + '</span>'
                    : '<span>' + escapeHtml(ratioText) + '</span><span>' + escapeHtml(nameText) + '</span>';
            } else {
                label.textContent = t('ai.image_size_auto');
            }
        }
        if (menu) {
            menu.querySelectorAll('.ai-size-option').forEach(function(option) {
                option.setAttribute('aria-selected', option.dataset.ratio === ratioValue ? 'true' : 'false');
            });
        }
        if (note) {
            var sizeText = computedSize.size === 'auto'
                ? t('ai.image_size_note_auto')
                : t('ai.image_size_note').replace('{size}', computedSize.size.replace('x', ' × '));
            note.textContent = sizeText + aiEstimatedImageCostSuffix();
        }
        applyAiRatioIcon(icon, computedSize.size === 'auto' ? ratioValue : computedSize.size);
    }

    // Detect the active provider from the saved settings only (not the
    // settings-form DOM), since cost resolution runs in the image generator
    // where the relevant provider is the configured one.
    function aiSavedProviderKey() {
        var settings = currentAiSettings || {};
        var provider = String(settings.provider || '').trim();
        var baseUrl = String(settings.baseUrl || '').trim();
        if (provider === 'anthropic' || baseUrl.indexOf('api.anthropic.com') !== -1) return 'anthropic';
        if (provider === 'kie' || baseUrl.indexOf('api.kie.ai') !== -1) return 'kie';
        if (provider === 'openrouter' || baseUrl.indexOf('openrouter.ai') !== -1) return 'openrouter';
        return 'openai-compatible';
    }

    // Resolve the estimated per-image cost for the selected provider/model.
    // Returns { cents, estimated } or null when no figure is available.
    function aiResolveImageCost() {
        var settings = currentAiSettings || {};
        var providerKey = aiSavedProviderKey();
        if (providerKey === 'anthropic') {
            return null; // Anthropic does not generate images.
        }
        var model = String(document.getElementById('aiImageModelPicker')?.value
            || document.getElementById('aiImageModel')?.value
            || settings.imageModel || '').trim();
        var configuredCents = parseInt((settings.pricing && settings.pricing.imageCentsPerRequest) || 0, 10);

        if (providerKey === 'openrouter') {
            var entry = aiOpenRouterModelsCache && Array.isArray(aiOpenRouterModelsCache.imageModels)
                ? aiOpenRouterModelsCache.imageModels.find(function(m) { return m.id === model; })
                : null;
            if (entry && entry.imageCostCents != null) {
                return { cents: entry.imageCostCents, estimated: !!entry.imageCostEstimated };
            }
            // Catalog not loaded yet or model not found: fall back to settings.
            return configuredCents ? { cents: configuredCents, estimated: true } : null;
        }

        if (providerKey === 'kie') {
            var kieCosts = {
                'gpt-image-2': 5,
                'nano-banana-2': 4,
                'seedream-5-0-pro': 5
            };
            if (kieCosts[model] != null) {
                return { cents: kieCosts[model], estimated: true };
            }
            return configuredCents ? { cents: configuredCents, estimated: true } : null;
        }

        // OpenAI-compatible: curated default for known models, else settings.
        if (AI_OPENAI_IMAGE_COST_CENTS[model] != null) {
            return { cents: AI_OPENAI_IMAGE_COST_CENTS[model], estimated: false };
        }
        return configuredCents ? { cents: configuredCents, estimated: false } : null;
    }

    // Format a (possibly fractional) cents value as EUR with enough precision
    // for sub-cent image prices: 2 decimals normally, more when very small.
    function formatAiImageCents(cents) {
        var eur = (parseFloat(cents) || 0) / 100;
        var decimals = eur >= 0.01 ? 2 : (eur >= 0.001 ? 3 : 4);
        return eur.toFixed(decimals) + ' EUR';
    }

    // " · Estimated cost per image: 0.05 EUR" (with a leading ~ when the figure
    // is an estimate). Empty when no figure is available.
    function aiEstimatedImageCostSuffix() {
        var cost = aiResolveImageCost();
        if (!cost || !cost.cents) return '';
        var amount = (cost.estimated ? '~' : '') + formatAiImageCents(cost.cents);
        return ' · ' + t('ai.image_cost_per_image').replace('{cost}', amount);
    }

    function applyAiRatioIcon(icon, value) {
        var match = String(value || '').match(/^(\d+)x(\d+)$/);
        if (!match) {
            match = String(value || '').match(/^(\d+):(\d+)$/);
        }
        if (!match) {
            icon.style.setProperty('--ratio-w', '1');
            icon.style.setProperty('--ratio-h', '1');
            icon.classList.add('ai-ratio-icon--auto');
            return;
        }
        icon.classList.remove('ai-ratio-icon--auto');
        var w = parseInt(match[1], 10);
        var h = parseInt(match[2], 10);
        var ratio = w / h;
        icon.style.setProperty('--ratio-w', ratio >= 1 ? Math.min(2.4, ratio) : 1);
        icon.style.setProperty('--ratio-h', ratio >= 1 ? 1 : Math.min(2.4, 1 / ratio));
    }

    function computeAiImageSize(ratioValue, scaleValue) {
        if (!ratioValue || ratioValue === 'auto') {
            return { size: 'auto' };
        }
        var parts = ratioValue.split(':').map(function(part) { return parseInt(part, 10); });
        if (parts.length !== 2 || !parts[0] || !parts[1]) {
            return { size: 'auto' };
        }
        var rw = parts[0];
        var rh = parts[1];
        var targetLong = parseInt(scaleValue, 10) || 2048;
        var ratio = Math.max(rw, rh) / Math.min(rw, rh);
        var minPixels = 655360;
        var maxPixels = 8294400;
        var longEdge = Math.min(3840, Math.max(16, targetLong));
        var minLongForPixels = Math.ceil(Math.sqrt(minPixels * ratio) / 16) * 16;
        longEdge = Math.max(longEdge, minLongForPixels);
        var shortEdge = Math.round((longEdge / ratio) / 16) * 16;
        if (shortEdge < 16) shortEdge = 16;
        while (longEdge * shortEdge > maxPixels && longEdge > 16) {
            longEdge -= 16;
            shortEdge = Math.round((longEdge / ratio) / 16) * 16;
        }
        while (longEdge * shortEdge < minPixels && longEdge < 3840) {
            longEdge += 16;
            shortEdge = Math.round((longEdge / ratio) / 16) * 16;
        }
        var width = rw >= rh ? longEdge : shortEdge;
        var height = rw >= rh ? shortEdge : longEdge;
        return { size: width + 'x' + height };
    }

    document.getElementById('aiImageScale')?.addEventListener('change', updateAiImageRatioIcon);

    var aiImageCountInput = document.getElementById('aiImageCount');
    var aiImageCountUp = document.getElementById('aiImageCountUp');
    var aiImageCountDown = document.getElementById('aiImageCountDown');
    if (aiImageCountInput) {
        aiImageCountInput.addEventListener('change', function() {
            updateAiImageCount(0);
        });
        aiImageCountInput.addEventListener('input', function() {
            updateAiImageCount(0);
        });
        updateAiImageCount(0);
    }
    if (aiImageForm) {
        aiImageForm.addEventListener('click', function(e) {
            if (e.target.closest('#aiImageCountUp')) {
                e.preventDefault();
                updateAiImageCount(1);
            } else if (e.target.closest('#aiImageCountDown')) {
                e.preventDefault();
                updateAiImageCount(-1);
            }
        });
    }

    function updateAiImageCount(delta) {
        var input = document.getElementById('aiImageCount');
        var button = document.getElementById('aiGenerateImageButton');
        if (!input) return;
        var value = parseInt(input.value, 10) || 1;
        value = Math.max(1, Math.min(10, value + delta));
        input.value = String(value);
        if (button && !button.dataset.originalHtml) {
            button.textContent = value === 1 ? t('ai.generate_image') : t('ai.generate_images');
        }
    }

    function aiImageFilenameSlug(value) {
        value = String(value || '').trim();
        if (!value) return '';
        value = value
            .replace(/[Ä]/g, 'Ae').replace(/[Ö]/g, 'Oe').replace(/[Ü]/g, 'Ue')
            .replace(/[ä]/g, 'ae').replace(/[ö]/g, 'oe').replace(/[ü]/g, 'ue')
            .replace(/[ß]/g, 'ss');
        try {
            value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (error) {}
        value = value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        return (value || 'ai-image').substring(0, 72).replace(/-+$/g, '') || 'ai-image';
    }

    function aiImageFilenameHintValue() {
        return String(document.getElementById('aiImageFilenameHint')?.value || '')
            .trim()
            .replace(/\.(png|jpe?g|webp)$/i, '');
    }

    document.getElementById('aiSuggestImageFilename')?.addEventListener('click', function() {
        var prompt = document.getElementById('aiImagePrompt')?.value || '';
        var input = document.getElementById('aiImageFilenameHint');
        if (!input) return;
        input.value = aiImageFilenameSlug(prompt);
        input.focus();
        input.select();
    });

    document.getElementById('aiImproveImagePrompt')?.addEventListener('click', async function() {
        var promptEl = document.getElementById('aiImagePrompt');
        var prompt = promptEl.value.trim();
        if (!prompt || aiImageBusy) return;
        var btn = this;
        setAiImageBusy(true, btn, 'ai.improving_prompt');
        try {
            var formData = new FormData();
            formData.append('action', 'ai-generate-text');
            formData.append('prompt', 'Improve this image generation prompt. Return only the improved prompt, no intro, no markdown. Keep the user intent and make it specific, visual, and concise:\\n\\n' + prompt);
            formData.append('maxOutputTokens', '350');
            formData.append('csrf_token', CSRF_TOKEN);
            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();
            if (!result.success) throw new Error(result.message);
            promptEl.value = String(result.data.text || '').trim();
            updateAiUsage(result.data.limits);
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            setAiImageBusy(false, btn);
        }
    });

    // ============================================================
    // SAVE LANGUAGE
    // ============================================================

    document.getElementById('languageForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var btn = document.getElementById('saveLanguageBtn');
        btn.disabled = true;
        btn.textContent = t('btn.saving');

        try {
            var settings = Object.assign({}, currentSettings || {});
            if (!settings.general) settings.general = {};
            settings.general.adminLanguage = document.getElementById('settingsAdminLanguage').value;

            var formData = new FormData();
            formData.append('action', 'save-settings');
            formData.append('settings', JSON.stringify(settings));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                showToast(t('toast.language_saved'), 'success');
                // Reload to apply new language
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.save_language');
        }
    });

    // ============================================================
    // SAVE LOGIN BEHAVIOUR
    // ============================================================

    var loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('saveLoginBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');

            try {
                var settings = Object.assign({}, currentSettings || {});
                if (!settings.general) settings.general = {};
                var modeRadio = document.querySelector('input[name="frontendLoginRedirect"]:checked');
                settings.general.frontendLoginRedirect = modeRadio ? modeRadio.value : 'auto';
                settings.login = {
                    brandAsset: document.getElementById('loginBrandAsset').value,
                    image: document.getElementById('loginImage').value.trim(),
                    imageLayout: document.getElementById('loginImageLayout').value,
                    overlayColor: document.getElementById('loginImageLayout').value === 'background'
                        ? document.getElementById('loginOverlayColor').value
                        : '',
                    overlayOpacity: document.getElementById('loginImageLayout').value === 'background'
                        ? parseInt(document.getElementById('loginOverlayOpacity').value, 10)
                        : 86,
                    boxStyle: document.getElementById('loginBoxStyle').value,
                    boxColor: document.getElementById('loginBoxStyle').value === 'card'
                        ? document.getElementById('loginBoxColor').value
                        : '',
                    boxTextColor: document.getElementById('loginBoxTextColor').value
                };

                var formData = new FormData();
                formData.append('action', 'save-settings');
                formData.append('settings', JSON.stringify(settings));
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    currentSettings = result.data;
                    showToast(t('toast.login_saved'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.save_login');
            }
        });
    }

    // ============================================================
    // ACCESS SETTINGS
    // ============================================================

    var accessForm = document.getElementById('accessForm');
    if (accessForm) {
        accessForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('saveAccessBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');

            try {
                var settings = Object.assign({}, currentSettings || {});
                settings.access = settings.access || {};
                settings.access.maintenance = {
                    enabled: document.getElementById('maintenanceEnabled').checked,
                    mode: document.getElementById('maintenanceMode').value,
                    title: document.getElementById('maintenanceTitle').value.trim(),
                    text: document.getElementById('maintenanceText').value.trim(),
                    until: document.getElementById('maintenanceUntil').value,
                    showCountdown: document.getElementById('maintenanceCountdown').checked,
                    brandAsset: document.getElementById('maintenanceBrandAsset').value,
                    image: document.getElementById('maintenanceImage').value.trim(),
                    imageLayout: document.getElementById('maintenanceImageLayout').value,
                    overlayColor: document.getElementById('maintenanceImageLayout').value === 'background'
                        ? document.getElementById('maintenanceOverlayColor').value
                        : '',
                    overlayOpacity: document.getElementById('maintenanceImageLayout').value === 'background'
                        ? parseInt(document.getElementById('maintenanceOverlayOpacity').value, 10)
                        : 88,
                    bypassParam: document.getElementById('maintenanceBypassParam').value.trim() || 'preview',
                    bypassKey: document.getElementById('maintenanceBypassKey').value
                };

                var formData = new FormData();
                formData.append('action', 'save-settings');
                formData.append('settings', JSON.stringify(settings));
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    currentSettings = result.data;
                    populateSettings(currentSettings);
                    showToast(t('toast.access_saved'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.save_access');
            }
        });
    }

    var moduleForm = document.getElementById('moduleForm');
    if (moduleForm) {
        moduleForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('saveModulesBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');

            try {
                var settings = Object.assign({}, currentSettings || {});
                var previousModules = JSON.stringify(settings.modules || {});
                settings.modules = settings.modules || {};
                settings.modules.ai = document.getElementById('moduleAi').checked;
                settings.modules.news = document.getElementById('moduleNews').checked;
                settings.modules.events = document.getElementById('moduleEvents').checked;
                settings.modules.messages = document.getElementById('moduleMessages').checked;
                settings.modules.iconManager = document.getElementById('moduleIconManager').checked;

                var formData = new FormData();
                formData.append('action', 'save-settings');
                formData.append('settings', JSON.stringify(settings));
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    currentSettings = result.data;
                    populateSettings(currentSettings);
                    showToast(t('toast.modules_saved'), 'success');
                    if (JSON.stringify(currentSettings.modules || {}) !== previousModules) {
                        setTimeout(function() { location.reload(); }, 800);
                    }
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.save_modules');
            }
        });
    }

    var privacyForm = document.getElementById('privacyForm');
    if (privacyForm) {
        privacyForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('savePrivacyBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');

            try {
                var settings = Object.assign({}, currentSettings || {});
                settings.privacy = settings.privacy || {};
                settings.privacy.emailObfuscation = document.getElementById('emailObfuscation').checked;
                settings.privacy.rememberPublicTheme = document.getElementById('rememberPublicTheme').checked;

                var formData = new FormData();
                formData.append('action', 'save-settings');
                formData.append('settings', JSON.stringify(settings));
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    currentSettings = result.data;
                    populateSettings(currentSettings);
                    showToast(t('toast.privacy_saved'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.save_privacy');
            }
        });
    }

    // ============================================================
    // EMAIL SETTINGS
    // ============================================================

    function toggleSmtpFields(method) {
        var smtpFields = document.getElementById('smtpFields');
        var sendmailHint = document.getElementById('sendmailHint');
        var emailFields = document.querySelectorAll('#settingsRecipientEmail, #settingsFromEmail, #settingsFromName');
        var inactiveHint = document.getElementById('emailInactiveHint');

        smtpFields.style.display = 'none';
        sendmailHint.style.display = 'none';
        if (inactiveHint) inactiveHint.style.display = 'none';

        // Show/hide all email config fields
        var fieldGroups = smtpFields.parentElement.querySelectorAll('.form-group');
        for (var i = 1; i < fieldGroups.length; i++) { // skip method dropdown
            fieldGroups[i].style.display = (method === 'inactive') ? 'none' : '';
        }
        smtpFields.style.display = (method === 'smtp') ? '' : 'none';

        if (method === 'sendmail') {
            sendmailHint.style.display = '';
        } else if (method === 'inactive') {
            if (inactiveHint) inactiveHint.style.display = '';
        }
    }

    document.getElementById('settingsEmailMethod').addEventListener('change', function() {
        toggleSmtpFields(this.value);
    });

    document.getElementById('settingsSmtpEncryption').addEventListener('change', function() {
        var portField = document.getElementById('settingsSmtpPort');
        if (this.value === 'ssl') portField.value = 465;
        else if (this.value === 'tls') portField.value = 587;
        else portField.value = 25;
    });

    function getEmailFormData() {
        return {
            method: document.getElementById('settingsEmailMethod').value,
            recipientEmail: document.getElementById('settingsRecipientEmail').value.trim(),
            bccEmail: document.getElementById('settingsBccEmail').value.trim(),
            fromEmail: document.getElementById('settingsFromEmail').value.trim(),
            fromName: document.getElementById('settingsFromName').value.trim(),
            smtpHost: document.getElementById('settingsSmtpHost').value.trim(),
            smtpPort: parseInt(document.getElementById('settingsSmtpPort').value, 10) || 587,
            smtpUsername: document.getElementById('settingsSmtpUsername').value.trim(),
            smtpPassword: document.getElementById('settingsSmtpPassword').value,
            smtpEncryption: document.getElementById('settingsSmtpEncryption').value
        };
    }

    document.getElementById('emailForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var btn = document.getElementById('saveEmailBtn');
        btn.disabled = true;
        btn.textContent = t('btn.saving');

        try {
            var emailData = getEmailFormData();
            var settings = Object.assign({}, currentSettings || {});
            settings.email = emailData;

            var formData = new FormData();
            formData.append('action', 'save-settings');
            formData.append('settings', JSON.stringify(settings));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                showToast(t('toast.email_saved'), 'success');
                // Update password placeholder to indicate saved
                if (emailData.smtpPassword || currentSettings.email?.smtpPassword) {
                    document.getElementById('settingsSmtpPassword').value = '';
                    document.getElementById('settingsSmtpPassword').placeholder = '••••••••';
                }
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.save_email');
        }
    });

    document.getElementById('testEmailBtn').addEventListener('click', async function() {
        var btn = this;
        var resultEl = document.getElementById('emailTestResult');
        btn.disabled = true;
        btn.textContent = t('settings.testing_email');
        resultEl.style.display = 'none';

        var emailData = getEmailFormData();
        if (!emailData.recipientEmail) {
            showToast(t('settings.recipient_required'), 'error');
            btn.disabled = false;
            btn.textContent = t('settings.test_email');
            return;
        }

        try {
            var formData = new FormData();
            formData.append('action', 'test-email');
            formData.append('emailConfig', JSON.stringify(emailData));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            resultEl.style.display = '';
            if (result.success) {
                resultEl.className = 'settings-test-result settings-test-result--success';
                resultEl.textContent = t('settings.test_email_success');
            } else {
                resultEl.className = 'settings-test-result settings-test-result--error';
                resultEl.textContent = result.message || t('settings.test_email_error');
            }
        } catch (error) {
            resultEl.style.display = '';
            resultEl.className = 'settings-test-result settings-test-result--error';
            resultEl.textContent = error.message;
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.test_email');
        }
    });

    // ============================================================
    // CHANGE PASSWORD
    // ============================================================

    (function() {
        var newPw = document.getElementById('newPassword');
        var confirmPw = document.getElementById('newPasswordConfirm');
        var form = document.getElementById('changePasswordForm');

        var reqs = {
            length:  function() { return newPw.value.length >= 8; },
            upper:   function() { return /[A-Z]/.test(newPw.value); },
            lower:   function() { return /[a-z]/.test(newPw.value); },
            digit:   function() { return /[0-9]/.test(newPw.value); },
            special: function() { return /[^A-Za-z0-9]/.test(newPw.value); },
            match:   function() { return newPw.value.length > 0 && newPw.value === confirmPw.value; }
        };

        function updateReqs() {
            for (var key in reqs) {
                var el = document.querySelector('#pwReqs [data-req="' + key + '"]');
                if (el) {
                    if (reqs[key]()) {
                        el.classList.add('met');
                        el.classList.remove('unmet');
                    } else {
                        el.classList.remove('met');
                        el.classList.add('unmet');
                    }
                }
            }
        }

        newPw.addEventListener('input', updateReqs);
        confirmPw.addEventListener('input', updateReqs);

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            var btn = document.getElementById('changePwBtn');
            btn.disabled = true;
            btn.textContent = t('btn.changing');

            try {
                var formData = new FormData();
                formData.append('action', 'change-password');
                formData.append('current_password', document.getElementById('currentPassword').value);
                formData.append('new_password', newPw.value);
                formData.append('new_password_confirm', confirmPw.value);
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    showToast(t('toast.password_changed'), 'success');
                    form.reset();
                    updateReqs();

                    // Remove password warning banner if present
                    var warning = document.getElementById('passwordWarning');
                    if (warning) warning.remove();
                    var adminMain = document.getElementById('adminMain');
                    if (adminMain) adminMain.classList.remove('has-security-warning');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.change_password');
            }
        });
    })();

    // ============================================================
    // SITE BACKUP
    // ============================================================

    (function() {
        var btn = document.getElementById('createSiteBackupBtn');
        var progress = document.getElementById('backupProgress');
        var progressText = document.getElementById('backupProgressText');

        btn.addEventListener('click', async function() {
            btn.disabled = true;
            btn.style.display = 'none';
            progress.style.display = 'flex';

            try {
                var formData = new FormData();
                formData.append('action', 'create-site-backup');
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    progressText.textContent = t('settings.backup_downloading');

                    var downloadUrl = 'api.php?action=download-site-backup'
                        + '&token=' + encodeURIComponent(result.data.token)
                        + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                        + '&filename=' + encodeURIComponent(result.data.filename);

                    var a = document.createElement('a');
                    a.href = downloadUrl;
                    a.download = result.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);

                    showToast(t('toast.backup_site_created'), 'success');
                } else {
                    showToast(t('toast.backup_site_failed', {message: result.message}), 'error');
                }
            } catch (error) {
                showToast(t('toast.backup_site_failed', {message: error.message}), 'error');
            }

            setTimeout(function() {
                btn.disabled = false;
                btn.style.display = '';
                progress.style.display = 'none';
                progressText.textContent = t('settings.backup_creating');
            }, 2000);
        });
    })();

    // ============================================================
    // RESTORE FROM BACKUP
    // ============================================================

    (function() {
        var fileInput = document.getElementById('restoreFileInput');
        var selectBtn = document.getElementById('restoreSelectBtn');
        var filenameEl = document.getElementById('restoreFilename');
        var modeSelector = document.getElementById('restoreModeSelector');
        var actionsEl = document.getElementById('restoreActions');
        var restoreBtn = document.getElementById('restoreBtn');
        var progress = document.getElementById('restoreProgress');
        var progressText = document.getElementById('restoreProgressText');

        selectBtn.addEventListener('click', function() {
            fileInput.click();
        });

        fileInput.addEventListener('change', function() {
            var file = fileInput.files[0];
            if (file) {
                filenameEl.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + ' MB)';
                filenameEl.style.display = '';
                selectBtn.querySelector('span').textContent = t('settings.restore_change_file');
                modeSelector.style.display = '';
                actionsEl.style.display = '';
            } else {
                filenameEl.style.display = 'none';
                selectBtn.querySelector('span').textContent = t('settings.restore_select_file');
                modeSelector.style.display = 'none';
                actionsEl.style.display = 'none';
            }
        });

        restoreBtn.addEventListener('click', function() {
            var file = fileInput.files[0];
            if (!file) return;

            var mode = document.querySelector('input[name="restore_mode"]:checked').value;
            var warningKey = mode === 'full' ? 'settings.restore_warning_full' : 'settings.restore_warning_content';

            showModal(t('settings.restore_title'), t(warningKey), async function() {
                closeModal();
                restoreBtn.disabled = true;
                restoreBtn.style.display = 'none';
                progress.style.display = 'flex';

                try {
                    var formData = new FormData();
                    formData.append('action', 'restore-site-backup');
                    formData.append('csrf_token', CSRF_TOKEN);
                    formData.append('restore_mode', mode);
                    formData.append('backup_zip', file);

                    var response = await fetch('api.php', { method: 'POST', body: formData });
                    var result = await response.json();

                    if (result.success) {
                        var toastKey = mode === 'full' ? 'toast.restore_success_full' : 'toast.restore_success_content';
                        showToast(t(toastKey, {extracted: result.data.extracted}), 'success');
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        showToast(t('toast.restore_failed', {message: result.message}), 'error');
                        restoreBtn.disabled = false;
                        restoreBtn.style.display = '';
                        progress.style.display = 'none';
                    }
                } catch (error) {
                    showToast(t('toast.restore_failed', {message: error.message}), 'error');
                    restoreBtn.disabled = false;
                    restoreBtn.style.display = '';
                    progress.style.display = 'none';
                }
            });
        });
    })();

    // ============================================================
    // SCHEDULED / AUTOMATED BACKUPS
    // ============================================================

    (function() {
        var statusEl       = document.getElementById('scheduledStatus');
        var lastRunEl      = document.getElementById('scheduledLastRun');
        var statusMsgEl    = document.getElementById('scheduledStatusMessage');
        var storageCountEl = document.getElementById('scheduledStorageCount');
        var storageDatesEl = document.getElementById('scheduledStorageDates');
        var form           = document.getElementById('scheduledBackupForm');
        var enabledEl      = document.getElementById('scheduledEnabled');
        var cronModeEls    = Array.from(document.querySelectorAll('input[name="scheduledCronMode"]'));
        var webCronUrlEl   = document.getElementById('webCronUrl');
        var dailyEl        = document.getElementById('retentionDaily');
        var weeklyEl       = document.getElementById('retentionWeekly');
        var monthlyEl      = document.getElementById('retentionMonthly');
        var yearlyEl       = document.getElementById('retentionYearly');
        var limitEl        = document.getElementById('storageLimitMb');
        var saveBtn        = document.getElementById('scheduledSaveBtn');
        var listBody       = document.getElementById('scheduledListBody');
        var cronCopyBtns   = Array.from(document.querySelectorAll('[data-copy-code]'));
        var remoteListEl   = document.getElementById('remoteTargetList');
        var remoteAddBtn   = document.getElementById('remoteAddBtn');
        var remoteSelectEl = document.getElementById('remoteProviderSelect');
        var remoteTargets  = [];
        var remoteProviders = {};
        var remoteFileCache = {};

        if (!statusEl) return; // Tab not on this dashboard

        var remoteFieldLabels = {
            app_key: t('settings.remote_field_app_key'),
            access_token: t('settings.remote_field_access_token'),
            refresh_token: t('settings.remote_field_refresh_token'),
            client_id: t('settings.remote_field_client_id'),
            client_secret: t('settings.remote_field_client_secret'),
            path: t('settings.remote_field_path'),
            folder_id: t('settings.remote_field_folder_id'),
            folder_path: t('settings.remote_field_folder_path'),
            host: t('settings.remote_field_host'),
            port: t('settings.remote_field_port'),
            username: t('settings.remote_field_username'),
            password: t('settings.remote_field_password'),
            remote_path: t('settings.remote_field_remote_path'),
            ssl: t('settings.remote_field_ssl'),
            passive: t('settings.remote_field_passive'),
            endpoint: t('settings.remote_field_endpoint'),
            region: t('settings.remote_field_region'),
            bucket: t('settings.remote_field_bucket'),
            prefix: t('settings.remote_field_prefix'),
            access_key: t('settings.remote_field_access_key'),
            secret_key: t('settings.remote_field_secret_key'),
            path_style: t('settings.remote_field_path_style'),
            url: t('settings.remote_field_url'),
            bearer_token: t('settings.remote_field_bearer_token')
        };

        var remotePlaceholders = {
            dropbox: { app_key: 'Dropbox app key', path: '/nibbly Backups' },
            google_drive: { client_id: 'Google OAuth client ID', client_secret: t('settings.remote_placeholder_optional'), folder_id: t('settings.remote_placeholder_optional') },
            onedrive: { client_id: 'Microsoft app client ID', client_secret: t('settings.remote_placeholder_optional'), folder_path: '/nibbly Backups' },
            sftp: { port: '22', remote_path: '/home/user/backups' },
            ftp: { port: '21', remote_path: 'backups', ssl: '0', passive: '1' },
            s3: { endpoint: 'https://s3.example.com', region: 'eu-central-1', prefix: 'nibbly', path_style: '0' },
            webdav: { url: 'https://cloud.example.com/remote.php/dav/files/user/backups' }
        };

        var remoteProviderHints = {
            sftp: t('settings.remote_hint_sftp'),
            ftp: t('settings.remote_hint_ftp')
        };

        var remoteDefaultSettings = {
            sftp: { port: '22' },
            ftp: { port: '21', passive: true }
        };

        function fmtSize(bytes) {
            if (!bytes) return '0 B';
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576)    return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024)       return (bytes / 1024).toFixed(1) + ' KB';
            return bytes + ' B';
        }
        function fmtDate(unixOrIso) {
            if (!unixOrIso) return '—';
            var d = (typeof unixOrIso === 'number') ? new Date(unixOrIso * 1000) : new Date(unixOrIso);
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleString();
        }
        function selectedCronMode() {
            var checked = cronModeEls.find(function(input) { return input.checked; });
            return checked ? checked.value : 'server';
        }
        function setCronMode(mode) {
            cronModeEls.forEach(function(input) {
                input.checked = input.value === (mode === 'web' ? 'web' : 'server');
            });
        }
        function webCronUrl(token) {
            if (!token) return '—';
            var url = new URL('../api/backup-cron.php', window.location.href);
            url.searchParams.set('token', token);
            return url.toString();
        }

        async function refresh() {
            try {
                var res = await fetch('api.php?action=backup-status');
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                var s = json.data;

                // Form fields
                enabledEl.checked = !!s.enabled;
                setCronMode(s.cron_mode || 'server');
                if (webCronUrlEl) webCronUrlEl.textContent = webCronUrl(s.web_cron_token || '');
                dailyEl.value     = s.retention.daily;
                weeklyEl.value    = s.retention.weekly;
                monthlyEl.value   = s.retention.monthly;
                yearlyEl.value    = s.retention.yearly;
                limitEl.value     = s.storage_limit_mb;
                remoteTargets     = s.remote_targets || [];
                remoteProviders   = s.remote_providers || {};
                renderRemoteTargets();

                // Last run + cron health
                if (s.last_run) {
                    lastRunEl.textContent = fmtDate(s.last_run);
                    var ageMs = Date.now() - new Date(s.last_run).getTime();
                    var ageDays = Math.floor(ageMs / 86400000);
                    if (s.last_status === 'error') {
                        statusEl.dataset.state = 'error';
                        statusMsgEl.textContent = t('settings.last_run_error', { message: s.last_message || '' });
                    } else if (s.enabled && ageDays >= 2) {
                        statusEl.dataset.state = 'warning';
                        statusMsgEl.textContent = t('settings.last_run_warning', { days: ageDays });
                    } else {
                        statusEl.dataset.state = 'ok';
                        statusMsgEl.textContent = t('settings.last_run_ok');
                    }
                } else {
                    lastRunEl.textContent = t('settings.last_run_never');
                    if (s.enabled) {
                        statusEl.dataset.state = 'warning';
                        statusMsgEl.textContent = t('settings.last_run_warning', { days: '∞' });
                    } else {
                        statusEl.dataset.state = 'idle';
                        statusMsgEl.textContent = '';
                    }
                }

                // Storage summary
                storageCountEl.textContent = t('settings.storage_count', {
                    count: s.count, size: fmtSize(s.total_bytes)
                });
                if (s.oldest && s.newest && s.count > 0) {
                    storageDatesEl.textContent = ' · '
                        + t('settings.storage_oldest', { date: fmtDate(s.oldest) })
                        + ' · '
                        + t('settings.storage_newest', { date: fmtDate(s.newest) });
                } else {
                    storageDatesEl.textContent = '';
                }

                // Backup list
                var listRes = await fetch('api.php?action=backup-list');
                var listJson = await listRes.json();
                if (!listJson.success) throw new Error(listJson.message);
                var backups = listJson.data.backups || [];
                renderList(backups);
            } catch (err) {
                statusEl.dataset.state = 'error';
                statusMsgEl.textContent = err.message || String(err);
            }
        }

        function renderList(backups) {
            if (backups.length === 0) {
                listBody.innerHTML = '<tr><td colspan="4" class="scheduled-list__empty">'
                    + t('settings.backup_list_empty') + '</td></tr>';
                return;
            }
            listBody.innerHTML = '';
            backups.forEach(function(b) {
                var tr = document.createElement('tr');

                var tdDate = document.createElement('td');
                tdDate.textContent = fmtDate(b.mtime);
                tr.appendChild(tdDate);

                var tdTier = document.createElement('td');
                var tierBadge = document.createElement('span');
                tierBadge.className = 'scheduled-tier scheduled-tier--' + b.tier;
                tierBadge.textContent = b.tier;
                tdTier.appendChild(tierBadge);
                tr.appendChild(tdTier);

                var tdSize = document.createElement('td');
                tdSize.textContent = fmtSize(b.size);
                tr.appendChild(tdSize);

                var tdActions = document.createElement('td');
                tdActions.className = 'scheduled-list__actions';

                var dlBtn = document.createElement('button');
                dlBtn.className = 'btn btn-secondary btn-sm';
                dlBtn.textContent = t('settings.backup_download');
                dlBtn.onclick = function() { downloadBackup(b.file); };
                tdActions.appendChild(dlBtn);

                var rsBtn = document.createElement('button');
                rsBtn.className = 'btn btn-secondary btn-sm';
                rsBtn.textContent = t('settings.backup_restore_from_pool');
                rsBtn.onclick = function() { restoreBackup(b.file); };
                tdActions.appendChild(rsBtn);

                var delBtn = document.createElement('button');
                delBtn.className = 'btn btn-danger btn-sm';
                delBtn.textContent = t('settings.backup_delete');
                delBtn.onclick = function() { deleteBackup(b.file); };
                tdActions.appendChild(delBtn);

                if (remoteTargets.length > 0) {
                    var upBtn = document.createElement('button');
                    upBtn.className = 'btn btn-secondary btn-sm';
                    upBtn.textContent = t('settings.remote_upload');
                    upBtn.onclick = function() { uploadBackupRemote(b.file, upBtn); };
                    tdActions.appendChild(upBtn);
                }

                tr.appendChild(tdActions);
                listBody.appendChild(tr);
            });
        }

        function targetLabel(type) {
            return (remoteProviders[type] && remoteProviders[type].label) || type;
        }

        function localizeRemoteMessage(message) {
            var text = message || '';
            var map = {
                'Dropbox access token is missing.': t('settings.remote_error_dropbox_access_token_missing'),
                'Google Drive access token is missing.': t('settings.remote_error_google_access_token_missing'),
                'OneDrive access token is missing.': t('settings.remote_error_onedrive_access_token_missing'),
                'PHP ssh2 extension is required for SFTP/SCP uploads.': t('settings.remote_error_ssh2_required'),
                'PHP FTP extension is required for FTP/FTPS uploads.': t('settings.remote_error_ftp_required')
            };
            return map[text] || text;
        }

        function makeTarget(type) {
            return {
                id: type + '-' + Date.now().toString(36),
                type: type,
                name: targetLabel(type),
                enabled: true,
                settings: Object.assign({}, remoteDefaultSettings[type] || {}),
                last_upload: null,
                last_status: null,
                last_message: null,
                last_file: null
            };
        }

        function collectRemoteTargets() {
            if (!remoteListEl) return [];
            return Array.from(remoteListEl.querySelectorAll('.backup-remote-target')).map(function(card) {
                var target = {
                    id: card.dataset.id,
                    type: card.dataset.type,
                    name: card.querySelector('[data-field="name"]').value.trim(),
                    enabled: card.querySelector('[data-field="enabled"]').checked,
                    settings: {},
                    last_upload: card.dataset.lastUpload || null,
                    last_status: card.dataset.lastStatus || null,
                    last_message: card.dataset.lastMessage || null,
                    last_file: card.dataset.lastFile || null
                };
                card.querySelectorAll('[data-setting]').forEach(function(input) {
                    target.settings[input.dataset.setting] = input.type === 'checkbox' ? input.checked : input.value;
                });
                return target;
            });
        }

        function renderRemoteTargets() {
            if (!remoteListEl) return;
            if (!remoteTargets.length) {
                remoteListEl.innerHTML = '<div class="backup-remote__empty">' + t('settings.remote_empty') + '</div>';
                return;
            }
            remoteListEl.innerHTML = '';
            remoteTargets.forEach(function(target, idx) {
                var provider = remoteProviders[target.type] || {};
                var oauthProvider = target.type === 'dropbox' || target.type === 'google_drive' || target.type === 'onedrive';
                var card = document.createElement('div');
                card.className = 'backup-remote-target';
                card.dataset.id = target.id;
                card.dataset.type = target.type;
                card.dataset.lastUpload = target.last_upload || '';
                card.dataset.lastStatus = target.last_status || '';
                card.dataset.lastMessage = target.last_message || '';
                card.dataset.lastFile = target.last_file || '';

                var header = document.createElement('div');
                header.className = 'backup-remote-target__header';
                var title = document.createElement('div');
                title.className = 'backup-remote-target__title';
                var enabled = document.createElement('input');
                enabled.type = 'checkbox';
                enabled.checked = !!target.enabled;
                enabled.dataset.field = 'enabled';
                title.appendChild(enabled);
                var name = document.createElement('input');
                name.type = 'text';
                name.value = target.name || targetLabel(target.type);
                name.dataset.field = 'name';
                title.appendChild(name);
                var badge = document.createElement('span');
                badge.textContent = targetLabel(target.type);
                title.appendChild(badge);
                header.appendChild(title);

                var actions = document.createElement('div');
                actions.className = 'backup-remote-target__actions';
                if (target.type === 'dropbox' || target.type === 'google_drive' || target.type === 'onedrive') {
                    var connectBtn = document.createElement('button');
                    connectBtn.type = 'button';
                    connectBtn.className = 'btn btn-secondary btn-sm';
                    connectBtn.textContent = target.type === 'dropbox'
                        ? t('settings.remote_connect_dropbox')
                        : (target.type === 'google_drive' ? t('settings.remote_connect_google') : t('settings.remote_connect_onedrive'));
                    connectBtn.onclick = function() { connectOAuthTarget(target.id, target.type); };
                    actions.appendChild(connectBtn);
                }
                var testBtn = document.createElement('button');
                testBtn.type = 'button';
                testBtn.className = 'btn btn-secondary btn-sm';
                testBtn.textContent = t('settings.remote_test');
                testBtn.onclick = function() { testRemoteTarget(target.id); };
                actions.appendChild(testBtn);
                var filesBtn = document.createElement('button');
                filesBtn.type = 'button';
                filesBtn.className = 'btn btn-secondary btn-sm';
                filesBtn.textContent = t('settings.remote_files');
                filesBtn.onclick = function() { loadRemoteFiles(target.id); };
                actions.appendChild(filesBtn);
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-danger btn-sm';
                removeBtn.textContent = t('btn.delete');
                removeBtn.onclick = function() {
                    remoteTargets.splice(idx, 1);
                    renderRemoteTargets();
                };
                actions.appendChild(removeBtn);
                header.appendChild(actions);
                card.appendChild(header);

                var grid = document.createElement('div');
                grid.className = 'backup-remote-target__grid';
                (provider.fields || []).forEach(function(field) {
                    if (oauthProvider && (field === 'access_token' || field === 'refresh_token')) return;
                    if (target.type === 'dropbox' && provider.has_global_oauth && field === 'app_key') return;
                    if ((target.type === 'google_drive' || target.type === 'onedrive') && provider.has_global_oauth && (field === 'client_id' || field === 'client_secret')) return;
                    var label = document.createElement('label');
                    if (field === 'path_style' || field === 'ssl' || field === 'passive') label.className = 'backup-remote-target__check';
                    var span = document.createElement('span');
                    span.textContent = remoteFieldLabels[field] || field;
                    label.appendChild(span);
                    var input = document.createElement('input');
                    input.dataset.setting = field;
                    if (field === 'path_style' || field === 'ssl' || field === 'passive') {
                        input.type = 'checkbox';
                        input.checked = !!(target.settings && target.settings[field]);
                    } else {
                        input.type = 'text';
                        input.value = (target.settings && target.settings[field]) || '';
                        input.placeholder = (remotePlaceholders[target.type] && remotePlaceholders[target.type][field]) || '';
                        input.name = 'nibbly_remote_' + target.type + '_' + field + '_' + target.id;
                        input.id = 'nibbly_remote_' + target.id + '_' + field;
                        input.autocomplete = 'off';
                        input.autocapitalize = 'off';
                        input.spellcheck = false;
                        input.setAttribute('data-lpignore', 'true');
                        input.setAttribute('data-1p-ignore', 'true');
                        input.setAttribute('data-form-type', 'other');
                        if ((provider.secret_fields || []).indexOf(field) !== -1) {
                            input.className = 'backup-remote-target__secret';
                        }
                    }
                    label.appendChild(input);
                    grid.appendChild(label);
                });
                card.appendChild(grid);

                if (remoteProviderHints[target.type]) {
                    var hint = document.createElement('p');
                    hint.className = 'form-hint backup-remote-target__hint';
                    hint.textContent = remoteProviderHints[target.type];
                    card.appendChild(hint);
                }

                var status = document.createElement('div');
                status.className = 'backup-remote-target__status';
                status.dataset.state = target.last_status || 'idle';
                status.textContent = target.last_upload
                    ? t('settings.remote_last_upload', { date: fmtDate(target.last_upload), message: localizeRemoteMessage(target.last_message || '') })
                    : t('settings.remote_not_tested');
                card.appendChild(status);
                if (remoteFileCache[target.id]) {
                    card.appendChild(renderRemoteFileList(target.id, remoteFileCache[target.id]));
                }
                remoteListEl.appendChild(card);
            });
        }

        function renderRemoteFileList(targetId, files) {
            var box = document.createElement('div');
            box.className = 'backup-remote-files';
            if (!files.length) {
                box.textContent = t('settings.remote_files_empty');
                return box;
            }
            files.forEach(function(file) {
                var row = document.createElement('div');
                row.className = 'backup-remote-file';
                var meta = document.createElement('div');
                meta.className = 'backup-remote-file__meta';
                var name = document.createElement('strong');
                name.textContent = file.file;
                var details = document.createElement('span');
                details.textContent = file.tier + ' · ' + fmtSize(file.size) + ' · ' + fmtDate(file.mtime);
                meta.appendChild(name);
                meta.appendChild(details);
                row.appendChild(meta);
                var actions = document.createElement('div');
                actions.className = 'backup-remote-file__actions';
                var importBtn = document.createElement('button');
                importBtn.type = 'button';
                importBtn.className = 'btn btn-secondary btn-sm';
                importBtn.textContent = t('settings.remote_import');
                importBtn.onclick = function() { importRemoteBackup(targetId, file.file); };
                actions.appendChild(importBtn);
                var deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'btn btn-danger btn-sm';
                deleteBtn.textContent = t('btn.delete');
                deleteBtn.onclick = function() { deleteRemoteBackup(targetId, file.file); };
                actions.appendChild(deleteBtn);
                row.appendChild(actions);
                box.appendChild(row);
            });
            return box;
        }

        async function saveRemoteSettingsOnly() {
            remoteTargets = collectRemoteTargets();
            var fd = new FormData();
            fd.append('action', 'backup-update-settings');
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('remote_targets', JSON.stringify(remoteTargets));
            var res = await fetch('api.php', { method: 'POST', body: fd });
            var json = await res.json();
            if (!json.success) throw new Error(json.message);
            remoteTargets = json.data.remote_targets || remoteTargets;
            remoteProviders = json.data.remote_providers || remoteProviders;
            renderRemoteTargets();
        }

        async function testRemoteTarget(targetId) {
            try {
                await saveRemoteSettingsOnly();
                var fd = new FormData();
                fd.append('action', 'backup-test-remote');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('target_id', targetId);
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                showToast(t('toast.remote_test_success'), 'success');
                refresh();
            } catch (err) {
                showToast(t('toast.remote_test_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
                refresh();
            }
        }

        async function connectOAuthTarget(targetId, type) {
            var popup = window.open('about:blank', '_blank');
            try {
                await saveRemoteSettingsOnly();
                var target = remoteTargets.find(function(tg) { return tg.id === targetId; });
                var requiredField = type === 'dropbox' ? 'app_key' : 'client_id';
                var provider = remoteProviders[type] || {};
                if (!target || !target.settings || (!target.settings[requiredField] && !provider.has_global_oauth)) {
                    if (popup) popup.close();
                    showToast(t(type === 'dropbox' ? 'toast.remote_dropbox_app_key_missing' : 'toast.remote_client_id_missing'), 'error');
                    return;
                }
                var action = type === 'dropbox'
                    ? 'backup-dropbox-oauth-start'
                    : (type === 'google_drive' ? 'backup-google-oauth-start' : 'backup-onedrive-oauth-start');
                var url = 'api.php?action=' + action
                    + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                    + '&target_id=' + encodeURIComponent(targetId);
                if (popup) {
                    popup.location = url;
                } else {
                    window.location.href = url;
                }
            } catch (err) {
                if (popup) popup.close();
                showToast(t('toast.remote_oauth_connect_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
            }
        }

        async function uploadBackupRemote(file, button) {
            var originalHtml = button ? button.innerHTML : '';
            var actionButtons = [];
            function setBusy(isBusy) {
                if (!button) return;
                var row = button.closest('tr');
                actionButtons = row ? Array.from(row.querySelectorAll('button')) : [button];
                actionButtons.forEach(function(btn) { btn.disabled = isBusy; });
                button.classList.toggle('is-loading', isBusy);
                button.setAttribute('aria-busy', isBusy ? 'true' : 'false');
                button.innerHTML = isBusy
                    ? '<span class="btn-spinner" aria-hidden="true"></span><span>' + t('settings.remote_uploading') + '</span>'
                    : originalHtml;
            }
            try {
                setBusy(true);
                await saveRemoteSettingsOnly();
                var enabledTargets = remoteTargets.filter(function(tg) { return tg.enabled; });
                if (!enabledTargets.length) {
                    showToast(t('toast.remote_no_enabled_targets'), 'error');
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'backup-upload-remote');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('file', file);
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                showToast(t('toast.remote_upload_success'), 'success');
                refresh();
            } catch (err) {
                showToast(t('toast.remote_upload_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
                refresh();
            } finally {
                setBusy(false);
            }
        }

        async function loadRemoteFiles(targetId) {
            try {
                var res = await fetch('api.php?action=backup-remote-list&target_id=' + encodeURIComponent(targetId));
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                remoteFileCache[targetId] = json.data.files || [];
                renderRemoteTargets();
            } catch (err) {
                showToast(t('toast.remote_list_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
            }
        }

        async function importRemoteBackup(targetId, file) {
            try {
                var fd = new FormData();
                fd.append('action', 'backup-remote-import');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('target_id', targetId);
                fd.append('file', file);
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                showToast(t('toast.remote_import_success'), 'success');
                refresh();
            } catch (err) {
                showToast(t('toast.remote_import_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
            }
        }

        function deleteRemoteBackup(targetId, file) {
            showModal(t('settings.remote_delete'), t('settings.remote_delete_confirm'), async function() {
                closeModal();
                try {
                    var fd = new FormData();
                    fd.append('action', 'backup-remote-delete');
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('target_id', targetId);
                    fd.append('file', file);
                    var res = await fetch('api.php', { method: 'POST', body: fd });
                    var json = await res.json();
                    if (!json.success) throw new Error(json.message);
                    showToast(t('toast.remote_delete_success'), 'success');
                    await loadRemoteFiles(targetId);
                } catch (err) {
                    showToast(t('toast.remote_delete_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
                }
            });
        }

        async function downloadBackup(file) {
            try {
                var fd = new FormData();
                fd.append('action', 'backup-prepare-download');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('file', file);
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                var url = 'api.php?action=download-site-backup'
                    + '&token=' + encodeURIComponent(json.data.token)
                    + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                    + '&filename=' + encodeURIComponent(json.data.filename);
                var a = document.createElement('a');
                a.href = url;
                a.download = json.data.filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            } catch (err) {
                showToast(t('toast.backup_site_failed', { message: err.message || String(err) }), 'error');
            }
        }

        function restoreBackup(file) {
            showModal(
                t('settings.restore_title'),
                '<div class="modal-choice-list">' +
                    '<label class="modal-choice">' +
                        '<input type="radio" name="restoreMode" value="content" checked>' +
                        '<span><strong>' + escapeHtml(t('settings.restore_content')) + '</strong><small>' + escapeHtml(t('settings.restore_content_desc')) + '</small></span>' +
                    '</label>' +
                    '<label class="modal-choice">' +
                        '<input type="radio" name="restoreMode" value="full">' +
                        '<span><strong>' + escapeHtml(t('settings.restore_full')) + '</strong><small>' + escapeHtml(t('settings.restore_full_desc')) + '</small></span>' +
                    '</label>' +
                '</div>',
                function() {
                    var selected = document.querySelector('input[name="restoreMode"]:checked');
                    var mode = selected ? selected.value : 'content';
                    closeModal();

                    var warningKey = mode === 'full'
                        ? 'settings.backup_restore_pool_warning_full'
                        : 'settings.backup_restore_pool_warning_content';

                    showModal(t('settings.restore_title'), t(warningKey), async function() {
                        closeModal();
                        try {
                            var fd = new FormData();
                            fd.append('action', 'restore-site-backup');
                            fd.append('csrf_token', CSRF_TOKEN);
                            fd.append('restore_mode', mode);
                            fd.append('pool_file', file);
                            var res = await fetch('api.php', { method: 'POST', body: fd });
                            var json = await res.json();
                            if (json.success) {
                                var toastKey = mode === 'full' ? 'toast.restore_success_full' : 'toast.restore_success_content';
                                showToast(t(toastKey, { extracted: json.data.extracted }), 'success');
                                setTimeout(function() { location.reload(); }, 2000);
                            } else {
                                showToast(t('toast.restore_failed', { message: json.message }), 'error');
                            }
                        } catch (err) {
                            showToast(t('toast.restore_failed', { message: err.message || String(err) }), 'error');
                        }
                    });
                },
                {
                    html: true,
                    confirmText: t('settings.restore_title'),
                    confirmClass: 'btn btn-primary'
                }
            );
        }

        function deleteBackup(file) {
            showModal(t('settings.backup_delete'), t('settings.backup_delete_confirm'), async function() {
                closeModal();
                try {
                    var fd = new FormData();
                    fd.append('action', 'backup-delete');
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('file', file);
                    var res = await fetch('api.php', { method: 'POST', body: fd });
                    var json = await res.json();
                    if (json.success) {
                        showToast(t('toast.backup_deleted'), 'success');
                        refresh();
                    } else {
                        showToast(t('toast.backup_delete_failed', { message: json.message }), 'error');
                    }
                } catch (err) {
                    showToast(t('toast.backup_delete_failed', { message: err.message || String(err) }), 'error');
                }
            });
        }

        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            saveBtn.disabled = true;
            try {
                var fd = new FormData();
                fd.append('action', 'backup-update-settings');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('enabled', enabledEl.checked ? 'true' : 'false');
                fd.append('cron_mode', selectedCronMode());
                fd.append('retention_daily', dailyEl.value);
                fd.append('retention_weekly', weeklyEl.value);
                fd.append('retention_monthly', monthlyEl.value);
                fd.append('retention_yearly', yearlyEl.value);
                fd.append('storage_limit_mb', limitEl.value);
                remoteTargets = collectRemoteTargets();
                fd.append('remote_targets', JSON.stringify(remoteTargets));
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (json.success) {
                    showToast(t('toast.scheduled_backup_settings_saved'), 'success');
                    refresh();
                } else {
                    showToast(t('toast.scheduled_backup_settings_failed', { message: json.message }), 'error');
                }
            } catch (err) {
                showToast(t('toast.scheduled_backup_settings_failed', { message: err.message || String(err) }), 'error');
            } finally {
                saveBtn.disabled = false;
            }
        });

        if (remoteAddBtn) {
            remoteAddBtn.addEventListener('click', function() {
                remoteTargets = collectRemoteTargets();
                remoteTargets.push(makeTarget(remoteSelectEl.value));
                renderRemoteTargets();
            });
        }

        window.addEventListener('message', function(event) {
            if (event.origin === window.location.origin && event.data && event.data.type === 'nibbly:backup-oauth') {
                refresh();
                showToast(t('toast.remote_oauth_connected'), 'success');
            }
        });

        cronCopyBtns.forEach(function(cronCopyBtn) {
            cronCopyBtn.addEventListener('click', function() {
                var codeEl = document.getElementById(cronCopyBtn.dataset.copyCode);
                if (!codeEl) return;
                var text = codeEl.textContent;
                var done = function() {
                    var orig = cronCopyBtn.textContent;
                    cronCopyBtn.textContent = t('settings.cron_copied');
                    setTimeout(function() { cronCopyBtn.textContent = orig; }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, done);
                } else {
                    // Fallback: select + execCommand
                    var range = document.createRange();
                    range.selectNode(codeEl);
                    window.getSelection().removeAllRanges();
                    window.getSelection().addRange(range);
                    try { document.execCommand('copy'); done(); } catch (e) { done(); }
                    window.getSelection().removeAllRanges();
                }
            });
        });

        // Refresh whenever the backup tab is shown (lazy: only fetch when visible).
        var backupTab = document.getElementById('backupTab');
        var observer = new MutationObserver(function() {
            if (backupTab.style.display !== 'none') refresh();
        });
        observer.observe(backupTab, { attributes: true, attributeFilter: ['style'] });
        if (backupTab.style.display !== 'none') refresh();

        // Refresh after a manual create-site-backup so the new entry shows up.
        var createBtn = document.getElementById('createSiteBackupBtn');
        if (createBtn) {
            createBtn.addEventListener('click', function() { setTimeout(refresh, 3000); });
        }
    })();

    // ============================================================
    // TOTAL RESET
    // ============================================================

    (function() {
        var input = document.getElementById('totalResetConfirm');
        var btn = document.getElementById('totalResetBtn');

        input.addEventListener('input', function() {
            btn.disabled = (input.value !== 'DELETE');
        });

        btn.addEventListener('click', async function() {
            if (input.value !== 'DELETE') {
                showToast(t('settings.total_reset_mismatch'), 'error');
                return;
            }

            btn.disabled = true;
            btn.textContent = '...';

            try {
                var formData = new FormData();
                formData.append('action', 'total-reset');
                formData.append('confirm', 'DELETE');
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    showToast(t('toast.total_reset_success'), 'success');
                    setTimeout(function() {
                        window.location.href = 'setup.php';
                    }, 1500);
                } else {
                    showToast(result.message, 'error');
                    btn.disabled = false;
                    btn.textContent = t('settings.total_reset_btn');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
                btn.disabled = false;
                btn.textContent = t('settings.total_reset_btn');
            }
        });
    })();

    // ============================================================
    // EVENTS EDITOR
    // ============================================================

    const SITE_LANGUAGES = <?php echo json_encode($siteLanguages); ?>;
    const DEFAULT_LANG = '<?php echo defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'; ?>';
    let eventsData = null;
    let eventsLoaded = false;
    let currentEventIndex = null;
    const EVENT_TRANSLATABLE = ['title', 'location', 'description', 'admission'];
    function adminLangCodes() {
        return Object.keys(SITE_LANGUAGES || {});
    }
    function adminLangLabel(code) {
        return String((SITE_LANGUAGES && SITE_LANGUAGES[code]) || code).toUpperCase();
    }
    function activeAdminLang(section) {
        return section.querySelector('.ce-lang-tab.active')?.dataset.lang || DEFAULT_LANG;
    }
    function readAdminLangField(fieldEl) {
        const input = fieldEl.querySelector('textarea, input:not([type="hidden"]), select');
        return input ? input.value || '' : '';
    }
    function writeAdminLangField(fieldEl, value) {
        const input = fieldEl.querySelector('textarea, input:not([type="hidden"]), select');
        if (!input) return;
        input.value = value == null ? '' : String(value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    async function translateAdminLanguageSection(section, button) {
        if (!AI_FEATURES_ENABLED || !section) return;
        const sourceLang = activeAdminLang(section);
        const targetLangs = adminLangCodes().filter(code => code !== sourceLang);
        const fields = {};
        section.querySelectorAll('[data-lang="' + CSS.escape(sourceLang) + '"][data-i18n-field]').forEach(fieldEl => {
            const value = readAdminLangField(fieldEl);
            if (value.trim() !== '') fields[fieldEl.dataset.i18nField] = value;
        });
        if (!Object.keys(fields).length) {
            showToast(t('editor.translate_no_source') || 'No source text to translate.', 'error');
            return;
        }
        const previous = button.textContent;
        button.disabled = true;
        button.textContent = t('editor.translating') || 'Translating...';
        try {
            const formData = new FormData();
            formData.append('action', 'ai-generate-text');
            formData.append('maxOutputTokens', '1800');
            formData.append('prompt', [
                'Translate these nibbly admin editor fields from ' + sourceLang + ' into the requested target languages.',
                'Return strict JSON only. Shape: {"translations":{"LANG":{"field":"translated value"}}}.',
                'Keep HTML tags if present. Do not add Markdown or explanations.',
                '',
                JSON.stringify({ sourceLang, targetLangs, fields: fields }, null, 2)
            ].join('\n'));
            formData.append('csrf_token', CSRF_TOKEN);
            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || t('toast.error'));
            let payload;
            try {
                payload = JSON.parse(String(result.data?.text || '').replace(/^```json\s*|\s*```$/g, '').trim());
            } catch (error) {
                throw new Error(t('editor.translate_invalid_response') || 'AI returned an unreadable translation.');
            }
            const translations = payload.translations || payload;
            targetLangs.forEach(lang => {
                Object.entries(translations[lang] || {}).forEach(([field, value]) => {
                    const fieldEl = section.querySelector('[data-lang="' + CSS.escape(lang) + '"][data-i18n-field="' + CSS.escape(field) + '"]');
                    if (fieldEl) writeAdminLangField(fieldEl, value);
                });
            });
            updateAiUsage(result.data ? result.data.limits : null);
            showToast(t('editor.translate_done') || 'Translations inserted.', 'success');
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            button.disabled = false;
            button.textContent = previous;
        }
    }

    async function loadEventsEditor() {
        try {
            const response = await fetch('api.php?action=load-events');
            const result = await response.json();
            if (result.success) {
                eventsData = result.data;
                renderEventsList();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading_events', {message: error.message}), 'error');
        }
    }

    function renderEventsList() {
        const tbody = document.getElementById('eventsListBody');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (eventsData && eventsData.lastModified) {
            const d = new Date(eventsData.lastModified);
            document.getElementById('eventsLastModified').textContent = t('editor.last_saved', {date: formatDateShort(d)});
        } else {
            document.getElementById('eventsLastModified').textContent = '';
        }

        const events = (eventsData && eventsData.events) || [];
        if (events.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="4" style="color: var(--nb-text-muted); text-align: center; padding: var(--nb-space-6);">${escapeHtml(t('events.no_events'))}</td>`;
            tbody.appendChild(tr);
            renderAdminListFooter('eventsListFooter', 'events', 0, getDashboardPageSize(), renderEventsList, 'eventsListFooterTop');
            return;
        }

        // Sort by date ascending
        events.sort((a, b) => (a.date || '').localeCompare(b.date || ''));
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(events, 'events', pageSize);
        renderAdminListFooter('eventsListFooter', 'events', events.length, pageSize, renderEventsList, 'eventsListFooterTop');

        const todayStr = new Date().toISOString().split('T')[0];

        paged.items.forEach((event) => {
            const index = events.indexOf(event);
            const title = event.title?.[DEFAULT_LANG] || event.title?.en || event.title?.de || t('events.untitled');
            const dateStr = event.date || '';
            const endDateStr = event['end-date'] || dateStr;

            // Status — upcoming / today / past
            let statusKey, statusClass;
            if (!dateStr) {
                statusKey = 'events.status_draft';
                statusClass = 'events-status events-status--draft';
            } else if (dateStr === todayStr || (dateStr <= todayStr && endDateStr >= todayStr)) {
                statusKey = 'events.status_today';
                statusClass = 'events-status events-status--today';
            } else if (dateStr > todayStr) {
                statusKey = 'events.status_upcoming';
                statusClass = 'events-status events-status--upcoming';
            } else {
                statusKey = 'events.status_past';
                statusClass = 'events-status events-status--past';
            }

            const tr = document.createElement('tr');
            tr.className = 'page-list-row';
            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';

            const titleLink = document.createElement('a');
            titleLink.href = '#';
            titleLink.className = 'page-list-title-link';
            titleLink.textContent = title;
            titleLink.onclick = (e) => { e.preventDefault(); openEventEditor(index); };
            tdTitle.appendChild(titleLink);

            // Hover action row
            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';

            const editLink = document.createElement('a');
            editLink.href = '#';
            editLink.className = 'page-list-row-action';
            editLink.innerHTML = icon('edit', 12, '2') + ' ' + t('pages.edit');
            editLink.onclick = (e) => { e.preventDefault(); openEventEditor(index); };
            actions.appendChild(editLink);

            if (event.url) {
                const sep1 = document.createElement('span');
                sep1.className = 'page-list-row-action-sep';
                sep1.textContent = '|';
                actions.appendChild(sep1);

                const viewLink = document.createElement('a');
                viewLink.href = event.url;
                viewLink.target = '_blank';
                viewLink.rel = 'noopener';
                viewLink.className = 'page-list-row-action';
                viewLink.innerHTML = icon('eye', 12, '2') + ' ' + t('pages.view');
                actions.appendChild(viewLink);
            }

            const sep2 = document.createElement('span');
            sep2.className = 'page-list-row-action-sep';
            sep2.textContent = '|';
            actions.appendChild(sep2);

            const dupLink = document.createElement('a');
            dupLink.href = '#';
            dupLink.className = 'page-list-row-action';
            dupLink.innerHTML = icon('duplicate', 12, '2') + ' ' + t('pages.duplicate');
            dupLink.onclick = (e) => { e.preventDefault(); duplicateEvent(index); };
            actions.appendChild(dupLink);

            const sep3 = document.createElement('span');
            sep3.className = 'page-list-row-action-sep';
            sep3.textContent = '|';
            actions.appendChild(sep3);

            const trashLink = document.createElement('a');
            trashLink.href = '#';
            trashLink.className = 'page-list-row-action page-list-row-action--danger';
            trashLink.innerHTML = icon('trash', 12, '2') + ' ' + t('pages.trash');
            trashLink.onclick = (e) => { e.preventDefault(); deleteEventDashboard(index); };
            actions.appendChild(trashLink);

            tdTitle.appendChild(actions);

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            tdDate.textContent = dateStr;

            const tdLocation = document.createElement('td');
            tdLocation.textContent = event.location?.[DEFAULT_LANG] || event.location?.en || event.location?.de || '';

            const tdStatus = document.createElement('td');
            tdStatus.className = 'page-list-cell-date';
            const statusBadge = document.createElement('span');
            statusBadge.className = statusClass;
            statusBadge.textContent = t(statusKey);
            tdStatus.appendChild(statusBadge);

            tr.appendChild(tdTitle);
            tr.appendChild(tdDate);
            tr.appendChild(tdLocation);
            tr.appendChild(tdStatus);
            tbody.appendChild(tr);
        });
    }

    function openEventEditor(index) {
        currentEventIndex = index;
        document.getElementById('eventsListView').style.display = 'none';
        document.getElementById('eventsEditorView').style.display = '';

        const event = eventsData.events[index];
        const title = event.title?.[DEFAULT_LANG] || event.title?.en || t('events.untitled');
        document.getElementById('eventEditorTitle').textContent = title;
        document.getElementById('eventEditorDeleteBtn').style.display = event.id ? '' : 'none';

        const body = document.getElementById('eventEditorBody');
        body.innerHTML = '';
        renderEventFields(body, event, index);
    }

    function closeEventEditor() {
        document.getElementById('eventsEditorView').style.display = 'none';
        document.getElementById('eventsListView').style.display = '';
        currentEventIndex = null;
    }

    function saveCurrentEvent() {
        if (currentEventIndex === null) return;
        saveEventDashboard(currentEventIndex);
    }

    function deleteCurrentEvent() {
        if (currentEventIndex === null) return;
        deleteEventDashboard(currentEventIndex);
    }

    function duplicateEvent(index) {
        const src = eventsData.events[index];
        const copy = JSON.parse(JSON.stringify(src));
        copy.id = ''; // unsaved → server assigns on save
        eventsData.events.push(copy);
        renderEventsList();
        openEventEditor(eventsData.events.length - 1);
    }

    function renderEventFields(container, eventObj, index) {
        const prefix = `events.${index}`;

        // Date/time row
        const dateRow = document.createElement('div');
        dateRow.className = 'ce-field-row';
        dateRow.innerHTML = `
            <div class="ce-field"><label class="ce-field-label">${t('events.start_date')}</label>
                <input type="date" class="ce-input" data-event-path="${prefix}.date" value="${escapeHtml(eventObj.date || '')}"></div>
            <div class="ce-field"><label class="ce-field-label">${t('events.start_time')}</label>
                <input type="time" class="ce-input" data-event-path="${prefix}.time" value="${escapeHtml(eventObj.time || '')}"></div>
            <div class="ce-field"><label class="ce-field-label">${t('events.end_date')}</label>
                <input type="date" class="ce-input" data-event-path="${prefix}.end-date" value="${escapeHtml(eventObj['end-date'] || '')}"></div>
            <div class="ce-field"><label class="ce-field-label">${t('events.end_time')}</label>
                <input type="time" class="ce-input" data-event-path="${prefix}.end-time" value="${escapeHtml(eventObj['end-time'] || '')}"></div>
        `;
        container.appendChild(dateRow);

        // URL
        const urlField = document.createElement('div');
        urlField.className = 'ce-field';
        urlField.innerHTML = `<label class="ce-field-label">URL</label>
            <input type="url" class="ce-input" data-event-path="${prefix}.url" value="${escapeHtml(eventObj.url || '')}">`;
        container.appendChild(urlField);

        // Image
        const imgField = document.createElement('div');
        imgField.className = 'ce-field';
        imgField.innerHTML = `<label class="ce-field-label">Image</label>
            <div class="ce-image-input-row">
                <input type="text" class="ce-input" data-event-path="${prefix}.image" value="${escapeHtml(eventObj.image || '')}">
                <button type="button" class="btn btn-secondary btn-sm ce-browse-btn">Browse</button>
            </div>`;
        const eventBrowseBtn = imgField.querySelector('.ce-browse-btn');
        const eventImgInput = imgField.querySelector('.ce-input');
        eventBrowseBtn.addEventListener('click', function() {
            browseImageForField(eventImgInput, null);
        });
        container.appendChild(imgField);

        // Translatable fields — language tabs
        const langSection = document.createElement('div');
        langSection.className = 'ce-lang-section';
        const langCodes = Object.keys(SITE_LANGUAGES);
        const isMultiLang = langCodes.length > 1;

        const tabsHtml = isMultiLang ? langCodes.map(code => {
            const isDefault = code === DEFAULT_LANG;
            return `<button type="button" class="ce-lang-tab${isDefault ? ' active' : ''}" role="tab" aria-selected="${isDefault ? 'true' : 'false'}" tabindex="${isDefault ? '0' : '-1'}" data-lang="${code}" data-event-idx="${index}">${escapeHtml(adminLangLabel(code))}${isDefault ? ' ★' : ''}</button>`;
        }).join('') : '';

        langSection.innerHTML = isMultiLang
            ? `<div class="ce-lang-header"><div class="ce-lang-tabs" role="tablist" aria-label="${escapeHtml(t('editor.language_tabs') || 'Languages')}">${tabsHtml}</div>${AI_FEATURES_ENABLED ? `<button type="button" class="btn btn-secondary btn-sm ce-lang-translate">${escapeHtml(t('editor.translate_from_active') || 'Translate from active language')}</button>` : ''}</div>`
            : '';

        langCodes.forEach(code => {
            const panel = document.createElement('div');
            panel.className = 'ce-lang-panel';
            if (code === DEFAULT_LANG) panel.classList.add('active');
            panel.setAttribute('role', 'tabpanel');
            panel.dataset.lang = code;
            panel.dataset.eventIdx = index;
            panel.hidden = isMultiLang && code !== DEFAULT_LANG;

            EVENT_TRANSLATABLE.forEach(field => {
                const val = eventObj[field]?.[code] || '';
                const isLong = field === 'description';
                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'ce-field';
                fieldDiv.dataset.lang = code;
                fieldDiv.dataset.i18nField = field;
                fieldDiv.innerHTML = `<label class="ce-field-label">${field.charAt(0).toUpperCase() + field.slice(1)}</label>`;

                if (isLong) {
                    const ta = document.createElement('textarea');
                    ta.className = 'ce-textarea';
                    ta.value = val;
                    ta.dataset.eventPath = `${prefix}.${field}.${code}`;
                    ta.rows = 3;
                    fieldDiv.appendChild(ta);
                } else {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'ce-input';
                    input.value = val;
                    input.dataset.eventPath = `${prefix}.${field}.${code}`;
                    fieldDiv.appendChild(input);
                }
                panel.appendChild(fieldDiv);
            });

            langSection.appendChild(panel);
        });

        container.appendChild(langSection);

        // Tab switching
        langSection.querySelectorAll('.ce-lang-tab').forEach((tab, tabIndex) => {
            const activateTab = (targetTab, focus = false) => {
                langSection.querySelectorAll('.ce-lang-tab').forEach(t => {
                    const active = t === targetTab;
                    t.classList.toggle('active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                    t.tabIndex = active ? 0 : -1;
                    if (active && focus) t.focus();
                });
                langSection.querySelectorAll('.ce-lang-panel').forEach(p => {
                    const active = p.dataset.lang === targetTab.dataset.lang;
                    p.hidden = !active;
                    p.classList.toggle('active', active);
                });
            };
            tab.addEventListener('click', () => {
                activateTab(tab);
            });
            tab.addEventListener('keydown', e => {
                const tabs = Array.from(langSection.querySelectorAll('.ce-lang-tab'));
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
                e.preventDefault();
                let next = tabIndex;
                if (e.key === 'ArrowLeft') next = tabIndex === 0 ? tabs.length - 1 : tabIndex - 1;
                if (e.key === 'ArrowRight') next = tabIndex === tabs.length - 1 ? 0 : tabIndex + 1;
                if (e.key === 'Home') next = 0;
                if (e.key === 'End') next = tabs.length - 1;
                activateTab(tabs[next], true);
            });
        });
        const translateBtn = langSection.querySelector('.ce-lang-translate');
        if (translateBtn) {
            translateBtn.addEventListener('click', function() {
                translateAdminLanguageSection(langSection, translateBtn);
            });
        }

        // Save button is in the editor header, not duplicated inline.
    }

    function collectEventData(index) {
        const prefix = `events.${index}`;
        const event = { id: eventsData.events[index].id };

        // Scalar fields
        ['date', 'time', 'end-date', 'end-time', 'url', 'image'].forEach(field => {
            const el = document.querySelector(`[data-event-path="${prefix}.${field}"]`);
            event[field] = el ? el.value : '';
        });

        // Translatable fields
        const langCodes = Object.keys(SITE_LANGUAGES);
        EVENT_TRANSLATABLE.forEach(field => {
            event[field] = {};
            langCodes.forEach(code => {
                const el = document.querySelector(`[data-event-path="${prefix}.${field}.${code}"]`);
                event[field][code] = el ? el.value : '';
            });
        });

        return event;
    }

    async function saveEventDashboard(index) {
        const event = collectEventData(index);

        if (!event.date || !event.title[DEFAULT_LANG]) {
            showToast(t('toast.events_date_required'), 'error');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'save-event');
            formData.append('event', JSON.stringify(event));
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                showToast(result.message || t('toast.events_saved'), 'success');
                // Reload data, then return to list view
                const reload = await fetch('api.php?action=load-events').then(r => r.json());
                if (reload.success) eventsData = reload.data;
                closeEventEditor();
                renderEventsList();
            } else {
                showToast(t('toast.error_generic', {message: result.message}), 'error');
            }
        } catch (error) {
            showToast(t('toast.error_saving', {message: error.message}), 'error');
        }
    }

    function addNewEvent() {
        const langCodes = Object.keys(SITE_LANGUAGES);
        const newEvent = {
            id: '',
            date: new Date().toISOString().split('T')[0],
            time: '',
            'end-date': '',
            'end-time': '',
            url: '',
            image: ''
        };
        EVENT_TRANSLATABLE.forEach(field => {
            newEvent[field] = {};
            langCodes.forEach(code => { newEvent[field][code] = ''; });
        });

        if (!eventsData) eventsData = { events: [], lastModified: null };
        eventsData.events.push(newEvent);
        renderEventsList();
        // Open the new event in the editor view immediately
        openEventEditor(eventsData.events.length - 1);
    }

    // Move event to trash (no confirm — symmetrical to Pages "move to trash")
    function deleteEventDashboard(index) {
        const event = eventsData.events[index];
        if (!event) return;

        if (!event.id) {
            // New unsaved event, just remove from array
            eventsData.events.splice(index, 1);
            if (currentEventIndex !== null) closeEventEditor();
            renderEventsList();
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete-event');
        formData.append('id', event.id);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('api.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    showToast(t('toast.event_trashed'), 'success');
                    if (currentEventIndex !== null) closeEventEditor();
                    loadEventsEditor();
                } else {
                    showToast(t('toast.error_generic', {message: result.message}), 'error');
                }
            })
            .catch(error => showToast(t('toast.error_generic', {message: error.message}), 'error'));
    }

    // ===== EVENTS TRASH =====

    async function showEventsTrash() {
        document.getElementById('eventsListView').style.display = 'none';
        document.getElementById('eventsEditorView').style.display = 'none';
        document.getElementById('eventsTrashView').style.display = '';
        await loadEventsTrash();
    }

    function closeEventsTrash() {
        document.getElementById('eventsTrashView').style.display = 'none';
        document.getElementById('eventsListView').style.display = '';
        // Reload main list — restore/permanent-delete may have changed the event set
        loadEventsEditor();
    }

    async function loadEventsTrash() {
        try {
            const response = await fetch('api.php?action=list-events-trash&_=' + Date.now());
            const result = await response.json();
            if (result.success) {
                renderEventsTrash(result.data);
            }
        } catch (error) {
            console.error('Error loading events trash:', error);
        }
    }

    function renderEventsTrash(items) {
        const tbody = document.getElementById('eventsTrashBody');
        const emptyMsg = document.getElementById('eventsTrashEmptyMsg');
        const emptyBtn = document.getElementById('emptyEventsTrashBtn');
        const table = document.getElementById('eventsTrashTable');
        tbody.innerHTML = '';

        if (!items || items.length === 0) {
            table.style.display = 'none';
            emptyMsg.style.display = 'block';
            emptyBtn.style.display = 'none';
            renderAdminListFooter('eventsTrashFooter', 'eventsTrash', 0, getDashboardPageSize(), function() {
                renderEventsTrash(items);
            }, 'eventsTrashFooterTop');
            return;
        }

        table.style.display = '';
        emptyMsg.style.display = 'none';
        emptyBtn.style.display = '';

        const pageSize = getDashboardPageSize();
        const paged = pageSlice(items, 'eventsTrash', pageSize);
        renderAdminListFooter('eventsTrashFooter', 'eventsTrash', items.length, pageSize, function() {
            renderEventsTrash(items);
        }, 'eventsTrashFooterTop');

        paged.items.forEach(item => {
            const ev = item.event || {};
            const title = ev.title?.[DEFAULT_LANG] || ev.title?.en || ev.title?.de || t('events.untitled');
            const tr = document.createElement('tr');

            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';
            tdTitle.textContent = title;
            tr.appendChild(tdTitle);

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            tdDate.textContent = ev.date || '';
            tr.appendChild(tdDate);

            const tdDeletedAt = document.createElement('td');
            tdDeletedAt.className = 'page-list-cell-date';
            if (item.deletedAt) {
                const d = new Date(item.deletedAt);
                tdDeletedAt.textContent = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            }
            tr.appendChild(tdDeletedAt);

            const tdActions = document.createElement('td');
            tdActions.className = 'page-list-cell-actions';

            const restoreBtn = document.createElement('button');
            restoreBtn.className = 'btn btn-primary btn-sm';
            restoreBtn.textContent = t('btn.restore');
            restoreBtn.onclick = async () => {
                restoreBtn.disabled = true;
                restoreBtn.textContent = '...';
                try {
                    const fd = new FormData();
                    fd.append('action', 'restore-event');
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('id', ev.id);
                    const r = await fetch('api.php', { method: 'POST', body: fd });
                    const res = await r.json();
                    if (res.success) {
                        showToast(t('toast.event_restored'), 'success');
                        loadEventsTrash();
                    } else {
                        showToast(res.message || 'Error', 'error');
                        restoreBtn.disabled = false;
                        restoreBtn.textContent = t('btn.restore');
                    }
                } catch (err) {
                    showToast(err.message, 'error');
                    restoreBtn.disabled = false;
                    restoreBtn.textContent = t('btn.restore');
                }
            };
            tdActions.appendChild(restoreBtn);

            const delBtn = document.createElement('button');
            delBtn.className = 'btn btn-danger btn-sm';
            delBtn.textContent = t('btn.delete');
            delBtn.onclick = () => {
                showModal(t('modal.delete_permanently'), t('events.confirm_delete_permanent', {title: title}), async () => {
                    closeModal();
                    try {
                        const fd = new FormData();
                        fd.append('action', 'delete-event-permanent');
                        fd.append('csrf_token', CSRF_TOKEN);
                        fd.append('id', ev.id);
                        const r = await fetch('api.php', { method: 'POST', body: fd });
                        const res = await r.json();
                        if (res.success) {
                            showToast(t('toast.event_deleted'), 'success');
                            loadEventsTrash();
                        } else {
                            showToast(res.message || 'Error', 'error');
                        }
                    } catch (err) {
                        showToast(err.message, 'error');
                    }
                });
            };
            tdActions.appendChild(delBtn);

            tr.appendChild(tdActions);
            tbody.appendChild(tr);
        });
    }

    async function emptyEventsTrash() {
        showModal(t('modal.empty_trash'), t('modal.empty_trash_confirm'), async () => {
            closeModal();
            try {
                const fd = new FormData();
                fd.append('action', 'empty-events-trash');
                fd.append('csrf_token', CSRF_TOKEN);
                const r = await fetch('api.php', { method: 'POST', body: fd });
                const res = await r.json();
                if (res.success) {
                    showToast(res.message || t('toast.trash_emptied'), 'success');
                    loadEventsTrash();
                } else {
                    showToast(res.message || 'Error', 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        });
    }

    // Sidebar toggle (mobile)
    document.getElementById('sidebarToggle').addEventListener('click', () => {
        document.getElementById('adminSidebar').classList.toggle('open');
    });

    // ============================================================
    // USER MANAGEMENT
    // ============================================================

    <?php if ($isAdminUser): ?>
    var CURRENT_USER_ID = <?php echo json_encode($_SESSION['admin_user_id'] ?? ''); ?>;

    function loadUsers() {
        fetch('api.php?action=list-users&csrf_token=' + encodeURIComponent(CSRF_TOKEN))
            .then(r => r.json())
            .then(result => {
                if (!result.success) return;
                renderUsersTable(result.data);
            });
    }

    function renderUsersTable(users) {
        var tbody = document.getElementById('usersTableBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        users.forEach(function(user) {
            var isCurrentUser = user.id === CURRENT_USER_ID;
            var tr = document.createElement('tr');
            if (isCurrentUser) tr.classList.add('users-table__current');
            var roleLabel = user.role.charAt(0).toUpperCase() + user.role.slice(1);
            tr.innerHTML =
                '<td>' + escapeHtml(user.username) + (isCurrentUser ? ' <em>(' + t('settings.user_you') + ')</em>' : '') + '</td>' +
                '<td>' + escapeHtml(user.email || '—') + '</td>' +
                '<td><span class="role-badge role-badge--' + user.role + '">' + roleLabel + '</span></td>' +
                '<td>' + (user.lastLogin ? new Date(user.lastLogin).toLocaleString() : '—') + '</td>' +
                '<td class="users-table__actions">' +
                    '<button class="btn btn-sm btn-secondary" onclick="editUser(\'' + user.id + '\')" title="' + t('pages.edit') + '">' + t('pages.edit') + '</button> ' +
                    '<button class="btn-icon" onclick="resetUserPassword(\'' + user.id + '\', \'' + escapeHtml(user.username) + '\')" title="' + t('settings.reset_password') + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></button> ' +
                    (isCurrentUser ? '' : '<button class="btn-icon btn-icon--danger" onclick="deleteUserConfirm(\'' + user.id + '\', \'' + escapeHtml(user.username) + '\')" title="' + t('btn.delete') + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>') +
                '</td>';
            tbody.appendChild(tr);
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function generatePassword() {
        var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var lower = 'abcdefghjkmnpqrstuvwxyz';
        var digits = '23456789';
        var special = '!@#$%&*+-=?';
        var all = upper + lower + digits + special;
        var arr = new Uint32Array(20);
        crypto.getRandomValues(arr);
        var pw = '';
        pw += upper[arr[0] % upper.length];
        pw += lower[arr[1] % lower.length];
        pw += digits[arr[2] % digits.length];
        pw += special[arr[3] % special.length];
        for (var i = 4; i < 16; i++) pw += all[arr[i] % all.length];
        var a = pw.split('');
        var s = new Uint32Array(a.length);
        crypto.getRandomValues(s);
        for (var j = a.length - 1; j > 0; j--) {
            var k = s[j] % (j + 1);
            var tmp = a[j]; a[j] = a[k]; a[k] = tmp;
        }
        return a.join('');
    }

    // Open add user modal
    document.getElementById('addUserBtn').addEventListener('click', function() {
        document.getElementById('userFormId').value = '';
        document.getElementById('userFormUsername').value = '';
        document.getElementById('userFormEmail').value = '';
        document.getElementById('userFormRole').value = 'editor';
        document.getElementById('userFormPassword').value = '';
        document.getElementById('userFormPasswordGroup').style.display = '';
        document.getElementById('userGeneratedPw').style.display = 'none';
        document.getElementById('userModalTitle').textContent = t('settings.add_user');
        document.getElementById('userFormPassword').required = true;
        closeAllComboboxes();
        document.getElementById('userModalOverlay').style.display = 'flex';
    });

    function closeUserModal() {
        document.getElementById('userModalOverlay').style.display = 'none';
    }

    // Generate password in user modal
    document.getElementById('userGenPwBtn').addEventListener('click', function() {
        var pw = generatePassword();
        document.getElementById('userFormPassword').value = pw;
        document.getElementById('userFormPassword').type = 'text';
        document.getElementById('userGeneratedPwText').textContent = pw;
        document.getElementById('userGeneratedPw').style.display = 'flex';
        setTimeout(function() {
            document.getElementById('userFormPassword').type = 'password';
        }, 30000);
    });

    // Edit user
    var _usersCache = [];
    function editUser(userId) {
        fetch('api.php?action=list-users&csrf_token=' + encodeURIComponent(CSRF_TOKEN))
            .then(r => r.json())
            .then(result => {
                if (!result.success) return;
                var user = result.data.find(u => u.id === userId);
                if (!user) return;
                document.getElementById('userFormId').value = user.id;
                document.getElementById('userFormUsername').value = user.username;
                document.getElementById('userFormEmail').value = user.email || '';
                document.getElementById('userFormRole').value = user.role;
                document.getElementById('userFormPassword').value = '';
                document.getElementById('userFormPasswordGroup').style.display = 'none';
                document.getElementById('userGeneratedPw').style.display = 'none';
                document.getElementById('userModalTitle').textContent = t('settings.edit_user');
                document.getElementById('userFormPassword').required = false;
                closeAllComboboxes();
                document.getElementById('userModalOverlay').style.display = 'flex';
            });
    }

    // Submit user form (add or edit)
    document.getElementById('userForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var userId = document.getElementById('userFormId').value;
        var isEdit = !!userId;

        var formData = new FormData();
        formData.append('action', isEdit ? 'update-user' : 'create-user');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('username', document.getElementById('userFormUsername').value);
        formData.append('email', document.getElementById('userFormEmail').value);
        formData.append('role', document.getElementById('userFormRole').value);
        if (isEdit) {
            formData.append('user_id', userId);
        } else {
            formData.append('password', document.getElementById('userFormPassword').value);
        }

        try {
            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();
            if (result.success) {
                closeUserModal();
                loadUsers();
                showToast(result.message, 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    });

    // Reset password for a user
    function resetUserPassword(userId, username) {
        document.getElementById('resetPwUserId').value = userId;
        document.getElementById('resetPwInput').value = '';
        document.getElementById('resetPwGenerated').style.display = 'none';
        document.getElementById('resetPwModalTitle').textContent = t('settings.reset_password') + ' — ' + username;
        // Reset requirement indicators
        document.querySelectorAll('#resetPwReqs .requirement').forEach(function(el) { el.classList.remove('met'); });
        closeAllComboboxes();
        document.getElementById('resetPwModalOverlay').style.display = 'flex';
        setTimeout(function() { document.getElementById('resetPwInput').focus(); }, 100);
    }

    function closeResetPwModal() {
        document.getElementById('resetPwModalOverlay').style.display = 'none';
    }

    // Generate password in reset modal
    document.getElementById('resetPwGenBtn').addEventListener('click', function() {
        var pw = generatePassword();
        document.getElementById('resetPwInput').value = pw;
        document.getElementById('resetPwInput').type = 'text';
        document.getElementById('resetPwGeneratedText').textContent = pw;
        document.getElementById('resetPwGenerated').style.display = 'flex';
        validatePasswordRequirements(pw, '#resetPwReqs');
        setTimeout(function() {
            document.getElementById('resetPwInput').type = 'password';
        }, 30000);
    });

    // Live validation for reset password
    document.getElementById('resetPwInput').addEventListener('input', function() {
        validatePasswordRequirements(this.value, '#resetPwReqs');
    });

    function validatePasswordRequirements(pw, containerSel) {
        var container = document.querySelector(containerSel);
        if (!container) return;
        var checks = {
            length: pw.length >= 8,
            upper: /[A-Z]/.test(pw),
            lower: /[a-z]/.test(pw),
            digit: /[0-9]/.test(pw),
            special: /[^A-Za-z0-9]/.test(pw)
        };
        Object.keys(checks).forEach(function(key) {
            var el = container.querySelector('[data-req="' + key + '"]');
            if (el) el.classList.toggle('met', checks[key]);
        });
    }

    // Submit reset password form
    document.getElementById('resetPwForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var userId = document.getElementById('resetPwUserId').value;
        var pw = document.getElementById('resetPwInput').value;

        var formData = new FormData();
        formData.append('action', 'admin-reset-password');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('user_id', userId);
        formData.append('password', pw);

        try {
            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();
            if (result.success) {
                closeResetPwModal();
                showToast(result.message, 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    });

    // Delete user
    function deleteUserConfirm(userId, username) {
        showModal(
            t('settings.delete_user'),
            t('settings.delete_user_confirm', {username: username}),
            function() {
                var formData = new FormData();
                formData.append('action', 'delete-user');
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('user_id', userId);

                fetch('api.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(result => {
                        if (result.success) {
                            closeModal();
                            loadUsers();
                            showToast(result.message, 'success');
                        } else {
                            showToast(result.message, 'error');
                        }
                    });
            }
        );
    }

    // Load users when the users panel becomes visible
    var _usersLoaded = false;
    var _menuOrderLoaded = false;
    // Watch for settings tab switches to load data on demand
    document.querySelectorAll('.settings-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (this.getAttribute('data-settings-action')) return;
            var tab = this.getAttribute('data-settings-tab');
            if (!tab) return;
            loadSettingsTabData(tab);
        });
    });

    // ============================================================
    // MENU ORDER
    // ============================================================

    var _menuOrderItems = [];

    var menuOrderSelect = document.getElementById('menuOrderSelect');
    var menuOrderList = document.getElementById('menuOrderList');
    var menuOrderEmpty = document.getElementById('menuOrderEmpty');
    var saveMenuOrderBtn = document.getElementById('saveMenuOrderBtn');

    if (menuOrderSelect) {
        menuOrderSelect.addEventListener('change', function() {
            loadMenuOrder();
        });
    }

    if (saveMenuOrderBtn) {
        saveMenuOrderBtn.addEventListener('click', function() {
            saveMenuOrder();
        });
    }

    async function loadMenuOrder() {
        var menuId = menuOrderSelect ? menuOrderSelect.value : '';
        if (!menuId) return;

        var defaultLang = '<?php echo defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'; ?>';

        try {
            var resp = await fetch('api.php?action=get-menu-items&menu=' + encodeURIComponent(menuId) + '&lang=' + encodeURIComponent(defaultLang));
            var result = await resp.json();
            if (result.success && result.data && result.data.items) {
                _menuOrderItems = result.data.items;
                renderMenuOrderList();
            } else {
                _menuOrderItems = [];
                renderMenuOrderList();
            }
        } catch (e) {
            showToast(t('toast.error'), 'error');
        }
    }

    function renderMenuOrderList() {
        if (!menuOrderList) return;

        if (_menuOrderItems.length === 0) {
            menuOrderList.style.display = 'none';
            menuOrderEmpty.style.display = 'block';
            if (saveMenuOrderBtn) saveMenuOrderBtn.disabled = true;
            return;
        }

        menuOrderList.style.display = 'block';
        menuOrderEmpty.style.display = 'none';
        if (saveMenuOrderBtn) saveMenuOrderBtn.disabled = false;

        var dragGripSvg = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11 18c0 1.1-.9 2-2 2s-2-.9-2-2 .9-2 2-2 2 .9 2 2zm-2-8c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0-6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm6 4c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>';

        var html = '';
        _menuOrderItems.forEach(function(item, i) {
            html += '<div class="menu-order-item" data-index="' + i + '" draggable="true">';
            html += '<span class="menu-order-item__drag-handle">' + dragGripSvg + '</span>';
            html += '<span class="menu-order-item__label">' + escapeHtml(item.label || item.page || '') + '</span>';
            html += '<span class="menu-order-item__slug">' + escapeHtml(item.page || '') + '</span>';
            html += '<span class="menu-order-item__actions">';
            html += '<button type="button" class="btn-icon" title="' + t('btn.move_up') + '"' + (i === 0 ? ' disabled' : '') + ' onclick="moveMenuItem(' + i + ', -1)">';
            html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>';
            html += '</button>';
            html += '<button type="button" class="btn-icon" title="' + t('btn.move_down') + '"' + (i === _menuOrderItems.length - 1 ? ' disabled' : '') + ' onclick="moveMenuItem(' + i + ', 1)">';
            html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>';
            html += '</button>';
            html += '</span>';
            html += '</div>';
        });
        menuOrderList.innerHTML = html;
        initMenuDragAndDrop();
    }

    function moveMenuItem(index, direction) {
        var newIndex = index + direction;
        if (newIndex < 0 || newIndex >= _menuOrderItems.length) return;
        var item = _menuOrderItems.splice(index, 1)[0];
        _menuOrderItems.splice(newIndex, 0, item);
        renderMenuOrderList();
    }

    // Drag and drop for menu order items
    var _menuDragIndex = null;

    function initMenuDragAndDrop() {
        var items = menuOrderList.querySelectorAll('.menu-order-item');
        items.forEach(function(el) {
            el.addEventListener('dragstart', function(e) {
                _menuDragIndex = parseInt(this.dataset.index);
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            el.addEventListener('dragend', function() {
                _menuDragIndex = null;
                this.classList.remove('dragging');
                items.forEach(function(item) {
                    item.classList.remove('drag-over-top', 'drag-over-bottom');
                });
            });
            el.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                var rect = this.getBoundingClientRect();
                var midY = rect.top + rect.height / 2;
                this.classList.remove('drag-over-top', 'drag-over-bottom');
                if (e.clientY < midY) {
                    this.classList.add('drag-over-top');
                } else {
                    this.classList.add('drag-over-bottom');
                }
            });
            el.addEventListener('dragleave', function() {
                this.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            el.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over-top', 'drag-over-bottom');
                var targetIndex = parseInt(this.dataset.index);
                if (_menuDragIndex === null || _menuDragIndex === targetIndex) return;

                var rect = this.getBoundingClientRect();
                var midY = rect.top + rect.height / 2;
                var insertBefore = e.clientY < midY;

                var item = _menuOrderItems.splice(_menuDragIndex, 1)[0];
                var newIndex = insertBefore ? targetIndex : targetIndex + 1;
                if (_menuDragIndex < targetIndex) newIndex--;
                _menuOrderItems.splice(newIndex, 0, item);
                renderMenuOrderList();
            });
        });
    }

    async function saveMenuOrder() {
        var menuId = menuOrderSelect ? menuOrderSelect.value : '';
        if (!menuId) return;

        var defaultLang = '<?php echo defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'; ?>';
        var order = _menuOrderItems.map(function(item) { return item.page || ''; }).filter(Boolean);

        var formData = new FormData();
        formData.append('action', 'save-menu-order');
        formData.append('menu', menuId);
        formData.append('lang', defaultLang);
        formData.append('order', JSON.stringify(order));
        formData.append('csrf_token', CSRF_TOKEN);

        try {
            var resp = await fetch('api.php', { method: 'POST', body: formData });
            var result = await resp.json();
            if (result.success) {
                showToast(t('settings.menu_order_saved'), 'success');
            } else {
                showToast(result.message || t('toast.error'), 'error');
            }
        } catch (e) {
            showToast(t('toast.error'), 'error');
        }
    }

    <?php endif; ?>

    </script>
    <?php if ($_aiDashboardCopilotAvailable && is_file(__DIR__ . '/../js/ai-copilot.js')): ?>
    <script src="../js/ai-copilot.js?v=<?php echo @filemtime(__DIR__ . '/../js/ai-copilot.js') ?: time(); ?>"></script>
    <?php endif; ?>
</body>
</html>
