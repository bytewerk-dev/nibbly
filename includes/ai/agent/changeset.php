<?php
/**
 * Change sets (agent phase P3): everything the agent wants to change is collected in one
 * package, validated, checked by rendering, previewed and only then applied by a person.
 *
 *   status: staging → ready → applied → undone
 *                  ↘ conflict (stored content changed) / failed / discarded
 *
 * A change set lives in content/ai-agent/changesets/{id}.json:
 *   id, runId, owner (user key), status, base {target: revision}, ops, media, sources,
 *   allowedUrls (URLs the user named), validation, renderCheck, previewHash, applied.
 *
 * Nothing in here writes outside content/ai-agent/ until nibblyAgentChangesetApply()
 * (changeset-apply.php). Operations are replayed from the stored content every time,
 * so a package is always judged against what is on disk now.
 */

require_once __DIR__ . '/changeset-ops.php';
require_once __DIR__ . '/safety.php';

const NIBBLY_AGENT_MAX_OPS = 200;
const NIBBLY_AGENT_MAX_DOC_BYTES = 524288;

function nibblyAgentDir(): string {
    return nibblyAgentSiteRoot() . '/content/ai-agent';
}

function nibblyAgentValidChangesetId($id): bool {
    return is_string($id) && preg_match('/^cs_[a-f0-9]{16}$/', $id) === 1;
}

function nibblyAgentChangesetPath(string $id): string {
    return nibblyAgentDir() . '/changesets/' . $id . '.json';
}

/** Delete a folder inside content/ai-agent (plain PHP: exec() is often disabled). */
function nibblyAgentRemoveTree(string $dir): void {
    $base = realpath(nibblyAgentDir());
    $real = realpath($dir);
    if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_dir($real)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($real);
}

function nibblyAgentUserKey(): string {
    return (string)($_SESSION['admin_user_id'] ?? $_SESSION['admin_username'] ?? '');
}

function nibblyAgentAudit(string $action, bool $success, array $meta = []): void {
    require_once dirname(__DIR__) . '/ai-helper.php';
    nibblyAiAudit($action, $success, $meta);
}

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

/** @param array $meta runId, owner (user key), allowedUrls (URLs from the user's message), sources */
function nibblyAgentChangesetCreate(array $meta): array {
    $cs = [
        'id' => 'cs_' . bin2hex(random_bytes(8)),
        'runId' => (string)($meta['runId'] ?? ''),
        'owner' => (string)($meta['owner'] ?? ''),
        'status' => 'staging',
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'base' => [],
        'ops' => [],
        'media' => [],
        'sources' => array_values((array)($meta['sources'] ?? [])),
        'allowedUrls' => array_values(array_filter(array_map('strval', (array)($meta['allowedUrls'] ?? [])))),
        'validation' => null,
        'renderCheck' => null,
        'previewHash' => '',
        'applied' => null,
    ];
    if ($cs['owner'] === '') throw new InvalidArgumentException('A change set needs an owner.');
    if (!nibblyJsonAtomicWrite(nibblyAgentChangesetPath($cs['id']), $cs)) throw new RuntimeException('Could not store the change set.');
    return $cs;
}

function nibblyAgentChangesetLoad(string $id): ?array {
    return nibblyAgentValidChangesetId($id) ? nibblyAgentReadJsonFile(nibblyAgentChangesetPath($id)) : null;
}

/**
 * Locked read-modify-write of a change set. The callback edits it by reference and
 * returns false to abort without writing.
 */
function nibblyAgentChangesetMutate(string $id, callable $change): ?array {
    if (!nibblyAgentValidChangesetId($id) || !is_file(nibblyAgentChangesetPath($id))) return null;
    $result = null;
    nibblyJsonUpdate(nibblyAgentChangesetPath($id), static function (array &$cs) use ($change, &$result) {
        $outcome = $change($cs);
        $result = $cs;
        if ($outcome === false) return false;
        $cs['updatedAt'] = date('c');
        $result = $cs;
        return true;
    });
    return $result;
}

function nibblyAgentCanSeeChangeset(array $cs, array $actor): bool {
    return !empty($actor['isAdmin']) || (!empty($actor['userKey']) && hash_equals((string)$cs['owner'], (string)$actor['userKey']));
}

// ---------------------------------------------------------------------------
// Staging (what the stage_* tools call)
// ---------------------------------------------------------------------------

const NIBBLY_AGENT_REQUEST_KEYS = [
    'set_field' => ['type', 'target', 'path', 'value'],
    'set_image' => ['type', 'target', 'path', 'mediaRef', 'libraryPath', 'alt'],
    'list_insert' => ['type', 'target', 'listKey', 'position', 'item'],
    'list_hide' => ['type', 'target', 'listKey', 'index', 'hidden'],
    'list_remove' => ['type', 'target', 'listKey', 'index', 'titlePrefix'],
    'list_move' => ['type', 'target', 'listKey', 'from', 'to'],
    'section_insert' => ['type', 'target', 'position', 'sectionType', 'fields'],
    'create_news' => ['type', 'lang', 'title', 'slug', 'date', 'author', 'excerpt', 'contentHtml', 'imageRef', 'imagePath'],
    'create_event' => ['type', 'lang', 'title', 'date', 'time', 'location', 'description', 'admission', 'url', 'endDate', 'endTime'],
    'create_page' => ['type', 'lang', 'slug', 'title', 'description', 'sections'],
    'set_page_menus' => ['type', 'target', 'add', 'remove'],
    'revert_op' => ['type', 'opId'],
];

function nibblyAgentRequireKeys(array $request, array $required): void {
    foreach ($required as $key) {
        if (!array_key_exists($key, $request) || $request[$key] === null) {
            throw new NibblyAgentOpException("'{$key}' is required for {$request['type']}.");
        }
    }
}

function nibblyAgentCapabilityMapFor(string $target): array {
    try {
        return nibblyAgentCapabilities($target)['map'];
    } catch (InvalidArgumentException $e) {
        throw new NibblyAgentOpException('Unknown target ' . mb_substr($target, 0, 80, 'UTF-8') . ': ' . $e->getMessage(), 'Use get_site_overview to see the available pages.');
    }
}

function nibblyAgentSimilarPaths(array $paths, string $wanted): string {
    $scored = [];
    foreach ($paths as $path) {
        similar_text(strtolower($wanted), strtolower((string)$path), $percent);
        $scored[(string)$path] = $percent;
    }
    arsort($scored);
    return implode(', ', array_slice(array_keys($scored), 0, 5));
}

/** Site override rules that apply to a path outside the capability map (list items, sections). */
function nibblyAgentOverrideForPath(string $target, string $path): array {
    $overrides = nibblyAgentLoadOverrides($target);
    $rules = ['readOnly' => false, 'max' => null, 'req' => false];
    foreach ($overrides['readOnly'] as $pattern) {
        if (fnmatch($pattern, $path)) $rules['readOnly'] = true;
    }
    $own = $overrides['fields'][$path] ?? [];
    if (!empty($own['readOnly'])) $rules['readOnly'] = true;
    if (isset($own['maxLength'])) $rules['max'] = $own['maxLength'];
    if (!empty($own['required'])) $rules['req'] = true;
    return $rules;
}

/**
 * Field spec for a path, or an error that tells the agent what to do.
 *
 * Top-level fields come from the capability map. Paths below a list ("features.items.2.title")
 * or a section ("sections.3.content") are checked against the page as it is after the previous
 * operations of this change set: the index has to exist there, and the allowed fields come from
 * the list's item schema or the block type of that section.
 *
 * @return array spec {type, max, req, options, fmt, altPath, mustExist?}
 */
function nibblyAgentFieldSpec(array $map, string $path, string $usedFor, array $state, string $target): array {
    // Paths below sections and lists follow the state of this change set, not the stored page.
    $dynamic = nibblyAgentDynamicFieldSpec($map, $path, $usedFor, $state, $target);
    if ($dynamic !== null) return $dynamic;
    $field = $map['fields'][$path] ?? null;
    if ($field !== null && empty($field['readOnly'])) {
        if ($usedFor === 'set_field' && $field['type'] === 'image') throw new NibblyAgentOpException("'{$path}' is an image.", 'Use stage_set_image.');
        if ($usedFor === 'set_image' && $field['type'] !== 'image') throw new NibblyAgentOpException("'{$path}' is not an image.", 'Use stage_set_field.');
        return ['type' => $field['type'], 'max' => $field['max'] ?? null, 'req' => !empty($field['req']), 'options' => $field['options'] ?? [],
            'fmt' => $field['format'] ?? null, 'altPath' => $field['altPath'] ?? null];
    }
    $editable = array_keys(array_filter($map['fields'], static fn($f) => empty($f['readOnly'])));
    $reason = ($field !== null || in_array($path, $map['readOnly'], true)) ? "'{$path}' is read-only." : "'{$path}' is not an editable field of {$map['target']}.";
    throw new NibblyAgentOpException($reason, 'Editable fields: ' . nibblyAgentSimilarPaths($editable, $path) . '. Call get_page_capabilities for the full list.');
}

