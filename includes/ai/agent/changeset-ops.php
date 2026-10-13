<?php
/**
 * Change set operations (agent phase P3): value normalisation and the pure replay of
 * operations onto in-memory copies of the stored content.
 *
 * An operation is created by the server from a validated tool request (see
 * nibblyAgentChangesetStage()) and stores everything needed to replay it later without
 * scanning a page again: the field spec captured from the capability map, the normalised
 * value and the hash of the value it replaced.
 *
 * Operations: set_field, set_image, list_insert, list_hide, list_remove, list_move,
 * section_insert, create_news, create_event, create_page.
 *
 * Lists are normalised to JSON arrays when an operation touches them. The editor writes
 * numbered objects ({"0": …}) and PHP reads both forms; arrays are what json_encode()
 * produces for them anyway.
 */

require_once __DIR__ . '/capabilities.php';

if (!class_exists('NibblyAgentOpException', false)) {
    /** A request or operation the agent has to correct; the hint tells it how. */
    class NibblyAgentOpException extends RuntimeException {
        public string $hint;

        public function __construct(string $message, string $hint = '') {
            parent::__construct($message);
            $this->hint = $hint;
        }
    }
}

// ---------------------------------------------------------------------------
// Targets and storage
// ---------------------------------------------------------------------------

/** @return array{kind:string,key:string,file:string,lang:string}|null */
function nibblyAgentTargetStorage(string $target): ?array {
    $info = nibblyAgentParseTarget($target);
    return $info === null ? null : ['kind' => $info['kind'], 'key' => $info['key'], 'file' => $info['file'], 'lang' => $info['lang']];
}

/** What a content file holds: a page, a news post or the events file. */
function nibblyAgentTargetKindOfFile(string $file): string {
    $root = realpath(nibblyAgentSiteRoot()) ?: nibblyAgentSiteRoot();
    $dir = realpath(dirname($file)) ?: dirname($file);
    if ($dir === $root . '/content/news') return 'news';
    if ($dir === $root . '/content' && basename($file) === 'events.json') return 'events';
    return 'page';
}

/** Stable, order-independent JSON for hashes. */
function nibblyAgentCanonicalJson($value): string {
    $normalize = static function ($node) use (&$normalize) {
        if (!is_array($node)) return $node;
        if (!array_is_list($node)) ksort($node);
        return array_map($normalize, $node);
    };
    return (string)json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function nibblyAgentValueHash($value): string {
    return hash('sha256', nibblyAgentCanonicalJson($value));
}

function nibblyAgentPathValid(string $path): bool {
    if ($path === '' || strlen($path) > 160) return false;
    foreach (explode('.', $path) as $segment) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $segment) || in_array($segment, ['__proto__', 'constructor', 'prototype'], true)) return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Value normalisation
// ---------------------------------------------------------------------------

