<?php

declare(strict_types=1);

// AI disclosure labels: storage, sync along file operations, detection and markup.

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/nibbly-ai-labels-' . bin2hex(random_bytes(4));
mkdir($tmp, 0755, true);
define('NIBBLY_MEDIA_META_PATH', $tmp . '/media-meta.json');
define('SETTINGS_PATH', $tmp . '/settings.json');
require_once $root . '/includes/ai-labels.php';

function aiLabelAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

try {
    // Public sources map to media type and relative name
    aiLabelAssert(nibblyMediaRefFromSrc('/assets/images/a.webp') === ['type' => 'image', 'name' => 'a.webp'], 'root-relative image path');
    aiLabelAssert(nibblyMediaRefFromSrc('../assets/images/team/b.jpg?v=3') === ['type' => 'image', 'name' => 'team/b.jpg'], 'relative path with folder and query');
    aiLabelAssert(nibblyMediaRefFromSrc('https://example.org/site/assets/videos/c.mp4') === ['type' => 'video', 'name' => 'c.mp4'], 'absolute video URL');
    aiLabelAssert(nibblyMediaRefFromSrc('/assets/images/../admin/x.php') === null, 'path traversal must be rejected');
    aiLabelAssert(nibblyMediaRefFromSrc('/css/style.css') === null, 'non-media sources carry no label');

    // Set, read and remove
    aiLabelAssert(nibblyMediaAiLabel('/assets/images/a.webp') === '', 'unlabelled file');
    aiLabelAssert(nibblySetMediaAiLabel('image', 'a.webp', 'generated'), 'label must be stored');
    aiLabelAssert(nibblyMediaAiLabel('assets/images/a.webp') === 'generated', 'stored label must be read back');
    aiLabelAssert(nibblySetMediaAiLabel('image', 'a.webp', 'invalid') && nibblyMediaAiLabel('/assets/images/a.webp') === '', 'unknown kinds remove the label');
    aiLabelAssert(nibblySetMediaAiLabel('image', 'a.webp', ''), 'removing a missing label must succeed');

    // Rename/move, trash, restore, permanent delete
    nibblySetMediaAiLabel('image', 'b.webp', 'modified');
    aiLabelAssert(nibblyMoveMediaMeta('image', 'b.webp', 'image', 'fotos/b.webp') && nibblyMediaAiLabelFor('image', 'fotos/b.webp') === 'modified', 'label must follow a move');
    aiLabelAssert(nibblyMediaAiLabelFor('image', 'b.webp') === '', 'old name must no longer carry the label');
    nibblyMoveMediaMeta('image', 'fotos/b.webp', 'image-trash', 'fotos/b.webp');
    aiLabelAssert(nibblyMediaAiLabelFor('image-trash', 'fotos/b.webp') === 'modified', 'label must go to the trash namespace');
    nibblyMoveMediaMeta('image-trash', 'fotos/b.webp', 'image', 'fotos/b-1.webp');
    aiLabelAssert(nibblyMediaAiLabelFor('image', 'fotos/b-1.webp') === 'modified', 'label must come back on restore');
    nibblyMoveMediaMeta('image', 'fotos/b-1.webp', 'image-trash', 'fotos/b-1.webp');
    aiLabelAssert(nibblyForgetMediaMeta('image-trash', 'fotos/b-1.webp') && nibblyMediaAiLabelFor('image-trash', 'fotos/b-1.webp') === '', 'permanent delete must forget the label');
    $stored = json_decode((string)file_get_contents(NIBBLY_MEDIA_META_PATH), true);
    aiLabelAssert($stored === [], 'empty namespaces must be pruned');

    // Detection from embedded provenance (IPTC digital source type)
    $generated = $tmp . '/generated.jpg';
    file_put_contents($generated, "xmp Iptc4xmpExt:DigitalSourceType=\"http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia\"");
    $modified = $tmp . '/modified.png';
    file_put_contents($modified, "c2pa digitalSourceType http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia");
    $plain = $tmp . '/plain.webp';
    file_put_contents($plain, 'RIFF....WEBPVP8 ');
    aiLabelAssert(nibblyDetectAiLabel($generated) === 'generated', 'trained algorithmic media must be detected as generated');
    aiLabelAssert(nibblyDetectAiLabel($modified) === 'modified', 'composites must be detected as modified');
    aiLabelAssert(nibblyDetectAiLabel($plain) === '', 'files without provenance carry no label');

    // Markup: English artwork by default, alt text in the page language
    $GLOBALS['basePath'] = '../';
    nibblySetMediaAiLabel('image', 'c.webp', 'generated');
    aiLabelAssert(nibblyAiLabelArtworkMode() === 'en', 'English artwork is the default');
    $de = nibblyAiLabelHtml('/assets/images/c.webp', 'de');
    aiLabelAssert(str_contains($de, 'class="nb-ai-label nb-ai-label--generated"'), 'label wrapper with kind modifier');
    aiLabelAssert(str_contains($de, 'src="../css/ai-labels/en-generated.svg"') && str_contains($de, 'alt="KI-generiert"'), 'English artwork with a German alt text on German pages');
    $fr = nibblyAiLabelHtml('/assets/images/c.webp', 'fr');
    aiLabelAssert(str_contains($fr, 'en-generated.svg') && str_contains($fr, 'alt="Généré par IA"'), 'English artwork with a localized alt text');

    // Optional German artwork on German pages (Settings → Language → AI labels on images)
    file_put_contents(SETTINGS_PATH, json_encode(['general' => ['aiLabelArtwork' => 'page']]));
    aiLabelAssert(nibblyAiLabelArtworkMode(true) === 'page', 'the page-language artwork can be selected');
    aiLabelAssert(str_contains(nibblyAiLabelHtml('/assets/images/c.webp', 'de'), 'src="../css/ai-labels/de-generated.svg"'), 'German artwork on German pages when selected');
    aiLabelAssert(str_contains(nibblyAiLabelHtml('/assets/images/c.webp', 'fr'), 'en-generated.svg'), 'other languages keep the English artwork');
    file_put_contents(SETTINGS_PATH, json_encode(['general' => ['aiLabelArtwork' => 'unknown']]));
    aiLabelAssert(nibblyAiLabelArtworkMode(true) === 'en', 'unknown artwork modes fall back to English');
    aiLabelAssert(nibblyAiLabelHtml('/assets/images/unlabelled.webp', 'de') === '', 'unlabelled images render no label');
    aiLabelAssert(nibblyAiLabelsRendered(), 'rendered labels must be tracked for the touch script');
    $framed = nibblyAiLabelAttach('<img src="/assets/images/c.webp" alt="">', '/assets/images/c.webp', true);
    aiLabelAssert(str_starts_with($framed, '<span class="nb-ai-media"><img') && str_ends_with($framed, '</span></span>'), 'framed image and label');
    aiLabelAssert(nibblyAiLabelFrame('<img src="x.webp" alt="">') === '<img src="x.webp" alt="">', 'unlabelled markup stays unframed');

    // Editor data: labelled files and label templates, without marking labels as rendered
    unset($GLOBALS['nibblyAiLabelsRendered']);
    nibblySetMediaAiLabel('video', 'clip.mp4', 'modified');
    $editorJson = json_encode(nibblyAiLabelEditorConfig('de'));
    $editor = json_decode((string)$editorJson, true);
    aiLabelAssert(($editor['files']['image']['c.webp'] ?? '') === 'generated' && ($editor['files']['video']['clip.mp4'] ?? '') === 'modified', 'editor data lists labelled files');
    aiLabelAssert(str_contains($editor['markup']['generated'] ?? '', 'en-generated.svg') && str_contains($editor['markup']['modified'] ?? '', 'nb-ai-label--modified'), 'editor data carries the label markup per kind');
    aiLabelAssert(!nibblyAiLabelsRendered(), 'label templates for the editor do not count as rendered labels');
    nibblySetMediaAiLabel('image', 'c.webp', '');
    nibblySetMediaAiLabel('video', 'clip.mp4', '');
    aiLabelAssert(str_starts_with((string)json_encode(nibblyAiLabelEditorConfig('de')), '{"files":{}'), 'no labelled files encode as an object');

    // en-ai is a round "AI" badge that ships for later use (e.g. AI-generated texts)
    foreach (['de-generated', 'de-modified', 'en-generated', 'en-modified', 'en-ai'] as $artwork) {
        aiLabelAssert(is_file($root . '/css/ai-labels/' . $artwork . '.svg'), "artwork {$artwork} must ship with the core");
    }
} finally {
    array_map('unlink', glob($tmp . '/*') ?: []);
    array_map('unlink', glob($tmp . '/.*.lock') ?: []);
    @unlink(NIBBLY_MEDIA_META_PATH . '.lock');
    @rmdir($tmp);
}

echo "AI label smoke test passed.\n";
