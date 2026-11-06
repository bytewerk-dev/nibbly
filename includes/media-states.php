<?php
/**
 * Media states: flags and properties a media file carries, several at once.
 *
 * A state is registered once (core or site) and set per file in the media library. The
 * core registers the AI disclosure label ('ai': generated | modified, see ai-labels.php).
 * A site adds its own states in includes/site-media-states.php (a site file, survives
 * core updates) that returns id => definition. Definition keys:
 *
 *   label        string, or [lang => string] (admin language, English or first as fallback)
 *   description  optional, same forms; shown under the label in the library
 *   badge        optional short text on the thumbnail while the state is active (bool states)
 *   type         'bool' (default: a checkbox) or 'choice' (radio group: none + options)
 *   options      choice only: value => ['label' => …, 'description' => …, 'badge' => …]
 *   media        media types the state applies to, default ['image', 'video']
 *
 * Ids: lowercase letters, digits, "-" and "_". Storage in content/media-meta.json per file:
 * the 'ai' state stays at $entry['ai'] (as before, nibblyMediaAiLabelFor() reads it), every
 * other state lives under $entry['states'][id]. Metadata follows the file when it is
 * renamed, moved, trashed or restored (nibblyMoveMediaMeta in ai-labels.php).
 *
 * Reading on the site: nibblyMediaStateForSrc($src, 'no-shadow') → value or null.
 */

require_once __DIR__ . '/ai-labels.php';

/** Registered states: id => normalised definition (labels resolved to the admin language). */
function nibblyMediaStates(bool $reload = false): array {
    static $states = null;
    if ($states !== null && !$reload) return $states;
    $states = [];
    $core = [
        'ai' => [
            'label' => nibblyMediaStateT('media.ai_label', 'AI label'),
            'description' => nibblyMediaStateT('media.ai_hint', ''),
            'type' => 'choice',
            'options' => [
                'generated' => [
                    'label' => nibblyMediaStateT('media.ai_generated', 'AI-generated'),
                    'description' => nibblyMediaStateT('media.ai_generated_desc', ''),
                    'badge' => nibblyMediaStateT('media.ai_badge', 'AI'),
                ],
                'modified' => [
                    'label' => nibblyMediaStateT('media.ai_modified', 'AI-modified'),
                    'description' => nibblyMediaStateT('media.ai_modified_desc', ''),
                    'badge' => nibblyMediaStateT('media.ai_badge', 'AI'),
                ],
            ],
            'media' => ['image', 'video'],
        ],
    ];
    $site = [];
    $file = __DIR__ . '/site-media-states.php';
    if (is_file($file)) {
        $list = include $file;
        if (is_array($list)) $site = $list;
    }
    foreach ($core + $site as $id => $definition) {
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id) || !is_array($definition)) continue;
        $normalised = nibblyMediaStateNormalise($id, $definition);
        if ($normalised !== null) $states[$id] = $normalised;
    }
    return $states;
}

function nibblyMediaStateNormalise(string $id, array $definition): ?array {
    $type = ($definition['type'] ?? 'bool') === 'choice' ? 'choice' : 'bool';
    $media = array_values(array_filter((array)($definition['media'] ?? ['image', 'video']), 'is_string'));
    $state = [
        'id' => $id,
        'label' => nibblyMediaStateText($definition['label'] ?? $id) ?: $id,
        'description' => nibblyMediaStateText($definition['description'] ?? ''),
        'badge' => nibblyMediaStateText($definition['badge'] ?? ''),
        'type' => $type,
        'media' => $media ?: ['image', 'video'],
    ];
    if ($type === 'choice') {
        $options = [];
        foreach ((array)($definition['options'] ?? []) as $value => $option) {
            if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value)) continue;
            if (!is_array($option)) $option = ['label' => $option];
            $options[$value] = [
                'label' => nibblyMediaStateText($option['label'] ?? $value) ?: $value,
                'description' => nibblyMediaStateText($option['description'] ?? ''),
                'badge' => nibblyMediaStateText($option['badge'] ?? ''),
            ];
        }
        if (!$options) return null;
        $state['options'] = $options;
    }
    return $state;
}

