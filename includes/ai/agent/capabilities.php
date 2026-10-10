<?php
/**
 * Capability map (agent phase P2): what the agent may change on a target, and how.
 *
 * Targets are addressed by key only, never by file path:
 *   page:{contentKey}   e.g. page:de_home, page:en_products__vitamin-d
 *   page:footer, page:sidebar   shared content (needs the editSharedContent right)
 *   news:{id}, event:{id}
 *
 * Merge order for pages:
 *   1. the render scan decides what is visible and editable,
 *   2. the page JSON supplies the real shape and current values (JSON wins on shape),
 *   3. block-types.php refines section field types,
 *   4. schemas.php holds the fixed schemas of news, events and new pages,
 *   5. content/ai-schema/_site.json and {target}.json may only restrict,
 *   6. hard filters keep sensitive, structural, visibility, seo and nav paths read-only.
 *
 * The scan result is cached in content/ai-agent/cache/ (see nibblyAgentCapabilities()).
 */

require_once __DIR__ . '/render-scan.php';
require_once __DIR__ . '/schemas.php';
require_once dirname(__DIR__, 2) . '/json-store.php';
require_once dirname(__DIR__, 2) . '/content-loader.php';
require_once dirname(__DIR__, 2) . '/version.php';
require_once dirname(__DIR__) . '/copilot-context.php';

const NIBBLY_AGENT_LIST_MAX_DEFAULT = 24;
const NIBBLY_AGENT_DESCRIPTOR_FIELDS = 150;

// ---------------------------------------------------------------------------
// Targets
// ---------------------------------------------------------------------------

/** @return array{kind:string,target:string,key:string,file:string,lang:string}|null */
function nibblyAgentParseTarget(string $target): ?array {
    $root = nibblyAgentSiteRoot();
    if (!preg_match('/^(page|news|event):([a-z0-9_-]{1,160})$/', $target, $match)) {
        return null;
    }
    [, $type, $key] = $match;
    if ($type === 'news' || $type === 'event') {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) return null;
        return ['kind' => $type, 'target' => $target, 'key' => $key, 'lang' => '',
            'file' => $type === 'news' ? $root . '/content/news/' . $key . '.json' : $root . '/content/events.json'];
    }
    if ($key === 'footer' || $key === 'sidebar') {
        return ['kind' => 'shared', 'target' => $target, 'key' => $key, 'lang' => '', 'file' => $root . '/content/pages/' . $key . '.json'];
    }
    $parsed = nibblyPageParseContentKey($key);
    if ($parsed === null) return null;
    return ['kind' => 'page', 'target' => $target, 'key' => $key, 'lang' => $parsed['lang'], 'file' => $root . '/content/pages/' . $key . '.json'];
}

/** First $max characters (UTF-8 safe). */
function nibblyAgentPrefix(string $text, int $max): string {
    return mb_substr($text, 0, $max, 'UTF-8');
}

