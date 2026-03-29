<?php
/**
 * Standard page template (Front Controller).
 *
 * Renders any page that has a JSON content file but no custom PHP template.
 * Expects the following variables to be set by the router:
 *   $lang     — language code (e.g. 'en')
 *   $slug     — page slug (e.g. 'example')
 *   $basePath — '' for root URLs, '../' for language-prefixed URLs
 */

if (!isset($lang) || !isset($slug)) {
    http_response_code(500);
    echo 'Missing $lang or $slug';
    return;
}

$contentPage = $lang . '_' . $slug;
$_includeBase = dirname(__DIR__) . '/';

// Load config if not already loaded
if (!defined('SITE_LANG_DEFAULT')) {
    require_once $_includeBase . 'admin/config.php';
}

$jsonPath = ($_includeBase) . 'content/pages/' . $contentPage . '.json';
if (!file_exists($jsonPath)) {
    http_response_code(404);
    include $_includeBase . '404.php';
    return;
}

$data = json_decode(file_get_contents($jsonPath), true);
if (!$data) {
    http_response_code(500);
    echo 'Failed to parse content file';
    return;
}

// Set template variables from JSON
$pageTitle = $data['title'] ?? ucfirst(str_replace('-', ' ', $slug));
$pageDescription = $data['description'] ?? '';
$currentLang = $lang;
$currentPage = $slug;

if (!isset($basePath)) {
    $basePath = '../';
}

// Site-specific page customizations (survives core updates).
// Use this to set $pageExternalStyles, $pageExternalScripts, $pageClass, etc.
$_sitePageHook = $_includeBase . 'includes/site-page-hook.php';
if (file_exists($_sitePageHook)) {
    include $_sitePageHook;
}

// Render page
include $_includeBase . 'includes/header.php';
include $_includeBase . 'includes/content-loader.php';
?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="content-inner">
            <?php echo renderAllSections($contentPage); ?>
            <?php if (!empty($data['contactForm']) || $slug === 'contact' || $slug === 'kontakt' || $slug === 'contacto'): ?>
                <?php include $_includeBase . 'includes/contact-form.php'; ?>
            <?php endif; ?>
        </div>
    </main>

<?php
include $_includeBase . 'includes/sidebar.php';
include $_includeBase . 'includes/footer.php';
