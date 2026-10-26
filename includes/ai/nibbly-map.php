<?php
/**
 * Nibbly map: where things are in the dashboard.
 *
 * One maintained table from a function or setting to the dashboard area, the settings tab and a deep
 * link. The AI assistants use it as knowledge ("where do I find the dashboard language?"), the
 * server builds links from it, and the dashboard assistant's tools take their areas and tabs from it.
 *
 * Names are not stored here: every entry points to label keys of admin/lang/*.json, so a breadcrumb
 * such as "Settings › Language › Admin language" exists in all admin languages. When you add a
 * dashboard area, a settings tab or a setting people ask about, add the entry here
 * (tests/nibbly-map-smoke.php fails when the dashboard has an area or tab the map does not know).
 */

/** Dashboard areas: id => [label key, role ('admin'|'editor'), module key or '']. */
function nibblyMapSections(): array {
    return [
        'home' => ['dashboard_home.title', 'editor', ''],
        'content' => ['nav.pages', 'editor', ''],
        'news' => ['nav.news', 'editor', 'news'],
        'people' => ['people.title', 'editor', 'people'],
        'events' => ['nav.events', 'editor', 'events'],
        'mails' => ['nav.messages', 'editor', 'messages'],
        'media' => ['nav.media_library', 'admin', ''],
        'menus' => ['nav.menus_footer', 'admin', 'menus'],
        'icons' => ['nav.icon_manager', 'admin', 'iconManager'],
        'settings' => ['nav.settings', 'editor', ''],
        'backup' => ['settings.backup', 'admin', ''],
        'system' => ['system.title', 'admin', ''],
    ];
}

/** Settings tabs: id => [label key, role, needs AI features]. */
function nibblyMapSettingsTabs(): array {
    return [
        'branding' => ['settings.branding', 'admin', false],
        'theme' => ['settings.dashboard_design', 'admin', false],
        'modules' => ['settings.modules_nav', 'admin', false],
        'language' => ['settings.language', 'admin', false],
        'access' => ['settings.access', 'admin', false],
        'email' => ['settings.email', 'admin', false],
        'privacy' => ['settings.privacy', 'admin', false],
        'forms' => ['forms.title', 'admin', false],
        'ai' => ['ai.settings', 'admin', true],
        'users' => ['settings.users', 'admin', false],
        'my-account' => ['settings.my_account', 'editor', false],
        'login' => ['settings.login', 'admin', false],
    ];
}

/**
 * Things people look for. id => [section, settings tab or '', label key, English synonyms, English hint].
 * The label key is the name in the dashboard (so it exists in every admin language); the synonyms
 * are words people use that the dashboard does not (they are matched in any language).
 */
