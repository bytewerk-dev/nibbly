/**
 * AI agent for the frontend assistant (agent phase P7).
 *
 * The agent works through a request on the server (js only drives the steps and shows
 * what happened), prepares a change package and waits. Nothing is applied except by the
 * button of the package card; a "yes" typed into the chat never applies anything.
 *
 * It plugs into js/ai-copilot.js through NibblyAiCopilot.registerAgent(): the assistant
 * calls render() for the cards below the chat messages and hands over every message while
 * the agent mode is on. All text from the server (summaries, content, sources) is inserted
 * escaped, links only when they are http(s), and nothing is ever evaluated.
 */
(function() {
    'use strict';

    const config = window.NB_AI_AGENT;
    if (!config || config.available !== true || !window.NibblyAiCopilot || typeof window.NibblyAiCopilot.registerAgent !== 'function') {
        return;
    }

    const MODE_KEY = 'nibbly.aiAgent.mode';
    const RUN_KEY = 'nibbly-ai-agent:run';
    const LAST_RUN_KEY = 'nibbly-ai-agent:last-run'; // only the run id, shared by all tabs, so a new tab finds the open package again
    const LAST_RUN_MAX_AGE = 48 * 3600 * 1000;
    const rights = config.rights && typeof config.rights === 'object' ? config.rights : {};
    const maxUploadFiles = Number(config.maxUploadFiles) > 0 ? Number(config.maxUploadFiles) : 6;
    const maxUploadBytes = Number(config.maxUploadBytes) > 0 ? Number(config.maxUploadBytes) : 10 * 1024 * 1024;
    const stepTimeoutMs = 75000;
    const visibleSteps = 6;
    const TOOL_STEPS = [
        'get_site_overview', 'get_page_capabilities', 'read_content', 'list_content', 'list_media', 'list_run_media', 'search_stock_images', 'import_stock_image',
        'generate_image', 'search_web', 'read_web_result', 'read_url', 'stage_set_field', 'stage_set_image', 'stage_set_page_menus', 'stage_add_list_item', 'stage_hide_list_item', 'stage_remove_list_item',
        'stage_move_list_item', 'stage_add_section', 'stage_create_news', 'stage_create_event', 'stage_create_page', 'stage_revert_op', 'validate_changeset', 'ask_user', 'present_changeset'
    ];

    let host = null;
    let root = null;

    const state = {
        mode: readMode(),
        run: null,
        review: null,
        applied: null,
        chips: [],
        attachedChangeset: '',
        uploads: 0,
        dragging: false,
        busyAction: '',
        notice: null,
        lastGoal: '',
        driving: false,
        awaitingChanges: false,
        openDetails: {},
        lastHtml: ''
    };

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    function label(key, fallback, params) {
        return host ? host.label(key, fallback, params) : fallback;
    }

    function esc(value) {
        return host ? host.escHtml(value) : String(value == null ? '' : value).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    }

    function sleep(ms) {
        return new Promise(resolve => window.setTimeout(resolve, ms));
    }

    function readMode() {
        try {
            return window.localStorage && window.localStorage.getItem(MODE_KEY) === 'classic' ? 'classic' : 'agent';
        } catch (error) {
            return 'agent';
        }
    }

    function writeMode(mode) {
        try {
            if (window.localStorage) window.localStorage.setItem(MODE_KEY, mode);
        } catch (error) {
            // A blocked storage only means the choice is not remembered.
        }
    }

    function persistRun() {
        try {
            if (!window.sessionStorage) return;
            if (!state.run || !state.run.runId) {
                window.sessionStorage.removeItem(RUN_KEY);
                if (window.localStorage) window.localStorage.removeItem(LAST_RUN_KEY);
                return;
            }
            const stored = { runId: state.run.runId, goal: String(state.lastGoal || '').slice(0, 2000) };
            window.sessionStorage.setItem(RUN_KEY, JSON.stringify(stored));
            if (window.localStorage) window.localStorage.setItem(LAST_RUN_KEY, JSON.stringify({ at: Date.now(), runId: stored.runId }));
        } catch (error) {
            // Storage limits or privacy settings must not break editing.
        }
    }

    function readStoredRun() {
        try {
            const valid = data => data && /^run_[a-z0-9]{6,}$/.test(String(data.runId || '')) ? data : null;
            const own = valid(JSON.parse((window.sessionStorage && window.sessionStorage.getItem(RUN_KEY)) || 'null'));
            if (own) return own;
            // Another tab of this browser started it (or this tab was opened fresh): the open package is still there.
            const shared = valid(JSON.parse((window.localStorage && window.localStorage.getItem(LAST_RUN_KEY)) || 'null'));
            return shared && Date.now() - Number(shared.at || 0) < LAST_RUN_MAX_AGE ? shared : null;
        } catch (error) {
            return null;
        }
    }

    // The preview page sends the person back here with #nb-agent-open or #nb-agent-apply={id}. Applying still
    // goes through the confirmation of this assistant; the hash only opens it.
    function launchRequest() {
        const match = /^#nb-agent-(apply|open)(?:=(cs_[a-f0-9]{8,}))?$/.exec(window.location.hash || '');
        return match ? { kind: match[1], id: match[2] || '' } : null;
    }

    function clearLaunchHash() {
        try {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        } catch (error) {
            // The hash stays; a reload would ask again at most.
        }
    }

    // final: nothing more will load, so a request that found no matching package is dropped.
    function handleLaunchRequest(final) {
        const request = launchRequest();
        if (!request || !host) return;
        if (request.kind === 'open') {
            clearLaunchHash();
            host.open();
            return;
        }
        const review = state.review;
        if (review && review.status === 'ready' && review.id === request.id) {
            clearLaunchHash();
            host.open();
            applyPackage();
        } else if (final) {
            clearLaunchHash();
            host.open();
        }
    }

    function apiGet(action, params) {
        const url = new URL(host.apiUrl, window.location.href);
        url.searchParams.set('action', action);
        Object.entries(params || {}).forEach(([key, value]) => url.searchParams.set(key, String(value)));
        return fetch(url.href, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(async response => {
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || data.success === false) {
                const error = new Error((data && data.message) || label('copilot.request_failed', 'AI Assistant request failed.'));
                error.status = response.status;
                throw error;
            }
            return data.data;
        });
    }

    function mediaUrl(changesetId, ref) {
        const url = new URL(host.apiUrl, window.location.href);
        url.searchParams.set('action', 'ai-agent-media');
        url.searchParams.set('id', changesetId);
        url.searchParams.set('ref', ref);
        return url.href;
    }

    function safeHttpUrl(value) {
        try {
            const url = new URL(String(value || ''));
            return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function safeLocalPath(value) {
        const path = String(value || '');
        return /^\/(?!\/)[^\s\\]*$/.test(path) ? path : '';
    }

    /** Readable text of a stored value: HTML is reduced to its text (the parser never runs scripts). */
    function plainText(value, limit, keepBreaks) {
        let text = String(value == null ? '' : value);
        if (/<[a-z!/][^>]*>/i.test(text)) {
            // Block ends become line breaks, otherwise headings and paragraphs run together.
            if (keepBreaks) text = text.replace(/<\/(p|h[1-6]|li|div|blockquote|tr)>|<br\s*\/?>/gi, '$&\n');
            try {
                text = new DOMParser().parseFromString(text, 'text/html').body.textContent || '';
            } catch (error) {
                text = text.replace(/<[^>]*>/g, ' ');
            }
        }
        text = keepBreaks
            ? text.replace(/[ \t\f\v\r]+/g, ' ').replace(/ ?\n ?/g, '\n').replace(/\n{2,}/g, '\n').trim()
            : text.replace(/\s+/g, ' ').trim();
        const max = limit || 400;
        return text.length > max ? text.slice(0, max - 1).trimEnd() + '…' : text;
    }

    function icon(name) {
        const icons = {
            attach: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21.4 11.1-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg>',
            ok: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
            failed: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
            warning: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 2.5 20h19L12 3.5Z"/><path d="M12 10v4.5M12 17.5v.1"/></svg>',
            ai: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3Z"/></svg>'
        };
        return icons[name] || '';
    }

    // ------------------------------------------------------------------
    // Run state
    // ------------------------------------------------------------------

    function isWorking() {
        return !!state.run && (state.run.status === 'running' || state.run.status === 'waiting_jobs');
    }

    function isBusy() {
        return isWorking() || state.uploads > 0 || state.busyAction !== '';
    }

    function applyRunState(data, live) {
        if (!data || !data.runId) return;
        const previous = state.run && state.run.runId === data.runId ? state.run.status : '';
        state.run = Object.assign({}, state.run && state.run.runId === data.runId ? state.run : {}, data);
        if (data.status !== 'needs_input' && data.status !== 'awaiting_review') state.awaitingChanges = false;
        if (Array.isArray(data.limitWarnings) && host.setLimitWarnings) host.setLimitWarnings(data.limitWarnings);
        // The answer and the summary belong in the chat, so the history keeps them. When a run is picked up again
        // (reload, a new login, another tab) the chat may lack them, so they are added unless they are there already.
        const addToChat = text => {
            if (text && (live !== false || !(typeof host.hasMessage === 'function' && host.hasMessage('assistant', text)))) host.pushMessage('assistant', text);
        };
        if (data.status === 'needs_input' && previous !== 'needs_input') addToChat(String(data.question || '').trim());
        if (data.status === 'awaiting_review' && previous !== 'awaiting_review') {
            addToChat(String(data.summary || '').trim());
            state.review = null;
            state.reviewLoading = loadReview();
        }
        if (data.status === 'cancelled' && previous !== 'cancelled') {
            // Whether the cancel was immediate or caught by a running step, the run ends here.
            host.pushMessage('assistant', label('agent.cancelled', 'The agent was cancelled. Nothing was changed.'));
            clearRun();
            return;
        }
        if (data.status === 'failed') {
            state.notice = { type: 'error', text: String(data.error || label('agent.error_generic', 'The agent could not finish.')), retry: !!state.lastGoal };
        }
        persistRun();
    }

    async function drive() {
        if (state.driving) return;
        state.driving = true;
        let failures = 0;
        try {
            while (isWorking()) {
                const runId = state.run.runId;
                let data;
                try {
                    data = await host.post('ai-agent-step', { runId }, stepTimeoutMs);
                    failures = 0;
                } catch (error) {
                    if (error && error.sessionExpired) throw error;
                    failures += 1;
                    if (failures >= 3) throw error;
                    await sleep(1500 * failures);
                    continue;
                }
                if (!state.run || state.run.runId !== runId) break;
                if (data && data.status === 'busy') {
                    await sleep(1200);
                    continue;
                }
                applyRunState(data);
                update();
                await sleep(state.run && state.run.status === 'waiting_jobs' ? 2500 : 150);
            }
        } catch (error) {
            state.notice = { type: 'error', text: String(error && error.message ? error.message : label('agent.error_connection', 'The connection was interrupted.')), resume: true };
        } finally {
            state.driving = false;
            update();
            if (host) host.updateActions();
        }
    }

    async function startRun(goal) {
        state.lastGoal = goal;
        state.notice = null;
        state.review = null;
        state.applied = null;
        state.run = null;
        host.pushMessage('user', goal);
        const payload = { goal };
        if (state.attachedChangeset) payload.changesetId = state.attachedChangeset;
        state.busyAction = 'start';
        update();
        try {
            const data = await host.post('ai-agent-start', payload);
            state.chips = [];
            state.attachedChangeset = '';
            applyRunState(data, true);
        } catch (error) {
            state.notice = { type: 'error', text: error.message };
        } finally {
            state.busyAction = '';
        }
        update();
        drive();
    }

    async function replyRun(message) {
        host.pushMessage('user', message);
        state.notice = null;
        state.busyAction = 'reply';
        update();
        try {
            const data = await host.post('ai-agent-reply', { runId: state.run.runId, message });
            if (data && data.status === 'busy') {
                state.notice = { type: 'info', text: label('agent.busy', 'The agent is still working. Try again in a moment.') };
            } else {
                state.review = null;
                applyRunState(data, true);
            }
        } catch (error) {
            state.notice = { type: 'error', text: error.message };
        } finally {
            state.busyAction = '';
        }
        update();
        drive();
    }

    async function handleMessage(content) {
        const text = String(content || '').trim();
        if (!text) return true;
        const run = state.run;
        const reviewOpen = !!state.review && ['staging', 'ready', 'conflict', 'failed'].includes(state.review.status);
        if (run && (run.status === 'needs_input' || (run.status === 'awaiting_review' && (reviewOpen || !state.review)))) {
            await replyRun(text);
            return true;
        }
        if (isBusy()) return true;
        if (state.applied && run) {
            host.pushMessage('assistant', label('agent.applied_summary', 'The previous package was applied.'));
        }
        await startRun(text);
        return true;
    }

    async function cancelRun() {
        if (!state.run || state.busyAction === 'cancel') return;
        state.busyAction = 'cancel';
        update();
        try {
            const data = await host.post('ai-agent-cancel', { runId: state.run.runId });
            if (data && data.status === 'cancelled') {
                applyRunState(Object.assign({}, state.run, data));
            } else if (data && data.status === 'cancelling') {
                state.notice = { type: 'info', text: label('agent.cancelling', 'Cancelling…') };
            }
        } catch (error) {
            state.notice = { type: 'error', text: error.message };
        } finally {
            state.busyAction = '';
        }
        update();
        drive();
    }

    // ------------------------------------------------------------------
    // The package
    // ------------------------------------------------------------------

    async function loadReview() {
        const id = state.run && state.run.changesetId;
        if (!id) return;
        try {
            const data = await apiGet('ai-agent-changeset', { id });
            state.review = data;
            if (data.status === 'applied' && data.applied) {
                state.applied = { items: Array.isArray(data.applied.items) ? data.applied.items : [], undoToken: String(data.applied.undoToken || '') };
            } else if (data.status !== 'applied') {
                state.applied = null;
            }
        } catch (error) {
            state.notice = { type: 'error', text: error.message };
        }
        update();
        handleLaunchRequest(false);
    }

    async function applyPackage() {
        const review = state.review;
        if (!review || review.status !== 'ready' || isBusy()) return;
        const operations = Object.values(review.targets || {}).reduce((total, list) => total + list.length, 0);
        const targets = Object.keys(review.targets || {}).map(targetLabel);
        // New pages, posts, events, sections and list items start hidden; changes to existing content are live at once.
        const createsContent = Object.values(review.targets || {}).some(list => list.some(op => ['create_news', 'create_event', 'create_page', 'section_insert', 'list_insert'].includes(op.type)));
        const confirmed = await host.confirm({
            title: label('agent.apply_confirm_title', 'Apply this package?'),
            body: createsContent
                ? label('agent.apply_confirm_body', '{count} changes will be saved. Changes to existing content go live right away; new content stays hidden until you publish it. You can undo the package afterwards.', { count: operations })
                : label('agent.apply_confirm_body_live', '{count} changes will be saved and go live right away. You can undo the package afterwards.', { count: operations }),
            items: targets,
            confirmLabel: label('agent.apply_confirm_button', 'Apply')
        });
        if (!confirmed) return;
        state.busyAction = 'apply';
        state.notice = null;
        update();
        try {
            const data = await host.post('ai-agent-apply', { id: review.id, previewHash: review.previewHash, confirmed: '1' });
            state.applied = { items: Array.isArray(data.items) ? data.items : [], undoToken: String(data.undoToken || '') };
            host.pushMessage('assistant', label('agent.applied_title', 'Changes applied'));
        } catch (error) {
            state.notice = { type: 'error', text: label('agent.apply_failed', 'The package could not be applied. {message}', { message: error.message }) };
        } finally {
            state.busyAction = '';
        }
        await loadReview();
    }

    async function discardPackage() {
        const review = state.review;
        if (!review || isBusy()) return;
        const confirmed = await host.confirm({
            title: label('agent.discard_confirm_title', 'Discard this package?'),
            body: label('agent.discard_confirm_body', 'The staged changes and images are deleted. Nothing on your site changes.'),
            confirmLabel: label('agent.discard_confirm_button', 'Discard')
        });
        if (!confirmed) return;
        state.busyAction = 'discard';
        update();
        try {
            await host.post('ai-agent-discard', { id: review.id });
            host.pushMessage('assistant', label('agent.discarded', 'The package was discarded. Nothing was changed.'));
            clearRun();
        } catch (error) {
            state.notice = { type: 'error', text: error.message };
        } finally {
            state.busyAction = '';
        }
        update();
    }

    async function revalidatePackage() {
        const review = state.review;
        if (!review || isBusy()) return;
        state.busyAction = 'revalidate';
        state.notice = null;
        update();
        try {
            await host.post('ai-agent-revalidate', { id: review.id });
        } catch (error) {
            state.notice = { type: 'error', text: error.message };
        } finally {
            state.busyAction = '';
        }
        await loadReview();
    }

    async function undoPackage() {
        const review = state.review;
        if (!review || !state.applied || isBusy()) return;
        const confirmed = await host.confirm({
            title: label('agent.undo_confirm_title', 'Undo this package?'),
            body: label('agent.undo_confirm_body', 'The affected content returns to the state before the package.'),
            confirmLabel: label('agent.undo', 'Undo')
        });
        if (!confirmed) return;
        state.busyAction = 'undo';
        state.notice = null;
        update();
        try {
            await host.post('ai-agent-undo', { id: review.id, token: state.applied.undoToken });
            host.pushMessage('assistant', label('agent.undone', 'The package was undone.'));
        } catch (error) {
            state.notice = { type: 'error', text: error.message };
        } finally {
            state.busyAction = '';
        }
        await loadReview();
    }

    async function publishItem(index) {
        const item = state.applied && state.applied.items[index];
        if (!item || item.published || isBusy()) return;
        const confirmed = await host.confirm({
            title: label('agent.publish_confirm_title', 'Publish this item?'),
            body: label('agent.publish_confirm_body', 'It becomes visible to visitors.'),
            items: [itemLabel(item)],
            confirmLabel: label('agent.publish', 'Publish')
        });
        if (!confirmed) return;
        state.busyAction = 'publish';
        state.notice = null;
        update();
        try {
            const payload = { kind: item.kind, target: item.target };
            ['listKey', 'index', 'sectionId'].forEach(key => {
                if (item[key] !== undefined && item[key] !== null) payload[key] = item[key];
            });
            await host.post('ai-agent-publish', { id: state.review.id, item: payload });
            item.published = true;
        } catch (error) {
            state.notice = { type: 'error', text: error.message };
        } finally {
            state.busyAction = '';
        }
        update();
    }

    function openPreview(index) {
        const review = state.review;
        if (!review || review.status !== 'ready') return;
        const link = (review.previewLinks || [])[index];
        const path = link ? safeLocalPath(link.url) : '';
        if (!path) return;
        const url = new URL(path, window.location.origin);
        url.searchParams.set('nb_agent_preview', review.id);
        // The preview opens in this tab and sends the person back here, where the package is still open.
        url.searchParams.set('nb_agent_return', window.location.pathname + window.location.search);
        window.location.assign(url.href);
    }

    function clearRun() {
        state.run = null;
        state.review = null;
        state.applied = null;
        state.notice = null;
        state.awaitingChanges = false;
        persistRun();
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    function targetLabel(target) {
        const value = String(target || '');
        const separator = value.indexOf(':');
        const kind = separator > 0 ? value.slice(0, separator) : '';
        const name = separator > 0 ? value.slice(separator + 1) : value;
        if (value === 'page:footer' || value === 'page:sidebar') return label('agent.target_shared', 'Shared content: {name}', { name });
        if (kind === 'news') return label('agent.target_news', 'News post: {name}', { name });
        if (kind === 'event') return label('agent.target_event', 'Event: {name}', { name });
        return label('agent.target_page', 'Page: {name}', { name });
    }

    function itemLabel(item) {
        const kinds = { news: 'agent.item_news', event: 'agent.item_event', page: 'agent.item_page', section: 'agent.item_section', list_item: 'agent.item_list_item' };
        const fallbacks = { news: 'News post', event: 'Event', page: 'Page', section: 'Section', list_item: 'List item' };
        return label(kinds[item.kind] || 'agent.item_page', fallbacks[item.kind] || 'Item') + ': ' + String(item.target || '').replace(/^[a-z]+:/, '');
    }

    function stepText(tool, detail) {
        const name = TOOL_STEPS.includes(tool) ? tool : '';
        const clean = plainText(detail, 80);
        if (!name) return label('agent.step.unknown', 'Working');
        return label('agent.step.' + name, 'Working', { detail: clean });
    }

    function eventDetail(event) {
        const raw = String(event.label || '');
        const tool = String(event.tool || '');
        if (tool === 'read_web_result') return '';
        if (tool === 'read_url') {
            try {
                return new URL(raw.slice(tool.length).trim()).hostname;
            } catch (error) {
                return '';
            }
        }
        return raw.indexOf(tool) === 0 ? raw.slice(tool.length).trim() : '';
    }

    /** Finished steps, plus what the agent is about to do (the tools of the latest turn that did not run yet). */
    function timeline() {
        const steps = [];
        let pending = [];
        (state.run && Array.isArray(state.run.events) ? state.run.events : []).forEach(event => {
            if (event.type === 'turn') {
                pending = Array.isArray(event.tools) ? event.tools.slice() : [];
            } else if (event.type === 'tool') {
                pending.shift();
                steps.push({ tool: String(event.tool || ''), detail: eventDetail(event), ok: event.ok !== false });
            } else if (event.type === 'reply' || event.type === 'start') {
                pending = [];
            }
        });
        return { steps, next: pending.length ? pending[0] : '' };
    }

    function renderRunCard() {
        const run = state.run;
        if (!run) return '';
        const working = isWorking();
        const { steps, next } = timeline();
        if (!working && !steps.length) return '';
        let headline = '';
        if (working) {
            if (run.status === 'waiting_jobs') headline = label('agent.status_waiting_image', 'Waiting for the image…');
            else headline = next ? stepText(next, '') + '…' : label('agent.status_thinking', 'Thinking…');
        }
        const items = steps.map(step => '<li class="' + (step.ok ? 'is-ok' : 'is-failed') + '">' +
            '<span class="nb-agent-step__mark">' + icon(step.ok ? 'ok' : 'failed') + '</span>' +
            '<span>' + esc(stepText(step.tool, step.detail)) + (step.ok ? '' : ' <em>(' + esc(label('agent.step_failed', 'did not work')) + ')</em>') + '</span></li>');
        let list = '';
        if (working) {
            list = items.length ? '<ol class="nb-agent-steps">' + items.slice(-visibleSteps).join('') + '</ol>' : '';
        } else if (items.length) {
            const open = state.openDetails.steps === true ? ' open' : '';
            list = '<details class="nb-agent-steps-details" data-agent-details="steps"' + open + '><summary>' +
                esc(label('agent.steps_title', '{count} steps', { count: items.length })) + '</summary><ol class="nb-agent-steps">' + items.join('') + '</ol></details>';
        }
        const head = working
            ? '<div class="nb-agent-run__head"><span class="nb-copilot-spinner" aria-hidden="true"></span><strong>' + esc(headline) + '</strong>' +
                '<button type="button" class="nb-agent-link" data-agent-action="cancel"' + (state.busyAction === 'cancel' ? ' disabled' : '') + '>' + esc(label('agent.cancel', 'Cancel')) + '</button></div>'
            : '';
        return '<section class="nb-agent-run" aria-label="' + esc(label('agent.title', 'AI agent')) + '">' + head + list + '</section>';
    }

    function describeOpValue(value) {
        const text = String(value == null ? '' : value);
        if (text === '') return '<span class="nb-agent-empty">' + esc(label('agent.empty_value', '(empty)')) + '</span>';
        if (text[0] === '{') {
            try {
                const object = JSON.parse(text);
                if (object && typeof object === 'object') {
                    const parts = [];
                    ['title', 'date', 'time', 'location', 'excerpt', 'description', 'content'].forEach(key => {
                        if (object[key]) parts.push('<strong>' + esc(key) + '</strong> ' + esc(plainText(object[key], 240)));
                    });
                    if (Array.isArray(object.sections)) parts.push('<strong>sections</strong> ' + esc(String(object.sections.length)));
                    if (parts.length) return parts.join('<br>');
                }
            } catch (error) {
                // Not JSON: show it as text.
            }
        }
        return esc(plainText(text, 400, true));
    }

    // A package of a few changes shows them right away; a long one stays folded.
    const OPEN_OPERATIONS_UP_TO = 4;

    function renderOperation(op, openByDefault) {
        const key = 'op:' + op.opId;
        const stored = state.openDetails[key];
        const open = (stored === undefined ? openByDefault : stored === true) ? ' open' : '';
        return '<li class="nb-agent-op' + (op.destructive ? ' is-destructive' : '') + '"><details data-agent-details="' + esc(key) + '"' + open + '><summary>' + esc(op.label) +
            (op.destructive ? ' <span class="nb-agent-tag nb-agent-tag--danger">' + esc(label('agent.destructive', 'Deletes content')) + '</span>' : '') + '</summary>' +
            '<div class="nb-agent-diff"><div><span>' + esc(label('agent.before', 'Before')) + '</span><p>' + describeOpValue(op.before) + '</p></div>' +
            '<div><span>' + esc(label('agent.after', 'After')) + '</span><p>' + describeOpValue(op.after) + '</p></div></div></details></li>';
    }

    function renderMedia(review) {
        const entries = Object.entries(review.media || {});
        if (!entries.length) return '';
        const cards = entries.map(([ref, media]) => {
            const source = String(media.source || '');
            const sourceLabel = source === 'stock' ? label('agent.image_stock', 'Stock photo')
                : source === 'generated' ? label('agent.image_generated', 'Generated image') : label('agent.image_upload', 'Your upload');
            const credit = media.credit && media.credit.attribution ? '<span class="nb-agent-media__credit">' + esc(String(media.credit.attribution)) + '</span>' : '';
            const ai = media.ai === 'generated' ? '<span class="nb-agent-tag nb-agent-tag--ai">' + icon('ai') + esc(label('agent.image_ai', 'AI-generated')) + '</span>' : '';
            return '<figure class="nb-agent-media"><img src="' + esc(mediaUrl(review.id, ref)) + '" alt="' + esc(sourceLabel) + '" loading="lazy" width="96" height="72">' +
                '<figcaption>' + esc(sourceLabel) + credit + ai + '</figcaption></figure>';
        });
        return '<div class="nb-agent-block"><h4>' + esc(label('agent.images', 'Images')) + '</h4><div class="nb-agent-media-grid">' + cards.join('') + '</div></div>';
    }

    function renderSources(review) {
        const sources = Array.isArray(review.sources) ? review.sources : [];
        if (!sources.length) return '';
        const items = sources.map(source => {
            const url = safeHttpUrl(source.url);
            const title = String(source.title || source.host || url);
            const name = url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">' + esc(plainText(title, 90)) + '</a>' : esc(plainText(title, 90));
            const warning = source.warning
                ? '<span class="nb-agent-source__warning" role="img" aria-label="' + esc(label('agent.source_warning', 'This page contains text that tries to instruct an AI. It was ignored.')) +
                    '" title="' + esc(label('agent.source_warning', 'This page contains text that tries to instruct an AI. It was ignored.')) + '">' + icon('warning') + '</span>'
                : '';
            return '<li>' + warning + name + '<span class="nb-agent-source__meta">' + esc([source.host, source.date].filter(Boolean).join(' · ')) + '</span></li>';
        });
        return '<div class="nb-agent-block"><h4>' + esc(label('agent.sources', 'Sources')) + '</h4><ul class="nb-agent-sources">' + items.join('') + '</ul></div>';
    }

    function renderChecks(review) {
        const errors = review.validation && Array.isArray(review.validation.errors) ? review.validation.errors : [];
        const warnings = review.validation && Array.isArray(review.validation.warnings) ? review.validation.warnings : [];
        const parts = [];
        if (review.status === 'conflict' || (Array.isArray(review.conflicts) && review.conflicts.length)) {
            parts.push('<div class="nb-agent-note nb-agent-note--warn"><p>' + esc(label('agent.conflict', 'The content changed after the agent started. Revalidate the package to continue.')) + '</p>' +
                '<button type="button" class="nb-copilot-copy" data-agent-action="revalidate"' + (isBusy() ? ' disabled' : '') + '>' + esc(label('agent.revalidate', 'Revalidate')) + '</button></div>');
        }
        if (errors.length && review.status !== 'conflict') {
            parts.push('<div class="nb-agent-note nb-agent-note--warn"><strong>' + esc(label('agent.validation_errors', 'Problems found')) + '</strong><ul>' +
                errors.slice(0, 6).map(error => '<li>' + esc(plainText(error.message, 200)) + '</li>').join('') + '</ul></div>');
        }
        const targets = review.renderCheck && review.renderCheck.targets ? Object.entries(review.renderCheck.targets) : [];
        const failedChecks = targets.filter(([, check]) => check && check.status === 'failed');
        if (failedChecks.length) {
            parts.push('<div class="nb-agent-note nb-agent-note--warn"><strong>' + esc(label('agent.render_failed', 'A page needs attention')) + '</strong><ul>' +
                failedChecks.slice(0, 4).map(([target, check]) => '<li>' + esc(targetLabel(target)) + ': ' + esc(plainText((check.messages || [])[0] || '', 160)) + '</li>').join('') + '</ul></div>');
        }
        if (warnings.length) {
            parts.push('<details class="nb-agent-notes" data-agent-details="notes"' + (state.openDetails.notes === true ? ' open' : '') + '><summary>' + esc(label('agent.validation_warnings', 'Notes')) + ' (' + warnings.length + ')</summary><ul>' +
                warnings.slice(0, 8).map(warning => '<li>' + esc(plainText(warning.message || warning, 200)) + '</li>').join('') + '</ul></details>');
        }
        if (!parts.length && review.status === 'ready') {
            parts.push('<p class="nb-agent-ok">' + icon('ok') + esc(label('agent.validation_ok', 'All checks passed')) + '</p>');
        }
        return parts.join('');
    }

    function renderReviewCard() {
        const review = state.review;
        const run = state.run;
        if (!review || !run || run.status !== 'awaiting_review' || !['staging', 'ready', 'conflict', 'failed'].includes(review.status)) return '';
        const operationCount = Object.values(review.targets || {}).reduce((total, list) => total + list.length, 0);
        const groups = Object.entries(review.targets || {}).map(([target, ops]) => '<section class="nb-agent-target"><h4>' + esc(targetLabel(target)) + '</h4><ul>' +
            ops.map(op => renderOperation(op, operationCount <= OPEN_OPERATIONS_UP_TO)).join('') + '</ul></section>').join('');
        const questions = Array.isArray(run.openQuestions) && run.openQuestions.length
            ? '<div class="nb-agent-block"><h4>' + esc(label('agent.open_questions', 'Open questions')) + '</h4><ul>' + run.openQuestions.map(question => '<li>' + esc(String(question)) + '</li>').join('') + '</ul></div>' : '';
        const previews = Array.isArray(review.previewLinks) ? review.previewLinks : [];
        const canApply = review.status === 'ready' && !isBusy();
        const previewButtons = previews.slice(0, 3).map((link, index) => '<button type="button" class="nb-copilot-copy" data-agent-action="preview" data-arg="' + index + '"' + (review.status === 'ready' ? '' : ' disabled') +
            ' title="' + esc(label('agent.preview_hint', 'Opens the page with the changes. Only you see it.')) + '">' +
            esc(previews.length > 1 ? label('agent.preview_page', 'Preview: {name}', { name: plainText(link.target.replace(/^[a-z]+:/, ''), 30) }) : label('agent.preview', 'Preview')) + '</button>').join('');
        return '<section class="nb-agent-package" aria-label="' + esc(label('agent.package_title', 'Change package')) + '">' +
            '<header><strong>' + esc(label('agent.package_title', 'Change package')) + '</strong><span class="nb-agent-tag' + (review.status === 'ready' ? ' nb-agent-tag--ok' : ' nb-agent-tag--warn') + '">' +
            esc(review.status === 'ready' ? label('agent.status_ready', 'Ready') : review.status === 'conflict' ? label('agent.status_conflict', 'Out of date') : label('agent.status_check', 'Needs attention')) + '</span></header>' +
            (run.summary ? '<p class="nb-agent-summary">' + esc(String(run.summary)) + '</p>' : '') + questions +
            renderChecks(review) + groups + renderMedia(review) + renderSources(review) +
            '<footer class="nb-agent-actions">' + previewButtons +
            '<button type="button" class="nb-copilot-copy" data-agent-action="changes"' + (isBusy() ? ' disabled' : '') + '>' + esc(label('agent.request_changes', 'Request changes')) + '</button>' +
            '<button type="button" class="nb-copilot-copy" data-agent-action="discard"' + (isBusy() ? ' disabled' : '') + '>' + esc(label('agent.discard', 'Discard')) + '</button>' +
            '<button type="button" class="nb-copilot-apply" data-agent-action="apply"' + (canApply ? '' : ' disabled') + '>' +
            (state.busyAction === 'apply' ? '<span class="nb-copilot-spinner" aria-hidden="true"></span>' : '') + esc(label('agent.apply', 'Apply changes')) + '</button></footer></section>';
    }

    function renderAppliedCard() {
        const review = state.review;
        if (!review || !state.run) return '';
        if (review.status === 'undone') {
            return '<section class="nb-agent-package nb-agent-package--quiet"><p>' + esc(label('agent.undone', 'The package was undone.')) + '</p></section>';
        }
        if (review.status !== 'applied' || !state.applied) return '';
        const canPublish = review.canPublish === true || rights.publish === true;
        const rows = state.applied.items.map((item, index) => {
            const action = item.published
                ? '<span class="nb-agent-tag nb-agent-tag--ok">' + esc(label('agent.published', 'Published')) + '</span>'
                : canPublish ? '<button type="button" class="nb-copilot-copy" data-agent-action="publish" data-arg="' + index + '"' + (isBusy() ? ' disabled' : '') + '>' + esc(label('agent.publish', 'Publish')) + '</button>'
                    : '<span class="nb-agent-hint">' + esc(label('agent.publish_not_allowed', 'Publishing needs a role with that right.')) + '</span>';
            return '<li><span>' + esc(itemLabel(item)) + '</span>' + action + '</li>';
        });
        return '<section class="nb-agent-package nb-agent-package--applied" aria-label="' + esc(label('agent.applied_title', 'Changes applied')) + '">' +
            '<header><strong>' + esc(label('agent.applied_title', 'Changes applied')) + '</strong><span class="nb-agent-tag nb-agent-tag--ok">' + icon('ok') + '</span></header>' +
            '<p class="nb-agent-summary">' + esc(state.applied.items.length
                ? label('agent.applied_note', 'A backup was made. Changes to existing content are live; new content is hidden until you publish it.')
                : label('agent.applied_note_live', 'A backup was made. The changes are live. Undo restores the previous state.')) + '</p>' +
            (rows.length ? '<ul class="nb-agent-items">' + rows.join('') + '</ul>' : '') +
            '<footer class="nb-agent-actions">' +
            '<button type="button" class="nb-copilot-copy" data-agent-action="reload">' + esc(label('agent.reload', 'Reload page')) + '</button>' +
            '<button type="button" class="nb-copilot-copy" data-agent-action="undo"' + (isBusy() ? ' disabled' : '') + '>' + esc(label('agent.undo', 'Undo')) + '</button></footer></section>';
    }

    function renderNotice() {
        const notice = state.notice;
        if (!notice || !notice.text) return '';
        const actions = [];
        if (notice.retry && state.lastGoal) actions.push('<button type="button" class="nb-copilot-copy" data-agent-action="retry">' + esc(label('agent.retry', 'Start again')) + '</button>');
        if (notice.resume) actions.push('<button type="button" class="nb-copilot-copy" data-agent-action="resume">' + esc(label('agent.continue', 'Continue')) + '</button>');
        return '<div class="nb-agent-note nb-agent-note--' + (notice.type === 'error' ? 'warn' : 'info') + '" role="' + (notice.type === 'error' ? 'alert' : 'status') + '"><p>' + esc(notice.text) + '</p>' + actions.join('') + '</div>';
    }

    function renderIntro() {
        if (state.run || state.review || state.notice) return '';
        return '<div class="nb-agent-intro"><strong>' + esc(label('agent.title', 'AI agent')) + '</strong><p>' +
            esc(label('agent.intro', 'The agent works through your request, prepares a package of changes and shows it to you. Nothing goes live until you apply it.')) + '</p></div>';
    }

    function buildHtml() {
        if (state.mode !== 'agent') return '';
        const cards = renderIntro() + renderRunCard() + renderReviewCard() + renderAppliedCard() + renderNotice();
        return cards ? '<div class="nb-agent" data-agent-root>' + cards + '</div>' : '';
    }

    function renderChips() {
        const box = root && root.querySelector('[data-agent-chips]');
        if (!box) return;
        const show = state.mode === 'agent' && (state.chips.length > 0 || state.uploads > 0);
        box.hidden = !show;
        if (!show) {
            box.innerHTML = '';
            return;
        }
        const chips = state.chips.map(chip => '<span class="nb-agent-chip"><img src="' + esc(chip.thumbnail) + '" alt="" width="28" height="28"><span>' + esc(plainText(chip.name, 28)) + '</span></span>').join('');
        const busy = state.uploads > 0 ? '<span class="nb-agent-chip nb-agent-chip--busy"><span class="nb-copilot-spinner" aria-hidden="true"></span>' + esc(label('agent.upload_busy', 'Uploading…')) + '</span>' : '';
        const clear = state.chips.length ? '<button type="button" class="nb-agent-link" data-agent-action="clear-images">' + esc(label('agent.chips_remove', 'Remove images')) + '</button>' : '';
        box.innerHTML = chips + busy + clear;
    }

    function updateControls() {
        if (!root) return;
        const agent = state.mode === 'agent';
        root.classList.toggle('nb-copilot--agent', agent);
        root.querySelectorAll('[data-agent-action="mode"]').forEach(button => {
            const pressed = button.getAttribute('data-arg') === state.mode;
            button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        });
        const input = root.querySelector('.nb-copilot-input');
        if (input) {
            const run = state.run;
            let key = 'agent.placeholder';
            let fallback = 'Tell the agent what to change, e.g. “Add a news post about …”';
            if (!agent) {
                key = 'copilot.placeholder';
                fallback = 'Ask how to change this page...';
            } else if (isWorking()) {
                key = 'agent.placeholder_busy';
                fallback = 'The agent is working…';
            } else if (run && run.status === 'needs_input') {
                key = 'agent.placeholder_answer';
                fallback = 'Answer the agent…';
            } else if (run && run.status === 'awaiting_review' && state.review && ['staging', 'ready', 'conflict'].includes(state.review.status)) {
                key = 'agent.placeholder_changes';
                fallback = 'What should be different in the package?';
            }
            input.setAttribute('placeholder', label(key, fallback));
        }
        const attach = root.querySelector('[data-agent-action="attach"]');
        if (attach) {
            const canAttach = agent && rights.uploadImages === true;
            attach.hidden = !canAttach;
            const blocked = !!state.run && state.run.status !== 'cancelled' && state.run.status !== 'failed' && !state.applied;
            attach.disabled = blocked || state.uploads > 0 || state.chips.length >= maxUploadFiles;
            attach.title = blocked ? label('agent.attach_blocked', 'Attach images before you send a request.') : label('agent.attach_hint', 'Attach images (JPEG, PNG or WebP). The agent can use them in the package.');
        }
        const mode = root.querySelector('.nb-agent-mode');
        if (mode) mode.hidden = false;
        renderChips();
        if (host) host.updateActions();
    }

    function update() {
        if (!host || !root) return;
        const next = buildHtml();
        updateControls();
        if (next === state.lastHtml) return;
        const list = document.querySelector('[data-copilot-messages]');
        const stick = !list || list.scrollHeight - list.scrollTop - list.clientHeight < 90;
        host.render({ keepScroll: !stick });
    }

    // ------------------------------------------------------------------
    // Uploads
    // ------------------------------------------------------------------

    function uploadFile(file) {
        const form = new FormData();
        form.append('action', 'ai-agent-upload');
        form.append('csrf_token', host.csrfToken);
        form.append('image', file, file.name);
        if (state.attachedChangeset) form.append('changesetId', state.attachedChangeset);
        return fetch(host.apiUrl, { method: 'POST', body: form, credentials: 'same-origin' }).then(async response => {
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || data.success === false) throw new Error((data && data.message) || label('copilot.request_failed', 'AI Assistant request failed.'));
            return data.data;
        });
    }

    async function handleFiles(fileList) {
        if (!rights.uploadImages || state.mode !== 'agent') return;
        const files = Array.from(fileList || []);
        if (!files.length) return;
        if (state.run && !state.applied && !['cancelled', 'failed'].includes(state.run.status)) {
            state.notice = { type: 'info', text: label('agent.attach_blocked', 'Attach images before you send a request.') };
            update();
            return;
        }
        state.notice = null;
        for (const file of files) {
            if (state.chips.length >= maxUploadFiles) {
                state.notice = { type: 'info', text: label('agent.upload_limit', 'You can attach up to {count} images at once.', { count: maxUploadFiles }) };
                break;
            }
            if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !/\.(jpe?g|png|webp)$/i.test(file.name)) {
                state.notice = { type: 'error', text: label('agent.upload_type', 'Only JPEG, PNG and WebP images can be attached.') };
                continue;
            }
            if (file.size > maxUploadBytes) {
                state.notice = { type: 'error', text: label('agent.upload_size', '{name} is too large (max. {size} MB).', { name: plainText(file.name, 40), size: Math.floor(maxUploadBytes / 1048576) }) };
                continue;
            }
            state.uploads += 1;
            updateControls();
            try {
                const data = await uploadFile(file);
                state.attachedChangeset = String(data.changesetId || '');
                const ref = String(data.media && data.media.mediaRef || '');
                state.chips.push({ name: file.name, ref, thumbnail: mediaUrl(state.attachedChangeset, ref) });
            } catch (error) {
                state.notice = { type: 'error', text: label('agent.upload_failed', '{name}: {message}', { name: plainText(file.name, 40), message: error.message }) };
            } finally {
                state.uploads -= 1;
            }
        }
        update();
    }

    async function clearImages() {
        const id = state.attachedChangeset;
        state.chips = [];
        state.attachedChangeset = '';
        update();
        if (id) {
            try {
                await host.post('ai-agent-discard', { id });
            } catch (error) {
                // The staged images are cleaned up on the server anyway.
            }
        }
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    function onClick(event) {
        const target = event.target instanceof Element ? event.target.closest('[data-agent-action]') : null;
        if (!target || !root || !root.contains(target)) return;
        const action = target.getAttribute('data-agent-action');
        const arg = target.getAttribute('data-arg');
        if (action === 'mode') {
            setMode(arg === 'classic' ? 'classic' : 'agent');
        } else if (action === 'cancel') {
            cancelRun();
        } else if (action === 'apply') {
            applyPackage();
        } else if (action === 'discard') {
            discardPackage();
        } else if (action === 'revalidate') {
            revalidatePackage();
        } else if (action === 'undo') {
            undoPackage();
        } else if (action === 'publish') {
            publishItem(Number(arg));
        } else if (action === 'preview') {
            openPreview(Number(arg));
        } else if (action === 'changes') {
            state.awaitingChanges = true;
            updateControls();
            const input = root.querySelector('.nb-copilot-input');
            if (input) input.focus();
        } else if (action === 'attach') {
            const file = root.querySelector('[data-agent-file]');
            if (file) file.click();
        } else if (action === 'clear-images') {
            clearImages();
        } else if (action === 'reload') {
            window.location.reload();
        } else if (action === 'retry') {
            const goal = state.lastGoal;
            state.notice = null;
            clearRun();
            if (goal) handleMessage(goal);
        } else if (action === 'resume') {
            state.notice = null;
            update();
            drive();
        }
    }

    function setMode(mode) {
        if (state.mode === mode) return;
        state.mode = mode;
        writeMode(mode);
        updateControls();
        host.render({ keepScroll: true });
    }

    function hasFiles(event) {
        const types = event.dataTransfer && event.dataTransfer.types ? Array.from(event.dataTransfer.types) : [];
        return types.indexOf('Files') !== -1;
    }

    function bindDrop(panel) {
        let depth = 0;
        const setDragging = value => {
            state.dragging = value;
            panel.classList.toggle('is-dragging', value);
        };
        panel.addEventListener('dragenter', event => {
            if (state.mode !== 'agent' || !rights.uploadImages || !hasFiles(event)) return;
            event.preventDefault();
            depth += 1;
            setDragging(true);
        });
        panel.addEventListener('dragover', event => {
            if (state.mode !== 'agent' || !rights.uploadImages || !hasFiles(event)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        });
        panel.addEventListener('dragleave', () => {
            depth = Math.max(0, depth - 1);
            if (depth === 0) setDragging(false);
        });
        panel.addEventListener('drop', event => {
            if (state.mode !== 'agent' || !rights.uploadImages || !hasFiles(event)) return;
            event.preventDefault();
            depth = 0;
            setDragging(false);
            handleFiles(event.dataTransfer.files);
        });
    }

    // ------------------------------------------------------------------
    // Mounting and resuming
    // ------------------------------------------------------------------

    function mount(shell, hostApi) {
        host = hostApi;
        root = shell;
        if (root.querySelector('[data-agent-controls]')) return;
        const form = root.querySelector('[data-copilot-form]');
        const actions = root.querySelector('.nb-copilot-actions');
        const panel = root.querySelector('.nb-copilot-panel');
        if (!form || !actions || !panel) return;

        const chips = document.createElement('div');
        chips.className = 'nb-agent-chips';
        chips.setAttribute('data-agent-chips', '');
        chips.hidden = true;
        form.insertBefore(chips, form.firstChild);

        const controls = document.createElement('div');
        controls.className = 'nb-agent-controls';
        controls.setAttribute('data-agent-controls', '');
        controls.innerHTML = '<div class="nb-agent-mode" role="group" aria-label="' + esc(label('agent.mode_label', 'Mode')) + '">' +
            '<button type="button" data-agent-action="mode" data-arg="agent" aria-pressed="true" title="' + esc(label('agent.mode_agent_hint', 'The agent prepares a complete change package for you to review.')) + '">' + esc(label('agent.mode_agent', 'Agent')) + '</button>' +
            '<button type="button" data-agent-action="mode" data-arg="classic" aria-pressed="false" title="' + esc(label('agent.mode_classic_hint', 'Ask questions and draft single changes step by step.')) + '">' + esc(label('agent.mode_classic', 'Classic')) + '</button></div>' +
            (rights.uploadImages === true
                ? '<button type="button" class="nb-agent-attach" data-agent-action="attach" aria-label="' + esc(label('agent.attach', 'Attach images')) + '">' + icon('attach') + '</button>' +
                    '<input type="file" data-agent-file accept="image/jpeg,image/png,image/webp" multiple hidden>' : '');
        actions.insertBefore(controls, actions.firstChild);

        const fileInput = root.querySelector('[data-agent-file]');
        if (fileInput) {
            fileInput.addEventListener('change', () => {
                handleFiles(fileInput.files);
                fileInput.value = '';
            });
        }
        root.addEventListener('click', onClick);
        root.addEventListener('toggle', event => {
            const details = event.target instanceof Element ? event.target.closest('[data-agent-details]') : null;
            if (details) state.openDetails[details.getAttribute('data-agent-details')] = details.open === true;
        }, true);
        bindDrop(panel);
        const overlay = document.createElement('div');
        overlay.className = 'nb-agent-drop';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.textContent = label('agent.drop_hint', 'Drop images here to attach them');
        panel.appendChild(overlay);

        updateControls();
        resume();
    }

    async function resume() {
        await resumeRun();
        if (state.reviewLoading) await state.reviewLoading;
        handleLaunchRequest(true);
    }

    async function resumeRun() {
        const stored = readStoredRun();
        if (!stored || state.run) return;
        try {
            const data = await apiGet('ai-agent-status', { runId: stored.runId });
            state.lastGoal = String(stored.goal || '');
            applyRunState(data, false); // an open review is loaded from there
            if (data.status === 'cancelled') clearRun();
            update();
            if (isWorking()) drive();
        } catch (error) {
            if (error && (error.status === 404 || error.status === 403)) {
                state.run = null;
                persistRun();
            }
        }
        update();
    }

    function reset() {
        state.run = null;
        state.review = null;
        state.applied = null;
        state.notice = null;
        state.chips = [];
        state.attachedChangeset = '';
        state.lastGoal = '';
        state.awaitingChanges = false;
        state.openDetails = {};
        persistRun();
        if (root) updateControls();
    }

    const registered = window.NibblyAiCopilot.registerAgent({
        mount,
        reset,
        isActive: () => state.mode === 'agent',
        isBusy,
        handleMessage,
        render() {
            state.lastHtml = buildHtml();
            return state.lastHtml;
        },
        afterRender() {
            updateControls();
        }
    });
    if (!registered) return;
})();
