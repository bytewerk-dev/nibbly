<?php

declare(strict_types=1);

// renderNewsTimeline(): order, grouping, jump bar, images and galleries, filters, links and feed XML.

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-timeline-' . bin2hex(random_bytes(4));
mkdir($site . '/content/news', 0755, true);
mkdir($site . '/includes', 0755, true);
mkdir($site . '/admin/lang', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
exec('cp -R ' . escapeshellarg($root . '/includes/.') . ' ' . escapeshellarg($site . '/includes/'));
exec('cp -R ' . escapeshellarg($root . '/admin/lang/.') . ' ' . escapeshellarg($site . '/admin/lang/'));
@unlink($site . '/includes/nav-config.php');

function timelineAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function timelinePost(string $date, string $slug, array $extra = []): void {
    global $site;
    $post = $extra + ['id' => $date . '-' . $slug, 'slug' => $slug, 'lang' => 'de', 'title' => 'Station ' . $slug, 'date' => $date, 'excerpt' => 'Auszug ' . $slug, 'content' => '<p>Text ' . $slug . '</p>', 'hidden' => false, 'categories' => ['zeitreise']];
    file_put_contents($site . '/content/news/' . $date . '-' . $slug . '.json', json_encode($post));
}

define('SITE_LANG_DEFAULT', 'de');
$SITE_LANGUAGES = ['de' => 'Deutsch'];
file_put_contents($site . '/content/settings.json', json_encode(['branding' => ['name' => 'Testsite'], 'seo' => ['siteUrl' => 'https://example.test'], 'news' => ['urlPattern' => '/{yyyy}/{mm}/{dd}/{slug}', 'archive' => ['base' => '/blog'], 'feedItems' => 10]]));
file_put_contents($site . '/content/news-categories.json', json_encode(['categories' => [['slug' => 'zeitreise', 'name' => 'Zeitreise'], ['slug' => 'blog', 'name' => 'Blog']]]));

timelinePost('1980-01-29', 'gruendung', ['image' => '/wp-content/uploads/1980/gruendung.webp', 'imageCaption' => 'Foto: Archiv', 'excerpt' => 'Mit <em>Link</em> <a href="/x">hier</a> <script>alert(1)</script>']);
timelinePost('1980-04-26', 'demo', ['gallery' => [['src' => '/a.webp', 'alt' => 'A', 'caption' => 'Erste'], ['src' => '/b.webp'], ['caption' => 'kein src']]]);
timelinePost('1984-06-23', 'warme-woche');
timelinePost('1989-12-01', 'ende', ['hidden' => true]);
timelinePost('1992-01-21', 'neunziger');
timelinePost('2005-05-04', 'blogpost', ['categories' => ['blog'], 'image' => '/assets/images/x.webp']);

require_once $site . '/includes/content-loader.php';

$html = renderNewsTimeline('de', ['category' => 'zeitreise', 'decade' => '1980', 'id' => 'zr']);
timelineAssert(substr_count($html, 'class="news-timeline__item"') === 3, 'three visible stations of the decade: ' . substr_count($html, 'class="news-timeline__item"'));
timelineAssert(strpos($html, 'gruendung') < strpos($html, 'demo') && strpos($html, 'demo') < strpos($html, 'warme-woche'), 'oldest first');
timelineAssert(!str_contains($html, 'ende') && !str_contains($html, 'neunziger'), 'hidden posts and other decades are left out');
timelineAssert(str_contains($html, 'id="zr-1980"') && str_contains($html, 'id="zr-1984"') && str_contains($html, 'href="#zr-1984"'), 'year groups with anchors and jump bar');
timelineAssert(str_contains($html, '<h2 class="news-timeline__group-title">1980</h2>') && str_contains($html, 'aria-label="Springe zu"'), 'group heading and jump bar label in German');
timelineAssert(str_contains($html, 'href="/1980/01/29/gruendung"') && str_contains($html, '>Zum Beitrag<span class="visually-hidden">: Station gruendung</span></a>'), 'links follow the URL pattern, accessible link label');
timelineAssert(str_contains($html, 'datetime="1980-01-29">29. Jänner 1980</time>'), 'date formatted in the page language');
timelineAssert(str_contains($html, '<img class="news-timeline__image" src="/wp-content/uploads/1980/gruendung.webp" alt="Station gruendung" loading="lazy">') && str_contains($html, '<figcaption class="news-timeline__caption">Foto: Archiv</figcaption>'), 'post image with caption');
timelineAssert(substr_count($html, 'news-timeline__gallery-item') === 2 && str_contains($html, 'data-lightbox="zr-1980-04-26-demo"') && str_contains($html, '<figcaption>Erste</figcaption>'), 'gallery with two valid images');
timelineAssert(str_contains($html, '<em>Link</em>') && str_contains($html, '<a href="/x">hier</a>') && !str_contains($html, '<script>'), 'excerpt markup sanitised: ' . $html);
timelineAssert(!str_contains($html, 'Text gruendung'), 'post text hidden by default');

$withText = renderNewsTimeline('de', ['category' => 'zeitreise', 'showContent' => true, 'showImages' => false, 'order' => 'desc', 'jumpBar' => 'decade', 'basePath' => '../../']);
timelineAssert(str_contains($withText, '<div class="news-timeline__content"><p>Text gruendung</p></div>'), 'showContent prints the body');
timelineAssert(!str_contains($withText, '<img'), 'showImages false');
timelineAssert(strpos($withText, 'neunziger') < strpos($withText, 'gruendung'), 'newest first with order desc');
timelineAssert(str_contains($withText, 'href="#timeline-1990"') && str_contains($withText, '>1990er<') && str_contains($withText, 'href="../../1992/01/21/neunziger"'), 'decade jump bar and relative links');

$plain = renderNewsTimeline('de', ['category' => 'zeitreise', 'year' => '1984', 'jumpBar' => 'none']);
timelineAssert(!str_contains($plain, 'news-timeline__jump') && !str_contains($plain, 'news-timeline__group'), 'no jump bar or groups when switched off');
timelineAssert(str_contains(renderNewsTimeline('de', ['category' => 'nothing']), 'news-timeline__empty'), 'empty text');
timelineAssert(substr_count(renderNewsTimeline('de', ['limit' => 2]), 'news-timeline__item') === 2, 'limit');

// Feed
require_once $site . '/includes/feed.php';
timelineAssert(nibblyNewsFeedEnabled() && nibblyNewsFeedUrl('de') === '/feed.xml' && nibblyNewsFeedMatchUrl('feed.xml') === ['lang' => 'de'] && nibblyNewsFeedMatchUrl('de/feed.xml') === null, 'feed URL and matching');
$xml = nibblyNewsFeedXml('de');
timelineAssert(substr_count($xml, '<item>') === 5, 'published posts in the feed: ' . substr_count($xml, '<item>'));
timelineAssert(str_contains($xml, '<title>Testsite – Beiträge (RSS)</title>') && str_contains($xml, '<link>https://example.test/blog</link>'), 'channel title and link');
timelineAssert(str_contains($xml, '<link>https://example.test/2005/05/04/blogpost</link>') && str_contains($xml, '<category>Blog</category>') && str_contains($xml, '<enclosure url="https://example.test/assets/images/x.webp" length="0" type="image/webp"/>'), 'items with link, category and enclosure');
timelineAssert(!str_contains($xml, 'ende'), 'hidden posts are not in the feed');
timelineAssert(str_contains($xml, '<description>Mit Link hier alert(1)</description>') && !str_contains($xml, '&lt;em&gt;'), 'description is plain text: ' . $xml);
file_put_contents($site . '/content/settings.json', json_encode(['branding' => ['name' => 'Testsite'], 'seo' => ['siteUrl' => 'https://example.test'], 'news' => ['feedItems' => 2]]));
timelineAssert(substr_count(nibblyNewsFeedXml('de'), '<item>') === 2, 'feedItems limits the feed');
$dom = new DOMDocument();
timelineAssert($dom->loadXML($xml) !== false, 'feed is well-formed XML');
$head = nibblyNewsFeedHeadLink('de', '../');
timelineAssert($head === '<link rel="alternate" type="application/rss+xml" title="Testsite – Beiträge (RSS)" href="../feed.xml">' . "\n", 'head link: ' . $head);
file_put_contents($site . '/content/settings.json', json_encode(['news' => ['feed' => false]]));
timelineAssert(!nibblyNewsFeedEnabled() && nibblyNewsFeedHeadLink('de') === '' && nibblyNewsFeedMatchUrl('feed.xml') === null, 'feed can be switched off');

echo "PASS news-timeline-smoke\n";
