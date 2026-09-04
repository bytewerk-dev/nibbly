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

$currentLang = $currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
$currentPage = 'news';
$basePath = $basePath ?? ($currentLang === (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en') ? '../' : '../../');

$_defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
$_newsPath = $currentLang === $_defaultLang ? 'news' : $currentLang . '/news';
$_backLabels = [
    'de' => '&larr; Zurück zu Neuigkeiten',
    'es' => '&larr; Volver a noticias',
    'fr' => '&larr; Retour aux actualités',
    'it' => '&larr; Torna alle news',
    'pt' => '&larr; Voltar às notícias',
    'tr' => '&larr; Haberlere dön',
    'cs' => '&larr; Zpět na novinky',
    'pl' => '&larr; Wróć do aktualności',
];
$_backLabel = $_backLabels[$currentLang] ?? '&larr; Back to News';

$slug = $_GET['slug'] ?? '';
if (empty($slug) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    header('Location: ' . $basePath . $_newsPath);
    exit;
}

$post = null;
$newsDir = $_includeBase . 'content/news/';
if (is_dir($newsDir)) {
    foreach (glob($newsDir . '*.json') as $file) {
        $p = json_decode(file_get_contents($file), true);
        if (!is_array($p)) continue;
        if (($p['slug'] ?? '') !== $slug) continue;
        if (!empty($p['hidden'])) continue;
        $postLang = $p['lang'] ?? $_defaultLang;
        if ($postLang !== $currentLang) continue;
        $post = $p;
        break;
    }
}

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
        $_formattedDate = $_dateObj->format('F j, Y');
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
                    <img src="<?php echo htmlspecialchars($post['image']); ?>" alt="<?php echo htmlspecialchars($pageTitle); ?>">
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
                </header>

                <div class="news-post-page__content">
                    <?php echo $_postBody; ?>
                </div>

                <footer class="news-post-page__footer">
                    <a href="<?php echo htmlspecialchars($basePath . $_newsPath); ?>" class="news-post-page__back"><?php echo $_backLabel; ?></a>
                </footer>
            </article>
        </div>
    </main>

<?php if (!empty($newsPostJson)): ?>
<script>window.__cmsNewsPost = <?php echo $newsPostJson; ?>;</script>
<?php endif; ?>
<?php include $_includeBase . 'includes/footer.php'; ?>
