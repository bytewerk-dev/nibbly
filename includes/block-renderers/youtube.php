<?php
/**
 * Block renderer: youtube
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
$title = $section['title'] ?? '';

if (!empty($section['videoId'])) {
    $videoId = htmlspecialchars($section['videoId']);
    $html .= '<div class="video-container video-container-framed">' . "\n";
    $html .= '    <iframe src="https://www.youtube-nocookie.com/embed/' . $videoId . '"' . "\n";
    $html .= '        frameborder="0"' . "\n";
    $html .= '        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"' . "\n";
    $html .= '        allowfullscreen>' . "\n";
    $html .= '    </iframe>' . "\n";
    $html .= '</div>' . "\n";
} elseif ($editable) {
    $html .= '<div class="video-container video-container-placeholder">' . "\n";
    $html .= '    <span class="placeholder-text">Enter YouTube Video ID</span>' . "\n";
    $html .= '</div>' . "\n";
}

if ($editable) {
    // Title is editor-only metadata (not rendered for visitors today).
    // Expose as inline-editable label so admins don't need to open the modal.
    $html .= '<p class="block-media-title block-media-title--editor-only">' . editableText($page, "sections.$index.title", $title) . '</p>' . "\n";
}

return $html;
