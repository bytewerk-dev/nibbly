<?php
/**
 * Shared news post renderer.
 *
 * Expects $currentLang, $_GET['slug'] and $basePath to be set by the router
 * or a legacy language wrapper.
 *
 * Post fields beyond title, date, author, excerpt and image:
 * - "subtitle": kicker above the title
 * - "sections": the body as a list of blocks (replaces the "content" HTML string); "aside": blocks of
 *   the sidebar. Both are rendered by nibblyNewsRenderBlocks() (includes/news-helper.php), which also
 *   adds the hooks of the visual editor for logged-in admins.
 * - "cover" ({src, alt, label}): image at the top of the sidebar, e.g. the issue a text appeared in;
 *   "sidebar": true shows the sidebar (with the excerpt) even without a cover. The sidebar is also
 *   shown as soon as "aside" holds blocks.
 * - "imageLayout": column (title image floats right in the text), full (full width), hidden (previews only)
 * - "comments": false closes the comments of this post (includes/comments.php refuses submissions;
 *   a template that renders nibblyCommentsRender() should check the field too).
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
$pageDescription = trim(strip_tags((string)($post['excerpt'] ?? '')));
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
// Hooks of the visual editor (js/inline-editor.js, enableNewsPostEditing)
$_newsField = static fn(string $name): string => $_isAdmin ? ' data-news-field="' . $name . '"' : '';

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

// Body: block list "sections" (with editor hooks for admins), or the "content" HTML string
$_postBody = '';
$_hasBlocks = !isset($post['content']) || !is_string($post['content']);
if ($_hasBlocks) {
    $_postBody = nibblyNewsRenderBlocks(is_array($post['sections'] ?? null) ? $post['sections'] : [], $_isAdmin ? 'sections' : '');
} else {
    $_postBody = sanitizeHtml($post['content']);
}

// Sidebar: cover, excerpt and "aside" blocks
$_asideBlocks = is_array($post['aside'] ?? null) ? array_values(array_filter($post['aside'], 'is_array')) : [];
$_cover = is_array($post['cover'] ?? null) && trim((string)($post['cover']['src'] ?? '')) !== '' ? $post['cover'] : null;
$_hasAside = $_cover !== null || !empty($post['sidebar']) || $_asideBlocks;
$_excerptHtml = static function (string $excerpt): string {
    $excerpt = trim($excerpt);
    if ($excerpt === '') return '';
    if (strip_tags($excerpt) === $excerpt) return '<p>' . htmlspecialchars($excerpt) . '</p>';
    return sanitizeHtml(str_starts_with($excerpt, '<') ? $excerpt : '<p>' . $excerpt . '</p>');
};

// Title image: column (default), full width or hidden (admins still see it dimmed in edit mode)
$_imageLayout = in_array($post['imageLayout'] ?? '', ['full', 'hidden'], true) ? $post['imageLayout'] : 'column';
$_showImage = !empty($post['image']) && ($_imageLayout !== 'hidden' || $_isAdmin);
?>

    <main class="main-content">
        <div class="content-inner">
            <article class="news-post-page<?php echo $_hasAside ? ' news-post-page--aside' : ''; ?>" data-news-post="<?php echo htmlspecialchars($post['id'] ?? $slug); ?>">
                <a href="<?php echo htmlspecialchars($basePath . $_newsPath); ?>" class="news-post-page__back"><?php echo $_backLabel; ?></a>

                <header class="news-post-page__header">
                    <?php if (trim((string)($post['subtitle'] ?? '')) !== '' || $_isAdmin): ?>
                    <p class="news-post-page__kicker"<?php echo $_newsField('subtitle'); ?> data-placeholder="…"><?php echo htmlspecialchars(trim((string)($post['subtitle'] ?? ''))); ?></p>
                    <?php endif; ?>
                    <?php if ($_dateRaw !== ''): ?>
                    <time class="news-post-page__date"<?php if ($_dateMachine !== '') echo ' datetime="' . htmlspecialchars($_dateMachine) . '"'; ?>><?php echo $_formattedDate; ?></time>
                    <?php endif; ?>
                    <h1 class="news-post-page__title"<?php echo $_newsField('title'); ?>><?php echo htmlspecialchars($pageTitle); ?></h1>
                    <?php if (!empty($post['author'])): ?>
                    <span class="news-post-page__author"<?php echo $_newsField('author'); ?>><?php echo htmlspecialchars($post['author']); ?></span>
                    <?php endif; ?>
                    <?php $_categories = nibblyNewsSlugList($post['categories'] ?? []); if ($_categories): ?>
                    <ul class="news-post-page__categories" aria-label="<?php echo htmlspecialchars(nibblyPublicString('news_categories', $currentLang)); ?>">
                        <?php foreach ($_categories as $_category): ?>
                        <li><a class="news-chip news-chip--category" href="<?php echo htmlspecialchars($basePath . ltrim(nibblyNewsArchiveUrl('category', $_category, $currentLang), '/')); ?>"><?php echo htmlspecialchars(nibblyNewsCategoryName($_category)); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </header>

                <?php if ($_hasAside): ?>
                <div class="news-post-page__layout">
                <aside class="news-post-page__aside">
                    <?php if ($_cover): ?>
                    <figure class="news-post-page__cover">
                        <a href="<?php echo htmlspecialchars((string)$_cover['src']); ?>" data-lightbox="cover"><img src="<?php echo htmlspecialchars((string)$_cover['src']); ?>" alt="<?php echo htmlspecialchars((string)($_cover['alt'] ?? '')); ?>"<?php echo $_newsField('cover'); ?>></a>
                        <?php if (!empty($_cover['label'])): ?>
                        <figcaption><?php echo htmlspecialchars((string)$_cover['label']); ?></figcaption>
                        <?php endif; ?>
                    </figure>
                    <?php elseif ($_isAdmin): ?>
                    <figure class="news-post-page__cover news-post-page__cover--empty"><a href="#" data-lightbox="cover" onclick="return false"><img src="<?php echo htmlspecialchars($basePath); ?>assets/images/placeholder-image.webp" alt="" data-news-field="cover"></a></figure>
                    <?php endif; ?>
                    <?php if (!empty($post['excerpt']) || $_isAdmin): ?>
                    <div class="news-post-page__aside-excerpt"<?php echo $_newsField('excerpt'); ?>>
                        <?php echo $_excerptHtml((string)($post['excerpt'] ?? '')); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($_asideBlocks || $_isAdmin): // admins see the (empty) list to add blocks ?>
                    <div class="news-post-page__aside-blocks"<?php echo $_isAdmin ? ' data-news-blocks="aside"' : ''; ?>>
                        <?php echo nibblyNewsRenderBlocks($_asideBlocks, $_isAdmin ? 'aside' : ''); ?>
                    </div>
                    <?php endif; ?>
                </aside>
                <div class="news-post-page__main">
                <?php endif; ?>

                <?php if ($_showImage): ?>
                <figure class="news-post-page__hero news-post-page__hero--<?php echo $_imageLayout; ?>"<?php echo $_newsField('image'); ?><?php echo $_imageLayout === 'hidden' ? ' data-hidden-label="' . htmlspecialchars(nibblyPublicString('news_image_hidden', $currentLang)) . '"' : ''; ?>>
                    <?php // visitors get sizes and srcset; admins keep a plain tag, the visual editor swaps its src
                    echo $_isAdmin
                        ? '<img src="' . htmlspecialchars((string)$post['image']) . '" alt="' . htmlspecialchars((string)($post['imageAlt'] ?? $pageTitle)) . '">'
                        : nibblyImageTag((string)$post['image'], (string)($post['imageAlt'] ?? $pageTitle), ['lazy' => false]);
                    echo nibblyAiLabelHtml((string)$post['image']); ?>
                    <?php if (!empty($post['imageCaption'])): ?>
                    <figcaption><?php echo htmlspecialchars((string)$post['imageCaption']); ?></figcaption>
                    <?php endif; ?>
                </figure>
                <?php endif; ?>

                <div class="news-post-page__content"<?php echo $_hasBlocks ? ($_isAdmin ? ' data-news-blocks="sections"' : '') : $_newsField('content'); ?>>
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

                <?php if ($_hasAside): ?>
                </div><!-- /.news-post-page__main -->
                </div><!-- /.news-post-page__layout -->
                <?php endif; ?>
            </article>
        </div>
    </main>

<?php if (!empty($newsPostJson)): ?>
<script>window.__cmsNewsPost = <?php echo $newsPostJson; ?>;</script>
<?php endif; ?>
<?php include $_includeBase . 'includes/footer.php'; ?>
