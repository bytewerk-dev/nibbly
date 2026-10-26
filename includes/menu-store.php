<?php
/**
 * Menus & Footer store
 *
 * One editable source per menu and language:
 * - managed: items in content/menus.json → menus.{id}.items.{lang}
 * - derived (until the first save): $NAV_ITEMS in includes/nav-config.php plus
 *   the "nav" field of page JSON files, or the fallback items a template adapter
 *   registers (includes/menu-adapters.php, includes/site-menus.php)
 *
 * Saving a derived menu adopts it into menus.json; removing the managed items
 * of that language ("reset") returns to the untouched derived source. The
 * footer fields are edited where they already live (content/pages/footer.json);
 * menus.json only records which field has which type (menus.json → footer.fields).
 */

require_once __DIR__ . '/page-path.php';
require_once __DIR__ . '/json-store.php';
require_once __DIR__ . '/menu-adapters.php';
require_once __DIR__ . '/menu-helpers.php';

const NIBBLY_MENU_ITEM_KEYS = ['uid', 'type', 'page', 'label', 'href', 'target', 'rel', 'hidden', 'children'];
const NIBBLY_MENU_REL_TOKENS = ['noopener', 'noreferrer', 'nofollow', 'external', 'sponsored', 'ugc', 'me', 'author', 'license', 'help', 'privacy-policy', 'terms-of-service'];
const NIBBLY_FOOTER_TYPES = ['text', 'link', 'linkgroup', 'image', 'contact'];
const NIBBLY_MENU_SYSTEM_PAGES = ['footer', 'sidebar', 'header'];

function nibblyMenusRoot(): string {
    return dirname(__DIR__);
}

function nibblyMenusRegistryPath(): string {
    return nibblyMenusRoot() . '/content/menus.json';
}

function nibblyFooterContentPath(): string {
    return nibblyMenusRoot() . '/content/pages/footer.json';
}

function nibblyMenusDefaultLang(): string {
    return defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
}

/** Registry straight from disk (no per-request cache), for the editor and writes. */
function nibblyMenusReadRegistry(): array {
    $path = nibblyMenusRegistryPath();
    if (!is_file($path)) return ['menus' => []];
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) return ['menus' => []];
    if (!isset($data['menus']) || !is_array($data['menus'])) $data['menus'] = [];
    return $data;
}

/** $NAV_ITEMS and $PAGE_MAPPING of includes/nav-config.php, read in isolation. */
function nibblyMenuNavConfig(): array {
    static $config = null;
    if ($config !== null) return $config;
    $config = ['items' => [], 'mapping' => []];
    $file = nibblyMenusRoot() . '/includes/nav-config.php';
    if (is_file($file)) {
        $config = (static function (string $file): array {
            include $file;
            return [
                'items' => isset($NAV_ITEMS) && is_array($NAV_ITEMS) ? $NAV_ITEMS : [],
                'mapping' => isset($PAGE_MAPPING) && is_array($PAGE_MAPPING) ? $PAGE_MAPPING : [],
            ];
        })($file);
    }
    return $config;
}

/** True when a {lang: value} map: every key a two-letter code. */
function nibblyMenuIsLangMap($value): bool {
    if (!is_array($value) || $value === []) return false;
    foreach (array_keys($value) as $key) {
        if (!is_string($key) || !preg_match('/^[a-z]{2}$/', $key)) return false;
    }
    return true;
}

// ============================================================
// PAGES
// ============================================================

function nibblyMenuPageData(string $lang, string $page): ?array {
    static $cache = [];
    $key = $lang . '|' . $page;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $file = nibblyPageJsonPath(nibblyMenusRoot(), $lang, $page);
    $data = ($file !== '' && is_file($file)) ? json_decode((string)file_get_contents($file), true) : null;
    return $cache[$key] = is_array($data) ? $data : null;
}

function nibblyMenuPageExists(string $lang, string $page): bool {
    if (!nibblyPageIsValidPath($page)) return false;
    if ($page === 'home') return true;
    if (nibblyMenuPageData($lang, $page) !== null) return true;
    $template = nibblyPageTemplatePath(nibblyMenusRoot(), $lang, $page);
    return $template !== '' && is_file($template);
}

/** Site-relative href of a page, in the form $NAV_ITEMS uses ('.' / 'de/' / 'about' / 'de/about'). */
function nibblyMenuPageHref(string $lang, string $page): string {
    $href = ltrim(nibblyPageUrlPath($lang, $page, nibblyMenusDefaultLang()), '/');
    return $href === '' ? '.' : $href;
}

function nibblyMenuPageTitle(string $lang, string $page): string {
    $data = nibblyMenuPageData($lang, $page);
    $title = is_array($data) && is_string($data['title'] ?? null) ? trim($data['title']) : '';
    return $title !== '' ? $title : ucfirst(str_replace(['-', '/'], ' ', $page));
}

/** Pages of one language for the "existing page" picker. */
function nibblyMenuPageList(string $lang): array {
    $pages = [['page' => 'home', 'title' => nibblyMenuPageTitle($lang, 'home'), 'href' => nibblyMenuPageHref($lang, 'home')]];
    foreach (glob(nibblyMenusRoot() . '/content/pages/' . $lang . '_*.json') ?: [] as $file) {
        $parsed = nibblyPageParseContentKey(basename($file, '.json'));
        if ($parsed === null || $parsed['lang'] !== $lang) continue;
        $slug = $parsed['path'];
        if ($slug === 'home' || in_array($slug, NIBBLY_MENU_SYSTEM_PAGES, true)) continue;
        $pages[] = ['page' => $slug, 'title' => nibblyMenuPageTitle($lang, $slug), 'href' => nibblyMenuPageHref($lang, $slug)];
    }
    usort($pages, fn($a, $b) => $a['page'] === 'home' ? -1 : ($b['page'] === 'home' ? 1 : strcasecmp($a['title'], $b['title'])));
    return $pages;
}

/** Href of a page key as the core footer always built it: $PAGE_MAPPING first, the slug otherwise. */
function nibblyMenuMappedHref(string $page, string $lang): string {
    $mapping = nibblyMenuNavConfig()['mapping'];
    $default = nibblyMenusDefaultLang();
    return (string)($mapping[$page][$lang] ?? $mapping[$page][$default] ?? $page);
}

