<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
require_once NIBBLY_ADMIN_DIR . '/../includes/menu-helpers.php';

/** Lock content/menus.json and footer.json in one fixed order; released at shutdown. */
function nibblyMenusLockStores(): void {
    foreach ([nibblyMenusRegistryPath(), nibblyFooterContentPath()] as $path) {
        $lock = nibblyJsonLock($path);
        if ($lock === false) { http_response_code(503); jsonResponse(false, null, 'Storage unavailable'); }
        register_shutdown_function(static function () use ($lock): void { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } });
        if (is_file($path) && !is_array(json_decode((string)file_get_contents($path), true))) {
            http_response_code(503);
            jsonResponse(false, null, 'Storage is damaged; existing data was preserved');
        }
    }
}

/** 428 without a revision, 409 when the stored bytes changed since the editor loaded them. */
function nibblyMenusCheckRevisions(array $revisions, array $resources): void {
    $paths = ['menus' => nibblyMenusRegistryPath(), 'footer' => nibblyFooterContentPath()];
    $stale = [];
    foreach ($resources as $resource) {
        $revision = $revisions[$resource] ?? null;
        if (!is_string($revision) || $revision === '') {
            http_response_code(428);
            jsonResponse(false, null, t('conflict.reload_required'));
        }
        if (!hash_equals(nibblyJsonRevision($paths[$resource]), $revision)) $stale[] = $resource;
    }
    if ($stale) {
        http_response_code(409);
        jsonResponse(false, ['conflict' => true, 'resources' => $stale], t('conflict.changed'));
    }
}

/** Menus & Footer can be switched off under Settings → Modules; the site keeps rendering its menus. */
function nibblyMenusModuleEnabled(): bool {
    $settings = defined('SETTINGS_PATH') && is_file(SETTINGS_PATH) ? json_decode((string)file_get_contents(SETTINGS_PATH), true) : [];
    return !isset($settings['modules']['menus']) || !empty($settings['modules']['menus']);
}

if (in_array($action, ['load-menus-footer', 'save-menus-footer', 'list-menus-backups', 'restore-menus-backup'], true)) {
    if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
    if (!nibblyMenusModuleEnabled()) { http_response_code(403); jsonResponse(false, null, 'Menus & Footer is switched off under Settings → Modules'); }
}

