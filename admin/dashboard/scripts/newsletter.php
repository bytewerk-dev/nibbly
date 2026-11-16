<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
// Newsletter module (admin/api/newsletter.php, includes/newsletter.php): campaigns with a block
// editor and live preview, subscribers, lists and delivery settings, all rendered into #newsletterMount.
window.NbNewsletter = (() => {
    const mount = () => document.getElementById('newsletterMount');
    if (!mount()) return null;
    let data = null, view = 'campaigns', subscribers = null, current = null, detail = null, posts = null;
    let dirty = false, busy = false, device = 'desktop', previewTimer = 0, runTimer = 0, uid = 0;
    let subStatus = 'all', subList = '', subSearch = '', selected = new Set();
    let log = null, logFilter = 'all', logSearch = '';
    dashboardListPages.nlLog = 1;
    const tr = (key, params) => t('newsletter.' + key, params);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const leave = () => !dirty || confirm(tr('unsaved'));
    const $ = id => document.getElementById(id);
    dashboardListPages.nlSubs = 1;

    async function call(action, fields) {
        const body = fields ? new URLSearchParams({action, csrf_token: CSRF_TOKEN, ...fields}) : null;
        const response = await fetch(fields ? 'api.php' : 'api.php?action=' + action, {method: fields ? 'POST' : 'GET', body});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || tr('error'));
        return result.data;
    }
    // One request at a time for everything the user triggers; buttons show it by being disabled.
    async function act(action, fields, done) {
        if (busy) return;
        busy = true;
        mount().classList.add('is-busy');
        try {
            const result = await call(action, fields);
            if (result && result.campaigns) data = result;
            if (done) await done(result);
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            busy = false;
            mount().classList.remove('is-busy');
        }
    }
    const absolute = path => !path ? '' : (/^(https?:|data:)/i.test(path) ? path : '../' + String(path).replace(/^\//, ''));
    const listName = id => (data.lists.find(l => l.id === id) || {}).name || '';
    const statusBadge = (status, prefix) => `<span class="nl-status nl-status--${esc(status)}">${esc(tr((prefix || 'status_') + status))}</span>`;
    const percent = (part, total) => total > 0 ? Math.round(part / total * 100) + ' %' : '–';

    function stopTimers() { clearTimeout(runTimer); clearTimeout(previewTimer); runTimer = previewTimer = 0; }

    async function open() {
        stopTimers();
        current = null; detail = null; dirty = false;
        if (!data) mount().innerHTML = `<p role="status">${esc(tr('loading'))}</p>`;
        try { data = await call('load-newsletter'); } catch (error) { mount().innerHTML = `<p role="alert">${esc(error.message)}</p>`; return; }
        if (view === 'settings' && !data.isAdmin) view = 'campaigns';
        render();
    }

    function shell(content, actions) {
        const tabs = ['campaigns', 'subscribers', 'lists', 'log'].concat(data.isAdmin ? ['settings'] : []);
        const notReady = !data.ready ? `<div class="info-banner info-banner--warning info-banner--contained nl-notice" role="status">
            <strong>${esc(tr('not_ready'))}</strong> ${esc(data.readyError)}
            ${data.isAdmin && view !== 'settings' ? `<a href="#" data-nl="tab" data-view="settings">${esc(tr('open_settings'))} &rarr;</a>` : ''}</div>` : '';
        mount().innerHTML = `<div class="page-list-container nl">
            <div class="page-list-header">
                <div class="page-list-header-left"><div class="page-list-title-stack"><h2>${esc(tr('title'))}</h2><p>${esc(tr('intro'))}</p></div></div>
                <div class="page-list-header-actions">${actions || ''}</div>
            </div>
            <div class="nl-tabs" role="tablist">${tabs.map(tab => `<button type="button" role="tab" class="nl-tab${view === tab ? ' is-active' : ''}" aria-selected="${view === tab}" data-nl="tab" data-view="${tab}">${esc(tr('tab_' + tab))}${tab === 'subscribers' ? ` <span class="nl-count">${data.counts.subscribed}</span>` : ''}</button>`).join('')}</div>
            ${notReady}${content}</div>`;
    }

    function render() {
        stopTimers();
        if (view === 'subscribers') return renderSubscribers();
        if (view === 'lists') return renderLists();
        if (view === 'settings') return renderSettings();
        if (view === 'log') return renderLog();
        renderCampaigns();
    }

    // ---- Campaigns -----------------------------------------------------------------------------

    function renderCampaigns() {
        const rows = data.campaigns.map(c => {
            const stats = c.stats || {};
            const sent = ['sending', 'paused', 'sent'].includes(c.status);
            const link = (action, iconName, label, danger) => `<a href="#" class="page-list-row-action${danger ? ' page-list-row-action--danger' : ''}" data-nl="${action}" data-id="${c.id}">${iconName ? icon(iconName, 12, '2') + ' ' : ''}${esc(label)}</a>`;
            const sep = '<span class="page-list-row-action-sep">|</span>';
            const when = c.status === 'scheduled' ? tr('scheduled_for', {date: nbFormatDate(c.sendAt, 'datetime')}) : nbFormatDate(c.finishedAt || c.startedAt || c.updatedAt, 'datetime');
            return `<tr class="page-list-row"><td class="page-list-col-title"><a href="#" class="page-list-title-link" data-nl="${sent ? 'report' : 'edit'}" data-id="${c.id}">${esc(c.subject || tr('untitled'))}</a>
                <div class="page-list-row-actions">${sent ? link('report', 'eye', tr('report')) : link('edit', 'edit', t('pages.edit'))}${sep}${link('duplicate', 'duplicate', tr('duplicate'))}${c.status !== 'sending' ? sep + link('delete-campaign', 'trash', t('btn.delete'), true) : ''}</div></td>
                <td>${statusBadge(c.status)}</td>
                <td>${sent ? `${stats.sent || 0} / ${stats.total || 0}` : '–'}</td>
                <td>${sent && c.status !== 'sending' ? percent(stats.opens || 0, stats.sent || 0) : '–'}</td>
                <td>${sent && c.status !== 'sending' ? percent(stats.clicks || 0, stats.sent || 0) : '–'}</td>
                <td>${esc(when)}</td></tr>`;
        }).join('');
        shell(`<div class="page-list-table-wrap"><table class="page-list-table"><thead><tr><th class="page-list-col-title">${esc(tr('subject'))}</th><th>${esc(tr('status'))}</th><th>${esc(tr('sent'))}</th><th>${esc(tr('opens'))}</th><th>${esc(tr('clicks'))}</th><th>${esc(tr('date'))}</th></tr></thead>
            <tbody>${rows || `<tr><td colspan="6" class="page-list-empty"><p class="page-list-empty__text">${esc(tr('no_campaigns'))}</p></td></tr>`}</tbody></table></div>`,
            `<button type="button" class="btn btn-primary btn-sm" data-nl="new">${esc(tr('new_campaign'))}</button>`);
        // Keep an eye on running campaigns while the list is open.
        if (data.campaigns.some(c => c.status === 'sending')) runTimer = setTimeout(async () => { try { await call('newsletter-run', {}); data = await call('load-newsletter'); } catch (e) {} if (view === 'campaigns' && !current && !detail) renderCampaigns(); }, 4000);
    }

    const blockDefaults = {
        heading: {type: 'heading', text: '', level: 2, align: 'left'}, text: {type: 'text', html: ''},
        image: {type: 'image', src: '', alt: '', link: ''}, button: {type: 'button', label: '', url: '', align: 'center'},
        divider: {type: 'divider'}, spacer: {type: 'spacer', height: 24},
        post: {type: 'post', title: '', excerpt: '', image: '', url: '', label: ''}
    };

    async function editCampaign(id) {
        if (!leave()) return;
        stopTimers();
        if (id) {
            try { detail = await call('load-newsletter-campaign&id=' + encodeURIComponent(id)); } catch (error) { showToast(error.message, 'error'); return; }
            if (!['draft', 'scheduled'].includes(detail.campaign.status)) return renderReport();
            current = structuredClone(detail.campaign);
        } else {
            detail = null;
            current = {id: '', status: 'draft', subject: '', preheader: '', lists: [], blocks: [structuredClone(blockDefaults.heading), structuredClone(blockDefaults.text)]};
        }
        dirty = false;
        renderEditor();
    }

    function blockForm(block, index) {
        const id = 'nlb' + index;
        const input = (key, label, type) => `<div class="form-group"><label for="${id}-${key}">${esc(tr(label))}</label><input id="${id}-${key}" type="${type || 'text'}" data-field="${key}" value="${esc(block[key] ?? '')}"></div>`;
        const select = (key, label, options) => `<div class="form-group"><label for="${id}-${key}">${esc(tr(label))}</label><select id="${id}-${key}" data-field="${key}">${options.map(([value, text]) => `<option value="${esc(value)}"${String(block[key]) === String(value) ? ' selected' : ''}>${esc(text)}</option>`).join('')}</select></div>`;
        const align = [['left', tr('align_left')], ['center', tr('align_center')]];
        let fields = '';
        if (block.type === 'heading') fields = input('text', 'block_heading') + `<div class="nl-row">${select('level', 'size', [[1, tr('size_large')], [2, tr('size_medium')], [3, tr('size_small')]])}${select('align', 'align', align)}</div>`;
        if (block.type === 'text') fields = `<div class="html-editor nl-rich" id="${id}-rich"><label for="${id}-html">${esc(tr('block_text'))}</label><textarea id="${id}-html" class="editor-textarea section-field" data-key="html" rows="8"></textarea></div>`;
        if (block.type === 'image') fields = `<div class="nl-row nl-row--media">${block.src ? `<img class="nl-thumb" src="${esc(absolute(block.src))}" alt="">` : ''}<div>${input('src', 'image_path')}<button type="button" class="btn btn-secondary btn-sm" data-nl="pick-image" data-index="${index}" data-key="src">${esc(tr('choose_image'))}</button></div></div>${input('alt', 'image_alt')}${input('link', 'image_link', 'url')}`;
        if (block.type === 'button') fields = `<div class="nl-row">${input('label', 'button_label')}${input('url', 'button_url')}</div>${select('align', 'align', align)}`;
        if (block.type === 'spacer') fields = input('height', 'spacer_height', 'number');
        if (block.type === 'divider') fields = `<p class="form-hint">${esc(tr('divider_hint'))}</p>`;
        if (block.type === 'post') fields = `<div class="form-group"><label for="${id}-pick">${esc(tr('post_pick'))}</label><select id="${id}-pick" data-nl-post="${index}"><option value="">${esc(posts ? tr('post_choose') : tr('loading'))}</option>${(posts || []).map((p, i) => `<option value="${i}">${esc((p.date ? p.date.slice(0, 10) + ' · ' : '') + p.title)}</option>`).join('')}</select></div>
            ${input('title', 'post_title')}<div class="form-group"><label for="${id}-excerpt">${esc(tr('post_excerpt'))}</label><textarea id="${id}-excerpt" data-field="excerpt" rows="3">${esc(block.excerpt || '')}</textarea></div>
            <div class="nl-row">${input('url', 'post_url')}${input('label', 'post_label')}</div>${input('image', 'image_path')}`;
        const move = (direction, label, disabled) => `<button type="button" class="nl-icon-btn" data-nl="move" data-index="${index}" data-direction="${direction}" title="${esc(label)}" aria-label="${esc(label)}"${disabled ? ' disabled' : ''}>${direction < 0 ? '↑' : '↓'}</button>`;
        return `<section class="nl-block" data-block="${index}"><header class="nl-block__head"><strong>${esc(tr('type_' + block.type))}</strong><span class="nl-block__tools">
            ${move(-1, tr('move_up'), index === 0)}${move(1, tr('move_down'), index === current.blocks.length - 1)}
            <button type="button" class="nl-icon-btn nl-icon-btn--danger" data-nl="remove-block" data-index="${index}" title="${esc(tr('remove_block'))}" aria-label="${esc(tr('remove_block'))}">${icon('trash', 14)}</button></span></header>${fields}</section>`;
    }

    function renderEditor() {
        const tags = ['{firstname|' + tr('tag_fallback') + '}', '{lastname}', '{email}', '{sitename}'].concat((data.fields || []).map(f => '{' + f.key + '}'));
        mount().innerHTML = `<div class="nl nl-editor-view">
            <div class="editor-back"><a href="#" class="editor-back-link" data-nl="back">${icon('back', 16)} ${esc(tr('all_campaigns'))}</a></div>
            <div class="editor-header"><div class="editor-header-left"><h2>${esc(current.id ? tr('edit_campaign') : tr('new_campaign'))}</h2></div>
                <div class="editor-header-right">
                    <span class="nl-dirty" id="nlDirty" role="status"></span>
                    <button type="button" class="btn btn-secondary btn-sm" data-nl="test">${esc(tr('send_test'))}</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-nl="save">${esc(t('btn.save'))}</button>
                    ${current.status === 'scheduled' ? `<button type="button" class="btn btn-secondary btn-sm" data-nl="unschedule">${esc(tr('unschedule'))}</button>` : ''}
                    <button type="button" class="btn btn-primary btn-sm" data-nl="send">${esc(tr('send'))}…</button>
                </div></div>
            ${current.status === 'scheduled' ? `<p class="nl-scheduled">${esc(tr('scheduled_for', {date: nbFormatDate(current.sendAt, 'datetime')}))}</p>` : ''}
            <div class="nl-editor">
                <form class="nl-editor__form" id="nlForm" autocomplete="off">
                    <div class="form-group"><label for="nlSubject">${esc(tr('subject'))}</label><input id="nlSubject" type="text" maxlength="200" required value="${esc(current.subject)}"></div>
                    <div class="form-group"><label for="nlPreheader">${esc(tr('preheader'))}</label><input id="nlPreheader" type="text" maxlength="250" value="${esc(current.preheader || '')}"><small class="form-hint">${esc(tr('preheader_hint'))}</small></div>
                    <fieldset class="nl-lists"><legend>${esc(tr('recipients'))}</legend>
                        ${data.lists.length ? data.lists.map(l => `<label class="nl-check"><input type="checkbox" name="nlList" value="${esc(l.id)}"${current.lists.includes(l.id) ? ' checked' : ''}> ${esc(l.name)} <span class="nl-count">${l.count}</span></label>`).join('') : ''}
                        <small class="form-hint">${esc(tr(data.lists.length ? 'recipients_hint' : 'recipients_all'))}</small></fieldset>
                    <div id="nlBlocks">${current.blocks.map(blockForm).join('')}</div>
                    <div class="nl-add"><span>${esc(tr('add_block'))}</span>${Object.keys(blockDefaults).map(type => `<button type="button" class="btn btn-secondary btn-sm" data-nl="add-block" data-type="${type}">${esc(tr('type_' + type))}</button>`).join('')}</div>
                    <p class="form-hint nl-tags">${esc(tr('tags_hint'))} ${tags.map(tag => `<code>${esc(tag)}</code>`).join(' ')}</p>
                </form>
                <aside class="nl-preview" aria-label="${esc(tr('preview'))}">
                    <div class="nl-preview__bar"><strong>${esc(tr('preview'))}</strong>
                        <span class="nl-preview__devices">${['desktop', 'mobile'].map(d => `<button type="button" class="nl-tab${device === d ? ' is-active' : ''}" data-nl="device" data-device="${d}">${esc(tr('device_' + d))}</button>`).join('')}</span></div>
                    <p class="nl-preview__subject" id="nlPreviewSubject"></p>
                    <div class="nl-preview__stage nl-preview__stage--${device}"><iframe id="nlPreviewFrame" title="${esc(tr('preview'))}" sandbox=""></iframe></div>
                </aside>
            </div></div>`;
        current.blocks.forEach((block, index) => {
            if (block.type !== 'text') return;
            const field = $('nlb' + index + '-rich');
            field.querySelector('textarea').value = block.html || '';
            window.NbPageEditor?.richEditor(field, 'nl' + (++uid), {markDirty() { touch(); }, pushUndoSnapshot() {}});
        });
        $('nlForm').addEventListener('input', touch);
        $('nlForm').addEventListener('change', event => {
            const pick = event.target.closest('[data-nl-post]');
            if (!pick || pick.value === '') return;
            remember();
            const post = posts[Number(pick.value)];
            Object.assign(current.blocks[Number(pick.dataset.nlPost)], {title: post.title, excerpt: post.excerpt, image: post.image, url: post.url});
            dirty = true;
            renderEditor();
        });
        $('nlForm').addEventListener('submit', event => event.preventDefault());
        if (current.blocks.some(b => b.type === 'post') && !posts) call('load-newsletter-posts').then(result => { posts = result.posts; remember(); renderEditor(); }).catch(() => { posts = []; });
        markDirty();
        preview();
    }

    function markDirty() { const el = $('nlDirty'); if (el) el.textContent = dirty ? tr('unsaved_label') : ''; }
    function touch() { dirty = true; markDirty(); clearTimeout(previewTimer); previewTimer = setTimeout(preview, 500); }

    // Copies the form into the campaign object.
    function remember() {
        if (!current || !$('nlForm')) return;
        current.subject = $('nlSubject').value.trim();
        current.preheader = $('nlPreheader').value.trim();
        current.lists = [...document.querySelectorAll('input[name=nlList]:checked')].map(i => i.value);
        document.querySelectorAll('#nlBlocks .nl-block').forEach(section => {
            const block = current.blocks[Number(section.dataset.block)];
            section.querySelectorAll('[data-field]').forEach(field => { block[field.dataset.field] = field.value; });
            const rich = section.querySelector('textarea.section-field');
            if (rich) block.html = rich.value;
        });
    }

    async function preview() {
        if (!current || !$('nlPreviewFrame')) return;
        remember();
        try {
            const result = await call('newsletter-preview', {campaign: JSON.stringify(current)});
            const frame = $('nlPreviewFrame');
            if (!frame) return;
            frame.srcdoc = result.html;
            $('nlPreviewSubject').textContent = result.subject || tr('untitled');
        } catch (error) { /* the next change tries again */ }
    }

    async function saveCampaign() {
        remember();
        if (!current.subject) { showToast(tr('error_subject'), 'error'); $('nlSubject')?.focus(); return false; }
        let ok = false;
        await act('newsletter-save-campaign', {id: current.id, campaign: JSON.stringify(current)}, result => {
            detail = result; current = structuredClone(result.campaign); dirty = false; ok = true;
        });
        if (ok) { call('load-newsletter').then(result => { data = result; }).catch(() => {}); markDirty(); }
        return ok;
    }

    async function sendDialog() {
        if ((dirty || !current.id) && !(await saveCampaign())) return;
        if (!data.ready) { showToast(data.readyError, 'error'); return; }
        const count = detail.recipientCount || 0;
        const soon = new Date(Date.now() + 3600000 - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        showModal(tr('send_title'), `<p>${esc(tr('send_recipients', {count}))}</p>
            <label class="nl-check"><input type="radio" name="nlWhen" value="now" checked> ${esc(tr('send_now'))}</label>
            <label class="nl-check"><input type="radio" name="nlWhen" value="later"> ${esc(tr('send_later'))}</label>
            <input type="datetime-local" id="nlSendAt" class="modal-input" value="${soon}" min="${soon.slice(0, 10)}T00:00">
            <p class="form-hint">${esc(data.dailyLimit > 0 ? tr('send_limit_hint', {limit: data.dailyLimit}) : tr('send_batch_hint'))}</p>`, () => {
            const later = document.querySelector('input[name=nlWhen]:checked').value === 'later';
            const sendAt = later ? new Date($('nlSendAt').value).toISOString() : '';
            closeModal();
            act('newsletter-start', {id: current.id, sendAt}, async result => {
                detail = result; current = null; dirty = false;
                data = await call('load-newsletter');
                if (result.campaign.status === 'scheduled') { showToast(tr('scheduled_toast'), 'success'); view = 'campaigns'; detail = null; render(); }
                else renderReport();
            });
        }, {html: true, confirmText: tr('send'), confirmClass: 'btn btn-primary'});
        if (!count) { $('modalConfirm').disabled = true; }
    }

    async function testDialog() {
        if ((dirty || !current.id) && !(await saveCampaign())) return;
        showModal(tr('send_test'), `<p>${esc(tr('test_text'))}</p><input type="email" id="nlTestEmail" class="modal-input" value="${esc(localStorage.getItem('nlTestEmail') || data.sender || '')}" placeholder="name@example.com">`, () => {
            const email = $('nlTestEmail').value.trim();
            try { localStorage.setItem('nlTestEmail', email); } catch (e) {}
            closeModal();
            act('newsletter-test', {id: current.id, email}, () => showToast(tr('test_sent', {email}), 'success'));
        }, {html: true, confirmText: tr('send_test'), confirmClass: 'btn btn-primary', focusSelector: '#nlTestEmail'});
    }

    // ---- Report --------------------------------------------------------------------------------

    function renderReport() {
        stopTimers();
        const c = detail.campaign, stats = c.stats || {}, report = detail.report || {recipients: [], links: [], queued: 0};
        const done = (stats.sent || 0) + (stats.failed || 0);
        const tile = (label, value, sub) => `<div class="dashboard-stat-card nl-stat"><span>${esc(label)}</span><strong>${esc(value)}</strong>${sub ? `<small>${esc(sub)}</small>` : ''}</div>`;
        const tracked = c.trackOpens || c.trackClicks;
        const state = row => row.error ? `<span class="nl-status nl-status--bounced" title="${esc(row.error)}">${esc(row.error === 'skipped' ? tr('row_skipped') : tr('row_failed'))}</span>`
            : row.unsubscribed ? statusBadge('unsubscribed', 'sub_') : row.clicks ? `<span class="nl-status nl-status--sent">${esc(tr('row_clicked'))}</span>`
            : row.opened ? `<span class="nl-status nl-status--sent">${esc(tr('row_opened'))}</span>` : `<span class="nl-status">${esc(tr('row_sent'))}</span>`;
        mount().innerHTML = `<div class="nl nl-report">
            <div class="editor-back"><a href="#" class="editor-back-link" data-nl="back">${icon('back', 16)} ${esc(tr('all_campaigns'))}</a></div>
            <div class="editor-header"><div class="editor-header-left"><h2>${esc(c.subject)}</h2> ${statusBadge(c.status)}</div>
                <div class="editor-header-right">
                    ${c.status === 'sending' ? `<button type="button" class="btn btn-secondary btn-sm" data-nl="pause">${esc(tr('pause'))}</button>` : ''}
                    ${c.status === 'paused' ? `<button type="button" class="btn btn-primary btn-sm" data-nl="resume">${esc(tr('resume'))}</button>` : ''}
                    <button type="button" class="btn btn-secondary btn-sm" data-nl="duplicate" data-id="${c.id}">${esc(tr('duplicate'))}</button>
                </div></div>
            ${c.status === 'sending' ? `<div class="nl-progress" role="status"><div class="nl-progress__bar"><span style="width:${stats.total ? Math.round(done / stats.total * 100) : 0}%"></span></div>
                <p>${esc(tr('progress', {done, total: stats.total || 0}))} ${esc(tr('progress_hint'))}</p></div>` : ''}
            ${c.lastError && c.status !== 'sent' ? `<div class="info-banner info-banner--warning info-banner--contained nl-notice" role="alert"><strong>${esc(tr(c.status === 'paused' ? 'paused_error' : 'last_error'))}</strong> ${esc(c.lastError.startsWith('newsletter.') ? t(c.lastError) : c.lastError)}</div>` : ''}
            <div class="dashboard-stat-grid nl-stats">
                ${tile(tr('recipients'), stats.total || 0)}${tile(tr('sent'), stats.sent || 0, stats.failed ? tr('failed_count', {count: stats.failed}) : '')}
                ${tile(tr('opens'), c.trackOpens || stats.opens ? (stats.opens || 0) : '–', c.trackOpens || stats.opens ? percent(stats.opens || 0, stats.sent || 0) : tr('not_tracked'))}
                ${tile(tr('clicks'), c.trackClicks ? (stats.clicks || 0) : '–', c.trackClicks ? percent(stats.clicks || 0, stats.sent || 0) : tr('not_tracked'))}
                ${tile(tr('unsubscribes'), stats.unsubs || 0, percent(stats.unsubs || 0, stats.sent || 0))}
            </div>
            ${tracked ? '' : `<p class="form-hint">${esc(tr('tracking_off_hint'))}</p>`}
            ${report.links.length ? `<h3 class="nl-h3">${esc(tr('clicked_links'))}</h3><div class="page-list-table-wrap"><table class="page-list-table"><thead><tr><th class="page-list-col-title">${esc(tr('link'))}</th><th>${esc(tr('clicks'))}</th></tr></thead><tbody>
                ${report.links.map(l => `<tr><td class="page-list-col-title nl-break">${esc(l.url)}</td><td>${l.count}</td></tr>`).join('')}</tbody></table></div>` : ''}
            <h3 class="nl-h3">${esc(tr('recipients'))}${report.queued ? ` <span class="nl-count">${esc(tr('queued_count', {count: report.queued}))}</span>` : ''}</h3>
            <div class="page-list-table-wrap"><table class="page-list-table"><thead><tr><th class="page-list-col-title">${esc(tr('email'))}</th><th>${esc(tr('status'))}</th><th>${esc(tr('sent'))}</th></tr></thead><tbody>
                ${report.recipients.slice(0, 500).map(r => `<tr><td class="page-list-col-title">${esc(r.email)}</td><td>${state(r)}</td><td>${r.sent ? esc(nbFormatDate(new Date(r.sent * 1000), 'datetime')) : '–'}</td></tr>`).join('') || `<tr><td colspan="3" class="page-list-empty"><p class="page-list-empty__text">${esc(tr('nothing_sent_yet'))}</p></td></tr>`}
            </tbody></table></div></div>`;
        // While this report is open the dashboard itself keeps the queue moving.
        if (c.status === 'sending') runTimer = setTimeout(async () => {
            try { await call('newsletter-run', {}); const fresh = await call('load-newsletter-campaign&id=' + c.id); if (detail && detail.campaign.id === c.id) { detail = fresh; renderReport(); } }
            catch (error) { if (detail && detail.campaign.id === c.id) runTimer = setTimeout(() => renderReport(), 8000); }
        }, 1500);
    }

    // ---- Subscribers ---------------------------------------------------------------------------

    async function renderSubscribers() {
        if (!subscribers) {
            shell(`<p role="status">${esc(tr('loading'))}</p>`);
            try { subscribers = (await call('load-newsletter-subscribers')).subscribers; } catch (error) { showToast(error.message, 'error'); subscribers = []; }
            if (view !== 'subscribers') return;
        }
        const counts = {all: subscribers.length};
        ['subscribed', 'pending', 'unsubscribed', 'bounced'].forEach(s => { counts[s] = subscribers.filter(x => x.status === s).length; });
        shell(`<div class="nb-filter-bar"><div class="nb-filter-bar__links" id="nlSubFilters"></div>
                <div class="nb-filter-bar__actions">
                    <button type="button" class="btn btn-danger btn-sm" data-nl="delete-selected" id="nlDeleteSelected" style="display:none">${esc(tr('delete_selected'))}</button>
                    <label class="page-list-search" for="nlSubSearch"><span class="sr-only">${esc(tr('search'))}</span>
                        <input type="search" id="nlSubSearch" class="page-list-search-input" placeholder="${esc(tr('search'))}…" value="${esc(subSearch)}" autocomplete="off"></label>
                </div></div>
            <div class="admin-list-footer admin-list-footer--top" id="nlSubFooterTop"></div>
            <div class="page-list-table-wrap"><table class="page-list-table"><thead><tr><th class="nl-col-check"><input type="checkbox" id="nlSubAll" aria-label="${esc(tr('select_all'))}"></th><th class="page-list-col-title">${esc(tr('email'))}</th><th>${esc(tr('name'))}</th><th>${esc(tr('tab_lists'))}</th><th>${esc(tr('status'))}</th><th>${esc(tr('since'))}</th></tr></thead><tbody id="nlSubRows"></tbody></table></div>
            <div class="admin-list-footer" id="nlSubFooter"></div>`,
            `<a class="btn btn-secondary btn-sm" href="api.php?action=get-newsletter-export${subStatus !== 'all' ? '&status=' + subStatus : ''}${subList ? '&list=' + encodeURIComponent(subList) : ''}">${esc(tr('export'))}</a>
             <button type="button" class="btn btn-secondary btn-sm" data-nl="import">${esc(tr('import'))}</button>
             <button type="button" class="btn btn-primary btn-sm" data-nl="new-subscriber">${esc(tr('new_subscriber'))}</button>`);
        renderFilterLinks($('nlSubFilters'), [
            {items: ['all', 'subscribed', 'pending', 'unsubscribed', 'bounced'].filter(s => s === 'all' || counts[s]).map(s => ({value: s, label: s === 'all' ? tr('all') : tr('sub_' + s), count: counts[s], active: subStatus === s, onSelect() { subStatus = s; dashboardListPages.nlSubs = 1; renderSubscribers(); }}))},
            data.lists.length && {label: tr('tab_lists'), icon: 'users', items: [{value: '', label: tr('all'), count: subscribers.length, active: !subList, onSelect() { subList = ''; renderSubscribers(); }},
                ...data.lists.map(l => ({value: l.id, label: l.name, count: subscribers.filter(x => (x.lists || []).includes(l.id)).length, active: subList === l.id, onSelect() { subList = l.id; dashboardListPages.nlSubs = 1; renderSubscribers(); }}))]}
        ]);
        $('nlSubSearch').oninput = event => { subSearch = event.target.value; dashboardListPages.nlSubs = 1; subscriberRows(); };
        $('nlSubAll').onchange = event => { document.querySelectorAll('#nlSubRows input[type=checkbox]').forEach(box => { box.checked = event.target.checked; box.checked ? selected.add(box.value) : selected.delete(box.value); }); selectionChanged(); };
        selected = new Set();
        subscriberRows();
    }

    function selectionChanged() { const button = $('nlDeleteSelected'); if (button) { button.style.display = selected.size ? '' : 'none'; button.textContent = tr('delete_selected') + ' (' + selected.size + ')'; } }

    function subscriberRows() {
        const needle = subSearch.toLocaleLowerCase();
        const filtered = subscribers.filter(s => (subStatus === 'all' || s.status === subStatus) && (!subList || (s.lists || []).includes(subList))
            && (!needle || [s.email, s.firstName, s.lastName, ...(s.tags || [])].join(' ').toLocaleLowerCase().includes(needle)))
            .sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)));
        const size = getDashboardPageSize();
        const page = pageSlice(filtered, 'nlSubs', size);
        $('nlSubRows').innerHTML = page.items.map(s => `<tr class="page-list-row"><td class="nl-col-check"><input type="checkbox" value="${esc(s.id)}"${selected.has(s.id) ? ' checked' : ''} aria-label="${esc(s.email)}"></td>
            <td class="page-list-col-title"><a href="#" class="page-list-title-link" data-nl="edit-subscriber" data-id="${esc(s.id)}">${esc(s.email)}</a>
                <div class="page-list-row-actions"><a href="#" class="page-list-row-action" data-nl="edit-subscriber" data-id="${esc(s.id)}">${icon('edit', 12, '2')} ${esc(t('pages.edit'))}</a>
                ${s.status === 'pending' ? `<span class="page-list-row-action-sep">|</span><a href="#" class="page-list-row-action" data-nl="resend" data-id="${esc(s.id)}">${esc(tr('resend_confirm'))}</a>` : ''}</div></td>
            <td>${esc([s.firstName, s.lastName].filter(Boolean).join(' '))}</td><td>${esc((s.lists || []).map(listName).filter(Boolean).join(', '))}${(s.tags || []).map(tag => ` <span class="nl-tag">${esc(tag)}</span>`).join('')}</td>
            <td>${statusBadge(s.status, 'sub_')}</td><td>${esc(nbFormatDate(s.confirmedAt || s.createdAt))}</td></tr>`).join('')
            || `<tr><td colspan="6" class="page-list-empty"><p class="page-list-empty__text">${esc(tr(subscribers.length ? 'no_matches' : 'no_subscribers'))}</p></td></tr>`;
        $('nlSubRows').onchange = event => { if (event.target.type !== 'checkbox') return; event.target.checked ? selected.add(event.target.value) : selected.delete(event.target.value); selectionChanged(); };
        renderAdminListFooter('nlSubFooter', 'nlSubs', filtered.length, size, subscriberRows, 'nlSubFooterTop');
        selectionChanged();
    }

    function subscriberDialog(id) {
        const s = subscribers.find(x => x.id === id) || {id: '', email: '', firstName: '', lastName: '', status: 'subscribed', lists: subList ? [subList] : [], tags: [], fields: {}};
        const field = (key, label, value, type) => `<label class="modal-label" for="nlSub-${key}">${esc(label)}</label><input class="modal-input" id="nlSub-${key}" type="${type || 'text'}" value="${esc(value || '')}">`;
        showModal(tr(id ? 'edit_subscriber' : 'new_subscriber'), `<div class="modal-form nl-modal-form">
            ${field('email', tr('email'), s.email, 'email')}<div class="nl-row"><div>${field('firstName', tr('first_name'), s.firstName)}</div><div>${field('lastName', tr('last_name'), s.lastName)}</div></div>
            ${(data.fields || []).map(f => field('f-' + f.key, f.label, (s.fields || {})[f.key])).join('')}
            ${field('tags', tr('tags'), (s.tags || []).join(', '))}
            <label class="modal-label" for="nlSub-status">${esc(tr('status'))}</label><select class="modal-input" id="nlSub-status">${['subscribed', 'pending', 'unsubscribed', 'bounced'].map(v => `<option value="${v}"${s.status === v ? ' selected' : ''}>${esc(tr('sub_' + v))}</option>`).join('')}</select>
            ${data.lists.length ? `<fieldset class="nl-lists"><legend>${esc(tr('tab_lists'))}</legend>${data.lists.map(l => `<label class="nl-check"><input type="checkbox" name="nlSubList" value="${esc(l.id)}"${(s.lists || []).includes(l.id) ? ' checked' : ''}> ${esc(l.name)}</label>`).join('')}</fieldset>` : ''}
            ${id ? '' : `<p class="form-hint">${esc(tr('manual_consent_hint'))}</p>`}</div>`, () => {
            const subscriber = {id, email: $('nlSub-email').value, firstName: $('nlSub-firstName').value, lastName: $('nlSub-lastName').value, status: $('nlSub-status').value,
                tags: $('nlSub-tags').value.split(',').map(v => v.trim()).filter(Boolean), lists: [...document.querySelectorAll('input[name=nlSubList]:checked')].map(i => i.value),
                fields: Object.fromEntries((data.fields || []).map(f => [f.key, $('nlSub-f-' + f.key).value]))};
            act('newsletter-save-subscriber', {subscriber: JSON.stringify(subscriber)}, () => { closeModal(); subscribers = null; renderSubscribers(); showToast(tr('saved'), 'success'); });
        }, {html: true, confirmText: t('btn.save'), confirmClass: 'btn btn-primary', modalClass: 'modal-large', focusSelector: '#nlSub-email'});
    }

    function importDialog() {
        showModal(tr('import'), `<div class="modal-form nl-modal-form"><p>${esc(tr('import_text'))}</p>
            <input type="file" id="nlImportFile" accept=".csv,.txt,text/csv,text/plain" class="modal-input">
            <label class="modal-label" for="nlImportText">${esc(tr('import_paste'))}</label><textarea id="nlImportText" class="modal-input" rows="5" placeholder="email;first_name;last_name"></textarea>
            ${data.lists.length ? `<fieldset class="nl-lists"><legend>${esc(tr('import_lists'))}</legend>${data.lists.map(l => `<label class="nl-check"><input type="checkbox" name="nlImportList" value="${esc(l.id)}"${subList === l.id ? ' checked' : ''}> ${esc(l.name)}</label>`).join('')}</fieldset>` : ''}
            <label class="modal-label" for="nlImportExisting">${esc(tr('import_existing'))}</label><select id="nlImportExisting" class="modal-input"><option value="skip">${esc(tr('import_skip'))}</option><option value="update">${esc(tr('import_update'))}</option></select>
            <label class="nl-check"><input type="checkbox" id="nlImportConsent"> ${esc(tr('import_consent'))}</label></div>`, async () => {
            if (!$('nlImportConsent').checked) { showToast(tr('import_consent_required'), 'error'); return; }
            const file = $('nlImportFile').files[0];
            const csv = file ? await file.text() : $('nlImportText').value;
            if (!csv.trim()) { showToast(tr('import_empty'), 'error'); return; }
            act('newsletter-import', {csv, existing: $('nlImportExisting').value, lists: JSON.stringify([...document.querySelectorAll('input[name=nlImportList]:checked')].map(i => i.value))}, result => {
                closeModal(); subscribers = null; renderSubscribers();
                showToast(tr('import_done', result.result), 'success');
            });
        }, {html: true, confirmText: tr('import'), confirmClass: 'btn btn-primary', modalClass: 'modal-large'});
    }

    // ---- Lists ---------------------------------------------------------------------------------

    function renderLists() {
        shell(`<div class="page-list-table-wrap"><table class="page-list-table"><thead><tr><th class="page-list-col-title">${esc(tr('name'))}</th><th>${esc(tr('description'))}</th><th>${esc(tr('tab_subscribers'))}</th></tr></thead><tbody>
            ${data.lists.map(l => `<tr class="page-list-row"><td class="page-list-col-title"><a href="#" class="page-list-title-link" data-nl="edit-list" data-id="${esc(l.id)}">${esc(l.name)}</a>
                <div class="page-list-row-actions"><a href="#" class="page-list-row-action" data-nl="edit-list" data-id="${esc(l.id)}">${icon('edit', 12, '2')} ${esc(t('pages.edit'))}</a><span class="page-list-row-action-sep">|</span><a href="#" class="page-list-row-action page-list-row-action--danger" data-nl="delete-list" data-id="${esc(l.id)}">${icon('trash', 12, '2')} ${esc(t('btn.delete'))}</a></div></td>
                <td>${esc(l.description || '')}</td><td>${l.count}</td></tr>`).join('') || `<tr><td colspan="3" class="page-list-empty"><p class="page-list-empty__text">${esc(tr('no_lists'))}</p></td></tr>`}
            </tbody></table></div><p class="form-hint">${esc(tr('lists_hint'))}</p>`,
            `<button type="button" class="btn btn-primary btn-sm" data-nl="new-list">${esc(tr('new_list'))}</button>`);
    }

    function listDialog(id) {
        const list = data.lists.find(l => l.id === id) || {id: '', name: '', description: ''};
        showModal(tr(id ? 'edit_list' : 'new_list'), `<div class="modal-form nl-modal-form"><label class="modal-label" for="nlListName">${esc(tr('name'))}</label><input class="modal-input" id="nlListName" maxlength="80" value="${esc(list.name)}">
            <label class="modal-label" for="nlListDescription">${esc(tr('description'))}</label><input class="modal-input" id="nlListDescription" maxlength="200" value="${esc(list.description || '')}"></div>`, () => {
            act('newsletter-save-list', {id, name: $('nlListName').value, description: $('nlListDescription').value}, () => { closeModal(); subscribers = null; renderLists(); });
        }, {html: true, confirmText: t('btn.save'), confirmClass: 'btn btn-primary', focusSelector: '#nlListName'});
    }

    // ---- Mail log ------------------------------------------------------------------------------

    async function renderLog(reload) {
        if (!log || reload !== false) {
            if (!log) shell(`<p role="status">${esc(tr('loading'))}</p>`);
            try { log = await call('load-newsletter-log'); } catch (error) { showToast(error.message, 'error'); log = {entries: [], enabled: true, days: 30}; }
            if (view !== 'log') return;
        }
        const errors = log.entries.filter(e => e.error).length;
        shell(`<p class="form-hint">${esc(tr(log.enabled ? 'log_hint' : 'log_off', {days: log.days}))}</p>
            <div class="nb-filter-bar"><div class="nb-filter-bar__links" id="nlLogFilters"></div>
                <div class="nb-filter-bar__actions"><label class="page-list-search" for="nlLogSearch"><span class="sr-only">${esc(tr('search'))}</span>
                    <input type="search" id="nlLogSearch" class="page-list-search-input" placeholder="${esc(tr('search'))}…" value="${esc(logSearch)}" autocomplete="off"></label></div></div>
            <div class="admin-list-footer admin-list-footer--top" id="nlLogFooterTop"></div>
            <div class="page-list-table-wrap"><table class="page-list-table"><thead><tr><th>${esc(tr('log_time'))}</th><th class="page-list-col-title">${esc(tr('email'))}</th><th>${esc(tr('subject'))}</th><th>${esc(tr('log_type'))}</th><th>${esc(tr('log_result'))}</th></tr></thead><tbody id="nlLogRows"></tbody></table></div>
            <div class="admin-list-footer" id="nlLogFooter"></div>`,
            `<button type="button" class="btn btn-secondary btn-sm" data-nl="reload-log">${esc(tr('log_reload'))}</button>
             ${data.isAdmin && log.entries.length ? `<button type="button" class="btn btn-danger btn-sm" data-nl="clear-log">${esc(tr('log_clear'))}</button>` : ''}`);
        renderFilterLinks($('nlLogFilters'), [{items: [['all', tr('all'), log.entries.length], ['errors', tr('log_errors'), errors]].filter(([value, , count]) => value === 'all' || count)
            .map(([value, label, count]) => ({value, label, count, active: logFilter === value, onSelect() { logFilter = value; dashboardListPages.nlLog = 1; renderLog(false); }}))}]);
        $('nlLogSearch').oninput = event => { logSearch = event.target.value; dashboardListPages.nlLog = 1; logRows(); };
        logRows();
    }

    function logRows() {
        const needle = logSearch.toLocaleLowerCase();
        const filtered = log.entries.filter(e => (logFilter === 'all' || e.error) && (!needle || (e.to + ' ' + e.subject + ' ' + e.error).toLocaleLowerCase().includes(needle)));
        const size = getDashboardPageSize();
        const page = pageSlice(filtered, 'nlLog', size);
        $('nlLogRows').innerHTML = page.items.map(e => `<tr class="page-list-row"><td>${esc(nbFormatDate(new Date(e.at * 1000), 'datetime'))}</td><td class="page-list-col-title">${esc(e.to)}</td><td>${esc(e.subject)}</td>
            <td>${esc(tr('log_type_' + (['campaign', 'confirm', 'test'].includes(e.type) ? e.type : 'campaign')))}</td>
            <td>${e.error ? `<span class="nl-status nl-status--bounced">${esc(tr('row_failed'))}</span> <span class="nl-log-error">${esc(e.error.startsWith('newsletter.') ? t(e.error) : e.error)}</span>` : `<span class="nl-status nl-status--sent">${esc(tr('row_sent'))}</span>`}</td></tr>`).join('')
            || `<tr><td colspan="5" class="page-list-empty"><p class="page-list-empty__text">${esc(tr('log_empty'))}</p></td></tr>`;
        renderAdminListFooter('nlLogFooter', 'nlLog', filtered.length, size, logRows, 'nlLogFooterTop');
    }

    // ---- Settings ------------------------------------------------------------------------------

    function renderSettings() {
        const s = data.settings;
        const input = (key, label, type, hint, attrs) => `<div class="form-group"><label for="nlSet-${key}">${esc(tr(label))}</label>${hint ? `<small class="form-hint">${esc(tr(hint))}</small>` : ''}<input id="nlSet-${key}" data-set="${key}" type="${type || 'text'}" value="${esc(s[key] ?? '')}" ${attrs || ''}></div>`;
        const toggle = (key, label, hint) => `<div class="form-group"><label class="toggle-label"><span>${esc(tr(label))}</span><div class="toggle-switch"><input type="checkbox" data-set="${key}"${s[key] ? ' checked' : ''}><span class="toggle-slider"></span></div></label>${hint ? `<small class="form-hint">${esc(tr(hint, {days: 30}))}</small>` : ''}</div>`;
        // Round picker plus hex field, as in Settings → Dashboard design
        const color = (key, label) => `<div class="form-group"><label for="nlSet-${key}">${esc(tr(label))}</label><div class="color-input-group"><input type="color" class="color-picker" data-color-for="nlSet-${key}" value="${esc(s[key])}" aria-label="${esc(tr(label))}"><input type="text" id="nlSet-${key}" data-set="${key}" class="color-hex-input" value="${esc(s[key])}" pattern="^#[0-9a-fA-F]{6}$" maxlength="7"></div></div>`;
        const secret = (key, label, has, hint) => `<div class="form-group"><label for="nlSet-${key}">${esc(tr(label))}</label>${hint ? `<small class="form-hint">${esc(tr(hint))}</small>` : ''}<input id="nlSet-${key}" data-set="${key}" type="password" autocomplete="new-password" value="" placeholder="${esc(has ? tr('password_kept') : '')}"></div>`;
        shell(`<div class="settings-panel active nl-settings"><form id="nlSettings" class="settings-form" autocomplete="off">
            <fieldset class="settings-section"><legend>${esc(tr('set_sender'))}</legend>
                ${input('senderName', 'set_sender_name')}${input('senderEmail', 'set_sender_email', 'email', 'set_sender_email_hint')}
                ${input('replyTo', 'set_reply_to', 'email')}</fieldset>
            <fieldset class="settings-section"><legend>${esc(tr('set_delivery'))}</legend>
                <div class="form-group"><label for="nlSet-delivery">${esc(tr('set_delivery_method'))}</label><select id="nlSet-delivery" data-set="delivery">
                    <option value="site"${s.delivery === 'site' ? ' selected' : ''}>${esc(tr('delivery_site'))}</option><option value="gmail"${s.delivery === 'gmail' ? ' selected' : ''}>${esc(tr('delivery_gmail'))}</option><option value="smtp"${s.delivery === 'smtp' ? ' selected' : ''}>${esc(tr('delivery_smtp'))}</option></select></div>
                <div class="form-group-row" data-delivery="site"><p class="form-hint">${esc(tr(data.siteMail ? 'delivery_site_hint' : 'delivery_site_missing'))}</p></div>
                <div class="form-group-row" data-delivery="gmail"><div class="nl-help"><strong>${esc(tr('gmail_help_title'))}</strong><ol><li>${esc(tr('gmail_step_1'))}</li><li>${esc(tr('gmail_step_2'))} <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">myaccount.google.com/apppasswords</a></li><li>${esc(tr('gmail_step_3'))}</li></ol><p>${esc(tr('gmail_limit_hint'))}</p></div>
                    ${input('gmailUser', 'gmail_user', 'email')}${secret('gmailPassword', 'gmail_password', s.hasGmailPassword)}</div>
                <div class="form-group-row" data-delivery="smtp">${input('smtpHost', 'smtp_host')}${input('smtpPort', 'smtp_port', 'number')}
                    ${input('smtpUsername', 'smtp_user')}${secret('smtpPassword', 'smtp_password', s.hasSmtpPassword)}
                    <div class="form-group"><label for="nlSet-smtpEncryption">${esc(tr('smtp_encryption'))}</label><select id="nlSet-smtpEncryption" data-set="smtpEncryption">${['tls', 'ssl', 'none'].map(v => `<option value="${v}"${s.smtpEncryption === v ? ' selected' : ''}>${v === 'none' ? esc(tr('smtp_none')) : v.toUpperCase()}</option>`).join('')}</select></div></div>
                ${input('batchSize', 'set_batch', 'number', 'set_batch_hint', 'min="1" max="200"')}${input('dailyLimit', 'set_daily', 'number', 'set_daily_hint', 'min="0"')}
                <p class="form-hint">${esc(tr('sent_24h', {count: data.sent24h}))}</p></fieldset>
            <fieldset class="settings-section"><legend>${esc(tr('set_cron'))}</legend><p class="form-hint">${esc(tr('set_cron_hint'))}</p>
                <div class="form-group"><label for="nlCronCommand">${esc(tr('cron_command'))}</label><input id="nlCronCommand" type="text" readonly value="* * * * * ${esc(data.cronCommand)}" onfocus="this.select()"></div>
                <div class="form-group"><label for="nlCronUrl">${esc(tr('cron_url'))}</label><input id="nlCronUrl" type="text" readonly value="${esc(data.cronUrl || tr('cron_url_missing'))}" onfocus="this.select()"></div>
                <p class="form-hint">${esc(data.lastRun ? tr('last_run', {date: nbFormatDate(data.lastRun, 'datetime')}) : tr('never_run'))}</p></fieldset>
            <fieldset class="settings-section"><legend>${esc(tr('set_signup'))}</legend>
                ${toggle('doubleOptIn', 'set_doi', 'set_doi_hint')}
                ${input('reminders', 'set_reminders', 'number', '', 'min="0" max="5"')}${input('reminderHours', 'set_reminder_hours', 'number', '', 'min="1" max="720"')}
                <div class="form-group"><label for="nlSet-consentText">${esc(tr('set_consent'))}</label><small class="form-hint">${esc(tr('set_consent_hint'))}</small><textarea id="nlSet-consentText" data-set="consentText" rows="2" maxlength="1000">${esc(s.consentText)}</textarea></div>
                ${toggle('storeIp', 'set_store_ip', 'set_store_ip_hint')}
                <div class="form-group"><label for="nlSet-fields">${esc(tr('set_fields'))}</label><small class="form-hint">${esc(tr('set_fields_hint'))}</small><input id="nlSet-fields" type="text" value="${esc((s.fields || []).map(f => f.label).join(', '))}"></div>
                <p class="form-hint">${esc(tr('embed_note'))}</p></fieldset>
            <fieldset class="settings-section"><legend>${esc(tr('set_tracking'))}</legend><p class="form-hint">${esc(tr('set_tracking_hint'))}</p>
                ${toggle('trackOpens', 'set_track_opens')}${toggle('trackClicks', 'set_track_clicks')}${toggle('webVersion', 'set_web_version', 'set_web_version_hint')}${toggle('mailLog', 'set_mail_log', 'set_mail_log_hint')}</fieldset>
            <fieldset class="settings-section"><legend>${esc(tr('set_design'))}</legend>
                ${color('accentColor', 'set_accent')}${color('backgroundColor', 'set_background')}
                <div class="form-group"><label for="nlSet-logo">${esc(tr('set_logo'))}</label><div class="nl-row nl-row--media"><input id="nlSet-logo" data-set="logo" type="text" value="${esc(s.logo)}"><button type="button" class="btn btn-secondary btn-sm" data-nl="pick-logo">${esc(tr('choose_image'))}</button></div></div>
                <div class="form-group"><label for="nlSet-footerText">${esc(tr('set_footer'))}</label><small class="form-hint">${esc(tr('set_footer_hint'))}</small><textarea id="nlSet-footerText" data-set="footerText" rows="4" maxlength="2000">${esc(s.footerText)}</textarea></div></fieldset>
            <div class="settings-actions"><button type="submit" class="btn btn-primary">${esc(tr('save_settings'))}</button></div></form></div>`);
        const delivery = $('nlSet-delivery');
        const sync = () => document.querySelectorAll('#nlSettings [data-delivery]').forEach(el => { el.style.display = el.dataset.delivery === delivery.value ? '' : 'none'; });
        delivery.onchange = sync; sync();
        document.querySelectorAll('#nlSettings [data-color-for]').forEach(picker => {
            const hex = $(picker.dataset.colorFor);
            picker.oninput = () => { hex.value = picker.value; };
            hex.oninput = () => { if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) picker.value = hex.value; };
        });
        $('nlSettings').onsubmit = event => {
            event.preventDefault();
            const settings = {};
            document.querySelectorAll('#nlSettings [data-set]').forEach(el => { settings[el.dataset.set] = el.type === 'checkbox' ? el.checked : el.value; });
            const known = Object.fromEntries((s.fields || []).map(f => [f.label, f.key]));
            settings.fields = $('nlSet-fields').value.split(',').map(v => v.trim()).filter(Boolean).map(label => ({label, key: known[label] || ''}));
            act('newsletter-save-settings', {settings: JSON.stringify(settings)}, () => { renderSettings(); showToast(tr('settings_saved'), 'success'); });
        };
    }

    // ---- Events --------------------------------------------------------------------------------

    mount().addEventListener('click', async event => {
        const button = event.target.closest('[data-nl]');
        if (!button) return;
        if (button.tagName === 'A') event.preventDefault();
        if (busy) return;
        const action = button.dataset.nl, id = button.dataset.id || '';
        if (action === 'tab') { if (!leave()) return; current = null; detail = null; dirty = false; log = null; view = button.dataset.view; return render(); }
        if (action === 'back') { if (!leave()) return; view = 'campaigns'; return open(); }
        if (action === 'new') return editCampaign('');
        if (action === 'edit' || action === 'report') return editCampaign(id);
        if (action === 'duplicate') return act('newsletter-duplicate-campaign', {id}, result => { dirty = false; editCampaign(result.id); });
        if (action === 'delete-campaign') return showModal(t('btn.delete'), tr('confirm_delete_campaign'), () => { closeModal(); act('newsletter-delete-campaign', {id}, () => render()); });
        if (action === 'save') { if (await saveCampaign()) showToast(tr('saved'), 'success'); return; }
        if (action === 'send') return sendDialog();
        if (action === 'test') return testDialog();
        if (action === 'unschedule') return act('newsletter-unschedule', {id: current.id}, result => { detail = result; current = structuredClone(result.campaign); renderEditor(); });
        if (action === 'pause' || action === 'resume') return act('newsletter-' + action, {id: detail.campaign.id}, result => { detail = result; renderReport(); });
        if (action === 'device') { remember(); device = button.dataset.device; return renderEditor(); }
        if (action === 'add-block') { remember(); current.blocks.push(structuredClone(blockDefaults[button.dataset.type])); dirty = true; renderEditor(); return mount().querySelector('.nl-block:last-child')?.scrollIntoView({behavior: 'smooth', block: 'center'}); }
        if (action === 'remove-block') { remember(); current.blocks.splice(Number(button.dataset.index), 1); dirty = true; return renderEditor(); }
        if (action === 'move') { remember(); const from = Number(button.dataset.index), to = from + Number(button.dataset.direction); if (to < 0 || to >= current.blocks.length) return; current.blocks.splice(to, 0, current.blocks.splice(from, 1)[0]); dirty = true; return renderEditor(); }
        if (action === 'pick-image') { remember(); const block = current.blocks[Number(button.dataset.index)]; return window.NbImageManager.open(path => { block[button.dataset.key] = path; dirty = true; renderEditor(); }, block[button.dataset.key], {types: ['image']}); }
        if (action === 'pick-logo') return window.NbImageManager.open(path => { $('nlSet-logo').value = path.startsWith('/') ? path : '/' + path; }, $('nlSet-logo').value, {types: ['image']});
        if (action === 'new-subscriber' || action === 'edit-subscriber') return subscriberDialog(id);
        if (action === 'resend') return act('newsletter-resend-confirm', {id}, () => showToast(tr('confirm_resent'), 'success'));
        if (action === 'import') return importDialog();
        if (action === 'delete-selected') return showModal(t('btn.delete'), tr('confirm_delete_subscribers', {count: selected.size}), () => { closeModal(); act('newsletter-delete-subscribers', {ids: JSON.stringify([...selected])}, () => { subscribers = null; renderSubscribers(); }); });
        if (action === 'reload-log') return renderLog();
        if (action === 'clear-log') return showModal(tr('log_clear'), tr('log_confirm_clear'), () => { closeModal(); act('newsletter-clear-log', {}, () => { log = null; renderLog(); }); });
        if (action === 'new-list' || action === 'edit-list') return listDialog(id);
        if (action === 'delete-list') return showModal(t('btn.delete'), tr('confirm_delete_list'), () => { closeModal(); act('newsletter-delete-list', {id}, () => { subscribers = null; renderLists(); }); });
    });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    return {open, canLeave: leave};
})();