// ============================================================
// LINKS: sanitising and rendering
// ============================================================

/** A safe link target, or null: http(s), mailto, tel, #anchor, root-relative or site-relative paths. */
function nibblyMenuSanitizeHref($href): ?string {
    if (!is_string($href)) return null;
    $href = trim($href);
    if ($href === '' || strlen($href) > 2048) return null;
    if (preg_match('/[\x00-\x20\x7f\\\\<>"]/', $href)) return null;
    if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $href, $match)) {
        $scheme = strtolower($match[1]);
        if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) return null;
        if (($scheme === 'http' || $scheme === 'https') && !filter_var($href, FILTER_VALIDATE_URL)) return null;
        return $href;
    }
    if (str_starts_with($href, '//')) return null; // protocol-relative: ambiguous host
    return $href;
}

function nibblyMenuSanitizeRel($rel): string {
    $tokens = preg_split('/\s+/', strtolower(trim((string)$rel))) ?: [];
    $tokens = array_values(array_unique(array_filter($tokens, fn($token) => in_array($token, NIBBLY_MENU_REL_TOKENS, true))));
    return implode(' ', $tokens);
}

function nibblyMenuSanitizeTarget($target): string {
    return in_array($target, ['_blank', '_self'], true) ? $target : '';
}

function nibblyMenuCleanText($text, int $max = 200): string {
    $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
}

/** href for an <a>: site-relative paths and #anchors get $basePath (as before), absolute and root-relative stay. */
function nibblyMenuHref(array $item, string $basePath = ''): string {
    $href = (string)($item['href'] ?? '#');
    if ($href === '') return $basePath . '.';
    if (preg_match('#^([a-z][a-z0-9+.-]*:|/)#i', $href)) return $href;
    return $basePath . $href;
}

/**
 * target, rel and class attributes of a resolved menu item (leading space).
 * A new window always gets rel="noopener"; $extraClasses come from the template.
 */
function nibblyMenuLinkAttrs(array $item, array $extraClasses = []): string {
    $attrs = '';
    $target = nibblyMenuSanitizeTarget($item['target'] ?? '');
    $rel = nibblyMenuSanitizeRel($item['rel'] ?? '');
    if ($target === '_blank' && !preg_match('/\bnoopener\b/', $rel)) $rel = trim($rel . ' noopener');
    if ($target !== '') $attrs .= ' target="' . $target . '"';
    if ($rel !== '') $attrs .= ' rel="' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') . '"';
    $classes = $extraClasses;
    if (is_string($item['class'] ?? null) && trim($item['class']) !== '') {
        foreach (preg_split('/\s+/', trim($item['class'])) ?: [] as $class) {
            if (preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class)) $classes[] = $class;
        }
    }
    if ($classes) $attrs .= ' class="' . htmlspecialchars(implode(' ', array_unique($classes)), ENT_QUOTES, 'UTF-8') . '"';
    return $attrs;
}

// ============================================================
// MENUS: definitions, managed and derived items
// ============================================================

/**
 * Every editable menu: content/menus.json entries plus registered template
 * adapters. depth 1 = flat list, 2 = one dropdown level.
 */
function nibblyMenuDefinitions(): array {
    $registry = nibblyMenusReadRegistry()['menus'];
    $adapters = nibblyMenuAdapters();
    $menus = [];
    foreach ($registry as $id => $menu) {
        if (!is_string($id) || !is_array($menu)) continue;
        $menus[$id] = [
            'id' => $id,
            'label' => is_array($menu['label'] ?? null) ? $menu['label'] : ['en' => $id],
            'type' => in_array($menu['type'] ?? '', ['standard', 'one-page'], true) ? $menu['type'] : 'standard',
            'weight' => (int)($menu['weight'] ?? 0),
            'depth' => (int)($menu['depth'] ?? ($id === 'header' ? 2 : 1)),
            'adapter' => null,
        ];
    }
    foreach ($adapters as $id => $adapter) {
        $menus[$id] = array_replace([
            'id' => $id,
            'label' => $adapter['label'],
            'type' => 'standard',
            'weight' => $adapter['weight'],
            'depth' => $adapter['depth'],
        ], $menus[$id] ?? [], ['adapter' => $adapter]);
        if (!isset($registry[$id]['depth'])) $menus[$id]['depth'] = $adapter['depth'];
    }
    foreach ($menus as &$menu) $menu['depth'] = max(1, min(3, $menu['depth']));
    unset($menu);
    uasort($menus, fn($a, $b) => $a['weight'] <=> $b['weight']);
    return $menus;
}

/** Stored items of a menu and language, or null while that language is still derived. */
function nibblyMenuManagedItems(string $menuId, string $lang, ?array $registry = null): ?array {
    $registry = $registry ?? (function_exists('getMenuRegistry') ? getMenuRegistry() : nibblyMenusReadRegistry());
    $items = $registry['menus'][$menuId]['items'][$lang] ?? null;
    return is_array($items) ? array_values($items) : null;
}

/**
 * Render-ready items (href, label, page, children, target, rel, uid and any
 * project fields). Hidden items and references to missing pages are skipped.
 */
function nibblyMenuResolveItems(array $items, string $lang): array {
    $resolved = [];
    foreach ($items as $item) {
        if (!is_array($item) || !empty($item['hidden'])) continue;
        $type = ($item['type'] ?? '') === 'page' ? 'page' : 'link';
        $page = is_string($item['page'] ?? null) ? $item['page'] : '';
        $label = is_string($item['label'] ?? null) ? $item['label'] : '';
        if ($type === 'page') {
            if (!nibblyMenuPageExists($lang, $page)) continue;
            $href = nibblyMenuPageHref($lang, $page);
            if ($label === '') $label = nibblyMenuPageTitle($lang, $page);
        } else {
            $href = (string)($item['href'] ?? '');
            if ($href === '') continue;
        }
        $out = array_diff_key($item, array_flip(NIBBLY_MENU_ITEM_KEYS));
        $out = array_merge($out, ['href' => $href, 'label' => $label, 'page' => $page]);
        if (!empty($item['uid'])) $out['uid'] = (string)$item['uid'];
        if (!empty($item['target'])) $out['target'] = nibblyMenuSanitizeTarget($item['target']);
        if (!empty($item['rel'])) $out['rel'] = nibblyMenuSanitizeRel($item['rel']);
        $children = is_array($item['children'] ?? null) ? nibblyMenuResolveItems($item['children'], $lang) : [];
        if ($children) $out['children'] = $children;
        $resolved[] = $out;
    }
    return $resolved;
}

