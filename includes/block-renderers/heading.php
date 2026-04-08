<?php
/**
 * Block renderer: heading
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
$text = $section['text'] ?? '';
if (empty($text) && !$editable) return '';

$level = $section['level'] ?? 'h2';
$allowedLevels = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
if (!in_array($level, $allowedLevels)) $level = 'h2';

$subtitle = $section['subtitle'] ?? '';

$html .= '<div class="block-heading">' . "\n";
$html .= "    <{$level}>" . htmlspecialchars($text) . "</{$level}>\n";
if (!empty($subtitle) || $editable) {
    $html .= '    <p class="block-heading__subtitle">' . htmlspecialchars($subtitle) . '</p>' . "\n";
}
$html .= '</div>' . "\n";

return $html;
