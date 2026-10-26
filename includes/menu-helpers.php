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
 * Get the behavior type for a menu. Supported values:
 * - standard: regular page navigation
 * - one-page: hash links are normalized to the current language homepage and
 *   active state is handled client-side by visible sections.
 */
function getMenuType(string $menuId): string {
    $menus = getMenuRegistry()['menus'] ?? [];
    $type = $menus[$menuId]['type'] ?? 'standard';
    return in_array($type, ['standard', 'one-page'], true) ? $type : 'standard';
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
 * Get all nav items for a specific menu.
 *
 * Managed menus (content/menus.json → menus.{id}.items.{lang}, edited in the
 * dashboard under "Menus & Footer") are returned as stored. Until a menu is
 * managed for a language, the items are derived as before: manual $NAV_ITEMS
 * entries plus auto-discovered pages from JSON content files, sorted by
 * menus.json → order.{lang} (see nibblyMenuLegacyItems()).
 *
 * @param string $menuId       Menu ID (e.g. 'header', 'footer-pages')
 * @param string $lang         Current language code
 * @param string $basePath     Relative path prefix ('' or '../')
 * @param array  $allNavItems  Full $NAV_ITEMS array for the current language
 * @return array               Array of nav items: ['href' => ..., 'label' => ..., 'page' => ..., 'children' => [...], 'target' => ..., 'rel' => ...]
 */
function getMenuItems(string $menuId, string $lang, string $basePath, array $allNavItems): array {
    $managed = nibblyMenuManagedItems($menuId, $lang, getMenuRegistry());
    if ($managed !== null) {
        return nibblyMenuResolveItems($managed, $lang);
    }
    return nibblyMenuLegacyItems($menuId, $lang, $allNavItems, getMenuRegistry());
}

require_once __DIR__ . '/menu-store.php';