/**
 * Items of the legacy derivation: manual $NAV_ITEMS for this location plus
 * auto-discovered pages whose "nav" field lists the menu, sorted by
 * menus.json → order.{lang}. This is how getMenuItems() always built menus.
 */
function nibblyMenuLegacyItems(string $menuId, string $lang, array $allNavItems, ?array $registry = null): array {
    $defaultLang = nibblyMenusDefaultLang();

    // 1. Manual nav items of this location
    $items = array_values(array_filter($allNavItems, fn($item) => is_array($item) && navItemInLocation($item, $menuId)));

    // 2. Pages already covered (including children)
    $existingPages = [];
    foreach ($allNavItems as $navItem) {
        if (!is_array($navItem)) continue;
        $existingPages[] = $navItem['page'] ?? '';
        foreach ((array)($navItem['children'] ?? []) as $child) {
            $existingPages[] = is_array($child) ? ($child['page'] ?? '') : '';
        }
    }

    // 3. Auto-discovered pages
    foreach (glob(nibblyMenusRoot() . '/content/pages/' . $lang . '_*.json') ?: [] as $file) {
        $page = nibblyPageParseContentKey(basename($file, '.json'));
        if ($page === null || $page['lang'] !== $lang) continue;
        $slug = $page['path'];
        if (in_array($slug, ['home', 'footer', 'sidebar', 'header'], true) || in_array($slug, $existingPages, true)) continue;
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !in_array($menuId, getNavLocations($data), true)) continue;
        $title = $data['title'] ?? ucfirst(str_replace('-', ' ', $slug));
        $items[] = ['href' => ltrim(nibblyPageUrlPath($lang, $slug, $defaultLang), '/'), 'label' => $title, 'page' => $slug];
    }

    // 4. Registry order
    $registry = $registry ?? (function_exists('getMenuRegistry') ? getMenuRegistry() : nibblyMenusReadRegistry());
    $order = $registry['menus'][$menuId]['order'][$lang] ?? null;
    if ($order && is_array($order)) {
        $orderMap = array_flip(array_values(array_filter($order, 'is_string')));
        usort($items, function ($a, $b) use ($orderMap) {
            $posA = $orderMap[$a['page'] ?? ''] ?? PHP_INT_MAX;
            $posB = $orderMap[$b['page'] ?? ''] ?? PHP_INT_MAX;
            if ($posA === $posB) return strcasecmp($a['label'] ?? '', $b['label'] ?? '');
            return $posA <=> $posB;
        });
    }
    return $items;
}

/** Stable id for a derived item: the same source always yields the same uid. */
function nibblyMenuDerivedUid(string $menuId, string $lang, string $parent, array $item, array &$used): string {
    $seed = $menuId . '|' . $lang . '|' . $parent . '|' . ($item['page'] ?? '') . '|' . ($item['href'] ?? '') . '|' . ($item['label'] ?? '');
    $uid = 'mi_' . substr(sha1($seed), 0, 12);
    $n = 2;
    while (isset($used[$uid])) $uid = 'mi_' . substr(sha1($seed . '#' . $n++), 0, 12);
    $used[$uid] = true;
    return $uid;
}

/** A $NAV_ITEMS-style item as an editor item; project fields stay, "nav" belongs to the old model. */
function nibblyMenuFromLegacy(array $item, string $menuId, string $lang, string $parent, array &$used): array {
    $page = is_string($item['page'] ?? null) && nibblyPageIsValidPath($item['page']) ? $item['page'] : '';
    $href = is_string($item['href'] ?? null) ? $item['href'] : '';
    $isPage = ($item['type'] ?? '') === 'page'
        || ($page !== '' && ($href === '' || $href === nibblyMenuPageHref($lang, $page) || ($page === 'home' && in_array($href, ['', '.'], true))) && nibblyMenuPageExists($lang, $page));
    $out = array_diff_key($item, array_flip(array_merge(NIBBLY_MENU_ITEM_KEYS, ['nav'])));
    $out['uid'] = is_string($item['uid'] ?? null) && preg_match('/^mi_[a-z0-9]{6,32}$/', $item['uid'])
        ? $item['uid'] : nibblyMenuDerivedUid($menuId, $lang, $parent, $item, $used);
    $out['type'] = $isPage ? 'page' : 'link';
    $out['label'] = is_string($item['label'] ?? null) ? $item['label'] : '';
    if ($page !== '') $out['page'] = $page;
    if (!$isPage) $out['href'] = $href;
    foreach (['target', 'rel'] as $key) {
        if (!empty($item[$key]) && is_string($item[$key])) $out[$key] = $item[$key];
    }
    if (!empty($item['hidden'])) $out['hidden'] = true;
    $children = [];
    foreach ((array)($item['children'] ?? []) as $child) {
        if (is_array($child)) $children[] = nibblyMenuFromLegacy($child, $menuId, $lang, $out['uid'], $used);
    }
    if ($children) $out['children'] = $children;
    return $out;
}

/**
 * The editor's view of a menu that is not managed yet, with its origin.
 * @return array{items:array, source:string, origin:array}
 */
