<?php
/**
 * Block renderer: card
 * Also handles legacy 'project' type.
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';

$title = $section['title'] ?? '';
$content = $section['content'] ?? '';
$image = $section['image'] ?? '';

$html .= '<div class="card-item">' . "\n";
if ($editable) {
    // Use split-format so the image and its alt text live at two sibling keys
    // (sections.N.image, sections.N.alt) — preserves title/content in the same
    // section object. Defaults alt to the title when empty.
    $defaultAlt = $section['alt'] ?? ($title !== '' ? $title : 'Image');
    $html .= '    ' . editableImageSplit($page, "sections.$index.image", "sections.$index.alt", $image, $defaultAlt) . "\n";
    $html .= '    <h3>' . editableText($page, "sections.$index.title", $title) . '</h3>' . "\n";
    $html .= '    <p>' . editableText($page, "sections.$index.content", $content) . '</p>' . "\n";
} else {
    if (!empty($image)) {
        $alt = !empty($section['alt']) ? $section['alt'] : ($title !== '' ? $title : 'Image');
        $html .= '    <img src="' . htmlspecialchars($image) . '" alt="' . htmlspecialchars($alt) . '">' . "\n";
    }
    if ($title !== '') {
        $html .= '    <h3>' . htmlspecialchars($title) . '</h3>' . "\n";
    }
    if ($content !== '') {
        $html .= '    <p>' . htmlspecialchars($content) . '</p>' . "\n";
    }
}
$html .= '</div>' . "\n";

return $html;