function nibblyAgentDynamicFieldSpec(array $map, string $path, string $usedFor, array $state, string $target): ?array {
    $doc = nibblyAgentFinalDoc($state, $target) ?? [];
    $rules = null;
    $spec = null;

    if (preg_match('/^sections\.(\d+)\.([A-Za-z0-9_-]+)$/', $path, $m) && is_array($doc['sections'] ?? null) && empty($map['readOnlySections'])) {
        $sections = array_values($doc['sections']);
        $index = (int)$m[1];
        if (!isset($sections[$index]) || !is_array($sections[$index])) {
            throw new NibblyAgentOpException("'{$path}': the page has " . count($sections) . ' sections.', 'Section indexes start at 0 and refer to the page after the previous changes.');
        }
        $type = (string)($sections[$index]['type'] ?? '');
        $fields = nibblyAgentBlockSchemas()[$type === 'project' ? 'card' : $type]['fields'] ?? [];
        if (!isset($fields[$m[2]]) || nibblyAgentReadOnlyReason($path) !== null) {
            throw new NibblyAgentOpException("'{$m[2]}' is not an editable field of a {$type} section.", 'Fields: ' . implode(', ', array_keys($fields)) . '.');
        }
        $fieldType = $fields[$m[2]];
        $spec = ['type' => $fieldType, 'max' => nibblyAgentDefaultMax($fieldType, $m[2]), 'req' => false, 'options' => [], 'fmt' => null, 'altPath' => null, 'mustExist' => 'sections.' . $index];
        if ($fieldType === 'image') {
            $spec['fmt'] = 'split'; // section images keep their alt text in the sibling field
            $spec['altPath'] = 'sections.' . $index . '.alt';
        }
    } else {
        foreach ($map['lists'] as $listKey => $list) {
            if (!str_starts_with($path, $listKey . '.') || !preg_match('/^(\d+)\.([A-Za-z0-9_.-]+)$/', substr($path, strlen($listKey) + 1), $m)) continue;
            if (!empty($list['readOnly'])) throw new NibblyAgentOpException("'{$listKey}' is read-only.");
            $items = nibblyAgentListItemsOf($doc, $listKey);
            if (!isset($items[(int)$m[1]])) {
                throw new NibblyAgentOpException("'{$path}': the list has " . count($items) . ' items.', 'Indexes start at 0 and refer to the list after the previous changes.');
            }
            $itemType = $list['item'][$m[2]] ?? null;
            if ($itemType === null) throw new NibblyAgentOpException("'{$m[2]}' is not a field of {$listKey}.", 'Fields: ' . implode(', ', array_keys($list['item'])) . '.');
            [$base, $format] = array_pad(explode(':', $itemType, 2), 2, null);
            if ($base === 'image' && $format === 'split') throw new NibblyAgentOpException("'{$path}' cannot be changed inside a list.");
            $spec = ['type' => $base, 'max' => nibblyAgentDefaultMax($base, $m[2]), 'req' => false, 'options' => [], 'fmt' => $format, 'altPath' => null, 'mustExist' => $listKey . '.' . (int)$m[1]];
            break;
        }
    }
    if ($spec === null) return null;
    if ($usedFor === 'set_field' && $spec['type'] === 'image') throw new NibblyAgentOpException("'{$path}' is an image.", 'Use stage_set_image.');
    if ($usedFor === 'set_image' && $spec['type'] !== 'image') throw new NibblyAgentOpException("'{$path}' is not an image.", 'Use stage_set_field.');
    $rules = nibblyAgentOverrideForPath($target, $path);
    if ($rules['readOnly']) throw new NibblyAgentOpException("'{$path}' is read-only on this site.");
    if ($rules['max'] !== null) $spec['max'] = min((int)$spec['max'], (int)$rules['max']);
    if ($rules['req']) $spec['req'] = true;
    return $spec;
}

/** Where the credit of an image goes: the override's creditPath, or the caption of an image section. */
function nibblyAgentCreditPath(array $map, string $path, array $state, string $target): ?string {
    $field = $map['fields'][$path] ?? null;
    if ($field !== null && !empty($field['creditPath'])) return (string)$field['creditPath'];
    if (preg_match('/^sections\.(\d+)\.(src|image)$/', $path, $m)) {
        $doc = nibblyAgentFinalDoc($state, $target) ?? [];
        $type = (string)($doc['sections'][(int)$m[1]]['type'] ?? '');
        if ($type === 'image') return 'sections.' . $m[1] . '.caption';
    }
    return null;
}

function nibblyAgentListSpec(array $map, string $listKey): array {
    $list = $map['lists'][$listKey] ?? null;
    if ($list === null || !empty($list['readOnly'])) {
        throw new NibblyAgentOpException("'{$listKey}' is not an editable list of {$map['target']}.", 'Lists: ' . (implode(', ', array_keys($map['lists'])) ?: 'none') . '.');
    }
    return ['min' => (int)$list['min'], 'max' => (int)$list['max'], 'item' => $list['item'], 'defaults' => $list['defaults'], 'hideSupported' => !empty($list['hideSupported'])];
}

/** Build a section from block-type defaults and validated fields. */
function nibblyAgentBuildSection(string $type, array $fields, array $cs, array &$warnings, string $label, bool $hidden): array {
    $blocks = nibblyAgentBlockSchemas();
    if (!isset($blocks[$type]) || $type === 'project') {
        throw new NibblyAgentOpException("Unknown section type '{$type}'.", 'Types: ' . implode(', ', array_keys($blocks)) . '.');
    }
    $definition = getBlockTypes()[$type];
    $section = ['id' => 'section_' . bin2hex(random_bytes(4)), 'type' => $type];
    $section += (array)($definition['defaults'] ?? []);
    foreach ($fields as $key => $value) {
        $key = (string)$key;
        $fieldType = $blocks[$type]['fields'][$key] ?? null;
        if ($fieldType === null) {
            throw new NibblyAgentOpException("{$label}: '{$key}' is not a field of a {$type} section.", 'Fields: ' . implode(', ', array_keys($blocks[$type]['fields'])) . '.');
        }
        $at = "{$label}.{$key}";
        if ($fieldType === 'image') {
            $section[$key] = nibblyAgentResolveImage($value, $cs, $at);
            if (array_key_exists('alt', $blocks[$type]['fields']) && trim((string)($fields['alt'] ?? '')) === '') {
                throw new NibblyAgentOpException("{$label}: an image section needs an alt text.", "Add the field 'alt'.");
            }
            continue;
        }
        $section[$key] = nibblyAgentNormalizeValue(['type' => $fieldType, 'max' => nibblyAgentDefaultMax($fieldType, $key)], $value, $cs, $warnings, $at);
    }
    if ($hidden) $section['hidden'] = true;
    return $section;
}

function nibblyAgentLanguageOrFail($lang): string {
    $lang = is_string($lang) ? strtolower(trim($lang)) : '';
    if (!in_array($lang, nibblyAgentSiteLanguages(), true)) {
        throw new NibblyAgentOpException('Language ' . ($lang === '' ? '(none)' : $lang) . ' is not a language of this site.', 'Languages: ' . implode(', ', nibblyAgentSiteLanguages()) . '.');
    }
    return $lang;
}

/**
 * Turn a request into an operation (without id). Throws NibblyAgentOpException for
 * everything the agent has to correct.
 *
 * @return array{op:array,warnings:array}
 */
