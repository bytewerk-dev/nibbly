<?php
/**
 * Privacy-friendly flat-file analytics for the dashboard.
 *
 * Stores aggregate counts only: no raw IP addresses, no raw user agents, and no
 * cross-site tracking identifiers. Unique visitors are short-lived hashes.
 */

if (!defined('NIBBLY_ANALYTICS_PATH')) {
    define('NIBBLY_ANALYTICS_PATH', dirname(__DIR__) . '/content/analytics.json');
}

function nibblyAnalyticsEmptyHourBucket(): array {
    return [
        'views' => 0,
        'visits' => 0,
        'bots' => 0,
        'visitorCount' => 0,
        'sessionCount' => 0,
        'visitors' => [],
        'sessions' => []
    ];
}

function nibblyAnalyticsEmptyHours(): array {
    $hours = [];
    for ($i = 0; $i < 24; $i++) {
        $hours[sprintf('%02d', $i)] = nibblyAnalyticsEmptyHourBucket();
    }
    return $hours;
}

function nibblyAnalyticsEmptyBucket(): array {
    return [
        'views' => 0,
        'visits' => 0,
        'bots' => 0,
        'visitorCount' => 0,
        'sessionCount' => 0,
        'visitors' => [],
        'sessions' => [],
        'pages' => [],
        'referrers' => [],
        'devices' => [],
        'browsers' => [],
        'os' => [],
        'languages' => [],
        'hours' => nibblyAnalyticsEmptyHours()
    ];
}

function nibblyAnalyticsLoad(): array {
    if (!is_file(NIBBLY_ANALYTICS_PATH)) {
        return ['days' => [], 'updatedAt' => null];
    }

    $data = json_decode((string)file_get_contents(NIBBLY_ANALYTICS_PATH), true);
    return is_array($data) ? array_replace(['days' => [], 'updatedAt' => null], $data) : ['days' => [], 'updatedAt' => null];
}

