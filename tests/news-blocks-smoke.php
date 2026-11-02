<?php

declare(strict_types=1);

// Posts built from blocks: nibblyNewsSanitizeBlocks(), nibblyNewsRenderBlocks(), nibblyNewsBodyHtml(),
// the gallery and image renderers and the text style registry with content/text-styles.json.

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-news-blocks-' . bin2hex(random_bytes(4));
mkdir($site . '/content/news', 0755, true);
mkdir($site . '/includes', 0755, true);
mkdir($site . '/admin/lang', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
exec('cp -R ' . escapeshellarg($root . '/includes/.') . ' ' . escapeshellarg($site . '/includes/'));
exec('cp -R ' . escapeshellarg($root . '/admin/lang/.') . ' ' . escapeshellarg($site . '/admin/lang/'));
@unlink($site . '/includes/nav-config.php');

function blocksAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

define('SITE_LANG_DEFAULT', 'de');
$SITE_LANGUAGES = ['de' => 'Deutsch', 'en' => 'English'];
file_put_contents($site . '/content/settings.json', json_encode(['news' => ['archive' => ['base' => '/news', 'categoryBase' => '/rubrik']]]));
file_put_contents($site . '/content/news-categories.json', json_encode(['categories' => [['slug' => 'kolumne', 'name' => 'Kolumne', 'order' => 1]]]));
file_put_contents($site . '/content/text-styles.json', json_encode([
    'callout' => ['label' => ['de' => 'Kasten', 'en' => 'Callout'], 'class' => 'my-callout'],
    'bare' => ['label' => 'Bare'],
    'highlight' => ['label' => 'Site highlight', 'class' => 'site-highlight "quoted"'],
    'bad key!' => ['label' => 'x', 'class' => 'y'],
]));

require_once $site . '/includes/content-loader.php';

// Text style registry: core style overridden, site styles added, labels per language, class cleaned
$styles = getTextStyles();
blocksAssert(array_keys($styles) === ['highlight', 'callout', 'bare'], 'style keys: ' . implode(',', array_keys($styles)));
blocksAssert($styles['callout'] === ['label' => 'Kasten', 'class' => 'my-callout'], 'site style with German label');
blocksAssert($styles['bare']['class'] === 'text-style--bare', 'style without class gets a default class');
blocksAssert($styles['highlight'] === ['label' => 'Site highlight', 'class' => 'site-highlight quoted'], 'site overrides the core style and the class is cleaned');
blocksAssert(textStyleClass('callout') === 'my-callout' && textStyleClass('') === '' && textStyleClass('nope') === '', 'textStyleClass');
$options = textStyleOptions();
blocksAssert($options[0]['value'] === '' && array_column($options, 'value') === ['', 'highlight', 'callout', 'bare'], 'style options start with the plain block');
$textType = getBlockTypes()['text'];
$styleField = array_values(array_filter($textType['fields'], static fn($f) => $f['key'] === 'style'))[0] ?? null;
blocksAssert($styleField !== null && array_column($styleField['options'], 'value') === ['', 'highlight', 'callout', 'bare'], 'block type select uses the registry');
blocksAssert(isset(getBlockTypes()['gallery']) && getBlockTypes()['gallery']['defaults'] === ['images' => [], 'layout' => 'column'], 'gallery block type registered');

// Sanitizer: unknown types and keys dropped, HTML cleaned, paths checked, legacy alias kept
$dirty = [
    ['type' => 'text', 'id' => 'a b', 'content' => '<p onclick="x()">Hi <script>evil()</script><b>there</b></p>', 'title' => '<i>T</i>', 'style' => 'callout', 'hidden' => false],
    ['type' => 'image', 'src' => 'javascript:alert(1)', 'alt' => 'A', 'width' => 'half', 'lightbox' => true, '_attrs' => ' onload="x"', '9bad' => 'x'],
    ['type' => 'gallery', 'layout' => 'grid', 'images' => [['src' => '/assets/images/a.webp', 'caption' => 'C']]],
    ['type' => 'bogus', 'content' => 'x'],
    'not a block',
    ['type' => 'project', 'title' => 'Legacy card'],
    ['type' => 'youtube', 'videoId' => 'abc', 'url' => 'https://example.com/v?x=1'],
];
$clean = nibblyNewsSanitizeBlocks($dirty);
blocksAssert(count($clean) === 5 && array_column($clean, 'type') === ['text', 'image', 'gallery', 'card', 'youtube'], 'sanitizer keeps known types: ' . implode(',', array_column($clean, 'type')));
blocksAssert($clean[0]['id'] === 'ab' && !str_contains($clean[0]['content'], 'script') && !str_contains($clean[0]['content'], 'onclick') && str_contains($clean[0]['content'], '<b>there</b>'), 'text content sanitized: ' . $clean[0]['content']);
blocksAssert($clean[0]['title'] === 'T' && $clean[0]['style'] === 'callout' && $clean[0]['hidden'] === false, 'plain strings lose tags, flags stay');
blocksAssert($clean[1]['src'] === '' && !isset($clean[1]['_attrs']) && !isset($clean[1]['9bad']) && $clean[1]['lightbox'] === true && $clean[1]['width'] === 'half', 'image: bad src emptied, internal and odd keys dropped');
blocksAssert($clean[2]['images'][0]['src'] === '/assets/images/a.webp' && $clean[2]['layout'] === 'grid', 'gallery images kept as array');
blocksAssert(preg_match('/^block_[a-f0-9]{8}$/', $clean[3]['id']) === 1 && $clean[4]['url'] === 'https://example.com/v?x=1', 'generated ids and absolute http urls');
blocksAssert(nibblyNewsSanitizeBlocks('nope') === [] && nibblyNewsSanitizeBlocks(null) === [], 'non-arrays give an empty list');

// Rendering for visitors: styles wrap, titles, floats, gallery, hidden and unknown blocks skipped
$blocks = [
    ['type' => 'text', 'title' => 'Kapitel <1>', 'titleTag' => 'h3', 'style' => 'callout', 'content' => '<p>Erster</p>'],
    ['type' => 'text', 'content' => 'bare text'],
    ['type' => 'text', 'style' => 'unknown', 'content' => '<p>Plain</p>', 'hidden' => true],
    ['type' => 'image', 'src' => '/assets/images/a.webp', 'alt' => 'Alt', 'width' => 'half', 'align' => 'left', 'caption' => 'Cap', 'credit' => 'Foto: X', 'lightbox' => true],
    ['type' => 'image', 'src' => '/assets/images/b.webp', 'alt' => '', 'width' => 'full', 'lightbox' => false],
    ['type' => 'gallery', 'layout' => 'grid', 'images' => [['src' => '/assets/images/g1.webp', 'alt' => 'G1', 'credit' => 'C1'], ['src' => 'ftp://nope/x.webp'], 'junk']],
    ['type' => 'divider'],
    ['type' => 'bogus'],
    ['type' => 'youtube', 'videoId' => 'dQw4w9WgXcQ'],
];
$html = nibblyNewsRenderBlocks($blocks);
blocksAssert(str_contains($html, '<div class="my-callout">' . "\n" . '<h3>Kapitel &lt;1&gt;</h3>'), 'style wrapper and escaped title');
blocksAssert(str_contains($html, '<p>bare text</p>'), 'bare text becomes a paragraph');
blocksAssert(!str_contains($html, 'Plain'), 'hidden block stays out');
blocksAssert(str_contains($html, '<figure class="block-image block-image--half block-image--left">'), 'image width and align classes');
blocksAssert(str_contains($html, '<a href="/assets/images/a.webp" data-lightbox="image"><img src="/assets/images/a.webp" alt="Alt"'), 'lightbox link');
blocksAssert(str_contains($html, '<figcaption>Cap <span class="block-image__credit">Foto: X</span></figcaption>'), 'caption with credit');
blocksAssert(str_contains($html, '<figure class="block-image block-image--full">' . "\n" . '    <img src="/assets/images/b.webp"') && substr_count($html, 'data-lightbox="image"') === 1, 'full image without lightbox or align');
blocksAssert(substr_count($html, '<figcaption>') === 2, 'no empty figcaption for visitors: ' . substr_count($html, '<figcaption>'));
blocksAssert(str_contains($html, '<div class="block-gallery block-gallery--grid">') && substr_count($html, 'block-gallery__item') === 1 && str_contains($html, 'data-gallery-item="0"') && str_contains($html, 'data-lightbox="gallery"'), 'gallery renders valid images only');
blocksAssert(str_contains($html, '<figcaption><span class="block-image__credit">C1</span></figcaption>'), 'gallery credit without caption');
blocksAssert(str_contains($html, 'dQw4w9WgXcQ') && str_contains($html, '<hr'), 'other block types render');
blocksAssert(!str_contains($html, 'data-news-block') && !str_contains($html, 'news-post-page__block'), 'no editor hooks for visitors');

// Rendering for admins: hooks per block, on the figure/gallery itself, text wrapped, empty caption kept
$admin = nibblyNewsRenderBlocks($blocks, 'sections');
blocksAssert(str_contains($admin, '<div class="news-post-page__block" data-news-block="sections.0" data-block-type="text"><p>Erster</p></div>'), 'text hook wrapper');
blocksAssert(str_contains($admin, '<figure class="block-image block-image--half block-image--left" data-news-block="sections.3" data-block-type="image">'), 'image hook on the figure');
blocksAssert(str_contains($admin, '<div class="block-gallery block-gallery--grid" data-news-block="sections.5" data-block-type="gallery">'), 'gallery hook on the container');
blocksAssert(str_contains($admin, '<div class="news-post-page__block" data-news-block="sections.6" data-block-type="divider">'), 'other blocks wrapped with hook');
blocksAssert(!str_contains($admin, 'sections.2') && !str_contains($admin, 'sections.7'), 'hidden and unknown blocks keep their index but render nothing');
blocksAssert(substr_count($admin, '<figcaption>') === 3 && str_contains($admin, '<figcaption></figcaption>'), 'empty caption element for the editor');
blocksAssert(str_contains(nibblyNewsRenderBlocks([['type' => 'text', 'content' => '']], 'aside'), 'data-news-block="aside.0" data-block-type="text"><p></p></div>'), 'empty text block gets a paragraph to type into');
blocksAssert(nibblyNewsRenderBlocks([['type' => 'image', 'src' => ''], ['type' => 'gallery', 'images' => []]]) === '', 'empty media blocks render nothing for visitors');
blocksAssert(str_contains(nibblyNewsRenderBlocks([['type' => 'gallery', 'images' => []]], 'sections'), 'block-gallery--column" data-news-block="sections.0"'), 'empty gallery keeps its container for the editor');

// Body of either form
blocksAssert(nibblyNewsBodyHtml(['content' => '<p>Legacy</p>', 'sections' => $blocks]) === '<p>Legacy</p>', 'content string wins');
blocksAssert(str_contains(nibblyNewsBodyHtml(['sections' => $blocks]), 'my-callout') && nibblyNewsBodyHtml(['sections' => []]) === '' && nibblyNewsBodyHtml([]) === '', 'sections body');

// Category pages at their own base
blocksAssert(nibblyNewsCategoryBaseUrl('de') === '/rubrik' && nibblyNewsCategoryBaseUrl('en') === '/en/rubrik', 'category base with language prefix: ' . nibblyNewsCategoryBaseUrl('en'));
blocksAssert(nibblyNewsSettings()['archive']['categoryBase'] === '/rubrik', 'categoryBase normalised');

echo "PASS news blocks: text style registry, block sanitizer, visitor and editor rendering of text, image and gallery blocks, body of either form, category base\n";