function nibblyMapEntries(): array {
    return [
        // Areas
        'area_home' => ['home', '', 'dashboard_home.title', ['overview', 'start', 'statistics', 'visitors', 'startseite des dashboards', 'übersicht', 'besucher'], 'Overview with visitor statistics, status and shortcuts.'],
        'area_pages' => ['content', '', 'nav.pages', ['pages', 'page list', 'new page', 'create page', 'delete page', 'duplicate page', 'seiten', 'neue seite', 'seite löschen', 'seite anlegen', 'seite duplizieren', 'unterseite', 'visual editor', 'html editor', 'wordpress', 'visueller editor', 'seiteneditor'], 'All pages: create, rename, duplicate, delete, page history and backups. Standard pages use a writing view with Visual/HTML text editing and a right sidebar for Save, preview, undo and Page settings, SEO/AEO and Access. Multiple blocks keep their navigation; custom JSON pages keep the field editor.'],
        'area_news' => ['news', '', 'nav.news', ['news', 'blog', 'posts', 'articles', 'neuigkeiten', 'beiträge', 'artikel', 'aktuelles'], 'News posts: create, edit, hide, publish.'],
        'area_people' => ['people', '', 'people.title', ['people', 'person', 'team', 'team member', 'speaker', 'personen', 'teammitglied'], 'Reusable people profiles: name, role, organization, biography, portrait, groups and publication. Embed with Person or Team blocks.'],
        'area_events' => ['events', '', 'nav.events', ['events', 'calendar', 'dates', 'termine', 'veranstaltungen', 'kalender'], 'Events with date, time and place.'],
        'area_messages' => ['mails', '', 'nav.messages', ['messages', 'inbox', 'form submissions', 'contact requests', 'nachrichten', 'anfragen', 'kontaktanfragen', 'formulareinträge'], 'Messages sent through the website forms.'],
        'area_media' => ['media', '', 'nav.media_library', ['media', 'images', 'pictures', 'files', 'uploads', 'upload', 'bilder', 'dateien', 'medien', 'hochladen', 'fotos'], 'Media library: upload, rename, delete and reuse images and files.'],
        'area_menus' => ['menus', '', 'nav.menus_footer', ['menu', 'menus', 'navigation', 'main menu', 'footer menu', 'footer', 'footer links', 'nav', 'menü', 'hauptmenü', 'fußmenü', 'fusszeile', 'fußzeile', 'navigation der website', 'menüpunkt'], 'Main navigation, further menus, footer links and footer content: add, rename, hide, reorder and remove entries.'],
        'area_icons' => ['icons', '', 'nav.icon_manager', ['icons', 'symbols', 'symbole'], 'Icons used on the website.'],
        'area_settings' => ['settings', '', 'nav.settings', ['settings', 'preferences', 'configuration', 'einstellungen', 'konfiguration'], 'All site and account settings, grouped in tabs.'],
        'area_backup' => ['backup', '', 'settings.backup', ['backup', 'backups', 'restore', 'download site', 'save copy', 'sicherung', 'wiederherstellen', 'wiederherstellung', 'datensicherung'], 'Create, download, schedule and restore site backups.'],
        'area_system' => ['system', '', 'system.title', ['system', 'status', 'health', 'security check', 'updates', 'version', 'systemstatus', 'sicherheitsprüfung', 'sicherheitscheck'], 'System status, security check and update notes.'],

        // Settings tabs
        'tab_branding' => ['settings', 'branding', 'settings.branding', ['branding', 'brand', 'name of the site', 'firmenname', 'marke'], 'Site name, favicon, logo and branding visibility.'],
        'tab_theme' => ['settings', 'theme', 'settings.dashboard_design', ['theme', 'design', 'colours', 'colors', 'appearance', 'farben', 'erscheinungsbild', 'darstellung'], 'Look of the dashboard: light/dark/system, primary and accent colour.'],
        'tab_modules' => ['settings', 'modules', 'settings.modules_nav', ['modules', 'features', 'turn on news', 'turn off ai', 'module', 'funktionen ein- ausschalten', 'ki ausschalten', 'news ausschalten'], 'Switch dashboard modules (news, events, messages, menus & footer, icons, AI) on or off.'],
        'tab_language' => ['settings', 'language', 'settings.language', ['languages', 'sprache', 'sprachen', 'language settings'], 'Dashboard language and the language of AI image labels.'],
        'tab_access' => ['settings', 'access', 'settings.access', ['maintenance', 'offline', 'coming soon', 'launch', 'preview', 'under construction', 'wartung', 'baustelle', 'vorschau', 'zugriff'], 'Maintenance mode, countdown and preview bypass link.'],
        'tab_email' => ['settings', 'email', 'settings.email', ['email', 'e-mail', 'mail', 'sender', 'notification', 'contact form mail', 'mailversand', 'absender', 'benachrichtigung', 'empfänger'], 'Mail sending (SMTP), recipient and sender for form messages.'],
        'tab_privacy' => ['settings', 'privacy', 'settings.privacy', ['privacy', 'cookies', 'cookie', 'datenschutz', 'e-mail verschleierung'], 'Privacy options, visitor statistics and e-mail obfuscation.'],
        'tab_forms' => ['settings', 'forms', 'forms.title', ['forms', 'form', 'contact form', 'formulare', 'formular', 'kontaktformular'], 'Public forms and their fields.'],
        'tab_ai' => ['settings', 'ai', 'ai.settings', ['ai', 'artificial intelligence', 'api key', 'model', 'provider', 'openai', 'anthropic', 'agent', 'ki', 'ki-einstellungen', 'api-schlüssel', 'modell', 'kosten', 'limit'], 'AI provider, API key, models, limits, costs and the AI agent.'],
        'tab_users' => ['settings', 'users', 'settings.users', ['users', 'user', 'accounts', 'editors', 'roles', 'add user', 'delete user', 'benutzer', 'nutzer', 'konten', 'rollen', 'benutzer anlegen', 'benutzer löschen'], 'Create, edit and remove users and set their role (administrator or editor).'],
        'tab_my_account' => ['settings', 'my-account', 'settings.my_account', ['my account', 'profile', 'my email', 'mein konto', 'profil'], 'Your own password and e-mail address.'],
        'tab_login' => ['settings', 'login', 'settings.login', ['login', 'sign in', 'log in page', 'frontend login', 'anmeldung', 'login-seite', 'anmeldeseite'], 'Design of the login page and where people land after signing in.'],
        'tab_danger' => ['backup', '', 'settings.danger_zone', ['reset', 'delete everything', 'factory reset', 'zurücksetzen', 'alles löschen', 'werkseinstellungen'], 'Total reset of the site, folded away at the bottom of the backup page. Irreversible; only the person who owns the site should use it.'],

        // Single settings
        'set_admin_language' => ['settings', 'language', 'settings.admin_language', ['system language', 'interface language', 'ui language', 'dashboard language', 'admin language', 'language of the dashboard', 'change language', 'systemsprache', 'oberflächensprache', 'sprache des dashboards', 'dashboard-sprache', 'sprache der oberfläche', 'sprache ändern', 'idioma del sistema', 'langue du système', 'lingua del sistema'], 'Language of the dashboard (the admin interface). The assistant can change it after you confirm.'],
        'set_theme' => ['settings', 'theme', 'copilot.dash_setting_adminTheme', ['dark mode', 'light mode', 'dark', 'light', 'night mode', 'dashboard theme', 'dunkelmodus', 'hellmodus', 'dunkel', 'hell'], 'Light, dark or follow the device. The assistant can change it after you confirm.'],
        'set_colors' => ['settings', 'theme', 'copilot.dash_setting_primaryColor', ['primary colour', 'primary color', 'accent colour', 'accent color', 'dashboard colour', 'hauptfarbe', 'akzentfarbe', 'farbe des dashboards'], 'Primary and accent colour of the dashboard. The assistant can change them after you confirm.'],
        'set_site_name' => ['settings', 'branding', 'settings.site_name', ['site name', 'website name', 'seitenname', 'name der website'], 'Name of the website.'],
        'set_logo' => ['settings', 'branding', 'settings.logo', ['logo', 'brand logo'], 'Logo of the website.'],
        'set_favicon' => ['settings', 'branding', 'settings.favicon', ['favicon', 'browser icon', 'tab icon'], 'Small icon in the browser tab.'],
        'set_maintenance' => ['settings', 'access', 'settings.maintenance', ['maintenance mode', 'take site offline', 'coming soon page', 'wartungsmodus', 'seite offline nehmen'], 'Show visitors a maintenance page instead of the site.'],
        'set_smtp' => ['settings', 'email', 'settings.smtp_settings', ['smtp', 'mail server', 'mailserver', 'send emails', 'mails senden'], 'Mail server for sending form messages.'],
        'set_password' => ['settings', 'my-account', 'settings.change_password', ['password', 'change my password', 'passwort ändern', 'mein passwort'], 'Change your own password.'],
        'set_analytics' => ['settings', 'privacy', 'analytics.enabled', ['visitor statistics', 'analytics', 'tracking', 'besucherstatistik', 'statistik'], 'Switch visitor statistics on or off.'],
        'set_total_reset' => ['backup', '', 'settings.total_reset', ['reset the whole site', 'wipe site', 'alles zurücksetzen'], 'Wipes the site back to a fresh install. Never done by the assistant.'],
        'set_ai_label' => ['settings', 'language', 'settings.ai_label_artwork', ['ai label', 'ai images label', 'ki-kennzeichnung', 'kennzeichnung ki-bilder'], 'Language of the AI label shown on generated images.'],
    ];
}

