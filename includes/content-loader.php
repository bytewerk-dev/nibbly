<?php
/**
 * Content Loader - Loads content from JSON files
 */

require_once __DIR__ . '/../admin/lang/i18n.php';
require_once __DIR__ . '/access-guard.php';

if (!defined('CONTENT_BASE_PATH')) {
    define('CONTENT_BASE_PATH', __DIR__ . '/../content/pages/');
}

/**
 * Allowed HTML tags for content areas (XSS protection)
 */
function sanitizeHtml($html) {
    $allowedTags = '<p><br><strong><b><em><i><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><span><div>';

    $clean = strip_tags($html, $allowedTags);

    // Remove dangerous attributes (onclick, onerror, etc.)
    $clean = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);
    $clean = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $clean);
    // Block dangerous URI schemes in href/src (javascript:, data:, vbscript:)
    $clean = preg_replace('/href\s*=\s*["\']?\s*(javascript|data|vbscript)\s*:[^"\'>\s]*/i', 'href="#"', $clean);
    $clean = preg_replace('/src\s*=\s*["\']?\s*(javascript|data|vbscript)\s*:[^"\'>\s]*/i', 'src=""', $clean);

    return $clean;
}

/**
 * Check if admin is logged in
 */
function isAdminLoggedIn() {
    return nibblyAccessIsLoggedIn();
}

/**
 * Load content from JSON file
 * @param string $page Page name (e.g. 'de_beispiel')
 * @return array Content array or empty array if not found
 */
function loadContent($page) {
    $filepath = CONTENT_BASE_PATH . $page . '.json';

    if (!file_exists($filepath)) {
        return ['sections' => []];
    }

    $content = json_decode(file_get_contents($filepath), true);
    return $content ?: ['sections' => []];
}

/**
 * Returns the section type display name
 */
function getBlockTypes() {
    static $blockTypes = null;
    if ($blockTypes === null) {
        $blockTypes = require __DIR__ . '/block-types.php';
    }
    return $blockTypes;
}

function getSectionTypeName($type) {
    // Legacy alias
    if ($type === 'project') return t('block.card');

    $blockTypes = getBlockTypes();
    return $blockTypes[$type]['label'] ?? $type;
}

/**
 * Renders a section based on its type
 * @param array $section Section data
 * @param int $index Section index
 * @param bool $editable Make editable
 * @return string HTML output
 */
function renderSection($section, $index = 0, $editable = false, $page = '') {
    $html = '';
    $innerHtml = '';

    // Resolve legacy type aliases
    $type = $section['type'];
    if ($type === 'project') $type = 'card';

    // Look up renderer file from registry
    $blockTypes = getBlockTypes();
    $rendererFile = __DIR__ . '/block-renderers/' . $type . '.php';

    if (isset($blockTypes[$type]) && file_exists($rendererFile)) {
        $innerHtml = (function() use ($section, $editable, $rendererFile, $page, $index) {
            return require $rendererFile;
        })();
    }

    $isHiddenSection = !empty($section['hidden']);

    // If editable, wrap in container
    if ($editable && !empty($innerHtml)) {
        $sectionId = htmlspecialchars($section['id'] ?? 'section_' . $index);
        $sectionType = htmlspecialchars($section['type']);
        $typeName = getSectionTypeName($section['type']);
        $hiddenAttr = $isHiddenSection ? ' data-hidden="true"' : '';

        $html .= '<div class="editable-section" data-section-index="' . $index . '" data-section-type="' . $sectionType . '" data-section-id="' . $sectionId . '"' . $hiddenAttr . '>' . "\n";
        $html .= '<div class="editable-overlay"><span class="editable-icon">&#9998; ' . $typeName . '</span></div>' . "\n";
        $html .= $innerHtml;
        $html .= '</div>' . "\n";
    } elseif ($isHiddenSection) {
        // Hidden sections produce no output for visitors
        $html = '';
    } else {
        $html = $innerHtml;
    }

    return $html;
}

/**
 * Renders all sections of a page
 * @param string $page Page name
 * @param bool $staggerCards Enable stagger animation on card grids
 * @return string HTML output of all sections
 */
function renderAllSections($page, $staggerCards = false) {
    $content = loadContent($page);
    $html = '';
    $isEditable = isAdminLoggedIn();

    if ($isEditable) {
        $html .= '<div class="editable-content-area" data-content-page="' . htmlspecialchars($page) . '">' . "\n";
    }

    if (!empty($content['sections'])) {
        $inCardGrid = false;
        $sectionCount = count($content['sections']);

        foreach ($content['sections'] as $index => $section) {
            $isCard = in_array($section['type'] ?? '', ['card', 'project']);
            $nextIsCard = isset($content['sections'][$index + 1]) &&
                          in_array($content['sections'][$index + 1]['type'] ?? '', ['card', 'project']);

            if ($isCard && !$inCardGrid) {
                $gridClass = 'cards-grid' . ($staggerCards ? ' stagger-reveal' : '');
                $html .= '<div class="' . $gridClass . '">' . "\n";
                $inCardGrid = true;
            }

            $html .= renderSection($section, $index, $isEditable, $page);

            if ($inCardGrid && (!$nextIsCard || $index === $sectionCount - 1)) {
                $html .= '</div>' . "\n";
                $inCardGrid = false;
            }
        }
    }

    if ($isEditable) {
        $html .= '</div>' . "\n";
    }

    return $html;
}

/**
 * Gets a specific value from content
 * @param string $page Page name
 * @param string $key Key (e.g. 'title')
 * @return mixed Value or null
 */
function getContentValue($page, $key) {
    $content = loadContent($page);
    return $content[$key] ?? null;
}

// ============================================================
// EDITABLE FIELDS FOR CUSTOM LAYOUTS
// ============================================================

/**
 * Navigate a nested array using dot notation.
 * Example: getNestedValue($data, 'hero.title') returns $data['hero']['title']
 */
function getNestedValue($data, $dotKey) {
    $keys = explode('.', $dotKey);
    $current = $data;
    foreach ($keys as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return null;
        }
        $current = $current[$key];
    }
    return $current;
}

/**
 * Load content with per-request caching.
 * Prevents re-reading JSON on every editableText() call.
 */
function &loadContentCached($page) {
    static $cache = [];
    if (!isset($cache[$page])) {
        $cache[$page] = loadContent($page);
    }
    return $cache[$page];
}

/**
 * Set a nested value using dot notation for auto-generation.
 */
