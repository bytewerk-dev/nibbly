<?php
/**
 * Block renderer: image
 * Data: src, alt, caption, width (full|half|medium|small), align (right|left|center, for
 * reduced widths), credit, lightbox (true: the image links to its file, opened by js/lightbox.js).
 *
 * The optional internal key "_attrs" carries extra attributes for the <figure> (editor hooks of
 * a post block, see news-helper.php); it is never stored. In posts, reduced widths float
 * (css/components.css); on pages they keep their plain max-width (css/style.css).
 *
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
$src = $section['src'] ?? '';
$rawAlt = $section['alt'] ?? '';
$caption = trim((string)($section['caption'] ?? ''));
$credit = trim((string)($section['credit'] ?? ''));
$width = $section['width'] ?? 'full';
$attrs = (string)($section['_attrs'] ?? '');

$classes = ['block-image'];
if (in_array($width, ['full', 'half', 'medium', 'small'], true)) {
    $classes[] = 'block-image--' . $width;
}
if (in_array($width, ['half', 'medium', 'small'], true)) {
    $align = (string)($section['align'] ?? 'right');
    $classes[] = 'block-image--' . (in_array($align, ['left', 'center'], true) ? $align : 'right');
}

if (empty($src) && !$editable && $attrs === '') {
    return '';
}

$html .= '<figure class="' . implode(' ', $classes) . '"' . $attrs . '>' . "\n";
if ($editable) {
    $html .= '    ' . nibblyAiLabelFrame(editableImageSplit($page, "sections.$index.src", "sections.$index.alt", $src, $rawAlt)) . "\n";
    $html .= '    <figcaption>' . editableText($page, "sections.$index.caption", $caption) . '</figcaption>' . "\n";
} else {
    $img = nibblyAiLabelAttach(nibblyImageTag((string)$src, (string)$rawAlt), (string)$src, true);
    $isImageFile = (bool)preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/i', (string)(parse_url((string)$src, PHP_URL_PATH) ?: ''));
    if (!empty($section['lightbox']) && $isImageFile) {
        $html .= '    <a href="' . htmlspecialchars($src) . '" data-lightbox="image">' . $img . '</a>' . "\n";
    } else {
        $html .= '    ' . $img . "\n";
    }
    // a post block in the editor needs a caption element to type into, even while it is empty
    if ($caption !== '' || $credit !== '' || $attrs !== '') {
        $html .= '    <figcaption>' . htmlspecialchars($caption);
        if ($credit !== '') $html .= ($caption !== '' ? ' ' : '') . '<span class="block-image__credit">' . htmlspecialchars($credit) . '</span>';
        $html .= '</figcaption>' . "\n";
    }
}
$html .= '</figure>' . "\n";

return $html;
