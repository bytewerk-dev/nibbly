<?php
/**
 * Render scan (agent phase P2): learn which fields and lists a page really offers
 * by rendering it as an editor and reading the data-* attributes of the HTML.
 *
 * Rendering never writes: scan mode (see content-loader.php) turns the editable
 * helpers into editor markup without auto-writing missing fields, without a session
 * and without analytics. Standard pages are rendered with renderAllSections() and can
 * be scanned repeatedly. Custom templates are real PHP pages (header, footer, their own
 * code), so they are rendered at most once per PHP process, in a sandbox:
 * superglobals are swapped, output and warnings are captured, headers and the response
 * code are restored, and a template that calls exit() is recorded instead of crashing
 * the caller unnoticed.
 */

require_once dirname(__DIR__, 2) . '/page-path.php';

if (!class_exists('NibblyAgentScanDeferred', false)) {
    /** A second template render in the same process: continue in the next step request. */
    class NibblyAgentScanDeferred extends RuntimeException {
    }
}

function nibblyAgentSiteRoot(): string {
    return dirname(__DIR__, 3);
}

// ---------------------------------------------------------------------------
// HTML -> structure
// ---------------------------------------------------------------------------

/**
 * Read the editable structure out of rendered editor markup.
 *
 * @return array{fields:array<string,array>,lists:array<string,array>,groups:array<string,array>,binds:array<string,string>,sections:array}
 *         fields: path => {path,type,page,format?,altPath?,hidden,value,shared}
 *         lists: key => {key,page,defaults,count,indexes,hidden,item}
 */