function setNestedValue(&$data, $dotKey, $value) {
    if (!is_array($data)) $data = [];
    $keys = explode('.', $dotKey);
    $current = &$data;
    foreach ($keys as $i => $key) {
        if ($i === count($keys) - 1) {
            $current[$key] = $value;
        } else {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
    }
}

/**
 * Track fields that were auto-generated during this request.
 * Returns the list when called without arguments.
 */
function autoGeneratedFields($page = null, $fieldKey = null) {
    static $fields = [];
    if ($page === null) return $fields;
    $fields[] = ['page' => $page, 'field' => $fieldKey];
}

/**
 * Auto-generate a missing field in the JSON file during admin browsing.
 * When an editable function encounters a key that doesn't exist in the JSON,
 * this writes the fallback value to disk so the Content Editor can see it.
 * Tracked via autoGeneratedFields() and surfaced as a toast notification.
 */
function autoGenerateContentField($page, $fieldKey, $value) {
    if (empty($page) || empty($fieldKey)) return;

    autoGeneratedFields($page, $fieldKey);

    $cache = &loadContentCached($page);

    if (!is_array($cache) || empty($cache['page'])) {
        $lang = substr($page, 0, 2);
        $cache['page'] = $page;
        $cache['lang'] = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
    }

    setNestedValue($cache, $fieldKey, $value);
    $cache['lastModified'] = date('c');

    $filepath = CONTENT_BASE_PATH . $page . '.json';
    file_put_contents($filepath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Check if a field is marked as hidden via a sibling "__hidden" key.
 * E.g. for fieldKey "header.title", checks "header.title__hidden".
 */
function isFieldHidden($data, $fieldKey) {
    return getNestedValue($data, $fieldKey . '__hidden') === true;
}

/**
 * Render an editable plain-text field for custom layouts.
 * When admin is logged in: wraps text in a <span> with data attributes.
 * For visitors: outputs plain escaped text (or empty string if hidden).
 * If the key is missing from JSON and an admin is browsing, the $default
 * value is auto-written to the JSON file (see autoGenerateContentField).
 *
 * @param string $page     JSON page name (e.g. 'en_home')
 * @param string $fieldKey Dot-notation key (e.g. 'hero.title')
 * @param string $default  Fallback text — becomes initial JSON value on auto-write
 * @return string HTML output
 */
function editableText($page, $fieldKey, $default = '') {
    $data = loadContentCached($page);
    $value = getNestedValue($data, $fieldKey);
    if ($value === null) {
        $value = $default;
        if (isAdminLoggedIn()) {
            autoGenerateContentField($page, $fieldKey, $value);
        }
    }

    $hidden = isFieldHidden($data, $fieldKey);

    // Decode HTML entities first (e.g. &rarr; → →), then escape for safe output.
    // This prevents double-encoding when content contains named entities.
    $safeValue = htmlspecialchars(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if (isAdminLoggedIn()) {
        $hiddenAttr = $hidden ? ' data-hidden="true"' : '';
        return '<span class="editable-field" data-page="' . htmlspecialchars($page) . '" data-field="' . htmlspecialchars($fieldKey) . '"' . $hiddenAttr . '>'
            . $safeValue
            . '</span>';
    }

    // Hidden fields are not rendered for visitors
    if ($hidden) return '';
    return $safeValue;
}

/**
 * Render an editable rich-text (HTML) field for custom layouts.
 * When admin is logged in: wraps content in a <div> with data attributes.
 * For visitors: outputs sanitized HTML.
 * If the key is missing from JSON and an admin is browsing, the $default
 * value is auto-written to the JSON file (see autoGenerateContentField).
 *
 * @param string $page     JSON page name (e.g. 'en_home')
 * @param string $fieldKey Dot-notation key (e.g. 'hero.subtitle')
 * @param string $default  Fallback HTML — becomes initial JSON value on auto-write
 * @return string HTML output
 */
function editableHtml($page, $fieldKey, $default = '') {
    $data = loadContentCached($page);
    $value = getNestedValue($data, $fieldKey);
    if ($value === null) {
        $value = $default;
        if (isAdminLoggedIn()) {
            autoGenerateContentField($page, $fieldKey, $value);
        }
    }

    $hidden = isFieldHidden($data, $fieldKey);

    if (isAdminLoggedIn()) {
        $hiddenAttr = $hidden ? ' data-hidden="true"' : '';
        return '<div class="editable-field editable-field-html" data-page="' . htmlspecialchars($page) . '" data-field="' . htmlspecialchars($fieldKey) . '"' . $hiddenAttr . '>'
            . sanitizeHtml($value)
            . '</div>';
    }

    if ($hidden) return '';
    return sanitizeHtml($value);
}

/**
 * Render an editable link/button for custom layouts.
 * Stores both text and href in JSON (fieldKey.text and fieldKey.href).
 * Admin: adds editable-field-link class + data attributes.
 * Visitor: outputs a normal <a> tag.
 * If the key is missing from JSON and an admin is browsing, {text, href}
 * is auto-written to the JSON file (see autoGenerateContentField).
 *
 * @param string $page        JSON page name
 * @param string $fieldKey    Dot-notation key (e.g. 'hero.cta1')
 * @param string $defaultText Fallback link text — becomes initial JSON value
 * @param string $defaultHref Fallback URL — becomes initial JSON value
 * @param string $class       CSS classes for the <a> tag
 * @param string $attrs       Extra HTML attributes (e.g. 'target="_blank" rel="noopener"')
 * @return string HTML output
 */
function editableLink($page, $fieldKey, $defaultText = '', $defaultHref = '#', $class = '', $attrs = '') {
    $data = loadContentCached($page);
    $linkData = getNestedValue($data, $fieldKey);

    if ($linkData === null) {
        $linkData = ['text' => $defaultText, 'href' => $defaultHref];
        if (isAdminLoggedIn()) {
            autoGenerateContentField($page, $fieldKey, $linkData);
        }
    }

    $text = $defaultText;
    $href = $defaultHref;
    $target = '';
    $download = false;
    if (is_array($linkData)) {
        $text = $linkData['text'] ?? $defaultText;
        $href = $linkData['href'] ?? $defaultHref;
        $target = $linkData['target'] ?? '';
        $download = !empty($linkData['download']);
    } elseif (is_string($linkData)) {
        $text = $linkData;
    }

    $hidden = isFieldHidden($data, $fieldKey);
    $classAttr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';
    $extraAttrs = $attrs ? ' ' . $attrs : '';
    $targetAttr = '';
    if ($target === '_blank') {
        // noopener hardens against reverse tabnabbing on new-tab links
        $targetAttr = ' target="_blank" rel="noopener"';
    }
    $downloadAttr = $download ? ' download' : '';

    // Decode HTML entities first (e.g. &rarr; → →), then escape for safe output.
    // This prevents double-encoding: &rarr; would become &amp;rarr; without decode.
    $safeText = htmlspecialchars(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if (isAdminLoggedIn()) {
        $hiddenAttr = $hidden ? ' data-hidden="true"' : '';
        return '<a href="' . htmlspecialchars($href) . '"' . $classAttr . $extraAttrs . $targetAttr . $downloadAttr
            . ' data-editable-link data-page="' . htmlspecialchars($page) . '" data-field="' . htmlspecialchars($fieldKey) . '"' . $hiddenAttr . '>'
            . $safeText . '</a>';
    }

    if ($hidden) return '';
    return '<a href="' . htmlspecialchars($href) . '"' . $classAttr . $extraAttrs . $targetAttr . $downloadAttr . '>'
        . $safeText . '</a>';
}

/**
 * Render an editable image for custom layouts.
 * Stores src and alt in JSON (fieldKey.src and fieldKey.alt).
 * Admin: adds editable-field-image class + data attributes.
 * Visitor: outputs a normal <img> tag.
 * If the key is missing from JSON and an admin is browsing, {src, alt}
 * is auto-written to the JSON file (see autoGenerateContentField).
 *
 * @param string $page       JSON page name
 * @param string $fieldKey   Dot-notation key (e.g. 'hero.image')
 * @param string $defaultSrc Fallback image path — becomes initial JSON value
 * @param string $defaultAlt Fallback alt text — becomes initial JSON value
 * @param string $class      CSS classes for the <img> tag
 * @return string HTML output
 */
function editableImage($page, $fieldKey, $defaultSrc = '', $defaultAlt = '', $class = '') {
    $data = loadContentCached($page);
    $imgData = getNestedValue($data, $fieldKey);

    if ($imgData === null) {
        $imgData = ['src' => $defaultSrc, 'alt' => $defaultAlt];
        if (isAdminLoggedIn()) {
            autoGenerateContentField($page, $fieldKey, $imgData);
        }
    }

    $src = $defaultSrc;
    $alt = $defaultAlt;
    if (is_array($imgData)) {
        $src = $imgData['src'] ?? $defaultSrc;
        $alt = $imgData['alt'] ?? $defaultAlt;
    } elseif (is_string($imgData)) {
        $src = $imgData;
    }

    $hidden = isFieldHidden($data, $fieldKey);
    $classAttr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';

    if (isAdminLoggedIn()) {
        $hiddenAttr = $hidden ? ' data-hidden="true"' : '';
        return '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '"' . $classAttr
            . ' data-editable-image data-page="' . htmlspecialchars($page) . '" data-field="' . htmlspecialchars($fieldKey) . '"' . $hiddenAttr . '>';
    }

    if ($hidden) return '';
    return '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '"' . $classAttr . '>';
}

/**
 * Render an editable image whose src and alt live at two separate
 * dot-notation keys (not nested inside a single {src, alt} object).
 * Used by block renderers where the JSON schema already stores src
 * and alt as sibling fields (e.g. image block: sections.N.src, sections.N.alt).
 * Does NOT auto-write on missing keys — schema is expected to exist.
 *
 * @param string $page        JSON page name
 * @param string $srcFieldKey Dot-notation key for src (e.g. 'sections.3.src')
 * @param string $altFieldKey Dot-notation key for alt (e.g. 'sections.3.alt')
 * @param string $defaultSrc  Fallback image path
 * @param string $defaultAlt  Fallback alt text
 * @param string $class       CSS classes for the <img> tag
 * @return string HTML output
 */
function editableImageSplit($page, $srcFieldKey, $altFieldKey, $defaultSrc = '', $defaultAlt = '', $class = '') {
    $data = loadContentCached($page);
    $src = getNestedValue($data, $srcFieldKey);
    $alt = getNestedValue($data, $altFieldKey);
    if ($src === null) $src = $defaultSrc;
    if ($alt === null) $alt = $defaultAlt;

    $hidden = isFieldHidden($data, $srcFieldKey);
    $classAttr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';

    if (isAdminLoggedIn()) {
        $hiddenAttr = $hidden ? ' data-hidden="true"' : '';
        return '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '"' . $classAttr
            . ' data-editable-image data-image-format="split"'
            . ' data-page="' . htmlspecialchars($page) . '"'
            . ' data-field="' . htmlspecialchars($srcFieldKey) . '"'
            . ' data-alt-field="' . htmlspecialchars($altFieldKey) . '"'
            . $hiddenAttr . '>';
    }

    if ($hidden) return '';
    return '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '"' . $classAttr . '>';
}

/**
 * Render an editable icon token for custom layouts.
 * The value is intentionally stored as plain text (e.g. "heart-pulse",
 * "check", "icon-service") so templates can map it to their own icon system.
 */
function editableIcon($page, $fieldKey, $default = '', $class = '') {
    $data = loadContentCached($page);
    $value = getNestedValue($data, $fieldKey);
    if ($value === null) {
        $value = $default;
        if (isAdminLoggedIn()) {
            autoGenerateContentField($page, $fieldKey, $value);
        }
    }

    $hidden = isFieldHidden($data, $fieldKey);
    $classAttr = trim('editable-field editable-field-icon ' . $class);
    $safeValue = htmlspecialchars((string)$value);

    if (isAdminLoggedIn()) {
        $hiddenAttr = $hidden ? ' data-hidden="true"' : '';
        return '<span class="' . htmlspecialchars($classAttr) . '" data-editable-icon data-page="' . htmlspecialchars($page) . '" data-field="' . htmlspecialchars($fieldKey) . '" data-icon="' . $safeValue . '"' . $hiddenAttr . '>'
            . $safeValue
            . '</span>';
    }

    if ($hidden) return '';
    return '<span class="' . htmlspecialchars($class) . '" data-icon="' . $safeValue . '">' . $safeValue . '</span>';
}

// ============================================================
// EDITABLE GROUPS (Custom Layout Composite Editors)
// ============================================================

/**
 * Return HTML attributes for a custom editable group.
 * Use on an existing component wrapper when several editable fields should be
 * edited together in one modal (e.g. icon + title + text + link cards).
 *
 * @param string $page      JSON page name (e.g. 'en_home')
 * @param string $groupKey  Dot-notation base key (e.g. 'features.0')
 * @param array  $schema    ['label' => 'Feature', 'fields' => [...]]
 * @return string HTML attributes string or empty for visitors
 */
function editableGroupAttrs($page, $groupKey, $schema = []) {
    if (!isAdminLoggedIn()) return '';

    if (!nibblyGroupSchemaNeedsModal($schema)) {
        return '';
    }

    $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE);
    if ($schemaJson === false) $schemaJson = '{}';

    return ' data-editable-group'
        . ' data-page="' . htmlspecialchars($page) . '"'
        . ' data-group="' . htmlspecialchars($groupKey) . '"'
        . ' data-group-schema="' . htmlspecialchars($schemaJson, ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * Keep one-field plain-text groups inline-only. A group modal is worthwhile
 * for multiple related fields or any field with a specialized editor.
 */
function nibblyGroupSchemaNeedsModal($schema): bool {
    if (!is_array($schema)) return false;

    $fields = $schema['fields'] ?? [];
    if (!is_array($fields) || empty($fields)) return false;

    $fieldCount = 0;
    $hasSpecial = false;
    foreach ($fields as $field) {
        if (!is_array($field)) continue;
        $key = strtolower((string)($field['key'] ?? $field['path'] ?? ''));
        if ($key === '' || $key === 'hidden') continue;

        $fieldCount++;
        $type = strtolower((string)($field['type'] ?? 'text'));
        if (in_array($type, ['image', 'link', 'icon', 'date', 'datetime', 'select', 'checkbox', 'number'], true)) {
            $hasSpecial = true;
        }
        if (preg_match('/^(image|imagesrc|src|photo|portrait|logo|avatar|cover|thumbnail|link|href|url|cta|button|buttonlink|icon|iconid|symbol)$/', $key)) {
            $hasSpecial = true;
        }
    }

    return $fieldCount > 1 || $hasSpecial;
}

/**
 * Start an admin-only editable group wrapper. The function echoes directly so
 * templates can use `<?php editableGroupStart(...); ?>` without `echo`.
 */
function editableGroupStart($page, $groupKey, $schema = [], $attrs = '') {
    if (!isAdminLoggedIn()) return '';
    $extraAttrs = trim($attrs);
    echo '<div class="editable-group"' . editableGroupAttrs($page, $groupKey, $schema) . ($extraAttrs ? ' ' . $extraAttrs : '') . '>';
    return '';
}

/**
 * Close an editable group wrapper opened with editableGroupStart().
 */
function editableGroupEnd() {
    if (!isAdminLoggedIn()) return '';
    echo '</div>';
    return '';
}

/**
 * Infer a structured group-editor schema from a list item/default item shape.
 * This is intentionally conservative: simple one-field text items stay inline
 * only, while cards with multiple fields or media/link/icon/date fields get a
 * modal editor that keeps related fields together.
 */
function nibblyInferGroupSchemaFromDefaults($defaults, $label = 'Item') {
    if (!is_array($defaults)) return [];

    $fields = [];
    foreach ($defaults as $key => $value) {
        if (!is_string($key) || $key === '' || $key === 'hidden') continue;

        $lower = strtolower($key);
        $type = 'text';

        if (preg_match('/^(image|imagesrc|src|photo|portrait|logo|avatar|cover|thumbnail)$/', $lower)) {
            $type = 'image';
        } elseif (preg_match('/^(link|href|url|cta|button|buttonlink)$/', $lower)) {
            $type = 'link';
        } elseif (preg_match('/^(text|description|desc|intro|body|content|copy|summary)$/', $lower)) {
            $type = 'textarea';
        } elseif (preg_match('/^(icon|iconid|symbol)$/', $lower)) {
            $type = 'icon';
        } elseif (preg_match('/^(date|startdate|enddate|time|datetime)$/', $lower)) {
            $type = 'text';
        }

        if (is_array($value)) {
            if (array_key_exists('src', $value) || array_key_exists('alt', $value)) {
                $type = 'image';
            } elseif (array_key_exists('href', $value) || array_key_exists('text', $value)) {
                $type = 'link';
            }
        }

        $fields[] = [
            'key' => $key,
            'type' => $type,
            'label' => ucwords(str_replace(['_', '-'], ' ', $key)),
        ];
    }

    $hasSpecialField = false;
    foreach ($fields as $field) {
        if (in_array($field['type'], ['image', 'link', 'icon'], true)) {
            $hasSpecialField = true;
            break;
        }
    }

    if (count($fields) < 2 && !$hasSpecialField) {
        return [];
    }

    return [
        'label' => $label,
        'fields' => $fields,
    ];
}

/**
 * Return list-item attributes plus a structured group-editor schema when the
 * item shape warrants a modal. Use this for card-like repeaters so editors get
 * both list controls and one modal for related item fields.
 */
function editableListGroupItemAttrs($page, $listKey, $index, $schema = [], $defaults = [], $label = 'Item') {
    if (!isAdminLoggedIn()) return '';

    if (empty($schema) && !empty($defaults)) {
        $schema = nibblyInferGroupSchemaFromDefaults($defaults, $label);
    }

    $attrs = editableListItemAttrs($page, $listKey, $index);
    if (!empty($schema)) {
        $attrs .= editableGroupAttrs($page, $listKey . '.' . (int)$index, $schema);
    }

    return $attrs;
}

// ============================================================
// EDITABLE LISTS (Repeatable Items)
// ============================================================

/**
 * Return HTML attributes for an editable list container.
 * Add these to the element that wraps all list items (e.g. the grid div).
 * For visitors: returns empty string.
 *
 * @param string $page      JSON page name (e.g. 'en_home')
 * @param string $listKey   Dot-notation key to the items object (e.g. 'features.items')
 * @param array  $defaults  Default values for new items (e.g. ['title' => 'New', 'desc' => ''])
 * @return string HTML attributes string or empty
 */
function editableListAttrs($page, $listKey, $defaults = []) {
    if (!isAdminLoggedIn()) return '';

    return ' data-editable-list'
        . ' data-list-page="' . htmlspecialchars($page) . '"'
        . ' data-list-key="' . htmlspecialchars($listKey) . '"'
        . ' data-list-defaults="' . htmlspecialchars(json_encode($defaults, JSON_UNESCAPED_UNICODE)) . '"';
}

/**
 * Return HTML attributes for a single editable list item.
 * Add these to each repeating element (e.g. each card div).
 * For visitors: returns empty string.
 *
 * @param string $page      JSON page name
 * @param string $listKey   Dot-notation key to the items object
 * @param int    $index     Item index
 * @return string HTML attributes string or empty
 */
function editableListItemAttrs($page, $listKey, $index) {
    if (!isAdminLoggedIn()) return '';

    // Check if list item is hidden
    $data = loadContentCached($page);
    $items = getNestedValue($data, $listKey);
    $hidden = '';
    if (is_array($items) && isset($items[$index]) && is_array($items[$index]) && !empty($items[$index]['hidden'])) {
        $hidden = ' data-hidden="true"';
    }

    return ' data-list-page="' . htmlspecialchars($page) . '"'
        . ' data-list-key="' . htmlspecialchars($listKey) . '"'
        . ' data-list-index="' . (int)$index . '"'
        . $hidden;
}

/**
 * Get list items from JSON content, reading count dynamically.
 * Returns an indexed array of item data, sorted by numeric key.
 *
 * @param string $page     JSON page name
 * @param string $listKey  Dot-notation key to the items object (e.g. 'features.items')
 * @return array Indexed array of item data
 */
function editableListItems($page, $listKey) {
    $data = loadContentCached($page);
    $items = getNestedValue($data, $listKey);

    if (!is_array($items)) {
        return [];
    }

    // Sort by numeric key to ensure correct order
    ksort($items, SORT_NUMERIC);

    // For visitors, filter out hidden items
    if (!isAdminLoggedIn()) {
        $items = array_filter($items, function($item) {
            return !(is_array($item) && !empty($item['hidden']));
        });
        // Re-index to maintain sequential keys
        $items = array_values($items);
    }

    return $items;
}

/**
 * Render an editable paragraph list — a list of HTML blocks that admins can
 * add, remove, reorder, and edit inline (with the floating toolbar).
 *
 * Uses the existing editable list system internally. Each item is a single
 * editableHtml() field with key "{listKey}.{index}.content".
 *
 * @param string $page      Content page identifier (e.g. 'en_about')
 * @param string $listKey   Dot-notation key to the items object (e.g. 'intro.paragraphs')
 * @param array  $defaults  Default values for new items (default: ['content' => ''])
 * @return string HTML output
 */
function editableTextList($page, $listKey, $defaults = ['content' => '']) {
    $items = editableListItems($page, $listKey);
    $output = '';

    if (isAdminLoggedIn()) {
        // Admin: wrap in list container with editing attributes
        $output .= '<div class="editable-text-list" ' . editableListAttrs($page, $listKey, $defaults) . '>';
        foreach ($items as $i => $item) {
            $content = is_array($item) ? ($item['content'] ?? '') : '';
            $output .= '<div class="editable-text-list-item" ' . editableListItemAttrs($page, $listKey, $i) . '>';
            $output .= editableHtml($page, "$listKey.$i.content", $content);
            $output .= '</div>';
        }
        $output .= '</div>';
    } else {
        // Visitors: output clean HTML content only
        foreach ($items as $item) {
            $content = is_array($item) ? ($item['content'] ?? '') : '';
            if ($content !== '') {
                $output .= $content;
            }
        }
    }

    return $output;
}

/**
 * Return the core fallback icon set.
 * Sites can override or extend this via content/settings/iconset.json.
 *
 * @return array<string,array{label:string,tags:array,svg:string}>
 */
function getDefaultIconSet() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $path = __DIR__ . '/default-iconset.json';
    if (is_file($path)) {
        $raw = json_decode(file_get_contents($path), true);
        $icons = normalizeIconSet($raw);
        if (!empty($icons)) {
            $cache = $icons;
            return $cache;
        }
    }

    $cache = getEmergencyDefaultIconSet();
    return $cache;
}

/**
 * Return a single hard-coded fallback icon for broken or missing icon files.
 * This keeps getIconSvg() safe even when both JSON icon sets are unavailable.
 *
 * @return array<string,array{label:string,tags:array,svg:string}>
 */
function getEmergencyDefaultIconSet() {
    return [
        'default' => [
            'label' => 'Default',
            'tags' => ['fallback'],
            'svg' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>',
            'viewBox' => '0 0 24 24',
        ],
    ];
}

/**
 * Path to the site-owned icon set. This lives below content/ so it survives
 * Nibbly core updates.
 */
function getIconSetPath() {
    $contentDir = defined('SETTINGS_PATH')
        ? dirname(SETTINGS_PATH)
        : (__DIR__ . '/../content');
    return $contentDir . '/settings/iconset.json';
}

/**
 * Very small SVG fragment sanitizer for icon inner markup.
 * Allows common SVG shape tags/attributes and strips scripts, event handlers,
 * external references, and full <svg> wrappers.
 */
function sanitizeIconSvgMarkup($svg) {
    $svg = trim((string)$svg);
    if ($svg === '') return '';
    $svg = preg_replace('#<\?xml[\s\S]*?\?>#i', '', $svg);
    $svg = preg_replace('#<!DOCTYPE[\s\S]*?>#i', '', $svg);
    $svg = preg_replace('#<\s*/?\s*svg\b[^>]*>#i', '', $svg);
    $svg = preg_replace('#<!--[\s\S]*?-->#', '', $svg);
    $svg = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $svg);
    $svg = preg_replace('#<\s*(metadata|desc|style|sodipodi:namedview)\b[^>]*>[\s\S]*?<\s*/\s*\1\s*>#is', '', $svg);
    $svg = preg_replace('#<\s*defs\b[^>]*>\s*(?:<\s*style\b[^>]*>[\s\S]*?<\s*/\s*style\s*>\s*)*<\s*/\s*defs\s*>#is', '', $svg);
    $svg = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/is', '', $svg);
    $svg = preg_replace('/\s+(href|xlink:href)\s*=\s*(["\'])\s*(?!#)[^"\']*\2/is', '', $svg);
    $svg = preg_replace('/\s+(class|style)\s*=\s*(["\']).*?\2/is', '', $svg);

    $allowedTags = 'path|circle|ellipse|line|polyline|polygon|rect|g|defs|clipPath|mask|linearGradient|radialGradient|stop|title';
    $svg = preg_replace_callback('#</?([a-zA-Z][a-zA-Z0-9:-]*)(\s[^>]*)?>#', function($m) use ($allowedTags) {
        return preg_match('/^(' . $allowedTags . ')$/i', $m[1]) ? $m[0] : '';
    }, $svg);
    $svg = preg_replace('/^\s*(?:\.[\w-]+\s*\{[^}]*\}\s*)+/m', '', $svg);

    $previous = null;
    while ($previous !== $svg) {
        $previous = $svg;
        $svg = preg_replace('#<defs\b[^>]*>\s*</defs>#i', '', $svg);
        $svg = preg_replace('#<g\b[^>]*>\s*</g>#i', '', $svg);
        $svg = preg_replace('#<clipPath\b[^>]*>\s*</clipPath>#i', '', $svg);
        $svg = preg_replace('#<mask\b[^>]*>\s*</mask>#i', '', $svg);
    }

    return trim($svg);
}

/**
 * Normalize an SVG viewBox string. Falls back to the 24x24 icon grid used by
 * Nibbly's bundled icons.
 */
function normalizeIconViewBox($viewBox) {
    $viewBox = trim((string)$viewBox);
    if ($viewBox === '') {
        return '0 0 24 24';
    }

    $parts = preg_split('/[\s,]+/', $viewBox);
    if (count($parts) !== 4) {
        return '0 0 24 24';
    }

    $normalized = [];
    foreach ($parts as $part) {
        if (!is_numeric($part)) {
            return '0 0 24 24';
        }
        $normalized[] = rtrim(rtrim(sprintf('%.6F', (float)$part), '0'), '.');
    }

    if ((float)$normalized[2] <= 0 || (float)$normalized[3] <= 0) {
        return '0 0 24 24';
    }

    return implode(' ', $normalized);
}

/**
 * Extract a viewBox from a full <svg> wrapper before the wrapper is stripped.
 */
function extractIconViewBoxFromSvg($svg) {
    if (!is_string($svg)) {
        return '';
    }
    if (preg_match('#<\s*svg\b[^>]*\sviewBox\s*=\s*(["\'])(.*?)\1#is', $svg, $m)) {
        return normalizeIconViewBox($m[2]);
    }
    if (preg_match('#<\s*svg\b[^>]*\swidth\s*=\s*(["\'])([0-9.]+)(?:px)?\1[^>]*\sheight\s*=\s*(["\'])([0-9.]+)(?:px)?\3#is', $svg, $m)) {
        return normalizeIconViewBox('0 0 ' . $m[2] . ' ' . $m[4]);
    }
    return '';
}

/**
 * Normalize icon definitions from either the rich format
 * { "key": {"label": "...", "svg": "...", "tags": []} }
 * or the legacy/simple format { "key": "<path .../>" }.
 */
function normalizeIconSet($raw) {
    if (!is_array($raw)) return [];

    $icons = [];
    foreach ($raw as $key => $value) {
        if ($key === '_deleted') continue;
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]+$/', $key)) continue;

        if (is_string($value)) {
            $label = ucwords(str_replace(['-', '_'], ' ', $key));
            $tags = [];
            $svg = $value;
            $viewBox = extractIconViewBoxFromSvg($svg);
            $style = '';
            $createdAt = '';
            $updatedAt = '';
        } elseif (is_array($value)) {
            $label = isset($value['label']) && is_string($value['label']) ? $value['label'] : ucwords(str_replace(['-', '_'], ' ', $key));
            $tags = isset($value['tags']) && is_array($value['tags']) ? array_values(array_filter($value['tags'], 'is_string')) : [];
            $svg = isset($value['svg']) && is_string($value['svg']) ? $value['svg'] : '';
            $viewBox = isset($value['viewBox']) && is_string($value['viewBox'])
                ? normalizeIconViewBox($value['viewBox'])
                : extractIconViewBoxFromSvg($svg);
            $style = isset($value['style']) && is_string($value['style']) ? $value['style'] : '';
            $createdAt = isset($value['createdAt']) && is_string($value['createdAt']) ? $value['createdAt'] : '';
            $updatedAt = isset($value['updatedAt']) && is_string($value['updatedAt']) ? $value['updatedAt'] : '';
        } else {
            continue;
        }

        $svg = sanitizeIconSvgMarkup($svg);
        if ($svg === '') continue;

        $icons[$key] = [
            'label' => $label,
            'tags' => $tags,
            'svg' => $svg,
            'viewBox' => $viewBox ?: '0 0 24 24',
            'style' => in_array($style ?? '', ['fill', 'stroke'], true) ? $style : 'stroke',
            'createdAt' => $createdAt ?? '',
            'updatedAt' => $updatedAt ?? '',
        ];
    }

    return $icons;
}

/**
 * Extract hidden core icon keys from a site icon set.
 *
 * @return array<int,string>
 */
function getDeletedIconKeys($raw) {
    if (!is_array($raw) || !isset($raw['_deleted']) || !is_array($raw['_deleted'])) {
        return [];
    }

    $deleted = [];
    foreach ($raw['_deleted'] as $key) {
        if (is_string($key) && preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
            $deleted[] = $key;
        }
    }
    return array_values(array_unique($deleted));
}

/**
 * Return available icon definitions, merging core defaults with the site-owned
 * content/settings/iconset.json file when present.
 */
function getAvailableIconDefinitions() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $icons = getDefaultIconSet();
    $path = getIconSetPath();

    if (is_file($path)) {
        $raw = json_decode(file_get_contents($path), true);
        foreach (getDeletedIconKeys($raw) as $deletedKey) {
            unset($icons[$deletedKey]);
        }
        $customIcons = normalizeIconSet($raw);
        if (!empty($customIcons)) {
            $icons = array_merge($icons, $customIcons);
        }
    }

    if (empty($icons['default'])) {
        $defaults = getEmergencyDefaultIconSet();
        $icons['default'] = $defaults['default'];
    }

    $cache = $icons;
    return $cache;
}

/**
 * Return available icon SVG path markup keyed by icon identifier.
 *
 * @return array<string,string>
 */
function getAvailableIcons() {
    $icons = [];
    foreach (getAvailableIconDefinitions() as $key => $definition) {
        $icons[$key] = $definition['svg'];
    }
    return $icons;
}

/**
 * Map an icon identifier to SVG path markup.
 * Used for feature cards and similar elements where icons are stored as IDs in JSON.
 *
 * @param string $iconId Icon identifier (e.g. 'database', 'edit', 'shield')
 * @return string SVG inner markup (paths, circles, etc.)
 */
function getIconSvg($iconId) {
    $icons = getAvailableIcons();

    return $icons[$iconId] ?? $icons['default'];
}

/**
 * Map an icon identifier to the full normalized icon definition.
 *
 * @param string $iconId Icon identifier
 * @return array{label:string,tags:array,svg:string,viewBox:string}
 */
function getIconDefinition($iconId) {
    $icons = getAvailableIconDefinitions();

    return $icons[$iconId] ?? $icons['default'];
}

/**
 * Render a complete SVG for an icon identifier, preserving custom viewBox
 * values while keeping the old getIconSvg() inner-markup helper available.
 */
function renderIconSvg($iconId, $attributes = '') {
    $icon = getIconDefinition($iconId);
    $viewBox = htmlspecialchars($icon['viewBox'] ?? '0 0 24 24', ENT_QUOTES, 'UTF-8');
    $attributes = trim((string)$attributes);
    $svg = $icon['svg'];
    if ($attributes === '') {
        if (($icon['style'] ?? 'stroke') === 'fill') {
            $attributes = 'fill="currentColor"';
            $svg = preg_replace('/\s+stroke\s*=\s*(["\'])currentColor\1/i', '', $svg);
        } else {
            $attributes = 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
        }
    }

    return '<svg viewBox="' . $viewBox . '" ' . $attributes . '>' . $svg . '</svg>';
}

// ============================================================
// COMPARISON TABLE
// ============================================================

/**
 * Comparison table column definitions.
 * Reads from comparison.columns in the page JSON, falls back to a generic default.
 */
function getComparisonColumns($page = null) {
    if ($page) {
        $data = loadContentCached($page);
        $cols = getNestedValue($data, 'comparison.columns');
        if (is_array($cols) && !empty($cols)) {
            return array_values($cols);
        }
    }
    // Generic fallback — two unnamed columns
    return [
        ['id' => 'col_a', 'name' => 'Option A', 'highlight' => true],
        ['id' => 'col_b', 'name' => 'Option B', 'highlight' => false],
    ];
}

/**
 * Render comparison table as HTML with editable fields.
 * Reads row data from page content JSON (comparison.rows).
 *
 * @param string $page Page name (e.g. 'en_showcase')
 * @return string HTML output
 */
function renderComparisonTable($page) {
    $columns = getComparisonColumns($page);
    // Load rows directly (not via editableListItems) to preserve original indices
    $data = loadContentCached($page);
    $rows = getNestedValue($data, 'comparison.rows');

    if (!is_array($rows) || empty($rows)) {
        return '<p>No comparison data available.</p>';
    }

    // Sort by numeric key
    ksort($rows, SORT_NUMERIC);

    $isAdmin = isAdminLoggedIn();
    $html = '<div class="comparison-table-wrap">';
    $html .= '<table class="comparison-table">';

    // Header row
    $html .= '<thead><tr>';
    $html .= '<th class="comparison-table__label-col"></th>';
    foreach ($columns as $col) {
        $highlightClass = !empty($col['highlight']) ? ' comparison-table__highlight' : '';
        $html .= '<th class="comparison-table__col' . $highlightClass . '">';
        $html .= htmlspecialchars($col['name']);
        $html .= '</th>';
    }
    $html .= '</tr></thead>';

    // Data rows — use original index $i so editableText paths match the JSON
    $html .= '<tbody>';
    foreach ($rows as $i => $row) {
        $isRowHidden = is_array($row) && !empty($row['hidden']);
        if (!$isAdmin && $isRowHidden) continue; // Skip hidden rows for visitors
        $hiddenAttr = ($isAdmin && $isRowHidden) ? ' data-hidden="true"' : '';
        $html .= '<tr data-comparison-row="' . (int)$i . '" data-row-page="' . htmlspecialchars($page) . '"' . $hiddenAttr . '>';
        $html .= '<td class="comparison-table__label">' . editableText($page, "comparison.rows.$i.label", 'Label') . '</td>';
        foreach ($columns as $col) {
            $colId = $col['id'];
            $value = $row[$colId] ?? '';
            $highlightClass = !empty($col['highlight']) ? ' comparison-table__highlight' : '';
            $cellClass = 'comparison-table__cell' . $highlightClass;

            // Determine cell display
            // For admins: editable text + toggle buttons (built via JS)
            // For visitors: yes/no render as icons, other values as text
            if ($isAdmin) {
                $cellType = ($value === 'yes' || $value === 'no') ? $value : 'text';
                $fieldKey = "comparison.rows.$i.$colId";
                $display = editableText($page, $fieldKey, $value);
                $html .= '<td class="' . $cellClass . '" data-cell-field="' . htmlspecialchars($fieldKey) . '" data-cell-page="' . htmlspecialchars($page) . '" data-cell-type="' . $cellType . '">' . $display . '</td>';
            } else {
                if ($value === 'yes') {
                    $display = '<span class="comparison-yes" title="Yes">&#10003;</span>';
                } elseif ($value === 'no') {
                    $display = '<span class="comparison-no" title="No">&#10005;</span>';
                } else {
                    $display = htmlspecialchars($value);
                }
                $html .= '<td class="' . $cellClass . '">' . $display . '</td>';
            }
        }
        $html .= '</tr>';
    }
    $html .= '</tbody>';

    $html .= '</table>';
    $html .= '</div>';

    return $html;
}

// ============================================================
// FAQ ACCORDION
// ============================================================

/**
 * Render FAQ accordion as HTML with editable fields.
 * Reads Q&A data from page content JSON (faq.entries).
 *
 * @param string $page Page name (e.g. 'en_showcase')
 * @return string HTML output
 */
function renderFaqAccordion($page) {
    $entries = editableListItems($page, 'faq.entries');

    if (empty($entries)) {
        return '<p>No FAQ items available.</p>';
    }

    $defaults = ['question' => 'New question', 'answer' => 'Answer'];
    $html = '<div class="faq-accordion"' . editableListAttrs($page, 'faq.entries', $defaults) . '>';
    foreach ($entries as $i => $item) {
        $html .= '<div class="faq-item" data-faq-index="' . $i . '"' . editableListGroupItemAttrs($page, 'faq.entries', $i, [], $defaults, 'FAQ item') . '>';
        $html .= '<button class="faq-item__question" type="button" aria-expanded="false">';
        $html .= '<span class="faq-item__question-text">' . editableText($page, "faq.entries.$i.question", 'Question') . '</span>';
        $html .= '<span class="faq-item__icon" aria-hidden="true">';
        $html .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
        $html .= '</span>';
        $html .= '</button>';
        $html .= '<div class="faq-item__answer" hidden>';
        $html .= '<div class="faq-item__answer-inner">';
        $html .= '<p>' . editableText($page, "faq.entries.$i.answer", 'Answer') . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

// ============================================================
// PRICING TABLE
// ============================================================

/**
 * Render pricing table as HTML with editable fields.
 * Reads plan data from page content JSON (pricing.plans).
 *
 * @param string $page Page name (e.g. 'en_showcase')
 * @return string HTML output
 */
function renderPricingTable($page) {
    $plans = editableListItems($page, 'pricing.plans');

    if (empty($plans)) {
        return '<p>No pricing data available.</p>';
    }

    $defaults = ['name' => 'Plan', 'price' => '$0', 'period' => '/mo', 'desc' => '', 'features' => "Feature 1\nFeature 2", 'cta' => ['text' => 'Get Started', 'href' => '#']];
    $html = '<div class="pricing-grid stagger-reveal"' . editableListAttrs($page, 'pricing.plans', $defaults) . '>';
    foreach ($plans as $i => $plan) {
        $highlighted = !empty($plan['highlight']);
        $cardClass = 'pricing-card' . ($highlighted ? ' pricing-card--highlight' : '');
        $html .= '<div class="' . $cardClass . '"' . editableListGroupItemAttrs($page, 'pricing.plans', $i, [], $defaults, 'Plan') . '>';

        if ($highlighted) {
            $html .= '<span class="pricing-card__badge">' . editableText($page, "pricing.plans.$i.badge", 'Popular') . '</span>';
        }

        $html .= '<h3 class="pricing-card__name">' . editableText($page, "pricing.plans.$i.name", 'Plan') . '</h3>';
        $html .= '<div class="pricing-card__price">';
        $html .= '<span class="pricing-card__amount">' . editableText($page, "pricing.plans.$i.price", '$0') . '</span>';
        $html .= '<span class="pricing-card__period">' . editableText($page, "pricing.plans.$i.period", '/mo') . '</span>';
        $html .= '</div>';
        $html .= '<p class="pricing-card__desc">' . editableText($page, "pricing.plans.$i.desc", '') . '</p>';

        // Features list — stored as newline-separated string
        $features = $plan['features'] ?? '';
        $featureList = is_string($features) ? array_filter(explode("\n", $features)) : (is_array($features) ? $features : []);
        $html .= '<ul class="pricing-card__features">';
        foreach ($featureList as $feature) {
            $html .= '<li>' . htmlspecialchars(trim($feature)) . '</li>';
        }
        $html .= '</ul>';

        $html .= '<div class="pricing-card__action">';
        $html .= editableLink($page, "pricing.plans.$i.cta", 'Get Started', '#', 'btn' . ($highlighted ? ' btn-gradient' : ' btn-ghost'));
        $html .= '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';

    // VAT footnote
    $data = loadContentCached($page);
    $vatNote = getNestedValue($data, 'pricing.vat_note');
    if (!empty($vatNote)) {
        $html .= '<p class="pricing-vat-note">' . htmlspecialchars($vatNote) . '</p>';
    }

    return $html;
}

// ============================================================
// TESTIMONIALS
// ============================================================

/**
 * Render testimonials grid.
 * Reads from page content JSON (testimonials.items).
 *
 * @param string $page Page name
 * @return string HTML output
 */
function renderTestimonials($page) {
    $items = editableListItems($page, 'testimonials.items');

    if (empty($items)) {
        return '<p>No testimonials available.</p>';
    }

    $defaults = ['quote' => 'Quote', 'author' => 'Name', 'role' => 'Role'];
    $html = '<div class="testimonials-grid stagger-reveal"' . editableListAttrs($page, 'testimonials.items', $defaults) . '>';
    foreach ($items as $i => $item) {
        $html .= '<blockquote class="testimonial-card"' . editableListGroupItemAttrs($page, 'testimonials.items', $i, [], $defaults, 'Testimonial') . '>';
        $html .= '<svg class="testimonial-card__quote-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.3 2.6c-4.2 2.3-7 6.2-7 10.6 0 3.5 2.1 5.8 4.7 5.8 2.2 0 4-1.7 4-4 0-2.2-1.6-3.8-3.6-4-.4 0-.8 0-1.2.2.4-2.7 2.8-5.4 5.2-6.6l-2.1-2zm10.3 0c-4.2 2.3-7 6.2-7 10.6 0 3.5 2.1 5.8 4.7 5.8 2.2 0 4-1.7 4-4 0-2.2-1.6-3.8-3.6-4-.4 0-.8 0-1.2.2.4-2.7 2.8-5.4 5.2-6.6l-2.1-2z"/></svg>';
        $html .= '<p class="testimonial-card__text">' . editableText($page, "testimonials.items.$i.quote", 'Quote') . '</p>';
        $html .= '<footer class="testimonial-card__footer">';
        $html .= '<cite class="testimonial-card__author">' . editableText($page, "testimonials.items.$i.author", 'Author') . '</cite>';
        $html .= '<span class="testimonial-card__role">' . editableText($page, "testimonials.items.$i.role", 'Role') . '</span>';
        $html .= '</footer>';
        $html .= '</blockquote>';
    }
    $html .= '</div>';

    return $html;
}

// ============================================================
// TEAM GRID
// ============================================================

/**
 * Render team/contributors grid.
 * Reads from page content JSON (team.members).
 *
 * @param string $page Page name
 * @return string HTML output
 */
function renderTeamGrid($page) {
    $members = editableListItems($page, 'team.members');

    if (empty($members)) {
        return '<p>No team members listed.</p>';
    }

    $defaults = ['image' => ['src' => 'https://placehold.co/200x200', 'alt' => 'Team member'], 'name' => 'Name', 'role' => 'Role', 'bio' => 'Bio'];
    $html = '<div class="team-grid stagger-reveal"' . editableListAttrs($page, 'team.members', $defaults) . '>';
    foreach ($members as $i => $member) {
        $html .= '<div class="team-card"' . editableListGroupItemAttrs($page, 'team.members', $i, [], $defaults, 'Team member') . '>';
        $html .= '<div class="team-card__avatar">';
        $html .= editableImage($page, "team.members.$i.image", 'https://placehold.co/200x200', 'Team member', 'team-card__img');
        $html .= '</div>';
        $html .= '<h3 class="team-card__name">' . editableText($page, "team.members.$i.name", 'Name') . '</h3>';
        $html .= '<span class="team-card__role">' . editableText($page, "team.members.$i.role", 'Role') . '</span>';
        $html .= '<p class="team-card__bio">' . editableText($page, "team.members.$i.bio", 'Bio') . '</p>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

// ============================================================
// FEATURE GRID WITH ICONS
// ============================================================

/**
 * Render feature grid with icons from the built-in icon system.
 * Reads from page content JSON (features.items).
 *
 * @param string $page Page name
 * @return string HTML output
 */
function renderFeatureGrid($page) {
    $items = editableListItems($page, 'features.items');

    if (empty($items)) {
        return '<p>No features listed.</p>';
    }

    $defaults = ['icon' => 'star', 'title' => 'Feature', 'desc' => 'Description'];
    $html = '<div class="feature-grid stagger-reveal"' . editableListAttrs($page, 'features.items', $defaults) . '>';
    foreach ($items as $i => $item) {
        $iconId = $item['icon'] ?? 'default';
        $html .= '<div class="feature-card"' . editableListGroupItemAttrs($page, 'features.items', $i, [], $defaults, 'Feature') . '>';
        $html .= '<div class="feature-card__icon">' . renderIconSvg($iconId) . '</div>';
        $html .= '<h3 class="feature-card__title">' . editableText($page, "features.items.$i.title", 'Feature') . '</h3>';
        $html .= '<p class="feature-card__desc">' . editableText($page, "features.items.$i.desc", 'Description') . '</p>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

// ============================================================
// TIMELINE / CHANGELOG
// ============================================================

/**
 * Render timeline/changelog.
 * Reads from page content JSON (timeline.entries).
 *
 * @param string $page Page name
 * @return string HTML output
 */
function renderTimeline($page) {
    $entries = editableListItems($page, 'timeline.entries');

    if (empty($entries)) {
        return '<p>No timeline entries.</p>';
    }

    $defaults = ['date' => '2026-01', 'version' => 'v1.0', 'title' => 'Release', 'desc' => 'Description', 'status' => 'released'];
    $html = '<div class="timeline"' . editableListAttrs($page, 'timeline.entries', $defaults) . '>';
    foreach ($entries as $i => $entry) {
        $status = $entry['status'] ?? 'released';
        $html .= '<div class="timeline-item timeline-item--' . htmlspecialchars($status) . '"' . editableListGroupItemAttrs($page, 'timeline.entries', $i, [], $defaults, 'Timeline entry') . '>';
        $html .= '<div class="timeline-item__marker"></div>';
        $html .= '<div class="timeline-item__content">';
        $html .= '<div class="timeline-item__meta">';
        $html .= '<span class="timeline-item__date">' . editableText($page, "timeline.entries.$i.date", '2026') . '</span>';
        $html .= '<span class="timeline-item__version">' . editableText($page, "timeline.entries.$i.version", 'v1.0') . '</span>';
        if ($status === 'upcoming') {
            $html .= '<span class="timeline-item__badge timeline-item__badge--upcoming">upcoming</span>';
        }
        $html .= '</div>';
        $html .= '<h3 class="timeline-item__title">' . editableText($page, "timeline.entries.$i.title", 'Release') . '</h3>';
        $html .= '<p class="timeline-item__desc">' . editableText($page, "timeline.entries.$i.desc", 'Description') . '</p>';
        $html .= '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

// ============================================================
// STATS / KEY FIGURES
// ============================================================

/**
 * Render stats/key figures section.
 * Reads from page content JSON (stats.items).
 *
 * @param string $page Page name
 * @return string HTML output
 */
function renderStats($page) {
    $items = editableListItems($page, 'stats.items');

    if (empty($items)) {
        return '<p>No stats available.</p>';
    }

    $defaults = ['value' => '0', 'label' => 'Label', 'desc' => 'Description'];
    $html = '<div class="stats-grid stagger-reveal"' . editableListAttrs($page, 'stats.items', $defaults) . '>';
    foreach ($items as $i => $item) {
        $html .= '<div class="stats-card"' . editableListGroupItemAttrs($page, 'stats.items', $i, [], $defaults, 'Stat') . '>';
        $html .= '<span class="stats-card__value">' . editableText($page, "stats.items.$i.value", '0') . '</span>';
        $html .= '<span class="stats-card__label">' . editableText($page, "stats.items.$i.label", 'Label') . '</span>';
        $html .= '<p class="stats-card__desc">' . editableText($page, "stats.items.$i.desc", '') . '</p>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

// ============================================================
// GALLERY / IMAGE GRID
// ============================================================

/**
 * Render gallery/image grid with lightbox support.
 * Reads from page content JSON (gallery.images).
 *
 * @param string $page Page name
 * @return string HTML output
 */
function renderGallery($page) {
    $images = editableListItems($page, 'gallery.images');

    if (empty($images)) {
        return '<p>No images available.</p>';
    }

    $defaults = ['src' => 'https://placehold.co/600x400', 'alt' => 'Image', 'caption' => 'Caption'];
    $html = '<div class="gallery-grid stagger-reveal"' . editableListAttrs($page, 'gallery.images', $defaults) . '>';
    foreach ($images as $i => $img) {
        $src = $img['src'] ?? '';
        $alt = $img['alt'] ?? '';
        $caption = $img['caption'] ?? '';
        $html .= '<figure class="gallery-item"' . editableListGroupItemAttrs($page, 'gallery.images', $i, [], $defaults, 'Image') . '>';
        $html .= '<a href="' . htmlspecialchars($src) . '" class="gallery-item__link" data-gallery>';
        $html .= editableImage($page, "gallery.images.$i", $src, $alt, 'gallery-item__img');
        $html .= '</a>';
        if (!empty($caption)) {
            $html .= '<figcaption class="gallery-item__caption">' . editableText($page, "gallery.images.$i.caption", '') . '</figcaption>';
        }
        $html .= '</figure>';
    }
    $html .= '</div>';

    // Lightbox overlay (rendered once)
    $html .= '<div class="gallery-lightbox" id="galleryLightbox" hidden>';
    $html .= '<button class="gallery-lightbox__close" aria-label="Close">&times;</button>';
    $html .= '<img class="gallery-lightbox__img" src="" alt="">';
    $html .= '</div>';

    return $html;
}

// ============================================================
// EVENT FUNCTIONS
// ============================================================
// NEWS / BLOG
// ============================================================

/**
 * Load all published news posts, sorted by date descending.
 *
 * @param int $limit Max number of posts (0 = all)
 * @return array
 */
function loadNewsPosts($limit = 0, $lang = '') {
    $newsDir = __DIR__ . '/../content/news/';
    if (!is_dir($newsDir)) return [];

    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';

    $posts = [];
    foreach (glob($newsDir . '*.json') as $file) {
        $post = json_decode(file_get_contents($file), true);
        if (!is_array($post)) continue;
        if (!empty($post['hidden'])) continue;

        // Posts without lang field default to primary language
        $postLang = $post['lang'] ?? $defaultLang;

        // Filter by language if specified
        if ($lang && $postLang !== $lang) continue;

        $posts[] = $post;
    }

    usort($posts, function($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });

    if ($limit > 0) {
        $posts = array_slice($posts, 0, $limit);
    }

    return $posts;
}

// ============================================================
// BREADCRUMBS
// ============================================================

/**
 * Render a breadcrumb trail from the page's "breadcrumb" JSON field.
 * Each entry has "label" (required) and "href" (optional, omitted for current page).
 * Returns empty string if the page has no breadcrumb data.
 *
 * @param string $page     JSON page name (e.g. 'en_about')
 * @param string $basePath Relative path prefix ('' or '../')
 * @return string HTML
 */
function renderBreadcrumb($page, $basePath = '') {
    $data = loadContentCached($page);
    $crumbs = $data['breadcrumb'] ?? null;
    if (!$crumbs || !is_array($crumbs)) return '';

    $html = '<nav class="breadcrumb" aria-label="Breadcrumb"><ol class="breadcrumb__list">';
    $count = count($crumbs);
    foreach ($crumbs as $i => $crumb) {
        $label = htmlspecialchars($crumb['label'] ?? '');
        $isLast = ($i === $count - 1);
        if ($isLast || empty($crumb['href'])) {
            $html .= '<li class="breadcrumb__item breadcrumb__item--current" aria-current="page">' . $label . '</li>';
        } else {
            $html .= '<li class="breadcrumb__item"><a href="' . htmlspecialchars($basePath . $crumb['href']) . '">' . $label . '</a></li>';
        }
    }
    $html .= '</ol></nav>';
    return $html;
}

/**
 * Render a news/blog listing.
 *
 * @param int $limit Max posts to show (0 = all)
 * @param string $lang Language code for date formatting
 * @return string HTML
 */
function renderNewsList($limit = 0, $lang = 'en') {
    $posts = loadNewsPosts($limit, $lang);

    if (empty($posts)) {
        return '<p class="news-empty">' . t('news.no_posts') . '</p>';
    }

    // Build base URL for post links
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $langPrefix = ($lang === $defaultLang) ? '' : $lang . '/';
    $postBaseUrl = '/' . $langPrefix . 'news/';

    $html = '<div class="news-grid">';
    foreach ($posts as $post) {
        $title = htmlspecialchars($post['title'] ?? '');
        $excerpt = htmlspecialchars($post['excerpt'] ?? '');
        $date = $post['date'] ?? '';
        $author = htmlspecialchars($post['author'] ?? '');
        $image = htmlspecialchars($post['image'] ?? '');
        $slug = htmlspecialchars($post['slug'] ?? '');
        $postUrl = $postBaseUrl . $slug;

        // Format date
        try {
            $dateObj = new DateTime($date);
            $formattedDate = $dateObj->format('M j, Y');
        } catch (Exception $e) {
            $formattedDate = htmlspecialchars($date);
        }

        $html .= '<article class="news-card">';
        $html .= '<a href="' . $postUrl . '" class="news-card__link">';
        $_isAdmin = isAdminLoggedIn();
        if ($image) {
            $html .= '<div class="news-card__image">';
            $html .= '<img src="' . $image . '" alt="' . $title . '" loading="lazy">';
            $html .= '</div>';
        } elseif ($_isAdmin) {
            $html .= '<div class="news-card__image">';
            $html .= '<img src="/assets/images/placeholder-image.webp" alt="" loading="lazy">';
            $html .= '</div>';
        }
        $html .= '<div class="news-card__body">';
        $html .= '<time class="news-card__date" datetime="' . htmlspecialchars($date) . '">' . $formattedDate . '</time>';
        $html .= '<h3 class="news-card__title">' . $title . '</h3>';
        if ($excerpt || $_isAdmin) {
            $html .= '<p class="news-card__excerpt">' . $excerpt . '</p>';
        }
        if ($author || $_isAdmin) {
            $html .= '<span class="news-card__author">' . $author . '</span>';
        }
        $html .= '</div>';
        $html .= '</a>';
        $html .= '</article>';
    }
    $html .= '</div>';

    return $html;
}

// ============================================================

if (!defined('EVENTS_FILE_PATH')) {
    define('EVENTS_FILE_PATH', __DIR__ . '/../content/events.json');
}

/**
 * Load all events from JSON file
 * @return array Array of events, sorted by date (newest first)
 */
function loadEvents() {
    if (!file_exists(EVENTS_FILE_PATH)) {
        return [];
    }

    $data = json_decode(file_get_contents(EVENTS_FILE_PATH), true);
    $events = $data['events'] ?? [];

    usort($events, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    return $events;
}

/**
 * Get upcoming events (today and future)
 * @param int $limit Optional: Maximum number (0 = all)
 * @return array Array of upcoming events
 */
function getUpcomingEvents($limit = 0) {
    $events = loadEvents();
    $today = date('Y-m-d');

    $upcoming = array_filter($events, function($event) use ($today) {
        return $event['date'] >= $today;
    });

    usort($upcoming, function($a, $b) {
        return strcmp($a['date'], $b['date']);
    });

    if ($limit > 0) {
        return array_slice($upcoming, 0, $limit);
    }

    return $upcoming;
}

/**
 * Get past events
 * @param int $limit Optional: Maximum number (0 = all)
 * @return array Array of past events (newest first)
 */
function getPastEvents($limit = 0) {
    $events = loadEvents();
    $today = date('Y-m-d');

    $past = array_filter($events, function($event) use ($today) {
        return $event['date'] < $today;
    });

    usort($past, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    if ($limit > 0) {
        return array_slice($past, 0, $limit);
    }

    return $past;
}

/**
 * Get next upcoming event
 * @return array|null Event data or null
 */
function getNextEvent() {
    $upcoming = getUpcomingEvents(1);
    return !empty($upcoming) ? $upcoming[0] : null;
}

/**
 * Format a date for display
 * @param string $date Date in Y-m-d format
 * @param string $lang Language ('de' or 'en')
 * @return string Formatted date
 */
function formatEventDate($date, $lang = 'de') {
    $timestamp = strtotime($date);

    if ($lang === 'de') {
        $days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $months = ['', 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
                   'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

        $dayName = $days[date('w', $timestamp)];
        $day = date('j', $timestamp);
        $month = $months[(int)date('n', $timestamp)];
        $year = date('Y', $timestamp);

        return "$dayName, $day. $month $year";
    } elseif ($lang === 'es') {
        $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                   'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $dayName = $days[date('w', $timestamp)];
        $day = date('j', $timestamp);
        $month = $months[(int)date('n', $timestamp)];
        $year = date('Y', $timestamp);

        return ucfirst($dayName) . ", $day de $month de $year";
    } else {
        return date('l, F j, Y', $timestamp);
    }
}

/**
 * Format a short date (without weekday) for use in date ranges.
 */
function formatEventDateShort($date, $lang = 'de') {
    $timestamp = strtotime($date);

    if ($lang === 'de') {
        $months = ['', 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
                   'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        $day = date('j', $timestamp);
        $month = $months[(int)date('n', $timestamp)];
        $year = date('Y', $timestamp);
        return "$day. $month $year";
    } elseif ($lang === 'es') {
        $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                   'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $day = date('j', $timestamp);
        $month = $months[(int)date('n', $timestamp)];
        $year = date('Y', $timestamp);
        return "$day de $month de $year";
    } else {
        return date('F j, Y', $timestamp);
    }
}

/**
 * Render a single event as HTML card
 */
function renderEvent($event, $lang = 'de', $showImage = true, $editable = false) {
    $dl = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $title = $event['title'][$lang] ?? $event['title'][$dl] ?? '';
    $location = $event['location'][$lang] ?? $event['location'][$dl] ?? '';
    $description = $event['description'][$lang] ?? $event['description'][$dl] ?? '';
    $admission = $event['admission'][$lang] ?? $event['admission'][$dl] ?? '';

    $startDate = $event['date'] ?? '';
    $startTime = $event['time'] ?? '';
    $endDate = $event['end-date'] ?? '';
    $endTime = $event['end-time'] ?? '';
    $eventIdHtml = htmlspecialchars($event['id']);
    $url = $event['url'] ?? '';

    $isMultiDay = !empty($endDate) && $endDate !== $startDate;
    $timeSuffix = $lang === 'de' ? ' Uhr' : ($lang === 'es' ? ' h' : '');

    $isEventHidden = !empty($event['hidden']);
    $html = '<article class="event-card' . ($editable ? ' event-card-editable' : '') . ($isEventHidden && $editable ? ' event-card-hidden' : '') . '"  data-event-id="' . $eventIdHtml . '">';

    if ($editable) {
        $html .= '<div class="event-edit-buttons">';
        $html .= '<button type="button" class="event-edit-btn" title="Edit">&#9998;</button>';
        $html .= '<button type="button" class="event-hide-btn" title="' . ($isEventHidden ? 'Show' : 'Hide') . '" data-hidden="' . ($isEventHidden ? 'true' : 'false') . '">'
            . ($isEventHidden
                ? '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>')
            . '</button>';
        $html .= '<button type="button" class="event-delete-btn" title="Delete"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg></button>';
        $html .= '</div>';
    }

    // Banner image
    if ($showImage && (!empty($event['image']) || $editable)) {
        $html .= '<div class="event-card__image">';
        $imgSrc = htmlspecialchars($event['image']);
        $imgAlt = htmlspecialchars($title);
        if ($editable) {
            $html .= '<img src="' . $imgSrc . '" alt="' . $imgAlt . '" data-editable-image data-page="events" data-field="' . $eventIdHtml . '.image">';
        } else {
            $html .= '<img src="' . $imgSrc . '" alt="' . $imgAlt . '">';
        }
        $html .= '</div>';
    }

    $html .= '<div class="event-card__body">';

    // Date badge
    $html .= '<div class="event-card__date">';
    $html .= '<svg class="event-card__date-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
    if ($isMultiDay) {
        $fromDate = formatEventDateShort($startDate, $lang);
        $toDate = formatEventDateShort($endDate, $lang);
        $html .= htmlspecialchars($fromDate);
        if (!empty($startTime)) {
            $html .= ', ' . htmlspecialchars($startTime) . $timeSuffix;
        }
        $bisLabel = $lang === 'de' ? ' – ' : ($lang === 'es' ? ' – ' : ' – ');
        $html .= $bisLabel . htmlspecialchars($toDate);
        if (!empty($endTime)) {
            $html .= ', ' . htmlspecialchars($endTime) . $timeSuffix;
        }
    } else {
        $dateFormatted = formatEventDate($startDate, $lang);
        $html .= htmlspecialchars($dateFormatted);
        if (!empty($startTime) && !empty($endTime)) {
            $html .= ', ' . htmlspecialchars($startTime) . '–' . htmlspecialchars($endTime) . $timeSuffix;
        } elseif (!empty($startTime)) {
            $html .= ', ' . htmlspecialchars($startTime) . $timeSuffix;
        }
    }
    $html .= '</div>';

    // Title
    if (!empty($url)) {
        $html .= '<h3 class="event-card__title"><a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . htmlspecialchars($title) . '</a></h3>';
    } else {
        $html .= '<h3 class="event-card__title">' . htmlspecialchars($title) . '</h3>';
    }

    // Location
    if (!empty($location) || $editable) {
        $html .= '<div class="event-card__location">';
        $html .= '<svg class="event-card__location-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        $html .= htmlspecialchars($location);
        $html .= '</div>';
    }

    // Description
    if (!empty($description) || $editable) {
        $html .= '<p class="event-card__desc">' . htmlspecialchars($description) . '</p>';
    }

    // Footer: admission + link
    $html .= '<div class="event-card__footer">';
    if (!empty($admission) || $editable) {
        $admissionLabels = ['de' => 'Eintritt: ', 'en' => 'Admission: ', 'es' => 'Entrada: '];
        $admissionLabel = $admissionLabels[$lang] ?? 'Admission: ';
        $html .= '<span class="event-card__admission">' . $admissionLabel . htmlspecialchars($admission) . '</span>';
    }
    if (!empty($url)) {
        $linkLabels = ['de' => 'Zur Website', 'en' => 'Visit website', 'es' => 'Visitar web'];
        $linkLabel = $linkLabels[$lang] ?? 'Visit website';
        $html .= '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener" class="event-card__link">' . $linkLabel . ' &rarr;</a>';
    }
    $html .= '</div>';

    $html .= '</div>'; // .event-card__body
    $html .= '</article>';

    return $html;
}

/**
 * Render a list of events
 */
function renderEventList($events, $lang = 'de', $showImages = true, $showAddButton = true) {
    $isEditable = isAdminLoggedIn();

    // Filter out hidden events for visitors
    if (!$isEditable) {
        $events = array_filter($events, function($event) {
            return empty($event['hidden']);
        });
    }

    $html = '';

    if (empty($events)) {
        $noEventsTexts = ['de' => 'Derzeit keine Termine geplant.', 'en' => 'No events scheduled at this time.', 'es' => 'No hay eventos programados en este momento.'];
        $noEventsText = $noEventsTexts[$lang] ?? $noEventsTexts['en'];
        $html .= '<p class="no-events">' . $noEventsText . '</p>';
    } else {
        $html .= '<div class="event-grid stagger-reveal">';
        foreach ($events as $event) {
            $html .= renderEvent($event, $lang, $showImages, $isEditable);
        }
        $html .= '</div>';
    }

    if ($isEditable && $showAddButton) {
        $addLabels = ['de' => '+ Neues Event', 'en' => '+ New Event', 'es' => '+ Nuevo evento'];
        $addLabel = $addLabels[$lang] ?? $addLabels['en'];
        $html .= '<div class="event-add-wrapper">';
        $html .= '<button type="button" class="event-add-btn" onclick="InlineEditor.openEventEditor()">' . $addLabel . '</button>';
        $html .= '</div>';
    }

    return $html;
}