function nibblyAgentBuildOp(array $cs, array $request, array $state): array {
    $warnings = [];
    $type = (string)($request['type'] ?? '');
    $allowed = NIBBLY_AGENT_REQUEST_KEYS[$type] ?? null;
    if ($allowed === null || $type === 'revert_op') throw new NibblyAgentOpException("Unknown operation '{$type}'.");
    $allowed = array_values(array_unique(array_merge($allowed, ['sourceRids'])));
    foreach (array_keys($request) as $key) {
        if (!in_array($key, $allowed, true)) throw new NibblyAgentOpException("'{$key}' is not an argument of {$type}.", 'Allowed: ' . implode(', ', array_slice($allowed, 1)) . '.');
    }
    $op = ['type' => $type];

    if (in_array($type, ['set_field', 'set_image', 'list_insert', 'list_hide', 'list_remove', 'list_move', 'section_insert'], true)) {
        nibblyAgentRequireKeys($request, ['target']);
        $target = (string)$request['target'];
        $op['target'] = $target;
        $map = nibblyAgentCapabilityMapFor($target);
        if (in_array($map['kind'], ['custom-template', 'standard-page'], true) && !empty($map['warnings']) && !$map['fields'] && !$map['lists']) {
            throw new NibblyAgentOpException("{$target} cannot be edited: " . $map['warnings'][0]);
        }
    }

    switch ($type) {
        case 'set_field':
            nibblyAgentRequireKeys($request, ['path', 'value']);
            $op['path'] = (string)$request['path'];
            $op['spec'] = nibblyAgentFieldSpec($map, $op['path'], 'set_field', $state, $target);
            $op['value'] = nibblyAgentNormalizeValue($op['spec'], $request['value'], $cs, $warnings, $op['path']);
            break;

        case 'set_image':
            nibblyAgentRequireKeys($request, ['path']);
            $op['path'] = (string)$request['path'];
            $op['spec'] = nibblyAgentFieldSpec($map, $op['path'], 'set_image', $state, $target);
            $op['src'] = nibblyAgentResolveImage($request['mediaRef'] ?? $request['libraryPath'] ?? null, $cs, $op['path']);
            $op['alt'] = $op['spec']['fmt'] === 'string' ? '' : nibblyAgentNormalizeAlt($request['alt'] ?? '', $op['path'] . '.alt');
            if (!empty($request['mediaRef'])) {
                $op['mediaRef'] = (string)$request['mediaRef'];
                $credit = nibblyAgentCreditText((array)($cs['media'][$op['mediaRef']]['credit'] ?? []), (string)($map['lang'] ?? ''));
                if ($credit !== '') {
                    $creditPath = nibblyAgentCreditPath($map, $op['path'], $state, $target);
                    if ($creditPath !== null) {
                        $op['creditPath'] = $creditPath;
                        $op['creditText'] = $credit;
                    } else {
                        $warnings[] = "This image needs the credit \"{$credit}\", but {$op['path']} has no caption field; it is stored in the media library.";
                    }
                }
            }
            break;

        case 'list_insert':
            nibblyAgentRequireKeys($request, ['listKey', 'item']);
            $op['listKey'] = (string)$request['listKey'];
            $op['listSpec'] = nibblyAgentListSpec($map, $op['listKey']);
            if (!is_array($request['item'])) throw new NibblyAgentOpException("'item' must be an object.");
            $op['position'] = isset($request['position']) ? (int)$request['position'] : PHP_INT_MAX;
            $op['item'] = nibblyAgentNormalizeItem($op['listSpec']['item'], $request['item'], (array)$op['listSpec']['defaults'], $cs, $warnings, $op['listKey']);
            if ($op['listSpec']['hideSupported']) $op['item']['hidden'] = true; // new items start hidden on live pages
            break;

        case 'list_hide':
            nibblyAgentRequireKeys($request, ['listKey', 'index', 'hidden']);
            $op['listKey'] = (string)$request['listKey'];
            $op['listSpec'] = nibblyAgentListSpec($map, $op['listKey']);
            $op['index'] = $request['index'];
            $op['hidden'] = (bool)$request['hidden'];
            break;

        case 'list_remove':
            nibblyAgentRequireKeys($request, ['listKey', 'index', 'titlePrefix']);
            $op['listKey'] = (string)$request['listKey'];
            $op['listSpec'] = nibblyAgentListSpec($map, $op['listKey']);
            $op['index'] = $request['index'];
            $op['titlePrefix'] = trim((string)$request['titlePrefix']);
            break;

        case 'list_move':
            nibblyAgentRequireKeys($request, ['listKey', 'from', 'to']);
            $op['listKey'] = (string)$request['listKey'];
            $op['listSpec'] = nibblyAgentListSpec($map, $op['listKey']);
            $op['from'] = $request['from'];
            $op['to'] = $request['to'];
            break;

        case 'section_insert':
            nibblyAgentRequireKeys($request, ['position', 'sectionType']);
            if ($map['kind'] !== 'standard-page') throw new NibblyAgentOpException('Sections can only be added to standard pages.', 'This page is built from a template; edit its fields and lists instead.');
            $op['position'] = (int)$request['position'];
            $op['section'] = nibblyAgentBuildSection((string)$request['sectionType'], (array)($request['fields'] ?? []), $cs, $warnings, 'section', true);
            break;

        case 'create_news':
            nibblyAgentRequireKeys($request, ['lang', 'title', 'date', 'contentHtml']);
            $lang = nibblyAgentLanguageOrFail($request['lang']);
            $title = nibblyAgentNormalizeText((string)$request['title'], 180, false, $warnings, 'title');
            $date = nibblyAgentNormalizeValue(['type' => 'date'], $request['date'], $cs, $warnings, 'date');
            $slug = nibblyCopilotSlugify((string)($request['slug'] ?? $title), 'news');
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) throw new NibblyAgentOpException('The slug is not valid.', 'Use lowercase words separated by hyphens.');
            $id = $date . '-' . $slug . ($lang !== nibblyAgentSiteLanguages()[0] ? '-' . $lang : '');
            $image = '';
            if (!empty($request['imageRef']) || !empty($request['imagePath'])) {
                $image = nibblyAgentResolveImage($request['imageRef'] ?? $request['imagePath'], $cs, 'image');
            }
            $op['target'] = 'news:' . $id;
            $op['post'] = [
                'id' => $id, 'lang' => $lang, 'title' => $title, 'slug' => $slug, 'date' => $date,
                'author' => nibblyAgentNormalizeText((string)($request['author'] ?? ''), 120, false, $warnings, 'author'),
                'excerpt' => nibblyAgentNormalizeText((string)($request['excerpt'] ?? ''), 320, true, $warnings, 'excerpt'),
                'image' => $image,
                'content' => nibblyAgentNormalizeValue(['type' => 'html', 'max' => 20000], $request['contentHtml'], $cs, $warnings, 'contentHtml'),
                'hidden' => true,
            ];
            if ($title === '') throw new NibblyAgentOpException('The news title is empty.');
            if (!empty($request['imageRef'])) {
                $op['mediaRef'] = (string)$request['imageRef'];
                $credit = nibblyAgentCreditText((array)($cs['media'][$op['mediaRef']]['credit'] ?? []), $lang);
                if ($credit !== '' && (!function_exists('nibblyAgentSettings') || nibblyAgentSettings()['creditInNews'])) {
                    $op['post']['content'] .= '<p><em>' . htmlspecialchars($credit, ENT_QUOTES, 'UTF-8') . '</em></p>';
                }
            }
            break;

        case 'create_event':
            nibblyAgentRequireKeys($request, ['lang', 'title', 'date', 'time', 'location']);
            $lang = nibblyAgentLanguageOrFail($request['lang']);
            $draft = [
                'lang' => $lang,
                'title' => nibblyAgentNormalizeText((string)$request['title'], 180, false, $warnings, 'title'),
                'date' => nibblyAgentNormalizeValue(['type' => 'date'], $request['date'], $cs, $warnings, 'date'),
                'time' => nibblyAgentNormalizeValue(['type' => 'time'], $request['time'], $cs, $warnings, 'time'),
                'location' => nibblyAgentNormalizeText((string)$request['location'], 240, false, $warnings, 'location'),
                'description' => isset($request['description']) ? nibblyAgentNormalizeValue(['type' => 'html', 'max' => 20000], $request['description'], $cs, $warnings, 'description') : '',
                'admission' => nibblyAgentNormalizeText((string)($request['admission'] ?? ''), 160, false, $warnings, 'admission'),
                'url' => isset($request['url']) ? nibblyAgentNormalizeHref((string)$request['url'], $cs) : '',
            ];
            try {
                $event = nibblyCopilotBuildCreatedContent('event', $draft);
            } catch (RuntimeException $e) {
                throw new NibblyAgentOpException($e->getMessage());
            }
            foreach (['endDate' => 'end-date', 'endTime' => 'end-time'] as $from => $to) {
                if (isset($request[$from])) $event[$to] = nibblyAgentNormalizeValue(['type' => $from === 'endDate' ? 'date' : 'time'], $request[$from], $cs, $warnings, $from);
            }
            $op['target'] = 'event:' . $event['id'];
            $op['event'] = $event;
            break;

        case 'set_page_menus':
            // Which menus show a page. Administrators only (the tool's right); the page's own "nav" list is not an editable field.
            nibblyAgentRequireKeys($request, ['target']);
            $target = (string)$request['target'];
            $info = nibblyAgentParseTarget($target);
            if ($info === null || $info['kind'] !== 'page') throw new NibblyAgentOpException('Menus belong to pages.', 'Use a page target such as page:de_about.');
            $doc = nibblyAgentFinalDoc($state, $target);
            if ($doc === null) throw new NibblyAgentOpException("{$target} does not exist.", 'Create the page first, or use get_site_overview to find it.');
            if (!function_exists('getRegisteredMenuIds')) require_once nibblyAgentSiteRoot() . '/includes/menu-helpers.php';
            $registered = getRegisteredMenuIds();
            foreach (['add', 'remove'] as $key) {
                $ids = array_values(array_unique(array_map('strval', (array)($request[$key] ?? []))));
                foreach ($ids as $id) {
                    if (!in_array($id, $registered, true)) throw new NibblyAgentOpException("'{$id}' is not a menu of this site.", 'Menus: ' . implode(', ', $registered) . '. "header" is the main menu.');
                }
                $op[$key] = $ids;
            }
            if (!$op['add'] && !$op['remove']) throw new NibblyAgentOpException('Name at least one menu to add or remove.');
            if (array_intersect($op['add'], $op['remove'])) throw new NibblyAgentOpException('A menu cannot be added and removed at once.');
            $op['target'] = $target;
            break;

        case 'create_page':
            nibblyAgentRequireKeys($request, ['lang', 'slug', 'title']);
            $lang = nibblyAgentLanguageOrFail($request['lang']);
            $slug = (string)$request['slug'];
            if (!nibblyPageIsValidPath($slug)) throw new NibblyAgentOpException('The page path is not valid.', 'Use lowercase URL segments separated by slashes, e.g. about/team.');
            $key = nibblyPageContentKey($lang, $slug);
            if (nibblyAgentTemplateFor($key) !== '') throw new NibblyAgentOpException("A template already serves {$lang}/{$slug}.", 'Choose another slug.');
            $title = nibblyAgentNormalizeText((string)$request['title'], 180, false, $warnings, 'title');
            if ($title === '') throw new NibblyAgentOpException('The page title is empty.');
            $sections = [];
            foreach ((array)($request['sections'] ?? []) as $index => $section) {
                if (!is_array($section) || !isset($section['type'])) throw new NibblyAgentOpException("sections[{$index}] needs a type.");
                $built = nibblyAgentBuildSection((string)$section['type'], (array)($section['fields'] ?? []), $cs, $warnings, "sections[{$index}]", false);
                // The level is not the agent's to choose; the heading that opens a page is the page title.
                if ($index === 0 && ($built['type'] ?? '') === 'heading') $built['level'] = 'h1';
                $sections[] = $built;
            }
            if (!$sections) throw new NibblyAgentOpException('A new page needs at least one section.', 'Add a heading and a text section.');
            $op['target'] = 'page:' . $key;
            $op['page'] = [
                'page' => $key, 'path' => $slug, 'lang' => $lang, 'title' => $title,
                'description' => nibblyAgentNormalizeText((string)($request['description'] ?? ''), 320, true, $warnings, 'description'),
                'nav' => [],
                'visibility' => [
                    'status' => 'private', 'title' => 'Draft page',
                    'text' => 'This AI-created draft is private until an editor publishes it.',
                    'passwordHash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                ],
                'sections' => $sections,
            ];
            break;
    }
    if (!empty($request['sourceRids'])) {
        $selected = [];
        foreach (array_values(array_unique(array_map('strval', (array)$request['sourceRids']))) as $rid) {
            $found = null;
            foreach ($cs['sources'] as $source) {
                if (($source['rid'] ?? '') === $rid) $found = $source;
            }
            if ($found === null) throw new NibblyAgentOpException("Unknown source {$rid}.", 'Use a rid that read_web_result returned in this run.');
            $selected[] = $found;
        }
        $op['sources'] = array_column($selected, 'rid');
        if ($type === 'create_news' && isset($op['post']) && (!function_exists('nibblyAgentSettings') || nibblyAgentSettings()['sourcesInNews'])) {
            $op['post']['content'] .= nibblyAgentSourcesHtml($selected, (string)$op['post']['lang']);
        }
    }
    return ['op' => $op, 'warnings' => $warnings];
}

