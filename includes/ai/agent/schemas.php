<?php
/**
 * Fixed content schemas for the agent (P2): news posts, events, new pages and the
 * block types of standard pages. They follow the rules of admin/api/news.php,
 * admin/api/events.php and includes/block-types.php. content/schema.json is
 * documentation only; nothing here reads it.
 *
 * A field spec: {path, t (text|textarea|html|date|time|link|image|select|icon|boolean),
 * max?, req?, opts?, ro? (read-only when editing an existing item), create? (only when creating)}.
 */

/** Default length limits per field type; site overrides may only lower them. */
function nibblyAgentDefaultMax(string $type, string $path = ''): int {
    $key = strtolower((string)preg_replace('/^.*\./', '', $path));
    if (in_array($type, ['text', 'textarea'], true)) {
        if (preg_match('/^(alt|alttext|label|caption)$/', $key)) return 200;
        if (preg_match('/title|heading|headline|name|subtitle|tagline/', $key)) return 160;
        return $type === 'textarea' ? 2000 : 300;
    }
    return match ($type) {
        'html' => 20000,
        'link' => 500,
        'icon' => 80,
        'date' => 10,
        'time' => 5,
        default => 300
    };
}

function nibblyAgentNewsSlugPattern(): string {
    return '^[a-z0-9]+(?:-[a-z0-9]+)*$';
}

/** News post (content/news/{id}.json): id = date-slug, plus -lang for non-primary languages. */
function nibblyAgentNewsSchema(): array {
    return [
        ['path' => 'title', 't' => 'text', 'max' => 180, 'req' => true],
        ['path' => 'excerpt', 't' => 'textarea', 'max' => 320],
        ['path' => 'content', 't' => 'html', 'max' => 20000, 'req' => true],
        ['path' => 'author', 't' => 'text', 'max' => 120],
        // The cover image is a plain string path; the alt text is taken from the title.
        ['path' => 'image', 't' => 'image', 'fmt' => 'string'],
        ['path' => 'date', 't' => 'date', 'req' => true, 'ro' => true],
        ['path' => 'slug', 't' => 'text', 'max' => 120, 'req' => true, 'ro' => true],
        ['path' => 'lang', 't' => 'text', 'max' => 2, 'req' => true, 'ro' => true, 'create' => true],
    ];
}

/** Event (content/events.json → events[]): texts are language maps, e.g. title.de. */
function nibblyAgentEventSchema(array $languages): array {
    $fields = [
        ['path' => 'date', 't' => 'date', 'req' => true, 'ro' => true],
        ['path' => 'time', 't' => 'time', 'req' => true],
        ['path' => 'end-date', 't' => 'date'],
        ['path' => 'end-time', 't' => 'time'],
        ['path' => 'url', 't' => 'link', 'max' => 500],
        ['path' => 'image', 't' => 'image', 'fmt' => 'string'],
    ];
    foreach (['title' => ['text', 180, true], 'location' => ['text', 240, true], 'description' => ['html', 20000, false], 'admission' => ['text', 160, false]] as $key => [$type, $max, $required]) {
        foreach ($languages as $index => $lang) {
            $fields[] = ['path' => $key . '.' . $lang, 't' => $type, 'max' => $max, 'req' => $required && $index === 0];
        }
    }
    return $fields;
}

/** New standard page: private, hidden from navigation, filled with block sections. */
function nibblyAgentNewPageSchema(): array {
    return [
        ['path' => 'title', 't' => 'text', 'max' => 180, 'req' => true],
        ['path' => 'description', 't' => 'textarea', 'max' => 320],
        ['path' => 'slug', 't' => 'text', 'max' => 120, 'req' => true, 'create' => true],
        ['path' => 'lang', 't' => 'text', 'max' => 2, 'req' => true, 'create' => true],
    ];
}

/** Map a block-types.php editor field type to an agent type. */
function nibblyAgentBlockFieldType(string $editorType): string {
    return match ($editorType) {
        'wysiwyg' => 'html',
        'textarea' => 'textarea',
        'image' => 'image',
        'select' => 'select',
        'input' => 'text',
        default => 'text'
    };
}

/**
 * Block types a standard page can hold, with their editable fields.
 * Structural controls (select styles/levels) stay out of the agent's reach.
 *
 * @return array<string,array{label:string,fields:array<string,string>}>
 */
function nibblyAgentBlockSchemas(): array {
    $blocks = [];
    foreach (getBlockTypes() as $type => $definition) {
        $fields = [];
        foreach ((array)($definition['fields'] ?? []) as $field) {
            $key = (string)($field['key'] ?? '');
            $mapped = nibblyAgentBlockFieldType((string)($field['type'] ?? 'input'));
            if ($key === '' || $mapped === 'select' || in_array((string)($field['type'] ?? ''), ['audio'], true)) continue;
            $fields[$key] = $mapped;
        }
        $blocks[$type] = ['label' => (string)($definition['label'] ?? $type), 'fields' => $fields];
    }
    return $blocks;
}

/** Editor field definition of a section field, or null. */
function nibblyAgentBlockField(string $blockType, string $key): ?array {
    $definition = getBlockTypes()[$blockType === 'project' ? 'card' : $blockType] ?? null;
    foreach ((array)($definition['fields'] ?? []) as $field) {
        if (($field['key'] ?? null) === $key) return $field;
    }
    return null;
}
