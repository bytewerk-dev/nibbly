<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.

if (!function_exists('nibblyEventsBackup')) {
    /** Copy the current events file into the backup folder and drop the oldest copies. */
    function nibblyEventsBackup(): void {
        if (!is_file(EVENTS_PATH)) {
            return;
        }
        copy(EVENTS_PATH, BACKUP_PATH . 'events_' . date('Y-m-d_His') . '.json');
        $backups = glob(BACKUP_PATH . 'events_*.json') ?: [];
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        while (count($backups) > MAX_BACKUPS) {
            unlink(array_pop($backups));
        }
    }

    /**
     * One locked read-modify-write of events.json. The callback edits the data by
     * reference and returns null to write, or an error message to abort.
     *
     * @return true|string|false true = written, string = callback error, false = storage failure
     */
    function nibblyEventsMutate(callable $change, bool $backup = false) {
        $error = null;
        $ok = nibblyJsonUpdate(EVENTS_PATH, function (array &$data) use ($change, $backup, &$error) {
            if (!isset($data['events']) || !is_array($data['events'])) {
                $data['events'] = [];
            }
            $error = $change($data);
            if ($error !== null) {
                return false;
            }
            if ($backup) {
                nibblyEventsBackup();
            }
            return true;
        }, ['events' => []]);
        if ($error !== null) {
            return $error;
        }
        return $ok;
    }
}

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

        if (empty($event['id'])) {
            // Use default language title for ID, fallback to first available
            $titleForId = $event['title'][$defaultLang] ?? reset($event['title']);
            $event['id'] = $event['date'] . '-' . preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $titleForId)));
        }

        $found = false;
        $result = nibblyEventsMutate(function (array &$data) use ($event, &$found) {
            foreach ($data['events'] as $index => $existing) {
                if (($existing['id'] ?? null) === $event['id']) {
                    $data['events'][$index] = $event;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $data['events'][] = $event;
            }
            $data['lastModified'] = date('c');
            return null;
        }, true);

        if ($result !== true) {
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

        $result = nibblyEventsMutate(function (array &$data) use ($eventId) {
            if (!isset($data['trash']) || !is_array($data['trash'])) {
                $data['trash'] = [];
            }

            // Move event to trash array (instead of deleting)
            $movedEvent = null;
            foreach ($data['events'] as $idx => $existing) {
                if (($existing['id'] ?? null) === $eventId) {
                    $movedEvent = $existing;
                    array_splice($data['events'], $idx, 1);
                    break;
                }
            }
            if ($movedEvent === null) {
                return 'Event not found';
            }

            $data['trash'][] = [
                'event' => $movedEvent,
                'deletedAt' => date('c'),
            ];
            $data['lastModified'] = date('c');
            return null;
        }, true);

        if ($result !== true) {
            jsonResponse(false, null, is_string($result) ? $result : 'Error moving event to trash');
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

        $restored = null;
        $result = nibblyEventsMutate(function (array &$data) use ($eventId, &$restored) {
            if (!isset($data['trash']) || !is_array($data['trash'])) {
                return 'Event not in trash';
            }

            foreach ($data['trash'] as $idx => $item) {
                if (($item['event']['id'] ?? null) === $eventId) {
                    $restored = $item['event'];
                    array_splice($data['trash'], $idx, 1);
                    break;
                }
            }
            if ($restored === null) {
                return 'Event not found in trash';
            }

            // Avoid id collision: if restored id already exists, append a suffix
            $existingIds = array_map(function($e) { return $e['id'] ?? ''; }, $data['events']);
            if (in_array($restored['id'], $existingIds, true)) {
                $restored['id'] = $restored['id'] . '-restored-' . time();
            }
            $data['events'][] = $restored;
            $data['lastModified'] = date('c');
            return null;
        });

        if ($result !== true) {
            jsonResponse(false, null, is_string($result) ? $result : 'Error restoring event');
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

        $result = nibblyEventsMutate(function (array &$data) use ($eventId) {
            if (!isset($data['trash']) || !is_array($data['trash'])) {
                return 'Event not in trash';
            }

            foreach ($data['trash'] as $idx => $item) {
                if (($item['event']['id'] ?? null) === $eventId) {
                    array_splice($data['trash'], $idx, 1);
                    return null;
                }
            }
            return 'Event not found in trash';
        });

        if ($result !== true) {
            jsonResponse(false, null, is_string($result) ? $result : 'Error deleting event permanently');
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

        $result = nibblyEventsMutate(function (array &$data) {
            $data['trash'] = [];
            return null;
        });

        if ($result !== true) {
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

        $nowHidden = false;
        $result = nibblyEventsMutate(function (array &$data) use ($eventId, &$nowHidden) {
            foreach ($data['events'] as $index => $existing) {
                if (($existing['id'] ?? null) === $eventId) {
                    $nowHidden = empty($existing['hidden']);
                    if ($nowHidden) {
                        $data['events'][$index]['hidden'] = true;
                    } else {
                        unset($data['events'][$index]['hidden']);
                    }
                    $data['lastModified'] = date('c');
                    return null;
                }
            }
            return 'Event not found';
        });

        if ($result !== true) {
            jsonResponse(false, null, is_string($result) ? $result : 'Error saving');
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
