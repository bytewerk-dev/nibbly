<?php
/**
 * Text block styles of the core: key => ['label' => …, 'class' => …].
 *
 * A text block (pages and posts) carries its style in the field "style"; the renderer wraps the
 * block in <div class="{class}"> when the key is known. "" (no style) is always the plain block.
 * A site adds or overrides styles in content/text-styles.json without touching the core:
 *
 *   { "callout": { "label": "Callout", "class": "my-callout" },
 *     "highlight": { "label": { "de": "Hervorgehoben", "en": "Highlight" }, "class": "my-highlight" } }
 *
 * "label" is a string or a map of language codes; the CSS for the class lives in the site's stylesheet.
 * Resolved by getTextStyles() in includes/content-loader.php.
 */
return [
    'highlight' => ['label' => t('option.highlight'), 'class' => 'content-highlight'],
];
