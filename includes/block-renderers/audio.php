<?php
/**
 * Block renderer: audio
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';

if (!empty($section['src'])) {
    $audioSrc = htmlspecialchars($section['src']);
    $rawTitle = $section['title'] ?? '';
    $playerId = 'player-' . uniqid();
    $html .= '<div class="custom-audio-player" data-player-id="' . $playerId . '">' . "\n";
    $html .= '    <audio id="' . $playerId . '" preload="metadata">' . "\n";
    $html .= '        <source src="' . $audioSrc . '" type="audio/mpeg">' . "\n";
    $html .= '    </audio>' . "\n";
    $html .= '    <button type="button" class="audio-play-btn" aria-label="Play">' . "\n";
    $html .= '        <svg class="icon-play" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>' . "\n";
    $html .= '        <svg class="icon-pause" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>' . "\n";
    $html .= '    </button>' . "\n";
    $html .= '    <div class="audio-info">' . "\n";
    if ($editable) {
        $html .= '        <div class="audio-title">' . editableText($page, "sections.$index.title", $rawTitle) . '</div>' . "\n";
    } else {
        $html .= '        <div class="audio-title">' . htmlspecialchars($rawTitle !== '' ? $rawTitle : 'Audio') . '</div>' . "\n";
    }
    $html .= '        <div class="audio-progress-container">' . "\n";
    $html .= '            <div class="audio-progress-bar">' . "\n";
    $html .= '                <div class="audio-progress"></div>' . "\n";
    $html .= '                <div class="audio-progress-handle"></div>' . "\n";
    $html .= '            </div>' . "\n";
    $html .= '            <div class="audio-time">' . "\n";
    $html .= '                <span class="audio-current">0:00</span>' . "\n";
    $html .= '                <span class="audio-duration">0:00</span>' . "\n";
    $html .= '            </div>' . "\n";
    $html .= '        </div>' . "\n";
    $html .= '    </div>' . "\n";
    $html .= '    <div class="audio-volume">' . "\n";
    $html .= '        <button type="button" class="audio-volume-btn" aria-label="Volume">' . "\n";
    $html .= '            <svg class="icon-volume-high" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>' . "\n";
    $html .= '            <svg class="icon-volume-low" viewBox="0 0 24 24"><path d="M18.5 12c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM5 9v6h4l5 5V4L9 9H5z"/></svg>' . "\n";
    $html .= '            <svg class="icon-volume-mute" viewBox="0 0 24 24"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>' . "\n";
    $html .= '        </button>' . "\n";
    $html .= '        <div class="audio-volume-slider">' . "\n";
    $html .= '            <div class="audio-volume-track">' . "\n";
    $html .= '                <div class="audio-volume-fill"></div>' . "\n";
    $html .= '                <div class="audio-volume-handle"></div>' . "\n";
    $html .= '            </div>' . "\n";
    $html .= '        </div>' . "\n";
    $html .= '    </div>' . "\n";
    $html .= '</div>' . "\n";
} elseif ($editable) {
    $html .= '<div class="audio-embed audio-embed-placeholder">' . "\n";
    $html .= '    <span class="placeholder-text">Select audio file</span>' . "\n";
    $html .= '</div>' . "\n";
}

return $html;
