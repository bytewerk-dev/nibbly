<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'load-events':
        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(true, ['events' => [], 'lastModified' => null]);
        }
        $content = json_decode(file_get_contents(EVENTS_PATH), true);
        jsonResponse(true, $content);
        break;

    case 'save-event':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventData = $_POST['event'] ?? '';
        $event = json_decode($eventData, true);
        if (!is_array($event)) {
            jsonResponse(false, null, 'Invalid JSON format');
        }

        // Validate: date required, title in at least one language required
        $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
        $hasTitle = false;
        if (!empty($event['title']) && is_array($event['title'])) {
            foreach ($event['title'] as $t) {
                if (!empty($t)) { $hasTitle = true; break; }
            }
        }
        if (empty($event['date']) || !$hasTitle) {
            jsonResponse(false, null, 'Date and title are required');
        }

        $data = file_exists(EVENTS_PATH)
            ? json_decode(file_get_contents(EVENTS_PATH), true)
            : ['events' => []];

        // Create backup
        if (file_exists(EVENTS_PATH)) {
            $timestamp = date('Y-m-d_His');
            $backupPath = BACKUP_PATH . 'events_' . $timestamp . '.json';
            copy(EVENTS_PATH, $backupPath);

            $backups = glob(BACKUP_PATH . 'events_*.json');
            usort($backups, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            while (count($backups) > MAX_BACKUPS) {
                $oldBackup = array_pop($backups);
                unlink($oldBackup);
            }
        }

        if (empty($event['id'])) {
            // Use default language title for ID, fallback to first available
            $titleForId = $event['title'][$defaultLang] ?? reset($event['title']);
            $event['id'] = $event['date'] . '-' . preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $titleForId)));
        }

        $found = false;
        foreach ($data['events'] as $index => $existing) {
            if ($existing['id'] === $event['id']) {
                $data['events'][$index] = $event;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['events'][] = $event;
        }

        $data['lastModified'] = date('c');

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error saving');
        }

        jsonResponse(true, ['id' => $event['id']], $found ? 'Event updated' : 'Event created');
        break;

    case 'delete-event':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventId = $_POST['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        if (!isset($data['trash']) || !is_array($data['trash'])) {
            $data['trash'] = [];
        }

        $timestamp = date('Y-m-d_His');
        $backupPath = BACKUP_PATH . 'events_' . $timestamp . '.json';
        copy(EVENTS_PATH, $backupPath);

        // Move event to trash array (instead of deleting)
        $movedEvent = null;
        foreach ($data['events'] as $idx => $existing) {
            if ($existing['id'] === $eventId) {
                $movedEvent = $existing;
                array_splice($data['events'], $idx, 1);
                break;
            }
        }

        if ($movedEvent === null) {
            jsonResponse(false, null, 'Event not found');
        }

        $data['trash'][] = [
            'event' => $movedEvent,
            'deletedAt' => date('c'),
        ];

        $data['lastModified'] = date('c');

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error moving event to trash');
        }

        jsonResponse(true, null, 'Event moved to trash');
        break;

    case 'list-events-trash':
        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(true, []);
        }
        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        $trash = $data['trash'] ?? [];
        // Sort newest first
        usort($trash, function($a, $b) {
            return strcmp($b['deletedAt'] ?? '', $a['deletedAt'] ?? '');
        });
        jsonResponse(true, $trash);
        break;

    case 'restore-event':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventId = $_POST['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        if (!isset($data['trash']) || !is_array($data['trash'])) {
            jsonResponse(false, null, 'Event not in trash');
        }

        $restored = null;
        foreach ($data['trash'] as $idx => $item) {
            if (($item['event']['id'] ?? null) === $eventId) {
                $restored = $item['event'];
                array_splice($data['trash'], $idx, 1);
                break;
            }
        }

        if ($restored === null) {
            jsonResponse(false, null, 'Event not found in trash');
        }

        // Avoid id collision: if restored id already exists, append a suffix
        $existingIds = array_map(function($e) { return $e['id'] ?? ''; }, $data['events']);
        if (in_array($restored['id'], $existingIds, true)) {
            $restored['id'] = $restored['id'] . '-restored-' . time();
        }
        $data['events'][] = $restored;
        $data['lastModified'] = date('c');

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error restoring event');
        }

        jsonResponse(true, ['id' => $restored['id']], 'Event restored');
        break;

    case 'delete-event-permanent':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventId = $_POST['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        if (!isset($data['trash']) || !is_array($data['trash'])) {
            jsonResponse(false, null, 'Event not in trash');
        }

        $found = false;
        foreach ($data['trash'] as $idx => $item) {
            if (($item['event']['id'] ?? null) === $eventId) {
                array_splice($data['trash'], $idx, 1);
                $found = true;
                break;
            }
        }

        if (!$found) {
            jsonResponse(false, null, 'Event not found in trash');
        }

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error deleting event permanently');
        }

        jsonResponse(true, null, 'Event permanently deleted');
        break;

    case 'empty-events-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(true, null, 'Trash is empty');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);
        $data['trash'] = [];

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error emptying trash');
        }

        jsonResponse(true, null, 'Events trash emptied');
        break;

    case 'toggle-event-visibility':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $eventId = $_POST['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);

        $found = false;
        $nowHidden = false;
        foreach ($data['events'] as $index => $existing) {
            if ($existing['id'] === $eventId) {
                $nowHidden = empty($existing['hidden']);
                if ($nowHidden) {
                    $data['events'][$index]['hidden'] = true;
                } else {
                    unset($data['events'][$index]['hidden']);
                }
                $found = true;
                break;
            }
        }

        if (!$found) {
            jsonResponse(false, null, 'Event not found');
        }

        $data['lastModified'] = date('c');

        $result = file_put_contents(
            EVENTS_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($result === false) {
            jsonResponse(false, null, 'Error saving');
        }

        jsonResponse(true, ['hidden' => $nowHidden], $nowHidden ? 'Event hidden' : 'Event visible');
        break;

    case 'load-event':
        $eventId = $_GET['id'] ?? '';
        if (empty($eventId)) {
            jsonResponse(false, null, 'Event ID missing');
        }

        if (!file_exists(EVENTS_PATH)) {
            jsonResponse(false, null, 'No events found');
        }

        $data = json_decode(file_get_contents(EVENTS_PATH), true);

        foreach ($data['events'] as $event) {
            if ($event['id'] === $eventId) {
                jsonResponse(true, $event);
            }
        }

        jsonResponse(false, null, 'Event not found');
        break;

    // ============================================================
    // IMAGE MANAGEMENT
    // ============================================================

}