/** Current revisions of the files behind targets: target => revision ('missing' when absent). */
function nibblyAgentTargetRevisions(array $targets): array {
    $revisions = [];
    clearstatcache();
    foreach ($targets as $target) {
        $storage = nibblyAgentTargetStorage($target);
        $revisions[$target] = $storage === null ? 'invalid' : nibblyJsonRevision($storage['file']);
    }
    return $revisions;
}

/** Targets whose stored revision no longer matches the base of the change set. */
function nibblyAgentConflicts(array $cs): array {
    $conflicts = [];
    foreach (nibblyAgentTargetRevisions(array_keys($cs['base'])) as $target => $revision) {
        if ($revision !== $cs['base'][$target]) $conflicts[] = $target;
    }
    return $conflicts;
}

function nibblyAgentOpTargets(array $ops): array {
    return array_values(array_unique(array_filter(array_column($ops, 'target'))));
}

function nibblyAgentDisplayValue($value): string {
    if (is_array($value)) {
        if (isset($value['href'])) return trim((string)($value['text'] ?? '') . ' → ' . $value['href']);
        if (isset($value['src'])) return (string)$value['src'] . (isset($value['alt']) && $value['alt'] !== '' ? ' (' . $value['alt'] . ')' : '');
        $encoded = (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return mb_strlen($encoded, 'UTF-8') > 600 ? mb_substr($encoded, 0, 600, 'UTF-8') . '…' : $encoded;
    }
    if (is_bool($value)) return $value ? 'true' : 'false';
    $text = (string)$value;
    return mb_strlen($text, 'UTF-8') > 600 ? mb_substr($text, 0, 600, 'UTF-8') . '…' : $text;
}

/** A review text in the admin language (English when the language files are not loaded, e.g. in CLI tests). */
function nibblyAgentOpText(string $key, string $fallback, array $params = []): string {
    $text = function_exists('t') ? t($key) : $key;
    if ($text === $key) $text = $fallback;
    foreach ($params as $name => $value) $text = str_replace('{' . $name . '}', (string)$value, $text);
    return $text;
}

/** A field or list name as a person says it: common names are translated, others shown as they are. */
function nibblyAgentFieldName(string $field): string {
    $known = ['content' => 'Text', 'text' => 'Text', 'title' => 'Title', 'heading' => 'Heading', 'subtitle' => 'Subtitle',
        'description' => 'Description', 'caption' => 'Caption', 'image' => 'Image', 'items' => 'Items', 'alt' => 'Alt text'];
    $field = ['src' => 'image', 'body' => 'content'][$field] ?? $field;
    return isset($known[$field]) ? nibblyAgentOpText('agent.field.' . $field, $known[$field]) : $field;
}

/** The headline of one operation in the review: what changes and where, e.g. Change “Text” in section 14 (text): “Loslegen”. */
function nibblyAgentOpLabel(array $op, array $result): string {
    $type = $op['type'];
    $context = is_array($result['context'] ?? null) ? $result['context'] : ['field' => '', 'section' => null, 'item' => null];
    $field = nibblyAgentFieldName((string)($context['field'] ?: ($op['path'] ?? $op['listKey'] ?? '')));
    $what = match ($type) {
        'set_field' => nibblyAgentOpText('agent.op.set_field', 'Change “{field}”', ['field' => $field]),
        'set_image' => nibblyAgentOpText('agent.op.set_image', 'Set image “{field}”', ['field' => $field]),
        'list_insert' => nibblyAgentOpText('agent.op.list_insert', 'Add item to “{field}”', ['field' => $field]),
        'list_hide' => nibblyAgentOpText($op['hidden'] ? 'agent.op.list_hide' : 'agent.op.list_show', $op['hidden'] ? 'Hide item {n} of “{field}”' : 'Show item {n} of “{field}”', ['n' => (int)$op['index'] + 1, 'field' => $field]),
        'list_remove' => nibblyAgentOpText('agent.op.list_remove', 'Remove item {n} of “{field}”', ['n' => (int)$op['index'] + 1, 'field' => $field]),
        'list_move' => nibblyAgentOpText('agent.op.list_move', 'Move item {from} to {to} in “{field}”', ['from' => (int)$op['from'] + 1, 'to' => (int)$op['to'] + 1, 'field' => $field]),
        'section_insert' => nibblyAgentOpText('agent.op.section_insert', 'Add {type} section', ['type' => (string)($op['section']['type'] ?? '')]),
        'create_news' => nibblyAgentOpText('agent.op.create_news', 'Create news post'),
        'create_event' => nibblyAgentOpText('agent.op.create_event', 'Create event'),
        'create_page' => nibblyAgentOpText('agent.op.create_page', 'Create page'),
        'set_page_menus' => nibblyAgentOpText('agent.op.set_page_menus', 'Change the menus of this page'),
        default => $type
    };
    $section = $context['section'] ?? null;
    if (!is_array($section)) return $what;
    $params = ['what' => $what, 'n' => $section['n'], 'type' => $section['type'], 'title' => $section['title']];
    $where = $section['title'] !== ''
        ? nibblyAgentOpText('agent.op.in_section', '{what} in section {n} ({type}): “{title}”', $params)
        : nibblyAgentOpText('agent.op.in_section_untitled', '{what} in section {n} ({type})', $params);
    return !empty($context['item']) ? $where . ' ' . nibblyAgentOpText('agent.op.item', '(item {item})', ['item' => $context['item']]) : $where;
}

/** One operation as the review panel shows it. Values are plain text; HTML values are already sanitised. */
function nibblyAgentOpPreview(array $op, array $result): array {
    $type = $op['type'];
    $label = nibblyAgentOpLabel($op, $result);
    $isHtml = ($op['spec']['type'] ?? '') === 'html' || $type === 'create_news';
    return [
        'opId' => $op['id'], 'type' => $type, 'target' => $op['target'], 'label' => $label,
        'before' => nibblyAgentDisplayValue($result['before'] ?? null),
        'after' => nibblyAgentDisplayValue($result['after'] ?? null),
        'html' => $isHtml,
        'destructive' => $type === 'list_remove',
    ];
}

/**
 * Add one operation. The whole package is replayed; nothing is stored when anything fails.
 *
 * @return array{ok:true,opId:string,preview:array,warnings:array}|array{error:string,hint:string}
 * @throws NibblyAgentScanDeferred when a custom template page still has to be scanned in another request
 */
function nibblyAgentChangesetStage(string $csId, array $request): array {
    $outcome = null;
    $stored = nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($request, &$outcome) {
        if ($cs['status'] !== 'staging') {
            $outcome = ['error' => 'This change set is ' . $cs['status'] . ' and cannot be changed.', 'hint' => 'Start a new change set.'];
            return false;
        }
        if (($request['type'] ?? '') === 'revert_op') {
            $outcome = nibblyAgentRevertOpIn($cs, (string)($request['opId'] ?? ''));
            return empty($outcome['error']) ? true : false;
        }
        if (count($cs['ops']) >= NIBBLY_AGENT_MAX_OPS) {
            $outcome = ['error' => 'This change set already has the maximum number of operations.', 'hint' => 'Present it for review first.'];
            return false;
        }
        if ($conflicts = nibblyAgentConflicts($cs)) {
            $cs['status'] = 'conflict';
            $outcome = ['error' => 'The content changed since this change set was started (' . implode(', ', $conflicts) . ').', 'hint' => 'Ask the user to revalidate the change set.'];
            return true;
        }
        $pre = nibblyAgentReplayOps($cs['ops']);
        if (!$pre['ok']) {
            $outcome = ['error' => 'The current change set no longer replays: ' . reset($pre['errors'])['message'], 'hint' => 'Revalidate or revert the failing operation.'];
            return false;
        }
        try {
            $built = nibblyAgentBuildOp($cs, $request, $pre['state']);
        } catch (NibblyAgentOpException $e) {
            $outcome = ['error' => $e->getMessage(), 'hint' => $e->hint];
            return false;
        }
        $op = $built['op'];
        $highest = 0;
        foreach ($cs['ops'] as $existing) $highest = max($highest, (int)substr((string)$existing['id'], 3));
        $op['id'] = 'op_' . ($highest + 1);
        $op['createdAt'] = date('c');

        $base = $cs['base'];
        $ops = array_merge($cs['ops'], [$op]);
        // The base revision of a target is the state the agent saw when it first touched it.
        foreach (nibblyAgentTargetRevisions(nibblyAgentOpTargets([$op])) as $target => $revision) {
            $base[$target] ??= $revision;
        }
        $replay = nibblyAgentReplayOps($ops);
        if (!$replay['ok']) {
            $error = reset($replay['errors']);
            $outcome = ['error' => $error['message'], 'hint' => $error['hint']];
            return false;
        }
        $op['beforeHash'] = nibblyAgentValueHash($replay['state']['results'][$op['id']]['before'] ?? null);
        $ops[count($ops) - 1] = $op;
        $problems = nibblyAgentValidate(array_replace($cs, ['ops' => $ops]), $replay, false);
        foreach ($problems['errors'] as $problem) {
            if (in_array($op['id'], $problem['opIds'] ?? [], true)) {
                if (str_starts_with((string)($problem['code'] ?? ''), 'safety_')) {
                    nibblyAgentAudit('agent-safety', false, ['stage' => 'stage', 'cs' => $csId, 'op' => $op['type'], 'codes' => [$problem['code']]]);
                }
                $outcome = ['error' => $problem['message'], 'hint' => $problem['hint'] ?? ''];
                return false;
            }
        }
        $cs['base'] = $base;
        $cs['ops'] = $ops;
        foreach ($cs['sources'] as &$source) {
            if (in_array($source['rid'] ?? '', (array)($op['sources'] ?? []), true)) $source['ops'] = array_values(array_unique(array_merge((array)($source['ops'] ?? []), [$op['id']])));
        }
        unset($source);
        $cs['validation'] = null;
        $cs['renderCheck'] = null;
        $cs['previewHash'] = '';
        $outcome = ['ok' => true, 'opId' => $op['id'], 'preview' => nibblyAgentOpPreview($op, $replay['state']['results'][$op['id']] ?? []), 'warnings' => $built['warnings']];
        return true;
    });
    if ($stored === null) return ['error' => 'Unknown change set.', 'hint' => ''];
    return $outcome ?? ['error' => 'The operation could not be stored.', 'hint' => ''];
}

