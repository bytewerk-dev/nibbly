<?php
/**
 * Menu Registry Helpers
 *
 * Provides functions for working with the menu registry (content/menus.json)
 * and building navigation item lists for any registered menu location.
 *
 * Included by header.php before footer.php, so all functions are available
 * throughout the page rendering lifecycle.
 */

/**
 * Load the menu registry from content/menus.json (cached per request).
 * @return array  e.g. ['menus' => ['header' => ['label' => [...], 'weight' => 0], ...]]
 */
function getMenuRegistry(): array {
    static $registry = null;
    if ($registry !== null) return $registry;

    $path = __DIR__ . '/../content/menus.json';
    if (file_exists($path)) {
        $registry = json_decode(file_get_contents($path), true) ?: ['menus' => []];
    } else {
        // Fallback: header-only if no registry file exists
        $registry = ['menus' => [
            'header' => ['label' => ['en' => 'Header'], 'weight' => 0],
        ]];
    }
    return $registry;
}

/**
 * Get all registered menu IDs, sorted by weight (ascending).
 * @return array  e.g. ['header', 'footer-pages', 'footer-legal']
 */
function getRegisteredMenuIds(): array {
    $menus = getMenuRegistry()['menus'] ?? [];
    uasort($menus, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    return array_keys($menus);
}

/**
 * Get the display label for a menu in a given language.
 * Falls back to English, then to the menu ID itself.
 *
 * @param string $menuId  e.g. 'footer-pages'
 * @param string $lang    e.g. 'de'
 * @return string         e.g. 'Seiten'
 */
function getMenuLabel(string $menuId, string $lang): string {
    $menus = getMenuRegistry()['menus'] ?? [];
    $menu = $menus[$menuId] ?? null;
    if (!$menu) return ucfirst(str_replace('-', ' ', $menuId));
    return $menu['label'][$lang] ?? $menu['label']['en'] ?? $menuId;
}

/**
 * Get nav locations from a page's JSON data.
 * Returns the "nav" array if set, otherwise defaults to ['header'].
 *
 * @param array $pageData  Decoded page JSON
 * @return array           e.g. ['header', 'footer-pages']
 */
function getNavLocations(array $pageData): array {
    if (isset($pageData['nav'])) return (array) $pageData['nav'];
    return ['header'];
}

/**
 * Check if a manual nav item (from $NAV_ITEMS) should appear in a given location.
 * Items without a 'nav' key default to ['header', 'footer-pages'].
 *
 * @param array  $item      Nav item from $NAV_ITEMS
 * @param string $location  Menu ID to check
 * @return bool
 */
function navItemInLocation(array $item, string $location): bool {
    $locations = $item['nav'] ?? ['header', 'footer-pages'];
    return in_array($location, $locations);
}

/**
 * Get all nav items for a specific menu, combining manual $NAV_ITEMS
 * entries with auto-discovered pages from JSON content files.
 *
 * @param string $menuId       Menu ID (e.g. 'header', 'footer-pages')
 * @param string $lang         Current language code
 * @param string $basePath     Relative path prefix ('' or '../')
 * @param array  $allNavItems  Full $NAV_ITEMS array for the current language
 * @return array               Array of nav items: ['href' => ..., 'label' => ..., 'page' => ..., 'children' => [...]]
 */
function getMenuItems(string $menuId, string $lang, string $basePath, array $allNavItems): array {
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';

    // 1. Filter manual nav items to this menu location
    $items = array_values(array_filter($allNavItems, fn($item) => navItemInLocation($item, $menuId)));

    // 2. Collect all pages already covered (including children)
    $existingPages = array_column($allNavItems, 'page');
    foreach ($allNavItems as $ni) {
        if (!empty($ni['children'])) {
            foreach ($ni['children'] as $child) {
                $existingPages[] = $child['page'] ?? '';
            }
        }
    }

    // 3. Auto-discover pages from JSON files
    $contentPath = __DIR__ . '/../content/pages/';
    $pageFiles = glob($contentPath . $lang . '_*.json');
    if ($pageFiles) {
        foreach ($pageFiles as $pf) {
            $slug = substr(basename($pf, '.json'), strlen($lang) + 1);

            // Skip system partials
            if (in_array($slug, ['home', 'footer', 'sidebar', 'header'])) continue;

            if (!in_array($slug, $existingPages)) {
                $data = json_decode(file_get_contents($pf), true);
                $locations = getNavLocations($data ?: []);
                if ($data && in_array($menuId, $locations)) {
                    $title = $data['title'] ?? ucfirst(str_replace('-', ' ', $slug));
                    $href = ($lang === $defaultLang) ? $slug : $lang . '/' . $slug;
                    $items[] = ['href' => $href, 'label' => $title, 'page' => $slug];
                }
            }
        }
    }

    // 4. Sort items by menu order (if defined in registry)
    $registry = getMenuRegistry()['menus'] ?? [];
    $order = $registry[$menuId]['order'][$lang] ?? null;
    if ($order && is_array($order)) {
        $orderMap = array_flip($order); // slug => position
        usort($items, function ($a, $b) use ($orderMap) {
            $posA = $orderMap[$a['page'] ?? ''] ?? PHP_INT_MAX;
            $posB = $orderMap[$b['page'] ?? ''] ?? PHP_INT_MAX;
            if ($posA === $posB) {
                // Both unordered: sort alphabetically by label
                return strcasecmp($a['label'] ?? '', $b['label'] ?? '');
            }
            return $posA <=> $posB;
        });
    }

    return $items;
}
