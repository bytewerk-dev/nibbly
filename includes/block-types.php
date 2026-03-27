<?php
/**
 * Block Type Registry
 *
 * Central definition of all available block types.
 * Each type defines: label, category, icon, default values, and editor fields.
 * Renderers live in includes/block-renderers/{type}.php
 */

require_once __DIR__ . '/../admin/lang/i18n.php';

return [
    'text' => [
        'label'    => t('block.text'),
        'category' => 'content',
        'icon'     => 'text',
        'defaults' => ['title' => '', 'content' => '<p></p>', 'titleTag' => 'h2'],
        'fields'   => [
            ['key' => 'title',    'type' => 'input',    'label' => t('field.title')],
            ['key' => 'titleTag', 'type' => 'select',   'label' => t('field.heading_level'), 'options' => [
                ['value' => 'h1', 'label' => 'H1'],
                ['value' => 'h2', 'label' => 'H2'],
                ['value' => 'h3', 'label' => 'H3'],
                ['value' => 'h4', 'label' => 'H4'],
                ['value' => 'h5', 'label' => 'H5'],
                ['value' => 'h6', 'label' => 'H6'],
            ]],
            ['key' => 'content',  'type' => 'wysiwyg',  'label' => t('field.content')],
            ['key' => 'style',    'type' => 'select',   'label' => t('field.style'), 'options' => [
                ['value' => '',          'label' => t('option.default')],
                ['value' => 'highlight', 'label' => t('option.highlight')],
            ]],
        ],
    ],

    'youtube' => [
        'label'    => t('block.youtube'),
        'category' => 'media',
        'icon'     => 'video',
        'defaults' => ['title' => '', 'videoId' => ''],
        'fields'   => [
            ['key' => 'title',   'type' => 'input', 'label' => t('field.title')],
            ['key' => 'videoId', 'type' => 'input', 'label' => t('field.video_id'), 'hint' => t('field.video_id_hint'), 'preview' => 'youtube'],
        ],
    ],

    'soundcloud' => [
        'label'    => t('block.soundcloud'),
        'category' => 'media',
        'icon'     => 'audio',
        'defaults' => ['title' => '', 'trackId' => ''],
        'fields'   => [
            ['key' => 'title',   'type' => 'input', 'label' => t('field.title')],
            ['key' => 'trackId', 'type' => 'input', 'label' => t('field.track_id'), 'hint' => t('field.track_id_hint'), 'preview' => 'soundcloud'],
        ],
    ],

    'audio' => [
        'label'    => t('block.audio'),
        'category' => 'media',
        'icon'     => 'audio',
        'defaults' => ['title' => '', 'src' => ''],
        'fields'   => [
            ['key' => 'title', 'type' => 'input', 'label' => t('field.title')],
            ['key' => 'src',   'type' => 'audio', 'label' => t('field.audio_file')],
        ],
    ],

    'card' => [
        'label'    => t('block.card'),
        'category' => 'cards',
        'icon'     => 'card',
        'defaults' => ['title' => '', 'content' => '', 'image' => ''],
        'fields'   => [
            ['key' => 'title',   'type' => 'input',    'label' => t('field.title')],
            ['key' => 'content', 'type' => 'textarea', 'label' => t('field.description')],
            ['key' => 'image',   'type' => 'image',    'label' => t('block.image')],
        ],
    ],

    'heading' => [
        'label'    => t('block.heading'),
        'category' => 'content',
        'icon'     => 'heading',
        'defaults' => ['text' => '', 'level' => 'h2', 'subtitle' => ''],
        'fields'   => [
            ['key' => 'text',     'type' => 'input',  'label' => t('field.heading_text')],
            ['key' => 'level',    'type' => 'select', 'label' => t('field.level'), 'options' => [
                ['value' => 'h1', 'label' => t('option.h1_page_title')],
                ['value' => 'h2', 'label' => t('option.h2_section')],
                ['value' => 'h3', 'label' => t('option.h3_subsection')],
                ['value' => 'h4', 'label' => t('option.h4_small')],
                ['value' => 'h5', 'label' => 'H5'],
                ['value' => 'h6', 'label' => 'H6'],
            ]],
            ['key' => 'subtitle', 'type' => 'input',  'label' => t('field.subtitle')],
        ],
    ],

    'quote' => [
        'label'    => t('block.quote'),
        'category' => 'content',
        'icon'     => 'quote',
        'defaults' => ['text' => '', 'attribution' => '', 'style' => 'default'],
        'fields'   => [
            ['key' => 'text',        'type' => 'textarea', 'label' => t('field.quote_text')],
            ['key' => 'attribution', 'type' => 'input',    'label' => t('field.attribution')],
            ['key' => 'style',       'type' => 'select',   'label' => t('field.style'), 'options' => [
                ['value' => 'default', 'label' => t('option.default')],
                ['value' => 'large',   'label' => t('option.large')],
            ]],
        ],
    ],

    'image' => [
        'label'    => t('block.image'),
        'category' => 'media',
        'icon'     => 'image',
        'defaults' => ['src' => '', 'alt' => '', 'caption' => '', 'width' => 'full'],
        'fields'   => [
            ['key' => 'src',     'type' => 'image',    'label' => t('block.image')],
            ['key' => 'alt',     'type' => 'input',    'label' => t('field.alt_text')],
            ['key' => 'caption', 'type' => 'input',    'label' => t('field.caption')],
            ['key' => 'width',   'type' => 'select',   'label' => t('field.width'), 'options' => [
                ['value' => 'full',   'label' => t('option.full_width')],
                ['value' => 'medium', 'label' => t('option.medium_75')],
                ['value' => 'small',  'label' => t('option.small_50')],
            ]],
        ],
    ],

    'list' => [
        'label'    => t('block.list'),
        'category' => 'content',
        'icon'     => 'list',
        'defaults' => ['title' => '', 'style' => 'bullet', 'content' => '<ul><li></li></ul>'],
        'fields'   => [
            ['key' => 'title',   'type' => 'input',   'label' => t('field.title_optional')],
            ['key' => 'style',   'type' => 'select',  'label' => t('field.style'), 'options' => [
                ['value' => 'bullet',   'label' => t('option.bullet_list')],
                ['value' => 'numbered', 'label' => t('option.numbered_list')],
            ]],
            ['key' => 'content', 'type' => 'wysiwyg', 'label' => t('field.list_items')],
        ],
    ],

    'divider' => [
        'label'    => t('block.divider'),
        'category' => 'layout',
        'icon'     => 'divider',
        'defaults' => [],
        'fields'   => [],
    ],

    'spacer' => [
        'label'    => t('block.spacer'),
        'category' => 'layout',
        'icon'     => 'spacer',
        'defaults' => ['height' => 'md'],
        'fields'   => [
            ['key' => 'height', 'type' => 'select', 'label' => t('field.height'), 'options' => [
                ['value' => 'sm', 'label' => t('option.small')],
                ['value' => 'md', 'label' => t('option.medium')],
                ['value' => 'lg', 'label' => t('option.large')],
                ['value' => 'xl', 'label' => t('option.extra_large')],
            ]],
        ],
    ],
];
