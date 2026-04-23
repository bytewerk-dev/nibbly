<?php
/**
 * Block renderer: text
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
$isHighlight = !empty($section['style']) && $section['style'] === 'highlight';

if ($isHighlight) {
    $html .= '<div class="content-highlight">' . "\n";
}

$title = $section['title'] ?? '';
$tag = $section['titleTag'] ?? 'h2';
$allowedTitleTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
if (!in_array($tag, $allowedTitleTags)) {
    $tag = 'h2';
}

if ($editable) {
    $html .= "<{$tag}>" . editableText($page, "sections.$index.title", $title) . "</{$tag}>\n";
    $html .= editableHtml($page, "sections.$index.content", $section['content'] ?? '');
} else {
    if ($title !== '') {
        $html .= "<{$tag}>" . htmlspecialchars($title) . "</{$tag}>\n";
    }
    $html .= sanitizeHtml($section['content'] ?? '');
}

if ($isHighlight) {
    $html .= '</div>' . "\n";
}

return $html;
