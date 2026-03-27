<?php
/**
 * Header Template
 *
 * Variables (set by each page):
 * $pageTitle       - Page title
 * $pageDescription - Meta description
 * $currentLang     - ISO 639-1 code (e.g. 'de', 'en')
 * $currentPage     - Current page slug (for navigation highlighting)
 * $basePath        - Relative path to root (e.g. '' or '../')
 *
 * Configuration (from config.php):
 * SITE_LANG_DEFAULT - Primary language code (pages at root)
 * $SITE_LANGUAGES   - Array of lang code => native name
 * $NAV_ITEMS        - (optional) Array of lang => [nav items]
 * $PAGE_MAPPING     - (optional) Array of page => [lang => path]
 */

// Start session early (before HTML output for admin login)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auto-load site config if not already loaded
$_configPath = __DIR__ . '/../admin/config.php';
if (!defined('SITE_LANG_DEFAULT') && file_exists($_configPath)) {
    require_once $_configPath;
}

$basePath = $basePath ?? '';
$currentLang = $currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
$isHomepage = ($currentPage ?? '') === 'home';
$defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';

// ============================================================
// PAGE MAPPING & NAVIGATION
// ============================================================
// Load shared nav config (also sets $SITE_LANGUAGES fallback).
// Customize includes/nav-config.php for your site's pages.

if (!isset($PAGE_MAPPING) || !isset($NAV_ITEMS)) {
    $_navConfigPath = __DIR__ . '/nav-config.php';
    if (!file_exists($_navConfigPath)) {
        $_navConfigPath = __DIR__ . '/nav-config.default.php';
    }
    if (file_exists($_navConfigPath)) {
        include_once $_navConfigPath;
    }
}

// Final fallback if $SITE_LANGUAGES still not set
if (!isset($SITE_LANGUAGES)) {
    $SITE_LANGUAGES = [$defaultLang => $defaultLang];
}

// Fallback if still not set
if (!isset($PAGE_MAPPING)) {
    $PAGE_MAPPING = ['home' => []];
    foreach ($SITE_LANGUAGES as $code => $name) {
        $PAGE_MAPPING['home'][$code] = ($code === $defaultLang) ? '.' : $code . '/';
    }
}

// Determine links for language switching
$currentPageKey = $currentPage ?? 'home';
$langLinks = [];
$_contentPath = __DIR__ . '/../content/pages/';
foreach ($SITE_LANGUAGES as $code => $name) {
    if (isset($PAGE_MAPPING[$currentPageKey][$code])) {
        $langLinks[$code] = $basePath . $PAGE_MAPPING[$currentPageKey][$code];
    } elseif ($currentPageKey !== 'home' && is_file($_contentPath . $code . '_' . $currentPageKey . '.json')) {
        // Dynamic fallback: same slug exists in target language
        $langLinks[$code] = $basePath . (($code === $defaultLang) ? $currentPageKey : $code . '/' . $currentPageKey);
    } else {
        // Final fallback: home page of that language
        $langLinks[$code] = $basePath . (($code === $defaultLang) ? '.' : $code . '/');
    }
}

// Fallback for $NAV_ITEMS
if (!isset($NAV_ITEMS)) {
    $NAV_ITEMS = [];
    foreach ($SITE_LANGUAGES as $code => $name) {
        $NAV_ITEMS[$code] = [
            ['href' => ($code === $defaultLang) ? '.' : $code . '/', 'label' => 'Home', 'page' => 'home'],
        ];
    }
}

$navItems = $NAV_ITEMS[$currentLang] ?? $NAV_ITEMS[$defaultLang] ?? [];