function nibblyMenuDerived(array $menu, string $lang, ?array $registry = null): array {
    $used = [];
    $adapter = $menu['adapter'] ?? null;
    if ($adapter && is_callable($adapter['fallback'] ?? null)) {
        $items = [];
        foreach ((array)call_user_func($adapter['fallback'], $lang) as $item) {
            if (is_array($item)) $items[] = nibblyMenuFromLegacy($item, $menu['id'], $lang, '', $used);
        }
        return ['items' => $items, 'source' => 'template', 'origin' => [$adapter['template']]];
    }
    $nav = nibblyMenuNavConfig()['items'];
    $navItems = is_array($nav[$lang] ?? null) ? $nav[$lang] : [];
    $legacy = nibblyMenuLegacyItems($menu['id'], $lang, $navItems, $registry);
    $origin = [];
    $items = [];
    foreach ($legacy as $item) {
        $items[] = nibblyMenuFromLegacy($item, $menu['id'], $lang, '', $used);
        $fromNav = false;
        foreach ($navItems as $navItem) {
            if (is_array($navItem) && ($navItem['page'] ?? null) === ($item['page'] ?? '') && ($navItem['href'] ?? null) === ($item['href'] ?? '')) { $fromNav = true; break; }
        }
        $key = $fromNav ? 'includes/nav-config.php' : 'content/pages/' . nibblyPageContentKey($lang, (string)($item['page'] ?? '')) . '.json';
        $origin[$key] = true;
    }
    return ['items' => $items, 'source' => 'legacy', 'origin' => array_keys($origin)];
}

/**
 * Items a template renders for a menu: managed items, otherwise the adapter's
 * fallback or the legacy derivation. Use this in header/footer templates.
 */
function nibblyMenuItems(string $menuId, ?string $lang = null): array {
    $lang = $lang ?? ($GLOBALS['currentLang'] ?? nibblyMenusDefaultLang());
    $managed = nibblyMenuManagedItems($menuId, $lang);
    if ($managed !== null) return nibblyMenuResolveItems($managed, $lang);
    $definitions = nibblyMenuDefinitions();
    if (isset($definitions[$menuId]['adapter'])) {
        return nibblyMenuResolveItems(nibblyMenuDerived($definitions[$menuId], $lang)['items'], $lang);
    }
    $nav = $GLOBALS['NAV_ITEMS'] ?? nibblyMenuNavConfig()['items'];
    return nibblyMenuLegacyItems($menuId, $lang, is_array($nav[$lang] ?? null) ? $nav[$lang] : []);
}

/**
 * Validate editor items. Project fields come from the server-side original of
 * the same uid, never from the request.
 */
function nibblyMenuSanitizeItems($items, array $originals, string $lang, int $depth, int $maxDepth, array &$errors, array &$seen): array {
    $clean = [];
    if (!is_array($items)) return $clean;
    foreach (array_values($items) as $index => $item) {
        if (!is_array($item)) continue;
        $uid = is_string($item['uid'] ?? null) && preg_match('/^mi_[a-z0-9]{6,32}$/', $item['uid']) ? $item['uid'] : '';
        if ($uid === '' || isset($seen[$uid])) $uid = 'mi_' . bin2hex(random_bytes(6));
        $seen[$uid] = true;
        $original = $originals[$uid] ?? [];
        $out = array_diff_key($original, array_flip(NIBBLY_MENU_ITEM_KEYS));
        $out['uid'] = $uid;
        $type = ($item['type'] ?? '') === 'page' ? 'page' : 'link';
        $out['type'] = $type;
        $label = nibblyMenuCleanText($item['label'] ?? '');
        $name = $label !== '' ? $label : ('#' . ($index + 1));
        $page = is_string($item['page'] ?? null) ? trim($item['page']) : '';
        if ($page !== '' && !nibblyPageIsValidPath($page)) {
            $errors[] = ['uid' => $uid, 'field' => 'page', 'message' => 'Invalid page reference: ' . $name];
            $page = '';
        }
        if ($type === 'page') {
            if ($page === '') {
                $errors[] = ['uid' => $uid, 'field' => 'page', 'message' => 'Choose a page: ' . $name];
            }
            $out['page'] = $page;
        } else {
            $href = nibblyMenuSanitizeHref($item['href'] ?? '');
            if ($href === null) {
                $errors[] = ['uid' => $uid, 'field' => 'href', 'message' => 'Invalid link target: ' . $name];
                $href = '';
            }
            if ($label === '') {
                $errors[] = ['uid' => $uid, 'field' => 'label', 'message' => 'A link needs a label'];
            }
            if ($page !== '') $out['page'] = $page;
            $out['href'] = $href;
        }
        $out['label'] = $label;
        $target = nibblyMenuSanitizeTarget($item['target'] ?? '');
        if ($target !== '') $out['target'] = $target;
        $rel = nibblyMenuSanitizeRel($item['rel'] ?? '');
        if ($rel !== '') $out['rel'] = $rel;
        if (!empty($item['hidden'])) $out['hidden'] = true;
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        if ($children) {
            if ($depth >= $maxDepth) {
                $errors[] = ['uid' => $uid, 'field' => 'children', 'message' => 'This menu does not support submenus at this level: ' . $name];
            } else {
                $out['children'] = nibblyMenuSanitizeItems($children, $originals, $lang, $depth + 1, $maxDepth, $errors, $seen);
            }
        }
        $clean[] = $out;
    }
    return $clean;
}

/** uid => item over a whole tree. */
function nibblyMenuIndexItems(array $items, array &$index = []): array {
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (is_string($item['uid'] ?? null)) $index[$item['uid']] = $item;
        if (is_array($item['children'] ?? null)) nibblyMenuIndexItems($item['children'], $index);
    }
    return $index;
}

/** Remove every item that points to $page (any depth). Returns the number removed. */
function nibblyMenuRemovePage(array &$items, string $page): int {
    $removed = 0;
    foreach ($items as $i => &$item) {
        if (!is_array($item)) continue;
        if (($item['type'] ?? '') === 'page' && ($item['page'] ?? '') === $page) {
            unset($items[$i]);
            $removed++;
            continue;
        }
        if (is_array($item['children'] ?? null)) {
            $removed += nibblyMenuRemovePage($item['children'], $page);
            if (!$item['children']) unset($item['children']);
        }
    }
    unset($item);
    $items = array_values($items);
    return $removed;
}

function nibblyMenuHasPage(array $items, string $page): bool {
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (($item['page'] ?? '') === $page) return true;
        if (is_array($item['children'] ?? null) && nibblyMenuHasPage($item['children'], $page)) return true;
    }
    return false;
}