/** A label given as text or per language, resolved to the admin language. */
function nibblyMediaStateText($value): string {
    if (is_string($value)) return trim($value);
    if (!is_array($value) || !$value) return '';
    $lang = function_exists('_nbAdminLang') ? _nbAdminLang() : (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
    $lang = strtolower(substr((string)$lang, 0, 2));
    $text = $value[$lang] ?? $value['en'] ?? reset($value);
    return is_string($text) ? trim($text) : '';
}

/** Admin translation when the i18n helper is loaded (API, dashboard, footer), else the fallback. */
function nibblyMediaStateT(string $key, string $fallback): string {
    if (!function_exists('t')) return $fallback;
    $text = t($key);
    return $text === $key ? $fallback : $text;
}

/** The registered states for the media library (JSON): a list in registration order. */
function nibblyMediaStatesPublic(): array {
    return array_values(nibblyMediaStates());
}

/** Whether $value is a valid value of the state (true for bool states, an option for choice states). */
function nibblyMediaStateValueValid(array $state, $value): bool {
    return $state['type'] === 'choice' ? isset($state['options'][$value]) : $value === true;
}

/** Active states of a media file: id => value (true, or the chosen option). */
function nibblyMediaStatesFor(string $type, string $name): array {
    $entry = nibblyMediaMeta()[$type][$name] ?? [];
    if (!is_array($entry)) return [];
    $states = nibblyMediaStates();
    $result = [];
    $ai = $entry['ai'] ?? '';
    if (isset($states['ai']) && nibblyMediaStateValueValid($states['ai'], $ai)) $result['ai'] = $ai;
    foreach ((array)($entry['states'] ?? []) as $id => $value) {
        if ($id === 'ai' || !isset($states[$id])) continue;
        if (nibblyMediaStateValueValid($states[$id], $value)) $result[$id] = $value;
    }
    return $result;
}

/** One state of a media file: true / option value, or null when not set. */
function nibblyMediaStateFor(string $type, string $name, string $id) {
    return nibblyMediaStatesFor($type, $name)[$id] ?? null;
}

/** One state for a public image or video source (e.g. /assets/images/a/b.webp). */
function nibblyMediaStateForSrc(string $src, string $id) {
    $ref = nibblyMediaRefFromSrc($src);
    return $ref ? nibblyMediaStateFor($ref['type'], $ref['name'], $id) : null;
}

/**
 * Set states of a media file: id => value. true / an option value sets, false / '' / null
 * removes. Unknown ids and invalid values are rejected (false). Other states stay as they are.
 */
function nibblySetMediaStates(string $type, string $name, array $changes): bool {
    $states = nibblyMediaStates();
    $clean = [];
    foreach ($changes as $id => $value) {
        if (!is_string($id) || !isset($states[$id])) return false;
        if ($value === false || $value === '' || $value === null) {
            $clean[$id] = null;
            continue;
        }
        if ($states[$id]['type'] === 'bool') {
            if (!in_array($value, [true, 1, '1', 'true', 'on'], true)) return false;
            $value = true;
        } elseif (!is_string($value) || !isset($states[$id]['options'][$value])) {
            return false;
        }
        $clean[$id] = $value;
    }
    if (!$clean) return true;
    if (array_key_exists('ai', $clean)) {
        if (!nibblySetMediaAiLabel($type, $name, (string)($clean['ai'] ?? ''))) return false;
        unset($clean['ai']);
        if (!$clean) return true;
    }
    $ok = nibblyJsonUpdate(nibblyMediaMetaPath(), function (array &$data) use ($type, $name, $clean) {
        foreach ($clean as $id => $value) {
            if ($value === null) unset($data[$type][$name]['states'][$id]);
            else $data[$type][$name]['states'][$id] = $value;
        }
        if (isset($data[$type][$name]['states']) && empty($data[$type][$name]['states'])) unset($data[$type][$name]['states']);
        nibblyMediaMetaPrune($data, $type, $name);
        return true;
    });
    nibblyMediaMeta(true);
    return $ok;
}

/**
 * Media hooks: a site reacts to library events in includes/site-media-hooks.php (site-owned,
 * survives core updates) that returns event => callable. Events:
 *   'uploaded' (string $type, string $name)  a file landed in the library: upload or replace
 *                                            in the Media Library, agent import, AI generation.
 * Hook errors are logged and never break the request.
 */
function nibblyMediaHooks(): array {
    static $hooks = null;
    if ($hooks !== null) return $hooks;
    $hooks = [];
    $file = __DIR__ . '/site-media-hooks.php';
    if (is_file($file)) {
        $list = include $file;
        if (is_array($list)) {
            foreach ($list as $event => $fn) {
                if (is_string($event) && is_callable($fn)) $hooks[$event][] = $fn;
            }
        }
    }
    return $hooks;
}

function nibblyMediaEvent(string $event, ...$args): void {
    foreach (nibblyMediaHooks()[$event] ?? [] as $fn) {
        try {
            $fn(...$args);
        } catch (Throwable $e) {
            error_log('Nibbly media hook ' . $event . ': ' . $e->getMessage());
        }
    }
}