/** Ids of the entries that have a dashboard change action (the assistant can do them after a confirm). */
function nibblyMapActionEntries(): array {
    return ['adminLanguage' => 'set_admin_language', 'adminTheme' => 'set_theme', 'primaryColor' => 'set_colors', 'accentColor' => 'set_colors'];
}

// ---------------------------------------------------------------------------
// Names and links
// ---------------------------------------------------------------------------

/** Admin UI languages that have a language file (code => strings), loaded once. */
function nibblyMapLanguageStrings(string $code): array {
    static $cache = [];
    if (!preg_match('/^[a-z]{2,3}$/', $code)) return [];
    if (!isset($cache[$code])) {
        $file = __DIR__ . '/../../admin/lang/' . $code . '.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        $cache[$code] = is_array($data) ? $data : [];
    }
    return $cache[$code];
}

/** All admin language codes. */
function nibblyMapLanguageCodes(): array {
    static $codes = null;
    if ($codes === null) {
        $codes = [];
        foreach (glob(__DIR__ . '/../../admin/lang/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if (!str_starts_with($name, 'editor-')) $codes[] = $name;
        }
        sort($codes);
    }
    return $codes;
}

/** Text of a label key in an admin language (English, then the key, as fallback). */
function nibblyMapLabel(string $key, ?string $language = null): string {
    if ($language === null) return function_exists('t') ? t($key) : (nibblyMapLanguageStrings('en')[$key] ?? $key);
    return nibblyMapLanguageStrings($language)[$key] ?? nibblyMapLanguageStrings('en')[$key] ?? $key;
}

function nibblyMapIsAdmin(): bool {
    return function_exists('isAdmin') ? isAdmin() : (($_SESSION['admin_role'] ?? '') === 'admin');
}

/** Whether a dashboard module is switched on (the same default as the dashboard: on). */
function nibblyMapModuleEnabled(string $module): bool {
    if ($module === '') return true;
    static $cache = ['key' => null, 'modules' => []];
    $path = defined('SETTINGS_PATH') ? (string)SETTINGS_PATH : '';
    $key = $path . '|' . ($path !== '' && is_file($path) ? filemtime($path) . '|' . filesize($path) : '');
    if ($cache['key'] !== $key) { // read again when the settings file changed (the file is small and read at most once per change)
        $modules = [];
        if ($path !== '' && is_file($path)) {
            $settings = json_decode((string)file_get_contents($path), true);
            if (is_array($settings) && is_array($settings['modules'] ?? null)) $modules = $settings['modules'];
        }
        $cache = ['key' => $key, 'modules' => $modules];
    }
    return !array_key_exists($module, $cache['modules']) || !empty($cache['modules'][$module]);
}

/** Whether the AI tab exists in the settings (the AI module is on). */
function nibblyMapAiTabAvailable(): bool {
    return nibblyMapModuleEnabled('ai');
}

/** Address of the dashboard, from the script that serves this request (same origin, no host). */
function nibblyMapDashboardUrl(): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (!preg_match('#/\.{1,2}(?:/|$)#', $script) && preg_match('#^((?:/[A-Za-z0-9._~-]+)*)/admin/[A-Za-z0-9._/~-]*\.php$#', $script, $match)) {
        return $match[1] . '/admin/dashboard.php';
    }
    return '/admin/dashboard.php';
}

