<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
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
            const response = await fetch('api.php?action=system-status');
            const result = await response.json();
            if (!result.success) throw new Error(result.message);
            target.replaceChildren();
            const data = result.data;
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
