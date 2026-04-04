<?php
/**
 * Simple page template — renders all sections from JSON.
 *
 * For standard pages, you don't need a PHP template at all.
 * Just create content/pages/{lang}_{slug}.json and the front controller
 * serves it automatically. This file only exists as an example of
 * how a PHP template wrapper would look if you needed one.
 *
 * Preferred: php cli/make.php --slug=your-page --lang=en --title="Page Title"
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
            <?php echo renderAllSections($contentPage); ?>
        </div>
    </main>

<?php include '../includes/footer.php'; ?>
