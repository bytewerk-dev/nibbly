<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }
require_once NIBBLY_ADMIN_DIR . '/../includes/people.php';
$settings = is_file(SETTINGS_PATH) ? json_decode(file_get_contents(SETTINGS_PATH), true) : [];
if (($settings['modules']['people'] ?? true) === false) { http_response_code(403); jsonResponse(false, null, 'Forbidden'); }
$path = nibblyPeoplePath();
$lock = nibblyJsonLock($path);
if ($lock === false) { http_response_code(503); jsonResponse(false, null, 'Storage unavailable'); }
register_shutdown_function(static function () use ($lock): void { flock($lock, LOCK_UN); fclose($lock); });
$GLOBALS['nibblyRevisionPath'] = $path;
try { $data = nibblyPeopleRead(); }
catch (RuntimeException $e) { http_response_code(503); jsonResponse(false, null, t('people.storage_error')); }
// Records list their page slug, also older ones that were saved before slugs existed
$withSlugs = fn(array $people) => array_map(fn($p) => is_array($p) ? $p + ['slug' => nibblyPersonSlug($p)] : $p, array_values($people));
if ($action === 'load-people') jsonResponse(true, ['people' => $withSlugs($data['people']), 'languages' => nibblyPeopleLanguages()]);
$revision = $_POST['revision'] ?? null;
if (!is_string($revision) || $revision === '') { http_response_code(428); jsonResponse(false, null, t('conflict.reload_required')); }
if (!hash_equals(nibblyJsonRevision($path), $revision)) { http_response_code(409); jsonResponse(false, ['conflict' => true], t('conflict.changed')); }
$id = $_POST['id'] ?? '';
if (!is_string($id) || ($id !== '' && !preg_match('/^person_[a-f0-9]{16}$/', $id))) { http_response_code(422); jsonResponse(false, null, t('people.invalid')); }
$index = null;
foreach ($data['people'] as $i => $p) if (($p['id'] ?? null) === $id) { $index = $i; break; }
if ($id !== '' && $index === null) { http_response_code(404); jsonResponse(false, null, t('people.not_found')); }
if ($action === 'save-person') {
    $input = json_decode($_POST['person'] ?? '', true);
    if (!is_array($input)) { http_response_code(422); jsonResponse(false, null, t('people.invalid')); }
    if ($index !== null && $data['people'][$index]['status'] === 'trash') { http_response_code(409); jsonResponse(false, null, t('people.restore_first')); }
    try { $person = nibblyPersonNormalize($input, $index === null ? [] : $data['people'][$index]); }
    catch (InvalidArgumentException $e) { http_response_code(422); jsonResponse(false, null, t($e->getMessage())); }
    // The page URL stays stable once set, also when the name changes later
    if (empty($person['slug'])) $person['slug'] = nibblyPersonUniqueSlug($person, $data['people']);
    if ($index === null) $data['people'][] = $person; else $data['people'][$index] = $person;
} elseif ($index !== null && $action === 'trash-person') {
    if ($data['people'][$index]['status'] !== 'trash') {
        $data['people'][$index]['previousStatus'] = $data['people'][$index]['status'];
        $data['people'][$index]['status'] = 'trash';
        $data['people'][$index]['trashedAt'] = date('c');
    }
} elseif ($index !== null && $action === 'restore-person') {
    // Only a trashed profile can be restored; anything else would silently unpublish it
    if (($data['people'][$index]['status'] ?? '') !== 'trash') { http_response_code(409); jsonResponse(false, null, t('people.invalid')); }
    // Restore as a draft: recovery never silently republishes a profile.
    $data['people'][$index]['status'] = 'draft';
    unset($data['people'][$index]['trashedAt'], $data['people'][$index]['previousStatus']);
} elseif ($index !== null && $action === 'delete-person') {
    // Permanent deletion only from the trash
    if (($data['people'][$index]['status'] ?? '') !== 'trash') { http_response_code(409); jsonResponse(false, null, t('people.invalid')); }
    array_splice($data['people'], $index, 1);
} elseif ($action === 'empty-people-trash') {
    $data['people'] = array_values(array_filter($data['people'], fn($p) => ($p['status'] ?? '') !== 'trash'));
} else { http_response_code(422); jsonResponse(false, null, t('people.invalid')); }
if (is_file($path)) {
    if (!is_dir(BACKUP_PATH)) @mkdir(BACKUP_PATH, 0755, true);
    if (!copy($path, BACKUP_PATH . 'people_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '.json')) {
        http_response_code(503); jsonResponse(false, null, 'Storage unavailable');
    }
}
$data['updatedAt'] = date('c');
if (!nibblyJsonAtomicWrite($path, $data)) { http_response_code(503); jsonResponse(false, null, 'Error saving'); }
$backups = glob(BACKUP_PATH . 'people_*.json') ?: []; rsort($backups);
foreach (array_slice($backups, defined('MAX_BACKUPS') ? MAX_BACKUPS : 30) as $old) @unlink($old);
jsonResponse(true, ['people' => $withSlugs($data['people']), 'person' => $person ?? null]);
