<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>

    // ============================================================
    // MAIL MANAGEMENT
    // ============================================================

    let mailsData = [];
    let mailFormsData = [];
    let mailFormFilter = '';
    let mailSortField = 'received';
    let mailSortDir = 'desc';

    async function loadMails() {
        try {
            if (!currentSettings) {
                await loadSettings();
            }
            const response = await fetch('api.php?action=load-mails');
            const result = await response.json();

            if (result.success) {
                if (Array.isArray(result.data)) {
                    mailsData = result.data;
                    mailFormsData = [];
                } else {
                    mailsData = result.data.mails || [];
                    mailFormsData = result.data.forms || [];
                }
                populateMailFormFilter();
                renderMails();
                updateMailBadge();
                updateMailConfigBanner();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    function renderMails() {
        const tbody = document.getElementById('mailsList');
        if (!tbody) return;
        tbody.innerHTML = '';
        const visibleMails = getFilteredMails();

        if (!visibleMails || visibleMails.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="6" style="color: var(--nb-text-muted); text-align: center; padding: var(--nb-space-6);">${escapeHtml(t('mails.no_messages'))}</td>`;
            tbody.appendChild(tr);
            renderAdminListFooter('mailsListFooter', 'mails', 0, getDashboardPageSize(), renderMails, 'mailsListFooterTop');
            updateMailBulkActions();
            updateMailSortIndicators();
            return;
        }

        const sortedMails = getSortedMails(visibleMails);
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(sortedMails, 'mails', pageSize);
        renderAdminListFooter('mailsListFooter', 'mails', sortedMails.length, pageSize, renderMails, 'mailsListFooterTop');

        paged.items.forEach(mail => {
            const date = new Date(mail.timestamp);
            const dateStr = date.toLocaleDateString();
            const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const isUnread = !mail.read;
            const isStarred = !!mail.starred;
            const formLabel = mail.formLabel || mail.formId || t('forms.contact_default');

            const tr = document.createElement('tr');
            tr.className = 'page-list-row';
            if (isUnread) tr.classList.add('mail-row-unread');

            const tdRead = document.createElement('td');
            tdRead.className = 'mail-cell-flag';
            const readBtn = document.createElement('button');
            readBtn.type = 'button';
            readBtn.className = 'mail-flag-btn mail-read-toggle' + (isUnread ? ' is-active' : '');
            readBtn.title = isUnread ? t('mails.mark_read') : t('mails.mark_unread');
            readBtn.setAttribute('aria-label', readBtn.title);
            readBtn.innerHTML = `<span class="mail-read-dot" aria-hidden="true"></span>`;
            readBtn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                setMailFlags(mail.id, { read: isUnread });
            };
            tdRead.appendChild(readBtn);

            const tdStar = document.createElement('td');
            tdStar.className = 'mail-cell-flag';
            const starBtn = document.createElement('button');
            starBtn.type = 'button';
            starBtn.className = 'mail-flag-btn mail-star-toggle' + (isStarred ? ' is-active' : '');
            starBtn.title = isStarred ? t('mails.unstar') : t('mails.star');
            starBtn.setAttribute('aria-label', starBtn.title);
            starBtn.textContent = isStarred ? '★' : '☆';
            starBtn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                setMailFlags(mail.id, { starred: !isStarred });
            };
            tdStar.appendChild(starBtn);

            const tdForm = document.createElement('td');
            tdForm.className = 'mail-cell-form';
            tdForm.textContent = formLabel;

            const tdFrom = document.createElement('td');
            tdFrom.className = 'page-list-cell-title';

            const fromLink = document.createElement('a');
            fromLink.href = '#';
            fromLink.className = 'page-list-title-link';
            fromLink.textContent = mail.name;
            fromLink.onclick = (e) => { e.preventDefault(); openMailDetail(mail.id); };
            tdFrom.appendChild(fromLink);

            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';

            const viewLink = document.createElement('a');
            viewLink.href = '#';
            viewLink.className = 'page-list-row-action';
            viewLink.innerHTML = icon('eye', 12, '2') + ' ' + t('pages.view');
            viewLink.onclick = (e) => { e.preventDefault(); openMailDetail(mail.id); };
            actions.appendChild(viewLink);

            const sep = document.createElement('span');
            sep.className = 'page-list-row-action-sep';
            sep.textContent = '|';
            actions.appendChild(sep);

            const trashLink = document.createElement('a');
            trashLink.href = '#';
            trashLink.className = 'page-list-row-action page-list-row-action--danger';
            trashLink.innerHTML = icon('trash', 12, '2') + ' ' + t('btn.delete');
            trashLink.onclick = (e) => { e.preventDefault(); deleteMail(mail.id); };
            actions.appendChild(trashLink);

            tdFrom.appendChild(actions);

            const tdSubject = document.createElement('td');
            tdSubject.textContent = mail.occasion || '';

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            tdDate.textContent = `${dateStr} ${timeStr}`;

            tr.appendChild(tdRead);
            tr.appendChild(tdStar);
            tr.appendChild(tdForm);
            tr.appendChild(tdFrom);
            tr.appendChild(tdSubject);
            tr.appendChild(tdDate);
            tbody.appendChild(tr);
        });
        updateMailBulkActions();
        updateMailSortIndicators();
    }

    function populateMailFormFilter() {
        const select = document.getElementById('mailFormFilter');
        if (!select) return;
        const current = mailFormFilter;
        const byId = new Map();
        (mailFormsData || []).forEach(form => {
            if (form.id) byId.set(form.id, form.label || form.id);
        });
        (mailsData || []).forEach(mail => {
            const id = mail.formId || 'contact';
            if (!byId.has(id)) byId.set(id, mail.formLabel || id);
        });
        select.innerHTML = `<option value="">${escapeHtml(t('mails.all_forms'))}</option>`;
        Array.from(byId.entries())
            .sort((a, b) => String(a[1]).localeCompare(String(b[1]), undefined, { sensitivity: 'base' }))
            .forEach(([id, label]) => {
                const option = document.createElement('option');
                option.value = id;
                option.textContent = label;
                select.appendChild(option);
            });
        select.value = byId.has(current) ? current : '';
        mailFormFilter = select.value;
    }

    function setMailFormFilter(value) {
        mailFormFilter = value || '';
        dashboardListPages.mails = 1;
        renderMails();
    }

    function getFilteredMails() {
        const list = Array.isArray(mailsData) ? mailsData : [];
        if (!mailFormFilter) return list;
        return list.filter(mail => (mail.formId || 'contact') === mailFormFilter);
    }

    function getSortedMails(list) {
        return [...(list || mailsData)].sort((a, b) => {
            let cmp = 0;
            if (mailSortField === 'read') {
                cmp = Number(!!a.read) - Number(!!b.read);
            } else if (mailSortField === 'starred') {
                cmp = Number(!!a.starred) - Number(!!b.starred);
            } else if (mailSortField === 'form') {
                cmp = String(a.formLabel || a.formId || '').localeCompare(String(b.formLabel || b.formId || ''), undefined, { sensitivity: 'base' });
            } else if (mailSortField === 'from') {
                cmp = String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
            } else if (mailSortField === 'subject') {
                cmp = String(a.occasion || '').localeCompare(String(b.occasion || ''), undefined, { sensitivity: 'base' });
            } else {
                cmp = new Date(a.timestamp || 0).getTime() - new Date(b.timestamp || 0).getTime();
            }
            return mailSortDir === 'asc' ? cmp : -cmp;
        });
    }

    function sortMails(field) {
        if (mailSortField === field) {
            mailSortDir = mailSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            mailSortField = field;
            mailSortDir = field === 'read' || field === 'from' || field === 'subject' || field === 'form' ? 'asc' : 'desc';
        }
        dashboardListPages.mails = 1;
        renderMails();
    }

    function updateMailSortIndicators() {
        document.querySelectorAll('#mailsListTable .page-list-sortable').forEach(th => {
            const iconEl = th.querySelector('.page-list-sort-icon');
            if (!iconEl) return;
            if (th.dataset.mailSort === mailSortField) {
                th.classList.add('sorted');
                iconEl.textContent = mailSortDir === 'asc' ? '▲' : '▼';
            } else {
                th.classList.remove('sorted');
                iconEl.textContent = '';
            }
        });
    }

    function updateMailBadge() {
        const unreadCount = mailsData.filter(m => !m.read).length;
        const badge = document.getElementById('mailBadge');
        if (!badge) return;

        if (unreadCount > 0) {
            badge.textContent = unreadCount;
            badge.classList.remove('mail-badge--hidden');
        } else {
            badge.classList.add('mail-badge--hidden');
        }
    }

    function updateMailBulkActions() {
        const btn = document.getElementById('deleteReadMailsBtn');
        if (!btn) return;
        const readCount = (mailsData || []).filter(m => m.read).length;
        btn.disabled = readCount === 0;
        btn.textContent = readCount > 0
            ? t('mails.delete_read_count', {count: readCount})
            : t('mails.delete_read');
    }

    function updateMailConfigBanner() {
        const banner = document.getElementById('mailConfigBanner');
        if (!banner) return;
        const title = document.getElementById('mailConfigBannerTitle');
        const text = document.getElementById('mailConfigBannerText');
        const email = (currentSettings && currentSettings.email) || {};
        const method = email.method || 'inactive';
        let titleText = '';
        let bodyText = '';

        if (method === 'inactive') {
            titleText = t('mails.email_inactive_title');
            bodyText = t('mails.email_inactive_text');
        } else if (!email.recipientEmail) {
            titleText = t('mails.email_recipient_missing_title');
            bodyText = t('mails.email_recipient_missing_text');
        }

        if (!titleText) {
            banner.hidden = true;
            return;
        }

        title.textContent = titleText;
        text.textContent = bodyText;
        banner.hidden = false;
    }

    function openEmailSettings() {
        switchTab('settings');
        const emailTab = document.querySelector('[data-settings-tab="email"]');
        if (emailTab) emailTab.click();
    }

    async function loadUnreadCount() {
        try {
            const response = await fetch('api.php?action=unread-mail-count');
            const result = await response.json();

            if (result.success) {
                const badge = document.getElementById('mailBadge');
                if (!badge) return;
                if (result.data.count > 0) {
                    badge.textContent = result.data.count;
                    badge.classList.remove('mail-badge--hidden');
                }
            }
        } catch (error) {
            // Badge is non-critical; ignore transient fetch aborts during navigation/tests.
        }
    }

    function openMailDetail(mailId) {
        const mail = mailsData.find(m => m.id === mailId);
        if (!mail) return;

        const date = new Date(mail.timestamp);
        const dateStr = date.toLocaleDateString();
        const timeStr = date.toLocaleTimeString();
        const formLabel = mail.formLabel || mail.formId || t('forms.contact_default');
        const fields = Array.isArray(mail.fields) ? mail.fields.filter(field => field && field.value !== '') : [];
        const fieldsHtml = fields.length ? `
            <div class="mail-detail-row mail-detail-fields">
                <label>${t('mails.form_fields')}</label>
                <div class="mail-fields-list">
                    ${fields.map(field => `
                        <div class="mail-field-item">
                            <span>${escapeHtml(field.label || field.key || '')}</span>
                            <strong>${escapeHtml(field.value || '').replace(/\n/g, '<br>')}</strong>
                        </div>
                    `).join('')}
                </div>
            </div>
        ` : '';

        document.getElementById('mailDetailTitle').textContent = mail.occasion;
        document.getElementById('mailDetailContent').innerHTML = `
            <div class="mail-detail-row">
                <label>${t('mails.form')}</label>
                <span>${escapeHtml(formLabel)}</span>
            </div>
            <div class="mail-detail-row">
                <label>${t('mails.date')}</label>
                <span>${dateStr} ${timeStr}</span>
            </div>
            <div class="mail-detail-row">
                <label>${t('mails.name')}</label>
                <span>${escapeHtml(mail.name)}</span>
            </div>
            <div class="mail-detail-row">
                <label>${t('mails.email')}</label>
                <span><a href="mailto:${escapeHtml(mail.email)}">${escapeHtml(mail.email)}</a></span>
            </div>
            ${mail.phone ? `
            <div class="mail-detail-row">
                <label>${t('mails.phone')}</label>
                <span><a href="tel:${escapeHtml(mail.phone)}">${escapeHtml(mail.phone)}</a></span>
            </div>
            ` : ''}
            ${mail.date ? `
            <div class="mail-detail-row">
                <label>${t('mails.preferred_date')}</label>
                <span>${new Date(mail.date).toLocaleDateString()}</span>
            </div>
            ` : ''}
            <div class="mail-detail-row mail-detail-message">
                <label>${t('mails.message')}</label>
                <div class="mail-message-text">${escapeHtml(mail.message).replace(/\n/g, '<br>')}</div>
            </div>
            ${fieldsHtml}
        `;

        document.getElementById('mailDeleteBtn').onclick = () => deleteMail(mailId);
        document.getElementById('mailsListView').style.display = 'none';
        document.getElementById('mailDetailView').style.display = '';

        if (!mail.read) {
            markMailRead(mailId);
        }
    }

    function closeMailDetail() {
        document.getElementById('mailDetailView').style.display = 'none';
        document.getElementById('mailsListView').style.display = '';
    }

    async function markMailRead(mailId) {
        return setMailFlags(mailId, { read: true }, { silent: true });
    }

    async function setMailFlags(mailId, flags, options = {}) {
        try {
            const formData = new FormData();
            formData.append('action', 'update-mail-flags');
            formData.append('mail_id', mailId);
            formData.append('csrf_token', CSRF_TOKEN);
            Object.entries(flags).forEach(([key, value]) => {
                formData.append(key, value ? '1' : '0');
            });

            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                const mail = mailsData.find(m => m.id === mailId);
                if (mail) {
                    Object.assign(mail, flags);
                }
                renderMails();
                updateMailBadge();
            } else if (!options.silent) {
                showToast(result.message, 'error');
            }
        } catch (error) {
            if (options.silent) {
                console.error('Error updating mail flags:', error);
            } else {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            }
        }
    }

    async function markAllMailsRead() {
        try {
            const formData = new FormData();
            formData.append('action', 'mark-all-mails-read');
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                mailsData.forEach(m => m.read = true);
                renderMails();
                updateMailBadge();
                showToast(t('toast.all_read'), 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }

    function deleteReadMails() {
        const readCount = (mailsData || []).filter(m => m.read).length;
        if (readCount === 0) return;

        showModal(t('modal.delete_read_messages'), t('modal.delete_read_messages_confirm', {count: readCount}), async () => {
            try {
                const formData = new FormData();
                formData.append('action', 'delete-read-mails');
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch('api.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    mailsData = mailsData.filter(m => !m.read);
                    renderMails();
                    updateMailBadge();
                    closeModal();
                    showToast(t('toast.read_messages_deleted', {count: result.data?.deleted || readCount}), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            }
        });
    }

    function deleteMail(mailId) {
        showModal(t('modal.delete_message'), t('modal.delete_message_confirm'), async () => {
            try {
                const formData = new FormData();
                formData.append('action', 'delete-mail');
                formData.append('mail_id', mailId);
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch('api.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    mailsData = mailsData.filter(m => m.id !== mailId);
                    renderMails();
                    updateMailBadge();
                    closeMailDetail();
                    closeModal();
                    showToast(t('toast.message_deleted'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            }
        });
    }

    // Load badge on startup
    loadUnreadCount();
