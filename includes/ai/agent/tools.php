<?php
/**
 * The agent's tools (agent phase P4): a whitelist instead of file access.
 *
 * Every tool has a JSON schema (validated by the gateway before a handler runs), a right,
 * and a handler. Read tools look at the site, stage tools add operations to the run's
 * change set. No tool writes outside content/ai-agent/staging/, none takes a file path,
 * none runs code. Results are JSON text; errors are {error, hint} so the model can correct
 * itself.
 *
 * Research, stock images, image generation and uploads come with later phases.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/changeset-apply.php';

if (!class_exists('NibblyAgentToolDeferred', false)) {
    /** The tool cannot finish in this request (e.g. a second template render); retry in the next step. */
    class NibblyAgentToolDeferred extends RuntimeException {
    }
}

const NIBBLY_AGENT_TOOL_RESULT_CHARS = 11000;

// ---------------------------------------------------------------------------
// Definitions
// ---------------------------------------------------------------------------

function nibblyAgentTargetSchema(): array {
    return ['type' => 'string', 'pattern' => '^(page|news|event):[a-z0-9_-]{1,160}$', 'maxLength' => 170,
        'description' => 'Target key: page:{contentKey}, news:{id}, event:{id}, page:footer or page:sidebar.'];
}

/** @return array<string,array{description:string,parameters:array,right:?string,handler:string}> */
function nibblyAgentToolDefinitions(): array {
    $object = static fn(array $properties, array $required = []) => ['type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties];
    $target = nibblyAgentTargetSchema();
    $index = ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000];
    $text = static fn(int $max, string $description = '') => ['type' => 'string', 'maxLength' => $max] + ($description !== '' ? ['description' => $description] : []);
    $lang = ['type' => 'string', 'pattern' => '^[a-z]{2}$', 'description' => 'Two-letter language code of the site.'];

    return [
        'get_site_overview' => ['description' => 'Languages, pages (target, title, kind), the latest news posts, your rights and today\'s date. Start here when you do not know the site.',
            'parameters' => $object([]), 'right' => null, 'handler' => 'nibblyAgentToolSiteOverview'],
        'get_page_capabilities' => ['description' => 'What can be edited on a target: fields (p path, t type, v current value, max length), lists with their item fields, sections, read-only paths and hints. Call it before changing a page.',
            'parameters' => $object(['target' => $target], ['target']), 'right' => null, 'handler' => 'nibblyAgentToolCapabilities'],
        'read_content' => ['description' => 'Read the current content of a target (including changes already staged in this change set), optionally one path. Long values are cut at 4000 characters.',
            'parameters' => $object(['target' => $target, 'path' => $text(160, 'Dot path, e.g. hero.title or sections.2.content.')], ['target']), 'right' => null, 'handler' => 'nibblyAgentToolReadContent'],
        'list_content' => ['description' => 'List pages, news posts or events (targets and titles only).',
            'parameters' => $object(['type' => ['type' => 'string', 'enum' => ['page', 'news', 'event']], 'lang' => $lang, 'query' => $text(80), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]], ['type']), 'right' => null, 'handler' => 'nibblyAgentToolListContent'],
        'list_media' => ['description' => 'Images in the media library: path, size, AI label. Use a path as libraryPath for set_image or an item image.',
            'parameters' => $object(['query' => $text(80), 'folder' => $text(80), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 24]]), 'right' => null, 'handler' => 'nibblyAgentToolListMedia'],
        'list_run_media' => ['description' => 'Images collected during this run (uploads, imported or generated images) as m_* references.',
            'parameters' => $object([]), 'right' => null, 'handler' => 'nibblyAgentToolListRunMedia'],

        'stage_set_field' => ['description' => 'Change one text, rich text (HTML), link or icon field. Value: a string, or {text, href} for links. Paths below lists and sections use the indexes of the change set as it is now.',
            'parameters' => $object(['target' => $target, 'path' => $text(160), 'value' => ['description' => 'New value.']], ['target', 'path', 'value']), 'right' => 'edit', 'handler' => 'nibblyAgentToolStage'],
        'stage_set_image' => ['description' => 'Set an image field from the media library (libraryPath) or from this run (mediaRef). An alt text is required except for news and event cover images.',
            'parameters' => $object(['target' => $target, 'path' => $text(160), 'libraryPath' => $text(300), 'mediaRef' => $text(60), 'alt' => $text(200)], ['target', 'path']), 'right' => 'edit', 'handler' => 'nibblyAgentToolStage'],
        'stage_add_list_item' => ['description' => 'Add an item to a list (see lists in get_page_capabilities for its fields). The item starts hidden on the live page.',
            'parameters' => $object(['target' => $target, 'listKey' => $text(160), 'position' => $index, 'item' => ['type' => 'object']], ['target', 'listKey', 'item']), 'right' => 'structural', 'handler' => 'nibblyAgentToolStage'],
        'stage_hide_list_item' => ['description' => 'Hide or show a list item. This is the normal way to take an item off the page.',
            'parameters' => $object(['target' => $target, 'listKey' => $text(160), 'index' => $index, 'hidden' => ['type' => 'boolean']], ['target', 'listKey', 'index', 'hidden']), 'right' => 'structural', 'handler' => 'nibblyAgentToolStage'],
        'stage_remove_list_item' => ['description' => 'Delete a list item for good. Confirm the index with the first characters of its title.',
            'parameters' => $object(['target' => $target, 'listKey' => $text(160), 'index' => $index, 'titlePrefix' => $text(80)], ['target', 'listKey', 'index', 'titlePrefix']), 'right' => 'structural', 'handler' => 'nibblyAgentToolStage'],
        'stage_move_list_item' => ['description' => 'Move a list item from one index to another.',
            'parameters' => $object(['target' => $target, 'listKey' => $text(160), 'from' => $index, 'to' => $index], ['target', 'listKey', 'from', 'to']), 'right' => 'structural', 'handler' => 'nibblyAgentToolStage'],
        'stage_add_section' => ['description' => 'Insert a section of a block type into a standard page (fields depend on the type, see get_page_capabilities → addable). The section starts hidden.',
            'parameters' => $object(['target' => $target, 'position' => $index, 'sectionType' => $text(40), 'fields' => ['type' => 'object']], ['target', 'position', 'sectionType']), 'right' => 'structural', 'handler' => 'nibblyAgentToolStage'],
        'stage_create_news' => ['description' => 'Create a news post. It is created hidden; the user publishes it. The body is rich text without images.',
            'parameters' => $object(['lang' => $lang, 'title' => $text(180), 'slug' => $text(120), 'date' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'], 'author' => $text(120), 'excerpt' => $text(320), 'contentHtml' => $text(20000), 'imageRef' => $text(60), 'imagePath' => $text(300)], ['lang', 'title', 'date', 'contentHtml']), 'right' => 'createNews', 'handler' => 'nibblyAgentToolStage'],
        'stage_create_event' => ['description' => 'Create an event. It is created hidden; the user publishes it.',
            'parameters' => $object(['lang' => $lang, 'title' => $text(180), 'date' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'], 'time' => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$'], 'endDate' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'], 'endTime' => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$'], 'location' => $text(240), 'description' => $text(20000), 'admission' => $text(160), 'url' => $text(500)], ['lang', 'title', 'date', 'time', 'location']), 'right' => 'createEvents', 'handler' => 'nibblyAgentToolStage'],
        'stage_create_page' => ['description' => 'Create a page from sections. It is created private and out of the navigation; the user publishes it.',
            'parameters' => $object(['lang' => $lang, 'slug' => $text(120), 'title' => $text(180), 'description' => $text(320), 'sections' => ['type' => 'array', 'maxItems' => 30, 'items' => $object(['type' => $text(40), 'fields' => ['type' => 'object']], ['type'])]], ['lang', 'slug', 'title', 'sections']), 'right' => 'createPages', 'handler' => 'nibblyAgentToolStage'],
        'stage_revert_op' => ['description' => 'Take one staged operation out of the change set again.',
            'parameters' => $object(['opId' => $text(20)], ['opId']), 'right' => 'edit', 'handler' => 'nibblyAgentToolStage'],

        'validate_changeset' => ['description' => 'Check the change set: validation, then a render check of every changed page. Call it when you are done staging.',
            'parameters' => $object([]), 'right' => null, 'handler' => 'nibblyAgentToolValidate'],
        'ask_user' => ['description' => 'Stop and ask the user a question. Use it when facts are missing; never invent them.',
            'parameters' => $object(['question' => $text(600)], ['question']), 'right' => null, 'handler' => 'nibblyAgentToolAskUser'],
        'present_changeset' => ['description' => 'Present the validated change set to the user for review. Only possible after validate_changeset passed. This ends your work.',
            'parameters' => $object(['summary' => $text(600), 'openQuestions' => ['type' => 'array', 'maxItems' => 5, 'items' => $text(200)]], ['summary']), 'right' => null, 'handler' => 'nibblyAgentToolPresent'],
    ];
}

