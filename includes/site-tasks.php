<?php
/**
 * Site maintenance tasks: jobs a site registers for administrators to run from the dashboard's
 * system tab. The optional file includes/site-maintenance.php (a site file, survives core updates)
 * returns an array id => ['label' => string, 'description' => string, 'run' => callable]. run()
 * returns the message shown in the dashboard (a string, or ['message' => string]) and throws on
 * failure. Ids: lowercase letters, digits, "-" and "_".
 */

function nibblySiteTasks(): array {
    static $tasks = null;
    if ($tasks !== null) return $tasks;
    $tasks = [];
    $file = __DIR__ . '/site-maintenance.php';
    if (!is_file($file)) return $tasks;
    $list = include $file;
    if (!is_array($list)) return $tasks;
    foreach ($list as $id => $task) {
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) continue;
        if (!is_array($task) || !is_callable($task['run'] ?? null)) continue;
        $tasks[$id] = [
            'id' => $id,
            'label' => trim((string)($task['label'] ?? $id)) ?: $id,
            'description' => trim((string)($task['description'] ?? '')),
            'run' => $task['run'],
        ];
    }
    return $tasks;
}

/** The tasks without their callables, for the dashboard (system-status). */
function nibblySiteTasksPublic(): array {
    return array_values(array_map(
        static fn(array $task): array => ['id' => $task['id'], 'label' => $task['label'], 'description' => $task['description']],
        nibblySiteTasks()
    ));
}

/** Run one task; returns the message for the dashboard. Throws when the task fails or does not exist. */
function nibblySiteTaskRun(string $id): string {
    $task = nibblySiteTasks()[$id] ?? null;
    if ($task === null) throw new RuntimeException('Unknown task');
    $result = ($task['run'])();
    if (is_array($result)) $result = (string)($result['message'] ?? '');
    return is_string($result) ? $result : '';
}
