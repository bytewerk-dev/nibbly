<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // Intercept all fetch calls — redirect to login on session expiry
    const _origFetch = window.fetch;
    window.fetch = async function(...args) {
        const response = await _origFetch.apply(this, args);
        if (response.status === 401) {
            try {
                const clone = response.clone();
                const data = await clone.json();
                if (data.session_expired) {
                    window.location.href = 'index.php?timeout=' + Math.floor(Date.now() / 1000);
                    return response;
                }
            } catch(e) {}
        }
        return response;
    };

    let currentPage = null;
    let currentContent = null;
    let sectionCounter = 0;
    const DASHBOARD_LIST_DEFAULT_PAGE_SIZE = 50;
    const dashboardListPages = {
        pages: 1,
        trash: 1,
        events: 1,
        eventsTrash: 1,
        news: 1,
        mails: 1,
        icons: 1
    };

    function clampDashboardPageSize(value) {
        const parsed = parseInt(value, 10);
        if (!Number.isFinite(parsed)) return DASHBOARD_LIST_DEFAULT_PAGE_SIZE;
        return Math.max(10, Math.min(500, parsed));
    }

    function clampMediaPageSize(value) {
        const parsed = parseInt(value, 10);
        if (!Number.isFinite(parsed)) return 25;
        return Math.max(10, Math.min(500, parsed));
    }

    function getDashboardPageSize(kind = 'default') {
        const dashboardSettings = currentSettings?.dashboard || {};
        return kind === 'icons'
            ? clampDashboardPageSize(dashboardSettings.iconManagerItemsPerPage)
            : clampDashboardPageSize(dashboardSettings.itemsPerPage);
    }

    function pageSlice(items, pageKey, pageSize) {
        const list = Array.isArray(items) ? items : [];
        const pages = Math.max(1, Math.ceil(list.length / pageSize));
        const current = Math.max(1, Math.min(dashboardListPages[pageKey] || 1, pages));
        dashboardListPages[pageKey] = current;
        const start = (current - 1) * pageSize;
        return {
            items: list.slice(start, start + pageSize),
            total: list.length,
            current,
            pages,
            from: list.length ? start + 1 : 0,
            to: Math.min(start + pageSize, list.length)
        };
    }

    function renderAdminListFooter(footerId, pageKey, total, pageSize, renderCallback, topFooterId = '') {
        const bottomFooter = document.getElementById(footerId);
        const topFooter = topFooterId ? document.getElementById(topFooterId) : null;
        if (!bottomFooter && !topFooter) return;
        const page = Math.max(1, Math.min(dashboardListPages[pageKey] || 1, Math.max(1, Math.ceil(total / pageSize))));
        const pages = Math.max(1, Math.ceil(total / pageSize));
        const from = total ? ((page - 1) * pageSize) + 1 : 0;
        const to = total ? Math.min(page * pageSize, total) : 0;
        const summary = total
            ? t('lists.summary_range', {from, to, total})
            : t('lists.summary_empty');
        const hasPagination = total > pageSize;

        function paginationIcon(direction) {
            const path = direction === 'prev' ? 'M15 18l-6-6 6-6' : 'M9 18l6-6-6-6';
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' + path + '"/></svg>';
        }

        function addButton(nav, label, targetPage, disabled, currentPage, direction = '') {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'admin-pagination__btn' + (currentPage ? ' is-current' : '');
            if (direction) {
                button.classList.add('admin-pagination__btn--arrow');
                button.innerHTML = paginationIcon(direction);
                button.setAttribute('aria-label', direction === 'prev' ? t('lists.previous_page') : t('lists.next_page'));
            } else {
                button.textContent = label;
            }
            button.disabled = !!disabled;
            if (currentPage) button.setAttribute('aria-current', 'page');
            button.addEventListener('click', function() {
                if (disabled || currentPage) return;
                dashboardListPages[pageKey] = targetPage;
                renderCallback();
            });
            nav.appendChild(button);
        }

        function buildNav() {
            const nav = document.createElement('nav');
            nav.className = 'admin-pagination';
            nav.setAttribute('aria-label', t('lists.pagination'));
            addButton(nav, '', page - 1, page <= 1, false, 'prev');
            const pageNumbers = [];
            for (let i = 1; i <= pages; i++) {
                if (i === 1 || i === pages || Math.abs(i - page) <= 1) pageNumbers.push(i);
            }
            let previous = 0;
            pageNumbers.forEach(number => {
                if (previous && number - previous > 1) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'admin-pagination__ellipsis';
                    ellipsis.textContent = '...';
                    nav.appendChild(ellipsis);
                }
                addButton(nav, String(number), number, false, number === page);
                previous = number;
            });
            addButton(nav, '', page + 1, page >= pages, false, 'next');
            return nav;
        }

        function renderFooter(footer, showOnlyWhenPaginated) {
            if (!footer) return;
            if (showOnlyWhenPaginated && !hasPagination) {
                footer.innerHTML = '';
                footer.hidden = true;
                return;
            }
            footer.hidden = false;
            footer.innerHTML = '<span class="admin-list-summary">' + escapeHtml(summary) + '</span>';
            if (hasPagination) footer.appendChild(buildNav());
        }

        renderFooter(topFooter, true);
        renderFooter(bottomFooter, false);
    }

    // Update page dropdown from pageListCache (both desktop and mobile)
    function updatePageSelect() {
        const lang = document.getElementById('langSelect').value;
        const pageSelect = document.getElementById('pageSelect');
        const pageSelectMobile = document.getElementById('pageSelectMobile');
        pageSelect.innerHTML = '';
        if (pageSelectMobile) pageSelectMobile.innerHTML = '';

        if (!pageListCache) return;

        for (const page of pageListCache.pages) {
            const langInfo = page.languages[lang];
            if (!langInfo || !langInfo.exists) continue;
            const option = document.createElement('option');
            option.value = page.slug;
            option.textContent = langInfo.title || page.title;
            pageSelect.appendChild(option);
            if (pageSelectMobile) pageSelectMobile.appendChild(option.cloneNode(true));
        }
    }

    // Sync selectors between desktop and mobile
    function syncSelect(targetId, value) {
        const target = document.getElementById(targetId);
        if (target) target.value = value;
        updatePageSelect();
    }

    // Page list
    let pageListCache = null;
    let currentSeoHealth = null;
    let pageListSearchQuery = '';

    function pageContentKey(lang, path) {
        return lang + '_' + String(path || '').replaceAll('/', '__');
    }

    async function loadPageList() {
        try {
            const response = await fetch('api.php?action=list-pages&_=' + Date.now());
            const result = await response.json();
            if (result.success) {
                applyPageList(result.data);
            }
        } catch (error) {
            console.error('Error loading page list:', error);
        }
    }

    function normalizePageListSearchValue(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLowerCase();
    }

    function handlePageListSearch(value) {
        pageListSearchQuery = normalizePageListSearchValue(value);
        dashboardListPages.pages = 1;
        if (pageListCache) {
            const viewLang = document.getElementById('pageListLang').value;
            renderPageList(pageListCache, viewLang);
        }
    }

    function applyPageList(pageListData) {
        pageListCache = pageListData;
        const viewLang = document.getElementById('pageListLang').value;
        renderPageList(pageListData, viewLang);
        updatePageSelect();
    }

    function renderPageListForLang(lang) {
        dashboardListPages.pages = 1;
        if (pageListCache) {
            renderPageList(pageListCache, lang);
        }
    }

    function renderPageList(data, viewLang) {
        const { pages, languages } = data;
        const langs = Object.keys(languages);
        const otherLangs = langs.filter(l => l !== viewLang);
        const thead = document.querySelector('#pageListTable thead tr');
        const tbody = document.getElementById('pageListBody');

        // Build header: Title | Date | lang columns...
        thead.innerHTML = '';
        const thTitle = document.createElement('th');
        thTitle.className = 'page-list-col-title page-list-sortable';
        thTitle.dataset.sort = 'title';
        thTitle.innerHTML = t('pages.col_title') + ' <span class="page-list-sort-icon"></span>';
        thTitle.onclick = () => sortPageList('title');
        thead.appendChild(thTitle);

        const thDate = document.createElement('th');
        thDate.className = 'page-list-col-date page-list-sortable';
        thDate.dataset.sort = 'date';
        thDate.innerHTML = t('pages.col_date') + ' <span class="page-list-sort-icon"></span>';
        thDate.onclick = () => sortPageList('date');
        thead.appendChild(thDate);

        otherLangs.forEach(lang => {
            const th = document.createElement('th');
            th.className = 'page-list-col-lang';
            th.textContent = languages[lang];
            thead.appendChild(th);
        });

        // Build rows — only show pages that exist in the view language (or are defined for it)
        tbody.innerHTML = '';
        const visiblePages = pages.filter(page => {
            const viewInfo = page.languages[viewLang];
            // Skip pages that have no entry at all for this language
            if (!viewInfo) return false;
            const titleText = viewInfo.title || page.slug;
            if (pageListSearchQuery && !normalizePageListSearchValue(titleText).includes(pageListSearchQuery)) {
                return false;
            }
            return true;
        });
        const pageSize = getDashboardPageSize();
        const paged = pageSlice(visiblePages, 'pages', pageSize);
        renderAdminListFooter('pageListFooter', 'pages', visiblePages.length, pageSize, function() {
            renderPageList(data, viewLang);
        }, 'pageListFooterTop');

        paged.items.forEach(page => {
            const viewInfo = page.languages[viewLang];
            const tr = document.createElement('tr');
            tr.className = 'page-list-row';

            // Title cell — slug above, title below, hover actions underneath
            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';

            const slugSpan = document.createElement('span');
            slugSpan.className = 'page-list-slug';
            slugSpan.textContent = '/' + page.slug;
            tdTitle.appendChild(slugSpan);

            const titleLink = document.createElement('a');
            titleLink.href = '#';
            titleLink.className = 'page-list-title-link';
            titleLink.textContent = viewInfo.title || page.slug;
            titleLink.onclick = (e) => {
                e.preventDefault();
                openPageFromList(viewLang, page.slug);
            };
            const titleRow = document.createElement('div');
            titleRow.className = 'page-list-title-row';
            titleRow.appendChild(titleLink);
            const seoBadge = createSeoHealthBadge(viewInfo.seoHealth, 'page-list');
            if (seoBadge) titleRow.appendChild(seoBadge);
            tdTitle.appendChild(titleRow);

            // Hover action row (WordPress-style)
            const actions = document.createElement('div');
            actions.className = 'page-list-row-actions';

            if (viewInfo.exists) {
                // Edit
                const editLink = document.createElement('a');
                editLink.href = '#';
                editLink.className = 'page-list-row-action';
                editLink.innerHTML = icon('edit', 12, '2') + ' ' + t('pages.edit');
                editLink.onclick = (e) => { e.preventDefault(); openPageFromList(viewLang, page.slug); };
                actions.appendChild(editLink);

                // View
                const sep1 = document.createElement('span');
                sep1.className = 'page-list-row-action-sep';
                sep1.textContent = '|';
                actions.appendChild(sep1);

                const viewLink = document.createElement('a');
                const frontendPath = page.slug === 'home'
                    ? ((viewLang === DEFAULT_LANG) ? '../' : '../' + viewLang + '/')
                    : ((viewLang === DEFAULT_LANG) ? '../' + page.slug : '../' + viewLang + '/' + page.slug);
                viewLink.href = frontendPath;
                viewLink.target = '_blank';
                viewLink.className = 'page-list-row-action';
                viewLink.innerHTML = icon('eye', 12, '2') + ' ' + t('pages.view');
                actions.appendChild(viewLink);

                // Duplicate
                const sep2 = document.createElement('span');
                sep2.className = 'page-list-row-action-sep';
                sep2.textContent = '|';
                actions.appendChild(sep2);

                const dupLink = document.createElement('a');
                dupLink.href = '#';
                dupLink.className = 'page-list-row-action';
                dupLink.innerHTML = icon('duplicate', 12, '2') + ' ' + t('pages.duplicate');
                dupLink.onclick = async (e) => {
                    e.preventDefault();
                    dupLink.classList.add('disabled');
                    try {
                        const result = await duplicatePage(viewLang, page.slug);
                        showToast(t('toast.page_duplicated', {slug: result.slug}), 'success');
                        if (result.pageList) applyPageList(result.pageList);
                    } catch (err) {
                        showToast(err.message, 'error');
                        dupLink.classList.remove('disabled');
                    }
                };
                actions.appendChild(dupLink);

                // Trash
                const sep3 = document.createElement('span');
                sep3.className = 'page-list-row-action-sep';
                sep3.textContent = '|';
                actions.appendChild(sep3);

                const trashLink = document.createElement('a');
                trashLink.href = '#';
                trashLink.className = 'page-list-row-action page-list-row-action--danger';
                trashLink.innerHTML = icon('trash', 12, '2') + ' ' + t('pages.trash');
                trashLink.onclick = async (e) => {
                    e.preventDefault();
                    const pageName = pageContentKey(viewLang, page.slug);
                    try {
                        const result = await deletePage(pageName);
                        showToast(t('toast.page_trashed'), 'success');
                        if (result.pageList) applyPageList(result.pageList);
                    } catch (err) {
                        showToast(err.message, 'error');
                    }
                };
                actions.appendChild(trashLink);
            }

            tdTitle.appendChild(actions);
            tr.appendChild(tdTitle);

            // Date cell — show date for this language
            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            if (viewInfo.lastModified) {
                const d = new Date(viewInfo.lastModified);
                tdDate.textContent = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            } else {
                tdDate.textContent = '—';
            }
            tr.appendChild(tdDate);

            // Other language columns
            otherLangs.forEach(lang => {
                const td = document.createElement('td');
                td.className = 'page-list-cell-lang';
                const langInfo = page.languages[lang];
                if (langInfo && langInfo.exists) {
                    const editBtn = document.createElement('a');
                    editBtn.href = '#';
                    editBtn.className = 'btn btn-secondary btn-sm page-list-lang-btn';
                    editBtn.innerHTML = icon('edit', 12) + ' ' + t('pages.edit');
                    editBtn.onclick = (e) => {
                        e.preventDefault();
                        openPageFromList(lang, page.slug);
                    };
                    td.appendChild(editBtn);
                } else if (viewInfo.exists) {
                    // Page doesn't exist in this language — offer to create as copy from view language
                    const createLink = document.createElement('a');
                    createLink.href = '#';
                    createLink.className = 'page-list-create-link';
                    createLink.textContent = t('pages.create');
                    createLink.onclick = async (e) => {
                        e.preventDefault();
                        createLink.classList.add('disabled');
                        createLink.textContent = '...';
                        try {
                            const result = await copyPageToLang(viewLang, page.slug, lang, page.slug);
                            showToast(t('toast.page_created', {title: lang + '_' + page.slug}), 'success');
                            if (result.data?.pageList) applyPageList(result.data.pageList);
                        } catch (err) {
                            showToast(err.message, 'error');
                            createLink.classList.remove('disabled');
                            createLink.textContent = t('pages.create');
                        }
                    };
                    td.appendChild(createLink);
                } else {
                    td.textContent = '—';
                }
                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });

        if (visiblePages.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.className = 'page-list-empty';
            td.colSpan = 2 + otherLangs.length;
            td.textContent = pageListSearchQuery
                ? 'Keine Seiten mit diesem Titel gefunden.'
                : 'Keine Seiten vorhanden.';
            tr.appendChild(td);
            tbody.appendChild(tr);
        }

        // Update sort indicators
        updateSortIndicators();
    }

    // Sort state
    let pageListSortField = 'title';
    let pageListSortDir = 'asc';

    function sortPageList(field) {
        if (pageListSortField === field) {
            pageListSortDir = pageListSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            pageListSortField = field;
            pageListSortDir = 'asc';
        }
        if (pageListCache) {
            const viewLang = document.getElementById('pageListLang').value;
            // Sort the pages array in place
            pageListCache.pages.sort((a, b) => {
                let valA, valB;
                if (field === 'title') {
                    const aInfo = a.languages[viewLang];
                    const bInfo = b.languages[viewLang];
                    valA = (aInfo?.title || a.slug).toLowerCase();
                    valB = (bInfo?.title || b.slug).toLowerCase();
                } else {
                    const aInfo = a.languages[viewLang];
                    const bInfo = b.languages[viewLang];
                    valA = aInfo?.lastModified || '';
                    valB = bInfo?.lastModified || '';
                }
                let cmp = valA < valB ? -1 : valA > valB ? 1 : 0;
                return pageListSortDir === 'asc' ? cmp : -cmp;
            });
            renderPageList(pageListCache, viewLang);
        }
    }

    function updateSortIndicators() {
        document.querySelectorAll('.page-list-sortable').forEach(th => {
            const icon = th.querySelector('.page-list-sort-icon');
            if (th.dataset.sort === pageListSortField) {
                th.classList.add('sorted');
                icon.textContent = pageListSortDir === 'asc' ? '▲' : '▼';
            } else {
                th.classList.remove('sorted');
                icon.textContent = '';
            }
        });
    }

    function openPageFromList(lang, slug) {
        // Set selectors and load
        document.getElementById('langSelect').value = lang;
        updatePageSelect();
        document.getElementById('pageSelect').value = slug;
        const m = document.getElementById('langSelectMobile');
        if (m) m.value = lang;
        const pm = document.getElementById('pageSelectMobile');
        if (pm) pm.value = slug;
        loadContent();
    }

    function getPageSeoHealth(lang, slug) {
        const pageData = pageListCache?.pages?.find(p => p.slug === slug);
        return pageData?.languages?.[lang]?.seoHealth || null;
    }

    function setPageSeoHealth(lang, slug, seoHealth) {
        if (!pageListCache || !seoHealth) return;
        const pageData = pageListCache.pages?.find(p => p.slug === slug);
        if (pageData?.languages?.[lang]) {
            pageData.languages[lang].seoHealth = seoHealth;
        }
    }

    function createSeoHealthBadge(health, context = '') {
        if (!health) return null;
        const status = ['green', 'yellow', 'red'].includes(health.status) ? health.status : 'yellow';
        const score = Number.isFinite(Number(health.score)) ? Number(health.score) : 0;
        const label = health.label || 'SEO';
        const issues = Array.isArray(health.issues) ? health.issues : [];
        const badge = document.createElement('span');
        badge.className = `seo-health-badge seo-health-badge--${status}${context ? ' seo-health-badge--' + context : ''}`;
        badge.tabIndex = 0;
        badge.setAttribute('role', 'status');
        badge.setAttribute('aria-label', [label + ' ' + score + '/100'].concat(issues).join('. '));
        badge.title = [label + ' ' + score + '/100'].concat(issues).join('\n');
        badge.innerHTML = `
            <span class="seo-health-badge__dot" aria-hidden="true"></span>
            <span class="seo-health-badge__text">${escapeHtml(label)}</span>
            <span class="seo-health-badge__score">${score}/100</span>
        `;
        if (issues.length) {
            const popover = document.createElement('span');
            popover.className = 'seo-health-badge__popover';
            popover.innerHTML = `<strong>${escapeHtml(label)}</strong><ul>${issues.map(issue => `<li>${escapeHtml(issue)}</li>`).join('')}</ul>`;
            badge.appendChild(popover);
        }
        return badge;
    }

    function updateEditorSeoHealth(health) {
        const holder = document.getElementById('editorSeoHealth');
        if (!holder) return;
        holder.innerHTML = '';
        const badge = createSeoHealthBadge(health, 'editor');
        if (badge) holder.appendChild(badge);
    }

    async function copyPageToLang(sourceLang, sourceSlug, targetLang, targetSlug) {
        const formData = new FormData();
        formData.append('action', 'copy-page');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('source', pageContentKey(sourceLang, sourceSlug));
        formData.append('targetLang', targetLang);
        formData.append('slug', targetSlug);

        const response = await fetch('api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Error creating page');
        }
        return result;
    }

    async function duplicatePage(lang, slug) {
        const formData = new FormData();
        formData.append('action', 'duplicate-page');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('source', pageContentKey(lang, slug));

        const response = await fetch('api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Error duplicating page');
        }
        return result.data;
    }

    async function deletePage(pageName) {
        const formData = new FormData();
        formData.append('action', 'delete-page');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('page', pageName);

        const response = await fetch('api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Error deleting page');
        }
        return result.data;
    }

    async function trashCurrentPage() {
        if (!currentPage) return;
        try {
            const result = await deletePage(currentPage);
            showToast(t('toast.page_trashed'), 'success');
            if (result.pageList) applyPageList(result.pageList);
            showPageList();
        } catch (err) {
            showToast(err.message, 'error');
        }
    }

    function showPageList(pushHistory = true) {
        document.getElementById('pageListContainer').style.display = 'block';
        document.getElementById('trashContainer').style.display = 'none';
        document.getElementById('editorContainer').style.display = 'none';
        document.getElementById('backupContainer').style.display = 'none';
        currentPage = null;
        currentContent = null;
        // Update topbar
        const topbarTitle = document.getElementById('topbarTitle');
        if (topbarTitle) topbarTitle.textContent = t('pages.title');
        // Hide topbar selectors, show them only when editing
        const topbarSelectors = document.getElementById('topbarSelectors');
        if (topbarSelectors) topbarSelectors.style.display = 'none';
        loadPageList();
        if (pushHistory) {
            updateDashboardHash('content');
        }
    }

    // ============================================================
    // TRASH
    // ============================================================

    async function showTrash() {
        document.getElementById('pageListContainer').style.display = 'none';
        document.getElementById('editorContainer').style.display = 'none';
        document.getElementById('backupContainer').style.display = 'none';
        document.getElementById('trashContainer').style.display = 'block';
        const topbarTitle = document.getElementById('topbarTitle');
        if (topbarTitle) topbarTitle.textContent = t('trash.title');
        const topbarSelectors = document.getElementById('topbarSelectors');
        if (topbarSelectors) topbarSelectors.style.display = 'none';
        await loadTrash();
    }

    async function loadTrash() {
        try {
            const response = await fetch('api.php?action=list-trash&_=' + Date.now());
            const result = await response.json();
            if (result.success) {
                renderTrash(result.data);
            }
        } catch (error) {
            console.error('Error loading trash:', error);
        }
    }

    function renderTrash(items) {
        const tbody = document.getElementById('trashBody');
        const emptyMsg = document.getElementById('trashEmptyMsg');
        const emptyBtn = document.getElementById('emptyTrashBtn');
        const table = document.getElementById('trashTable');
        tbody.innerHTML = '';

        if (!items || items.length === 0) {
            table.style.display = 'none';
            emptyMsg.style.display = 'block';
            emptyBtn.style.display = 'none';
            renderAdminListFooter('trashListFooter', 'trash', 0, getDashboardPageSize(), function() {
                renderTrash(items);
            }, 'trashListFooterTop');
            return;
        }

        table.style.display = '';
        emptyMsg.style.display = 'none';
        emptyBtn.style.display = '';

        const pageSize = getDashboardPageSize();
        const paged = pageSlice(items, 'trash', pageSize);
        renderAdminListFooter('trashListFooter', 'trash', items.length, pageSize, function() {
            renderTrash(items);
        }, 'trashListFooterTop');

        paged.items.forEach(item => {
            const tr = document.createElement('tr');

            const tdTitle = document.createElement('td');
            tdTitle.className = 'page-list-cell-title';
            tdTitle.textContent = item.title;
            tr.appendChild(tdTitle);

            const tdPage = document.createElement('td');
            tdPage.textContent = item.page;
            tdPage.className = 'page-list-cell-slug';
            tr.appendChild(tdPage);

            const tdDate = document.createElement('td');
            tdDate.className = 'page-list-cell-date';
            tdDate.textContent = item.deletedDate + ' ' + item.deletedTime;
            tr.appendChild(tdDate);

            const tdActions = document.createElement('td');
            tdActions.className = 'page-list-cell-actions';

            const restoreBtn = document.createElement('button');
            restoreBtn.className = 'btn btn-primary btn-sm';
            restoreBtn.textContent = t('btn.restore');
            restoreBtn.onclick = async () => {
                restoreBtn.disabled = true;
                restoreBtn.textContent = '...';
                try {
                    const formData = new FormData();
                    formData.append('action', 'restore-page');
                    formData.append('csrf_token', CSRF_TOKEN);
                    formData.append('filename', item.filename);
                    const response = await fetch('api.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        showToast(t('toast.page_restored'), 'success');
                        if (result.data?.pageList) applyPageList(result.data.pageList);
                        loadTrash();
                    } else {
                        showToast(result.message || 'Error', 'error');
                        restoreBtn.disabled = false;
                        restoreBtn.textContent = t('btn.restore');
                    }
                } catch (err) {
                    showToast(err.message, 'error');
                    restoreBtn.disabled = false;
                    restoreBtn.textContent = t('btn.restore');
                }
            };
            tdActions.appendChild(restoreBtn);

            const delBtn = document.createElement('button');
            delBtn.className = 'btn btn-danger btn-sm';
            delBtn.textContent = t('btn.delete');
            delBtn.onclick = () => {
                showModal(t('modal.delete_permanently'), t('modal.delete_confirm', {title: item.title, page: item.page}), async () => {
                    closeModal();
                    try {
                        const formData = new FormData();
                        formData.append('action', 'delete-trash');
                        formData.append('csrf_token', CSRF_TOKEN);
                        formData.append('filename', item.filename);
                        const response = await fetch('api.php', { method: 'POST', body: formData });
                        const result = await response.json();
                        if (result.success) {
                            showToast(t('toast.page_deleted'), 'success');
                            loadTrash();
                        } else {
                            showToast(result.message || 'Error', 'error');
                        }
                    } catch (err) {
                        showToast(err.message, 'error');
                    }
                });
            };
            tdActions.appendChild(delBtn);

            tr.appendChild(tdActions);
            tbody.appendChild(tr);
        });
    }

    async function emptyTrash() {
        showModal(t('modal.empty_trash'), t('modal.empty_trash_confirm'), async () => {
            closeModal();
            try {
                const formData = new FormData();
                formData.append('action', 'empty-trash');
                formData.append('csrf_token', CSRF_TOKEN);
                const response = await fetch('api.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message || t('toast.trash_emptied'), 'success');
                    loadTrash();
                } else {
                    showToast(result.message || 'Error', 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        });
    }

    // New page modal
    function showNewPageModal() {
        const lang = document.getElementById('pageListLang').value;
        const overlay = document.getElementById('modalOverlay');
        const title = document.getElementById('modalTitle');
        const text = document.getElementById('modalText');
        const confirmBtn = document.getElementById('modalConfirm');

        title.textContent = t('modal.new_page');
        text.innerHTML =
            '<div class="modal-form">' +
                '<label class="modal-label">' + t('modal.new_page_title') + '<input type="text" id="newPageTitle" class="modal-input" placeholder="' + t('modal.new_page_title_placeholder') + '" autofocus></label>' +
                '<label class="modal-label">' + t('modal.new_page_slug') + '<input type="text" id="newPageSlug" class="modal-input" placeholder="' + t('modal.new_page_slug_placeholder') + '"><span class="modal-hint">' + t('modal.new_page_slug_hint') + '</span></label>' +
            '</div>';

        confirmBtn.textContent = t('modal.create_page');
        confirmBtn.className = 'btn btn-primary';
        confirmBtn.style.display = '';
        overlay.style.display = 'flex';
        overlay.setAttribute('aria-hidden', 'false');

        const titleInput = document.getElementById('newPageTitle');
        const slugInput = document.getElementById('newPageSlug');
        let slugManuallyEdited = false;

        titleInput.addEventListener('input', () => {
            if (!slugManuallyEdited) {
                slugInput.value = titleInput.value
                    .toLowerCase()
                    .replace(/[äöüß]/g, m => ({ä:'ae',ö:'oe',ü:'ue',ß:'ss'})[m])
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '');
            }
        });
        slugInput.addEventListener('input', () => { slugManuallyEdited = true; });

        confirmBtn.onclick = async () => {
            const pageTitle = titleInput.value.trim();
            const pageSlug = slugInput.value.trim();
            if (!pageTitle) { titleInput.focus(); return; }
            if (!pageSlug || !/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/.test(pageSlug)) {
                slugInput.focus();
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = '...';
            try {
                const result = await createPage(lang, pageTitle, pageSlug);
                closeModal();
                showToast(t('toast.page_created', {title: pageTitle}), 'success');
                if (result.pageList) applyPageList(result.pageList);
            } catch (err) {
                showToast(err.message, 'error');
                confirmBtn.disabled = false;
                confirmBtn.textContent = t('modal.create_page');
            }
        };

        setTimeout(() => titleInput.focus(), 100);
    }

    async function createPage(lang, title, slug) {
        const formData = new FormData();
        formData.append('action', 'create-page');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('lang', lang);
        formData.append('title', title);
        formData.append('slug', slug);

        const response = await fetch('api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Error creating page');
        }
        return result.data;
    }

    // Load content
    async function loadContent(pushHistory = true) {
        const lang = document.getElementById('langSelect').value;
        const page = document.getElementById('pageSelect').value;
        currentPage = pageContentKey(lang, page);

        try {
            const response = await fetch(`api.php?action=load&page=${currentPage}`);
            const result = await response.json();

            if (result.success) {
                currentContent = result.data;
                currentSeoHealth = getPageSeoHealth(lang, page);
                clearUndoHistory();
                renderEditor();
                loadBackups();
                // Hide page list, show editor
                document.getElementById('pageListContainer').style.display = 'none';
                document.getElementById('editorContainer').style.display = 'block';
                document.getElementById('backupContainer').style.display = 'block';
                document.getElementById('toggleAllBtn').style.display = '';
                allExpanded = false;
                document.getElementById('toggleAllBtn').textContent = t('editor.expand_all');
                // Show topbar selectors when editing
                const topbarSelectors = document.getElementById('topbarSelectors');
                if (topbarSelectors) topbarSelectors.style.display = 'flex';
                // Update topbar title
                const topbarTitle = document.getElementById('topbarTitle');
                if (topbarTitle) topbarTitle.textContent = t('editor.title');
                // Update View button URL
                const _defLang = '<?php echo SITE_LANG_DEFAULT; ?>';
                const _viewUrl = (lang === _defLang) ? '../' + page : '../' + lang + '/' + page;
                const viewBtn = document.getElementById('editorViewBtn');
                if (viewBtn) viewBtn.href = _viewUrl;

                // Push history state so browser back button returns to page list
                if (pushHistory) {
                    history.pushState({ view: 'editor', page: currentPage }, '', DASHBOARD_PATH + '#page/' + currentPage);
                }

            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    // Read-only meta keys that should not be editable
    const META_KEYS = new Set(['page', 'lang', 'lastModified']);
    // Keys that get their own special renderer
    const SPECIAL_KEYS = new Set(['sections']);
    // Keys rendered in the dedicated "Page Settings" panel
    const PAGE_SETTINGS_KEYS = new Set(['title', 'description', 'nav', 'breadcrumb', 'visibility', 'seo']);

    function isEditableTopLevelContentKey(key) {
        return !META_KEYS.has(key) && !PAGE_SETTINGS_KEYS.has(key) && !SPECIAL_KEYS.has(key);
    }

    function createEditorShell(container) {
        const shell = document.createElement('div');
        shell.className = 'ce-editor-shell';

        const tabs = document.createElement('div');
        tabs.className = 'ce-editor-tabs';
        const tabDefs = [
            ['content', t('editor.tab_content')],
            ['settings', t('editor.page_settings')],
            ['seo', t('editor.seo')],
            ['access', t('editor.settings_access')]
        ];
        tabDefs.forEach(([id, label], index) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ce-editor-tab' + (index === 0 ? ' active' : '');
            btn.dataset.editorTab = id;
            btn.textContent = label;
            btn.addEventListener('click', () => activateEditorTab(id));
            tabs.appendChild(btn);
        });
        shell.appendChild(tabs);

        const panelsWrap = document.createElement('div');
        panelsWrap.className = 'ce-editor-panels';
        const panels = {};
        tabDefs.forEach(([id], index) => {
            const panel = document.createElement('div');
            panel.className = 'ce-editor-panel' + (index === 0 ? ' active' : '');
            panel.dataset.editorPanel = id;
            panels[id] = panel;
            panelsWrap.appendChild(panel);
        });
        shell.appendChild(panelsWrap);
        container.appendChild(shell);
        return panels;
    }

    function activateEditorTab(tabId) {
        document.querySelectorAll('.ce-editor-tab').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.editorTab === tabId);
        });
        document.querySelectorAll('.ce-editor-panel').forEach(panel => {
            panel.classList.toggle('active', panel.dataset.editorPanel === tabId);
        });
    }

    // Render the page metadata panels (basics, SEO, access, navigation)
    function renderPageSettings(panels) {
        const createSettingsSection = (panel, className, title, subtitle = '') => {
            const section = document.createElement('section');
            section.className = `ce-settings-card ${className}`;
            const header = document.createElement('div');
            header.className = 'ce-settings-card__header';
            header.innerHTML = `<h4>${title}</h4>${subtitle ? `<p>${subtitle}</p>` : ''}`;
            section.appendChild(header);
            const sectionBody = document.createElement('div');
            sectionBody.className = 'ce-settings-card__body';
            section.appendChild(sectionBody);
            panel.appendChild(section);
            return sectionBody;
        };

        const basicsBody = createSettingsSection(panels.settings, 'ce-settings-card--basics', t('editor.settings_basics'));
        const navBody = createSettingsSection(panels.settings, 'ce-settings-card--navigation', t('editor.settings_navigation'));
        const seoBody = createSettingsSection(panels.seo, 'ce-settings-card--seo', t('editor.seo'), t('editor.seo_hint'));
        const accessBody = createSettingsSection(panels.access, 'ce-settings-card--access', t('editor.settings_access'));

        // Title
        const titleField = document.createElement('div');
        titleField.className = 'ce-field';
        titleField.innerHTML = `<label class="ce-field-label">${t('editor.meta_title')}</label>`;
        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'ce-input';
        titleInput.value = currentContent.title || '';
        titleInput.dataset.path = 'title';
        titleInput.addEventListener('input', () => markDirty());
        titleField.appendChild(titleInput);
        basicsBody.appendChild(titleField);

        // Description
        const descField = document.createElement('div');
        descField.className = 'ce-field';
        descField.innerHTML = `<label class="ce-field-label">${t('editor.meta_description')}</label>`;
        const descInput = document.createElement('textarea');
        descInput.className = 'ce-textarea';
        descInput.rows = 2;
        descInput.value = currentContent.description || '';
        descInput.dataset.path = 'description';
        descInput.addEventListener('input', () => markDirty());
        descField.appendChild(descInput);
        basicsBody.appendChild(descField);

        const seo = currentContent.seo || {};
        const seoField = document.createElement('div');
        seoField.className = 'ce-field ce-field--stacked';
        const seoWrap = document.createElement('div');
        seoWrap.className = 'ce-seo-grid';
        seoWrap.innerHTML = `
            ${AI_FEATURES_ENABLED ? `<div class="ce-ai-seo-actions ce-form-tile--wide">
                <button type="button" class="btn btn-secondary btn-sm ce-ai-fill-all" onclick="generateSeoFields('all')">${icon('ai', 14)} ${t('editor.ai_fill_seo')}</button>
                <span class="ce-ai-status" id="seoAiStatus"></span>
            </div>` : ''}
            <label class="ce-form-tile ce-form-tile--wide"><span class="ce-label-with-action">${t('editor.seo_title')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('title')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><input type="text" class="ce-input" id="seoTitle" value="${escapeHtml(seo.title || '')}"></label>
            <label class="ce-form-tile ce-form-tile--wide ce-form-tile--resizable"><span class="ce-label-with-action">${t('editor.seo_description')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('description')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><textarea class="ce-textarea ce-textarea--manual-resize" id="seoDescription" rows="2">${escapeHtml(seo.description || '')}</textarea><span class="ce-textarea-resize-handle" aria-hidden="true"></span></label>
            <label class="ce-form-tile ce-form-tile--wide ce-form-tile--resizable"><span class="ce-label-with-action">${t('editor.seo_answer_summary')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('answerSummary')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><textarea class="ce-textarea ce-textarea--manual-resize" id="seoAnswerSummary" rows="2">${escapeHtml(seo.answerSummary || '')}</textarea><span class="ce-textarea-resize-handle" aria-hidden="true"></span></label>
            <label class="ce-form-tile"><span>${t('editor.seo_canonical')}</span><input type="url" class="ce-input" id="seoCanonical" value="${escapeHtml(seo.canonical || '')}"></label>
            <label class="ce-form-tile"><span>Robots</span><select class="ce-input" id="seoRobots">
                    <option value="index, follow">${t('editor.seo_robots_index')}</option>
                    <option value="noindex, follow">${t('editor.seo_robots_noindex')}</option>
                    <option value="noindex, nofollow">${t('editor.seo_robots_private')}</option>
                </select></label>
            <label class="ce-form-tile"><span class="ce-label-with-action">${t('editor.seo_og_title')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('ogTitle')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><input type="text" class="ce-input" id="seoOgTitle" value="${escapeHtml(seo.ogTitle || '')}"></label>
            <label class="ce-form-tile ce-form-tile--resizable"><span class="ce-label-with-action">${t('editor.seo_og_description')}${AI_FEATURES_ENABLED ? `<button type="button" class="ce-ai-field-btn" onclick="generateSeoFields('ogDescription')" title="${escapeHtml(t('editor.ai_fill_field'))}" aria-label="${escapeHtml(t('editor.ai_fill_field'))}">${icon('ai', 13)}</button>` : ''}</span><textarea class="ce-textarea ce-textarea--manual-resize" id="seoOgDescription" rows="2">${escapeHtml(seo.ogDescription || '')}</textarea><span class="ce-textarea-resize-handle" aria-hidden="true"></span></label>
            <div class="ce-form-tile ce-form-tile--wide">
                <span>${t('editor.seo_og_image')}</span>
                <div class="ce-image-input-row">
                    <input type="text" class="ce-input" id="seoOgImage" placeholder="/assets/images/og-image.jpg" value="${escapeHtml(seo.ogImage || '')}">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="browseSeoOgImage()">${t('btn.browse')}</button>
                </div>
                ${AI_FEATURES_ENABLED ? `<p class="ai-field-hint">${t('ai.image_field_hint')} <button type="button" class="btn btn-secondary btn-sm" onclick="openAiImageGenerator(getSeoOgImagePrompt(), '16:9')">${t('ai.open_image_generator')}</button></p>` : ''}
            </div>
            <label class="ce-nav-check ce-nav-check--standalone"><input type="checkbox" id="seoSitemap"> ${t('editor.seo_sitemap')}</label>
        `;
        seoWrap.querySelector('#seoRobots').value = seo.robots || 'index, follow';
        seoWrap.querySelector('#seoSitemap').checked = seo.sitemap !== false;
        seoWrap.querySelectorAll('input, textarea, select').forEach(inp => inp.addEventListener('input', () => markDirty()));
        seoWrap.querySelectorAll('input[type="checkbox"]').forEach(inp => inp.addEventListener('change', () => markDirty()));
        seoField.appendChild(seoWrap);
        seoBody.appendChild(seoField);
        refreshSeoAiButtons();
        if (AI_FEATURES_ENABLED && !currentAiSettings) {
            loadAiSettings().then(refreshSeoAiButtons).catch(refreshSeoAiButtons);
        }

        // Nav locations
        const navField = document.createElement('div');
        navField.className = 'ce-field ce-field--stacked';
        navField.innerHTML = `<label class="ce-card-field-label">${t('editor.nav_locations')}</label>`;
        const navRow = document.createElement('div');
        navRow.className = 'ce-nav-checkboxes';
        const navLocations = currentContent.nav || ['header'];
        const registeredMenus = window.NB_MENUS || {};
        const menuIds = Object.keys(registeredMenus);
        const customLocations = navLocations.filter(l => !menuIds.includes(l));
        const adminLang = document.getElementById('langSelect')?.value || document.documentElement.lang || 'en';

        menuIds.forEach(menuId => {
            const menu = registeredMenus[menuId];
            const label = document.createElement('label');
            label.className = 'ce-nav-check';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.dataset.navLocation = menuId;
            cb.checked = navLocations.includes(menuId);
            cb.addEventListener('change', () => markDirty());
            label.appendChild(cb);
            const displayName = menu.label[adminLang] || menu.label['en'] || menuId;
            label.appendChild(document.createTextNode(' ' + displayName));
            navRow.appendChild(label);
        });

        navField.appendChild(navRow);

        // Custom locations (add-row pattern)
        const customContainer = document.createElement('div');
        customContainer.id = 'navCustomContainer';
        customContainer.className = 'ce-breadcrumb-editor ce-nav-custom-editor';
        customLocations.forEach(loc => {
            customContainer.appendChild(createNavCustomRow(loc));
        });
        const addNavBtn = document.createElement('button');
        addNavBtn.type = 'button';
        addNavBtn.className = 'btn btn-secondary btn-sm';
        addNavBtn.textContent = '+ ' + t('editor.nav_add_custom');
        addNavBtn.addEventListener('click', () => {
            customContainer.insertBefore(createNavCustomRow(''), addNavBtn);
            markDirty();
        });
        customContainer.appendChild(addNavBtn);
        navField.appendChild(customContainer);
        navBody.appendChild(navField);

        // Breadcrumb editor
        const bcField = document.createElement('div');
        bcField.className = 'ce-field ce-field--stacked';
        bcField.innerHTML = `<label class="ce-card-field-label">${t('editor.breadcrumb')}</label>
            <small class="form-hint ce-card-field-hint">${t('editor.breadcrumb_hint')}</small>`;
        const bcContainer = document.createElement('div');
        bcContainer.id = 'breadcrumbEditor';
        bcContainer.className = 'ce-breadcrumb-editor';

        const crumbs = currentContent.breadcrumb || [];
        crumbs.forEach((crumb, i) => bcContainer.appendChild(createBreadcrumbRow(crumb, i)));

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn btn-secondary btn-sm';
        addBtn.textContent = '+ ' + t('editor.breadcrumb_add');
        addBtn.addEventListener('click', () => {
            bcContainer.insertBefore(createBreadcrumbRow({label: '', href: ''}, bcContainer.children.length), addBtn);
            markDirty();
        });
        bcContainer.appendChild(addBtn);
        bcField.appendChild(bcContainer);
        navBody.appendChild(bcField);

        // Visibility / password protection
        const visibility = currentContent.visibility || {};
        const visField = document.createElement('div');
        visField.className = 'ce-field ce-field--stacked';
        const visWrap = document.createElement('div');
        visWrap.className = 'ce-visibility-grid';
        visWrap.innerHTML = `
            <label class="ce-form-tile"><span>${t('editor.visibility')}</span><select class="ce-input" id="pageVisibilityStatus">
                    <option value="public">${t('editor.visibility_public')}</option>
                    <option value="private">${t('editor.visibility_private')}</option>
                </select></label>
            <label class="ce-form-tile"><span>${t('editor.visibility_password')}</span><input type="password" class="ce-input" id="pageVisibilityPassword"></label>
            <label class="ce-form-tile"><span>${t('editor.visibility_title')}</span><input type="text" class="ce-input" id="pageVisibilityTitle" value="${escapeHtml(visibility.title || '')}"></label>
            <label class="ce-form-tile ce-form-tile--wide"><span>${t('editor.visibility_text')}</span><textarea class="ce-textarea" id="pageVisibilityText" rows="2">${escapeHtml(visibility.text || '')}</textarea></label>
            <small class="form-hint">${visibility.passwordHash ? t('editor.visibility_password_set') : t('editor.visibility_password_hint')}</small>
        `;
        visWrap.querySelector('#pageVisibilityStatus').value = visibility.status || 'public';
        visWrap.querySelectorAll('input, textarea, select').forEach(inp => inp.addEventListener('input', () => markDirty()));
        visField.appendChild(visWrap);
        accessBody.appendChild(visField);

    }

    function seoAiIsUsable() {
        var settings = currentAiSettings || {};
        return !!settings.enabled && !!(settings.features && settings.features.seoTextGeneration) && aiProviderIsConfigured(settings);
    }

    function refreshSeoAiButtons() {
        var usable = seoAiIsUsable();
        document.querySelectorAll('.ce-ai-fill-all, .ce-ai-field-btn').forEach(function(btn) {
            btn.disabled = !usable;
            btn.title = usable ? t('editor.ai_fill_field') : t('ai.not_configured_text');
        });
        document.querySelectorAll('.ai-field-hint').forEach(function(hint) {
            hint.hidden = !dashboardAiImageUsable;
        });
        var status = document.getElementById('seoAiStatus');
        if (status) status.textContent = usable ? '' : t('editor.ai_unavailable');
    }

    function collectSeoAiContext() {
        collectPageSettings();
        var selectedLang = document.getElementById('langSelect')?.value || currentContent.lang || '';
        var selectedSlug = document.getElementById('pageSelect')?.value || currentPage || '';
        return {
            lang: selectedLang,
            slug: selectedSlug,
            title: currentContent.title || '',
            description: currentContent.description || '',
            seo: currentContent.seo || {},
            contentText: extractContentText(currentContent).slice(0, 9000)
        };
    }

    function extractContentText(value) {
        var chunks = [];
        var seen = new WeakSet();
        function walk(node, key) {
            if (node == null) return;
            if (typeof node === 'string') {
                var clean = node.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                if (clean && !/^(src|href|url|image|icon|id|slug|class|style)$/i.test(String(key || ''))) {
                    chunks.push(clean);
                }
                return;
            }
            if (typeof node === 'number' || typeof node === 'boolean') return;
            if (typeof node !== 'object' || seen.has(node)) return;
            seen.add(node);
            if (Array.isArray(node)) {
                node.forEach(function(item) { walk(item, key); });
                return;
            }
            Object.keys(node).forEach(function(childKey) {
                if (['seo', 'nav', 'breadcrumb', 'visibility', 'lastModified'].includes(childKey)) return;
                walk(node[childKey], childKey);
            });
        }
        walk(value, '');
        return chunks.join('\n').slice(0, 12000);
    }

    async function generateSeoFields(field) {
        if (!seoAiIsUsable()) {
            showToast(t('ai.not_configured_text'), 'error');
            refreshSeoAiButtons();
            return;
        }
        var buttons = Array.from(document.querySelectorAll('.ce-ai-fill-all, .ce-ai-field-btn'));
        var status = document.getElementById('seoAiStatus');
        buttons.forEach(function(btn) { btn.disabled = true; });
        if (status) status.textContent = t('editor.ai_generating');
        try {
            var formData = new FormData();
            formData.append('action', 'ai-generate-seo');
            formData.append('field', field || 'all');
            formData.append('context', JSON.stringify(collectSeoAiContext()));
            formData.append('csrf_token', CSRF_TOKEN);
            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();
            if (!result.success) throw new Error(result.message || t('toast.error'));
            applySeoAiFields(result.data && result.data.fields ? result.data.fields : {});
            updateAiUsage(result.data ? result.data.limits : null);
            markDirty();
            if (status) status.textContent = t('editor.ai_done');
        } catch (error) {
            if (status) status.textContent = '';
            showToast(error.message, 'error');
        } finally {
            refreshSeoAiButtons();
        }
    }

    function applySeoAiFields(fields) {
        var map = {
            title: 'seoTitle',
            description: 'seoDescription',
            answerSummary: 'seoAnswerSummary',
            ogTitle: 'seoOgTitle',
            ogDescription: 'seoOgDescription'
        };
        Object.keys(map).forEach(function(key) {
            if (typeof fields[key] !== 'string') return;
            var el = document.getElementById(map[key]);
            if (!el) return;
            el.value = fields[key];
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function createNavCustomRow(value) {
        const row = document.createElement('div');
        row.className = 'ce-breadcrumb-row';
        row.innerHTML = `
            <input type="text" class="ce-input ce-input--sm" placeholder="${t('editor.nav_custom_hint')}" value="${escapeHtml(value)}" data-nav-custom>
            <button type="button" class="btn btn-secondary btn-sm ce-breadcrumb-remove" onclick="this.parentElement.remove(); markDirty();">&times;</button>
        `;
        row.querySelector('input').addEventListener('input', () => markDirty());
        return row;
    }

    function createBreadcrumbRow(crumb, index) {
        const row = document.createElement('div');
        row.className = 'ce-breadcrumb-row';
        row.innerHTML = `
            <input type="text" class="ce-input ce-input--sm" placeholder="${t('editor.breadcrumb_label')}" value="${escapeHtml(crumb.label || '')}" data-bc-label>
            <input type="text" class="ce-input ce-input--sm" placeholder="${t('editor.breadcrumb_href')}" value="${escapeHtml(crumb.href || '')}" data-bc-href>
            <button type="button" class="btn btn-secondary btn-sm ce-breadcrumb-remove" onclick="this.parentElement.remove(); markDirty();">&times;</button>
        `;
        row.querySelectorAll('input').forEach(inp => inp.addEventListener('input', () => markDirty()));
        return row;
    }

    // Collect nav locations and breadcrumb from the page settings panel
    function collectPageSettings() {
        // Nav locations
        const registeredIds = Object.keys(window.NB_MENUS || {});
        const navLocs = [];
        registeredIds.forEach(menuId => {
            const cb = document.querySelector(`[data-nav-location="${menuId}"]`);
            if (cb && cb.checked) navLocs.push(menuId);
        });
        document.querySelectorAll('#navCustomContainer [data-nav-custom]').forEach(input => {
            const loc = input.value.trim();
            if (loc && !navLocs.includes(loc)) navLocs.push(loc);
        });
        currentContent.nav = navLocs;

        // Breadcrumb
        const rows = document.querySelectorAll('#breadcrumbEditor .ce-breadcrumb-row');
        if (rows.length > 0) {
            const crumbs = [];
            rows.forEach(row => {
                const label = row.querySelector('[data-bc-label]')?.value?.trim();
                const href = row.querySelector('[data-bc-href]')?.value?.trim();
                if (label) {
                    const crumb = { label };
                    if (href) crumb.href = href;
                    crumbs.push(crumb);
                }
            });
            if (crumbs.length > 0) {
                currentContent.breadcrumb = crumbs;
            } else {
                delete currentContent.breadcrumb;
            }
        } else {
            delete currentContent.breadcrumb;
        }

        const visStatus = document.getElementById('pageVisibilityStatus')?.value || 'public';
        const visPassword = document.getElementById('pageVisibilityPassword')?.value || '';
        const visTitle = document.getElementById('pageVisibilityTitle')?.value?.trim() || '';
        const visText = document.getElementById('pageVisibilityText')?.value?.trim() || '';
        if (visStatus === 'private') {
            currentContent.visibility = Object.assign({}, currentContent.visibility || {}, {
                status: 'private',
                title: visTitle,
                text: visText
            });
            if (visPassword) currentContent.visibility.password = visPassword;
        } else {
            currentContent.visibility = { status: 'public', title: visTitle, text: visText };
        }

        currentContent.seo = {
            title: document.getElementById('seoTitle')?.value?.trim() || '',
            description: document.getElementById('seoDescription')?.value?.trim() || '',
            answerSummary: document.getElementById('seoAnswerSummary')?.value?.trim() || '',
            canonical: document.getElementById('seoCanonical')?.value?.trim() || '',
            robots: document.getElementById('seoRobots')?.value || 'index, follow',
            ogTitle: document.getElementById('seoOgTitle')?.value?.trim() || '',
            ogDescription: document.getElementById('seoOgDescription')?.value?.trim() || '',
            ogImage: document.getElementById('seoOgImage')?.value?.trim() || '',
            sitemap: document.getElementById('seoSitemap')?.checked !== false
        };
    }

    function getSectionPreview(section, maxLength = 72) {
        const formatPreview = (value) => {
            const clean = value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            if (!maxLength || clean.length <= maxLength) return clean;
            return clean.slice(0, maxLength) + '…';
        };
        if (!section || typeof section !== 'object') return '';
        const preferred = ['title', 'heading', 'headline', 'text', 'content', 'caption', 'src', 'image', 'url'];
        for (const key of preferred) {
            const value = section[key];
            if (typeof value === 'string' && value.trim()) {
                return formatPreview(value);
            }
        }
        for (const value of Object.values(section)) {
            if (typeof value === 'string' && value.trim() && value.length <= 120) {
                return formatPreview(value);
            }
        }
        return '';
    }

    let sectionObserver = null;
    let activeSectionIndex = 0;
    let sectionScrollLockIndex = null;
    let sectionScrollLockTimer = null;
    let sectionDragIndex = null;
    let sectionCompactMode = localStorage.getItem('nibblySectionCompactMode') === '1';

    function getSectionTypeLabel(section) {
        const def = window.BlockTypeRegistry?.[section?.type];
        return def?.label || section?.type || 'Section';
    }

    function getSectionHeading(section, index) {
        const typeLabel = getSectionTypeLabel(section);
        const preview = getSectionPreview(section);
        return preview ? `${index + 1}. ${typeLabel} - ${preview}` : `${index + 1}. ${typeLabel}`;
    }

    function getSectionSearchText(section, index) {
        return [
            index + 1,
            section?.type || '',
            getSectionTypeLabel(section),
            getSectionPreview(section, 0),
            section?.id || ''
        ].join(' ').toLowerCase();
    }

    function getSectionIssues(section, index, sections) {
        const issues = [];
        const def = window.BlockTypeRegistry?.[section?.type];
        if (def?.fields) {
            def.fields.forEach(field => {
                const value = section?.[field.key];
                if ((field.type === 'image' || field.type === 'audio') && (!value || !String(value).trim())) {
                    issues.push(t('editor.missing_media'));
                }
            });
        }
        if (section?.type === 'image' && section.src && !String(section.alt || '').trim()) {
            issues.push(t('editor.missing_alt'));
        }
        const level = String(section?.level || section?.titleTag || '').toLowerCase();
        if (level === 'h1') {
            const h1Before = sections.slice(0, index).some(item => String(item?.level || item?.titleTag || '').toLowerCase() === 'h1');
            if (h1Before) issues.push(t('editor.duplicate_h1'));
        }
        return issues;
    }

    function renderSectionInsertControls(index) {
        const wrap = document.createElement('div');
        wrap.className = 'ce-section-insert';
        wrap.dataset.insertIndex = index;
        let html = `<button type="button" class="ce-section-insert__trigger" onclick="toggleSectionInsertMenu(this)">${t('editor.insert_here')}</button>`;
        html += '<div class="ce-section-insert__menu" hidden>';
        if (window.BlockTypeRegistry) {
            for (const [type, def] of Object.entries(window.BlockTypeRegistry)) {
                html += `<button type="button" onclick="addSection('${type}', ${index})">+ ${escapeHtml(def.label || type)}</button>`;
            }
        }
        html += '</div>';
        wrap.innerHTML = html;
        return wrap;
    }

    function toggleSectionInsertMenu(btn) {
        const menu = btn.parentElement?.querySelector('.ce-section-insert__menu');
        if (!menu) return;
        const nextHidden = !menu.hidden;
        document.querySelectorAll('.ce-section-insert__menu').forEach(el => { el.hidden = true; });
        menu.hidden = nextHidden;
    }

    function getEditorSectionItems() {
        return document.querySelectorAll('[data-editor-section-kind]');
    }

    function applySectionFilter() {
        const query = (document.getElementById('sectionSearchInput')?.value || '').trim().toLowerCase();
        const type = document.getElementById('sectionTypeFilter')?.value || '';
        let visible = 0;
        getEditorSectionItems().forEach(item => {
            const matchesQuery = !query || (item.dataset.search || '').includes(query);
            const matchesType = !type || item.dataset.type === type;
            const show = matchesQuery && matchesType;
            item.hidden = !show;
            const navItem = document.querySelector(`.ce-section-nav__item[data-editor-section-id="${item.dataset.editorSectionId}"]`);
            if (navItem) {
                navItem.hidden = false;
                navItem.classList.toggle('is-filtered-out', !show);
                navItem.setAttribute('aria-disabled', show ? 'false' : 'true');
            }
            if (show) visible++;
        });
        document.querySelectorAll('.ce-section-insert').forEach(el => { el.hidden = !!query || !!type; });
        const empty = document.getElementById('sectionFilterEmpty');
        if (empty) empty.hidden = visible !== 0;
    }

    function setActiveSection(index) {
        const items = Array.from(getEditorSectionItems());
        if (!items.length) return;
        activeSectionIndex = Math.max(0, Math.min(index, items.length - 1));
        items.forEach(item => {
            const isActive = Number(item.dataset.index) === activeSectionIndex;
            item.classList.toggle('is-active', isActive);
            if (sectionCompactMode && isActive) item.classList.add('is-open');
        });
        document.querySelectorAll('.ce-section-nav__item').forEach(item => {
            item.classList.toggle('is-active', Number(item.dataset.sectionIndex) === activeSectionIndex);
        });
    }

    function initSectionObserver() {
        if (sectionObserver) sectionObserver.disconnect();
        const items = getEditorSectionItems();
        if (!items.length) return;
        sectionObserver = new IntersectionObserver(entries => {
            if (sectionScrollLockIndex !== null) {
                setActiveSection(sectionScrollLockIndex);
                return;
            }
            const visible = entries
                .filter(entry => entry.isIntersecting)
                .sort((a, b) => Math.abs(a.boundingClientRect.top - 110) - Math.abs(b.boundingClientRect.top - 110));
            if (visible[0]) setActiveSection(Number(visible[0].target.dataset.index));
        }, { rootMargin: '-110px 0px -55% 0px', threshold: [0, 0.1, 0.35] });
        items.forEach(item => sectionObserver.observe(item));
        setActiveSection(activeSectionIndex);
    }

    function scrollToSection(index) {
        const target = Array.from(getEditorSectionItems()).find(item => Number(item.dataset.index) === index);
        if (!target) return;
        sectionScrollLockIndex = index;
        if (sectionScrollLockTimer) clearTimeout(sectionScrollLockTimer);
        sectionScrollLockTimer = setTimeout(() => {
            sectionScrollLockIndex = null;
            sectionScrollLockTimer = null;
        }, 800);
        setActiveSection(index);
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function toggleCompactMode(force) {
        sectionCompactMode = typeof force === 'boolean' ? force : !sectionCompactMode;
        localStorage.setItem('nibblySectionCompactMode', sectionCompactMode ? '1' : '0');
        document.querySelectorAll('.ce-content-layout').forEach(layout => {
            layout.classList.toggle('ce-content-layout--compact', sectionCompactMode);
        });
        const btn = document.getElementById('sectionCompactToggle');
        if (btn) {
            btn.classList.toggle('active', sectionCompactMode);
            btn.setAttribute('aria-pressed', sectionCompactMode ? 'true' : 'false');
        }
        if (sectionCompactMode) {
            document.querySelectorAll('.section-item').forEach(item => {
                item.classList.toggle('is-open', Number(item.dataset.index) === activeSectionIndex);
                const sectionBtn = item.querySelector('.section-toggle-btn');
                if (sectionBtn) {
                    const open = item.classList.contains('is-open');
                    sectionBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    sectionBtn.title = open ? t('editor.collapse_section') : t('editor.expand_section');
                }
            });
        } else {
            document.querySelectorAll('.section-item').forEach(item => item.classList.add('is-open'));
        }
    }

    function toggleSectionOpen(index) {
        const item = document.getElementById(`section-${index}`);
        if (!item) return;
        item.classList.toggle('is-open');
        const btn = item.querySelector('.section-toggle-btn');
        if (btn) {
            const open = item.classList.contains('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.title = open ? t('editor.collapse_section') : t('editor.expand_section');
        }
    }

    function reorderSection(fromIndex, toIndex) {
        if (!currentContent?.sections || fromIndex === toIndex) return;
        if (toIndex < 0 || toIndex >= currentContent.sections.length) return;
        pushUndoSnapshot();
        const openTypes = sectionCompactMode
            ? Array.from(document.querySelectorAll('.section-item.is-open')).map(item => Number(item.dataset.index))
            : [];
        const [moved] = currentContent.sections.splice(fromIndex, 1);
        currentContent.sections.splice(toIndex, 0, moved);
        renderEditor();
        if (sectionCompactMode) {
            openTypes.forEach(oldIndex => {
                let next = oldIndex;
                if (oldIndex === fromIndex) next = toIndex;
                else if (fromIndex < toIndex && oldIndex > fromIndex && oldIndex <= toIndex) next = oldIndex - 1;
                else if (fromIndex > toIndex && oldIndex >= toIndex && oldIndex < fromIndex) next = oldIndex + 1;
                document.getElementById(`section-${next}`)?.classList.add('is-open');
            });
        }
        setActiveSection(toIndex);
        markDirty();
    }

    function getGroupSearchText(key, value) {
        const parts = [key];
        const seen = new WeakSet();
        function walk(node) {
            if (node == null) return;
            if (typeof node === 'string' || typeof node === 'number' || typeof node === 'boolean') {
                parts.push(String(node));
                return;
            }
            if (typeof node !== 'object' || seen.has(node)) return;
            seen.add(node);
            if (Array.isArray(node)) {
                node.slice(0, 20).forEach(walk);
                return;
            }
            Object.entries(node).slice(0, 30).forEach(([childKey, childValue]) => {
                parts.push(childKey);
                walk(childValue);
            });
        }
        walk(value);
        return parts.join(' ').toLowerCase();
    }

    function getGroupPreview(value) {
        if (value === null || typeof value !== 'object') return '';
        for (const key of ['title', 'heading', 'eyebrow', 'intro', 'text']) {
            if (typeof value[key] === 'string' && value[key].trim()) {
                return value[key].trim().slice(0, 160);
            }
        }
        return getObjectPreview(value);
    }

    function createSectionNavLink({index, id, label, preview, type, draggable = false, onClick, onDragStart, onDrop}) {
        const link = document.createElement('a');
        link.href = `#${id}`;
        link.className = 'ce-section-nav__item';
        link.dataset.sectionIndex = index;
        link.dataset.editorSectionId = id;
        link.dataset.type = type || '';
        if (draggable) link.setAttribute('draggable', 'true');
        link.innerHTML = `<span>${index + 1}. ${escapeHtml(label)}</span>${preview ? `<small title="${escapeHtml(preview)}">${escapeHtml(preview)}</small>` : ''}`;
        link.addEventListener('click', (e) => {
            e.preventDefault();
            if (link.classList.contains('is-filtered-out')) return;
            if (typeof onClick === 'function') onClick(e);
            else scrollToSection(index);
        });
        if (draggable) {
            link.addEventListener('dragstart', e => {
                if (typeof onDragStart === 'function') onDragStart(e);
                link.classList.add('is-dragging');
            });
            link.addEventListener('dragend', () => {
                sectionDragIndex = null;
                document.querySelectorAll('.is-dragging, .is-drop-target').forEach(el => el.classList.remove('is-dragging', 'is-drop-target'));
            });
            link.addEventListener('dragover', e => {
                e.preventDefault();
                link.classList.add('is-drop-target');
            });
            link.addEventListener('dragleave', () => link.classList.remove('is-drop-target'));
            link.addEventListener('drop', e => {
                e.preventDefault();
                link.classList.remove('is-drop-target');
                if (typeof onDrop === 'function') onDrop(e);
            });
        }
        return link;
    }

    function renderContentPanel(panel, customContentKeys = []) {
        panel.innerHTML = '';
        const sections = Array.isArray(currentContent.sections) ? currentContent.sections : [];
        const customGroups = customContentKeys.map(key => ({
            key,
            value: currentContent[key],
            isNavigable: currentContent[key] !== null && typeof currentContent[key] === 'object'
        }));
        const navigableGroups = customGroups.filter(group => group.isNavigable);
        const hasLegacySections = sections.length > 0;
        const itemCount = hasLegacySections ? sections.length : navigableGroups.length;

        const layout = document.createElement('div');
        layout.className = 'ce-content-layout';
        layout.classList.toggle('ce-content-layout--compact', sectionCompactMode);

        const nav = document.createElement('aside');
        nav.className = 'ce-section-nav';
        nav.innerHTML = `<div class="ce-section-nav__title">${t('editor.sections')}</div>
            <div class="ce-section-nav__tools">
                <input type="search" id="sectionSearchInput" class="ce-section-nav__search" placeholder="${t('editor.search_sections')}">
                <select id="sectionTypeFilter" class="ce-section-nav__select">
                    <option value="">${t('editor.filter_all_types')}</option>
                </select>
            </div>`;
        const typeFilter = nav.querySelector('#sectionTypeFilter');
        const seenTypes = new Set();
        sections.forEach(section => {
            if (!section?.type || seenTypes.has(section.type)) return;
            seenTypes.add(section.type);
            const option = document.createElement('option');
            option.value = section.type;
            option.textContent = getSectionTypeLabel(section);
            typeFilter.appendChild(option);
        });
        if (!hasLegacySections && navigableGroups.length) {
            const option = document.createElement('option');
            option.value = 'field-group';
            option.textContent = t('editor.field_groups');
            typeFilter.appendChild(option);
        }
        const navList = document.createElement('div');
        navList.className = 'ce-section-nav__list';
        nav.appendChild(navList);
        const navEmpty = document.createElement('p');
        navEmpty.id = 'sectionFilterEmpty';
        navEmpty.className = 'ce-section-nav__empty';
        navEmpty.hidden = true;
        navEmpty.textContent = t('editor.no_section_matches');
        nav.appendChild(navEmpty);

        const editor = document.createElement('div');
        editor.className = 'ce-section-editor';
        const header = document.createElement('div');
        header.className = 'ce-section-editor__header';
        header.innerHTML = `<h3>${t('editor.sections')}</h3>
            <div class="ce-section-editor__tools">
                <span>${hasLegacySections ? t('editor.items', {count: itemCount}) : t('editor.field_groups_count', {count: itemCount})}</span>
                ${hasLegacySections ? `<button type="button" class="btn btn-secondary btn-sm" id="sectionCompactToggle" aria-pressed="${sectionCompactMode ? 'true' : 'false'}" onclick="toggleCompactMode()">${t('editor.compact_mode')}</button>` : ''}
            </div>`;
        editor.appendChild(header);

        const legacyContainer = document.createElement('div');
        legacyContainer.id = 'sectionsLegacyContainer';
        legacyContainer.className = 'ce-section-list';
        editor.appendChild(legacyContainer);

        if (sections.length > 0) {
            legacyContainer.appendChild(renderSectionInsertControls(0));
            sections.forEach((section, index) => {
                const issues = getSectionIssues(section, index, sections);
                addSectionElement(section, index, legacyContainer);
                legacyContainer.appendChild(renderSectionInsertControls(index + 1));
                const typeLabel = getSectionTypeLabel(section);
                const preview = getSectionPreview(section, 240);
                const link = createSectionNavLink({
                    index,
                    id: `section-${index}`,
                    label: typeLabel,
                    preview,
                    type: section.type || '',
                    draggable: true,
                    onDragStart: e => {
                        sectionDragIndex = index;
                        e.dataTransfer.effectAllowed = 'move';
                    },
                    onDrop: () => reorderSection(sectionDragIndex, index)
                });
                if (issues.length) {
                    link.insertAdjacentHTML('beforeend', `<em title="${escapeHtml(issues.join('\n'))}">${issues.length}</em>`);
                }
                navList.appendChild(link);
            });
        } else if (navigableGroups.length > 0) {
            legacyContainer.hidden = true;
            navigableGroups.forEach((group, index) => {
                navList.appendChild(createSectionNavLink({
                    index,
                    id: `custom-section-${group.key}`,
                    label: group.key,
                    preview: getGroupPreview(group.value),
                    type: 'field-group'
                }));
            });
        } else {
            navList.innerHTML = `<p class="ce-section-nav__empty">${t('editor.no_sections')}</p>`;
            legacyContainer.hidden = true;
        }

        if (hasLegacySections || customGroups.length === 0) {
            const addBtns = document.createElement('div');
            addBtns.className = 'add-section-container';
            let addBtnsHtml = '<p>' + t('editor.add_section') + '</p><div class="add-section-buttons">';
            if (window.BlockTypeRegistry) {
                for (const [type, def] of Object.entries(window.BlockTypeRegistry)) {
                    addBtnsHtml += `<button class="btn btn-secondary btn-sm" onclick="addSection('${type}')">+ ${def.label}</button>`;
                }
            }
            addBtnsHtml += '</div>';
            addBtns.innerHTML = addBtnsHtml;
            editor.appendChild(addBtns);
        }

        customGroups.forEach(group => {
            const groupEl = renderJsonGroup(group.key, group.value, group.key);
            if (group.isNavigable) {
                const groupIndex = navigableGroups.findIndex(item => item.key === group.key);
                groupEl.id = `custom-section-${group.key}`;
                groupEl.classList.add('ce-custom-section');
                groupEl.dataset.index = groupIndex;
                groupEl.dataset.type = 'field-group';
                groupEl.dataset.search = getGroupSearchText(group.key, group.value);
                groupEl.dataset.editorSectionId = groupEl.id;
                groupEl.dataset.editorSectionKind = 'field-group';
            }
            editor.appendChild(groupEl);
        });

        layout.appendChild(nav);
        layout.appendChild(editor);
        panel.appendChild(layout);
        nav.querySelector('#sectionSearchInput')?.addEventListener('input', applySectionFilter);
        nav.querySelector('#sectionTypeFilter')?.addEventListener('change', applySectionFilter);
        if (hasLegacySections) toggleCompactMode(sectionCompactMode);
        initSectionObserver();
    }

    // Render editor — generic JSON-to-form
    function renderEditor() {
        const container = document.getElementById('sectionsContainer');
        container.innerHTML = '';
        sectionCounter = 0;

        const lang = document.getElementById('langSelect').value;
        const page = document.getElementById('pageSelect').value;
        const pageData = pageListCache?.pages?.find(p => p.slug === page);
        document.getElementById('editorTitle').textContent = pageData?.languages?.[lang]?.title || pageData?.title || page;
        updateEditorSeoHealth(currentSeoHealth || pageData?.languages?.[lang]?.seoHealth || null);

        if (currentContent.lastModified) {
            document.getElementById('lastModified').textContent =
                t('editor.last_saved', {date: formatDateShort(currentContent.lastModified)});
        } else {
            document.getElementById('lastModified').textContent = t('editor.not_saved_yet');
        }

        // Render meta info (read-only)
        const metaDiv = document.createElement('div');
        metaDiv.className = 'ce-meta';
        metaDiv.innerHTML = `<span class="ce-meta-item"><strong>${t('editor.meta_page')}</strong> ${escapeHtml(currentContent.page || currentPage)}</span>
            <span class="ce-meta-item"><strong>${t('editor.meta_lang')}</strong> ${escapeHtml(currentContent.lang || lang)}</span>`;
        container.appendChild(metaDiv);

        const panels = createEditorShell(container);
        renderPageSettings(panels);
        const customContentKeys = Object.keys(currentContent).filter(isEditableTopLevelContentKey);
        renderContentPanel(panels.content, customContentKeys);

        // Auto-resize all textareas
        container.querySelectorAll('textarea.ce-textarea:not(.ce-textarea--manual-resize)').forEach(autoResizeTextarea);
        initManualTextareaResize(container);
    }

    // Save/restore open state of groups across re-renders
    function getOpenGroupPaths() {
        const open = new Set();
        document.querySelectorAll('.ce-group--open[data-group-path], .ce-array-item[data-group-path]').forEach(el => {
            // For array items, check if their body is visible
            if (el.classList.contains('ce-array-item')) {
                const body = el.querySelector('.ce-array-item-body');
                if (body && body.style.display !== 'none') open.add(el.dataset.groupPath);
            } else {
                open.add(el.dataset.groupPath);
            }
        });
        return open;
    }

    function restoreOpenGroupPaths(openPaths) {
        if (!openPaths || !openPaths.size) return;
        // Restore groups
        document.querySelectorAll('.ce-group[data-group-path]').forEach(el => {
            if (openPaths.has(el.dataset.groupPath)) {
                const header = el.querySelector('.ce-group-header');
                if (header) toggleGroup(header);
            }
        });
        // Restore array items
        document.querySelectorAll('.ce-array-item[data-group-path]').forEach(el => {
            if (openPaths.has(el.dataset.groupPath)) {
                const header = el.querySelector('.ce-array-item-header');
                if (header) toggleArrayItemBody(header);
            }
        });
    }

    // Toggle a collapsible group
    function toggleGroup(header) {
        const body = header.nextElementSibling;
        const chevron = header.querySelector('.ce-chevron');
        const isOpen = body.style.display !== 'none';
        body.style.display = isOpen ? 'none' : 'block';
        chevron.textContent = isOpen ? '▶' : '▼';
        header.parentElement.classList.toggle('ce-group--open', !isOpen);
    }

    // Toggle all groups open/closed
    let allExpanded = false;
    function toggleAllGroups() {
        allExpanded = !allExpanded;
        const btn = document.getElementById('toggleAllBtn');
        btn.textContent = allExpanded ? t('editor.collapse_all') : t('editor.expand_all');

        document.querySelectorAll('#sectionsContainer .ce-group-header').forEach(header => {
            const body = header.nextElementSibling;
            if (!body) return;
            const chevron = header.querySelector('.ce-chevron');
            body.style.display = allExpanded ? 'block' : 'none';
            if (chevron) chevron.textContent = allExpanded ? '▼' : '▶';
            header.parentElement.classList.toggle('ce-group--open', allExpanded);
        });

        // Also toggle array item bodies
        document.querySelectorAll('#sectionsContainer .ce-array-item-header').forEach(header => {
            const body = header.nextElementSibling;
            if (!body || !body.classList.contains('ce-array-item-body')) return;
            const chevron = header.querySelector('.ce-chevron');
            body.style.display = allExpanded ? 'block' : 'none';
            if (chevron) chevron.textContent = allExpanded ? '▼' : '▶';
        });
    }

    // Render a top-level or nested group
    function renderJsonGroup(key, value, path) {
        const div = document.createElement('div');
        div.className = 'ce-group';
        div.dataset.groupPath = path;

        const isArray = Array.isArray(value);
        const isObject = value !== null && typeof value === 'object' && !isArray;
        let countLabel = '';
        if (isArray) countLabel = `<span class="ce-group-count">${t('editor.items', {count: value.length})}</span>`;
        else if (isObject) countLabel = `<span class="ce-group-count">${t('editor.fields', {count: Object.keys(value).length})}</span>`;

        div.innerHTML = `<div class="ce-group-header" onclick="toggleGroup(this)">
            <span class="ce-chevron">▶</span>
            <span class="ce-group-title">${escapeHtml(key)}</span>
            ${countLabel}
        </div>
        <div class="ce-group-body" style="display:none;"></div>`;

        const body = div.querySelector('.ce-group-body');

        if (isArray) {
            renderArrayField(body, value, path);
        } else if (isObject) {
            renderObjectFields(body, value, path);
        } else {
            // Primitive at top level (rare)
            body.appendChild(renderPrimitiveField(key, value, path));
        }

        return div;
    }

    // Render object fields (key-value pairs)
    function renderObjectFields(container, obj, basePath) {
        for (const [k, v] of Object.entries(obj)) {
            if (v !== null && typeof v === 'object') {
                // Nested object or array — render as sub-group
                const subGroup = renderJsonGroup(k, v, basePath + '.' + k);
                subGroup.classList.add('ce-group--nested');
                container.appendChild(subGroup);
            } else {
                container.appendChild(renderPrimitiveField(k, v, basePath + '.' + k));
            }
        }
    }

    // Render an array field with add/remove/reorder
    function renderArrayField(container, arr, basePath) {
        const list = document.createElement('div');
        list.className = 'ce-array-list';
        list.dataset.path = basePath;

        arr.forEach((item, index) => {
            const itemEl = renderArrayItem(item, index, basePath, arr.length);
            list.appendChild(itemEl);
        });

        container.appendChild(list);

        // Add button
        const addBtn = document.createElement('button');
        addBtn.className = 'btn btn-secondary btn-sm ce-array-add';
        addBtn.textContent = t('editor.add_item');
        addBtn.onclick = function() { addArrayItem(basePath); };
        container.appendChild(addBtn);
    }

    // Render a single array item
    function renderArrayItem(item, index, basePath, totalCount) {
        const div = document.createElement('div');
        div.className = 'ce-array-item';
        div.dataset.index = index;
        div.dataset.groupPath = basePath + '.' + index;

        const isObject = item !== null && typeof item === 'object' && !Array.isArray(item);

        // Header with controls
        const header = document.createElement('div');
        header.className = 'ce-array-item-header';

        if (isObject) {
            // Show a preview of the first string value
            const preview = getObjectPreview(item);
            header.innerHTML = `<span class="ce-chevron" style="cursor:pointer;" onclick="toggleArrayItemBody(this.closest('.ce-array-item-header'))">▶</span>
                <span class="ce-array-item-label" onclick="toggleArrayItemBody(this.closest('.ce-array-item-header'))">${index} — <span class="ce-preview-text">${escapeHtml(preview)}</span></span>`;
        } else {
            header.innerHTML = `<span class="ce-array-item-label">${index}</span>`;
        }

        // Action buttons
        const actions = document.createElement('div');
        actions.className = 'ce-array-item-actions';
        actions.innerHTML = `<button class="btn btn-sm btn-secondary" onclick="moveArrayItem('${basePath}', ${index}, -1)" ${index === 0 ? 'disabled' : ''}>↑</button>
            <button class="btn btn-sm btn-secondary" onclick="moveArrayItem('${basePath}', ${index}, 1)" ${index === totalCount - 1 ? 'disabled' : ''}>↓</button>
            <button class="btn btn-sm btn-danger" onclick="removeArrayItem('${basePath}', ${index})">${icon('trash', 14)}</button>`;
        header.appendChild(actions);
        div.appendChild(header);

        if (isObject) {
            // Collapsible body
            const body = document.createElement('div');
            body.className = 'ce-array-item-body';
            body.style.display = 'none';
            renderObjectFields(body, item, basePath + '.' + index);
            div.appendChild(body);
        } else {
            // Inline primitive
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'ce-input';
            input.value = item != null ? String(item) : '';
            input.dataset.path = basePath + '.' + index;
            input.addEventListener('input', function() { markDirty(); });
            div.appendChild(input);
        }

        return div;
    }

    // Toggle array item body (object items)
    function toggleArrayItemBody(header) {
        const body = header.nextElementSibling;
        if (!body || !body.classList.contains('ce-array-item-body')) return;
        const chevron = header.querySelector('.ce-chevron');
        const isOpen = body.style.display !== 'none';
        body.style.display = isOpen ? 'none' : 'block';
        if (chevron) chevron.textContent = isOpen ? '▶' : '▼';
    }

    // Get a preview string from an object (first short string value)
    function getObjectPreview(obj) {
        for (const v of Object.values(obj)) {
            if (typeof v === 'string' && v.length > 0 && v.length <= 80) {
                return v.length > 50 ? v.substring(0, 50) + '…' : v;
            }
        }
        return '';
    }

    // Render a primitive field (string, number, boolean)
    function renderPrimitiveField(label, value, path) {
        const row = document.createElement('div');
        row.className = 'ce-field';

        const labelEl = document.createElement('label');
        labelEl.className = 'ce-field-label';
        labelEl.textContent = label;
        row.appendChild(labelEl);

        if (typeof value === 'boolean') {
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'ce-checkbox';
            cb.checked = value;
            cb.dataset.path = path;
            cb.dataset.type = 'boolean';
            cb.addEventListener('change', function() { markDirty(); });
            row.appendChild(cb);
        } else if (typeof value === 'number') {
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'ce-input';
            input.value = value;
            input.dataset.path = path;
            input.dataset.type = 'number';
            input.addEventListener('input', function() { markDirty(); });
            row.appendChild(input);
        } else {
            // String
            const strVal = value != null ? String(value) : '';
            const keyParts = path.split('.');
            const fieldName = keyParts[keyParts.length - 1];
            const isImage = /\.(jpg|jpeg|png|webp|svg|gif)(\?.*)?$/i.test(strVal)
                || /^(src|image|logo|icon|avatar|photo|thumbnail|cover|hero|poster|og_image)$/i.test(fieldName);
            const isLong = strVal.length > 80 || strVal.includes('\n');

            if (isImage) {
                const preview = document.createElement('div');
                preview.className = 'ce-image-preview';
                preview.innerHTML = `<img src="${escapeHtml(strVal.startsWith('/') ? '..' + strVal : strVal)}" alt="preview" onerror="this.style.display='none'">`;
                row.appendChild(preview);

                // Image field: input + browse button in a row
                const inputRow = document.createElement('div');
                inputRow.className = 'ce-image-input-row';
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'ce-input';
                input.value = strVal;
                input.dataset.path = path;
                input.addEventListener('input', function() {
                    markDirty();
                    // Update preview
                    const img = preview.querySelector('img');
                    if (img) {
                        const v = input.value;
                        img.src = v.startsWith('/') ? '..' + v : v;
                        img.style.display = '';
                    }
                });
                inputRow.appendChild(input);

                const browseBtn = document.createElement('button');
                browseBtn.type = 'button';
                browseBtn.className = 'btn btn-secondary btn-sm';
                browseBtn.textContent = t('btn.browse');
                browseBtn.addEventListener('click', function() {
                    browseImageForField(input, preview);
                });
                inputRow.appendChild(browseBtn);
                row.appendChild(inputRow);
            } else if (isLong) {
                const ta = document.createElement('textarea');
                ta.className = 'ce-textarea';
                ta.value = strVal;
                ta.dataset.path = path;
                ta.addEventListener('input', function() {
                    markDirty();
                    autoResizeTextarea(ta);
                });
                row.appendChild(ta);
            } else {
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'ce-input';
                input.value = strVal;
                input.dataset.path = path;
                input.addEventListener('input', function() { markDirty(); });
                row.appendChild(input);
            }
        }

        return row;
    }

    // Auto-resize textarea
    function autoResizeTextarea(ta) {
        ta.style.height = 'auto';
        ta.style.height = Math.max(60, ta.scrollHeight + 2) + 'px';
    }

    function initManualTextareaResize(container) {
        container.querySelectorAll('.ce-form-tile--resizable').forEach(tile => {
            const textarea = tile.querySelector('textarea.ce-textarea--manual-resize');
            const handle = tile.querySelector('.ce-textarea-resize-handle');
            if (!textarea || !handle || handle.dataset.resizeReady === '1') return;
            handle.dataset.resizeReady = '1';
            handle.addEventListener('pointerdown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const startY = e.clientY;
                const startHeight = textarea.getBoundingClientRect().height;
                const minHeight = 72;
                const maxHeight = Math.max(260, Math.round(window.innerHeight * 0.55));

                function move(ev) {
                    const nextHeight = Math.max(minHeight, Math.min(maxHeight, startHeight + ev.clientY - startY));
                    textarea.style.height = nextHeight + 'px';
                }

                function stop() {
                    document.removeEventListener('pointermove', move);
                    document.removeEventListener('pointerup', stop);
                    document.body.classList.remove('ce-resizing-textarea');
                }

                document.body.classList.add('ce-resizing-textarea');
                document.addEventListener('pointermove', move);
                document.addEventListener('pointerup', stop);
            });
        });
    }
