<?php
/**
 * Shared news post renderer.
 *
 * Expects $currentLang, $_GET['slug'] and $basePath to be set by the router
 * or a legacy language wrapper.
 */

$_includeBase = dirname(__DIR__) . '/';

if (!defined('SITE_LANG_DEFAULT') && is_file($_includeBase . 'admin/config.php')) {
    require_once $_includeBase . 'admin/config.php';
}

include_once $_includeBase . 'includes/content-loader.php';
if (isset($_GET['nb_agent_preview']) && is_file($_includeBase . 'includes/ai/agent/preview.php')) {
    require_once $_includeBase . 'includes/ai/agent/preview.php'; // owner-only preview of an AI change set
    nibblyAgentPreviewBoot();
}

$currentLang = $currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
$currentPage = 'news';
$basePath = $basePath ?? ($currentLang === (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en') ? '../' : '../../');

$_defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
// The listing the back links point to (archive base, or /news); the post URL itself follows news.urlPattern
$_newsPath = ltrim(nibblyNewsListUrl($currentLang), '/');
require_once __DIR__ . '/public-strings.php';
$_backLabel = nibblyPublicString('news_back', $currentLang);

$slug = $_GET['slug'] ?? '';
if (empty($slug) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    header('Location: ' . $basePath . $_newsPath);
    exit;
}

// Found by the "slug" field (never the file name); an unapplied preview change set overlays the stored post.
$post = $post ?? nibblyNewsFindPost($slug, $currentLang);

if (!$post) {
    http_response_code(404);
    $pageTitle = 'Not Found';
    $pageDescription = '';
    include $_includeBase . 'includes/header.php';
    echo '<main class="main-content"><div class="content-inner"><h1>Not found</h1><p><a href="' . htmlspecialchars($basePath . $_newsPath) . '">' . $_backLabel . '</a></p></div></main>';
    include $_includeBase . 'includes/footer.php';
    exit;
}

$pageTitle = (string)($post['title'] ?? '');
$pageDescription = (string)($post['excerpt'] ?? '');
// SEO context of the post: canonical at its pattern URL (the date part is tolerant, so every variant
// names the same address), optional "seo" overrides from the record (title, description, ogImage)
$_postSeo = is_array($post['seo'] ?? null) ? $post['seo'] : [];
require_once __DIR__ . '/seo-helper.php';
$data = [
    'lang' => $currentLang,
    'title' => $pageTitle,
    'description' => $pageDescription,
    'seo' => $_postSeo + ['canonical' => rtrim(nibblySeoBaseUrl(), '/') . nibblyNewsUrl($post, $currentLang), 'ogImage' => (string)($post['image'] ?? '')],
];

$_isAdmin = isAdminLoggedIn();
$newsPostJson = $_isAdmin ? json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) : '';

include $_includeBase . 'includes/header.php';

$_dateRaw = (string)($post['date'] ?? '');
$_dateMachine = $_dateRaw;
$_formattedDate = htmlspecialchars($_dateRaw);
if ($_dateRaw !== '') {
    try {
        $_dateObj = new DateTime($_dateRaw);
        $_dateMachine = $_dateObj->format('Y-m-d');
        $_formattedDate = nibblyFormatNewsDate($_dateObj, $currentLang, true);
    } catch (Exception $e) {
        $_dateMachine = '';
    }
}

$_postBody = '';
if (isset($post['content']) && is_string($post['content'])) {
    $_postBody = sanitizeHtml($post['content']);
} elseif (!empty($post['sections']) && is_array($post['sections'])) {
    foreach ($post['sections'] as $_sectionIndex => $_section) {
        if (is_array($_section)) {
            $_postBody .= renderSection($_section, $_sectionIndex, false);
        }
    }
}
?>

    <main class="main-content">
        <div class="content-inner">
            <article class="news-post-page" data-news-post="<?php echo htmlspecialchars($post['id'] ?? $slug); ?>">
                <a href="<?php echo htmlspecialchars($basePath . $_newsPath); ?>" class="news-post-page__back"><?php echo $_backLabel; ?></a>

                <?php if (!empty($post['image'])): ?>
                <div class="news-post-page__hero">
                    <img src="<?php echo htmlspecialchars($post['image']); ?>" alt="<?php echo htmlspecialchars($pageTitle); ?>"><?php echo nibblyAiLabelHtml((string)$post['image']); ?>
                </div>
                <?php endif; ?>

                <header class="news-post-page__header">
                    <?php if ($_dateRaw !== ''): ?>
                    <time class="news-post-page__date"<?php if ($_dateMachine !== '') echo ' datetime="' . htmlspecialchars($_dateMachine) . '"'; ?>><?php echo $_formattedDate; ?></time>
                    <?php endif; ?>
                    <h1 class="news-post-page__title"><?php echo htmlspecialchars($pageTitle); ?></h1>
                    <?php if (!empty($post['author'])): ?>
                    <span class="news-post-page__author"><?php echo htmlspecialchars($post['author']); ?></span>
                    <?php endif; ?>
                    <?php $_categories = nibblyNewsSlugList($post['categories'] ?? []); if ($_categories): ?>
                    <ul class="news-post-page__categories" aria-label="<?php echo htmlspecialchars(nibblyPublicString('news_categories', $currentLang)); ?>">
                        <?php foreach ($_categories as $_category): ?>
                        <li><a class="news-chip news-chip--category" href="<?php echo htmlspecialchars($basePath . ltrim(nibblyNewsArchiveUrl('category', $_category, $currentLang), '/')); ?>"><?php echo htmlspecialchars(nibblyNewsCategoryName($_category)); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </header>

                <div class="news-post-page__content">
                    <?php echo $_postBody; ?>
                </div>

                <footer class="news-post-page__footer">
                    <?php $_tags = nibblyNewsSlugList($post['tags'] ?? []); if ($_tags): ?>
                    <ul class="news-post-page__tags" aria-label="<?php echo htmlspecialchars(nibblyPublicString('news_tags', $currentLang)); ?>">
                        <?php foreach ($_tags as $_tag): ?>
                        <li><a class="news-chip news-chip--tag" href="<?php echo htmlspecialchars($basePath . ltrim(nibblyNewsArchiveUrl('tag', $_tag, $currentLang), '/')); ?>"><?php echo htmlspecialchars(nibblyNewsTagName($_tag)); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($basePath . $_newsPath); ?>" class="news-post-page__back"><?php echo $_backLabel; ?></a>
                </footer>
            </article>
        </div>
    </main>

<?php if (!empty($newsPostJson)): ?>
<script>window.__cmsNewsPost = <?php echo $newsPostJson; ?>;</script>
<?php endif; ?>
<?php include $_includeBase . 'includes/footer.php'; ?>
