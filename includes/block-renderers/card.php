<?php
/**
 * Block renderer: card
 * Also handles legacy 'project' type.
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';

$html .= '<div class="card-item">' . "\n";
if (!empty($section['image'])) {
    $alt = !empty($section['title']) ? htmlspecialchars($section['title']) : 'Image';
    $html .= '    <img src="' . htmlspecialchars($section['image']) . '" alt="' . $alt . '">' . "\n";
}
if (!empty($section['title'])) {
    $html .= '    <h3>' . htmlspecialchars($section['title']) . '</h3>' . "\n";
}
if (!empty($section['content'])) {
    $html .= '    <p>' . htmlspecialchars($section['content']) . '</p>' . "\n";
}
$html .= '</div>' . "\n";

return $html;
