<?php
/**
 * Core archive page for news posts: all posts, one category, one tag, one year or one decade,
 * paginated. route.php includes it with $newsArchive (type, value, lang), $currentLang and $basePath.
 * A site replaces it with its own {lang}/news-archive.php, which receives the same variables and
 * can call renderNewsList() with the filters below.
 */

$_includeBase = dirname(__DIR__) . '/';
if (!defined('SITE_LANG_DEFAULT') && is_file($_includeBase . 'admin/config.php')) {
    require_once $_includeBase . 'admin/config.php';
}
include_once $_includeBase . 'includes/content-loader.php';
require_once __DIR__ . '/public-strings.php';

$newsArchive = $newsArchive ?? ['type' => 'list', 'value' => '', 'lang' => $currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en')];
$currentLang = $currentLang ?? $newsArchive['lang'];
$currentPage = 'news';
$basePath = $basePath ?? '../';

// Filters for renderNewsList(): the archive type names the filter key
$newsFilters = [];
if (in_array($newsArchive['type'], ['category', 'tag', 'year', 'decade'], true)) {
    $newsFilters[$newsArchive['type']] = $newsArchive['value'];
}
$newsListUrl = $newsArchive['type'] === 'list' ? nibblyNewsListUrl($currentLang) : nibblyNewsArchiveUrl($newsArchive['type'], $newsArchive['value'], $currentLang);
$newsPageNumber = nibblyNewsCurrentPage();
$newsTotal = loadNewsPosts(0, $currentLang, $newsFilters + ['withTotal' => true, 'page' => 1, 'perPage' => 1])['total'];
$newsPerPage = nibblyNewsSettings()['perPage'];

$pageTitle = nibblyNewsArchiveTitle($newsArchive['type'], $newsArchive['value'], $currentLang);
if ($newsPageNumber > 1) {
    $pageTitle .= ' – ' . str_replace('{page}', (string)$newsPageNumber, nibblyPublicString('pagination_page', $currentLang));
}
$pageDescription = $newsArchive['type'] === 'category' ? (string)(nibblyNewsCategory($newsArchive['value'])['description'] ?? '') : '';
$pageHeadExtra = nibblyNewsPaginationHeadLinks($newsTotal, $newsPageNumber, $newsPerPage, $newsListUrl);
// A defined category is a real page even while empty; a tag, year or decade without posts is not found
if ($newsTotal === 0 && !in_array($newsArchive['type'], ['list', 'category'], true)) {
    http_response_code(404);
}

include $_includeBase . 'includes/header.php';
?>

    <main class="main-content">
        <div class="content-inner">
            <div class="news-page news-archive news-archive--<?php echo htmlspecialchars($newsArchive['type']); ?>">
                <div class="news-page__header">
                    <h1 class="news-page__title"><?php echo htmlspecialchars(nibblyNewsArchiveTitle($newsArchive['type'], $newsArchive['value'], $currentLang)); ?></h1>
                    <?php if ($pageDescription !== ''): ?>
                    <p class="news-page__intro"><?php echo htmlspecialchars($pageDescription); ?></p>
                    <?php endif; ?>
                </div>
                <?php if (nibblyNewsCategories()): ?>
                <nav class="news-archive__categories" aria-label="<?php echo htmlspecialchars(nibblyPublicString('news_categories', $currentLang)); ?>">
                    <a class="news-chip<?php echo $newsArchive['type'] === 'list' ? ' news-chip--active' : ''; ?>" href="<?php echo htmlspecialchars(nibblyNewsListUrl($currentLang)); ?>"<?php echo $newsArchive['type'] === 'list' ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars(nibblyPublicString('news_archive_all', $currentLang)); ?></a>
                    <?php foreach (nibblyNewsCategories() as $category): $active = $newsArchive['type'] === 'category' && $newsArchive['value'] === $category['slug']; ?>
                    <a class="news-chip news-chip--category<?php echo $active ? ' news-chip--active' : ''; ?>" href="<?php echo htmlspecialchars(nibblyNewsArchiveUrl('category', $category['slug'], $currentLang)); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></a>
                    <?php endforeach; ?>
                </nav>
                <?php endif; ?>
                <?php echo renderNewsList(0, $currentLang, $newsFilters + ['paginate' => true, 'page' => $newsPageNumber, 'baseUrl' => $newsListUrl]); ?>
            </div>
        </div>
    </main>

<?php include $_includeBase . 'includes/footer.php'; ?>