/** Point every reference to $oldPage at $newPage (slug change). Returns the number changed. */
function nibblyMenuRenamePageInItems(array &$items, string $oldPage, string $newPage): int {
    $changed = 0;
    foreach ($items as &$item) {
        if (!is_array($item)) continue;
        if (($item['page'] ?? '') === $oldPage) { $item['page'] = $newPage; $changed++; }
        if (is_array($item['children'] ?? null)) $changed += nibblyMenuRenamePageInItems($item['children'], $oldPage, $newPage);
    }
    unset($item);
    return $changed;
}

/** Copy content/menus.json to the backup folder before a write. */
function nibblyMenusBackupRegistry(): bool {
    $path = nibblyMenusRegistryPath();
    if (!is_file($path)) return true;
    $dir = defined('BACKUP_PATH') ? BACKUP_PATH : nibblyMenusRoot() . '/backups/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    // The suffix is the microsecond in hex, so names sort by time even within one second.
    $now = microtime(true);
    $target = $dir . 'menus-registry_' . date('Y-m-d_His', (int)$now) . '_' . sprintf('%06x', (int)(($now - floor($now)) * 1000000)) . '.json';
    while (is_file($target)) $target = substr($target, 0, -11) . sprintf('%06x', random_int(0, 0xffffff)) . '.json';
    if (!@copy($path, $target)) return false;
    $backups = glob($dir . 'menus-registry_*.json') ?: [];
    rsort($backups, SORT_STRING);
    $keep = defined('MAX_BACKUPS') ? (int)MAX_BACKUPS : 20;
    foreach (array_slice($backups, max(1, $keep)) as $old) @unlink($old);
    return true;
}

/**
 * Keep managed menus in step with a page's "nav" checkboxes. $authoritative:
 * the page settings form sent the managed state (add and remove); otherwise only
 * menus whose membership changed between $oldNav and $newNav are touched (a new
 * page passes $oldNav = [] and joins every managed menu its locations list).
 */
function nibblyMenusSyncPageNav(string $lang, string $page, array $oldNav, array $newNav, bool $authoritative = false): bool {
    if (in_array($page, NIBBLY_MENU_SYSTEM_PAGES, true) || !nibblyPageIsValidPath($page)) return true;
    $path = nibblyMenusRegistryPath();
    if (!is_file($path)) return true;
    $registry = nibblyMenusReadRegistry();
    $touch = false;
    foreach ($registry['menus'] as $menuId => $menu) {
        if (!is_array($menu['items'][$lang] ?? null)) continue;
        $want = in_array($menuId, $newNav, true);
        if (!$authoritative && $want === in_array($menuId, $oldNav, true)) continue;
        if ($want !== nibblyMenuHasPage($menu['items'][$lang], $page)) { $touch = true; break; }
    }
    if (!$touch) return true;
    if (!nibblyMenusBackupRegistry()) return false;
    return nibblyJsonUpdate($path, function (array &$data) use ($lang, $page, $oldNav, $newNav, $authoritative) {
        foreach ($data['menus'] ?? [] as $menuId => $menu) {
            if (!is_array($menu['items'][$lang] ?? null)) continue;
            $want = in_array($menuId, $newNav, true);
            if (!$authoritative && $want === in_array($menuId, $oldNav, true)) continue;
            $items = $menu['items'][$lang];
            if ($want && !nibblyMenuHasPage($items, $page)) {
                $items[] = ['uid' => 'mi_' . bin2hex(random_bytes(6)), 'type' => 'page', 'page' => $page, 'label' => ''];
            } elseif (!$want) {
                nibblyMenuRemovePage($items, $page);
            }
            $data['menus'][$menuId]['items'][$lang] = $items;
        }
    });
}

/** Managed page references follow a slug change. */
function nibblyMenusRenamePage(string $lang, string $oldPage, string $newPage): bool {
    if (!is_file(nibblyMenusRegistryPath()) || !nibblyPageIsValidPath($newPage)) return true;
    $registry = nibblyMenusReadRegistry();
    $found = false;
    foreach ($registry['menus'] as $menu) {
        if (is_array($menu['items'][$lang] ?? null) && nibblyMenuHasPage($menu['items'][$lang], $oldPage)) { $found = true; break; }
    }
    if (!$found) return true;
    if (!nibblyMenusBackupRegistry()) return false;
    return nibblyJsonUpdate(nibblyMenusRegistryPath(), function (array &$data) use ($lang, $oldPage, $newPage) {
        foreach ($data['menus'] ?? [] as $menuId => $menu) {
            if (!is_array($menu['items'][$lang] ?? null)) continue;
            $items = $menu['items'][$lang];
            nibblyMenuRenamePageInItems($items, $oldPage, $newPage);
            $data['menus'][$menuId]['items'][$lang] = $items;
        }
    });
}

/** A copied language: site-relative link targets move to the new language prefix. */
function nibblyMenusTranslateLinks(array $items, callable $translate): array {
    foreach ($items as &$item) {
        if (!is_array($item)) continue;
        if (($item['type'] ?? '') !== 'page' && is_string($item['href'] ?? null)
            && !preg_match('#^([a-z][a-z0-9+.-]*:|/|\#)#i', $item['href'])) {
            $item['href'] = $translate($item['href']);
        }
        if (is_array($item['children'] ?? null)) $item['children'] = nibblyMenusTranslateLinks($item['children'], $translate);
    }
    unset($item);
    return $items;
}

// ============================================================
// FOOTER: schema, detection, sanitising
// ============================================================

function nibblyFooterReadData(): array {
    $path = nibblyFooterContentPath();
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/** True when footer.json stores $path as a JSON object with keys "0", "1", … (PHP decodes it as a list). */
function nibblyFooterIsNumberedObject(string $path): bool {
    $file = nibblyFooterContentPath();
    $value = is_file($file) ? json_decode((string)file_get_contents($file)) : null;
    foreach (explode('.', $path) as $key) {
        if (!is_object($value) || !property_exists($value, $key)) return false;
        $value = $value->{$key};
    }
    if (!is_object($value)) return false;
    $keys = array_map('strval', array_keys(get_object_vars($value)));
    return $keys !== [] && $keys === array_map('strval', range(0, count($keys) - 1));
}

function nibblyFooterGet(array $data, string $path) {
    $value = $data;
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return null;
        $value = $value[$key];
    }
    return $value;
}

