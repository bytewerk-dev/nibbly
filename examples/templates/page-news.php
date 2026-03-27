<?php
/**
 * News listing page template.
 *
 * Displays all news posts from content/news/ as a card grid.
 * Individual posts are handled by the front controller (route.php).
 *
 * Usage: Copy to {lang}/news.php and update the variables.
 */
$pageTitle = 'News';
$pageDescription = 'Latest updates and articles.';
$currentLang = 'en';
$currentPage = 'news';
$contentPage = 'en_news';
$basePath = '../';

include '../includes/header.php';
include '../includes/content-loader.php';
?>

    <main class="main-content">
        <div class="content-inner">
            <h1 class="page-title"><?php echo editableText($contentPage, 'title', 'News'); ?></h1>
            <?php echo renderNewsList($currentLang, $basePath); ?>
        </div>
    </main>

<?php include '../includes/footer.php'; ?>