function nibblyAgentScanHtml(string $html, string $page): array {
    $result = ['fields' => [], 'lists' => [], 'groups' => [], 'binds' => [], 'sections' => []];
    if (trim($html) === '') {
        return $result;
    }
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    $innerHtml = static function (DOMElement $element) use ($document): string {
        $out = '';
        foreach ($element->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }
        return $out;
    };

    foreach ($xpath->query('//*[@data-field and @data-page]') as $element) {
        /** @var DOMElement $element */
        $path = trim($element->getAttribute('data-field'));
        $owner = trim($element->getAttribute('data-page'));
        if ($path === '' || $owner === '') continue;
        $classes = ' ' . $element->getAttribute('class') . ' ';
        $field = ['path' => $path, 'page' => $owner, 'hidden' => $element->getAttribute('data-hidden') === 'true', 'shared' => $owner !== $page];

        if ($element->tagName === 'a' && $element->hasAttribute('data-editable-link')) {
            $field['type'] = 'link';
            $field['value'] = ['text' => trim($element->textContent), 'href' => $element->getAttribute('href')];
        } elseif ($element->tagName === 'img' && $element->hasAttribute('data-editable-image')) {
            $field['type'] = 'image';
            $field['value'] = ['src' => $element->getAttribute('src'), 'alt' => $element->getAttribute('alt')];
            if ($element->getAttribute('data-image-format') === 'split' && $element->getAttribute('data-alt-field') !== '') {
                $field['format'] = 'split';
                $field['altPath'] = trim($element->getAttribute('data-alt-field'));
            }
        } elseif ($element->hasAttribute('data-editable-icon')) {
            $field['type'] = 'icon';
            $field['value'] = $element->getAttribute('data-icon');
        } elseif (str_contains($classes, ' editable-field-html ')) {
            $field['type'] = 'html';
            $field['value'] = trim($innerHtml($element));
        } elseif (str_contains($classes, ' editable-field ')) {
            $field['type'] = 'text';
            $field['value'] = trim($element->textContent);
        } else {
            continue;
        }
        // The first occurrence wins; a path rendered twice is still one field.
        $result['fields'][$owner . '|' . $path] ??= $field;
    }
    // Key by path for the page itself, keep foreign pages separate.
    $keyed = [];
    foreach ($result['fields'] as $compound => $field) {
        $keyed[$field['shared'] ? $compound : $field['path']] = $field;
    }
    $result['fields'] = $keyed;

    foreach ($xpath->query('//*[@data-editable-list]') as $element) {
        $key = trim($element->getAttribute('data-list-key'));
        if ($key === '') continue;
        $defaults = json_decode($element->getAttribute('data-list-defaults'), true);
        $result['lists'][$key] ??= [
            'key' => $key,
            'page' => trim($element->getAttribute('data-list-page')),
            'defaults' => is_array($defaults) ? $defaults : [],
            'count' => 0,
            'indexes' => [],
            'hidden' => [],
        ];
    }
    foreach ($xpath->query('//*[@data-list-index and @data-list-key]') as $element) {
        $key = trim($element->getAttribute('data-list-key'));
        $index = (int)$element->getAttribute('data-list-index');
        if (!isset($result['lists'][$key])) {
            $result['lists'][$key] = ['key' => $key, 'page' => trim($element->getAttribute('data-list-page')), 'defaults' => [], 'count' => 0, 'indexes' => [], 'hidden' => []];
        }
        if (!in_array($index, $result['lists'][$key]['indexes'], true)) {
            $result['lists'][$key]['indexes'][] = $index;
            if ($element->getAttribute('data-hidden') === 'true') $result['lists'][$key]['hidden'][] = $index;
        }
    }
    foreach ($result['lists'] as $key => &$list) {
        sort($list['indexes']);
        $list['count'] = count($list['indexes']);
    }
    unset($list);

    foreach ($xpath->query('//*[@data-editable-group and @data-group]') as $element) {
        $group = trim($element->getAttribute('data-group'));
        $schema = json_decode($element->getAttribute('data-group-schema'), true);
        if ($group !== '' && is_array($schema)) {
            $result['groups'][$group] ??= $schema;
        }
    }
    foreach ($xpath->query('//*[@data-nb-bind-field]') as $element) {
        $path = trim($element->getAttribute('data-nb-bind-field'));
        if ($path !== '') $result['binds'][$path] = $element->getAttribute('data-nb-bind') ?: 'text';
    }
    foreach ($xpath->query('//*[@data-section-index]') as $element) {
        $result['sections'][(int)$element->getAttribute('data-section-index')] = [
            'id' => $element->getAttribute('data-section-id'),
            'type' => $element->getAttribute('data-section-type'),
            'hidden' => $element->getAttribute('data-hidden') === 'true',
        ];
    }
    ksort($result['sections']);
    return $result;
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/**
 * Render a standard (sections) page as an editor. Repeatable.
 *
 * @return array{html:string,warnings:array,deps:array,exception:?string}
 */
function nibblyAgentRenderStandard(string $contentKey): array {
    require_once dirname(__DIR__, 2) . '/content-loader.php';
    $warnings = [];
    nibblyContentScanBegin();
    $handler = nibblyAgentCaptureWarnings($warnings);
    try {
        $html = renderAllSections($contentKey);
        $exception = null;
    } catch (Throwable $error) {
        $html = '';
        $exception = get_class($error) . ': ' . $error->getMessage();
    } finally {
        restore_error_handler();
        nibblyContentScanEnd();
    }
    return ['html' => $html, 'warnings' => $warnings, 'deps' => nibblyAgentStandardDeps(), 'exception' => $exception];
}

function nibblyAgentStandardDeps(): array {
    $root = nibblyAgentSiteRoot() . '/includes';
    return array_merge(
        [$root . '/content-loader.php', $root . '/block-types.php', $root . '/html-sanitizer.php', $root . '/ai-labels.php'],
        glob($root . '/block-renderers/*.php') ?: []
    );
}

/** Collect PHP warnings/notices instead of printing them. Returns the previous handler. */
function nibblyAgentCaptureWarnings(array &$warnings) {
    return set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$warnings): bool {
        if (!(error_reporting() & $level)) return true;
        if (count($warnings) < 20) {
            $warnings[] = nibblyAgentRelativePath($file) . ':' . $line . ' ' . $message;
        }
        return true;
    });
}

function nibblyAgentRelativePath(string $file): string {
    $root = rtrim(nibblyAgentSiteRoot(), '/') . '/';
    return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
}

/** Path of the PHP template behind a content key, or '' when the page has none. */
function nibblyAgentTemplateFor(string $contentKey): string {
    $parsed = nibblyPageParseContentKey($contentKey);
    if ($parsed === null) return '';
    $root = nibblyAgentSiteRoot();
    $candidates = [nibblyPageTemplatePath($root, $parsed['lang'], $parsed['path'])];
    if ($parsed['path'] === 'home') {
        array_unshift($candidates, $root . '/' . $parsed['lang'] . '/index.php');
    }
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) return $candidate;
    }
    return '';
}

/**
 * Render a custom template page as an editor, at most once per PHP process.
 *
 * @param array $options onExit: callable(string $reason) run from the shutdown handler when
 *                       the template terminates the request (exit/die/fatal); the caller
 *                       can then answer the client. The buffer is discarded first.
 * @return array{html:string,warnings:array,deps:array,exception:?string}
 * @throws NibblyAgentScanDeferred when a template was already rendered by this process
 */