function nibblyAgentReadJsonFile(string $file): ?array {
    clearstatcache(true, $file); // other requests and processes write these files
    if (!is_file($file)) return null;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/** Why a path is never writable by the agent, or null. */
function nibblyAgentReadOnlyReason(string $path): ?string {
    $lower = strtolower($path);
    if (nibblyCopilotIsSensitivePath($path)) return 'sensitive';
    if (preg_match('/^(visibility|seo|nav)(\.|$)/', $lower)) return 'protected';
    if (nibblyCopilotIsStructuralPath($path)) return 'structural';
    foreach (explode('.', $path) as $part) {
        if (in_array($part, ['__proto__', 'constructor', 'prototype'], true)) return 'sensitive';
    }
    return null;
}

function nibblyAgentSiteLanguages(): array {
    $languages = [];
    if (isset($GLOBALS['SITE_LANGUAGES']) && is_array($GLOBALS['SITE_LANGUAGES'])) {
        $languages = array_keys($GLOBALS['SITE_LANGUAGES']);
    }
    $default = defined('SITE_LANG_DEFAULT') ? (string)SITE_LANG_DEFAULT : 'en';
    array_unshift($languages, $default);
    return array_values(array_unique(array_filter($languages, static fn($l) => preg_match('/^[a-z]{2}$/', (string)$l))));
}

// ---------------------------------------------------------------------------
// Cache of the render scan
// ---------------------------------------------------------------------------

function nibblyAgentCacheDir(): string {
    return nibblyAgentSiteRoot() . '/content/ai-agent/cache';
}

/** Remove every cached scan ("Cache leeren"). Returns the number of files removed. */
function nibblyAgentClearCapabilityCache(): int {
    $removed = 0;
    foreach (glob(nibblyAgentCacheDir() . '/cap-*.json') ?: [] as $file) {
        if (@unlink($file)) $removed++;
    }
    return $removed;
}

function nibblyAgentCacheFile(string $target): string {
    return nibblyAgentCacheDir() . '/cap-' . sha1($target) . '.json';
}

/** @return array<string,array{0:int,1:int}> file => [mtime, size] */
function nibblyAgentStatFiles(array $files): array {
    $stat = [];
    foreach ($files as $file) {
        clearstatcache(true, $file);
        if (is_file($file)) {
            $stat[$file] = [(int)filemtime($file), (int)filesize($file)];
        }
    }
    ksort($stat);
    return $stat;
}

function nibblyAgentDepsValid($deps): bool {
    if (!is_array($deps) || !$deps) return false;
    foreach ($deps as $file => $signature) {
        clearstatcache(true, (string)$file);
        if (!is_file((string)$file) || [(int)filemtime($file), (int)filesize($file)] !== array_map('intval', (array)$signature)) {
            return false;
        }
    }
    return true;
}

/** Structure of a JSON document without its values (keys, list sizes, value types, emptiness). */
function nibblyAgentJsonShape($data, string $path = '', int $depth = 0): string {
    $parts = [];
    $walk = static function ($value, string $at, int $level) use (&$walk, &$parts): void {
        if (is_array($value) && $level < 8) {
            $parts[] = $at . '{' . count($value) . '}';
            foreach ($value as $key => $child) {
                $walk($child, $at . '.' . $key, $level + 1);
            }
            return;
        }
        $type = is_string($value) ? ($value === '' ? 's0' : 's') : (is_bool($value) ? ($value ? 'bt' : 'bf') : (is_array($value) ? 'a' : gettype($value)));
        $parts[] = $at . ':' . $type;
    };
    $walk($data, $path, $depth);
    sort($parts);
    return sha1(implode("\n", $parts));
}

function nibblyAgentFailureFile(string $template): string {
    return nibblyAgentCacheDir() . '/cap-fail-' . sha1($template) . '.json';
}

/** Remember that a template cannot be scanned, until it or the core changes. Also called from the shutdown handler. */
function nibblyAgentRecordScanFailure(string $template, string $reason): void {
    $deps = nibblyAgentStatFiles(array_merge([$template], glob(nibblyAgentSiteRoot() . '/includes/*.php') ?: []));
    nibblyJsonAtomicWrite(nibblyAgentFailureFile($template), ['reason' => $reason, 'version' => nibblyVersion(), 'deps' => $deps, 'at' => date('c')]);
}

function nibblyAgentScanFailure(string $template): ?string {
    $entry = nibblyAgentReadJsonFile(nibblyAgentFailureFile($template));
    if ($entry && ($entry['version'] ?? '') === nibblyVersion() && nibblyAgentDepsValid($entry['deps'] ?? null)) {
        return (string)($entry['reason'] ?? 'scan failed');
    }
    return null;
}

/**
 * Scan a page, or reuse the cached scan.
 *
 * @return array{scan:?array,cache:string,warnings:array}
 *         cache: hit (nothing changed), remix (values changed, structure did not), miss (first scan),
 *         rescan (structure or code changed), failed (known to be unscannable)
 * @throws NibblyAgentScanDeferred a second custom template in one process; retry in the next request
 */
function nibblyAgentScanTarget(array $info, array $json, array $options = []): array {
    $key = $info['key'];
    $template = nibblyAgentTemplateFor($key);
    $isCustom = $template !== '';
    $file = nibblyAgentCacheFile($info['target']);
    $revision = nibblyJsonRevision($info['file']);
    $shape = nibblyAgentJsonShape($json);
    $existing = nibblyAgentReadJsonFile($file);
    $cached = empty($options['forceScan']) ? $existing : null;

    if ($isCustom && ($reason = nibblyAgentScanFailure($template)) !== null) {
        return ['scan' => null, 'cache' => 'failed', 'warnings' => ['The page template cannot be scanned: ' . $reason]];
    }

    $status = $existing ? 'rescan' : 'miss';
    if ($cached) {
        if (($cached['version'] ?? '') === nibblyVersion() && nibblyAgentDepsValid($cached['deps'] ?? null) && ($cached['template'] ?? '') === $template) {
            $countsMatch = true;
            foreach ((array)($cached['scan']['lists'] ?? []) as $listKey => $list) {
                if (($list['page'] ?? $key) === $key && nibblyAgentCountItems(getNestedValue($json, (string)$listKey)) !== (int)($list['count'] ?? -1)) {
                    $countsMatch = false;
                }
            }
            // Same structure: only values changed (or nothing). New keys, other list sizes or
            // another type of value mean the page may render differently, so scan again.
            if ($countsMatch && ($cached['shape'] ?? '') === $shape) {
                return ['scan' => $cached['scan'], 'cache' => ($cached['jsonRevision'] ?? '') === $revision ? 'hit' : 'remix', 'warnings' => (array)($cached['warnings'] ?? [])];
            }
        }
    }

    $rendered = $isCustom
        ? nibblyAgentRenderTemplateOnce($template, $key, $options)
        : nibblyAgentRenderStandard($key);
    if ($rendered['exception'] !== null) {
        if ($isCustom) nibblyAgentRecordScanFailure($template, $rendered['exception']);
        return ['scan' => null, 'cache' => 'failed', 'warnings' => ['The page could not be rendered: ' . $rendered['exception']]];
    }
    $scan = nibblyAgentScanHtml($rendered['html'], $key);
    nibblyJsonAtomicWrite($file, [
        'version' => nibblyVersion(),
        'target' => $info['target'],
        'template' => $template,
        'deps' => nibblyAgentStatFiles($rendered['deps']),
        'shape' => $shape,
        'jsonRevision' => $revision,
        'scan' => $scan,
        'warnings' => $rendered['warnings'],
        'scannedAt' => date('c'),
    ]);
    return ['scan' => $scan, 'cache' => $status, 'warnings' => $rendered['warnings']];
}

function nibblyAgentCountItems($value): int {
    return is_array($value) ? count($value) : 0;
}

// ---------------------------------------------------------------------------
// Site overrides (restrict only)
// ---------------------------------------------------------------------------

function nibblyAgentOverrideFile(string $target): string {
    return nibblyAgentSiteRoot() . '/content/ai-schema/' . str_replace(':', '_', $target) . '.json';
}

/**
 * Read and sanitise content/ai-schema/_site.json and the target's own file.
 * Unknown keys are dropped; only restricting values survive.
 */
function nibblyAgentLoadOverrides(string $target): array {
    $result = ['fields' => [], 'lists' => [], 'readOnly' => [], 'hints' => [], 'news' => [], 'warnings' => []];
    $dir = nibblyAgentSiteRoot() . '/content/ai-schema';
    foreach (['_site' => $dir . '/_site.json', 'target' => nibblyAgentOverrideFile($target)] as $label => $file) {
        if (!is_file($file)) continue;
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            $result['warnings'][] = 'Ignored an invalid AI schema file (' . basename($file) . ').';
            continue;
        }
        foreach ((array)($data['fields'] ?? []) as $path => $rules) {
            if (!is_string($path) || !is_array($rules) || $label === '_site') continue;
            $clean = [];
            if (isset($rules['maxLength']) && is_int($rules['maxLength']) && $rules['maxLength'] >= 1) $clean['maxLength'] = $rules['maxLength'];
            if (($rules['required'] ?? null) === true) $clean['required'] = true;
            if (($rules['readOnly'] ?? null) === true) $clean['readOnly'] = true;
            if (isset($rules['imageAspect']) && is_string($rules['imageAspect']) && preg_match('/^\d{1,2}:\d{1,2}$/', $rules['imageAspect'])) $clean['imageAspect'] = $rules['imageAspect'];
            if (isset($rules['creditPath']) && is_string($rules['creditPath']) && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $rules['creditPath'])) $clean['creditPath'] = $rules['creditPath'];
            if ($clean) $result['fields'][$path] = array_replace($result['fields'][$path] ?? [], $clean);
        }
        foreach ((array)($data['lists'] ?? []) as $key => $rules) {
            if (!is_string($key) || !is_array($rules) || $label === '_site') continue;
            $clean = [];
            foreach (['minItems', 'maxItems'] as $limit) {
                if (isset($rules[$limit]) && is_int($rules[$limit]) && $rules[$limit] >= 0 && $rules[$limit] <= 1000) $clean[$limit] = $rules[$limit];
            }
            if ($clean) $result['lists'][$key] = array_replace($result['lists'][$key] ?? [], $clean);
        }
        foreach ((array)($data['readOnly'] ?? []) as $pattern) {
            if (is_string($pattern) && preg_match('/^[A-Za-z0-9_.*-]{1,160}$/', $pattern)) $result['readOnly'][] = $pattern;
        }
        foreach ((array)($data['hints'] ?? []) as $hint) {
            if (is_string($hint) && trim($hint) !== '' && count($result['hints']) < 10) $result['hints'][] = nibblyAgentPrefix(trim($hint), 200);
        }
        if (($data['news']['requireImage'] ?? null) === true) $result['news']['requireImage'] = true;
    }
    return $result;
}

