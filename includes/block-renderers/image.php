<?php
/**
 * Block renderer: image
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
$src = $section['src'] ?? '';
$rawAlt = $section['alt'] ?? '';
$caption = $section['caption'] ?? '';
$width = $section['width'] ?? 'full';
$widthClass = in_array($width, ['full', 'medium', 'small']) ? " block-image--{$width}" : '';

if (empty($src) && !$editable) {
    return '';
}

$html .= '<figure class="block-image' . $widthClass . '">' . "\n";
if ($editable) {
    $html .= '    ' . editableImageSplit($page, "sections.$index.src", "sections.$index.alt", $src, $rawAlt) . "\n";
    $html .= '    <figcaption>' . editableText($page, "sections.$index.caption", $caption) . '</figcaption>' . "\n";
} else {
    $html .= '    <img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($rawAlt) . '" loading="lazy">' . "\n";
    if ($caption !== '') {
        $html .= '    <figcaption>' . htmlspecialchars($caption) . '</figcaption>' . "\n";
    }
}
$html .= '</figure>' . "\n";

return $html;