function nibblyAgentRenderTemplateOnce(string $templatePath, string $contentKey, array $options = []): array {
    static $rendered = false;
    if ($rendered) {
        throw new NibblyAgentScanDeferred('Only one custom template can be rendered per request.');
    }
    $real = realpath($templatePath);
    $root = realpath(nibblyAgentSiteRoot());
    if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || pathinfo($real, PATHINFO_EXTENSION) !== 'php') {
        throw new InvalidArgumentException('Template path is outside the site.');
    }
    $rendered = true;
    require_once dirname(__DIR__, 2) . '/content-loader.php';

    $parsed = nibblyPageParseContentKey($contentKey) ?? ['lang' => 'en', 'path' => 'home'];
    $savedGlobals = ['_GET' => $_GET, '_POST' => $_POST, '_FILES' => $_FILES, '_COOKIE' => $_COOKIE ?? []];
    $savedServer = [];
    foreach (['REQUEST_METHOD', 'REQUEST_URI', 'SCRIPT_NAME', 'PHP_SELF', 'QUERY_STRING', 'CONTENT_TYPE'] as $key) {
        $savedServer[$key] = $_SERVER[$key] ?? null;
    }
    $publicPath = '/' . $parsed['lang'] . '/' . ($parsed['path'] === 'home' ? '' : $parsed['path']);
    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $publicPath;
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $publicPath;
    $_SERVER['QUERY_STRING'] = '';
    unset($_SERVER['CONTENT_TYPE']);

    $before = get_included_files();
    $headersBefore = array_map(static fn($line) => strtolower(explode(':', $line, 2)[0]), headers_list());
    $statusBefore = http_response_code();
    $cwd = getcwd();
    $bufferLevel = ob_get_level();
    $warnings = [];
    $state = ['finished' => false];

    // exit()/die()/fatal errors inside the template skip everything below; this handler
    // is the only code that still runs, so it records what happened.
    register_shutdown_function(static function () use (&$state, $bufferLevel, $real, $options): void {
        if ($state['finished']) return;
        $fatal = error_get_last();
        $reason = $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)
            ? 'fatal: ' . $fatal['message']
            : 'the template ended the request (exit/die)';
        while (ob_get_level() > $bufferLevel) @ob_end_clean();
        foreach (array_diff(array_map(static fn($line) => strtolower(explode(':', $line, 2)[0]), headers_list()), $state['headersBefore'] ?? []) as $name) {
            header_remove($name);
        }
        if (isset($state['status']) && $state['status']) http_response_code($state['status']);
        nibblyContentScanEnd();
        if (function_exists('nibblyAgentRecordScanFailure')) nibblyAgentRecordScanFailure($real, $reason);
        if (is_callable($options['onExit'] ?? null)) $options['onExit']($reason);
    });
    $state['headersBefore'] = $headersBefore;
    $state['status'] = $statusBefore;

    $html = '';
    $exception = null;
    ob_start();
    nibblyContentScanBegin();
    nibblyAgentCaptureWarnings($warnings);
    try {
        chdir(dirname($real)); // templates include '../includes/header.php' relative to their folder
        (static function (string $__template): void {
            include $__template;
        })($real);
        while (ob_get_level() > $bufferLevel + 1) ob_end_flush();
        $html = (string)ob_get_clean();
    } catch (Throwable $error) {
        while (ob_get_level() > $bufferLevel) @ob_end_clean();
        $exception = get_class($error) . ': ' . $error->getMessage();
    } finally {
        restore_error_handler();
        nibblyContentScanEnd();
        if ($cwd !== false) chdir($cwd);
        foreach (array_diff(array_map(static fn($line) => strtolower(explode(':', $line, 2)[0]), headers_list()), $headersBefore) as $name) {
            header_remove($name);
        }
        if ($statusBefore) http_response_code($statusBefore);
        foreach ($savedGlobals as $name => $value) $GLOBALS[$name] = $value;
        foreach ($savedServer as $key => $value) {
            if ($value === null) unset($_SERVER[$key]); else $_SERVER[$key] = $value;
        }
        $state['finished'] = true;
    }

    $deps = array_values(array_unique(array_merge([$real], array_diff(get_included_files(), $before), nibblyAgentStandardDeps(), glob(nibblyAgentSiteRoot() . '/includes/*.php') ?: [])));
    return ['html' => $html, 'warnings' => $warnings, 'deps' => $deps, 'exception' => $exception];
}