function nibblyFooterSet(array &$data, string $path, $value): void {
    $ref = &$data;
    foreach (explode('.', $path) as $key) {
        if (!isset($ref[$key]) || !is_array($ref[$key])) $ref[$key] = [];
        $ref = &$ref[$key];
    }
    $ref = $value;
}

function nibblyFooterIsValidPath($path): bool {
    return is_string($path) && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}(\.[A-Za-z0-9_-]{1,64}){0,3}$/', $path)
        && !in_array(explode('.', $path)[0], ['page', 'lastModified', 'lang', 'title', 'sections'], true);
}

/** Field definitions: registered adapters first, then assignments in menus.json → footer.fields. */
function nibblyFooterSchema(?array $registry = null): array {
    $registry = $registry ?? nibblyMenusReadRegistry();
    $fields = [];
    foreach (nibblyFooterFieldAdapters() as $path => $field) {
        $fields[$path] = $field + ['origin' => 'adapter'];
    }
    foreach ((array)($registry['footer']['fields'] ?? []) as $path => $field) {
        if (!nibblyFooterIsValidPath($path) || !is_array($field) || isset($fields[$path])) continue;
        $type = $field['type'] ?? '';
        if ($type !== 'ignore' && !in_array($type, NIBBLY_FOOTER_TYPES, true)) continue;
        $fields[$path] = [
            'path' => $path,
            'type' => $type,
            'label' => is_array($field['label'] ?? null) || is_string($field['label'] ?? null) ? $field['label'] : $path,
            'html' => !empty($field['html']),
            'multiline' => !empty($field['multiline']),
            'template' => is_string($field['template'] ?? null) ? $field['template'] : '',
            'supports' => is_array($field['supports'] ?? null) ? $field['supports'] : [],
            'origin' => 'assigned',
        ];
    }
    return $fields;
}

/** Guess the type of a footer value; confident = no assignment needed. */
function nibblyFooterGuessType(string $key, $value): array {
    $isImage = fn($v) => is_string($v) && preg_match('/\.(svg|png|jpe?g|webp|gif|avif)(\?.*)?$/i', $v);
    if (is_string($value) || nibblyMenuIsLangMap($value) && count(array_filter($value, 'is_string')) === count($value)) {
        if ($isImage(is_string($value) ? $value : reset($value)) || preg_match('/logo|image|bild/i', $key)) return ['type' => 'image', 'confident' => $isImage(is_string($value) ? $value : reset($value))];
        return ['type' => 'text', 'confident' => true];
    }
    if (!is_array($value)) return ['type' => '', 'confident' => false];
    $keys = array_map('strtolower', array_map('strval', array_keys($value)));
    $linkKeys = array_intersect($keys, ['href', 'url', 'link']);
    $labelKeys = array_intersect($keys, ['label', 'text', 'linktext', 'title']);
    if (isset($value['src'])) return ['type' => 'image', 'confident' => true];
    if (array_intersect($keys, ['phone', 'email', 'tel', 'mail', 'fax', 'address', 'mobile'])) {
        return ['type' => 'contact', 'confident' => !$linkKeys];
    }
    if ($linkKeys && $labelKeys) return ['type' => 'link', 'confident' => true];
    if ($linkKeys) return ['type' => 'link', 'confident' => false];
    $list = array_values($value);
    if ($list && count(array_filter($list, fn($v) => is_array($v) && array_intersect(array_map('strtolower', array_map('strval', array_keys($v))), ['href', 'url', 'link']))) === count($list)) {
        return ['type' => 'linkgroup', 'confident' => true];
    }
    return ['type' => '', 'confident' => false];
}

/** Template references of a footer key (file:line) and whether the template prints it. */
function nibblyFooterTemplateRefs(string $key, array $files): array {
    $refs = [];
    $quoted = preg_quote($key, '/');
    foreach ($files as $relative) {
        $source = @file_get_contents(nibblyMenusRoot() . '/' . $relative);
        if ($source === false) continue;
        $lines = preg_split('/\R/', $source) ?: [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/\[[\'"]' . $quoted . '[\'"]\]/', $line)) continue;
            $used = true;
            if (preg_match('/\$(\w+)\s*=\s*[^;]*\[[\'"]' . $quoted . '[\'"]\]/', $line, $assign)) {
                // Assigned to a variable: printed only if the variable appears again.
                $count = preg_match_all('/\$' . preg_quote($assign[1], '/') . '\b/', $source);
                $used = $count > 1;
            }
            $refs[] = ['file' => $relative, 'line' => $i + 1, 'used' => $used];
        }
    }
    return $refs;
}

/** Templates that read the footer content: core footer, adapter templates, includes/*.php naming footer.json. */
function nibblyFooterTemplateFiles(): array {
    $files = ['includes/footer.php' => true];
    foreach (nibblyFooterFieldAdapters() as $field) {
        if (!empty($field['template'])) $files[$field['template']] = true;
    }
    foreach (glob(nibblyMenusRoot() . '/includes/*.php') ?: [] as $file) {
        $base = basename($file);
        if (in_array($base, ['menu-store.php', 'menu-adapters.php', 'site-languages.php'], true)) continue;
        $source = (string)@file_get_contents($file);
        if (str_contains($source, 'footer.json') || str_contains($source, "'footer'")) $files['includes/' . $base] = true;
    }
    return array_keys(array_filter($files, fn($_, $file) => is_file(nibblyMenusRoot() . '/' . $file), ARRAY_FILTER_USE_BOTH));
}

/**
 * Footer fields: the schema with current values plus every other top-level key
 * of footer.json as a finding (guessed type, template references). Running it
 * again never adds anything: findings are keyed by their JSON path.
 */
