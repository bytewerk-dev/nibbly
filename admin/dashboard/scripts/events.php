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
    function activeAdminLang(section) {
        return document.querySelector('#eventsEditorView .editor-header .nb-lang-switch input:checked')?.value || DEFAULT_LANG;
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
        if (!dashboardAiTextUsable || !section) return;
        const sourceLang = activeAdminLang(section);
        const targetLangs = adminLangCodes().filter(code => code !== sourceLang);
        const fields = {};
        section.querySelectorAll('[data-lang="' + CSS.escape(sourceLang) + '"][data-i18n-field]').forEach(fieldEl => {
            const value = readAdminLangField(fieldEl);
            if (value.trim() !== '') fields[fieldEl.dataset.i18nField] = value;
        });
        const translations = await aiTranslateFields(sourceLang, targetLangs, fields, button);
        if (!translations) return;
        targetLangs.forEach(lang => {
            Object.entries(translations[lang] || {}).forEach(([field, value]) => {
                const fieldEl = section.querySelector('[data-lang="' + CSS.escape(lang) + '"][data-i18n-field="' + CSS.escape(field) + '"]');
                if (fieldEl) writeAdminLangField(fieldEl, value);
            });
        });
        showToast(t('editor.translate_done'), 'success');
    }

    let eventsStatusFilter = 'all';
    // View language of the list: titles and locations in that language, the primary language as fallback
    let eventsViewLang = DEFAULT_LANG;
    const eventTranslated = (event, lang) => !!String(event.title?.[lang] || '').trim();
    let eventsTrashCount = 0;

    // "upcoming" (incl. today), "past", or "draft" for an event without a date
    function eventPhase(event) {
        const today = new Date().toISOString().split('T')[0];
        if (!event.date) return 'draft';
        return (event['end-date'] || event.date) >= today ? 'upcoming' : 'past';
    }

    function eventMatchesFilter(event) {
        if (eventsStatusFilter === 'hidden') return !!event.hidden;
        return eventsStatusFilter === 'all' || eventPhase(event) === eventsStatusFilter;
    }

    // Filter links (all / upcoming / past / hidden / trash)
    function renderEventsFilterLinks(target, inTrash) {
        const events = (eventsData && eventsData.events) || [];
        const status = (value, label, count) => ({ value, label, count, active: !inTrash && eventsStatusFilter === value, onSelect() {
            eventsStatusFilter = value;
            if (inTrash) closeEventsTrash(); else renderEventsList();
        } });
        renderFilterLinks(target, [{ items: [
            status('all', t('filter.all'), events.length),
            status('upcoming', t('filter.upcoming'), events.filter(e => eventPhase(e) === 'upcoming').length),
            status('past', t('filter.past'), events.filter(e => eventPhase(e) === 'past').length),
            status('hidden', t('filter.hidden'), events.filter(e => e.hidden).length),
            { value: 'trash', label: t('pages.trash'), count: eventsTrashCount, active: inTrash, onSelect: showEventsTrash }
        ] }, !inTrash && Object.keys(SITE_LANGUAGES).length > 1 && { label: t('filter.language'), icon: 'language', items: Object.keys(SITE_LANGUAGES).map(lang => ({
            value: lang, label: SITE_LANGUAGES[lang], count: events.filter(e => eventTranslated(e, lang)).length,
            active: lang === eventsViewLang, onSelect() { eventsViewLang = lang; renderEventsList(); }
        })) }]);
    }

    async function loadEventsEditor() {
        try {
            // The trash only contributes its count to the filter links
            const [response, trash] = await Promise.all([
                fetch('api.php?action=load-events'),
                fetch('api.php?action=list-events-trash&_=' + Date.now()).then(r => r.json()).catch(() => null)
            ]);
            if (trash?.success) eventsTrashCount = (trash.data || []).length;
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
        renderEventsFilterLinks(document.getElementById('eventsFilterLinks'), false);
        if (events.length === 0) {
            nbRenderEmptyRow(tbody, 4, t('events.no_events'), { label: t('events.new_event'), onClick: addNewEvent });
            renderAdminListFooter('eventsListFooter', 'events', 0, getDashboardPageSize(), renderEventsList, 'eventsListFooterTop');
            return;
        }

        // Sort by date ascending
        events.sort((a, b) => (a.date || '').localeCompare(b.date || ''));
        const pageSize = getDashboardPageSize();
        const shown = events.filter(eventMatchesFilter);
        const paged = pageSlice(shown, 'events', pageSize);
        renderAdminListFooter('eventsListFooter', 'events', shown.length, pageSize, renderEventsList, 'eventsListFooterTop');

        const todayStr = new Date().toISOString().split('T')[0];

        paged.items.forEach((event) => {
            const index = events.indexOf(event);
            const title = event.title?.[eventsViewLang] || event.title?.[DEFAULT_LANG] || Object.values(event.title || {}).find(Boolean) || t('events.untitled');
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
            if (!eventTranslated(event, eventsViewLang)) {
                const missing = document.createElement('span');
                missing.className = 'nb-untranslated';
                missing.textContent = t('filter.not_translated');
                tdTitle.appendChild(missing);
            }

            // Hover action row
            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';

            const editLink = document.createElement('a');
            editLink.href = '#';
            editLink.className = 'page-list-row-action';
            editLink.innerHTML = icon('edit', 12, '2') + ' ' + t('pages.edit');
            editLink.onclick = (e) => { e.preventDefault(); openEventEditor(index); };
            actions.appendChild(editLink);

            // View: the event's own page (saved events only)
            const viewHref = event.id ? '../events/' + encodeURIComponent(event.id) : '';
            if (viewHref) {
                const sep1 = document.createElement('span');
                sep1.className = 'page-list-row-action-sep';
                sep1.textContent = '|';
                actions.appendChild(sep1);

                const viewLink = document.createElement('a');
                viewLink.href = viewHref;
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
            tdDate.textContent = dateStr ? (window.nbFormatDate ? nbFormatDate(dateStr) : dateStr) : '—';

            const tdLocation = document.createElement('td');
            tdLocation.textContent = event.location?.[eventsViewLang] || event.location?.[DEFAULT_LANG] || '';

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

        // Writing view like pages and news: title and texts in the main column,
        // save controls, date/time, link and image in the sidebar
        const view = document.getElementById('eventsEditorView');
        view.classList.add('nb-writing-view', 'nb-event-writing');
        const body = document.getElementById('eventEditorBody');
        const headerActions = view.querySelector('.editor-header-right');
        if (headerActions) view.querySelector('.editor-header').append(headerActions);
        body.innerHTML = `
            <div class="nb-writing-layout">
                <div class="nb-news-main nb-event-main"></div>
                <aside class="nb-page-sidebar" aria-label="${escapeHtml(t('events.writing_actions'))}">
                    <section class="nb-page-save">
                        <h3>${escapeHtml(t('events.writing_save'))}</h3>
                        <div class="nb-page-save-controls"></div>
                    </section>
                </aside>
            </div>`;
        if (headerActions) body.querySelector('.nb-page-save-controls').append(headerActions);
        renderEventFields(body.querySelector('.nb-event-main'), event, index, body.querySelector('.nb-page-sidebar'));
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

    function renderEventFields(container, eventObj, index, sidebar) {
        const prefix = `events.${index}`;
        // Without a sidebar (other callers) everything stays in one column
        const side = sidebar || container;
        const panel = (titleKey, html) => {
            const section = document.createElement('section');
            section.className = 'nb-page-panel';
            section.innerHTML = `<h3>${escapeHtml(t(titleKey))}</h3>${html}`;
            side.appendChild(section);
            return section;
        };
        const row = (path, type, labelKey, value) => `<div class="editor-form-row"><label for="ev-${index}-${path}">${escapeHtml(t(labelKey))}</label>
            <input type="${type}" id="ev-${index}-${path}" class="ce-input editor-input" data-event-path="${prefix}.${path}" value="${escapeHtml(value || '')}"></div>`;

        // Date and time
        panel('events.writing_when',
            row('date', 'date', 'events.start_date', eventObj.date) +
            row('time', 'time', 'events.start_time', eventObj.time) +
            row('end-date', 'date', 'events.end_date', eventObj['end-date']) +
            row('end-time', 'time', 'events.end_time', eventObj['end-time']));

        // Link
        panel('events.writing_link', row('url', 'url', 'events.url', eventObj.url));

        // Image with preview, like the news cover
        const imageSrc = eventObj.image ? (eventObj.image.startsWith('/') ? '..' + eventObj.image : eventObj.image) : '';
        const imgPanel = panel('events.image', `
            <div class="ce-image-preview"><img src="${escapeHtml(imageSrc)}" alt="" style="${eventObj.image ? '' : 'display:none;'}"></div>
            <div class="ce-image-input-row">
                <input type="text" class="ce-input editor-input" data-event-path="${prefix}.image" value="${escapeHtml(eventObj.image || '')}" aria-label="${escapeHtml(t('events.image'))}">
                <button type="button" class="btn btn-secondary btn-sm ce-browse-btn">${escapeHtml(t('btn.browse'))}</button>
            </div>`);
        const eventImgInput = imgPanel.querySelector('[data-event-path]');
        imgPanel.querySelector('.ce-browse-btn').addEventListener('click', function() {
            browseImageForField(eventImgInput, imgPanel.querySelector('.ce-image-preview'));
        });

        // Translatable fields — language tabs
        const langSection = document.createElement('div');
        langSection.className = 'ce-lang-section';
        const langCodes = Object.keys(SITE_LANGUAGES);
        const isMultiLang = langCodes.length > 1;

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
                fieldDiv.innerHTML = `<label class="ce-field-label">${escapeHtml(t('events.field_' + field))}</label>`;
                if (field === 'title') fieldDiv.classList.add('nb-page-title-field');

                if (isLong) {
                    const ta = document.createElement('textarea');
                    ta.className = 'ce-textarea';
                    ta.value = val;
                    ta.dataset.eventPath = `${prefix}.${field}.${code}`;
                    ta.rows = 8;
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

        // Language choice top right in the header, as in every editor; the panels hold each language's texts
        const filled = code => EVENT_TRANSLATABLE.some(field => String(eventObj[field]?.[code] || '').trim() !== '');
        placeLanguageSwitch(document.getElementById('eventsEditorView'), isMultiLang && renderLanguageSwitch({
                name: 'event-' + index,
                current: DEFAULT_LANG,
                languages: langCodes.map(code => ({ code, label: SITE_LANGUAGES[code] || code, exists: filled(code) })),
                onSelect(code) { langSection.querySelectorAll('.ce-lang-panel').forEach(p => { p.hidden = p.dataset.lang !== code; p.classList.toggle('active', p.dataset.lang === code); }); },
                onTranslate(button) { translateAdminLanguageSection(langSection, button); }
        }));
        container.appendChild(langSection);

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
        eventsTrashCount = (items || []).length;
        renderEventsFilterLinks(document.getElementById('eventsTrashFilterLinks'), true);
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
                tdDeletedAt.textContent = nbFormatDate(d, 'datetime');
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
