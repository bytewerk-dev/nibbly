<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>

    // ============================================================
    // COMMENTS AND GUESTBOOK MODERATION
    // ============================================================

    let commentsData = [];
    let commentsFilter = 'pending';

    async function loadComments() {
        const tbody = document.getElementById('commentsList');
        if (!tbody) return;
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
        if (comment.targetType === 'news') {
            const post = (typeof newsData !== 'undefined' ? newsData : []).find(p => p.id === comment.targetId);
            if (post && post.url) return '..' + post.url;
            return '';
        }
        const key = String(comment.targetId || '');
        const path = key.replace(/^[a-z]{2}_/, '').replace(/__/g, '/');
        return '../' + (path === 'home' ? '' : path);
    }

    function commentTargetLabel(comment) {
        if (comment.targetType === 'news') {
            const post = (typeof newsData !== 'undefined' ? newsData : []).find(p => p.id === comment.targetId);
            return post ? post.title : comment.targetId;
        }
        return String(comment.targetId || '').replace(/^[a-z]{2}_/, '').replace(/__/g, ' › ');
    }

    function renderComments() {
        const tbody = document.getElementById('commentsList');
        if (!tbody) return;
        tbody.innerHTML = '';
        const visible = commentsData.filter(c => commentsFilter === '' || (c.status || 'pending') === commentsFilter);
        if (!visible.length) {
            nbRenderEmptyRow(tbody, 4, t('comments.none'));
            renderAdminListFooter('commentsListFooter', 'comments', 0, getDashboardPageSize(), renderComments, 'commentsListFooterTop');
            return;
        }
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(visible, 'comments', pageSize);
        renderAdminListFooter('commentsListFooter', 'comments', visible.length, pageSize, renderComments, 'commentsListFooterTop');
        paged.items.forEach(comment => {
            const tr = document.createElement('tr');
            tr.className = 'page-list-row' + ((comment.status || 'pending') === 'pending' ? ' mail-row-unread' : '');

            const tdEntry = document.createElement('td');
            tdEntry.className = 'page-list-cell-title';
            const author = document.createElement('strong');
            author.textContent = comment.author || t('comments.anonymous');
            tdEntry.appendChild(author);
            if (comment.email) {
                const mail = document.createElement('span');
                mail.className = 'nb-comment-admin__email';
                mail.textContent = ' <' + comment.email + '>';
                tdEntry.appendChild(mail);
            }
            if (comment.parent) {
                const reply = document.createElement('span');
                reply.className = 'nb-comment-admin__reply';
                reply.textContent = ' · ' + t('comments.is_reply');
                tdEntry.appendChild(reply);
            }
            const text = document.createElement('div');
            text.className = 'nb-comment-admin__text';
            text.innerHTML = comment.content || '';
            tdEntry.appendChild(text);
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

            const tdTarget = document.createElement('td');
            tdTarget.textContent = commentTargetLabel(comment);

            const tdStatus = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'status-badge status-badge--' + (comment.status || 'pending');
            badge.textContent = t('comments.status_' + (comment.status || 'pending'));
            tdStatus.appendChild(badge);

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            const date = new Date(comment.date);
            tdDate.textContent = isNaN(date) ? (comment.date || '') : nbFormatDate(date, 'date') + ' ' + nbFormatDate(date, 'time');

            tr.append(tdEntry, tdTarget, tdStatus, tdDate);
            tbody.appendChild(tr);
        });
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