function nibblyFooterDetect(?array $registry = null): array {
    $data = nibblyFooterReadData();
    $schema = nibblyFooterSchema($registry);
    $files = nibblyFooterTemplateFiles();
    $fields = [];
    $findings = [];
    foreach ($schema as $path => $field) {
        if ($field['type'] === 'ignore') continue;
        $field['value'] = nibblyFooterGet($data, $path);
        $field['exists'] = $field['value'] !== null;
        if ($field['value'] === null && is_callable($field['default'] ?? null)) {
            $field['defaults'] = [];
            foreach (array_keys(nibblyMenuSiteLanguages()) as $lang) $field['defaults'][$lang] = (string)call_user_func($field['default'], $lang);
        }
        unset($field['default']);
        $field['refs'] = nibblyFooterTemplateRefs(explode('.', $path)[0], $files);
        $fields[] = $field;
    }
    foreach ($data as $key => $value) {
        if (!is_string($key) || !nibblyFooterIsValidPath($key) || isset($schema[$key])) continue;
        $guess = nibblyFooterGuessType($key, $value);
        $refs = nibblyFooterTemplateRefs($key, $files);
        $printed = (bool)array_filter($refs, fn($ref) => $ref['used']);
        $findings[] = [
            'path' => $key,
            'type' => $guess['type'],
            'confident' => $guess['confident'] && $printed,
            'printed' => $printed,
            'value' => $value,
            'refs' => $refs,
            'origin' => 'content/pages/footer.json',
        ];
    }
    $ignored = array_keys(array_filter($schema, fn($field) => $field['type'] === 'ignore'));
    return ['fields' => $fields, 'findings' => $findings, 'ignored' => $ignored];
}

/** Sanitise one leaf; $role from the key name picks link, email or text handling. */
function nibblyFooterCleanLeaf($value, string $role, bool $html, &$error) {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $leaf) {
            if (!is_string($key) && !is_int($key)) continue;
            $out[$key] = nibblyFooterCleanLeaf($leaf, $role, $html, $error);
        }
        return $out;
    }
    if (is_bool($value) || $value === null) return $value;
    $value = (string)$value;
    if (in_array($role, ['href', 'url', 'link', 'src'], true)) {
        if (trim($value) === '') return '';
        $href = nibblyMenuSanitizeHref($value);
        if ($href === null) { $error = 'Invalid link target: ' . nibblyMenuCleanText($value, 80); return ''; }
        return $href;
    }
    if (in_array($role, ['email', 'mail', 'contactemail'], true)) {
        $value = trim($value);
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) { $error = 'Invalid email address: ' . nibblyMenuCleanText($value, 80); return ''; }
        return $value;
    }
    if ($role === 'target') return nibblyMenuSanitizeTarget($value);
    if ($role === 'rel') return nibblyMenuSanitizeRel($value);
    if ($html) {
        require_once __DIR__ . '/html-sanitizer.php';
        return nibblySanitizeRichHtml($value);
    }
    $value = str_replace(["\r\n", "\r"], "\n", strip_tags($value));
    return function_exists('mb_substr') ? mb_substr($value, 0, 4000) : substr($value, 0, 4000);
}

/**
 * New value of a footer field. Unchanged leaves keep their stored bytes; keys
 * the editor does not know are carried over from the stored value.
 */
function nibblyFooterSanitizeValue(array $field, $value, $old, ?string &$error = null) {
    if ($value === $old) return $old;
    $html = !empty($field['html']);
    $walk = function ($new, $old, string $role) use (&$walk, $html, &$error) {
        if ($new === $old) return $old;
        if (is_array($new) && !nibblyMenuIsLangMap($new)) {
            $isList = array_is_list($new);
            $out = (is_array($old) && !$isList && !array_is_list($old)) ? $old : [];
            foreach ($new as $key => $leaf) {
                if (!is_string($key) && !is_int($key)) continue;
                $childRole = is_string($key) ? strtolower($key) : $role;
                $oldLeaf = is_array($old) && array_key_exists($key, $old) ? $old[$key] : null;
                if ($isList && is_array($leaf) && is_array($old)) {
                    // List items keep their project fields by position of their former uid or index.
                    $oldLeaf = null;
                    foreach ($old as $candidate) {
                        if (is_array($candidate) && isset($leaf['uid'], $candidate['uid']) && $candidate['uid'] === $leaf['uid']) { $oldLeaf = $candidate; break; }
                    }
                }
                $out[$key] = $walk($leaf, $oldLeaf, $childRole);
            }
            if ($isList) $out = array_values($out);
            return $out;
        }
        return nibblyFooterCleanLeaf($new, $role, $html && !in_array($role, ['href', 'url', 'link', 'src', 'email', 'alt', 'target', 'rel'], true), $error);
    };
    $role = strtolower((string)substr((string)strrchr('.' . $field['path'], '.'), 1));
    if ($field['type'] === 'image' && is_string($value)) $role = 'src';
    if (!empty($field['numbered']) && is_array($value) && array_is_list($value)) {
        // Numbered-object lists ({"0": {...}}) stay numbered objects.
        return (object)$walk($value, is_array($old) ? array_values($old) : null, $role);
    }
    return $walk($value, $old, $role);
}

// ============================================================
// TEMPLATE DETECTION (read-only)
// ============================================================

/** Site languages from the request globals or nav-config.php. */
function nibblyMenuSiteLanguages(): array {
    global $SITE_LANGUAGES;
    if (is_array($SITE_LANGUAGES ?? null) && $SITE_LANGUAGES) return $SITE_LANGUAGES;
    $file = nibblyMenusRoot() . '/includes/nav-config.php';
    if (is_file($file)) {
        $languages = (static function (string $file) { include $file; return $SITE_LANGUAGES ?? null; })($file);
        if (is_array($languages) && $languages) return $languages;
    }
    $default = nibblyMenusDefaultLang();
    return [$default => strtoupper($default)];
}

/** Templates that may hold navigation markup. */
function nibblyMenuTemplateFiles(): array {
    $root = nibblyMenusRoot();
    $files = [];
    foreach (['includes/header.php', 'includes/footer.php', 'includes/sidebar.php'] as $file) {
        if (is_file($root . '/' . $file)) $files[] = $file;
    }
    foreach (nibblyMenuAdapters() as $adapter) {
        if (!empty($adapter['template']) && is_file($root . '/' . $adapter['template'])) $files[] = $adapter['template'];
    }
    foreach (array_keys(nibblyMenuSiteLanguages()) as $lang) {
        foreach (glob($root . '/' . $lang . '/*.php') ?: [] as $file) $files[] = $lang . '/' . basename($file);
    }
    foreach (glob($root . '/templates/*.php') ?: [] as $file) $files[] = 'templates/' . basename($file);
    return array_values(array_unique($files));
}

