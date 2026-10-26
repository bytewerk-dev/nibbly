<?php
require_once __DIR__ . '/../people.php';
$lang = $GLOBALS['currentLang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
$people = nibblyPeople((string)($section['group'] ?? ''));
if (!$people) return $editable ? '<p class="people-empty">'.htmlspecialchars(t('people.empty_team')).'</p>' : '';
$columns = in_array((string)($section['columns'] ?? ''), ['2','3','4'], true) ? $section['columns'] : '3';
$html = '<div class="people-grid people-grid--'.$columns.'">';
foreach ($people as $person) $html .= nibblyRenderPerson($person, $lang, ($section['details'] ?? 'summary') === 'full');
return $html.'</div>';