/** "This module is switched off. You can switch it on under Settings › Modules." in the dashboard language. */
function nibblyMapModuleOffNote(): string {
    $path = nibblyMapLabel('nav.settings') . ' › ' . nibblyMapLabel('settings.modules_nav');
    return str_replace('{path}', $path, nibblyMapLabel('copilot.map_module_off'));
}

/** The part after # that opens an area or a settings tab. */
function nibblyMapHash(string $section, string $tab = ''): string {
    $public = ['home' => 'dashboard', 'content' => 'pages', 'mails' => 'messages'][$section] ?? $section;
    return '#' . $public . ($section === 'settings' && $tab !== '' ? '/' . $tab : '');
}

/**
 * Describe one entry for a person: name, breadcrumb and link.
 *
 * @return array|null null for an unknown id
 *   [id, title, path, url, section, settingsTab, available, restricted, note]
 *   restricted: the signed-in role may not use it (no link); available: false when a module is switched off
 *   (the link then leads to the modules tab).
 */
function nibblyMapDescribe(string $id, ?bool $isAdmin = null): ?array {
    $entries = nibblyMapEntries();
    if (!isset($entries[$id])) return null;
    [$section, $tab, $labelKey] = $entries[$id];
    $isAdmin ??= nibblyMapIsAdmin();
    $sections = nibblyMapSections();
    $tabs = nibblyMapSettingsTabs();
    $crumbs = [nibblyMapLabel($sections[$section][0])];
    if ($tab !== '') $crumbs[] = nibblyMapLabel($tabs[$tab][0]);
    $title = nibblyMapLabel($labelKey);
    if (end($crumbs) !== $title) $crumbs[] = $title;

    $role = $tab !== '' ? $tabs[$tab][1] : $sections[$section][1];
    if ($sections[$section][1] === 'admin') $role = 'admin';
    $restricted = $role === 'admin' && !$isAdmin;
    $available = true;
    $note = '';
    $linkSection = $section;
    $linkTab = $tab;
    if ($restricted) {
        $note = nibblyMapLabel('copilot.map_admin_only');
    } elseif (!nibblyMapModuleEnabled($sections[$section][2])) {
        $available = false;
        $note = nibblyMapModuleOffNote();
        $linkSection = 'settings';
        $linkTab = 'modules';
    } elseif ($tab === 'ai' && !nibblyMapAiTabAvailable()) {
        $available = false;
        $note = nibblyMapModuleOffNote();
        $linkSection = 'settings';
        $linkTab = 'modules';
    }
    return [
        'id' => $id, 'title' => $title, 'path' => implode(' › ', $crumbs),
        'url' => $restricted ? '' : nibblyMapDashboardUrl() . nibblyMapHash($linkSection, $linkTab),
        'section' => $section, 'settingsTab' => $tab, 'available' => $available, 'restricted' => $restricted, 'note' => $note,
    ];
}

