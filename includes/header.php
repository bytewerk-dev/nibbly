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

require_once __DIR__ . '/access-guard.php';
nibblyAccessEnforceMaintenance();
if (!empty($contentPage)) {
    nibblyAccessEnforceCurrentTemplatePage((string)$contentPage);
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

require_once __DIR__ . '/menu-helpers.php';
require_once __DIR__ . '/asset-helpers.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/seo-helper.php';
require_once __DIR__ . '/analytics-helper.php';

$_nibblyDevLogin = session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['nibbly_dev_login']);
$_allNavItems = $NAV_ITEMS[$currentLang] ?? $NAV_ITEMS[$defaultLang] ?? [];
$navItems = getMenuItems('header', $currentLang, $basePath ?? '', $_allNavItems);
$_headerMenuType = getMenuType('header');
$_homeHref = ($currentLang === $defaultLang) ? '.' : $currentLang . '/';
$_buildNavHref = function (array $item) use ($basePath, $isHomepage, $_headerMenuType, $_homeHref) {
    $href = (string)($item['href'] ?? '#');
    if ($_headerMenuType === 'one-page' && str_starts_with($href, '#') && $href !== '#') {
        return ($isHomepage ? '' : $basePath . $_homeHref) . $href;
    }
    return $basePath . $href;
};
$_navLinkAttrs = function (array $item, bool $isActive) use ($_headerMenuType) {
    $href = (string)($item['href'] ?? '');
    $classes = [];
    if ($isActive) $classes[] = 'active';
    $attrs = '';
    if ($_headerMenuType === 'one-page' && str_starts_with($href, '#') && $href !== '#') {
        $attrs .= ' data-nav-hash="' . htmlspecialchars(substr($href, 1), ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($classes) {
        $attrs .= ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '"';
    }
    return $attrs;
};

// Load site settings (used for favicon, theme colors, editor button style)
$_settingsPath = __DIR__ . '/../content/settings.json';
$_settings = [];
$_favicon = ltrim(defined('NIBBLY_DEFAULT_FAVICON') ? NIBBLY_DEFAULT_FAVICON : '/assets/images/favicon.svg', '/');
$_faviconPng = '';
if (file_exists($_settingsPath)) {
    $_settings = json_decode(file_get_contents($_settingsPath), true) ?: [];
    if (!empty($_settings['favicon'])) $_favicon = ltrim($_settings['favicon'], '/');
    if (!empty($_settings['favicon_png'])) $_faviconPng = ltrim($_settings['favicon_png'], '/');
}
$_rememberPublicTheme = !isset($_settings['privacy']['rememberPublicTheme']) || !empty($_settings['privacy']['rememberPublicTheme']);
$_editorFlat = isset($_settings['theme']['buttonGlow']) && !$_settings['theme']['buttonGlow'];
$_seoContext = nibblySeoContext([
    'contentPage' => $contentPage ?? null,
    'currentLang' => $currentLang,
    'currentPage' => $currentPage ?? '',
    'pageTitle' => $pageTitle ?? '',
    'pageDescription' => $pageDescription ?? '',
    'data' => isset($data) && is_array($data) ? $data : null,
]);
$_seoHealth = nibblySeoHealth($_seoContext);
$_seoJsonLd = nibblySeoJsonLd($_seoContext, $langLinks);
nibblyAnalyticsTrack($contentPage ?? null, $currentLang, $currentPage ?? null);

require_once __DIR__ . '/email-obfuscator.php';
nibblyStartEmailObfuscation();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>"<?php if ($_editorFlat) echo ' class="editor-flat"'; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo htmlspecialchars($_seoContext['description']); ?>">
    <meta name="robots" content="<?php echo htmlspecialchars($_seoContext['robots']); ?>">
    <meta name="generator" content="Nibbly <?php echo htmlspecialchars(nibblyVersion(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php if (!empty($_seoContext['canonical'])): ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($_seoContext['canonical']); ?>">
    <?php endif; ?>
    <?php foreach ($SITE_LANGUAGES as $_altCode => $_altName): ?>
    <?php if (isset($langLinks[$_altCode])): ?>
    <link rel="alternate" hreflang="<?php echo htmlspecialchars($_altCode); ?>" href="<?php echo htmlspecialchars(nibblySeoPageUrl($_altCode, $currentPageKey)); ?>">
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if (isset($langLinks[$defaultLang])): ?>
    <link rel="alternate" hreflang="x-default" href="<?php echo htmlspecialchars(nibblySeoPageUrl($defaultLang, $currentPageKey)); ?>">
    <?php endif; ?>
    <?php $_faviconType = pathinfo($_favicon, PATHINFO_EXTENSION) === 'svg' ? 'image/svg+xml' : 'image/png'; ?>
    <link rel="icon" href="<?php echo $basePath . htmlspecialchars($_favicon); ?>" type="<?php echo $_faviconType; ?>">
    <?php if ($_faviconPng): ?>
    <link rel="alternate icon" href="<?php echo $basePath . htmlspecialchars($_faviconPng); ?>" type="image/png">
    <link rel="apple-touch-icon" href="<?php echo $basePath . htmlspecialchars($_faviconPng); ?>">
    <?php else: ?>
    <link rel="apple-touch-icon" href="<?php echo $basePath . htmlspecialchars($_favicon); ?>">
    <?php endif; ?>

    <!-- Optional: uncomment to load custom fonts -->
    <!-- <link rel="stylesheet" href="<?php echo $basePath; ?>css/fonts.css"> -->

    <!-- Open Graph -->
    <meta property="og:title" content="<?php echo htmlspecialchars($_seoContext['ogTitle']); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($_seoContext['ogDescription']); ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="<?php echo htmlspecialchars($currentLang); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($_seoContext['canonical'] ?: nibblySeoCurrentUrl()); ?>">
    <?php if (!empty($_seoContext['ogImage'])): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($_seoContext['ogImage']); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <?php else: ?>
    <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <meta name="twitter:title" content="<?php echo htmlspecialchars($_seoContext['ogTitle']); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($_seoContext['ogDescription']); ?>">

    <title><?php echo htmlspecialchars($_seoContext['title'] ?: 'Website'); ?></title>
    <script type="application/ld+json"><?php echo json_encode($_seoJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>

    <?php echo nibblyCoreStyles($basePath); ?>
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
    // Build editor theme variables from settings (injected in footer.php AFTER inline-editor.css)
    $_editorVars = [];
    if (!empty($_settings['theme']['primaryColor'])) {
        $pc = $_settings['theme']['primaryColor'];
        $_editorVars[] = '--editor-primary:' . htmlspecialchars($pc);
        $r = hexdec(substr($pc, 1, 2)); $g = hexdec(substr($pc, 3, 2)); $b = hexdec(substr($pc, 5, 2));
        $pcHover = '#' . sprintf('%02x%02x%02x', max(0,$r-15), max(0,$g-15), max(0,$b-15));
        $pcLight = '#' . sprintf('%02x%02x%02x', min(255,$r+30), min(255,$g+30), min(255,$b+30));
        $pcLightHover = '#' . sprintf('%02x%02x%02x', min(255,$r+50), min(255,$g+50), min(255,$b+50));
        $_editorVars[] = '--editor-primary-hover:' . $pcHover;
        if (!empty($_settings['theme']['accentColor'])) {
            $_editorVars[] = '--editor-primary-light:' . htmlspecialchars($_settings['theme']['accentColor']);
        } else {
            $_editorVars[] = '--editor-primary-light:#' . sprintf('%02x%02x%02x', min(255,$r+40), min(255,$g+40), min(255,$b+40));
        }
        // Button background: gradient (glow) or flat color
        $buttonGlow = $_settings['theme']['buttonGlow'] ?? true;
        if ($buttonGlow) {
            $_editorVars[] = '--editor-btn-bg:radial-gradient(ellipse at 50% 0%, ' . $pcLight . ' 0%, ' . htmlspecialchars($pc) . ' 70%)';
            $_editorVars[] = '--editor-btn-bg-hover:radial-gradient(ellipse at 50% 0%, ' . $pcLightHover . ' 0%, ' . htmlspecialchars($pc) . ' 70%)';
        } else {
            $_editorVars[] = '--editor-btn-bg:' . htmlspecialchars($pc);
            $_editorVars[] = '--editor-btn-bg-hover:' . $pcHover;
        }
    }
    if (isset($_settings['theme']['buttonRadius'])) {
        $_editorVars[] = '--editor-btn-radius:' . intval($_settings['theme']['buttonRadius']) . 'px';
    }
    ?>

    <!-- Prevent FOUC: apply public theme preference before paint -->
    <script>
    (function(){var remember=<?php echo $_rememberPublicTheme ? 'true' : 'false'; ?>;try{var t=remember?localStorage.getItem('site-theme'):null;if(t==='system')t=null;if(t!=='dark'&&t!=='light')t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();
    </script>
</head>
<body class="<?php echo $isHomepage ? 'page-home' : 'page-subpage'; ?><?php echo isset($pageClass) ? ' ' . $pageClass : ''; ?><?php echo $_nibblyDevLogin ? ' has-dev-login' : ''; ?>"<?php echo $_nibblyDevLogin ? ' data-dev-login="true"' : ''; ?>>
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <!-- Fixed Header -->
    <header class="site-header" id="siteHeader">
        <div class="header-inner">
            <!-- Logo -->
            <?php
            $_headerLogo = $_settings['logo'] ?? $_settings['branding']['logo'] ?? '';
            $_headerLogo = ltrim($_headerLogo, '/');
            $_headerLogoDark = ltrim($_settings['branding']['logoDark'] ?? '', '/');
            // Treat a logo that points at the favicon as "no separate logo set"
            if ($_headerLogo === $_favicon) $_headerLogo = '';
            $_siteName = $_settings['branding']['name'] ?? (defined('SITE_NAME') ? SITE_NAME : '');
            $_logoDisplay = $_settings['branding']['logoDisplay'] ?? 'both';
            $_logoSize = $_settings['branding']['logoSize'] ?? 'medium';
            if (!in_array($_logoSize, ['small', 'medium', 'large'], true)) $_logoSize = 'medium';
            $_showFavicon = !$_headerLogo && $_logoDisplay !== 'text';
            $_showText    = $_siteName && !$_headerLogo && $_logoDisplay !== 'favicon';
            ?>
            <a href="<?php echo $basePath; ?>." class="site-logo site-logo--size-<?php echo htmlspecialchars($_logoSize); ?>" aria-label="Home">
                <?php
                if ($_headerLogo):
                    $_headerLogoDarkSrc = $_headerLogoDark ?: $_headerLogo;
                    $_hasDarkLogo = ($_headerLogoDark && $_headerLogoDark !== $_headerLogo);
                ?>
                <img class="site-logo-img site-logo-img--light" src="<?php echo $basePath . htmlspecialchars($_headerLogo); ?>" alt="<?php echo htmlspecialchars($_siteName); ?>">
                <?php if ($_hasDarkLogo): ?>
                <img class="site-logo-img site-logo-img--dark" src="<?php echo $basePath . htmlspecialchars($_headerLogoDarkSrc); ?>" alt="<?php echo htmlspecialchars($_siteName); ?>" aria-hidden="true">
                <?php endif; ?>
                <?php elseif ($_showFavicon): ?>
                <?php echo nibblyIconOrImg($basePath . $_favicon, $_siteName, ['width' => 32, 'height' => 32, 'class' => 'site-logo-img site-logo-icon']); ?>
                <?php endif; ?>
                <?php if ($_showText): ?>
                <span class="site-logo-text" title="<?php echo htmlspecialchars($_siteName); ?>"><?php echo htmlspecialchars($_siteName); ?></span>
                <?php endif; ?>
            </a>

            <!-- Desktop Navigation -->
            <nav class="nav-main" aria-label="Primary navigation">
                <ul class="nav-list">
                    <?php foreach ($navItems as $item):
                        $hasChildren = !empty($item['children']);
                        $isHashItem = $_headerMenuType === 'one-page' && str_starts_with((string)($item['href'] ?? ''), '#');
                        $isActive = !$isHashItem && (($currentPage ?? '') === ($item['page'] ?? ''));
                        // Parent is active if any child matches
                        if ($hasChildren && !$isActive) {
                            foreach ($item['children'] as $_child) {
                                $_childHash = $_headerMenuType === 'one-page' && str_starts_with((string)($_child['href'] ?? ''), '#');
                                if (!$_childHash && ($currentPage ?? '') === ($_child['page'] ?? '')) { $isActive = true; break; }
                            }
                        }
                    ?>
                    <li<?php echo $hasChildren ? ' class="nav-item--has-children"' : ''; ?>>
                        <a href="<?php echo htmlspecialchars($_buildNavHref($item)); ?>"<?php echo $_navLinkAttrs($item, $isActive); ?>>
                            <?php echo htmlspecialchars($item['label']); ?>
                            <?php if ($hasChildren): ?><svg class="nav-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg><?php endif; ?>
                        </a>
                        <?php if ($hasChildren): ?>
                        <ul class="nav-dropdown">
                            <?php foreach ($item['children'] as $child):
                                $_childHash = $_headerMenuType === 'one-page' && str_starts_with((string)($child['href'] ?? ''), '#');
                                $_childActive = !$_childHash && (($currentPage ?? '') === ($child['page'] ?? ''));
                            ?>
                            <li>
                                <a href="<?php echo htmlspecialchars($_buildNavHref($child)); ?>"<?php echo $_navLinkAttrs($child, $_childActive); ?>>
                                    <?php echo htmlspecialchars($child['label']); ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Language Selector -->
                <?php if (count($SITE_LANGUAGES) > 1): ?>
                <div class="nav-lang" aria-label="Language selection">
                    <?php $langCodes = array_keys($SITE_LANGUAGES); ?>
                    <?php foreach ($langCodes as $i => $code): ?>
                        <?php if ($i > 0): ?><span class="lang-separator">|</span><?php endif; ?>
                        <a href="<?php echo $langLinks[$code]; ?>" class="lang-link<?php echo ($code === $currentLang) ? ' active' : ''; ?>"<?php echo ($code === $currentLang) ? ' aria-current="true"' : ''; ?>><?php echo strtoupper($code); ?></a>
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
            <button class="hamburger" id="hamburger" aria-label="Open menu" aria-expanded="false" aria-controls="mobileNavOverlay">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
    </header>

    <!-- Mobile Navigation Overlay -->
    <div class="mobile-nav-overlay" id="mobileNavOverlay" aria-hidden="true">
        <nav class="mobile-nav" aria-label="Mobile navigation">
            <ul class="mobile-nav-list">
                <?php foreach ($navItems as $item):
                    $hasChildren = !empty($item['children']);
                    $isHashItem = $_headerMenuType === 'one-page' && str_starts_with((string)($item['href'] ?? ''), '#');
                    $isActive = !$isHashItem && (($currentPage ?? '') === ($item['page'] ?? ''));
                    if ($hasChildren && !$isActive) {
                        foreach ($item['children'] as $_child) {
                            $_childHash = $_headerMenuType === 'one-page' && str_starts_with((string)($_child['href'] ?? ''), '#');
                            if (!$_childHash && ($currentPage ?? '') === ($_child['page'] ?? '')) { $isActive = true; break; }
                        }
                    }
                ?>
                <li<?php echo $hasChildren ? ' class="mobile-nav-item--parent"' : ''; ?>>
                    <a href="<?php echo htmlspecialchars($_buildNavHref($item)); ?>"<?php echo $_navLinkAttrs($item, $isActive); ?>>
                        <?php echo htmlspecialchars($item['label']); ?>
                    </a>
                    <?php if ($hasChildren): ?>
                    <ul class="mobile-nav-children">
                        <?php foreach ($item['children'] as $child):
                            $_childHash = $_headerMenuType === 'one-page' && str_starts_with((string)($child['href'] ?? ''), '#');
                            $_childActive = !$_childHash && (($currentPage ?? '') === ($child['page'] ?? ''));
                        ?>
                        <li>
                            <a href="<?php echo htmlspecialchars($_buildNavHref($child)); ?>"<?php echo $_navLinkAttrs($child, $_childActive); ?>>
                                <?php echo htmlspecialchars($child['label']); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if (count($SITE_LANGUAGES) > 1): ?>
            <div class="mobile-nav-lang">
                <?php foreach ($langCodes as $i => $code): ?>
                    <?php if ($i > 0): ?><span>|</span><?php endif; ?>
                    <a href="<?php echo $langLinks[$code]; ?>"<?php echo ($code === $currentLang) ? ' class="active" aria-current="true"' : ''; ?>><?php echo strtoupper($code); ?></a>
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