/** Tool definitions the signed-in user may use, in the gateway's format. */
function nibblyAgentToolsForUser(): array {
    $tools = [];
    foreach (nibblyAgentToolDefinitions() as $name => $tool) {
        if ($tool['right'] !== null && !nibblyAgentCan($tool['right'])) continue;
        $tools[] = ['name' => $name, 'description' => $tool['description'], 'parameters' => $tool['parameters']];
    }
    return $tools;
}

/** Map a stage_* tool to the change set request type. */
const NIBBLY_AGENT_STAGE_TYPES = [
    'stage_set_field' => 'set_field', 'stage_set_image' => 'set_image', 'stage_add_list_item' => 'list_insert',
    'stage_hide_list_item' => 'list_hide', 'stage_remove_list_item' => 'list_remove', 'stage_move_list_item' => 'list_move',
    'stage_add_section' => 'section_insert', 'stage_create_news' => 'create_news', 'stage_create_event' => 'create_event',
    'stage_create_page' => 'create_page', 'stage_revert_op' => 'revert_op',
];

// ---------------------------------------------------------------------------
// Running a tool call
// ---------------------------------------------------------------------------

/**
 * Execute one validated tool call for a run.
 *
 * @param array $run  the run (by reference: tools may change its status, question, change set)
 * @param array $call {id, name, arguments}
 * @return array{content:string,isError:bool,digest:string}
 * @throws NibblyAgentToolDeferred
 */
