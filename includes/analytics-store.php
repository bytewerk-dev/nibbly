<?php
/** Partitioned analytics. Legacy import is restartable and retains its source. */
function nibblyAnalyticsDirectory(): string { return dirname(NIBBLY_ANALYTICS_PATH) . '/analytics'; }
function nibblyAnalyticsReadFile(string $path): array {
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Analytics storage is damaged: ' . basename($path));
    return $data;
}
function nibblyAnalyticsMigrate(): void {
    $dir = nibblyAnalyticsDirectory();
    if (is_file($dir . '/format.json')) return;
    $lock = nibblyJsonLock(NIBBLY_ANALYTICS_PATH);
    if (!$lock) throw new RuntimeException('Analytics storage unavailable');
    try {
        if (is_file($dir . '/format.json')) return;
        $legacy = nibblyAnalyticsReadFile(NIBBLY_ANALYTICS_PATH);
        foreach (($legacy['days'] ?? []) as $day => $bucket) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day) || !is_array($bucket)) continue;
            $target = $dir . '/days/' . $day . '.json';
            // No tracking is allowed before the marker. A retry can safely replace partial imports.
            if (!nibblyJsonAtomicWrite($target, ['days' => [$day => $bucket], 'updatedAt' => $legacy['updatedAt'] ?? null])) {
                throw new RuntimeException('Could not migrate analytics');
            }
        }
        // Retain an aggregate migration source without keeping old visitor hashes indefinitely.
        if (is_file(NIBBLY_ANALYTICS_PATH)) {
            foreach ($legacy['days'] as &$bucket) { if (is_array($bucket)) nibblyAnalyticsForgetIdentifiers($bucket); }
            unset($bucket);
            if (!nibblyJsonAtomicWrite(NIBBLY_ANALYTICS_PATH, $legacy)) throw new RuntimeException('Could not compact migration source');
        }
        if (!nibblyJsonAtomicWrite($dir . '/format.json', ['version' => 2, 'migratedAt' => date('c')])) throw new RuntimeException('Could not finish analytics migration');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function nibblyAnalyticsArchive(): void {
    $dir = nibblyAnalyticsDirectory();
    $marker = $dir . '/maintenance.json';
    if ((nibblyAnalyticsReadFile($marker)['day'] ?? '') === date('Y-m-d')) return;
    $lock = nibblyJsonLock($marker);
    if (!$lock) return;
    try {
        if ((nibblyAnalyticsReadFile($marker)['day'] ?? '') === date('Y-m-d')) return;
        $cutoff = date('Y-m-d', strtotime('-90 days'));
        foreach (glob($dir . '/days/*.json') ?: [] as $path) {
            $day = basename($path, '.json');
            if ($day >= $cutoff) continue;
            $data = nibblyAnalyticsReadFile($path);
            nibblyAnalyticsCompact($data, 90);
            // Archive contains per-day aggregates, preserving day/month/year queries.
            $saved = nibblyJsonUpdate($dir . '/archive/' . substr($day, 0, 7) . '.json', static function (&$archive) use ($data) {
                $archive['days'] = array_replace($archive['days'] ?? [], $data['days'] ?? []);
                $archive['updatedAt'] = $data['updatedAt'] ?? null;
            });
            if (!$saved) throw new RuntimeException('Could not archive analytics');
            if (!unlink($path)) throw new RuntimeException('Could not finish analytics archive');
        }
        nibblyJsonAtomicWrite($marker, ['day' => date('Y-m-d')]);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function nibblyAnalyticsLoad(?string $from = null): array {
    nibblyAnalyticsMigrate();
    nibblyAnalyticsArchive();
    $data = ['days' => [], 'updatedAt' => null];
    $dir = nibblyAnalyticsDirectory();
    $files = array_merge(glob($dir . '/archive/*.json') ?: [], glob($dir . '/days/*.json') ?: []);
    foreach ($files as $file) {
        $key = basename($file, '.json');
        if ($from !== null && (strlen($key) === 7 ? $key < substr($from, 0, 7) : $key < $from)) continue;
        $part = nibblyAnalyticsReadFile($file);
        foreach (($part['days'] ?? []) as $day => $bucket) {
            if ($from === null || $day >= $from) $data['days'][$day] = $bucket;
        }
        $data['updatedAt'] = max($data['updatedAt'] ?? '', $part['updatedAt'] ?? '') ?: null;
    }
    return $data;
}
/** Historical summaries are cached briefly; a current-day write invalidates immediately. */
function nibblyAnalyticsSummary(string $period = 'days', int $count = 30): array {
    $period = in_array($period, ['days', 'months', 'years'], true) ? $period : 'days';
    $count = max(0, min($count, $period === 'days' ? 3660 : 120));
    try {
        nibblyAnalyticsMigrate();
        nibblyAnalyticsArchive();
        $dir = nibblyAnalyticsDirectory();
        $revision = date('Y-m-d') . ':' . nibblyJsonRevision($dir . '/days/' . date('Y-m-d') . '.json');
        $path = $dir . '/cache/' . $period . '-' . $count . '.json';
        $cache = nibblyAnalyticsReadFile($path);
        if (($cache['revision'] ?? '') === $revision && ($cache['expires'] ?? 0) > time()) return $cache['summary'];
        $summary = nibblyAnalyticsBuildSummary($period, $count);
        nibblyJsonAtomicWrite($path, ['revision' => $revision, 'expires' => time() + 60, 'summary' => $summary]);
        return $summary;
    } catch (Throwable $error) {
        error_log('Nibbly analytics: ' . $error->getMessage());
        return ['state' => 'error', 'series' => [], 'hourlyToday' => [], 'periodViews' => 0, 'todayViews' => 0];
    }
}
