<?php
/**
 * Page of a single event: /events/{id} or /{lang}/events/{id}.
 *
 * Expects $currentLang, $basePath and $event (found by the router) to be set.
 * Hidden events are shown to logged-in editors only. The layout reuses the
 * news article classes (news-post-page__*), so both look alike.
 */

$_includeBase = dirname(__DIR__) . '/';
include_once $_includeBase . 'includes/content-loader.php';
require_once __DIR__ . '/public-strings.php';

$_defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
$currentPage = 'events';
$_text = fn(string $field) => (string)($event[$field][$currentLang] ?? $event[$field][$_defaultLang] ?? '');

// Back to the page that lists the events (the homepage if none does)
$_listPath = nibblyPublicPageShowing($currentLang, 'renderEventList(') ?? ($currentLang === $_defaultLang ? '' : $currentLang . '/');
$_backHtml = '<a href="' . htmlspecialchars($basePath . $_listPath) . '" class="news-post-page__back">&larr; ' . htmlspecialchars(nibblyPublicString('events_back', $currentLang)) . '</a>';

if (!empty($event['hidden']) && !isAdminLoggedIn()) {
    http_response_code(404);
    $pageTitle = nibblyPublicString('not_found', $currentLang);
    $pageDescription = '';
    include $_includeBase . 'includes/header.php';
    echo '<main class="main-content"><div class="content-inner"><h1>' . htmlspecialchars($pageTitle) . '</h1><p>' . $_backHtml . '</p></div></main>';
    include $_includeBase . 'includes/footer.php';
    exit;
}

$pageTitle = $_text('title');
$_description = $_text('description');
$pageDescription = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($_description))), 0, 160);
$_location = $_text('location');
$_admission = $_text('admission');
$_url = (string)($event['url'] ?? '');

// Date line: "Saturday, 14 November 2026, 18:30–21:00" or a range over several days
$_start = (string)($event['date'] ?? '');
$_end = (string)($event['end-date'] ?? '');
$_startTime = (string)($event['time'] ?? '');
$_endTime = (string)($event['end-time'] ?? '');
$_suffix = $currentLang === 'de' ? ' Uhr' : ($currentLang === 'es' ? ' h' : '');
$_when = '';
if ($_start !== '') {
    if ($_end !== '' && $_end !== $_start) {
        $_when = formatEventDateShort($_start, $currentLang) . ($_startTime !== '' ? ', ' . $_startTime . $_suffix : '')
            . ' – ' . formatEventDateShort($_end, $currentLang) . ($_endTime !== '' ? ', ' . $_endTime . $_suffix : '');
    } else {
        $_when = formatEventDate($_start, $currentLang)
            . ($_startTime !== '' ? ', ' . $_startTime . ($_endTime !== '' ? '–' . $_endTime : '') . $_suffix : '');
    }
}

// The language switcher leads to this event in the other languages
$pageLanguagePaths = [];
foreach (array_keys($SITE_LANGUAGES ?? []) as $_code) $pageLanguagePaths[$_code] = nibblyEventPath($event, $_code);

include $_includeBase . 'includes/header.php';
?>

    <main class="main-content">
        <div class="content-inner">
            <article class="news-post-page event-page" data-event-id="<?php echo htmlspecialchars((string)($event['id'] ?? '')); ?>">
                <?php echo $_backHtml; ?>

                <?php if (!empty($event['image'])): ?>
                <div class="news-post-page__hero">
                    <img src="<?php echo htmlspecialchars((string)$event['image']); ?>" alt="<?php echo htmlspecialchars($pageTitle); ?>"><?php echo nibblyAiLabelHtml((string)$event['image']); ?>
                </div>
                <?php endif; ?>

                <header class="news-post-page__header">
                    <?php if ($_when !== ''): ?>
                    <time class="news-post-page__date" datetime="<?php echo htmlspecialchars($_start); ?>"><?php echo htmlspecialchars($_when); ?></time>
                    <?php endif; ?>
                    <h1 class="news-post-page__title"><?php echo htmlspecialchars($pageTitle); ?></h1>
                    <?php if ($_location !== ''): ?>
                    <span class="news-post-page__author event-page__location"><?php echo htmlspecialchars($_location); ?></span>
                    <?php endif; ?>
                </header>

                <div class="news-post-page__content">
                    <?php if ($_description !== ''): ?>
                    <p><?php echo nl2br(htmlspecialchars($_description)); ?></p>
                    <?php endif; ?>
                    <?php if ($_admission !== ''): ?>
                    <p class="event-page__admission"><strong><?php echo htmlspecialchars(nibblyPublicString('event_admission', $currentLang)); ?>:</strong> <?php echo htmlspecialchars($_admission); ?></p>
                    <?php endif; ?>
                    <?php if ($_url !== ''): ?>
                    <p><a href="<?php echo htmlspecialchars(nibblySanitizeHref($_url)); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(nibblyPublicString('event_website', $currentLang)); ?> &rarr;</a></p>
                    <?php endif; ?>
                </div>

                <footer class="news-post-page__footer">
                    <?php echo $_backHtml; ?>
                </footer>
            </article>
        </div>
    </main>

<?php include $_includeBase . 'includes/footer.php'; ?>