// Load site settings (used for favicon, theme colors, editor button style)
$_settingsPath = __DIR__ . '/../content/settings.json';
$_settings = [];
$_favicon = 'assets/images/favicon.svg';
if (file_exists($_settingsPath)) {
    $_settings = json_decode(file_get_contents($_settingsPath), true) ?: [];
    if (!empty($_settings['favicon'])) $_favicon = ltrim($_settings['favicon'], '/');
}
$_editorFlat = isset($_settings['theme']['buttonGlow']) && !$_settings['theme']['buttonGlow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>"<?php if ($_editorFlat) echo ' class="editor-flat"'; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription ?? ''); ?>">
    <meta name="robots" content="index, follow">
    <meta name="generator" content="Nibbly <?php echo defined('NIBBLY_VERSION') ? NIBBLY_VERSION : ''; ?>">
    <link rel="icon" href="<?php echo $basePath . htmlspecialchars($_favicon); ?>">
    <link rel="apple-touch-icon" href="<?php echo $basePath . htmlspecialchars($_favicon); ?>">

    <!-- Optional: uncomment to load custom fonts -->
    <!-- <link rel="stylesheet" href="<?php echo $basePath; ?>css/fonts.css"> -->

    <!-- Open Graph -->
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle ?? ''); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription ?? ''); ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="<?php echo htmlspecialchars($currentLang); ?>">

    <title><?php echo htmlspecialchars($pageTitle ?? 'Website'); ?></title>

    <link rel="stylesheet" href="<?php echo $basePath; ?>css/style.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>css/components.css">
    <?php if (file_exists(__DIR__ . '/../css/website.css')): ?>
    <link rel="stylesheet" href="<?php echo $basePath; ?>css/website.css">
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/../css/fonts.css') && file_exists(__DIR__ . '/../assets/fonts/')): ?>
    <link rel="stylesheet" href="<?php echo $basePath; ?>css/fonts.css">
    <?php endif; ?>
    <?php if (!empty($pageExternalStyles)): ?>
    <?php foreach ($pageExternalStyles as $_extStyle): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($_extStyle); ?>">
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if (isset($pageStylesheet) && file_exists(__DIR__ . '/../' . $pageStylesheet)): ?>
    <link rel="stylesheet" href="<?php echo $basePath . htmlspecialchars($pageStylesheet); ?>">
    <?php endif; ?>

    <?php
    // Inject editor-only theme variables from settings (does NOT affect site design)
    $_editorVars = [];
    if (!empty($_settings['theme']['primaryColor'])) {
        $pc = $_settings['theme']['primaryColor'];
        $_editorVars[] = '--editor-primary:' . htmlspecialchars($pc);
        $r = hexdec(substr($pc, 1, 2)); $g = hexdec(substr($pc, 3, 2)); $b = hexdec(substr($pc, 5, 2));
        $_editorVars[] = '--editor-primary-hover:#' . sprintf('%02x%02x%02x', max(0,$r-15), max(0,$g-15), max(0,$b-15));
        $_editorVars[] = '--editor-primary-light:#' . sprintf('%02x%02x%02x', min(255,$r+40), min(255,$g+40), min(255,$b+40));
    }
    if (isset($_settings['theme']['buttonRadius'])) {
        $_editorVars[] = '--editor-btn-radius:' . intval($_settings['theme']['buttonRadius']) . 'px';
    }
    if ($_editorVars): ?>
    <style>:root{<?php echo implode(';', $_editorVars); ?>}</style>
    <?php endif; ?>

    <!-- Prevent FOUC: apply stored theme before paint -->
    <script>
    (function(){try{var t=localStorage.getItem('site-theme');if(t==='system')t='dark';document.documentElement.setAttribute('data-theme',t||'light');}catch(e){}})();
    </script>
</head>
<body class="<?php echo $isHomepage ? 'page-home' : 'page-subpage'; ?><?php echo isset($pageClass) ? ' ' . $pageClass : ''; ?>">

    <!-- Fixed Header -->
    <header class="site-header" id="siteHeader">
        <div class="header-inner">
            <!-- Logo -->
            <a href="<?php echo $basePath; ?>." class="site-logo" aria-label="Home">
                <?php
                $_headerLogo = $_settings['logo'] ?? $_settings['branding']['logo'] ?? '';
                $_headerLogo = ltrim($_headerLogo, '/');
                $_siteName = defined('SITE_NAME') ? SITE_NAME : '';
                if ($_headerLogo): ?>
                <img class="site-logo-img" src="<?php echo $basePath . htmlspecialchars($_headerLogo); ?>" alt="<?php echo htmlspecialchars($_siteName); ?>">
                <?php else: ?>
                <img class="site-logo-img" src="<?php echo $basePath . htmlspecialchars($_favicon); ?>" alt="<?php echo htmlspecialchars($_siteName); ?>" width="32" height="32">
                <span class="site-logo-text"><?php echo htmlspecialchars($_siteName); ?></span>
                <?php endif; ?>
            </a>

            <!-- Desktop Navigation -->
            <nav class="nav-main">
                <ul class="nav-list">
                    <?php foreach ($navItems as $item): ?>
                    <li>
                        <a href="<?php echo $basePath . $item['href']; ?>"<?php echo ($currentPage ?? '') === $item['page'] ? ' class="active"' : ''; ?>>
                            <?php echo $item['label']; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Language Selector -->
                <?php if (count($SITE_LANGUAGES) > 1): ?>
                <div class="nav-lang">
                    <?php $langCodes = array_keys($SITE_LANGUAGES); ?>
                    <?php foreach ($langCodes as $i => $code): ?>
                        <?php if ($i > 0): ?><span class="lang-separator">|</span><?php endif; ?>
                        <a href="<?php echo $langLinks[$code]; ?>" class="lang-link<?php echo ($code === $currentLang) ? ' active' : ''; ?>"><?php echo strtoupper($code); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Theme Toggle -->
                <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
                    <svg class="theme-icon theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    <svg class="theme-icon theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                </button>
            </nav>

            <!-- Hamburger Menu Button -->
            <button class="hamburger" id="hamburger" aria-label="Menu" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
    </header>

    <!-- Mobile Navigation Overlay -->
    <div class="mobile-nav-overlay" id="mobileNavOverlay">
        <nav class="mobile-nav">
            <ul class="mobile-nav-list">
                <?php foreach ($navItems as $item): ?>
                <li>
                    <a href="<?php echo $basePath . $item['href']; ?>"<?php echo ($currentPage ?? '') === $item['page'] ? ' class="active"' : ''; ?>>
                        <?php echo $item['label']; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if (count($SITE_LANGUAGES) > 1): ?>
            <div class="mobile-nav-lang">
                <?php foreach ($langCodes as $i => $code): ?>
                    <?php if ($i > 0): ?><span>|</span><?php endif; ?>
                    <a href="<?php echo $langLinks[$code]; ?>"<?php echo ($code === $currentLang) ? ' class="active"' : ''; ?>><?php echo strtoupper($code); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="mobile-theme-toggle">
                <button class="theme-toggle-mobile" data-theme-choice="light" aria-label="Light theme">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                </button>
                <button class="theme-toggle-mobile" data-theme-choice="dark" aria-label="Dark theme">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                </button>
            </div>
        </nav>
    </div>