function nibblyAgentUrlKey(string $url): string {
    $url = trim($url);
    if (preg_match('/^(mailto|tel):/i', $url)) return strtolower($url);
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) return strtolower($url);
    $path = rtrim((string)($parts['path'] ?? ''), '/');
    return strtolower(($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')) . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

/** Whether an external URL was named by the user or fetched as a source of this run. */
function nibblyAgentUrlAllowed(string $url, array $cs): bool {
    $key = nibblyAgentUrlKey($url);
    foreach ((array)($cs['allowedUrls'] ?? []) as $allowed) {
        if (nibblyAgentUrlKey((string)$allowed) === $key) return true;
    }
    foreach ((array)($cs['sources'] ?? []) as $source) {
        if (!is_array($source)) continue;
        foreach (['url', 'canonical', 'resultUrl'] as $field) {
            if (!empty($source[$field]) && nibblyAgentUrlKey((string)$source[$field]) === $key) return true;
        }
    }
    return false;
}

function nibblyAgentNormalizeHref(string $href, array $cs): string {
    try {
        $href = nibblyCopilotNormalizeLinkValue($href);
    } catch (RuntimeException $e) {
        throw new NibblyAgentOpException('The link is not allowed.', 'Use a relative link such as /contact, an anchor, or an http(s)/mailto/tel address.');
    }
    if ($href === '' || str_starts_with($href, '#') || (str_starts_with($href, '/') && !str_starts_with($href, '//'))) return $href;
    $scheme = parse_url($href, PHP_URL_SCHEME);
    if (!$scheme) return $href; // relative path
    if (!nibblyAgentUrlAllowed($href, $cs)) {
        throw new NibblyAgentOpException('External link not allowed: ' . mb_substr($href, 0, 80, 'UTF-8'), 'External links are only allowed when the user named them or a source of this run contains them. Link an internal page instead.');
    }
    return $href;
}

function nibblyAgentNormalizeText(string $value, int $max, bool $multiline, array &$warnings, string $label): string {
    $value = strip_tags(str_replace(["\r\n", "\r"], "\n", $value));
    $value = preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]+/', $multiline ? '' : ' ', $value) ?? '';
    $value = trim($multiline ? $value : preg_replace('/\s{2,}/', ' ', $value) ?? '');
    if (mb_strlen($value, 'UTF-8') > $max) {
        $value = rtrim(mb_substr($value, 0, $max, 'UTF-8'));
        $warnings[] = "{$label} was shortened to {$max} characters.";
    }
    return $value;
}

/**
 * Normalise a value for a field spec {type, max, req, options, fmt}.
 * Images are resolved by nibblyAgentResolveImage(), not here.
 *
 * @return string|array
 * @throws NibblyAgentOpException
 */
function nibblyAgentNormalizeValue(array $spec, $value, array $cs, array &$warnings, string $label) {
    $type = (string)$spec['type'];
    $max = (int)($spec['max'] ?? nibblyAgentDefaultMax($type, $label));
    switch ($type) {
        case 'text':
        case 'textarea':
            if (!is_string($value) && !is_int($value) && !is_float($value)) throw new NibblyAgentOpException("{$label} needs a text value.");
            return nibblyAgentNormalizeText((string)$value, $max, $type === 'textarea', $warnings, $label);
        case 'html':
            if (!is_string($value)) throw new NibblyAgentOpException("{$label} needs an HTML string.");
            $clean = nibblySanitizeRichHtml(nibblyCopilotSanitizeHtml($value));
            if (nibblySanitizeRichHtml($clean) !== $clean) {
                throw new NibblyAgentOpException("{$label} contains markup that cannot be stored safely.", 'Use only paragraphs, headings (h2, h3), lists, bold, italic and links.');
            }
            if (mb_strlen($clean, 'UTF-8') > $max) {
                throw new NibblyAgentOpException("{$label} is longer than {$max} characters.", 'Shorten the text.');
            }
            // The link rule holds inside rich text too: an external address needs the user's word or a source of this run.
            if (preg_match_all('/<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $clean, $links, PREG_SET_ORDER)) {
                foreach ($links as $link) {
                    $href = trim(html_entity_decode(($link[1] ?? '') !== '' ? $link[1] : ($link[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($href !== '') nibblyAgentNormalizeHref($href, $cs);
                }
            }
            return $clean;
        case 'icon':
            if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', trim($value))) throw new NibblyAgentOpException("{$label} needs an icon name (letters, digits, - and _).");
            return trim($value);
        case 'select':
            $options = array_map('strval', (array)($spec['options'] ?? []));
            if (!is_string($value) || ($options && !in_array($value, $options, true))) {
                throw new NibblyAgentOpException("{$label} must be one of: " . implode(', ', $options) . '.');
            }
            return $value;
        case 'date':
            if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                throw new NibblyAgentOpException("{$label} needs a date as YYYY-MM-DD.");
            }
            return $value;
        case 'time':
            if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) throw new NibblyAgentOpException("{$label} needs a time as HH:MM.");
            return $value;
        case 'boolean':
            if (!is_bool($value)) throw new NibblyAgentOpException("{$label} needs true or false.");
            return $value;
        case 'number':
            if (!is_int($value) && !is_float($value)) throw new NibblyAgentOpException("{$label} needs a number.");
            return $value;
        case 'link':
            if (($spec['fmt'] ?? '') === 'string') {
                if (!is_string($value)) throw new NibblyAgentOpException("{$label} needs a link address as text.");
                return nibblyAgentNormalizeHref($value, $cs);
            }
            if (!is_array($value) || !isset($value['href']) || !is_string($value['href'])) {
                throw new NibblyAgentOpException("{$label} needs {text, href}.");
            }
            $text = nibblyAgentNormalizeText((string)($value['text'] ?? ''), 120, false, $warnings, $label . '.text');
            return ['text' => $text, 'href' => nibblyAgentNormalizeHref($value['href'], $cs)];
    }
    throw new NibblyAgentOpException("{$label} has an unsupported type ({$type}).");
}

/**
 * Resolve an image source: a staged media reference (m_…) or a path in the media library.
 *
 * @return string public path
 */
function nibblyAgentResolveImage($source, array $cs, string $label): string {
    if (!is_string($source) || trim($source) === '') throw new NibblyAgentOpException("{$label} needs an image (mediaRef or libraryPath).");
    $source = trim($source);
    if (preg_match('/^m_[A-Za-z0-9]{4,40}$/', $source)) {
        $media = $cs['media'][$source] ?? null;
        if (!is_array($media) || empty($media['finalPath'])) {
            throw new NibblyAgentOpException("{$label}: unknown image reference {$source}.", 'Use list_run_media to see the images of this run.');
        }
        return (string)$media['finalPath'];
    }
    try {
        return nibblyCopilotNormalizeImagePath($source);
    } catch (RuntimeException $e) {
        throw new NibblyAgentOpException("{$label}: the image {$source} is not in the media library.", 'Use list_media to find a library image, or an image reference of this run.');
    }
}

function nibblyAgentNormalizeAlt($alt, string $label): string {
    $alt = nibblyCopilotNormalizeAltText((string)$alt);
    if ($alt === '') throw new NibblyAgentOpException("{$label} needs an alt text.", 'Describe the image in a short sentence.');
    return $alt;
}

/** A short readable piece of (possibly HTML) text. */
function nibblyAgentSnippet(string $text, int $max = 40): string {
    $text = preg_replace('~</?(p|h[1-6]|li|ul|ol|div|br|blockquote|tr|td)\b[^>]*>~i', ' ', $text);
    $text = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return mb_strlen($text, 'UTF-8') > $max ? rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…' : $text;
}

/** What a person would call a section: its title, its first heading or the start of its text. */
function nibblyAgentSectionTitle(array $section): string {
    foreach (['title', 'heading', 'headline', 'name'] as $key) {
        if (isset($section[$key]) && is_string($section[$key]) && nibblyAgentSnippet($section[$key]) !== '') return nibblyAgentSnippet($section[$key]);
    }
    foreach ($section as $value) {
        if (is_string($value) && preg_match('~<h[1-6][^>]*>(.*?)</h[1-6]>~is', $value, $m) && nibblyAgentSnippet($m[1]) !== '') return nibblyAgentSnippet($m[1]);
    }
    foreach (['content', 'text', 'description', 'quote'] as $key) {
        if (isset($section[$key]) && is_string($section[$key]) && nibblyAgentSnippet($section[$key]) !== '') return nibblyAgentSnippet($section[$key]);
    }
    return '';
}

/**
 * Where a path points, in terms a person understands: the field, and the section (position, type,
 * title) and list item it sits in. Used for the review texts only.
 *
 * @return array{field:string,section:?array{n:int,type:string,title:string},item:?int}
 */
function nibblyAgentFieldContext(array $doc, string $path): array {
    $parts = explode('.', $path);
    $context = ['field' => $path, 'section' => null, 'item' => null];
    if (count($parts) >= 3 && $parts[0] === 'sections' && ctype_digit($parts[1])) {
        $section = getNestedValue($doc, 'sections.' . $parts[1]);
        if (is_array($section)) {
            $context['section'] = ['n' => (int)$parts[1] + 1, 'type' => (string)($section['type'] ?? ''), 'title' => nibblyAgentSectionTitle($section)];
            $rest = array_slice($parts, 2);
            if (count($rest) >= 3 && ctype_digit($rest[1])) {
                $context['item'] = (int)$rest[1] + 1;
                $context['field'] = implode('.', array_slice($rest, 2));
            } else {
                $context['field'] = implode('.', $rest);
            }
        }
    }
    return $context;
}

/** Text that identifies a list item to a human (used to confirm removals). */
function nibblyAgentItemLabel($item): string {
    if (!is_array($item)) return trim(strip_tags((string)$item));
    foreach (['title', 'name', 'heading', 'question', 'label', 'text', 'content'] as $key) {
        if (isset($item[$key]) && is_string($item[$key]) && trim(strip_tags($item[$key])) !== '') return trim(strip_tags($item[$key]));
    }
    foreach ($item as $value) {
        if (is_string($value) && trim(strip_tags($value)) !== '') return trim(strip_tags($value));
    }
    return '';
}

/** Normalise the item of a list insert against the item schema ({key: type}). */
function nibblyAgentNormalizeItem(array $schema, array $item, array $defaults, array $cs, array &$warnings, string $label): array {
    $result = $defaults;
    foreach ($item as $key => $value) {
        $key = (string)$key;
        if (!isset($schema[$key])) {
            throw new NibblyAgentOpException("{$label}: '{$key}' is not a field of this list.", 'Fields: ' . implode(', ', array_keys($schema)) . '.');
        }
        $type = $schema[$key];
        $at = "{$label}.{$key}";
        if (str_starts_with($type, 'image:')) {
            $format = substr($type, 6);
            $source = is_array($value) ? ($value['mediaRef'] ?? $value['libraryPath'] ?? null) : $value;
            $src = nibblyAgentResolveImage($source, $cs, $at);
            if ($format === 'object') {
                $existing = is_array($defaults[$key] ?? null) ? $defaults[$key] : [];
                $result[$key] = array_replace($existing, ['src' => $src, 'alt' => nibblyAgentNormalizeAlt(is_array($value) ? ($value['alt'] ?? '') : '', $at)]);
            } elseif ($format === 'string') {
                $result[$key] = $src;
            } else {
                throw new NibblyAgentOpException("{$at}: this image field cannot be set inside a list.");
            }
            continue;
        }
        $result[$key] = nibblyAgentNormalizeValue(['type' => $type, 'max' => nibblyAgentDefaultMax($type, $key)], $value, $cs, $warnings, $at);
    }
    return $result;
}

// ---------------------------------------------------------------------------
// Replay
// ---------------------------------------------------------------------------

/**
 * Fresh in-memory state: file data is loaded lazily, so a replay always starts from
 * what is stored right now.
 */
function nibblyAgentReplayState(): array {
    return ['files' => [], 'tokens' => [], 'results' => [], 'created' => [], 'touched' => []];
}

function nibblyAgentStateFile(array &$state, string $file): void {
    if (!isset($state['files'][$file])) {
        $data = nibblyAgentReadJsonFile($file);
        $state['files'][$file] = ['exists' => $data !== null, 'before' => $data, 'data' => $data];
    }
}

/** Reference to the document of a target inside the state. */
function &nibblyAgentDocRef(array &$state, string $target): array {
    $storage = nibblyAgentTargetStorage($target);
    if ($storage === null) throw new NibblyAgentOpException('Invalid target.');
    nibblyAgentStateFile($state, $storage['file']);
    $file = &$state['files'][$storage['file']];
    if ($storage['kind'] === 'event') {
        if (!is_array($file['data']['events'] ?? null)) throw new NibblyAgentOpException("Event {$storage['key']} not found.");
        foreach ($file['data']['events'] as $index => &$event) {
            if (is_array($event) && ($event['id'] ?? null) === $storage['key']) {
                $state['touched'][$target] = true;
                return $event;
            }
        }
        throw new NibblyAgentOpException("Event {$storage['key']} not found.");
    }
    if ($file['data'] === null) throw new NibblyAgentOpException("{$target} does not exist.");
    $state['touched'][$target] = true;
    return $file['data'];
}

function nibblyAgentListItemsOf(array $doc, string $listKey): array {
    $value = getNestedValue($doc, $listKey);
    if (!is_array($value)) return [];
    ksort($value, SORT_NUMERIC);
    return array_values($value);
}

function nibblyAgentTokensFor(array &$state, string $target, string $listKey, array $items): array {
    $key = $target . '|' . $listKey;
    if (!isset($state['tokens'][$key]) || count($state['tokens'][$key]) !== count($items)) {
        $state['tokens'][$key] = array_fill(0, count($items), null);
    }
    return $state['tokens'][$key];
}

function nibblyAgentCheckIndex($index, int $count, string $what): int {
    if (!is_int($index) || $index < 0 || $index >= $count) {
        throw new NibblyAgentOpException("{$what}: index " . json_encode($index) . " is out of range (the list has {$count} items).", 'Indexes start at 0 and refer to the list after the previous changes.');
    }
    return $index;
}

/** Write a normalised image value according to the field format. */
function nibblyAgentWriteImage(array &$doc, array $spec, string $path, string $src, string $alt): void {
    $format = (string)($spec['fmt'] ?? 'object');
    if ($format === 'string') {
        setNestedValue($doc, $path, $src);
    } elseif ($format === 'split') {
        if (empty($spec['altPath'])) throw new NibblyAgentOpException("{$path}: the alt field of this image is unknown.");
        setNestedValue($doc, $path, $src);
        setNestedValue($doc, (string)$spec['altPath'], $alt);
    } else {
        $existing = getNestedValue($doc, $path);
        setNestedValue($doc, $path, array_replace(is_array($existing) ? $existing : [], ['src' => $src, 'alt' => $alt]));
    }
}

/**
 * Apply one operation to the state.
 *
 * @throws NibblyAgentOpException
 */
function nibblyAgentApplyOp(array &$state, array $op): void {
    $type = (string)$op['type'];
    $target = (string)($op['target'] ?? '');
    $before = null;
    $after = null;
    $effectivePosition = null;
    $context = null;

    switch ($type) {
        case 'set_field':
        case 'set_image':
            $doc = &nibblyAgentDocRef($state, $target);
            $path = (string)$op['path'];
            if (!nibblyAgentPathValid($path)) throw new NibblyAgentOpException('Invalid path.');
            if (!empty($op['spec']['mustExist']) && !is_array(getNestedValue($doc, (string)$op['spec']['mustExist']))) {
                throw new NibblyAgentOpException("'{$path}': {$op['spec']['mustExist']} no longer exists at this point of the change set.", 'Indexes refer to the page after the previous changes.');
            }
            $before = getNestedValue($doc, $path);
            $context = nibblyAgentFieldContext($doc, $path);
            if ($type === 'set_field') {
                $value = $op['value'];
                if (is_array($value) && is_array($before) && !array_is_list($before)) {
                    $value = array_replace($before, $value); // a link keeps its other settings (target, download)
                }
                setNestedValue($doc, $path, $value);
                $after = $value;
            } else {
                nibblyAgentWriteImage($doc, (array)$op['spec'], $path, (string)$op['src'], (string)($op['alt'] ?? ''));
                $after = getNestedValue($doc, $path);
                if (!empty($op['creditPath']) && !empty($op['creditText'])) {
                    // The credit goes into the caption; an existing caption keeps its text and gets the credit appended.
                    $caption = trim((string)getNestedValue($doc, (string)$op['creditPath']));
                    if (!str_contains($caption, (string)$op['creditText'])) {
                        setNestedValue($doc, (string)$op['creditPath'], $caption === '' ? $op['creditText'] : $caption . ' – ' . $op['creditText']);
                    }
                }
            }
            unset($doc);
            break;

        case 'list_insert':
        case 'list_hide':
        case 'list_remove':
        case 'list_move':
            $doc = &nibblyAgentDocRef($state, $target);
            $listKey = (string)$op['listKey'];
            if (!nibblyAgentPathValid($listKey)) throw new NibblyAgentOpException('Invalid list key.');
            $items = nibblyAgentListItemsOf($doc, $listKey);
            $context = nibblyAgentFieldContext($doc, $listKey);
            $tokens = nibblyAgentTokensFor($state, $target, $listKey, $items);
            $count = count($items);
            if ($type === 'list_insert') {
                $position = min((int)$op['position'], $count);
                if ($position < 0) throw new NibblyAgentOpException('Invalid position.');
                $effectivePosition = $position;
                array_splice($items, $position, 0, [$op['item']]);
                array_splice($tokens, $position, 0, [$op['id']]);
                $after = $op['item'];
            } elseif ($type === 'list_hide') {
                $index = nibblyAgentCheckIndex($op['index'], $count, 'list_hide');
                $before = !empty($items[$index]['hidden']);
                if (!is_array($items[$index])) throw new NibblyAgentOpException('This list item cannot be hidden.');
                if ($op['hidden']) $items[$index]['hidden'] = true; else unset($items[$index]['hidden']);
                $after = (bool)$op['hidden'];
            } elseif ($type === 'list_remove') {
                $index = nibblyAgentCheckIndex($op['index'], $count, 'list_remove');
                $label = nibblyAgentItemLabel($items[$index]);
                $prefix = (string)$op['titlePrefix'];
                if ($prefix === '' || mb_stripos($label, $prefix, 0, 'UTF-8') !== 0) {
                    throw new NibblyAgentOpException("list_remove: item {$index} starts with \"" . mb_substr($label, 0, 40, 'UTF-8') . "\", not \"" . mb_substr($prefix, 0, 40, 'UTF-8') . '".', 'Pass the first characters of the title of the item you want to remove, to confirm the index.');
                }
                $before = $items[$index];
                array_splice($items, $index, 1);
                array_splice($tokens, $index, 1);
            } else {
                $from = nibblyAgentCheckIndex($op['from'], $count, 'list_move from');
                $to = nibblyAgentCheckIndex($op['to'], $count, 'list_move to');
                $moved = array_splice($items, $from, 1);
                array_splice($items, $to, 0, $moved);
                $movedToken = array_splice($tokens, $from, 1);
                array_splice($tokens, $to, 0, $movedToken);
                $before = $from;
                $after = $to;
            }
            setNestedValue($doc, $listKey, array_values($items));
            $state['tokens'][$target . '|' . $listKey] = array_values($tokens);
            unset($doc);
            break;

        case 'section_insert':
            $doc = &nibblyAgentDocRef($state, $target);
            if (!is_array($doc['sections'] ?? null)) throw new NibblyAgentOpException('This page has no sections.');
            $sections = array_values($doc['sections']);
            $position = min((int)$op['position'], count($sections));
            if ($position < 0) throw new NibblyAgentOpException('Invalid position.');
            $effectivePosition = $position;
            foreach ($sections as $section) {
                if (is_array($section) && ($section['id'] ?? null) === $op['section']['id']) throw new NibblyAgentOpException('Section id already exists.');
            }
            array_splice($sections, $position, 0, [$op['section']]);
            $doc['sections'] = $sections;
            $after = $op['section'];
            unset($doc);
            break;

        case 'create_news':
            $storage = nibblyAgentTargetStorage($target);
            nibblyAgentStateFile($state, $storage['file']);
            if ($state['files'][$storage['file']]['data'] !== null) throw new NibblyAgentOpException("News post {$storage['key']} already exists.", 'Choose another slug or date.');
            $state['files'][$storage['file']]['data'] = $op['post'];
            $state['touched'][$target] = true;
            $after = $op['post'];
            break;

        case 'create_page':
            $storage = nibblyAgentTargetStorage($target);
            nibblyAgentStateFile($state, $storage['file']);
            if ($state['files'][$storage['file']]['data'] !== null) throw new NibblyAgentOpException("Page {$storage['key']} already exists.", 'Choose another slug.');
            $state['files'][$storage['file']]['data'] = $op['page'];
            $state['touched'][$target] = true;
            $after = $op['page'];
            break;

        case 'create_event':
            $storage = nibblyAgentTargetStorage($target);
            nibblyAgentStateFile($state, $storage['file']);
            $file = &$state['files'][$storage['file']];
            $file['data'] ??= ['events' => []];
            foreach ((array)($file['data']['events'] ?? []) as $existing) {
                if (is_array($existing) && ($existing['id'] ?? null) === $storage['key']) throw new NibblyAgentOpException("Event {$storage['key']} already exists.", 'Change the title or date.');
            }
            $file['data']['events'][] = $op['event'];
            $state['touched'][$target] = true;
            $after = $op['event'];
            unset($file);
            break;

        default:
            throw new NibblyAgentOpException("Unknown operation {$type}.");
    }
    if (in_array($type, ['create_news', 'create_page', 'create_event'], true)) {
        $state['created'][$target] = $op['id'];
    }
    $state['results'][$op['id']] = ['before' => $before, 'after' => $after, 'position' => $effectivePosition, 'context' => $context];
}

/**
 * Replay operations onto fresh copies of the stored content.
 *
 * @return array{ok:bool,errors:array<string,array{message:string,hint:string}>,state:array}
 */
function nibblyAgentReplayOps(array $ops): array {
    $state = nibblyAgentReplayState();
    $errors = [];
    foreach ($ops as $op) {
        try {
            nibblyAgentApplyOp($state, $op);
        } catch (NibblyAgentOpException $e) {
            $errors[(string)$op['id']] = ['message' => $e->getMessage(), 'hint' => $e->hint];
            break; // later operations depend on this one
        }
    }
    return ['ok' => !$errors, 'errors' => $errors, 'state' => $state];
}

/** Files that differ from their stored state: file => {exists, before, after}. */
function nibblyAgentChangedFiles(array $state): array {
    $changed = [];
    foreach ($state['files'] as $file => $entry) {
        if ($entry['data'] !== $entry['before']) {
            $changed[$file] = ['exists' => $entry['exists'], 'before' => $entry['before'], 'after' => $entry['data']];
        }
    }
    return $changed;
}
