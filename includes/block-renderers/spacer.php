<?php
/**
 * Block renderer: spacer
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$height = $section['height'] ?? 'md';
$allowed = ['sm', 'md', 'lg', 'xl'];
if (!in_array($height, $allowed)) $height = 'md';

return '<div class="block-spacer block-spacer--' . $height . '" aria-hidden="true"></div>' . "\n";