/** The entry that belongs to a change_setting key, as a description (for the cards). */
function nibblyMapDescribeSetting(string $setting, ?bool $isAdmin = null): ?array {
    $id = nibblyMapActionEntries()[$setting] ?? null;
    return $id === null ? null : nibblyMapDescribe($id, $isAdmin);
}

// ---------------------------------------------------------------------------
// Finding
// ---------------------------------------------------------------------------

/** Lowercase text without accents and with simple word characters (for matching across languages). */
function nibblyMapNormalize(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ę' => 'e', 'ě' => 'e', 'í' => 'i',
        'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ı' => 'i', 'İ' => 'i', 'ł' => 'l', 'ń' => 'n', 'ñ' => 'n', 'ň' => 'n', 'ó' => 'o',
        'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ř' => 'r', 'ť' => 't', 'ú' => 'u',
        'ù' => 'u', 'û' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z', 'ğ' => 'g',
    ]);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $text));
}

/** Search terms of an entry: its name in every admin language, plus the synonyms (normalised, unique). */
function nibblyMapTerms(string $id): array {
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    $entry = nibblyMapEntries()[$id];
    $terms = [];
    foreach (nibblyMapLanguageCodes() as $code) $terms[] = nibblyMapNormalize(nibblyMapLabel($entry[2], $code));
    foreach ($entry[3] as $alias) $terms[] = nibblyMapNormalize($alias);
    return $cache[$id] = array_values(array_unique(array_filter($terms, static fn($term) => strlen($term) >= 2)));
}

/**
 * Find entries for a free-text question or request, best first.
 *
 * @return array<int,array> descriptions (see nibblyMapDescribe) with 'score'
 */
function nibblyMapLookup(string $query, ?bool $isAdmin = null, int $limit = 3): array {
    $query = nibblyMapNormalize(mb_substr($query, 0, 600, 'UTF-8'));
    if ($query === '') return [];
    $padded = ' ' . $query . ' ';
    $tokens = array_values(array_filter(explode(' ', $query), static fn($token) => strlen($token) >= 4));
    $scored = [];
    foreach (array_keys(nibblyMapEntries()) as $id) {
        $score = 0;
        foreach (nibblyMapTerms($id) as $term) {
            if (str_contains($padded, ' ' . $term . ' ')) {
                $score = max($score, 100 + strlen($term) * 4); // the whole name or synonym is in the text
                continue;
            }
            foreach ($tokens as $token) {
                if (strlen($term) >= 5 && str_contains($token, $term)) $score = max($score, 30 + strlen($term)); // "systemsprache" contains "sprache"
                elseif (strlen($token) >= 5 && !str_contains($term, ' ') && str_contains($term, $token)) $score = max($score, 20 + strlen($token));
            }
        }
        if ($score > 0) $scored[$id] = $score;
    }
    arsort($scored);
    $best = $scored ? max($scored) : 0;
    $result = [];
    foreach ($scored as $id => $score) {
        if ($score < 40 || $score < $best - 25) continue;
        $description = nibblyMapDescribe((string)$id, $isAdmin);
        if ($description === null) continue;
        $result[] = $description + ['score' => $score];
        if (count($result) >= $limit) break;
    }
    return $result;
}

