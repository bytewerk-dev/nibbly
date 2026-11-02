<?php
/**
 * Block renderer: gallery
 * Data: images => [{src, alt, caption, credit}], layout => column|grid|full
 *
 * column: image column the text flows around (a swipeable strip on phones), grid: tiles,
 * full: stacked at the full width of the content box. Every image links to its file
 * (data-lightbox, opened by js/lightbox.js). The optional internal key "_attrs" carries
 * extra attributes for the container (editor hooks of a post block, see news-helper.php);
 * it is never stored.
 *
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$images = is_array($section['images'] ?? null) ? $section['images'] : [];
$attrs = (string)($section['_attrs'] ?? '');
if (!$images && $attrs === '') {
    return '';
}
$layout = in_array($section['layout'] ?? '', ['grid', 'full'], true) ? $section['layout'] : 'column';

$html = '<div class="block-gallery block-gallery--' . $layout . '"' . $attrs . '>' . "\n";
foreach ($images as $i => $image) {
    if (!is_array($image)) continue;
    $src = trim((string)($image['src'] ?? ''));
    if ($src === '' || !preg_match('#^(/|https?://)#i', $src)) continue;
    $alt = (string)($image['alt'] ?? '');
    $img = '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '" loading="lazy">';
    $html .= '    <figure class="block-gallery__item" data-gallery-item="' . (int)$i . '">';
    $html .= '<a href="' . htmlspecialchars($src) . '" data-lightbox="gallery">' . nibblyAiLabelAttach($img, $src) . '</a>';
    $caption = trim((string)($image['caption'] ?? ''));
    $credit = trim((string)($image['credit'] ?? ''));
    if ($caption !== '' || $credit !== '') {
        $html .= '<figcaption>' . htmlspecialchars($caption);
        if ($credit !== '') $html .= ($caption !== '' ? ' ' : '') . '<span class="block-image__credit">' . htmlspecialchars($credit) . '</span>';
        $html .= '</figcaption>';
    }
    $html .= '</figure>' . "\n";
}
$html .= '</div>' . "\n";

return $html;
