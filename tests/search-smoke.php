<?php

declare(strict_types=1);

// Search index (pages and posts), ranking, snippets, umlaut folding, settings and URLs.

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-search-' . bin2hex(random_bytes(4));
mkdir($site . '/content/news', 0755, true);
mkdir($site . '/content/pages', 0755, true);
mkdir($site . '/includes', 0755, true);
mkdir($site . '/admin/lang', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
exec('cp -R ' . escapeshellarg($root . '/includes/.') . ' ' . escapeshellarg($site . '/includes/'));
exec('cp -R ' . escapeshellarg($root . '/admin/lang/.') . ' ' . escapeshellarg($site . '/admin/lang/'));
@unlink($site . '/includes/nav-config.php');

function searchAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function searchPost(string $date, string $slug, array $extra = []): void {
    global $site;
    $post = $extra + ['id' => $date . '-' . $slug, 'slug' => $slug, 'lang' => 'de', 'title' => 'Post ' . $slug, 'date' => $date, 'excerpt' => '', 'content' => '<p>x</p>', 'hidden' => false];
    file_put_contents($site . '/content/news/' . $date . '-' . $slug . '.json', json_encode($post));
}

function searchPage(string $key, array $data): void {
    global $site;
    file_put_contents($site . '/content/pages/' . $key . '.json', json_encode($data + ['page' => $key, 'lang' => substr($key, 0, 2)]));
}

define('SITE_LANG_DEFAULT', 'de');
$SITE_LANGUAGES = ['de' => 'Deutsch', 'en' => 'English'];
file_put_contents($site . '/content/settings.json', json_encode(['news' => ['urlPattern' => '/{yyyy}/{mm}/{dd}/{slug}', 'pageParam' => 'seite'], 'search' => ['base' => 'suche/', 'param' => 'q', 'perPage' => 2, 'minLength' => 2]]));
file_put_contents($site . '/content/news-categories.json', json_encode(['categories' => [['slug' => 'blog', 'name' => 'Blog'], ['slug' => 'ln', 'name' => 'LAMBDA-Nachrichten']]]));
file_put_contents($site . '/content/news-tags.json', json_encode(['tags' => ['joerg-haider' => 'Jörg Haider']]));

searchPost('2026-08-17', 'haider-titel', ['title' => 'Jörg Haider und die FPÖ', 'excerpt' => 'Ein Kommentar.', 'content' => '<p>Text ohne das Wort.</p>', 'categories' => ['blog']]);
searchPost('2019-05-01', 'haider-text', ['title' => 'Ein anderer Titel', 'excerpt' => 'Auszug.', 'content' => '<p>Hier geht es um Haider, nochmals Haider und die <strong>Partei</strong>.</p>', 'tags' => ['joerg-haider']]);
searchPost('2001-01-01', 'haider-tag', ['title' => 'Nur ein Schlagwort', 'content' => '<p>Nichts.</p>', 'tags' => ['joerg-haider'], 'categories' => ['ln']]);
searchPost('2000-01-01', 'strassburg', ['title' => 'Konferenz in Straßburg', 'content' => '<p>Über die Reise.</p>']);
searchPost('1999-01-01', 'hidden', ['title' => 'Haider versteckt', 'hidden' => true]);
searchPost('1998-01-01', 'english', ['title' => 'Haider in English', 'lang' => 'en']);
searchPost('1997-01-01', 'gallery', ['title' => 'Galerie', 'gallery' => [['src' => '/a.webp', 'caption' => 'Bildunterschrift Mauthausen']]]);
for ($i = 1; $i <= 3; $i++) searchPost('2010-01-0' . $i, 'bulk-' . $i, ['title' => 'Bulk ' . $i, 'content' => '<p>Haider</p>']);

searchPage('de_home', ['title' => 'Start', 'sections' => [['id' => 's1', 'type' => 'text', 'content' => '<p>Willkommen bei Haider? Nein, bei der Startseite.</p>']]]);
searchPage('de_engagement__lobbying', ['title' => 'Politisches Lobbying', 'description' => 'Strafrechtsreform und mehr', 'sections' => [['id' => 's1', 'type' => 'heading', 'text' => 'Lobbying'], ['id' => 's2', 'type' => 'text', 'content' => '<p>Der Kampf gegen § 209 und <a href="/x">Haider</a>.</p>']], 'hero' => ['title' => 'Kampf um Gleichstellung', 'image' => '/assets/images/haider.webp']]);
searchPage('de_privat', ['title' => 'Haider privat', 'visibility' => ['status' => 'private'], 'sections' => []]);
searchPage('de_noindex', ['title' => 'Haider noindex', 'seo' => ['robots' => 'noindex'], 'sections' => []]);
searchPage('en_about', ['title' => 'About Haider', 'sections' => []]);

require_once $site . '/includes/content-loader.php';
require_once $site . '/includes/search-helper.php';

// Settings
$settings = nibblySearchSettings();
searchAssert($settings['base'] === '/suche' && $settings['param'] === 'q' && $settings['perPage'] === 2 && $settings['enabled'], 'settings normalised: ' . json_encode($settings));
searchAssert(nibblySearchUrl('de') === '/suche' && nibblySearchUrl('en') === '/en/suche', 'search URLs per language');
searchAssert(nibblySearchQueryUrl('Jörg Haider', 'de', '../') === '../suche?q=J%C3%B6rg+Haider', 'query URL: ' . nibblySearchQueryUrl('Jörg Haider', 'de', '../'));
searchAssert(nibblySearchMatchUrl('suche') === ['lang' => 'de'] && nibblySearchMatchUrl('en/suche') === ['lang' => 'en'] && nibblySearchMatchUrl('suche/x') === null && nibblySearchMatchUrl('search') === null, 'URL matching');

// Index
$count = nibblySearchIndexRebuild();
searchAssert($count === 12, 'index holds public pages and published posts: ' . var_export($count, true));
$index = json_decode((string)file_get_contents($site . '/content/search-index.json'), true);
searchAssert(isset($index['documents']) && $index['count'] === 12 && !empty($index['generatedAt']), 'index file written');
$byId = array_column($index['documents'], null, 'id');
searchAssert(!isset($byId['de_privat']) && !isset($byId['de_noindex']) && !isset($byId['1999-01-01-hidden']), 'private, noindex and hidden content stay out of the index');
searchAssert($byId['de_engagement__lobbying']['url'] === '/engagement/lobbying' && $byId['en_about']['url'] === '/en/about', 'page URLs follow the language and the nested path');
searchAssert($byId['2026-08-17-haider-titel']['url'] === '/2026/08/17/haider-titel', 'post URLs follow the pattern');
searchAssert(str_contains($byId['de_engagement__lobbying']['text'], 'Kampf um Gleichstellung') && str_contains($byId['de_engagement__lobbying']['text'], '§ 209') && !str_contains($byId['de_engagement__lobbying']['text'], 'haider.webp'), 'page text collects section and custom-layout strings, not asset paths');
searchAssert($byId['2026-08-17-haider-titel']['labels'] === 'Blog' && str_contains($byId['2019-05-01-haider-text']['labels'], 'Jörg Haider'), 'category and tag names are indexed');
searchAssert(str_contains($byId['1997-01-01-gallery']['text'], 'Mauthausen'), 'gallery captions are indexed');

// Ranking: title before tag/excerpt before text; AND across terms; hidden/other language excluded
$result = nibblySearchQuery('Haider', ['lang' => 'de', 'perPage' => 50]);
searchAssert($result['total'] === 8, 'eight German hits: ' . $result['total']);
$ids = array_column($result['results'], 'id');
searchAssert($ids[0] === '2026-08-17-haider-titel', 'title hit ranks first: ' . json_encode($ids));
searchAssert(array_search('2019-05-01-haider-text', $ids, true) < array_search('de_home', $ids, true), 'tag + body hits rank before a single body hit');
searchAssert(!in_array('1998-01-01-english', $ids, true) && !in_array('en_about', $ids, true), 'language filter');
searchAssert(in_array('de_engagement__lobbying', $ids, true), 'pages are found');

$english = nibblySearchQuery('Haider', ['lang' => 'en']);
searchAssert(array_column($english['results'], 'id') === ['en_about', '1998-01-01-english'] || count($english['results']) === 2, 'English search');
$all = nibblySearchQuery('Haider', ['lang' => '', 'perPage' => 50]);
searchAssert($all['total'] === 10, 'all languages: ' . $all['total']);
$posts = nibblySearchQuery('Haider', ['lang' => 'de', 'type' => 'post', 'perPage' => 50]);
searchAssert($posts['total'] === 6, 'type filter: ' . $posts['total']);

// Folding: ä ↔ ae ↔ a, ß ↔ ss
foreach (['Jörg', 'Joerg', 'jorg', 'JÖRG'] as $spelling) {
    $hit = nibblySearchQuery($spelling, ['lang' => 'de', 'perPage' => 50]);
    searchAssert(in_array('2026-08-17-haider-titel', array_column($hit['results'], 'id'), true), $spelling . ' finds Jörg: ' . json_encode(array_column($hit['results'], 'id')));
}
searchAssert(nibblySearchQuery('Strassburg', ['lang' => 'de'])['total'] === 1 && nibblySearchQuery('straßburg', ['lang' => 'de'])['total'] === 1, 'ß folding');

// AND semantics and phrase bonus
searchAssert(nibblySearchQuery('Haider Partei', ['lang' => 'de'])['results'][0]['id'] === '2019-05-01-haider-text', 'both terms must match');
searchAssert(nibblySearchQuery('Haider Nirgends', ['lang' => 'de'])['total'] === 0, 'a missing term yields nothing');
$phrase = nibblySearchQuery('Jörg Haider', ['lang' => 'de']);
searchAssert($phrase['results'][0]['id'] === '2026-08-17-haider-titel', 'phrase in the title ranks first');

// Snippets and highlighting
$text = nibblySearchQuery('Partei', ['lang' => 'de']);
searchAssert(str_contains($text['results'][0]['snippet'], '<mark>Partei</mark>') && !str_contains($text['results'][0]['snippet'], '<strong>'), 'snippet marks the term in plain text: ' . $text['results'][0]['snippet']);
searchAssert(nibblySearchHighlight('Jörg & Joerg', [['joerg', 'jorg']]) === '<mark>Jörg</mark> &amp; <mark>Joerg</mark>', 'highlight folds umlauts and escapes: ' . nibblySearchHighlight('Jörg & Joerg', [['joerg', 'jorg']]));
searchAssert(nibblySearchHighlight('<b>x</b>', [['x']]) === '&lt;b&gt;<mark>x</mark>&lt;/b&gt;', 'highlight escapes HTML');

// Paging and short queries
$page2 = nibblySearchQuery('Haider', ['lang' => 'de', 'page' => 2]);
searchAssert($page2['page'] === 2 && count($page2['results']) === 2 && $page2['pages'] === 4, 'page 2 of 4');
$beyond = nibblySearchQuery('Haider', ['lang' => 'de', 'page' => 9]);
searchAssert($beyond['page'] === 4 && count($beyond['results']) === 2, 'a page beyond the end shows the last one');
searchAssert(nibblySearchQuery('H', ['lang' => 'de'])['tooShort'] === true && nibblySearchQuery('', ['lang' => 'de'])['tooShort'] === false, 'short query flag');
searchAssert(nibblySearchPageUrl('/suche', 'Jörg Haider', 3) === '/suche?q=J%C3%B6rg+Haider&seite=3', 'page URL keeps the query: ' . nibblySearchPageUrl('/suche', 'Jörg Haider', 3));

// Stale index: a new post appears without an explicit rebuild
sleep(1);
searchPost('2026-09-01', 'neu', ['title' => 'Brandneu und Haider']);
touch($site . '/content/news');
$fresh = nibblySearchQuery('Brandneu', ['lang' => 'de']);
searchAssert($fresh['total'] === 1 && $fresh['results'][0]['id'] === '2026-09-01-neu', 'index rebuilt when content is newer');

// Form markup
$form = nibblySearchForm(['lang' => 'de', 'basePath' => '../', 'value' => 'x"y']);
searchAssert(str_contains($form, 'action="../suche"') && str_contains($form, 'name="q"') && str_contains($form, 'value="x&quot;y"') && str_contains($form, 'role="search"'), 'search form markup: ' . $form);

echo "PASS search-smoke\n";
