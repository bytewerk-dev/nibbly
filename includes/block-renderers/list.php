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

$items = [];
if ($content === '' && !empty($section['items']) && is_array($section['items'])) {
    foreach ($section['items'] as $item) {
        if (is_array($item)) {
            $text = trim((string)($item['text'] ?? ''));
        } else {
            $text = trim((string)$item);
        }
        if ($text !== '') {
            $items[] = $text;
        }
    }
}

if (empty($content) && empty($items) && !$editable) return '';

$styleClass = $style === 'numbered' ? ' block-list--numbered' : ' block-list--bullet';

$html .= '<div class="block-list' . $styleClass . '">' . "\n";
if ($editable) {
    $html .= '    <h3>' . editableText($page, "sections.$index.title", $title) . '</h3>' . "\n";
    $html .= editableHtml($page, "sections.$index.content", $content);
} else {
    if ($title !== '') {
        $html .= '    <h3>' . htmlspecialchars($title) . '</h3>' . "\n";
    }
    if (!empty($content)) {
        $html .= sanitizeHtml($content);
    } elseif (!empty($items)) {
        $tag = $style === 'numbered' ? 'ol' : 'ul';
        $html .= '    <' . $tag . '>' . "\n";
        foreach ($items as $item) {
            $html .= '        <li>' . htmlspecialchars($item) . '</li>' . "\n";
        }
        $html .= '    </' . $tag . '>' . "\n";
    }
}
$html .= '</div>' . "\n";

return $html;
