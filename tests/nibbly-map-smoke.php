<?php
/**
 * The Nibbly map (includes/ai/nibbly-map.php): complete against the dashboard, named in all admin languages,
 * found from free text in any language, links that stay on the site, roles respected.
 *
 * Run from the repository root:
 * php tests/nibbly-map-smoke.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
define('SITE_LANG_DEFAULT', 'en');
$_SESSION = ['admin_role' => 'admin'];
$_SERVER['SCRIPT_NAME'] = '/admin/api.php';

require_once $root . '/admin/lang/i18n.php';
require_once $root . '/includes/ai/nibbly-map.php';

function mapAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$entries = nibblyMapEntries();
$sections = nibblyMapSections();
$tabs = nibblyMapSettingsTabs();
$languages = nibblyMapLanguageCodes();
mapAssert(count($languages) === 9, 'nine admin languages expected, found ' . implode(',', $languages));

// ---- Complete against the dashboard --------------------------------------------------------------------------
$dashboard = (string)file_get_contents($root . '/admin/dashboard.php');
preg_match_all('/data-tab="([a-z-]+)"/', $dashboard, $found);
$dashboardAreas = array_values(array_unique($found[1]));
foreach ($dashboardAreas as $area) mapAssert(isset($sections[$area]), "dashboard area '{$area}' is missing in nibblyMapSections()");
foreach (array_keys($sections) as $area) mapAssert(in_array($area, $dashboardAreas, true), "map area '{$area}' does not exist in the dashboard");
preg_match_all('/data-settings-tab="([a-z-]+)"/', $dashboard, $found);
$dashboardTabs = array_values(array_unique($found[1]));
foreach ($dashboardTabs as $tab) mapAssert(isset($tabs[$tab]), "settings tab '{$tab}' is missing in nibblyMapSettingsTabs()");
foreach (array_keys($tabs) as $tab) mapAssert(in_array($tab, $dashboardTabs, true), "map settings tab '{$tab}' does not exist in the dashboard");
$ids = array_keys($entries);
foreach (array_keys($sections) as $area) {
    mapAssert(count(array_filter($entries, static fn($e) => $e[0] === $area && $e[1] === '' && str_starts_with(array_search($e, $entries), 'area_'))) === 1, "area '{$area}' needs exactly one area_ entry");
}
foreach (array_keys($tabs) as $tab) {
    mapAssert(count(array_filter(array_keys($entries), static fn($id) => str_starts_with($id, 'tab_') && $entries[$id][1] === $tab)) === 1, "settings tab '{$tab}' needs exactly one tab_ entry");
}
// The module keys the map uses are the ones the dashboard uses.
preg_match("/'news' => true, 'events' => true, 'messages' => true, 'iconManager' => true, 'ai' => true/", $dashboard) or mapAssert(false, 'the dashboard module list changed; update nibblyMapSections()');
foreach ($sections as $area => [$label, $role, $module]) {
    mapAssert(in_array($role, ['admin', 'editor'], true), "area '{$area}' has an unknown role");
    mapAssert($module === '' || in_array($module, ['news', 'events', 'messages', 'iconManager'], true), "area '{$area}' has an unknown module");
}

// ---- Every name exists in every admin language -----------------------------------------------------------------
$keys = [];
foreach ($sections as [$label]) $keys[$label] = true;
foreach ($tabs as [$label]) $keys[$label] = true;
foreach ($entries as $id => $entry) {
    mapAssert(isset($sections[$entry[0]]), "{$id}: unknown area {$entry[0]}");
    mapAssert($entry[1] === '' || isset($tabs[$entry[1]]), "{$id}: unknown settings tab {$entry[1]}");
    mapAssert($entry[1] === '' || $entry[0] === 'settings', "{$id}: a settings tab belongs to the settings area");
    mapAssert(is_array($entry[3]) && is_string($entry[4]) && $entry[4] !== '', "{$id}: synonyms and an English hint are needed");
    $keys[$entry[2]] = true;
}
foreach (array_keys($keys) as $key) {
    foreach ($languages as $language) {
        $strings = nibblyMapLanguageStrings($language);
        $english = nibblyMapLanguageStrings('en');
        mapAssert(isset($english[$key]) && trim((string)$english[$key]) !== '', "label key {$key} missing in en.json");
        mapAssert(nibblyMapLabel($key, $language) !== $key, "label key {$key} cannot be resolved for {$language}");
        mapAssert(isset($strings[$key]) || $language === 'en' || isset($english[$key]), "label key {$key} for {$language}");
    }
}

// A synonym belongs to one entry: the same word on two entries makes the answer a coin toss.
$owners = [];
foreach (array_keys($entries) as $id) {
    foreach (nibblyMapTerms($id) as $term) $owners[$term][] = $id;
}
foreach ($owners as $term => $list) {
    if ($term !== 'dashboard') mapAssert(count(array_unique($list)) === 1, "'{$term}' is a name or synonym of several entries: " . implode(', ', array_unique($list)));
}

// ---- Finding things ----------------------------------------------------------------------------------------------
$first = static function (string $query, bool $admin = true): ?string {
    $found = nibblyMapLookup($query, $admin, 3);
    return $found[0]['id'] ?? null;
};
foreach ([
    'Stell die Systemsprache auf Englisch' => 'set_admin_language',
    'Set the system language to German' => 'set_admin_language',
    'change the interface language' => 'set_admin_language',
    'Wie ändere ich die Sprache des Dashboards?' => 'set_admin_language',
    'cambiar el idioma del sistema' => 'set_admin_language',
    'Wo kann ich mein Passwort ändern?' => 'set_password',
    'where do I switch on dark mode' => 'set_theme',
    'Ich möchte einen Benutzer anlegen' => 'tab_users',
    'how do I restore a backup' => 'area_backup',
    'Wartungsmodus einschalten' => 'set_maintenance',
    'where are the form messages' => 'area_messages',
    'Logo ändern' => 'set_logo',
    'SMTP Server einstellen' => 'set_smtp',
] as $query => $expected) {
    mapAssert($first($query) === $expected, "'{$query}' should find {$expected}, found " . json_encode($first($query)));
}
mapAssert($first('Tell me a joke about cats') === null, 'a question with no place finds nothing');
mapAssert($first('') === null, 'an empty question finds nothing');
// The name in each admin language finds its entry.
foreach ($languages as $language) {
    $label = nibblyMapLabel('settings.admin_language', $language);
    mapAssert($first($label) === 'set_admin_language', "the name '{$label}' ({$language}) should find the admin language setting, found " . json_encode($first($label)));
}

// ---- Links and breadcrumbs -------------------------------------------------------------------------------------------
$place = nibblyMapDescribe('set_admin_language', true);
mapAssert($place['path'] === 'Settings › Language › Admin Language' && $place['url'] === '/admin/dashboard.php#settings/language' && !$place['restricted'] && $place['available'], 'language setting: ' . json_encode($place));
mapAssert(nibblyMapDescribe('area_home', true)['url'] === '/admin/dashboard.php#dashboard', 'the home area uses the public hash');
mapAssert(nibblyMapDescribe('area_pages', true)['url'] === '/admin/dashboard.php#pages', 'pages use the public hash');
mapAssert(nibblyMapDescribe('area_messages', true)['url'] === '/admin/dashboard.php#messages', 'messages use the public hash');
mapAssert(nibblyMapDescribe('area_media', true)['url'] === '/admin/dashboard.php#media', 'media');
mapAssert(nibblyMapDescribe('nope') === null, 'unknown id');
$_SERVER['SCRIPT_NAME'] = '/sub/site/admin/api.php';
mapAssert(nibblyMapDescribe('tab_users', true)['url'] === '/sub/site/admin/dashboard.php#settings/users', 'the dashboard address follows the folder of the site');
foreach (['//evil.example/admin/api.php', '/x/../../admin/api.php', "/admin/api.php?\"><script>", 'https://evil.example/admin/api.php'] as $bad) {
    $_SERVER['SCRIPT_NAME'] = $bad;
    $url = nibblyMapDescribe('tab_users', true)['url'];
    mapAssert(preg_match('#^/(?!/)[A-Za-z0-9._~/-]*dashboard\.php\#settings/users$#', $url) === 1 && !str_contains($url, '..'), "an odd script name must not leak into the link: {$bad} → {$url}");
}
$_SERVER['SCRIPT_NAME'] = '/admin/api.php';
foreach (array_keys($entries) as $id) {
    $url = nibblyMapDescribe($id, true)['url'];
    mapAssert(preg_match('#^/[A-Za-z0-9._~/-]+dashboard\.php\#[a-z-]+(/[a-z-]+)?$#', $url) === 1, "{$id}: link looks wrong: {$url}");
}

// ---- Roles -------------------------------------------------------------------------------------------------------------
$editorLanguage = nibblyMapDescribe('set_admin_language', false);
mapAssert($editorLanguage['restricted'] && $editorLanguage['url'] === '' && $editorLanguage['note'] !== '', 'editors get no link to admin settings');
mapAssert(!nibblyMapDescribe('tab_my_account', false)['restricted'], 'the own account is for editors too');
mapAssert(!nibblyMapDescribe('area_pages', false)['restricted'] && nibblyMapDescribe('area_backup', false)['restricted'], 'areas by role');
mapAssert(!in_array('set_admin_language', nibblyMapToolIds(false), true) && in_array('set_admin_language', nibblyMapToolIds(true), true), 'tool topics by role');
$editorFound = nibblyMapLookup('Systemsprache ändern', false, 2);
mapAssert($editorFound && $editorFound[0]['restricted'] && $editorFound[0]['url'] === '', 'an editor still learns where it is, without a link');

// ---- Modules switched off ------------------------------------------------------------------------------------------------
$off = nibblyMapDescribe('area_news', true);
mapAssert($off['available'], 'news is on by default');
$settingsFile = sys_get_temp_dir() . '/nibbly-map-settings-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($settingsFile, json_encode(['modules' => ['news' => false, 'ai' => false]]));
register_shutdown_function(static fn() => @unlink($settingsFile));
define('SETTINGS_PATH', $settingsFile);
$off = nibblyMapDescribe('area_news', true);
mapAssert(!$off['available'] && str_ends_with($off['url'], '#settings/modules') && str_contains($off['note'], 'Settings › Modules'), 'a module that is off points to the modules tab: ' . json_encode($off));
$ai = nibblyMapDescribe('tab_ai', true);
mapAssert(!$ai['available'] && str_ends_with($ai['url'], '#settings/modules'), 'the AI tab is not there when AI is off');

// ---- Knowledge -----------------------------------------------------------------------------------------------------------------
$knowledge = nibblyMapKnowledge(true);
mapAssert(str_contains($knowledge, 'set_admin_language: Settings › Language › Admin Language'), 'the knowledge names the language setting');
mapAssert(!str_contains(nibblyMapKnowledge(false), 'set_admin_language'), 'editors do not get admin places in the knowledge');
mapAssert(mb_strlen($knowledge, 'UTF-8') < 9000, 'the knowledge block stays small: ' . mb_strlen($knowledge, 'UTF-8'));
mapAssert(str_contains(nibblyMapStructureSummary(true), 'Settings › Language'), 'the structure summary names the dashboard language place');

echo json_encode(['ok' => true, 'entries' => count($entries), 'languages' => count($languages)]) . "\n";