function nibblyAgentRevertOpIn(array &$cs, string $opId): array {
    $remaining = array_values(array_filter($cs['ops'], static fn($op) => $op['id'] !== $opId));
    if (count($remaining) === count($cs['ops'])) return ['error' => "Unknown operation {$opId}.", 'hint' => ''];
    // Indexes name the state after the operations before them. Taking out a structural change
    // would silently re-point every later operation on the same target, so those go first.
    $position = array_search($opId, array_column($cs['ops'], 'id'), true);
    $reverted = $cs['ops'][$position];
    if (in_array($reverted['type'], ['list_insert', 'list_remove', 'list_move', 'section_insert'], true)) {
        foreach (array_slice($cs['ops'], $position + 1) as $later) {
            if (($later['target'] ?? '') === $reverted['target']) {
                return ['error' => "Later operations depend on {$opId} ({$later['id']} changes the same page).", 'hint' => 'Revert the later operations first.'];
            }
        }
    }
    $replay = nibblyAgentReplayOps($remaining);
    if (!$replay['ok']) {
        return ['error' => 'Later operations depend on ' . $opId . ': ' . reset($replay['errors'])['message'], 'hint' => 'Revert the later operations first.'];
    }
    $cs['ops'] = $remaining;
    foreach ($cs['sources'] as &$source) {
        $source['ops'] = array_values(array_diff((array)($source['ops'] ?? []), [$opId]));
    }
    unset($source);
    $cs['base'] = array_intersect_key($cs['base'], array_flip(nibblyAgentOpTargets($remaining)));
    $cs['validation'] = null;
    $cs['renderCheck'] = null;
    $cs['previewHash'] = '';
    return ['ok' => true, 'opId' => $opId, 'preview' => [], 'warnings' => []];
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/**
 * Judge the replayed package. With $final false only what can never become right later
 * is reported (limits that only grow); the full check runs when the package is presented.
 *
 * @return array{ok:bool,errors:array,warnings:array}
 */
function nibblyAgentValidate(array $cs, array $replay, bool $final): array {
    $errors = [];
    $warnings = [];
    $add = static function (string $message, ?string $opId = null, string $hint = '') use (&$errors): void {
        foreach ($errors as $index => $known) {
            if ($known['message'] === $message) { // several operations on one list report it once
                if ($opId !== null) $errors[$index]['opIds'][] = $opId;
                return;
            }
        }
        $errors[] = ['message' => $message, 'opId' => $opId, 'opIds' => $opId === null ? [] : [$opId], 'hint' => $hint];
    };
    if (!$replay['ok']) {
        foreach ($replay['errors'] as $opId => $error) $add($error['message'], $opId, $error['hint']);
        return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings, 'risk' => ['level' => 'ok', 'codes' => []]];
    }
    $state = $replay['state'];
    $createdIds = [];
    foreach ($cs['ops'] as $op) {
        $target = $op['target'];
        $storage = nibblyAgentTargetStorage($target);
        switch ($op['type']) {
            case 'set_field':
            case 'set_image':
                if ($final && !empty($op['spec']['req'])) {
                    $doc = nibblyAgentFinalDoc($state, $target);
                    $value = $doc === null ? null : getNestedValue($doc, $op['path']);
                    $empty = $value === null || $value === '' || $value === [] || (is_array($value) && trim((string)($value['src'] ?? $value['text'] ?? '')) === '' && isset($value['src']));
                    if ($empty) $add("{$op['path']} is required but empty.", $op['id']);
                }
                break;
            case 'list_insert':
            case 'list_hide':
            case 'list_remove':
            case 'list_move':
                $doc = nibblyAgentFinalDoc($state, $target);
                $count = $doc === null ? 0 : count(nibblyAgentListItemsOf($doc, $op['listKey']));
                $max = (int)$op['listSpec']['max'];
                $min = (int)$op['listSpec']['min'];
                if ($count > $max) $add("{$op['listKey']} would have {$count} items; at most {$max} are allowed.", $op['id'], 'Remove or hide items, or add fewer.');
                if ($final && $count < $min) $add("{$op['listKey']} would have {$count} items; at least {$min} are required.", $op['id']);
                break;
            case 'set_page_menus':
                $doc = nibblyAgentFinalDoc($state, $target);
                if ($doc === null) {
                    $add("{$target} does not exist at the end of the change set.", $op['id']);
                } elseif (in_array('header', (array)($doc['nav'] ?? []), true) && ($doc['visibility']['status'] ?? '') === 'private') {
                    $warnings[] = ['message' => 'The page is private until it is published, but it shows in the main menu: visitors would find a link they cannot open yet.', 'opId' => $op['id'], 'hint' => 'Publish the page after applying, or leave it out of the menu until then.'];
                }
                break;
            case 'create_news':
            case 'create_event':
            case 'create_page':
                if (isset($createdIds[$target])) $add("{$target} is created twice.", $op['id']);
                $createdIds[$target] = true;
                $existed = nibblyAgentReadJsonFile($storage['file']);
                if ($op['type'] === 'create_news' && $existed !== null) $add("News post {$storage['key']} already exists.", $op['id'], 'Change the slug or date.');
                if ($op['type'] === 'create_page' && $existed !== null) $add("Page {$storage['key']} already exists.", $op['id'], 'Choose another slug.');
                if ($op['type'] === 'create_event') {
                    foreach ((array)($existed['events'] ?? []) as $event) {
                        if (is_array($event) && ($event['id'] ?? null) === $storage['key']) $add("Event {$storage['key']} already exists.", $op['id'], 'Change the title or date.');
                    }
                }
                if ($op['type'] === 'create_news' && $final) {
                    if (trim(strip_tags((string)$op['post']['content'])) === '') $add('The news post has no content.', $op['id']);
                    $override = nibblyAgentLoadOverrides($target);
                    if (!empty($override['news']['requireImage']) && $op['post']['image'] === '') {
                        $add('This site requires an image for every news post.', $op['id'], 'Add one with a stock image, a generated image, the media library or an upload.');
                    }
                }
                break;
        }
    }
    foreach ($state['files'] as $file => $entry) {
        if ($entry['data'] !== $entry['before'] && strlen((string)json_encode($entry['data'])) > NIBBLY_AGENT_MAX_DOC_BYTES) {
            $add('The result would exceed the size limit of a content file (' . basename($file) . ').');
        }
    }
    // Safety: what the change set does to the site, judged on the replayed content (see safety.php).
    $safety = nibblyAgentSafetyAssess($cs, $replay);
    $allOps = array_column($cs['ops'], 'id');
    foreach ($safety['findings'] as $finding) {
        $opIds = $finding['opIds'] ?: $allOps;
        if ($finding['level'] === 'block') {
            $errors[] = ['message' => $finding['message'], 'opId' => $opIds[0] ?? null, 'opIds' => $opIds, 'hint' => $finding['hint'], 'code' => 'safety_' . $finding['code']];
        } else {
            $warnings[] = ['message' => $finding['message'], 'opId' => $opIds[0] ?? null, 'hint' => $finding['hint'], 'code' => 'safety_' . $finding['code']];
        }
    }
    return ['ok' => !$errors, 'errors' => $errors, 'warnings' => $warnings, 'risk' => ['level' => $safety['level'], 'codes' => array_values(array_unique(array_column($safety['findings'], 'code')))]];
}

