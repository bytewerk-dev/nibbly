<?php
/**
 * Editorial guide ("Handbuch") at /admin/handbook.
 *
 * Core chapters live in includes/handbook/chapters/{id}.{lang}.php (de, en),
 * screenshots in includes/handbook/img/{lang}/ (retaken by tests/handbook-screenshots.py).
 * A site adds or hides chapters with the site-owned folder includes/site-handbook/:
 *
 *   includes/site-handbook/chapters.php   returns the manifest below
 *   includes/site-handbook/{file}         chapter bodies, e.g. speaker.de.php
 *   includes/site-handbook/img/           screenshots (optionally img/de/, img/en/), referenced as "site/{name}"
 *
 *   return [
 *       'chapters' => [
 *           ['id' => 'speaker', 'after' => 'people', 'title' => ['de' => 'Speaker', 'en' => 'Speakers'],
 *            'file' => 'speaker.{lang}.php', 'role' => 'all', 'module' => null],
 *       ],
 *       'hide' => ['icons'],
 *       'contact' => ['de' => 'Fragen? Schreib an …', 'en' => 'Questions? Write to …'],
 *   ];
 *
 * Settings → Modules → Guide (modules.handbook, on by default) turns the guide
 * and every link to it off; the route then answers 404.
 */

const NIBBLY_HANDBOOK_DIR = __DIR__ . '/handbook';
const NIBBLY_HANDBOOK_SITE_DIR = __DIR__ . '/site-handbook';
const NIBBLY_HANDBOOK_LANGUAGES = ['de', 'en'];

/** Whether the guide is switched on (Settings → Modules). */
function nibblyHandbookEnabled(?array $settings = null): bool {
    if ($settings === null) {
        $settings = defined('SETTINGS_PATH') && is_file(SETTINGS_PATH) ? json_decode((string)file_get_contents(SETTINGS_PATH), true) : [];
    }
    return !is_array($settings) || !is_array($settings['modules'] ?? null) || ($settings['modules']['handbook'] ?? true) !== false;
}

/**
 * Core chapters in reading order. "module" names the key in settings.modules
 * the chapter depends on; "role" admin hides it from editors, who do not see
 * that part of the dashboard.
 */
function nibblyHandbookCoreChapters(): array {
    return [
        'welcome'   => ['module' => null,          'role' => 'all'],
        'login'     => ['module' => null,          'role' => 'all'],
        'edit-mode' => ['module' => null,          'role' => 'all'],
        'saving'    => ['module' => null,          'role' => 'all'],
        'texts'     => ['module' => null,          'role' => 'all'],
        'links'     => ['module' => null,          'role' => 'all'],
        'images'    => ['module' => null,          'role' => 'all'],
        'pages'     => ['module' => null,          'role' => 'all'],
        'menus'     => ['module' => 'menus',       'role' => 'admin'],
        'news'      => ['module' => 'news',        'role' => 'all'],
        'events'    => ['module' => 'events',      'role' => 'all'],
        'people'    => ['module' => 'people',      'role' => 'all'],
        'messages'  => ['module' => 'messages',    'role' => 'all'],
        'icons'     => ['module' => 'iconManager', 'role' => 'admin'],
        'ai'        => ['module' => 'ai',          'role' => 'all'],
        'settings'  => ['module' => null,          'role' => 'all'],
        'users'     => ['module' => null,          'role' => 'admin'],
        'backups'   => ['module' => null,          'role' => 'all'],
        'security'  => ['module' => null,          'role' => 'all'],
        'tips'      => ['module' => null,          'role' => 'all'],
    ];
}

/** The site's manifest (includes/site-handbook/chapters.php), or an empty one. */
function nibblyHandbookSiteManifest(): array {
    $file = NIBBLY_HANDBOOK_SITE_DIR . '/chapters.php';
    $manifest = is_file($file) ? (static fn() => include $file)() : [];
    if (!is_array($manifest)) $manifest = [];
    return [
        'chapters' => is_array($manifest['chapters'] ?? null) ? array_values($manifest['chapters']) : [],
        'hide' => is_array($manifest['hide'] ?? null) ? array_map('strval', $manifest['hide']) : [],
        'contact' => $manifest['contact'] ?? null,
    ];
}

/** Language of the guide: the admin language when the guide has it, else English. */
function nibblyHandbookLanguage(): string {
    $lang = function_exists('_nbAdminLang') ? _nbAdminLang() : 'en';
    return in_array($lang, NIBBLY_HANDBOOK_LANGUAGES, true) ? $lang : 'en';
}

/** A per-language value: a string as it is, an array by language with English, then German as fallback. */
function nibblyHandbookPick($value, string $lang): string {
    if (!is_array($value)) return (string)$value;
    foreach ([$lang, 'en', 'de'] as $candidate) {
        if (isset($value[$candidate]) && is_string($value[$candidate])) return $value[$candidate];
    }
    $first = reset($value);
    return is_string($first) ? $first : '';
}

