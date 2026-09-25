<?php
/**
 * AI disclosure labels for media (EU AI Act, Art. 50).
 *
 * Images that were created or modified with AI carry a label wherever Nibbly
 * renders them, shown when a visitor lingers on the image and always available to
 * screen readers (alt text). The label kind ('generated' or 'modified') is stored per
 * media file in content/media-meta.json and managed in the media library; the
 * media API carries it along when files are renamed, moved, trashed or restored.
 *
 * Rendering: nibblyAiLabelHtml($src) returns the label markup for an image source
 * (empty for unlabelled files). editableImage(), editableImageSplit() and the core
 * blocks call it automatically. The label artwork lives in css/ai-labels/. In edit
 * mode, js/inline-editor.js keeps labels in sync with image changes (window.NB_AI_LABELS).
 */

require_once __DIR__ . '/json-store.php';

const NIBBLY_AI_LABEL_KINDS = ['generated', 'modified'];

function nibblyMediaMetaPath(): string {
    return defined('NIBBLY_MEDIA_META_PATH') ? NIBBLY_MEDIA_META_PATH : dirname(__DIR__) . '/content/media-meta.json';
}

/** Stored media metadata: [type => [relative name => ['ai' => kind]]]. */
function nibblyMediaMeta(bool $reload = false): array {
    static $cache = null;
    if ($cache === null || $reload) {
        $path = nibblyMediaMetaPath();
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        $cache = is_array($data) ? $data : [];
    }
    return $cache;
}

/** Media type and name relative to the type folder for a public source, e.g. /assets/images/a/b.webp. */
function nibblyMediaRefFromSrc(string $src): ?array {
    $path = rawurldecode((string)(parse_url($src, PHP_URL_PATH) ?? ''));
    foreach (['image' => 'assets/images/', 'video' => 'assets/videos/'] as $type => $marker) {
        $position = strpos($path, $marker);
        if ($position === false) continue;
        $name = substr($path, $position + strlen($marker));
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) return null;
        return ['type' => $type, 'name' => $name];
    }
    return null;
}

/** Label kind of a media file: 'generated', 'modified' or ''. */
function nibblyMediaAiLabelFor(string $type, string $name): string {
    $kind = nibblyMediaMeta()[$type][$name]['ai'] ?? '';
    return in_array($kind, NIBBLY_AI_LABEL_KINDS, true) ? $kind : '';
}

/** Label kind for a public image or video source. */
function nibblyMediaAiLabel(string $src): string {
    $ref = nibblyMediaRefFromSrc($src);
    return $ref ? nibblyMediaAiLabelFor($ref['type'], $ref['name']) : '';
}

/** Set ('generated', 'modified') or remove ('') the label of a media file. */
function nibblySetMediaAiLabel(string $type, string $name, string $kind): bool {
    $kind = in_array($kind, NIBBLY_AI_LABEL_KINDS, true) ? $kind : '';
    if ($kind === '' && !isset(nibblyMediaMeta()[$type][$name])) return true;
    $ok = nibblyJsonUpdate(nibblyMediaMetaPath(), function (array &$data) use ($type, $name, $kind) {
        if ($kind !== '') {
            $data[$type][$name]['ai'] = $kind;
            return true;
        }
        unset($data[$type][$name]['ai']);
        nibblyMediaMetaPrune($data, $type, $name);
        return true;
    });
    nibblyMediaMeta(true);
    return $ok;
}

/** Carry metadata along when a media file is renamed, moved, trashed or restored. */
function nibblyMoveMediaMeta(string $fromType, string $fromName, string $toType, string $toName): bool {
    if (!isset(nibblyMediaMeta()[$fromType][$fromName]) || ($fromType === $toType && $fromName === $toName)) return true;
    $ok = nibblyJsonUpdate(nibblyMediaMetaPath(), function (array &$data) use ($fromType, $fromName, $toType, $toName) {
        if (!isset($data[$fromType][$fromName])) return false;
        $data[$toType][$toName] = $data[$fromType][$fromName];
        unset($data[$fromType][$fromName]);
        nibblyMediaMetaPrune($data, $fromType, $fromName);
        return true;
    });
    nibblyMediaMeta(true);
    return $ok;
}

/** Forget the metadata of a deleted file, or of a whole namespace when $name is null. */
function nibblyForgetMediaMeta(string $type, ?string $name = null): bool {
    $meta = nibblyMediaMeta();
    if ($name === null ? !isset($meta[$type]) : !isset($meta[$type][$name])) return true;
    $ok = nibblyJsonUpdate(nibblyMediaMetaPath(), function (array &$data) use ($type, $name) {
        if ($name === null) {
            unset($data[$type]);
            return true;
        }
        unset($data[$type][$name]);
        nibblyMediaMetaPrune($data, $type, $name);
        return true;
    });
    nibblyMediaMeta(true);
    return $ok;
}

function nibblyMediaMetaPrune(array &$data, string $type, string $name): void {
    if (isset($data[$type][$name]) && empty($data[$type][$name])) unset($data[$type][$name]);
    if (isset($data[$type]) && empty($data[$type])) unset($data[$type]);
}

/**
 * Best-effort detection from embedded provenance: the IPTC digital source type,
 * written to XMP or C2PA manifests by image generators and generative editing.
 */