/** State of a target document (read-only copy); files not yet touched are read from disk. */
function nibblyAgentFinalDoc(array $state, string $target): ?array {
    $storage = nibblyAgentTargetStorage($target);
    if ($storage === null) return null;
    nibblyAgentStateFile($state, $storage['file']);
    $data = $state['files'][$storage['file']]['data'];
    if ($storage['kind'] === 'event') {
        foreach ((array)($data['events'] ?? []) as $event) {
            if (is_array($event) && ($event['id'] ?? null) === $storage['key']) return $event;
        }
        return null;
    }
    return $data;
}

/** Hash of what the package would store; the user confirms exactly this. */
function nibblyAgentPreviewHash(array $cs, array $replay): string {
    $files = [];
    foreach (nibblyAgentChangedFiles($replay['state']) as $file => $change) {
        $files[nibblyAgentRelativePath($file)] = $change['after'];
    }
    ksort($files);
    return nibblyAgentValueHash(['files' => $files, 'media' => array_keys($cs['media'])]);
}

// ---------------------------------------------------------------------------
// Render check
// ---------------------------------------------------------------------------

/**
 * Render every changed page with the package as overlay and look at the result:
 * no warnings or exceptions, every staged path is rendered as an editable field, list
 * sizes and sections match. News and events are rendered through their own components.
 *
 * Custom templates render once per PHP process; targets that could not be rendered in
 * this process stay 'pending' and are checked by a later call.
 *
 * @return array{status:string,targets:array}
 */
function nibblyAgentRenderCheck(array $cs, array $replay, array $previous = []): array {
    $state = $replay['state'];
    $results = [];
    $changed = nibblyAgentChangedFiles($state);
    $overlayTargets = [];
    foreach (nibblyAgentOpTargets($cs['ops']) as $target) {
        $storage = nibblyAgentTargetStorage($target);
        if ($storage === null || !isset($changed[$storage['file']])) continue;
        $overlayTargets[$target] = $storage;
    }

    foreach ($overlayTargets as $target => $storage) {
        if (($previous[$target]['status'] ?? '') === 'passed' && ($previous[$target]['hash'] ?? '') === nibblyAgentValueHash(nibblyAgentFinalDoc($state, $target))) {
            $results[$target] = $previous[$target];
            continue;
        }
        $results[$target] = nibblyAgentRenderCheckTarget($cs, $state, $target, $storage);
        $results[$target]['hash'] = nibblyAgentValueHash(nibblyAgentFinalDoc($state, $target));
    }
    $statuses = array_column($results, 'status');
    $status = in_array('failed', $statuses, true) ? 'failed' : (in_array('pending', $statuses, true) ? 'pending' : 'passed');
    return ['status' => $status, 'targets' => $results];
}

