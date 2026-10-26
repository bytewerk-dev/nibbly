<?php
/**
 * Page of a single published profile: /people/{slug} or /{lang}/people/{slug}.
 *
 * Expects $currentLang, $basePath and $person (found by the router) to be set.
 * The layout reuses the news article classes (news-post-page__*), so both look alike.
 */

$_includeBase = dirname(__DIR__) . '/';
include_once $_includeBase . 'includes/content-loader.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/public-strings.php';

$_defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
$currentPage = 'people';
$_text = fn(string $field) => nibblyPersonText($person, $field, $currentLang);
$_e = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

// Back to the page with the profile's Person block or a Team block of its group (the homepage if none)
$_listPath = nibblyPublicPageShowing($currentLang, '', fn(array $section) =>
    (($section['type'] ?? '') === 'person' && ($section['personId'] ?? '') === $person['id'])
    || (($section['type'] ?? '') === 'team' && (($section['group'] ?? '') === '' || in_array($section['group'], $person['groups'] ?? [], true)))
) ?? ($currentLang === $_defaultLang ? '' : $currentLang . '/');
$_backHtml = '<a href="' . $_e($basePath . $_listPath) . '" class="news-post-page__back">&larr; ' . $_e(nibblyPublicString('people_back', $currentLang)) . '</a>';

$pageTitle = (string)$person['name'];
$_summary = $_text('summary');
$pageDescription = mb_substr($_summary, 0, 160);
$_roleLine = implode(' · ', array_filter([$_text('role'), $_text('organization')], fn($s) => $s !== ''));
$_biography = nibblySanitizeRichHtml($_text('biography'));
$_quote = $_text('quote');

$_contact = [];
if (!empty($person['website'])) $_contact[] = '<a href="' . $_e(nibblySanitizeHref(nibblyPeopleAsset($person['website']))) . '">' . $_e($person['website']) . '</a>';
if (!empty($person['email'])) $_contact[] = '<a href="mailto:' . $_e($person['email']) . '">' . $_e($person['email']) . '</a>';
if (!empty($person['phone'])) $_contact[] = '<a href="tel:' . $_e(preg_replace('/[^+0-9]/', '', $person['phone'])) . '">' . $_e($person['phone']) . '</a>';

// The language switcher leads to this profile in the other languages
$pageLanguagePaths = [];
foreach (array_keys($SITE_LANGUAGES ?? []) as $_code) $pageLanguagePaths[$_code] = nibblyPersonPath($person, $_code);

include $_includeBase . 'includes/header.php';
?>

    <main class="main-content">
        <div class="content-inner">
            <article class="news-post-page person-page" data-person-id="<?php echo $_e($person['id']); ?>">
                <?php echo $_backHtml; ?>

                <div class="person-page__layout">
                    <?php if (!empty($person['photo'])): ?>
                    <figure class="person-page__portrait">
                        <img src="<?php echo $_e(nibblySanitizeHref(nibblyPeopleAsset($person['photo']))); ?>" alt="<?php echo $_e($person['alt'] ?: $person['name']); ?>">
                        <?php if (!empty($person['credit'])): ?><figcaption><?php echo $_e($person['credit']); ?></figcaption><?php endif; ?>
                    </figure>
                    <?php endif; ?>

                    <div class="person-page__body">
                        <header class="news-post-page__header">
                            <?php if ($_roleLine !== ''): ?>
                            <span class="news-post-page__date"><?php echo $_e($_roleLine); ?></span>
                            <?php endif; ?>
                            <h1 class="news-post-page__title"><?php echo $_e($pageTitle); ?></h1>
                        </header>

                        <div class="news-post-page__content">
                            <?php if ($_summary !== ''): ?><p class="person-page__summary"><?php echo $_e($_summary); ?></p><?php endif; ?>
                            <?php echo $_biography; ?>
                            <?php if ($_quote !== ''): ?><blockquote class="person-page__quote"><?php echo $_e($_quote); ?></blockquote><?php endif; ?>
                            <?php if ($_contact): ?><p class="person-page__contact"><?php echo implode('<br>', $_contact); ?></p><?php endif; ?>
                        </div>
                    </div>
                </div>

                <footer class="news-post-page__footer">
                    <?php echo $_backHtml; ?>
                </footer>
            </article>
        </div>
    </main>

<?php include $_includeBase . 'includes/footer.php'; ?>
