<?php
require_once __DIR__ . '/../people.php';
$lang = $GLOBALS['currentLang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
foreach (nibblyPeople() as $person) {
    if ($person['id'] === ($section['personId'] ?? '')) return nibblyRenderPerson($person, $lang, true);
}
return $editable ? '<p class="people-empty">'.htmlspecialchars(t('people.choose_hint')).'</p>' : '';
