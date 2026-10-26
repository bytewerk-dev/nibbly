<?php
/**
 * Site languages: the catalog offered in setup and the dashboard, and the
 * operations behind Settings → Language → Site languages.
 *
 * The language list lives in $SITE_LANGUAGES (code => native name) in
 * admin/config.php and, when the site has one, in includes/nav-config.php
 * (which wins at runtime). Its order is the order of the language switcher.
 * The primary language (SITE_LANG_DEFAULT) is served at the root URL, every
 * other language under /{code}/.
 */

/** Languages that can be chosen for a site: code => native name. */
function nibblySiteLanguageCatalog(): array
{
    return [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'cs' => 'Čeština',
        'da' => 'Dansk',
        'sv' => 'Svenska',
        'no' => 'Norsk',
        'fi' => 'Suomi',
        'hu' => 'Magyar',
        'ro' => 'Română',
        'hr' => 'Hrvatski',
        'sk' => 'Slovenčina',
        'sl' => 'Slovenščina',
        'bg' => 'Български',
        'el' => 'Ελληνικά',
        'tr' => 'Türkçe',
        'ru' => 'Русский',
        'uk' => 'Українська',
        'ja' => '日本語',
        'zh' => '中文',
        'ko' => '한국어',
        'ar' => 'العربية',
    ];
}

/**
 * The current language list in switcher order: includes/nav-config.php when it
 * defines one (it wins at runtime), else the $SITE_LANGUAGES of admin/config.php.
 */
function nibblyCurrentSiteLanguages(string $root): array
{
    $navFile = $root . '/includes/nav-config.php';
    if (is_file($navFile)) {
        $fromNav = (function () use ($navFile) {
            include $navFile;
            return $SITE_LANGUAGES ?? null;
        })();
        if (is_array($fromNav) && $fromNav) return $fromNav;
    }
    return is_array($GLOBALS['SITE_LANGUAGES'] ?? null) ? $GLOBALS['SITE_LANGUAGES'] : [];
}

/** PHP source of a $SITE_LANGUAGES assignment for the given list. */
function nibblySiteLanguagesPhp(array $languages): string
{
    $lines = [];
    foreach ($languages as $code => $name) {
        $lines[] = "    '" . $code . "' => '" . addcslashes((string)$name, "'\\") . "',";
    }
    return "\$SITE_LANGUAGES = [\n" . implode("\n", $lines) . "\n];";
}

/**
 * Replace the $SITE_LANGUAGES assignment in a PHP file. Returns false when the
 * file has none (nothing to change) or cannot be written.
 */
function nibblyRewriteSiteLanguages(string $file, array $languages): bool
{
    if (!is_file($file)) return false;
    $source = (string)file_get_contents($file);
    $count = 0;
    $updated = preg_replace_callback('/\$SITE_LANGUAGES\s*=\s*\[.*?\];/s', function () use ($languages) {
        return nibblySiteLanguagesPhp($languages);
    }, $source, 1, $count);
    if ($count !== 1 || $updated === null) return false;
    if (file_put_contents($file, $updated, LOCK_EX) === false) return false;
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    return true;
}

/** Write the language list (in switcher order) to config.php and nav-config.php. */
function nibblySaveSiteLanguages(string $root, array $languages): bool
{
    if (!nibblyRewriteSiteLanguages($root . '/admin/config.php', $languages)) return false;
    nibblyRewriteSiteLanguages($root . '/includes/nav-config.php', $languages);
    return true;
}

/** A site path of $from moved to $to: '.' / 'about' (primary) or 'en/about' → 'fr/' / 'fr/about'. */
function nibblyTranslateSitePath(string $path, string $from, string $to, string $primary): string
{
    if ($from === $primary) {
        $rest = ($path === '.' || $path === '' || $path === './') ? '' : ltrim($path, '/');
    } else {
        $rest = preg_replace('#^' . preg_quote($from, '#') . '(/|$)#', '', ltrim($path, '/'));
    }
    return $to . '/' . $rest;
}

/** Give every {lang: text} map that has $from a $to entry with the same value (recursively). */
function nibblyCopyLanguageMaps($data, string $from, string $to)
{
    if (!is_array($data)) return $data;
    $keys = array_keys($data);
    $isLangMap = $keys && count(array_filter($keys, fn($k) => is_string($k) && preg_match('/^[a-z]{2}$/', $k))) === count($keys);
    if ($isLangMap && array_key_exists($from, $data) && !array_key_exists($to, $data)) {
        $data[$to] = $data[$from];
        return $data;
    }
    foreach ($data as $key => $value) {
        $data[$key] = nibblyCopyLanguageMaps($value, $from, $to);
    }
    return $data;
}

/**
 * Add a site language, starting from a copy of an existing one: pages, news
 * posts, language templates, navigation items and page mapping, and the
 * multilingual maps of footer, menus and events. Existing files of the new
 * language are never overwritten. The new language is appended to the switcher.
 *
 * @return array{ok:bool, message:string, copied?:array}
 */
