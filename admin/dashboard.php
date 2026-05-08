<?php
/**
 * Admin Dashboard - Content Editor
 */

session_start();
require_once 'config.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/lang/i18n.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/../includes/asset-helpers.php';
ensureUsersFile();

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Session timeout check
if (time() - $_SESSION['admin_login_time'] > SESSION_LIFETIME) {
    session_destroy();
    header('Location: index.php?timeout=1');
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$csrfToken = $_SESSION['csrf_token'];
$userRole = $_SESSION['admin_role'] ?? 'admin'; // backward compat: old sessions default to admin
$isAdminUser = ($userRole === 'admin');

// Load settings for theme
$_defaultFavicon = defined('NIBBLY_DEFAULT_FAVICON') ? NIBBLY_DEFAULT_FAVICON : '/assets/images/favicon.svg';
$siteSettings = ['favicon' => $_defaultFavicon, 'favicon_png' => '', 'branding' => ['logo' => '', 'logoDark' => '', 'adminLogo' => '', 'name' => '', 'showBranding' => true, 'logoDisplay' => 'both', 'logoSize' => 'medium'], 'theme' => ['adminTheme' => 'light', 'primaryColor' => '#2563eb', 'accentColor' => '#60a5fa', 'sidebarBg' => '', 'darkPrimaryColor' => '', 'darkAccentColor' => '', 'darkSidebarBg' => '', 'buttonGlow' => true, 'buttonRadius' => 6]];
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

// SVG icon helper — keeps inline SVG paths in one place
function nbIcon(string $name, int $size = 16, string $strokeWidth = '1.5'): string {
    static $paths = [
        'hamburger' => '<path d="M3 12h18M3 6h18M3 18h18"/>',
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
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="../css/image-manager.css">
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
        : "color-mix(in srgb, {$_pcLight} 8%, white)";
    $_pcDark = !empty($_t['darkPrimaryColor']) ? htmlspecialchars($_t['darkPrimaryColor']) : $_pcLight;
    $_acDark = !empty($_t['darkAccentColor']) ? htmlspecialchars($_t['darkAccentColor']) : $_acLight;
    $_sbDark = !empty($_t['darkSidebarBg'])
        ? htmlspecialchars($_t['darkSidebarBg'])
        : "color-mix(in srgb, {$_pcDark} 8%, #050505)";
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
        --nb-brand-dark: color-mix(in srgb, <?php echo $_acLight; ?> 80%, black);
        --nb-sidebar-bg: <?php echo $_sbLight; ?>;
    }
    [data-site-theme="dark"] {
        --nb-primary: <?php echo $_pcDark; ?>;
        --nb-primary-subtle: color-mix(in srgb, <?php echo $_pcDark; ?> 12%, transparent);
        --nb-primary-muted: color-mix(in srgb, <?php echo $_pcDark; ?> 22%, transparent);
        --nb-primary-medium: color-mix(in srgb, <?php echo $_pcDark; ?> 38%, transparent);
        --nb-primary-btn: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcDark; ?> 70%, white) 0%, <?php echo $_pcDark; ?> 70%);
        --nb-primary-btn-hover: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcDark; ?> 50%, white) 0%, <?php echo $_pcDark; ?> 70%);
        --nb-brand: <?php echo $_acDark; ?>;
        --nb-brand-dark: color-mix(in srgb, <?php echo $_acDark; ?> 80%, black);
        --nb-sidebar-bg: <?php echo $_sbDark; ?>;
    }
    </style>