/** Apply restricting overrides to a built map. Nothing here can add a field or relax a limit. */
function nibblyAgentApplyOverrides(array $map, array $overrides): array {
    foreach ($overrides['fields'] as $path => $rules) {
        if (!isset($map['fields'][$path])) continue;
        $field = &$map['fields'][$path];
        if (isset($rules['maxLength'])) $field['max'] = isset($field['max']) ? min((int)$field['max'], $rules['maxLength']) : $rules['maxLength'];
        if (!empty($rules['required'])) $field['req'] = true;
        if (!empty($rules['readOnly'])) $field['readOnly'] = true;
        if (isset($rules['imageAspect']) && $field['type'] === 'image') $field['imageAspect'] = $rules['imageAspect'];
        if (isset($rules['creditPath']) && $field['type'] === 'image') $field['creditPath'] = $rules['creditPath'];
        unset($field);
    }
    foreach ($overrides['lists'] as $key => $rules) {
        if (!isset($map['lists'][$key])) continue;
        $list = &$map['lists'][$key];
        if (isset($rules['maxItems'])) $list['max'] = min((int)$list['max'], $rules['maxItems']);
        if (isset($rules['minItems']) && max((int)$list['min'], $rules['minItems']) <= (int)$list['max']) {
            $list['min'] = max((int)$list['min'], $rules['minItems']); // a minimum above the maximum is contradictory and ignored
        }
        unset($list);
    }
    foreach ($overrides['readOnly'] as $pattern) {
        foreach ($map['fields'] as $path => $field) {
            if (fnmatch($pattern, $path)) $map['fields'][$path]['readOnly'] = true;
        }
        foreach ($map['lists'] as $key => $list) {
            if (fnmatch($pattern, $key) || fnmatch($pattern, $key . '.*')) $map['lists'][$key]['readOnly'] = true;
        }
    }
    foreach ($map['fields'] as $path => $field) {
        if (!empty($field['readOnly'])) {
            $map['readOnly'][] = $path;
        }
    }
    foreach ($map['lists'] as $key => $list) {
        if (!empty($list['readOnly'])) $map['readOnly'][] = $key;
    }
    $map['readOnly'] = array_values(array_unique($map['readOnly']));
    $map['hints'] = array_values(array_unique(array_merge($map['hints'], $overrides['hints'])));
    if (!empty($overrides['news']['requireImage']) && $map['kind'] === 'news') {
        $map['requireImage'] = true;
    }
    $map['warnings'] = array_merge($map['warnings'], $overrides['warnings']);
    return $map;
}