function nibblyAnalyticsSave(array $data): void {
    $dir = dirname(NIBBLY_ANALYTICS_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    nibblyAnalyticsCompact($data, 90);
    file_put_contents(NIBBLY_ANALYTICS_PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function nibblyAnalyticsSetCount(array &$bucket, string $setKey, string $countKey): void {
    if (isset($bucket[$setKey]) && is_array($bucket[$setKey])) {
        $bucket[$countKey] = count($bucket[$setKey]);
        unset($bucket[$setKey]);
        return;
    }
    $bucket[$countKey] = (int)($bucket[$countKey] ?? 0);
}

function nibblyAnalyticsCompact(array &$data, int $detailDays = 90): void {
    $cutoff = date('Y-m-d', time() - max(1, $detailDays) * 86400);
    if (empty($data['days']) || !is_array($data['days'])) {
        return;
    }
    foreach ($data['days'] as $day => &$bucket) {
        if ((string)$day >= $cutoff || !is_array($bucket)) {
            continue;
        }

        nibblyAnalyticsSetCount($bucket, 'visitors', 'visitorCount');
        nibblyAnalyticsSetCount($bucket, 'sessions', 'sessionCount');

        if (!empty($bucket['pages']) && is_array($bucket['pages'])) {
            foreach ($bucket['pages'] as &$page) {
                if (is_array($page)) {
                    nibblyAnalyticsSetCount($page, 'visitors', 'visitorCount');
                }
            }
            unset($page);
        }

        if (!empty($bucket['hours']) && is_array($bucket['hours'])) {
            foreach ($bucket['hours'] as &$hourBucket) {
                if (is_array($hourBucket)) {
                    nibblyAnalyticsSetCount($hourBucket, 'visitors', 'visitorCount');
                    nibblyAnalyticsSetCount($hourBucket, 'sessions', 'sessionCount');
                }
            }
            unset($hourBucket);
        }

        $bucket['compacted'] = true;
    }
    unset($bucket);
}

function nibblyAnalyticsShouldTrack(): bool {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (preg_match('#^/(admin|api|assets|css|js|content|backups)(/|$)#', $path)) {
        return false;
    }
    if (!empty($_SESSION['admin_logged_in'])) {
        return false;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET') {
        return false;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return $accept === '' || $accept === '*/*' || stripos($accept, 'text/html') !== false;
}

function nibblyAnalyticsIsBot(string $ua): bool {
    if ($ua === '') {
        return true;
    }

    $pattern = '/bot|crawler|spider|crawl|slurp|bingpreview|facebookexternalhit|facebot|twitterbot|linkedinbot|whatsapp|telegrambot|pinterest|duckduckbot|baiduspider|yandex|semrush|ahrefs|mj12bot|dotbot|petalbot|bytespider|applebot|google-inspectiontool|lighthouse|pagespeed|uptimerobot|pingdom|headlesschrome|phantomjs|python-requests|curl|wget|go-http-client|httpclient|libwww-perl/i';
    return preg_match($pattern, $ua) === 1;
}

function nibblyAnalyticsTrack(?string $contentPage = null, ?string $currentLang = null, ?string $currentPage = null): void {
    if (!nibblyAnalyticsShouldTrack()) {
        return;
    }

    $data = nibblyAnalyticsLoad();
    $day = date('Y-m-d');
    if (!isset($data['days'][$day]) || !is_array($data['days'][$day])) {
        $data['days'][$day] = nibblyAnalyticsEmptyBucket();
    }
    $bucket =& $data['days'][$day];
    $bucket = array_replace_recursive(nibblyAnalyticsEmptyBucket(), $bucket);
    $hour = date('H');
    if (!isset($bucket['hours'][$hour]) || !is_array($bucket['hours'][$hour])) {
        $bucket['hours'][$hour] = nibblyAnalyticsEmptyHourBucket();
    }
    $bucket['hours'][$hour] = array_replace_recursive(nibblyAnalyticsEmptyHourBucket(), $bucket['hours'][$hour]);

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (nibblyAnalyticsIsBot($ua)) {
        $bucket['bots'] = (int)($bucket['bots'] ?? 0) + 1;
        $bucket['hours'][$hour]['bots'] = (int)($bucket['hours'][$hour]['bots'] ?? 0) + 1;
        $data['updatedAt'] = date('c');
        nibblyAnalyticsSave($data);
        return;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $key = $contentPage ?: trim((string)$currentLang . '_' . (string)$currentPage, '_');
    if ($key === '') {
        $key = $path;
    }

    $visitorId = nibblyAnalyticsVisitorId();
    $sessionId = nibblyAnalyticsSessionId($visitorId);
    $isNewVisitor = empty($bucket['visitors'][$visitorId]);
    $isNewSession = empty($bucket['sessions'][$sessionId]);

    $bucket['views'] = (int)($bucket['views'] ?? 0) + 1;
    $bucket['hours'][$hour]['views'] = (int)($bucket['hours'][$hour]['views'] ?? 0) + 1;
    if ($isNewSession) {
        $bucket['visits'] = (int)($bucket['visits'] ?? 0) + 1;
        $bucket['hours'][$hour]['visits'] = (int)($bucket['hours'][$hour]['visits'] ?? 0) + 1;
    }
    $bucket['visitors'][$visitorId] = true;
    $bucket['sessions'][$sessionId] = true;
    $bucket['hours'][$hour]['visitors'][$visitorId] = true;
    $bucket['hours'][$hour]['sessions'][$sessionId] = true;

    if (!isset($bucket['pages'][$key])) {
        $bucket['pages'][$key] = [
            'views' => 0,
            'visits' => 0,
            'visitors' => [],
            'path' => $path,
            'title' => $currentPage ?: $key
        ];
    }
    $bucket['pages'][$key] = array_replace([
        'views' => 0,
        'visits' => 0,
        'visitors' => [],
        'path' => $path,
        'title' => $currentPage ?: $key
    ], is_array($bucket['pages'][$key]) ? $bucket['pages'][$key] : []);
    if (!is_array($bucket['pages'][$key]['visitors'])) {
        $bucket['pages'][$key]['visitors'] = [];
    }
    $bucket['pages'][$key]['views']++;
    if ($isNewSession) {
        $bucket['pages'][$key]['visits']++;
    }
    $bucket['pages'][$key]['visitors'][$visitorId] = true;
    $bucket['pages'][$key]['path'] = $path;
    $bucket['pages'][$key]['title'] = $currentPage ?: $key;

    nibblyAnalyticsIncrement($bucket['referrers'], nibblyAnalyticsReferrer());
    nibblyAnalyticsIncrement($bucket['devices'], nibblyAnalyticsDevice($ua));
    nibblyAnalyticsIncrement($bucket['browsers'], nibblyAnalyticsBrowser($ua));
    nibblyAnalyticsIncrement($bucket['os'], nibblyAnalyticsOs($ua));
    nibblyAnalyticsIncrement($bucket['languages'], nibblyAnalyticsLanguage());

    $data['updatedAt'] = date('c');
    nibblyAnalyticsSave($data);
}

function nibblyAnalyticsSummary(string $period = 'days', int $count = 30): array {
    $data = nibblyAnalyticsLoad();
    $today = date('Y-m-d');
    $period = in_array($period, ['days', 'months', 'years'], true) ? $period : 'days';
    $count = max(0, $count);
    $totals = [
        'todayViews' => 0,
        'periodViews' => 0,
        'periodVisits' => 0,
        'periodVisitors' => 0,
        'botRequests' => 0
    ];
    $visitorSet = [];
    $pageTotals = [];
    $referrers = [];
    $devices = [];
    $browsers = [];
    $os = [];
    $languages = [];
    $series = [];
    $visitorFallback = 0;

    if ($period === 'months') {
        $count = $count > 0 ? $count : 12;
        $start = new DateTimeImmutable('first day of this month 00:00:00');
        for ($i = $count - 1; $i >= 0; $i--) {
            $date = $start->modify("-{$i} months");
            $key = $date->format('Y-m');
            $series[$key] = [
                'key' => $key,
                'date' => $date->format('Y-m-01'),
                'label' => $date->format('M Y'),
                'views' => 0,
                'visits' => 0,
                'visitors' => [],
                'visitorCount' => 0,
                'bots' => 0
            ];
        }
    } elseif ($period === 'years') {
        $years = [];
        foreach (array_keys($data['days'] ?? []) as $dayKey) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dayKey)) {
                $years[] = substr((string)$dayKey, 0, 4);
            }
        }
        $years[] = date('Y');
        $years = array_values(array_unique($years));
        sort($years);
        if ($count > 0 && count($years) > $count) {
            $years = array_slice($years, -$count);
        }
        foreach ($years as $year) {
            $series[$year] = [
                'key' => $year,
                'date' => $year . '-01-01',
                'label' => $year,
                'views' => 0,
                'visits' => 0,
                'visitors' => [],
                'visitorCount' => 0,
                'bots' => 0
            ];
        }
    } else {
        $count = $count > 0 ? $count : 30;
        for ($i = $count - 1; $i >= 0; $i--) {
            $dayKey = date('Y-m-d', time() - $i * 86400);
            $series[$dayKey] = [
                'key' => $dayKey,
                'date' => $dayKey,
                'label' => $dayKey,
                'views' => 0,
                'visits' => 0,
                'visitors' => [],
                'visitorCount' => 0,
                'bots' => 0
            ];
        }
    }

    foreach ($data['days'] as $day => $bucket) {
        $seriesKey = $period === 'months' ? substr((string)$day, 0, 7) : ($period === 'years' ? substr((string)$day, 0, 4) : (string)$day);
        if (!isset($series[$seriesKey])) {
            continue;
        }
        $bucket = array_replace_recursive(nibblyAnalyticsEmptyBucket(), is_array($bucket) ? $bucket : []);
        $views = (int)($bucket['views'] ?? 0);
        $totals['periodViews'] += $views;
        $totals['periodVisits'] += (int)($bucket['visits'] ?? 0);
        $totals['botRequests'] += (int)($bucket['bots'] ?? 0);
        if ($day === $today) {
            $totals['todayViews'] = $views;
        }
        $series[$seriesKey]['views'] += $views;
        $series[$seriesKey]['visits'] += (int)($bucket['visits'] ?? 0);
        $series[$seriesKey]['bots'] += (int)($bucket['bots'] ?? 0);
        foreach (($bucket['visitors'] ?? []) as $visitor => $_seen) {
            $visitorSet[$visitor] = true;
            $series[$seriesKey]['visitors'][$visitor] = true;
        }
        if (empty($bucket['visitors']) && (int)($bucket['visitorCount'] ?? 0) > 0) {
            $visitorFallback += (int)$bucket['visitorCount'];
            $series[$seriesKey]['visitorCount'] += (int)$bucket['visitorCount'];
        }
        foreach (($bucket['pages'] ?? []) as $key => $page) {
            if (!isset($pageTotals[$key])) {
                $pageTotals[$key] = [
                    'key' => $key,
                    'views' => 0,
                    'visits' => 0,
                    'visitors' => [],
                    'visitorCount' => 0,
                    'path' => $page['path'] ?? '',
                    'title' => $page['title'] ?? $key
                ];
            }
            $pageTotals[$key]['views'] += (int)($page['views'] ?? 0);
            $pageTotals[$key]['visits'] += (int)($page['visits'] ?? 0);
            foreach (($page['visitors'] ?? []) as $visitor => $_seen) {
                $pageTotals[$key]['visitors'][$visitor] = true;
            }
            if (empty($page['visitors']) && (int)($page['visitorCount'] ?? 0) > 0) {
                $pageTotals[$key]['visitorCount'] += (int)$page['visitorCount'];
            }
        }
        nibblyAnalyticsMergeCounts($referrers, $bucket['referrers'] ?? []);
        nibblyAnalyticsMergeCounts($devices, $bucket['devices'] ?? []);
        nibblyAnalyticsMergeCounts($browsers, $bucket['browsers'] ?? []);
        nibblyAnalyticsMergeCounts($os, $bucket['os'] ?? []);
        nibblyAnalyticsMergeCounts($languages, $bucket['languages'] ?? []);
    }

    foreach ($series as &$point) {
        $point['visitors'] = count($point['visitors']) + (int)($point['visitorCount'] ?? 0);
        unset($point['visitorCount']);
    }
    unset($point);

    foreach ($pageTotals as &$page) {
        $page['visitors'] = count($page['visitors']) + (int)($page['visitorCount'] ?? 0);
        unset($page['visitorCount']);
    }
    unset($page);
    usort($pageTotals, fn($a, $b) => $b['views'] <=> $a['views']);
    $totals['periodVisitors'] = count($visitorSet) + $visitorFallback;
    $todayBucket = $data['days'][$today] ?? [];
    $hourlyToday = nibblyAnalyticsHourlySeries(is_array($todayBucket) ? $todayBucket : []);

    return [
        'period' => $period,
        'count' => $count,
        'todayViews' => $totals['todayViews'],
        'periodViews' => $totals['periodViews'],
        'periodVisits' => $totals['periodVisits'],
        'periodVisitors' => $totals['periodVisitors'],
        'botRequests' => $totals['botRequests'],
        'topPages' => array_slice($pageTotals, 0, 8),
        'referrers' => nibblyAnalyticsTopList($referrers, 8),
        'devices' => nibblyAnalyticsTopList($devices, 5),
        'browsers' => nibblyAnalyticsTopList($browsers, 5),
        'os' => nibblyAnalyticsTopList($os, 5),
        'languages' => nibblyAnalyticsTopList($languages, 5),
        'series' => array_values($series),
        'hourlyToday' => $hourlyToday,
        'updatedAt' => $data['updatedAt'] ?? null
    ];
}

function nibblyAnalyticsHourlySeries(array $bucket): array {
    $bucket = array_replace_recursive(nibblyAnalyticsEmptyBucket(), $bucket);
    $hours = [];
    $hasTrackedHours = false;
    for ($i = 0; $i < 24; $i++) {
        $hour = sprintf('%02d', $i);
        $hourBucket = array_replace_recursive(nibblyAnalyticsEmptyHourBucket(), is_array($bucket['hours'][$hour] ?? null) ? $bucket['hours'][$hour] : []);
        $views = (int)($hourBucket['views'] ?? 0);
        $visits = (int)($hourBucket['visits'] ?? 0);
        $bots = (int)($hourBucket['bots'] ?? 0);
        if ($views || $visits || $bots) {
            $hasTrackedHours = true;
        }
        $hours[$hour] = [
            'hour' => $hour,
            'label' => $hour . ':00',
            'views' => $views,
            'visits' => $visits,
            'visitors' => (is_array($hourBucket['visitors'] ?? null) ? count($hourBucket['visitors']) : 0) + (int)($hourBucket['visitorCount'] ?? 0),
            'bots' => $bots
        ];
    }

    if (!$hasTrackedHours && (int)($bucket['views'] ?? 0) > 0) {
        $hour = date('H');
        $hours[$hour]['views'] = (int)($bucket['views'] ?? 0);
        $hours[$hour]['visits'] = (int)($bucket['visits'] ?? 0);
        $hours[$hour]['visitors'] = (is_array($bucket['visitors'] ?? null) ? count($bucket['visitors']) : 0) + (int)($bucket['visitorCount'] ?? 0);
        $hours[$hour]['bots'] = (int)($bucket['bots'] ?? 0);
        $hours[$hour]['legacyEstimate'] = true;
    }

    return array_values($hours);
}

function nibblyAnalyticsVisitorId(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $month = date('Y-m');
    $salt = defined('SITE_NAME') ? SITE_NAME : dirname(__DIR__);
    return hash('sha256', $month . '|' . $ip . '|' . $ua . '|' . $salt);
}

function nibblyAnalyticsSessionId(string $visitorId): string {
    $slot = (int)floor(time() / 1800);
    return hash('sha256', $visitorId . '|' . $slot);
}

function nibblyAnalyticsReferrer(): string {
    $ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($ref === '') {
        return 'Direct';
    }
    $host = parse_url($ref, PHP_URL_HOST);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    if (!$host || strcasecmp($host, $currentHost) === 0) {
        return 'Internal';
    }
    return strtolower(preg_replace('/^www\./i', '', $host));
}

function nibblyAnalyticsDevice(string $ua): string {
    if (preg_match('/ipad|tablet|kindle|silk/i', $ua)) return 'Tablet';
    if (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone/i', $ua)) return 'Mobile';
    return 'Desktop';
}

function nibblyAnalyticsBrowser(string $ua): string {
    if (preg_match('/Edg\//', $ua)) return 'Edge';
    if (preg_match('/OPR\/|Opera/i', $ua)) return 'Opera';
    if (preg_match('/Firefox\//i', $ua)) return 'Firefox';
    if (preg_match('/Chrome\//i', $ua) && !preg_match('/Chromium\//i', $ua)) return 'Chrome';
    if (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome\//i', $ua)) return 'Safari';
    return 'Other';
}

function nibblyAnalyticsOs(string $ua): string {
    if (preg_match('/Windows/i', $ua)) return 'Windows';
    if (preg_match('/iPhone|iPad|iOS/i', $ua)) return 'iOS';
    if (preg_match('/Mac OS X|Macintosh/i', $ua)) return 'macOS';
    if (preg_match('/Android/i', $ua)) return 'Android';
    if (preg_match('/Linux/i', $ua)) return 'Linux';
    return 'Other';
}

function nibblyAnalyticsLanguage(): string {
    $lang = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if (preg_match('/^[a-z]{2}/i', $lang, $m)) {
        return strtolower($m[0]);
    }
    return 'Unknown';
}

function nibblyAnalyticsIncrement(array &$bucket, string $key): void {
    $key = $key !== '' ? $key : 'Unknown';
    $bucket[$key] = (int)($bucket[$key] ?? 0) + 1;
}

function nibblyAnalyticsMergeCounts(array &$target, array $source): void {
    foreach ($source as $key => $count) {
        $target[$key] = (int)($target[$key] ?? 0) + (int)$count;
    }
}

function nibblyAnalyticsTopList(array $counts, int $limit): array {
    arsort($counts);
    $out = [];
    foreach (array_slice($counts, 0, $limit, true) as $label => $count) {
        $out[] = ['label' => (string)$label, 'count' => (int)$count];
    }
    return $out;
}

function nibblyAnalyticsPrune(array &$data): void {
    // Analytics are retained indefinitely. This no-op is kept for compatibility
    // with older integrations that may still call the function.
}
