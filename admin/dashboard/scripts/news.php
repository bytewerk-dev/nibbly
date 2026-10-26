<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // NEWS / BLOG MANAGEMENT
    // ============================================================

    let newsLoaded = false;
    let newsData = [];
    let editingPostId = null;
    let newsStatusFilter = 'all';
    // View language of the list: its posts are the rows, the other languages are columns (as on pages)
    let newsViewLang = '<?php echo SITE_LANG_DEFAULT; ?>';
    let newsTrashCount = 0;

    async function loadNews() {
        try {
            // The trash only contributes its count to the filter links
            const [response, trash] = await Promise.all([
                fetch('api.php?action=load-news'),
                fetch('api.php?action=list-news-trash&_=' + Date.now()).then(r => r.json()).catch(() => null)
            ]);
            const result = await response.json();
            if (trash?.success) newsTrashCount = (trash.data || []).length;

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
        const otherLangs = Object.keys(SITE_LANGUAGES).filter(lang => lang !== newsViewLang);
        const colCount = 2 + otherLangs.length;
        document.querySelector('#newsListTable thead tr').innerHTML = `<th class="page-list-col-title">${escapeHtml(t('pages.col_title'))}</th><th class="page-list-col-date">${escapeHtml(t('news.post_date'))}</th>`
            + otherLangs.map(lang => `<th class="page-list-col-lang">${escapeHtml(SITE_LANGUAGES[lang])}</th>`).join('');

        renderNewsFilterLinks(document.getElementById('newsFilterLinks'), false);
        if (newsData.length === 0) {
            tbody.innerHTML = '';
            nbRenderEmptyRow(tbody, colCount, t('news.no_posts'), { label: t('news.new_post'), onClick: addNewPost });
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
        const primaryPost = slug => slugGroups[slug][newsViewLang];
        const sortedSlugs = Object.keys(slugGroups).filter(slug => primaryPost(slug) && (newsStatusFilter === 'all' || !!primaryPost(slug).hidden === (newsStatusFilter === 'draft'))).sort((a, b) => {
            const aPost = primaryPost(a);
            const bPost = primaryPost(b);
            return (bPost.date || '').localeCompare(aPost.date || '');
        });

        tbody.innerHTML = '';
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(sortedSlugs, 'news', pageSize);
        renderAdminListFooter('newsListFooter', 'news', sortedSlugs.length, pageSize, renderNewsList, 'newsListFooterTop');

        paged.items.forEach(slug => {
            const group = slugGroups[slug];
            const post = group[newsViewLang];
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
            deleteLink.innerHTML = icon('trash', 12, '2') + ' ' + t('pages.trash');
            deleteLink.onclick = (e) => { e.preventDefault(); trashPost(post.id); };
            actions.appendChild(deleteLink);

            tdTitle.appendChild(actions);
            tr.appendChild(tdTitle);

            // Date cell
            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            if (post.date) {
                tdDate.textContent = nbFormatDate(post.date, 'date');
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
                        chooseVersionCreation(SITE_LANGUAGES[lang], withAi => createNewsTranslation(post, lang, createLink, withAi));
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
        document.getElementById('newsTrashView').style.display = 'none';
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

        // Hide list, show editor
        document.getElementById('newsListContainer').style.display = 'none';
        document.getElementById('newsEditorContainer').style.display = 'block';

        // Update title
        const editorTitle = document.getElementById('newsEditorTitle');
        if (editorTitle) editorTitle.textContent = isNew ? t('news.new_post') : (post.title || t('news.edit'));

        // Writing view like pages (scripts/page-writing.php): title, content and excerpt in the
        // main column; save controls and post settings in the sidebar
        const editorRoot = document.getElementById('newsEditorContainer');
        editorRoot.classList.add('nb-writing-view', 'nb-news-writing');
        const imageSrc = post.image ? (post.image.startsWith('/') ? '..' + escapeHtml(post.image) : escapeHtml(post.image)) : '';
        const container = document.getElementById('newsEditorForm');
        // Bring the header actions back before the form is rebuilt (they live in its sidebar)
        const headerActions = editorRoot.querySelector('.editor-header-right');
        if (headerActions) editorRoot.querySelector('.editor-header').append(headerActions);
        container.innerHTML = `
            <div class="nb-writing-layout">
                <div class="nb-news-main">
                    <input type="hidden" id="newsLang" value="${escapeHtml(post.lang || DEFAULT_LANG)}">
                    <div class="nb-page-title-field">
                        <label for="newsTitle" class="ce-field-label">${t('news.post_title')}</label>
                        <input type="text" id="newsTitle" class="editor-input" value="${escapeHtml(post.title)}" placeholder="${t('news.post_title')}" autocomplete="off">
                    </div>
                    <div class="section-item nb-news-content">
                        <div class="section-fields">
                            <div class="html-editor" id="newsContentField">
                                <label for="newsContentHtml">${t('news.post_content')}</label>
                                <textarea id="newsContentHtml" class="editor-textarea section-field" data-key="content" rows="16"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="section-item nb-news-excerpt">
                        <div class="section-fields">
                            <label for="newsExcerpt">${t('news.post_excerpt')}</label>
                            <textarea id="newsExcerpt" class="editor-textarea" rows="3">${escapeHtml(post.excerpt)}</textarea>
                        </div>
                    </div>
                </div>
                <aside class="nb-page-sidebar" aria-label="${escapeHtml(t('news.writing_actions'))}">
                    <section class="nb-page-save">
                        <h3>${escapeHtml(t('news.writing_save'))}</h3>
                        <div class="nb-page-save-controls"></div>
                        <label class="editor-checkbox-label nb-news-draft">
                            <input type="checkbox" id="newsHidden" ${post.hidden ? 'checked' : ''}>
                            ${t('news.draft')}
                        </label>
                    </section>
                    <section class="nb-page-panel" aria-labelledby="newsSettingsTitle">
                        <h3 id="newsSettingsTitle">${escapeHtml(t('news.writing_settings'))}</h3>
                        <div class="editor-form-row">
                            <label for="newsSlug">${t('news.post_slug')}</label>
                            <input type="text" id="newsSlug" class="editor-input" value="${escapeHtml(post.slug)}" placeholder="my-post-slug" autocomplete="off">
                        </div>
                        <div class="editor-form-row">
                            <label for="newsDate">${t('news.post_date')}</label>
                            <input type="date" id="newsDate" class="editor-input" value="${escapeHtml(post.date)}">
                        </div>
                        <div class="editor-form-row">
                            <label for="newsAuthor">${t('news.post_author')}</label>
                            <input type="text" id="newsAuthor" class="editor-input" value="${escapeHtml(post.author)}">
                        </div>
                    </section>
                    <section class="nb-page-panel" aria-labelledby="newsImageTitle">
                        <h3 id="newsImageTitle">${t('news.post_image')}</h3>
                        <div class="ce-image-preview" id="newsImagePreview">
                            <img src="${imageSrc}" alt="" style="${post.image ? '' : 'display:none;'}">
                        </div>
                        <div class="ce-image-input-row">
                            <input type="text" id="newsImage" class="editor-input" value="${escapeHtml(post.image)}" placeholder="/assets/images/cover.jpg" aria-labelledby="newsImageTitle">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="browseNewsImage()">${t('btn.browse')}</button>
                        </div>
                        ${AI_FEATURES_ENABLED ? `<p class="ai-field-hint"${dashboardAiImageUsable ? '' : ' hidden'}>${t('ai.image_field_hint')} <button type="button" class="btn btn-secondary btn-sm" onclick="openAiImageGenerator(getNewsImagePrompt(), '16:9')">${t('ai.open_image_generator')}</button></p>` : ''}
                    </section>
                </aside>
            </div>
        `;

        // Save, view and trash move from the header into the sidebar, as on pages
        if (headerActions) container.querySelector('.nb-page-save-controls').append(headerActions);

        // Content: the page editor's Visual / HTML editor on the stored HTML
        document.getElementById('newsContentHtml').value = post.content || '';
        window.NbPageEditor.richEditor(document.getElementById('newsContentField'), 'news', { markDirty() {}, pushUndoSnapshot() {} });

        mountNewsLanguageSwitch(post);

        // Update view + trash buttons (after form is built, so newsSlug/newsLang exist)
        updateNewsEditorButtons();
        newsSavedState = JSON.stringify(collectNewsPost());

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

    function updateNewsEditorButtons() {
        const viewBtn = document.getElementById('newsViewBtn');
        const trashBtn = document.getElementById('newsTrashBtn');
        if (editingPostId) {
            const defLang = '<?php echo SITE_LANG_DEFAULT; ?>';
            const lang = document.getElementById('newsLang')?.value || defLang;
            const slug = document.getElementById('newsSlug')?.value || editingPostId;
            const prefix = (lang === defLang) ? '../' : '../' + lang + '/';
            if (viewBtn) {
                viewBtn.href = prefix + 'news/' + slug;
                viewBtn.style.display = '';
                viewBtn.onclick = e => viewWithUnsavedCheck(e, viewBtn, newsHasUnsavedChanges, savePost);
            }
            if (trashBtn) trashBtn.style.display = '';
        } else {
            if (viewBtn) viewBtn.style.display = 'none';
            if (trashBtn) trashBtn.style.display = 'none';
        }
    }

    // Posts move to the trash without a question, like pages and events; the trash restores them
    async function trashPost(postId) {
        try {
            const formData = new FormData();
            formData.append('action', 'trash-news');
            formData.append('post_id', postId);
            formData.append('csrf_token', CSRF_TOKEN);
            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) {
                showToast(result.message, 'error');
                return false;
            }
            newsData = newsData.filter(p => p.id !== postId);
            renderNewsList();
            showToast(t('toast.news_trashed'), 'success');
            return true;
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
            return false;
        }
    }

    async function trashCurrentPost() {
        if (!editingPostId) return;
        if (await trashPost(editingPostId)) {
            editingPostId = null;
            showNewsList();
            loadNews();
        }
    }

    function getNewsContent() {
        // The rich editor keeps the textarea in sync in both modes (it renames its id)
        return document.querySelector('#newsContentField textarea.section-field')?.value || '';
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

    // The post as it stands in the editor; also compared with newsSavedState to spot unsaved changes
    function collectNewsPost() {
        return {
            id: editingPostId || '',
            lang: document.getElementById('newsLang').value,
            title: document.getElementById('newsTitle').value.trim(),
            slug: document.getElementById('newsSlug').value.trim(),
            date: document.getElementById('newsDate').value,
            author: document.getElementById('newsAuthor').value.trim(),
            excerpt: document.getElementById('newsExcerpt').value.trim(),
            image: document.getElementById('newsImage').value.trim(),
            content: getNewsContent(),
            hidden: document.getElementById('newsHidden').checked
        };
    }

    let newsSavedState = '';
    function newsHasUnsavedChanges() {
        return !!document.getElementById('newsTitle') && JSON.stringify(collectNewsPost()) !== newsSavedState;
    }

    async function savePost() {
        const post = collectNewsPost();
        if (!post.title || !post.date) {
            showToast(t('toast.news_date_required'), 'error');
            return false;
        }

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
                newsSavedState = JSON.stringify(collectNewsPost());
                loadNews();
                return true;
            }
            showToast(result.message, 'error');
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
        return false;
    }

    // Status filter links (all / published / draft / trash); a post counts by its primary-language version
    function renderNewsFilterLinks(target, inTrash) {
        const defaultLang = '<?php echo SITE_LANG_DEFAULT; ?>';
        const langOf = post => post.lang || defaultLang;
        const posts = newsData.filter(post => langOf(post) === newsViewLang);
        const status = (value, label, count) => ({ value, label, count, active: !inTrash && newsStatusFilter === value, onSelect() {
            newsStatusFilter = value;
            if (inTrash) closeNewsTrash(); else renderNewsList();
        } });
        renderFilterLinks(target, [{ items: [
            status('all', t('filter.all'), posts.length),
            status('published', t('filter.published'), posts.filter(p => !p.hidden).length),
            status('draft', t('filter.draft'), posts.filter(p => p.hidden).length),
            { value: 'trash', label: t('pages.trash'), count: newsTrashCount, active: inTrash, onSelect: showNewsTrash }
        ] }, !inTrash && Object.keys(SITE_LANGUAGES).length > 1 && { label: t('filter.language'), icon: 'language', items: Object.keys(SITE_LANGUAGES).map(lang => ({
            value: lang, label: SITE_LANGUAGES[lang], count: newsData.filter(post => langOf(post) === lang).length,
            active: lang === newsViewLang, onSelect() { newsViewLang = lang; dashboardListPages.news = 1; renderNewsList(); }
        })) }]);
    }

    // ===== NEWS TRASH =====

    function showNewsTrash() {
        document.getElementById('newsListContainer').style.display = 'none';
        document.getElementById('newsEditorContainer').style.display = 'none';
        document.getElementById('newsTrashView').style.display = '';
        loadNewsTrash();
    }

    function closeNewsTrash() {
        showNewsList();
        loadNews();
    }

    async function newsTrashRequest(action, filename) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', CSRF_TOKEN);
        if (filename) fd.append('filename', filename);
        try {
            const res = await (await fetch('api.php', { method: 'POST', body: fd })).json();
            if (!res.success) showToast(res.message || 'Error', 'error');
            return res.success;
        } catch (err) {
            showToast(err.message, 'error');
            return false;
        }
    }

    async function loadNewsTrash() {
        try {
            const result = await (await fetch('api.php?action=list-news-trash&_=' + Date.now())).json();
            if (result.success) renderNewsTrash(result.data);
        } catch (error) {
            console.error('Error loading news trash:', error);
        }
    }

    function renderNewsTrash(items) {
        newsTrashCount = (items || []).length;
        renderNewsFilterLinks(document.getElementById('newsTrashFilterLinks'), true);
        const tbody = document.getElementById('newsTrashBody');
        const empty = !items || items.length === 0;
        document.getElementById('newsTrashTable').style.display = empty ? 'none' : '';
        document.getElementById('newsTrashEmptyMsg').style.display = empty ? 'block' : 'none';
        document.getElementById('emptyNewsTrashBtn').style.display = empty ? 'none' : '';
        tbody.innerHTML = '';
        (items || []).forEach(item => {
            const title = item.title || t('news.untitled');
            const tr = document.createElement('tr');
            tr.innerHTML = `<td class="page-list-cell-title"></td>
                <td class="page-list-cell-date">${item.date ? escapeHtml(nbFormatDate(item.date, 'date')) : '—'}</td>
                <td class="page-list-cell-date">${escapeHtml(nbFormatDate(new Date(item.deletedAt), 'datetime'))}</td>
                <td class="page-list-cell-actions"></td>`;
            tr.querySelector('.page-list-cell-title').textContent = title;

            const restoreBtn = document.createElement('button');
            restoreBtn.className = 'btn btn-primary btn-sm';
            restoreBtn.textContent = t('btn.restore');
            restoreBtn.onclick = async () => {
                restoreBtn.disabled = true;
                if (await newsTrashRequest('restore-news', item.filename)) showToast(t('toast.news_restored'), 'success');
                loadNewsTrash();
            };

            const delBtn = document.createElement('button');
            delBtn.className = 'btn btn-danger btn-sm';
            delBtn.textContent = t('btn.delete');
            delBtn.onclick = () => showModal(t('modal.delete_permanently'), t('news.confirm_delete_permanent', {title}), async () => {
                closeModal();
                if (await newsTrashRequest('delete-news-permanent', item.filename)) showToast(t('toast.news_deleted'), 'success');
                loadNewsTrash();
            });

            tr.querySelector('.page-list-cell-actions').append(restoreBtn, delBtn);
            tbody.appendChild(tr);
        });
    }

    function emptyNewsTrash() {
        showModal(t('modal.empty_trash'), t('modal.empty_trash_confirm'), async () => {
            closeModal();
            if (await newsTrashRequest('empty-news-trash')) showToast(t('toast.trash_emptied'), 'success');
            loadNewsTrash();
        });
    }

    // Language choice top right in the header. Each language is its own post (same slug): the choice opens
    // that version or creates it; a new post just takes the chosen language.
    function mountNewsLanguageSwitch(post) {
        const root = document.getElementById('newsEditorContainer');
        const languages = Object.keys(SITE_LANGUAGES);
        if (languages.length < 2) return placeLanguageSwitch(root, null);
        const postLang = post.lang || DEFAULT_LANG;
        const slug = post.slug || post.id;
        const versions = {};
        if (editingPostId) newsData.forEach(p => { if ((p.slug || p.id) === slug) versions[p.lang || DEFAULT_LANG] = p; });
        const source = versions[DEFAULT_LANG];
        placeLanguageSwitch(root, renderLanguageSwitch({
            name: 'news',
            current: postLang,
            languages: languages.map(code => ({ code, label: SITE_LANGUAGES[code], exists: !editingPostId || !!versions[code] })),
            onSelect(code, exists) {
                if (!editingPostId) { document.getElementById('newsLang').value = code; return true; }
                continueWithUnsaved(newsHasUnsavedChanges, savePost, () => {
                    if (exists) editPost(versions[code].id);
                    else chooseVersionCreation(SITE_LANGUAGES[code], withAi => createNewsTranslation(newsData.find(p => p.id === editingPostId), code, null, withAi));
                });
                return false;
            },
            // In another language's version: take the primary language's texts, translated (not saved yet)
            onTranslate: editingPostId && source && postLang !== DEFAULT_LANG ? button => translateNewsFrom(source, button) : null,
            translateLabel: t('editor.translate_from', { lang: SITE_LANGUAGES[DEFAULT_LANG] })
        }));
    }

    async function translateNewsFrom(source, button) {
        const target = document.getElementById('newsLang').value;
        const fields = translatableStrings({ title: source.title || '', excerpt: source.excerpt || '', content: source.content || '' });
        const translations = await aiTranslateFields(source.lang || DEFAULT_LANG, [target], fields, button, 8000);
        if (!translations?.[target]) return;
        const before = collectNewsPost();
        const translated = Object.assign({}, newsData.find(p => p.id === editingPostId), before, translations[target]);
        showPostEditor(translated);
        newsSavedState = JSON.stringify(before); // the translation is a change still to be saved
        showToast(t('editor.translate_review'), 'success');
    }

    // A new language version of a post: a copy as draft (so untranslated text never goes live), opened at once
    async function createNewsTranslation(sourcePost, targetLang, linkEl, withAi = false) {
        if (linkEl) { linkEl.classList.add('disabled'); linkEl.textContent = '...'; }
        try {
            const newPost = Object.assign({}, sourcePost, {
                id: '',
                lang: targetLang,
                hidden: true,
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
                if (withAi) await translateNewsFrom(sourcePost, document.querySelector('#newsEditorForm .nb-ai-translate'));
                return;
            }
            showToast(result.message, 'error');
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
        if (linkEl) { linkEl.classList.remove('disabled'); linkEl.textContent = t('pages.create'); }
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