// ---------------------------------------------------------------------------
// Building the map
// ---------------------------------------------------------------------------

function nibblyAgentEmptyMap(array $info, string $kind): array {
    return ['target' => $info['target'], 'kind' => $kind, 'lang' => $info['lang'], 'contentKey' => $info['key'],
        'fields' => [], 'lists' => [], 'shared' => [], 'readOnly' => [], 'hints' => [], 'warnings' => [], 'sections' => [], 'addable' => []];
}

function nibblyAgentFieldEntry(string $path, string $type, $value, array $extra = []): array {
    $entry = ['path' => $path, 'type' => $type, 'value' => $value, 'readOnly' => false, 'hidden' => false, 'req' => false];
    $max = nibblyAgentDefaultMax($type, $path);
    if (!in_array($type, ['image', 'select'], true)) $entry['max'] = $max;
    return array_replace($entry, $extra);
}

/** Image storage format: split (own alt path), string, or {src, alt}. */
function nibblyAgentImageFormat($stored, ?string $scanFormat): string {
    if ($scanFormat === 'split') return 'split';
    return is_string($stored) ? 'string' : 'object';
}

function nibblyAgentItemTypeFromValue(string $key, $value): string {
    $lower = strtolower($key);
    if (is_array($value)) {
        if (array_key_exists('src', $value) || array_key_exists('alt', $value)) return 'image:object';
        if (array_key_exists('href', $value) || array_key_exists('text', $value)) return 'link';
        return 'text';
    }
    if (preg_match('/^(image|imagesrc|src|photo|portrait|logo|avatar|cover|thumbnail)$/', $lower)) return 'image:string';
    if (preg_match('/^(link|href|url|cta|button|buttonlink)$/', $lower)) return 'link';
    if (preg_match('/^(icon|iconid|symbol)$/', $lower)) return 'icon';
    if (preg_match('/^(text|description|desc|intro|body|content|copy|summary)$/', $lower)) return 'textarea';
    return 'text';
}

