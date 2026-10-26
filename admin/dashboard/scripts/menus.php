<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    <?php if ($isAdminUser): ?>
    // ============================================================
    // MENUS & FOOTER
    // One working copy per menu and language; saved together with the footer
    // fields through save-menus-footer (revision-checked per store).
    // ============================================================
    (function() {
        const root = document.getElementById('menusTab');
        if (!root) return;
        const form = document.getElementById('mfForm');
        const statusEl = document.getElementById('mfStatus');
        const langSelect = document.getElementById('mfLang');
        const saveBtn = document.getElementById('mfSave');
        const discardBtn = document.getElementById('mfDiscard');
        const saveNote = document.getElementById('mfSaveNote');
        const FOOTER_TYPES = ['text', 'link', 'linkgroup', 'image', 'contact'];

        const mf = {
            state: null,
            lang: '',
            menus: {},          // id → lang → items (working copy)
            dirtyMenus: new Set(), // "id|lang"
            footer: {},         // path → value (working copy)
            dirtyFooter: new Set(),
            assign: {},         // path → type | 'ignore' | ''
            open: new Set(),    // expanded rows / panels
            busy: false,
            loaded: false,
            conflict: false,
            errors: {},         // uid / path → message
        };
        let drag = null;

        const clone = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
        const esc = value => escapeHtml(value == null ? '' : String(value));
        const tr = (key, params) => t('menus.' + key, params);
        const isLangMap = value => !!value && typeof value === 'object' && !Array.isArray(value)
            && Object.keys(value).length > 0 && Object.keys(value).every(key => /^[a-z]{2}$/.test(key));
        const localized = (value, lang) => isLangMap(value)
            ? (value[lang] ?? value[mf.state.defaultLang] ?? Object.values(value)[0] ?? '')
            : value;
        const labelOf = value => typeof value === 'string' ? value : (localized(value, document.documentElement.lang || mf.lang) || '');
        const newUid = () => 'mi_' + Array.from(crypto.getRandomValues(new Uint8Array(6)), b => b.toString(16).padStart(2, '0')).join('');

        function icon(name) {
            const paths = {
                plus: 'M12 5v14M5 12h14',
                down: 'M6 9l6 6 6-6',
                up: 'M6 12l6-6 6 6M12 6v14',
                moveDown: 'M6 12l6 6 6-6M12 4v14',
                indent: 'M3 6h18M11 12h10M11 18h10M3 10l4 3-4 3',
                outdent: 'M3 6h18M11 12h10M11 18h10M7 10l-4 3 4 3',
                trash: 'M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z',
                reset: 'M3 7v6h6M21 17a9 9 0 00-9-9 9 9 0 00-6.69 3L3 13',
                external: 'M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3',
                image: 'M3 3h18v18H3zM8.5 10a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM21 15l-5-5L5 21',
            };
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true" focusable="false"><path d="' + paths[name] + '"/></svg>';
        }
        const gripSvg = '<svg width="16" height="20" viewBox="0 0 16 20" aria-hidden="true" focusable="false" fill="currentColor"><circle cx="5" cy="5" r="1.5"/><circle cx="11" cy="5" r="1.5"/><circle cx="5" cy="10" r="1.5"/><circle cx="11" cy="10" r="1.5"/><circle cx="5" cy="15" r="1.5"/><circle cx="11" cy="15" r="1.5"/></svg>';

        function setStatus(message, kind) {
            statusEl.textContent = message || '';
            statusEl.className = 'mf-status' + (kind ? ' mf-status--' + kind : '');
        }

        // ------------------------------------------------------------
        // State
        // ------------------------------------------------------------

        function isDirty() {
            return mf.dirtyMenus.size > 0 || mf.dirtyFooter.size > 0 || Object.keys(mf.assign).length > 0;
        }

        function markDirty() {
            updateSaveBar();
        }

        function updateSaveBar() {
            const dirty = isDirty();
            saveBtn.disabled = !dirty || mf.busy;
            discardBtn.disabled = !dirty || mf.busy;
            saveNote.textContent = dirty ? tr('unsaved') : '';
            root.classList.toggle('mf-is-dirty', dirty);
            const navItem = document.querySelector('.sidebar-nav-item[data-tab="menus"]');
            if (navItem) navItem.classList.toggle('has-unsaved', dirty);
        }

        function applyState(state) {
            mf.state = state;
            mf.menus = {};
            state.menus.forEach(menu => {
                mf.menus[menu.id] = {};
                Object.keys(menu.languages).forEach(lang => { mf.menus[menu.id][lang] = clone(menu.languages[lang].items); });
            });
            mf.footer = {};
            state.footer.fields.forEach(field => { mf.footer[field.path] = initialFooterValue(field); });
            mf.dirtyMenus.clear();
            mf.dirtyFooter.clear();
            mf.assign = {};
            mf.errors = {};
            mf.conflict = false;
            if (!mf.lang || !state.languages[mf.lang]) mf.lang = state.defaultLang in state.languages ? state.defaultLang : Object.keys(state.languages)[0];
            if (langSelect) langSelect.value = mf.lang;
            syncPageSettingsMenus();
        }

        function initialFooterValue(field) {
            if (field.value !== null && field.value !== undefined) return clone(field.value);
            if (field.defaults) return clone(field.defaults);
            if (field.type === 'linkgroup') return [];
            if (field.type === 'text') return '';
            return {};
        }

        // Page settings → menu checkboxes read the managed items from NB_MENUS.
        function syncPageSettingsMenus() {
            if (!window.NB_MENUS || !mf.state) return;
            mf.state.menus.forEach(menu => {
                const managed = {};
                Object.entries(menu.languages).forEach(([lang, data]) => { if (data.managed) managed[lang] = data.items; });
                if (!window.NB_MENUS[menu.id]) {
                    if (!menu.registered) return;
                    window.NB_MENUS[menu.id] = { label: menu.label };
                }
                if (Object.keys(managed).length) window.NB_MENUS[menu.id].items = managed;
                else delete window.NB_MENUS[menu.id].items;
            });
        }

        function menuDef(id) {
            return mf.state.menus.find(menu => menu.id === id);
        }

        function items(menuId) {
            return mf.menus[menuId][mf.lang];
        }

        function touchMenu(menuId) {
            mf.dirtyMenus.add(menuId + '|' + mf.lang);
            markDirty();
        }

        /** { item, list, index, parent, depth } for a uid in the current language. */
        function locate(menuId, uid) {
            const walk = (list, parent, depth) => {
                for (let i = 0; i < list.length; i++) {
                    if (list[i].uid === uid) return { item: list[i], list, index: i, parent, depth };
                    if (Array.isArray(list[i].children)) {
                        const found = walk(list[i].children, list[i], depth + 1);
                        if (found) return found;
                    }
                }
                return null;
            };
            return walk(items(menuId), null, 1);
        }

        function subtreeHeight(item) {
            return 1 + Math.max(0, ...(item.children || []).map(subtreeHeight));
        }

        function pageInfo(page) {
            return (mf.state.pages[mf.lang] || []).find(p => p.page === page) || null;
        }

        function displayLabel(item) {
            if (item.label) return item.label;
            if (item.type === 'page') return pageInfo(item.page)?.title || item.page || tr('untitled');
            return tr('untitled');
        }

        function displayTarget(item) {
            if (item.type === 'page') {
                const page = pageInfo(item.page);
                return tr('page_ref', { page: page ? page.title : item.page }) + (page ? ' · /' + page.href.replace(/^\.$/, '') : '');
            }
            return item.href || '';
        }

        // ------------------------------------------------------------
        // Rendering: menus
        // ------------------------------------------------------------

        function sourceLine(menu, langState) {
            const parts = [];
            if (langState.managed) {
                parts.push(tr('source_managed'));
            } else if (langState.source === 'template') {
                parts.push(tr('source_template', { file: (langState.origin || []).join(', ') }));
            } else {
                parts.push(tr('source_legacy', { files: (langState.origin || []).join(', ') || 'includes/nav-config.php' }));
            }
            if (menu.bindings.length) {
                parts.push(tr('rendered_in', { where: menu.bindings.map(b => b.file + ':' + b.line).join(', ') }));
            }
            return parts.join(' · ');
        }

        function renderMenus() {
            const container = document.getElementById('mfMenus');
            container.innerHTML = mf.state.menus.map(renderMenu).join('');
        }

        function renderMenu(menu) {
            const langState = menu.languages[mf.lang];
            const list = items(menu.id);
            const dirty = mf.dirtyMenus.has(menu.id + '|' + mf.lang);
            const addOpen = mf.open.has('add:' + menu.id);
            let badge = langState.managed ? '<span class="mf-badge mf-badge--managed">' + esc(tr('badge_managed')) + '</span>'
                : '<span class="mf-badge">' + esc(tr('badge_derived')) + '</span>';
            if (!menu.bindings.length) badge += '<span class="mf-badge mf-badge--warning">' + esc(tr('badge_unbound')) + '</span>';
            if (dirty) badge += '<span class="mf-badge mf-badge--dirty">' + esc(tr('badge_changed')) + '</span>';
            const pages = mf.state.pages[mf.lang] || [];
            return `<section class="mf-panel" data-menu="${esc(menu.id)}" aria-labelledby="mf-title-${esc(menu.id)}">
                <header class="mf-panel__head">
                    <div class="mf-panel__titles">
                        <h3 class="mf-section-title" id="mf-title-${esc(menu.id)}">${esc(labelOf(menu.label) || menu.id)}</h3>
                        <p class="mf-source">${esc(sourceLine(menu, langState))}</p>
                    </div>
                    <div class="mf-badges">${badge}</div>
                </header>
                ${!menu.bindings.length ? `<p class="form-hint mf-unbound-hint">${esc(tr('unbound_hint', { id: menu.id }))}</p>` : ''}
                <div class="mf-toolbar">
                    <p class="form-hint">${esc(menu.depth > 1 ? tr('list_hint_nested') : tr('list_hint'))}</p>
                    <button type="button" class="btn btn-secondary" data-mf-add-toggle="${esc(menu.id)}" aria-expanded="${addOpen}" aria-controls="mf-add-${esc(menu.id)}">${icon('plus')}<span>${esc(tr('add_entry'))}</span></button>
                </div>
                <div class="mf-add" id="mf-add-${esc(menu.id)}" data-mf-add="${esc(menu.id)}"${addOpen ? '' : ' hidden'}>
                    <div class="form-row-inline">
                        <div class="form-group">
                            <label for="mf-add-kind-${esc(menu.id)}">${esc(tr('entry_kind'))}</label>
                            <select id="mf-add-kind-${esc(menu.id)}" data-mf-add-kind>
                                <option value="page">${esc(tr('kind_page'))}</option>
                                <option value="link">${esc(tr('kind_link'))}</option>
                            </select>
                        </div>
                        <div class="form-group" data-mf-add-page-fields>
                            <label for="mf-add-page-${esc(menu.id)}">${esc(tr('page'))}</label>
                            <select id="mf-add-page-${esc(menu.id)}" data-mf-add-page>
                                <option value="">${esc(tr('choose_page'))}</option>
                                ${pages.map(p => `<option value="${esc(p.page)}">${esc(p.title)} (/${esc(p.href.replace(/^\.$/, ''))})</option>`).join('')}
                            </select>
                        </div>
                    </div>
                    <div class="form-row-inline" data-mf-add-link-fields hidden>
                        <div class="form-group">
                            <label for="mf-add-label-${esc(menu.id)}">${esc(tr('label'))}</label>
                            <input type="text" id="mf-add-label-${esc(menu.id)}" data-mf-add-label maxlength="200" placeholder="${esc(tr('label_placeholder'))}">
                        </div>
                        <div class="form-group">
                            <label for="mf-add-href-${esc(menu.id)}">${esc(tr('href'))}</label>
                            <input type="text" id="mf-add-href-${esc(menu.id)}" data-mf-add-href maxlength="2048" placeholder="https://… / #abschnitt / /pfad" spellcheck="false" autocomplete="off">
                        </div>
                    </div>
                    <label class="mf-check"><input type="checkbox" data-mf-add-blank> <span>${esc(tr('new_window'))}</span></label>
                    <p class="mf-add-error" role="alert" data-mf-add-error></p>
                    <div class="mf-add-actions">
                        <button type="button" class="btn btn-secondary" data-mf-add-cancel="${esc(menu.id)}">${esc(t('btn.cancel'))}</button>
                        <button type="button" class="btn btn-primary" data-mf-add-submit="${esc(menu.id)}">${esc(tr('add_submit'))}</button>
                    </div>
                </div>
                <ol class="mf-list" data-mf-list="${esc(menu.id)}" data-parent="">
                    ${list.length ? list.map((item, i) => renderRow(menu, item, i, list.length, 1)).join('') : `<li class="mf-empty">${esc(tr('empty'))}</li>`}
                </ol>
                ${langState.managed ? `<div class="mf-panel__foot"><button type="button" class="btn btn-secondary btn-sm" data-mf-reset="${esc(menu.id)}">${icon('reset')}<span>${esc(tr('reset'))}</span></button><span class="form-hint">${esc(tr('reset_hint'))}</span></div>` : ''}
            </section>`;
        }

        function renderRow(menu, item, index, count, depth) {
            const uid = item.uid;
            const open = mf.open.has(uid);
            const label = displayLabel(item);
            const missing = (menu.languages[mf.lang].missing || []).includes(uid) && item.type === 'page' && !pageInfo(item.page) && item.page !== 'home';
            const error = mf.errors[uid];
            const badges = [];
            if (item.hidden) badges.push(`<span class="mf-badge">${esc(tr('badge_hidden'))}</span>`);
            if (item.target === '_blank') badges.push(`<span class="mf-badge" title="${esc(tr('new_window'))}">${icon('external')}<span class="sr-only">${esc(tr('new_window'))}</span></span>`);
            if (missing) badges.push(`<span class="mf-badge mf-badge--warning">${esc(tr('badge_missing'))}</span>`);
            if (error) badges.push(`<span class="mf-badge mf-badge--danger">${esc(tr('badge_error'))}</span>`);
            const canNest = menu.depth > 1;
            const children = Array.isArray(item.children) ? item.children : [];
            const pages = mf.state.pages[mf.lang] || [];
            const fid = 'mf-' + uid;
            return `<li class="mf-row${open ? ' is-open' : ''}${item.hidden ? ' is-hidden' : ''}" data-uid="${esc(uid)}" data-menu="${esc(menu.id)}" data-depth="${depth}">
                <div class="mf-row__head">
                    <button type="button" class="mf-grip" draggable="true" data-mf-grip aria-label="${esc(tr('move_label', { label }))}" aria-describedby="mfGripHelp">${gripSvg}</button>
                    <button type="button" class="mf-toggle" data-mf-toggle aria-expanded="${open}" aria-controls="${fid}-details">
                        <span class="mf-toggle__text"><strong>${esc(label)}</strong><small>${esc(displayTarget(item))}</small></span>
                        <span class="mf-toggle__badges">${badges.join('')}</span>
                        ${icon('down')}
                    </button>
                    <label class="toggle-label mf-visible" title="${esc(tr('visible'))}">
                        <span class="toggle-switch"><input type="checkbox" data-mf-visible aria-label="${esc(tr('visible_label', { label }))}"${item.hidden ? '' : ' checked'}><span class="toggle-slider"></span></span>
                        <span class="mf-visible__text">${esc(tr('visible'))}</span>
                    </label>
                </div>
                <div class="mf-row__details" id="${fid}-details"${open ? '' : ' hidden'}>${open ? `
                    ${error ? `<p class="mf-field-error" role="alert">${esc(error)}</p>` : ''}
                    <div class="form-row-inline">
                        <div class="form-group">
                            <label for="${fid}-type">${esc(tr('entry_kind'))}</label>
                            <select id="${fid}-type" data-mf-field="type">
                                <option value="page"${item.type === 'page' ? ' selected' : ''}>${esc(tr('kind_page'))}</option>
                                <option value="link"${item.type !== 'page' ? ' selected' : ''}>${esc(tr('kind_link'))}</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="${fid}-label">${esc(tr('label'))}</label>
                            <input type="text" id="${fid}-label" data-mf-field="label" value="${esc(item.label || '')}" maxlength="200"${item.type === 'page' ? ` placeholder="${esc(pageInfo(item.page)?.title || '')}"` : ''}>
                        </div>
                    </div>
                    <div class="form-row-inline">
                        ${item.type === 'page' ? `<div class="form-group">
                            <label for="${fid}-page">${esc(tr('page'))}</label>
                            <select id="${fid}-page" data-mf-field="page">
                                ${missing || !item.page ? `<option value="${esc(item.page || '')}" selected>${esc(item.page ? tr('missing_option', { page: item.page }) : tr('choose_page'))}</option>` : ''}
                                ${pages.map(p => `<option value="${esc(p.page)}"${p.page === item.page ? ' selected' : ''}>${esc(p.title)} (/${esc(p.href.replace(/^\.$/, ''))})</option>`).join('')}
                            </select>
                            ${missing ? `<small class="form-hint">${esc(tr('missing_hint'))}</small>` : ''}
                        </div>` : `<div class="form-group">
                            <label for="${fid}-href">${esc(tr('href'))}</label>
                            <input type="text" id="${fid}-href" data-mf-field="href" value="${esc(item.href || '')}" maxlength="2048" spellcheck="false" autocomplete="off">
                            <small class="form-hint">${esc(tr('href_hint'))}</small>
                        </div>`}
                        <div class="form-group">
                            <label for="${fid}-target">${esc(tr('target'))}</label>
                            <select id="${fid}-target" data-mf-field="target">
                                <option value=""${!item.target ? ' selected' : ''}>${esc(tr('target_same'))}</option>
                                <option value="_blank"${item.target === '_blank' ? ' selected' : ''}>${esc(tr('new_window'))}</option>
                                ${item.target === '_self' ? '<option value="_self" selected>_self</option>' : ''}
                            </select>
                        </div>
                    </div>
                    <div class="form-row-inline">
                        <div class="form-group">
                            <label for="${fid}-rel">${esc(tr('rel'))}</label>
                            <input type="text" id="${fid}-rel" data-mf-field="rel" value="${esc(item.rel || '')}" maxlength="120" spellcheck="false" autocomplete="off" placeholder="nofollow noopener">
                            <small class="form-hint">${esc(tr('rel_hint'))}</small>
                        </div>
                    </div>
                    <div class="mf-row__actions">
                        <button type="button" class="btn btn-secondary btn-sm" data-mf-move="-1"${index === 0 ? ' disabled' : ''}>${icon('up')}<span>${esc(tr('move_up'))}</span></button>
                        <button type="button" class="btn btn-secondary btn-sm" data-mf-move="1"${index === count - 1 ? ' disabled' : ''}>${icon('moveDown')}<span>${esc(tr('move_down'))}</span></button>
                        ${canNest ? `<button type="button" class="btn btn-secondary btn-sm" data-mf-indent${index === 0 || depth >= menu.depth ? ' disabled' : ''}>${icon('indent')}<span>${esc(tr('indent'))}</span></button>
                        <button type="button" class="btn btn-secondary btn-sm" data-mf-outdent${depth === 1 ? ' disabled' : ''}>${icon('outdent')}<span>${esc(tr('outdent'))}</span></button>` : ''}
                        <button type="button" class="btn btn-secondary btn-sm mf-remove" data-mf-remove>${icon('trash')}<span>${esc(tr('remove'))}</span></button>
                    </div>` : ''}
                </div>
                ${children.length ? `<ol class="mf-list mf-list--children" data-mf-list="${esc(menu.id)}" data-parent="${esc(uid)}">${children.map((child, i) => renderRow(menu, child, i, children.length, depth + 1)).join('')}</ol>` : ''}
            </li>`;
        }

        // ------------------------------------------------------------
        // Rendering: footer
        // ------------------------------------------------------------

        const LEAF_LABELS = {
            label: 'f_label', text: 'f_text', linktext: 'f_label', title: 'f_title', href: 'f_href', url: 'f_href', link: 'f_href',
            src: 'f_src', alt: 'f_alt', phone: 'f_phone', tel: 'f_phone', mobile: 'f_mobile', fax: 'f_fax', email: 'f_email', mail: 'f_email',
            address: 'f_address', street: 'f_street', zip: 'f_zip', city: 'f_city', country: 'f_country', name: 'f_name', heading: 'f_heading',
            target: 'target', rel: 'rel',
        };
        const leafLabel = key => LEAF_LABELS[String(key).toLowerCase()] ? tr(LEAF_LABELS[String(key).toLowerCase()]) : String(key).replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[-_]/g, ' ');
        const leafRole = key => String(key).toLowerCase();

        function footerFieldsAll() {
            // Assigned in this session but not saved yet: shown as editable, marked as pending.
            const fields = mf.state.footer.fields.slice();
            Object.entries(mf.assign).forEach(([path, type]) => {
                if (!FOOTER_TYPES.includes(type)) return;
                const finding = mf.state.footer.findings.find(f => f.path === path);
                if (!finding) return;
                fields.push({ path, type, label: path, html: false, multiline: false, supports: { add: type === 'linkgroup', remove: type === 'linkgroup', order: type === 'linkgroup', hidden: false }, origin: 'pending', value: finding.value, refs: finding.refs });
                if (!(path in mf.footer)) mf.footer[path] = clone(finding.value);
            });
            return fields;
        }

        function renderFooter() {
            const container = document.getElementById('mfFooter');
            const fields = footerFieldsAll();
            const findings = mf.state.footer.findings.filter(f => !FOOTER_TYPES.includes(mf.assign[f.path] || '') && mf.assign[f.path] !== 'ignore');
            const ignored = mf.state.footer.ignored.concat(Object.keys(mf.assign).filter(p => mf.assign[p] === 'ignore'));
            let html = `<section class="mf-panel mf-panel--footer" aria-labelledby="mf-footer-title">
                <header class="mf-panel__head">
                    <div class="mf-panel__titles">
                        <h3 class="mf-section-title" id="mf-footer-title">${esc(tr('footer_title'))}</h3>
                        <p class="mf-source">${esc(tr('footer_source'))}</p>
                    </div>
                </header>`;
            if (!fields.length) html += `<p class="mf-empty">${esc(tr('footer_empty'))}</p>`;
            html += `<ol class="mf-list mf-list--fields">${fields.map(renderFooterField).join('')}</ol></section>`;
            if (findings.length || ignored.length) {
                html += `<section class="mf-panel" aria-labelledby="mf-findings-title">
                    <header class="mf-panel__head"><div class="mf-panel__titles">
                        <h3 class="mf-section-title" id="mf-findings-title">${esc(tr('findings_title'))}</h3>
                        <p class="mf-source">${esc(tr('findings_hint'))}</p>
                    </div></header>
                    <ol class="mf-list mf-list--findings">${findings.map(renderFinding).join('')}</ol>
                    ${ignored.length ? `<p class="form-hint mf-ignored">${esc(tr('ignored', { fields: ignored.join(', ') }))} <button type="button" class="mf-link-button" data-mf-unignore>${esc(tr('unignore'))}</button></p>` : ''}
                </section>`;
            }
            container.innerHTML = html;
        }

        function refsText(refs) {
            if (!refs || !refs.length) return tr('ref_none');
            return refs.map(ref => ref.file + ':' + ref.line + (ref.used ? '' : ' (' + tr('ref_unused') + ')')).join(', ');
        }

        function preview(value) {
            const v = localized(value, mf.lang);
            if (typeof v === 'string') return v.replace(/<[^>]*>/g, ' ').trim().slice(0, 90);
            if (Array.isArray(v)) return tr('entries', { count: v.length });
            if (v && typeof v === 'object') return Object.entries(v).filter(([, x]) => typeof x === 'string' || isLangMap(x)).map(([k, x]) => k + ': ' + String(localized(x, mf.lang)).slice(0, 40)).join(' · ').slice(0, 120);
            return '';
        }

        function renderFinding(finding) {
            const id = 'mf-assign-' + finding.path;
            const suggestion = finding.type ? tr('type_' + finding.type) : '';
            return `<li class="mf-row mf-finding" data-finding="${esc(finding.path)}">
                <div class="mf-finding__body">
                    <div class="mf-finding__text">
                        <strong><code>${esc(finding.path)}</code></strong>
                        <small>${esc(preview(finding.value))}</small>
                        <small class="mf-origin">${esc(tr('origin', { file: finding.origin }))} · ${esc(tr('refs', { refs: refsText(finding.refs) }))}</small>
                        ${!finding.printed ? `<small class="mf-origin mf-origin--warning">${esc(tr('not_printed'))}</small>` : ''}
                    </div>
                    <div class="form-group mf-finding__assign">
                        <label for="${esc(id)}">${esc(tr('assign_as'))}</label>
                        <select id="${esc(id)}" data-mf-assign="${esc(finding.path)}">
                            <option value="">${esc(tr('assign_none'))}${suggestion ? ' — ' + esc(tr('suggestion', { type: suggestion })) : ''}</option>
                            ${FOOTER_TYPES.map(type => `<option value="${type}">${esc(tr('type_' + type))}</option>`).join('')}
                            <option value="ignore">${esc(tr('assign_ignore'))}</option>
                        </select>
                    </div>
                </div>
            </li>`;
        }

        function renderFooterField(field) {
            const path = field.path;
            const key = 'f:' + path;
            const open = mf.open.has(key);
            const value = mf.footer[path];
            const dirty = mf.dirtyFooter.has(path);
            const error = mf.errors[path];
            const fid = 'mf-f-' + path.replace(/[^A-Za-z0-9_-]/g, '-');
            const badges = [`<span class="mf-badge">${esc(tr('type_' + field.type))}</span>`];
            if (field.origin === 'pending') badges.push(`<span class="mf-badge mf-badge--dirty">${esc(tr('badge_assigned'))}</span>`);
            if (dirty) badges.push(`<span class="mf-badge mf-badge--dirty">${esc(tr('badge_changed'))}</span>`);
            if (error) badges.push(`<span class="mf-badge mf-badge--danger">${esc(tr('badge_error'))}</span>`);
            return `<li class="mf-row mf-field${open ? ' is-open' : ''}" data-field="${esc(path)}">
                <div class="mf-row__head mf-row__head--field">
                    <button type="button" class="mf-toggle" data-mf-toggle aria-expanded="${open}" aria-controls="${fid}-details">
                        <span class="mf-toggle__text"><strong>${esc(labelOf(field.label) || path)}</strong><small>${esc(preview(value) || tr('empty_value'))}</small></span>
                        <span class="mf-toggle__badges">${badges.join('')}</span>
                        ${icon('down')}
                    </button>
                </div>
                <div class="mf-row__details" id="${fid}-details"${open ? '' : ' hidden'}>${open ? `
                    ${error ? `<p class="mf-field-error" role="alert">${esc(error)}</p>` : ''}
                    <p class="form-hint mf-origin">${esc(tr('origin', { file: 'content/pages/footer.json → ' + path }))} · ${esc(tr('refs', { refs: refsText(field.refs) }))}</p>
                    ${renderFieldEditor(field, value, fid)}` : ''}
                </div>
            </li>`;
        }

        function inputFor(fid, leafPath, key, value, field) {
            const id = fid + '-' + leafPath.join('-').replace(/[^A-Za-z0-9_-]/g, '-');
            const localizedValue = isLangMap(value);
            const current = localizedValue ? (value[mf.lang] ?? '') : (value ?? '');
            const role = leafRole(key);
            const attrs = `id="${esc(id)}" data-mf-leaf='${esc(JSON.stringify(leafPath))}'${localizedValue ? ` data-mf-lang="${esc(mf.lang)}"` : ''}`;
            const scope = localizedValue ? tr('scope_lang', { lang: mf.lang.toUpperCase() }) : tr('scope_all');
            let control;
            if (role === 'target') {
                control = `<select ${attrs}><option value=""${!current ? ' selected' : ''}>${esc(tr('target_same'))}</option><option value="_blank"${current === '_blank' ? ' selected' : ''}>${esc(tr('new_window'))}</option></select>`;
            } else if (field.multiline || (typeof current === 'string' && (current.length > 90 || current.includes('\n')))) {
                control = `<textarea ${attrs} rows="3">${esc(current)}</textarea>`;
            } else {
                const type = ['email', 'mail'].includes(role) ? 'email' : (['phone', 'tel', 'mobile', 'fax'].includes(role) ? 'tel' : 'text');
                control = `<input type="${type}" ${attrs} value="${esc(current)}"${['href', 'url', 'link', 'src'].includes(role) ? ' spellcheck="false" autocomplete="off"' : ''}>`;
            }
            const imageButton = role === 'src' || (field.type === 'image' && leafPath.length === 0)
                ? `<button type="button" class="btn btn-secondary btn-sm" data-mf-image='${esc(JSON.stringify(leafPath))}'>${icon('image')}<span>${esc(tr('choose_image'))}</span></button>` : '';
            const hint = field.html && !['href', 'url', 'link', 'src', 'email', 'alt'].includes(role) ? `<small class="form-hint">${esc(tr('html_hint'))}</small>` : '';
            return `<div class="form-group"><label for="${esc(id)}">${esc(leafPath.length ? leafLabel(key) : (labelOf(field.label) || field.path))} <span class="mf-scope">${esc(scope)}</span></label>${control}${imageButton}${hint}</div>`;
        }

        /** Every string (or {lang: string}) leaf of an object gets its own input; other keys stay untouched. */
        function leafInputs(fid, value, basePath, field) {
            if (typeof value === 'string' || isLangMap(value) || value === null || value === undefined) {
                return inputFor(fid, basePath, basePath[basePath.length - 1] ?? field.path, value, field);
            }
            if (typeof value !== 'object') return '';
            return Object.entries(value).map(([key, leaf]) => {
                if (key === 'uid' || key === 'hidden' || typeof leaf === 'boolean' || typeof leaf === 'number') return '';
                if (typeof leaf === 'string' || isLangMap(leaf)) return inputFor(fid, basePath.concat(key), key, leaf, field);
                if (leaf && typeof leaf === 'object' && !Array.isArray(leaf)) {
                    return `<fieldset class="mf-subgroup"><legend>${esc(leafLabel(key))}</legend>${leafInputs(fid, leaf, basePath.concat(key), field)}</fieldset>`;
                }
                return '';
            }).join('');
        }

        function renderFieldEditor(field, value, fid) {
            if (field.type === 'image') {
                const src = typeof value === 'string' ? value : localized(value && value.src, mf.lang);
                const thumb = src ? `<img class="mf-image-preview" src="${esc(/^(https?:|\/)/.test(src) ? src : '../' + src)}" alt="">` : '';
                return `<div class="mf-image">${thumb}<div class="mf-image__fields">${leafInputs(fid, value, [], field)}</div></div>`;
            }
            if (field.type === 'linkgroup') {
                const list = Array.isArray(value) ? value : (value && typeof value === 'object' ? Object.values(value) : []);
                const s = field.supports || {};
                return `<ol class="mf-list mf-list--group" data-mf-group="${esc(field.path)}">
                    ${list.length ? list.map((entry, i) => renderGroupEntry(field, entry, i, list.length, fid)).join('') : `<li class="mf-empty">${esc(tr('empty'))}</li>`}
                </ol>
                ${s.add ? `<button type="button" class="btn btn-secondary btn-sm" data-mf-group-add="${esc(field.path)}">${icon('plus')}<span>${esc(tr('add_link'))}</span></button>` : ''}`;
            }
            return `<div class="mf-leafs">${leafInputs(fid, value, [], field)}</div>`;
        }

        function groupEntryLabel(entry) {
            if (!entry || typeof entry !== 'object') return tr('untitled');
            for (const key of ['label', 'text', 'linkText', 'title', 'name']) {
                if (entry[key]) return localized(entry[key], mf.lang) || tr('untitled');
            }
            return tr('untitled');
        }

        function groupEntryHref(entry) {
            if (!entry || typeof entry !== 'object') return '';
            for (const key of ['href', 'url', 'link']) if (entry[key]) return localized(entry[key], mf.lang);
            return '';
        }

        function renderGroupEntry(field, entry, index, count, fid) {
            const s = field.supports || {};
            const key = 'g:' + field.path + ':' + index;
            const open = mf.open.has(key);
            const efid = fid + '-' + index;
            const hidden = !!(entry && entry.hidden);
            return `<li class="mf-row${open ? ' is-open' : ''}${hidden ? ' is-hidden' : ''}" data-group-entry="${index}" data-group="${esc(field.path)}">
                <div class="mf-row__head${s.order ? '' : ' mf-row__head--nogrip'}">
                    ${s.order ? `<button type="button" class="mf-grip" draggable="true" data-mf-grip aria-label="${esc(tr('move_label', { label: groupEntryLabel(entry) }))}" aria-describedby="mfGripHelp">${gripSvg}</button>` : ''}
                    <button type="button" class="mf-toggle" data-mf-toggle aria-expanded="${open}" aria-controls="${efid}-details">
                        <span class="mf-toggle__text"><strong>${esc(groupEntryLabel(entry))}</strong><small>${esc(groupEntryHref(entry))}</small></span>
                        <span class="mf-toggle__badges">${hidden ? `<span class="mf-badge">${esc(tr('badge_hidden'))}</span>` : ''}</span>
                        ${icon('down')}
                    </button>
                    ${s.hidden ? `<label class="toggle-label mf-visible"><span class="toggle-switch"><input type="checkbox" data-mf-group-visible aria-label="${esc(tr('visible_label', { label: groupEntryLabel(entry) }))}"${hidden ? '' : ' checked'}><span class="toggle-slider"></span></span><span class="mf-visible__text">${esc(tr('visible'))}</span></label>` : ''}
                </div>
                <div class="mf-row__details" id="${efid}-details"${open ? '' : ' hidden'}>${open ? `
                    <div class="mf-leafs">${leafInputs(efid, entry, [String(index)], field)}</div>
                    <div class="mf-row__actions">
                        ${s.order ? `<button type="button" class="btn btn-secondary btn-sm" data-mf-group-move="-1"${index === 0 ? ' disabled' : ''}>${icon('up')}<span>${esc(tr('move_up'))}</span></button>
                        <button type="button" class="btn btn-secondary btn-sm" data-mf-group-move="1"${index === count - 1 ? ' disabled' : ''}>${icon('moveDown')}<span>${esc(tr('move_down'))}</span></button>` : ''}
                        ${s.remove ? `<button type="button" class="btn btn-secondary btn-sm mf-remove" data-mf-group-remove>${icon('trash')}<span>${esc(tr('remove'))}</span></button>` : ''}
                    </div>` : ''}
                </div>
            </li>`;
        }

        function renderTemplates() {
            const container = document.getElementById('mfTemplates');
            const found = mf.state.templates || [];
            if (!found.length) { container.innerHTML = ''; return; }
            container.innerHTML = `<section class="mf-panel" aria-labelledby="mf-templates-title">
                <header class="mf-panel__head"><div class="mf-panel__titles">
                    <h3 class="mf-section-title" id="mf-templates-title">${esc(tr('templates_title'))}</h3>
                    <p class="mf-source">${esc(tr('templates_hint'))}</p>
                </div></header>
                <ol class="mf-list">${found.map(nav => `<li class="mf-row mf-finding"><div class="mf-finding__body"><div class="mf-finding__text">
                    <strong><code>${esc(nav.file)}:${esc(nav.line)}</code></strong>
                    <small>&lt;nav ${esc(nav.attributes)}&gt; · ${esc(tr('entries', { count: nav.count }))}</small>
                    <small class="mf-origin">${esc(nav.links.map(link => (link.label || '…') + ' → ' + link.href).join(' · '))}</small>
                </div></div></li>`).join('')}</ol>
            </section>`;
        }

        function renderBackups() {
            const container = document.getElementById('mfBackups');
            const open = mf.open.has('backups');
            container.innerHTML = `<section class="mf-panel mf-panel--backups">
                <div class="mf-row__head mf-row__head--field">
                    <button type="button" class="mf-toggle" data-mf-backups aria-expanded="${open}" aria-controls="mfBackupList">
                        <span class="mf-toggle__text"><strong class="mf-section-title">${esc(tr('backups_title'))}</strong><small>${esc(tr('backups_hint'))}</small></span>
                        ${icon('down')}
                    </button>
                </div>
                <div id="mfBackupList" class="mf-row__details"${open ? '' : ' hidden'}><p class="form-hint">${esc(tr('loading'))}</p></div>
            </section>`;
            if (open) loadBackups();
        }

        async function loadBackups() {
            const list = document.getElementById('mfBackupList');
            try {
                const result = await (await fetch('api.php?action=list-menus-backups&_=' + Date.now())).json();
                const backups = (result.data && result.data.backups) || [];
                list.innerHTML = backups.length ? `<ol class="mf-list">${backups.slice(0, 20).map(b => `<li class="mf-row mf-finding"><div class="mf-finding__body"><div class="mf-finding__text"><strong>${esc(b.date)}</strong><small><code>${esc(b.filename)}</code></small></div><button type="button" class="btn btn-secondary btn-sm" data-mf-restore="${esc(b.filename)}">${icon('reset')}<span>${esc(tr('restore'))}</span></button></div></li>`).join('')}</ol>` : `<p class="form-hint">${esc(tr('backups_empty'))}</p>`;
            } catch (e) {
                list.innerHTML = `<p class="mf-field-error">${esc(t('toast.error'))}</p>`;
            }
        }

        function render() {
            if (!mf.state) return;
            const active = document.activeElement && root.contains(document.activeElement) ? focusKey(document.activeElement) : null;
            renderMenus();
            renderFooter();
            renderTemplates();
            renderBackups();
            updateSaveBar();
            if (active) restoreFocus(active);
            scheduleSaveBar();
        }

        const ROW_SELECTOR = '[data-uid],[data-field],[data-group-entry],[data-finding]';
        const rowKey = row => row.dataset.uid || (row.dataset.groupEntry !== undefined ? row.dataset.group + '#' + row.dataset.groupEntry : '') || row.dataset.field || row.dataset.finding || '';
        const FOCUS_ATTRS = ['data-mf-grip', 'data-mf-toggle', 'data-mf-visible', 'data-mf-move', 'data-mf-indent', 'data-mf-outdent', 'data-mf-field', 'data-mf-leaf', 'data-mf-assign', 'data-mf-group-move', 'data-mf-add-toggle', 'data-mf-group-visible'];

        /** Where focus was before a re-render: row key, attribute and its value. */
        function focusKey(el) {
            const attr = FOCUS_ATTRS.find(a => el.hasAttribute(a));
            if (!attr) return null;
            const row = el.closest(ROW_SELECTOR);
            return { row: row ? rowKey(row) : '', attr, value: el.getAttribute(attr) };
        }

        function restoreFocus(key) {
            const scope = key.row ? (Array.from(root.querySelectorAll(ROW_SELECTOR)).find(row => rowKey(row) === key.row) || null) : root;
            if (!scope) return;
            const selector = '[' + key.attr + (key.value ? '="' + CSS.escape(key.value) + '"' : '') + ']';
            const target = Array.from(scope.querySelectorAll(selector)).find(el => !el.disabled && (scope === root || el.closest(ROW_SELECTOR) === scope))
                || (scope !== root ? scope.querySelector('[data-mf-grip],[data-mf-toggle]') : null);
            if (target) target.focus();
        }

        // ------------------------------------------------------------
        // Menu operations
        // ------------------------------------------------------------

        function announce(message) {
            setStatus(message);
        }

        function moveTo(menuId, uid, parentUid, index) {
            const found = locate(menuId, uid);
            if (!found) return false;
            const menu = menuDef(menuId);
            let targetList;
            let targetDepth = 1;
            if (parentUid) {
                const parent = locate(menuId, parentUid);
                if (!parent || parentUid === uid || locateIn(found.item, parentUid)) return false;
                targetDepth = parent.depth + 1;
                if (!Array.isArray(parent.item.children)) parent.item.children = [];
                targetList = parent.item.children;
            } else {
                targetList = items(menuId);
            }
            if (targetDepth + subtreeHeight(found.item) - 1 > menu.depth) {
                announce(tr('too_deep'));
                return false;
            }
            const sameList = targetList === found.list;
            found.list.splice(found.index, 1);
            if (sameList && found.index < index) index--;
            index = Math.max(0, Math.min(index, targetList.length));
            targetList.splice(index, 0, found.item);
            if (found.parent && Array.isArray(found.parent.children) && !found.parent.children.length) delete found.parent.children;
            touchMenu(menuId);
            return true;
        }

        function locateIn(item, uid) {
            return (item.children || []).some(child => child.uid === uid || locateIn(child, uid));
        }

        function moveBy(menuId, uid, delta) {
            const found = locate(menuId, uid);
            if (!found) return;
            const to = found.index + delta;
            if (to < 0 || to >= found.list.length) return;
            found.list.splice(found.index, 1);
            found.list.splice(to, 0, found.item);
            touchMenu(menuId);
            render();
            announce(tr('moved', { label: displayLabel(found.item), position: to + 1, count: found.list.length }));
        }

        function indent(menuId, uid) {
            const found = locate(menuId, uid);
            if (!found || found.index === 0) return;
            const prev = found.list[found.index - 1];
            if (moveTo(menuId, uid, prev.uid, (prev.children || []).length)) {
                mf.open.delete(uid);
                render();
                announce(tr('indented', { label: displayLabel(found.item), parent: displayLabel(prev) }));
            }
        }

        function outdent(menuId, uid) {
            const found = locate(menuId, uid);
            if (!found || !found.parent) return;
            const parent = locate(menuId, found.parent.uid);
            if (moveTo(menuId, uid, parent.parent ? parent.parent.uid : null, parent.index + 1)) {
                render();
                announce(tr('outdented', { label: displayLabel(found.item) }));
            }
        }

        function removeItem(menuId, uid) {
            const found = locate(menuId, uid);
            if (!found) return;
            found.list.splice(found.index, 1);
            if (found.parent && !found.parent.children.length) delete found.parent.children;
            touchMenu(menuId);
            const next = found.list[Math.min(found.index, found.list.length - 1)];
            render();
            announce(tr('removed', { label: displayLabel(found.item) }));
            const focusTarget = next ? root.querySelector(`[data-uid="${CSS.escape(next.uid)}"] [data-mf-toggle]`) : root.querySelector(`[data-mf-add-toggle="${CSS.escape(menuId)}"]`);
            if (focusTarget) focusTarget.focus();
        }

        function validHref(href) {
            return /^(https?:\/\/[^\s]+|mailto:[^\s]+|tel:[^\s]+|#[^\s]*|\/(?!\/)[^\s]*|[A-Za-z0-9._~-][^\s:]*)$/.test(href) && !/[\s\\<>"]/.test(href);
        }

        function addEntry(menuId) {
            const panel = root.querySelector(`[data-mf-add="${CSS.escape(menuId)}"]`);
            const kind = panel.querySelector('[data-mf-add-kind]').value;
            const errorEl = panel.querySelector('[data-mf-add-error]');
            const blank = panel.querySelector('[data-mf-add-blank]').checked;
            let item;
            if (kind === 'page') {
                const page = panel.querySelector('[data-mf-add-page]').value;
                if (!page) { errorEl.textContent = tr('error_choose_page'); return; }
                item = { uid: newUid(), type: 'page', page, label: '' };
            } else {
                const label = panel.querySelector('[data-mf-add-label]').value.trim();
                const href = panel.querySelector('[data-mf-add-href]').value.trim();
                if (!label || !href) { errorEl.textContent = tr('error_label_href'); return; }
                if (!validHref(href)) { errorEl.textContent = tr('error_href'); return; }
                item = { uid: newUid(), type: 'link', label, href };
            }
            if (blank) { item.target = '_blank'; item.rel = 'noopener'; }
            items(menuId).push(item);
            mf.open.delete('add:' + menuId);
            touchMenu(menuId);
            render();
            announce(tr('added', { label: displayLabel(item) }));
            const toggle = root.querySelector(`[data-uid="${CSS.escape(item.uid)}"] [data-mf-toggle]`);
            if (toggle) toggle.focus();
        }

        // ------------------------------------------------------------
        // Footer operations
        // ------------------------------------------------------------

        function footerField(path) {
            return footerFieldsAll().find(field => field.path === path);
        }

        function setLeaf(path, leafPath, lang, value) {
            if (!leafPath.length) {
                const current = mf.footer[path];
                if (lang && isLangMap(current)) current[lang] = value;
                else mf.footer[path] = value;
            } else {
                let target = mf.footer[path];
                if (target === null || typeof target !== 'object') target = mf.footer[path] = {};
                for (let i = 0; i < leafPath.length - 1; i++) {
                    const key = leafPath[i];
                    if (target[key] === null || typeof target[key] !== 'object') target[key] = {};
                    target = target[key];
                }
                const last = leafPath[leafPath.length - 1];
                if (lang && isLangMap(target[last])) target[last][lang] = value;
                else target[last] = value;
            }
            mf.dirtyFooter.add(path);
            markDirty();
        }

        function groupList(path) {
            const value = mf.footer[path];
            if (Array.isArray(value)) return value;
            const list = value && typeof value === 'object' ? Object.values(value) : [];
            mf.footer[path] = list;
            return list;
        }

        function groupNewEntry(list) {
            // A new entry takes the keys of its neighbours, so the template finds what it reads.
            const sample = list.find(entry => entry && typeof entry === 'object') || { label: '', href: '' };
            const entry = {};
            Object.entries(sample).forEach(([key, value]) => {
                if (key === 'uid') return;
                if (typeof value === 'boolean') entry[key] = false;
                else if (isLangMap(value)) entry[key] = Object.fromEntries(Object.keys(value).map(lang => [lang, '']));
                else if (typeof value === 'string') entry[key] = '';
            });
            if (!Object.keys(entry).length) Object.assign(entry, { label: '', href: '' });
            return entry;
        }

        // ------------------------------------------------------------
        // Events
        // ------------------------------------------------------------

        form.addEventListener('click', event => {
            const target = event.target.closest('button');
            if (!target || !form.contains(target)) return;
            const row = target.closest('[data-uid]');
            const menuId = row ? row.dataset.menu : null;
            const uid = row ? row.dataset.uid : null;

            if (target.hasAttribute('data-mf-toggle')) {
                const groupEntry = target.closest('[data-group-entry]');
                const fieldRow = target.closest('[data-field]');
                const key = row ? uid : (groupEntry ? 'g:' + groupEntry.dataset.group + ':' + groupEntry.dataset.groupEntry
                    : (fieldRow ? 'f:' + fieldRow.dataset.field : ''));
                // Details are rendered only while open (no hidden form controls for long menus).
                if (mf.open.has(key)) mf.open.delete(key); else mf.open.add(key);
                render();
                return;
            }
            if (target.hasAttribute('data-mf-backups')) {
                if (mf.open.has('backups')) mf.open.delete('backups'); else mf.open.add('backups');
                renderBackups();
                root.querySelector('[data-mf-backups]').focus();
                return;
            }
            if (target.dataset.mfAddToggle) {
                const id = target.dataset.mfAddToggle;
                if (mf.open.has('add:' + id)) mf.open.delete('add:' + id); else mf.open.add('add:' + id);
                const panel = root.querySelector(`[data-mf-add="${CSS.escape(id)}"]`);
                panel.hidden = !mf.open.has('add:' + id);
                target.setAttribute('aria-expanded', String(!panel.hidden));
                if (!panel.hidden) panel.querySelector('[data-mf-add-kind]').focus();
                return;
            }
            if (target.dataset.mfAddCancel) {
                const id = target.dataset.mfAddCancel;
                mf.open.delete('add:' + id);
                root.querySelector(`[data-mf-add="${CSS.escape(id)}"]`).hidden = true;
                const toggle = root.querySelector(`[data-mf-add-toggle="${CSS.escape(id)}"]`);
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
                return;
            }
            if (target.dataset.mfAddSubmit) { addEntry(target.dataset.mfAddSubmit); return; }
            if (target.hasAttribute('data-mf-move') && row) { moveBy(menuId, uid, Number(target.dataset.mfMove)); return; }
            if (target.hasAttribute('data-mf-indent') && row) { indent(menuId, uid); return; }
            if (target.hasAttribute('data-mf-outdent') && row) { outdent(menuId, uid); return; }
            if (target.hasAttribute('data-mf-remove') && row) { removeItem(menuId, uid); return; }
            if (target.dataset.mfReset) { resetMenu(target.dataset.mfReset); return; }
            if (target.dataset.mfRestore) { restoreBackup(target.dataset.mfRestore); return; }
            if (target.hasAttribute('data-mf-unignore')) {
                Object.keys(mf.assign).forEach(path => { if (mf.assign[path] === 'ignore') delete mf.assign[path]; });
                mf.state.footer.ignored.forEach(path => { mf.assign[path] = ''; });
                // Previously ignored keys come back as findings after saving; show them now as well.
                mf.state.footer.ignored.forEach(path => {
                    if (!mf.state.footer.findings.some(f => f.path === path)) mf.state.footer.findings.push({ path, type: '', confident: false, printed: false, value: null, refs: [], origin: 'content/pages/footer.json' });
                });
                mf.state.footer.ignored = [];
                markDirty();
                render();
                return;
            }
            const entry = target.closest('[data-group-entry]');
            if (target.dataset.mfGroupAdd) {
                const path = target.dataset.mfGroupAdd;
                const list = groupList(path);
                list.push(groupNewEntry(list));
                mf.open.add('g:' + path + ':' + (list.length - 1));
                mf.dirtyFooter.add(path);
                markDirty();
                render();
                const added = root.querySelector(`[data-group="${CSS.escape(path)}"][data-group-entry="${list.length - 1}"] input, [data-group="${CSS.escape(path)}"][data-group-entry="${list.length - 1}"] textarea`);
                if (added) added.focus();
                return;
            }
            if (entry && (target.hasAttribute('data-mf-group-move') || target.hasAttribute('data-mf-group-remove'))) {
                const path = entry.dataset.group;
                const index = Number(entry.dataset.groupEntry);
                const list = groupList(path);
                if (target.hasAttribute('data-mf-group-remove')) {
                    list.splice(index, 1);
                } else {
                    const to = index + Number(target.dataset.mfGroupMove);
                    if (to < 0 || to >= list.length) return;
                    list.splice(to, 0, list.splice(index, 1)[0]);
                    remapGroupOpen(path, index, to);
                }
                mf.dirtyFooter.add(path);
                markDirty();
                render();
                return;
            }
            if (target.dataset.mfImage !== undefined) {
                const path = target.closest('[data-field]').dataset.field;
                const leafPath = JSON.parse(target.dataset.mfImage);
                if (!window.NbImageManager) return;
                window.NbImageManager.open(src => {
                    // setLeaf only writes into the current language when the value is a {lang: …} map.
                    setLeaf(path, leafPath, mf.lang, '/' + String(src).replace(/^(\.\.\/|\/)+/, ''));
                    render();
                }, null, { type: 'image' });
            }
        });

        function remapGroupOpen(path, from, to) {
            const wasOpen = mf.open.has('g:' + path + ':' + from);
            mf.open.delete('g:' + path + ':' + from);
            mf.open.delete('g:' + path + ':' + to);
            if (wasOpen) mf.open.add('g:' + path + ':' + to);
        }

        form.addEventListener('input', event => {
            const el = event.target;
            const row = el.closest('[data-uid]');
            if (el.hasAttribute('data-mf-add-label') || el.hasAttribute('data-mf-add-href')) {
                el.closest('[data-mf-add]').querySelector('[data-mf-add-error]').textContent = '';
                return;
            }
            if (row && el.dataset.mfField && ['label', 'href', 'rel'].includes(el.dataset.mfField)) {
                const found = locate(row.dataset.menu, row.dataset.uid);
                if (!found) return;
                if (el.value.trim() === '' && el.dataset.mfField !== 'label') delete found.item[el.dataset.mfField];
                else found.item[el.dataset.mfField] = el.value;
                if (el.dataset.mfField === 'label' && !el.value) delete found.item.label;
                delete mf.errors[row.dataset.uid];
                const head = row.querySelector(':scope > .mf-row__head');
                head.querySelector('strong').textContent = displayLabel(found.item);
                head.querySelector('small').textContent = displayTarget(found.item);
                touchMenu(row.dataset.menu);
                return;
            }
            if (el.hasAttribute('data-mf-leaf')) {
                const fieldEl = el.closest('[data-field]');
                if (!fieldEl) return;
                const path = fieldEl.dataset.field;
                const leafPath = JSON.parse(el.dataset.mfLeaf);
                setLeaf(path, leafPath, el.dataset.mfLang || null, el.value);
                delete mf.errors[path];
                const entry = el.closest('[data-group-entry]');
                if (entry) {
                    const list = groupList(path);
                    const item = list[Number(entry.dataset.groupEntry)];
                    entry.querySelector(':scope > .mf-row__head strong').textContent = groupEntryLabel(item);
                    entry.querySelector(':scope > .mf-row__head small').textContent = groupEntryHref(item);
                } else {
                    fieldEl.querySelector(':scope > .mf-row__head small').textContent = preview(mf.footer[path]) || tr('empty_value');
                }
            }
        });

        form.addEventListener('change', event => {
            const el = event.target;
            const row = el.closest('[data-uid]');
            if (el.hasAttribute('data-mf-add-kind')) {
                const panel = el.closest('[data-mf-add]');
                const isPage = el.value === 'page';
                panel.querySelector('[data-mf-add-page-fields]').hidden = !isPage;
                panel.querySelector('[data-mf-add-link-fields]').hidden = isPage;
                panel.querySelector('[data-mf-add-error]').textContent = '';
                return;
            }
            if (el.hasAttribute('data-mf-add-page')) {
                el.closest('[data-mf-add]').querySelector('[data-mf-add-error]').textContent = '';
                return;
            }
            if (row && el.hasAttribute('data-mf-visible')) {
                const found = locate(row.dataset.menu, row.dataset.uid);
                if (!found) return;
                if (el.checked) delete found.item.hidden; else found.item.hidden = true;
                touchMenu(row.dataset.menu);
                render();
                announce(el.checked ? tr('shown', { label: displayLabel(found.item) }) : tr('hidden', { label: displayLabel(found.item) }));
                return;
            }
            if (row && el.dataset.mfField && ['type', 'page', 'target'].includes(el.dataset.mfField)) {
                const found = locate(row.dataset.menu, row.dataset.uid);
                if (!found) return;
                const field = el.dataset.mfField;
                if (field === 'type') {
                    if (el.value === 'link' && found.item.type === 'page') {
                        found.item.type = 'link';
                        const page = pageInfo(found.item.page);
                        if (!found.item.label && page) found.item.label = page.title;
                        if (!found.item.href) found.item.href = page ? page.href : '';
                    } else if (el.value === 'page') {
                        found.item.type = 'page';
                        const match = (mf.state.pages[mf.lang] || []).find(p => p.href === found.item.href || p.page === found.item.page);
                        found.item.page = match ? match.page : (found.item.page || '');
                        delete found.item.href;
                    }
                } else if (field === 'target') {
                    if (el.value) found.item.target = el.value; else delete found.item.target;
                    if (el.value === '_blank' && !/\bnoopener\b/.test(found.item.rel || '')) found.item.rel = ((found.item.rel || '') + ' noopener').trim();
                } else {
                    found.item.page = el.value;
                }
                delete mf.errors[row.dataset.uid];
                touchMenu(row.dataset.menu);
                mf.open.add(row.dataset.uid);
                render();
                return;
            }
            if (el.dataset.mfAssign !== undefined) {
                const path = el.dataset.mfAssign;
                if (el.value) mf.assign[path] = el.value; else delete mf.assign[path];
                if (FOOTER_TYPES.includes(el.value)) mf.open.add('f:' + path);
                markDirty();
                render();
                return;
            }
            if (el.hasAttribute('data-mf-group-visible')) {
                const entry = el.closest('[data-group-entry]');
                const path = entry.dataset.group;
                const item = groupList(path)[Number(entry.dataset.groupEntry)];
                item.hidden = !el.checked;
                mf.dirtyFooter.add(path);
                markDirty();
                render();
                return;
            }
            if (el.hasAttribute('data-mf-leaf') && el.tagName === 'SELECT') {
                const fieldEl = el.closest('[data-field]');
                setLeaf(fieldEl.dataset.field, JSON.parse(el.dataset.mfLeaf), el.dataset.mfLang || null, el.value);
            }
        });

        form.addEventListener('keydown', event => {
            const el = event.target;
            if ((el.hasAttribute('data-mf-add-label') || el.hasAttribute('data-mf-add-href')) && event.key === 'Enter') {
                event.preventDefault();
                addEntry(el.closest('[data-mf-add]').dataset.mfAdd);
                return;
            }
            if (!el.hasAttribute('data-mf-grip')) return;
            const row = el.closest('[data-uid]');
            const entry = el.closest('[data-group-entry]');
            const keys = { ArrowUp: -1, ArrowDown: 1 };
            if (row) {
                if (event.key in keys) { event.preventDefault(); moveBy(row.dataset.menu, row.dataset.uid, keys[event.key]); }
                else if (event.key === 'ArrowRight' && menuDef(row.dataset.menu).depth > 1) { event.preventDefault(); indent(row.dataset.menu, row.dataset.uid); }
                else if (event.key === 'ArrowLeft' && menuDef(row.dataset.menu).depth > 1) { event.preventDefault(); outdent(row.dataset.menu, row.dataset.uid); }
            } else if (entry && event.key in keys) {
                event.preventDefault();
                const path = entry.dataset.group;
                const list = groupList(path);
                const index = Number(entry.dataset.groupEntry);
                const to = index + keys[event.key];
                if (to < 0 || to >= list.length) return;
                list.splice(to, 0, list.splice(index, 1)[0]);
                remapGroupOpen(path, index, to);
                mf.dirtyFooter.add(path);
                markDirty();
                render();
                const grip = root.querySelector(`[data-group="${CSS.escape(path)}"][data-group-entry="${to}"] [data-mf-grip]`);
                if (grip) grip.focus();
                announce(tr('moved', { label: groupEntryLabel(list[to]), position: to + 1, count: list.length }));
            }
        });

        // Drag and drop: rows move before/after another row of the same menu (or group).
        form.addEventListener('dragstart', event => {
            const grip = event.target.closest && event.target.closest('[data-mf-grip]');
            if (!grip) return;
            const row = grip.closest('[data-uid],[data-group-entry]');
            drag = row.dataset.uid ? { menu: row.dataset.menu, uid: row.dataset.uid } : { group: row.dataset.group, index: Number(row.dataset.groupEntry) };
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', row.dataset.uid || row.dataset.groupEntry);
            try { event.dataTransfer.setDragImage(row, 24, 24); } catch (e) {}
            row.classList.add('is-dragging');
        });
        form.addEventListener('dragend', () => {
            drag = null;
            form.querySelectorAll('.is-dragging,.drop-before,.drop-after').forEach(el => el.classList.remove('is-dragging', 'drop-before', 'drop-after'));
        });
        form.addEventListener('dragover', event => {
            if (!drag) return;
            const row = event.target.closest && event.target.closest(drag.uid ? '[data-uid]' : '[data-group-entry]');
            if (!row || (drag.uid ? row.dataset.menu !== drag.menu : row.dataset.group !== drag.group)) return;
            if (drag.uid && (row.dataset.uid === drag.uid || row.closest(`[data-uid="${CSS.escape(drag.uid)}"]`))) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const head = row.querySelector(':scope > .mf-row__head') || row;
            const rect = head.getBoundingClientRect();
            const before = event.clientY < rect.top + rect.height / 2;
            form.querySelectorAll('.drop-before,.drop-after').forEach(el => el.classList.remove('drop-before', 'drop-after'));
            row.classList.add(before ? 'drop-before' : 'drop-after');
        });
        form.addEventListener('drop', event => {
            if (!drag) return;
            const row = form.querySelector('.drop-before,.drop-after');
            if (!row) return;
            event.preventDefault();
            const after = row.classList.contains('drop-after');
            const current = drag;
            drag = null;
            if (current.uid) {
                const target = locate(current.menu, row.dataset.uid);
                if (!target) return;
                if (moveTo(current.menu, current.uid, target.parent ? target.parent.uid : null, target.index + (after ? 1 : 0))) {
                    const moved = locate(current.menu, current.uid);
                    render();
                    announce(tr('moved', { label: displayLabel(moved.item), position: moved.index + 1, count: moved.list.length }));
                    const grip = root.querySelector(`[data-uid="${CSS.escape(current.uid)}"] [data-mf-grip]`);
                    if (grip) grip.focus();
                } else {
                    render();
                }
            } else {
                const list = groupList(current.group);
                let to = Number(row.dataset.groupEntry) + (after ? 1 : 0);
                if (current.index < to) to--;
                if (to === current.index) { render(); return; }
                list.splice(to, 0, list.splice(current.index, 1)[0]);
                remapGroupOpen(current.group, current.index, to);
                mf.dirtyFooter.add(current.group);
                markDirty();
                render();
            }
        });

        // ------------------------------------------------------------
        // Load, save, reset, restore
        // ------------------------------------------------------------

        async function load(message) {
            mf.busy = true;
            updateSaveBar();
            form.inert = true;
            setStatus(tr('loading'));
            try {
                const response = await fetch('api.php?action=load-menus-footer&_=' + Date.now(), { cache: 'no-store' });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || t('toast.error'));
                applyState(result.data);
                mf.loaded = true;
                render();
                setStatus(message || '');
            } catch (error) {
                setStatus(error.message || t('toast.error'), 'error');
            } finally {
                mf.busy = false;
                form.inert = false;
                updateSaveBar();
            }
        }

        function buildPayload() {
            const payload = { menus: {}, footer: {}, assign: {} };
            mf.dirtyMenus.forEach(key => {
                const [id, lang] = key.split('|');
                (payload.menus[id] = payload.menus[id] || {})[lang] = mf.menus[id][lang];
            });
            mf.dirtyFooter.forEach(path => { payload.footer[path] = mf.footer[path]; });
            Object.entries(mf.assign).forEach(([path, type]) => { payload.assign[path] = type; });
            return payload;
        }

        async function post(fields) {
            const body = new FormData();
            Object.entries(fields).forEach(([key, value]) => body.append(key, value));
            body.append('csrf_token', CSRF_TOKEN);
            const response = await fetch('api.php', { method: 'POST', body });
            let result = {};
            try { result = await response.json(); } catch (e) { result = { success: false, message: t('toast.error') }; }
            return { response, result };
        }

        function showConflict(message) {
            mf.conflict = true;
            statusEl.className = 'mf-status mf-status--error';
            statusEl.textContent = (message || tr('conflict')) + ' ';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-secondary btn-sm';
            button.textContent = tr('conflict_reload');
            button.addEventListener('click', () => load(tr('reloaded')));
            statusEl.appendChild(button);
        }

        async function save() {
            if (mf.busy || !isDirty()) return;
            mf.busy = true;
            form.inert = true;
            updateSaveBar();
            setStatus(tr('saving'));
            try {
                const { response, result } = await post({
                    action: 'save-menus-footer',
                    payload: JSON.stringify(buildPayload()),
                    revisions: JSON.stringify(mf.state.revisions),
                });
                if (response.status === 409 || response.status === 428) { showConflict(result.message); return; }
                if (response.status === 422 && result.data && result.data.errors) {
                    mf.errors = {};
                    result.data.errors.forEach(error => {
                        if (error.uid) { mf.errors[error.uid] = error.message; mf.open.add(error.uid); if (error.lang && error.lang !== mf.lang) mf.lang = error.lang; }
                        if (error.field) { mf.errors[error.field] = error.message; mf.open.add('f:' + error.field); }
                    });
                    if (langSelect) langSelect.value = mf.lang;
                    render();
                    setStatus(result.message || t('toast.error'), 'error');
                    const first = root.querySelector('.mf-field-error');
                    if (first) first.closest('.mf-row').querySelector('input, select, textarea')?.focus();
                    return;
                }
                if (!result.success) throw new Error(result.message || t('toast.error'));
                applyState(result.data);
                render();
                setStatus(tr('saved'), 'success');
                showToast(tr('saved'), 'success');
            } catch (error) {
                setStatus(error.message || t('toast.error'), 'error');
            } finally {
                mf.busy = false;
                form.inert = false;
                updateSaveBar();
            }
        }

        function resetMenu(menuId) {
            if (isDirty()) { setStatus(tr('save_first'), 'error'); return; }
            const menu = menuDef(menuId);
            showModal(tr('reset_title'), tr('reset_confirm', { menu: labelOf(menu.label) || menuId, lang: mf.lang.toUpperCase() }), async () => {
                closeModal();
                mf.busy = true;
                const { response, result } = await post({
                    action: 'save-menus-footer',
                    payload: JSON.stringify({ reset: [{ menu: menuId, lang: mf.lang }] }),
                    revisions: JSON.stringify(mf.state.revisions),
                });
                mf.busy = false;
                if (response.status === 409 || response.status === 428) { showConflict(result.message); return; }
                if (!result.success) { setStatus(result.message || t('toast.error'), 'error'); return; }
                applyState(result.data);
                render();
                setStatus(tr('reset_done'), 'success');
            }, { confirmText: tr('reset'), confirmClass: 'btn btn-danger' });
        }

        function restoreBackup(filename) {
            if (isDirty()) { setStatus(tr('save_first'), 'error'); return; }
            showModal(tr('restore_title'), tr('restore_confirm', { file: filename }), async () => {
                closeModal();
                const { response, result } = await post({ action: 'restore-menus-backup', backup: filename, revision: mf.state.revisions.menus });
                if (response.status === 409 || response.status === 428) { showConflict(result.message); return; }
                if (!result.success) { setStatus(result.message || t('toast.error'), 'error'); return; }
                applyState(result.data);
                render();
                setStatus(tr('restored'), 'success');
            }, { confirmText: tr('restore'), confirmClass: 'btn btn-primary' });
        }

        form.addEventListener('submit', event => {
            event.preventDefault();
            save();
        });

        discardBtn.addEventListener('click', () => {
            if (!isDirty()) return;
            showModal(tr('discard_title'), tr('discard_confirm'), () => { closeModal(); load(tr('discarded')); },
                { confirmText: tr('discard'), confirmClass: 'btn btn-danger' });
        });

        document.getElementById('mfRescan').addEventListener('click', () => {
            if (isDirty() && !window.confirm(tr('discard_confirm'))) return;
            load(tr('rescanned'));
        });

        if (langSelect) {
            langSelect.addEventListener('change', () => {
                mf.lang = langSelect.value;
                mf.open.clear();
                render();
            });
        }

        window.addEventListener('beforeunload', event => {
            if (!isDirty()) return;
            event.preventDefault();
            event.returnValue = '';
        });

        // Save bar: transparent in the flow, a darker strip once it sticks to the bottom edge.
        let barFrame = 0;
        function updateStuck() {
            barFrame = 0;
            const bar = form.querySelector('.mf-save-bar');
            const marker = form.querySelector('.mf-save-sentinel');
            if (!bar || !marker || !bar.getClientRects().length) return;
            // Full width of the content area: bleed from the form's edges to those of .admin-main.
            const main = document.getElementById('adminMain');
            if (main) {
                const outer = main.getBoundingClientRect();
                const inner = form.getBoundingClientRect();
                form.style.setProperty('--mf-bleed-left', Math.max(0, Math.round(inner.left - outer.left)) + 'px');
                form.style.setProperty('--mf-bleed-right', Math.max(0, Math.round(outer.right - inner.right - (main.offsetWidth - main.clientWidth))) + 'px');
            }
            const normalTop = marker.getBoundingClientRect().bottom + parseFloat(getComputedStyle(bar).marginTop || '0');
            bar.classList.toggle('is-stuck', bar.getBoundingClientRect().top < normalTop - 1);
        }
        function scheduleSaveBar() { if (!barFrame) barFrame = requestAnimationFrame(updateStuck); }
        window.addEventListener('resize', scheduleSaveBar, { passive: true });
        document.addEventListener('scroll', scheduleSaveBar, { capture: true, passive: true });
        if (window.ResizeObserver) new ResizeObserver(scheduleSaveBar).observe(form);

        window.NbMenusEditor = {
            open() {
                if (!mf.loaded && !mf.busy) load();
                else scheduleSaveBar();
            },
            canLeave() {
                if (!isDirty()) return true;
                if (!window.confirm(tr('leave_confirm'))) return false;
                mf.loaded = false;
                mf.dirtyMenus.clear();
                mf.dirtyFooter.clear();
                mf.assign = {};
                updateSaveBar();
                return true;
            },
            isDirty,
        };
    })();
    <?php endif; ?>
