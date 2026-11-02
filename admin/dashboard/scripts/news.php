<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // NEWS / BLOG MANAGEMENT
    // ============================================================

    let newsLoaded = false;
    let newsData = []; // list rows (metadata only, api load-news); the open post is loaded in full
    let editingPostId = null;
    let editingPost = null; // the complete record of the post in the editor (api load-news-post)
    let newsStatusFilter = 'all';
    let newsCategoryFilter = 'all'; // Rubrik-Slug oder 'all'
    // View language of the list: its posts are the rows, the other languages are columns (as on pages)
    let newsViewLang = '<?php echo SITE_LANG_DEFAULT; ?>';
    let newsTrashCount = 0;
    // URL pattern, archive paths, categories and tag names (api load-news-config); shared with the settings panel
    let newsConfig = { settings: { urlPattern: '/news/{slug}', perPage: 12, pageParam: 'page', archive: { base: '/news', tag: 'tag', year: 'year', decade: 'decade' } }, categories: [], tagNames: {}, tagCounts: {} };

    async function loadNewsConfig() {
        try {
            const result = await (await fetch('api.php?action=load-news-config&_=' + Date.now())).json();
            if (result.success && result.data) newsConfig = Object.assign(newsConfig, result.data);
        } catch (error) {
            console.error('Error loading news config:', error);
        }
        return newsConfig;
    }

    // Public path of a post from the configured pattern (mirrors nibblyNewsUrl() in includes/news-helper.php)
    function nbNewsUrl(post, pattern) {
        const date = String(post.date || '');
        const parts = /^(\d{4})-(\d{2})-(\d{2})/.exec(date) || ['', '0000', '00', '00'];
        const path = String(pattern || newsConfig.settings.urlPattern || '/news/{slug}')
            .replace('{yyyy}', parts[1]).replace('{mm}', parts[2]).replace('{dd}', parts[3]).replace('{slug}', post.slug || post.id || '');
        const lang = post.lang || DEFAULT_LANG;
        return (lang !== DEFAULT_LANG ? '/' + lang : '') + path;
    }

    // Language versions of one post share date and slug (their ids differ only in the language suffix)
    function nbNewsGroupKey(post) {
        return (post.date || '') + '/' + (post.slug || post.id);
    }

    function nbNewsTagLabel(slug) {
        return newsConfig.tagNames[slug] || slug.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
    }

    async function loadNews() {
        try {
            // The trash only contributes its count to the filter links. Every body is read as soon as it
            // arrives: a response left unread while a sibling request waits blocks the server and gets cut off.
            const [result, trash] = await Promise.all([
                fetch('api.php?action=load-news&_=' + Date.now()).then(r => r.json()),
                fetch('api.php?action=list-news-trash&_=' + Date.now()).then(r => r.json()).catch(() => null),
                loadNewsConfig()
            ]);
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

        // Group the language versions of a post (same date and slug) — primary language row is the "main" entry.
        // The date is part of the key: two posts may share a slug on different days.
        const slugGroups = {};
        newsData.forEach(post => {
            const slug = nbNewsGroupKey(post);
            if (!slugGroups[slug]) slugGroups[slug] = {};
            const lang = post.lang || defaultLang;
            slugGroups[slug][lang] = post;
        });

        // Sort groups by date descending (use primary lang post date, or any available)
        const primaryPost = slug => slugGroups[slug][newsViewLang];
        const inCategory = post => newsCategoryFilter === 'all' || (post.categories || []).includes(newsCategoryFilter);
        const sortedSlugs = Object.keys(slugGroups).filter(slug => primaryPost(slug) && inCategory(primaryPost(slug)) && (newsStatusFilter === 'all' || !!primaryPost(slug).hidden === (newsStatusFilter === 'draft'))).sort((a, b) => {
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
            slugSpan.textContent = post.url || nbNewsUrl(post);
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

            const viewLink = document.createElement('a');
            viewLink.href = '..' + (post.url || nbNewsUrl(post));
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
        editingPost = null;
        showPostEditor({
            id: '',
            lang: lang,
            title: '',
            slug: '',
            date: new Date().toISOString().split('T')[0],
            author: '',
            excerpt: '',
            image: '',
            cover: null,
            sidebar: false,
            content: '',
            categories: [],
            tags: [],
            flags: [],
            hidden: false
        });
    }

    // The complete record of a post; the list rows carry only metadata
    async function loadNewsPost(postId) {
        try {
            const result = await (await fetch('api.php?action=load-news-post&id=' + encodeURIComponent(postId) + '&_=' + Date.now())).json();
            if (result.success && result.data) return result.data;
            showToast(result.message || t('toast.error_generic', {message: postId}), 'error');
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
        return null;
    }

    // A post as a copy source: rows without a content field are completed from the server
    async function newsPostInFull(post) {
        if (!post) return null;
        if (Object.prototype.hasOwnProperty.call(post, 'content')) return post;
        return loadNewsPost(post.id);
    }

    async function editPost(postId) {
        if (!newsData.some(p => p.id === postId)) return false;
        const post = await loadNewsPost(postId);
        if (!post) return false;
        editingPostId = postId;
        editingPost = post;
        showPostEditor(post);
        return true;
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
        const cover = post.cover && typeof post.cover === 'object' ? post.cover : {};
        const coverSrc = cover.src ? (cover.src.startsWith('/') ? '..' + escapeHtml(cover.src) : escapeHtml(cover.src)) : '';
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
                    <div class="nb-news-subtitle-field">
                        <label for="newsSubtitle" class="ce-field-label">${t('news.post_subtitle')}</label>
                        <input type="text" id="newsSubtitle" class="editor-input" value="${escapeHtml(post.subtitle || '')}" placeholder="${escapeHtml(t('news.post_subtitle_placeholder'))}" autocomplete="off">
                        <p class="form-hint">${escapeHtml(t('news.post_subtitle_hint'))}</p>
                    </div>
                    <div class="nb-news-blocks" id="newsBlocks-sections" data-field="sections" aria-label="${escapeHtml(t('news.blocks'))}"></div>
                    <div class="nb-news-blocks-add" id="newsBlocksAdd-sections"></div>
                    <div class="section-item nb-news-excerpt">
                        <div class="section-fields">
                            <label for="newsExcerpt">${t('news.post_excerpt')}</label>
                            <textarea id="newsExcerpt" class="editor-textarea" rows="3">${escapeHtml(post.excerpt)}</textarea>
                        </div>
                    </div>
                    <details class="nb-news-aside-panel" id="newsAsidePanel" ${(post.aside || []).length ? 'open' : ''}>
                        <summary><strong>${escapeHtml(t('news.aside_blocks'))}</strong> <span class="nb-news-aside-count"></span></summary>
                        <p class="form-hint">${escapeHtml(t('news.aside_blocks_hint'))}</p>
                        <div class="nb-news-blocks nb-news-blocks--aside" id="newsBlocks-aside" data-field="aside" aria-label="${escapeHtml(t('news.aside_blocks'))}"></div>
                        <div class="nb-news-blocks-add" id="newsBlocksAdd-aside"></div>
                    </details>
                </div>
                <aside class="nb-page-sidebar" aria-label="${escapeHtml(t('news.writing_actions'))}">
                    <section class="nb-page-save">
                        <h3>${escapeHtml(t('news.writing_save'))}</h3>
                        <div class="nb-page-save-controls"></div>
                        <label class="editor-checkbox-label nb-news-draft">
                            <input type="checkbox" id="newsHidden" ${post.hidden ? 'checked' : ''}>
                            ${t('news.draft')}
                        </label>
                        <label class="editor-checkbox-label nb-news-comments">
                            <input type="checkbox" id="newsComments" ${post.comments === false ? '' : 'checked'}>
                            ${t('news.post_comments')}
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
                        <div class="editor-form-row">
                            <span class="ce-field-label">${t('news.url')}</span>
                            <code class="nb-news-url" id="newsUrlPreview">${escapeHtml(nbNewsUrl(post))}</code>
                        </div>
                    </section>
                    <section class="nb-page-panel" aria-labelledby="newsTaxonomyTitle">
                        <h3 id="newsTaxonomyTitle">${escapeHtml(t('news.post_categories'))}</h3>
                        <div class="nb-news-categories" id="newsCategories">
                            ${newsConfig.categories.length ? newsConfig.categories.map(category => `
                            <label class="editor-checkbox-label nb-news-category">
                                <input type="checkbox" name="newsCategory" value="${escapeHtml(category.slug)}" ${(post.categories || []).includes(category.slug) ? 'checked' : ''}>
                                ${escapeHtml(category.name)}
                            </label>`).join('') : `<p class="form-hint">${escapeHtml(t('news.post_categories_hint'))}</p>`}
                        </div>
                        <div class="editor-form-row">
                            <label for="newsTags">${t('news.post_tags')}</label>
                            <input type="text" id="newsTags" class="editor-input" list="newsTagSuggestions" value="${escapeHtml((post.tags || []).map(nbNewsTagLabel).join(', '))}" placeholder="${escapeHtml(t('news.post_tags_hint'))}" autocomplete="off">
                            <datalist id="newsTagSuggestions">${Object.keys(newsConfig.tagCounts || {}).map(slug => `<option value="${escapeHtml(nbNewsTagLabel(slug))}"></option>`).join('')}</datalist>
                            <small class="form-hint">${escapeHtml(t('news.post_tags_hint'))}</small>
                        </div>
                        <div class="editor-form-row">
                            <label for="newsFlags">${t('news.post_flags')}</label>
                            <input type="text" id="newsFlags" class="editor-input" value="${escapeHtml((post.flags || []).join(', '))}" autocomplete="off">
                            <small class="form-hint">${escapeHtml(t('news.post_flags_hint'))}</small>
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
                        <label class="nb-news-image-layout">${escapeHtml(t('news.post_image_layout'))}
                            <select id="newsImageLayout" class="ce-input">
                                ${['column', 'full', 'hidden'].map(v => `<option value="${v}"${(post.imageLayout || 'column') === v ? ' selected' : ''}>${escapeHtml(t('news.post_image_layout_' + v))}</option>`).join('')}
                            </select>
                        </label>
                        ${AI_FEATURES_ENABLED ? `<p class="ai-field-hint"${dashboardAiImageUsable ? '' : ' hidden'}>${t('ai.image_field_hint')} <button type="button" class="btn btn-secondary btn-sm" onclick="openAiImageGenerator(getNewsImagePrompt(), '16:9')">${t('ai.open_image_generator')}</button></p>` : ''}
                    </section>
                    <section class="nb-page-panel" aria-labelledby="newsCoverTitle">
                        <h3 id="newsCoverTitle">${t('news.post_cover')}</h3>
                        <div class="ce-image-preview" id="newsCoverPreview">
                            <img src="${coverSrc}" alt="" style="${cover.src ? '' : 'display:none;'}">
                        </div>
                        <div class="ce-image-input-row">
                            <input type="text" id="newsCover" class="editor-input" value="${escapeHtml(cover.src || '')}" placeholder="/assets/images/cover.webp" aria-labelledby="newsCoverTitle">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="browseNewsCover()">${t('btn.browse')}</button>
                        </div>
                        <label for="newsCoverLabel" class="ce-field-label">${t('news.post_cover_label')}</label>
                        <input type="text" id="newsCoverLabel" class="editor-input" value="${escapeHtml(cover.label || '')}" placeholder="2/2026" autocomplete="off">
                        <label for="newsCoverAlt" class="ce-field-label">${t('news.post_cover_alt')}</label>
                        <input type="text" id="newsCoverAlt" class="editor-input" value="${escapeHtml(cover.alt || '')}" autocomplete="off">
                        <label class="editor-checkbox-label nb-news-sidebar">
                            <input type="checkbox" id="newsSidebar" ${post.sidebar ? 'checked' : ''}>
                            <span>${t('news.post_sidebar')}</span>
                        </label>
                        <small class="form-hint">${escapeHtml(t('news.post_sidebar_hint'))}</small>
                    </section>
                </aside>
            </div>
        `;

        // Save, view and trash move from the header into the sidebar, as on pages
        if (headerActions) container.querySelector('.nb-page-save-controls').append(headerActions);

        // Body and sidebar as block lists (a post still stored as HTML string becomes one text block)
        initNewsBlocks(post);
        renderNewsBlocks('sections');
        renderNewsBlocks('aside');

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
                updateNewsUrlPreview();
            }
        });
        ['newsSlug', 'newsDate'].forEach(id => document.getElementById(id).addEventListener('input', updateNewsUrlPreview));
    }

 with the slug and date in the form
    function updateNewsUrlPreview() {
        const preview = document.getElementById('newsUrlPreview');
        if (!preview) return;
        preview.textContent = nbNewsUrl({
            slug: document.getElementById('newsSlug')?.value.trim() || '…',
            date: document.getElementById('newsDate')?.value || '',
            lang: document.getElementById('newsLang')?.value || DEFAULT_LANG
        });
        updateNewsEditorButtons();
    }

    function updateNewsEditorButtons() {
        const viewBtn = document.getElementById('newsViewBtn');
        const trashBtn = document.getElementById('newsTrashBtn');
        if (editingPostId) {
            const saved = newsData.find(p => p.id === editingPostId);
            // The link opens the saved address; after a slug or date change the save updates it
            const target = saved ? (saved.url || nbNewsUrl(saved)) : nbNewsUrl({
                slug: document.getElementById('newsSlug')?.value || editingPostId,
                date: document.getElementById('newsDate')?.value || '',
                lang: document.getElementById('newsLang')?.value || DEFAULT_LANG
            });
            if (viewBtn) {
                viewBtn.href = '..' + target;
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
            editingPost = null;
            showNewsList();
            loadNews();
        }
    }

    // ------------------------------------------------------------
    // Blocks of a post: "sections" (reading column) and "aside" (sidebar below cover and excerpt).
    // Same block types and field markup as pages (window.BlockTypeRegistry, sectionFieldsHtml in
    // scripts/media.php); the image block carries the figure options (width, align, credit, lightbox)
    // rendered by includes/block-renderers/image.php.
    // ------------------------------------------------------------
    let newsBlocks = { sections: [], aside: [] };
    const NEWS_BLOCK_TYPES = {
        sections: ['text', 'image', 'gallery', 'heading', 'quote', 'list', 'youtube', 'audio', 'divider', 'spacer'],
        aside: ['text', 'image', 'gallery', 'heading', 'quote', 'list', 'divider', 'spacer']
    };

    function newsBlockDef(type) {
        const def = window.BlockTypeRegistry?.[type];
        if (!def) return null;
        if (type === 'text') {
            // Posts carry their headings in the text; title and style stay reachable but come last
            return Object.assign({}, def, { defaults: { content: '' }, fields: [
                { key: 'content', type: 'wysiwyg', label: t('field.content') },
                { key: 'title', type: 'input', label: t('field.title_optional') },
                { key: 'titleTag', type: 'select', label: t('field.heading_level'), options: ['h2', 'h3', 'h4'].map(v => ({ value: v, label: v.toUpperCase() })) },
                // styles of includes/text-styles.php + content/text-styles.json, same list as the page editor
                { key: 'style', type: 'select', label: t('field.style'), options: (def.fields || []).find(f => f.key === 'style')?.options || [{ value: '', label: t('option.default') }] }
            ] });
        }
        if (type === 'image') {
            return Object.assign({}, def, { defaults: { src: '', alt: '', caption: '', credit: '', width: 'full', align: 'right', lightbox: true }, fields: [
                { key: 'src', type: 'image', label: t('block.image') },
                { key: 'alt', type: 'input', label: t('field.alt_text') },
                { key: 'caption', type: 'input', label: t('field.caption') },
                { key: 'credit', type: 'input', label: t('field.credit') },
                { key: 'width', type: 'select', label: t('field.width'), options: [
                    { value: 'full', label: t('option.full_width') }, { value: 'half', label: t('option.half_width') },
                    { value: 'medium', label: t('option.medium') }, { value: 'small', label: t('option.small') }
                ] },
                { key: 'align', type: 'select', label: t('field.align'), options: [
                    { value: 'right', label: t('option.align_right') }, { value: 'left', label: t('option.align_left') }, { value: 'center', label: t('option.align_center') }
                ] },
                { key: 'lightbox', type: 'checkbox', label: t('field.lightbox') }
            ] });
        }
        return def;
    }

    function initNewsBlocks(post) {
        const copy = list => Array.isArray(list) ? JSON.parse(JSON.stringify(list.filter(b => b && typeof b === 'object' && b.type))) : [];
        let sections = copy(post.sections);
        if (!Array.isArray(post.sections) && typeof post.content === 'string' && post.content.trim()) {
            sections = [{ id: 'block_' + Date.now().toString(36), type: 'text', content: post.content }];
        }
        newsBlocks = { sections, aside: copy(post.aside) };
        Object.values(newsBlocks).flat().forEach(block => {
            if (block.type === 'image' && block.lightbox === undefined) block.lightbox = true;
        });
    }

    function newsBlockPreview(block, def) {
        const plain = html => String(html || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        let text = '';
        if (block.type === 'text') text = plain(block.title) || plain(block.content);
        else if (block.type === 'image') text = plain(block.caption) || plain(block.alt) || String(block.src || '').split('/').pop();
        else if (block.type === 'gallery') text = t('news.gallery_images', { count: Array.isArray(block.images) ? block.images.length : 0 });
        else text = plain(block.text || block.title || block.content || '');
        if (text.length > 72) text = text.slice(0, 71).trimEnd() + '…';
        return text || (def?.label || block.type);
    }

    function renderNewsBlocks(field) {
        const container = document.getElementById('newsBlocks-' + field);
        const adder = document.getElementById('newsBlocksAdd-' + field);
        if (!container || !adder) return;
        const blocks = newsBlocks[field];
        container.innerHTML = '';
        if (!blocks.length) {
            container.innerHTML = `<p class="nb-news-blocks-empty form-hint">${escapeHtml(t('news.blocks_empty'))}</p>`;
        }
        blocks.forEach((block, index) => {
            const def = newsBlockDef(block.type);
            const fields = block.type === 'gallery' ? newsGalleryFieldsHtml(field, index, block) : sectionFieldsHtml(block, def);
            const item = document.createElement('div');
            item.className = 'section-item is-open';
            item.dataset.index = index;
            item.dataset.type = block.type;
            item.innerHTML = `
                <div class="section-header">
                    <div class="section-heading">
                        <span class="section-index-label">${index + 1}</span>
                        <span class="section-type ${escapeHtml(block.type)}">${escapeHtml(def?.label || block.type)}</span>
                        <span class="section-title-preview">${escapeHtml(newsBlockPreview(block, def))}</span>
                    </div>
                    <div class="section-actions">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="moveNewsBlock('${field}', ${index}, -1)" title="${escapeHtml(t('news.block_up'))}" aria-label="${escapeHtml(t('news.block_up'))}"${index === 0 ? ' disabled' : ''}>${NEWS_ARROW_UP_ICON}</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="moveNewsBlock('${field}', ${index}, 1)" title="${escapeHtml(t('news.block_down'))}" aria-label="${escapeHtml(t('news.block_down'))}"${index === blocks.length - 1 ? ' disabled' : ''}>${NEWS_ARROW_DOWN_ICON}</button>
                        ${block.type === 'text' ? `<button type="button" class="btn btn-sm btn-secondary" onclick="splitNewsBlock('${field}', ${index})" title="${escapeHtml(t('news.block_split'))}" aria-label="${escapeHtml(t('news.block_split'))}">${NEWS_SPLIT_ICON}</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="mergeNewsBlock('${field}', ${index})" title="${escapeHtml(t('news.block_merge'))}" aria-label="${escapeHtml(t('news.block_merge'))}"${blocks[index + 1]?.type === 'text' ? '' : ' disabled'}>${NEWS_MERGE_ICON}</button>` : ''}
                        ${block.type === 'image' ? `<button type="button" class="btn btn-sm btn-secondary" onclick="convertNewsImageToGallery('${field}', ${index})" title="${escapeHtml(t(blocks[index + 1]?.type === 'image' ? 'news.block_to_gallery_merge' : 'news.block_to_gallery'))}" aria-label="${escapeHtml(t('news.block_to_gallery'))}">${NEWS_GALLERY_ICON}</button>` : ''}
                        ${block.type === 'gallery' && (block.images || []).length === 1 ? `<button type="button" class="btn btn-sm btn-secondary" onclick="convertNewsGalleryToImage('${field}', ${index})" title="${escapeHtml(t('news.block_to_image'))}" aria-label="${escapeHtml(t('news.block_to_image'))}">${NEWS_IMAGE_ICON}</button>` : ''}
                        <button type="button" class="btn btn-sm btn-secondary section-delete-btn" onclick="deleteNewsBlock('${field}', ${index})" title="${escapeHtml(t('section.delete'))}" aria-label="${escapeHtml(t('section.delete'))}">${NEWS_TRASH_ICON}</button>
                    </div>
                </div>
                <div class="section-fields">${fields}</div>`;
            container.appendChild(item);
        });
        container.querySelectorAll('.html-editor').forEach((el, i) => window.NbPageEditor.richEditor(el, `news-${field}-${i}`, { markDirty() {}, pushUndoSnapshot() {} }));
        adder.innerHTML = `<span class="nb-news-blocks-add-label">${escapeHtml(t('news.add_block'))}:</span>` + NEWS_BLOCK_TYPES[field]
            .filter(type => window.BlockTypeRegistry?.[type])
            .map(type => `<button type="button" class="btn btn-secondary btn-sm" onclick="addNewsBlock('${field}', '${type}')">${window.BlockTypeRegistry[type].iconSvg || ''} <span>${escapeHtml(window.BlockTypeRegistry[type].label)}</span></button>`).join('');
        const count = document.querySelector('#newsAsidePanel .nb-news-aside-count');
        if (count && field === 'aside') count.textContent = blocks.length ? `(${blocks.length})` : '';
    }

    // Gallery block: one row per image (thumbnail, path, alt, caption, credit) plus an add button.
    // The rows use their own classes so the generic .section-field collector leaves them alone.
    function newsGalleryFieldsHtml(field, index, block) {
        const images = Array.isArray(block.images) ? block.images : [];
        const rows = images.map((img, j) => {
            const src = String(img.src || '');
            const preview = src ? (src.startsWith('/') ? '..' + src : src) : '';
            return `<div class="nb-news-gallery-item" data-gallery-item>
                <div class="nb-news-gallery-thumb">${preview ? `<img src="${escapeHtml(preview)}" alt="" onerror="this.style.display='none'">` : ''}</div>
                <div class="nb-news-gallery-fields">
                    <div class="ce-image-input-row">
                        <input type="text" class="ce-input nb-news-gallery-src" data-gallery-key="src" value="${escapeHtml(src)}" placeholder="${escapeHtml(t('block.image'))}">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="browseNewsGalleryImage(this)">${escapeHtml(t('btn.browse'))}</button>
                    </div>
                    <input type="text" class="ce-input" data-gallery-key="alt" value="${escapeHtml(img.alt || '')}" placeholder="${escapeHtml(t('field.alt_text'))}">
                    <input type="text" class="ce-input" data-gallery-key="caption" value="${escapeHtml(img.caption || '')}" placeholder="${escapeHtml(t('field.caption'))}">
                    <input type="text" class="ce-input" data-gallery-key="credit" value="${escapeHtml(img.credit || '')}" placeholder="${escapeHtml(t('field.credit'))}">
                </div>
                <div class="nb-news-gallery-actions">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="moveNewsGalleryImage('${field}', ${index}, ${j}, -1)" title="${escapeHtml(t('news.gallery_up'))}" aria-label="${escapeHtml(t('news.gallery_up'))}"${j === 0 ? ' disabled' : ''}>${NEWS_ARROW_UP_ICON}</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="moveNewsGalleryImage('${field}', ${index}, ${j}, 1)" title="${escapeHtml(t('news.gallery_down'))}" aria-label="${escapeHtml(t('news.gallery_down'))}"${j === images.length - 1 ? ' disabled' : ''}>${NEWS_ARROW_DOWN_ICON}</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="extractNewsGalleryImage('${field}', ${index}, ${j})" title="${escapeHtml(t('news.gallery_extract'))}" aria-label="${escapeHtml(t('news.gallery_extract'))}">${NEWS_EXTRACT_ICON}</button>
                    <button type="button" class="btn btn-sm btn-secondary section-delete-btn" onclick="removeNewsGalleryImage('${field}', ${index}, ${j})" title="${escapeHtml(t('news.gallery_remove'))}" aria-label="${escapeHtml(t('news.gallery_remove'))}">${NEWS_TRASH_ICON}</button>
                </div>
            </div>`;
        }).join('');
        const layout = ['grid', 'full'].includes(block.layout) ? block.layout : 'column';
        return `<div class="nb-news-gallery" data-gallery>
            <label class="nb-news-gallery-layout">${escapeHtml(t('news.gallery_layout'))}
                <select class="ce-input nb-news-gallery-layout-select">
                    <option value="column"${layout === 'column' ? ' selected' : ''}>${escapeHtml(t('news.gallery_layout_column'))}</option>
                    <option value="grid"${layout === 'grid' ? ' selected' : ''}>${escapeHtml(t('news.gallery_layout_grid'))}</option>
                    <option value="full"${layout === 'full' ? ' selected' : ''}>${escapeHtml(t('news.gallery_layout_full'))}</option>
                </select>
            </label>
            ${rows || `<p class="form-hint nb-news-gallery-empty">${escapeHtml(t('news.gallery_empty'))}</p>`}
            <div class="nb-news-gallery-add"><button type="button" class="btn btn-sm btn-secondary" onclick="addNewsGalleryImage('${field}', ${index})">+ ${escapeHtml(t('news.gallery_add'))}</button></div>
        </div>`;
    }

    function newsGalleryImages(itemEl) {
        return Array.from(itemEl.querySelectorAll('[data-gallery-item]')).map(row => {
            const img = {};
            row.querySelectorAll('[data-gallery-key]').forEach(input => { img[input.dataset.galleryKey] = input.value.trim(); });
            return img;
        }).filter(img => img.src);
    }

    function browseNewsGalleryImage(button) {
        const row = button.closest('[data-gallery-item]');
        const input = row?.querySelector('.nb-news-gallery-src');
        if (!input) return;
        NbImageManager.open(function(path) {
            input.value = path;
            const thumb = row.querySelector('.nb-news-gallery-thumb');
            if (thumb) thumb.innerHTML = `<img src="${escapeHtml(path.startsWith('/') ? '..' + path : path)}" alt="">`;
            markDirty();
        }, input.value || null, { types: ['image'], type: 'image' });
    }

    function addNewsGalleryImage(field, index) {
        collectNewsBlocks(field);
        const block = newsBlocks[field][index];
        if (!block) return;
        NbImageManager.open(function(path) {
            if (!Array.isArray(block.images)) block.images = [];
            block.images.push({ src: path, alt: '', caption: '', credit: '' });
            renderNewsBlocks(field);
            markDirty();
        }, null, { types: ['image'], type: 'image' });
    }

    function moveNewsGalleryImage(field, index, j, direction) {
        collectNewsBlocks(field);
        const images = newsBlocks[field][index]?.images;
        const target = j + direction;
        if (!images || target < 0 || target >= images.length) return;
        [images[j], images[target]] = [images[target], images[j]];
        renderNewsBlocks(field);
        markDirty();
    }

    // same glyphs as the visual editor's post tool bars (js/inline-editor.js Icons.arrowUp/arrowDown/delete/image)
    const NEWS_ARROW_UP_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></g></svg>';
    const NEWS_ARROW_DOWN_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M19 12l-7 7-7-7"/></g></svg>';
    const NEWS_TRASH_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M8.451,5.968V3.484 c0-1.371,1.113-2.484,2.484-2.484h2.129c1.371,0,2.484,1.113,2.484,2.484v2.484 M2.065,5.968h19.871 M4.194,5.968l1.732,14.733 c0.156,1.334,1.277,2.299,2.626,2.299h6.898c1.348,0,2.47-0.965,2.626-2.299l1.732-14.733 M8.341,9.804l0.753,9.616 M12,9.804 v9.616 M15.66,9.804l-0.753,9.616"/></g></svg>';
    const NEWS_IMAGE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></g></svg>';
    const NEWS_EXTRACT_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M16.96 20.883 H2.714 c-1.09 0 -1.974 -0.884 -1.974 -1.974 V5.09 C0.74 4 1.624 3.117 2.714 3.117 h13.818 c1.09 0 1.974 0.884 1.974 1.974 V14.961 M6.17 7.065 c0.817 0 1.481 0.663 1.481 1.481 s-0.663 1.481 -1.481 1.481 s-1.481 -0.663 -1.481 -1.481 S5.351 7.065 6.17 7.065 z M18.507 14.961 L13.572 10.026 L2.714 20.883 M15.149 17.253 h8.111 M21.461 15.454 l1.799 1.799 M21.461 19.052 l1.799 -1.799"/></g></svg>';
    const NEWS_GALLERY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></g></svg>';

    function newsImageBlockFromGalleryItem(image) {
        return { id: 'block_' + Date.now().toString(36), type: 'image', src: image.src || '', alt: image.alt || '', caption: image.caption || '', credit: image.credit || '', width: 'full', align: 'right', lightbox: true };
    }

    // The image becomes an image block right after the gallery; an emptied gallery goes away
    function extractNewsGalleryImage(field, index, j) {
        collectNewsBlocks(field);
        const blocks = newsBlocks[field];
        const images = blocks[index]?.images;
        if (!images || !images[j]) return;
        const image = images.splice(j, 1)[0];
        blocks.splice(index + 1, 0, newsImageBlockFromGalleryItem(image));
        if (!images.length) blocks.splice(index, 1);
        renderNewsBlocks(field);
        markDirty();
    }

    // Image block → gallery with that image plus the directly following image blocks
    function convertNewsImageToGallery(field, index) {
        collectNewsBlocks(field);
        const blocks = newsBlocks[field];
        const block = blocks[index];
        if (!block || block.type !== 'image') return;
        let n = 0;
        while (blocks[index + 1 + n]?.type === 'image') n++;
        const images = blocks.slice(index, index + 1 + n).map(b => ({ src: b.src || '', alt: b.alt || '', caption: b.caption || '', credit: b.credit || '' }));
        blocks.splice(index, 1 + n, { id: block.id, type: 'gallery', layout: index === 0 ? 'column' : 'grid', images });
        renderNewsBlocks(field);
        markDirty();
    }

    // Gallery with exactly one image → image block
    function convertNewsGalleryToImage(field, index) {
        collectNewsBlocks(field);
        const blocks = newsBlocks[field];
        const block = blocks[index];
        if (!block || block.type !== 'gallery' || (block.images || []).length !== 1) return;
        blocks.splice(index, 1, Object.assign(newsImageBlockFromGalleryItem(block.images[0]), { id: block.id }));
        renderNewsBlocks(field);
        markDirty();
    }

    function removeNewsGalleryImage(field, index, j) {
        collectNewsBlocks(field);
        const images = newsBlocks[field][index]?.images;
        if (!images || !images[j]) return;
        images.splice(j, 1);
        renderNewsBlocks(field);
        markDirty();
    }

    // The blocks as they stand in the form (checkboxes stay booleans: lightbox off must survive)
    function collectNewsBlocks(field) {
        const container = document.getElementById('newsBlocks-' + field);
        const blocks = newsBlocks[field] || [];
        if (!container) return blocks;
        container.querySelectorAll(':scope > .section-item').forEach((el, index) => {
            const block = blocks[index];
            if (!block) return;
            if (block.type === 'gallery') {
                // every row is collected (empty paths dropped), so a cleared row disappears on save
                block.images = newsGalleryImages(el);
                const layoutValue = el.querySelector('.nb-news-gallery-layout-select')?.value;
                block.layout = ['grid', 'full'].includes(layoutValue) ? layoutValue : 'column';
                return;
            }
            el.querySelectorAll('.section-field').forEach(fieldEl => {
                const key = fieldEl.dataset.key;
                if (!key) return;
                block[key] = fieldEl.type === 'checkbox' ? fieldEl.checked : fieldEl.value;
            });
        });
        return blocks;
    }

    function addNewsBlock(field, type) {
        const def = newsBlockDef(type);
        if (!def) return;
        collectNewsBlocks(field);
        const block = Object.assign({ id: 'block_' + Date.now().toString(36), type }, JSON.parse(JSON.stringify(def.defaults || {})));
        if (type === 'text' && !('content' in block)) block.content = '';
        newsBlocks[field].push(block);
        renderNewsBlocks(field);
        const item = document.querySelector(`#newsBlocks-${field} > .section-item:last-child`);
        if (item) {
            item.scrollIntoView({ behavior: 'smooth', block: 'center' });
            (item.querySelector('.nb-page-rich-editor') || item.querySelector('input:not([type="checkbox"]), textarea, select'))?.focus();
        }
    }

    const NEWS_SPLIT_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16M4 8h16M4 16h16M4 20h16"/><path d="M3 12h3M10.5 12h3M18 12h3"/></g></svg>';
    const NEWS_MERGE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h16M4 7h16M4 17h16M4 21h16"/><path d="m9 10 3 2 3-2M9 14l3-2 3 2"/></g></svg>';

    // Top-level elements of a text block's HTML (the split happens between them)
    function newsBlockElements(html) {
        const box = document.createElement('div');
        box.innerHTML = html || '';
        return Array.from(box.childNodes).filter(node => node.nodeType === 1 || (node.textContent || '').trim() !== '');
    }

    function newsBlockHtml(nodes) {
        return nodes.map(node => node.nodeType === 1 ? node.outerHTML : node.textContent).join('\n').trim();
    }

    // Split a text block in two at position `at` (index into its top-level elements)
    function splitNewsBlockAt(field, index, at) {
        collectNewsBlocks(field);
        const blocks = newsBlocks[field];
        const block = blocks[index];
        if (!block || block.type !== 'text') return;
        const nodes = newsBlockElements(block.content);
        if (at <= 0 || at >= nodes.length) return;
        block.content = newsBlockHtml(nodes.slice(0, at));
        blocks.splice(index + 1, 0, { id: 'block_' + Date.now().toString(36), type: 'text', content: newsBlockHtml(nodes.slice(at)) });
        renderNewsBlocks(field);
        document.querySelector(`#newsBlocks-${field} > .section-item:nth-child(${index + 2})`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Split where the caret stands in the visual editor; otherwise offer the headings of the block
    function splitNewsBlock(field, index) {
        collectNewsBlocks(field);
        const block = newsBlocks[field][index];
        if (!block || block.type !== 'text') return;
        const nodes = newsBlockElements(block.content);
        if (nodes.length < 2) { showToast(t('news.block_split_hint'), 'error'); return; }
        // the rich editor of this block (its id counts html editors, not blocks, so look inside the item)
        const visual = document.querySelector(`#newsBlocks-${field} > .section-item:nth-child(${index + 1}) .nb-page-rich-editor`);
        const sel = window.getSelection();
        if (visual && sel && sel.rangeCount && visual.contains(sel.getRangeAt(0).startContainer) && sel.getRangeAt(0).startContainer !== visual) {
            let node = sel.getRangeAt(0).startContainer;
            while (node.parentNode !== visual) node = node.parentNode;
            const at = Array.from(visual.childNodes).filter(n => n.nodeType === 1 || (n.textContent || '').trim() !== '').indexOf(node);
            if (at > 0) { splitNewsBlockAt(field, index, at); return; }
            if (at === 0) { showToast(t('news.block_split_first'), 'error'); return; }
        }
        const item = document.querySelector(`#newsBlocks-${field} > .section-item:nth-child(${index + 1})`);
        if (!item) return;
        item.querySelector('.nb-news-split-picker')?.remove();
        const headings = nodes.map((node, i) => ({ node, i })).filter(({ node, i }) => i > 0 && node.nodeType === 1 && /^H[1-6]$/.test(node.tagName));
        const picker = document.createElement('div');
        picker.className = 'nb-news-split-picker';
        const label = document.createElement('span');
        label.className = 'form-hint';
        label.textContent = headings.length ? t('news.block_split_before') : t('news.block_split_caret');
        picker.appendChild(label);
        headings.forEach(({ node, i }) => {
            const btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'btn btn-sm btn-secondary';
            btn.textContent = (node.textContent || '').trim().slice(0, 60) || node.tagName.toLowerCase();
            btn.addEventListener('click', () => splitNewsBlockAt(field, index, i));
            picker.appendChild(btn);
        });
        const cancel = document.createElement('button');
        cancel.type = 'button'; cancel.className = 'btn btn-sm btn-secondary';
        cancel.textContent = t('cancel');
        cancel.addEventListener('click', () => picker.remove());
        picker.appendChild(cancel);
        item.querySelector('.section-header').after(picker);
    }

    // Append the following text block to this one (its title becomes a heading at the seam)
    function mergeNewsBlock(field, index) {
        collectNewsBlocks(field);
        const blocks = newsBlocks[field];
        const current = blocks[index], next = blocks[index + 1];
        if (!current || !next || current.type !== 'text' || next.type !== 'text') return;
        const title = next.title ? '<h2>' + escapeHtml(next.title) + '</h2>' : '';
        current.content = [current.content || '', title, next.content || ''].filter(Boolean).join('\n');
        blocks.splice(index + 1, 1);
        renderNewsBlocks(field);
    }

    function moveNewsBlock(field, index, direction) {
        collectNewsBlocks(field);
        const blocks = newsBlocks[field];
        const target = index + direction;
        if (target < 0 || target >= blocks.length) return;
        [blocks[index], blocks[target]] = [blocks[target], blocks[index]];
        renderNewsBlocks(field);
    }

    // Like deleteSection() of the page editor: no dialog, the change is only persisted on save
    function deleteNewsBlock(field, index) {
        collectNewsBlocks(field);
        newsBlocks[field].splice(index, 1);
        renderNewsBlocks(field);
    }

    function browseNewsImage() {
        const inputEl = document.getElementById('newsImage');
        const previewEl = document.getElementById('newsImagePreview');
        browseImageForField(inputEl, previewEl);
    }

    function browseNewsCover() {
        const inputEl = document.getElementById('newsCover');
        const previewEl = document.getElementById('newsCoverPreview');
        browseImageForField(inputEl, previewEl);
    }

    function cancelPostEditor() {
        editingPostId = null;
        editingPost = null;
        showNewsList();
        renderNewsList();
    }

    // The post as it stands in the editor; also compared with newsSavedState to spot unsaved changes
    // Keys the form edits; everything else of the loaded record (imageAlt, wpId, ...) is passed through
    const NEWS_FORM_KEYS = ['id', 'lang', 'title', 'subtitle', 'slug', 'date', 'author', 'excerpt', 'image', 'imageLayout', 'cover', 'sidebar', 'content', 'sections', 'aside', 'categories', 'tags', 'flags', 'hidden', 'comments', 'url', 'lastModified'];

    function collectNewsPost() {
        const extras = {};
        Object.keys(editingPost || {}).forEach((key) => {
            if (!NEWS_FORM_KEYS.includes(key)) extras[key] = editingPost[key];
        });
        const coverSrc = (document.getElementById('newsCover')?.value || '').trim();
        return {
            ...extras,
            id: editingPostId || '',
            lang: document.getElementById('newsLang').value,
            title: document.getElementById('newsTitle').value.trim(),
            subtitle: document.getElementById('newsSubtitle').value.trim(),
            slug: document.getElementById('newsSlug').value.trim(),
            date: document.getElementById('newsDate').value,
            author: document.getElementById('newsAuthor').value.trim(),
            excerpt: document.getElementById('newsExcerpt').value.trim(),
            image: document.getElementById('newsImage').value.trim(),
            imageLayout: document.getElementById('newsImageLayout')?.value || 'column',
            cover: coverSrc ? {
                src: coverSrc,
                alt: (document.getElementById('newsCoverAlt')?.value || '').trim(),
                label: (document.getElementById('newsCoverLabel')?.value || '').trim()
            } : null,
            sidebar: !!document.getElementById('newsSidebar')?.checked,
            sections: JSON.parse(JSON.stringify(collectNewsBlocks('sections'))),
            aside: JSON.parse(JSON.stringify(collectNewsBlocks('aside'))),
            categories: Array.from(document.querySelectorAll('#newsCategories input[name="newsCategory"]:checked')).map(el => el.value),
            tags: (document.getElementById('newsTags')?.value || '').split(',').map(v => v.trim()).filter(Boolean),
            flags: (document.getElementById('newsFlags')?.value || '').split(',').map(v => v.trim()).filter(Boolean),
            hidden: document.getElementById('newsHidden').checked,
            comments: document.getElementById('newsComments')?.checked !== false
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
                // Stay in editor — the saved record has the id (date + slug) that view and trash need
                if (result.data?.id) {
                    editingPostId = result.data.id;
                    editingPost = result.data;
                    document.getElementById('newsSlug').value = result.data.slug || '';
                }
                await loadNews(); // also refreshes the tag names the server just recorded
                if (result.data?.tags) document.getElementById('newsTags').value = result.data.tags.map(nbNewsTagLabel).join(', ');
                // Update view + trash buttons
                updateNewsEditorButtons();
                updateNewsUrlPreview();
                newsSavedState = JSON.stringify(collectNewsPost());
                return true;
            }
            showToast(result.message, 'error');
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
        return false;
    }

    // Status filter links (all / published / draft / trash), the categories with their counts, and the
    // languages; a post counts by its primary-language version
    function renderNewsFilterLinks(target, inTrash) {
        const defaultLang = '<?php echo SITE_LANG_DEFAULT; ?>';
        const langOf = post => post.lang || defaultLang;
        const posts = newsData.filter(post => langOf(post) === newsViewLang);
        const category = (value, label, count) => ({ value, label, count, active: !inTrash && newsCategoryFilter === value, onSelect() {
            newsCategoryFilter = value;
            dashboardListPages.news = 1;
            if (inTrash) closeNewsTrash(); else renderNewsList();
        } });
        const categorySet = !inTrash && newsConfig.categories.length > 0 && { label: t('filter.category'), icon: 'folder', items: [
            category('all', t('filter.all'), posts.length),
            ...newsConfig.categories.map(c => category(c.slug, c.name, posts.filter(p => (p.categories || []).includes(c.slug)).length))
        ] };
        const status = (value, label, count) => ({ value, label, count, active: !inTrash && newsStatusFilter === value, onSelect() {
            newsStatusFilter = value;
            if (inTrash) closeNewsTrash(); else renderNewsList();
        } });
        renderFilterLinks(target, [{ items: [
            status('all', t('filter.all'), posts.length),
            status('published', t('filter.published'), posts.filter(p => !p.hidden).length),
            status('draft', t('filter.draft'), posts.filter(p => p.hidden).length),
            { value: 'trash', label: t('pages.trash'), count: newsTrashCount, active: inTrash, onSelect: showNewsTrash }
        ] }, categorySet, !inTrash && Object.keys(SITE_LANGUAGES).length > 1 && { label: t('filter.language'), icon: 'language', items: Object.keys(SITE_LANGUAGES).map(lang => ({
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
        const key = nbNewsGroupKey(post);
        const versions = {};
        if (editingPostId) newsData.forEach(p => { if (nbNewsGroupKey(p) === key) versions[p.lang || DEFAULT_LANG] = p; });
        const source = versions[DEFAULT_LANG];
        placeLanguageSwitch(root, renderLanguageSwitch({
            name: 'news',
            current: postLang,
            languages: languages.map(code => ({ code, label: SITE_LANGUAGES[code], exists: !editingPostId || !!versions[code] })),
            onSelect(code, exists) {
                if (!editingPostId) { document.getElementById('newsLang').value = code; return true; }
                continueWithUnsaved(newsHasUnsavedChanges, savePost, () => {
                    if (exists) editPost(versions[code].id);
                    else chooseVersionCreation(SITE_LANGUAGES[code], withAi => createNewsTranslation(editingPost || newsData.find(p => p.id === editingPostId), code, null, withAi));
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
        source = await newsPostInFull(source); // the list row has neither content nor the full excerpt
        if (!source) return;
        const textBlocks = {};
        (Array.isArray(source.sections) ? source.sections : []).forEach((block, index) => { if (block?.type === 'text' && block.content) textBlocks['sections.' + index + '.content'] = block.content; });
        const fields = translatableStrings({ title: source.title || '', excerpt: source.excerpt || '', content: source.content || '', ...textBlocks });
        const translations = await aiTranslateFields(source.lang || DEFAULT_LANG, [target], fields, button, 8000);
        if (!translations?.[target]) return;
        const before = collectNewsPost();
        const result = Object.assign({}, translations[target]);
        const sections = JSON.parse(JSON.stringify(before.sections || []));
        Object.keys(result).forEach(key => {
            const match = /^sections\.(\d+)\.content$/.exec(key);
            if (!match) return;
            if (sections[match[1]]) sections[match[1]].content = result[key];
            delete result[key];
        });
        const translated = Object.assign({}, editingPost || {}, before, result, { sections });
        showPostEditor(translated);
        newsSavedState = JSON.stringify(before); // the translation is a change still to be saved
        showToast(t('editor.translate_review'), 'success');
    }

    // A new language version of a post: a copy as draft (so untranslated text never goes live), opened at once
    async function createNewsTranslation(sourcePost, targetLang, linkEl, withAi = false) {
        if (linkEl) { linkEl.classList.add('disabled'); linkEl.textContent = '...'; }
        try {
            sourcePost = await newsPostInFull(sourcePost);
            if (!sourcePost) throw new Error(t('news.untitled'));
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
                await editPost(result.data.id);
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
