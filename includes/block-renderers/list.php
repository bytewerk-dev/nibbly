<?php
/**
 * Block renderer: list
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
$title = $section['title'] ?? '';
$style = $section['style'] ?? 'bullet';
$content = $section['content'] ?? '';

if (empty($content) && !$editable) return '';

$styleClass = $style === 'numbered' ? ' block-list--numbered' : ' block-list--bullet';

$html .= '<div class="block-list' . $styleClass . '">' . "\n";
if (!empty($title)) {
    $html .= '    <h3>' . htmlspecialchars($title) . '</h3>' . "\n";
}
$html .= sanitizeHtml($content);
$html .= '</div>' . "\n";

return $html;
