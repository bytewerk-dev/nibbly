/** Shared optimistic concurrency for dashboard and inline content forms. */
(() => {
    'use strict';
    if (window.NibblyRevisions) return;
    const revisions = new Map();
    const originalFetch = window.fetch.bind(window);
    const tr = key => (window.NB_LANG || {})['conflict.' + key] || key;
    let dialog;
    async function showConflict(url, action, page, pending) {
        if (dialog?.open) return;
        const latestUrl = new URL(url);
        latestUrl.search = new URLSearchParams({ action: action === 'save' ? 'load' : 'load-settings', ...(page ? { page } : {}) });
        let latest;
        try { latest = (await (await originalFetch(latestUrl)).json()).data; }
        catch (_) { latest = tr('unavailable'); }
        const previousFocus = document.activeElement;
        dialog = document.createElement('dialog');
        dialog.className = 'nibbly-conflict';
        const heading = document.createElement('h2');
        heading.id = 'nibblyConflictTitle'; heading.textContent = tr('changed');
        dialog.setAttribute('aria-labelledby', heading.id);
        const hint = document.createElement('p'); hint.textContent = tr('hint');
        const columns = document.createElement('div'); columns.className = 'nibbly-conflict__columns';
        for (const [label, value] of [[tr('yours'), pending], [tr('current'), latest]]) {
            const wrapper = document.createElement('label'); wrapper.textContent = label;
            const content = document.createElement('textarea'); content.readOnly = true;
            content.value = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
            wrapper.append(content); columns.append(wrapper);
        }
        const actions = document.createElement('div'); actions.className = 'nibbly-conflict__actions';
        function button(label, callback) { const btn = document.createElement('button'); btn.type = 'button'; btn.textContent = label; btn.onclick = callback; actions.append(btn); }
        button(tr('keep'), () => dialog.close());
        button(tr('download'), () => {
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([typeof pending === 'string' ? pending : JSON.stringify(pending, null, 2)], { type: 'application/json' }));
            link.download = (page || 'settings') + '-unsaved.json'; link.click(); URL.revokeObjectURL(link.href);
        });
        button(tr('reload'), () => window.location.reload());
        dialog.append(heading, hint, columns, actions); document.body.append(dialog);
        dialog.addEventListener('close', () => { dialog.remove(); previousFocus?.focus(); });
        dialog.showModal();
    }
    window.fetch = async function(input, init) {
        const url = new URL(input instanceof Request ? input.url : input, location.href);
        const apiUrl = new URL(window.NB_ADMIN_API_URL || 'api.php', location.href);
        if (url.origin !== apiUrl.origin || url.pathname !== apiUrl.pathname) return originalFetch(input, init);
        const body = init?.body;
        const fields = body instanceof FormData || body instanceof URLSearchParams ? body : url.searchParams;
        const action = fields.get('action') || url.searchParams.get('action');
        const page = fields.get('page') || url.searchParams.get('page') || '';
        const key = ['load-settings', 'save-settings'].includes(action) ? 'settings' : 'page:' + page;
        const write = ['save', 'save-settings'].includes(action);
        if (write && fields !== url.searchParams && !fields.has('revision') && revisions.has(key)) fields.set('revision', revisions.get(key));
        const response = await originalFetch(input, init);
        if (['load', 'load-settings', 'save', 'save-settings'].includes(action)) {
            try {
                const payload = await response.clone().json();
                if (payload.success && payload.revision) revisions.set(key, payload.revision);
                if ([409, 428].includes(response.status)) void showConflict(url, action, page, fields.get(action === 'save' ? 'content' : 'settings'));
            } catch (_) { /* The caller owns transport/error handling. */ }
        }
        return response;
    };
    window.NibblyRevisions = { get: key => revisions.get(key) };
})();