/** The first existing file for a "{lang}" pattern, trying the language, English, then German. */
function nibblyHandbookLocalizedFile(string $dir, string $pattern, string $lang): ?string {
    foreach (array_unique([$lang, 'en', 'de']) as $candidate) {
        $name = str_replace('{lang}', $candidate, $pattern);
        if (preg_match('~^[A-Za-z0-9._-]+$~D', $name) && is_file($dir . '/' . $name)) return $dir . '/' . $name;
    }
    return null;
}

/**
 * Chapters to show: core chapters filtered by module and role, the site's own
 * chapters placed after the chapter they name (or at the end). Each entry has
 * id, title, file and site (bool).
 */
function nibblyHandbookChapters(string $lang, bool $isAdmin, array $modules, ?array $manifest = null): array {
    $manifest = $manifest ?? nibblyHandbookSiteManifest();
    $visible = static function (array $chapter) use ($isAdmin, $modules): bool {
        if (($chapter['role'] ?? 'all') === 'admin' && !$isAdmin) return false;
        $module = $chapter['module'] ?? null;
        return $module === null || $module === '' || ($modules[$module] ?? true) !== false;
    };

    $chapters = [];
    foreach (nibblyHandbookCoreChapters() as $id => $chapter) {
        if (in_array($id, $manifest['hide'], true) || !$visible($chapter)) continue;
        $file = nibblyHandbookLocalizedFile(NIBBLY_HANDBOOK_DIR . '/chapters', $id . '.{lang}.php', $lang);
        if ($file === null) continue;
        $chapters[] = ['id' => $id, 'title' => t('handbook.chapter_' . str_replace('-', '_', $id)), 'file' => $file, 'site' => false];
    }

    foreach ($manifest['chapters'] as $chapter) {
        if (!is_array($chapter) || !$visible($chapter)) continue;
        $id = (string)($chapter['id'] ?? '');
        if (!preg_match('~^[a-z0-9-]+$~D', $id) || in_array($id, array_column($chapters, 'id'), true)) continue;
        $file = nibblyHandbookLocalizedFile(NIBBLY_HANDBOOK_SITE_DIR, (string)($chapter['file'] ?? $id . '.{lang}.php'), $lang);
        if ($file === null) continue;
        $entry = ['id' => $id, 'title' => nibblyHandbookPick($chapter['title'] ?? $id, $lang), 'file' => $file, 'site' => true];
        $position = array_search((string)($chapter['after'] ?? ''), array_column($chapters, 'id'), true);
        if ($position === false) {
            $chapters[] = $entry;
        } else {
            array_splice($chapters, $position + 1, 0, [$entry]);
        }
    }
    return $chapters;
}

/**
 * Path of a screenshot for ?img=: "name.webp" from the core folder, "site/name.webp"
 * from the site's. Each folder may hold one subfolder per language (img/de/, img/en/);
 * the guide's language comes first, then English, German and the folder itself.
 */
function nibblyHandbookImagePath(string $name, ?string $lang = null): ?string {
    if (!preg_match('~^(site/)?([a-z0-9][a-z0-9-]*\.(?:jpg|png|webp))$~D', $name, $match)) return null;
    $dir = ($match[1] !== '' ? NIBBLY_HANDBOOK_SITE_DIR : NIBBLY_HANDBOOK_DIR) . '/img/';
    foreach (array_unique([$lang ?? nibblyHandbookLanguage(), 'en', 'de']) as $candidate) {
        if (is_file($dir . $candidate . '/' . $match[2])) return $dir . $candidate . '/' . $match[2];
    }
    return is_file($dir . $match[2]) ? $dir . $match[2] : null;
}

/**
 * Helpers for chapter templates ($hb). Small UI elements are drawn live with
 * the admin CSS, so they follow the installation's preset, colours and mode;
 * texts never name a colour.
 */
final class NibblyHandbook {
    /** @var array<string, string> chapter id => title of every visible chapter */
    private array $titles;

    public function __construct(
        public readonly string $lang,
        public readonly bool $isAdmin,
        public readonly array $modules,
        public readonly string $siteName,
        array $chapters
    ) {
        $this->titles = array_column($chapters, 'title', 'id');
    }

    public static function esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Whether a module is on (settings.modules). */
    public function module(string $key): bool {
        return ($this->modules[$key] ?? true) !== false;
    }

    /** Whether a chapter is part of this guide (for cross references). */
    public function has(string $id): bool {
        return isset($this->titles[$id]);
    }

    /** Link to another chapter by its title; plain text when that chapter is hidden. */
    public function ref(string $id, ?string $label = null): string {
        $text = self::esc($label ?? ($this->titles[$id] ?? $id));
        return $this->has($id) ? '<a href="#' . self::esc($id) . '">' . $text . '</a>' : '<b>' . $text . '</b>';
    }