function nibblyAgentExecuteTool(array &$run, array $call, array $actor): array {
    $definitions = nibblyAgentToolDefinitions();
    $name = (string)$call['name'];
    try {
        $tool = $definitions[$name] ?? null;
        if ($tool === null) throw new NibblyAgentOpException("Unknown tool '{$name}'.");
        if ($tool['right'] !== null && !nibblyAgentCan($tool['right'])) {
            throw new NibblyAgentOpException("You are not allowed to use {$name}.", 'The signed-in user does not have the right for this. Do it differently or explain it to the user.');
        }
        $result = ($tool['handler'])($name, (array)$call['arguments'], $run, $actor);
        $isError = isset($result['error']);
    } catch (NibblyAgentOpException $e) {
        $result = ['error' => $e->getMessage()] + ($e->hint !== '' ? ['hint' => $e->hint] : []);
        $isError = true;
    } catch (NibblyAgentToolDeferred | NibblyAgentScanDeferred $e) {
        throw new NibblyAgentToolDeferred($e->getMessage());
    } catch (Throwable $e) {
        // Details stay in the log; the model only learns that this call failed.
        error_log('Nibbly agent tool ' . $name . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
        $result = ['error' => 'The tool failed unexpectedly.', 'hint' => 'Try something else or ask the user.'];
        $isError = true;
    }
    $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (mb_strlen($json, 'UTF-8') > NIBBLY_AGENT_TOOL_RESULT_CHARS) {
        $json = (string)json_encode(['truncated' => true, 'partial' => mb_substr($json, 0, NIBBLY_AGENT_TOOL_RESULT_CHARS - 200, 'UTF-8')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $digest = $isError ? $json : (mb_strlen($json, 'UTF-8') > 220 ? mb_substr($json, 0, 200, 'UTF-8') . '… (shortened)' : $json);
    return ['content' => $json, 'isError' => $isError, 'digest' => $digest];
}

// ---------------------------------------------------------------------------
// Access rules
// ---------------------------------------------------------------------------

/** Text the user wrote in this run, lowercase (goal and replies). */
function nibblyAgentUserText(array $run): string {
    $text = '';
    foreach ($run['transcript'] as $message) {
        if ($message['role'] === 'user') $text .= ' ' . $message['content'];
    }
    return mb_strtolower($text, 'UTF-8');
}

/**
 * Who may touch a target at all: shared content needs its right, and the content of private
 * pages only enters the conversation when the user named the page.
 *
 * @throws NibblyAgentOpException
 */
function nibblyAgentAssertTargetAccess(array $run, string $target): void {
    $info = nibblyAgentParseTarget($target);
    if ($info === null) throw new NibblyAgentOpException('Invalid target.', 'Use get_site_overview or list_content to find targets.');
    if ($info['kind'] === 'shared' && !nibblyAgentCan('editSharedContent')) {
        throw new NibblyAgentOpException('Shared content (footer, sidebar) is not available to you.', 'Only users with the right to edit shared content may change it.');
    }
    if ($info['kind'] !== 'page') return;
    $json = nibblyAgentReadJsonFile($info['file']);
    if (($json['visibility']['status'] ?? '') !== 'private') return;
    $text = nibblyAgentUserText($run);
    $path = (string)(nibblyPageParseContentKey($info['key'])['path'] ?? '');
    $title = mb_strtolower((string)($json['title'] ?? ''), 'UTF-8');
    $named = str_contains($text, mb_strtolower($info['key'], 'UTF-8')) || ($path !== '' && str_contains($text, $path)) || (mb_strlen($title, 'UTF-8') >= 3 && str_contains($text, $title));
    if (!$named) {
        throw new NibblyAgentOpException('This page is private.', 'Ask the user to name the page before you read or change it.');
    }
}

/** The run's change set id, created on first use. */
function nibblyAgentRunChangeset(array &$run, array $actor): string {
    if (!empty($run['changesetId']) && nibblyAgentChangesetLoad($run['changesetId']) !== null) return $run['changesetId'];
    $cs = nibblyAgentChangesetCreate(['owner' => $actor['userKey'], 'runId' => $run['id'], 'allowedUrls' => nibblyAgentExtractUrls(implode("\n", array_column(array_filter($run['transcript'], static fn($m) => $m['role'] === 'user'), 'content')))]);
    $run['changesetId'] = $cs['id'];
    return $cs['id'];
}

/** URLs, e-mail addresses and phone links the user typed: the only external links the agent may use. */
function nibblyAgentExtractUrls(string $text): array {
    $found = [];
    if (preg_match_all('#https?://[^\s<>"\'\)\]]+#i', $text, $m)) $found = array_merge($found, $m[0]);
    if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $m)) $found = array_merge($found, array_map(static fn($e) => 'mailto:' . $e, $m[0]));
    if (preg_match_all('/\btel:\+?[0-9][0-9 ()\-]{4,20}/i', $text, $m)) $found = array_merge($found, $m[0]);
    return array_values(array_unique(array_map(static fn($u) => rtrim($u, '.,;:!?'), $found)));
}

/** The document of a target as it is in the run's change set right now (stored content when there is none). */
function nibblyAgentRunDoc(array $run, string $target): ?array {
    $state = nibblyAgentReplayState();
    $cs = !empty($run['changesetId']) ? nibblyAgentChangesetLoad($run['changesetId']) : null;
    if ($cs !== null) {
        $replay = nibblyAgentReplayOps($cs['ops']);
        if ($replay['ok']) $state = $replay['state'];
    }
    return nibblyAgentFinalDoc($state, $target);
}

// ---------------------------------------------------------------------------
// Read tools
// ---------------------------------------------------------------------------

function nibblyAgentPrivatePage(array $json): bool {
    return ($json['visibility']['status'] ?? '') === 'private';
}

function nibblyAgentToolSiteOverview(string $name, array $args, array &$run, array $actor): array {
    $root = nibblyAgentSiteRoot();
    $pages = [];
    foreach (glob($root . '/content/pages/*.json') ?: [] as $file) {
        $key = basename($file, '.json');
        if (!nibblyPageIsValidContentKey($key)) continue;
        $json = nibblyAgentReadJsonFile($file) ?? [];
        $private = nibblyAgentPrivatePage($json);
        $entry = ['target' => 'page:' . $key, 'kind' => nibblyAgentTemplateFor($key) !== '' ? 'template' : (isset($json['sections']) ? 'standard' : 'other')];
        if ($private) $entry['private'] = true; else $entry['title'] = mb_substr((string)($json['title'] ?? ''), 0, 80, 'UTF-8');
        $pages[] = $entry;
    }
    usort($pages, static fn($a, $b) => strcmp($a['target'], $b['target']));
    $news = [];
    foreach (glob($root . '/content/news/*.json') ?: [] as $file) {
        $post = nibblyAgentReadJsonFile($file);
        if ($post) $news[] = ['target' => 'news:' . basename($file, '.json'), 'title' => mb_substr((string)($post['title'] ?? ''), 0, 80, 'UTF-8'), 'date' => (string)($post['date'] ?? ''), 'hidden' => !empty($post['hidden'])];
    }
    usort($news, static fn($a, $b) => strcmp($b['date'], $a['date']));
    $rights = [];
    foreach (['structural', 'createNews', 'createEvents', 'createPages', 'editSharedContent', 'publish'] as $right) $rights[$right] = nibblyAgentCan($right);
    return [
        'today' => date('Y-m-d'),
        'site' => defined('SITE_NAME') ? (string)SITE_NAME : '',
        'languages' => nibblyAgentSiteLanguages(),
        'pages' => array_slice($pages, 0, 60),
        'pagesTotal' => count($pages),
        'latestNews' => array_slice($news, 0, 10),
        'yourRights' => $rights,
    ];
}

function nibblyAgentToolCapabilities(string $name, array $args, array &$run, array $actor): array {
    nibblyAgentAssertTargetAccess($run, (string)$args['target']);
    try {
        $result = nibblyAgentCapabilities((string)$args['target']);
    } catch (InvalidArgumentException $e) {
        throw new NibblyAgentOpException($e->getMessage(), 'Use get_site_overview or list_content to find targets.');
    }
    return $result['descriptor'] + ['note' => 'These values are the stored content. Staged operations of this change set are not shown here; use read_content to see them.'];
}

/** Content a model may read: no credentials, hashes or visibility settings. */
function nibblyAgentReadableCopy(array $data, string $prefix = ''): array {
    $copy = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (nibblyCopilotIsSensitivePath($path) || $path === 'visibility' || str_starts_with($path, 'visibility.')) continue;
        $copy[$key] = is_array($value) ? nibblyAgentReadableCopy($value, $path) : $value;
    }
    return $copy;
}

function nibblyAgentToolReadContent(string $name, array $args, array &$run, array $actor): array {
    $target = (string)$args['target'];
    nibblyAgentAssertTargetAccess($run, $target);
    $doc = nibblyAgentRunDoc($run, $target);
    if ($doc === null) throw new NibblyAgentOpException("{$target} does not exist.");
    $path = isset($args['path']) ? trim((string)$args['path']) : '';
    if ($path !== '') {
        if (!nibblyAgentPathValid($path) || nibblyCopilotIsSensitivePath($path) || str_starts_with($path, 'visibility')) {
            throw new NibblyAgentOpException("'{$path}' cannot be read.");
        }
        $value = getNestedValue($doc, $path);
        if ($value === null) throw new NibblyAgentOpException("'{$path}' does not exist on {$target}.", 'Use get_page_capabilities to see the fields.');
    } else {
        $value = nibblyAgentReadableCopy($doc);
    }
    $encoded = is_string($value) ? $value : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $truncated = mb_strlen($encoded, 'UTF-8') > 4000;
    return ['target' => $target, 'path' => $path, 'value' => $truncated ? mb_substr($encoded, 0, 4000, 'UTF-8') : $value, 'truncated' => $truncated]
        + (!empty($run['changesetId']) ? ['includesStagedChanges' => true] : []);
}

function nibblyAgentToolListContent(string $name, array $args, array &$run, array $actor): array {
    $root = nibblyAgentSiteRoot();
    $limit = max(1, min(20, (int)($args['limit'] ?? 20)));
    $query = mb_strtolower(trim((string)($args['query'] ?? '')), 'UTF-8');
    $lang = (string)($args['lang'] ?? '');
    $items = [];
    if ($args['type'] === 'page') {
        foreach (glob($root . '/content/pages/*.json') ?: [] as $file) {
            $key = basename($file, '.json');
            $parsed = nibblyPageParseContentKey($key);
            if ($parsed === null || ($lang !== '' && $parsed['lang'] !== $lang)) continue;
            $json = nibblyAgentReadJsonFile($file) ?? [];
            $private = nibblyAgentPrivatePage($json);
            $title = (string)($json['title'] ?? '');
            if ($query !== '' && !str_contains(mb_strtolower($key . ' ' . ($private ? '' : $title), 'UTF-8'), $query)) continue;
            $items[] = ['target' => 'page:' . $key] + ($private ? ['private' => true] : ['title' => mb_substr($title, 0, 80, 'UTF-8')]);
        }
    } elseif ($args['type'] === 'news') {
        foreach (glob($root . '/content/news/*.json') ?: [] as $file) {
            $post = nibblyAgentReadJsonFile($file);
            if (!$post || ($lang !== '' && ($post['lang'] ?? '') !== $lang)) continue;
            if ($query !== '' && !str_contains(mb_strtolower(basename($file) . ' ' . ($post['title'] ?? ''), 'UTF-8'), $query)) continue;
            $items[] = ['target' => 'news:' . basename($file, '.json'), 'title' => mb_substr((string)($post['title'] ?? ''), 0, 80, 'UTF-8'), 'date' => (string)($post['date'] ?? ''), 'hidden' => !empty($post['hidden'])];
        }
        usort($items, static fn($a, $b) => strcmp($b['date'], $a['date']));
    } else {
        $events = nibblyAgentReadJsonFile($root . '/content/events.json');
        foreach ((array)($events['events'] ?? []) as $event) {
            if (!is_array($event) || !isset($event['id'])) continue;
            $title = (string)(reset($event['title']) ?: '');
            if ($query !== '' && !str_contains(mb_strtolower($event['id'] . ' ' . implode(' ', (array)($event['title'] ?? [])), 'UTF-8'), $query)) continue;
            $items[] = ['target' => 'event:' . $event['id'], 'title' => mb_substr($title, 0, 80, 'UTF-8'), 'date' => (string)($event['date'] ?? ''), 'hidden' => !empty($event['hidden'])];
        }
        usort($items, static fn($a, $b) => strcmp($b['date'], $a['date']));
    }
    return ['type' => $args['type'], 'count' => count($items), 'items' => array_slice($items, 0, $limit)];
}

function nibblyAgentToolListMedia(string $name, array $args, array &$run, array $actor): array {
    $base = realpath(nibblyAgentSiteRoot() . '/assets/images');
    if ($base === false) return ['items' => []];
    $query = mb_strtolower(trim((string)($args['query'] ?? '')), 'UTF-8');
    $folder = trim((string)($args['folder'] ?? ''), '/');
    if ($folder !== '' && (str_contains($folder, '..') || !preg_match('#^[A-Za-z0-9_/.-]+$#', $folder))) throw new NibblyAgentOpException('Invalid folder.');
    $limit = max(1, min(24, (int)($args['limit'] ?? 24)));
    $items = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue;
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($base) + 1));
        if ($folder !== '' && !str_starts_with($relative, $folder . '/')) continue;
        if ($query !== '' && !str_contains(mb_strtolower($relative, 'UTF-8'), $query)) continue;
        $items[] = $relative;
    }
    sort($items);
    $result = [];
    foreach (array_slice($items, 0, $limit) as $relative) {
        $info = @getimagesize($base . '/' . $relative);
        $entry = ['libraryPath' => '/assets/images/' . $relative, 'width' => $info[0] ?? null, 'height' => $info[1] ?? null];
        $label = function_exists('nibblyMediaAiLabelFor') ? nibblyMediaAiLabelFor('image', $relative) : '';
        if ($label !== '') $entry['ai'] = $label;
        $result[] = $entry;
    }
    return ['total' => count($items), 'items' => $result];
}