switch ($action) {
    case 'get-menu-items':
        if (!file_exists(NIBBLY_ADMIN_DIR . '/../includes/nav-config.php')) {
            $NAV_ITEMS = [];
        } else {
            include_once NIBBLY_ADMIN_DIR . '/../includes/nav-config.php';
            if (!isset($NAV_ITEMS)) $NAV_ITEMS = [];
        }

        $menuId = trim($_GET['menu'] ?? '');
        $lang = trim($_GET['lang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'));

        if (!$menuId) {
            jsonResponse(false, null, 'Missing menu parameter');
        }

        $allNavItems = $NAV_ITEMS[$lang] ?? [];
        $items = getMenuItems($menuId, $lang, '', $allNavItems);

        jsonResponse(true, ['items' => $items, 'menu' => $menuId, 'lang' => $lang]);
        break;

    // Kept for older clients. A managed menu is reordered in place, so the
    // order never becomes a second source next to menus.{id}.items.
    case 'save-menu-order':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $menuId = trim($_POST['menu'] ?? '');
        $lang = trim($_POST['lang'] ?? '');
        $orderRaw = $_POST['order'] ?? '';

        if (!$menuId || !$lang) {
            jsonResponse(false, null, 'Missing menu or lang parameter');
        }

        $order = json_decode($orderRaw, true);
        if (!is_array($order)) {
            jsonResponse(false, null, 'Invalid order data');
        }

        // Sanitize: only allow valid slug characters
        $order = array_values(array_filter($order, fn($s) => is_string($s) && nibblyPageIsValidPath($s)));

        $menusPath = nibblyMenusRegistryPath();
        $registry = nibblyMenusReadRegistry();
        if (!isset($registry['menus'][$menuId])) {
            jsonResponse(false, null, 'Unknown menu: ' . $menuId);
        }

        nibblyMenusBackupRegistry();
        $saved = nibblyJsonUpdate($menusPath, function (array &$data) use ($menuId, $lang, $order) {
            if (is_array($data['menus'][$menuId]['items'][$lang] ?? null)) {
                $position = array_flip($order);
                $items = $data['menus'][$menuId]['items'][$lang];
                usort($items, fn($a, $b) => ($position[$a['page'] ?? ''] ?? PHP_INT_MAX) <=> ($position[$b['page'] ?? ''] ?? PHP_INT_MAX));
                $data['menus'][$menuId]['items'][$lang] = $items;
                return;
            }
            $data['menus'][$menuId]['order'][$lang] = $order;
        }, ['menus' => []]);
        if (!$saved) jsonResponse(false, null, 'Error saving');
        jsonResponse(true, null, 'Menu order saved');
        break;

    case 'load-menus-footer':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        jsonResponse(true, nibblyMenusEditorState());
        break;

    case 'list-menus-backups':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        jsonResponse(true, ['backups' => nibblyMenusRegistryBackups()]);
        break;

    case 'save-menus-footer':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        $payload = json_decode((string)($_POST['payload'] ?? ''), true);
        $revisions = json_decode((string)($_POST['revisions'] ?? ''), true);
        if (!is_array($payload) || !is_array($revisions)) jsonResponse(false, null, 'Invalid JSON format');

        $menuChanges = is_array($payload['menus'] ?? null) ? $payload['menus'] : [];
        $resets = is_array($payload['reset'] ?? null) ? $payload['reset'] : [];
        $footerChanges = is_array($payload['footer'] ?? null) ? $payload['footer'] : [];
        $assignments = is_array($payload['assign'] ?? null) ? $payload['assign'] : [];
        $writeMenus = $menuChanges || $resets || $assignments;
        $writeFooter = (bool)$footerChanges;
        if (!$writeMenus && !$writeFooter) jsonResponse(true, nibblyMenusEditorState(), 'Nothing to save');

        nibblyMenusLockStores();
        nibblyMenusCheckRevisions($revisions, array_merge($writeMenus ? ['menus'] : [], $writeFooter ? ['footer'] : []));

        $registry = nibblyMenusReadRegistry();
        $definitions = nibblyMenuDefinitions();
        $languages = nibblyMenuSiteLanguages();
        $errors = [];

        // Menus: whole item lists per language; a derived menu is adopted on its first save.
        foreach ($menuChanges as $menuId => $byLang) {
            if (!is_string($menuId) || !isset($definitions[$menuId]) || !is_array($byLang)) {
                $errors[] = ['menu' => (string)$menuId, 'message' => 'Unknown menu: ' . $menuId];
                continue;
            }
            $definition = $definitions[$menuId];
            foreach ($byLang as $lang => $items) {
                if (!is_string($lang) || !isset($languages[$lang]) || !is_array($items)) {
                    $errors[] = ['menu' => $menuId, 'message' => 'Unknown language: ' . $lang];
                    continue;
                }
                $current = nibblyMenuManagedItems($menuId, $lang, $registry) ?? nibblyMenuDerived($definition, $lang, $registry)['items'];
                $seen = [];
                $itemErrors = [];
                $clean = nibblyMenuSanitizeItems($items, nibblyMenuIndexItems($current), $lang, 1, $definition['depth'], $itemErrors, $seen);
                foreach ($itemErrors as $error) $errors[] = $error + ['menu' => $menuId, 'lang' => $lang];
                if (!isset($registry['menus'][$menuId])) {
                    $registry['menus'][$menuId] = ['label' => $definition['label'], 'weight' => $definition['weight']];
                    if (!empty($definition['adapter']['template'])) $registry['menus'][$menuId]['template'] = $definition['adapter']['template'];
                }
                $registry['menus'][$menuId]['items'][$lang] = $clean;
            }
        }

        // Reset = rollback to the derived source (nav-config.php, page "nav" fields, template defaults).
        foreach ($resets as $reset) {
            $menuId = is_array($reset) ? ($reset['menu'] ?? '') : '';
            $lang = is_array($reset) ? ($reset['lang'] ?? '') : '';
            if (!is_string($menuId) || !is_string($lang) || !isset($registry['menus'][$menuId]['items'][$lang])) continue;
            unset($registry['menus'][$menuId]['items'][$lang]);
            if (!$registry['menus'][$menuId]['items']) unset($registry['menus'][$menuId]['items']);
            // An adapter menu that only existed to hold items goes back to the template.
            if (isset($definitions[$menuId]['adapter']) && array_keys($registry['menus'][$menuId]) == ['label', 'weight', 'template']) {
                unset($registry['menus'][$menuId]);
            }
        }

        // Assign detected footer keys to a type, or ignore them; keyed by path, so never twice.
        $footerData = nibblyFooterReadData();
        $adapterFields = nibblyFooterFieldAdapters();
        foreach ($assignments as $path => $type) {
            if (!nibblyFooterIsValidPath($path) || isset($adapterFields[$path]) || nibblyFooterGet($footerData, $path) === null) {
                $errors[] = ['field' => (string)$path, 'message' => 'Unknown footer field: ' . $path];
                continue;
            }
            if ($type === '' || $type === null) {
                unset($registry['footer']['fields'][$path]);
            } elseif ($type === 'ignore' || in_array($type, NIBBLY_FOOTER_TYPES, true)) {
                $previous = is_array($registry['footer']['fields'][$path] ?? null) ? $registry['footer']['fields'][$path] : [];
                $registry['footer']['fields'][$path] = array_replace($previous, ['type' => $type]);
            } else {
                $errors[] = ['field' => (string)$path, 'message' => 'Unknown field type: ' . $type];
            }
        }
        if (isset($registry['footer']['fields']) && !$registry['footer']['fields']) unset($registry['footer']);

        // Footer values, written where they already live.
        $schema = nibblyFooterSchema($registry);
        $newFooter = $footerData;
        foreach ($footerChanges as $path => $value) {
            $field = $schema[$path] ?? null;
            if (!$field || $field['type'] === 'ignore') {
                $errors[] = ['field' => (string)$path, 'message' => 'This footer field cannot be edited: ' . $path];
                continue;
            }
            $error = null;
            $field['numbered'] = nibblyFooterIsNumberedObject($path);
            $clean = nibblyFooterSanitizeValue($field, $value, nibblyFooterGet($footerData, $path), $error);
            if ($error !== null) { $errors[] = ['field' => $path, 'message' => $error]; continue; }
            nibblyFooterSet($newFooter, $path, $clean);
        }

        if ($errors) {
            http_response_code(422);
            jsonResponse(false, ['errors' => $errors], $errors[0]['message']);
        }

        if ($writeMenus) {
            if (!nibblyMenusBackupRegistry()) jsonResponse(false, null, 'Could not create a backup before saving');
            if (!nibblyJsonAtomicWrite(nibblyMenusRegistryPath(), $registry)) jsonResponse(false, null, 'Error saving');
        }
        if ($writeFooter && $newFooter !== $footerData) {
            $footerPath = nibblyFooterContentPath();
            if (is_file($footerPath)) {
                $backupPath = BACKUP_PATH . 'footer_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '.json';
                if (!copy($footerPath, $backupPath)) jsonResponse(false, null, 'Could not create a backup before saving');
                cleanupOldBackups('footer');
            } else {
                $newFooter = ['page' => 'footer'] + $newFooter;
            }
            $newFooter['lastModified'] = date('c');
            if (!nibblyJsonAtomicWrite($footerPath, $newFooter)) jsonResponse(false, null, 'Error saving');
        }
        jsonResponse(true, nibblyMenusEditorState(), 'Saved successfully');
        break;

    case 'restore-menus-backup':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        $backup = (string)($_POST['backup'] ?? '');
        if (!preg_match('/^menus-registry_\d{4}-\d{2}-\d{2}_\d{6}_[a-f0-9]{6}\.json$/', $backup) || !is_file(BACKUP_PATH . $backup)) {
            jsonResponse(false, null, 'Backup not found');
        }
        $restored = json_decode((string)file_get_contents(BACKUP_PATH . $backup), true);
        if (!is_array($restored) || !is_array($restored['menus'] ?? null)) jsonResponse(false, null, 'Backup is damaged');
        nibblyMenusLockStores();
        nibblyMenusCheckRevisions(['menus' => $_POST['revision'] ?? null], ['menus']);
        if (!nibblyMenusBackupRegistry()) jsonResponse(false, null, 'Could not create a backup before saving');
        if (!nibblyJsonAtomicWrite(nibblyMenusRegistryPath(), $restored)) jsonResponse(false, null, 'Error saving');
        jsonResponse(true, nibblyMenusEditorState(), 'Backup restored');
        break;
}