</head>
<body>
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
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-top">
            <nav class="sidebar-nav">
                <button class="sidebar-nav-item active" onclick="switchTab('content')" data-tab="content">
                    <?php echo nbIcon('edit'); ?>
                    <span><?php echo t('nav.pages'); ?></span>
                </button>
                <button class="sidebar-nav-item" onclick="switchTab('news')" data-tab="news">
                    <?php echo nbIcon('news'); ?>
                    <span><?php echo t('nav.news'); ?></span>
                </button>
                <button class="sidebar-nav-item" onclick="switchTab('events')" data-tab="events">
                    <?php echo nbIcon('calendar'); ?>
                    <span><?php echo t('nav.events'); ?></span>
                </button>
                <button class="sidebar-nav-item" onclick="switchTab('mails')" data-tab="mails">
                    <?php echo nbIcon('mail'); ?>
                    <span><?php echo t('nav.messages'); ?></span>
                    <span class="mail-badge mail-badge--hidden" id="mailBadge">0</span>
                </button>
                <?php if ($isAdminUser): ?>
                <button class="sidebar-nav-item" onclick="openImageManager()" type="button">
                    <?php echo nbIcon('image'); ?>
                    <span><?php echo t('nav.image_manager'); ?></span>
                </button>
                <button class="sidebar-nav-item" onclick="switchTab('icons')" data-tab="icons">
                    <?php echo nbIcon('icons'); ?>
                    <span><?php echo t('nav.icon_manager'); ?></span>
                </button>
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
            <a class="sidebar-version" href="https://nibbly.dev" target="_blank" rel="noopener noreferrer">Nibbly <?php echo htmlspecialchars(nibblyVersion(), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
    </aside>

    <div class="admin-main" id="adminMain">
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
    <div class="info-banner info-banner--warning" id="passwordWarning">
        <div class="info-banner__inner">
            <strong class="info-banner__title"><?php echo nbIcon('alert'); ?> <?php echo t('security.warning'); ?></strong>
            <span class="info-banner__body">
                <?php echo t('security.weak_password'); ?>
                <strong><?php echo t('security.change_now'); ?></strong> &mdash; this is a significant security risk.
                <a href="#" class="info-banner__cta" onclick="switchTab('settings'); document.querySelector('[data-settings-tab=&quot;my-account&quot;]').click(); return false;"><?php echo t('security.change_link'); ?> &rarr;</a>
            </span>
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
    <div class="admin-container" id="contentTab">
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
                </div>
                <select id="pageListLang" class="topbar-select" onchange="renderPageListForLang(this.value)">
                    <?php foreach ($siteLanguages as $code => $name): ?>
                    <option value="<?php echo htmlspecialchars($code); ?>"<?php if ($code === SITE_LANG_DEFAULT) echo ' selected'; ?>><?php echo htmlspecialchars($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
                    <strong class="info-banner__title"><?php echo nbIcon('alert'); ?> <span id="mailConfigBannerTitle"></span></strong>
                    <span class="info-banner__body">
                        <span id="mailConfigBannerText"></span>
                        <button type="button" class="info-banner__cta info-banner__cta-button" onclick="openEmailSettings()"><?php echo t('mails.open_email_settings'); ?> &rarr;</button>
                    </span>
                </div>
            </div>
            <div class="page-list-header">
                <div class="page-list-header-left">
                    <h2><?php echo t('mails.title'); ?></h2>
                    <button class="btn btn-secondary btn-sm" onclick="loadMails()"><?php echo t('btn.refresh'); ?></button>
                    <button class="btn btn-secondary btn-sm" onclick="markAllMailsRead()"><?php echo t('mails.mark_all_read'); ?></button>
                    <button class="btn btn-secondary btn-sm" id="deleteReadMailsBtn" onclick="deleteReadMails()" disabled><?php echo t('mails.delete_read'); ?></button>
                </div>
            </div>
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
            <div class="icon-manager-grid" id="iconManagerGrid">
                <!-- Icons inserted via JS -->
            </div>
            <p class="trash-empty-msg" id="iconManagerEmpty" style="display:none;"><?php echo t('icons.empty'); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Settings Tab -->
    <div class="admin-container" id="settingsTab" style="display: none;">
        <div class="settings-tabs">
            <?php if ($isAdminUser): ?>
            <button class="settings-tab-btn active" data-settings-tab="branding"><?php echo t('settings.branding'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="theme"><?php echo t('settings.theme'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="language"><?php echo t('settings.language'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="login"><?php echo t('settings.login'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="email"><?php echo t('settings.email'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="menus"><?php echo t('settings.menus'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="users"><?php echo t('settings.users'); ?></button>
            <?php endif; ?>
            <button class="settings-tab-btn<?php echo !$isAdminUser ? ' active' : ''; ?>" data-settings-tab="my-account"><?php echo t('settings.my_account'); ?></button>
            <?php if ($isAdminUser): ?>
            <button class="settings-tab-btn settings-tab-btn--danger" data-settings-tab="danger"><?php echo t('settings.danger_zone'); ?></button>
            <?php endif; ?>
        </div>
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
                    <fieldset class="theme-color-section" data-mode="light">
                        <legend><?php echo t('settings.theme_section_light'); ?></legend>
                        <div class="form-group">
                            <label for="settingsPrimaryColor"><?php echo t('settings.primary_color'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="settingsPrimaryColorPicker" value="#2563eb" class="color-picker">
                                <input type="text" id="settingsPrimaryColor" value="#2563eb" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                            </div>
                            <small class="form-hint"><?php echo t('settings.primary_color_hint'); ?></small>
                        </div>
                        <div class="form-group">
                            <label for="settingsAccentColor"><?php echo t('settings.accent_color'); ?></label>
                            <div class="color-input-group">
                                <input type="color" id="settingsAccentColorPicker" value="#60a5fa" class="color-picker">
                                <input type="text" id="settingsAccentColor" value="#60a5fa" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                            </div>
                            <small class="form-hint"><?php echo t('settings.accent_color_hint'); ?></small>
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
                        </div>
                    </fieldset>

                    <fieldset class="theme-color-section" data-mode="dark">
                        <legend><?php echo t('settings.theme_section_dark'); ?></legend>
                        <div class="form-group">
                            <label for="settingsDarkPrimaryColor"><?php echo t('settings.primary_color'); ?></label>
                            <div class="color-input-group" data-auto-field="darkPrimaryColor">
                                <input type="color" id="settingsDarkPrimaryColorPicker" value="#2563eb" class="color-picker">
                                <input type="text" id="settingsDarkPrimaryColor" value="#2563eb" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                                <span class="auto-badge" data-auto-for="darkPrimaryColor" hidden><?php echo t('settings.auto_badge'); ?></span>
                                <button type="button" class="auto-reset-btn" data-auto-reset="darkPrimaryColor" title="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>" aria-label="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>">&#x21BA;</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="settingsDarkAccentColor"><?php echo t('settings.accent_color'); ?></label>
                            <div class="color-input-group" data-auto-field="darkAccentColor">
                                <input type="color" id="settingsDarkAccentColorPicker" value="#60a5fa" class="color-picker">
                                <input type="text" id="settingsDarkAccentColor" value="#60a5fa" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                                <span class="auto-badge" data-auto-for="darkAccentColor" hidden><?php echo t('settings.auto_badge'); ?></span>
                                <button type="button" class="auto-reset-btn" data-auto-reset="darkAccentColor" title="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>" aria-label="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>">&#x21BA;</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="settingsDarkSidebarBg"><?php echo t('settings.sidebar_bg'); ?></label>
                            <div class="color-input-group" data-auto-field="darkSidebarBg">
                                <input type="color" id="settingsDarkSidebarBgPicker" value="#0a0a0a" class="color-picker">
                                <input type="text" id="settingsDarkSidebarBg" value="#0a0a0a" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" class="color-hex-input">
                                <span class="auto-badge" data-auto-for="darkSidebarBg" hidden><?php echo t('settings.auto_badge'); ?></span>
                                <button type="button" class="auto-reset-btn" data-auto-reset="darkSidebarBg" title="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>" aria-label="<?php echo htmlspecialchars(t('settings.reset_to_auto')); ?>">&#x21BA;</button>
                            </div>
                        </div>
                        <small class="form-hint"><?php echo t('settings.dark_section_hint'); ?></small>
                    </fieldset>
                    </div>

                    <div class="form-group">
                        <label><?php echo t('settings.button_style'); ?></label>
                        <div class="btn-style-row">
                            <div class="btn-style-controls">
                                <label class="toggle-label">
                                    <span><?php echo t('settings.button_glow'); ?></span>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="settingsButtonGlow" checked>
                                        <span class="toggle-slider"></span>
                                    </div>
                                </label>
                                <div class="range-field">
                                    <label for="settingsButtonRadius"><?php echo t('settings.button_radius'); ?></label>
                                    <div class="range-input-group">
                                        <input type="range" id="settingsButtonRadius" min="0" max="24" value="6" class="range-input">
                                        <span class="range-value" id="settingsButtonRadiusValue">6px</span>
                                    </div>
                                </div>
                                <small class="form-hint"><?php echo t('settings.button_glow_hint'); ?></small>
                            </div>
                            <div class="btn-style-preview" id="btnStylePreview">
                                <button type="button" class="btn-preview-primary" id="previewBtnPrimary"><?php echo t('settings.preview_primary'); ?></button>
                                <button type="button" class="btn-preview-secondary" id="previewBtnSecondary"><?php echo t('settings.preview_secondary'); ?></button>
                            </div>
                        </div>
                    </div>
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
                    <button type="submit" class="btn btn-primary" id="saveLoginBtn"><?php echo t('settings.save_login'); ?></button>
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
                        <input type="email" id="settingsRecipientEmail" placeholder="info@example.com">
                        <small class="form-hint"><?php echo t('settings.recipient_email_hint'); ?></small>
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

            <!-- Users Panel -->
            <div class="settings-panel" id="settingsPanel-users">
                <h2><?php echo t('settings.users'); ?></h2>
                <p class="settings-description"><?php echo t('settings.users_desc'); ?></p>

                <div class="users-toolbar">
                    <button type="button" class="btn btn-primary" id="addUserBtn">+ <?php echo t('settings.add_user'); ?></button>
                </div>

                <table class="users-table" id="usersTable">
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

                    <div class="restore-mode-selector" id="restoreModeSelector" style="display: none;">
                        <label class="restore-mode-option">
                            <input type="radio" name="restore_mode" value="content" checked>
                            <div class="restore-mode-card">
                                <strong><?php echo t('settings.restore_content'); ?></strong>
                                <span><?php echo t('settings.restore_content_desc'); ?></span>
                            </div>
                        </label>
                        <label class="restore-mode-option">
                            <input type="radio" name="restore_mode" value="full">
                            <div class="restore-mode-card">
                                <strong><?php echo t('settings.restore_full'); ?></strong>
                                <span><?php echo t('settings.restore_full_desc'); ?></span>
                            </div>
                        </label>
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
    <div class="modal-overlay" id="modalOverlay" style="display: none;">
        <div class="modal">
            <h3 id="modalTitle"><?php echo t('btn.confirm'); ?></h3>
            <p id="modalText"></p>
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
                    <label class="modal-label"><?php echo t('icons.icon_set'); ?>
                        <select id="iconifyImportSet" class="modal-input">
                            <option value="lucide">Lucide</option>
                            <option value="tabler">Tabler Icons</option>
                            <option value="heroicons">Heroicons</option>
                            <option value="bi">Bootstrap Icons</option>
                        </select>
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

    <script src="../js/image-manager.js"></script>
    <script>
    // Block Type Registry
    window.BlockTypeRegistry = <?php
        require_once dirname(__DIR__) . '/includes/content-loader.php';
        require_once dirname(__DIR__) . '/includes/menu-helpers.php';
        echo json_encode(getBlockTypes(), JSON_UNESCAPED_UNICODE);
    ?>;

    // Admin translations for JS
    const NB_LANG = <?php echo json_encode(array_merge(tEditorAll(), tAll()), JSON_UNESCAPED_UNICODE); ?>;
    // Menu registry for Page Settings nav checkboxes
    window.NB_MENUS = <?php echo json_encode(getMenuRegistry()['menus'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    function t(key, params) {
        let s = NB_LANG[key] || key;
        if (params) { for (const [k, v] of Object.entries(params)) { s = s.replace('{' + k + '}', v); } }
        return s;
    }

    // SVG icon paths (viewBox 0 0 24 24)
    const ICONS = {
        edit:      '<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        eye:       '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        duplicate: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>',
        trash:     '<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/>',
        'eye-off': '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
        back:      '<path d="M19 12H5M12 19l-7-7 7-7"/>',
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
                    window.location.href = 'index.php?timeout=1';
                    return response;
                }
            } catch(e) {}
        }
        return response;
    };

    let currentPage = null;
    let currentContent = null;
    let sectionCounter = 0;

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

    function applyPageList(pageListData) {
        pageListCache = pageListData;
        const viewLang = document.getElementById('pageListLang').value;
        renderPageList(pageListData, viewLang);
        updatePageSelect();
    }

    function renderPageListForLang(lang) {
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
        pages.forEach(page => {
            const viewInfo = page.languages[viewLang];
            // Skip pages that have no entry at all for this language
            if (!viewInfo) return;

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
            tdTitle.appendChild(titleLink);

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
                    const pageName = viewLang + '_' + page.slug;
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
            const viewLang = document.getElementById('langSelect').value;
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

    async function copyPageToLang(sourceLang, sourceSlug, targetLang, targetSlug) {
        const formData = new FormData();
        formData.append('action', 'copy-page');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('source', sourceLang + '_' + sourceSlug);
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
        formData.append('source', lang + '_' + slug);

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
            return;
        }

        table.style.display = '';
        emptyMsg.style.display = 'none';
        emptyBtn.style.display = '';

        items.forEach(item => {
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
            if (!pageSlug || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(pageSlug)) {
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
        currentPage = lang + '_' + page;

        try {
            const response = await fetch(`api.php?action=load&page=${currentPage}`);
            const result = await response.json();

            if (result.success) {
                currentContent = result.data;
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
    const PAGE_SETTINGS_KEYS = new Set(['title', 'description', 'nav', 'breadcrumb']);

    // Render the "Page Settings" panel (title, description, nav locations, breadcrumb)
    function renderPageSettings(container) {
        const group = document.createElement('div');
        group.className = 'ce-group ce-group--open ce-group--settings';
        group.innerHTML = `<div class="ce-group-header" onclick="toggleGroup(this)">
            <span class="ce-chevron">▼</span>
            <span class="ce-group-title">${t('editor.page_settings')}</span>
        </div>
        <div class="ce-group-body" style="display:block;"></div>`;
        const body = group.querySelector('.ce-group-body');

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
        body.appendChild(titleField);

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
        body.appendChild(descField);

        // Nav locations
        const navField = document.createElement('div');
        navField.className = 'ce-field';
        navField.innerHTML = `<label class="ce-field-label">${t('editor.nav_locations')}</label>`;
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
        customContainer.className = 'ce-breadcrumb-editor';
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
        body.appendChild(navField);

        // Breadcrumb editor
        const bcField = document.createElement('div');
        bcField.className = 'ce-field';
        bcField.innerHTML = `<label class="ce-field-label">${t('editor.breadcrumb')}</label>
            <small class="form-hint">${t('editor.breadcrumb_hint')}</small>`;
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
        body.appendChild(bcField);

        container.appendChild(group);
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

        // Render page settings panel (title, description, nav, breadcrumb)
        renderPageSettings(container);

        // Render each top-level key as a collapsible group
        for (const key of Object.keys(currentContent)) {
            if (META_KEYS.has(key) || PAGE_SETTINGS_KEYS.has(key)) continue;

            if (SPECIAL_KEYS.has(key)) {
                // Sections: render with existing special UI
                const sectionsGroup = document.createElement('div');
                sectionsGroup.className = 'ce-group';
                sectionsGroup.innerHTML = `<div class="ce-group-header" onclick="toggleGroup(this)">
                    <span class="ce-chevron">▶</span>
                    <span class="ce-group-title">sections</span>
                    <span class="ce-group-count">${t('editor.items', {count: (currentContent.sections || []).length})}</span>
                </div>
                <div class="ce-group-body" style="display:none;">
                    <div id="sectionsLegacyContainer"></div>
                </div>`;
                container.appendChild(sectionsGroup);

                // Render legacy sections inside
                const legacyContainer = sectionsGroup.querySelector('#sectionsLegacyContainer');
                if (currentContent.sections && currentContent.sections.length > 0) {
                    currentContent.sections.forEach((section, index) => {
                        addSectionElement(section, index, legacyContainer);
                    });
                }
                // Add section buttons
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
                legacyContainer.parentElement.appendChild(addBtns);
                continue;
            }

            const value = currentContent[key];
            const group = renderJsonGroup(key, value, key);
            container.appendChild(group);
        }

        // Auto-resize all textareas
        container.querySelectorAll('textarea.ce-textarea').forEach(autoResizeTextarea);
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

    // ============================================================
    // IMAGE MANAGER — thin wrappers around NbImageManager (js/image-manager.js)
    // ============================================================

    // Initialize the shared image manager component with dashboard dependencies.
    // (Deferred to end of script where CSRF_TOKEN and t() are defined.)
    window.addEventListener('DOMContentLoaded', function() {
        NbImageManager.init({
            apiUrl: 'api.php',
            csrfToken: CSRF_TOKEN,
            t: function(key, params) {
                return typeof t === 'function' ? t(key, params) : key;
            },
            showToast: function(msg, type) {
                if (typeof showToast === 'function') showToast(msg, type);
            },
            showConfirm: null
        });
    });

    function browseImageForField(inputEl, previewEl) {
        NbImageManager.open(function(path) {
            inputEl.value = path;
            inputEl.dispatchEvent(new Event('input'));
            if (previewEl) {
                const img = previewEl.querySelector('img');
                if (img) { img.src = path.startsWith('/') ? '..' + path : path; img.style.display = ''; }
            }
            markDirty();
        }, inputEl ? inputEl.value : null);
    }

    // Backward-compat globals (in case any onclick attribute still references them)
    window.openImageManager = function() { NbImageManager.open(); };
    window.closeImageManager = function() { NbImageManager.close(); };
    window.browseSectionImage = function(btn) {
        const input = btn.parentElement.querySelector('.section-field');
        const preview = btn.closest('.form-group').querySelector('.ce-image-preview');
        NbImageManager.open(function(path) {
            if (path && input) {
                input.value = path;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                if (preview) {
                    const src = path.startsWith('/') ? '..' + path : path;
                    preview.innerHTML = '<img src="' + escapeHtml(src) + '" alt="preview" onerror="this.style.display=\'none\'">';
                } else {
                    const previewDiv = document.createElement('div');
                    previewDiv.className = 'ce-image-preview';
                    const src = path.startsWith('/') ? '..' + path : path;
                    previewDiv.innerHTML = '<img src="' + escapeHtml(src) + '" alt="preview">';
                    input.parentElement.before(previewDiv);
                }
                markDirty();
            }
        }, input ? input.value : null);
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

    // Keyboard shortcuts for undo/redo
    document.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'z') {
            if (!currentContent) return;
            // Ignore when typing in input/textarea
            const tag = document.activeElement?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            e.preventDefault();
            if (e.shiftKey) {
                editorRedo();
            } else {
                editorUndo();
            }
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
        div.className = 'section-item';
        div.dataset.index = index;
        div.dataset.type = section.type;

        const def = window.BlockTypeRegistry?.[section.type];
        const typeLabel = def?.label || section.type;

        // Build form fields from registry
        let content = '';
        if (def && def.fields) {
            for (const field of def.fields) {
                const val = escapeHtml(section[field.key] ?? '');
                switch (field.type) {
                    case 'input':
                    case 'url':
                    case 'number':
                        content += `<div class="form-group">
                            <label>${field.label}</label>
                            <input type="${field.type === 'input' ? 'text' : field.type}" class="section-field" data-key="${field.key}" value="${val}" placeholder="${field.label}...">
                            ${field.hint ? `<small style="color: #666;">${field.hint}</small>` : ''}
                        </div>`;
                        break;
                    case 'textarea':
                        content += `<div class="form-group">
                            <label>${field.label}</label>
                            <textarea class="section-field" data-key="${field.key}" placeholder="${field.label}...">${val}</textarea>
                        </div>`;
                        break;
                    case 'wysiwyg':
                        content += `<div class="form-group html-editor">
                            <label>${field.label} (HTML)</label>
                            <textarea class="section-field" data-key="${field.key}">${val}</textarea>
                        </div>`;
                        break;
                    case 'select':
                        const opts = (field.options || []).map(o =>
                            `<option value="${o.value}"${section[field.key] === o.value ? ' selected' : ''}>${o.label}</option>`
                        ).join('');
                        content += `<div class="form-group">
                            <label>${field.label}</label>
                            <select class="section-field" data-key="${field.key}">${opts}</select>
                        </div>`;
                        break;
                    case 'checkbox':
                        content += `<div class="form-group">
                            <label><input type="checkbox" class="section-field" data-key="${field.key}"${section[field.key] ? ' checked' : ''}> ${field.label}</label>
                        </div>`;
                        break;
                    case 'image':
                        const imgSrc = val ? (val.startsWith('/') ? '..' + val : val) : '';
                        content += `<div class="form-group">
                            <label>${field.label}</label>
                            ${imgSrc ? `<div class="ce-image-preview"><img src="${escapeHtml(imgSrc)}" alt="preview" onerror="this.style.display='none'"></div>` : ''}
                            <div class="ce-image-input-row">
                                <input type="text" class="section-field ce-input" data-key="${field.key}" value="${val}" placeholder="Path to image...">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="browseSectionImage(this)">${t('btn.browse')}</button>
                            </div>
                        </div>`;
                        break;
                    case 'audio':
                        content += `<div class="form-group">
                            <label>${field.label}</label>
                            <input type="text" class="section-field" data-key="${field.key}" value="${val}" placeholder="Path to audio file...">
                        </div>`;
                        break;
                }
            }
        }

        div.innerHTML = `
            <div class="section-header">
                <span class="section-type ${section.type}">${typeLabel}</span>
                <div class="section-actions">
                    <button class="btn btn-sm btn-secondary" onclick="moveSection(${index}, -1)">&#8593;</button>
                    <button class="btn btn-sm btn-secondary" onclick="moveSection(${index}, 1)">&#8595;</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteSection(${index})">${icon('trash', 14)}</button>
                </div>
            </div>
            ${content}
        `;

        container.appendChild(div);
        sectionCounter++;
    }

    // Add new section (registry-driven)
    function addSection(type) {
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

        currentContent.sections.push(newSection);
        addSectionElement(newSection, currentContent.sections.length - 1);
    }

    // Move section
    function moveSection(index, direction) {
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= currentContent.sections.length) return;

        pushUndoSnapshot();

        const temp = currentContent.sections[index];
        currentContent.sections[index] = currentContent.sections[newIndex];
        currentContent.sections[newIndex] = temp;

        renderEditor();
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
                currentContent.lastModified = result.data.lastModified;
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
                alert('Backup content:\n\n' + JSON.stringify(result.data, null, 2));
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
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
    function showModal(title, text, onConfirm) {
        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalText').textContent = text;
        document.getElementById('modalOverlay').style.display = 'flex';
        document.getElementById('modalConfirm').onclick = onConfirm;
    }

    function closeModal() {
        document.getElementById('modalOverlay').style.display = 'none';
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
    const VALID_DASHBOARD_TABS = <?php echo json_encode($isAdminUser ? ['content', 'news', 'events', 'mails', 'icons', 'settings', 'backup'] : ['content', 'news', 'events', 'mails', 'settings']); ?>;
    const DASHBOARD_HASH_ALIASES = { pages: 'content', messages: 'mails' };
    let dashboardRouteApplying = false;

    function dashboardHashFor(tab, subtab) {
        const publicTab = tab === 'content' ? 'pages' : (tab === 'mails' ? 'messages' : tab);
        return '#' + publicTab + (subtab ? '/' + subtab : '');
    }

    function parseDashboardHash() {
        const raw = (window.location.hash || '').replace(/^#/, '');
        if (!raw) return { tab: 'content', subtab: '' };
        const parts = raw.split('/').filter(Boolean);
        const first = parts[0] || 'content';
        const tab = DASHBOARD_HASH_ALIASES[first] || first;
        return {
            tab: VALID_DASHBOARD_TABS.indexOf(tab) !== -1 ? tab : 'content',
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
        // Load page list first so dropdowns are populated
        try {
            const response = await fetch('api.php?action=list-pages&_=' + Date.now());
            const result = await response.json();
            if (result.success) {
                applyPageList(result.data);
            }
        } catch (e) {
            console.error('Error loading page list:', e);
        }

        const params = new URLSearchParams(window.location.search);
        const pageParam = params.get('page');
        const tabParam = params.get('tab');
        const postParam = params.get('post');
        const route = parseDashboardHash();
        const hashParts = (window.location.hash || '').replace(/^#/, '').split('/').filter(Boolean);
        let canonicalTab = route.tab || 'content';
        let canonicalSubtab = route.subtab || '';
        let canonicalUrl = null;

        if (hashParts[0] === 'page' && hashParts[1] && hashParts[1].includes('_')) {
            const page = hashParts[1];
            const lang = page.substring(0, page.indexOf('_'));
            const slug = page.substring(page.indexOf('_') + 1);
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
        } else if (route.tab !== 'content' || route.subtab) {
            switchTab(route.tab, { settingsTab: route.subtab, replace: !!replace });
            canonicalTab = route.tab;
            canonicalSubtab = route.subtab;
        } else if (tabParam === 'news') {
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
            // No page specified — show page list
            showPageList(false);
            canonicalTab = 'content';
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
        if (tab !== 'content') {
            dismissFrontendEditBanner();
        }

        document.getElementById('contentTab').style.display = tab === 'content' ? 'block' : 'none';
        document.getElementById('newsTab').style.display = tab === 'news' ? 'block' : 'none';
        document.getElementById('eventsTab').style.display = tab === 'events' ? 'block' : 'none';
        document.getElementById('mailsTab').style.display = tab === 'mails' ? 'block' : 'none';
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
        const titles = { content: currentPage ? t('editor.title') : t('pages.title'), news: t('news.title'), mails: t('mails.title'), events: t('events.title'), icons: t('icons.title'), settings: t('settings.title'), backup: t('settings.backup') };
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
        if (tab === 'settings' && options.settingsTab) {
            activateSettingsTab(options.settingsTab, { silent: true });
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
        lucide: { label: 'Lucide', license: 'ISC', licenseUrl: 'https://github.com/lucide-icons/lucide/blob/main/LICENSE' },
        tabler: { label: 'Tabler Icons', license: 'MIT', licenseUrl: 'https://github.com/tabler/tabler-icons/blob/master/LICENSE' },
        heroicons: { label: 'Heroicons', license: 'MIT', licenseUrl: 'https://github.com/tailwindlabs/heroicons/blob/master/LICENSE' },
        bi: { label: 'Bootstrap Icons', license: 'MIT', licenseUrl: 'https://github.com/twbs/icons/blob/main/LICENSE.md', style: 'fill' }
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
            return;
        }
        if (empty) empty.style.display = 'none';

        icons.forEach(function(iconItem) {
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
        document.getElementById('iconManagerModalOverlay').style.display = 'flex';
    }

    function closeIconManagerModal() {
        document.getElementById('iconManagerModalOverlay').style.display = 'none';
    }

    function openIconifyImportModal() {
        const overlay = document.getElementById('iconifyImportModalOverlay');
        if (!overlay) return;
        overlay.style.display = 'flex';
        updateIconifyImportLicense();
        setTimeout(function() {
            document.getElementById('iconifyImportQuery')?.focus();
        }, 50);
    }

    function closeIconifyImportModal() {
        document.getElementById('iconifyImportModalOverlay').style.display = 'none';
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
        renderIconManager();
    });
    document.getElementById('iconManagerSort')?.addEventListener('change', function() {
        iconManagerSortMode = this.value || 'alpha';
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
                mailsData = result.data;
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

        if (!mailsData || mailsData.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="5" style="color: var(--nb-text-muted); text-align: center; padding: var(--nb-space-6);">${escapeHtml(t('mails.no_messages'))}</td>`;
            tbody.appendChild(tr);
            updateMailBulkActions();
            updateMailSortIndicators();
            return;
        }

        getSortedMails().forEach(mail => {
            const date = new Date(mail.timestamp);
            const dateStr = date.toLocaleDateString();
            const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const isUnread = !mail.read;
            const isStarred = !!mail.starred;

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
            tr.appendChild(tdFrom);
            tr.appendChild(tdSubject);
            tr.appendChild(tdDate);
            tbody.appendChild(tr);
        });
        updateMailBulkActions();
        updateMailSortIndicators();
    }

    function getSortedMails() {
        return [...mailsData].sort((a, b) => {
            let cmp = 0;
            if (mailSortField === 'read') {
                cmp = Number(!!a.read) - Number(!!b.read);
            } else if (mailSortField === 'starred') {
                cmp = Number(!!a.starred) - Number(!!b.starred);
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
            mailSortDir = field === 'read' || field === 'from' || field === 'subject' ? 'asc' : 'desc';
        }
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
                if (result.data.count > 0) {
                    badge.textContent = result.data.count;
                    badge.classList.remove('mail-badge--hidden');
                }
            }
        } catch (error) {
            console.error('Error loading badge:', error);
        }
    }

    function openMailDetail(mailId) {
        const mail = mailsData.find(m => m.id === mailId);
        if (!mail) return;

        const date = new Date(mail.timestamp);
        const dateStr = date.toLocaleDateString();
        const timeStr = date.toLocaleTimeString();

        document.getElementById('mailDetailTitle').textContent = mail.occasion;
        document.getElementById('mailDetailContent').innerHTML = `
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
        sortedSlugs.forEach(slug => {
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
                <div class="editor-form-grid">
                    <div class="editor-form-row">
                        <label for="newsTitle">${t('news.post_title')}</label>
                        <input type="text" id="newsTitle" class="editor-input" value="${escapeHtml(post.title)}" placeholder="${t('news.post_title')}">
                    </div>
                    <div class="editor-form-row-half">
                        <div class="editor-form-row">
                            <label for="newsSlug">${t('news.post_slug')}</label>
                            <input type="text" id="newsSlug" class="editor-input" value="${escapeHtml(post.slug)}" placeholder="my-post-slug">
                        </div>
                        <div class="editor-form-row">
                            <label for="newsDate">${t('news.post_date')}</label>
                            <input type="date" id="newsDate" class="editor-input" value="${escapeHtml(post.date)}">
                        </div>
                    </div>
                    <div class="editor-form-row-half">
                        <div class="editor-form-row">
                            <label for="newsAuthor">${t('news.post_author')}</label>
                            <input type="text" id="newsAuthor" class="editor-input" value="${escapeHtml(post.author)}">
                        </div>
                        <div class="editor-form-row">
                            <label for="newsLang">${t('news.post_language')}</label>
                            <select id="newsLang" class="editor-input">${langOpts}</select>
                        </div>
                    </div>
                    <div class="editor-form-row">
                        <label for="newsImage">${t('news.post_image')}</label>
                        <div class="ce-image-input-row">
                            <input type="text" id="newsImage" class="editor-input" value="${escapeHtml(post.image)}" placeholder="/assets/images/cover.jpg">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="browseNewsImage()">${t('btn.browse')}</button>
                        </div>
                        <div class="ce-image-preview" id="newsImagePreview">
                            <img src="${post.image ? (post.image.startsWith('/') ? '..' + escapeHtml(post.image) : escapeHtml(post.image)) : ''}" alt="" style="${post.image ? '' : 'display:none;'}">
                        </div>
                    </div>
                    <div class="editor-form-row">
                        <label for="newsExcerpt">${t('news.post_excerpt')}</label>
                        <textarea id="newsExcerpt" class="editor-textarea" rows="3">${escapeHtml(post.excerpt)}</textarea>
                    </div>
                    <div class="editor-form-row">
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
        const url = prompt('URL:', 'https://');
        if (url) {
            document.execCommand('createLink', false, url);
            document.getElementById('newsContentWysiwyg').focus();
        }
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

    let settingsLoaded = false;
    let currentSettings = null;

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
            var tab = this.getAttribute('data-settings-tab');
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

        // Theme
        document.getElementById('settingsAdminTheme').value = settings.theme.adminTheme || 'light';
        document.querySelectorAll('.theme-option').forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.theme === settings.theme.adminTheme);
        });

        // Colors — Light mode
        var primary = settings.theme.primaryColor || '#2563eb';
        var accent = settings.theme.accentColor || '#60a5fa';
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

        // Language
        var langSelect = document.getElementById('settingsAdminLanguage');
        if (langSelect) langSelect.value = settings.general?.adminLanguage || '';

        // Frontend-login redirect mode (default: 'auto')
        var loginMode = (settings.general && settings.general.frontendLoginRedirect) || 'auto';
        var modeRadio = document.querySelector('input[name="frontendLoginRedirect"][value="' + loginMode + '"]');
        if (modeRadio) modeRadio.checked = true;

        updateColorPreview(primary, accent);
        updateBtnStylePreview();

        // Email
        var email = settings.email || {};
        var methodSelect = document.getElementById('settingsEmailMethod');
        if (methodSelect) methodSelect.value = email.method || 'inactive';
        document.getElementById('settingsRecipientEmail').value = email.recipientEmail || '';
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

    // 3-way logo display selector is only relevant when no logo is set
    function updateLogoDisplayVisibility() {
        var logoVal = document.getElementById('settingsLogo').value.trim();
        var group = document.getElementById('logoDisplayGroup');
        if (group) group.style.display = logoVal ? 'none' : '';
    }

    // ============================================================
    // THEME COLORS — auto-derivation, auto-badge, live preview
    // ============================================================

    // Defaults — kept in sync with server-side ($defaults in api.php load-settings)
    var THEME_DEFAULTS = {
        adminTheme: 'light',
        primaryColor: '#2563eb',
        accentColor: '#60a5fa',
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
        logoSize: 'medium'
    };

    // Sidebar bg derivations — match the CSS color-mix() on first paint
    function deriveSidebarLight(primary) {
        return mixColors(primary, '#ffffff', 0.08);
    }
    function deriveSidebarDark(primary) {
        return mixColors(primary, '#050505', 0.08);
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
            primaryColor: document.getElementById('settingsPrimaryColor').value,
            accentColor: document.getElementById('settingsAccentColor').value,
            sidebarBg: AUTO_STATE.sidebarBg ? '' : document.getElementById('settingsSidebarBg').value,
            darkPrimaryColor: AUTO_STATE.darkPrimaryColor ? '' : document.getElementById('settingsDarkPrimaryColor').value,
            darkAccentColor: AUTO_STATE.darkAccentColor ? '' : document.getElementById('settingsDarkAccentColor').value,
            darkSidebarBg: AUTO_STATE.darkSidebarBg ? '' : document.getElementById('settingsDarkSidebarBg').value,
            buttonGlow: document.getElementById('settingsButtonGlow').checked,
            buttonRadius: parseInt(document.getElementById('settingsButtonRadius').value, 10)
        };
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
            btnPrimary.style.background = primary;
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
        hex.addEventListener('input', onHex);
    }

    bindColorPair('primaryColor', false);
    bindColorPair('accentColor', false);
    bindColorPair('sidebarBg', true);
    bindColorPair('darkPrimaryColor', true);
    bindColorPair('darkAccentColor', true);
    bindColorPair('darkSidebarBg', true);

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
        }, input ? input.value : null);
    });

    // Browse dark-logo button — opens the image manager
    document.getElementById('browseLogoDarkBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsLogoDark');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null);
    });

    // Browse admin-logo button — opens the image manager
    document.getElementById('browseAdminLogoBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsAdminLogo');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null);
    });

    // Browse favicon button — opens the image manager
    document.getElementById('browseFaviconBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsFavicon');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null);
    });

    // Browse PNG favicon button — opens the image manager
    document.getElementById('browseFaviconPngBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsFaviconPng');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null);
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

    document.getElementById('resetBrandingBtn').addEventListener('click', function() {
        document.getElementById('settingsFavicon').value = BRANDING_DEFAULTS.favicon;
        document.getElementById('settingsFaviconPng').value = BRANDING_DEFAULTS.favicon_png;
        document.getElementById('settingsLogo').value = BRANDING_DEFAULTS.logo;
        document.getElementById('settingsLogoDark').value = BRANDING_DEFAULTS.logoDark;
        document.getElementById('settingsAdminLogo').value = BRANDING_DEFAULTS.adminLogo;
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
            settings.theme = readThemeFormState();

            var formData = new FormData();
            formData.append('action', 'save-settings');
            formData.append('settings', JSON.stringify(settings));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                applyTheme(currentSettings.theme);
                showToast(t('toast.theme_saved'), 'success');
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
                '--nb-sidebar-bg: ' + c.sidebar + ';' +
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
            dropbox: { app_key: 'Dropbox app key', path: '/Nibbly Backups' },
            google_drive: { client_id: 'Google OAuth client ID', client_secret: t('settings.remote_placeholder_optional'), folder_id: t('settings.remote_placeholder_optional') },
            onedrive: { client_id: 'Microsoft app client ID', client_secret: t('settings.remote_placeholder_optional'), folder_path: '/Nibbly Backups' },
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
            // Pick mode (full vs content) — same UI choice as the upload restore.
            var mode = window.prompt(
                t('settings.restore_full') + ' / ' + t('settings.restore_content')
                + '\n\n[full] = ' + t('settings.restore_full_desc')
                + '\n[content] = ' + t('settings.restore_content_desc')
                + '\n\nType "full" or "content":', 'content');
            if (mode !== 'full' && mode !== 'content') return;

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
            return;
        }

        // Sort by date ascending
        events.sort((a, b) => (a.date || '').localeCompare(b.date || ''));

        const todayStr = new Date().toISOString().split('T')[0];

        events.forEach((event, index) => {
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

        const tabsHtml = langCodes.map(code => {
            const isDefault = code === DEFAULT_LANG;
            return `<button type="button" class="ce-lang-tab${isDefault ? ' active' : ''}" data-lang="${code}" data-event-idx="${index}">${SITE_LANGUAGES[code]}${isDefault ? ' ★' : ''}</button>`;
        }).join('');

        langSection.innerHTML = `<div class="ce-lang-tabs">${tabsHtml}</div>`;

        langCodes.forEach(code => {
            const panel = document.createElement('div');
            panel.className = 'ce-lang-panel';
            panel.dataset.lang = code;
            panel.dataset.eventIdx = index;
            panel.style.display = code === DEFAULT_LANG ? '' : 'none';

            EVENT_TRANSLATABLE.forEach(field => {
                const val = eventObj[field]?.[code] || '';
                const isLong = field === 'description';
                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'ce-field';
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
        langSection.querySelectorAll('.ce-lang-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const idx = tab.dataset.eventIdx;
                langSection.querySelectorAll('.ce-lang-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                langSection.querySelectorAll('.ce-lang-panel').forEach(p => {
                    p.style.display = p.dataset.lang === tab.dataset.lang ? '' : 'none';
                });
            });
        });

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
            return;
        }

        table.style.display = '';
        emptyMsg.style.display = 'none';
        emptyBtn.style.display = '';

        items.forEach(item => {
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
            var tab = this.getAttribute('data-settings-tab');
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
</body>
</html>
