<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'get-menu-items':
        require_once NIBBLY_ADMIN_DIR . '/../includes/menu-helpers.php';
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

        $menusPath = NIBBLY_ADMIN_DIR . '/../content/menus.json';
        $registry = file_exists($menusPath) ? json_decode(file_get_contents($menusPath), true) : ['menus' => []];
        if (!isset($registry['menus'][$menuId])) {
            jsonResponse(false, null, 'Unknown menu: ' . $menuId);
        }

        if (!isset($registry['menus'][$menuId]['order'])) {
            $registry['menus'][$menuId]['order'] = [];
        }
        $registry['menus'][$menuId]['order'][$lang] = $order;

        file_put_contents($menusPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        jsonResponse(true, null, 'Menu order saved');
        break;

}
