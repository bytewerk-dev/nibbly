<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>

    // ============================================================
    // COMMENTS AND GUESTBOOK MODERATION
    // ============================================================

    let commentsData = [];
    let commentsFilter = 'pending';

    async function loadComments() {
        if (!document.getElementById('commentsGroups')) return;
        try {
            const response = await fetch('api.php?action=load-comments');
            const result = await response.json();
            if (!result.success) { showToast(result.message, 'error'); return; }
            commentsData = result.data.comments || [];
            updateCommentsBadge(result.data.pending || 0);
            renderComments();
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    function setCommentsFilter(value) {
        commentsFilter = value;
        renderComments();
    }

    function updateCommentsBadge(count) {
        const badge = document.getElementById('commentsBadge');
        if (!badge) return;
        if (count > 0) { badge.textContent = count; badge.classList.remove('mail-badge--hidden'); }
        else badge.classList.add('mail-badge--hidden');
    }

    async function loadPendingCommentCount() {
        if (!document.getElementById('commentsBadge')) return;
        try {
            const response = await fetch('api.php?action=pending-comment-count');
            const result = await response.json();
            if (result.success) updateCommentsBadge(result.data.count || 0);
        } catch (error) { /* badge only */ }
    }

    function commentTargetUrl(comment) {
        if (comment.targetUrl) return '..' + comment.targetUrl;
        if (comment.targetType === 'news') {
            const post = (typeof newsData !== 'undefined' ? newsData : []).find(p => p.id === comment.targetId);
            return post && post.url ? '..' + post.url : '';
        }
        const key = String(comment.targetId || '');
        const path = key.replace(/^[a-z]{2}_/, '').replace(/__/g, '/');
        return '../' + (path === 'home' ? '' : path);
    }

    function commentTargetLabel(comment) {
        if (comment.targetTitle) return comment.targetTitle;
        if (comment.targetType === 'news') {
            const post = (typeof newsData !== 'undefined' ? newsData : []).find(p => p.id === comment.targetId);
            return post ? post.title : comment.targetId;
        }
        return String(comment.targetId || '').replace(/^[a-z]{2}_/, '').replace(/__/g, ' › ');
    }

    // Entries of one post or page stay together; the group with the newest entry comes first
    function groupCommentsByTarget(comments) {
        const groups = new Map();
        comments.forEach(comment => {
            const key = comment.target || (comment.targetType + ':' + comment.targetId);
            if (!groups.has(key)) groups.set(key, {key, comment, items: []});
            groups.get(key).items.push(comment);
        });
        return [...groups.values()].sort((a, b) => String(b.items[0].date || '').localeCompare(String(a.items[0].date || '')));
    }

    // One table per post or page: its title (linked) sits in the header bar in place of "Entry"
    function commentTable(comment, count) {
        const wrap = document.createElement('div');
        wrap.className = 'page-list-table-wrap nb-comment-table';
        const table = document.createElement('table');
        table.className = 'page-list-table';
        const thead = document.createElement('thead');
        const row = document.createElement('tr');
        const thTitle = document.createElement('th');
        thTitle.className = 'page-list-col-title nb-comment-table__head';
        const url = commentTargetUrl(comment);
        const title = document.createElement(url ? 'a' : 'span');
        title.className = 'nb-comment-table__title';
        title.textContent = commentTargetLabel(comment) || t('comments.target_missing');
        if (url) { title.href = url; title.target = '_blank'; title.rel = 'noopener'; }
        thTitle.appendChild(title);
        if (count) {
            const total = document.createElement('span');
            total.className = 'nb-comment-table__count';
            total.textContent = t('comments.group_count', {count});
            thTitle.appendChild(total);
        }
        const thStatus = document.createElement('th');
        thStatus.textContent = t('comments.col_status');
        const thDate = document.createElement('th');
        thDate.className = 'page-list-col-date';
        thDate.textContent = t('comments.col_date');
        row.append(thTitle, thStatus, thDate);
        thead.appendChild(row);
        const tbody = document.createElement('tbody');
        table.append(thead, tbody);
        wrap.appendChild(table);
        return {wrap, tbody};
    }

    function renderComments() {
        const container = document.getElementById('commentsGroups');
        if (!container) return;
        container.innerHTML = '';
        const visible = commentsData.filter(c => commentsFilter === '' || (c.status || 'pending') === commentsFilter);
        const pageSize = getDashboardPageSize();
        if (!visible.length) {
            const empty = commentTable({targetTitle: t('comments.title'), targetUrl: ''}, 0);
            nbRenderEmptyRow(empty.tbody, 3, t('comments.none'));
            container.appendChild(empty.wrap);
            renderAdminListFooter('commentsListFooter', 'comments', 0, pageSize, renderComments, 'commentsListFooterTop');
            return;
        }
        const groups = groupCommentsByTarget(visible);
        const grouped = groups.flatMap(group => group.items);
        const paged = pageSlice(grouped, 'comments', pageSize);
        renderAdminListFooter('commentsListFooter', 'comments', grouped.length, pageSize, renderComments, 'commentsListFooterTop');
        let current = null;
        paged.items.forEach(comment => {
            const key = comment.target || (comment.targetType + ':' + comment.targetId);
            if (!current || current.key !== key) {
                const group = groups.find(g => g.key === key);
                const table = commentTable(comment, group ? group.items.length : 0);
                container.appendChild(table.wrap);
                current = {key, tbody: table.tbody};
            }
            current.tbody.appendChild(commentRow(comment));
        });
        // The "show more" link only where the text really is cut off (needs the rows in the DOM)
        container.querySelectorAll('.nb-comment-admin__text').forEach(text => {
            if (text.scrollHeight > text.clientHeight + 1) text.parentElement.classList.add('nb-comment-admin--clamped');
        });
    }

    function commentRow(comment) {
            const tr = document.createElement('tr');
            tr.className = 'page-list-row' + ((comment.status || 'pending') === 'pending' ? ' mail-row-unread' : '');

            const tdEntry = document.createElement('td');
            tdEntry.className = 'page-list-cell-title nb-comment-admin';
            const head = document.createElement('div');
            head.className = 'nb-comment-admin__head';
            const author = document.createElement('strong');
            author.textContent = comment.author || t('comments.anonymous');
            head.appendChild(author);
            if (comment.email) {
                const mail = document.createElement('span');
                mail.className = 'nb-comment-admin__email';
                mail.textContent = comment.email;
                head.appendChild(mail);
            }
            if (comment.parent) {
                const reply = document.createElement('span');
                reply.className = 'nb-comment-admin__reply';
                reply.textContent = t('comments.is_reply');
                head.appendChild(reply);
            }
            tdEntry.appendChild(head);
            const text = document.createElement('div');
            text.className = 'nb-comment-admin__text';
            text.innerHTML = comment.content || '';
            tdEntry.appendChild(text);
            const more = document.createElement('a');
            more.href = '#';
            more.className = 'nb-comment-admin__more';
            more.textContent = t('comments.show_more');
            more.onclick = (e) => {
                e.preventDefault();
                const open = tdEntry.classList.toggle('nb-comment-admin--open');
                more.textContent = open ? t('comments.show_less') : t('comments.show_more');
            };
            tdEntry.appendChild(more);
            if (comment.spamReason) {
                const why = document.createElement('div');
                why.className = 'nb-comment-admin__reason';
                why.textContent = t('comments.spam_reason') + ': ' + comment.spamReason;
                tdEntry.appendChild(why);
            }
            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';
            const addAction = (label, iconName, handler, danger) => {
                if (actions.childElementCount) {
                    const sep = document.createElement('span'); sep.className = 'page-list-row-action-sep'; sep.textContent = '|'; actions.appendChild(sep);
                }
                const link = document.createElement('a');
                link.href = '#';
                link.className = 'page-list-row-action' + (danger ? ' page-list-row-action--danger' : '');
                link.innerHTML = (iconName ? icon(iconName, 12, '2') + ' ' : '') + label;
                link.onclick = (e) => { e.preventDefault(); handler(); };
                actions.appendChild(link);
            };
            if (comment.status !== 'approved') addAction(t('comments.approve'), 'check', () => setCommentStatus(comment, 'approved'));
            if (comment.status === 'approved') addAction(t('comments.unapprove'), 'eye', () => setCommentStatus(comment, 'pending'));
            if (comment.status !== 'spam') addAction(t('comments.mark_spam'), 'alert', () => setCommentStatus(comment, 'spam'));
            addAction(t('btn.delete'), 'trash', () => deleteComment(comment), true);
            const url = commentTargetUrl(comment);
            if (url) {
                const sep = document.createElement('span'); sep.className = 'page-list-row-action-sep'; sep.textContent = '|'; actions.appendChild(sep);
                const view = document.createElement('a');
                view.href = url + '#comment-' + encodeURIComponent(comment.id);
                view.target = '_blank';
                view.rel = 'noopener';
                view.className = 'page-list-row-action';
                view.innerHTML = icon('eye', 12, '2') + ' ' + t('pages.view');
                actions.appendChild(view);
            }
            tdEntry.appendChild(actions);

            const tdStatus = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'status-badge status-badge--' + (comment.status || 'pending');
            badge.textContent = t('comments.status_' + (comment.status || 'pending'));
            tdStatus.appendChild(badge);

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            const date = new Date(comment.date);
            tdDate.textContent = isNaN(date) ? (comment.date || '') : nbFormatDate(date, 'date') + ' ' + nbFormatDate(date, 'time');

            tr.append(tdEntry, tdStatus, tdDate);
            return tr;
    }


    async function setCommentStatus(comment, status) {
        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('target_type', comment.targetType);
        formData.append('target_id', comment.targetId);
        formData.append('comment_id', comment.id);
        formData.append('status', status);
        try {
            const response = await fetch('api.php?action=set-comment-status', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) { showToast(result.message, 'error'); return; }
            comment.status = status;
            updateCommentsBadge(result.data.pending || 0);
            renderComments();
            showToast(t('comments.saved'));
        } catch (error) {
            showToast(t('toast.error_saving', {message: error.message}), 'error');
        }
    }

    function deleteComment(comment) {
        showModal(t('comments.delete_title'), t('comments.confirm_delete'), () => { closeModal(); deleteCommentNow(comment); });
    }

    async function deleteCommentNow(comment) {
        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('target_type', comment.targetType);
        formData.append('target_id', comment.targetId);
        formData.append('comment_id', comment.id);
        try {
            const response = await fetch('api.php?action=delete-comment', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) { showToast(result.message, 'error'); return; }
            commentsData = commentsData.filter(c => c !== comment);
            updateCommentsBadge(result.data.pending || 0);
            renderComments();
            showToast(t('comments.deleted'));
        } catch (error) {
            showToast(t('toast.error_saving', {message: error.message}), 'error');
        }
    }

    document.addEventListener('DOMContentLoaded', loadPendingCommentCount);
