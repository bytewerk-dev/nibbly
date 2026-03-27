<?php
/**
 * Simple page template — renders all sections from JSON.
 *
 * Usage: Copy to {lang}/your-page.php and update the variables below.
 * Create matching content at content/pages/{lang}_your-page.json.
 */
$pageTitle = 'Page Title';
$pageDescription = 'Page description for search engines.';
$currentLang = 'en';       // Language code
$currentPage = 'your-page'; // Page slug (for nav highlighting)
$contentPage = 'en_your-page'; // Content file name (without .json)
$basePath = '../';

include '../includes/header.php';
include '../includes/content-loader.php';
?>

    <main class="main-content">
        <div class="content-inner">
            <?php echo renderAllSections('en_your-page'); ?>
        </div>
    </main>

<?php include '../includes/footer.php'; ?>