function nibblyAgentToolListRunMedia(string $name, array $args, array &$run, array $actor): array {
    $cs = !empty($run['changesetId']) ? nibblyAgentChangesetLoad($run['changesetId']) : null;
    $items = [];
    foreach ((array)($cs['media'] ?? []) as $ref => $media) {
        $items[] = ['mediaRef' => $ref] + array_intersect_key((array)$media, array_flip(['source', 'credit', 'ai', 'width', 'height']));
    }
    return ['items' => $items];
}

// ---------------------------------------------------------------------------
// Staging tools
// ---------------------------------------------------------------------------

/** What the model needs to see after a structural operation: the list or sections as they are now. */
function nibblyAgentStageContext(string $csId, array $request): array {
    $cs = nibblyAgentChangesetLoad($csId);
    $replay = $cs ? nibblyAgentReplayOps($cs['ops']) : null;
    if (!$replay || !$replay['ok'] || empty($request['target'])) return [];
    $doc = nibblyAgentFinalDoc($replay['state'], (string)$request['target']);
    if ($doc === null) return [];
    if (in_array($request['type'], ['list_insert', 'list_hide', 'list_remove', 'list_move'], true)) {
        $items = [];
        foreach (array_slice(nibblyAgentListItemsOf($doc, (string)$request['listKey']), 0, 30) as $i => $item) {
            $items[] = ['i' => $i, 'label' => mb_substr(nibblyAgentItemLabel($item), 0, 50, 'UTF-8')] + (!empty($item['hidden']) ? ['hidden' => true] : []);
        }
        return ['list' => $request['listKey'], 'items' => $items];
    }
    if ($request['type'] === 'section_insert') {
        return ['sections' => array_map(static fn($s, $i) => ['i' => $i, 'type' => $s['type'] ?? '', 'id' => $s['id'] ?? ''] + (!empty($s['hidden']) ? ['hidden' => true] : []), array_values((array)($doc['sections'] ?? [])), array_keys(array_values((array)($doc['sections'] ?? []))))];
    }
    return [];
}

