<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // EVENTS EDITOR
    // ============================================================

    const SITE_LANGUAGES = <?php echo json_encode($siteLanguages); ?>;
    const DEFAULT_LANG = '<?php echo defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'; ?>';
    let eventsData = null;
    let eventsLoaded = false;
    let currentEventIndex = null;
    const EVENT_TRANSLATABLE = ['title', 'location', 'description', 'admission'];
    function adminLangCodes() {
        return Object.keys(SITE_LANGUAGES || {});
    }
    function adminLangLabel(code) {
        return String((SITE_LANGUAGES && SITE_LANGUAGES[code]) || code).toUpperCase();
    }
    function activeAdminLang(section) {
        return section.querySelector('.ce-lang-tab.active')?.dataset.lang || DEFAULT_LANG;
    }
    function readAdminLangField(fieldEl) {
        const input = fieldEl.querySelector('textarea, input:not([type="hidden"]), select');
        return input ? input.value || '' : '';
    }
    function writeAdminLangField(fieldEl, value) {
        const input = fieldEl.querySelector('textarea, input:not([type="hidden"]), select');
        if (!input) return;
        input.value = value == null ? '' : String(value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    async function translateAdminLanguageSection(section, button) {
        if (!AI_FEATURES_ENABLED || !section) return;
        const sourceLang = activeAdminLang(section);
        const targetLangs = adminLangCodes().filter(code => code !== sourceLang);
        const fields = {};
        section.querySelectorAll('[data-lang="' + CSS.escape(sourceLang) + '"][data-i18n-field]').forEach(fieldEl => {
            const value = readAdminLangField(fieldEl);
            if (value.trim() !== '') fields[fieldEl.dataset.i18nField] = value;
        });
        if (!Object.keys(fields).length) {
            showToast(t('editor.translate_no_source') || 'No source text to translate.', 'error');
            return;
        }
        const previous = button.textContent;
        button.disabled = true;
        button.textContent = t('editor.translating') || 'Translating...';
        try {
            const formData = new FormData();
            formData.append('action', 'ai-generate-text');
            formData.append('maxOutputTokens', '1800');
            formData.append('prompt', [
                'Translate these nibbly admin editor fields from ' + sourceLang + ' into the requested target languages.',
                'Return strict JSON only. Shape: {"translations":{"LANG":{"field":"translated value"}}}.',
                'Keep HTML tags if present. Do not add Markdown or explanations.',
                '',
                JSON.stringify({ sourceLang, targetLangs, fields: fields }, null, 2)
            ].join('\n'));
            formData.append('csrf_token', CSRF_TOKEN);
            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || t('toast.error'));
            let payload;
            try {
                payload = JSON.parse(String(result.data?.text || '').replace(/^```json\s*|\s*```$/g, '').trim());
            } catch (error) {
                throw new Error(t('editor.translate_invalid_response') || 'AI returned an unreadable translation.');
            }
            const translations = payload.translations || payload;
            targetLangs.forEach(lang => {
                Object.entries(translations[lang] || {}).forEach(([field, value]) => {
                    const fieldEl = section.querySelector('[data-lang="' + CSS.escape(lang) + '"][data-i18n-field="' + CSS.escape(field) + '"]');
                    if (fieldEl) writeAdminLangField(fieldEl, value);
                });
            });
            updateAiUsage(result.data ? result.data.limits : null);
            showToast(t('editor.translate_done') || 'Translations inserted.', 'success');
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            button.disabled = false;
            button.textContent = previous;
        }
    }

    async function loadEventsEditor() {
        try {
            const response = await fetch('api.php?action=load-events');
            const result = await response.json();
            if (result.success) {
                eventsData = result.data;
                renderEventsList();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading_events', {message: error.message}), 'error');
        }
    }

    function renderEventsList() {
        const tbody = document.getElementById('eventsListBody');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (eventsData && eventsData.lastModified) {
            const d = new Date(eventsData.lastModified);
            document.getElementById('eventsLastModified').textContent = t('editor.last_saved', {date: formatDateShort(d)});
        } else {
            document.getElementById('eventsLastModified').textContent = '';
        }

        const events = (eventsData && eventsData.events) || [];
        if (events.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="4" style="color: var(--nb-text-muted); text-align: center; padding: var(--nb-space-6);">${escapeHtml(t('events.no_events'))}</td>`;
            tbody.appendChild(tr);
            renderAdminListFooter('eventsListFooter', 'events', 0, getDashboardPageSize(), renderEventsList, 'eventsListFooterTop');
            return;
        }

        // Sort by date ascending
        events.sort((a, b) => (a.date || '').localeCompare(b.date || ''));
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(events, 'events', pageSize);
        renderAdminListFooter('eventsListFooter', 'events', events.length, pageSize, renderEventsList, 'eventsListFooterTop');

        const todayStr = new Date().toISOString().split('T')[0];

        paged.items.forEach((event) => {
            const index = events.indexOf(event);
            const title = event.title?.[DEFAULT_LANG] || event.title?.en || event.title?.de || t('events.untitled');
            const dateStr = event.date || '';
            const endDateStr = event['end-date'] || dateStr;

            // Status — upcoming / today / past
            let statusKey, statusClass;
            if (!dateStr) {
                statusKey = 'events.status_draft';
                statusClass = 'events-status events-status--draft';
            } else if (dateStr === todayStr || (dateStr <= todayStr && endDateStr >= todayStr)) {
                statusKey = 'events.status_today';
                statusClass = 'events-status events-status--today';
            } else if (dateStr > todayStr) {
                statusKey = 'events.status_upcoming';
                statusClass = 'events-status events-status--upcoming';
            } else {
                statusKey = 'events.status_past';
                statusClass = 'events-status events-status--past';
            }

            const tr = document.createElement('tr');
            tr.className = 'page-list-row';
            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';

            const titleLink = document.createElement('a');
            titleLink.href = '#';
            titleLink.className = 'page-list-title-link';
            titleLink.textContent = title;
            titleLink.onclick = (e) => { e.preventDefault(); openEventEditor(index); };
            tdTitle.appendChild(titleLink);

            // Hover action row
            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';

            const editLink = document.createElement('a');
            editLink.href = '#';
            editLink.className = 'page-list-row-action';
            editLink.innerHTML = icon('edit', 12, '2') + ' ' + t('pages.edit');
            editLink.onclick = (e) => { e.preventDefault(); openEventEditor(index); };
            actions.appendChild(editLink);

            if (event.url) {
                const sep1 = document.createElement('span');
                sep1.className = 'page-list-row-action-sep';
                sep1.textContent = '|';
                actions.appendChild(sep1);

                const viewLink = document.createElement('a');
                viewLink.href = event.url;
                viewLink.target = '_blank';
                viewLink.rel = 'noopener';
                viewLink.className = 'page-list-row-action';
                viewLink.innerHTML = icon('eye', 12, '2') + ' ' + t('pages.view');
                actions.appendChild(viewLink);
            }

            const sep2 = document.createElement('span');
            sep2.className = 'page-list-row-action-sep';
            sep2.textContent = '|';
            actions.appendChild(sep2);

            const dupLink = document.createElement('a');
            dupLink.href = '#';
            dupLink.className = 'page-list-row-action';
            dupLink.innerHTML = icon('duplicate', 12, '2') + ' ' + t('pages.duplicate');
            dupLink.onclick = (e) => { e.preventDefault(); duplicateEvent(index); };
            actions.appendChild(dupLink);

            const sep3 = document.createElement('span');
            sep3.className = 'page-list-row-action-sep';
            sep3.textContent = '|';
            actions.appendChild(sep3);

            const trashLink = document.createElement('a');
            trashLink.href = '#';
            trashLink.className = 'page-list-row-action page-list-row-action--danger';
            trashLink.innerHTML = icon('trash', 12, '2') + ' ' + t('pages.trash');
            trashLink.onclick = (e) => { e.preventDefault(); deleteEventDashboard(index); };
            actions.appendChild(trashLink);

            tdTitle.appendChild(actions);

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            tdDate.textContent = dateStr;

            const tdLocation = document.createElement('td');
            tdLocation.textContent = event.location?.[DEFAULT_LANG] || event.location?.en || event.location?.de || '';

            const tdStatus = document.createElement('td');
            tdStatus.className = 'page-list-cell-date';
            const statusBadge = document.createElement('span');
            statusBadge.className = statusClass;
            statusBadge.textContent = t(statusKey);
            tdStatus.appendChild(statusBadge);

            tr.appendChild(tdTitle);
            tr.appendChild(tdDate);
            tr.appendChild(tdLocation);
            tr.appendChild(tdStatus);
            tbody.appendChild(tr);
        });
    }

    function openEventEditor(index) {
        currentEventIndex = index;
        document.getElementById('eventsListView').style.display = 'none';
        document.getElementById('eventsEditorView').style.display = '';

        const event = eventsData.events[index];
        const title = event.title?.[DEFAULT_LANG] || event.title?.en || t('events.untitled');
        document.getElementById('eventEditorTitle').textContent = title;
        document.getElementById('eventEditorDeleteBtn').style.display = event.id ? '' : 'none';

        const body = document.getElementById('eventEditorBody');
        body.innerHTML = '';
        renderEventFields(body, event, index);
    }

    function closeEventEditor() {
        document.getElementById('eventsEditorView').style.display = 'none';
        document.getElementById('eventsListView').style.display = '';
        currentEventIndex = null;
    }

    function saveCurrentEvent() {
        if (currentEventIndex === null) return;
        saveEventDashboard(currentEventIndex);
    }

    function deleteCurrentEvent() {
        if (currentEventIndex === null) return;
        deleteEventDashboard(currentEventIndex);
    }

    function duplicateEvent(index) {
        const src = eventsData.events[index];
        const copy = JSON.parse(JSON.stringify(src));
        copy.id = ''; // unsaved → server assigns on save
        eventsData.events.push(copy);
        renderEventsList();
        openEventEditor(eventsData.events.length - 1);
    }

    function renderEventFields(container, eventObj, index) {
        const prefix = `events.${index}`;

        // Date/time row
        const dateRow = document.createElement('div');
        dateRow.className = 'ce-field-row';
        dateRow.innerHTML = `
            <div class="ce-field"><label class="ce-field-label">${t('events.start_date')}</label>
                <input type="date" class="ce-input" data-event-path="${prefix}.date" value="${escapeHtml(eventObj.date || '')}"></div>
            <div class="ce-field"><label class="ce-field-label">${t('events.start_time')}</label>
                <input type="time" class="ce-input" data-event-path="${prefix}.time" value="${escapeHtml(eventObj.time || '')}"></div>
            <div class="ce-field"><label class="ce-field-label">${t('events.end_date')}</label>
                <input type="date" class="ce-input" data-event-path="${prefix}.end-date" value="${escapeHtml(eventObj['end-date'] || '')}"></div>
            <div class="ce-field"><label class="ce-field-label">${t('events.end_time')}</label>
                <input type="time" class="ce-input" data-event-path="${prefix}.end-time" value="${escapeHtml(eventObj['end-time'] || '')}"></div>
        `;
        container.appendChild(dateRow);

        // URL
        const urlField = document.createElement('div');
        urlField.className = 'ce-field';
        urlField.innerHTML = `<label class="ce-field-label">URL</label>
            <input type="url" class="ce-input" data-event-path="${prefix}.url" value="${escapeHtml(eventObj.url || '')}">`;
        container.appendChild(urlField);

        // Image
        const imgField = document.createElement('div');
        imgField.className = 'ce-field';
        imgField.innerHTML = `<label class="ce-field-label">Image</label>
            <div class="ce-image-input-row">
                <input type="text" class="ce-input" data-event-path="${prefix}.image" value="${escapeHtml(eventObj.image || '')}">
                <button type="button" class="btn btn-secondary btn-sm ce-browse-btn">Browse</button>
            </div>`;
        const eventBrowseBtn = imgField.querySelector('.ce-browse-btn');
        const eventImgInput = imgField.querySelector('.ce-input');
        eventBrowseBtn.addEventListener('click', function() {
            browseImageForField(eventImgInput, null);
        });
        container.appendChild(imgField);

        // Translatable fields — language tabs
        const langSection = document.createElement('div');
        langSection.className = 'ce-lang-section';
        const langCodes = Object.keys(SITE_LANGUAGES);
        const isMultiLang = langCodes.length > 1;

        const tabsHtml = isMultiLang ? langCodes.map(code => {
            const isDefault = code === DEFAULT_LANG;
            return `<button type="button" class="ce-lang-tab${isDefault ? ' active' : ''}" role="tab" aria-selected="${isDefault ? 'true' : 'false'}" tabindex="${isDefault ? '0' : '-1'}" data-lang="${code}" data-event-idx="${index}">${escapeHtml(adminLangLabel(code))}${isDefault ? ' ★' : ''}</button>`;
        }).join('') : '';

        langSection.innerHTML = isMultiLang
            ? `<div class="ce-lang-header"><div class="ce-lang-tabs" role="tablist" aria-label="${escapeHtml(t('editor.language_tabs') || 'Languages')}">${tabsHtml}</div>${AI_FEATURES_ENABLED ? `<button type="button" class="btn btn-secondary btn-sm ce-lang-translate">${escapeHtml(t('editor.translate_from_active') || 'Translate from active language')}</button>` : ''}</div>`
            : '';

        langCodes.forEach(code => {
            const panel = document.createElement('div');
            panel.className = 'ce-lang-panel';
            if (code === DEFAULT_LANG) panel.classList.add('active');
            panel.setAttribute('role', 'tabpanel');
            panel.dataset.lang = code;
            panel.dataset.eventIdx = index;
            panel.hidden = isMultiLang && code !== DEFAULT_LANG;

            EVENT_TRANSLATABLE.forEach(field => {
                const val = eventObj[field]?.[code] || '';
                const isLong = field === 'description';
                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'ce-field';
                fieldDiv.dataset.lang = code;
                fieldDiv.dataset.i18nField = field;
                fieldDiv.innerHTML = `<label class="ce-field-label">${field.charAt(0).toUpperCase() + field.slice(1)}</label>`;

                if (isLong) {
                    const ta = document.createElement('textarea');
                    ta.className = 'ce-textarea';
                    ta.value = val;
                    ta.dataset.eventPath = `${prefix}.${field}.${code}`;
                    ta.rows = 3;
                    fieldDiv.appendChild(ta);
                } else {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'ce-input';
                    input.value = val;
                    input.dataset.eventPath = `${prefix}.${field}.${code}`;
                    fieldDiv.appendChild(input);
                }
                panel.appendChild(fieldDiv);
            });

            langSection.appendChild(panel);
        });

        container.appendChild(langSection);

        // Tab switching
        langSection.querySelectorAll('.ce-lang-tab').forEach((tab, tabIndex) => {
            const activateTab = (targetTab, focus = false) => {
                langSection.querySelectorAll('.ce-lang-tab').forEach(t => {
                    const active = t === targetTab;
                    t.classList.toggle('active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                    t.tabIndex = active ? 0 : -1;
                    if (active && focus) t.focus();
                });
                langSection.querySelectorAll('.ce-lang-panel').forEach(p => {
                    const active = p.dataset.lang === targetTab.dataset.lang;
                    p.hidden = !active;
                    p.classList.toggle('active', active);
                });
            };
            tab.addEventListener('click', () => {
                activateTab(tab);
            });
            tab.addEventListener('keydown', e => {
                const tabs = Array.from(langSection.querySelectorAll('.ce-lang-tab'));
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
                e.preventDefault();
                let next = tabIndex;
                if (e.key === 'ArrowLeft') next = tabIndex === 0 ? tabs.length - 1 : tabIndex - 1;
                if (e.key === 'ArrowRight') next = tabIndex === tabs.length - 1 ? 0 : tabIndex + 1;
                if (e.key === 'Home') next = 0;
                if (e.key === 'End') next = tabs.length - 1;
                activateTab(tabs[next], true);
            });
        });
        const translateBtn = langSection.querySelector('.ce-lang-translate');
        if (translateBtn) {
            translateBtn.addEventListener('click', function() {
                translateAdminLanguageSection(langSection, translateBtn);
            });
        }

        // Save button is in the editor header, not duplicated inline.
    }

    function collectEventData(index) {
        const prefix = `events.${index}`;
        const event = { id: eventsData.events[index].id };

        // Scalar fields
        ['date', 'time', 'end-date', 'end-time', 'url', 'image'].forEach(field => {
            const el = document.querySelector(`[data-event-path="${prefix}.${field}"]`);
            event[field] = el ? el.value : '';
        });

        // Translatable fields
        const langCodes = Object.keys(SITE_LANGUAGES);
        EVENT_TRANSLATABLE.forEach(field => {
            event[field] = {};
            langCodes.forEach(code => {
                const el = document.querySelector(`[data-event-path="${prefix}.${field}.${code}"]`);
                event[field][code] = el ? el.value : '';
            });
        });

        return event;
    }

    async function saveEventDashboard(index) {
        const event = collectEventData(index);

        if (!event.date || !event.title[DEFAULT_LANG]) {
            showToast(t('toast.events_date_required'), 'error');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'save-event');
            formData.append('event', JSON.stringify(event));
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                showToast(result.message || t('toast.events_saved'), 'success');
                // Reload data, then return to list view
                const reload = await fetch('api.php?action=load-events').then(r => r.json());
                if (reload.success) eventsData = reload.data;
                closeEventEditor();
                renderEventsList();
            } else {
                showToast(t('toast.error_generic', {message: result.message}), 'error');
            }
        } catch (error) {
            showToast(t('toast.error_saving', {message: error.message}), 'error');
        }
    }

    function addNewEvent() {
        const langCodes = Object.keys(SITE_LANGUAGES);
        const newEvent = {
            id: '',
            date: new Date().toISOString().split('T')[0],
            time: '',
            'end-date': '',
            'end-time': '',
            url: '',
            image: ''
        };
        EVENT_TRANSLATABLE.forEach(field => {
            newEvent[field] = {};
            langCodes.forEach(code => { newEvent[field][code] = ''; });
        });

        if (!eventsData) eventsData = { events: [], lastModified: null };
        eventsData.events.push(newEvent);
        renderEventsList();
        // Open the new event in the editor view immediately
        openEventEditor(eventsData.events.length - 1);
    }

    // Move event to trash (no confirm — symmetrical to Pages "move to trash")
    function deleteEventDashboard(index) {
        const event = eventsData.events[index];
        if (!event) return;

        if (!event.id) {
            // New unsaved event, just remove from array
            eventsData.events.splice(index, 1);
            if (currentEventIndex !== null) closeEventEditor();
            renderEventsList();
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete-event');
        formData.append('id', event.id);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('api.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    showToast(t('toast.event_trashed'), 'success');
                    if (currentEventIndex !== null) closeEventEditor();
                    loadEventsEditor();
                } else {
                    showToast(t('toast.error_generic', {message: result.message}), 'error');
                }
            })
            .catch(error => showToast(t('toast.error_generic', {message: error.message}), 'error'));
    }

    // ===== EVENTS TRASH =====

    async function showEventsTrash() {
        document.getElementById('eventsListView').style.display = 'none';
        document.getElementById('eventsEditorView').style.display = 'none';
        document.getElementById('eventsTrashView').style.display = '';
        await loadEventsTrash();
    }

    function closeEventsTrash() {
        document.getElementById('eventsTrashView').style.display = 'none';
        document.getElementById('eventsListView').style.display = '';
        // Reload main list — restore/permanent-delete may have changed the event set
        loadEventsEditor();
    }

    async function loadEventsTrash() {
        try {
            const response = await fetch('api.php?action=list-events-trash&_=' + Date.now());
            const result = await response.json();
            if (result.success) {
                renderEventsTrash(result.data);
            }
        } catch (error) {
            console.error('Error loading events trash:', error);
        }
    }

    function renderEventsTrash(items) {
        const tbody = document.getElementById('eventsTrashBody');
        const emptyMsg = document.getElementById('eventsTrashEmptyMsg');
        const emptyBtn = document.getElementById('emptyEventsTrashBtn');
        const table = document.getElementById('eventsTrashTable');
        tbody.innerHTML = '';

        if (!items || items.length === 0) {
            table.style.display = 'none';
            emptyMsg.style.display = 'block';
            emptyBtn.style.display = 'none';
            renderAdminListFooter('eventsTrashFooter', 'eventsTrash', 0, getDashboardPageSize(), function() {
                renderEventsTrash(items);
            }, 'eventsTrashFooterTop');
            return;
        }

        table.style.display = '';
        emptyMsg.style.display = 'none';
        emptyBtn.style.display = '';

        const pageSize = getDashboardPageSize();
        const paged = pageSlice(items, 'eventsTrash', pageSize);
        renderAdminListFooter('eventsTrashFooter', 'eventsTrash', items.length, pageSize, function() {
            renderEventsTrash(items);
        }, 'eventsTrashFooterTop');

        paged.items.forEach(item => {
            const ev = item.event || {};
            const title = ev.title?.[DEFAULT_LANG] || ev.title?.en || ev.title?.de || t('events.untitled');
            const tr = document.createElement('tr');

            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';
            tdTitle.textContent = title;
            tr.appendChild(tdTitle);

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            tdDate.textContent = ev.date || '';
            tr.appendChild(tdDate);

            const tdDeletedAt = document.createElement('td');
            tdDeletedAt.className = 'page-list-cell-date';
            if (item.deletedAt) {
                const d = new Date(item.deletedAt);
                tdDeletedAt.textContent = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            }
            tr.appendChild(tdDeletedAt);

            const tdActions = document.createElement('td');
            tdActions.className = 'page-list-cell-actions';

            const restoreBtn = document.createElement('button');
            restoreBtn.className = 'btn btn-primary btn-sm';
            restoreBtn.textContent = t('btn.restore');
            restoreBtn.onclick = async () => {
                restoreBtn.disabled = true;
                restoreBtn.textContent = '...';
                try {
                    const fd = new FormData();
                    fd.append('action', 'restore-event');
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('id', ev.id);
                    const r = await fetch('api.php', { method: 'POST', body: fd });
                    const res = await r.json();
                    if (res.success) {
                        showToast(t('toast.event_restored'), 'success');
                        loadEventsTrash();
                    } else {
                        showToast(res.message || 'Error', 'error');
                        restoreBtn.disabled = false;
                        restoreBtn.textContent = t('btn.restore');
                    }
                } catch (err) {
                    showToast(err.message, 'error');
                    restoreBtn.disabled = false;
                    restoreBtn.textContent = t('btn.restore');
                }
            };
            tdActions.appendChild(restoreBtn);

            const delBtn = document.createElement('button');
            delBtn.className = 'btn btn-danger btn-sm';
            delBtn.textContent = t('btn.delete');
            delBtn.onclick = () => {
                showModal(t('modal.delete_permanently'), t('events.confirm_delete_permanent', {title: title}), async () => {
                    closeModal();
                    try {
                        const fd = new FormData();
                        fd.append('action', 'delete-event-permanent');
                        fd.append('csrf_token', CSRF_TOKEN);
                        fd.append('id', ev.id);
                        const r = await fetch('api.php', { method: 'POST', body: fd });
                        const res = await r.json();
                        if (res.success) {
                            showToast(t('toast.event_deleted'), 'success');
                            loadEventsTrash();
                        } else {
                            showToast(res.message || 'Error', 'error');
                        }
                    } catch (err) {
                        showToast(err.message, 'error');
                    }
                });
            };
            tdActions.appendChild(delBtn);

            tr.appendChild(tdActions);
            tbody.appendChild(tr);
        });
    }

    async function emptyEventsTrash() {
        showModal(t('modal.empty_trash'), t('modal.empty_trash_confirm'), async () => {
            closeModal();
            try {
                const fd = new FormData();
                fd.append('action', 'empty-events-trash');
                fd.append('csrf_token', CSRF_TOKEN);
                const r = await fetch('api.php', { method: 'POST', body: fd });
                const res = await r.json();
                if (res.success) {
                    showToast(res.message || t('toast.trash_emptied'), 'success');
                    loadEventsTrash();
                } else {
                    showToast(res.message || 'Error', 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        });
    }

    // Sidebar toggle (mobile)
    document.getElementById('sidebarToggle').addEventListener('click', () => {
        document.getElementById('adminSidebar').classList.toggle('open');
    });
