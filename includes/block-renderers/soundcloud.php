<?php
/**
 * Block renderer: soundcloud
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
$scUrl = '';

if (!empty($section['trackId'])) {
    $scUrl = 'https%3A//api.soundcloud.com/tracks/' . htmlspecialchars($section['trackId']);
} elseif (!empty($section['url'])) {
    $scUrl = urlencode($section['url']);
}

if (!empty($scUrl)) {
    $html .= '<div class="audio-embed audio-embed-soundcloud">' . "\n";
    $html .= '    <iframe width="100%" height="20" scrolling="no" frameborder="no" allow="autoplay"' . "\n";
    $html .= '        src="https://w.soundcloud.com/player/?url=' . $scUrl . '&color=%233b82f6&inverse=true&auto_play=false&show_user=false">' . "\n";
    $html .= '    </iframe>' . "\n";
    $html .= '</div>' . "\n";
} elseif ($editable) {
    $html .= '<div class="audio-embed audio-embed-placeholder">' . "\n";
    $html .= '    <span class="placeholder-text">Enter SoundCloud Track ID</span>' . "\n";
    $html .= '</div>' . "\n";
}

if ($editable) {
    // Title is editor-only metadata; expose as inline-editable label.
    $title = $section['title'] ?? '';
    $html .= '<p class="block-media-title block-media-title--editor-only">' . editableText($page, "sections.$index.title", $title) . '</p>' . "\n";
}

return $html;