function nibblyAgentToolStage(string $name, array $args, array &$run, array $actor): array {
    $type = NIBBLY_AGENT_STAGE_TYPES[$name] ?? null;
    if ($type === null) throw new NibblyAgentOpException("Unknown tool '{$name}'.");
    $request = ['type' => $type];
    foreach ($args as $key => $value) {
        if ($value !== null) $request[$key] = $value;
    }
    if (!empty($request['target'])) nibblyAgentAssertTargetAccess($run, (string)$request['target']);
    $csId = nibblyAgentRunChangeset($run, $actor);
    $result = nibblyAgentChangesetStage($csId, $request);
    if (isset($result['error'])) return $result;
    return $result + nibblyAgentStageContext($csId, $request);
}

function nibblyAgentFinalizeSummary(array $report): array {
    $errors = array_slice(array_map(static fn($e) => $e['message'] . (($e['hint'] ?? '') !== '' ? ' (' . $e['hint'] . ')' : ''), (array)($report['validation']['errors'] ?? [])), 0, 8);
    $render = [];
    foreach ((array)($report['renderCheck']['targets'] ?? []) as $target => $check) {
        if (($check['status'] ?? '') !== 'passed') $render[$target] = ['status' => $check['status'], 'messages' => array_slice((array)($check['messages'] ?? []), 0, 3)];
    }
    return ['status' => $report['status'], 'ready' => $report['status'] === 'ready', 'errors' => $errors, 'renderCheck' => ['status' => $report['renderCheck']['status'] ?? 'skipped', 'problems' => $render]];
}

