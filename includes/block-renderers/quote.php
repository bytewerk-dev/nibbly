<?php
/**
 * Block renderer: quote
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
$text = $section['text'] ?? '';
if (empty($text) && !$editable) return '';

$attribution = $section['attribution'] ?? '';
$style = $section['style'] ?? 'default';
$styleClass = $style === 'large' ? ' block-quote--large' : '';

$html .= '<blockquote class="block-quote' . $styleClass . '">' . "\n";
$html .= '    <p>' . nl2br(htmlspecialchars($text)) . '</p>' . "\n";
if (!empty($attribution)) {
    $html .= '    <cite>' . htmlspecialchars($attribution) . '</cite>' . "\n";
}
$html .= '</blockquote>' . "\n";

return $html;
