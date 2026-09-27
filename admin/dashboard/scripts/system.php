<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // Security check state is shared by the startup check, the warning banner and
    // the system tab. var: switchTab('system') can run before this fragment executes.
    var securityCheckRequest = null;
    var securityStatus = null;

    function runSecurityCheck(force) {
        if (securityCheckRequest && !force) return securityCheckRequest;
        const form = new FormData();
        form.set('action', 'security-check');
        form.set('csrf_token', CSRF_TOKEN);
        form.set('scheme', window.location.protocol === 'https:' ? 'https' : 'http');
        if (force) form.set('force', '1');
        const request = fetch('api.php', { method: 'POST', body: form })
            .then(response => response.json())
            .then(result => {
                if (!result.success) throw new Error(result.message || t('system.load_failed'));
                return result.data;
            })
            .finally(() => { if (securityCheckRequest === request) securityCheckRequest = null; })
            .then(data => { applySecurityStatus(data); return data; });
        securityCheckRequest = request;
        if (securityStatus) applySecurityStatus(securityStatus);
        return request;
    }

    function applySecurityStatus(security) {
        if (!security || !Array.isArray(security.items)) return;
        securityStatus = security;
        const folders = security.items.find(item => item.id === 'folders') || {};
        const exposed = folders.state === 'critical' ? (folders.exposed || []) : [];
        const banner = document.getElementById('securityExposureWarning');
        if (banner) {
            const data = exposed.some(path => path === 'content/' || path === 'backups/');
            const text = t('security_check.banner_text', { paths: exposed.join(', ') }) + (data ? ' ' + t('security_check.banner_data') : '');
            const body = document.getElementById('securityExposureText');
            // role="alert" announces changes; do not repeat an unchanged warning.
            if (body.textContent !== text) body.textContent = text;
            banner.hidden = !exposed.length;
        }
        const badge = document.getElementById('systemBadge');
        if (badge) {
            const count = security.items.filter(item => item.state === 'critical' || item.state === 'warning').length;
            badge.textContent = String(count);
            badge.title = t('security_check.badge', { count: count });
            badge.classList.toggle('mail-badge--hidden', count === 0);
        }
        const panel = document.getElementById('systemSecurityPanel');
        if (panel) panel.replaceWith(renderSecurityPanel(security));
    }

    function securityItemText(item) {
        let key = item.id + '_' + item.state;
        if (item.state === 'skipped') key = (item.id === 'folders' && item.canRun ? 'folders_' : '') + 'skipped_' + (item.reason || 'local');
        else if (item.state === 'pending') key = securityCheckRequest || item.running ? 'running' : 'pending';
        else if (item.state === 'unknown') key = item.reason ? 'unknown_reason' : 'unknown';
        else if (item.id === 'http') key = 'http_' + item.mode;
        else if (item.id === 'php' && item.state === 'ok' && !item.until) key = 'php_current';
        const reason = item.reason ? t('security_check.reason_' + item.reason, { url: item.url || '', detail: item.detail || '' }) : '';
        return t('security_check.' + key, {
            paths: (item.exposed || []).join(', '), ip: item.ip || '', version: item.version || '', reason: reason,
            until: item.until ? new Date(item.until + 'T00:00:00').toLocaleDateString() : ''
        });
    }

    function renderSecurityDetails(item) {
        const blocks = [];
        const paragraph = (key, params) => {
            const text = document.createElement('p'); text.textContent = t(key, params); blocks.push(text);
        };
        // Only media trash folders public: a warning with its own explanation.
        if (['critical', 'warning', 'note'].includes(item.state)) paragraph('security_check.' + item.id + '_fix' + (item.id === 'folders' && item.state === 'warning' ? '_trash' : ''));
        if (item.id === 'folders' && item.state === 'unknown' && item.url) paragraph('security_check.folders_manual', { url: item.url + '/content/settings.json' });
        if (item.id === 'folders' && item.probes && item.probes.length) {
            const list = document.createElement('ul'); list.className = 'system-check__probes';
            for (const probe of item.probes) {
                const entry = document.createElement('li'); const path = document.createElement('code');
                path.textContent = probe.path;
                entry.append(path, ' ' + t('security_check.probe_' + (probe.status ? probe.state : 'skipped'), { status: probe.status }));
                list.append(entry);
            }
            blocks.push(list);
        }
        if (item.rules) {
            const rules = document.createElement('div'); rules.className = 'system-check__rules';
            const pre = document.createElement('pre'); const code = document.createElement('code');
            code.textContent = item.rules; pre.append(code);
            const copy = document.createElement('button'); copy.type = 'button'; copy.className = 'btn btn-secondary btn-sm';
            copy.textContent = t('security_check.copy');
            copy.onclick = () => {
                const done = () => { copy.textContent = t('security_check.copied'); setTimeout(() => { copy.textContent = t('security_check.copy'); }, 1500); };
                // Without the clipboard API (plain http://) or permission, select the rules for manual copying.
                const select = () => {
                    const range = document.createRange(); range.selectNodeContents(code);
                    const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
                    if (document.execCommand('copy')) done();
                };
                if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(item.rules).then(done, select);
                else select();
            };
            rules.append(pre, copy); blocks.push(rules);
            paragraph('security_check.folders_hestia', { domain: window.location.hostname });
            paragraph('security_check.folders_plesk');
            paragraph('security_check.folders_htaccess');
        }
        if (!blocks.length) return null;
        const details = document.createElement('details'); details.className = 'system-check__details';
        details.open = item.state === 'critical';
        const summary = document.createElement('summary');
        summary.textContent = t(['critical', 'warning', 'note'].includes(item.state) ? 'security_check.how_to_fix' : 'system.details');
        details.append(summary, ...blocks);
        return details;
    }

    function renderSecurityPanel(security) {
        const section = document.createElement('section');
        section.className = 'dashboard-panel system-security'; section.id = 'systemSecurityPanel';
        const header = document.createElement('div'); header.className = 'dashboard-panel-header';
        const heading = document.createElement('h3'); heading.textContent = t('security_check.title');
        header.append(heading);
        const folders = security.items.find(item => item.id === 'folders') || {};
        const actions = document.createElement('div'); actions.className = 'system-security__actions';
        if (folders.checkedAt) {
            const checked = document.createElement('span');
            checked.textContent = t('security_check.checked_at', { date: new Date(folders.checkedAt).toLocaleString() });
            actions.append(checked);
        }
        if (folders.canRun) {
            const busy = !!securityCheckRequest || !!folders.running;
            const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-secondary btn-sm';
            button.textContent = t(busy ? 'security_check.running' : 'security_check.run'); button.disabled = busy;
            button.onclick = () => runSecurityCheck(true).catch(error => {
                showToast(error.message, 'error');
                if (securityStatus) applySecurityStatus(securityStatus);
            });
            actions.append(button);
        }
        header.append(actions);
        section.append(header);
        for (const item of security.items) {
            const check = document.createElement('div'); check.className = 'system-check system-check--' + item.state;
            const row = document.createElement('div'); row.className = 'system-status-row';
            const name = document.createElement('strong'); name.textContent = t('security_check.' + item.id);
            const text = document.createElement('span'); text.className = 'system-check__state'; text.textContent = securityItemText(item);
            row.append(name, text); check.append(row);
            const details = renderSecurityDetails(item);
            if (details) check.append(details);
            section.append(check);
        }
        return section;
    }

    async function loadSystemStatus() {
        const target = document.getElementById('systemStatusBody');
        if (!target) return;
        target.textContent = t('system.loading');
        const row = (label, value) => {
            const item = document.createElement('div'); item.className = 'system-status-row';
            const name = document.createElement('strong'); name.textContent = label;
            const text = document.createElement('span'); text.textContent = value;
            item.append(name, text); return item;
        };
        const group = title => {
            const section = document.createElement('section'); section.className = 'dashboard-panel';
            const heading = document.createElement('h3'); heading.textContent = title;
            section.append(heading); target.append(section); return section;
        };
        try {
            const scheme = window.location.protocol === 'https:' ? 'https' : 'http';
            const response = await fetch('api.php?action=system-status&scheme=' + scheme);
            const result = await response.json();
            if (!result.success) throw new Error(result.message);
            target.replaceChildren();
            const data = result.data;
            if (data.security) {
                const placeholder = document.createElement('section'); placeholder.id = 'systemSecurityPanel';
                target.append(placeholder);
                applySecurityStatus(data.security);
                // Refresh an outdated or missing probe in the background; local hosts only on request.
                const folders = data.security.items.find(item => item.id === 'folders') || {};
                if (folders.canRun && folders.stale && !data.security.local) {
                    runSecurityCheck(false).catch(() => { if (securityStatus) applySecurityStatus(securityStatus); });
                }
            }
            const runtime = group(t('system.runtime'));
            runtime.append(row('PHP', data.php));
            for (const ext of data.extensions) runtime.append(row(ext.name, t(ext.available ? 'system.available' : 'system.missing')));
            const storage = group(t('system.storage'));
            for (const path of data.paths) storage.append(row(path.name, t(path.writable ? 'system.writable' : 'system.not_writable')));
            storage.append(row(t('system.last_backup'), data.lastBackup ? new Date(data.lastBackup).toLocaleString() : t('system.no_backup')));
            const jobs = group(t('system.failed_jobs'));
            if (!data.failedJobs.length) jobs.append(row(t('system.state'), t('system.no_failed_jobs')));
            for (const job of data.failedJobs) {
                const details = document.createElement('details'); const summary = document.createElement('summary');
                summary.textContent = job.id; const text = document.createElement('p'); text.textContent = job.error;
                details.append(summary, text); jobs.append(details);
            }
            const requests = group(t('system.requests'));
            const hint = document.createElement('p'); hint.textContent = t('system.resolve_hint'); requests.append(hint);
            if (!data.requests.length) requests.append(row(t('system.state'), t('system.no_requests')));
            for (const entry of data.requests) {
                const article = document.createElement('article'); article.className = 'system-request';
                article.append(row(entry.provider, formatAiCents(entry.reservedCents)));
                const details = document.createElement('details'); const summary = document.createElement('summary'); summary.textContent = t('system.details');
                details.append(summary, row(t('system.request_id'), entry.id), row(t('system.provider_tasks'), entry.tasks.join(', ') || '—'));
                article.append(details);
                const actions = document.createElement('div'); actions.className = 'system-request-actions';
                for (const resolution of ['charged', 'released']) {
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-secondary btn-sm';
                    button.textContent = t('system.resolve_' + resolution); button.disabled = !entry.resolvable;
                    button.onclick = async () => {
                        button.disabled = true;
                        const form = new FormData(); form.set('action', 'ai-resolve-request'); form.set('csrf_token', CSRF_TOKEN);
                        form.set('request_id', entry.id); form.set('resolution', resolution);
                        try {
                            const result = await (await fetch('api.php', { method: 'POST', body: form })).json();
                            if (!result.success) throw new Error(result.message);
                            await loadSystemStatus();
                        } catch (error) { showToast(error.message, 'error'); button.disabled = false; }
                    };
                    actions.append(button);
                }
                article.append(actions); requests.append(article);
            }
        } catch (_) { target.textContent = t('system.load_failed'); }
    }

    // Administrators: check security in the background once the dashboard has loaded.
    // The server reuses a recent result and never delays the dashboard itself.
    if (VALID_DASHBOARD_TABS.indexOf('system') !== -1) {
        window.addEventListener('load', () => setTimeout(() => { runSecurityCheck(false).catch(() => {}); }, 1000));
    }