function nibblyAgentGroupTypeToItemType(string $type, string $key, $sample): string {
    return match (strtolower($type)) {
        'image' => is_array($sample) ? 'image:object' : 'image:string',
        'link' => 'link',
        'icon' => 'icon',
        'textarea' => 'textarea',
        'checkbox' => 'boolean',
        'number' => 'number',
        'select', 'date', 'datetime', 'text' => 'text',
        default => 'text'
    };
}

function nibblyAgentListItems($value): array {
    if (!is_array($value)) return [];
    ksort($value, SORT_NUMERIC);
    return $value;
}

/** Build the map of a page (standard or custom template) from its scan and JSON. */
function nibblyAgentBuildPageMap(array $info, array $json, ?array $scan, string $kind): array {
    $map = nibblyAgentEmptyMap($info, $kind);
    $map['title'] = (string)($json['title'] ?? '');
    $page = $info['key'];
    if ($scan === null) {
        $map['warnings'][] = 'The page structure could not be read; nothing on it can be edited by the agent.';
        return $map;
    }

    // Lists: keys, counts, item schema (group schema > scanned fields > defaults).
    $listKeys = array_keys(array_filter($scan['lists'], static fn($l) => ($l['page'] ?? $page) === $page));
    usort($listKeys, static fn($a, $b) => strlen($b) <=> strlen($a));
    $itemFields = array_fill_keys($listKeys, []);
    $itemEvidence = array_fill_keys($listKeys, false);
    $itemOf = static function (string $path) use ($listKeys): ?array {
        foreach ($listKeys as $key) {
            if (str_starts_with($path, $key . '.') && preg_match('/^(\d+)\.(.+)$/', substr($path, strlen($key) + 1), $m)) {
                return [$key, (int)$m[1], $m[2]];
            }
        }
        return null;
    };

    foreach ($scan['groups'] as $group => $schema) {
        $listKey = null;
        foreach ($listKeys as $candidate) {
            if (preg_match('/^' . preg_quote($candidate, '/') . '\.(\d+)$/', $group)) {
                $listKey = $candidate;
                break;
            }
        }
        foreach ((array)($schema['fields'] ?? []) as $field) {
            $key = (string)($field['key'] ?? '');
            if ($key === '' || $key === 'hidden' || nibblyAgentReadOnlyReason($key) !== null) continue;
            if ($listKey !== null) {
                // Item group of a list: the modal edits these fields of every item.
                $sample = getNestedValue($json, $group . '.' . $key);
                $itemFields[$listKey][$key] ??= nibblyAgentGroupTypeToItemType((string)($field['type'] ?? 'text'), $key, $sample);
                $itemEvidence[$listKey] = true;
                continue;
            }
            // A group outside any list: its fields are editable through one modal.
            $path = $group . '.' . $key;
            if (isset($scan['fields'][$path]) || nibblyAgentReadOnlyReason($path) !== null) continue;
            $stored = getNestedValue($json, $path);
            $type = explode(':', nibblyAgentGroupTypeToItemType((string)($field['type'] ?? 'text'), $key, $stored))[0];
            $map['fields'][$path] = nibblyAgentFieldEntry($path, $type, $stored ?? '', $type === 'image' ? ['format' => nibblyAgentImageFormat($stored, null)] : []);
        }
    }

    foreach ($scan['fields'] as $compound => $field) {
        $path = (string)$field['path'];
        if (!empty($field['shared'])) {
            $map['shared'][] = ['page' => (string)$field['page'], 'path' => $path];
            continue;
        }
        if (($item = $itemOf($path)) !== null) {
            [$listKey, , $rest] = $item;
            if (nibblyAgentReadOnlyReason($rest) !== null) continue;
            $stored = getNestedValue($json, $path);
            $itemType = (string)$field['type'];
            if ($itemType === 'image') {
                $itemType = ($field['format'] ?? '') === 'split' ? 'image:split' : (is_string($stored) ? 'image:string' : 'image:object');
            }
            $itemFields[$listKey][$rest] ??= $itemType;
            $itemEvidence[$listKey] = true;
            continue;
        }
        if (($reason = nibblyAgentReadOnlyReason($path)) !== null) {
            $map['readOnly'][] = $path;
            continue;
        }
        $stored = getNestedValue($json, $path);
        $type = (string)$field['type'];
        $extra = ['hidden' => !empty($field['hidden']) || getNestedValue($json, $path . '__hidden') === true];
        // Sections: refine the type with the block definition.
        if (preg_match('/^sections\.(\d+)\.([A-Za-z0-9_-]+)$/', $path, $m) && isset($json['sections'][(int)$m[1]]['type'])) {
            $definition = nibblyAgentBlockField((string)$json['sections'][(int)$m[1]]['type'], $m[2]);
            if ($definition !== null) {
                $mapped = nibblyAgentBlockFieldType((string)($definition['type'] ?? 'input'));
                if (in_array($mapped, ['html', 'textarea'], true) && in_array($type, ['text', 'html'], true)) $type = $mapped;
            }
        }
        if ($type === 'image') {
            $extra['format'] = nibblyAgentImageFormat($stored, $field['format'] ?? null);
            if ($extra['format'] === 'split') $extra['altPath'] = (string)$field['altPath'];
        }
        $value = $stored ?? $field['value'];
        $map['fields'][$path] = nibblyAgentFieldEntry($path, $type, $value, $extra);
    }

    foreach ($scan['binds'] as $path => $bindType) {
        if (!isset($map['fields'][$path]) && $itemOf($path) === null && !in_array($path, $map['readOnly'], true)) {
            $map['readOnly'][] = $path; // mirrored somewhere else, not editable here
        }
    }

    foreach ($listKeys as $key) {
        $list = $scan['lists'][$key];
        $stored = getNestedValue($json, $key);
        $schema = $itemFields[$key];
        if (!$itemEvidence[$key]) {
            foreach ((array)($list['defaults'] ?? []) as $itemKey => $default) {
                if (!is_string($itemKey) || $itemKey === 'hidden' || nibblyAgentReadOnlyReason($itemKey) !== null) continue;
                $schema[$itemKey] ??= nibblyAgentItemTypeFromValue($itemKey, $default);
            }
        }
        $hiddenIndexes = [];
        foreach (nibblyAgentListItems($stored) as $index => $item) {
            if (is_array($item) && !empty($item['hidden'])) $hiddenIndexes[] = (int)$index;
        }
        $map['lists'][$key] = [
            'key' => $key,
            'count' => nibblyAgentCountItems($stored),
            'min' => 0,
            'max' => NIBBLY_AGENT_LIST_MAX_DEFAULT,
            'hideSupported' => true,
            'readOnly' => false,
            'item' => $schema,
            'defaults' => (array)($list['defaults'] ?? []),
            'hiddenIndexes' => $hiddenIndexes,
        ];
    }
    ksort($map['lists']);

    // Pages with sections (also a template that only calls renderAllSections(), like the stock
    // home page) show their section overview; new sections are for standard pages only.
    foreach ((array)($json['sections'] ?? []) as $index => $section) {
        if (!is_array($section)) continue;
        $map['sections'][] = ['i' => (int)$index, 'id' => (string)($section['id'] ?? ''), 'type' => (string)($section['type'] ?? ''), 'hidden' => !empty($section['hidden'])];
    }
    if ($kind === 'standard-page') {
        $map['addable'] = array_keys(nibblyAgentBlockSchemas());
    }
    $map['shared'] = array_values(array_unique($map['shared'], SORT_REGULAR));
    $map['readOnly'] = array_values(array_unique($map['readOnly']));
    return $map;
}