function nibblyAgentRenderCheckTarget(array $cs, array $state, string $target, array $storage): array {
    $doc = nibblyAgentFinalDoc($state, $target);
    $problems = [];
    $warnings = [];
    try {
        if ($storage['kind'] === 'shared') {
            return ['status' => 'skipped', 'messages' => ['Shared content (footer, sidebar) cannot be rendered on its own, so it was not checked by rendering.']];
        }
        if ($storage['kind'] === 'event') {
            $lang = (string)(array_key_first((array)($doc['title'] ?? [])) ?? nibblyAgentSiteLanguages()[0]);
            $html = renderEvent($doc, $lang, true, false);
            $title = (string)(($doc['title'][$lang] ?? ''));
            if ($title !== '' && !str_contains($html, htmlspecialchars($title))) $problems[] = 'The event title is not rendered.';
            return ['status' => $problems ? 'failed' : 'passed', 'messages' => $problems];
        }
        if ($storage['kind'] === 'news') {
            $GLOBALS['nibblyNewsOverlay'] = [$doc['id'] => $doc];
            nibblyAgentCaptureWarnings($warnings);
            try {
                $html = renderNewsList(0, (string)($doc['lang'] ?? nibblyAgentSiteLanguages()[0]));
            } finally {
                restore_error_handler();
                unset($GLOBALS['nibblyNewsOverlay']);
            }
            if (!str_contains($html, htmlspecialchars((string)$doc['title']))) $problems[] = 'The news title is not rendered in the news list.';
            if (($doc['image'] ?? '') !== '' && !str_contains($html, htmlspecialchars((string)$doc['image']))) $problems[] = 'The news image is not rendered.';
            return ['status' => ($problems || $warnings) ? 'failed' : 'passed', 'messages' => array_merge($problems, $warnings)];
        }

        // Pages: overlay every changed page, then render this one as an editor.
        foreach ($state['files'] as $file => $entry) {
            $info = nibblyAgentParseTargetFromFile($file);
            if ($info !== null && $entry['data'] !== $entry['before']) nibblyContentOverlaySet($info, $entry['data']);
        }
        $template = nibblyAgentTemplateFor($storage['key']);
        try {
            $rendered = $template !== '' ? nibblyAgentRenderTemplateOnce($template, $storage['key']) : nibblyAgentRenderStandard($storage['key']);
        } catch (NibblyAgentScanDeferred $e) {
            return ['status' => 'pending', 'messages' => ['This page is rendered in another step.']];
        } finally {
            nibblyContentOverlayClear();
        }
        if ($rendered['exception'] !== null) {
            return ['status' => 'failed', 'messages' => ['Rendering failed: ' . $rendered['exception']]];
        }
        foreach ($rendered['warnings'] as $warning) $problems[] = 'PHP warning while rendering: ' . $warning;
        $scan = nibblyAgentScanHtml($rendered['html'], $storage['key']);
        foreach ($cs['ops'] as $position => $op) {
            if (($op['target'] ?? '') !== $target) continue;
            if (in_array($op['type'], ['set_field', 'set_image'], true)) {
                $path = nibblyAgentFinalPath($cs['ops'], $state['results'], $position, $target, (string)$op['path']);
                if ($path === null) continue; // the item was removed later in the package
                if (!isset($scan['fields'][$path]) && !nibblyAgentRenderedInList($scan, $path)) {
                    $problems[] = "{$op['path']} does not appear on the rendered page (the template may not show it).";
                }
            } elseif (str_starts_with($op['type'], 'list_')) {
                $listKey = $op['listKey'];
                $expected = count(nibblyAgentListItemsOf($doc, $listKey));
                $actual = $scan['lists'][$listKey]['count'] ?? null;
                if ($actual !== $expected) $problems[] = "{$listKey} should render {$expected} items but renders " . ($actual ?? 'none') . '.';
            } elseif ($op['type'] === 'section_insert') {
                if (!in_array($op['section']['id'], array_column($scan['sections'], 'id'), true)) $problems[] = 'The new ' . $op['section']['type'] . ' section is not rendered.';
            }
        }
        if ($op = nibblyAgentFirstOp($cs, $target, 'create_page')) {
            if (count($scan['sections']) !== count((array)($doc['sections'] ?? []))) $problems[] = 'Not every section of the new page is rendered.';
        }
        return ['status' => $problems ? 'failed' : 'passed', 'messages' => array_values(array_unique($problems))];
    } catch (Throwable $error) {
        return ['status' => 'failed', 'messages' => ['Check failed: ' . get_class($error) . ': ' . $error->getMessage()]];
    }
}

/**
 * Where a path ends up after the later operations of the change set moved list items or
 * sections around; null when its item was removed. Operations name paths in the state at
 * their time, the rendered page shows the end state.
 */
function nibblyAgentFinalPath(array $ops, array $results, int $position, string $target, string $path): ?string {
    for ($i = $position + 1; $i < count($ops); $i++) {
        $op = $ops[$i];
        if (($op['target'] ?? '') !== $target) continue;
        $listKey = $op['type'] === 'section_insert' ? 'sections' : ($op['listKey'] ?? null);
        if ($listKey === null || !str_starts_with($path, $listKey . '.') || !preg_match('/^(\d+)(\..*)?$/', substr($path, strlen($listKey) + 1), $m)) continue;
        $index = (int)$m[1];
        $rest = $m[2] ?? '';
        switch ($op['type']) {
            case 'section_insert':
            case 'list_insert':
                if ($index >= (int)($results[$op['id']]['position'] ?? PHP_INT_MAX)) $index++;
                break;
            case 'list_remove':
                if ($index === (int)$op['index']) return null;
                if ($index > (int)$op['index']) $index--;
                break;
            case 'list_move':
                $from = (int)$op['from'];
                $to = (int)$op['to'];
                if ($index === $from) $index = $to;
                elseif ($from < $to && $index > $from && $index <= $to) $index--;
                elseif ($from > $to && $index >= $to && $index < $from) $index++;
                break;
        }
        $path = $listKey . '.' . $index . $rest;
    }
    return $path;
}

function nibblyAgentFirstOp(array $cs, string $target, string $type): ?array {
    foreach ($cs['ops'] as $op) {
        if (($op['target'] ?? '') === $target && $op['type'] === $type) return $op;
    }
    return null;
}

/** Content key of a page file path (for the overlay), or null for anything else. */
function nibblyAgentParseTargetFromFile(string $file): ?string {
    $pages = realpath(nibblyAgentSiteRoot() . '/content/pages') ?: nibblyAgentSiteRoot() . '/content/pages';
    $dir = dirname($file);
    if ((realpath($dir) ?: $dir) !== $pages || !str_ends_with($file, '.json')) return null;
    $key = basename($file, '.json');
    return nibblyPageIsValidContentKey($key) ? $key : null;
}

