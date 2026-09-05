<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'system-status':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        require_once NIBBLY_ADMIN_DIR . '/../includes/system-status.php';
        jsonResponse(true, nibblySystemStatus());
        break;
    case 'keepalive':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        jsonResponse(true, ['time' => time()], 'Session refreshed');
        break;

    case 'dashboard-overview':
        $analyticsPeriod = $_GET['analytics_period'] ?? 'days';
        $analyticsCount = isset($_GET['analytics_count']) ? (int)$_GET['analytics_count'] : 30;
        if (!in_array($analyticsPeriod, ['days', 'months', 'years'], true)) {
            $analyticsPeriod = 'days';
        }
        if ($analyticsPeriod === 'months' && $analyticsCount <= 0) {
            $analyticsCount = 12;
        } elseif ($analyticsPeriod === 'years' && $analyticsCount < 0) {
            $analyticsCount = 0;
        } elseif ($analyticsPeriod === 'days' && $analyticsCount <= 0) {
            $analyticsCount = 30;
        }
        $pagesPath = defined('CONTENT_PATH') ? CONTENT_PATH : dirname(NIBBLY_ADMIN_DIR) . '/content/pages/';
        $newsPath = dirname(NIBBLY_ADMIN_DIR) . '/content/news/';
        $pageCount = count(glob($pagesPath . '[a-z][a-z]_*.json') ?: []);
        $newsCount = is_dir($newsPath) ? count(glob($newsPath . '*.json') ?: []) : 0;
        $response = [
            'pages' => $pageCount,
            'news' => $newsCount,
            'status' => dashboardStatusOverview($pagesPath, $newsPath),
            'analytics' => nibblyAnalyticsSummary($analyticsPeriod, $analyticsCount),
        ];
        $settings = is_file(SETTINGS_PATH) ? json_decode(file_get_contents(SETTINGS_PATH), true) : [];
        $response['analytics']['state'] = ($response['analytics']['state'] ?? '') === 'error' ? 'error'
            : (!nibblyAnalyticsEnabled() ? 'disabled' : (empty($response['analytics']['periodViews']) ? 'empty' : 'ready'));
        if (dashboardAiModuleEnabled()) {
            $response['ai'] = [
                'settings' => nibblyAiLoadSettings(true),
                'usage' => nibblyAiUsageSummary()
            ];
        }
        jsonResponse(true, $response);
        break;

    // ============================================================
    // CONTENT MANAGEMENT
    // ============================================================

}