/** News post or event: fixed schema, values from the stored JSON. */
function nibblyAgentBuildFixedMap(array $info, array $data, array $schema, string $kind): array {
    $map = nibblyAgentEmptyMap($info, $kind);
    $map['lang'] = (string)($data['lang'] ?? '');
    $map['title'] = is_array($data['title'] ?? null) ? (string)reset($data['title']) : (string)($data['title'] ?? '');
    foreach ($schema as $spec) {
        $path = $spec['path'];
        $stored = getNestedValue($data, $path);
        if (!empty($spec['ro']) || !empty($spec['create'])) { // identity of an existing item never changes
            $map['readOnly'][] = $path;
            continue;
        }
        $extra = ['req' => !empty($spec['req'])];
        if (isset($spec['max'])) $extra['max'] = $spec['max'];
        if ($spec['t'] === 'image' || ($spec['t'] === 'link' && ($spec['fmt'] ?? '') === 'string')) $extra['format'] = 'string';
        $map['fields'][$path] = nibblyAgentFieldEntry($path, $spec['t'], $stored ?? '', $extra);
    }
    foreach (['id', 'hidden', 'lastModified'] as $structural) {
        $map['readOnly'][] = $structural;
    }
    $map['hints'][] = $kind === 'news'
        ? 'The news body is plain rich text without images; the cover image is the "image" field.'
        : 'Texts are per language, for example title.de and title.en.';
    return $map;
}

