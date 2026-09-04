<?php
/** Reservations and usage share one transaction, so concurrent callers cannot oversubscribe. */
function nibblyAiWithRequest(callable $run, ?string $id = null): array {
    if (!empty($GLOBALS['nibblyAiRequestId'])) return $run();
    $id = $id ?: 'req_' . bin2hex(random_bytes(16));
    $GLOBALS['nibblyAiRequestId'] = $id;
    $GLOBALS['nibblyAiDispatched'] = false;
    try {
        $result = $run();
        $result['requestId'] = $id;
        return $result;
    } catch (Throwable $error) {
        nibblyAiRequestPatch($id, [
            'status' => !empty($GLOBALS['nibblyAiDispatched']) ? 'uncertain' : 'released',
            'error' => nibblyAiUtf8Prefix($error->getMessage(), 500),
            'updatedAt' => gmdate('c')
        ], true);
        throw $error;
    } finally {
        unset($GLOBALS['nibblyAiRequestId'], $GLOBALS['nibblyAiDispatched']);
    }
}
function nibblyAiRequestPatch(string $id, array $patch, bool $onlyUnsettled = false): void {
    $ok = nibblyJsonUpdate(NIBBLY_AI_USAGE_PATH, static function (&$usage) use ($id, $patch, $onlyUnsettled) {
        if (!isset($usage['reservations'][$id])) return false;
        if ($onlyUnsettled && in_array($usage['reservations'][$id]['status'] ?? '', ['settled', 'released'], true)) return false;
        $usage['reservations'][$id] = array_replace($usage['reservations'][$id], $patch);
    });
    // An absent reservation means validation failed before the reservation was created.
    if (!$ok && isset(nibblyAiLoadUsage()['reservations'][$id])) {
        $current = nibblyAiLoadUsage()['reservations'][$id];
        if (!$onlyUnsettled || !in_array($current['status'] ?? '', ['settled', 'released'], true)) throw new RuntimeException('Could not persist AI request state.');
    }
}
function nibblyAiMarkDispatched(): void {
    $id = $GLOBALS['nibblyAiRequestId'] ?? '';
    if ($id === '') return;
    $GLOBALS['nibblyAiDispatched'] = true;
    nibblyAiRequestPatch($id, ['dispatchedAt' => gmdate('c')]);
}
function nibblyAiReservedUsage(array $usage): array {
    foreach (($usage['reservations'] ?? []) as $entry) {
        if (!in_array($entry['status'] ?? '', ['reserved', 'uncertain'], true)) continue;
        foreach ([['days', substr($entry['createdAt'], 0, 10)], ['months', substr($entry['createdAt'], 0, 7)]] as [$group, $key]) {
            $usage[$group][$key] = array_replace(nibblyAiEmptyUsageBucket(), $usage[$group][$key] ?? []);
            $usage[$group][$key]['requests']++;
            $usage[$group][$key][$entry['type'] === 'image' ? 'imageRequests' : 'textRequests']++;
            $usage[$group][$key]['estimatedCostCents'] += $entry['reservedCents'];
        }
    }
    return $usage;
}
function nibblyAiReserve(array $settings, string $type, int $cost, string $id): void {
    $ok = nibblyJsonUpdate(NIBBLY_AI_USAGE_PATH, static function (&$usage) use ($settings, $type, $cost, $id) {
        $previous = $usage['reservations'][$id] ?? null;
        if ($previous) {
            if ($previous['status'] === 'released') throw new RuntimeException('AI reservation was released; create a new request.');
            if ($previous['type'] !== $type) throw new RuntimeException('AI request type mismatch.');
            // A persistent image job resumes the original reservation and price estimate.
            $GLOBALS['nibblyAiDispatched'] = !empty($previous['dispatchedAt']);
            return false;
        }
        nibblyAiCheckLimits($settings, $type, $cost, nibblyAiReservedUsage($usage));
        $usage['reservations'][$id] = [
            'id' => $id, 'type' => $type, 'status' => 'reserved', 'reservedCents' => max(0, $cost),
            'createdAt' => gmdate('c'), 'updatedAt' => gmdate('c'),
            'provider' => $settings['provider'], 'baseUrl' => $settings['baseUrl'],
            'model' => $type === 'image' ? $settings['imageModel'] : $settings['chatModel'],
            'user' => (string)($_SESSION['admin_username'] ?? ''), 'tasks' => []
        ];
    }, ['days' => [], 'months' => [], 'reservations' => []]);
    if (!$ok && !isset(nibblyAiLoadUsage()['reservations'][$id])) throw new RuntimeException('Could not reserve AI budget.');
}
function nibblyAiReservationSummary(array $usage): array {
    $total = 0; $count = 0; $unresolved = [];
    foreach (($usage['reservations'] ?? []) as $entry) {
        if (!in_array($entry['status'] ?? '', ['reserved', 'uncertain'], true)) continue;
        $count++;
        if (substr($entry['createdAt'], 0, 7) === gmdate('Y-m')) $total += $entry['reservedCents'];
        $unresolved[] = $entry;
    }
    return ['reservedCents' => $total, 'pendingRequests' => $count, 'unresolved' => $unresolved];
}
function nibblyAiResolveReservation(string $id, string $resolution): void {
    if (!in_array($resolution, ['charged', 'released'], true)) throw new RuntimeException('Invalid resolution');
    $ok = nibblyJsonUpdate(NIBBLY_AI_USAGE_PATH, static function (&$usage) use ($id, $resolution) {
        $entry = $usage['reservations'][$id] ?? null;
        if (!$entry || !in_array($entry['status'] ?? '', ['reserved', 'uncertain'], true)) throw new RuntimeException('Request is already resolved.');
        // Never release a possibly live worker's reservation.
        if (strtotime($entry['updatedAt'] ?? $entry['createdAt']) > time() - 900) throw new RuntimeException('Wait until the request has stopped before resolving it.');
        if ($resolution === 'charged') {
            foreach ([['days', substr($entry['createdAt'], 0, 10)], ['months', substr($entry['createdAt'], 0, 7)]] as [$group, $key]) {
                $usage[$group][$key] = array_replace(nibblyAiEmptyUsageBucket(), $usage[$group][$key] ?? []);
                $usage[$group][$key]['requests']++;
                $usage[$group][$key][$entry['type'] === 'image' ? 'imageRequests' : 'textRequests']++;
                $usage[$group][$key]['estimatedCostCents'] += $entry['reservedCents'];
            }
        }
        $usage['reservations'][$id]['status'] = $resolution === 'charged' ? 'settled' : 'released';
        $usage['reservations'][$id]['resolution'] = $resolution;
        $usage['reservations'][$id]['resolvedBy'] = (string)($_SESSION['admin_username'] ?? '');
        $usage['reservations'][$id]['updatedAt'] = gmdate('c');
    });
    if (!$ok) throw new RuntimeException('Could not resolve AI request.');
}
