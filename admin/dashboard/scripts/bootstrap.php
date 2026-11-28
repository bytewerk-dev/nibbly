<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // Block Type Registry
    window.BlockTypeRegistry = <?php
        require_once dirname(NIBBLY_DASHBOARD_DIR) . '/includes/menu-helpers.php';
        echo json_encode(getBlockTypes(), JSON_UNESCAPED_UNICODE);
    ?>;

    // Admin translations for JS
    const NB_LANG = <?php echo json_encode(array_merge(tEditorAll(), tAll()), JSON_UNESCAPED_UNICODE); ?>;
    window.NB_LANG = NB_LANG;
    window.NB_AI_CATALOG = <?php echo json_encode(nibblyAiModelCatalog(), JSON_UNESCAPED_SLASHES); ?>;
    window.NB_AI_IMAGE_SERVICES = <?php echo json_encode(nibblyAiImageServicesForUi(), JSON_UNESCAPED_SLASHES); ?>;
    window.NB_AI_CAPS = <?php echo json_encode(nibblyAiCapabilitiesForUi(), JSON_UNESCAPED_SLASHES); ?>;
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
    // Binds a listener only when the element is there: admin-only parts of the settings are not rendered for editors
    function onElement(id, type, handler) {
        const element = document.getElementById(id);
        if (element) element.addEventListener(type, handler);
    }
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
        const keyByTab = { news: 'news', events: 'events', people: 'people', mails: 'messages', icons: 'iconManager', menus: 'menus', ai: 'ai' };
        const key = keyByTab[tab];
        if (tab === 'newsletter') return DASHBOARD_MODULES.newsletter === true;
        if (!key) return true;
        return DASHBOARD_MODULES[key] !== false;
    }

    const ICONS = <?php echo json_encode(nbDashboardIconPaths(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
    const ICON_PLAYBACK_MODES = <?php echo json_encode(nibblyIconPlaybackModes()); ?>;
    window.NB_ICON_EVENT_ACTIONS = <?php echo json_encode(nibblyIconEventActions()); ?>;
    const PAGE_EDITOR_ICONS = <?php echo json_encode(getAvailableIconsRendered(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;

    function icon(name, size = 16, strokeWidth = '1.5', playback = {}) {
        const mode = ICON_PLAYBACK_MODES.includes(playback.mode) ? ` data-nb-play="${playback.mode}"` : '';
        const direction = ['forward', 'reverse'].includes(playback.direction) ? ` data-nb-reverse="${playback.direction === 'reverse'}"` : '';
        const events = Object.entries(NibblyIconPlacement.eventAttributes(playback.events))
            .map(([key, value]) => ` data-nb-${key}="${value}"`).join('');
        return `<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round" width="${size}" height="${size}"${mode}${direction}${events}>${ICONS[name]}</svg>`;
    }

    // Language choice of an editor, the same in every editor: a segmented control under the title.
    // languages: [{ code, label, exists }]; a language without content shows as "+ Label".
    // onSelect(code, exists) may return false to keep the current choice (e.g. after Cancel).
    // onTranslate(button) adds "Translate from active language", shown while AI text generation works.
    function renderLanguageSwitch({ name, current, languages, onSelect, onTranslate, translateLabel }) {
        const wrap = document.createElement('div');
        wrap.className = 'nb-lang-switch';
        const labelId = 'nbLangSwitch-' + name;
        const caption = document.createElement('span');
        caption.className = 'nb-lang-switch__label'; caption.id = labelId; caption.innerHTML = icon('language', 16, '2') + escapeHtml(t('filter.language'));
        const group = document.createElement('div');
        group.className = 'segmented'; group.setAttribute('role', 'radiogroup'); group.setAttribute('aria-labelledby', labelId);
        let selected = current;
        const setCurrent = code => { selected = code; group.querySelectorAll('input').forEach(input => { input.checked = input.value === code; }); };
        languages.forEach(lang => {
            const option = document.createElement('label');
            const input = document.createElement('input'); input.type = 'radio'; input.name = labelId; input.value = lang.code; input.checked = lang.code === current;
            const text = document.createElement('span'); text.textContent = (lang.exists ? '' : '+ ') + lang.label;
            if (!lang.exists) { option.classList.add('is-missing'); option.title = t('editor.lang_missing', { lang: lang.label }); }
            input.addEventListener('change', () => {
                const previous = selected;
                selected = lang.code;
                if (onSelect(lang.code, lang.exists) === false) setCurrent(previous);
            });
            option.append(input, text); group.append(option);
        });
        wrap.append(caption, group);
        if (onTranslate) {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'btn btn-secondary btn-sm nb-ai-translate';
            button.textContent = translateLabel || t('editor.translate_from_active'); button.hidden = !dashboardAiTextUsable;
            button.addEventListener('click', () => onTranslate(button, selected));
            wrap.append(button);
        }
        wrap.setCurrent = setCurrent;
        return wrap;
    }

    // The language switch sits top right in the editor's (sticky) header, level with the heading;
    // without a switch (one language) an older one is removed
    function placeLanguageSwitch(root, languageSwitch) {
        const header = root?.querySelector('.editor-header');
        if (!header) return;
        header.querySelector(':scope > .nb-lang-switch')?.remove();
        if (languageSwitch) header.append(languageSwitch);
    }

    // AI translation of a set of fields ({key: text}) into other languages: {LANG: {key: text}}, or null
    // after showing the error. The button shows "Translating…" meanwhile. HTML in the texts is kept.
    async function aiTranslateFields(sourceLang, targetLangs, fields, button, maxOutputTokens = 1800) {
        if (!Object.keys(fields).length) { showToast(t('editor.translate_no_source'), 'error'); return null; }
        const previous = button ? button.textContent : '';
        if (button) { button.disabled = true; button.textContent = t('editor.translating'); }
        try {
            const formData = new FormData();
            formData.append('action', 'ai-generate-text');
            formData.append('maxOutputTokens', String(maxOutputTokens));
            formData.append('prompt', [
                'Translate these nibbly admin editor fields from ' + sourceLang + ' into the requested target languages.',
                'Return strict JSON only. Shape: {"translations":{"LANG":{"field":"translated value"}}}.',
                'Keep HTML tags if present. Do not add Markdown or explanations.',
                '',
                JSON.stringify({ sourceLang, targetLangs, fields }, null, 2)
            ].join('\n'));
            formData.append('csrf_token', CSRF_TOKEN);
            const result = await (await fetch('api.php', { method: 'POST', body: formData })).json();
            if (!result.success) throw new Error(result.message || t('toast.error'));
            let payload;
            try { payload = JSON.parse(String(result.data?.text || '').replace(/^```json\s*|\s*```$/g, '').trim()); }
            catch (error) { throw new Error(t('editor.translate_invalid_response')); }
            if (typeof updateAiUsage === 'function') updateAiUsage(result.data ? result.data.limits : null);
            return payload.translations || payload;
        } catch (error) {
            showToast(error.message, 'error');
            return null;
        } finally {
            if (button) { button.disabled = false; button.textContent = previous; }
        }
    }

    // Texts of a content object for translation: {path: text} for string values that read as text,
    // leaving out ids, types, paths, links, image sources and other technical values
    const UNTRANSLATED_KEYS = new Set('id type src image href url link icon style variant level personId group columns details height align size layout target rel video audio embed email phone date time lang page path class anchor slug author lastModified passwordHash password status robots canonical ogImage sitemap'.split(' '));
    function translatableStrings(value, path = '', out = {}) {
        if (typeof value === 'string') {
            if (/\p{L}/u.test(value) && !/^(https?:|\/|#|mailto:|tel:)/i.test(value.trim())) out[path] = value;
        } else if (value && typeof value === 'object') {
            Object.entries(value).forEach(([key, child]) => {
                if (!UNTRANSLATED_KEYS.has(key) && !isIconPlaybackProperty(value, key)) translatableStrings(child, path ? path + '.' + key : key, out);
            });
        }
        return out;
    }
    function setPathValue(target, path, value) {
        const keys = path.split('.'); let node = target;
        for (const key of keys.slice(0, -1)) { if (!node || typeof node !== 'object' || !(key in node)) return false; node = node[key]; }
        if (!node || typeof node !== 'object' || typeof node[keys[keys.length - 1]] !== 'string') return false;
        node[keys[keys.length - 1]] = value; return true;
    }

    // Filter links above a list, as in WordPress: "All (5) | Published (4) | Trash (1)", followed in the
    // same line by labelled sets such as "Groups: All (3) | Team (3)". Each set is {label?, icon?, items:
    // [{value, label, count, active, onSelect}]}. Items without entries are left out (the first, "All",
    // always stays); the current item is bold text, the others are links.
    function renderFilterLinks(target, sets) {
        if (!target) return;
        target.replaceChildren(...sets.filter(Boolean).map(set => {
            const list = document.createElement('ul');
            list.className = 'nb-filter-links';
            if (set.label) list.setAttribute('aria-label', set.label);
            if (set.label) {
                const label = document.createElement('li');
                label.className = 'nb-filter-links__label' + (set.icon ? ' nb-filter-links__label--' + set.icon : '');
                label.innerHTML = (set.icon ? icon(set.icon, 14, '1.4') : '') + escapeHtml(set.label + ':');
                list.appendChild(label);
            }
            set.items.filter((item, index) => index === 0 || item.count > 0 || item.active).forEach(item => {
                const entry = document.createElement('li');
                const link = document.createElement(item.active ? 'strong' : 'a');
                link.dataset.filterValue = item.value;
                if (item.active) {
                    link.setAttribute('aria-current', 'true');
                } else {
                    link.href = '#';
                    link.addEventListener('click', event => { event.preventDefault(); item.onSelect(); });
                }
                const count = document.createElement('span');
                count.className = 'nb-filter-links__count';
                count.textContent = '(' + item.count + ')';
                link.append(item.label + ' ', count);
                entry.appendChild(link);
                list.appendChild(entry);
            });
            return list;
        }));
    }

    // Configuration
    const CSRF_TOKEN = '<?php echo $csrfToken; ?>';
    const USER_ROLE = '<?php echo htmlspecialchars($userRole); ?>';