function nibblyAddSiteLanguage(string $root, string $code, string $from): array
{
    $catalog = nibblySiteLanguageCatalog();
    $languages = nibblyCurrentSiteLanguages($root);
    $primary = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    if (!isset($catalog[$code])) return ['ok' => false, 'message' => 'Unknown language'];
    if (isset($languages[$code])) return ['ok' => false, 'message' => 'Language exists'];
    if (!isset($languages[$from])) return ['ok' => false, 'message' => 'Unknown source language'];
    if (!is_writable($root . '/admin/config.php')) return ['ok' => false, 'message' => 'admin/config.php is not writable'];

    $copied = ['pages' => 0, 'news' => 0, 'templates' => 0];

    // 1. Pages: {from}_{slug}.json → {code}_{slug}.json
    foreach (glob($root . '/content/pages/' . $from . '_*.json') ?: [] as $file) {
        $target = $root . '/content/pages/' . $code . substr(basename($file), strlen($from));
        if (is_file($target)) continue;
        $page = json_decode((string)file_get_contents($file), true);
        if (!is_array($page)) continue;
        if (isset($page['page']) && is_string($page['page'])) $page['page'] = $code . substr($page['page'], strlen($from));
        if (isset($page['lang'])) $page['lang'] = $code;
        $page['lastModified'] = date('c');
        file_put_contents($target, json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $copied['pages']++;
    }

    // 2. News posts of the source language
    foreach (glob($root . '/content/news/*.json') ?: [] as $file) {
        $post = json_decode((string)file_get_contents($file), true);
        if (!is_array($post) || ($post['lang'] ?? $primary) !== $from) continue;
        $base = basename($file, '.json');
        $base = preg_replace('/-' . preg_quote($from, '/') . '$/', '', $base);
        $target = $root . '/content/news/' . $base . '-' . $code . '.json';
        if (is_file($target)) continue;
        $post['lang'] = $code;
        if (isset($post['id']) && is_string($post['id'])) {
            $post['id'] = preg_replace('/-' . preg_quote($from, '/') . '$/', '', $post['id']) . '-' . $code;
        }
        file_put_contents($target, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $copied['news']++;
    }

    // 3. Language templates ({from}/*.php → {code}/*.php), language code swapped
    $sourceDir = $root . '/' . $from;
    if (is_dir($sourceDir)) {
        $targetDir = $root . '/' . $code;
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
        foreach (glob($sourceDir . '/*.php') ?: [] as $file) {
            $target = $targetDir . '/' . basename($file);
            if (is_file($target)) continue;
            $php = strtr((string)file_get_contents($file), [
                "'" . $from . "'" => "'" . $code . "'",
                "'" . $from . "_" => "'" . $code . "_",
            ]);
            file_put_contents($target, $php, LOCK_EX);
            $copied['templates']++;
        }
    }

    // 4. Multilingual maps: footer, menus, events
    foreach (['/content/pages/footer.json', '/content/menus.json', '/content/events.json'] as $relative) {
        $file = $root . $relative;
        if (!is_file($file)) continue;
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) continue;
        $data = nibblyCopyLanguageMaps($data, $from, $code);
        if ($relative === '/content/menus.json') {
            // Managed menu items (Menus & Footer): page references follow by slug, site paths get the new prefix.
            require_once __DIR__ . '/menu-store.php';
            foreach ($data['menus'] ?? [] as $menuId => $menu) {
                if (!is_array($menu['items'][$code] ?? null)) continue;
                $data['menus'][$menuId]['items'][$code] = nibblyMenusTranslateLinks($menu['items'][$code],
                    fn(string $href): string => nibblyTranslateSitePath($href, $from, $code, $primary));
            }
        }
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    // 5. Navigation items and page mapping for the new language (appended to nav-config.php)
    $navFile = $root . '/includes/nav-config.php';
    if (is_file($navFile) && is_writable($navFile)) {
        $NAV_ITEMS = [];
        $PAGE_MAPPING = [];
        (function () use ($navFile, &$NAV_ITEMS, &$PAGE_MAPPING) {
            include $navFile;
        })();
        $block = "\n// Language '" . $code . "', added in Settings → Language (copied from '" . $from . "')\n";
        $items = [];
        foreach ((array)($NAV_ITEMS[$from] ?? []) as $item) {
            if (isset($item['href']) && is_string($item['href']) && !preg_match('#^([a-z][a-z0-9+.-]*:|/|\#)#i', $item['href'])) {
                $item['href'] = nibblyTranslateSitePath($item['href'], $from, $code, $primary);
            }
            $items[] = $item;
        }
        $block .= "\$NAV_ITEMS['" . $code . "'] = " . var_export($items, true) . ";\n";
        foreach ($PAGE_MAPPING as $key => $paths) {
            if (is_array($paths) && isset($paths[$from]) && !isset($paths[$code])) {
                $block .= "\$PAGE_MAPPING[" . var_export((string)$key, true) . "]['" . $code . "'] = "
                    . var_export(nibblyTranslateSitePath((string)$paths[$from], $from, $code, $primary), true) . ";\n";
            }
        }
        file_put_contents($navFile, rtrim((string)file_get_contents($navFile)) . "\n" . $block, LOCK_EX);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($navFile, true);
    }

    // 6. The language list: appended to the switcher
    $languages[$code] = $catalog[$code];
    if (!nibblySaveSiteLanguages($root, $languages)) {
        return ['ok' => false, 'message' => 'Could not update the language list'];
    }
    return ['ok' => true, 'message' => 'Language added', 'copied' => $copied];
}

/** Reorder the site languages (switcher order); $order must contain exactly the current codes. */
function nibblyReorderSiteLanguages(string $root, array $order): array
{
    $languages = nibblyCurrentSiteLanguages($root);
    $order = array_values(array_filter($order, 'is_string'));
    if (count($order) !== count($languages) || array_diff($order, array_keys($languages))) {
        return ['ok' => false, 'message' => 'The order must list every site language once'];
    }
    $sorted = [];
    foreach ($order as $code) $sorted[$code] = $languages[$code];
    if (!nibblySaveSiteLanguages($root, $sorted)) {
        return ['ok' => false, 'message' => 'Could not update the language list'];
    }
    return ['ok' => true, 'message' => 'Order saved'];
}
