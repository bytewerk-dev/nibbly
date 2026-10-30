<?php

declare(strict_types=1);

// loadNewsPosts() filters and paging, nibblyNewsUrl() patterns, slug lists and pagination markup.

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-news-filter-' . bin2hex(random_bytes(4));
mkdir($site . '/content/news', 0755, true);
mkdir($site . '/includes', 0755, true);
mkdir($site . '/admin/lang', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
exec('cp -R ' . escapeshellarg($root . '/includes/.') . ' ' . escapeshellarg($site . '/includes/'));
exec('cp -R ' . escapeshellarg($root . '/admin/lang/.') . ' ' . escapeshellarg($site . '/admin/lang/'));
@unlink($site . '/includes/nav-config.php');

function newsAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function newsPost(string $date, string $slug, array $extra = []): void {
    global $site;
    $post = $extra + ['id' => $date . '-' . $slug, 'slug' => $slug, 'lang' => 'en', 'title' => 'Post ' . $slug, 'date' => $date, 'content' => '<p>x</p>', 'hidden' => false];
    file_put_contents($site . '/content/news/' . $date . '-' . $slug . '.json', json_encode($post));
}

define('SITE_LANG_DEFAULT', 'en');
$SITE_LANGUAGES = ['en' => 'English', 'de' => 'Deutsch'];
file_put_contents($site . '/content/settings.json', json_encode(['news' => ['urlPattern' => '/{yyyy}/{mm}/{dd}/{slug}', 'perPage' => 4, 'pageParam' => 'seite', 'archive' => ['base' => '/blog', 'tag' => 'thema', 'year' => 'jahr', 'decade' => 'jahrzehnt']]]));
file_put_contents($site . '/content/news-categories.json', json_encode(['categories' => [['slug' => 'b', 'name' => 'Second', 'order' => 2], ['slug' => 'a', 'name' => 'First', 'order' => 1], ['slug' => 'bad slug!', 'name' => 'x']]]));
newsPost('2026-08-17', 'newest', ['categories' => ['a', 'b'], 'tags' => ['t1', 'T 2'], 'flags' => ['column']]);
newsPost('2019-05-01', 'mid', ['categories' => ['a'], 'tags' => ['t1']]);
newsPost('2019-01-01', 'mid-older', ['categories' => ['b'], 'flags' => ['column']]);
newsPost('1988-10-07', 'eighties', ['categories' => ['a']]);
newsPost('1988-03-01', 'hidden', ['hidden' => true, 'categories' => ['a']]);
newsPost('2020-01-01', 'german', ['lang' => 'de', 'categories' => ['a']]);
for ($i = 1; $i <= 6; $i++) newsPost(sprintf('2001-01-%02d', $i), 'bulk-' . $i, ['categories' => ['b']]);

require_once $site . '/includes/content-loader.php';
$_SERVER['REQUEST_URI'] = '/blog/a?seite=2';
$_GET = ['seite' => '2'];

// Compatibility: the old signature still works
$all = loadNewsPosts(0, 'en');
newsAssert(count($all) === 10 && $all[0]['slug'] === 'newest' && end($all)['slug'] === 'eighties', 'all published English posts, newest first: ' . count($all));
newsAssert(count(loadNewsPosts(3, 'en')) === 3 && count(loadNewsPosts(0, '')) === 11, 'limit and every language');
newsAssert(!isset($all[0]['_file']), 'internal file name is not exposed');

// Filters
newsAssert(array_column(loadNewsPosts(0, 'en', ['category' => 'a']), 'slug') === ['newest', 'mid', 'eighties'], 'category filter');
newsAssert(count(loadNewsPosts(0, 'en', ['category' => ['a', 'b']])) === 10, 'any of several categories');
newsAssert(array_column(loadNewsPosts(0, 'en', ['tag' => 't1']), 'slug') === ['newest', 'mid'], 'tag filter');
newsAssert(array_column(loadNewsPosts(0, 'en', ['tag' => 't-2']), 'slug') === ['newest'], 'tags are normalised to slugs');
newsAssert(array_column(loadNewsPosts(0, 'en', ['flag' => 'column']), 'slug') === ['newest', 'mid-older'], 'flag filter');
newsAssert(array_column(loadNewsPosts(0, 'en', ['category' => 'a', 'flagNot' => 'column']), 'slug') === ['mid', 'eighties'], 'flagNot excludes flagged posts');
newsAssert(array_column(loadNewsPosts(0, 'en', ['year' => '2019']), 'slug') === ['mid', 'mid-older'], 'year filter');
newsAssert(array_column(loadNewsPosts(0, 'en', ['decade' => '1980']), 'slug') === ['eighties'], 'decade filter skips hidden posts');
newsAssert(array_column(loadNewsPosts(0, 'en', ['category' => 'a', 'year' => '2019']), 'slug') === ['mid'], 'filters combine');
newsAssert(loadNewsPosts(0, 'en', ['category' => 'nope']) === [], 'no match');

// Paging
$page = loadNewsPosts(0, 'en', ['page' => 2, 'withTotal' => true]);
newsAssert($page['total'] === 10 && array_column($page['posts'], 'slug') === ['bulk-5', 'bulk-4', 'bulk-3', 'bulk-2'], 'page 2 of the setting size 4: ' . implode(',', array_column($page['posts'], 'slug')));
newsAssert(count(loadNewsPosts(0, 'en', ['page' => 3])) === 2 && loadNewsPosts(0, 'en', ['page' => 4]) === [], 'last page and beyond');
newsAssert(array_column(loadNewsPosts(0, 'en', ['page' => 1, 'perPage' => 2]), 'slug') === ['newest', 'mid'], 'explicit page size');
newsAssert(array_column(loadNewsPosts(2, 'en', ['offset' => 1]), 'slug') === ['mid', 'mid-older'], 'offset with limit');
newsAssert(nibblyNewsCurrentPage() === 2 && nibblyNewsCurrentPage(['seite' => '0']) === 1 && nibblyNewsCurrentPage(['seite' => 'x']) === 1, 'current page from the request');

// URLs
newsAssert(nibblyNewsUrl($all[0]) === '/2026/08/17/newest', 'date pattern URL');
newsAssert(nibblyNewsUrl(['slug' => 'german', 'date' => '2020-01-01', 'lang' => 'de']) === '/de/2020/01/01/german', 'language prefix');
newsAssert(nibblyNewsUrl(['slug' => 'x', 'date' => '']) === '/0000/00/00/x', 'missing date yields a syntactically valid path');
newsAssert(nibblyNewsListUrl() === '/blog' && nibblyNewsListUrl('de') === '/de/blog', 'list URL');
newsAssert(nibblyNewsArchiveUrl('category', 'a') === '/blog/a' && nibblyNewsArchiveUrl('tag', 't1', 'de') === '/de/blog/thema/t1' && nibblyNewsArchiveUrl('year', '1988') === '/blog/jahr/1988' && nibblyNewsArchiveUrl('decade', '1980') === '/blog/jahrzehnt/1980', 'archive URLs');
newsAssert(nibblyNewsMatchUrl('2026/08/17/newest') === ['lang' => 'en', 'slug' => 'newest', 'date' => '2026-08-17', 'parts' => ['yyyy' => '2026', 'mm' => '08', 'dd' => '17']], 'URL match');
newsAssert(nibblyNewsMatchUrl('news/newest', ) === null && nibblyNewsUrlRegex('/blog/{yyyy}/{slug}') === 'blog/(?P<yyyy>\\d{4})/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)', 'regex of a year-only pattern');
newsAssert(nibblyNewsMatchUrl('de/2020/01/01/german')['lang'] === 'de' && nibblyNewsMatchUrl('fr/2020/01/01/german') === null, 'language prefix only for site languages');
newsAssert(nibblyNewsMatchUrl('2026/08/newest') === null && nibblyNewsMatchUrl('news/newest') === null, 'other shapes do not match');
newsAssert(nibblyNewsMatchArchiveUrl('blog') === ['type' => 'list', 'value' => '', 'lang' => 'en'], 'archive list match');
newsAssert(nibblyNewsMatchArchiveUrl('blog/a')['type'] === 'category' && nibblyNewsMatchArchiveUrl('blog/zz') === null, 'category archive only for defined categories');
newsAssert(nibblyNewsMatchArchiveUrl('blog/thema/t1') === ['type' => 'tag', 'value' => 't1', 'lang' => 'en'] && nibblyNewsMatchArchiveUrl('blog/jahr/1988')['type'] === 'year' && nibblyNewsMatchArchiveUrl('blog/jahrzehnt/1985') === null, 'tag, year, decade matches');
newsAssert(nibblyNewsNormalizeUrlPattern(' /News/{YYYY}/{slug}/ ') === '/news/{yyyy}/{slug}', 'pattern normalisation');
foreach (['/news', '/{slug}/{slug}', '/news/{bad}/{slug}', '/news/{slug}extra', '/', ''] as $bad) {
    newsAssert(nibblyNewsNormalizeUrlPattern($bad) === null, 'invalid pattern rejected: ' . $bad);
}
newsAssert(nibblyNewsNormalizeUrlPattern('/{slug}') === '/{slug}', 'bare slug pattern is allowed');
newsAssert(nibblyNewsSettings()['perPage'] === 4 && nibblyNewsSettings()['pageParam'] === 'seite', 'settings read');

// Categories, slugs, tags
newsAssert(array_column(nibblyNewsCategories(), 'slug') === ['a', 'b', 'bad-slug'], 'categories sorted by order, slugs normalised: ' . implode(',', array_column(nibblyNewsCategories(), 'slug')));
newsAssert(nibblyNewsCategoryName('a') === 'First' && nibblyNewsCategoryName('zz') === 'zz', 'category names');
newsAssert(nibblyNewsSlugify('Jörg Haider & ÖVP') === 'joerg-haider-oevp' && nibblyNewsSlugify('  ') === '', 'slugify');
newsAssert(nibblyNewsSlugList(['HOSI Wien', 'hosi-wien', 'a, b', '', 7]) === ['hosi-wien', 'a', 'b', '7'], 'slug list from mixed input: ' . json_encode(nibblyNewsSlugList(['HOSI Wien', 'hosi-wien', 'a, b', '', 7])));
newsAssert(nibblyNewsTagCounts('en') === ['t1' => 2, 't-2' => 1], 'tag counts');
newsAssert(nibblyNewsRememberTagNames('HOSI Wien, Hosi Wien, plain') === ['hosi-wien', 'plain'], 'typed tags become slugs');
newsAssert(nibblyNewsTagNames() === ['hosi-wien' => 'HOSI Wien'] && nibblyNewsTagName('plain') === 'Plain', 'only spellings worth keeping are stored');
newsAssert(nibblyNewsFindPost('newest', 'en')['slug'] === 'newest' && nibblyNewsFindPost('hidden', 'en') === null && nibblyNewsFindPost('german', 'en') === null && nibblyNewsFindPost('german', 'de') !== null, 'find by slug and language');
newsAssert(nibblyNewsRoute('2020/01/01/newest', '/2020/01/01/newest') === ['redirect' => '/2026/08/17/newest', 'lang' => 'en'], 'wrong date redirects');
newsAssert(nibblyNewsRoute('2026/08/17/newest', '/2026/08/17/newest/')['redirect'] === '/2026/08/17/newest', 'trailing slash redirects');
newsAssert(isset(nibblyNewsRoute('2026/08/17/newest', '/2026/08/17/newest')['post']) && nibblyNewsRoute('2026/08/17/nope', '/2026/08/17/nope') === null, 'route hit and miss');

// Pagination markup
$html = nibblyNewsPagination(10, 2, 4, '/blog/a?x=1', 'en');
newsAssert(str_contains($html, 'aria-label="Pagination"') && str_contains($html, 'rel="prev"') && str_contains($html, 'rel="next"'), 'pagination landmarks');
newsAssert(str_contains($html, 'href="/blog/a?x=1"') && str_contains($html, 'href="/blog/a?x=1&amp;seite=3"') && !str_contains($html, 'seite=2"'), 'page 1 is the bare URL, other query keys kept: ' . $html);
newsAssert(str_contains($html, 'aria-current="page" aria-label="Page 2">2<') && str_contains($html, 'Page 2 of 3'), 'current page and summary');
newsAssert(nibblyNewsPagination(4, 1, 4, '/blog') === '' && nibblyNewsPagination(0, 1, 4, '/blog') === '', 'no pagination for a single page');
$long = nibblyNewsPagination(100, 10, 4, '/blog', 'de');
newsAssert(substr_count($long, 'pagination__item--gap') === 2 && str_contains($long, '>25<') && str_contains($long, 'Seite 10 von 25') && str_contains($long, 'aria-label="Seitennavigation"'), 'gaps and German labels');
newsAssert(nibblyNewsPaginationHeadLinks(10, 2, 4, '/blog') === "<link rel=\"prev\" href=\"/blog\">\n<link rel=\"next\" href=\"/blog?seite=3\">\n", 'head links');

// renderNewsList: URLs from the pattern, chips, pagination
$list = renderNewsList(0, 'en', ['category' => 'b', 'paginate' => true, 'page' => 2, 'baseUrl' => '/blog/b']);
newsAssert(substr_count($list, 'class="news-card"') === 4 && str_contains($list, 'href="/2001/01/03/bulk-3"') && str_contains($list, 'news-card__category">Second<'), 'list page 2 of category b: ' . substr_count($list, 'class="news-card"'));
newsAssert(str_contains($list, 'href="/blog/b"') && str_contains($list, 'Page 2 of 2'), 'list pagination');
newsAssert(substr_count(renderNewsList(2, 'en'), 'class="news-card"') === 2 && !str_contains(renderNewsList(2, 'en'), 'pagination'), 'plain limit without pagination');
newsAssert(str_contains(renderNewsList(0, 'en', ['category' => 'nope']), 'news-empty'), 'empty list');

echo "PASS news filters: compatibility, category/tag/flag/year/decade filters, paging, URL patterns and matches, categories, tags, pagination markup, list rendering\n";