function nibblyAgentToolValidate(string $name, array $args, array &$run, array $actor): array {
    if (empty($run['changesetId'])) throw new NibblyAgentOpException('There is nothing to validate yet.', 'Stage at least one operation first.');
    $report = nibblyAgentChangesetFinalize($run['changesetId']);
    if (($report['renderCheck']['status'] ?? '') === 'pending') {
        throw new NibblyAgentToolDeferred('The render check continues in the next step.');
    }
    return nibblyAgentFinalizeSummary($report);
}

function nibblyAgentToolAskUser(string $name, array $args, array &$run, array $actor): array {
    $run['question'] = trim((string)$args['question']);
    $run['end'] = 'needs_input';
    return ['ok' => true, 'note' => 'The question was sent to the user. Your work stops here until they answer.'];
}

function nibblyAgentToolPresent(string $name, array $args, array &$run, array $actor): array {
    if (empty($run['changesetId'])) throw new NibblyAgentOpException('There is nothing to present.', 'Stage the changes first, or answer the user in plain text.');
    $report = nibblyAgentChangesetFinalize($run['changesetId']);
    if (($report['renderCheck']['status'] ?? '') === 'pending') throw new NibblyAgentToolDeferred('The render check continues in the next step.');
    if ($report['status'] !== 'ready') {
        return nibblyAgentFinalizeSummary($report) + ['error' => 'The change set is not ready to present.', 'hint' => 'Fix the listed problems, then call validate_changeset again.'];
    }
    $run['summary'] = trim((string)$args['summary']);
    $run['openQuestions'] = array_values(array_map('strval', (array)($args['openQuestions'] ?? [])));
    $run['end'] = 'awaiting_review';
    return ['ok' => true, 'note' => 'The change set is with the user for review.'];
}