/** A path under a list item counts as rendered when the list's items render it. */
function nibblyAgentRenderedInList(array $scan, string $path): bool {
    foreach ($scan['lists'] as $key => $list) {
        if (str_starts_with($path, $key . '.') && preg_match('/^\d+\./', substr($path, strlen($key) + 1))) {
            return isset($scan['fields'][$path]) || ($list['count'] ?? 0) > 0;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Finalising, review data, revalidation
// ---------------------------------------------------------------------------

/**
 * Validate, render-check and fingerprint the package. A package that passes becomes
 * 'ready'; a render check that is still pending (another custom template) keeps it in
 * 'staging' until a later call finishes it.
 *
 * @return array{status:string,validation:array,renderCheck:array,previewHash:string}
 */
function nibblyAgentChangesetFinalize(string $csId): array {
    $report = null;
    nibblyAgentChangesetMutate($csId, static function (array &$cs) use (&$report) {
        if (!in_array($cs['status'], ['staging', 'ready'], true)) {
            $report = ['status' => $cs['status'], 'validation' => $cs['validation'], 'renderCheck' => $cs['renderCheck'], 'previewHash' => $cs['previewHash']];
            return false;
        }
        if ($conflicts = nibblyAgentConflicts($cs)) {
            $cs['status'] = 'conflict';
            $cs['validation'] = ['ok' => false, 'errors' => [['message' => 'The content changed since this change set was started (' . implode(', ', $conflicts) . ').', 'opId' => null, 'hint' => 'Revalidate the change set.']], 'warnings' => []];
            $report = ['status' => 'conflict', 'validation' => $cs['validation'], 'renderCheck' => null, 'previewHash' => ''];
            return true;
        }
        $replay = nibblyAgentReplayOps($cs['ops']);
        $validation = nibblyAgentValidate($cs, $replay, true);
        $blocked = array_values(array_filter(array_column($validation['errors'], 'code'), static fn($code) => is_string($code) && str_starts_with($code, 'safety_')));
        if ($blocked && empty($cs['validation']['errors']) ) nibblyAgentAudit('agent-safety', false, ['stage' => 'validate', 'cs' => $csId, 'codes' => $blocked]);
        if (!$cs['ops']) $validation = ['ok' => false, 'errors' => [['message' => 'The change set is empty.', 'opId' => null, 'hint' => '']], 'warnings' => []];
        $renderCheck = $validation['ok'] ? nibblyAgentRenderCheck($cs, $replay, (array)($cs['renderCheck']['targets'] ?? [])) : ['status' => 'skipped', 'targets' => []];
        $cs['validation'] = $validation;
        $cs['renderCheck'] = $renderCheck;
        $cs['previewHash'] = $validation['ok'] ? nibblyAgentPreviewHash($cs, $replay) : '';
        $cs['status'] = ($validation['ok'] && $renderCheck['status'] === 'passed') ? 'ready' : 'staging';
        $report = ['status' => $cs['status'], 'validation' => $validation, 'renderCheck' => $renderCheck, 'previewHash' => $cs['previewHash']];
        return true;
    });
    return $report ?? ['status' => 'missing', 'validation' => null, 'renderCheck' => null, 'previewHash' => ''];
}

/**
 * Pages on which the package can be previewed (the preview works on any page URL with
 * ?nb_agent_preview=ID). Targets without a page of their own link to the home page.
 *
 * @return array<int,array{target:string,url:string}>
 */
function nibblyAgentPreviewLinks(array $cs, array $state): array {
    $default = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $links = [];
    foreach (nibblyAgentOpTargets($cs['ops']) as $target) {
        $url = '';
        if (str_starts_with($target, 'page:') && !in_array($target, ['page:footer', 'page:sidebar'], true)) {
            $parsed = function_exists('nibblyPageParseContentKey') ? nibblyPageParseContentKey(substr($target, 5)) : null;
            if ($parsed !== null) $url = nibblyPageUrlPath($parsed['lang'], $parsed['path'], $default);
        } elseif (str_starts_with($target, 'news:')) {
            $doc = $state ? nibblyAgentFinalDoc($state, $target) : null;
            $slug = is_array($doc) ? (string)($doc['slug'] ?? '') : '';
            $lang = is_array($doc) ? (string)($doc['lang'] ?? $default) : $default;
            if (preg_match('/^[a-z0-9-]+$/', $slug) && preg_match('/^[a-z]{2}$/', $lang)) {
                require_once dirname(__DIR__, 2) . '/news-helper.php';
                $url = nibblyNewsUrl(['slug' => $slug, 'date' => (string)($doc['date'] ?? ''), 'lang' => $lang], $lang); // follows settings news.urlPattern
            }
        }
        if ($url === '') $url = '/';
        $links[$url] ??= ['target' => $target, 'url' => $url];
        if (count($links) >= 6) break;
    }
    return array_values($links);
}

/**
 * Review data for the panel: every operation with before/after, per-target render check,
 * sources, warnings and the hash the user confirms.
 */
function nibblyAgentChangesetPreview(array $cs): array {
    $replay = nibblyAgentReplayOps($cs['ops']);
    $operations = [];
    foreach ($cs['ops'] as $op) {
        $operations[] = nibblyAgentOpPreview($op, $replay['state']['results'][$op['id']] ?? []);
    }
    $groups = [];
    foreach ($operations as $operation) $groups[$operation['target']][] = $operation;
    return [
        'id' => $cs['id'], 'status' => $cs['status'], 'previewHash' => $cs['previewHash'],
        'previewLinks' => nibblyAgentPreviewLinks($cs, $replay['state'] ?? []),
        'targets' => $groups,
        'sources' => nibblyAgentPublicSources($cs['sources']),
        'validation' => $cs['validation'],
        'renderCheck' => $cs['renderCheck'],
        'conflicts' => nibblyAgentConflicts($cs),
        'media' => array_map(static fn($m) => array_intersect_key($m, array_flip(['finalPath', 'credit', 'ai', 'source'])), $cs['media']),
    ];
}

/**
 * After a conflict: take the current content as the new base and replay the operations
 * on it. Operations that no longer fit stay a conflict, with the reason.
 */
function nibblyAgentChangesetRevalidate(string $csId, array $actor): array {
    $outcome = ['ok' => false, 'error' => 'Unknown change set.'];
    nibblyAgentChangesetMutate($csId, static function (array &$cs) use (&$outcome, $actor) {
        if (!nibblyAgentCanSeeChangeset($cs, $actor)) return false;
        if (!in_array($cs['status'], ['conflict', 'staging', 'ready'], true)) {
            $outcome = ['ok' => false, 'error' => 'This change set is ' . $cs['status'] . '.'];
            return false;
        }
        $base = nibblyAgentTargetRevisions(nibblyAgentOpTargets($cs['ops']));
        $replay = nibblyAgentReplayOps($cs['ops']);
        if (!$replay['ok']) {
            $error = reset($replay['errors']);
            $cs['status'] = 'conflict';
            $outcome = ['ok' => false, 'error' => 'The operations no longer fit the current content: ' . $error['message']];
            return true;
        }
        $cs['base'] = $base;
        $cs['status'] = 'staging';
        $cs['validation'] = null;
        $cs['renderCheck'] = null;
        $cs['previewHash'] = '';
        $outcome = ['ok' => true];
        return true;
    });
    return $outcome;
}

/** After a review the user wants changes: a ready change set goes back to staging. */
function nibblyAgentChangesetReopen(string $csId, array $actor): bool {
    $done = false;
    nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($actor, &$done) {
        if (!nibblyAgentCanSeeChangeset($cs, $actor) || !in_array($cs['status'], ['ready', 'staging', 'conflict'], true)) return false;
        $cs['status'] = 'staging';
        $cs['validation'] = null;
        $cs['renderCheck'] = null;
        $cs['previewHash'] = '';
        $done = true;
        return true;
    });
    return $done;
}

/** URLs the user typed later in the conversation become usable as external links. */
function nibblyAgentChangesetAllowUrls(string $csId, array $urls): void {
    if (!$urls) return;
    nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($urls) {
        $cs['allowedUrls'] = array_values(array_unique(array_merge($cs['allowedUrls'], $urls)));
        return true;
    });
}

function nibblyAgentChangesetDiscard(string $csId, array $actor): bool {
    $done = false;
    nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($actor, &$done) {
        if (!nibblyAgentCanSeeChangeset($cs, $actor) || !in_array($cs['status'], ['staging', 'ready', 'conflict', 'failed'], true)) return false;
        $cs['status'] = 'discarded';
        $done = true;
        return true;
    });
    if ($done) {
        nibblyAgentRemoveTree(nibblyAgentDir() . '/staging/' . $csId);
        nibblyAgentAudit('agent-discard', true, ['cs' => $csId]);
    }
    return $done;
}

// ---------------------------------------------------------------------------
// Sources of a change set
// ---------------------------------------------------------------------------

/** What the review shows about a source (no internal fields). */
function nibblyAgentPublicSources(array $sources): array {
    $out = [];
    foreach ($sources as $source) {
        if (!is_array($source)) continue;
        $out[] = ['rid' => (string)($source['rid'] ?? ''), 'url' => (string)($source['url'] ?? ''), 'title' => (string)($source['title'] ?? ''), 'host' => (string)($source['host'] ?? ''),
            'date' => $source['date'] ?? null, 'warning' => !empty($source['warning']), 'warnings' => array_values((array)($source['warnings'] ?? [])), 'ops' => array_values((array)($source['ops'] ?? []))];
    }
    return $out;
}

const NIBBLY_AGENT_SOURCES_HEADINGS = ['de' => 'Quellen', 'en' => 'Sources', 'fr' => 'Sources', 'es' => 'Fuentes', 'it' => 'Fonti', 'pt' => 'Fontes', 'pl' => 'Źródła', 'cs' => 'Zdroje', 'tr' => 'Kaynaklar'];

/** The "Sources" block appended to a news post: only URLs the run actually fetched. */
function nibblyAgentSourcesHtml(array $sources, string $lang): string {
    if (!$sources) return '';
    $items = '';
    foreach ($sources as $source) {
        $url = (string)($source['url'] ?? '');
        if (!preg_match('#^https?://#i', $url) || preg_match('/["\'<>]/', $url)) continue;
        $label = (string)($source['title'] ?? '') !== '' ? (string)$source['title'] : (string)($source['host'] ?? $url);
        $items .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer">' . htmlspecialchars(mb_substr($label, 0, 120, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $items === '' ? '' : '<h2>' . (NIBBLY_AGENT_SOURCES_HEADINGS[$lang] ?? 'Sources') . '</h2><ul>' . $items . '</ul>';
}
