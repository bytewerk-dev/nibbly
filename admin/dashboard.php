<?php
/**
 * Admin Dashboard - Content Editor
 */

session_start();
require_once 'config.php';
require_once __DIR__ . '/lang/i18n.php';
require_once __DIR__ . '/users.php';
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
$siteSettings = ['branding' => ['logo' => '/assets/images/favicon.svg', 'name' => '', 'showBranding' => true], 'theme' => ['adminTheme' => 'light', 'primaryColor' => '#2563eb', 'accentColor' => '#60a5fa', 'buttonGlow' => true, 'buttonRadius' => 6]];
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
    $_dashFavicon = $siteSettings['favicon'] ?? $siteSettings['branding']['logo'] ?? '/assets/images/favicon.svg';
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
<body>
    <header class="admin-topbar">
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <?php echo nbIcon('hamburger', 24, '2'); ?>
        </button>
        <div class="topbar-brand">
            <?php if ($siteSettings['branding']['showBranding'] && !empty($siteSettings['branding']['logo'])): ?>
            <img src="<?php echo htmlspecialchars($siteSettings['branding']['logo']); ?>" alt="<?php echo htmlspecialchars($siteSettings['branding']['name']); ?>" width="24" height="24" class="topbar-logo">
            <?php endif; ?>
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
            <a href=".." target="_blank" class="topbar-viewsite">
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
            <div class="sidebar-version">Nibbly <?php echo defined('NIBBLY_VERSION') ? NIBBLY_VERSION : 'dev'; ?></div>
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
    <div class="password-warning" id="emailWarning">
        <div class="password-warning-inner">
            <strong>&#9888; <?php echo t('settings.email_missing_title'); ?></strong>
            <?php echo t('settings.email_missing_text'); ?>
            <br><a href="#" onclick="switchTab('settings'); document.querySelector('[data-settings-tab=&quot;users&quot;]').click(); return false;"><?php echo t('settings.email_missing_link'); ?> &rarr;</a>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['password_warning'])): ?>
    <div class="password-warning" id="passwordWarning">
        <div class="password-warning-inner">
            <strong>&#9888; <?php echo t('security.warning'); ?></strong>
            <?php echo t('security.weak_password'); ?>
            <strong><?php echo t('security.change_now'); ?></strong> &mdash; this is a significant security risk.
            <br><a href="#" onclick="switchTab('settings'); document.querySelector('[data-settings-tab=&quot;password&quot;]').click(); return false;"><?php echo t('security.change_link'); ?> &rarr;</a>
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
                    <a class="btn btn-secondary btn-sm" id="editorViewBtn" href="#" target="_blank" title="<?php echo t('pages.view'); ?>"><?php echo nbIcon('eye', 14); ?></a>
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

    <!-- Events Tab -->
    <div class="admin-container" id="eventsTab" style="display: none;">
        <div class="editor-container" id="eventsEditorContainer">
            <div class="editor-header">
                <div class="editor-header-left">
                    <h2><?php echo t('events.title'); ?></h2>
                    <button class="btn btn-primary btn-sm" onclick="addNewEvent()"><?php echo t('events.new_event'); ?></button>
                </div>
                <span class="last-modified" id="eventsLastModified"></span>
            </div>
            <div id="eventsListContainer">
                <!-- Events inserted via JS -->
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
                    <a class="btn btn-secondary btn-sm" id="newsViewBtn" href="#" target="_blank" style="display:none;" title="<?php echo t('news.view'); ?>"><?php echo nbIcon('eye', 14); ?></a>
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
        <div class="mails-header">
            <h2><?php echo t('mails.title'); ?></h2>
            <div class="mails-actions">
                <button class="btn btn-secondary btn-sm" onclick="loadMails()"><?php echo t('btn.refresh'); ?></button>
                <button class="btn btn-primary btn-sm" onclick="markAllMailsRead()"><?php echo t('mails.mark_all_read'); ?></button>
            </div>
        </div>
        <div class="mails-list" id="mailsList">
            <!-- Mails inserted via JS -->
        </div>
    </div>

    <!-- Settings Tab -->
    <div class="admin-container" id="settingsTab" style="display: none;">
        <div class="settings-tabs">
            <?php if ($isAdminUser): ?>
            <button class="settings-tab-btn active" data-settings-tab="branding"><?php echo t('settings.branding'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="theme"><?php echo t('settings.theme'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="language"><?php echo t('settings.language'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="email"><?php echo t('settings.email'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="users"><?php echo t('settings.users'); ?></button>
            <button class="settings-tab-btn" data-settings-tab="menus"><?php echo t('settings.menus'); ?></button>
            <?php endif; ?>
            <button class="settings-tab-btn<?php echo !$isAdminUser ? ' active' : ''; ?>" data-settings-tab="password"><?php echo t('settings.password'); ?></button>
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
                    <div class="form-group">
                        <label for="settingsLogo"><?php echo t('settings.logo'); ?></label>
                        <div class="logo-preview-group">
                            <div class="logo-preview" id="logoPreview">
                                <img src="/assets/images/favicon.svg" alt="<?php echo t('settings.logo'); ?>" id="logoPreviewImg">
                            </div>
                            <div class="logo-controls">
                                <div class="logo-path-input">
                                    <input type="text" id="settingsLogo" value="/assets/images/favicon.svg" placeholder="/assets/images/logo.png">
                                    <button type="button" class="btn btn-secondary btn-sm" id="browseLogoBtn"><?php echo t('btn.browse'); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="settingsName"><?php echo t('settings.site_name'); ?></label>
                        <input type="text" id="settingsName" value="" placeholder="<?php echo t('settings.site_name_placeholder'); ?>" maxlength="100">
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
                    <button type="submit" class="btn btn-primary" id="saveBrandingBtn"><?php echo t('settings.save_branding'); ?></button>
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
                            </div>
                            <div class="btn-style-preview" id="btnStylePreview">
                                <button type="button" class="btn-preview-primary" id="previewBtnPrimary"><?php echo t('settings.preview_primary'); ?></button>
                                <button type="button" class="btn-preview-secondary" id="previewBtnSecondary"><?php echo t('settings.preview_secondary'); ?></button>
                            </div>
                        </div>
                        <small class="form-hint"><?php echo t('settings.button_glow_hint'); ?></small>
                    </div>
                    <button type="submit" class="btn btn-primary" id="saveThemeBtn"><?php echo t('settings.save_theme'); ?></button>
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
                    <button type="button" class="btn btn-primary" id="saveMenuOrderBtn" disabled>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <?php echo t('btn.save'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Password Panel -->
            <div class="settings-panel<?php echo !$isAdminUser ? ' active' : ''; ?>" id="settingsPanel-password">
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
        <h1><?php echo t('settings.backup'); ?></h1>
        <p class="page-description"><?php echo t('settings.backup_desc'); ?></p>

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
        <div class="backup-site-card" style="margin-top: var(--nb-space-5);">
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

    </div><!-- /.admin-main -->
    </div><!-- /.admin-body -->

    <!-- Mail Detail Modal -->
    <div class="modal-overlay" id="mailDetailOverlay" style="display: none;">
        <div class="modal modal-large">
            <h3 id="mailDetailTitle"><?php echo t('mails.detail_title'); ?></h3>
            <div class="mail-detail-content" id="mailDetailContent">
            </div>
            <div class="modal-actions">
                <button class="btn btn-danger" id="mailDeleteBtn"><?php echo t('btn.delete'); ?></button>
                <button class="btn btn-secondary" onclick="closeMailDetail()"><?php echo t('btn.close'); ?></button>
            </div>
        </div>
    </div>

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
            history.pushState({ view: 'pageList' }, '', 'dashboard.php');
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
                    history.pushState({ view: 'editor', page: currentPage }, '', 'dashboard.php?page=' + currentPage);
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
        });
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
        });
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
    // Auto-load page from URL parameter (?page=en_home), otherwise show page list
    (async function() {
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

        if (tabParam === 'news') {
            switchTab('news');
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
                    loadContent();
                }
            }
        } else {
            // No page specified — show page list
            showPageList(false);
            history.replaceState({ view: 'pageList' }, '', 'dashboard.php');
        }
    })();

    // Browser back/forward navigation
    window.addEventListener('popstate', (e) => {
        if (e.state && e.state.view === 'editor' && e.state.page) {
            const lang = e.state.page.substring(0, e.state.page.indexOf('_'));
            const slug = e.state.page.substring(e.state.page.indexOf('_') + 1);
            document.getElementById('langSelect').value = lang;
            updatePageSelect();
            document.getElementById('pageSelect').value = slug;
            loadContent(false);
        } else {
            showPageList(false);
        }
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

    function switchTab(tab) {
        document.getElementById('contentTab').style.display = tab === 'content' ? 'block' : 'none';
        document.getElementById('newsTab').style.display = tab === 'news' ? 'block' : 'none';
        document.getElementById('eventsTab').style.display = tab === 'events' ? 'block' : 'none';
        document.getElementById('mailsTab').style.display = tab === 'mails' ? 'block' : 'none';
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
        const titles = { content: currentPage ? t('editor.title') : t('pages.title'), news: t('news.title'), mails: t('mails.title'), events: t('events.title'), settings: t('settings.title'), backup: t('settings.backup') };
        const topbarTitle = document.getElementById('topbarTitle');
        if (topbarTitle) topbarTitle.textContent = titles[tab] || 'Dashboard';

        // Close sidebar on mobile after tab switch
        document.getElementById('adminSidebar').classList.remove('open');

        if (tab === 'mails') {
            loadMails();
        }
        if (tab === 'news') {
            showNewsList();
            if (!newsLoaded) {
                newsLoaded = true;
                loadNews();
            }
        }
        if (tab === 'events' && !eventsLoaded) {
            eventsLoaded = true;
            loadEventsEditor();
        }
        if (tab === 'settings' && !settingsLoaded) {
            loadSettings();
        }
    }

    // ============================================================
    // MAIL MANAGEMENT
    // ============================================================

    let mailsData = [];

    async function loadMails() {
        try {
            const response = await fetch('api.php?action=load-mails');
            const result = await response.json();

            if (result.success) {
                mailsData = result.data;
                renderMails();
                updateMailBadge();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    function renderMails() {
        const container = document.getElementById('mailsList');

        if (mailsData.length === 0) {
            container.innerHTML = '<p class="mails-empty">' + t('mails.no_messages') + '</p>';
            return;
        }

        container.innerHTML = mailsData.map(mail => {
            const date = new Date(mail.timestamp);
            const dateStr = date.toLocaleDateString();
            const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const unreadClass = mail.read ? '' : 'mail-unread';

            return `
                <div class="mail-item ${unreadClass}" onclick="openMailDetail('${mail.id}')">
                    <div class="mail-item-header">
                        <span class="mail-name">${escapeHtml(mail.name)}</span>
                        <span class="mail-date">${dateStr} ${timeStr}</span>
                    </div>
                    <div class="mail-item-subject">${escapeHtml(mail.occasion)}</div>
                    <div class="mail-item-preview">${escapeHtml(mail.message.substring(0, 100))}${mail.message.length > 100 ? '...' : ''}</div>
                </div>
            `;
        }).join('');
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
        document.getElementById('mailDetailOverlay').style.display = 'flex';

        if (!mail.read) {
            markMailRead(mailId);
        }
    }

    function closeMailDetail() {
        document.getElementById('mailDetailOverlay').style.display = 'none';
    }

    async function markMailRead(mailId) {
        try {
            const formData = new FormData();
            formData.append('action', 'mark-mail-read');
            formData.append('mail_id', mailId);
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                const mail = mailsData.find(m => m.id === mailId);
                if (mail) mail.read = true;
                renderMails();
                updateMailBadge();
            }
        } catch (error) {
            console.error('Error marking:', error);
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
    document.querySelectorAll('.settings-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tab = this.getAttribute('data-settings-tab');
            document.querySelectorAll('.settings-tab-btn').forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('.settings-panel').forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
            var panel = document.getElementById('settingsPanel-' + tab);
            if (panel) panel.classList.add('active');
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
        var logoPath = settings.branding.logo || '/assets/images/favicon.svg';
        document.getElementById('settingsLogo').value = logoPath;
        document.getElementById('settingsName').value = settings.branding.name || '';
        document.getElementById('settingsShowBranding').checked = settings.branding.showBranding !== false;
        updateLogoPreview(logoPath);

        // Theme
        document.getElementById('settingsAdminTheme').value = settings.theme.adminTheme || 'light';
        document.querySelectorAll('.theme-option').forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.theme === settings.theme.adminTheme);
        });

        // Colors
        var primary = settings.theme.primaryColor || '#2563eb';
        var accent = settings.theme.accentColor || '#60a5fa';
        document.getElementById('settingsPrimaryColor').value = primary;
        document.getElementById('settingsPrimaryColorPicker').value = primary;
        document.getElementById('settingsAccentColor').value = accent;
        document.getElementById('settingsAccentColorPicker').value = accent;

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
            img.style.display = 'none';
        }
    }

    function updateColorPreview(primary, accent) {
        var root = document.documentElement;
        root.style.setProperty('--nb-primary', primary);
        root.style.setProperty('--nb-primary-hover', adjustColor(primary, -15));
        root.style.setProperty('--nb-primary-active', adjustColor(primary, -25));
        root.style.setProperty('--nb-primary-btn',
            `radial-gradient(ellipse at 50% 0%, color-mix(in srgb, ${primary} 70%, white) 0%, ${primary} 70%)`);
        root.style.setProperty('--nb-primary-btn-hover',
            `radial-gradient(ellipse at 50% 0%, color-mix(in srgb, ${primary} 50%, white) 0%, ${primary} 70%)`);
        root.style.setProperty('--nb-brand', accent);
        root.style.setProperty('--nb-brand-light', adjustColor(accent, 20));
        updateBtnStylePreview();
    }

    // Combined button preview — live updates color, glow, and radius on both buttons
    function updateBtnStylePreview() {
        var glow = document.getElementById('settingsButtonGlow').checked;
        var radius = parseInt(document.getElementById('settingsButtonRadius').value, 10);
        var primary = document.getElementById('settingsPrimaryColor').value;
        var accent = document.getElementById('settingsAccentColor').value;
        var btnPrimary = document.getElementById('previewBtnPrimary');
        var btnSecondary = document.getElementById('previewBtnSecondary');
        if (btnPrimary) {
            btnPrimary.style.background = primary;
            btnPrimary.style.borderRadius = radius + 'px';
            if (glow) {
                btnPrimary.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15), 0 4px 20px ' + primary + '59';
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
        });
    });

    // Color picker sync
    document.getElementById('settingsPrimaryColorPicker').addEventListener('input', function() {
        document.getElementById('settingsPrimaryColor').value = this.value;
        updateColorPreview(this.value, document.getElementById('settingsAccentColor').value);
        updateBtnStylePreview();
    });

    document.getElementById('settingsPrimaryColor').addEventListener('input', function() {
        if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
            document.getElementById('settingsPrimaryColorPicker').value = this.value;
            updateColorPreview(this.value, document.getElementById('settingsAccentColor').value);
            updateBtnStylePreview();
        }
    });

    document.getElementById('settingsAccentColorPicker').addEventListener('input', function() {
        document.getElementById('settingsAccentColor').value = this.value;
        updateColorPreview(document.getElementById('settingsPrimaryColor').value, this.value);
    });

    document.getElementById('settingsAccentColor').addEventListener('input', function() {
        if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
            document.getElementById('settingsAccentColorPicker').value = this.value;
            updateColorPreview(document.getElementById('settingsPrimaryColor').value, this.value);
        }
    });

    // Logo path change
    document.getElementById('settingsLogo').addEventListener('input', function() {
        updateLogoPreview(this.value);
    });

    // Browse logo button — opens image list modal
    document.getElementById('browseLogoBtn').addEventListener('click', async function() {
        try {
            var response = await fetch('api.php?action=list-images');
            var result = await response.json();

            if (!result.success || result.data.length === 0) {
                showToast(t('toast.no_images'), 'error');
                return;
            }

            var overlay = document.getElementById('modalOverlay');
            var title = document.getElementById('modalTitle');
            var text = document.getElementById('modalText');
            var confirmBtn = document.getElementById('modalConfirm');

            title.textContent = t('modal.select_logo');
            text.innerHTML = '<div class="logo-browser-grid">' +
                result.data.map(function(img) {
                    return '<div class="logo-browser-item" data-path="' + img.path.replace('../', '/') + '">' +
                        '<img src="' + img.path + '" alt="' + img.name + '">' +
                    '</div>';
                }).join('') +
            '</div>';

            confirmBtn.style.display = 'none';
            overlay.style.display = 'flex';

            text.querySelectorAll('.logo-browser-item').forEach(function(item) {
                item.addEventListener('click', function() {
                    var path = this.dataset.path;
                    document.getElementById('settingsLogo').value = path;
                    updateLogoPreview(path);
                    overlay.style.display = 'none';
                    confirmBtn.style.display = '';
                });
            });
        } catch (error) {
            showToast(t('toast.error_loading_images', {message: error.message}), 'error');
        }
    });

    // Save branding
    document.getElementById('brandingForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var btn = document.getElementById('saveBrandingBtn');
        btn.disabled = true;
        btn.textContent = t('btn.saving');

        try {
            var settings = Object.assign({}, currentSettings || {});
            settings.branding = {
                logo: document.getElementById('settingsLogo').value.trim(),
                name: document.getElementById('settingsName').value.trim(),
                showBranding: document.getElementById('settingsShowBranding').checked
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
            settings.theme = {
                adminTheme: document.getElementById('settingsAdminTheme').value,
                primaryColor: primaryColor,
                accentColor: accentColor,
                buttonGlow: document.getElementById('settingsButtonGlow').checked,
                buttonRadius: parseInt(document.getElementById('settingsButtonRadius').value, 10)
            };

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

    // Apply theme live
    function applyTheme(theme) {
        var themeValue = theme.adminTheme || 'light';
        if (themeValue === 'system') {
            themeValue = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-site-theme', themeValue);
        localStorage.setItem('site-admin-theme', theme.adminTheme);

        if (theme.primaryColor) {
            var pc = theme.primaryColor;
            var pcLight = adjustColor(pc, 30);
            document.documentElement.style.setProperty('--nb-primary', pc);
            document.documentElement.style.setProperty('--nb-primary-hover', adjustColor(pc, -15));
            document.documentElement.style.setProperty('--nb-primary-active', adjustColor(pc, -25));

            // Update admin button gradients
            if (theme.buttonGlow === false) {
                document.documentElement.style.setProperty('--nb-primary-btn', pc);
                document.documentElement.style.setProperty('--nb-primary-btn-hover', adjustColor(pc, -15));
            } else {
                document.documentElement.style.setProperty('--nb-primary-btn', 'radial-gradient(ellipse at 50% 0%, ' + pcLight + ' 0%, ' + pc + ' 70%)');
                document.documentElement.style.setProperty('--nb-primary-btn-hover', 'radial-gradient(ellipse at 50% 0%, ' + adjustColor(pcLight, 20) + ' 0%, ' + pc + ' 70%)');
            }
        }
        if (theme.accentColor) {
            document.documentElement.style.setProperty('--nb-brand', theme.accentColor);
            document.documentElement.style.setProperty('--nb-brand-light', adjustColor(theme.accentColor, 20));
        }

        // Button radius — affects both admin and frontend editor buttons
        if (theme.buttonRadius != null) {
            document.documentElement.style.setProperty('--nb-radius-md', theme.buttonRadius + 'px');
            document.documentElement.style.setProperty('--editor-btn-radius', theme.buttonRadius + 'px');
        }

        // Flat button classes (glow disabled)
        document.documentElement.classList.toggle('editor-flat', theme.buttonGlow === false);
        document.documentElement.classList.toggle('nb-flat-buttons', theme.buttonGlow === false);
    }

    function adjustColor(hex, amount) {
        hex = hex.replace('#', '');
        var r = Math.max(0, Math.min(255, parseInt(hex.substring(0, 2), 16) + amount));
        var g = Math.max(0, Math.min(255, parseInt(hex.substring(2, 4), 16) + amount));
        var b = Math.max(0, Math.min(255, parseInt(hex.substring(4, 6), 16) + amount));
        return '#' + [r, g, b].map(function(c) { return c.toString(16).padStart(2, '0'); }).join('');
    }

    // Apply saved theme immediately on page load (server-rendered)
    applyTheme(<?php echo json_encode($siteSettings['theme']); ?>);

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
    const EVENT_TRANSLATABLE = ['title', 'location', 'description', 'admission'];

    async function loadEventsEditor() {
        try {
            const response = await fetch('api.php?action=load-events');
            const result = await response.json();
            if (result.success) {
                eventsData = result.data;
                renderEventsEditor();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading_events', {message: error.message}), 'error');
        }
    }

    function renderEventsEditor() {
        const container = document.getElementById('eventsListContainer');
        container.innerHTML = '';

        if (eventsData.lastModified) {
            const d = new Date(eventsData.lastModified);
            document.getElementById('eventsLastModified').textContent = t('editor.last_saved', {date: formatDateShort(d)});
        }

        const events = eventsData.events || [];
        if (events.length === 0) {
            container.innerHTML = '<p style="color:var(--nb-text-muted, #888);">' + t('events.no_events') + '</p>';
            return;
        }

        // Sort by date ascending
        events.sort((a, b) => (a.date || '').localeCompare(b.date || ''));

        events.forEach((event, index) => {
            const group = document.createElement('div');
            group.className = 'ce-group';

            const title = event.title?.[DEFAULT_LANG] || event.title?.en || event.title?.de || t('events.untitled');
            const dateStr = event.date || '';

            group.innerHTML = `
                <div class="ce-group-header" onclick="toggleGroup(this)">
                    <span class="ce-chevron">▶</span>
                    <span class="ce-group-title">${escapeHtml(dateStr)} — ${escapeHtml(title)}</span>
                    <div class="ce-group-actions">
                        <button class="btn btn-sm btn-danger" onclick="event.stopPropagation(); deleteEventDashboard(${index})">${t('btn.delete')}</button>
                    </div>
                </div>
                <div class="ce-group-body" style="display:none;"></div>
            `;

            const body = group.querySelector('.ce-group-body');
            renderEventFields(body, event, index);
            container.appendChild(group);
        });
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

        // Save button for this event
        const saveRow = document.createElement('div');
        saveRow.style.marginTop = '12px';
        saveRow.innerHTML = `<button class="btn btn-primary btn-sm" onclick="saveEventDashboard(${index})">${t('events.save_events')}</button>`;
        container.appendChild(saveRow);
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
                loadEventsEditor();
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
        renderEventsEditor();

        // Expand the new event group and scroll to it
        const groups = document.querySelectorAll('#eventsListContainer .ce-group');
        const lastGroup = groups[groups.length - 1];
        if (lastGroup) {
            const header = lastGroup.querySelector('.ce-group-header');
            if (header) toggleGroup(header);
            lastGroup.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function deleteEventDashboard(index) {
        const event = eventsData.events[index];
        if (!event) return;

        const title = event.title?.[DEFAULT_LANG] || event.title?.en || t('events.untitled');
        if (!confirm(`Delete event "${title}"?`)) return;

        if (!event.id) {
            // New unsaved event, just remove from array
            eventsData.events.splice(index, 1);
            renderEventsEditor();
            return;
        }

        // Delete via API
        const formData = new FormData();
        formData.append('action', 'delete-event');
        formData.append('id', event.id);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('api.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    showToast(t('toast.event_deleted'), 'success');
                    loadEventsEditor();
                } else {
                    showToast(t('toast.error_generic', {message: result.message}), 'error');
                }
            })
            .catch(error => showToast(t('toast.error_generic', {message: error.message}), 'error'));
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
        showConfirmModal(
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
            if (tab === 'users' && !_usersLoaded) {
                _usersLoaded = true;
                loadUsers();
            }
            if (tab === 'menus' && !_menuOrderLoaded) {
                _menuOrderLoaded = true;
                loadMenuOrder();
            }
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