/** Footer and sidebar: leaf strings of the shared JSON (no template scan). */
function nibblyAgentBuildSharedMap(array $info, array $data): array {
    $map = nibblyAgentEmptyMap($info, 'shared');
    $count = 0;
    $walk = static function (array $node, string $prefix, int $depth) use (&$walk, &$map, &$count): void {
        foreach ($node as $key => $value) {
            $key = (string)$key;
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            if ($depth > 4 || $count >= 80 || str_starts_with($key, '_')) continue;
            if (nibblyAgentReadOnlyReason($path) !== null) {
                $map['readOnly'][] = $path;
                continue;
            }
            if (is_array($value)) {
                $walk($value, $path, $depth + 1);
            } elseif (is_string($value)) {
                $type = $value !== strip_tags($value) ? 'html' : (strlen($value) > 300 ? 'textarea' : 'text');
                $map['fields'][$path] = nibblyAgentFieldEntry($path, $type, $value);
                $count++;
            }
        }
    };
    $walk($data, '', 0);
    $map['hints'][] = 'Shared content appears on every page of the site.';
    return $map;
}

// ---------------------------------------------------------------------------
// Entry points
// ---------------------------------------------------------------------------

/**
 * Capability map and the compact descriptor for one target.
 *
 * @param array $options forceScan (ignore the cache), onExit (see nibblyAgentRenderTemplateOnce)
 * @return array{target:string,kind:string,cache:string,map:array,descriptor:array,warnings:array}
 * @throws InvalidArgumentException invalid or unknown target
 * @throws NibblyAgentScanDeferred a custom template still has to be scanned, but this process already rendered one
 */
