(function() {
    'use strict';

    if (window.NB_AI_FEATURES_ENABLED === false || window.NB_AI_COPILOT_AVAILABLE === false || window.NibblyAiCopilot) {
        return;
    }

    const tokenMeta = document.querySelector('meta[name="csrf-token"]');
    if (!tokenMeta || !tokenMeta.content) {
        return;
    }

    const contentPageMeta = document.querySelector('meta[name="content-page"]');
    const csrfToken = tokenMeta.content;
    const copilotMode = window.NB_AI_COPILOT_MODE === 'dashboard' ? 'dashboard' : 'frontend';
    let contentPage = contentPageMeta && contentPageMeta.content ? String(contentPageMeta.content).trim() : inferHostContentPage();
    const apiUrl = window.NB_ADMIN_API_URL || inferAdminApiUrl();
    const requestTimeoutMs = 45000;
    const imageRequestTimeoutMs = 300000;
    const imageJobPollMs = 12000;
    const imageJobRunning = new Set();
    const imageJobFinished = new Set();
    const imageJobNoticeStorageKey = 'nibbly.aiImageJobNotices.v1';
    const imageJobNoticeLimit = 80;
    let imageJobPollTimer = null;

    const state = {
        open: false,
        loading: false,
        loadingMessage: '',
        context: null,
        contextPromise: null,
        messages: [],
        proposals: [],
        changeDraft: null,
        contentDraft: null,
        createdContent: null,
        imageDraft: null,
        lastImageResult: null,
        lastApplied: null,
        lastInstruction: '',
        currentSessionId: '',
        historyItems: [],
        historyOpen: false,
        historyLoaded: false,
        historySaving: false,
        historyTimer: null,
        changeFormOpen: false,
        contentFormOpen: false,
        sessionExpired: false,
        selectedField: null,
        selectedFieldPath: '',
        selectedElement: null,
        lastFocusedBeforeOpen: null,
        modal: null,
        maximized: false
    };

    function escHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function renderInlineMarkdown(value) {
        let html = escHtml(value == null ? '' : String(value));
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*([^*\n][\s\S]*?[^*\n])\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__([^_\n][\s\S]*?[^_\n])__/g, '<strong>$1</strong>');
        html = html.replace(/(^|[^*])\*([^*\n][^*\n]*?[^*\n])\*(?!\*)/g, '$1<em>$2</em>');
        html = html.replace(/(^|[^_])_([^_\n][^_\n]*?[^_\n])_(?!_)/g, '$1<em>$2</em>');
        html = html.replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
        return html.replace(/\n/g, '<br>');
    }

    function label(key, fallback, params) {
        let text = (window.NB_LANG && window.NB_LANG[key]) || fallback || key;
        Object.entries(params || {}).forEach(([name, value]) => {
            text = text.replace(new RegExp('\\{' + name + '\\}', 'g'), String(value));
        });
        return text;
    }

    function readableFieldLabel(path) {
        const raw = String(path || '').trim();
        if (!raw) return label('copilot.unknown_field', 'selected field');
        const section = raw.match(/^sections\.(\d+)\.(.+)$/);
        if (section) {
            return label('copilot.section_field_label', 'Section {section} {field}', {
                section: Number(section[1]) + 1,
                field: section[2].replace(/[._-]+/g, ' ')
            });
        }
        return raw.replace(/[._-]+/g, ' ');
    }

    function truncateOptionLabel(value, limit) {
        const text = String(value || '').replace(/\s+/g, ' ').trim();
        const max = Number(limit || 72);
        if (text.length <= max) return text;
        return text.slice(0, Math.max(0, max - 1)).trimEnd() + '…';
    }

    function truncateTargetLabel(value, limit) {
        const text = String(value || '').replace(/\s+/g, ' ').trim();
        const max = Number(limit || 104);
        if (text.length <= max) return text;
        const match = text.match(/^(\[[^\]]+\]\s*)(.*?)(\s+-\s+[^-]+)$/);
        if (!match) return truncateOptionLabel(text, max);
        const prefix = match[1];
        const middle = match[2];
        const suffix = match[3];
        const room = max - prefix.length - suffix.length - 1;
        if (room < 12) return truncateOptionLabel(text, max);
        return prefix + truncateOptionLabel(middle, room) + suffix;
    }

    function currentFieldPreview(field) {
        if (!field) return '';
        return String(field.preview || '').trim();
    }

    function renderCurrentFieldPreview(field) {
        const content = currentFieldPreview(field);
        let body = label('copilot.current_field_select', 'Choose a target field to preview its current content.');
        if (field) {
            body = content || label('copilot.current_field_empty', 'This field is currently empty.');
        }
        return '<div class="nb-copilot-current-field" data-change-draft-current>' +
            '<span>' + escHtml(label('copilot.current_field_content', 'Current content')) + '</span>' +
            '<p' + (content ? '' : ' class="is-empty"') + '>' + escHtml(body) + '</p>' +
            '</div>';
    }

    function icon(name) {
        const icons = {
            history: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg>',
            newChat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>',
            trash: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6h14Z"/></svg>',
            maximize: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M16 3h3a2 2 0 0 1 2 2v3"/><path d="M8 21H5a2 2 0 0 1-2-2v-3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>',
            minimize: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3v3a2 2 0 0 1-2 2H3"/><path d="M16 3v3a2 2 0 0 0 2 2h3"/><path d="M8 21v-3a2 2 0 0 0-2-2H3"/><path d="M16 21v-3a2 2 0 0 1 2-2h3"/></svg>',
            close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>'
        };
        return icons[name] || '';
    }

    function inferAdminApiUrl() {
        const script = document.currentScript && document.currentScript.src ? document.currentScript.src : '';
        if (script) {
            try {
                const url = new URL(script, window.location.href);
                url.pathname = url.pathname.replace(/\/js\/ai-copilot\.js$/, '/admin/api.php');
                url.search = '';
                url.hash = '';
                return url.href;
            } catch (error) {
                // Fall through to the root-relative default.
            }
        }
        return '/admin/api.php';
    }

    function inferHostContentPage() {
        if (window.__cmsNewsPost && window.__cmsNewsPost.id) {
            return 'news:' + String(window.__cmsNewsPost.id).trim();
        }
        const newsPostEl = document.querySelector('[data-news-post]');
        if (newsPostEl && newsPostEl.getAttribute('data-news-post')) {
            return 'news:' + String(newsPostEl.getAttribute('data-news-post')).trim();
        }
        const editablePages = Array.from(document.querySelectorAll('[data-page]'))
            .map(element => String(element.getAttribute('data-page') || '').trim())
            .filter(Boolean);
        const uniquePages = editablePages.filter((page, index) => editablePages.indexOf(page) === index);
        if (uniquePages.length === 1) {
            return uniquePages[0];
        }
        return '';
    }

    function syncContentPageFromHost() {
        if (copilotMode !== 'dashboard' || typeof window.NB_AI_COPILOT_GET_CONTENT_PAGE !== 'function') {
            const next = contentPage || inferHostContentPage();
            if (next && next !== contentPage) {
                contentPage = next;
                state.context = null;
                state.contextPromise = null;
                state.selectedField = null;
                state.selectedFieldPath = '';
                clearSelectedElement();
            }
            return contentPage;
        }
        const next = String(window.NB_AI_COPILOT_GET_CONTENT_PAGE() || '').trim();
        if (next !== contentPage) {
            contentPage = next;
            state.context = null;
            state.contextPromise = null;
            state.selectedField = null;
            state.selectedFieldPath = '';
            clearSelectedElement();
        }
        return contentPage;
    }

    function assistantUiLanguage() {
        const raw = String(window.NB_AI_ASSISTANT_LANGUAGE || document.documentElement.lang || navigator.language || 'en').trim();
        const normalized = raw.replace('_', '-').match(/^[a-zA-Z]{2,3}(?:-[a-zA-Z]{2})?/);
        return normalized ? normalized[0] : 'en';
    }

    function post(action, payload, timeoutMs) {
        const form = new FormData();
        form.append('action', action);
        form.append('csrf_token', csrfToken);
        Object.entries(payload || {}).forEach(([key, value]) => {
            form.append(key, typeof value === 'string' ? value : JSON.stringify(value));
        });
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timeout = controller
            ? window.setTimeout(() => controller.abort(), timeoutMs || requestTimeoutMs)
            : null;
        return fetch(apiUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(async response => {
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || data.success === false) {
                if (data && data.session_expired) {
                    state.sessionExpired = true;
                    const expired = new Error(label('copilot.session_expired', 'Your session expired. Please log in again in the dashboard, then reload this page.'));
                    expired.sessionExpired = true;
                    throw expired;
                }
                throw new Error((data && data.message) || label('copilot.request_failed', 'AI Assistant request failed.'));
            }
            return data.data;
        }).catch(error => {
            if (error && error.name === 'AbortError') {
                if (action === 'ai-copilot-generate-image') {
                    throw new Error(label('copilot.image_request_timeout', 'Image generation timed out. No image preview was created.'));
                }
                throw new Error(label('copilot.request_timeout', 'AI Assistant request timed out. No change was applied.'));
            }
            throw error;
        }).finally(() => {
            if (timeout) window.clearTimeout(timeout);
        });
    }

    function supportsStreaming() {
        return typeof TextDecoder !== 'undefined'
            && typeof window.ReadableStream !== 'undefined'
            && typeof Response !== 'undefined';
    }

    // POST to an SSE action and surface text deltas as they arrive. Resolves
    // with the final "done" payload; throws on gate errors or stream errors.
    function postStream(action, payload, onDelta) {
        const form = new FormData();
        form.append('action', action);
        form.append('csrf_token', csrfToken);
        Object.entries(payload || {}).forEach(([key, value]) => {
            form.append(key, typeof value === 'string' ? value : JSON.stringify(value));
        });
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        let idleTimer = null;
        const armIdleTimeout = () => {
            if (!controller) return;
            if (idleTimer) window.clearTimeout(idleTimer);
            idleTimer = window.setTimeout(() => controller.abort(), 60000);
        };
        armIdleTimeout();
        return fetch(apiUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(response => {
            const contentType = String(response.headers.get('Content-Type') || '');
            if (!response.ok || contentType.indexOf('text/event-stream') === -1 || !response.body || !response.body.getReader) {
                return response.json().catch(() => null).then(data => {
                    if (data && data.success !== false && data.data) return data.data;
                    if (data && data.session_expired) {
                        state.sessionExpired = true;
                        const expired = new Error(label('copilot.session_expired', 'Your session expired. Please log in again in the dashboard, then reload this page.'));
                        expired.sessionExpired = true;
                        throw expired;
                    }
                    throw new Error((data && data.message) || label('copilot.request_failed', 'AI Assistant request failed.'));
                });
            }
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let donePayload = null;
            let streamError = null;
            const handleLine = line => {
                line = line.trim();
                if (!line || line.indexOf('data:') !== 0) return;
                let event = null;
                try { event = JSON.parse(line.slice(5).trim()); } catch (e) { return; }
                if (!event) return;
                if (typeof event.delta === 'string' && onDelta) onDelta(event.delta);
                if (event.error) streamError = new Error(event.error);
                if (event.done) donePayload = event;
            };
            const pump = () => reader.read().then(({ value, done }) => {
                armIdleTimeout();
                buffer += decoder.decode(value || new Uint8Array(), { stream: !done });
                let idx;
                while ((idx = buffer.indexOf('\n')) !== -1) {
                    handleLine(buffer.slice(0, idx));
                    buffer = buffer.slice(idx + 1);
                }
                if (!done) return pump();
                if (buffer) handleLine(buffer);
                if (streamError) throw streamError;
                return donePayload;
            });
            return pump();
        }).catch(error => {
            if (error && error.name === 'AbortError') {
                throw new Error(label('copilot.request_timeout', 'AI Assistant request timed out. No change was applied.'));
            }
            throw error;
        }).finally(() => {
            if (idleTimer) window.clearTimeout(idleTimer);
        });
    }

    function runImageJob(job) {
        if (!job || !job.id || imageJobRunning.has(job.id)) return;
        imageJobRunning.add(job.id);
        const form = new FormData();
        form.append('action', 'ai-image-job-run');
        form.append('job_id', job.id);
        form.append('csrf_token', csrfToken);
        fetch(apiUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            cache: 'no-store'
        }).catch(() => {
            // Polling will surface the persisted error state if the runner failed.
        }).finally(() => {
            imageJobRunning.delete(job.id);
        });
    }

    function imageJobNoticeKey(job) {
        if (!job || !job.id) return '';
        return [job.id, job.status || '', job.finishedAt || job.updatedAt || ''].join(':');
    }

    function readImageJobNotices() {
        try {
            const raw = window.localStorage ? window.localStorage.getItem(imageJobNoticeStorageKey) : '';
            const notices = raw ? JSON.parse(raw) : {};
            return notices && typeof notices === 'object' ? notices : {};
        } catch (error) {
            return {};
        }
    }

    function writeImageJobNotices(notices) {
        if (!window.localStorage) return;
        try {
            const entries = Object.entries(notices || {})
                .sort((a, b) => Number(b[1] || 0) - Number(a[1] || 0))
                .slice(0, imageJobNoticeLimit);
            window.localStorage.setItem(imageJobNoticeStorageKey, JSON.stringify(Object.fromEntries(entries)));
        } catch (error) {
            // Notification history is only used to avoid repeated UI messages.
        }
    }

    function imageJobNoticeWasShown(job) {
        const key = imageJobNoticeKey(job);
        if (!key) return false;
        return !!readImageJobNotices()[key];
    }

    function markImageJobNoticeShown(job) {
        const key = imageJobNoticeKey(job);
        if (!key) return;
        const notices = readImageJobNotices();
        notices[key] = Date.now();
        writeImageJobNotices(notices);
    }

    function handleImageJobFinished(job, activeJobId) {
        if (!job || !job.id) return;
        const noticeAlreadyShown = imageJobFinished.has(job.id) || imageJobNoticeWasShown(job);
        imageJobFinished.add(job.id);
        const isActiveJob = job.id === activeJobId;
        if (!isActiveJob || noticeAlreadyShown) {
            markImageJobNoticeShown(job);
            return;
        }
        if (job.status === 'success' && job.result && job.result.proposal) {
            if (job.result.context) state.context = job.result.context;
            state.imageDraft = null;
            state.proposals = [job.result.proposal];
            state.messages.push({ role: 'assistant', content: label('copilot.image_draft_ready', 'Generated image ready. Review the preview card before applying it.') });
        } else if (job.status === 'error') {
            state.messages.push({ role: 'assistant', content: job.error || label('copilot.image_request_failed', 'Image generation failed.') });
        }
        markImageJobNoticeShown(job);
        setLoading(false);
        renderMessages();
    }

    function pollImageJobs(activeJobId) {
        const url = apiUrl + '?action=ai-image-jobs&open_only=0&limit=30&csrf_token=' + encodeURIComponent(csrfToken);
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(response => response.json())
            .then(result => {
                if (!result || result.success === false) throw new Error(result && result.message ? result.message : 'Error');
                const jobs = Array.isArray(result.data && result.data.jobs) ? result.data.jobs : [];
                const openJobs = jobs.filter(job => job && (job.status === 'queued' || job.status === 'running'));
                const runningJobs = openJobs.filter(job => job.status === 'running');
                const queuedJobs = openJobs.filter(job => job.status === 'queued');
                if (!runningJobs.length && queuedJobs.length) {
                    runImageJob(queuedJobs[queuedJobs.length - 1]);
                }
                jobs
                    .filter(job => job && (job.status === 'success' || job.status === 'error'))
                    .forEach(job => handleImageJobFinished(job, activeJobId));
                if (openJobs.length) {
                    imageJobPollTimer = window.setTimeout(() => pollImageJobs(activeJobId || ''), imageJobPollMs);
                } else {
                    imageJobPollTimer = null;
                }
            })
            .catch(() => {
                imageJobPollTimer = window.setTimeout(() => pollImageJobs(activeJobId || ''), 15000);
            });
    }

    function startImageJobPolling(activeJobId) {
        if (imageJobPollTimer) {
            window.clearTimeout(imageJobPollTimer);
            imageJobPollTimer = null;
        }
        pollImageJobs(activeJobId || '');
    }

    function setLoading(isLoading, messageKey, fallback) {
        state.loading = isLoading;
        state.loadingMessage = isLoading ? label(messageKey || 'copilot.working', fallback || 'AI Assistant is working...') : '';
    }

    function renderMessages() {
        const list = document.querySelector('[data-copilot-messages]');
        if (!list) return;
        const intro = state.context ? contextIntro(state.context) : '';
        const messages = state.messages.map(message => {
            return '<div class="nb-copilot-msg nb-copilot-msg--' + escHtml(message.role) + '">' +
                '<div class="nb-copilot-msg__bubble">' + renderInlineMarkdown(message.content) + '</div>' +
                '</div>';
        }).join('');
        const loading = state.loading
            ? '<div class="nb-copilot-msg nb-copilot-msg--assistant"><div class="nb-copilot-msg__bubble nb-copilot-msg__bubble--loading"><span class="nb-copilot-spinner" aria-hidden="true"></span>' + escHtml(state.loadingMessage || label('copilot.working', 'AI Assistant is working...')) + '</div></div>'
            : '';
        list.innerHTML = intro + messages + renderContentDraft() + renderCreatedContent() + renderLastImageResult() + renderUndo() + renderProposals() + loading;
        list.setAttribute('aria-busy', state.loading ? 'true' : 'false');
        list.scrollTop = list.scrollHeight;
        updateActionAvailability();
        renderModal();
        persistSession();
    }

    function renderChangeDraftForm() {
        const draft = state.changeDraft || {};
        const instruction = String(draft.instruction || '');
        const selected = draft.field || state.selectedField || null;
        const selectedRef = selected ? String(selected.id || selected.path || '') : '';
        const targetFields = getChangeTargetFields();
        const fieldOptions = '<option value="">' + escHtml(label('copilot.choose_target_field_placeholder', 'Select a field...')) + '</option>' + targetFields.map(field => {
            const ref = String(field.id || field.path || '');
            const selectedAttr = ref && ref === selectedRef ? ' selected' : '';
            return '<option value="' + escHtml(ref) + '"' + selectedAttr + '>' + escHtml(changeTargetLabel(field)) + '</option>';
        }).join('');
        return '<div class="nb-copilot-modal-backdrop" role="presentation">' +
            '<section class="nb-copilot-modal nb-copilot-modal--wide" role="dialog" aria-modal="true" aria-labelledby="nb-copilot-change-modal-title">' +
                '<header><strong id="nb-copilot-change-modal-title">' + escHtml(label('copilot.change_details_title', 'Prepare change')) + '</strong><span>' + escHtml(label('copilot.change_details_hint', 'Describe the change. The Assistant will create a reviewable preview before anything is applied.')) + '</span></header>' +
                '<form class="nb-copilot-change-form">' +
                    '<div class="nb-copilot-modal__body"><div class="nb-copilot-content-fields">' +
                        '<label><span>' + escHtml(label('copilot.choose_target_field', 'Choose target field')) + ' *</span><select data-change-draft-field>' + fieldOptions + '</select></label>' +
                        '<p class="nb-copilot-field-hint">' + escHtml(label('copilot.choose_target_field_hint', 'You can also click an editable element on the page before opening this dialog.')) + '</p>' +
                        renderCurrentFieldPreview(selected) +
                        '<label><span>' + escHtml(label('copilot.change_instruction', 'Change instruction')) + ' *</span><textarea rows="5" data-change-draft-instruction placeholder="' + escHtml(label('copilot.change_instruction_placeholder', 'Describe what should change on this page...')) + '">' + escHtml(instruction) + '</textarea></label>' +
                    '</div></div>' +
                    '<footer>' +
                        '<button type="button" class="nb-copilot-change-cancel">' + escHtml(label('copilot.modal_cancel', 'Cancel')) + '</button>' +
                        '<button type="submit" class="nb-copilot-change-submit">' + escHtml(label('copilot.prepare_preview', 'Prepare preview')) + '</button>' +
                    '</footer>' +
                '</form>' +
            '</section>' +
        '</div>';
    }

    function renderContentDraft() {
        const draftWrap = state.contentDraft;
        if (!draftWrap || !draftWrap.draft) return '';
        const draft = draftWrap.draft;
        const fields = Object.entries(draft.draft || {}).map(([key, value]) => {
            return '<div><span>' + escHtml(key) + '</span><p>' + escHtml(value == null ? '' : String(value)).replace(/\n/g, '<br>') + '</p></div>';
        }).join('');
        const missing = Array.isArray(draft.missing) && draft.missing.length
            ? '<p class="nb-copilot-create__missing">' + escHtml(label('copilot.missing', 'Missing: {fields}', { fields: draft.missing.join(', ') })) + '</p>'
            : '';
        const action = draft.canCreate
            ? '<button type="button" class="nb-copilot-create-btn">' + escHtml(label('copilot.create_draft', 'Create draft')) + '</button>'
            : '<button type="button" class="nb-copilot-details-btn">' + escHtml(label('copilot.complete_details', 'Complete details')) + '</button>';
        return '<section class="nb-copilot-create" aria-label="Draft content preview">' +
            '<header><strong>' + escHtml(label('copilot.content_draft_title', '{type} draft', { type: (draft.contentType || 'content').toUpperCase() })) + '</strong><span>' + escHtml(draft.canCreate ? label('copilot.ready_to_create', 'Ready to create') : label('copilot.needs_details', 'Needs details')) + '</span></header>' +
            '<div class="nb-copilot-create__fields">' + fields + '</div>' +
            missing +
            '<footer>' + action + '</footer>' +
        '</section>';
    }

    function renderCreatedContent() {
        const created = state.createdContent;
        if (!created || !created.id) return '';
        const status = created.private
            ? label('copilot.private_draft', 'Private draft')
            : (created.hidden ? label('copilot.hidden_draft', 'Hidden draft') : label('copilot.draft_created', 'Draft created'));
        const adminLink = created.adminUrl
            ? '<a href="' + escHtml(created.adminUrl) + '">' + escHtml(label('copilot.open_dashboard', 'Open in dashboard')) + '</a>'
            : '';
        const publicLink = created.publicUrl
            ? '<a href="' + escHtml(created.publicUrl) + '">' + escHtml(label('copilot.open_preview', 'Open preview URL')) + '</a>'
            : '';
        const publishBtn = created.publishable && (created.private || created.hidden)
            ? '<button type="button" class="nb-copilot-publish-btn">' + escHtml(label('copilot.publish', 'Publish')) + '</button>'
            : '';
        return '<section class="nb-copilot-created" aria-label="Created content">' +
            '<header><strong>' + escHtml(label('copilot.created_title', '{type} created', { type: (created.contentType || 'content').toUpperCase() })) + '</strong><span>' + escHtml(status) + '</span></header>' +
            '<p>' + escHtml(created.id) + '</p>' +
            '<footer>' + adminLink + publicLink + publishBtn + '</footer>' +
        '</section>';
    }

    function renderLastImageResult() {
        const image = state.lastImageResult;
        if (!image || !image.path) return '';
        const alt = image.alt
            ? '<span>' + escHtml(label('copilot.alt_text', 'Alt text')) + ': ' + escHtml(image.alt) + '</span>'
            : '';
        const prompt = image.prompt
            ? '<span>' + escHtml(label('copilot.image_prompt', 'Prompt')) + ': ' + escHtml(nibblyShortSummary(image.prompt)) + '</span>'
            : '';
        const src = image.path.charAt(0) === '/' ? image.path : '/' + image.path;
        return '<section class="nb-copilot-image-result" aria-label="' + escHtml(label('copilot.generated_image', 'Generated image')) + '">' +
            '<img src="' + escHtml(src) + '" alt="">' +
            '<div>' +
                '<strong>' + escHtml(label('copilot.generated_image_applied', 'Generated image applied')) + '</strong>' +
                '<span>' + escHtml(image.label || image.field || '') + '</span>' +
                prompt +
                alt +
            '</div>' +
            '</section>';
    }

    function imageFieldPreviewSrc(field) {
        if (!field) return '';
        const candidates = [];
        const domSrc = imageFieldDomSrc(field.path);
        if (domSrc) candidates.push(domSrc);
        if (field.preview) candidates.push(field.preview);
        if (field.value) candidates.push(field.value);
        for (const candidate of candidates) {
            const src = normalizeImagePreviewSrc(candidate);
            if (src) return src;
        }
        return '';
    }

    function imageFieldDomSrc(path) {
        if (!path) return '';
        try {
            const element = document.querySelector(fieldSelector(path));
            if (!element) return '';
            const img = element.matches('img') ? element : element.querySelector('img');
            return img ? (img.currentSrc || img.src || img.getAttribute('src') || '') : '';
        } catch (error) {
            return '';
        }
    }

    function normalizeImagePreviewSrc(value) {
        const src = String(value || '').trim();
        if (!src) return '';
        if (/^(https?:|data:image\/|blob:)/i.test(src)) return src;
        if (/^\/assets\/images\//i.test(src)) return src;
        if (/^assets\/images\//i.test(src)) return '/' + src;
        return '';
    }

    function renderImageDraftOptions() {
        const draft = state.imageDraft;
        if (!draft || !Array.isArray(draft.fields) || !draft.fields.length) return '';
        const selectedFieldId = draft.fieldId || (draft.fields[0].id || draft.fields[0].path);
        const fieldButtons = draft.fields.map(field => {
            const fieldId = field.id || field.path;
            const active = fieldId === selectedFieldId ? ' is-active' : '';
            const previewSrc = imageFieldPreviewSrc(field);
            const preview = previewSrc
                ? '<img src="' + escHtml(previewSrc) + '" alt="" loading="lazy" referrerpolicy="no-referrer">'
                : '<span>' + escHtml(field.label || field.path) + '</span>';
            return '<button type="button" class="nb-copilot-image-field' + active + '" data-image-draft-field="' + escHtml(fieldId) + '">' +
                preview +
                '<strong>' + escHtml(field.label || field.path) + '</strong>' +
            '</button>';
        }).join('');
        const modeButton = mode => {
            const active = draft.mode === mode ? ' is-active' : '';
            const key = mode === 'edit' ? 'copilot.image_mode_edit' : 'copilot.image_mode_generate';
            const fallback = mode === 'edit' ? 'With reference image' : 'From prompt';
            const pressed = draft.mode === mode ? 'true' : 'false';
            return '<button type="button" class="nb-copilot-image-mode' + active + '" data-image-draft-mode="' + mode + '" aria-pressed="' + pressed + '">' + escHtml(label(key, fallback)) + '</button>';
        };
        const optionSelect = (name, labelKey, fallback, options) => {
            const current = draft.options && draft.options[name] != null ? String(draft.options[name]) : '';
            const optionHtml = options.map(option => {
                const selected = String(option.value) === current ? ' selected' : '';
                return '<option value="' + escHtml(option.value) + '"' + selected + '>' + escHtml(option.label) + '</option>';
            }).join('');
            return '<label><span>' + escHtml(label(labelKey, fallback)) + '</span><select data-image-draft-option="' + escHtml(name) + '">' + optionHtml + '</select></label>';
        };
        const promptHtml = '<label class="nb-copilot-image-prompt">' +
            '<span>' + escHtml(label('copilot.image_prompt', 'Prompt')) + '</span>' +
            '<textarea rows="3" data-image-draft-prompt placeholder="' + escHtml(label('copilot.image_prompt_placeholder', 'Describe the image to generate...')) + '">' + escHtml(draft.instruction || '') + '</textarea>' +
        '</label>';
        const optionsHtml = '<div class="nb-copilot-image-settings">' +
            optionSelect('size', 'copilot.image_size', 'Size', [
                { value: 'auto', label: label('copilot.image_size_auto', 'Auto') },
                { value: '1024x1024', label: label('copilot.image_size_square', 'Square') },
                { value: '1536x1024', label: label('copilot.image_size_landscape', 'Landscape') },
                { value: '1024x1536', label: label('copilot.image_size_portrait', 'Portrait') }
            ]) +
            optionSelect('count', 'copilot.image_count', 'Images', [
                { value: '1', label: '1' },
                { value: '2', label: '2' },
                { value: '3', label: '3' },
                { value: '4', label: '4' }
            ]) +
            optionSelect('outputFormat', 'copilot.image_format', 'Format', [
                { value: 'webp', label: 'WebP' },
                { value: 'png', label: 'PNG' },
                { value: 'jpeg', label: 'JPEG' }
            ]) +
            optionSelect('quality', 'copilot.image_quality', 'Quality', [
                { value: 'auto', label: label('copilot.image_quality_auto', 'Auto') },
                { value: 'low', label: label('copilot.image_quality_low', 'Low') },
                { value: 'medium', label: label('copilot.image_quality_medium', 'Medium') },
                { value: 'high', label: label('copilot.image_quality_high', 'High') }
            ]) +
        '</div>';
        return '<section class="nb-copilot-image-options" aria-label="Image draft options">' +
            '<div class="nb-copilot-image-fields">' + fieldButtons + '</div>' +
            '<div class="nb-copilot-image-mode-group"><span>' + escHtml(label('copilot.image_mode_label', 'Mode')) + '</span><div class="nb-copilot-image-modes" role="group" aria-label="' + escHtml(label('copilot.image_mode_label', 'Mode')) + '">' + modeButton('generate') + modeButton('edit') + '</div></div>' +
            promptHtml +
            optionsHtml +
            '<footer><button type="button" class="nb-copilot-image-run">' + escHtml(label('copilot.image_generate_action', 'Generate image')) + '</button></footer>' +
        '</section>';
    }

    function renderUndo() {
        const undo = state.lastApplied && state.lastApplied.undo;
        if (!undo || !undo.backup) return '';
        const undoDetail = state.lastApplied.label || readableFieldLabel(state.lastApplied.path || undo.path || '');
        return '<section class="nb-copilot-undo" aria-label="Undo last AI change">' +
            '<div><strong>' + escHtml(label('copilot.last_change', 'Last AI change applied')) + '</strong><span>' + escHtml(label('copilot.undo_detail', 'Changed: {field}', { field: undoDetail })) + '</span></div>' +
            '<button type="button" class="nb-copilot-undo-btn">' + escHtml(label('copilot.undo', 'Undo')) + '</button>' +
        '</section>';
    }

    function renderProposals() {
        if (!state.proposals.length) return '';
        const summary = state.proposals.length > 1
            ? '<section class="nb-copilot-proposal-summary" aria-label="AI suggestion summary">' +
                '<header><strong>' + escHtml(label('copilot.proposal_summary_title', 'Planned changes')) + '</strong><span>' + escHtml(label('copilot.proposal_summary_hint', '{count} changes will be reviewed together.', { count: state.proposals.length })) + '</span></header>' +
                '<ul>' + state.proposals.map(proposal => {
                    return '<li><strong>' + escHtml(proposal.label || proposal.path) + '</strong><span>' + escHtml(proposalSummaryText(proposal)) + '</span></li>';
                }).join('') + '</ul>' +
                '<footer><button type="button" class="nb-copilot-apply-all">' + escHtml(label('copilot.apply_all', 'Apply all')) + '</button></footer>' +
            '</section>'
            : '';
        const cards = state.proposals.map((proposal, index) => {
            const isImage = proposal.type === 'image';
            const currentHtml = renderProposalValue(proposal, proposal.current);
            const draftHtml = renderProposalValue(proposal, proposal.preview || proposal.value || '');
            const variantsHtml = isImage && Array.isArray(proposal.paths) && proposal.paths.length > 1
                ? '<div class="nb-copilot-variants">' + proposal.paths.map((path, variantIndex) => {
                    const active = path === proposal.value ? ' is-active' : '';
                    return '<button type="button" class="nb-copilot-variant' + active + '" data-proposal-variant="' + index + ':' + variantIndex + '"><img src="' + escHtml(path) + '" alt=""></button>';
                }).join('') + '</div>'
                : '';
            const altHtml = isImage && proposal.altValue
                ? '<label class="nb-copilot-alt"><span>' + escHtml(label('copilot.alt_text', 'Alt text')) + '</span><input type="text" value="' + escHtml(proposal.altValue) + '" data-proposal-alt="' + index + '"></label>'
                : '';
            return '<article class="nb-copilot-proposal">' +
                '<header><strong>' + escHtml(proposal.label || proposal.path) + '</strong><span>' + escHtml(proposal.type || 'field') + '</span></header>' +
                '<div class="nb-copilot-proposal__diff">' +
                    '<div><span>' + escHtml(label('copilot.current', 'Current')) + '</span><p>' + currentHtml + '</p></div>' +
                    '<div><span>' + escHtml(label('copilot.draft', 'Draft')) + '</span><p>' + draftHtml + '</p></div>' +
                '</div>' +
                variantsHtml +
                altHtml +
                (proposal.reason ? '<p class="nb-copilot-proposal__reason">' + escHtml(proposal.reason) + '</p>' : '') +
                '<footer>' +
                    '<span>' + escHtml(label('copilot.review_backup', 'Review before applying. A backup is created server-side.')) + '</span>' +
                    '<div class="nb-copilot-proposal__actions">' +
                        '<button type="button" class="nb-copilot-copy" data-proposal-copy="' + index + '">' + escHtml(label('copilot.copy', 'Copy')) + '</button>' +
                        '<button type="button" class="nb-copilot-apply" data-proposal-index="' + index + '">' + escHtml(label('copilot.apply', 'Apply')) + '</button>' +
                    '</div>' +
                '</footer>' +
            '</article>';
        }).join('');
        return '<section class="nb-copilot-proposals" aria-label="Draft AI suggestions">' + summary + cards + '</section>';
    }

    function proposalSummaryText(proposal) {
        if (proposal.type === 'visibility') {
            return String(proposal.preview || proposal.value || '').toLowerCase() === 'hidden' || String(proposal.value || '').toLowerCase() === 'hide'
                ? label('copilot.summary_hide', 'Hide field')
                : label('copilot.summary_show', 'Show field');
        }
        if (proposal.type === 'image') {
            return label('copilot.summary_image', 'Replace image');
        }
        const value = proposal.preview || proposal.value || '';
        return nibblyShortSummary(value);
    }

    function nibblyShortSummary(value) {
        const text = String(value == null ? '' : value).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        return text.length > 90 ? text.slice(0, 87) + '...' : text;
    }

    function renderProposalValue(proposal, value) {
        if (proposal.type === 'image' && value) {
            return '<img class="nb-copilot-proposal__img" src="' + escHtml(value) + '" alt="">';
        }
        if (proposal.type === 'boolean') {
            const boolText = String(value).toLowerCase() === 'true' || value === true ? 'true' : 'false';
            return '<span class="nb-copilot-bool nb-copilot-bool--' + boolText + '">' + boolText + '</span>';
        }
        if (proposal.type === 'visibility') {
            const stateText = String(value || '').toLowerCase() === 'hidden' || String(value || '').toLowerCase() === 'hide' ? 'hidden' : 'visible';
            return '<span class="nb-copilot-option">' + escHtml(stateText) + '</span>';
        }
        if (proposal.type === 'select') {
            return '<span class="nb-copilot-option">' + escHtml(value || '') + '</span>';
        }
        return escHtml(value || '').replace(/\n/g, '<br>');
    }

    function contextIntro(context) {
        if (!context.ai || !context.ai.enabled || !context.ai.hasApiKey || !context.ai.features.backendAssistant) {
            return '<div class="nb-copilot-status">' + escHtml(label('copilot.not_ready', 'AI is not ready yet. Check AI Settings in the dashboard.')) + '</div>';
        }
        const page = context.page || {};
        const title = page.exists
            ? (page.title || page.contentPage || label('copilot.current_page', 'current page'))
            : (copilotMode === 'dashboard' ? label('copilot.dashboard_context', 'Dashboard') : label('copilot.current_page', 'current page'));
        const fieldCount = Array.isArray(page.fields) ? page.fields.length : 0;
        const contentTypes = Array.isArray(context.contentTypes)
            ? context.contentTypes.filter(type => type.available).map(type => label(type.labelKey || '', type.label || type.id || '')).slice(0, 4).join(', ')
            : '';
        const selected = state.selectedField
            ? '<span class="nb-copilot-context__selected">' + escHtml(label('copilot.selected_field', 'Selected field: {field}', { field: state.selectedField.label || state.selectedField.path })) + '</span>'
            : '';
        return '<div class="nb-copilot-context">' +
            '<strong>' + escHtml(title) + '</strong>' +
            '<span>' + escHtml((page.exists ? label('copilot.field_hints', '{count} editable field hints found', { count: fieldCount }) : label('copilot.dashboard_hint', 'General site and dashboard assistance')) + (contentTypes ? ' - ' + contentTypes : '')) + '</span>' +
            selected +
            '</div>';
    }

    function renderShell() {
        const root = document.createElement('div');
        root.className = 'nb-copilot';
        root.innerHTML = '' +
            '<button class="nb-copilot-toggle" type="button" aria-expanded="false" aria-controls="nb-copilot-panel" title="' + escHtml(label('copilot.title', 'AI Assistant')) + '">' +
                '<svg viewBox="0 0 60.02 54.01" aria-hidden="true"><path d="M24.05,16.95c0.67,0.21,1.32,0.47,1.96,0.75c0.17-8.68,7.27-15.69,15.98-15.69s16,7.18,16,16c0,3.72-1.28,7.35-3.67,10.2c-0.28,0.33-0.31,0.81-0.07,1.17l2.62,4.13l-6.66-1.48c-0.23-0.05-0.48-0.02-0.69,0.09C47.21,33.34,44.62,34,42,34c-1.45,0-2.85-0.21-4.18-0.57c0.09,0.69,0.14,1.39,0.16,2.1C39.28,35.83,40.62,36,42,36c2.82,0,5.62-0.67,8.13-1.94l8.66,1.92C58.86,36,58.93,36,59.01,36c0.32,0,0.63-0.16,0.82-0.43c0.23-0.33,0.24-0.77,0.03-1.11l-3.52-5.56c2.38-3.12,3.68-6.96,3.68-10.9c0-9.93-8.07-18-18-18S24.63,7.51,24.07,16.94L24.05,16.95z M34,11.01h16v2H34V11.01z M34,17.01h16v2H34V17.01z M38,23.01h12v2H38V23.01z M13.69,32.01h-0.46l-1.05,4h2.57L13.69,32.01z M9.54,51.9c2.52,1.35,5.4,2.11,8.46,2.11c9.94,0,18-8.06,18-18s-8.06-18-18-18s-18,8.06-18,18c0,4.36,1.55,8.36,4.13,11.47L0,54.01L9.54,51.9z M20.46,40.01h2.5v-8h-2.5v-2h7v2h-2.5v8h2.5v2h-7V40.01z M11.49,30.76c0.12-0.44,0.51-0.75,0.97-0.75h2c0.45,0,0.85,0.31,0.97,0.75l2.96,11.25h-2.07l-1.05-4h-3.62l-1.05,4H8.53L11.49,30.76z"/></svg>' +
            '</button>' +
            '<section class="nb-copilot-panel" id="nb-copilot-panel" role="dialog" aria-modal="false" aria-label="' + escHtml(label('copilot.title', 'AI Assistant')) + '" hidden>' +
                '<header class="nb-copilot-header">' +
                    '<div class="nb-copilot-header__top">' +
                        '<strong>' + escHtml(label('copilot.title', 'AI Assistant')) + '</strong>' +
                        '<button class="nb-copilot-close" type="button" aria-label="' + escHtml(label('copilot.close', 'Close AI Assistant')) + '">' + icon('close') + '</button>' +
                    '</div>' +
                    '<div class="nb-copilot-header__actions">' +
                        '<button class="nb-copilot-history-btn" type="button" aria-label="' + escHtml(label('copilot.history', 'Chat history')) + '" title="' + escHtml(label('copilot.history', 'Chat history')) + '">' + icon('history') + '</button>' +
                        '<button class="nb-copilot-new-btn" type="button" aria-label="' + escHtml(label('copilot.new_chat', 'New chat')) + '" title="' + escHtml(label('copilot.new_chat', 'New chat')) + '">' + icon('newChat') + '</button>' +
                        '<button class="nb-copilot-delete-btn" type="button" aria-label="' + escHtml(label('copilot.delete_chat', 'Delete current chat')) + '" title="' + escHtml(label('copilot.delete_chat', 'Delete current chat')) + '">' + icon('trash') + '</button>' +
                        '<button class="nb-copilot-maximize" type="button" aria-label="' + escHtml(label('copilot.maximize', 'Maximize AI Assistant')) + '" title="' + escHtml(label('copilot.maximize', 'Maximize AI Assistant')) + '">' + icon('maximize') + '</button>' +
                    '</div>' +
                '</header>' +
                '<div class="nb-copilot-messages" data-copilot-messages aria-live="polite" aria-busy="false"></div>' +
                '<form class="nb-copilot-form" data-copilot-form>' +
                    '<textarea class="nb-copilot-input" rows="2" placeholder="' + escHtml(label('copilot.placeholder', 'Ask how to change this page...')) + '" aria-label="' + escHtml(label('copilot.ask', 'Ask AI Assistant')) + '"></textarea>' +
                    '<div class="nb-copilot-actions">' +
                        '<button class="nb-copilot-draft" type="button" disabled title="' + escHtml(label('copilot.draft_changes_hint', 'Open a guided dialog to prepare a reviewed page change.')) + '">' + escHtml(label('copilot.draft_changes', 'Draft changes')) + '</button>' +
                        '<button class="nb-copilot-content" type="button" disabled title="' + escHtml(label('copilot.draft_content_hint', 'Open a guided dialog to prepare a new page, news post, event, or appointment.')) + '">' + escHtml(label('copilot.draft_content', 'Draft content')) + '</button>' +
                        '<button class="nb-copilot-image" type="button" disabled hidden title="' + escHtml(label('copilot.draft_image_hint', 'Open image options and enter or refine the image prompt.')) + '">' + escHtml(label('copilot.draft_image', 'Image')) + '</button>' +
                        '<button class="nb-copilot-send" type="submit">' + escHtml(label('copilot.send', 'Send')) + '</button>' +
                    '</div>' +
                '</form>' +
        '</section>' +
            '<div class="nb-copilot-modal-host" data-copilot-modal-host></div>';
        document.body.appendChild(root);
        syncMaximizedState(root);
        bind(root);
    }

    function renderModal() {
        const host = document.querySelector('[data-copilot-modal-host]');
        if (!host) return;
        const modal = state.modal;
        if (state.changeFormOpen) {
            host.innerHTML = renderChangeDraftForm();
            const first = host.querySelector('[data-change-draft-instruction]');
            if (first) setTimeout(() => first.focus(), 0);
            return;
        }
        if (state.contentFormOpen && state.contentDraft && state.contentDraft.draft) {
            host.innerHTML = renderContentDraftForm();
            const first = host.querySelector('[data-content-draft-field]');
            if (first) setTimeout(() => first.focus(), 0);
            return;
        }
        if (modal) {
            const items = Array.isArray(modal.items) && modal.items.length
                ? '<ul>' + modal.items.map(item => '<li>' + escHtml(item) + '</li>').join('') + '</ul>'
                : '';
            host.innerHTML = '<div class="nb-copilot-modal-backdrop" role="presentation">' +
                '<section class="nb-copilot-modal" role="dialog" aria-modal="true" aria-labelledby="nb-copilot-modal-title">' +
                    '<header><strong id="nb-copilot-modal-title">' + escHtml(modal.title || label('copilot.confirm_title', 'Confirm action')) + '</strong></header>' +
                    '<div class="nb-copilot-modal__body">' + (modal.body ? '<p>' + escHtml(modal.body) + '</p>' : '') + items + '</div>' +
                    '<footer>' +
                        '<button type="button" class="nb-copilot-modal-cancel">' + escHtml(modal.cancelLabel || label('copilot.modal_cancel', 'Cancel')) + '</button>' +
                        '<button type="button" class="nb-copilot-modal-confirm">' + escHtml(modal.confirmLabel || label('copilot.modal_confirm', 'Confirm')) + '</button>' +
                    '</footer>' +
                '</section>' +
            '</div>';
            const confirm = host.querySelector('.nb-copilot-modal-confirm');
            if (confirm) confirm.focus();
            return;
        }
        if (state.imageDraft) {
            host.innerHTML = '<div class="nb-copilot-modal-backdrop" role="presentation">' +
                '<section class="nb-copilot-modal nb-copilot-modal--wide nb-copilot-modal--image" role="dialog" aria-modal="true" aria-labelledby="nb-copilot-image-modal-title">' +
                    '<header><strong id="nb-copilot-image-modal-title">' + escHtml(label('copilot.image_options_title', 'Image options')) + '</strong><span>' + escHtml(label('copilot.image_options_hint', 'Choose the target image and generation mode.')) + '</span></header>' +
                    '<div class="nb-copilot-modal__body nb-copilot-modal__body--flush">' + renderImageDraftOptions() + '</div>' +
                    '<footer>' +
                        '<button type="button" class="nb-copilot-image-cancel">' + escHtml(label('copilot.modal_cancel', 'Cancel')) + '</button>' +
                    '</footer>' +
                '</section>' +
            '</div>';
            const prompt = host.querySelector('[data-image-draft-prompt]');
            if (prompt) prompt.focus();
            return;
        }
        if (state.historyOpen) {
            host.innerHTML = renderHistoryModal();
            return;
        }
        host.innerHTML = '';
    }

    function contentDraftFieldConfig(type) {
        if (type === 'event') {
            return [
                { key: 'title', kind: 'text', required: true },
                { key: 'lang', kind: 'lang', required: true },
                { key: 'date', kind: 'date', required: true },
                { key: 'time', kind: 'time', required: true },
                { key: 'location', kind: 'text', required: true },
                { key: 'description', kind: 'textarea', required: false },
                { key: 'admission', kind: 'text', required: false },
                { key: 'url', kind: 'text', required: false }
            ];
        }
        if (type === 'news') {
            return [
                { key: 'title', kind: 'text', required: true },
                { key: 'slug', kind: 'text', required: true },
                { key: 'lang', kind: 'lang', required: true },
                { key: 'date', kind: 'date', required: true },
                { key: 'excerpt', kind: 'textarea', required: false },
                { key: 'content', kind: 'textarea', required: true },
                { key: 'author', kind: 'text', required: false }
            ];
        }
        return [
            { key: 'title', kind: 'text', required: true },
            { key: 'slug', kind: 'text', required: true },
            { key: 'lang', kind: 'lang', required: true },
            { key: 'description', kind: 'textarea', required: false },
            { key: 'content', kind: 'textarea', required: true }
        ];
    }

    function renderContentDraftForm() {
        const draft = state.contentDraft && state.contentDraft.draft ? state.contentDraft.draft : null;
        const type = draft ? (draft.contentType || 'page') : 'page';
        const values = draft && draft.draft ? draft.draft : {};
        const missing = Array.isArray(draft && draft.missing) ? draft.missing : [];
        const languageOptions = state.context && state.context.site && state.context.site.languages
            ? Object.entries(state.context.site.languages)
            : [];
        const fields = contentDraftFieldConfig(type).map(field => {
            const value = values[field.key] == null ? '' : String(values[field.key]);
            const required = field.required || missing.includes(field.key);
            const labelText = field.key + (required ? ' *' : '');
            if (field.kind === 'textarea') {
                return '<label><span>' + escHtml(labelText) + '</span><textarea rows="4" data-content-draft-field="' + escHtml(field.key) + '">' + escHtml(value) + '</textarea></label>';
            }
            if (field.kind === 'lang') {
                const options = (languageOptions.length ? languageOptions : [['en', 'English']]).map(([code, name]) => {
                    return '<option value="' + escHtml(code) + '"' + (String(code) === value ? ' selected' : '') + '>' + escHtml(String(name || code)) + '</option>';
                }).join('');
                return '<label><span>' + escHtml(labelText) + '</span><select data-content-draft-field="' + escHtml(field.key) + '">' + options + '</select></label>';
            }
            return '<label><span>' + escHtml(labelText) + '</span><input type="' + escHtml(field.kind) + '" value="' + escHtml(value) + '" data-content-draft-field="' + escHtml(field.key) + '"></label>';
        }).join('');
        const typeOptions = [
            ['page', label('copilot.content_type_page', 'Page')],
            ['news', label('copilot.content_type_news', 'News post')],
            ['event', label('copilot.content_type_event', 'Event / appointment')]
        ].map(([value, text]) => '<option value="' + escHtml(value) + '"' + (value === type ? ' selected' : '') + '>' + escHtml(text) + '</option>').join('');
        return '<div class="nb-copilot-modal-backdrop" role="presentation">' +
            '<section class="nb-copilot-modal nb-copilot-modal--wide" role="dialog" aria-modal="true" aria-labelledby="nb-copilot-content-modal-title">' +
                '<header><strong id="nb-copilot-content-modal-title">' + escHtml(label('copilot.complete_details_title', 'Complete content details')) + '</strong></header>' +
                '<form class="nb-copilot-content-form">' +
                    '<div class="nb-copilot-modal__body"><div class="nb-copilot-content-fields">' +
                        '<label><span>' + escHtml(label('copilot.content_type', 'Content type')) + '</span><select data-content-draft-type>' + typeOptions + '</select></label>' +
                        fields +
                    '</div></div>' +
                    '<footer>' +
                        '<button type="button" class="nb-copilot-content-cancel">' + escHtml(label('copilot.modal_cancel', 'Cancel')) + '</button>' +
                        '<button type="submit" class="nb-copilot-content-submit">' + escHtml(label('copilot.update_draft', 'Update draft')) + '</button>' +
                    '</footer>' +
                '</form>' +
            '</section>' +
        '</div>';
    }

    function renderHistoryModal() {
        const items = state.historyItems.length
            ? state.historyItems.map(item => {
                const active = item.id && item.id === state.currentSessionId ? ' is-active' : '';
                const meta = [item.pageTitle || item.contentPage || '', formatHistoryDate(item.updatedAt)].filter(Boolean).join(' - ');
                return '<article class="nb-copilot-history-item' + active + '">' +
                    '<button type="button" class="nb-copilot-history-load" data-history-load="' + escHtml(item.id) + '">' +
                        '<strong>' + escHtml(item.title || label('copilot.untitled_chat', 'Untitled chat')) + '</strong>' +
                        '<span>' + escHtml(meta) + '</span>' +
                    '</button>' +
                    '<button type="button" class="nb-copilot-history-delete" data-history-delete="' + escHtml(item.id) + '" aria-label="' + escHtml(label('copilot.delete_chat', 'Delete chat')) + '">' + icon('trash') + '</button>' +
                '</article>';
            }).join('')
            : '<p class="nb-copilot-history-empty">' + escHtml(state.historyLoaded ? label('copilot.history_empty', 'No archived chats yet.') : label('copilot.history_loading', 'Loading chat history...')) + '</p>';
        return '<div class="nb-copilot-modal-backdrop" role="presentation">' +
            '<section class="nb-copilot-modal nb-copilot-modal--wide" role="dialog" aria-modal="true" aria-labelledby="nb-copilot-history-title">' +
                '<header><strong id="nb-copilot-history-title">' + escHtml(label('copilot.history', 'Chat history')) + '</strong></header>' +
                '<div class="nb-copilot-modal__body">' +
                    '<div class="nb-copilot-history-list">' + items + '</div>' +
                '</div>' +
                '<footer>' +
                    '<button type="button" class="nb-copilot-history-close">' + escHtml(label('copilot.modal_cancel', 'Close')) + '</button>' +
                    '<button type="button" class="nb-copilot-history-new">' + escHtml(label('copilot.new_chat', 'New chat')) + '</button>' +
                '</footer>' +
            '</section>' +
        '</div>';
    }

    function formatHistoryDate(value) {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString();
    }

    function confirmCopilot(options) {
        return new Promise(resolve => {
            state.modal = Object.assign({}, options || {}, { resolve });
            renderModal();
        });
    }

    function closeCopilotModal(result) {
        const modal = state.modal;
        state.modal = null;
        renderModal();
        if (modal && typeof modal.resolve === 'function') {
            modal.resolve(result === true);
        }
    }

    function setOpen(open) {
        if (open && !state.open) {
            state.lastFocusedBeforeOpen = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        }
        state.open = open;
        const panel = document.getElementById('nb-copilot-panel');
        const toggle = document.querySelector('.nb-copilot-toggle');
        if (!panel || !toggle) return;
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            loadContext();
            const input = panel.querySelector('.nb-copilot-input');
            if (input) setTimeout(() => input.focus(), 0);
        } else {
            const restoreTarget = state.lastFocusedBeforeOpen && document.contains(state.lastFocusedBeforeOpen)
                ? state.lastFocusedBeforeOpen
                : toggle;
            state.lastFocusedBeforeOpen = null;
            if (restoreTarget) setTimeout(() => restoreTarget.focus(), 0);
        }
        persistSession();
    }

    function setMaximized(maximized) {
        state.maximized = maximized;
        syncMaximizedState();
        persistSession();
    }

    function syncMaximizedState(root) {
        const container = root || document.querySelector('.nb-copilot');
        if (!container) return;
        container.classList.toggle('is-maximized', state.maximized === true);
        const button = container.querySelector('.nb-copilot-maximize');
        if (!button) return;
        const key = state.maximized ? 'copilot.minimize' : 'copilot.maximize';
        const fallback = state.maximized ? 'Minimize AI Assistant' : 'Maximize AI Assistant';
        button.setAttribute('aria-label', label(key, fallback));
        button.setAttribute('title', label(key, fallback));
        button.innerHTML = icon(state.maximized ? 'minimize' : 'maximize');
    }

    function bind(root) {
        const toggle = root.querySelector('.nb-copilot-toggle');
        const close = root.querySelector('.nb-copilot-close');
        const maximize = root.querySelector('.nb-copilot-maximize');
        const historyBtn = root.querySelector('.nb-copilot-history-btn');
        const newBtn = root.querySelector('.nb-copilot-new-btn');
        const deleteBtn = root.querySelector('.nb-copilot-delete-btn');
        const form = root.querySelector('[data-copilot-form]');
        const input = root.querySelector('.nb-copilot-input');
        const draftBtn = root.querySelector('.nb-copilot-draft');
        const contentBtn = root.querySelector('.nb-copilot-content');
        const imageBtn = root.querySelector('.nb-copilot-image');

        toggle.addEventListener('click', () => setOpen(!state.open));
        close.addEventListener('click', () => setOpen(false));
        maximize.addEventListener('click', () => setMaximized(!state.maximized));
        historyBtn.addEventListener('click', event => {
            event.stopPropagation();
            openHistory();
        });
        newBtn.addEventListener('click', event => {
            event.stopPropagation();
            startNewChat();
        });
        deleteBtn.addEventListener('click', event => {
            event.stopPropagation();
            deleteCurrentChat();
        });
        document.addEventListener('click', trackSelectedEditableField, true);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && state.open) {
                event.preventDefault();
                if (state.imageDraft) {
                    state.imageDraft = null;
                    renderMessages();
                } else if (state.changeFormOpen) {
                    state.changeFormOpen = false;
                    renderMessages();
                } else if (state.historyOpen) {
                    state.historyOpen = false;
                    renderMessages();
                } else if (state.contentFormOpen) {
                    state.contentFormOpen = false;
                    renderMessages();
                } else if (state.modal) {
                    closeCopilotModal(false);
                } else {
                    setOpen(false);
                }
                return;
            }
            if (event.key === 'Tab' && state.open) {
                trapPanelFocus(event);
            }
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
        input.addEventListener('input', updateActionAvailability);
        form.addEventListener('submit', event => {
            event.preventDefault();
            const value = input.value.trim();
            if (!value || state.loading) return;
            input.value = '';
            updateActionAvailability();
            handleUserMessage(value);
        });
        draftBtn.addEventListener('click', async () => {
            const value = input.value.trim();
            if (state.loading) return;
            if (!canDraftChanges()) {
                state.messages.push({ role: 'assistant', content: label('copilot.no_safe_field', 'I could not map that request to a safe editable field on this page yet.') });
                renderMessages();
                return;
            }
            if (copilotMode === 'frontend' && !await ensureVisualEditorForWrite()) return;
            openChangeDraftForm(value);
        });
        contentBtn.addEventListener('click', async () => {
            const value = input.value.trim();
            if (state.loading) return;
            if (!canDraftContent()) {
                state.messages.push({ role: 'assistant', content: label('copilot.content_unavailable', 'Content creation is not available for your role or this site configuration.') });
                renderMessages();
                return;
            }
            if (copilotMode === 'frontend' && !await ensureVisualEditorForWrite()) return;
            openContentDraftForm(value);
        });
        imageBtn.addEventListener('click', async () => {
            const value = input.value.trim();
            if (state.loading) return;
            if (!canDraftImage()) {
                state.messages.push({ role: 'assistant', content: label('copilot.image_unavailable', 'Image generation is not available for your role, this page, or the current AI settings.') });
                renderMessages();
                return;
            }
            if (copilotMode === 'frontend' && !await ensureVisualEditorForWrite()) return;
            draftImage(value, { silent: true });
        });
        root.addEventListener('click', event => {
            const variantBtn = event.target.closest('[data-proposal-variant]');
            if (variantBtn && !state.loading) {
                selectImageVariant(variantBtn.dataset.proposalVariant);
                return;
            }
            const btn = event.target.closest('[data-proposal-index]');
            if (btn && !state.loading) {
                const index = Number(btn.dataset.proposalIndex);
                if (Number.isInteger(index) && state.proposals[index]) applyProposal(index, { skipConfirm: true });
                return;
            }
            if (event.target.closest('.nb-copilot-apply-all') && !state.loading) {
                applyAllProposals({ skipConfirm: true });
                return;
            }
            const copyBtn = event.target.closest('[data-proposal-copy]');
            if (copyBtn && !state.loading) {
                const index = Number(copyBtn.dataset.proposalCopy);
                if (Number.isInteger(index) && state.proposals[index]) copyProposal(index);
                return;
            }
            if (event.target.closest('.nb-copilot-create-btn') && !state.loading) {
                createContentDraft();
                return;
            }
            if (event.target.closest('.nb-copilot-details-btn') && !state.loading) {
                state.contentFormOpen = true;
                renderMessages();
                return;
            }
            if (event.target.closest('.nb-copilot-change-cancel') && !state.loading) {
                state.changeFormOpen = false;
                renderMessages();
                return;
            }
            if (event.target.closest('.nb-copilot-content-cancel') && !state.loading) {
                state.contentFormOpen = false;
                renderMessages();
                return;
            }
            if (event.target.closest('.nb-copilot-publish-btn') && !state.loading) {
                publishCreatedContent();
                return;
            }
            if (event.target.closest('.nb-copilot-undo-btn') && !state.loading) {
                undoLastApply();
                return;
            }
            const imageFieldBtn = event.target.closest('[data-image-draft-field]');
            if (imageFieldBtn && !state.loading) {
                selectImageDraftField(imageFieldBtn.dataset.imageDraftField || '');
                return;
            }
            const imageModeBtn = event.target.closest('[data-image-draft-mode]');
            if (imageModeBtn && !state.loading) {
                selectImageDraftMode(imageModeBtn.dataset.imageDraftMode || '');
                return;
            }
            if (event.target.closest('.nb-copilot-image-run') && !state.loading) {
                runImageDraft();
                return;
            }
            if (event.target.closest('.nb-copilot-image-cancel') && !state.loading) {
                state.imageDraft = null;
                renderMessages();
                return;
            }
            const historyLoad = event.target.closest('[data-history-load]');
            if (historyLoad && !state.loading) {
                loadHistoryChat(historyLoad.dataset.historyLoad || '');
                return;
            }
            const historyDelete = event.target.closest('[data-history-delete]');
            if (historyDelete && !state.loading) {
                deleteHistoryChat(historyDelete.dataset.historyDelete || '');
                return;
            }
            if (event.target.closest('.nb-copilot-history-close')) {
                state.historyOpen = false;
                renderMessages();
                return;
            }
            if (event.target.closest('.nb-copilot-history-new')) {
                startNewChat();
                return;
            }
            if (event.target.closest('.nb-copilot-modal-confirm')) {
                closeCopilotModal(true);
                return;
            }
            if (event.target.classList.contains('nb-copilot-modal-backdrop') && state.changeFormOpen) {
                state.changeFormOpen = false;
                renderMessages();
                return;
            }
            if (event.target.classList.contains('nb-copilot-modal-backdrop') && state.contentFormOpen) {
                state.contentFormOpen = false;
                renderMessages();
                return;
            }
            if (event.target.closest('.nb-copilot-modal-cancel') || event.target.classList.contains('nb-copilot-modal-backdrop')) {
                if (state.modal) {
                    closeCopilotModal(false);
                } else if (state.historyOpen) {
                    state.historyOpen = false;
                    renderMessages();
                } else if (state.changeFormOpen) {
                    state.changeFormOpen = false;
                    renderMessages();
                } else if (state.imageDraft) {
                    state.imageDraft = null;
                    renderMessages();
                } else {
                    closeCopilotModal(false);
                }
            }
        });
        root.addEventListener('submit', event => {
            if (event.target.closest('.nb-copilot-change-form')) {
                event.preventDefault();
                if (state.loading) return;
                submitChangeDraftForm(event.target);
                return;
            }
            if (!event.target.closest('.nb-copilot-content-form')) return;
            event.preventDefault();
            if (state.loading) return;
            submitContentDraftForm(event.target);
        });
        root.addEventListener('input', event => {
            const inputEl = event.target.closest('[data-proposal-alt]');
            if (inputEl) {
                const index = Number(inputEl.dataset.proposalAlt);
            if (Number.isInteger(index) && state.proposals[index]) {
                state.proposals[index].altValue = inputEl.value;
            }
                return;
            }
            const imagePromptEl = event.target.closest('[data-image-draft-prompt]');
            if (imagePromptEl && state.imageDraft) {
                state.imageDraft.instruction = imagePromptEl.value;
                return;
            }
            const changeInstructionEl = event.target.closest('[data-change-draft-instruction]');
            if (changeInstructionEl && state.changeDraft) {
                state.changeDraft.instruction = changeInstructionEl.value;
            }
        });
        root.addEventListener('change', event => {
            const changeFieldEl = event.target.closest('[data-change-draft-field]');
            if (changeFieldEl && state.changeDraft) {
                const field = findChangeTargetField(changeFieldEl.value || '');
                state.changeDraft.field = field || null;
                if (field) {
                    state.selectedField = field;
                    state.selectedFieldPath = field.path || '';
                    markContextElementByPath(field.path || '');
                } else {
                    state.selectedField = null;
                    state.selectedFieldPath = '';
                    markContextElementByPath('');
                }
                renderMessages();
                return;
            }
            const typeEl = event.target.closest('[data-content-draft-type]');
            if (typeEl && state.contentFormOpen && state.contentDraft && state.contentDraft.draft) {
                state.contentDraft.draft.contentType = typeEl.value || 'page';
                state.contentDraft.draft.draft = {};
                state.contentDraft.draft.missing = [];
                renderMessages();
                return;
            }
            const optionEl = event.target.closest('[data-image-draft-option]');
            if (!optionEl || !state.imageDraft) return;
            updateImageDraftOption(optionEl.dataset.imageDraftOption || '', optionEl.value);
        });
    }

    function openHistory() {
        state.historyOpen = true;
        renderMessages();
        post('ai-copilot-history-list', { contentPage }).then(result => {
            state.historyItems = Array.isArray(result && result.items) ? result.items : [];
            state.historyLoaded = true;
        }).catch(error => {
            state.historyItems = [];
            state.historyLoaded = true;
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(renderMessages);
    }

    function startNewChat() {
        state.historyOpen = false;
        state.imageDraft = null;
        state.proposals = [];
        state.contentDraft = null;
        state.createdContent = null;
        state.lastImageResult = null;
        state.lastApplied = null;
        state.lastInstruction = '';
        state.currentSessionId = newSessionId();
        state.messages = [{
            role: 'assistant',
            content: label('copilot.intro_message', 'I can help with this page and explain how to edit or create content safely. Write what you want to change or create.')
        }];
        persistSession();
        renderMessages();
    }

    async function deleteCurrentChat() {
        if (!state.currentSessionId && !state.messages.length) return;
        const confirmed = await confirmCopilot({
            title: label('copilot.delete_chat_title', 'Delete current chat?'),
            body: label('copilot.delete_chat_confirm', 'Delete the current AI Assistant chat history? This cannot be undone.'),
            confirmLabel: label('copilot.delete_chat', 'Delete current chat')
        });
        if (!confirmed) return;
        const id = state.currentSessionId;
        if (id) {
            post('ai-copilot-history-delete', { id }).catch(() => null);
        }
        startNewChat();
    }

    async function deleteHistoryChat(id) {
        if (!id) return;
        const confirmed = await confirmCopilot({
            title: label('copilot.delete_chat_title', 'Delete chat?'),
            body: label('copilot.delete_chat_confirm', 'Delete this AI Assistant chat history? This cannot be undone.'),
            confirmLabel: label('copilot.delete_chat', 'Delete chat')
        });
        if (!confirmed) return;
        post('ai-copilot-history-delete', { id }).then(() => {
            state.historyItems = state.historyItems.filter(item => item.id !== id);
            if (state.currentSessionId === id) {
                startNewChat();
            } else {
                renderMessages();
            }
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
            renderMessages();
        });
    }

    function loadHistoryChat(id) {
        if (!id) return;
        post('ai-copilot-history-load', { id }).then(result => {
            const chat = result && result.chat ? result.chat : null;
            if (!chat) return;
            state.currentSessionId = chat.id || id;
            state.messages = Array.isArray(chat.messages) ? chat.messages : [];
            state.lastInstruction = chat.lastInstruction || '';
            state.lastImageResult = chat.lastImageResult || null;
            state.proposals = [];
            state.contentDraft = null;
            state.createdContent = null;
            state.imageDraft = null;
            state.lastApplied = null;
            state.historyOpen = false;
            persistSession();
            renderMessages();
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
            renderMessages();
        });
    }

    function getPanelFocusableElements(panel) {
        return Array.from(panel.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'))
            .filter(element => !element.hidden && element.getClientRects().length > 0);
    }

    function trapPanelFocus(event) {
        const modal = document.querySelector('.nb-copilot-modal');
        if (modal) {
            trapFocusWithin(event, modal);
            return;
        }
        const panel = document.getElementById('nb-copilot-panel');
        if (!panel || panel.hidden) return;
        trapFocusWithin(event, panel);
    }

    function trapFocusWithin(event, container) {
        const focusable = getPanelFocusableElements(container);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        } else if (!container.contains(document.activeElement)) {
            event.preventDefault();
            first.focus();
        }
    }

    function loadContext() {
        syncContentPageFromHost();
        ensureContextReady();
    }

    function ensureContextReady() {
        syncContentPageFromHost();
        if (state.context) {
            renderMessages();
            return Promise.resolve(true);
        }
        if (state.contextPromise) {
            return state.contextPromise;
        }
        setLoading(true, 'copilot.working_context', 'Loading page context...');
        renderMessages();
        state.contextPromise = post('ai-copilot-context', {
            contentPage,
            uiLanguage: assistantUiLanguage()
        }).then(context => {
            state.context = context;
            resolveSelectedField();
            if (!state.messages.length) {
                state.messages.push({
                    role: 'assistant',
                    content: label('copilot.intro_message', 'I can help with this page and explain how to edit or create content safely. Write what you want to change or create.')
                });
            }
            return true;
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
            return false;
        }).finally(() => {
            state.contextPromise = null;
            setLoading(false);
            renderMessages();
        });
        return state.contextPromise;
    }

    async function handleUserMessage(content) {
        syncContentPageFromHost();
        if (isConfirmationIntent(content)) {
            if (state.proposals.length) {
                if (state.proposals.length === 1) {
                    applyProposal(0, { skipConfirm: true, confirmedByChat: true });
                } else {
                    applyAllProposals({ skipConfirm: true, confirmedByChat: true });
                }
                return;
            }
            if (state.contentDraft && state.contentDraft.draft && state.contentDraft.draft.canCreate) {
                createContentDraft({ skipConfirm: true, confirmedByChat: true });
                return;
            }
            if (state.createdContent && state.createdContent.id && state.createdContent.publishable) {
                publishCreatedContent({ skipConfirm: true, confirmedByChat: true });
                return;
            }
            state.messages.push({
                role: 'assistant',
                content: label('copilot.no_pending_preview', 'There is no reviewed AI preview waiting to apply. Ask for a change first, then use the preview card.')
            });
            renderMessages();
            return;
        }

        if (looksLikeImageRequest(content)) {
            if (!state.context && !await ensureContextReady()) return;
            if (canDraftImage()) {
                draftImage(content);
            } else {
                state.lastInstruction = content;
                state.messages.push({ role: 'user', content });
                state.messages.push({
                    role: 'assistant',
                    content: label('copilot.image_unavailable', 'Image generation is not available for your role, this page, or the current AI settings.')
                });
                renderMessages();
            }
            return;
        }

        if (looksLikeContentCreateRequest(content)) {
            if (!state.context && !await ensureContextReady()) return;
            if (canDraftContent()) {
                draftContent(content);
            } else {
                state.lastInstruction = content;
                state.messages.push({ role: 'user', content });
                state.messages.push({
                    role: 'assistant',
                    content: label('copilot.content_unavailable', 'Content creation is not available for your role or this site configuration.')
                });
                renderMessages();
            }
            return;
        }

        if (looksLikeNavigationMenuRequest(content)) {
            state.lastInstruction = content;
            state.messages.push({ role: 'user', content });
            state.messages.push({
                role: 'assistant',
                content: label('copilot.navigation_manual_required', 'No change was applied. I cannot safely change page navigation from chat yet. Open Page Settings / Navigation in the Content Editor and select the menus where this page should appear.')
            });
            renderMessages();
            return;
        }

        if (looksLikeTranslateRequest(content)) {
            if (!state.context && !await ensureContextReady()) return;
            if (hasPermission('suggestField') && copilotMode !== 'dashboard') {
                draftTranslation(content);
            } else {
                state.lastInstruction = content;
                state.messages.push({ role: 'user', content });
                state.messages.push({
                    role: 'assistant',
                    content: label('copilot.translate_unavailable', 'Translation drafts are not available for your role or on this surface. Open the page in the Visual Editor to translate it.')
                });
                renderMessages();
            }
            return;
        }

        if (looksLikeChangeRequest(content) && canDraftChanges()) {
            draftChanges(content);
            return;
        }

        sendMessage(content);
    }

    function draftTranslation(instruction) {
        syncContentPageFromHost();
        state.lastInstruction = instruction;
        state.proposals = [];
        state.contentDraft = null;
        state.imageDraft = null;
        state.messages.push({ role: 'user', content: instruction });
        setLoading(true, 'copilot.working_translate', 'Translating the page content...');
        renderMessages();
        post('ai-copilot-translate', {
            contentPage,
            instruction
        }).then(result => {
            state.proposals = Array.isArray(result && result.proposals) ? result.proposals : [];
            const target = String((result && result.targetLang) || '').toUpperCase();
            state.messages.push({
                role: 'assistant',
                content: state.proposals.length
                    ? label('copilot.translate_ready', 'I drafted {target} translations. Review the preview cards below; applying saves them to the {target} page.', { target })
                    : label('copilot.translate_no_fields', 'I could not draft translations for this page. Check that the target language page exists and has translatable fields.')
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    let streamRenderTimer = null;
    function renderMessagesThrottled() {
        if (streamRenderTimer) return;
        streamRenderTimer = window.setTimeout(() => {
            streamRenderTimer = null;
            renderMessages();
        }, 120);
    }

    function sendMessage(content) {
        syncContentPageFromHost();
        state.lastInstruction = content;
        state.proposals = [];
        state.contentDraft = null;
        state.imageDraft = null;
        state.messages.push({ role: 'user', content });
        setLoading(true, 'copilot.working_chat', 'Thinking about your question...');
        renderMessages();
        const payload = {
            contentPage,
            uiLanguage: assistantUiLanguage(),
            messages: state.messages.map(message => ({ role: message.role, content: message.content }))
        };
        let streamingMessage = null;
        const onDelta = delta => {
            if (!streamingMessage) {
                streamingMessage = { role: 'assistant', content: '' };
                state.messages.push(streamingMessage);
                setLoading(false);
            }
            streamingMessage.content += delta;
            renderMessagesThrottled();
        };
        const finish = result => {
            if (result && result.context) state.context = result.context;
            const reply = (result && result.reply) || (streamingMessage && streamingMessage.content) || label('copilot.no_response', 'No response.');
            if (streamingMessage) {
                streamingMessage.content = reply;
            } else {
                state.messages.push({ role: 'assistant', content: reply });
            }
        };
        const request = supportsStreaming()
            ? postStream('ai-copilot-chat-stream', payload, onDelta).then(finish)
            : post('ai-copilot-chat', payload).then(finish);
        request.catch(error => {
            if (!streamingMessage && supportsStreaming() && !error.sessionExpired) {
                // Streaming produced no output: retry once over the JSON endpoint.
                return post('ai-copilot-chat', payload).then(finish).catch(fallbackError => {
                    state.messages.push({ role: 'assistant', content: fallbackError.message });
                });
            }
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    async function draftChanges(instruction) {
        syncContentPageFromHost();
        if (!await ensureVisualEditorForWrite()) return;
        state.lastInstruction = instruction;
        state.proposals = [];
        state.contentDraft = null;
        state.createdContent = null;
        state.imageDraft = null;
        const targetField = resolveSelectedFieldForInstruction(instruction);
        const visibilityAction = detectVisibilityIntent(instruction);
        if (visibilityAction && targetField && hasPermission('toggleVisibility')) {
            state.selectedField = targetField;
            draftVisibility(instruction, visibilityAction);
            return;
        }
        const htmlFormat = detectHtmlFormatIntent(instruction);
        if (htmlFormat && targetField && targetField.type === 'html') {
            state.selectedField = targetField;
            draftHtmlFormat(instruction, htmlFormat);
            return;
        }
        state.messages.push({ role: 'user', content: instruction });
        setLoading(true, 'copilot.working_draft', 'Drafting a safe change...');
        renderMessages();
        post('ai-copilot-suggest', {
            contentPage,
            instruction,
            fieldRef: targetField ? (targetField.id || targetField.path) : ''
        }).then(result => {
            if (result && result.context) state.context = result.context;
            state.proposals = Array.isArray(result && result.proposals) ? result.proposals : [];
            state.messages.push({
                role: 'assistant',
                content: state.proposals.length
                    ? label('copilot.suggestions_ready', 'I drafted safe field-level suggestions. Review the preview cards below.')
                    : label('copilot.no_safe_field', 'I could not map that request to a safe editable field on this page yet.')
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    async function draftVisibility(instruction, visibilityAction) {
        if (!await ensureVisualEditorForWrite()) return;
        state.messages.push({ role: 'user', content: instruction });
        setLoading(true, 'copilot.working_draft', 'Drafting a safe change...');
        renderMessages();
        post('ai-copilot-visibility', {
            contentPage,
            fieldRef: state.selectedField.id || state.selectedField.path,
            visibilityAction,
            instruction
        }).then(result => {
            if (result && result.context) state.context = result.context;
            state.proposals = Array.isArray(result && result.proposals) ? result.proposals : [];
            state.messages.push({
                role: 'assistant',
                content: state.proposals.length
                    ? label('copilot.suggestions_ready', 'I drafted safe field-level suggestions. Review the preview cards below.')
                    : label('copilot.no_safe_field', 'I could not map that request to a safe editable field on this page yet.')
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    async function draftHtmlFormat(instruction, format) {
        if (!await ensureVisualEditorForWrite()) return;
        state.messages.push({ role: 'user', content: instruction });
        setLoading(true, 'copilot.working_draft', 'Drafting a safe change...');
        renderMessages();
        post('ai-copilot-format-html', {
            contentPage,
            fieldRef: state.selectedField.id || state.selectedField.path,
            format,
            instruction
        }).then(result => {
            if (result && result.context) state.context = result.context;
            state.proposals = Array.isArray(result && result.proposals) ? result.proposals : [];
            state.messages.push({
                role: 'assistant',
                content: state.proposals.length
                    ? label('copilot.suggestions_ready', 'I drafted safe field-level suggestions. Review the preview cards below.')
                    : label('copilot.no_safe_field', 'I could not map that request to a safe editable field on this page yet.')
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    function looksLikeTranslateRequest(instruction) {
        const text = String(instruction || '').toLowerCase();
        return /translat|übersetz|uebersetz|traduc|tradui|tradur|traduz|tłumacz|przetłumacz|tlumacz|çevir|cevir|přelož|prelozit|preloz/.test(text);
    }

    function looksLikeChangeRequest(instruction) {
        const text = String(instruction || '').toLowerCase();
        if (state.selectedField && !looksLikeHelpQuestion(text)) return true;
        return /\b(change|update|replace|rewrite|improve|shorten|extend|make|set|hide|show|rename|edit|apply|ändern|aendern|änder|aender|ersetzen|aktualisieren|verbessern|kürzen|kuerzen|verlängern|verlaengern|mach|mache|setz|setze|schreib|schreibe|formulier|formuliere|passe|anpassen|tausch|tausche|ausblenden|anzeigen|umbenennen|zitat|titel|text)\b/.test(text);
    }

    function looksLikeImageRequest(instruction) {
        const text = String(instruction || '').toLowerCase();
        return /\b(image|picture|photo|illustration|generate image|create image|new image|edit image|replace image|bild|foto|illustration|bild generieren|bild erstellen|neues bild|bild bearbeiten|bild tauschen|bild ersetzen)\b/.test(text);
    }

    function looksLikeContentCreateRequest(instruction) {
        const text = String(instruction || '').toLowerCase();
        if (looksLikeHelpQuestion(text)) return false;
        const createVerb = /\b(create|add|make|draft|publish|new|erstelle|erstell|lege|leg|anlegen|erstellt|hinzufügen|hinzufuegen|neu)\b/.test(text);
        const contentType = /\b(page|site page|webpage|news|post|article|event|appointment|termin|veranstaltung|seite|unterseite|newsbeitrag|meldung|beitrag|artikel)\b/.test(text);
        const explicitGermanPage = /\b(neue|neuen|neuer)\s+(seite|unterseite|meldung|termin|veranstaltung|beitrag|newsbeitrag)\b/.test(text);
        const explicitEnglishPage = /\b(new|create|add)\s+(page|webpage|news|post|article|event|appointment)\b/.test(text);
        return (createVerb && contentType) || explicitGermanPage || explicitEnglishPage;
    }

    function looksLikeNavigationMenuRequest(instruction) {
        const text = String(instruction || '').toLowerCase();
        if (looksLikeHelpQuestion(text)) return false;
        const navigationTarget = /\b(menu|navigation|nav|header|footer|pages|info|menü|menue|navigation|kopfmenü|hauptmenü|seitenmenü)\b/.test(text);
        const action = /\b(add|show|include|display|enable|set|change|update|aufnehmen|anzeigen|hinzufügen|hinzufuegen|setzen|ändern|aendern|aktivieren|erscheinen)\b/.test(text);
        return navigationTarget && action;
    }

    function looksLikeHelpQuestion(text) {
        return /^(how|where|why|what|which|can you explain|explain|help|wie|wo|warum|wieso|was|welche|kannst du erklären|erklaer|erklär|hilfe)\b/.test(String(text || '').trim());
    }

    function isConfirmationIntent(instruction) {
        const text = String(instruction || '').trim().toLowerCase();
        return /^(yes|y|ok|okay|do it|apply|confirm|go ahead|ja|jep|jawohl|passt|mach das|anwenden|bestätigen|bestaetigen|genau)$/i.test(text);
    }

    function inlineEditorAvailable() {
        return window.InlineEditor && typeof window.InlineEditor.enterEditMode === 'function';
    }

    function inlineEditorActive() {
        if (window.InlineEditor && typeof window.InlineEditor.isEditMode === 'function') {
            return window.InlineEditor.isEditMode();
        }
        return document.body.classList.contains('edit-mode-active');
    }

    async function ensureVisualEditorForWrite() {
        if (inlineEditorActive()) return true;
        if (!inlineEditorAvailable()) {
            state.messages.push({ role: 'assistant', content: label('copilot.visual_editor_unavailable', 'The Visual Editor is not available on this page, so I cannot safely prepare page changes here.') });
            renderMessages();
            return false;
        }
        const confirmed = await confirmCopilot({
            title: label('copilot.enable_visual_editor_title', 'Activate Visual Editor?'),
            body: label('copilot.enable_visual_editor_confirm', 'The Visual Editor must be active before AI changes can be prepared on this page. Activate it now?'),
            confirmLabel: label('copilot.enable_visual_editor_action', 'Activate Visual Editor')
        });
        if (!confirmed) {
            state.messages.push({ role: 'assistant', content: label('copilot.visual_editor_required', 'No change was made. Activate the Visual Editor first, then ask me to prepare the change again.') });
            renderMessages();
            return false;
        }
        window.InlineEditor.enterEditMode();
        state.messages.push({ role: 'assistant', content: label('copilot.visual_editor_enabled', 'Visual Editor is active. I will prepare the change as a preview first.') });
        return true;
    }

    function detectHtmlFormatIntent(instruction) {
        const text = String(instruction || '').toLowerCase();
        if (/\b(bold|strong|fett)\b/.test(text)) return 'strong';
        if (/\b(italic|italics|kursiv)\b/.test(text)) return 'em';
        if (/\b(underline|underlined|unterstrichen)\b/.test(text)) return 'u';
        return '';
    }

    function detectVisibilityIntent(instruction) {
        const text = String(instruction || '').toLowerCase();
        if (/\b(hide|hidden|ausblenden|verstecken|unsichtbar)\b/.test(text) || /nicht anzeigen/.test(text)) return 'hide';
        if (/\b(show|visible|anzeigen|einblenden|sichtbar)\b/.test(text) || /wieder anzeigen/.test(text)) return 'show';
        return '';
    }

    function openChangeDraftForm(instruction) {
        const selected = findChangeTargetField(state.selectedField && (state.selectedField.id || state.selectedField.path))
            || findChangeTargetField(state.changeDraft && state.changeDraft.field && (state.changeDraft.field.id || state.changeDraft.field.path));
        state.changeDraft = {
            instruction: instruction || (state.changeDraft && state.changeDraft.instruction) || '',
            field: selected
        };
        state.changeFormOpen = true;
        state.contentFormOpen = false;
        state.imageDraft = null;
        state.historyOpen = false;
        state.modal = null;
        renderMessages();
    }

    function submitChangeDraftForm(form) {
        const fieldEl = form.querySelector('[data-change-draft-field]');
        const instructionEl = form.querySelector('[data-change-draft-instruction]');
        const field = fieldEl ? findChangeTargetField(fieldEl.value || '') : null;
        if (!field) {
            state.messages.push({ role: 'assistant', content: label('copilot.choose_target_required', 'Choose the field you want to change first.') });
            if (fieldEl) fieldEl.focus();
            renderMessages();
            return;
        }
        const instruction = instructionEl ? instructionEl.value.trim() : '';
        if (!instruction) {
            if (instructionEl) instructionEl.focus();
            return;
        }
        state.selectedField = field;
        state.selectedFieldPath = field.path || '';
        markContextElementByPath(field.path || '');
        state.changeDraft = { instruction, field };
        state.changeFormOpen = false;
        draftChanges(instruction);
    }

    function openContentDraftForm(briefing) {
        const existing = state.contentDraft && state.contentDraft.draft ? state.contentDraft.draft : null;
        const contentType = existing ? (existing.contentType || 'page') : 'page';
        const values = existing && existing.draft ? Object.assign({}, existing.draft) : {};
        if (briefing && !values.content && !values.description) {
            values.content = briefing;
        }
        state.contentDraft = {
            draft: {
                contentType,
                draft: values,
                missing: [],
                canCreate: false
            }
        };
        state.contentFormOpen = true;
        state.changeFormOpen = false;
        state.imageDraft = null;
        state.historyOpen = false;
        state.modal = null;
        renderMessages();
    }

    function draftContent(instruction) {
        syncContentPageFromHost();
        state.lastInstruction = instruction;
        const existingDraft = state.contentDraft && state.contentDraft.draft ? state.contentDraft.draft : null;
        state.proposals = [];
        state.contentDraft = null;
        state.createdContent = null;
        state.imageDraft = null;
        state.messages.push({ role: 'user', content: instruction });
        setLoading(true, 'copilot.working_content', 'Preparing a content draft...');
        renderMessages();
        post('ai-copilot-draft-content', {
            contentPage,
            instruction,
            existingDraft
        }).then(result => {
            state.contentDraft = result && result.draft ? result : null;
            const draft = state.contentDraft && state.contentDraft.draft;
            state.messages.push({
                role: 'assistant',
                content: draft && draft.canCreate
                    ? label('copilot.content_draft_ready_message', 'I prepared a new {type} draft. Review the preview before creating it.', { type: draft.contentType })
                    : label('copilot.content_draft_needs_details_message', 'I prepared a draft, but it needs more details before it can be created.')
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    function submitContentDraftForm(form) {
        const current = state.contentDraft && state.contentDraft.draft ? state.contentDraft.draft : null;
        if (!current) return;
        const values = Object.assign({}, current.draft || {});
        form.querySelectorAll('[data-content-draft-field]').forEach(input => {
            values[input.getAttribute('data-content-draft-field')] = input.value.trim();
        });
        state.contentFormOpen = false;
        draftContentFromForm(current.contentType || 'page', values);
    }

    function draftContentFromForm(contentType, values) {
        const instruction = label('copilot.update_draft_instruction', 'Update the content draft with the submitted form fields.');
        state.lastInstruction = instruction;
        state.proposals = [];
        state.createdContent = null;
        state.imageDraft = null;
        setLoading(true, 'copilot.working_content', 'Preparing a content draft...');
        renderMessages();
        post('ai-copilot-draft-content', {
            contentPage,
            contentType,
            instruction: instruction + ' ' + JSON.stringify(values),
            existingDraft: {
                contentType,
                missing: [],
                draft: values
            }
        }).then(result => {
            state.contentDraft = result && result.draft ? result : null;
            const draft = state.contentDraft && state.contentDraft.draft;
            state.messages.push({
                role: 'assistant',
                content: draft && draft.canCreate
                    ? label('copilot.content_draft_ready_message', 'I prepared a new {type} draft. Review the preview before creating it.', { type: draft.contentType })
                    : label('copilot.content_draft_needs_details_message', 'I prepared a draft, but it needs more details before it can be created.')
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    function draftImage(instruction, options) {
        options = options || {};
        syncContentPageFromHost();
        state.lastInstruction = instruction;
        state.proposals = [];
        state.changeFormOpen = false;
        state.contentDraft = null;
        state.createdContent = null;
        const imageFields = getImageFields();
        if (!imageFields.length) {
            state.messages.push({ role: 'assistant', content: label('copilot.no_image_field', 'I could not find an editable image field on this page.') });
            renderMessages();
            return;
        }
        let field = imageFields[0];
        if (state.selectedField && state.selectedField.type === 'image') {
            const selectedImageField = imageFields.find(item => item.id === state.selectedField.id || item.path === state.selectedField.path);
            if (selectedImageField) field = selectedImageField;
        }
        if (!options.silent && String(instruction || '').trim()) {
            state.messages.push({ role: 'user', content: instruction });
        }
        state.imageDraft = {
            instruction: extractImagePrompt(instruction),
            fields: imageFields,
            fieldId: field.id || field.path,
            mode: 'generate',
            options: {
                size: inferImageFieldSize(field),
                count: '1',
                outputFormat: 'webp',
                quality: 'auto'
            }
        };
        updateImageDraftSizeFromField(field);
        if (!options.silent) {
            state.messages.push({ role: 'assistant', content: label('copilot.image_options_ready', 'Choose the image field and whether to generate a new image or edit the current one.') });
        }
        renderMessages();
    }

    function runImageDraft() {
        const draft = state.imageDraft;
        if (!draft || !Array.isArray(draft.fields) || !draft.fields.length) return;
        const field = draft.fields.find(item => (item.id || item.path) === draft.fieldId) || draft.fields[0];
        const instruction = String(draft.instruction || '').trim();
        if (!instruction) {
            state.messages.push({ role: 'assistant', content: label('copilot.image_prompt_required', 'Add a prompt before generating an image draft.') });
            renderMessages();
            return;
        }
        if (state.sessionExpired) {
            state.messages.push({ role: 'assistant', content: label('copilot.session_expired', 'Your session expired. Please log in again in the dashboard, then reload this page.') });
            renderMessages();
            return;
        }
        const useCurrentAsReference = draft.mode === 'edit';
        setLoading(true, 'copilot.working_image', 'Generating image...');
        renderMessages();
        post('ai-copilot-generate-image', {
            contentPage,
            fieldRef: field.id || field.path,
            instruction,
            imageMode: useCurrentAsReference ? 'edit' : 'generate',
            useCurrentAsReference: useCurrentAsReference ? '1' : '',
            size: draft.options && draft.options.size ? draft.options.size : 'auto',
            count: draft.options && draft.options.count ? draft.options.count : '1',
            outputFormat: draft.options && draft.options.outputFormat ? draft.options.outputFormat : 'webp',
            quality: draft.options && draft.options.quality ? draft.options.quality : 'auto'
        }, imageRequestTimeoutMs).then(result => {
            if (result && result.context) state.context = result.context;
            if (result && result.job) {
                state.messages.push({ role: 'assistant', content: label('copilot.image_job_queued', 'Image generation is running in the background. You can keep working; I will show the preview when it is ready.') });
                runImageJob(result.job);
                startImageJobPolling(result.job.id);
                return;
            }
            if (result && result.proposal) {
                state.imageDraft = null;
                state.proposals = [result.proposal];
                state.messages.push({ role: 'assistant', content: label('copilot.image_draft_ready', 'Generated image ready. Review the preview card before applying it.') });
            } else {
                state.messages.push({ role: 'assistant', content: label('copilot.image_no_proposal', 'Image generation returned no preview.') });
            }
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            if (!imageJobPollTimer) {
                setLoading(false);
            }
            renderMessages();
        });
    }

    function extractImagePrompt(instruction) {
        let prompt = String(instruction || '').trim();
        prompt = prompt.replace(/^\/?(draft|generate|create|make)\s+(an?\s+)?(image|picture|photo|illustration)\s*(of|for|showing|with)?\s*/i, '');
        prompt = prompt.replace(/^(ich\s+)?(möchte|will|hätte\s+gerne|brauche|erstelle|generiere|mach|mache)\s+(mir\s+)?(bitte\s+)?(ein|eine|einen)?\s*(bild|foto|illustration)\s*(von|für|mit)?\s*/i, '');
        prompt = prompt.replace(/^(bitte\s+)?(ein|eine|einen)\s+(bild|foto|illustration)\s*(von|für|mit)?\s*/i, '');
        prompt = prompt.replace(/^["'„“”]+|["'„“”]+$/g, '').trim();
        return prompt || String(instruction || '').trim();
    }

    function trackSelectedEditableField(event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest('.nb-copilot')) return;
        const editable = target.closest('[data-field][data-page]');
        if (!editable) return;
        const page = editable.getAttribute('data-page') || '';
        if (page !== contentPage) return;
        const path = editable.getAttribute('data-field') || '';
        state.selectedFieldPath = path;
        markSelectedElement(editable);
        const field = findContextField(path);
        if (!field) return;
        state.selectedField = field;
        if (state.open) renderMessages();
    }

    function findContextField(path) {
        const fields = state.context && state.context.page && Array.isArray(state.context.page.fields)
            ? state.context.page.fields
            : [];
        return fields.find(field => field && field.path === path)
            || fields.find(field => field && typeof field.path === 'string' && field.path.indexOf(path + '.') === 0)
            || null;
    }

    function findContextFieldByPath(path) {
        const fields = state.context && state.context.page && Array.isArray(state.context.page.fields)
            ? state.context.page.fields
            : [];
        return fields.find(field => field && field.path === path) || null;
    }

    function changeTargetLabel(field) {
        if (!field) return label('copilot.unknown_field', 'selected field');
        const raw = field.label || readableFieldLabel(field.path || field.id || '');
        const section = String(raw).match(/^(Sec\. \d+ [^:]+):\s*(.*?)\s+-\s+(.+)$/);
        if (section) {
            return truncateTargetLabel('[' + section[1] + '] ' + section[2] + ' - ' + section[3], 104);
        }
        return truncateOptionLabel(raw, 104);
    }

    function getChangeTargetFields() {
        const fields = state.context && state.context.page && Array.isArray(state.context.page.fields)
            ? state.context.page.fields
            : [];
        return fields.filter(field => {
            if (!field || !Array.isArray(field.operations)) return false;
            if (!/^sections\./i.test(String(field.path || ''))) return false;
            if (/^seo(?:\.|$)/i.test(String(field.path || ''))) return false;
            return field.operations.includes('suggest')
                || field.operations.includes('format-html')
                || field.operations.includes('toggle-visibility')
                || field.operations.includes('toggleVisibility');
        });
    }

    function findChangeTargetField(ref) {
        const value = String(ref || '');
        if (!value) return null;
        return getChangeTargetFields().find(field => field && (field.id === value || field.path === value)) || null;
    }

    function markContextElementByPath(path) {
        if (!path) {
            clearSelectedElement();
            return;
        }
        const selector = fieldSelector(path);
        const element = selector ? document.querySelector(selector) : null;
        if (element) {
            markSelectedElement(element);
            return;
        }
        clearSelectedElement();
    }

    function resolveSelectedFieldForInstruction(instruction) {
        if (!state.selectedFieldPath) return state.selectedField;
        const basePath = state.selectedFieldPath;
        const text = String(instruction || '').toLowerCase();
        if (state.selectedElement && state.selectedElement.matches('a[data-editable-link]')) {
            const wantsText = /\b(text|label|caption|wording|title|beschriftung|linktext|buttontext|text ändern|umbenennen)\b/.test(text);
            const wantsHref = /\b(url|href|link|target|ziel|adresse|weiterleitung|verlinkung)\b/.test(text);
            const preferredPaths = wantsText && !wantsHref
                ? [basePath + '.text', basePath + '.label', basePath + '.title', basePath + '.href', basePath + '.url']
                : [basePath + '.href', basePath + '.url', basePath + '.text', basePath + '.label', basePath + '.title'];
            for (const path of preferredPaths) {
                const field = findContextFieldByPath(path);
                if (field) return field;
            }
        }
        return state.selectedField || findContextField(basePath);
    }

    function resolveSelectedField() {
        if (!state.selectedFieldPath) return;
        state.selectedField = findContextField(state.selectedFieldPath);
        if (!state.selectedField) {
            clearSelectedElement();
        }
    }

    function markSelectedElement(element) {
        clearSelectedElement();
        state.selectedElement = element;
        element.classList.add('nb-copilot-selected-field');
    }

    function clearSelectedElement() {
        if (state.selectedElement && document.contains(state.selectedElement)) {
            state.selectedElement.classList.remove('nb-copilot-selected-field');
        }
        state.selectedElement = null;
    }

    function getImageFields() {
        const fields = state.context && state.context.page && Array.isArray(state.context.page.fields)
            ? state.context.page.fields
            : [];
        return fields.filter(field => {
            return field && Array.isArray(field.operations) && field.operations.includes('generate-image');
        });
    }

    function inferImageFieldSize(field) {
        if (!field) return 'auto';
        const src = String(field.preview || field.value || field.path || '').trim();
        const lower = src.toLowerCase();
        if (/\b(portrait|hochformat|vertical)\b/.test(lower)) return '1024x1536';
        if (/\b(square|quadratisch)\b/.test(lower)) return '1024x1024';
        if (/\b(landscape|querformat|wide|hero|banner)\b/.test(lower)) return '1536x1024';
        return 'auto';
    }

    function imageSizeFromDimensions(width, height) {
        width = Number(width || 0);
        height = Number(height || 0);
        if (!width || !height) return 'auto';
        const ratio = width / height;
        if (ratio >= 1.18) return '1536x1024';
        if (ratio <= 0.85) return '1024x1536';
        return '1024x1024';
    }

    function updateImageDraftSizeFromField(field) {
        if (!state.imageDraft || !field || state.imageDraft.sizeTouched) return;
        const fallback = inferImageFieldSize(field);
        const src = imageFieldPreviewSrc(field);
        if (!src || /^(api\.php\?|data:|blob:)/i.test(src)) {
            if (fallback !== 'auto') {
                state.imageDraft.options = Object.assign({}, state.imageDraft.options || {}, { size: fallback });
                renderMessages();
            }
            return;
        }
        const img = new Image();
        img.onload = function () {
            if (!state.imageDraft || state.imageDraft.sizeTouched) return;
            const active = state.imageDraft.fields.find(item => (item.id || item.path) === state.imageDraft.fieldId);
            if (!active || (active.id || active.path) !== (field.id || field.path)) return;
            const size = imageSizeFromDimensions(img.naturalWidth, img.naturalHeight);
            if (size === 'auto') return;
            state.imageDraft.options = Object.assign({}, state.imageDraft.options || {}, { size });
            renderMessages();
        };
        img.onerror = function () {
            if (!state.imageDraft || state.imageDraft.sizeTouched || fallback === 'auto') return;
            const active = state.imageDraft.fields.find(item => (item.id || item.path) === state.imageDraft.fieldId);
            if (!active || (active.id || active.path) !== (field.id || field.path)) return;
            state.imageDraft.options = Object.assign({}, state.imageDraft.options || {}, { size: fallback });
            renderMessages();
        };
        img.src = src;
    }

    function permissions() {
        return state.context && state.context.user && state.context.user.permissions
            ? state.context.user.permissions
            : {};
    }

    function hasPermission(name) {
        return permissions()[name] === true;
    }

    function canDraftChanges() {
        if (copilotMode === 'dashboard') return false;
        return (hasPermission('suggestField') || hasPermission('toggleVisibility')) && getChangeTargetFields().length > 0;
    }

    function canDraftContent() {
        const contentTypes = Array.isArray(state.context && state.context.contentTypes)
            ? state.context.contentTypes
            : [];
        const permissionByType = {
            page: 'createPage',
            news: 'createNews',
            event: 'createEvent'
        };
        return contentTypes.some(type => {
            return type && type.available && hasPermission(permissionByType[type.id] || '');
        });
    }

    function canDraftImage() {
        if (copilotMode === 'dashboard') return false;
        const ai = state.context && state.context.ai ? state.context.ai : {};
        const features = ai.features || {};
        const models = ai.models || {};
        return hasPermission('generateImage')
            && ai.enabled === true
            && ai.hasApiKey === true
            && features.imageGeneration === true
            && Boolean(models.image)
            && getImageFields().length > 0;
    }

    function updateActionAvailability() {
        const draftBtn = document.querySelector('.nb-copilot-draft');
        const contentBtn = document.querySelector('.nb-copilot-content');
        const imageBtn = document.querySelector('.nb-copilot-image');
        const sendBtn = document.querySelector('.nb-copilot-send');
        const input = document.querySelector('.nb-copilot-input');
        const hasPrompt = input ? input.value.trim() !== '' : false;
        const needsPrompt = label('copilot.shortcut_needs_prompt', 'Enter an instruction first.');
        if (sendBtn) {
            sendBtn.disabled = state.loading || state.sessionExpired || !hasPrompt;
            sendBtn.title = hasPrompt ? '' : needsPrompt;
        }
        if (input) input.disabled = state.loading || state.sessionExpired;
        if (draftBtn) {
            const enabled = canDraftChanges();
            draftBtn.disabled = state.sessionExpired || !enabled || state.loading;
            draftBtn.title = enabled ? label('copilot.draft_changes_hint', 'Open a guided dialog to prepare a reviewed page change.') : label('copilot.no_safe_field', 'I could not map that request to a safe editable field on this page yet.');
        }
        if (contentBtn) {
            const enabled = canDraftContent();
            contentBtn.disabled = state.sessionExpired || !enabled || state.loading;
            contentBtn.title = enabled ? label('copilot.draft_content_hint', 'Open a guided dialog to prepare a new page, news post, event, or appointment.') : label('copilot.content_unavailable', 'Content creation is not available for your role or this site configuration.');
        }
        if (imageBtn) {
            const enabled = canDraftImage();
            imageBtn.hidden = !enabled;
            imageBtn.disabled = state.sessionExpired || !enabled || state.loading;
            imageBtn.title = enabled ? label('copilot.draft_image_hint', 'Open image options and enter or refine the image prompt.') : label('copilot.image_unavailable', 'Image generation is not available for your role, this page, or the current AI settings.');
        }
    }

    async function applyProposal(index, options) {
        const proposal = state.proposals[index];
        if (!proposal) return;
        if (proposal.type === 'visibility' || proposal.action === 'toggleFieldVisibility') {
            await applyVisibilityProposal(index, options);
            return;
        }
        if (!await ensureVisualEditorForWrite()) return;
        const confirmed = options && options.skipConfirm
            ? true
            : await confirmCopilot({
                title: label('copilot.apply_confirm_title', 'Apply AI draft?'),
                body: label('copilot.apply_confirm', 'Apply this AI draft to "{field}"?', { field: proposal.label || proposal.path }),
                items: [proposal.label || proposal.path],
                confirmLabel: label('copilot.apply', 'Apply')
            });
        if (!confirmed) return;
        setLoading(true, 'copilot.working_apply', 'Applying the confirmed change...');
        renderMessages();
        applyProposalObject(proposal).then(result => {
            state.proposals.splice(index, 1);
            state.lastApplied = result && result.undo ? result : null;
            if (proposal.type === 'image') rememberAppliedImage(proposal, result);
            state.messages.push({
                role: 'assistant',
                content: appliedMessageForProposal(proposal)
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    async function applyVisibilityProposal(index, options) {
        const proposal = state.proposals[index];
        if (!proposal) return;
        if (!await ensureVisualEditorForWrite()) return;
        const confirmed = options && options.skipConfirm
            ? true
            : await confirmCopilot({
                title: label('copilot.apply_confirm_title', 'Apply AI draft?'),
                body: label('copilot.apply_confirm', 'Apply this AI draft to "{field}"?', { field: proposal.label || proposal.path }),
                items: [proposal.label || proposal.path],
                confirmLabel: label('copilot.apply', 'Apply')
            });
        if (!confirmed) return;
        setLoading(true, 'copilot.working_apply', 'Applying the confirmed change...');
        renderMessages();
        applyProposalObject(proposal).then(result => {
            state.proposals.splice(index, 1);
            state.lastApplied = result && result.undo ? result : null;
            if (proposal.type === 'image') rememberAppliedImage(proposal, result);
            state.messages.push({
                role: 'assistant',
                content: appliedMessageForProposal(proposal)
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    async function applyAllProposals(options) {
        if (!state.proposals.length) return;
        if (!await ensureVisualEditorForWrite()) return;
        const proposals = state.proposals.slice();
        const confirmed = options && options.skipConfirm
            ? true
            : await confirmCopilot({
                title: label('copilot.apply_all_confirm_title', 'Apply all AI drafts?'),
                body: label('copilot.apply_all_confirm_body', 'Apply these {count} AI drafts? A backup is created for every changed page state.', { count: proposals.length }),
                items: proposals.map(proposal => (proposal.label || proposal.path) + ': ' + proposalSummaryText(proposal)),
                confirmLabel: label('copilot.apply_all', 'Apply all')
            });
        if (!confirmed) return;
        setLoading(true, 'copilot.working_apply', 'Applying the confirmed change...');
        renderMessages();
        const applied = [];
        try {
            for (const proposal of proposals) {
                const result = await applyProposalObject(proposal);
                applied.push({ proposal, result });
                state.lastApplied = result && result.undo ? result : state.lastApplied;
            }
            state.proposals = state.proposals.filter(proposal => !proposals.includes(proposal));
            state.messages.push({
                role: 'assistant',
                content: label('copilot.applied_all_message', 'Applied {count} AI drafts. A backup was created before each save.', { count: applied.length })
            });
        } catch (error) {
            state.messages.push({ role: 'assistant', content: error.message });
        } finally {
            setLoading(false);
            renderMessages();
        }
    }

    function applyProposalObject(proposal) {
        if (proposal.type === 'visibility' || proposal.action === 'toggleFieldVisibility') {
            return post('ai-copilot-apply-visibility', {
                contentPage,
                path: proposal.path,
                value: proposal.value,
                currentHash: proposal.currentHash || '',
                visibilitySignature: proposal.visibilitySignature || '',
                confirmed: '1'
            }).then(result => {
                patchVisibility(result.path, result.hidden === true);
                return result;
            });
        }
        // Translation proposals target the same page in another language;
        // their signatures are bound to that page, and the local DOM must
        // not be patched for changes on a different page.
        const proposalPage = proposal.contentPage || contentPage;
        return post('ai-copilot-apply', {
            contentPage: proposalPage,
            path: proposal.path,
            value: proposal.value,
            altValue: proposal.altValue || '',
            currentHash: proposal.currentHash || '',
            allowedValueHashes: proposal.allowedValueHashes || [],
            proposalSignature: proposal.proposalSignature || '',
            confirmed: '1'
        }).then(result => {
            if (proposalPage === contentPage) {
                patchPageField(result.path, result.value, result.altValue || '');
            }
            return result;
        });
    }

    function rememberAppliedImage(proposal, result) {
        const value = result && result.value ? result.value : (proposal.value || proposal.preview || '');
        if (!value) return;
        state.lastImageResult = {
            path: value,
            alt: result && result.altValue ? result.altValue : (proposal.altValue || ''),
            prompt: proposal.reason || state.lastInstruction || '',
            field: proposal.path || '',
            label: proposal.label || proposal.path || ''
        };
    }

    function appliedMessageForProposal(proposal) {
        if (proposal && proposal.type === 'image') {
            return label('copilot.applied_image_message', 'Applied the generated image to {field}. A backup was created before saving.', { field: proposal.label || proposal.path });
        }
        return label('copilot.applied_message', 'Applied the draft to {field}. A backup was created before saving.', { field: proposal.label || proposal.path });
    }

    function copyProposal(index) {
        const proposal = state.proposals[index];
        if (!proposal) return;
        const value = proposal.type === 'image'
            ? (proposal.value || proposal.preview || '')
            : (proposal.value != null ? proposal.value : proposal.preview || '');
        const text = String(value || '');
        if (!text) return;
        copyText(text).then(() => {
            state.messages.push({
                role: 'assistant',
                content: label('copilot.copied_message', 'Copied the draft value for {field}.', { field: proposal.label || proposal.path })
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(renderMessages);
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise((resolve, reject) => {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy') ? resolve() : reject(new Error(label('copilot.copy_failed', 'Could not copy the draft value.')));
            } catch (error) {
                reject(error);
            } finally {
                textarea.remove();
            }
        });
    }

    function selectImageVariant(value) {
        const parts = String(value || '').split(':');
        const proposalIndex = Number(parts[0]);
        const variantIndex = Number(parts[1]);
        const proposal = Number.isInteger(proposalIndex) ? state.proposals[proposalIndex] : null;
        if (!proposal || !Array.isArray(proposal.paths) || !proposal.paths[variantIndex]) return;
        proposal.value = proposal.paths[variantIndex];
        proposal.preview = proposal.paths[variantIndex];
        renderMessages();
    }

    function selectImageDraftField(fieldId) {
        if (!state.imageDraft || !fieldId) return;
        const match = state.imageDraft.fields.find(field => (field.id || field.path) === fieldId);
        if (!match) return;
        state.imageDraft.fieldId = fieldId;
        updateImageDraftSizeFromField(match);
        renderMessages();
    }

    function selectImageDraftMode(mode) {
        if (!state.imageDraft || !['generate', 'edit'].includes(mode)) return;
        state.imageDraft.mode = mode;
        renderMessages();
    }

    function updateImageDraftOption(name, value) {
        if (!state.imageDraft || !name) return;
        const allowed = {
            size: ['auto', '1024x1024', '1536x1024', '1024x1536'],
            count: ['1', '2', '3', '4'],
            outputFormat: ['webp', 'png', 'jpeg'],
            quality: ['auto', 'low', 'medium', 'high']
        };
        if (!allowed[name] || !allowed[name].includes(String(value))) return;
        state.imageDraft.options = Object.assign({}, state.imageDraft.options || {}, { [name]: String(value) });
        if (name === 'size') state.imageDraft.sizeTouched = true;
    }

    async function undoLastApply() {
        const undo = state.lastApplied && state.lastApplied.undo;
        if (!undo || !undo.backup) return;
        const confirmed = await confirmCopilot({
            title: label('copilot.undo_confirm_title', 'Undo last AI change?'),
            body: label('copilot.undo_confirm', 'Undo the last AI change on this page? The current state will be backed up first.'),
            confirmLabel: label('copilot.undo', 'Undo')
        });
        if (!confirmed) return;
        setLoading(true, 'copilot.working_undo', 'Restoring the previous version...');
        renderMessages();
        post('ai-copilot-undo', {
            contentPage: undo.contentPage || contentPage,
            backup: undo.backup,
            path: undo.path || state.lastApplied.path || '',
            undoSignature: undo.undoSignature || '',
            confirmed: '1'
        }).then(() => {
            state.lastApplied = null;
            state.messages.push({
                role: 'assistant',
                content: label('copilot.undo_message', 'Restored the page from the backup. Reloading the page now.')
            });
            renderMessages();
            window.setTimeout(() => window.location.reload(), 650);
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    async function createContentDraft(options) {
        if (!state.contentDraft || !state.contentDraft.draft) return;
        const draft = state.contentDraft.draft;
        if (!draft.canCreate) return;
        const confirmed = options && options.skipConfirm
            ? true
            : await confirmCopilot({
                title: label('copilot.create_confirm_title', 'Create draft?'),
                body: label('copilot.create_confirm', 'Create this {type} draft?', { type: draft.contentType }),
                items: Object.entries(draft.draft || {}).slice(0, 6).map(([key, value]) => key + ': ' + nibblyShortSummary(value)),
                confirmLabel: label('copilot.create_draft', 'Create draft')
            });
        if (!confirmed) return;
        setLoading(true, 'copilot.working_create', 'Creating the confirmed draft...');
        renderMessages();
        post('ai-copilot-create-content', {
            draft,
            confirmed: '1'
        }).then(result => {
            state.contentDraft = null;
            state.createdContent = result;
            let createdMessage = label('copilot.created_message', 'Created {type} draft "{id}". It is not published publicly by default.', {
                type: result.contentType,
                id: result.id
            });
            if (result.contentType === 'page') {
                createdMessage += '\n\n' + label('copilot.created_page_navigation_hint', 'Open the page in the Content Editor and set Page Settings / Navigation if it should appear in a menu.');
            }
            state.messages.push({
                role: 'assistant',
                content: createdMessage
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    async function publishCreatedContent(options) {
        const created = state.createdContent;
        if (!created || !created.id || !created.contentType) return;
        const confirmed = options && options.skipConfirm
            ? true
            : await confirmCopilot({
                title: label('copilot.publish_confirm_title', 'Publish draft?'),
                body: label('copilot.publish_confirm', 'Publish this draft now?'),
                items: [created.contentType + ': ' + created.id],
                confirmLabel: label('copilot.publish', 'Publish')
            });
        if (!confirmed) return;
        setLoading(true, 'copilot.working_publish', 'Publishing the confirmed draft...');
        renderMessages();
        post('ai-copilot-publish-content', {
            contentType: created.contentType,
            id: created.id,
            confirmed: '1'
        }).then(result => {
            state.createdContent = Object.assign({}, created, result, {
                private: false,
                hidden: false,
                publishable: false
            });
            state.messages.push({
                role: 'assistant',
                content: label('copilot.published_message', 'Published {type} "{id}".', {
                    type: result.contentType || created.contentType,
                    id: result.id || created.id
                })
            });
        }).catch(error => {
            state.messages.push({ role: 'assistant', content: error.message });
        }).finally(() => {
            setLoading(false);
            renderMessages();
        });
    }

    function patchPageField(path, value, altValue) {
        if (!path) return;
        const selectors = [fieldSelector(path)];
        if (path.endsWith('.href') || path.endsWith('.url') || path.endsWith('.text') || path.endsWith('.label') || path.endsWith('.title')) {
            selectors.push(fieldSelector(path.replace(/\.(href|url|text|label|title)$/, '')));
        }
        if (path.endsWith('.src') || path.endsWith('.image')) {
            selectors.push(fieldSelector(path.replace(/\.(src|image)$/, '')));
        } else {
            selectors.push(fieldSelector(path + '.src'), fieldSelector(path + '.image'));
        }
        document.querySelectorAll(unique(selectors).join(',')).forEach(element => {
            if (element.matches('img[data-editable-image]')) {
                element.setAttribute('src', value);
                if (altValue) {
                    element.setAttribute('alt', altValue);
                    patchAltField(element.getAttribute('data-alt-field'), altValue);
                }
            } else if (element.matches('a[data-editable-link]')) {
                if (path.endsWith('.text') || path.endsWith('.label') || path.endsWith('.title')) {
                    element.textContent = value;
                } else {
                    element.setAttribute('href', value);
                }
            } else if (element.classList.contains('editable-field-html')) {
                element.innerHTML = value;
            } else {
                element.textContent = value;
            }
        });
    }

    function patchVisibility(path, hidden) {
        if (!path) return;
        const selectors = [fieldSelector(path)];
        if (path.endsWith('.href') || path.endsWith('.url') || path.endsWith('.text') || path.endsWith('.label') || path.endsWith('.title')) {
            selectors.push(fieldSelector(path.replace(/\.(href|url|text|label|title)$/, '')));
        }
        if (path.endsWith('.src') || path.endsWith('.image')) {
            selectors.push(fieldSelector(path.replace(/\.(src|image)$/, '')));
        } else {
            selectors.push(fieldSelector(path + '.src'), fieldSelector(path + '.image'));
        }
        document.querySelectorAll(unique(selectors).join(',')).forEach(element => {
            if (hidden) {
                element.setAttribute('data-hidden', 'true');
                element.classList.add('editable-hidden');
            } else {
                element.removeAttribute('data-hidden');
                element.classList.remove('editable-hidden');
            }
        });
    }

    function patchAltField(altField, altValue) {
        if (!altField || !altValue) return;
        document.querySelectorAll(fieldSelector(altField)).forEach(element => {
            if (element.matches('input, textarea')) {
                element.value = altValue;
            } else if (element.classList.contains('editable-field-html')) {
                element.innerHTML = altValue;
            } else {
                element.textContent = altValue;
            }
        });
    }

    function fieldSelector(fieldPath) {
        return '[data-page="' + cssEscape(contentPage) + '"][data-field="' + cssEscape(fieldPath) + '"]';
    }

    function unique(values) {
        return values.filter((value, index) => values.indexOf(value) === index);
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function sessionKey() {
        return 'nibbly-ai-assistant:' + copilotMode + ':' + (contentPage || window.location.pathname || 'site');
    }

    function newSessionId() {
        return 'chat-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function restoreSession() {
        try {
            const raw = window.sessionStorage ? window.sessionStorage.getItem(sessionKey()) : '';
            if (!raw) {
                state.currentSessionId = newSessionId();
                return;
            }
            const data = JSON.parse(raw);
            state.currentSessionId = typeof data.currentSessionId === 'string' && data.currentSessionId
                ? data.currentSessionId.slice(0, 80)
                : newSessionId();
            if (data && Array.isArray(data.messages)) {
                state.messages = data.messages
                    .filter(message => message && (message.role === 'assistant' || message.role === 'user') && typeof message.content === 'string')
                    .slice(-30)
                    .map(message => ({
                        role: message.role,
                        content: message.content.slice(0, 3000)
                    }));
            }
            state.lastInstruction = typeof data.lastInstruction === 'string' ? data.lastInstruction.slice(0, 3000) : '';
            state.lastImageResult = data.lastImageResult && typeof data.lastImageResult.path === 'string'
                ? {
                    path: data.lastImageResult.path.slice(0, 500),
                    alt: String(data.lastImageResult.alt || '').slice(0, 500),
                    prompt: String(data.lastImageResult.prompt || '').slice(0, 1000),
                    field: String(data.lastImageResult.field || '').slice(0, 500),
                    label: String(data.lastImageResult.label || '').slice(0, 500)
                }
                : null;
            state.open = data.open === true;
            state.maximized = data.maximized === true;
        } catch (error) {
            // Ignore broken browser storage and continue with a fresh assistant session.
            state.currentSessionId = newSessionId();
        }
    }

    function persistSession() {
        try {
            if (!window.sessionStorage) return;
            window.sessionStorage.setItem(sessionKey(), JSON.stringify({
                messages: state.messages.slice(-30).map(message => ({
                    role: message.role,
                    content: String(message.content || '').slice(0, 3000)
                })),
                currentSessionId: state.currentSessionId || '',
                lastInstruction: String(state.lastInstruction || '').slice(0, 3000),
                lastImageResult: state.lastImageResult,
                open: state.open === true,
                maximized: state.maximized === true
            }));
            archiveSessionDebounced();
        } catch (error) {
            // Storage limits or privacy settings should not break editing.
        }
    }

    function archiveSessionDebounced() {
        if (state.sessionExpired || state.historySaving || !state.currentSessionId) return;
        if (!state.messages.some(message => message.role === 'user')) return;
        if (state.historyTimer) window.clearTimeout(state.historyTimer);
        state.historyTimer = window.setTimeout(saveChatHistoryNow, 1200);
    }

    function saveChatHistoryNow() {
        if (state.sessionExpired || state.historySaving || !state.currentSessionId) return;
        if (!state.messages.some(message => message.role === 'user')) return;
        state.historySaving = true;
        const page = state.context && state.context.page ? state.context.page : {};
        const firstUserMessage = state.messages.find(message => message.role === 'user');
        post('ai-copilot-history-save', {
            id: state.currentSessionId,
            contentPage,
            pageTitle: page.title || document.title || '',
            url: window.location.href,
            title: firstUserMessage ? firstUserMessage.content : '',
            messages: state.messages.map(message => ({ role: message.role, content: message.content })),
            lastInstruction: state.lastInstruction || '',
            lastImageResult: state.lastImageResult || null
        }).then(result => {
            const chat = result && result.chat ? result.chat : null;
            if (chat && chat.id) {
                state.currentSessionId = chat.id;
            }
        }).catch(() => {
            // Archiving must never interrupt editing.
        }).finally(() => {
            state.historySaving = false;
        });
    }

    window.NibblyAiCopilot = {
        open: () => setOpen(true),
        close: () => setOpen(false),
        maximize: () => setMaximized(true),
        minimize: () => setMaximized(false)
    };
    window.NibblyAiAssistant = window.NibblyAiCopilot;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            restoreSession();
            renderShell();
            startImageJobPolling('');
            if (state.open) setOpen(true);
        });
    } else {
        restoreSession();
        renderShell();
        startImageJobPolling('');
        if (state.open) setOpen(true);
    }
})();