function nibblyDetectAiLabel(string $file): string {
    $size = @filesize($file);
    if (!$size || $size > 16 * 1024 * 1024) return '';
    $bytes = (string)@file_get_contents($file);
    if (stripos($bytes, 'digitalsourcetype/compositeWithTrainedAlgorithmicMedia') !== false
        || stripos($bytes, 'digitalsourcetype/compositeSynthetic') !== false) {
        return 'modified';
    }
    if (stripos($bytes, 'digitalsourcetype/trainedAlgorithmicMedia') !== false) {
        return 'generated';
    }
    return '';
}

/** Two-letter language of the rendered page. */
function nibblyAiLabelLanguage(?string $lang = null): string {
    $lang = $lang ?? ($GLOBALS['currentLang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'));
    return strtolower(substr((string)$lang, 0, 2));
}

/** Accessible label text in the page language. */
function nibblyAiLabelText(string $kind, ?string $lang = null): string {
    $texts = [
        'cs' => ['generated' => 'Vytvořeno pomocí AI', 'modified' => 'Upraveno pomocí AI'],
        'de' => ['generated' => 'KI-generiert', 'modified' => 'KI-modifiziert'],
        'en' => ['generated' => 'AI-generated', 'modified' => 'AI-modified'],
        'es' => ['generated' => 'Generado con IA', 'modified' => 'Modificado con IA'],
        'fr' => ['generated' => 'Généré par IA', 'modified' => 'Modifié par IA'],
        'it' => ['generated' => 'Generato con IA', 'modified' => 'Modificato con IA'],
        'pl' => ['generated' => 'Wygenerowano przez AI', 'modified' => 'Zmodyfikowano przez AI'],
        'pt' => ['generated' => 'Gerado por IA', 'modified' => 'Modificado por IA'],
        'tr' => ['generated' => 'Yapay zekâ ile oluşturuldu', 'modified' => 'Yapay zekâ ile değiştirildi'],
    ];
    $lang = nibblyAiLabelLanguage($lang);
    return ($texts[$lang] ?? $texts['en'])[$kind] ?? '';
}

/** Label markup for an image source; empty when the file carries no AI label. */
function nibblyAiLabelHtml(string $src, ?string $lang = null): string {
    $kind = nibblyMediaAiLabel($src);
    return $kind === '' ? '' : nibblyAiLabelMarkup($kind, $lang);
}

/** Label markup for a label kind. The artwork says "KI" on German pages and "AI" otherwise. */
function nibblyAiLabelMarkup(string $kind, ?string $lang = null): string {
    if (!in_array($kind, NIBBLY_AI_LABEL_KINDS, true)) return '';
    $lang = nibblyAiLabelLanguage($lang);
    $artwork = ($lang === 'de' ? 'de' : 'en') . '-' . $kind;
    // Width at the default height of 22px, from the artwork's aspect ratio
    $widths = ['de-generated' => 111, 'de-modified' => 122, 'en-generated' => 114, 'en-modified' => 102];
    $GLOBALS['nibblyAiLabelsRendered'] = true;
    $base = (string)($GLOBALS['basePath'] ?? '');
    $text = htmlspecialchars(nibblyAiLabelText($kind, $lang), ENT_QUOTES, 'UTF-8');

    return '<span class="nb-ai-label nb-ai-label--' . $kind . '">'
        . '<img class="nb-ai-label__badge" src="' . htmlspecialchars($base . 'css/ai-labels/' . $artwork . '.svg', ENT_QUOTES, 'UTF-8')
        . '" width="' . $widths[$artwork] . '" height="22" alt="' . $text . '" decoding="async"></span>';
}

/**
 * Image markup followed by its label. With $frame, a labelled image and its label
 * share a positioned frame, so the label sits on the image even when text follows.
 */
function nibblyAiLabelAttach(string $imageHtml, string $src, bool $frame = false): string {
    $label = nibblyAiLabelHtml($src);
    if ($label === '') return $imageHtml;
    return $frame
        ? '<span class="nb-ai-media">' . $imageHtml . $label . '</span>'
        : $imageHtml . $label;
}

/** Wrap already rendered image markup (image + label) in a label frame when it carries a label. */
function nibblyAiLabelFrame(string $html): string {
    return str_contains($html, 'class="nb-ai-label ') ? '<span class="nb-ai-media">' . $html . '</span>' : $html;
}

/**
 * Label data for the inline editor (window.NB_AI_LABELS): labelled files and the label
 * markup per kind, so labels follow image swaps and media library changes right away.
 */
function nibblyAiLabelEditorConfig(?string $lang = null): array {
    $files = [];
    foreach (['image', 'video'] as $type) {
        foreach (array_keys((array)(nibblyMediaMeta()[$type] ?? [])) as $name) {
            $kind = nibblyMediaAiLabelFor($type, (string)$name);
            if ($kind !== '') $files[$type][(string)$name] = $kind;
        }
    }
    // Templates only: they must not count as labels rendered on the page
    $rendered = $GLOBALS['nibblyAiLabelsRendered'] ?? false;
    $markup = [];
    foreach (NIBBLY_AI_LABEL_KINDS as $kind) {
        $markup[$kind] = nibblyAiLabelMarkup($kind, $lang);
    }
    $GLOBALS['nibblyAiLabelsRendered'] = $rendered;

    return ['files' => (object)array_map(fn($names) => (object)$names, $files), 'markup' => $markup];
}

/** Whether a label was rendered on this page (loads js/ai-labels.js). */
function nibblyAiLabelsRendered(): bool {
    return !empty($GLOBALS['nibblyAiLabelsRendered']);
}