function nibblyAgentCapabilities(string $target, array $options = []): array {
    $info = nibblyAgentParseTarget($target);
    if ($info === null) {
        throw new InvalidArgumentException('Invalid target.');
    }
    $cache = 'none';
    $warnings = [];

    if ($info['kind'] === 'event') {
        $events = nibblyAgentReadJsonFile($info['file']);
        $event = null;
        foreach ((array)($events['events'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $info['key']) $event = $candidate;
        }
        if ($event === null) throw new InvalidArgumentException('Event not found.');
        $languages = array_values(array_unique(array_merge(nibblyAgentSiteLanguages(), array_keys((array)($event['title'] ?? [])))));
        $map = nibblyAgentBuildFixedMap($info, $event, nibblyAgentEventSchema($languages), 'event');
    } elseif ($info['kind'] === 'news') {
        $post = nibblyAgentReadJsonFile($info['file']);
        if ($post === null) throw new InvalidArgumentException('News post not found.');
        $info['lang'] = (string)($post['lang'] ?? '');
        $map = nibblyAgentBuildFixedMap($info, $post, nibblyAgentNewsSchema(), 'news');
    } elseif ($info['kind'] === 'shared') {
        $data = nibblyAgentReadJsonFile($info['file']);
        if ($data === null) throw new InvalidArgumentException('Shared content not found.');
        $map = nibblyAgentBuildSharedMap($info, $data);
    } else {
        $json = nibblyAgentReadJsonFile($info['file']);
        if ($json === null) throw new InvalidArgumentException('Page not found.');
        $kind = nibblyAgentTemplateFor($info['key']) !== '' ? 'custom-template' : (isset($json['sections']) && is_array($json['sections']) ? 'standard-page' : 'unknown');
        if ($kind === 'unknown') {
            $map = nibblyAgentBuildPageMap($info, $json, null, $kind);
            $map['warnings'][] = 'The page has neither a template nor sections.';
        } else {
            $scanned = nibblyAgentScanTarget($info, $json, $options);
            $cache = $scanned['cache'];
            $warnings = $scanned['warnings'];
            $map = nibblyAgentBuildPageMap($info, $json, $scanned['scan'], $kind);
        }
    }
    $map = nibblyAgentApplyOverrides($map, nibblyAgentLoadOverrides($target));
    $map['warnings'] = array_merge($map['warnings'], $warnings);
    return [
        'target' => $target,
        'kind' => $map['kind'],
        'cache' => $cache,
        'map' => $map,
        'descriptor' => nibblyAgentDescriptor($map),
        'warnings' => $map['warnings'],
    ];
}

/** Short value preview for the model. */
function nibblyAgentPreviewValue(array $field): string {
    $value = $field['value'] ?? '';
    if (is_array($value)) {
        if ($field['type'] === 'link') {
            $value = trim((string)($value['text'] ?? '') . ' → ' . (string)($value['href'] ?? ''));
        } elseif ($field['type'] === 'image') {
            $value = (string)($value['src'] ?? '');
        } else {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$value)) ?? '');
    return mb_strlen($text, 'UTF-8') > 120 ? nibblyAgentPrefix($text, 117) . '...' : $text;
}

/**
 * Compact descriptor the model sees (about 2-4k tokens per page):
 * fields (p path, t type, v value, fmt image format, max, req, hid), lists, readOnly, hints, shared.
 */
function nibblyAgentDescriptor(array $map): array {
    $fields = [];
    foreach ($map['fields'] as $field) {
        if (!empty($field['readOnly'])) continue;
        $entry = ['p' => $field['path'], 't' => $field['type']];
        if (isset($field['format'])) $entry['fmt'] = $field['format'];
        if (isset($field['altPath'])) $entry['alt'] = $field['altPath'];
        if (isset($field['max'])) $entry['max'] = $field['max'];
        if (!empty($field['req'])) $entry['req'] = true;
        if (!empty($field['hidden'])) $entry['hid'] = true;
        if (isset($field['imageAspect'])) $entry['aspect'] = $field['imageAspect'];
        if (isset($field['creditPath'])) $entry['credit'] = $field['creditPath'];
        $entry['v'] = nibblyAgentPreviewValue($field);
        $fields[] = $entry;
    }
    $truncated = count($fields) > NIBBLY_AGENT_DESCRIPTOR_FIELDS;
    $descriptor = ['target' => $map['target'], 'kind' => $map['kind'], 'lang' => $map['lang'], 'fields' => array_slice($fields, 0, NIBBLY_AGENT_DESCRIPTOR_FIELDS)];
    if ($truncated) $descriptor['truncated'] = true;
    $lists = [];
    foreach ($map['lists'] as $list) {
        if (!empty($list['readOnly'])) continue;
        $lists[] = ['key' => $list['key'], 'count' => $list['count'], 'min' => $list['min'], 'max' => $list['max'],
            'hideSupported' => $list['hideSupported'], 'item' => $list['item']];
    }
    if ($lists) $descriptor['lists'] = $lists;
    if ($map['sections']) $descriptor['sections'] = $map['sections'];
    if ($map['addable']) $descriptor['addable'] = $map['addable'];
    $descriptor['readOnly'] = $map['readOnly'];
    if ($map['shared']) $descriptor['shared'] = $map['shared'];
    if ($map['hints']) $descriptor['hints'] = $map['hints'];
    if (!empty($map['requireImage'])) $descriptor['requireImage'] = true;
    if ($map['warnings']) $descriptor['warnings'] = array_slice($map['warnings'], 0, 5);
    return $descriptor;
}
