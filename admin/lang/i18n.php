<?php
/**
 * Nibbly CMS — Admin i18n helper
 *
 * Provides t() for translating admin UI strings.
 * Language files: admin/lang/{code}.json  (flat key-value)
 * Editor files:  admin/lang/editor-{code}.json
 *
 * Resolution order for admin language:
 *   1. settings.json  → general.adminLanguage  (if set)
 *   2. config.php     → SITE_LANG_DEFAULT
 *   3. 'en'           (ultimate fallback)
 */

/**
 * Determine the admin UI language code.
 */
function _nbAdminLang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;

    // 1. Override from settings.json
    if (defined('SETTINGS_PATH') && is_file(SETTINGS_PATH)) {
        $settings = json_decode(file_get_contents(SETTINGS_PATH), true);
        $override = $settings['general']['adminLanguage'] ?? '';
        if ($override !== '') {
            $lang = $override;
            return $lang;
        }
    }

    // 2. Primary site language
    $lang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    return $lang;
}

/**
 * Load a JSON translation file. Returns associative array.
 */
function _nbLoadLangFile(string $path): array {
    if (!is_file($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Translate an admin UI string.
 *
 * @param string $key    Dot-notation key, e.g. "nav.pages"
 * @param array  $params Placeholder replacements: ['slug' => 'home'] replaces {slug}
 * @return string Translated string, or English fallback, or the key itself
 */
function t(string $key, array $params = []): string {
    static $strings = null;
    static $fallback = null;

    if ($strings === null) {
        $langDir  = __DIR__ . '/';
        $lang     = _nbAdminLang();
        $strings  = _nbLoadLangFile($langDir . $lang . '.json');
        $fallback = ($lang !== 'en') ? _nbLoadLangFile($langDir . 'en.json') : [];
    }

    $text = $strings[$key] ?? $fallback[$key] ?? $key;

    if ($params) {
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', $v, $text);
        }
    }

    return $text;
}

/**
 * Return all admin translation strings as array (for JS injection).
 */
function tAll(): array {
    static $merged = null;
    if ($merged !== null) return $merged;

    $langDir  = __DIR__ . '/';
    $lang     = _nbAdminLang();
    $en       = _nbLoadLangFile($langDir . 'en.json');
    $current  = ($lang !== 'en') ? _nbLoadLangFile($langDir . $lang . '.json') : [];

    $merged = array_merge($en, $current);
    return $merged;
}

/**
 * Return all editor translation strings (for frontend JS injection).
 */
function tEditorAll(): array {
    $langDir  = __DIR__ . '/';
    $lang     = _nbAdminLang();
    $en       = _nbLoadLangFile($langDir . 'editor-en.json');
    $current  = ($lang !== 'en') ? _nbLoadLangFile($langDir . 'editor-' . $lang . '.json') : [];

    return array_merge($en, $current);
}

/**
 * List available admin languages (code => native name).
 * Scans admin/lang/ for {code}.json files (excludes editor-* files).
 */
function tAvailableLanguages(): array {
    $langDir = __DIR__ . '/';
    $names = [
        'en' => 'English', 'de' => 'Deutsch', 'es' => 'Español',
        'fr' => 'Français', 'it' => 'Italiano', 'pt' => 'Português',
        'nl' => 'Nederlands', 'pl' => 'Polski', 'ja' => '日本語',
        'zh' => '中文', 'ko' => '한국어', 'ru' => 'Русский',
        'cs' => 'Čeština', 'tr' => 'Türkçe',
    ];

    $langs = [];
    foreach (glob($langDir . '*.json') as $file) {
        $basename = basename($file, '.json');
        if (str_starts_with($basename, 'editor-')) continue;
        $langs[$basename] = $names[$basename] ?? $basename;
    }
    ksort($langs);
    return $langs;
}
