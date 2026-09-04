<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'list-icons':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        jsonResponse(true, iconManagerListData());
        break;

    // ============================================================
    // AI GATEWAY
    // ============================================================

    case 'iconify-search':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        [$validSearch, $searchData, $searchError] = searchIconifyIcons($_GET['prefix'] ?? '', $_GET['query'] ?? '');
        if (!$validSearch) {
            jsonResponse(false, null, $searchError);
        }
        jsonResponse(true, $searchData);
        break;

    case 'iconify-import':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        [$validImport, $importData, $importError] = importIconifyIcon($_POST['icon'] ?? '');
        if (!$validImport) {
            jsonResponse(false, null, $importError);
        }
        jsonResponse(true, $importData, 'Icon imported.');
        break;

    case 'save-icon':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $oldKey = sanitizeIconKeyInput($_POST['old_key'] ?? '');
        [$validIcon, $normalizedIcon, $iconError] = normalizeIconManagerPayload(
            $_POST['key'] ?? '',
            $_POST['label'] ?? '',
            $_POST['tags'] ?? '',
            $_POST['svg'] ?? '',
            $_POST['viewBox'] ?? ''
        );
        if (!$validIcon) {
            jsonResponse(false, null, $iconError);
        }

        [$newKey, $definition] = $normalizedIcon;
        if ($oldKey === 'default' && $newKey !== 'default') {
            jsonResponse(false, null, 'The fallback icon key cannot be renamed.');
        }
        $rawIconSet = readSiteIconSetRaw();
        $customIcons = normalizeIconSet($rawIconSet);
        $availableIcons = iconManagerListData()['icons'];
        $availableKeys = array_column($availableIcons, 'key');

        if (($oldKey === '' || $oldKey !== $newKey) && in_array($newKey, $availableKeys, true)) {
            jsonResponse(false, null, 'An icon with this key already exists.');
        }

        if ($oldKey !== '' && $oldKey !== $newKey) {
            unset($rawIconSet[$oldKey]);
            if (isset(getDefaultIconSet()[$oldKey])) {
                $rawIconSet['_deleted'] = $rawIconSet['_deleted'] ?? [];
                $rawIconSet['_deleted'][] = $oldKey;
            }
        }

        $previousKey = $oldKey ?: $newKey;
        $previousDefinition = isset($rawIconSet[$previousKey]) && is_array($rawIconSet[$previousKey]) ? $rawIconSet[$previousKey] : [];
        $definition['createdAt'] = isset($previousDefinition['createdAt']) && is_string($previousDefinition['createdAt'])
            ? $previousDefinition['createdAt']
            : date('c');
        $definition['updatedAt'] = date('c');
        $rawIconSet[$newKey] = $definition;
        if (isset($rawIconSet['_deleted']) && is_array($rawIconSet['_deleted'])) {
            $rawIconSet['_deleted'] = array_values(array_filter($rawIconSet['_deleted'], function($key) use ($newKey) {
                return $key !== $newKey;
            }));
        }

        if (!writeSiteIconSetRaw($rawIconSet)) {
            jsonResponse(false, null, 'Could not write icon set.');
        }

        jsonResponse(true, iconManagerListData(), 'Icon saved.');
        break;

    case 'delete-icon':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $key = sanitizeIconKeyInput($_POST['key'] ?? '');
        if ($key === '') {
            jsonResponse(false, null, 'Invalid icon key.');
        }
        if ($key === 'default') {
            jsonResponse(false, null, 'The fallback icon cannot be deleted.');
        }

        $rawIconSet = readSiteIconSetRaw();
        unset($rawIconSet[$key]);
        if (isset(getDefaultIconSet()[$key])) {
            $rawIconSet['_deleted'] = $rawIconSet['_deleted'] ?? [];
            $rawIconSet['_deleted'][] = $key;
        }

        if (!writeSiteIconSetRaw($rawIconSet)) {
            jsonResponse(false, null, 'Could not write icon set.');
        }

        jsonResponse(true, iconManagerListData(), 'Icon deleted.');
        break;

}
