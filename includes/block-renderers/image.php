<?php
/**
 * Block renderer: image
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
$src = $section['src'] ?? '';

if (empty($src)) {
    if ($editable) {
        $html .= '<div class="block-image block-image--placeholder">' . "\n";
        $html .= '    <span class="placeholder-text">Select an image</span>' . "\n";
        $html .= '</div>' . "\n";
    }
    return $html;
}

$alt = htmlspecialchars($section['alt'] ?? '');
$caption = $section['caption'] ?? '';
$width = $section['width'] ?? 'full';
$widthClass = in_array($width, ['full', 'medium', 'small']) ? " block-image--{$width}" : '';

$html .= '<figure class="block-image' . $widthClass . '">' . "\n";
$html .= '    <img src="' . htmlspecialchars($src) . '" alt="' . $alt . '" loading="lazy">' . "\n";
if (!empty($caption)) {
    $html .= '    <figcaption>' . htmlspecialchars($caption) . '</figcaption>' . "\n";
}
$html .= '</figure>' . "\n";

return $html;
