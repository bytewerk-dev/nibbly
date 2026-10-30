<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    const VALID_DASHBOARD_TABS = <?php echo json_encode($validDashboardTabs, JSON_UNESCAPED_UNICODE); ?>;
    const DASHBOARD_HASH_ALIASES = { dashboard: 'home', ai: 'home', pages: 'content', messages: 'mails' };
    let dashboardRouteApplying = false;
    let settingsLoaded = false;
    let currentSettings = null;
    let currentAiSettings = null;
    let aiChatMessages = [];
    let dashboardAiImageUsable = false;
    // "Need a new image?" hints beside image fields: only while image generation is set up and on
    function syncAiImageHints() {
        document.querySelectorAll('.ai-field-hint').forEach(hint => { hint.hidden = !dashboardAiImageUsable; });
        document.querySelectorAll('.nb-ai-translate').forEach(button => { button.hidden = !dashboardAiTextUsable; });
    }
    // "Translate from active language": only while AI text generation is set up and on
    let dashboardAiTextUsable = false;
    let currentDashboardAnalyticsRange = { period: 'days', count: 30 };
    let formsAdminLoaded = false;

    function dashboardHashFor(tab, subtab) {
        const publicTab = tab === 'home' ? 'dashboard' : (tab === 'content' ? 'pages' : (tab === 'mails' ? 'messages' : tab));
        return '#' + publicTab + (subtab ? '/' + subtab : '');
    }

    // Topbar breadcrumb: ["Einstellungen", "Design"] → Einstellungen › Design
    // Collapsible sidebar: icons only, remembered per browser
    (function() {
        var btn = document.getElementById('sidebarCollapseBtn');
        if (!btn || !document.documentElement) return;
        var KEY = 'nibblySidebarCollapsed';
        var collapsed = false;
        try { collapsed = window.localStorage.getItem(KEY) === '1'; } catch (e) {}

        function applySidebarCollapsed(state) {
            var root = document.documentElement;
            if (state) root.setAttribute('data-sidebar', 'collapsed');
            else root.removeAttribute('data-sidebar');
            btn.setAttribute('aria-pressed', state ? 'true' : 'false');
            var label = typeof t === 'function' ? t(state ? 'nav.expand_sidebar' : 'nav.collapse_sidebar') : '';
            var labelSpan = btn.querySelector('span');
            if (label && labelSpan) labelSpan.textContent = label;
            if (label) btn.title = label;
            // Icon-only items explain themselves on hover
            document.querySelectorAll('.sidebar-nav-item').forEach(function(item) {
                if (item === btn) return;
                var text = item.querySelector(':scope > span:not(.mail-badge)');
                if (!text) return;
                if (state) item.setAttribute('title', text.textContent.trim());
                else item.removeAttribute('title');
            });
        }

        applySidebarCollapsed(collapsed || document.documentElement.hasAttribute('data-settings-open'));
        btn.addEventListener('click', function() {
            // Toggle what is shown (it may be collapsed for the settings only) and remember that
            collapsed = document.documentElement.getAttribute('data-sidebar') !== 'collapsed';
            try { window.localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) {}
            animateSidebarWidth();
            applySidebarCollapsed(collapsed);
        });
        // The settings show their own sub-navigation beside the main sidebar, which then shows only its
        // icons; leaving them brings back the saved state. Nothing is stored for this.
        // Width changes animate only while .nb-sidebar-animating is set, never on the first paint
        var animateTimer = null;
        function animateSidebarWidth() {
            document.documentElement.classList.add('nb-sidebar-animating');
            clearTimeout(animateTimer);
            animateTimer = setTimeout(function() { document.documentElement.classList.remove('nb-sidebar-animating'); }, 400);
        }
        window.nbSidebarForSettings = function(active) {
            if (document.documentElement.hasAttribute('data-settings-open') !== active) animateSidebarWidth();
            document.documentElement.toggleAttribute('data-settings-open', active);
            applySidebarCollapsed(active || collapsed);
        };
    })();

    function nbSetTopbarTitle(parts) {
        const el = document.getElementById('topbarTitle');
        if (!el) return;
        const list = (Array.isArray(parts) ? parts : [parts]).filter(Boolean);
        el.textContent = '';
        list.forEach((text, i) => {
            if (i > 0) {
                const sep = document.createElement('span');
                sep.className = 'topbar-crumb-sep';
                sep.setAttribute('aria-hidden', 'true');
                sep.textContent = '›';
                el.appendChild(sep);
            }
            const span = document.createElement('span');
            span.className = i < list.length - 1 ? 'topbar-crumb' : 'topbar-crumb-current';
            span.textContent = text;
            el.appendChild(span);
        });
    }
    window.nbSetTopbarTitle = nbSetTopbarTitle;
    function parseDashboardHash() {
        const raw = (window.location.hash || '').replace(/^#/, '');
        if (!raw) return { tab: 'home', subtab: '' };
        const parts = raw.split('/').filter(Boolean);
        // The menu order moved from Settings → Menus to its own tab.
        if (parts[0] === 'settings' && parts[1] === 'menus') return { tab: VALID_DASHBOARD_TABS.indexOf('menus') !== -1 ? 'menus' : 'home', subtab: '' };
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
                updatePageSelect(slug);
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
                updatePageSelect(slug);
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
    // Deferred by one microtask: every fragment shares this script block, and a
    // route into News or Events reads flags (newsLoaded, eventsLoaded) that are
    // declared further down. Running synchronously here hit their temporal dead
    // zone and left those tabs empty after a direct #news / #events load.
    Promise.resolve().then(() => applyDashboardRoute(true));

    // Browser back/forward navigation
    window.addEventListener('popstate', async (e) => {
        if (e.state && e.state.view === 'editor' && e.state.page) {
            const lang = e.state.page.substring(0, e.state.page.indexOf('_'));
            const slug = e.state.page.substring(e.state.page.indexOf('_') + 1);
            document.getElementById('langSelect').value = lang;
            updatePageSelect(slug);
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

    // A second click on the settings button closes them again: back to the area they were opened from
    let tabBeforeSettings = 'home';
    function toggleSettingsTab() {
        if (document.documentElement.hasAttribute('data-settings-open')) switchTab(tabBeforeSettings);
        else switchTab('settings');
    }

    function switchTab(tab, options) {
        options = options || {};
        if (tab === 'settings') {
            const from = document.querySelector('.admin-sidebar .sidebar-nav-item.active[data-tab]')?.dataset.tab;
            if (from && from !== 'settings') tabBeforeSettings = from;
        }
        if (!isDashboardModuleEnabled(tab)) {
            tab = 'home';
            options = Object.assign({}, options, { replace: true });
        }
        if (tab !== 'content') {
            dismissFrontendEditBanner();
        }
        // Unsaved menu or footer changes stay unless the user lets them go.
        if (tab !== 'people' && window.NbPeople && !window.NbPeople.canLeave()) {
            history.replaceState({view:'dashboard',tab:'people',subtab:''}, '', DASHBOARD_PATH + '#people');
            return;
        }
        if (tab !== 'menus' && window.NbMenusEditor && !window.NbMenusEditor.canLeave()) {
            if (window.location.hash.replace(/^#/, '').split('/')[0] !== 'menus') {
                history.replaceState({ view: 'dashboard', tab: 'menus', subtab: '' }, '', DASHBOARD_PATH + '#menus');
            }
            return;
        }

        if (typeof window.nbSidebarForSettings === 'function') window.nbSidebarForSettings(tab === 'settings');
        const systemTab = document.getElementById('systemTab');
        if (systemTab) systemTab.style.display = tab === 'system' ? 'block' : 'none';
        if (tab === 'system') loadSystemStatus();
        document.getElementById('homeTab').style.display = tab === 'home' ? 'block' : 'none';
        document.getElementById('contentTab').style.display = tab === 'content' ? 'block' : 'none';
        document.getElementById('newsTab').style.display = tab === 'news' ? 'block' : 'none';
        const peopleTab = document.getElementById('peopleTab');
        if (peopleTab) peopleTab.style.display = tab === 'people' ? 'block' : 'none';
        document.getElementById('eventsTab').style.display = tab === 'events' ? 'block' : 'none';
        document.getElementById('mailsTab').style.display = tab === 'mails' ? 'block' : 'none';
        const commentsTab = document.getElementById('commentsTab');
        if (commentsTab) commentsTab.style.display = tab === 'comments' ? 'block' : 'none';
        if (tab === 'comments' && typeof loadComments === 'function') loadComments();
        const mediaTab = document.getElementById('mediaTab');
        if (mediaTab) mediaTab.style.display = tab === 'media' ? 'block' : 'none';
        const menusTab = document.getElementById('menusTab');
        if (menusTab) menusTab.style.display = tab === 'menus' ? 'block' : 'none';
        const iconsTab = document.getElementById('iconsTab');
        if (iconsTab) iconsTab.style.display = tab === 'icons' ? 'block' : 'none';
        document.getElementById('settingsTab').style.display = tab === 'settings' ? 'block' : 'none';
        document.getElementById('backupTab').style.display = tab === 'backup' ? 'block' : 'none';
        if (typeof syncAssistantEmbed === 'function') syncAssistantEmbed();

        // When switching to content tab, always show page list
        if (tab === 'content') {
            showPageList();
        }

        // Update sidebar active state
        document.querySelectorAll('.sidebar-nav-item[data-tab]').forEach(btn => btn.classList.remove('active'));
        const activeNavItem = document.querySelector(`.sidebar-nav-item[data-tab="${tab}"]`);
        if (activeNavItem) activeNavItem.classList.add('active');

        // Update topbar title
        const titles = { system: t('system.title'), home: t('dashboard_home.title'), content: currentPage ? t('editor.title') : t('pages.title'), news: t('news.title'), mails: t('mails.title'), comments: t('comments.title'), events: t('events.title'), people: t('people.title'), media: t('nav.media_library'), menus: t('menus.title'), icons: t('icons.title'), settings: t('settings.title'), backup: t('settings.backup') };
        nbSetTopbarTitle([titles[tab] || 'Dashboard']);

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
        if (tab === 'people' && window.NbPeople) window.NbPeople.open();
        if (tab === 'menus' && window.NbMenusEditor) {
            window.NbMenusEditor.open();
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

    // ---- Truncated pill labels (Pop preset) ------------------------------------
    // Pop pills stay on one line and end in an ellipsis when the label does not fit
    // (other languages run longer). A truncated pill shows its full text as a
    // tooltip; one that fits again loses the tooltip it was given here.
    (function markTruncatedPills() {
        // Browser only (script sandboxes in the tests have no timers or observers)
        if (typeof setTimeout !== 'function' || typeof MutationObserver === 'undefined') return;
        const PILL_SELECTOR = [
            '.settings-section > legend', '.dashboard-panel h3', '.dashboard-section-title',
            '.dashboard-section-header h3', '.ai-card > h3', '.backup-site-card__info h3',
            '.scheduled-list__header h4', '.backup-remote__header h4', '.dashboard-stat-card span',
            '.dashboard-status-chip__label', '.settings-panel h3.settings-subheading',
            '.frontend-logos-block__title', '.scheduled-form__fieldset legend',
            '.ai-image-jobs-panel__copy h4', '.ai-image-history__header h4', '.mf-section-title'
        ].join(',');
        let timer = null;
        const update = () => {
            timer = null;
            const pop = document.documentElement.dataset.themePreset === 'pop';
            document.querySelectorAll(PILL_SELECTOR).forEach(pill => {
                const truncated = pop && pill.offsetParent !== null && pill.scrollWidth > pill.clientWidth + 1;
                if (truncated) {
                    pill.title = pill.textContent.trim();
                    pill.dataset.pillTitle = '1';
                } else if (pill.dataset.pillTitle) {
                    pill.removeAttribute('title');
                    delete pill.dataset.pillTitle;
                }
            });
        };
        const schedule = () => { if (!timer) timer = setTimeout(update, 150); };
        if (typeof window.addEventListener === 'function') window.addEventListener('resize', schedule);
        const main = (document.getElementById && document.getElementById('adminMain')) || document.body;
        if (!main) return;
        if (typeof ResizeObserver !== 'undefined') new ResizeObserver(schedule).observe(main);
        new MutationObserver(schedule).observe(main, { childList: true, subtree: true, characterData: true });
        new MutationObserver(schedule).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme-preset', 'data-font-size'] });
        schedule();
    })();