/**
 * Menu bindings found in templates (getMenuItems/nibblyMenuItems calls) and
 * <nav> blocks with hard-coded links that no menu feeds. Reports only; never
 * edits a template.
 */
function nibblyMenuScanTemplates(): array {
    $root = nibblyMenusRoot();
    $bound = [];
    $static = [];
    foreach (nibblyMenuTemplateFiles() as $file) {
        $source = (string)@file_get_contents($root . '/' . $file);
        if ($source === '') continue;
        if (preg_match_all('/(?:getMenuItems|nibblyMenuItems)\(\s*[\'"]([A-Za-z0-9_-]+)[\'"]/', $source, $calls, PREG_OFFSET_CAPTURE)) {
            foreach ($calls[1] as [$id, $offset]) {
                $bound[$id][] = ['file' => $file, 'line' => substr_count(substr($source, 0, $offset), "\n") + 1];
            }
        }
        if (preg_match('/foreach\s*\(\s*getRegisteredMenuIds\(\)/', $source, $loop, PREG_OFFSET_CAPTURE)) {
            $bound['*'][] = ['file' => $file, 'line' => substr_count(substr($source, 0, $loop[0][1]), "\n") + 1];
        }
        if (!preg_match_all('/<nav\b[^>]*>(.*?)<\/nav>/is', $source, $navs, PREG_OFFSET_CAPTURE)) continue;
        foreach ($navs[1] as $index => [$inner, $offset]) {
            if (preg_match('/getMenuItems|nibblyMenuItems|\$navItems|getRegisteredMenuIds/', $inner)) continue;
            if (!preg_match_all('/<a\b[^>]*\bhref="([^"<]+)"[^>]*>(.*?)<\/a>/is', $inner, $links, PREG_SET_ORDER)) continue;
            $labels = [];
            foreach ($links as $link) {
                $label = nibblyMenuCleanText(preg_replace('/<\?php.*?\?>/s', '…', $link[2]) ?? '', 60);
                $labels[] = ['label' => $label, 'href' => $link[1]];
            }
            $tag = $navs[0][$index][0];
            preg_match('/<nav\b([^>]*)>/i', $tag, $open);
            $static[] = [
                'file' => $file,
                'line' => substr_count(substr($source, 0, $navs[0][$index][1]), "\n") + 1,
                'attributes' => trim(nibblyMenuCleanText($open[1] ?? '', 160)),
                'links' => array_slice($labels, 0, 12),
                'count' => count($labels),
            ];
        }
    }
    return ['bound' => $bound, 'static' => $static];
}

// ============================================================
// EDITOR STATE
// ============================================================

/** Page references of a tree that point to no existing page. */
function nibblyMenuMissingPages(array $items, string $lang, array &$missing = []): array {
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (($item['type'] ?? '') === 'page' && !nibblyMenuPageExists($lang, (string)($item['page'] ?? ''))) {
            $missing[] = (string)($item['uid'] ?? '');
        }
        if (is_array($item['children'] ?? null)) nibblyMenuMissingPages($item['children'], $lang, $missing);
    }
    return $missing;
}

/** Everything the dashboard editor needs, with the revisions it must send back. */
function nibblyMenusEditorState(): array {
    $registry = nibblyMenusReadRegistry();
    $languages = nibblyMenuSiteLanguages();
    $scan = nibblyMenuScanTemplates();
    $menus = [];
    foreach (nibblyMenuDefinitions() as $id => $menu) {
        $bindings = $scan['bound'][$id] ?? [];
        if ($id !== 'header') $bindings = array_merge($bindings, $scan['bound']['*'] ?? []);
        $entry = [
            'id' => $id,
            'label' => $menu['label'],
            'type' => $menu['type'],
            'depth' => $menu['depth'],
            'template' => $menu['adapter']['template'] ?? '',
            'registered' => isset($registry['menus'][$id]),
            'bindings' => $bindings,
            'languages' => [],
        ];
        foreach (array_keys($languages) as $lang) {
            $managed = nibblyMenuManagedItems($id, $lang, $registry);
            $state = $managed !== null
                ? ['managed' => true, 'items' => $managed, 'source' => 'menus', 'origin' => ['content/menus.json']]
                : ['managed' => false] + nibblyMenuDerived($menu, $lang, $registry);
            $state['missing'] = nibblyMenuMissingPages($state['items'], $lang);
            $entry['languages'][$lang] = $state;
        }
        $menus[] = $entry;
    }
    $pages = [];
    foreach (array_keys($languages) as $lang) $pages[$lang] = nibblyMenuPageList($lang);
    $footer = nibblyFooterDetect($registry);
    $footer['exists'] = is_file(nibblyFooterContentPath());
    return [
        'languages' => $languages,
        'defaultLang' => nibblyMenusDefaultLang(),
        'menus' => $menus,
        'pages' => $pages,
        'footer' => $footer,
        'templates' => $scan['static'],
        'revisions' => [
            'menus' => nibblyJsonRevision(nibblyMenusRegistryPath()),
            'footer' => nibblyJsonRevision(nibblyFooterContentPath()),
        ],
    ];
}

/** Registry backups, newest first. */
function nibblyMenusRegistryBackups(): array {
    $dir = defined('BACKUP_PATH') ? BACKUP_PATH : nibblyMenusRoot() . '/backups/';
    $list = [];
    foreach (glob($dir . 'menus-registry_*.json') ?: [] as $file) {
        if (!preg_match('/^menus-registry_(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})_[a-f0-9]{6}\.json$/', basename($file), $m)) continue;
        $list[] = ['filename' => basename($file), 'date' => $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . $m[4], 'size' => filesize($file)];
    }
    usort($list, fn($a, $b) => strcmp($b['filename'], $a['filename']));
    return $list;
}