    /**
     * A screenshot with caption; a click opens it large. $image is a core file
     * name ("pages.webp") or "site/…" for the site's own folder. $caption is HTML.
     */
    public function shot(string $image, string $alt, string $caption = ''): string {
        $file = nibblyHandbookImagePath($image, $this->lang);
        if ($file === null) return '';
        $src = 'handbook?img=' . rawurlencode($image);
        $size = @getimagesize($file) ?: [1600, 1000];
        return '<figure class="hb-shot"><button type="button" data-full="' . self::esc($src) . '">'
            . '<img src="' . self::esc($src) . '" alt="' . self::esc($alt) . '" loading="lazy" width="' . (int)$size[0] . '" height="' . (int)$size[1] . '"></button>'
            . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
    }

    /** Two screenshots side by side on wide screens. */
    public function pair(string $first, string $second): string {
        return '<div class="hb-pair">' . $first . $second . '</div>';
    }

    /** A dashboard button as it looks in this installation: primary, secondary or danger. */
    public function btn(string $label, string $variant = 'secondary', string $icon = ''): string {
        $variant = in_array($variant, ['primary', 'secondary', 'danger'], true) ? $variant : 'secondary';
        return '<span class="hb-live btn btn-' . $variant . ' btn-sm" aria-hidden="true">' . $this->svg($icon) . self::esc($label) . '</span>'
            . '<span class="hb-sr">' . self::esc($label) . '</span>';
    }

    /** A button of the admin bar on the website: quiet, primary (filled) or save (outlined). */
    public function bar(string $label, string $variant = 'quiet', string $icon = ''): string {
        $variant = in_array($variant, ['quiet', 'primary', 'save'], true) ? $variant : 'quiet';
        return '<span class="hb-live hb-bar" aria-hidden="true"><span class="hb-bar-btn hb-bar-btn--' . $variant . '">' . $this->svg($icon) . self::esc($label) . '</span></span>'
            . '<span class="hb-sr">' . self::esc($label) . '</span>';
    }

    /** A sidebar entry of the dashboard with its icon. */
    public function nav(string $label, string $icon): string {
        return '<span class="hb-live hb-nav" aria-hidden="true">' . $this->svg($icon) . self::esc($label) . '</span>'
            . '<span class="hb-sr">' . self::esc($label) . '</span>';
    }

    /** A switch, on or off. */
    public function toggle(bool $on = true, string $label = ''): string {
        $state = t($on ? 'handbook.toggle_on' : 'handbook.toggle_off');
        return '<span class="hb-live hb-toggle" aria-hidden="true"><span class="toggle-switch"><input type="checkbox" tabindex="-1" disabled' . ($on ? ' checked' : '') . '><span class="toggle-slider"></span></span></span>'
            . '<span class="hb-sr">' . self::esc($label !== '' ? $label . ': ' . $state : $state) . '</span>';
    }

    /** An icon button without text, e.g. undo; $label is read out. */
    public function icon(string $icon, string $label): string {
        return '<span class="hb-live hb-icon" title="' . self::esc($label) . '" aria-hidden="true">' . $this->svg($icon) . '</span>'
            . '<span class="hb-sr">' . self::esc($label) . '</span>';
    }

    /** A keyboard shortcut; Mac and Windows variants, e.g. key('⌘ Z', 'Strg Z'). */
    public function key(string $mac, string $windows = ''): string {
        $html = '<kbd class="hb-key">' . self::esc($mac) . '</kbd>';
        if ($windows !== '') $html .= ' <span class="hb-key-alt">(' . self::esc(t('handbook.windows')) . ' <kbd class="hb-key">' . self::esc($windows) . '</kbd>)</span>';
        return $html;
    }

    /** A label in the interface that is not a button: a menu entry, a field, a tab. */
    public function ui(string $label): string {
        return '<span class="hb-ui">' . self::esc($label) . '</span>';
    }

    /** A note with a short heading. $body is HTML. */
    public function note(string $title, string $body): string {
        return '<div class="hb-note"><strong>' . self::esc($title) . '</strong><p>' . $body . '</p></div>';
    }

    /** Line icons shared with the dashboard (24 px grid, stroke). */
    public function svg(string $name): string {
        static $paths = [
            'book' => '<path d="M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2zM22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7z"/>',
            'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
            'pages' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
            'news' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'people' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M17 4a4 4 0 0 1 0 8M22 21v-2a7 7 0 0 0-4-6"/>',
            'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/>',
            'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
            'menu' => '<path d="M3 6h18M3 12h18M3 18h12"/>',
            'icons' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><circle cx="17.5" cy="17.5" r="3.5"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'backup' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5M12 7v5l3 3"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'ai' => '<path d="M12 2l1.6 5.2L19 9l-5.4 1.8L12 16l-1.6-5.2L5 9l5.4-1.8L12 2z"/><path d="M19 14l.8 2.7 2.2.8-2.2.8L19 21l-.8-2.7-2.2-.8 2.2-.8L19 14z"/>',
            'undo' => '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
            'redo' => '<path d="M15 14l5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h3"/>',
            'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
            'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'trash' => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6h14z"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'check' => '<path d="M20 6L9 17l-5-5"/>',
            'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
            'grip' => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        ];
        if ($name === '' || !isset($paths[$name])) return '';
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
    }
}