// ---------------------------------------------------------------------------
// Knowledge for the models
// ---------------------------------------------------------------------------

/**
 * Compact description of the dashboard for the system prompt. Names are in the dashboard language,
 * hints in English (they are for the model). Entries the role cannot use are left out for editors.
 */
function nibblyMapKnowledge(bool $isAdmin): string {
    $lines = ['Dashboard map (name = what the person sees in the dashboard; tell people where to find things with these exact names and offer the link):'];
    foreach (array_keys(nibblyMapEntries()) as $id) {
        $description = nibblyMapDescribe($id, $isAdmin);
        if ($description === null || $description['restricted']) continue;
        $entry = nibblyMapEntries()[$id];
        $lines[] = '- ' . $id . ': ' . $description['path'] . ' — ' . $entry[4] . ($description['available'] ? '' : ' (switched off: ' . $description['note'] . ')');
    }
    if (!$isAdmin) $lines[] = 'This person is an editor: settings other than My account, backups, media library, users and system belong to administrators.';
    $lines[] = 'The site languages (primary and secondary) and the dashboard language are different things: the dashboard language is set in Settings › Language; the site languages are set when the site is installed.';
    return implode("\n", $lines);
}

/** Entry ids a model may name in tools (those the role can reach). */
function nibblyMapToolIds(bool $isAdmin): array {
    $ids = [];
    foreach (array_keys(nibblyMapEntries()) as $id) {
        $description = nibblyMapDescribe($id, $isAdmin);
        if ($description !== null && !$description['restricted']) $ids[] = $id;
    }
    return $ids;
}

/** One short paragraph about the structure of the dashboard, for the Copilot's knowledge base (names in the dashboard language). */
function nibblyMapStructureSummary(bool $isAdmin): string {
    $areas = [];
    foreach (nibblyMapSections() as $id => [$label, $role, $module]) {
        if ($role === 'admin' && !$isAdmin) continue;
        if (!nibblyMapModuleEnabled($module)) continue;
        $areas[] = nibblyMapLabel($label);
    }
    $tabs = [];
    foreach (nibblyMapSettingsTabs() as $id => [$label, $role, $needsAi]) {
        if (($role === 'admin' && !$isAdmin) || ($needsAi && !nibblyMapAiTabAvailable())) continue;
        $tabs[] = nibblyMapLabel($label);
    }
    return 'The dashboard has these areas: ' . implode(', ', $areas) . '. The settings have these tabs: ' . implode(', ', $tabs)
        . '. The dashboard language is set in ' . nibblyMapLabel('nav.settings') . ' › ' . nibblyMapLabel('settings.language') . ', not in the page editor. Tell people where something is with these names; never only say "in the settings".';
}

/** Pages of the site (content key and title), for the dashboard assistant. */
function nibblyMapPagesKnowledge(string $contentRoot, int $limit = 40): string {
    $pages = [];
    foreach (glob(rtrim($contentRoot, '/') . '/pages/*.json') ?: [] as $file) {
        $key = basename($file, '.json');
        if (in_array($key, ['footer', 'sidebar'], true) || !preg_match('/^[a-z]{2}_[a-z0-9_\/-]+$/i', $key)) continue;
        $json = json_decode((string)file_get_contents($file), true);
        $title = is_array($json) ? trim(strip_tags((string)($json['title'] ?? ''))) : '';
        $pages[] = $key . ($title !== '' ? ' — ' . mb_substr($title, 0, 50, 'UTF-8') : '');
    }
    sort($pages);
    $total = count($pages);
    if (!$total) return '';
    return 'Pages of this site (' . $total . '): ' . implode('; ', array_slice($pages, 0, $limit)) . ($total > $limit ? '; …' : '') . '. Each page opens in the page editor from the Pages area.';
}
