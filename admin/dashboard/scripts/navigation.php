<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    const VALID_DASHBOARD_TABS = <?php echo json_encode($validDashboardTabs, JSON_UNESCAPED_UNICODE); ?>;
    const DASHBOARD_HASH_ALIASES = { dashboard: 'home', ai: 'home', pages: 'content', messages: 'mails' };
    let dashboardRouteApplying = false;
    let settingsLoaded = false;
    let currentSettings = null;
    let currentAiSettings = null;
    let aiChatMessages = [];
    let dashboardAiImageUsable = false;
    let currentDashboardAnalyticsRange = { period: 'days', count: 30 };
    let formsAdminLoaded = false;

    function dashboardHashFor(tab, subtab) {
        const publicTab = tab === 'home' ? 'dashboard' : (tab === 'content' ? 'pages' : (tab === 'mails' ? 'messages' : tab));
        return '#' + publicTab + (subtab ? '/' + subtab : '');
    }

    function parseDashboardHash() {
        const raw = (window.location.hash || '').replace(/^#/, '');
        if (!raw) return { tab: 'home', subtab: '' };
        const parts = raw.split('/').filter(Boolean);
        const first = parts[0] || 'home';
        const tab = DASHBOARD_HASH_ALIASES[first] || first;
        return {
            tab: VALID_DASHBOARD_TABS.indexOf(tab) !== -1 ? tab : 'home',
            subtab: parts[1] || ''
        };
    }

    function updateDashboardHash(tab, subtab, replace) {
        if (dashboardRouteApplying) return;
        const next = DASHBOARD_PATH + dashboardHashFor(tab, subtab);
        if (window.location.pathname + window.location.hash === next) return;
        history[replace ? 'replaceState' : 'pushState']({ view: 'dashboard', tab: tab, subtab: subtab || '' }, '', next);
    }

    async function applyDashboardRoute(replace) {
        dashboardRouteApplying = true;
        const params = new URLSearchParams(window.location.search);
        const pageParam = params.get('page');
        const tabParam = params.get('tab');
        const postParam = params.get('post');
        const route = parseDashboardHash();
        const hashParts = (window.location.hash || '').replace(/^#/, '').split('/').filter(Boolean);
        const needsPageList = hashParts[0] === 'page'
            || !!pageParam
            || tabParam === 'news'
            || route.tab === 'content';

        if (needsPageList) {
            try {
                const response = await fetch('api.php?action=list-pages&_=' + Date.now());
                const result = await response.json();
                if (result.success) {
                    applyPageList(result.data);
                }
            } catch (e) {
                console.error('Error loading page list:', e);
            }
        }

        let canonicalTab = route.tab || 'home';
        let canonicalSubtab = route.subtab || '';
        let canonicalUrl = null;

        if (hashParts[0] === 'page' && hashParts[1] && hashParts[1].includes('_')) {
            const page = hashParts[1];
            const lang = page.substring(0, page.indexOf('_'));
            const slug = page.substring(page.indexOf('_') + 1);
            switchTab('content', { replace: true });
            canonicalTab = 'content';
            canonicalSubtab = '';
            const langSelect = document.getElementById('langSelect');
            if (langSelect) {
                langSelect.value = lang;
                updatePageSelect();
                const pageSelect = document.getElementById('pageSelect');
                if (pageSelect) {
                    pageSelect.value = slug;
                    await loadContent(false);
                }
            }
            canonicalUrl = DASHBOARD_PATH + '#page/' + page;
        } else if (route.tab !== 'home' || route.subtab) {
            switchTab(route.tab, { settingsTab: route.subtab, replace: !!replace });
            canonicalTab = route.tab;
            canonicalSubtab = route.subtab;
        } else if (tabParam === 'news' && isDashboardModuleEnabled('news')) {
            switchTab('news');
            canonicalTab = 'news';
            canonicalSubtab = '';
            if (postParam) {
                // Wait for news to load, then open the post editor
                await loadNews();
                newsLoaded = true;
                editPost(postParam);
            }
        } else if (pageParam && pageParam.includes('_')) {
            const lang = pageParam.substring(0, pageParam.indexOf('_'));
            const slug = pageParam.substring(pageParam.indexOf('_') + 1);
            switchTab('content', { replace: true });
            canonicalTab = 'content';
            canonicalSubtab = '';
            const langSelect = document.getElementById('langSelect');
            if (langSelect) {
                langSelect.value = lang;
                updatePageSelect();
                const pageSelect = document.getElementById('pageSelect');
                if (pageSelect) {
                    pageSelect.value = slug;
                    await loadContent(false);
                }
            }
            canonicalUrl = DASHBOARD_PATH + '#page/' + pageParam;
        } else {
            switchTab('home', { replace: !!replace });
            canonicalTab = 'home';
            canonicalSubtab = '';
        }
        dashboardRouteApplying = false;
        if (canonicalUrl) {
            history.replaceState({ view: 'editor', page: hashParts[1] || pageParam }, '', canonicalUrl);
        } else if (window.location.pathname.endsWith('/dashboard.php') || replace) {
            updateDashboardHash(canonicalTab, canonicalSubtab, true);
        }
    }

    // Auto-load page/hash route, otherwise show page list.
    applyDashboardRoute(true);

    // Browser back/forward navigation
    window.addEventListener('popstate', async (e) => {
        if (e.state && e.state.view === 'editor' && e.state.page) {
            const lang = e.state.page.substring(0, e.state.page.indexOf('_'));
            const slug = e.state.page.substring(e.state.page.indexOf('_') + 1);
            document.getElementById('langSelect').value = lang;
            updatePageSelect();
            document.getElementById('pageSelect').value = slug;
            loadContent(false);
        } else {
            await applyDashboardRoute(true);
        }
    });

    window.addEventListener('hashchange', function() {
        applyDashboardRoute(true);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal();
            closeMailDetail();
        }
    });

    // ============================================================
    // TAB NAVIGATION
    // ============================================================

    function dismissFrontendEditBanner() {
        const banner = document.getElementById('frontendEditBanner');
        if (banner) banner.remove();
    }

    function switchTab(tab, options) {
        options = options || {};
        if (!isDashboardModuleEnabled(tab)) {
            tab = 'home';
            options = Object.assign({}, options, { replace: true });
        }
        if (tab !== 'content') {
            dismissFrontendEditBanner();
        }

        const systemTab = document.getElementById('systemTab');
        if (systemTab) systemTab.style.display = tab === 'system' ? 'block' : 'none';
        if (tab === 'system') loadSystemStatus();
        document.getElementById('homeTab').style.display = tab === 'home' ? 'block' : 'none';
        document.getElementById('contentTab').style.display = tab === 'content' ? 'block' : 'none';
        document.getElementById('newsTab').style.display = tab === 'news' ? 'block' : 'none';
        document.getElementById('eventsTab').style.display = tab === 'events' ? 'block' : 'none';
        document.getElementById('mailsTab').style.display = tab === 'mails' ? 'block' : 'none';
        const mediaTab = document.getElementById('mediaTab');
        if (mediaTab) mediaTab.style.display = tab === 'media' ? 'block' : 'none';
        const iconsTab = document.getElementById('iconsTab');
        if (iconsTab) iconsTab.style.display = tab === 'icons' ? 'block' : 'none';
        document.getElementById('settingsTab').style.display = tab === 'settings' ? 'block' : 'none';
        document.getElementById('backupTab').style.display = tab === 'backup' ? 'block' : 'none';

        // Show/hide topbar selectors — only when editing a page (not on page list)
        const topbarSelectors = document.getElementById('topbarSelectors');
        if (topbarSelectors) {
            if (tab === 'content' && currentPage) {
                topbarSelectors.style.display = 'flex';
            } else {
                topbarSelectors.style.display = 'none';
            }
        }

        // When switching to content tab, always show page list
        if (tab === 'content') {
            showPageList();
        }

        // Update sidebar active state
        document.querySelectorAll('.sidebar-nav-item[data-tab]').forEach(btn => btn.classList.remove('active'));
        const activeNavItem = document.querySelector(`.sidebar-nav-item[data-tab="${tab}"]`);
        if (activeNavItem) activeNavItem.classList.add('active');

        // Update topbar title
        const titles = { system: t('system.title'), home: t('dashboard_home.title'), content: currentPage ? t('editor.title') : t('pages.title'), news: t('news.title'), mails: t('mails.title'), events: t('events.title'), media: t('nav.media_library'), icons: t('icons.title'), settings: t('settings.title'), backup: t('settings.backup') };
        const topbarTitle = document.getElementById('topbarTitle');
        if (topbarTitle) topbarTitle.textContent = titles[tab] || 'Dashboard';

        // Close sidebar on mobile after tab switch
        document.getElementById('adminSidebar').classList.remove('open');

        if (tab === 'mails') {
            // Always start on the list view when switching into Mails
            const mailsList = document.getElementById('mailsListView');
            const mailDetail = document.getElementById('mailDetailView');
            if (mailsList) mailsList.style.display = '';
            if (mailDetail) mailDetail.style.display = 'none';
            loadMails();
        }
        if (tab === 'media' && window.NbImageManager) {
            initDashboardImageManager();
            const mount = document.getElementById('mediaLibraryMount');
            if (mount) {
                NbImageManager.mount(mount, { types: ['image', 'audio', 'video', 'document'] });
            }
        }
        if (tab === 'home') {
            loadDashboardOverview();
        }
        if (tab === 'news') {
            showNewsList();
            if (!newsLoaded) {
                newsLoaded = true;
                loadNews();
            }
        }
        if (tab === 'events') {
            // Always start on the list view when switching into Events
            const listView = document.getElementById('eventsListView');
            const editorView = document.getElementById('eventsEditorView');
            const trashView = document.getElementById('eventsTrashView');
            if (listView) listView.style.display = '';
            if (editorView) editorView.style.display = 'none';
            if (trashView) trashView.style.display = 'none';
            currentEventIndex = null;
            if (!eventsLoaded) {
                eventsLoaded = true;
                loadEventsEditor();
            }
        }
        if (tab === 'icons' && typeof loadIconManager === 'function') {
            loadIconManager();
        }
        if (tab === 'settings') {
            activateSettingsTab(options.settingsTab || getActiveSettingsTab(), { silent: true });
        }

        if (tab === 'settings' && !settingsLoaded) {
            loadSettings();
        }

        updateDashboardHash(tab, tab === 'settings' ? (options.settingsTab || getActiveSettingsTab()) : '', !!options.replace);
    }
