<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // NEWS / BLOG MANAGEMENT
    // ============================================================

    let newsLoaded = false;
    let newsData = [];
    let editingPostId = null;
    let newsHtmlMode = false;

    async function loadNews() {
        try {
            const response = await fetch('api.php?action=load-news');
            const result = await response.json();

            if (result.success) {
                newsData = result.data;
                renderNewsList();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading_news', {message: error.message}), 'error');
        }
    }

    function renderNewsList() {
        const tbody = document.getElementById('newsListBody');
        const defaultLang = '<?php echo SITE_LANG_DEFAULT; ?>';
        const otherLangs = <?php $defLang = SITE_LANG_DEFAULT; echo json_encode(array_values(array_filter(array_keys($siteLanguages), function($c) use ($defLang) { return $c !== $defLang; }))); ?>;
        const colCount = 2 + otherLangs.length;

        if (newsData.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center;padding:2rem;">${t('news.no_posts')}</td></tr>`;
            renderAdminListFooter('newsListFooter', 'news', 0, getDashboardPageSize(), renderNewsList, 'newsListFooterTop');
            return;
        }

        // Group posts by slug — primary language row is the "main" entry
        const slugGroups = {};
        newsData.forEach(post => {
            const slug = post.slug || post.id;
            if (!slugGroups[slug]) slugGroups[slug] = {};
            const lang = post.lang || defaultLang;
            slugGroups[slug][lang] = post;
        });

        // Sort groups by date descending (use primary lang post date, or any available)
        const sortedSlugs = Object.keys(slugGroups).sort((a, b) => {
            const aPost = slugGroups[a][defaultLang] || Object.values(slugGroups[a])[0];
            const bPost = slugGroups[b][defaultLang] || Object.values(slugGroups[b])[0];
            return (bPost.date || '').localeCompare(aPost.date || '');
        });

        tbody.innerHTML = '';
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(sortedSlugs, 'news', pageSize);
        renderAdminListFooter('newsListFooter', 'news', sortedSlugs.length, pageSize, renderNewsList, 'newsListFooterTop');

        paged.items.forEach(slug => {
            const group = slugGroups[slug];
            // Primary post = default lang or first available
            const post = group[defaultLang] || Object.values(group)[0];
            const postLang = post.lang || defaultLang;

            const tr = document.createElement('tr');
            tr.className = 'page-list-row';

            // Title cell
            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';

            const slugSpan = document.createElement('span');
            slugSpan.className = 'page-list-slug';
            slugSpan.textContent = '/news/' + slug;
            tdTitle.appendChild(slugSpan);

            const titleLink = document.createElement('a');
            titleLink.href = '#';
            titleLink.className = 'page-list-title-link';
            titleLink.textContent = post.title || t('news.untitled');
            titleLink.onclick = (e) => { e.preventDefault(); editPost(post.id); };
            tdTitle.appendChild(titleLink);

            // Status badge
            if (post.hidden) {
                const badge = document.createElement('span');
                badge.className = 'news-status news-draft';
                badge.textContent = t('news.draft');
                tdTitle.appendChild(badge);
            }

            // Hover actions
            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';

            const editLink = document.createElement('a');
            editLink.href = '#';
            editLink.className = 'page-list-row-action';
            editLink.innerHTML = icon('edit', 12, '2') + ' ' + t('news.edit');
            editLink.onclick = (e) => { e.preventDefault(); editPost(post.id); };
            actions.appendChild(editLink);

            const sep1 = document.createElement('span');
            sep1.className = 'page-list-row-action-sep';
            sep1.textContent = '|';
            actions.appendChild(sep1);

            const langPrefix = postLang === defaultLang ? '' : postLang + '/';
            const viewLink = document.createElement('a');
            viewLink.href = '../' + langPrefix + 'news/' + slug;
            viewLink.target = '_blank';
            viewLink.className = 'page-list-row-action';
            viewLink.innerHTML = icon('eye', 12, '2') + ' ' + t('news.view');
            actions.appendChild(viewLink);

            const sep2 = document.createElement('span');
            sep2.className = 'page-list-row-action-sep';
            sep2.textContent = '|';
            actions.appendChild(sep2);

            const toggleLink = document.createElement('a');
            toggleLink.href = '#';
            toggleLink.className = 'page-list-row-action';
            toggleLink.innerHTML = post.hidden
                ? icon('eye', 12, '2') + ' ' + t('news.publish')
                : icon('eye-off', 12, '2') + ' ' + t('news.unpublish');
            toggleLink.onclick = (e) => { e.preventDefault(); toggleNewsStatus(post.id); };
            actions.appendChild(toggleLink);

            const sep3 = document.createElement('span');
            sep3.className = 'page-list-row-action-sep';
            sep3.textContent = '|';
            actions.appendChild(sep3);

            const deleteLink = document.createElement('a');
            deleteLink.href = '#';
            deleteLink.className = 'page-list-row-action page-list-row-action--danger';
            deleteLink.innerHTML = icon('trash', 12, '2') + ' ' + t('btn.delete');
            deleteLink.onclick = (e) => { e.preventDefault(); deletePost(post.id); };
            actions.appendChild(deleteLink);

            tdTitle.appendChild(actions);
            tr.appendChild(tdTitle);

            // Date cell
            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            if (post.date) {
                const d = new Date(post.date + 'T00:00:00');
                tdDate.textContent = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            } else {
                tdDate.textContent = '—';
            }
            tr.appendChild(tdDate);

            // Other language columns
            otherLangs.forEach(lang => {
                const td = document.createElement('td');
                td.className = 'page-list-cell-lang';
                const langPost = group[lang];
                if (langPost) {
                    const editBtn = document.createElement('a');
                    editBtn.href = '#';
                    editBtn.className = 'btn btn-secondary btn-sm page-list-lang-btn';
                    editBtn.innerHTML = icon('edit', 12) + ' ' + t('news.edit');
                    editBtn.onclick = (e) => { e.preventDefault(); editPost(langPost.id); };
                    td.appendChild(editBtn);
                } else {
                    const createLink = document.createElement('a');
                    createLink.href = '#';
                    createLink.className = 'page-list-create-link';
                    createLink.textContent = t('pages.create');
                    createLink.onclick = (e) => {
                        e.preventDefault();
                        createNewsTranslation(post, lang, createLink);
                    };
                    td.appendChild(createLink);
                }
                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });
    }

    function showNewsList() {
        document.getElementById('newsListContainer').style.display = 'block';
        document.getElementById('newsEditorContainer').style.display = 'none';
    }

    function addNewPost() {
        const lang = '<?php echo SITE_LANG_DEFAULT; ?>';
        editingPostId = null;
        showPostEditor({
            id: '',
            lang: lang,
            title: '',
            slug: '',
            date: new Date().toISOString().split('T')[0],
            author: '',
            excerpt: '',
            image: '',
            content: '',
            hidden: false
        });
    }

    function editPost(postId) {
        const post = newsData.find(p => p.id === postId);
        if (!post) return;
        editingPostId = postId;
        showPostEditor(post);
    }

    function showPostEditor(post) {
        const isNew = !editingPostId;
        newsHtmlMode = false;

        // Hide list, show editor
        document.getElementById('newsListContainer').style.display = 'none';
        document.getElementById('newsEditorContainer').style.display = 'block';

        // Update title
        const editorTitle = document.getElementById('newsEditorTitle');
        if (editorTitle) editorTitle.textContent = isNew ? t('news.new_post') : (post.title || t('news.edit'));

        // Build language options
        const languages = <?php echo json_encode($siteLanguages); ?>;
        const langOpts = Object.entries(languages).map(([code, name]) =>
            `<option value="${code}"${code === (post.lang || '<?php echo SITE_LANG_DEFAULT; ?>') ? ' selected' : ''}>${escapeHtml(name)}</option>`
        ).join('');

        const container = document.getElementById('newsEditorForm');
        container.innerHTML = `
            <div class="news-editor">
                <div class="editor-form-grid editor-form-grid--news">
                    <div class="editor-form-row editor-form-row--span-2">
                        <label for="newsTitle">${t('news.post_title')}</label>
                        <input type="text" id="newsTitle" class="editor-input" value="${escapeHtml(post.title)}" placeholder="${t('news.post_title')}">
                    </div>
                    <div class="editor-form-row-half editor-form-row--span-2">
                        <div class="editor-form-row">
                            <label for="newsSlug">${t('news.post_slug')}</label>
                            <input type="text" id="newsSlug" class="editor-input" value="${escapeHtml(post.slug)}" placeholder="my-post-slug">
                        </div>
                        <div class="editor-form-row">
                            <label for="newsDate">${t('news.post_date')}</label>
                            <input type="date" id="newsDate" class="editor-input" value="${escapeHtml(post.date)}">
                        </div>
                    </div>
                    <div class="editor-form-row-half editor-form-row--span-2">
                        <div class="editor-form-row">
                            <label for="newsAuthor">${t('news.post_author')}</label>
                            <input type="text" id="newsAuthor" class="editor-input" value="${escapeHtml(post.author)}">
                        </div>
                        <div class="editor-form-row">
                            <label for="newsLang">${t('news.post_language')}</label>
                            <select id="newsLang" class="editor-input">${langOpts}</select>
                        </div>
                    </div>
                    <div class="editor-form-row editor-form-row--cover">
                        <label for="newsImage">${t('news.post_image')}</label>
                        <div class="ce-image-input-row">
                            <input type="text" id="newsImage" class="editor-input" value="${escapeHtml(post.image)}" placeholder="/assets/images/cover.jpg">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="browseNewsImage()">${t('btn.browse')}</button>
                        </div>
                        ${AI_FEATURES_ENABLED ? `<p class="ai-field-hint">${t('ai.image_field_hint')} <button type="button" class="btn btn-secondary btn-sm" onclick="openAiImageGenerator(getNewsImagePrompt(), '16:9')">${t('ai.open_image_generator')}</button></p>` : ''}
                        <div class="ce-image-preview" id="newsImagePreview">
                            <img src="${post.image ? (post.image.startsWith('/') ? '..' + escapeHtml(post.image) : escapeHtml(post.image)) : ''}" alt="" style="${post.image ? '' : 'display:none;'}">
                        </div>
                    </div>
                    <div class="editor-form-row editor-form-row--full">
                        <label for="newsExcerpt">${t('news.post_excerpt')}</label>
                        <textarea id="newsExcerpt" class="editor-textarea" rows="3">${escapeHtml(post.excerpt)}</textarea>
                    </div>
                    <div class="editor-form-row editor-form-row--full">
                        <label>${t('news.post_content')}</label>
                        <div class="news-wysiwyg-toolbar">
                            <button type="button" onclick="newsExecCmd('bold')" title="Bold"><b>B</b></button>
                            <button type="button" onclick="newsExecCmd('italic')" title="Italic"><i>I</i></button>
                            <button type="button" onclick="newsInsertLink()" title="Link">🔗</button>
                            <button type="button" onclick="newsInsertHeading()" title="Heading">H3</button>
                            <button type="button" onclick="newsExecCmd('insertUnorderedList')" title="List">☰</button>
                            <button type="button" onclick="newsCleanHtml()" title="Clean formatting">✕</button>
                            <span class="news-toolbar-sep"></span>
                            <label class="news-html-toggle">
                                <input type="checkbox" id="newsHtmlToggle" onchange="newsToggleHtml()">
                                <span>HTML</span>
                            </label>
                        </div>
                        <div id="newsContentWysiwyg" class="news-wysiwyg-editor" contenteditable="true"></div>
                        <textarea id="newsContentHtml" class="editor-textarea editor-textarea-large" style="display:none;" rows="16"></textarea>
                    </div>
                    <div class="editor-form-row">
                        <label class="editor-checkbox-label">
                            <input type="checkbox" id="newsHidden" ${post.hidden ? 'checked' : ''}>
                            ${t('news.draft')}
                        </label>
                    </div>
                </div>
                <div class="editor-form-actions">
                    <button class="btn btn-secondary" onclick="cancelPostEditor()">${t('btn.cancel')}</button>
                    <button class="btn btn-primary" onclick="savePost()">${t('btn.save')}</button>
                </div>
            </div>
        `;

        // Set WYSIWYG content (not escaped — it's HTML)
        document.getElementById('newsContentWysiwyg').innerHTML = post.content || '';

        // Update view + trash buttons (after form is built, so newsSlug/newsLang exist)
        updateNewsEditorButtons();

        // Auto-generate slug from title for new posts
        document.getElementById('newsTitle').addEventListener('input', function() {
            if (!editingPostId) {
                const slug = this.value.toLowerCase().trim()
                    .replace(/[äöüß]/g, m => ({ä:'ae',ö:'oe',ü:'ue',ß:'ss'})[m])
                    .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
                document.getElementById('newsSlug').value = slug;
            }
        });
    }

    // WYSIWYG helpers for news editor
    function newsExecCmd(cmd) {
        document.execCommand(cmd, false, null);
        document.getElementById('newsContentWysiwyg').focus();
    }

    function newsInsertLink() {
        showModal(
            t('insert_link'),
            '<label class="modal-field-label" for="newsLinkUrlInput">URL</label>' +
            '<input class="form-control" id="newsLinkUrlInput" type="url" value="https://" placeholder="https://...">',
            function() {
                const input = document.getElementById('newsLinkUrlInput');
                const url = input ? input.value.trim() : '';
                if (!url) return;
                closeModal();
                document.getElementById('newsContentWysiwyg').focus();
                document.execCommand('createLink', false, url);
            },
            {
                html: true,
                confirmText: t('btn.insert'),
                confirmClass: 'btn btn-primary',
                focusSelector: '#newsLinkUrlInput'
            }
        );
        setTimeout(function() {
            const input = document.getElementById('newsLinkUrlInput');
            if (input) {
                input.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        document.getElementById('modalConfirm').click();
                    }
                });
                input.select();
            }
        }, 0);
    }

    function newsInsertHeading() {
        document.execCommand('formatBlock', false, 'h3');
        document.getElementById('newsContentWysiwyg').focus();
    }

    function newsCleanHtml() {
        document.execCommand('removeFormat', false, null);
        document.getElementById('newsContentWysiwyg').focus();
    }

    function newsToggleHtml() {
        const wysiwyg = document.getElementById('newsContentWysiwyg');
        const html = document.getElementById('newsContentHtml');
        newsHtmlMode = !newsHtmlMode;

        if (newsHtmlMode) {
            html.value = wysiwyg.innerHTML;
            wysiwyg.style.display = 'none';
            html.style.display = 'block';
            html.focus();
        } else {
            wysiwyg.innerHTML = html.value;
            html.style.display = 'none';
            wysiwyg.style.display = 'block';
            wysiwyg.focus();
        }
    }

    function updateNewsEditorButtons() {
        const viewBtn = document.getElementById('newsViewBtn');
        const trashBtn = document.getElementById('newsTrashBtn');
        if (editingPostId) {
            const defLang = '<?php echo SITE_LANG_DEFAULT; ?>';
            const lang = document.getElementById('newsLang')?.value || defLang;
            const slug = document.getElementById('newsSlug')?.value || editingPostId;
            const prefix = (lang === defLang) ? '../' : '../' + lang + '/';
            if (viewBtn) { viewBtn.href = prefix + 'news/' + slug; viewBtn.style.display = ''; }
            if (trashBtn) trashBtn.style.display = '';
        } else {
            if (viewBtn) viewBtn.style.display = 'none';
            if (trashBtn) trashBtn.style.display = 'none';
        }
    }

    function deleteCurrentPost() {
        if (!editingPostId) return;
        const title = document.getElementById('newsTitle')?.value || editingPostId;
        showModal(
            t('modal.delete_post'),
            t('modal.delete_post_confirm', { title }),
            async function() {
                closeModal();
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete-news');
                    formData.append('slug', editingPostId);
                    formData.append('csrf_token', CSRF_TOKEN);
                    const response = await fetch('api.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        showToast(t('toast.news_deleted'), 'success');
                        editingPostId = null;
                        showNewsList();
                        loadNews();
                    } else {
                        showToast(result.message, 'error');
                    }
                } catch (e) {
                    showToast(t('toast.error_generic', {message: e.message}), 'error');
                }
            }
        );
    }

    function getNewsContent() {
        if (newsHtmlMode) {
            return document.getElementById('newsContentHtml').value;
        }
        return document.getElementById('newsContentWysiwyg').innerHTML;
    }

    function browseNewsImage() {
        const inputEl = document.getElementById('newsImage');
        const previewEl = document.getElementById('newsImagePreview');
        browseImageForField(inputEl, previewEl);
    }

    function cancelPostEditor() {
        editingPostId = null;
        showNewsList();
        renderNewsList();
    }

    async function savePost() {
        const title = document.getElementById('newsTitle').value.trim();
        const date = document.getElementById('newsDate').value;

        if (!title || !date) {
            showToast(t('toast.news_date_required'), 'error');
            return;
        }

        const post = {
            id: editingPostId || '',
            lang: document.getElementById('newsLang').value,
            title: title,
            slug: document.getElementById('newsSlug').value.trim(),
            date: date,
            author: document.getElementById('newsAuthor').value.trim(),
            excerpt: document.getElementById('newsExcerpt').value.trim(),
            image: document.getElementById('newsImage').value.trim(),
            content: getNewsContent(),
            hidden: document.getElementById('newsHidden').checked
        };

        try {
            const formData = new FormData();
            formData.append('action', 'save-news');
            formData.append('post', JSON.stringify(post));
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                const wasNew = !editingPostId;
                showToast(wasNew ? t('toast.news_created') : t('toast.news_saved'), 'success');
                // Stay in editor — update slug reference for subsequent saves
                if (result.data?.slug) {
                    editingPostId = result.data.slug;
                    document.getElementById('newsSlug').value = result.data.slug;
                }
                // Update view + trash buttons
                updateNewsEditorButtons();
                loadNews();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }

    function deletePost(postId) {
        const post = newsData.find(p => p.id === postId);
        const title = post ? post.title : postId;

        showModal(t('modal.delete_post'), t('modal.delete_post_confirm', {title: title}), async () => {
            try {
                const formData = new FormData();
                formData.append('action', 'delete-news');
                formData.append('post_id', postId);
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch('api.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    newsData = newsData.filter(p => p.id !== postId);
                    renderNewsList();
                    closeModal();
                    showToast(t('toast.news_deleted'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            }
        });
    }

    async function createNewsTranslation(sourcePost, targetLang, linkEl) {
        linkEl.classList.add('disabled');
        linkEl.textContent = '...';
        try {
            // Clone the source post with the new language
            const newPost = Object.assign({}, sourcePost, {
                id: '',
                lang: targetLang,
                lastModified: new Date().toISOString()
            });

            const formData = new FormData();
            formData.append('action', 'save-news');
            formData.append('post', JSON.stringify(newPost));
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                showToast(t('toast.news_created'), 'success');
                await loadNews();
                editPost(result.data.id);
            } else {
                showToast(result.message, 'error');
                linkEl.classList.remove('disabled');
                linkEl.textContent = t('pages.create');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
            linkEl.classList.remove('disabled');
            linkEl.textContent = t('pages.create');
        }
    }

    async function toggleNewsStatus(postId) {
        try {
            const formData = new FormData();
            formData.append('action', 'toggle-news-status');
            formData.append('post_id', postId);
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                const post = newsData.find(p => p.id === postId);
                if (post) post.hidden = result.data.hidden;
                renderNewsList();
                showToast(result.data.hidden ? t('news.draft') : t('news.published'), 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }
