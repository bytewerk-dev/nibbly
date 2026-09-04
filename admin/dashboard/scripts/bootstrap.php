<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // Block Type Registry
    window.BlockTypeRegistry = <?php
        require_once dirname(NIBBLY_DASHBOARD_DIR) . '/includes/content-loader.php';
        require_once dirname(NIBBLY_DASHBOARD_DIR) . '/includes/menu-helpers.php';
        echo json_encode(getBlockTypes(), JSON_UNESCAPED_UNICODE);
    ?>;

    // Admin translations for JS
    const NB_LANG = <?php echo json_encode(array_merge(tEditorAll(), tAll()), JSON_UNESCAPED_UNICODE); ?>;
    window.NB_LANG = NB_LANG;
    window.NB_AI_CATALOG = <?php echo json_encode(nibblyAiModelCatalog(), JSON_UNESCAPED_SLASHES); ?>;
    window.NB_AI_FEATURES_ENABLED = <?php echo json_encode($aiFeaturesEnabled); ?>;
    window.NB_AI_COPILOT_AVAILABLE = <?php echo json_encode($_aiDashboardCopilotAvailable); ?>;
    window.NB_AI_COPILOT_MODE = 'dashboard';
    window.NB_AI_ASSISTANT_LANGUAGE = <?php echo json_encode(function_exists('_nbAdminLang') ? _nbAdminLang() : (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en')); ?>;
    window.NB_ADMIN_API_URL = 'api.php';
    window.NB_AI_COPILOT_GET_CONTENT_PAGE = function() {
        if (typeof currentPage === 'string' && currentPage) return currentPage;
        var select = document.getElementById('pageSelect');
        return select && select.value ? select.value : '';
    };
    // Menu registry for Page Settings nav checkboxes
    window.NB_MENUS = <?php echo json_encode(getMenuRegistry()['menus'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    const DASHBOARD_MODULES = <?php echo json_encode($dashboardModules, JSON_UNESCAPED_UNICODE); ?>;
    const AI_FEATURES_ENABLED = DASHBOARD_MODULES.ai !== false;
    const AI_NOTICE_DISMISSED_KEY = 'nibbly.aiUnavailableNotice.dismissed';
    const AI_FEATURE_DEFAULTS = {
        backendAssistant: true,
        seoTextGeneration: true,
        imageGeneration: true
    };
    function t(key, params) {
        let s = NB_LANG[key] || key;
        if (params) { for (const [k, v] of Object.entries(params)) { s = s.replace('{' + k + '}', v); } }
        return s;
    }

    function isDashboardModuleEnabled(tab) {
        const keyByTab = { news: 'news', events: 'events', mails: 'messages', icons: 'iconManager', ai: 'ai' };
        const key = keyByTab[tab];
        if (!key) return true;
        return DASHBOARD_MODULES[key] !== false;
    }

    // SVG icon paths (viewBox 0 0 24 24)
    const ICONS = {
        edit:      '<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        eye:       '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        duplicate: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>',
        trash:     '<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/>',
        'eye-off': '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
        back:      '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        ai:        '<path d="M12 2l1.6 5.2L19 9l-5.4 1.8L12 16l-1.6-5.2L5 9l5.4-1.8L12 2z"/><path d="M19 14l.8 2.7 2.2.8-2.2.8L19 21l-.8-2.7-2.2-.8 2.2-.8L19 14z"/>',
    };

    function icon(name, size = 16, strokeWidth = '1.5') {
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round" width="${size}" height="${size}">${ICONS[name]}</svg>`;
    }

    // Configuration
    const CSRF_TOKEN = '<?php echo $csrfToken; ?>';
    const USER_ROLE = '<?php echo htmlspecialchars($userRole); ?>';
