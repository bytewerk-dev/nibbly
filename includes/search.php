<?php
/**
 * Core search page: a form, the result list and pagination.
 * route.php includes it with $currentLang and $basePath for the path settings search.base
 * (/search by default, /{lang}/search for other languages). A site replaces it with its own
 * {lang}/search.php, which receives the same variables and can call nibblySearchQuery() itself.
 */

$_includeBase = dirname(__DIR__) . '/';
if (!defined('SITE_LANG_DEFAULT') && is_file($_includeBase . 'admin/config.php')) {
    require_once $_includeBase . 'admin/config.php';
}
include_once $_includeBase . 'includes/content-loader.php';
require_once __DIR__ . '/search-helper.php';
require_once __DIR__ . '/public-strings.php';

$currentLang = $currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
$currentPage = 'search';
$basePath = $basePath ?? '';

$searchQuery = $searchQuery ?? nibblySearchRequestQuery();
$searchResult = $searchResult ?? nibblySearchQuery($searchQuery, ['lang' => $currentLang, 'page' => nibblyNewsCurrentPage()]);
$searchSettings = nibblySearchSettings();
$_t = static fn(string $key, array $params = []): string => strtr(nibblyPublicString($key, $currentLang), array_combine(array_map(static fn($k) => '{' . $k . '}', array_keys($params)), array_values($params)));

$pageTitle = $searchQuery !== '' ? $_t('search_results_for', ['query' => $searchQuery]) : $_t('search_title');
$pageDescription = $_t('search_intro');
$data = ['lang' => $currentLang, 'title' => $pageTitle, 'description' => $pageDescription, 'seo' => ['robots' => 'noindex, follow']];
$searchListUrl = nibblySearchUrl($currentLang);

include $_includeBase . 'includes/header.php';
?>

    <main class="main-content" id="main-content">
        <div class="content-inner">
            <div class="search-page">
                <header class="search-page__header">
                    <h1 class="search-page__title"><?php echo htmlspecialchars($_t('search_title')); ?></h1>
                    <p class="search-page__intro"><?php echo htmlspecialchars($pageDescription); ?></p>
                    <?php echo nibblySearchForm(['value' => $searchQuery, 'class' => 'search-form--page', 'id' => 'search-page-input', 'lang' => $currentLang, 'basePath' => $basePath]); ?>
                </header>

                <?php if ($searchResult['tooShort']): ?>
                <p class="search-page__status"><?php echo htmlspecialchars($_t('search_too_short', ['min' => (string)$searchSettings['minLength']])); ?></p>
                <?php elseif ($searchQuery !== ''): ?>
                <p class="search-page__status" aria-live="polite">
                    <?php echo htmlspecialchars($searchResult['total'] === 1 ? $_t('search_count_one') : $_t('search_count', ['count' => (string)$searchResult['total']])); ?>
                </p>
                <?php if ($searchResult['total'] === 0): ?>
                <p class="search-page__empty"><?php echo htmlspecialchars($_t('search_none')); ?></p>
                <?php else: ?>
                <ol class="search-results" start="<?php echo (int)(($searchResult['page'] - 1) * $searchSettings['perPage'] + 1); ?>">
                    <?php foreach ($searchResult['results'] as $_hit):
                        $_href = $basePath . ltrim((string)$_hit['url'], '/');
                        $_dateLabel = '';
                        if (!empty($_hit['date']) && $_hit['type'] === 'post') {
                            try { $_dateLabel = nibblyFormatNewsDate(new DateTime($_hit['date']), $currentLang); } catch (Exception $e) { $_dateLabel = ''; }
                        }
                    ?>
                    <li class="search-result search-result--<?php echo htmlspecialchars($_hit['type']); ?>">
                        <div class="search-result__meta">
                            <span class="search-result__type"><?php echo htmlspecialchars($_t('search_type_' . $_hit['type'])); ?></span>
                            <?php if ($_dateLabel !== ''): ?><time class="search-result__date" datetime="<?php echo htmlspecialchars($_hit['date']); ?>"><?php echo $_dateLabel; ?></time><?php endif; ?>
                            <?php foreach ($_hit['categories'] ?? [] as $_category): ?>
                            <span class="search-result__category"><?php echo htmlspecialchars(nibblyNewsCategoryName($_category)); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <h2 class="search-result__title"><a href="<?php echo htmlspecialchars($_href); ?>"><?php echo nibblySearchHighlight((string)$_hit['title'], $searchResult['terms']); ?></a></h2>
                        <?php if (!empty($_hit['snippet'])): ?>
                        <p class="search-result__snippet"><?php echo $_hit['snippet']; ?></p>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($searchResult['pages'] > 1): ?>
                <?php
                    // The pagination helper keeps other query keys, so the search term travels with the page links
                    echo nibblyNewsPagination($searchResult['total'], $searchResult['page'], $searchSettings['perPage'], $searchListUrl . '?' . http_build_query([$searchSettings['param'] => $searchQuery]), $currentLang);
                ?>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

<?php include $_includeBase . 'includes/footer.php'; ?>
