/**
 * Inline Editor - nibbly CMS
 * Edit content directly on the page when logged in as admin.
 *
 * Features:
 * - WYSIWYG Text Editor with HTML mode
 * - SoundCloud & YouTube Embeds
 * - Drag & Drop Sorting
 * - Add/Delete Sections
 * - Event Management
 * - Image & Audio Library
 */

(function() {
    'use strict';

    // Intercept fetch — redirect to login on session expiry (guard against double-wrap)
    if (!window._nibblyFetchWrapped) {
        const _origFetch = window.fetch;
        window.fetch = async function(...args) {
            const response = await _origFetch.apply(this, args);
            if (response.status === 401) {
                try {
                    const clone = response.clone();
                    const data = await clone.json();
                    if (data.session_expired) {
                        window.location.href = '/admin/index.php?timeout=' + Math.floor(Date.now() / 1000);
                        return response;
                    }
                } catch(e) {}
            }
            return response;
        };
        window._nibblyFetchWrapped = true;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Admin bar = the dashboard's topbar. Copies the saved design settings
     * (window.NB_ADMIN_BAR_THEME from includes/footer.php) onto the bar as the
     * data attributes the dashboard sets on <html>, so css/nibbly-admin-tokens.css
     * derives the topbar colours, font and sizes for the bar alone. "System"
     * follows the operating system's light/dark setting, live.
     */
    function applyAdminBarTheme(bar) {
        const theme = window.NB_ADMIN_BAR_THEME || {};
        bar.dataset.themePreset = theme.preset || 'pop';
        bar.dataset.density = theme.density || 'auto';
        bar.dataset.font = theme.font || 'atkinson';
        bar.dataset.fontSize = theme.fontSize || 'default';
        // The bar's right end lines up with the dashboard's content, which depends on its sidebar
        try {
            if (window.localStorage.getItem('nibblySidebarCollapsed') === '1') bar.dataset.sidebar = 'collapsed';
        } catch (e) { /* storage blocked: expanded sidebar */ }
        const mode = theme.mode || 'light';
        if (mode !== 'system' || !window.matchMedia) {
            bar.dataset.siteTheme = mode === 'dark' ? 'dark' : 'light';
            return;
        }
        const query = window.matchMedia('(prefers-color-scheme: dark)');
        const sync = () => { bar.dataset.siteTheme = query.matches ? 'dark' : 'light'; };
        sync();
        if (query.addEventListener) query.addEventListener('change', sync);
    }

    /**
     * Single-colour logos (like the default Nibbly icon) are drawn in the primary colour, as the
     * dashboard's topbar draws them (.admin-bar-logo-icon--default, the logo becomes a mask over
     * the colour), so they read on light and dark bars alike; multi-coloured site icons keep
     * their colours. Decided from
     * the rendered pixels: sites often replace the default file at assets/images/favicon.svg, so
     * the path alone says nothing. Without pixel access (cross-origin logo) the path is used.
     */
    function markAdminBarLogo(img, src) {
        const decide = () => {
            let mono = /(^|\/)assets\/images\/favicon\.svg(?:[?#].*)?$/.test(src);
            try {
                const canvas = document.createElement('canvas');
                canvas.width = canvas.height = 24;
                const context = canvas.getContext('2d', { willReadFrequently: true });
                context.drawImage(img, 0, 0, 24, 24);
                const data = context.getImageData(0, 0, 24, 24).data;
                const min = [255, 255, 255];
                const max = [0, 0, 0];
                let opaque = 0;
                for (let i = 0; i < data.length; i += 4) {
                    if (data[i + 3] < 200) continue;
                    opaque++;
                    for (let c = 0; c < 3; c++) {
                        min[c] = Math.min(min[c], data[i + c]);
                        max[c] = Math.max(max[c], data[i + c]);
                    }
                }
                mono = opaque > 0 && max.every((value, c) => value - min[c] <= 24);
            } catch (e) {
                // Pixels not readable: keep the path-based guess
            }
            img.classList.toggle('admin-bar-logo-icon--default', mono);
            if (mono) img.style.setProperty('--ab-logo-mask', 'url("' + String(img.currentSrc || src).replace(/["\\\n]/g, encodeURIComponent) + '")');
        };
        if (img.complete && img.naturalWidth) decide();
        else img.addEventListener('load', decide, { once: true });
    }

    /** Escape a string for safe insertion into HTML via innerHTML / template literals. */
    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function tFallback(key, fallback, params) {
        const value = t(key, params);
        return value && value !== key ? value : fallback;
    }

    const A11Y_FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
    let activeDialog = null;
    let activeDialogPreviousFocus = null;

    function setupDialogA11y(modal, titleId, initialSelector) {
        const dialog = modal.querySelector('.editor-modal-content');
        if (!dialog) return;
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        if (titleId) dialog.setAttribute('aria-labelledby', titleId);
        modal.dataset.initialFocus = initialSelector || '.editor-close-btn';
        if (modal.dataset.a11yReady === 'true') return;
        modal.dataset.a11yReady = 'true';
        modal.addEventListener('keydown', function(e) {
            if (e.key !== 'Tab' || !modal.classList.contains('active')) return;
            const focusable = Array.from(modal.querySelectorAll(A11Y_FOCUSABLE)).filter(el => el.offsetParent !== null);
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });
    }

    function openDialogA11y(modal) {
        activeDialog = modal;
        activeDialogPreviousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        setTimeout(() => {
            const target = modal.querySelector(modal.dataset.initialFocus || A11Y_FOCUSABLE) || modal.querySelector(A11Y_FOCUSABLE);
            if (target) target.focus();
        }, 0);
    }

    function closeDialogA11y(modal) {
        if (activeDialog === modal && activeDialogPreviousFocus && document.contains(activeDialogPreviousFocus)) {
            activeDialogPreviousFocus.focus();
        }
        if (activeDialog === modal) {
            activeDialog = null;
            activeDialogPreviousFocus = null;
        }
    }

    // ============================================================
    // CONFIGURATION
    // ============================================================

    const EditorConfig = {
        apiUrl: '/admin/api.php',
        csrfToken: null,
        currentPage: null,
        currentSection: null,
        currentSectionIndex: null,
        currentContentPage: null,
        contentData: {},
        contentRevisions: {},
        saving: false,
        loadedPages: [],
        currentEvent: null,
        currentGroup: null,
        eventsData: null,
        draggedElement: null,
        draggedIndex: null,
        draggedContentPage: null,
        // Language config (populated from meta tags during init)
        languages: {},
        defaultLang: 'en',
        aiFeaturesEnabled: window.NB_AI_FEATURES_ENABLED !== false,
        // News post editing
        isNewsPost: false,
        newsPostId: null,
        newsPostData: null,
        // Edit-Mode State
        editMode: false,
        originalPageData: {},
        dirtyPages: new Set(),
        undoStack: [],
        redoStack: []
    };

    const SESSION_KEEPALIVE_INTERVAL_MS = 5 * 60 * 1000;
    const sessionKeepaliveEvents = ['pointerdown', 'keydown', 'input', 'focusin'];
    let lastSessionKeepaliveAt = 0;

    function requestSessionKeepalive(force = false) {
        if (!EditorConfig.csrfToken || !EditorConfig.apiUrl) return;
        const now = Date.now();
        if (!force && now - lastSessionKeepaliveAt < SESSION_KEEPALIVE_INTERVAL_MS) return;
        lastSessionKeepaliveAt = now;

        const url = `${EditorConfig.apiUrl}?action=keepalive&csrf_token=${encodeURIComponent(EditorConfig.csrfToken)}&_=${now}`;
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .catch(error => {
                console.warn('Session keepalive failed:', error);
            });
    }

    function handleSessionActivity() {
        if (!EditorConfig.editMode) return;
        requestSessionKeepalive(false);
    }

    function startSessionKeepalive() {
        requestSessionKeepalive(true);
        sessionKeepaliveEvents.forEach(eventName => {
            document.addEventListener(eventName, handleSessionActivity, true);
        });
    }

    function stopSessionKeepalive() {
        sessionKeepaliveEvents.forEach(eventName => {
            document.removeEventListener(eventName, handleSessionActivity, true);
        });
    }

    // Block type registry (injected from PHP via window.BlockTypeRegistry)
    const BlockTypes = window.BlockTypeRegistry || {};

    // Category display names
    const CategoryLabels = {
        content: t('cat.content'),
        media: t('cat.media'),
        cards: t('cat.cards'),
        interactive: t('cat.interactive'),
        layout: t('cat.layout'),
        embed: t('cat.embed')
    };

    // Legacy compat alias
    const SectionTypes = {};
    for (const [type, def] of Object.entries(BlockTypes)) {
        SectionTypes[type] = { label: def.label };
    }
    SectionTypes.project = { label: 'Card' };

    // SVG Icons (monochrome)
    const Icons = {
        edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></g></svg>',
        delete: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M8.451,5.968V3.484 c0-1.371,1.113-2.484,2.484-2.484h2.129c1.371,0,2.484,1.113,2.484,2.484v2.484 M2.065,5.968h19.871 M4.194,5.968l1.732,14.733 c0.156,1.334,1.277,2.299,2.626,2.299h6.898c1.348,0,2.47-0.965,2.626-2.299l1.732-14.733 M8.341,9.804l0.753,9.616 M12,9.804 v9.616 M15.66,9.804l-0.753,9.616"/></g></svg>',
        drag: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></g></svg>',
        audio: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></g></svg>',
        video: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/></g></svg>',
        text: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></g></svg>',
        plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></g></svg>',
        arrowUp: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></g></svg>',
        arrowDown: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M19 12l-7 7-7-7"/></g></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></g></svg>',
        eraser: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21M22 21H7M5 11l9 9"/></g></svg>',
        undo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6.69 3L3 13"/></g></svg>',
        redo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6.69 3L21 13"/></g></svg>',
        save: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></g></svg>',
        history: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></g></svg>',
        eyeOpen: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></g></svg>',
        eyeClosed: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/></g></svg>',
        extract: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M16.96 20.883 H2.714 c-1.09 0 -1.974 -0.884 -1.974 -1.974 V5.09 C0.74 4 1.624 3.117 2.714 3.117 h13.818 c1.09 0 1.974 0.884 1.974 1.974 V14.961 M6.17 7.065 c0.817 0 1.481 0.663 1.481 1.481 s-0.663 1.481 -1.481 1.481 s-1.481 -0.663 -1.481 -1.481 S5.351 7.065 6.17 7.065 z M18.507 14.961 L13.572 10.026 L2.714 20.883 M15.149 17.253 h8.111 M21.461 15.454 l1.799 1.799 M21.461 19.052 l1.799 -1.799"/></g></svg>',
        image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></g></svg>',
        link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></g></svg>',
        unlink: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/><path d="m3 3 18 18"/></g></svg>',
        card: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/></g></svg>',
        upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/></g></svg>',
        folder: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/></g></svg>',
        grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></g></svg>',
        list: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></g></svg>',
        eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></g></svg>',
        replace: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></g></svg>',
        check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></g></svg>',
        heading: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16M18 4v16M6 12h12"/></g></svg>',
        quote: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M10 11H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v6a4 4 0 0 1-4 4"/><path d="M20 11h-4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v6a4 4 0 0 1-4 4"/></g></svg>',
        spacer: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18M3 20h18"/><path d="m8 10 4-3 4 3M8 14l4 3 4-3"/></g></svg>',
        divider: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18"/></g></svg>',
        // Offizielle Brand Icons
        soundcloud: '<svg viewBox="0 0 24 24"><defs><style>.cls-1{fill:currentColor;stroke:currentColor;stroke-miterlimit:10;stroke-width:1.91px;}</style></defs><path class="cls-1" d="M22.5,13.93a2.87,2.87,0,0,1-2.86,2.87H13V7.47a4.82,4.82,0,0,1,1.44-.22,4.07,4.07,0,0,1,4.29,3.82h1A2.86,2.86,0,0,1,22.5,13.93Z"/><line class="cls-1" x1="10.09" y1="8.2" x2="10.09" y2="17.75"/><line class="cls-1" x1="7.23" y1="9.16" x2="7.23" y2="17.75"/><line class="cls-1" x1="4.36" y1="9.16" x2="4.36" y2="17.75"/><line class="cls-1" x1="1.5" y1="11.07" x2="1.5" y2="16.8"/></svg>',
        youtube: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9.629 16.258 V7.728 l6.411 4.265 L9.629 16.258 z M12 20.298 c0.278 0 5.828 0.013 9.616 -1.139 c0.662 -0.199 1.152 -0.768 1.285 -1.47 l0 0 c0.278 -1.536 0.411 -3.444 0.411 -5.695 c0 -2.252 -0.146 -4.159 -0.424 -5.695 c-0.132 -0.702 -0.609 -1.258 -1.272 -1.457 C17.828 3.675 12.252 3.689 12.013 3.702 c-1.02 0 -6.066 0.053 -9.629 1.139 C1.722 5.026 1.232 5.596 1.099 6.298 c-0.278 1.536 -0.424 3.457 -0.424 5.695 c0 2.252 0.146 4.159 0.424 5.695 c0.132 0.702 0.623 1.258 1.272 1.47 C6.172 20.311 11.762 20.298 12 20.298 z"/></g></svg>'
    };

    // ============================================================
    // UNDO HISTORY (localStorage-basiert)
    // ============================================================

    const UndoHistory = {
        maxEntries: 10,
        storageKeyPrefix: 'editor_history_',

        // Save backup before a change
        saveBackup(contentPage, data, actionDescription = 'Change') {
            try {
                const key = this.storageKeyPrefix + contentPage;
                let history = this.getBackups(contentPage);

                // Neuen Eintrag erstellen
                const entry = {
                    timestamp: Date.now(),
                    date: new Date().toLocaleString('de-AT'),
                    action: actionDescription,
                    data: JSON.parse(JSON.stringify(data)) // Deep copy
                };

                // Insert at beginning (newest first)
                history.unshift(entry);

                // Auf maxEntries begrenzen
                if (history.length > this.maxEntries) {
                    history = history.slice(0, this.maxEntries);
                }

                localStorage.setItem(key, JSON.stringify(history));
                return true;
            } catch (error) {
                console.error('Error saving backup:', error);
                return false;
            }
        },

        // Get all backups for a page
        getBackups(contentPage) {
            try {
                const key = this.storageKeyPrefix + contentPage;
                const stored = localStorage.getItem(key);
                return stored ? JSON.parse(stored) : [];
            } catch (error) {
                console.error('Error loading backups:', error);
                return [];
            }
        },

        // Get all pages with backups
        getAllPages() {
            const pages = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith(this.storageKeyPrefix)) {
                    pages.push(key.replace(this.storageKeyPrefix, ''));
                }
            }
            return pages;
        },

        // Delete history for a page
        clearHistory(contentPage) {
            const key = this.storageKeyPrefix + contentPage;
            localStorage.removeItem(key);
        },

        // Delete entire history
        clearAllHistory() {
            const pages = this.getAllPages();
            pages.forEach(page => this.clearHistory(page));
        }
    };

    // Variable for last state (for instant undo in toast)
    let lastSavedState = null;
    let lastSavedPage = null;
    let lastSavedType = null; // 'content' or 'event'

    // ============================================================
    // RESIZABLE MODAL FUNCTIONALITY
    // ============================================================

    const ModalResize = {
        isResizing: false,
        currentModal: null,
        startX: 0,
        startY: 0,
        startWidth: 0,
        startHeight: 0,
        startLeft: 0,
        startTop: 0,

        // Initialize resize for a modal
        init(modalContent) {
            if (!modalContent || modalContent.querySelector('.modal-resize-handle')) return;

            // Resize-Handle erstellen
            const handle = document.createElement('div');
            handle.className = 'modal-resize-handle';
            handle.title = 'Resize';
            modalContent.appendChild(handle);

            // Event-Listener
            handle.addEventListener('mousedown', (e) => this.startResize(e, modalContent));
        },

        startResize(e, modalContent) {
            e.preventDefault();
            e.stopPropagation();

            this.isResizing = true;
            this.currentModal = modalContent;
            this.startX = e.clientX;
            this.startY = e.clientY;

            const rect = modalContent.getBoundingClientRect();
            this.startWidth = rect.width;
            this.startHeight = rect.height;
            this.startLeft = rect.left;
            this.startTop = rect.top;

            // If still centered, switch to absolute positioning
            if (!modalContent.classList.contains('custom-size')) {
                modalContent.style.width = this.startWidth + 'px';
                modalContent.style.height = this.startHeight + 'px';
                modalContent.style.left = this.startLeft + 'px';
                modalContent.style.top = this.startTop + 'px';
                modalContent.classList.add('custom-size');
            }

            modalContent.classList.add('resizing');
            document.addEventListener('mousemove', this.handleMouseMove);
            document.addEventListener('mouseup', this.handleMouseUp);
        },

        handleMouseMove: function(e) {
            if (!ModalResize.isResizing || !ModalResize.currentModal) return;

            const deltaX = e.clientX - ModalResize.startX;
            const deltaY = e.clientY - ModalResize.startY;

            const newWidth = Math.max(320, ModalResize.startWidth + deltaX);
            const newHeight = Math.max(200, ModalResize.startHeight + deltaY);

            // Limit max size
            const maxWidth = window.innerWidth - 40;
            const maxHeight = window.innerHeight - 40;

            ModalResize.currentModal.style.width = Math.min(newWidth, maxWidth) + 'px';
            ModalResize.currentModal.style.height = Math.min(newHeight, maxHeight) + 'px';
        },

        handleMouseUp: function() {
            if (!ModalResize.currentModal) return;

            ModalResize.currentModal.classList.remove('resizing');
            ModalResize.isResizing = false;
            ModalResize.currentModal = null;

            document.removeEventListener('mousemove', ModalResize.handleMouseMove);
            document.removeEventListener('mouseup', ModalResize.handleMouseUp);
        },

        // Reset modal to default size (on close)
        reset(modalContent) {
            if (!modalContent) return;
            modalContent.classList.remove('custom-size', 'resizing');
            modalContent.style.width = '';
            modalContent.style.height = '';
            modalContent.style.left = '';
            modalContent.style.top = '';
        }
    };

    // ============================================================
    // CONFIRM DIALOG (replaces window.confirm)
    // ============================================================

    let confirmCallback = null;

    function createConfirmDialog() {
        if (document.getElementById('confirm-dialog-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'confirm-dialog-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop" aria-hidden="true"></div>
            <div class="editor-modal-content editor-modal-small">
                <div class="editor-modal-header">
                    <h3 id="confirm-dialog-title"></h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeConfirmDialog(false)" aria-label="${escHtml(tFallback('close', 'Close'))}">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div class="confirm-dialog-content">
                        <div class="confirm-dialog-icon">${Icons.warning}</div>
                        <p class="confirm-dialog-message" id="confirm-dialog-message"></p>
                        <p class="confirm-dialog-hint" id="confirm-dialog-hint"></p>
                    </div>
                </div>
                <div class="confirm-dialog-footer">
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closeConfirmDialog(false)">${t('cancel')}</button>
                    <button type="button" class="editor-btn editor-btn-danger" id="confirm-dialog-action-btn" onclick="InlineEditor.closeConfirmDialog(true)">${t('delete')}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        setupDialogA11y(modal, 'confirm-dialog-title', '#confirm-dialog-action-btn');

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', () => closeConfirmDialog(false));

        // Resizable Modal
        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    function showConfirmDialog(title, message, hint, callback, buttonText = t('delete'), buttonClass = 'editor-btn-danger') {
        createConfirmDialog();
        confirmCallback = callback;

        document.getElementById('confirm-dialog-title').textContent = title;
        document.getElementById('confirm-dialog-message').textContent = message;
        document.getElementById('confirm-dialog-hint').textContent = hint || '';
        document.getElementById('confirm-dialog-hint').style.display = hint ? 'block' : 'none';
        document.getElementById('confirm-dialog-action-btn').textContent = buttonText;
        document.getElementById('confirm-dialog-action-btn').className = 'editor-btn ' + buttonClass;

        const modal = document.getElementById('confirm-dialog-modal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        openDialogA11y(modal);
        openDialogA11y(modal);
    }

    function closeConfirmDialog(confirmed) {
        const modal = document.getElementById('confirm-dialog-modal');
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';
        closeDialogA11y(modal);

        if (confirmCallback) {
            const cb = confirmCallback;
            confirmCallback = null;
            if (confirmed) cb();
        }
    }

    // ============================================================
    // PROMPT-DIALOG (ersetzt window.prompt)
    // ============================================================

    let promptCallback = null;

    function createPromptDialog() {
        if (document.getElementById('prompt-dialog-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'prompt-dialog-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop" aria-hidden="true"></div>
            <div class="editor-modal-content editor-modal-small">
                <div class="editor-modal-header">
                    <h3 id="prompt-dialog-title"></h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closePromptDialog(null)" aria-label="${escHtml(tFallback('close', 'Close'))}">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div class="prompt-dialog-content">
                        <label class="prompt-dialog-label" id="prompt-dialog-label"></label>
                        <input type="text" class="prompt-dialog-input" id="prompt-dialog-input">
                    </div>
                </div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closePromptDialog(null)">${escHtml(t('cancel'))}</button>
                    <button type="button" class="editor-btn editor-btn-primary" onclick="InlineEditor.submitPromptDialog()">${escHtml(t('ok'))}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        setupDialogA11y(modal, 'prompt-dialog-title', '#prompt-dialog-input');

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', () => closePromptDialog(null));

        // Enter key to confirm
        document.getElementById('prompt-dialog-input').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitPromptDialog();
            }
        });

        // Resizable Modal
        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    function showPromptDialog(title, label, defaultValue, callback) {
        createPromptDialog();
        promptCallback = callback;

        document.getElementById('prompt-dialog-title').textContent = title;
        document.getElementById('prompt-dialog-label').textContent = label;
        document.getElementById('prompt-dialog-input').value = defaultValue || '';

        const modal = document.getElementById('prompt-dialog-modal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        openDialogA11y(modal);

        // Focus on input
        setTimeout(() => {
            document.getElementById('prompt-dialog-input').focus();
            document.getElementById('prompt-dialog-input').select();
        }, 100);
    }

    function closePromptDialog(value) {
        const modal = document.getElementById('prompt-dialog-modal');
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';
        closeDialogA11y(modal);

        if (promptCallback) {
            const cb = promptCallback;
            promptCallback = null;
            cb(value);
        }
    }

    function submitPromptDialog() {
        const value = document.getElementById('prompt-dialog-input').value;
        closePromptDialog(value);
    }

    // ============================================================
    // INITIALISIERUNG
    // ============================================================

    function initEditor() {
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');
        if (!tokenMeta) return;

        EditorConfig.csrfToken = tokenMeta.content;

        const pageMeta = document.querySelector('meta[name="content-page"]');
        if (pageMeta) {
            EditorConfig.currentPage = pageMeta.content;
        }

        const langMeta = document.querySelector('meta[name="site-languages"]');
        if (langMeta) {
            try { EditorConfig.languages = JSON.parse(langMeta.content); } catch(e) {}
        }
        const defaultLangMeta = document.querySelector('meta[name="site-lang-default"]');
        if (defaultLangMeta) {
            EditorConfig.defaultLang = defaultLangMeta.content;
        }

        // Detect news post page
        const newsPostEl = document.querySelector('[data-news-post]');
        if (newsPostEl && window.__cmsNewsPost) {
            EditorConfig.isNewsPost = true;
            EditorConfig.newsPostId = newsPostEl.dataset.newsPost;
            EditorConfig.newsPostData = window.__cmsNewsPost;
        }

        // Apply admin theme preference to frontend for editor modals
        try {
            const adminTheme = localStorage.getItem('site-admin-theme');
            if (adminTheme) {
                let themeValue = adminTheme;
                if (themeValue === 'system') {
                    themeValue = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-site-theme', themeValue);
            }
        } catch(e) {}

        // Alle Content-Pages sammeln
        const contentPages = new Set();
        if (EditorConfig.currentPage) {
            contentPages.add(EditorConfig.currentPage);
        }

        document.querySelectorAll('[data-content-page]').forEach(el => {
            contentPages.add(el.dataset.contentPage);
        });
        // Custom layouts may reference shared content only through individual
        // helpers, without a content-area wrapper or editable group. Load the
        // complete document and its revision before any of those fields can save.
        document.querySelectorAll('.editable-field[data-page], [data-editable-group][data-page], [data-editable-link][data-page], [data-editable-image][data-page], [data-editable-icon][data-page], [data-editable-list][data-list-page]').forEach(el => {
            const page = el.dataset.page || el.dataset.listPage;
            // Event image helpers use the separate events API, not page JSON.
            if (page && page !== 'events') contentPages.add(page);
        });

        // Alle Content-Daten laden
        const loadPromises = Array.from(contentPages).map(page => loadContent(page));
        loadPromises.push(loadEvents());

        Promise.all(loadPromises).then(async () => {
            attachEditorChromeHoverStates();
            createEditorUI();
            createGroupEditorUI();
            createEventEditorUI();
            createAddSectionUI();
            attachEditHandlers();
            attachEventEditHandlers();
            attachFooterEditHandlers();
            attachGroupEditHandlers();
            attachFieldEditHandlers();
            attachLinkEditHandlers();
            attachImageEditHandlers();
            attachListEditHandlers();
            attachComparisonRowHandlers();
            attachComparisonCellToggles();
            // Await the admin bar — showAdminBar() awaits a settings fetch
            // and only then injects the bar markup. Without await, the
            // auto-restore branch below ran enterEditMode() before the
            // bar existed, leaving the toolbar stuck on the "start edit"
            // state even though edit mode was active internally.
            await showAdminBar();

            // Notify admin about auto-generated content fields
            if (window.NB_AUTO_GENERATED && window.NB_AUTO_GENERATED.length > 0) {
                const count = window.NB_AUTO_GENERATED.length;
                // The technical detail (which file and keys) goes to the console, the toast stays plain
                console.info('nibbly: added missing fields with their default text', window.NB_AUTO_GENERATED);
                const msg = count === 1
                    ? tFallback('auto_generated_field', 'Added a missing field with its default text, so you can edit it.')
                    : tFallback('auto_generated_fields', 'Added {count} missing fields with their default text, so you can edit them.').replace('{count}', count);
                setTimeout(() => showToast(msg, 'info'), 500);
            }

            // Auto-restore edit mode after structural-change reload
            if (sessionStorage.getItem('site-edit-mode') === 'true') {
                sessionStorage.removeItem('site-edit-mode');
                enterEditMode();
            }
            attachEditModeHint();
        });
    }

    // A reminder for authenticated visitors who try to edit before enabling the editor.
    let editModeHintReady = false;

    function attachEditModeHint() {
        if (editModeHintReady || !EditorConfig.csrfToken || !document.getElementById('admin-btn-edit')) return;
        editModeHintReady = true;
        let clicks = 0;
        const interactive = [
            'a', 'button', 'input', 'textarea', 'select', 'label', 'form', 'summary',
            'audio', 'video', 'iframe', '[onclick]', '[tabindex]:not([tabindex="-1"])',
            '[contenteditable]:not([contenteditable="false"])',
            '[role="button"]', '[role="link"]', '[role="tab"]', '[role="menuitem"]',
            '[role="checkbox"]', '[role="radio"]', '[role="switch"]', '[role="slider"]',
            '[role="combobox"]', '[role="option"]', '[aria-controls]', '[aria-haspopup]',
            '#admin-bar', '#adminAccess', '.editor-modal', '.nb-imgmgr-modal'
        ].join(', ');

        function onPageClick(event) {
            if (EditorConfig.editMode) {
                clicks = 0;
                return;
            }
            if (!event.isTrusted || event.defaultPrevented || event.button !== 0 || event.detail === 0 ||
                event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
            const target = event.target instanceof Element ? event.target : null;
            if (!target || target.closest(interactive)) return;
            // Never cover another dialog, including dialogs supplied by the website.
            if (Array.from(document.querySelectorAll('dialog[open], [role="dialog"], [aria-modal="true"], .editor-modal.active'))
                .some(dialog => dialog.getClientRects().length > 0)) return;
            if (++clicks < 2) return;
            document.removeEventListener('click', onPageClick);
            showEditModeHint();
        }

        document.addEventListener('click', onPageClick);
    }

    function showEditModeHint() {
        const dialog = document.createElement('dialog');
        dialog.className = 'editor-mode-hint';
        dialog.setAttribute('aria-labelledby', 'editor-mode-hint-title');
        dialog.setAttribute('aria-describedby', 'editor-mode-hint-message');
        dialog.innerHTML = `
            <h2 id="editor-mode-hint-title">${escHtml(tFallback('edit_reminder.title', 'Edit this page?'))}</h2>
            <p id="editor-mode-hint-message">${escHtml(tFallback('edit_reminder.message', 'You are logged in. Activate the visual editor to edit text and images directly on this page.'))}</p>
            <div class="editor-mode-hint__actions">
                <button type="button" class="editor-btn editor-btn-secondary" data-hint-dismiss>${escHtml(tFallback('edit_reminder.dismiss', 'Keep browsing'))}</button>
                <button type="button" class="editor-btn editor-btn-primary" data-hint-activate autofocus>${escHtml(tFallback('edit_reminder.activate', 'Activate visual editor'))}</button>
            </div>`;
        dialog.querySelector('[data-hint-dismiss]').addEventListener('click', () => dialog.close());
        dialog.querySelector('[data-hint-activate]').addEventListener('click', () => {
            dialog.close();
            if (!EditorConfig.editMode) enterEditMode();
            document.getElementById('admin-btn-cancel')?.focus();
        });
        dialog.addEventListener('close', () => dialog.remove(), { once: true });
        document.body.appendChild(dialog);
        dialog.showModal();
    }

    function attachEditorChromeHoverStates() {
        if (document.documentElement.dataset.editorHoverReady === 'true') return;
        document.documentElement.dataset.editorHoverReady = 'true';

        const selector = [
            '#admin-bar .admin-bar-btn',
            '.section-add-btn-toggle',
            '.nibbly-editor-control-btn',
            '.nibbly-editor-drag-handle'
        ].join(',');

        document.addEventListener('pointerover', (e) => {
            const el = e.target.closest(selector);
            if (!el || el.disabled) return;
            el.classList.add('is-hover');
        }, true);

        document.addEventListener('pointerout', (e) => {
            const el = e.target.closest(selector);
            if (!el) return;
            if (e.relatedTarget && el.contains(e.relatedTarget)) return;
            el.classList.remove('is-hover');
        }, true);
    }

    // ============================================================
    // DATEN LADEN
    // ============================================================

    async function loadEvents() {
        try {
            const response = await fetch(`${EditorConfig.apiUrl}?action=load-events`);
            const result = await response.json();
            if (result.success) {
                EditorConfig.eventsData = result.data;
            }
        } catch (error) {
            console.error('Error loading events:', error);
        }
    }

    async function loadContent(page) {
        try {
            const response = await fetch(`${EditorConfig.apiUrl}?action=load&page=${page}`);
            const result = await response.json();
            if (result.success) {
                EditorConfig.contentData[page] = result.data;
                EditorConfig.contentRevisions[page] = result.revision;
                EditorConfig.loadedPages.push(page);
            }
        } catch (error) {
            console.error('Error loading ' + page + ':', error);
        }
    }

    // ============================================================
    // ADMIN BAR
    // ============================================================

    // Persisted page backups (separate from the current session's undo history).
    const PageBackups = {
        items: [], loading: false, restoring: false, loadFailed: false, reloadRequired: false,
        update() {
            const group = document.getElementById('admin-page-backups');
            const select = document.getElementById('admin-backup-select');
            const button = document.getElementById('admin-btn-restore-backup');
            if (!group || !select || !button) return;
            group.hidden = !this.items.length && !this.loadFailed;
            document.getElementById('admin-bar').classList.toggle('has-page-backups', !group.hidden);
            const busy = this.loading || this.restoring || EditorConfig.saving;
            select.disabled = busy || this.loadFailed || this.reloadRequired;
            button.disabled = busy || this.reloadRequired || (!this.loadFailed && !select.value);
            button.textContent = t(this.restoring ? 'page_backups.restoring' : this.loadFailed ? 'page_backups.retry' : 'page_backups.apply');
            button.title = t(this.reloadRequired ? 'page_backups.reload' : 'page_backups.restore_title');
            group.setAttribute('aria-busy', String(!!busy));
        },
        // Posts have their own backups (api list-news-backups / restore-news-backup, no page revision)
        isPost() { return !!(EditorConfig.isNewsPost && EditorConfig.newsPostData?.id); },
        async load() {
            // a post loads its own backups only once its id is known, never the page list
            if ((EditorConfig.isNewsPost ? !this.isPost() : !EditorConfig.currentPage) || this.loading || this.restoring) return;
            const select = document.getElementById('admin-backup-select');
            if (!select) return;
            this.loading = true;
            this.update();
            try {
                const url = this.isPost()
                    ? `${EditorConfig.apiUrl}?action=list-news-backups&id=${encodeURIComponent(EditorConfig.newsPostData.id)}`
                    : `${EditorConfig.apiUrl}?action=backups&page=${encodeURIComponent(EditorConfig.currentPage)}`;
                const response = await fetch(url, {cache: 'no-store'});
                const result = await response.json();
                if (!response.ok || !result.success || !Array.isArray(result.data)) throw new Error('Backup list unavailable');
                // Only show backups of this exact content page, never shared or nested pages.
                this.items = this.isPost() ? result.data : result.data.filter(item => {
                    const match = String(item.filename || '').match(/^(.*)_\d{4}-\d{2}-\d{2}_\d{6}(?:_[a-f0-9]{6})?\.json$/);
                    return match && match[1] === EditorConfig.currentPage;
                });
                const selected = select.value;
                select.replaceChildren();
                const labels = this.items.map(item => this.label(item));
                const seen = new Map();
                this.items.forEach((item, index) => {
                    const option = document.createElement('option');
                    let label = labels[index];
                    if (labels.indexOf(label) !== labels.lastIndexOf(label)) label = this.label(item, true);
                    const occurrence = (seen.get(label) || 0) + 1;
                    seen.set(label, occurrence);
                    option.value = item.filename;
                    option.textContent = label + (occurrence > 1 ? ` (${occurrence})` : '');
                    select.appendChild(option);
                });
                if (this.items.some(item => item.filename === selected)) select.value = selected;
                this.loadFailed = false;
            } catch (error) {
                this.loadFailed = true;
                const option = document.createElement('option');
                option.value = '';
                option.textContent = t('page_backups.unavailable');
                select.replaceChildren(option);
            } finally {
                this.loading = false;
                this.update();
            }
        },
        label(item, seconds = false) {
            const date = new Date(Number(item.timestamp) * 1000);
            if (!Number.isFinite(date.getTime())) return `Backup ${item.date || ''} ${item.time || ''}`.trim();
            const locale = document.documentElement.lang || 'en';
            const formatted = new Intl.DateTimeFormat(locale, {
                day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit',
                ...(seconds ? {second: '2-digit'} : {}), hour12: false
            }).format(date).replace(/,\s*/g, ' ');
            return `Backup ${formatted}`;
        },
        requestRestore() {
            if (this.loading || this.restoring || this.reloadRequired || EditorConfig.saving) return;
            if (this.loadFailed) { void this.load(); return; }
            const select = document.getElementById('admin-backup-select');
            const item = this.items.find(backup => backup.filename === select?.value);
            if (!item) return;
            flushActiveInlineEdits();
            if (this.isPost()) {
                const message = t('post_backups.confirm', {backup: select.options[select.selectedIndex].textContent});
                const hint = t(EditorConfig.dirtyPages.size > 0 ? 'page_backups.unsaved' : 'page_backups.saved_first');
                showConfirmDialog(t('page_backups.restore_title'), message, hint,
                    () => this.restore(item, ''), t('page_backups.apply'), 'editor-btn-primary');
                return;
            }
            const revision = window.NibblyRevisions?.get('page:' + EditorConfig.currentPage)
                || EditorConfig.contentRevisions[EditorConfig.currentPage];
            if (!revision) {
                this.reloadRequired = true;
                this.update();
                showToast(t('page_backups.reload'), 'error');
                return;
            }
            const message = t('page_backups.confirm', {backup: select.options[select.selectedIndex].textContent});
            const hint = t(EditorConfig.dirtyPages.size > 0 ? 'page_backups.unsaved' : 'page_backups.saved_first');
            showConfirmDialog(t('page_backups.restore_title'), message, hint,
                () => this.restore(item, revision), t('page_backups.apply'), 'editor-btn-primary');
        },
        async restore(item, revision) {
            if (this.restoring || EditorConfig.saving) return;
            this.restoring = true;
            this.update();
            const controls = Array.from(document.querySelectorAll('#admin-bar .admin-bar-btn'));
            const disabled = controls.map(control => control.disabled);
            controls.forEach(control => { control.disabled = true; });
            try {
                const formData = new FormData();
                if (this.isPost()) {
                    formData.append('action', 'restore-news-backup');
                    formData.append('post_id', EditorConfig.newsPostData.id);
                } else {
                    formData.append('action', 'restore');
                    formData.append('page', EditorConfig.currentPage);
                    formData.append('revision', revision);
                }
                formData.append('backup', item.filename);
                formData.append('csrf_token', EditorConfig.csrfToken);
                const response = await fetch(EditorConfig.apiUrl, {method: 'POST', body: formData});
                const result = await response.json();
                if (!response.ok || !result.success) {
                    this.reloadRequired = response.status === 409 || response.status === 428;
                    showToast(this.reloadRequired ? t('page_backups.reload') : t('page_backups.failed'), 'error');
                    return;
                }
                const resumeEditing = EditorConfig.editMode;
                exitEditModeClean();
                if (resumeEditing) sessionStorage.setItem('site-edit-mode', 'true');
                else sessionStorage.removeItem('site-edit-mode');
                location.reload();
            } catch (error) {
                // A failed response may follow a successful write; never repeat it automatically.
                this.reloadRequired = true;
                showToast(t('page_backups.unconfirmed'), 'error');
            } finally {
                this.restoring = false;
                controls.forEach((control, index) => { control.disabled = disabled[index]; });
                this.update();
            }
        }
    };

    async function showAdminBar() {
        // Load branding settings
        let brandLogo = '/assets/images/favicon.svg';
        let brandName = 'CMS';
        let showBranding = true;
        try {
            const settingsResp = await fetch('/admin/api.php?action=load-settings');
            const settingsResult = await settingsResp.json();
            if (settingsResult.success && settingsResult.data) {
                const d = settingsResult.data;
                const b = d.branding || {};
                brandLogo = d.logo || d.favicon || b.logo || brandLogo;
                brandName = d.siteName || b.name || brandName;
                showBranding = b.showBranding !== false;
            }
        } catch (e) { /* use defaults */ }

        const bar = document.createElement('div');
        bar.id = 'admin-bar';
        applyAdminBarTheme(bar);
        // Monochrome treatment (--default) is decided from the rendered logo, see markAdminBarLogo()
        // Same order as the dashboard's topbar: logo, site name, divider, then the way back
        const logoHtml = showBranding
            ? `<img src="${escHtml(brandLogo)}" alt="" width="24" height="24" class="admin-bar-logo-icon"><span class="admin-bar-brand">${escHtml(brandName)}</span><span class="admin-bar-divider" aria-hidden="true"></span>`
            : '';
        const seoHealth = window.NB_SEO_HEALTH || { status: 'yellow', score: 0, label: 'SEO prüfen', issues: ['Keine SEO-Prüfung verfügbar.'] };
        const seoIssues = Array.isArray(seoHealth.issues) ? seoHealth.issues : [];
        const seoDetail = seoIssues.map(issue => `<li>${escHtml(issue)}</li>`).join('');
        const seoA11yLabel = [seoHealth.label || 'SEO', `${Number(seoHealth.score || 0)}/100`].concat(seoIssues).join('. ');
        const contentEditorHref = EditorConfig.isNewsPost
            ? '/admin/dashboard.php?tab=news&post=' + encodeURIComponent(EditorConfig.newsPostId || '')
            : '/admin/dashboard.php#page/' + encodeURIComponent(EditorConfig.currentPage || '');
        const adminBaseUrl = window.NB_ADMIN_BASE_URL || '/admin/';
        const logoutUrl = new URL(adminBaseUrl, window.location.origin);
        logoutUrl.searchParams.set('logout', '1');
        logoutUrl.searchParams.set('redirect', window.location.pathname + window.location.search + window.location.hash);
        // The guide (Settings → Modules → Guide) opens in a new tab next to the page being edited
        const handbookHtml = window.NB_HANDBOOK_ENABLED === false ? '' : `
            <span class="admin-bar-divider admin-bar-divider--handbook" aria-hidden="true"></span>
            <a href="${escHtml(adminBaseUrl)}handbook" class="admin-bar-link admin-bar-link--handbook" target="_blank" rel="noopener" title="${escHtml(t('handbook.link_title'))}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><path d="M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2zM22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7z"/></svg> <span class="admin-bar-link-label">${escHtml(t('handbook.link'))}</span></a>`;
        const seoHtml = `
            <a class="admin-bar-seo admin-bar-seo--${escHtml(seoHealth.status || 'yellow')}" href="${contentEditorHref}" aria-label="${escHtml(seoA11yLabel)}">
                <span class="admin-bar-seo-dot" aria-hidden="true"></span>
                <span class="admin-bar-seo-label">${escHtml(seoHealth.label || 'SEO')}</span>
                <span class="admin-bar-seo-score">${Number(seoHealth.score || 0)}/100</span>
                <div class="admin-bar-seo-popover" role="tooltip">
                    <strong>${escHtml(seoHealth.label || 'SEO')}</strong>
                    <ul>${seoDetail}</ul>
                </div>
            </a>
        `;
        bar.innerHTML = `
            <div class="admin-bar-inner">
                <div class="admin-bar-left">
                    ${logoHtml}
                    <a href="/admin/dashboard" class="admin-bar-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="14" height="14"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></g></svg> ${t('dashboard')}</a>
                    ${handbookHtml}
                </div>
                <div class="admin-bar-center">
                    <div class="admin-bar-backups" id="admin-page-backups" role="group" aria-label="${escHtml(t(EditorConfig.isNewsPost ? 'post_backups.label' : 'page_backups.label'))}" hidden>
                        <select class="admin-bar-backup-select" id="admin-backup-select" data-native-select aria-label="${escHtml(t(EditorConfig.isNewsPost ? 'post_backups.label' : 'page_backups.label'))}"></select>
                        <button type="button" class="admin-bar-btn" id="admin-btn-restore-backup">${t('page_backups.apply')}</button>
                    </div>
                    <span class="admin-bar-info" id="admin-bar-info" role="status" aria-live="polite" aria-atomic="true"></span>
                    <div class="admin-bar-edit-controls" style="display:none;">
                        <button type="button" class="admin-bar-btn admin-bar-btn-undo" id="admin-btn-undo" disabled title="${t('undo')} (${navigator.platform.toUpperCase().indexOf('MAC') >= 0 ? '⌘' : 'Ctrl'}+Z)">
                            ${Icons.undo}
                        </button>
                        <button type="button" class="admin-bar-btn admin-bar-btn-redo" id="admin-btn-redo" disabled title="${t('redo')} (${navigator.platform.toUpperCase().indexOf('MAC') >= 0 ? '⇧⌘' : 'Ctrl+Shift+'}Z)">
                            ${Icons.redo}
                        </button>
                    </div>
                    <div class="admin-bar-edit-controls" style="display:none;">
                        <button type="button" class="admin-bar-btn admin-bar-btn-cancel" id="admin-btn-cancel">
                            ${t('cancel')}
                        </button>
                        <button type="button" class="admin-bar-btn admin-bar-btn-save" id="admin-btn-save">
                            ${Icons.save} ${t('save')}
                        </button>
                        <button type="button" class="admin-bar-btn admin-bar-btn-save admin-bar-btn-save-exit" id="admin-btn-save-exit">
                            ${Icons.save} ${t('save_exit')}
                        </button>
                    </div>
                    <div class="admin-bar-actions">
                        <button type="button" class="admin-bar-btn admin-bar-btn-edit" id="admin-btn-edit">
                            ${t('visual_editor')}
                        </button>
                        <a href="${contentEditorHref}" class="admin-bar-btn admin-bar-btn-content-editor" id="admin-btn-content-editor">
                            ${t('content_editor')}
                        </a>
                    </div>
                </div>
                <div class="admin-bar-right">
                    ${seoHtml}
                    <a href="${logoutUrl.toString()}" class="admin-bar-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg> ${t('logout')}</a>
                </div>
            </div>
        `;
        document.body.insertBefore(bar, document.body.firstChild);
        const barLogo = bar.querySelector('.admin-bar-logo-icon');
        if (barLogo) markAdminBarLogo(barLogo, brandLogo);
        document.body.classList.add('has-admin-bar');

        // Rounded down: whatever is placed below the bar (sticky headers, body padding) may tuck under
        // its last fraction of a pixel, but must never leave a gap the content shows through.
        const syncAdminBarHeight = () => {
            document.documentElement.style.setProperty('--nibbly-admin-bar-height', Math.floor(bar.getBoundingClientRect().height) + 'px');
        };
        syncAdminBarHeight();
        if (typeof ResizeObserver !== 'undefined') {
            const barObserver = new ResizeObserver(syncAdminBarHeight);
            barObserver.observe(bar);
        } else {
            window.addEventListener('resize', syncAdminBarHeight);
        }

        // Button Event Listeners
        document.getElementById('admin-btn-edit').addEventListener('click', enterEditMode);
        document.getElementById('admin-btn-save').addEventListener('click', () => saveAllChanges({ exitAfterSave: false }));
        document.getElementById('admin-btn-save-exit').addEventListener('click', () => saveAllChanges({ exitAfterSave: true }));
        document.getElementById('admin-btn-cancel').addEventListener('click', () => exitEditMode(false));
        document.getElementById('admin-btn-undo').addEventListener('click', undo);
        document.getElementById('admin-btn-redo').addEventListener('click', redo);
        document.getElementById('admin-btn-restore-backup').addEventListener('click', () => PageBackups.requestRestore());
        document.getElementById('admin-backup-select').addEventListener('change', () => PageBackups.update());
        void PageBackups.load();
    }

    function updateAdminBarMode(editing) {
        const actions = document.querySelectorAll('.admin-bar-actions');
        const editControls = document.querySelectorAll('.admin-bar-edit-controls');
        const infoEl = document.getElementById('admin-bar-info');

        actions.forEach(el => el.style.display = editing ? 'none' : '');
        editControls.forEach(el => el.style.display = editing ? '' : 'none');

        if (infoEl) {
            infoEl.textContent = editing
                ? t('edit_mode_hint')
                : '';
        }
    }

    function updateUndoRedoButtons() {
        const undoBtn = document.getElementById('admin-btn-undo');
        const redoBtn = document.getElementById('admin-btn-redo');
        if (undoBtn) undoBtn.disabled = EditorConfig.undoStack.length === 0;
        if (redoBtn) redoBtn.disabled = EditorConfig.redoStack.length === 0;
        document.querySelectorAll('#admin-btn-save, #admin-btn-save-exit').forEach(saveBtn => {
            if (EditorConfig.dirtyPages.size > 0) {
                saveBtn.classList.add('has-changes');
            } else {
                saveBtn.classList.remove('has-changes');
            }
        });
    }

    // ============================================================
    // EDIT MODE — Enter / Exit / Save All / Undo / Redo
    // ============================================================

    // --- Navigation guard for unsaved changes ---

    function beforeUnloadGuard(e) {
        if (EditorConfig.editMode) {
            e.preventDefault();
            e.returnValue = '';
        }
    }

    function navClickGuard(e) {
        if (!EditorConfig.editMode) return;

        // Only intercept real navigation links (not editor buttons, anchors, etc.)
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href');

        // Editable links must be intercepted in the capture phase. Page-level
        // hash/smooth-scroll handlers may be registered on the link itself
        // before the editor's direct click handler.
        if (link.hasAttribute('data-editable-link')) {
            e.preventDefault();
            e.stopPropagation();
            openLinkEditor(link);
            return;
        }

        if (!href || href === '#' || href.startsWith('#') || href.startsWith('javascript:')) return;

        // Don't block admin bar buttons (they have their own logic)
        if (link.closest('.admin-bar')) return;
        // Don't block editor modal links
        if (link.closest('.editor-modal')) return;
        // Don't block footer editable fields (handled by footer editor)
        if (link.classList.contains('editable-footer-field')) return;
        // Editable fields inside ordinary links must enter the editor before
        // the navigation guard can turn the click into a leave-page prompt.
        if (e.target.closest('.editable-field, .editable-field-wrapper, [data-editable-image]')) return;
        // Images of post blocks (image, gallery) open the library; their lightbox link is not followed
        if (e.target.closest('img.news-editable')) return;
        // Links inside an editable post text (blocks, legacy content field): only place the caret
        if (e.target.closest('.news-editable[contenteditable="true"]')) {
            e.preventDefault();
            return;
        }

        const groupedLink = link.closest('[data-editable-group]');
        if (groupedLink && !link.hasAttribute('data-editable-link')) {
            e.preventDefault();
            e.stopPropagation();
            openGroupEditor(groupedLink);
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        const hasDirty = EditorConfig.dirtyPages.size > 0;
        const message = hasDirty
            ? t('nav.unsaved_changes')
            : t('nav.edit_mode_active');

        showConfirmDialog(t('nav.leave_title'), message, '', function() {
            window.removeEventListener('beforeunload', beforeUnloadGuard);
            document.removeEventListener('click', navClickGuard, true);
            window.location.href = link.href;
        }, t('nav.leave_confirm'), 'editor-btn-primary');
    }

    function enterEditMode() {
        EditorConfig.editMode = true;
        EditorConfig.originalPageData = JSON.parse(JSON.stringify(EditorConfig.contentData));
        EditorConfig.dirtyPages = new Set();
        EditorConfig.undoStack = [];
        EditorConfig.redoStack = [];

        document.body.classList.add('edit-mode-active', 'visual-editing');
        updateAdminBarMode(true);
        updateUndoRedoButtons();

        // Enable news post inline editing
        if (EditorConfig.isNewsPost) {
            enableNewsPostEditing();
        } else {
            enablePageBlockEditing();
        }

        // Register keyboard shortcuts
        document.addEventListener('keydown', handleEditModeKeyboard);

        // Guard against accidental navigation with unsaved changes
        window.addEventListener('beforeunload', beforeUnloadGuard);
        document.addEventListener('click', navClickGuard, true);
        startSessionKeepalive();
    }

    function exitEditMode(save) {
        if (save) {
            // saveAllChanges handles the exit after successful save
            saveAllChanges({ exitAfterSave: true });
            return;
        }

        // Cancel: discard changes — reload gets clean state from server
        EditorConfig.editMode = false;
        EditorConfig.dirtyPages = new Set();
        EditorConfig.undoStack = [];
        EditorConfig.redoStack = [];

        document.removeEventListener('keydown', handleEditModeKeyboard);
        window.removeEventListener('beforeunload', beforeUnloadGuard);
        document.removeEventListener('click', navClickGuard, true);
        stopSessionKeepalive();
        cleanupActiveEditorState();
        document.body.classList.remove('edit-mode-active', 'visual-editing');
        updateAdminBarMode(false);

        // Disable news post editing
        if (EditorConfig.isNewsPost) {
            disableNewsPostEditing();
        } else {
            disablePageBlockEditing();
        }

        // If there were unsaved changes, reload to restore original content
        if (Object.keys(EditorConfig.originalPageData).length > 0 || (EditorConfig.isNewsPost && EditorConfig.dirtyPages.size > 0)) {
            // Restore contentData to original
            EditorConfig.contentData = JSON.parse(JSON.stringify(EditorConfig.originalPageData));
            // Reload to restore DOM to server state
            location.reload();
        }
    }

    async function saveAllChanges(options = {}) {
        if (PageBackups.restoring || EditorConfig.saving) return false;
        const exitAfterSave = options.exitAfterSave === true;
        flushActiveInlineEdits();

        // For news posts, check if the news post data is dirty
        const hasNewsChanges = EditorConfig.isNewsPost && EditorConfig.dirtyPages.has('__news_post__');
        const hasPageChanges = Array.from(EditorConfig.dirtyPages).some(p => p !== '__news_post__');

        if (EditorConfig.dirtyPages.size === 0) {
            showToast(t('toast.no_changes'), 'info');
            if (exitAfterSave) {
                exitEditModeClean();
            } else {
                markSavedStateClean();
            }
            return;
        }

        EditorConfig.saving = true;
        PageBackups.update();
        const saveButtons = Array.from(document.querySelectorAll('#admin-btn-save, #admin-btn-save-exit'));
        saveButtons.forEach(saveBtn => {
            saveBtn.disabled = true;
        });
        const activeSaveBtn = exitAfterSave
            ? document.getElementById('admin-btn-save-exit')
            : document.getElementById('admin-btn-save');
        if (activeSaveBtn) {
            activeSaveBtn.textContent = exitAfterSave ? t('saving_exit') : t('saving');
        }

        try {
            let allSuccess = true;

            // Save news post if dirty
            if (hasNewsChanges && EditorConfig.newsPostData) {
                const postEl = document.querySelector('[data-news-post]');
                if (postEl) collectNewsPostFields(postEl);

                const formData = new FormData();
                formData.append('action', 'save-news');
                formData.append('post', JSON.stringify(EditorConfig.newsPostData));
                formData.append('csrf_token', EditorConfig.csrfToken);

                const response = await fetch(EditorConfig.apiUrl, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (!result.success) {
                    showToast(t('toast.error_saving', { page: 'news', message: result.message || 'Unknown' }), 'error');
                    allSuccess = false;
                }
            }

            // Save page content
            if (allSuccess && hasPageChanges) {
                const pages = Array.from(EditorConfig.dirtyPages).filter(p => p !== '__news_post__');
                for (const page of pages) {
                    const pageData = EditorConfig.contentData[page];
                    if (!pageData) continue;

                    const formData = new FormData();
                    formData.append('action', 'save');
                    formData.append('page', page);
                    formData.append('content', JSON.stringify(pageData));
                    formData.append('csrf_token', EditorConfig.csrfToken);

                    const response = await fetch(EditorConfig.apiUrl, {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    if (result.success && result.revision) EditorConfig.contentRevisions[page] = result.revision;

                    if (!result.success) {
                        showToast(t('toast.error_saving', { page, message: result.message || 'Unknown' }), 'error');
                        allSuccess = false;
                        break;
                    }
                }
            }

            if (allSuccess) {
                void PageBackups.load();
                showToast(t('toast.saved'), 'success');
                if (exitAfterSave) {
                    exitEditModeClean();
                    // Reload to ensure server-rendered HTML matches saved data
                    setTimeout(() => location.reload(), 500);
                } else {
                    markSavedStateClean();
                }
            }
        } catch (error) {
            console.error('Save all error:', error);
            showToast(t('toast.error_saving_short'), 'error');
        } finally {
            EditorConfig.saving = false;
            PageBackups.update();
            saveButtons.forEach(saveBtn => {
                saveBtn.disabled = false;
            });
            const keepEditingSaveBtn = document.getElementById('admin-btn-save');
            if (keepEditingSaveBtn) {
                keepEditingSaveBtn.innerHTML = Icons.save + ' ' + t('save');
            }
            const exitSaveBtn = document.getElementById('admin-btn-save-exit');
            if (exitSaveBtn) {
                exitSaveBtn.innerHTML = Icons.save + ' ' + t('save_exit');
            }
        }
    }

    function markSavedStateClean() {
        EditorConfig.originalPageData = JSON.parse(JSON.stringify(EditorConfig.contentData));
        EditorConfig.dirtyPages = new Set();
        EditorConfig.undoStack = [];
        EditorConfig.redoStack = [];
        updateUndoRedoButtons();
    }

    function flushActiveInlineEdits() {
        if (activeHtmlField && activeHtmlField.isContentEditable) {
            finishInlineHtmlEdit(activeHtmlField, activeHtmlField._originalHtml || '', false);
        }

        document.querySelectorAll('.editable-field-editing').forEach(field => {
            if (field === activeHtmlField || field.classList.contains('editable-field-html')) return;
            if (!field.isContentEditable) return;

            finishInlineEdit(field, field._originalText || '');
        });
    }

    function exitEditModeClean() {
        EditorConfig.editMode = false;
        EditorConfig.dirtyPages = new Set();
        EditorConfig.undoStack = [];
        EditorConfig.redoStack = [];
        EditorConfig.originalPageData = {};
        document.removeEventListener('keydown', handleEditModeKeyboard);
        window.removeEventListener('beforeunload', beforeUnloadGuard);
        document.removeEventListener('click', navClickGuard, true);
        stopSessionKeepalive();
        cleanupActiveEditorState();
        document.body.classList.remove('edit-mode-active', 'visual-editing');
        updateAdminBarMode(false);
    }

    function cleanupActiveEditorState() {
        if (activeHtmlField && activeHtmlField.isContentEditable) {
            finishInlineHtmlEdit(activeHtmlField, activeHtmlField._originalHtml || '', false);
        }
        activeHtmlField = null;
        document.querySelectorAll('.editable-field-editing').forEach(field => {
            field.contentEditable = 'false';
            field.classList.remove('editable-field-editing');
        });
        document.querySelectorAll('.is-overlay-active').forEach(el => {
            el.classList.remove('is-overlay-active');
        });
        document.querySelectorAll('.nb-copilot-selected-field').forEach(el => {
            el.classList.remove('nb-copilot-selected-field');
        });
        const toolbar = document.getElementById('inline-editor-toolbar');
        if (toolbar) {
            toolbar.hidden = true;
        }
    }

    // --- Undo / Redo ---

    function pushUndoState() {
        EditorConfig.undoStack.push({
            type: 'snapshot',
            contentData: JSON.parse(JSON.stringify(EditorConfig.contentData))
        });
        if (EditorConfig.undoStack.length > 50) {
            EditorConfig.undoStack.shift();
        }
        EditorConfig.redoStack = [];
        updateUndoRedoButtons();
    }

    function pushReorderUndo(reorderType, params) {
        EditorConfig.undoStack.push({
            type: reorderType,
            ...params
        });
        if (EditorConfig.undoStack.length > 50) {
            EditorConfig.undoStack.shift();
        }
        EditorConfig.redoStack = [];
        updateUndoRedoButtons();
    }

    function undo() {
        if (!EditorConfig.editMode || EditorConfig.undoStack.length === 0) return;

        const entry = EditorConfig.undoStack.pop();

        if (entry.type === 'reorder-list') {
            // Push inverse to redo stack
            EditorConfig.redoStack.push({
                type: 'reorder-list',
                page: entry.page,
                listKey: entry.listKey,
                from: entry.to,
                to: entry.from
            });
            // Execute inverse reorder (no new undo entry)
            executeListReorder(entry.page, entry.listKey, entry.to, entry.from);
        } else if (entry.type === 'reorder-section') {
            EditorConfig.redoStack.push({
                type: 'reorder-section',
                contentPage: entry.contentPage,
                from: entry.to,
                to: entry.from
            });
            executeSectionReorder(entry.to, entry.from, entry.contentPage);
        } else {
            // Snapshot-based undo
            EditorConfig.redoStack.push({
                type: 'snapshot',
                contentData: JSON.parse(JSON.stringify(EditorConfig.contentData))
            });
            EditorConfig.contentData = entry.contentData;
            refreshDomFromContentData();
        }

        recalcDirtyPages();
        updateUndoRedoButtons();
    }

    function redo() {
        if (!EditorConfig.editMode || EditorConfig.redoStack.length === 0) return;

        const entry = EditorConfig.redoStack.pop();

        if (entry.type === 'reorder-list') {
            EditorConfig.undoStack.push({
                type: 'reorder-list',
                page: entry.page,
                listKey: entry.listKey,
                from: entry.to,
                to: entry.from
            });
            executeListReorder(entry.page, entry.listKey, entry.from, entry.to);
        } else if (entry.type === 'reorder-section') {
            EditorConfig.undoStack.push({
                type: 'reorder-section',
                contentPage: entry.contentPage,
                from: entry.to,
                to: entry.from
            });
            executeSectionReorder(entry.from, entry.to, entry.contentPage);
        } else {
            EditorConfig.undoStack.push({
                type: 'snapshot',
                contentData: JSON.parse(JSON.stringify(EditorConfig.contentData))
            });
            EditorConfig.contentData = entry.contentData;
            refreshDomFromContentData();
        }

        recalcDirtyPages();
        updateUndoRedoButtons();
    }

    function recalcDirtyPages() {
        EditorConfig.dirtyPages = new Set();
        for (const page of EditorConfig.loadedPages) {
            const current = JSON.stringify(EditorConfig.contentData[page]);
            const original = JSON.stringify(EditorConfig.originalPageData[page]);
            if (current !== original) {
                EditorConfig.dirtyPages.add(page);
            }
        }
    }

    function refreshDomFromContentData() {
        // Update all editable-field elements from contentData
        document.querySelectorAll('.editable-field').forEach(field => {
            const page = field.dataset.page;
            const fieldKey = field.dataset.field;
            if (!page || !fieldKey) return;

            const pageData = EditorConfig.contentData[page];
            if (!pageData) return;

            const value = getNestedValue(pageData, fieldKey);
            if (value === null || value === undefined) return;

            if (field.classList.contains('editable-field-html')) {
                field.innerHTML = value;
            } else {
                field.textContent = value;
            }
        });

        // Update editable links
        document.querySelectorAll('[data-editable-link]').forEach(link => {
            const page = link.dataset.page;
            const fieldKey = link.dataset.field;
            if (!page || !fieldKey) return;

            const pageData = EditorConfig.contentData[page];
            if (!pageData) return;

            const value = getNestedValue(pageData, fieldKey);
            if (!value || typeof value !== 'object') return;

            if (value.text !== undefined) link.textContent = value.text;
            if (value.href !== undefined) link.setAttribute('href', value.href);
            if (value.target === '_blank') {
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener');
            } else {
                link.removeAttribute('target');
                link.removeAttribute('rel');
            }
        });

        // Update editable images
        document.querySelectorAll('[data-editable-image]').forEach(img => {
            const page = img.dataset.page;
            const fieldKey = img.dataset.field;
            if (!page || !fieldKey) return;

            const pageData = EditorConfig.contentData[page];
            if (!pageData) return;

            const value = getNestedValue(pageData, fieldKey);
            if (!value || typeof value !== 'object') return;

            if (value.src) img.setAttribute('src', value.src);
            if (value.alt !== undefined) img.setAttribute('alt', value.alt);
            syncAiLabel(img);
        });

        // Update non-editable elements bound to content values via contentBindAttrs(),
        // e.g. image previews or repeated labels that show the same field elsewhere
        document.querySelectorAll('[data-nb-bind]').forEach(el => {
            const pageData = EditorConfig.contentData[el.dataset.nbBindPage];
            if (!pageData || !el.dataset.nbBindField) return;
            const value = getNestedValue(pageData, el.dataset.nbBindField);
            if (value === null || value === undefined) return;

            if (el.dataset.nbBind === 'image') {
                const src = typeof value === 'object' ? value.src : value;
                if (src) el.setAttribute('src', src);
                syncAiLabel(el);
            } else if (typeof value !== 'object') {
                if (el.dataset.nbBind === 'html') el.innerHTML = value;
                else el.textContent = value;
            }
        });

        // Update custom editable icon values
        document.querySelectorAll('[data-editable-icon]').forEach(iconEl => {
            const page = iconEl.dataset.page;
            const fieldKey = iconEl.dataset.field;
            if (!page || !fieldKey) return;

            const pageData = EditorConfig.contentData[page];
            if (!pageData) return;

            const value = getNestedValue(pageData, fieldKey);
            if (value === null || value === undefined) return;

            iconEl.textContent = value;
            iconEl.dataset.icon = value;
        });

        // Update visible SVGs for grouped custom components that use icon fields.
        document.querySelectorAll('[data-editable-group]').forEach(group => {
            const page = group.dataset.page;
            const groupKey = group.dataset.group || '';
            if (!page || !groupKey) return;

            const schema = parseGroupSchema(group);
            const iconField = Array.isArray(schema.fields)
                ? schema.fields.find(field => (field.type || '') === 'icon')
                : null;
            if (!iconField) return;

            const pageData = EditorConfig.contentData[page];
            if (!pageData) return;

            const iconId = getNestedValue(pageData, groupFieldPath(groupKey, iconField));
            const svg = group.querySelector('.feature-icon svg, .vibecoding-icon svg, svg');
            if (svg && iconId !== null && iconId !== undefined) {
                svg.innerHTML = getIconMarkup(iconId);
            }
        });

    }

    function getNestedValue(obj, dotKey) {
        const keys = dotKey.split('.');
        let current = obj;
        for (const key of keys) {
            if (!current || typeof current !== 'object') return undefined;
            current = current[key];
        }
        return current;
    }

    // --- Keyboard shortcuts ---

    function handleEditModeKeyboard(e) {
        if (e.defaultPrevented) return;
        if (!EditorConfig.editMode) return;

        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const ctrlKey = isMac ? e.metaKey : e.ctrlKey;

        if (ctrlKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            saveAllChanges();
            return;
        }

        // Don't intercept undo/redo while typing in input/textarea/contenteditable.
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.target.isContentEditable) return;

        if (ctrlKey && !e.shiftKey && e.key === 'z') {
            e.preventDefault();
            undo();
        } else if (ctrlKey && e.shiftKey && (e.key === 'z' || e.key === 'Z')) {
            e.preventDefault();
            redo();
        }
    }

    // ============================================================
    // EDITOR UI - HAUPTMODAL
    // ============================================================

    /**
     * Builds the DOM IDs for an editor field.
     * Uses key+type so that multiple block types with the same field key
     * (e.g. 'src' exists for both image and audio blocks) don't collide.
     */
    function editorFieldIds(field) {
        return {
            fid: `editor-field-${field.key}-${field.type}`,
            iid: `editor-input-${field.key}-${field.type}`,
        };
    }

    /**
     * Renders an editor field HTML snippet based on field definition.
     */
    function renderEditorField(field) {
        const { fid, iid } = editorFieldIds(field);

        switch (field.type) {
            case 'input': {
                const hint = field.hint ? `<small>${field.hint}</small>` : '';
                const preview = field.preview ? `<div id="editor-${field.preview}-preview" class="editor-media-preview"></div>` : '';
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <input type="text" id="${iid}" placeholder="${field.label}...">
                    ${hint}${preview}
                </div>`;
            }

            case 'textarea':
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <textarea id="${iid}" rows="3" placeholder="${field.label}..."></textarea>
                </div>`;

            case 'wysiwyg':
                // The same Visual / Code editor as the dashboard (js/rich-editor.js) takes over the
                // field once the dialog is in the DOM; the textarea stays the source that is read back
                return `<div class="editor-field html-editor" id="${fid}" style="display:none;">
                    <label for="${iid}">${field.label}</label>
                    <textarea id="${iid}" class="section-field" data-key="${escHtml(field.key)}" rows="8"></textarea>
                </div>`;

            case 'select':
                const opts = (field.options || []).map(o =>
                    `<option value="${o.value}">${o.label}</option>`
                ).join('');
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <select id="${iid}">${opts}</select>
                </div>`;

            case 'checkbox':
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label class="editor-checkbox-label">
                        <input type="checkbox" id="${iid}">
                        <span>${field.label}</span>
                    </label>
                </div>`;

            case 'number':
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <input type="number" id="${iid}" placeholder="${field.label}...">
                </div>`;

            case 'url':
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <input type="url" id="${iid}" placeholder="https://...">
                </div>`;

            case 'image':
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <div class="editor-image-row">
                        <input type="text" id="${iid}" placeholder="${escHtml(t('image.path_placeholder'))}" oninput="InlineEditor.updateImagePreview('${iid}')">
                        <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.openImageManager(null, '${iid}')">
                            ${Icons.folder} ${t('image_manager')}
                        </button>
                        <button type="button" class="editor-btn editor-btn-secondary editor-btn-icon" onclick="InlineEditor.clearImageField('${iid}')" aria-label="${escHtml(t('image.remove'))}" data-editor-tooltip="${escHtml(t('image.remove'))}">${Icons.delete}</button>
                    </div>
                    <div id="editor-image-preview-${field.key}" class="editor-image-preview"></div>
                </div>`;

            case 'audio':
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <input type="text" id="${iid}" placeholder="${escHtml(t('audio.path_placeholder'))}" oninput="InlineEditor.updateAudioPreview()">
                    <div class="editor-audio-buttons">
                        <label class="editor-btn editor-btn-primary editor-btn-inline">
                            <input type="file" id="editor-audio-upload-input" accept=".mp3,.wav,.ogg,.m4a,.aac,.flac,audio/*" style="display:none;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/></g></svg>
                            ${t('image.upload')}
                        </label>
                        <button type="button" class="editor-btn editor-btn-secondary editor-btn-inline" onclick="InlineEditor.openAudioManager('${iid}')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/></g></svg>
                            ${t('audio.manager')}
                        </button>
                    </div>
                    <div id="editor-audio-preview" class="editor-media-preview"></div>
                </div>`;

            default:
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <input type="text" id="${iid}" placeholder="${field.label}...">
                </div>`;
        }
    }

    function normalizeGroupField(field) {
        const normalized = { ...field };
        normalized.type = normalized.type || 'text';
        if (!field.type && normalized.key) {
            const key = String(normalized.key).toLowerCase();
            if (/^(image|imagesrc|src|photo|portrait|logo|avatar|cover|thumbnail)$/.test(key)) {
                normalized.type = 'image';
            } else if (/^(link|href|url|cta|button|buttonlink)$/.test(key)) {
                normalized.type = 'link';
            } else if (/^(text|description|desc|intro|body|content|copy|summary)$/.test(key)) {
                normalized.type = 'textarea';
            } else if (/^(icon|iconid|symbol)$/.test(key)) {
                normalized.type = 'icon';
            }
        }
        if (normalized.type === 'text') normalized.type = 'input';
        if (normalized.type === 'html' || normalized.type === 'richtext') normalized.type = 'wysiwyg';
        return normalized;
    }

    function editorLanguageEntries() {
        const languages = EditorConfig.languages && typeof EditorConfig.languages === 'object'
            ? EditorConfig.languages
            : {};
        return Object.entries(languages);
    }

    function editorLanguageCodes() {
        return editorLanguageEntries().map(([code]) => code);
    }

    function editorIsMultilingual() {
        return editorLanguageCodes().length > 1;
    }

    function languageLabel(code) {
        const languages = EditorConfig.languages || {};
        const label = languages[code] || code;
        return String(label || code).toUpperCase();
    }

    function splitLocalizedPath(field) {
        const raw = String(field.path || field.key || '');
        const parts = raw.split('.');
        if (parts.length < 2) return null;
        const lang = parts[parts.length - 1];
        if (!editorLanguageCodes().includes(lang)) return null;
        return {
            base: parts.slice(0, -1).join('.'),
            lang,
        };
    }

    function isLocalizedGroupField(field, page, groupKey) {
        if (field.localized === true || field.multilingual === true || field.translatable === true) {
            return true;
        }
        const path = groupFieldPath(groupKey, normalizeGroupField(field));
        const value = getNestedValue(EditorConfig.contentData[page] || {}, path);
        if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
        const codes = editorLanguageCodes();
        return codes.length > 0 && codes.some(code => Object.prototype.hasOwnProperty.call(value, code));
    }

    function stripLanguageFromLabel(label, lang) {
        const text = String(label || '');
        return text
            .replace(new RegExp('\\s*\\(?\\b' + lang + '\\b\\)?\\s*$', 'i'), '')
            .replace(/\s*[-–—:]\s*$/, '')
            .trim() || text;
    }

    function localizedFieldBaseLabel(field, base, lang) {
        if (field.label) return stripLanguageFromLabel(field.label, lang || '');
        const last = String(base || field.key || field.path || '').split('.').pop();
        return ucwords(last.replace(/[_-]/g, ' '));
    }

    function ucwords(value) {
        return String(value || '').replace(/\b\w/g, c => c.toUpperCase());
    }

    function fieldWithPath(field, path, label) {
        const clone = { ...field, path, key: path, label };
        delete clone.localized;
        delete clone.multilingual;
        delete clone.translatable;
        return clone;
    }

    function buildLocalizedGroupLayout(fields, page, groupKey) {
        const codes = editorLanguageCodes();
        if (!editorIsMultilingual()) {
            return {
                flatFields: fields,
                html: fields.map((field, index) => renderGroupEditorField(field, index)).join('\n')
            };
        }

        const normalFields = [];
        const localizedByBase = new Map();

        fields.forEach((field) => {
            const normalized = normalizeGroupField(field);
            const split = splitLocalizedPath(normalized);

            if (split) {
                if (!localizedByBase.has(split.base)) {
                    localizedByBase.set(split.base, { label: localizedFieldBaseLabel(normalized, split.base, split.lang), byLang: {} });
                }
                localizedByBase.get(split.base).byLang[split.lang] = normalized;
                return;
            }

            if (isLocalizedGroupField(normalized, page, groupKey)) {
                const base = normalized.path || normalized.key || '';
                if (!localizedByBase.has(base)) {
                    localizedByBase.set(base, { label: localizedFieldBaseLabel(normalized, base), byLang: {} });
                }
                codes.forEach(code => {
                    localizedByBase.get(base).byLang[code] = fieldWithPath(normalized, base + '.' + code, localizedFieldBaseLabel(normalized, base));
                });
                return;
            }

            normalFields.push(normalized);
        });

        if (localizedByBase.size === 0) {
            return {
                flatFields: fields,
                html: fields.map((field, index) => renderGroupEditorField(field, index)).join('\n')
            };
        }

        const flatFields = [];
        const normalHtml = normalFields.map((field) => {
            const index = flatFields.length;
            flatFields.push(field);
            return renderGroupEditorField(field, index);
        }).join('\n');

        const defaultLang = codes.includes(EditorConfig.defaultLang) ? EditorConfig.defaultLang : codes[0];
        const tabId = 'group-lang-tabs-' + Date.now();
        const canTranslate = EditorConfig.aiFeaturesEnabled === true;
        const tabsHtml = codes.map(code => {
            const active = code === defaultLang;
            return `<button type="button" class="editor-lang-tab${active ? ' active' : ''}" id="${tabId}-tab-${escHtml(code)}" role="tab" aria-selected="${active ? 'true' : 'false'}" aria-controls="${tabId}-panel-${escHtml(code)}" tabindex="${active ? '0' : '-1'}" data-editor-lang-tab="${escHtml(code)}">${escHtml(languageLabel(code))}</button>`;
        }).join('');

        let panelsHtml = '';
        codes.forEach(code => {
            const active = code === defaultLang;
            let panelFields = '';
            localizedByBase.forEach((group, base) => {
                const field = group.byLang[code] || fieldWithPath(group.byLang[defaultLang] || {}, base + '.' + code, group.label);
                const index = flatFields.length;
                flatFields.push(field);
                const rendered = renderGroupEditorField(field, index);
                panelFields += rendered.replace(
                    /^<div class="([^"]*)"/,
                    `<div class="$1" data-editor-lang="${escHtml(code)}" data-editor-lang-field="${escHtml(base)}"`
                );
            });
            panelsHtml += `<div class="editor-lang-panel${active ? ' active' : ''}" id="${tabId}-panel-${escHtml(code)}" role="tabpanel" aria-labelledby="${tabId}-tab-${escHtml(code)}" data-editor-lang-panel="${escHtml(code)}"${active ? '' : ' hidden'}>${panelFields}</div>`;
        });

        const translateButton = canTranslate
            ? `<button type="button" class="editor-lang-translate-btn" data-editor-lang-translate>${escHtml(tFallback('editor.translate_from_active', 'Translate from active language'))}</button>`
            : '';

        const langHtml = `<section class="editor-lang-section" data-editor-lang-section>
            <div class="editor-lang-header">
                <div class="editor-lang-tabs" role="tablist" aria-label="${escHtml(tFallback('editor.language_tabs', 'Languages'))}">${tabsHtml}</div>
                ${translateButton}
            </div>
            ${panelsHtml}
        </section>`;

        return { flatFields, html: normalHtml + langHtml };
    }

    function setupEditorLanguageTabs(root) {
        root.querySelectorAll('[data-editor-lang-section]').forEach(section => {
            const tabs = Array.from(section.querySelectorAll('[data-editor-lang-tab]'));
            const panels = Array.from(section.querySelectorAll('[data-editor-lang-panel]'));
            const activate = (lang, focus = false) => {
                tabs.forEach(tab => {
                    const active = tab.dataset.editorLangTab === lang;
                    tab.classList.toggle('active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    tab.tabIndex = active ? 0 : -1;
                    if (active && focus) tab.focus();
                });
                panels.forEach(panel => {
                    const active = panel.dataset.editorLangPanel === lang;
                    panel.hidden = !active;
                    panel.classList.toggle('active', active);
                });
            };

            tabs.forEach((tab, index) => {
                tab.addEventListener('click', () => activate(tab.dataset.editorLangTab));
                tab.addEventListener('keydown', e => {
                    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
                    e.preventDefault();
                    let next = index;
                    if (e.key === 'ArrowLeft') next = index === 0 ? tabs.length - 1 : index - 1;
                    if (e.key === 'ArrowRight') next = index === tabs.length - 1 ? 0 : index + 1;
                    if (e.key === 'Home') next = 0;
                    if (e.key === 'End') next = tabs.length - 1;
                    activate(tabs[next].dataset.editorLangTab, true);
                });
            });

            const translateBtn = section.querySelector('[data-editor-lang-translate]');
            if (translateBtn) {
                translateBtn.addEventListener('click', () => translateEditorLanguageSection(section, translateBtn));
            }
        });
    }

    function getActiveEditorLanguage(section) {
        return section.querySelector('[data-editor-lang-tab].active')?.dataset.editorLangTab
            || EditorConfig.defaultLang
            || editorLanguageCodes()[0];
    }

    function readSimpleEditorInput(fieldEl) {
        const input = fieldEl.querySelector('textarea, input:not([type="hidden"]), select');
        if (!input || input.type === 'checkbox') return null;
        return input.value || '';
    }

    function writeSimpleEditorInput(fieldEl, value) {
        const input = fieldEl.querySelector('textarea, input:not([type="hidden"]), select');
        if (!input || input.type === 'checkbox') return;
        input.value = value == null ? '' : String(value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    async function translateEditorLanguageSection(section, button) {
        if (!EditorConfig.aiFeaturesEnabled) return;
        const sourceLang = getActiveEditorLanguage(section);
        const targetLangs = editorLanguageCodes().filter(code => code !== sourceLang);
        if (!targetLangs.length) return;

        const sourceFields = {};
        section.querySelectorAll(`[data-editor-lang="${CSS.escape(sourceLang)}"][data-editor-lang-field]`).forEach(fieldEl => {
            const value = readSimpleEditorInput(fieldEl);
            if (value !== null && String(value).trim() !== '') {
                sourceFields[fieldEl.dataset.editorLangField] = value;
            }
        });
        if (Object.keys(sourceFields).length === 0) {
            showToast(tFallback('editor.translate_no_source', 'No source text to translate.'), 'error');
            return;
        }

        const previousText = button.textContent;
        button.disabled = true;
        button.textContent = tFallback('editor.translating', 'Translating...');
        try {
            const prompt = [
                'Translate these nibbly editor fields from ' + sourceLang + ' into the requested target languages.',
                'Return strict JSON only. Shape: {"translations":{"LANG":{"field.path":"translated value"}}}.',
                'Keep HTML tags if present. Do not add Markdown or explanations.',
                '',
                JSON.stringify({ sourceLang, targetLangs, fields: sourceFields }, null, 2)
            ].join('\n');
            const formData = new FormData();
            formData.append('action', 'ai-generate-text');
            formData.append('prompt', prompt);
            formData.append('maxOutputTokens', '1800');
            formData.append('csrf_token', EditorConfig.csrfToken);
            const response = await fetch(EditorConfig.apiUrl, { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || tFallback('toast.error', 'Error'));
            let payload = {};
            try {
                payload = JSON.parse(String(result.data?.text || '').replace(/^```json\s*|\s*```$/g, '').trim());
            } catch (e) {
                throw new Error(tFallback('editor.translate_invalid_response', 'AI returned an unreadable translation.'));
            }
            const translations = payload.translations || payload;
            targetLangs.forEach(lang => {
                const langTranslations = translations[lang] || {};
                Object.entries(langTranslations).forEach(([base, value]) => {
                    const fieldEl = section.querySelector(`[data-editor-lang="${CSS.escape(lang)}"][data-editor-lang-field="${CSS.escape(base)}"]`);
                    if (fieldEl) writeSimpleEditorInput(fieldEl, value);
                });
            });
            showToast(tFallback('editor.translate_done', 'Translations inserted.'), 'success');
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            button.disabled = false;
            button.textContent = previousText;
        }
    }

    function getAvailableIconsForField(field = {}) {
        const allIcons = window.NB_AVAILABLE_ICONS && typeof window.NB_AVAILABLE_ICONS === 'object'
            ? window.NB_AVAILABLE_ICONS
            : {};
        const allowed = Array.isArray(field.icons) ? field.icons : null;
        if (!allowed || allowed.length === 0) return allIcons;

        return allowed.reduce((icons, key) => {
            if (Object.prototype.hasOwnProperty.call(allIcons, key)) {
                icons[key] = allIcons[key];
            }
            return icons;
        }, {});
    }

    // NB_AVAILABLE_ICONS carries fully rendered <svg> markup (viewBox, fill/stroke style and stroke width
    // resolved server-side); older inner-markup values are still wrapped for compatibility.
    function iconSvgHtml(markup) {
        const svg = String(markup || '');
        if (/^\s*<svg\b/i.test(svg)) return svg;
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${svg}</svg>`;
    }

    function getIconMarkup(iconId) {
        const icons = window.NB_AVAILABLE_ICONS && typeof window.NB_AVAILABLE_ICONS === 'object'
            ? window.NB_AVAILABLE_ICONS
            : {};
        return icons[iconId] || icons.default || '';
    }

    function groupFieldPath(groupKey, field) {
        if (field.path) return field.path;
        const key = field.key || '';
        if (!groupKey) return key;
        if (!key) return groupKey;
        if (key === groupKey || key.startsWith(groupKey + '.')) return key;
        return groupKey + '.' + key;
    }

    function groupFieldIds(index, suffix = '') {
        const id = `group-editor-input-${index}${suffix ? '-' + suffix : ''}`;
        return { id, fieldId: `group-editor-field-${index}` };
    }

    function renderGroupEditorField(field, index) {
        const normalized = normalizeGroupField(field);
        const { id, fieldId } = groupFieldIds(index);
        const label = escHtml(normalized.label || normalized.key || normalized.path || '');
        const hint = normalized.hint ? `<small>${escHtml(normalized.hint)}</small>` : '';

        switch (normalized.type) {
            case 'icon': {
                const icons = getAvailableIconsForField(normalized);
                const iconButtons = Object.entries(icons).map(([key, markup]) => `
                    <button type="button" class="group-icon-option" data-group-icon-value="${escHtml(key)}" title="${escHtml(key)}" aria-label="${escHtml(key)}">
                        ${iconSvgHtml(markup)}
                        <span>${escHtml(key)}</span>
                    </button>
                `).join('');

                return `<div class="editor-field group-editor-field" id="${fieldId}" data-group-field-index="${index}">
                    <label>${label}</label>
                    <input type="hidden" id="${id}">
                    <div class="group-icon-picker" data-group-icon-picker="${index}">
                        <div class="group-icon-selected">
                            <span class="group-icon-selected-preview" id="group-editor-icon-preview-${index}"></span>
                            <span class="group-icon-selected-label" id="group-editor-icon-label-${index}"></span>
                        </div>
                        <input type="search" class="group-icon-search" data-group-icon-search="${index}" placeholder="${escHtml(t('icons.search_placeholder'))}">
                        <div class="group-icon-grid">${iconButtons}</div>
                    </div>
                    ${hint}
                </div>`;
            }

            case 'textarea':
                return `<div class="editor-field group-editor-field" id="${fieldId}" data-group-field-index="${index}">
                    <label>${label}</label>
                    <textarea id="${id}" rows="3" placeholder="${label}..."></textarea>
                    ${hint}
                </div>`;

            case 'wysiwyg':
                return `<div class="editor-field group-editor-field" id="${fieldId}" data-group-field-index="${index}">
                    <label>${label}</label>
                    <textarea id="${id}" class="editor-html group-editor-html" rows="6"></textarea>
                    ${hint}
                </div>`;

            case 'select': {
                const opts = (normalized.options || []).map(o =>
                    `<option value="${escHtml(o.value || '')}">${escHtml(o.label || o.value || '')}</option>`
                ).join('');
                return `<div class="editor-field group-editor-field" id="${fieldId}" data-group-field-index="${index}">
                    <label>${label}</label>
                    <select id="${id}">${opts}</select>
                    ${hint}
                </div>`;
            }

            case 'checkbox':
                return `<div class="editor-field group-editor-field" id="${fieldId}" data-group-field-index="${index}">
                    <label class="editor-checkbox-label">
                        <input type="checkbox" id="${id}">
                        <span>${label}</span>
                    </label>
                    ${hint}
                </div>`;

            case 'number':
                return `<div class="editor-field group-editor-field" id="${fieldId}" data-group-field-index="${index}">
                    <label>${label}</label>
                    <input type="number" id="${id}" placeholder="${label}...">
                    ${hint}
                </div>`;

            case 'url':
                return `<div class="editor-field group-editor-field" id="${fieldId}" data-group-field-index="${index}">
                    <label>${label}</label>
                    <input type="url" id="${id}" placeholder="https://...">
                    ${hint}
                </div>`;

            case 'link': {
                const textId = groupFieldIds(index, 'text').id;
                const hrefId = groupFieldIds(index, 'href').id;
                const targetId = groupFieldIds(index, 'target').id;
                return `<div class="editor-field group-editor-field group-editor-special group-editor-link" id="${fieldId}" data-group-field-index="${index}">
                    <div class="group-special-header">
                        <span class="group-special-icon">${Icons.link}</span>
                        <label>${label}</label>
                    </div>
                    <div class="group-link-grid">
                        <label class="group-special-control">
                            <span>Text</span>
                            <input type="text" id="${textId}" placeholder="${escHtml(t('footer.link_text'))}">
                        </label>
                        <label class="group-special-control group-special-control--target">
                            <span>Target</span>
                            ${buildPagePickerHtml(groupFieldIds(index, 'page').id, hrefId)}
                        </label>
                    </div>
                    <label class="link-editor-option group-link-option">
                        <input type="checkbox" id="${targetId}">
                        <span>${t('link_open_new_tab') || 'Open in new tab'}</span>
                    </label>
                    ${hint}
                </div>`;
            }

            case 'image': {
                const srcId = groupFieldIds(index, 'src').id;
                const altId = groupFieldIds(index, 'alt').id;
                return `<div class="editor-field group-editor-field group-editor-special group-editor-image" id="${fieldId}" data-group-field-index="${index}">
                    <div class="group-special-header">
                        <span class="group-special-icon">${Icons.image}</span>
                        <label>${label}</label>
                    </div>
                    <div class="group-image-grid">
                        <div id="group-editor-image-preview-${index}" class="editor-image-preview group-image-preview"></div>
                        <div class="group-image-controls">
                            <div class="editor-image-row group-image-path-row">
                                <input type="text" id="${srcId}" placeholder="${escHtml(t('image.path_placeholder'))}" data-group-image-src="${index}">
                                <button type="button" class="editor-btn editor-btn-secondary" data-group-image-pick="${index}">
                                    ${Icons.folder} ${t('image_manager')}
                                </button>
                                <button type="button" class="editor-btn editor-btn-secondary editor-btn-icon" data-group-image-clear="${index}" aria-label="${escHtml(t('image.remove'))}" data-editor-tooltip="${escHtml(t('image.remove'))}">${Icons.delete}</button>
                            </div>
                            <input type="text" id="${altId}" placeholder="${escHtml(t('image.alt_text'))}" class="group-image-alt">
                        </div>
                    </div>
                    ${hint}
                </div>`;
            }

            default:
                return `<div class="editor-field group-editor-field" id="${fieldId}" data-group-field-index="${index}">
                    <label>${label}</label>
                    <input type="text" id="${id}" placeholder="${label}...">
                    ${hint}
                </div>`;
        }
    }

    function createEditorUI() {
        // Build all field HTML from all block types
        const allFieldsHtml = {};
        const renderedKeys = new Set();

        for (const [type, def] of Object.entries(BlockTypes)) {
            for (const field of (def.fields || [])) {
                const dedupKey = `${field.key}-${field.type}`;
                if (!renderedKeys.has(dedupKey)) {
                    renderedKeys.add(dedupKey);
                    allFieldsHtml[dedupKey] = renderEditorField(field);
                }
            }
        }

        const fieldsHtml = Object.values(allFieldsHtml).join('\n');

        const modal = document.createElement('div');
        modal.id = 'editor-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop" aria-hidden="true"></div>
            <div class="editor-modal-content">
                <div class="editor-modal-header">
                    <h3 id="editor-modal-title">${t('edit')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeModal()" aria-label="${escHtml(tFallback('close', 'Close'))}">&times;</button>
                </div>
                <div class="editor-modal-body">
                    ${fieldsHtml}
                </div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closeModal()">${t('cancel')}</button>
                    <button type="button" class="editor-btn editor-btn-primary" onclick="InlineEditor.saveSection()">${t('save')}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        modal.querySelector('.editor-modal-content').classList.add('nb-rich-host');
        modal.querySelectorAll('.editor-field.html-editor').forEach((fieldEl, i) => window.NbRichEditor?.richEditor(fieldEl, 'dialog-' + i, { markDirty() {}, pushUndoSnapshot() {} }));
        setupDialogA11y(modal, 'editor-modal-title', '.editor-modal-body input, .editor-modal-body textarea, .editor-modal-body select, .editor-close-btn');

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', closeModal);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
        });

        // Attach event listeners for special field types
        const youtubeInput = document.getElementById('editor-input-videoId-input');
        if (youtubeInput) youtubeInput.addEventListener('input', updateYoutubePreview);

        const scInput = document.getElementById('editor-input-trackId-input');
        if (scInput) scInput.addEventListener('input', updateSoundcloudPreview);

        const audioUpload = document.getElementById('editor-audio-upload-input');
        if (audioUpload) audioUpload.addEventListener('change', handleEditorAudioUpload);

        // Resizable Modal
        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    function createGroupEditorUI() {
        if (document.getElementById('editable-group-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'editable-group-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop" aria-hidden="true"></div>
            <div class="editor-modal-content">
                <div class="editor-modal-header">
                    <h3 id="editable-group-modal-title">${t('edit')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeGroupEditor()" aria-label="${escHtml(tFallback('close', 'Close'))}">&times;</button>
                </div>
                <div class="editor-modal-body" id="editable-group-modal-body"></div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closeGroupEditor()">${t('cancel')}</button>
                    <button type="button" class="editor-btn editor-btn-primary" onclick="InlineEditor.saveGroupEditor()">${t('save')}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        setupDialogA11y(modal, 'editable-group-modal-title', '#editable-group-modal-body input, #editable-group-modal-body textarea, #editable-group-modal-body select, .editor-close-btn');
        modal.querySelector('.editor-modal-backdrop').addEventListener('click', closeGroupEditor);
        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    function attachGroupEditHandlers() {
        const groups = document.querySelectorAll('[data-editable-group]');
        if (groups.length === 0) return;

        groups.forEach(group => {
            if (group.dataset.groupEditorReady === 'true') return;
            group.dataset.groupEditorReady = 'true';
            group.classList.add('editable-group-active');

            if (!group.hasAttribute('data-list-index')) {
                const overlay = document.createElement('button');
                overlay.type = 'button';
                overlay.className = 'editable-group-overlay nibbly-editor-group-overlay';
                overlay.innerHTML = `${Icons.edit}<span>${t('edit')}</span>`;
                overlay.title = t('edit');
                overlay.addEventListener('click', (e) => {
                    if (!EditorConfig.editMode) return;
                    e.preventDefault();
                    e.stopPropagation();
                    openGroupEditor(group);
                });
                group.appendChild(overlay);
            }

            group.addEventListener('click', (e) => {
                if (!EditorConfig.editMode) return;
                if (group.hasAttribute('data-list-index')) return;
                if (e.target.closest('.editable-group-overlay')) return;
                if (e.target.closest('.editable-list-overlay')) return;
                if (e.target.closest('.editable-field, [data-editable-link], [data-editable-image]')) return;
                e.preventDefault();
                e.stopPropagation();
                openGroupEditor(group);
            });
        });
    }

    function parseGroupSchema(groupEl) {
        try {
            const parsed = JSON.parse(groupEl.dataset.groupSchema || '{}');
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            console.warn('Invalid editable group schema', e);
            return {};
        }
    }

    function openGroupEditor(groupEl) {
        const page = groupEl.dataset.page;
        const groupKey = groupEl.dataset.group || '';
        if (!page || !groupKey) return;

        const schema = parseGroupSchema(groupEl);
        const fields = Array.isArray(schema.fields) ? schema.fields : [];
        if (fields.length === 0) {
            showToast(t('toast.error_generic', { message: 'Editable group has no fields.' }), 'error');
            return;
        }

        const modal = document.getElementById('editable-group-modal');
        const body = document.getElementById('editable-group-modal-body');
        const title = document.getElementById('editable-group-modal-title');
        const label = schema.label || groupKey.split('.').pop() || groupKey;

        const layout = buildLocalizedGroupLayout(fields, page, groupKey);
        const renderedFields = layout.flatFields || fields;
        EditorConfig.currentGroup = { element: groupEl, page, groupKey, schema, fields: renderedFields };
        title.textContent = formatEditorTitle(label);
        body.innerHTML = layout.html;

        renderedFields.forEach((field, index) => populateGroupEditorField(field, index, page, groupKey));
        setupEditorLanguageTabs(body);
        body.querySelectorAll('[data-group-icon-picker]').forEach(picker => {
            picker.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-group-icon-value]');
                if (!btn) return;
                e.preventDefault();
                const index = parseInt(picker.dataset.groupIconPicker, 10);
                const input = document.getElementById(groupFieldIds(index).id);
                if (input) input.value = btn.dataset.groupIconValue || '';
                updateGroupIconPicker(index, btn.dataset.groupIconValue || '');
            });
        });
        body.querySelectorAll('[data-group-icon-search]').forEach(search => {
            search.addEventListener('input', () => {
                const index = parseInt(search.dataset.groupIconSearch, 10);
                filterGroupIconPicker(index, search.value);
            });
        });
        body.querySelectorAll('[data-group-image-pick]').forEach(btn => {
            btn.addEventListener('click', () => {
                const index = parseInt(btn.dataset.groupImagePick, 10);
                const currentSrcInput = document.getElementById(groupFieldIds(index, 'src').id);
                openImageManager((imagePath) => {
                    const srcInput = document.getElementById(groupFieldIds(index, 'src').id);
                    if (srcInput) srcInput.value = imagePath;
                    updateGroupImagePreview(index, imagePath);
                }, null, currentSrcInput ? currentSrcInput.value : null);
            });
        });
        // Typed path: preview follows; clear button empties path, alt text and preview
        body.querySelectorAll('[data-group-image-src]').forEach(input => {
            input.addEventListener('input', () => updateGroupImagePreview(parseInt(input.dataset.groupImageSrc, 10), input.value.trim()));
        });
        body.querySelectorAll('[data-group-image-clear]').forEach(btn => {
            btn.addEventListener('click', () => {
                const index = parseInt(btn.dataset.groupImageClear, 10);
                const srcInput = document.getElementById(groupFieldIds(index, 'src').id);
                const altInput = document.getElementById(groupFieldIds(index, 'alt').id);
                if (srcInput) srcInput.value = '';
                if (altInput) altInput.value = '';
                updateGroupImagePreview(index, '');
            });
        });

        renderedFields.forEach((field, index) => {
            if (normalizeGroupField(field).type === 'link') {
                initPagePicker(groupFieldIds(index, 'page').id, groupFieldIds(index, 'href').id);
            }
        });

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function populateGroupEditorField(field, index, page, groupKey) {
        const normalized = normalizeGroupField(field);
        const pageData = EditorConfig.contentData[page] || {};
        const path = groupFieldPath(groupKey, normalized);
        const value = getNestedValue(pageData, path);

        if (normalized.type === 'link') {
            const linkValue = value && typeof value === 'object' ? value : { text: '', href: (typeof value === 'string' ? value : '') };
            const textInput = document.getElementById(groupFieldIds(index, 'text').id);
            const hrefInput = document.getElementById(groupFieldIds(index, 'href').id);
            const targetInput = document.getElementById(groupFieldIds(index, 'target').id);
            if (textInput) textInput.value = linkValue.text || '';
            if (hrefInput) hrefInput.value = linkValue.href || '';
            if (targetInput) targetInput.checked = linkValue.target === '_blank';
            setTimeout(() => syncPagePicker(groupFieldIds(index, 'page').id, groupFieldIds(index, 'href').id, linkValue.href || ''), 0);
            return;
        }

        if (normalized.type === 'image') {
            const imgValue = value && typeof value === 'object' ? value : { src: value || '', alt: '' };
            const srcInput = document.getElementById(groupFieldIds(index, 'src').id);
            const altInput = document.getElementById(groupFieldIds(index, 'alt').id);
            if (srcInput) srcInput.value = imgValue.src || '';
            if (altInput) altInput.value = imgValue.alt || '';
            updateGroupImagePreview(index, imgValue.src || '');
            return;
        }

        const input = document.getElementById(groupFieldIds(index).id);
        if (!input) return;
        if (normalized.type === 'checkbox') {
            input.checked = value === true;
        } else if (normalized.type === 'icon') {
            input.value = value || 'default';
            updateGroupIconPicker(index, input.value);
        } else {
            input.value = value ?? '';
        }
    }

    function readGroupEditorField(field, index, groupKey) {
        const normalized = normalizeGroupField(field);
        const path = groupFieldPath(groupKey, normalized);

        if (normalized.type === 'link') {
            const text = document.getElementById(groupFieldIds(index, 'text').id)?.value || '';
            const href = document.getElementById(groupFieldIds(index, 'href').id)?.value || '';
            const target = document.getElementById(groupFieldIds(index, 'target').id)?.checked ? '_blank' : '';
            const value = { text, href };
            if (target) value.target = target;
            return { path, value };
        }

        if (normalized.type === 'image') {
            const src = document.getElementById(groupFieldIds(index, 'src').id)?.value || '';
            const alt = document.getElementById(groupFieldIds(index, 'alt').id)?.value || '';
            return { path, value: { src, alt } };
        }

        const input = document.getElementById(groupFieldIds(index).id);
        const value = normalized.type === 'checkbox' ? !!input?.checked : (input?.value || '');
        return { path, value };
    }

    function updateGroupIconPicker(index, iconId) {
        const picker = document.querySelector(`[data-group-icon-picker="${index}"]`);
        if (!picker) return;

        picker.querySelectorAll('[data-group-icon-value]').forEach(btn => {
            btn.classList.toggle('group-icon-option-active', btn.dataset.groupIconValue === iconId);
        });

        const preview = document.getElementById(`group-editor-icon-preview-${index}`);
        const label = document.getElementById(`group-editor-icon-label-${index}`);
        if (preview) {
            preview.innerHTML = iconSvgHtml(getIconMarkup(iconId));
        }
        if (label) label.textContent = iconId || '';
    }

    function filterGroupIconPicker(index, query) {
        const picker = document.querySelector(`[data-group-icon-picker="${index}"]`);
        if (!picker) return;

        const q = (query || '').trim().toLowerCase();
        picker.querySelectorAll('[data-group-icon-value]').forEach(btn => {
            const key = (btn.dataset.groupIconValue || '').toLowerCase();
            btn.hidden = q !== '' && !key.includes(q);
        });
    }

    function updateGroupImagePreview(index, src) {
        const preview = document.getElementById(`group-editor-image-preview-${index}`);
        if (!preview) return;
        if (!src) {
            preview.innerHTML = '';
            return;
        }
        preview.innerHTML = `<img src="${escHtml(src)}" alt="" onerror="this.style.display='none'">`;
    }

    function closeGroupEditor() {
        const modal = document.getElementById('editable-group-modal');
        if (!modal) return;
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';
        closeDialogA11y(modal);
        EditorConfig.currentGroup = null;
    }

    function saveGroupEditor() {
        const group = EditorConfig.currentGroup;
        if (!group) return;

        const { page, groupKey, fields } = group;
        if (!EditorConfig.contentData[page]) EditorConfig.contentData[page] = {};

        pushUndoState();
        fields.forEach((field, index) => {
            const { path, value } = readGroupEditorField(field, index, groupKey);
            if (!path) return;
            setNestedValue(EditorConfig.contentData[page], path, value);
        });

        EditorConfig.dirtyPages.add(page);
        refreshDomFromContentData();
        updateUndoRedoButtons();
        showToast(t('toast.change_queued'), 'success');
        closeGroupEditor();
    }

    // ============================================================
    // "ADD SECTION" MODAL
    // ============================================================

    function getBlockIcon(type) {
        if (BlockTypes[type]?.iconSvg) return BlockTypes[type].iconSvg;
        const iconMap = {
            person: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/></g></svg>',
            team: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M17 4a4 4 0 0 1 0 8M22 21v-2a7 7 0 0 0-4-6"/></g></svg>',
            text: Icons.text,
            heading: Icons.heading,
            quote: Icons.quote,
            list: Icons.list,
            soundcloud: Icons.soundcloud,
            youtube: Icons.youtube,
            audio: Icons.audio,
            image: Icons.image,
            card: Icons.card,
            divider: '—',
            spacer: Icons.spacer,
        };
        return iconMap[type] || Icons.text;
    }

    function createAddSectionUI() {
        // Group block types by category
        const categories = {};
        for (const [type, def] of Object.entries(BlockTypes)) {
            const cat = def.category || 'other';
            if (!categories[cat]) categories[cat] = [];
            categories[cat].push({ type, ...def });
        }

        // Build category HTML
        let categoriesHtml = '';
        for (const [cat, types] of Object.entries(categories)) {
            const catLabel = CategoryLabels[cat] || cat;
            let buttonsHtml = '';
            for (const t of types) {
                buttonsHtml += `
                    <button type="button" class="add-section-option" data-block-type="${t.type}" onclick="InlineEditor.addSection('${t.type}')">
                        <span class="add-section-icon">${getBlockIcon(t.type)}</span>
                        <span class="add-section-label">${t.label}</span>
                    </button>`;
            }
            categoriesHtml += `
                <div class="add-section-category" data-category="${cat}">
                    <div class="add-section-category-label">${catLabel}</div>
                    <div class="add-section-options">${buttonsHtml}</div>
                </div>`;
        }

        const modal = document.createElement('div');
        modal.id = 'add-section-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop" aria-hidden="true"></div>
            <div class="editor-modal-content editor-modal-small">
                <div class="editor-modal-header">
                    <h3 id="add-section-modal-title">${t('add_block')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeAddModal()" aria-label="${escHtml(tFallback('close', 'Close'))}">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div class="add-section-search">
                        <input type="text" id="add-section-search-input" aria-label="${escHtml(t('search_blocks'))}" placeholder="${t('search_blocks')}" autocomplete="off">
                    </div>
                    ${categoriesHtml}
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        setupDialogA11y(modal, 'add-section-modal-title', '#add-section-search-input');

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', closeAddModal);

        // Search filter
        const searchInput = document.getElementById('add-section-search-input');
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.toLowerCase().trim();
            const allOptions = modal.querySelectorAll('.add-section-option');
            const allCategories = modal.querySelectorAll('.add-section-category');

            allOptions.forEach(btn => {
                const label = btn.querySelector('.add-section-label').textContent.toLowerCase();
                const type = btn.dataset.blockType;
                btn.style.display = (!query || label.includes(query) || type.includes(query)) ? '' : 'none';
            });

            // Hide empty categories
            allCategories.forEach(cat => {
                const visible = cat.querySelectorAll('.add-section-option:not([style*="display: none"])');
                cat.style.display = visible.length ? '' : 'none';
            });
        });

        // Resizable Modal
        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    // ============================================================
    // EDIT HANDLERS MIT DRAG & DROP
    // ============================================================

    function getContentPageForSection(wrapper) {
        const container = wrapper.closest('[data-content-page]');
        if (container) {
            return container.dataset.contentPage;
        }
        return EditorConfig.currentPage;
    }

    function keepOverlayOpenWhileCrossing(owner, overlay) {
        let hideTimer = null;

        const show = () => {
            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }
            owner.classList.add('is-overlay-active');
        };

        const hideIfInactive = () => {
            hideTimer = setTimeout(() => {
                if (owner.matches(':hover') || overlay.matches(':hover') || overlay.contains(document.activeElement)) {
                    return;
                }
                owner.classList.remove('is-overlay-active');
            }, 650);
        };

        owner.addEventListener('mouseenter', show);
        owner.addEventListener('mouseleave', hideIfInactive);
        overlay.addEventListener('mouseenter', show);
        overlay.addEventListener('mouseleave', hideIfInactive);
        overlay.addEventListener('focusin', show);
        overlay.addEventListener('focusout', hideIfInactive);
    }

    function attachEditHandlers() {
        const sections = document.querySelectorAll('.editable-section');

        sections.forEach((wrapper) => {
            if (wrapper.dataset.editorReady === 'true') return;
            wrapper.dataset.editorReady = 'true';
            const index = parseInt(wrapper.dataset.sectionIndex, 10);
            const type = wrapper.dataset.sectionType;
            const contentPage = getContentPageForSection(wrapper);

            // Replace overlay with buttons
            const existingOverlay = wrapper.querySelector('.editable-overlay');
            if (existingOverlay) {
                existingOverlay.remove();
            }

            // New overlay with edit and delete buttons
            const overlay = document.createElement('div');
            overlay.className = 'editable-overlay editable-overlay-buttons nibbly-editor-section-overlay';
            // Check if section is hidden
            const sectionIdx = parseInt(wrapper.dataset.sectionIndex, 10);
            const pageData = EditorConfig.contentData[contentPage];
            const sectionData = pageData && pageData.sections ? pageData.sections[sectionIdx] : null;
            const isHidden = sectionData && sectionData.hidden === true;
            const hideIcon = isHidden ? Icons.eyeClosed : Icons.eyeOpen;
            const hideTitle = isHidden ? t('show') : t('hide');

            overlay.innerHTML = `
                <button type="button" class="overlay-btn overlay-btn-edit nibbly-editor-control-btn" data-editor-tooltip="${escHtml(t('edit'))}" aria-label="${escHtml(t('edit'))}" data-action="edit">${Icons.edit}</button>
                <button type="button" class="overlay-btn overlay-btn-hide nibbly-editor-control-btn" data-editor-tooltip="${escHtml(hideTitle)}" aria-label="${escHtml(hideTitle)}" data-action="hide">${hideIcon}</button>
                <button type="button" class="overlay-btn overlay-btn-delete nibbly-editor-control-btn" data-editor-tooltip="${escHtml(t('delete'))}" aria-label="${escHtml(t('delete'))}" data-action="delete">${Icons.delete}</button>
                <span class="overlay-drag-handle nibbly-editor-drag-handle" data-editor-tooltip="${escHtml(t('drag_reorder'))}" aria-label="${escHtml(t('drag_reorder'))}">${Icons.drag}</span>
            `;
            pageBlockPage = contentPage;
            overlay.querySelector('.overlay-btn-delete').before(...pageSectionTools(wrapper, type, contentPage));
            // gallery, divider: no fields, nothing for the dialog
            if (!(BlockTypes[type]?.fields || []).length) overlay.querySelector('.overlay-btn-edit').remove();
            wrapper.appendChild(overlay);
            keepOverlayOpenWhileCrossing(wrapper, overlay);

            // Apply hidden state
            if (isHidden) {
                wrapper.dataset.hidden = 'true';
            }

            // Drag starts only from the handle so text clicks/selection inside
            // sections are not hijacked by HTML5 drag.
            wrapper.setAttribute('draggable', 'false');
            wrapper.dataset.contentPage = contentPage;

            // Find all iframes in wrapper and disable pointer-events on start
            const iframes = wrapper.querySelectorAll('iframe');
            iframes.forEach(iframe => {
                iframe.style.pointerEvents = 'none';
            });

            // Drag Events
            wrapper.addEventListener('dragstart', handleDragStart);
            wrapper.addEventListener('dragend', handleDragEnd);
            wrapper.addEventListener('dragover', handleDragOver);
            wrapper.addEventListener('drop', handleDrop);
            wrapper.addEventListener('dragenter', handleDragEnter);
            wrapper.addEventListener('dragleave', handleDragLeave);

            // Drag handle as additional trigger
            const dragHandle = overlay.querySelector('.overlay-drag-handle');
            if (dragHandle) {
                const armDrag = () => {
                    wrapper.dataset.dragArmed = 'true';
                    wrapper.setAttribute('draggable', 'true');
                };
                const disarmDrag = () => {
                    delete wrapper.dataset.dragArmed;
                    wrapper.setAttribute('draggable', 'false');
                };
                dragHandle.addEventListener('pointerdown', armDrag);
                dragHandle.addEventListener('mousedown', armDrag);
                dragHandle.addEventListener('pointerup', () => setTimeout(disarmDrag, 0));
            }

            // Click handler for buttons — read index dynamically (survives reorder)
            overlay.addEventListener('click', (e) => {
                const btn = e.target.closest('.overlay-btn');
                if (!btn) return;

                e.preventDefault();
                e.stopPropagation();

                const currentIndex = parseInt(wrapper.dataset.sectionIndex, 10);
                const action = btn.dataset.action;
                if (action === 'edit' && wrapper.querySelector('[data-news-block]')) {
                    // rendered with the post block markup: same dialog, saved and re-rendered like the other block tools
                    pageBlockPage = contentPage;
                    editNewsBlockAt('sections', currentIndex);
                } else if (action === 'edit') {
                    const pageData = EditorConfig.contentData[contentPage];
                    if (pageData && pageData.sections && pageData.sections[currentIndex]) {
                        openEditor(currentIndex, pageData.sections[currentIndex], contentPage);
                    }
                } else if (action === 'hide') {
                    toggleSectionHidden(currentIndex, contentPage, wrapper, btn);
                } else if (action === 'delete') {
                    deleteSection(currentIndex, contentPage);
                }
            });

            // Note: section body clicks no longer open the editor modal.
            // Text content is edited inline via .editable-field handlers; the
            // modal is reserved for structural fields and is opened via the
            // edit button in the overlay (handled above).

            // "Add" buttons below the section
            createAddButtonsForSection(wrapper, index, contentPage);
        });
    }

    function createAddButtonsForSection(wrapper, index, contentPage) {
        // Check if already present
        if (wrapper.querySelector('.section-add-buttons')) return;

        const container = document.createElement('div');
        container.className = 'section-add-buttons';

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'section-add-btn-toggle nibbly-editor-add-button';
        const addLabel = tFallback('add_block', 'Add block');
        addBtn.dataset.editorTooltip = addLabel;
        addBtn.setAttribute('aria-label', addLabel);
        addBtn.setAttribute('aria-expanded', 'false');
        addBtn.innerHTML = Icons.plus;

        addBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!EditorConfig.editMode) return;
            const currentIndex = parseInt(wrapper.dataset.sectionIndex, 10);
            openSectionTypePicker(addBtn, currentIndex, contentPage);
        });

        container.appendChild(addBtn);
        wrapper.appendChild(container);
    }

    // ============================================================
    // SECTION TYPE PICKER POPOVER
    // ============================================================

    function openSectionTypePicker(anchorBtn, afterIndex, contentPage, options) {
        options = options || {};
        // Toggle: close if already open for this anchor
        const existing = document.querySelector('.section-type-picker');
        if (existing) {
            existing.remove();
            if (existing.dataset.anchorId === anchorBtn.dataset.pickerId) {
                anchorBtn.setAttribute('aria-expanded', 'false');
                return;
            }
        }

        const pickerId = 'picker-' + Date.now();
        anchorBtn.dataset.pickerId = pickerId;
        anchorBtn.setAttribute('aria-expanded', 'true');

        // Group types by category, using the BlockTypeRegistry definition order
        const categoryOrder = ['content', 'cards', 'media', 'interactive', 'embed', 'layout'];
        const grouped = {};
        for (const [type, def] of Object.entries(BlockTypes)) {
            if (options.types && !options.types.includes(type)) continue;
            const cat = def.category || 'other';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push({ type, def });
        }

        const picker = document.createElement('div');
        // Few types (news blocks): one flat row without category headings
        const compact = !!options.types && options.types.length <= 4;
        picker.className = 'section-type-picker' + (compact ? ' section-type-picker--compact' : '');
        picker.dataset.anchorId = pickerId;

        const orderedCategories = [
            ...categoryOrder.filter(c => grouped[c]),
            ...Object.keys(grouped).filter(c => !categoryOrder.includes(c))
        ];

        let markup = '';
        for (const cat of orderedCategories) {
            const label = CategoryLabels[cat] || cat;
            markup += compact
                ? `<div class="section-type-picker__category"><div class="section-type-picker__grid">`
                : `<div class="section-type-picker__category"><span class="section-type-picker__category-label">${label}</span><div class="section-type-picker__grid">`;
            for (const { type, def } of grouped[cat]) {
                const icon = def.iconSvg || Icons[def.icon] || Icons[type] || Icons.plus;
                const labelText = def.label || type;
                markup += `<button type="button" class="section-type-picker__item" data-type="${type}" title="${labelText}"><span class="section-type-picker__icon">${icon}</span><span class="section-type-picker__label">${labelText}</span></button>`;
            }
            markup += `</div></div>`;
        }
        picker.innerHTML = markup;

        // Click handler for type selection
        picker.addEventListener('click', (e) => {
            const item = e.target.closest('.section-type-picker__item');
            if (!item) return;
            e.preventDefault();
            e.stopPropagation();
            const type = item.dataset.type;
            closePicker();
            if (options.onSelect) {
                options.onSelect(type);
                return;
            }
            const wrapper = anchorBtn.closest('.editable-section');
            const currentIndex = wrapper ? parseInt(wrapper.dataset.sectionIndex, 10) : afterIndex;
            addSectionAfter(currentIndex, type, contentPage);
        });

        document.body.appendChild(picker);
        positionPicker(picker, anchorBtn);

        // Close on outside click / Escape
        const closePicker = () => {
            picker.remove();
            anchorBtn.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', outsideClick, true);
            document.removeEventListener('keydown', escHandler, true);
            window.removeEventListener('resize', reposition);
            window.removeEventListener('scroll', reposition, true);
        };
        const outsideClick = (e) => {
            if (picker.contains(e.target) || anchorBtn.contains(e.target)) return;
            closePicker();
        };
        const escHandler = (e) => {
            if (e.key === 'Escape') closePicker();
        };
        const reposition = () => positionPicker(picker, anchorBtn);
        // Defer to avoid catching the click that opened it
        setTimeout(() => {
            document.addEventListener('click', outsideClick, true);
            document.addEventListener('keydown', escHandler, true);
            window.addEventListener('resize', reposition);
            window.addEventListener('scroll', reposition, true);
        }, 0);
    }

    function positionPicker(picker, anchor) {
        const rect = anchor.getBoundingClientRect();
        const pickerRect = picker.getBoundingClientRect();
        const margin = 8;
        let top = rect.bottom + margin;
        let left = rect.left + rect.width / 2 - pickerRect.width / 2;

        // Clamp to viewport
        const maxLeft = window.innerWidth - pickerRect.width - margin;
        if (left < margin) left = margin;
        if (left > maxLeft) left = maxLeft;

        // Flip above if not enough space below
        if (top + pickerRect.height > window.innerHeight - margin) {
            const flipped = rect.top - pickerRect.height - margin;
            if (flipped > margin) top = flipped;
        }

        picker.style.top = `${top + window.scrollY}px`;
        picker.style.left = `${left + window.scrollX}px`;
    }

    // ============================================================
    // DRAG & DROP
    // ============================================================

    // Above this height a dragged element is shown as a small label: its full image would hide the drop position
    const DRAG_COMPACT_HEIGHT = 240;
    const DRAG_SCROLL_ZONE = 80;
    const DRAG_SCROLL_MAX_SPEED = 1000; // px per second, independent of the display's frame rate
    const DRAG_SCROLL_MIN_SPEED = 120;

    // Lower edge of the fixed bars: the scroll zone starts below them, a pointer on them is no drop position
    function dragScrollTopEdge() {
        let edge = 0;
        document.querySelectorAll('#admin-bar, header').forEach(bar => {
            const position = getComputedStyle(bar).position;
            if (position === 'fixed' || position === 'sticky') edge = Math.max(edge, bar.getBoundingClientRect().bottom);
        });
        return edge;
    }

    // Sets the drag image and scrolls the page while the pointer is near the top or bottom edge.
    // Runs on its own frames because browsers send dragover irregularly while the pointer rests.
    function beginDragAssist(event, element, type) {
        const rect = element.getBoundingClientRect();
        let marker = null;
        if (rect.height > DRAG_COMPACT_HEIGHT) {
            marker = document.createElement('div');
            marker.className = 'nibbly-drag-marker';
            marker.textContent = element.textContent.trim().replace(/\s+/g, ' ').slice(0, 60) || BlockTypes[type]?.label || t('drag_reorder');
            document.body.appendChild(marker); // has to be rendered to serve as drag image; the CSS keeps it off-screen
            event.dataTransfer.setDragImage(marker, 14, 14);
        } else {
            event.dataTransfer.setDragImage(element, 20, 20);
        }

        let pointerY = null;
        let frame = null;
        let lastTime = null;
        let carry = 0; // fractions of a pixel, or slow scrolling would be lost to rounding
        const onOver = e => { pointerY = e.clientY; };
        const tick = time => {
            const elapsed = lastTime === null ? 0 : Math.min(time - lastTime, 100);
            lastTime = time;
            if (pointerY !== null) {
                const top = dragScrollTopEdge() + DRAG_SCROLL_ZONE;
                const bottom = window.innerHeight - DRAG_SCROLL_ZONE;
                const depth = pointerY < top ? pointerY - top : pointerY > bottom ? pointerY - bottom : 0;
                if (depth) {
                    const speed = Math.max(DRAG_SCROLL_MIN_SPEED, DRAG_SCROLL_MAX_SPEED * Math.min(1, Math.abs(depth) / DRAG_SCROLL_ZONE));
                    carry += Math.sign(depth) * speed * elapsed / 1000;
                    const step = Math.trunc(carry);
                    carry -= step;
                    // not the page's smooth scrolling: it would restart on every frame and hardly move
                    if (step) window.scrollBy({ top: step, behavior: 'instant' });
                }
            }
            frame = requestAnimationFrame(tick);
        };
        const stop = () => {
            cancelAnimationFrame(frame);
            document.removeEventListener('dragover', onOver, true);
            document.removeEventListener('drop', stop, true);
            document.removeEventListener('dragend', stop, true);
            marker?.remove();
        };
        document.addEventListener('dragover', onOver, true);
        document.addEventListener('drop', stop, true);
        document.addEventListener('dragend', stop, true);
        frame = requestAnimationFrame(tick);
    }

    function handleDragStart(e) {
        if (!EditorConfig.editMode) { e.preventDefault(); return; }
        const wrapper = e.target.closest('.editable-section');
        if (!wrapper) return;
        if (wrapper.dataset.dragArmed !== 'true') {
            e.preventDefault();
            return;
        }

        EditorConfig.draggedElement = wrapper;
        EditorConfig.draggedIndex = parseInt(wrapper.dataset.sectionIndex, 10);
        EditorConfig.draggedContentPage = wrapper.dataset.contentPage;

        wrapper.classList.add('dragging');

        // Disable all iframes during drag
        document.querySelectorAll('.editable-section iframe').forEach(iframe => {
            iframe.style.pointerEvents = 'none';
        });

        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', EditorConfig.draggedIndex.toString());

        beginDragAssist(e, wrapper, wrapper.dataset.sectionType);
    }

    function handleDragEnd(e) {
        const wrapper = e.target.closest('.editable-section') || this;
        wrapper.classList.remove('dragging');

        document.querySelectorAll('.editable-section').forEach(el => {
            el.classList.remove('drag-over', 'drag-over-top', 'drag-over-bottom', 'dragging');
        });

        // Re-enable iframes (for playback etc., but in editor mode they stay blocked via CSS)
        // Not necessary, CSS handles this

        EditorConfig.draggedElement = null;
        EditorConfig.draggedIndex = null;
        EditorConfig.draggedContentPage = null;
        delete wrapper.dataset.dragArmed;
        wrapper.setAttribute('draggable', 'false');
    }

    function handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        const targetPage = this.dataset.contentPage;
        if (targetPage !== EditorConfig.draggedContentPage) {
            return;
        }

        const rect = this.getBoundingClientRect();
        const midY = rect.top + rect.height / 2;

        this.classList.remove('drag-over-top', 'drag-over-bottom');
        if (e.clientY < midY) {
            this.classList.add('drag-over-top');
        } else {
            this.classList.add('drag-over-bottom');
        }
    }

    function handleDragEnter(e) {
        const targetPage = this.dataset.contentPage;
        if (targetPage === EditorConfig.draggedContentPage) {
            this.classList.add('drag-over');
        }
    }

    function handleDragLeave(e) {
        this.classList.remove('drag-over', 'drag-over-top', 'drag-over-bottom');
    }

    function handleDrop(e) {
        e.preventDefault();

        const targetPage = this.dataset.contentPage;
        if (targetPage !== EditorConfig.draggedContentPage) {
            showToast(t('toast.sort_same_section'), 'error');
            return;
        }

        const fromIndex = EditorConfig.draggedIndex;
        let toIndex = parseInt(this.dataset.sectionIndex, 10);

        const rect = this.getBoundingClientRect();
        const midY = rect.top + rect.height / 2;
        if (e.clientY > midY && toIndex < fromIndex) {
            toIndex++;
        } else if (e.clientY < midY && toIndex > fromIndex) {
            toIndex--;
        }

        if (fromIndex !== toIndex) {
            reorderSections(fromIndex, toIndex, targetPage);
        }

        this.classList.remove('drag-over', 'drag-over-top', 'drag-over-bottom');
    }

    function reorderSections(fromIndex, toIndex, contentPage) {
        pushReorderUndo('reorder-section', { contentPage, from: fromIndex, to: toIndex });
        executeSectionReorder(fromIndex, toIndex, contentPage);
    }

    function executeSectionReorder(fromIndex, toIndex, contentPage) {
        const pageData = EditorConfig.contentData[contentPage];
        if (!pageData || !pageData.sections) return;

        // Update data
        const sections = pageData.sections;
        const [movedItem] = sections.splice(fromIndex, 1);
        sections.splice(toIndex, 0, movedItem);

        EditorConfig.dirtyPages.add(contentPage);

        // Update DOM
        const allSections = Array.from(
            document.querySelectorAll(`.editable-section[data-content-page="${contentPage}"]`)
        );
        if (allSections[fromIndex]) {
            const movedEl = allSections[fromIndex];
            if (fromIndex < toIndex) {
                const refEl = allSections[toIndex];
                refEl.parentNode.insertBefore(movedEl, refEl.nextSibling);
            } else {
                const refEl = allSections[toIndex];
                refEl.parentNode.insertBefore(movedEl, refEl);
            }
            // Update indices
            disablePageBlockEditing();
            document.querySelectorAll(`.editable-section[data-content-page="${contentPage}"]`).forEach((el, i) => {
                el.dataset.sectionIndex = i;
                const hook = el.querySelector('[data-news-block]');
                if (hook) hook.dataset.newsBlock = 'sections.' + i;
            });
            enablePageBlockEditing();
        }

        showToast(t('toast.order_changed'), 'success');
    }

    // ============================================================
    // ADD SECTION
    // ============================================================

    let addAfterIndex = null;
    let addContentPage = null;

    function openAddModal(afterIndex, contentPage) {
        addAfterIndex = afterIndex;
        addContentPage = contentPage;
        const modal = document.getElementById('add-section-modal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        openDialogA11y(modal);

        // Reset search and show all
        const searchInput = document.getElementById('add-section-search-input');
        if (searchInput) {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
            setTimeout(() => searchInput.focus(), 100);
        }
    }

    function closeAddModal() {
        const modal = document.getElementById('add-section-modal');
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';
        closeDialogA11y(modal);
        addAfterIndex = null;
        addContentPage = null;
    }

    function addSectionAfter(afterIndex, type, contentPage) {
        const pageData = EditorConfig.contentData[contentPage];
        if (!pageData) return;

        pushUndoState();
        const newSection = createNewSection(type, pageData.sections.length);
        const insertIndex = afterIndex + 1;
        pageData.sections.splice(insertIndex, 0, newSection);

        saveAndInsertSection(insertIndex, contentPage, false);
    }

    function addSection(type) {
        if (addAfterIndex === null || !addContentPage) return;

        const pageData = EditorConfig.contentData[addContentPage];
        if (!pageData) return;

        pushUndoState();
        const newSection = createNewSection(type, pageData.sections.length);
        const insertIndex = addAfterIndex + 1;
        pageData.sections.splice(insertIndex, 0, newSection);

        closeAddModal();
        saveAndInsertSection(insertIndex, addContentPage, false);
    }

    function createNewSection(type, count) {
        const id = `new_${type}_${Date.now()}`;
        const def = BlockTypes[type];

        if (def && def.defaults) {
            return { id, type, ...JSON.parse(JSON.stringify(def.defaults)) };
        }
        return { id, type };
    }

    /**
     * Persist contentData, fetch a freshly-rendered .editable-content-area
     * block from the server, replace the existing DOM region in-place and
     * re-wire handlers. Avoids a full page reload so the admin bar and
     * edit-mode state stay intact.
     */
    async function saveAndInsertSection(index, contentPage, shouldPushUndo = true) {
        if (shouldPushUndo) pushUndoState();
        EditorConfig.dirtyPages.add(contentPage);
        updateUndoRedoButtons();

        try {
            const pageData = EditorConfig.contentData[contentPage];
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('page', contentPage);
            formData.append('content', JSON.stringify(pageData));
            formData.append('csrf_token', EditorConfig.csrfToken);
            formData.append('render_sections', '1');

            const response = await fetch(EditorConfig.apiUrl, { method: 'POST', body: formData });
            const result = await response.json();

            if (!result.success) {
                showToast(t('toast.error_generic', { message: result.message || 'Unknown' }), 'error');
                return;
            }

            if (result.data && result.data.sectionsHtml) {
                replaceContentArea(result.data.sectionsHtml, contentPage, index);
                showToast(tFallback('toast.section_added', 'Section added'), 'success');
                EditorConfig.dirtyPages.delete(contentPage);
            } else {
                // Server did not return HTML (shouldn't happen) — fall back to reload.
                sessionStorage.setItem('site-edit-mode', 'true');
                location.reload();
            }
        } catch (error) {
            showToast(t('toast.error_saving', { page: contentPage, message: error.message }), 'error');
        }
    }

    /**
     * Replace the <div class="editable-content-area"> for the given page with
     * freshly-rendered server HTML, then re-wire editor handlers on the new
     * nodes. Scrolls the section at `focusIndex` into view.
     */
    function replaceContentArea(html, contentPage, focusIndex) {
        const oldArea = document.querySelector(`.editable-content-area[data-content-page="${CSS.escape(contentPage)}"]`);
        if (!oldArea) {
            sessionStorage.setItem('site-edit-mode', 'true');
            location.reload();
            return;
        }

        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const newArea = tpl.content.firstElementChild;
        if (!newArea) return;

        oldArea.replaceWith(newArea);

        // Re-wire handlers. attach* functions guard via data-editor-ready,
        // but the new DOM nodes have no such marker so they all get wired.
        attachEditHandlers();
        attachFieldEditHandlers();
        attachLinkEditHandlers();
        attachImageEditHandlers();
        attachListEditHandlers();

        if (EditorConfig.editMode) {
            document.body.classList.add('edit-mode-active', 'visual-editing');
            enablePageBlockEditing();
        }

        const focused = newArea.querySelectorAll('.editable-section')[focusIndex];
        if (focused) focused.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ============================================================
    // HIDE / SHOW SECTION
    // ============================================================

    function toggleSectionHidden(index, contentPage, wrapperEl, btnEl) {
        const pageData = EditorConfig.contentData[contentPage];
        if (!pageData || !pageData.sections || !pageData.sections[index]) return;

        pushUndoState();

        const section = pageData.sections[index];
        const nowHidden = !section.hidden;

        if (nowHidden) {
            section.hidden = true;
            wrapperEl.dataset.hidden = 'true';
        } else {
            delete section.hidden;
            delete wrapperEl.dataset.hidden;
        }

        // Update button icon
        btnEl.innerHTML = nowHidden ? Icons.eyeClosed : Icons.eyeOpen;
        const label = nowHidden ? t('show') : t('hide');
        btnEl.dataset.editorTooltip = label;
        btnEl.setAttribute('aria-label', label);

        EditorConfig.dirtyPages.add(contentPage);
        updateUndoRedoButtons();

        showToast(nowHidden ? t('toast.hidden') : t('toast.visible'), 'success');
    }

    // ============================================================
    // DELETE SECTION
    // ============================================================

    function deleteSection(index, contentPage) {
        const pageData = EditorConfig.contentData[contentPage];
        if (!pageData || !pageData.sections || !pageData.sections[index]) return;

        const section = pageData.sections[index];
        const typeName = BlockTypes[section.type]?.label || section.type;

        showConfirmDialog(
            t('section.delete'),
            t('section.delete_confirm', { type: typeName }),
            t('section.reload_hint'),
            () => {
                pushUndoState();
                pageData.sections.splice(index, 1);
                EditorConfig.dirtyPages.add(contentPage);
                updateUndoRedoButtons();
                saveStructuralChange(contentPage, 'Section deleted');
            }
        );
    }

    // ============================================================
    // OPEN/CLOSE EDITOR
    // ============================================================

    function getSectionTypeName(type) {
        return SectionTypes[type]?.label || type;
    }

    /**
     * Populates an editor field with data from the section.
     */
    function populateEditorField(field, section, defaults = {}) {
        const { fid, iid } = editorFieldIds(field);
        const fieldEl = document.getElementById(fid);
        if (!fieldEl) return;

        fieldEl.style.display = 'block';
        const value = section[field.key] ?? defaults[field.key] ?? '';

        switch (field.type) {
            case 'input':
            case 'number':
            case 'url':
                document.getElementById(iid).value = value;
                break;

            case 'textarea':
                document.getElementById(iid).value = value;
                break;

            case 'wysiwyg': {
                const rich = fieldEl._nbRich;
                if (rich) rich.setValue(value);
                else document.getElementById(iid).value = value;
                break;
            }

            case 'select': {
                const selectEl = document.getElementById(iid);
                // Fall back to first option when no value is set
                if (value) selectEl.value = value;
                else if (selectEl.options.length > 0) selectEl.selectedIndex = 0;
                break;
            }

            case 'checkbox':
                document.getElementById(iid).checked = !!value;
                break;

            case 'image':
                document.getElementById(iid).value = value;
                updateImagePreview(iid);
                break;

            case 'audio':
                document.getElementById(iid).value = value;
                updateAudioPreview();
                break;
        }
    }

    /**
     * Reads an editor field value back.
     */
    function readEditorField(field) {
        const { iid } = editorFieldIds(field);

        switch (field.type) {
            case 'input':
            case 'textarea':
            case 'number':
            case 'url':
            case 'image':
                return (document.getElementById(iid)?.value || '').trim();

            case 'wysiwyg': {
                // The rich-text module (js/rich-editor.js) renames the textarea; its API holds the current value.
                const rich = document.getElementById(editorFieldIds(field).fid)?._nbRich;
                if (rich) return rich.getValue() || '';
                return document.getElementById(iid)?.value || '';
            }

            case 'select':
                return document.getElementById(iid)?.value || '';

            case 'checkbox':
                return document.getElementById(iid)?.checked || false;

            case 'audio':
                return (document.getElementById(iid)?.value || '').trim();

            default:
                return (document.getElementById(iid)?.value || '').trim();
        }
    }

    function formatEditorTitle(label) {
        const template = t('edit_block_title');
        return template && template !== 'edit_block_title'
            ? template.replace('{label}', label)
            : t('edit') + ' ' + label;
    }

    function openEditor(index, section, contentPage) {
        EditorConfig.currentSectionIndex = index;
        EditorConfig.currentSection = { ...section };
        EditorConfig.currentContentPage = contentPage;

        const modal = document.getElementById('editor-modal');
        const modalTitle = document.getElementById('editor-modal-title');

        // Hide all fields
        modal.querySelectorAll('.editor-field').forEach(f => f.style.display = 'none');

        // Resolve type (legacy alias)
        const type = section.type === 'project' ? 'card' : section.type;
        const def = BlockTypes[type];

        if (!def) {
            modalTitle.textContent = formatEditorTitle(type);
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            openDialogA11y(modal);
            return;
        }

        // "Edit section" rather than "Edit Text": the dialog holds title, text and image, not one field
        modalTitle.textContent = t('edit_section_title') !== 'edit_section_title' ? t('edit_section_title') : formatEditorTitle(def.label);

        // Show and populate fields from registry
        for (const field of (def.fields || [])) {
            populateEditorField(field, section, def.defaults || {});

            // Special preview triggers
            if (field.key === 'videoId') updateYoutubePreview();
            if (field.key === 'trackId') updateSoundcloudPreview();
        }

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        openDialogA11y(modal);
    }

    function closeModal() {
        const modal = document.getElementById('editor-modal');
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';
        closeDialogA11y(modal);
        EditorConfig.currentSection = null;
        EditorConfig.currentSectionIndex = null;
        EditorConfig.currentContentPage = null;
        EditorConfig.newsEditorSave = null;
    }

    // ============================================================
    // WYSIWYG FUNKTIONEN
    // ============================================================

    /**
     * Sanitizes an HTML string: removes style/class attributes, unwraps disallowed tags.
     * Keeps: P, H1-H6, A, B, STRONG, I, EM, BR, UL, OL, LI, IMG
     * @param {string} html - Raw HTML string
     * @returns {string} Cleaned HTML string
     */
    var FOOTNOTE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="16" height="16"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16M5.07 8l13.86 8M5.07 16 18.93 8"/></g></svg>';

    /**
     * Footnote: wraps the selection in <span class="footnote">; inside an existing footnote the
     * span is unwrapped again. js/footnotes.js renders the span as a numbered marker with a tooltip.
     */
    // Moves the ends of a range inwards past spaces (also non-breaking ones) in text nodes.
    function trimRangeWhitespace(range) {
        const isSpace = (ch) => ch === ' ' || ch === '\u00a0' || ch === '\t' || ch === '\n';
        while (!range.collapsed && range.startContainer.nodeType === 3 && range.startOffset < range.startContainer.data.length && isSpace(range.startContainer.data[range.startOffset])) {
            range.setStart(range.startContainer, range.startOffset + 1);
        }
        while (!range.collapsed && range.endContainer.nodeType === 3 && range.endOffset > 0 && isSpace(range.endContainer.data[range.endOffset - 1])) {
            range.setEnd(range.endContainer, range.endOffset - 1);
        }
    }

    // insertHTML turns the spaces next to the inserted element into non-breaking ones; give them back
    function unstickSpacesAround(el) {
        const prev = el.previousSibling, next = el.nextSibling;
        if (prev && prev.nodeType === 3 && prev.data.endsWith('\u00a0')) prev.data = prev.data.slice(0, -1) + ' ';
        if (next && next.nodeType === 3 && next.data.startsWith('\u00a0')) next.data = ' ' + next.data.slice(1);
    }

    function toggleFootnoteIn(root) {
        const selection = window.getSelection();
        if (!selection.rangeCount || !root) return;
        const range = selection.getRangeAt(0);
        if (!root.contains(range.commonAncestorContainer)) return;
        const startNode = range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentElement;
        const existing = startNode ? startNode.closest('.footnote') : null;
        if (existing && root.contains(existing)) {
            existing.replaceWith(...existing.childNodes);
            root.normalize();
            return;
        }
        // A double-click selects the word plus its trailing space; the space stays outside the footnote
        trimRangeWhitespace(range);
        selection.removeAllRanges();
        selection.addRange(range);
        const holder = document.createElement('div');
        holder.append(range.cloneContents());
        const inner = range.collapsed ? escHtml(t('footnote_placeholder')) : holder.innerHTML;
        document.execCommand('insertHTML', false, '<span class="footnote" id="nb-footnote-new">' + inner + '</span>');
        const span = root.querySelector('#nb-footnote-new');
        if (!span) return;
        span.removeAttribute('id');
        unstickSpacesAround(span);
        const next = document.createRange();
        if (range.collapsed) next.selectNodeContents(span); else { next.setStartAfter(span); next.collapse(true); }
        selection.removeAllRanges();
        selection.addRange(next);
    }

    // Mirrors includes/html-sanitizer.php (the server's sanitizer remains final): the same tags and
    // attributes survive inline editing, so site components (classes, ids, tables, figures) keep working.
    const SANITIZE_TAGS = new Set('p br strong b em i u a ul ol li h1 h2 h3 h4 h5 h6 blockquote span div figure figcaption img table thead tbody tfoot tr th td caption sup sub hr pre code small cite q mark s del ins abbr dl dt dd details summary video audio source'.split(' '));
    const SANITIZE_DROP = new Set('script style iframe object embed svg math template form input button textarea select link meta base'.split(' '));
    const SANITIZE_ATTRS = { a: ['href', 'target', 'rel', 'download'], img: ['src', 'alt', 'width', 'height', 'loading', 'decoding'], ol: ['start', 'reversed'], td: ['colspan', 'rowspan', 'scope'], th: ['colspan', 'rowspan', 'scope'], video: ['src', 'controls', 'preload', 'poster', 'playsinline', 'muted', 'loop'], audio: ['src', 'controls', 'preload', 'loop'], source: ['src', 'type'], details: ['open'], abbr: ['title'] };
    const safeUrl = (value, schemes) => {
        const probe = value.replace(/[\x00-\x20\x7f]+/g, '');
        return !probe.includes('\\') && !probe.startsWith('//') && (!/^[a-z][a-z0-9+.-]*:/i.test(probe) || schemes.test(probe));
    };

    function sanitizeHtmlString(html) {
        const temp = document.createElement('div');
        temp.innerHTML = html;

        function cleanNode(node) {
            const children = Array.from(node.childNodes);
            children.forEach(child => {
                if (child.nodeType === Node.COMMENT_NODE) { node.removeChild(child); return; }
                if (child.nodeType === Node.ELEMENT_NODE) {
                    const tagName = child.tagName.toLowerCase();
                    if (SANITIZE_DROP.has(tagName)) { node.removeChild(child); return; }

                    if (SANITIZE_TAGS.has(tagName)) {
                        const attrs = Array.from(child.attributes);
                        attrs.forEach(attr => {
                            const name = attr.name.toLowerCase();
                            const keep = ['class', 'title', 'lang', 'dir'].includes(name)
                                || (name === 'id' && /^[A-Za-z][\w:.-]{0,80}$/.test(attr.value))
                                || (SANITIZE_ATTRS[tagName] || []).includes(name);
                            if (!keep) { child.removeAttribute(attr.name); return; }
                            if (name === 'href' && !safeUrl(attr.value, /^(https?|mailto|tel):/i)) child.setAttribute('href', '#');
                            if ((name === 'src' || name === 'poster') && !safeUrl(attr.value, /^https?:/i)) child.removeAttribute(attr.name);
                            if (name === 'target' && !['_blank', '_self'].includes(attr.value)) child.removeAttribute(attr.name);
                        });
                        if ((tagName === 'img' || tagName === 'source') && !child.hasAttribute('src')) { node.removeChild(child); return; }
                        // External links open in a new tab unless the link dialog set target="_self";
                        // a target chosen for an internal link stays
                        if (tagName === 'a') {
                            const href = child.getAttribute('href') || '';
                            if (/^https?:\/\//i.test(href) && !child.hasAttribute('target')) child.setAttribute('target', '_blank');
                            if (child.getAttribute('target') === '_blank') child.setAttribute('rel', 'noopener');
                            else child.removeAttribute('rel');
                        }
                        cleanNode(child);
                    } else {
                        cleanNode(child);
                        while (child.firstChild) {
                            node.insertBefore(child.firstChild, child);
                        }
                        node.removeChild(child);
                    }
                }
            });
        }

        cleanNode(temp);
        return temp.innerHTML;
    }

    /**
     * Cleans HTML in the WYSIWYG modal editor (uses sanitizeHtmlString internally)
     */
    // ============================================================
    // FLOATING TOOLBAR (inline rich text editing)
    // ============================================================

    let floatingToolbar = null;
    let activeHtmlField = null;

    function createFloatingToolbar() {
        if (floatingToolbar) return floatingToolbar;

        const toolbar = document.createElement('div');
        toolbar.className = 'floating-toolbar';
        const formats = [['p', t('format_paragraph')], ['h2', t('format_heading') + ' 2'], ['h3', t('format_heading') + ' 3'], ['h4', t('format_heading') + ' 4'], ['blockquote', t('format_quote')]];
        // Text block styles (includes/text-styles.php + content/text-styles.json) for post blocks; the
        // page editor offers the same list in its field dialog
        const textStyles = ((BlockTypes.text?.fields || []).find(f => f.key === 'style')?.options || [{ value: '' }])
            .map(o => ({ value: o.value || '', label: o.value ? o.label : t('text_style_default') }));
        toolbar.innerHTML = `
            <button type="button" class="floating-toolbar-format" data-action="format-menu" aria-haspopup="menu" aria-expanded="false" data-editor-tooltip="${escHtml(t('format_block'))}" aria-label="${escHtml(t('format_block'))}"><span class="floating-toolbar-format-label">${escHtml(formats[0][1])}</span><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
            <div class="floating-toolbar-menu" role="menu" hidden>${formats.map(([v, l]) => `<button type="button" role="menuitemradio" aria-checked="false" data-format="${v}"><${v === 'blockquote' ? 'em' : v}>${escHtml(l)}</${v === 'blockquote' ? 'em' : v}></button>`).join('')}</div>
            <span class="floating-toolbar-separator"></span>
            <button type="button" data-command="bold" data-editor-tooltip="${escHtml(t('bold'))} (Ctrl+B)" aria-label="${escHtml(t('bold'))} (Ctrl+B)"><b>B</b></button>
            <button type="button" data-command="italic" data-editor-tooltip="${escHtml(t('italic'))} (Ctrl+I)" aria-label="${escHtml(t('italic'))} (Ctrl+I)"><i>I</i></button>
            <button type="button" data-action="link" data-editor-tooltip="${escHtml(t('insert_link'))}" aria-label="${escHtml(t('insert_link'))}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="16" height="16"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></g></svg></button>
            <button type="button" data-action="unlink" data-editor-tooltip="${escHtml(t('remove_link'))}" aria-label="${escHtml(t('remove_link'))}">${Icons.unlink}</button>
            <button type="button" data-action="footnote" data-editor-tooltip="${escHtml(t('insert_footnote'))}" aria-label="${escHtml(t('insert_footnote'))}">${FOOTNOTE_ICON}</button>
            <button type="button" data-command="removeFormat" data-editor-tooltip="${escHtml(t('clean_formatting'))}" aria-label="${escHtml(t('clean_formatting'))}">${Icons.eraser}</button>
            <span class="floating-toolbar-separator"></span>
            <button type="button" data-action="html-source" data-editor-tooltip="HTML" aria-label="HTML">&lt;/&gt;</button>
            <span class="floating-toolbar-separator" data-news-only hidden></span>
            <button type="button" data-action="split" data-news-only hidden data-editor-tooltip="${escHtml(t('news_block.split'))}" aria-label="${escHtml(t('news_block.split'))}">${NEWS_SPLIT_ICON}</button>
            <span class="floating-toolbar-separator" data-news-only hidden></span>
            <button type="button" class="floating-toolbar-format floating-toolbar-style" data-action="style-menu" data-news-only hidden aria-haspopup="menu" aria-expanded="false" data-editor-tooltip="${escHtml(t('text_style'))}" aria-label="${escHtml(t('text_style'))}"><span class="floating-toolbar-style-label">${escHtml(textStyles[0].label)}</span><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
            <div class="floating-toolbar-menu floating-toolbar-style-menu" role="menu" hidden>${textStyles.map(s => `<button type="button" role="menuitemradio" aria-checked="false" data-style="${escHtml(s.value)}">${escHtml(s.label)}</button>`).join('')}</div>
        `;

        // Prevent blur on toolbar button clicks
        toolbar.addEventListener('mousedown', (e) => {
            e.preventDefault();
        });

        // Block format (paragraph, heading, quote) of the element at the caret: own menu,
        // because a native <select> is painted by the browser and ignores the dark toolbar
        const formatButton = toolbar.querySelector('.floating-toolbar-format');
        const formatLabel = toolbar.querySelector('.floating-toolbar-format-label');
        const formatMenu = toolbar.querySelector('.floating-toolbar-menu');
        let currentFormat = 'p';
        const setFormatState = (value) => {
            currentFormat = formats.some(([v]) => v === value) ? value : 'p';
            formatLabel.textContent = formats.find(([v]) => v === currentFormat)[1];
            formatMenu.querySelectorAll('[data-format]').forEach(item => item.setAttribute('aria-checked', String(item.dataset.format === currentFormat)));
        };
        const closeFormatMenu = () => {
            formatMenu.hidden = true;
            formatButton.setAttribute('aria-expanded', 'false');
        };
        document.addEventListener('selectionchange', () => {
            if (!activeHtmlField) return;
            const sel = window.getSelection();
            if (!sel.rangeCount || !activeHtmlField.contains(sel.anchorNode)) return;
            setFormatState(String(document.queryCommandValue('formatBlock') || '').replace(/[<>]/g, '').toLowerCase());
        });
        formatButton.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            formatMenu.hidden = !formatMenu.hidden;
            formatButton.setAttribute('aria-expanded', String(!formatMenu.hidden));
        });
        formatMenu.addEventListener('click', (e) => {
            const item = e.target.closest('[data-format]');
            if (!item) return;
            e.preventDefault();
            e.stopPropagation();
            closeFormatMenu();
            if (!activeHtmlField) return;
            activeHtmlField.focus();
            document.execCommand('formatBlock', false, '<' + item.dataset.format + '>');
            setFormatState(item.dataset.format);
            activeHtmlField.dispatchEvent(new Event('input', { bubbles: true }));
        });
        document.addEventListener('click', (e) => {
            if (!formatMenu.hidden && !toolbar.contains(e.target)) closeFormatMenu();
        }, true);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !formatMenu.hidden) { e.stopPropagation(); closeFormatMenu(); }
        }, true);
        toolbar._closeFormatMenu = closeFormatMenu;

        // Style of the whole post text block (field "style"): saved at once like other structural changes
        const styleButton = toolbar.querySelector('.floating-toolbar-style');
        const styleLabel = toolbar.querySelector('.floating-toolbar-style-label');
        const styleMenu = toolbar.querySelector('.floating-toolbar-style-menu');
        const newsBlockRef = (el) => {
            const parts = String(el?.dataset.newsBlock || '').split('.');
            return { field: parts[0], index: parseInt(parts[1], 10) };
        };
        const setStyleState = (value) => {
            const current = textStyles.some(s => s.value === value) ? value : '';
            styleLabel.textContent = textStyles.find(s => s.value === current).label;
            styleMenu.querySelectorAll('[data-style]').forEach(item => item.setAttribute('aria-checked', String(item.dataset.style === current)));
        };
        const closeStyleMenu = () => {
            styleMenu.hidden = true;
            styleButton.setAttribute('aria-expanded', 'false');
        };
        toolbar._syncNewsStyle = (el) => {
            const pageRef = pageTextSectionRef(el);
            if (pageRef) {
                setStyleState(pageRef.section.style ? String(pageRef.section.style) : '');
                return;
            }
            const ref = newsBlockRef(el);
            const block = Number.isInteger(ref.index) ? newsBlockList(ref.field)[ref.index] : null;
            setStyleState(block && block.style ? String(block.style) : '');
        };
        styleButton.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            closeFormatMenu();
            styleMenu.style.left = styleButton.offsetLeft + 'px';
            styleMenu.hidden = !styleMenu.hidden;
            styleButton.setAttribute('aria-expanded', String(!styleMenu.hidden));
        });
        styleMenu.addEventListener('click', (e) => {
            const item = e.target.closest('[data-style]');
            if (!item) return;
            e.preventDefault();
            e.stopPropagation();
            closeStyleMenu();
            if (pageTextSectionRef(activeHtmlField)) {
                setPageSectionStyle(activeHtmlField, item.dataset.style);
                return;
            }
            const ref = newsBlockRef(activeHtmlField);
            if (!Number.isInteger(ref.index)) return;
            const value = item.dataset.style;
            newsStructuralChange(() => {
                const block = newsBlockList(ref.field)[ref.index];
                if (!block) return;
                if (value) block.style = value; else delete block.style;
            });
        });
        document.addEventListener('click', (e) => {
            if (!styleMenu.hidden && !toolbar.contains(e.target)) closeStyleMenu();
        }, true);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !styleMenu.hidden) { e.stopPropagation(); closeStyleMenu(); }
        }, true);
        toolbar._closeStyleMenu = closeStyleMenu;

        // Handle button clicks
        toolbar.addEventListener('click', (e) => {
            const btn = e.target.closest('button');
            if (!btn || !activeHtmlField) return;

            const command = btn.dataset.command;
            const action = btn.dataset.action;

            if (command) {
                document.execCommand(command, false, null);
                activeHtmlField.focus();
            } else if (action === 'link') {
                insertLinkInField(activeHtmlField);
            } else if (action === 'unlink') {
                removeLinkInField(activeHtmlField);
            } else if (action === 'footnote') {
                toggleFootnoteIn(activeHtmlField);
                activeHtmlField.focus();
            } else if (action === 'split') {
                if (pageTextSectionRef(activeHtmlField)) {
                    splitPageSection(activeHtmlField);
                    return;
                }
                const parts = String(activeHtmlField.dataset.newsBlock || '').split('.');
                splitNewsBlockAt(parts[0], parseInt(parts[1], 10), { el: activeHtmlField });
            } else if (action === 'html-source') {
                if (activeHtmlField.classList.contains('news-editable')) {
                    openNewsHtmlEditor(activeHtmlField);
                    return;
                }
                // Finish inline editing first, then open modal
                const field = activeHtmlField;
                finishInlineHtmlEdit(field, field._originalHtml, false);
                openFieldHtmlEditor(field);
            }
        });

        document.body.appendChild(toolbar);
        floatingToolbar = toolbar;
        return toolbar;
    }

    function insertLinkInField(field) {
        const selection = window.getSelection();
        const savedRange = selection.rangeCount > 0 ? selection.getRangeAt(0).cloneRange() : null;
        const selectedText = selection.toString();

        // Caret or selection inside an existing link: edit that link
        const container = savedRange ? savedRange.commonAncestorContainer : null;
        const containerEl = container && (container.nodeType === 1 ? container : container.parentElement);
        const closestAnchor = containerEl ? containerEl.closest('a') : null;
        const anchor = closestAnchor && field.contains(closestAnchor) ? closestAnchor : null;

        if (!anchor && !selectedText) {
            showToast(t('toast.select_text_first'), 'error');
            return;
        }

        // Reuse the link editor modal with page picker
        createLinkEditorDialog();
        initPagePicker('link-editor-page-select', 'link-editor-href');

        const originalText = anchor ? anchor.textContent : selectedText;
        document.getElementById('link-editor-text').value = originalText;
        syncPagePicker('link-editor-page-select', 'link-editor-href', anchor ? anchor.getAttribute('href') || '' : '');
        document.getElementById('link-editor-target').checked = !!anchor && anchor.getAttribute('target') === '_blank';
        document.getElementById('link-editor-download').checked = !!anchor && anchor.hasAttribute('download');
        updateDownloadCheckboxState();

        const modal = document.getElementById('link-editor-modal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Override save to insert link via execCommand
        modal._editTarget = null;
        modal._inlineInsert = { field, savedRange, anchor, originalText };

        setTimeout(() => {
            const select = document.getElementById('link-editor-page-select');
            if (select) select.focus();
        }, 100);
    }

    // With only the caret in a link the browser's unlink does nothing: select the whole link first
    function removeLinkInField(field) {
        const selection = window.getSelection();
        if (selection.rangeCount && selection.isCollapsed) {
            const node = selection.getRangeAt(0).startContainer;
            const anchor = (node.nodeType === 1 ? node : node.parentElement).closest('a');
            if (anchor && field.contains(anchor)) selection.selectAllChildren(anchor);
        }
        document.execCommand('unlink', false, null);
        field.focus();
    }

    // Target, rel and download of a link in a text field. An external link without "new tab"
    // gets target="_self", otherwise the sanitizer would open it in a new tab again.
    function applyInlineLinkSettings(anchor, href, target, download) {
        anchor.setAttribute('href', href);
        if (target === '_blank') {
            anchor.setAttribute('target', '_blank');
            anchor.setAttribute('rel', 'noopener');
        } else {
            anchor.removeAttribute('rel');
            if (/^https?:\/\//i.test(href)) anchor.setAttribute('target', '_self');
            else anchor.removeAttribute('target');
        }
        if (download) anchor.setAttribute('download', '');
        else anchor.removeAttribute('download');
    }

    function saveInlineLink({ field, savedRange, anchor, originalText }, text, href, target, download) {
        field.focus();
        const textChanged = text.trim() !== '' && text !== originalText;
        if (anchor) {
            if (!href) {
                // no target left: the link goes, its text stays
                anchor.replaceWith(...anchor.childNodes);
            } else {
                applyInlineLinkSettings(anchor, href, target, download);
                if (textChanged) anchor.textContent = text;
            }
        } else if (href && savedRange) {
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(savedRange);
            // A selection across elements yields several links; the marker finds all of them
            const marker = 'nibbly-link-' + Date.now();
            document.execCommand('createLink', false, marker);
            const anchors = Array.from(field.querySelectorAll('a')).filter(a => a.getAttribute('href') === marker);
            anchors.forEach(a => applyInlineLinkSettings(a, href, target, download));
            if (textChanged && anchors.length) {
                anchors[0].textContent = text;
                anchors.slice(1).forEach(a => a.remove());
            }
        }
        // attribute changes fire no input event; post blocks mark themselves dirty on it
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function positionFloatingToolbar(field) {
        const toolbar = createFloatingToolbar();
        const rect = field.getBoundingClientRect();
        const isNewsBlock = field.hasAttribute('data-news-block');
        const hasBlockTools = isNewsBlock || !!pageTextSectionRef(field);
        toolbar.querySelectorAll('[data-news-only]').forEach(node => { node.hidden = !hasBlockTools; });
        if (hasBlockTools && toolbar._syncNewsStyle) toolbar._syncNewsStyle(field);
        toolbar.style.display = 'flex';

        // Measure toolbar
        const tbRect = toolbar.getBoundingClientRect();
        const gap = 8;

        // The block's own bar sits at the top right: start at the field's left edge instead of centering
        const blockBar = floatingToolbarBlockBar(field);
        let left = blockBar ? rect.left : rect.left + (rect.width / 2) - (tbRect.width / 2);
        let top = rect.top - tbRect.height - gap;

        // If no room above, place below; a field taller than the viewport gets the
        // toolbar pinned in the upper third of the viewport (not at the very top edge, where it
        // would sit under the admin bar and far from the text) so it stays in reach while scrolling
        if (top < 50) {
            top = rect.bottom + gap;
            if (top > window.innerHeight - tbRect.height - gap) {
                top = Math.max(50 + gap, Math.round(window.innerHeight * 0.3) - tbRect.height);
            }
        }

        // Keep within viewport horizontally
        left = Math.max(8, Math.min(left, window.innerWidth - tbRect.width - 8));

        // Narrow fields: both bars still collide, so this one moves above the block bar (below it when the admin bar is in the way)
        if (blockBar) {
            const bar = blockBar.getBoundingClientRect();
            if (left < bar.right && left + tbRect.width > bar.left && top < bar.bottom && top + tbRect.height > bar.top) {
                top = bar.top - tbRect.height - gap;
                if (top < 50) top = bar.bottom + gap;
            }
        }

        toolbar.style.left = left + 'px';
        toolbar.style.top = top + 'px';
    }

    function floatingToolbarBlockBar(field) {
        return field.closest('[data-news-block]')?.querySelector(':scope > .news-block-tools')
            || field.closest('.editable-section')?.querySelector(':scope > .nibbly-editor-section-overlay')
            || null;
    }

    function hideFloatingToolbar() {
        if (floatingToolbar) {
            floatingToolbar.style.display = 'none';
            if (floatingToolbar._closeFormatMenu) floatingToolbar._closeFormatMenu();
            if (floatingToolbar._closeStyleMenu) floatingToolbar._closeStyleMenu();
        }
        if (activeHtmlField && activeHtmlField._scrollHandler) {
            window.removeEventListener('scroll', activeHtmlField._scrollHandler, true);
            window.removeEventListener('resize', activeHtmlField._scrollHandler);
            delete activeHtmlField._scrollHandler;
        }
        activeHtmlField = null;
    }

    function startInlineHtmlEdit(field) {
        // Finish any other active inline HTML edit
        if (activeHtmlField && activeHtmlField !== field) {
            finishInlineHtmlEdit(activeHtmlField, activeHtmlField._originalHtml, false);
        }

        const originalHtml = field.innerHTML;
        field._originalHtml = originalHtml;
        field.contentEditable = 'true';
        field.classList.add('editable-field-editing');

        // Show and position toolbar
        activeHtmlField = field;
        positionFloatingToolbar(field);

        // Reposition on scroll/resize
        const scrollHandler = () => positionFloatingToolbar(field);
        field._scrollHandler = scrollHandler;
        window.addEventListener('scroll', scrollHandler, true);
        window.addEventListener('resize', scrollHandler);

        // Keyboard handler
        const keyHandler = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                cancelInlineHtmlEdit(field, originalHtml);
            } else if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                finishInlineHtmlEdit(field, originalHtml, false);
                saveAllChanges();
            }
            // Bold/Italic/etc. handled by browser natively in contentEditable
        };

        // Blur handler
        const blurHandler = () => {
            setTimeout(() => {
                // Check if focus moved to toolbar or prompt dialog
                const active = document.activeElement;
                if (floatingToolbar && floatingToolbar.contains(active)) return;
                if (document.querySelector('.prompt-dialog-overlay, #link-editor-modal.active')) return;
                if (field.isContentEditable) {
                    finishInlineHtmlEdit(field, originalHtml, false);
                }
            }, 200);
        };

        // Paste handler — sanitize pasted HTML
        const pasteHandler = (e) => {
            e.preventDefault();
            const html = e.clipboardData.getData('text/html');
            const text = e.clipboardData.getData('text/plain');

            if (html) {
                const clean = sanitizeHtmlString(html);
                document.execCommand('insertHTML', false, clean);
            } else if (text) {
                document.execCommand('insertText', false, text);
            }
        };

        field.addEventListener('keydown', keyHandler);
        field.addEventListener('blur', blurHandler);
        field.addEventListener('paste', pasteHandler);
        field._inlineHtmlHandlers = { keyHandler, blurHandler, pasteHandler };

        field.focus();
    }

    function finishInlineHtmlEdit(field, originalHtml, cancelled) {
        if (!field.isContentEditable) return;

        const newHtml = sanitizeHtmlString(field.innerHTML).trim();
        field.contentEditable = 'false';
        field.classList.remove('editable-field-editing');
        cleanupInlineHtmlHandlers(field);
        hideFloatingToolbar();

        if (!cancelled && newHtml !== originalHtml) {
            saveField(field.dataset.page, field.dataset.field, newHtml, field, true);
        } else if (cancelled) {
            field.innerHTML = originalHtml;
        }
    }

    function cancelInlineHtmlEdit(field, originalHtml) {
        finishInlineHtmlEdit(field, originalHtml, true);
    }

    function cleanupInlineHtmlHandlers(field) {
        if (field._inlineHtmlHandlers) {
            field.removeEventListener('keydown', field._inlineHtmlHandlers.keyHandler);
            field.removeEventListener('blur', field._inlineHtmlHandlers.blurHandler);
            field.removeEventListener('paste', field._inlineHtmlHandlers.pasteHandler);
            delete field._inlineHtmlHandlers;
        }
        delete field._originalHtml;
        delete field._scrollHandler;
    }

    // ============================================================
    // MEDIA PREVIEWS
    // ============================================================

    function updateYoutubePreview() {
        const input = document.getElementById('editor-input-videoId-input');
        const preview = document.getElementById('editor-youtube-preview');
        const videoId = input.value.trim();

        if (videoId) {
            preview.innerHTML = `
                <iframe src="https://www.youtube-nocookie.com/embed/${escHtml(videoId)}"
                    frameborder="0" allowfullscreen style="width:100%;height:200px;"></iframe>
            `;
        } else {
            preview.innerHTML = '<p class="preview-placeholder">Enter video ID for preview</p>';
        }
    }

    function updateSoundcloudPreview() {
        const input = document.getElementById('editor-input-trackId-input');
        const preview = document.getElementById('editor-soundcloud-preview');
        const trackId = input.value.trim();

        if (trackId) {
            preview.innerHTML = `
                <iframe width="100%" height="120" scrolling="no" frameborder="no"
                    src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/${escHtml(trackId)}&color=%233b82f6&inverse=true&auto_play=false&show_user=true">
                </iframe>
            `;
        } else {
            preview.innerHTML = '<p class="preview-placeholder">Enter track ID for preview</p>';
        }
    }

    function updateAudioPreview() {
        const input = document.getElementById('editor-input-src-audio');
        const preview = document.getElementById('editor-audio-preview');
        const src = input.value.trim();

        if (src) {
            preview.innerHTML = `
                <audio controls preload="metadata" style="width:100%;">
                    <source src="${escHtml(src)}" type="audio/mpeg">
                    Your browser does not support audio.
                </audio>
            `;
        } else {
            preview.innerHTML = '<p class="preview-placeholder">Select an audio file for preview</p>';
        }
    }

    /**
     * Direkter Audio-Upload im Editor-Modal
     * Automatically generates a title from the filename
     */
    async function handleEditorAudioUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        // Check file type
        const allowedTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/aac', 'audio/flac'];
        if (!allowedTypes.includes(file.type) && !file.name.match(/\.(mp3|wav|ogg|m4a|aac|flac)$/i)) {
            showToast(t('audio.format_error'), 'error');
            e.target.value = '';
            return;
        }

        // Check file size (max 50MB)
        if (file.size > 50 * 1024 * 1024) {
            showToast(t('audio.size_error'), 'error');
            e.target.value = '';
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'upload-audio');
            formData.append('audio', file);
            formData.append('csrf_token', EditorConfig.csrfToken);

            showToast(t('image.uploading'), 'success');

            const response = await fetch(EditorConfig.apiUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success && result.data) {
                // Set audio path
                document.getElementById('editor-input-src-audio').value = result.data.path;
                updateAudioPreview();

                // Generate title from filename (without extension, hyphens/underscores → spaces)
                const titleInput = document.getElementById('editor-input-title-input');
                if (!titleInput.value) {
                    const filename = result.data.name;
                    // Remove file extension
                    const nameWithoutExt = filename.replace(/\.[^.]+$/, '');
                    // Replace hyphens and underscores with spaces
                    const title = nameWithoutExt.replace(/[-_]/g, ' ');
                    titleInput.value = title;
                }

                showToast(t('audio.uploaded'), 'success');
            } else {
                showToast(t('toast.error_generic', { message: result.message }), 'error');
            }
        } catch (error) {
            showToast(t('toast.upload_error', { message: error.message }), 'error');
        }

        e.target.value = '';
    }

    // ============================================================
    // SPEICHERN
    // ============================================================

    /**
     * Generische Undo-Funktion: Stellt vorherigen Zustand wieder her
     */
    async function restoreContentState(previousData, contentPage) {
        try {
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('page', contentPage);
            formData.append('content', JSON.stringify(previousData));
            formData.append('csrf_token', EditorConfig.csrfToken);

            const response = await fetch(EditorConfig.apiUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showToast(t('toast.undone'), 'success');
                setTimeout(() => location.reload(), 500);
            } else {
                showToast(t('toast.error_undo', { message: result.message }), 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', { message: error.message }), 'error');
        }
    }

    function saveSection() {
        const section = EditorConfig.currentSection;
        const index = EditorConfig.currentSectionIndex;
        const contentPage = EditorConfig.currentContentPage;

        if (EditorConfig.newsEditorSave && section) {
            // post block (openNewsBlockEditor): read the fields, hand the block back, no page involved
            for (const field of (BlockTypes[section.type]?.fields || [])) {
                const value = readEditorField(field);
                if ((field.type === 'select' && value === '') || (field.type === 'checkbox' && !value)) delete section[field.key];
                else section[field.key] = value;
            }
            if (!section.title) delete section.titleTag;
            const onSave = EditorConfig.newsEditorSave;
            closeModal();
            onSave(section);
            return;
        }

        if (!section || index === null || !contentPage) return;

        pushUndoState();

        // Resolve type (legacy alias)
        const type = section.type === 'project' ? 'card' : section.type;
        const def = BlockTypes[type];

        if (def) {
            for (const field of (def.fields || [])) {
                const value = readEditorField(field);

                // For select fields with empty string meaning "no value", clean up
                if (field.type === 'select' && value === '' && field.key !== 'titleTag') {
                    delete section[field.key];
                } else if (field.type === 'checkbox' && !value) {
                    delete section[field.key];
                } else {
                    section[field.key] = value;
                }
            }
        }

        // Clean up titleTag if no title is set
        if (!section.title && section.titleTag) {
            delete section.titleTag;
        }

        // Update in-memory data
        EditorConfig.contentData[contentPage].sections[index] = section;
        EditorConfig.dirtyPages.add(contentPage);

        // Live-update the section in the DOM so admins see changes immediately
        patchSectionInDom(index, section);

        updateUndoRedoButtons();
        showToast(t('toast.change_queued'), 'success');
        closeModal();
    }

    /**
     * Patch a rendered section in the DOM with new data, so the editor shows
     * changes immediately without a full page reload.
     */
    function patchSectionInDom(index, section) {
        const sectionEl = document.querySelector('.editable-section[data-section-index="' + index + '"]');
        if (!sectionEl) return;
        const type = section.type === 'project' ? 'card' : section.type;
        const fieldSelector = (field) => `[data-field="sections.${index}.${field}"]`;
        const replaceHeadingTag = (fieldEl, tagName) => {
            if (!fieldEl || !tagName) return fieldEl;
            const current = fieldEl.parentElement;
            if (!current || !/^H[1-6]$/.test(current.tagName) || current.tagName.toLowerCase() === tagName) return fieldEl;
            const replacement = document.createElement(tagName);
            replacement.innerHTML = current.innerHTML;
            Array.from(current.attributes).forEach(attr => replacement.setAttribute(attr.name, attr.value));
            current.replaceWith(replacement);
            return replacement.querySelector('[data-field]') || replacement;
        };

        switch (type) {
            case 'image': {
                const img = sectionEl.querySelector('.block-image img');
                if (img && section.src) {
                    img.src = section.src;
                    img.alt = section.alt || '';
                    syncAiLabel(img);
                }
                const caption = sectionEl.querySelector('.block-image figcaption');
                if (caption) caption.textContent = section.caption || '';
                break;
            }
            case 'heading': {
                const textField = replaceHeadingTag(sectionEl.querySelector(fieldSelector('text')), section.level || 'h2');
                if (textField) textField.textContent = section.text || '';
                const subtitleField = sectionEl.querySelector(fieldSelector('subtitle'));
                if (subtitleField) subtitleField.textContent = section.subtitle || '';
                break;
            }
            case 'text': {
                const titleField = replaceHeadingTag(sectionEl.querySelector(fieldSelector('title')), section.titleTag || 'h2');
                if (titleField) titleField.textContent = section.title || '';
                const contentField = sectionEl.querySelector(fieldSelector('content'));
                if (contentField && section.content !== undefined) contentField.innerHTML = section.content;
                // Block image (optional field "image"): swap source and alt text in place. Position, size and
                // style classes come from the site's renderer and only change after the page is reloaded.
                const blockImg = sectionEl.querySelector('img' + fieldSelector('image'));
                if (blockImg) {
                    if (section.image) {
                        if (blockImg.getAttribute('src') !== section.image) {
                            blockImg.src = section.image;
                            ['srcset', 'sizes', 'width', 'height'].forEach(a => blockImg.removeAttribute(a));
                            const link = blockImg.closest('a');
                            if (link && sectionEl.contains(link)) link.href = section.image;
                        }
                        blockImg.alt = section.imageAlt || '';
                    } else {
                        (blockImg.closest('figure') || blockImg).remove();
                    }
                }
                break;
            }
            case 'quote': {
                const quoteText = sectionEl.querySelector('.block-quote__text');
                if (quoteText && section.text !== undefined) quoteText.textContent = section.text;
                const attr = sectionEl.querySelector('.block-quote__attribution');
                if (attr) attr.textContent = section.attribution || '';
                break;
            }
            // For complex block types (card, list, etc.) a partial DOM patch would
            // duplicate renderer logic. Full reload happens after the global "Save"
            // admin button anyway, which is already persisted in dirtyPages.
        }
    }

    // ============================================================
    // TOAST NACHRICHTEN
    // ============================================================

    let toastTimeout = null;

    function showToast(message, type = 'success') {
        const existing = document.querySelector('.editor-toast');
        if (existing) existing.remove();
        if (toastTimeout) clearTimeout(toastTimeout);

        const toast = document.createElement('div');
        toast.className = `editor-toast editor-toast-${type}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.innerHTML = `<span class="toast-message">${message}</span>`;
        toastTimeout = setTimeout(() => toast.remove(), 3000);

        document.body.appendChild(toast);
    }

    // ============================================================
    // EVENT-EDITOR
    // ============================================================

    function createEventEditorUI() {
        const langs = Object.entries(EditorConfig.languages);
        const defaultLang = EditorConfig.defaultLang;
        const isMultiLang = langs.length > 1;

        // Build language tabs
        const tabsHtml = isMultiLang ? langs.map(([code, name], i) => {
            const isDefault = code === defaultLang;
            const active = isDefault ? ' active' : '';
            return `<button type="button" class="event-lang-tab editor-lang-tab${active}" role="tab" aria-selected="${isDefault ? 'true' : 'false'}" tabindex="${isDefault ? '0' : '-1'}" data-lang="${code}">${escHtml(languageLabel(code))}${isDefault ? ' ★' : ''}</button>`;
        }).join('') : '';

        // Build language panels (translatable fields)
        const panelsHtml = langs.map(([code, name]) => {
            const isDefault = code === defaultLang;
            const display = !isMultiLang || isDefault ? '' : ' hidden';
            const reqMark = isDefault ? ' *' : '';
            return `
                <div class="event-lang-panel editor-lang-panel${isDefault ? ' active' : ''}" role="tabpanel" data-lang="${code}"${display}>
                    <div class="editor-field" data-editor-lang="${code}" data-editor-lang-field="title">
                        <label>${t('event.title')}${reqMark}</label>
                        <input type="text" id="event-title-${code}"${isDefault ? ' required' : ''}>
                    </div>
                    <div class="editor-field" data-editor-lang="${code}" data-editor-lang-field="location">
                        <label>${t('event.location')}</label>
                        <input type="text" id="event-location-${code}">
                    </div>
                    <div class="editor-field" data-editor-lang="${code}" data-editor-lang-field="description">
                        <label>${t('event.description')}</label>
                        <textarea id="event-description-${code}" rows="3"></textarea>
                    </div>
                    <div class="editor-field" data-editor-lang="${code}" data-editor-lang-field="admission">
                        <label>${t('event.admission')}</label>
                        <input type="text" id="event-admission-${code}">
                    </div>
                </div>
            `;
        }).join('');

        const modal = document.createElement('div');
        modal.id = 'event-editor-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-wide">
                <div class="editor-modal-header">
                    <h3 id="event-editor-title">${t('event.edit')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeEventModal()">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <input type="hidden" id="event-id">

                    <div class="editor-row">
                        <div class="editor-field">
                            <label>${t('event.start_date')}</label>
                            <input type="date" id="event-date" required>
                        </div>
                        <div class="editor-field">
                            <label>${t('event.start_time')}</label>
                            <input type="time" id="event-time">
                        </div>
                        <div class="editor-field">
                            <label>${t('event.end_date')}</label>
                            <input type="date" id="event-end-date">
                        </div>
                        <div class="editor-field">
                            <label>${t('event.end_time')}</label>
                            <input type="time" id="event-end-time">
                        </div>
                    </div>

                    <div class="editor-field">
                        <label>${t('event.url')}</label>
                        <input type="url" id="event-url" placeholder="https://...">
                    </div>

                    <div class="editor-field">
                        <label>${t('event.image')}</label>
                        <div class="editor-image-row">
                            <input type="text" id="event-image" placeholder="...">
                            <button type="button" class="editor-btn editor-btn-secondary editor-btn-inline" onclick="InlineEditor.openImageManagerForEvent()">
                                ${Icons.folder} ${t('image_manager')}
                            </button>
                        </div>
                    </div>

                    <div class="event-lang-section editor-lang-section" data-event-lang-section>
                        ${isMultiLang ? `<div class="event-lang-tabs editor-lang-header">
                            <div class="editor-lang-tabs" role="tablist" aria-label="${escHtml(tFallback('editor.language_tabs', 'Languages'))}">
                            ${tabsHtml}
                            </div>
                            ${EditorConfig.aiFeaturesEnabled ? `<button type="button" class="editor-lang-translate-btn" data-event-lang-translate>${escHtml(tFallback('editor.translate_from_active', 'Translate from active language'))}</button>` : ''}
                            <button type="button" class="event-lang-copy-btn" id="event-copy-default" title="${t('event.copy_to_all')}">
                                ${Icons.copy || '⧉'} ${t('event.copy_to_all')}
                            </button>
                        </div>` : ''}
                        ${panelsHtml}
                    </div>
                </div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-danger" id="event-delete-btn" onclick="InlineEditor.deleteEvent()" style="margin-right:auto;">${t('delete')}</button>
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closeEventModal()">${t('cancel')}</button>
                    <button type="button" class="editor-btn editor-btn-primary" onclick="InlineEditor.saveEvent()">${t('save')}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Tab switching
        modal.querySelectorAll('.event-lang-tab').forEach((tab, index) => {
            const activateEventLang = (targetTab, focus = false) => {
                modal.querySelectorAll('.event-lang-tab').forEach(t => {
                    const active = t === targetTab;
                    t.classList.toggle('active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                    t.tabIndex = active ? 0 : -1;
                    if (active && focus) t.focus();
                });
                const lang = targetTab.dataset.lang;
                modal.querySelectorAll('.event-lang-panel').forEach(p => {
                    const active = p.dataset.lang === lang;
                    p.hidden = !active;
                    p.classList.toggle('active', active);
                });
            };
            tab.addEventListener('click', () => activateEventLang(tab));
            tab.addEventListener('keydown', e => {
                const tabs = Array.from(modal.querySelectorAll('.event-lang-tab'));
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
                e.preventDefault();
                let next = index;
                if (e.key === 'ArrowLeft') next = index === 0 ? tabs.length - 1 : index - 1;
                if (e.key === 'ArrowRight') next = index === tabs.length - 1 ? 0 : index + 1;
                if (e.key === 'Home') next = 0;
                if (e.key === 'End') next = tabs.length - 1;
                activateEventLang(tabs[next], true);
            });
        });

        // Copy default language to all others
        document.getElementById('event-copy-default')?.addEventListener('click', () => {
            const dl = defaultLang;
            const fields = ['title', 'location', 'description', 'admission'];
            const otherLangs = langs.map(([c]) => c).filter(c => c !== dl);
            fields.forEach(field => {
                const val = document.getElementById(`event-${field}-${dl}`)?.value || '';
                otherLangs.forEach(lang => {
                    const el = document.getElementById(`event-${field}-${lang}`);
                    if (el && !el.value) el.value = val;
                });
            });
            showToast(t('toast.copied_to_empty'), 'success');
        });
        modal.querySelector('[data-event-lang-translate]')?.addEventListener('click', function() {
            translateEditorLanguageSection(modal.querySelector('[data-event-lang-section]'), this);
        });

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', closeEventModal);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeEventModal();
            }
        });

        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    function attachEventEditHandlers() {
        document.querySelectorAll('.event-card[data-event-id]').forEach(card => {
            const eventId = card.dataset.eventId;
            if (!eventId) return;

            card.querySelectorAll('.event-edit-btn, .event-hide-btn, .event-delete-btn').forEach(btn => {
                if (btn.dataset.eventButtonReady === 'true') return;
                btn.dataset.eventButtonReady = 'true';
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (btn.classList.contains('event-edit-btn')) {
                        openEventEditor(eventId);
                    } else if (btn.classList.contains('event-hide-btn')) {
                        toggleEventVisibility(eventId);
                    } else if (btn.classList.contains('event-delete-btn')) {
                        deleteEvent(eventId);
                    }
                });
            });

            if (card.dataset.eventCardReady === 'true') return;
            card.dataset.eventCardReady = 'true';
            card.addEventListener('click', (e) => {
                if (!EditorConfig.editMode) return;
                if (e.target.closest('a')) return;
                if (e.target.closest('.event-edit-buttons')) return;
                e.preventDefault();
                e.stopPropagation();
                openEventEditor(eventId);
            });
        });
    }

    // ============================================================
    // FOOTER EDITING
    // ============================================================

    function attachFooterEditHandlers() {
        const footerFields = document.querySelectorAll('.editable-footer-field');
        if (footerFields.length === 0) return;

        footerFields.forEach(field => {
            field.classList.add('editable-footer-active');

            field.addEventListener('click', (e) => {
                if (!EditorConfig.editMode) return;
                e.preventDefault();
                e.stopPropagation();
                openFooterFieldEditor(field);
            });
        });
    }

    function openFooterFieldEditor(field) {
        const fieldName = field.dataset.field;
        const lang = field.dataset.lang;
        const linkHref = field.dataset.linkHref;

        // Link fields: edit text + href
        if (fieldName === 'credit.link') {
            const currentText = field.textContent.trim();
            const currentHref = linkHref || field.getAttribute('href') || '';
            showPromptDialog(t('footer.edit'), t('footer.link_text'), currentText, (newText) => {
                if (newText === null) return;
                showPromptDialog(t('footer.edit'), t('footer.link_url'), currentHref, (newHref) => {
                    if (newHref === null) return;
                    saveFooterLinkField('credit', { linkText: newText, link: newHref }, field);
                    field.textContent = newText;
                    field.href = newHref;
                    field.dataset.linkHref = newHref;
                });
            });
            return;
        }

        // Copyright field: edit raw shortcode text
        if (fieldName === 'copyright') {
            // Get the raw shortcode text from JSON (innerHTML contains rendered HTML)
            const footerData = EditorConfig.contentData['footer'] || {};
            const currentRaw = footerData.copyright || field.innerHTML
                .replace(/<span\s+id="([^"]*)"[^>]*>(.*?)<\/span>/g, '[id="$1"]$2[/id]')
                .replace(/<span\s+class="([^"]*)"[^>]*>(.*?)<\/span>/g, '[class="$1"]$2[/class]');
            showPromptDialog(t('footer.edit'), t('footer.copyright'), currentRaw, (newValue) => {
                if (newValue !== null && newValue !== currentRaw) {
                    saveFooterFieldDirect('copyright', newValue, field);
                    // Render shortcodes to HTML for display
                    field.innerHTML = newValue
                        .replace(/\[id="([^"]*)"\](.*?)\[\/id\]/g, '<span id="$1">$2</span>')
                        .replace(/\[class="([^"]*)"\](.*?)\[\/class\]/g, '<span class="$1">$2</span>');
                }
            });
            return;
        }

        // Simple text fields (with or without lang key)
        const currentValue = field.textContent.trim();
        const label = getFooterFieldLabel(fieldName);
        showPromptDialog(t('footer.edit'), label, currentValue, (newValue) => {
            if (newValue !== null && newValue !== currentValue) {
                saveFooterField(fieldName, lang, newValue, field);
            }
        });
    }

    function getFooterFieldLabel(fieldName) {
        const labels = {
            'tagline': 'Tagline',
            'services': 'Services',
            'claim': 'Claim',
            'contact.phone': 'Telefonnummer',
            'contact.email': 'E-Mail-Adresse',
            'credit.text': 'Credit text',
            'contactHeading': 'Contact heading',
            'legalHeading': 'Legal heading'
        };
        return labels[fieldName] || fieldName;
    }

    function saveFooterField(fieldName, lang, newValue, element) {
        pushUndoState();

        if (!EditorConfig.contentData['footer']) EditorConfig.contentData['footer'] = {};
        const footerData = EditorConfig.contentData['footer'];

        // Fields with dot notation but no lang (e.g. contact.phone, credit.text)
        if (fieldName.includes('.')) {
            const parts = fieldName.split('.');
            if (!footerData[parts[0]]) footerData[parts[0]] = {};
            footerData[parts[0]][parts[1]] = newValue;
        } else if (lang) {
            // Lang-dependent fields (tagline, services, claim, contactHeading, legalHeading)
            if (!footerData[fieldName]) footerData[fieldName] = {};
            footerData[fieldName][lang] = newValue;
        } else {
            // Plain flat fields
            footerData[fieldName] = newValue;
        }

        EditorConfig.dirtyPages.add('footer');

        // Update DOM
        element.textContent = newValue;
        if (fieldName === 'contact.email' && element.tagName === 'A') {
            element.href = 'mailto:' + newValue;
        }

        updateUndoRedoButtons();
    }

    // Save credit link (text + href stored as credit.linkText / credit.link)
    function saveFooterLinkField(prefix, values, element) {
        pushUndoState();

        if (!EditorConfig.contentData['footer']) EditorConfig.contentData['footer'] = {};
        const footerData = EditorConfig.contentData['footer'];
        if (!footerData[prefix]) footerData[prefix] = {};
        Object.assign(footerData[prefix], values);

        EditorConfig.dirtyPages.add('footer');
        updateUndoRedoButtons();
    }

    // Save legal link (lang-dependent text + flat href)
    // Save a direct (non-nested, non-lang) footer field
    function saveFooterFieldDirect(fieldName, newValue, element) {
        pushUndoState();

        if (!EditorConfig.contentData['footer']) EditorConfig.contentData['footer'] = {};
        EditorConfig.contentData['footer'][fieldName] = newValue;

        EditorConfig.dirtyPages.add('footer');
        updateUndoRedoButtons();
    }

    // ============================================================
    // EDITABLE FIELDS FOR CUSTOM LAYOUTS
    // ============================================================

    function attachFieldEditHandlers() {
        const fields = document.querySelectorAll('.editable-field');
        if (fields.length === 0) return;

        fields.forEach(field => {
            if (field.dataset.editorReady === 'true') return;
            field.dataset.editorReady = 'true';
            field.classList.add('editable-field-active');
            applyBlockHitboxHint(field);

            // Add hide toggle button
            addFieldHideButton(field);

            if (field.classList.contains('editable-field-html')) {
                field.addEventListener('click', (e) => {
                    if (!EditorConfig.editMode) return;
                    e.preventDefault();
                    e.stopPropagation();
                    if (field.isContentEditable) return;
                    startInlineHtmlEdit(field);
                });
                return;
            }

            // Plain text fields: inline editing with contentEditable
            field.addEventListener('click', (e) => {
                if (!EditorConfig.editMode) return;
                e.preventDefault();
                e.stopPropagation();
                if (field.isContentEditable) return;
                startInlineEdit(field);
            });
        });
    }

    function applyBlockHitboxHint(field) {
        if (!field.closest('[data-editable-group], [data-list-index]')) return;
        const parentTag = (field.parentElement && field.parentElement.tagName || '').toLowerCase();
        const fieldTag = (field.tagName || '').toLowerCase();
        const text = (field.textContent || '').trim();
        if (
            field.classList.contains('editable-field-html') ||
            fieldTag === 'p' ||
            /^(p|li|blockquote|figcaption|h[1-6])$/.test(parentTag) ||
            text.length > 38
        ) {
            field.classList.add('editable-field-block-hitbox');
        }
    }

    function addFieldHideButton(field) {
        // Skip fields inside comparison table (rows handle hiding at row level)
        if (field.closest('.comparison-table')) return;

        const isHidden = field.dataset.hidden === 'true';
        // Field-level hiding is intentionally quiet by default. It created too
        // much chrome on every heading and text span, while the common editorial
        // use case is hiding whole sections or list items. Keep an escape hatch
        // for already-hidden fields so editors can make legacy content visible.
        if (!isHidden && field.dataset.allowFieldHide !== 'true') return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'field-hide-btn';
        btn.title = isHidden ? t('show') : t('hide');
        btn.innerHTML = isHidden ? Icons.eyeClosed : Icons.eyeOpen;

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!EditorConfig.editMode) return;
            toggleFieldHidden(field, btn);
        });

        // Wrap field in a relative container for positioning
        const parent = field.parentNode;
        const wrapper = document.createElement('span');
        wrapper.className = 'editable-field-wrapper';
        parent.insertBefore(wrapper, field);
        wrapper.appendChild(field);
        wrapper.appendChild(btn);
    }

    function toggleFieldHidden(field, btn) {
        const page = field.dataset.page;
        const fieldKey = field.dataset.field;
        if (!page || !fieldKey) return;

        pushUndoState();

        const pageData = EditorConfig.contentData[page];
        if (!pageData) return;

        const hiddenKey = fieldKey + '__hidden';
        const currentlyHidden = getNestedValue(pageData, hiddenKey) === true;
        const nowHidden = !currentlyHidden;

        if (nowHidden) {
            setNestedValue(pageData, hiddenKey, true);
            field.dataset.hidden = 'true';
        } else {
            // Remove the __hidden key entirely when showing
            deleteNestedValue(pageData, hiddenKey);
            delete field.dataset.hidden;
        }

        btn.innerHTML = nowHidden ? Icons.eyeClosed : Icons.eyeOpen;
        btn.title = nowHidden ? t('show') : t('hide');

        EditorConfig.dirtyPages.add(page);
        updateUndoRedoButtons();
        showToast(nowHidden ? t('toast.hidden') : t('toast.visible'), 'success');
    }

    function startInlineEdit(field) {
        const originalText = field.textContent;
        field._originalText = originalText;
        field.contentEditable = 'true';
        field.classList.add('editable-field-editing');
        field.focus();

        // Text komplett markieren
        const range = document.createRange();
        range.selectNodeContents(field);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        const keyHandler = (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                finishInlineEdit(field, originalText);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancelInlineEdit(field, originalText);
            }
        };

        const blurHandler = () => {
            // Short delay so overlay clicks are not treated as blur
            setTimeout(() => {
                if (field.isContentEditable) {
                    finishInlineEdit(field, originalText);
                }
            }, 150);
        };

        field.addEventListener('keydown', keyHandler);
        field.addEventListener('blur', blurHandler);
        field._inlineEditHandlers = { keyHandler, blurHandler };
    }

    function finishInlineEdit(field, originalText) {
        const newText = field.textContent.trim();
        field.contentEditable = 'false';
        field.classList.remove('editable-field-editing');
        cleanupInlineHandlers(field);

        if (newText !== originalText && newText !== '') {
            saveField(field.dataset.page, field.dataset.field, newText, field, false);
        } else if (newText === '') {
            field.textContent = originalText;
        }
    }

    function cancelInlineEdit(field, originalText) {
        field.textContent = originalText;
        field.contentEditable = 'false';
        field.classList.remove('editable-field-editing');
        cleanupInlineHandlers(field);
    }

    function cleanupInlineHandlers(field) {
        if (field._inlineEditHandlers) {
            field.removeEventListener('keydown', field._inlineEditHandlers.keyHandler);
            field.removeEventListener('blur', field._inlineEditHandlers.blurHandler);
            delete field._inlineEditHandlers;
        }
        delete field._originalText;
    }

    function openFieldHtmlEditor(field) {
        const fieldKey = field.dataset.field;
        const page = field.dataset.page;
        const currentValue = field.innerHTML.trim();
        const label = fieldKey.split('.').pop();

        createHtmlSourceDialog();

        const titleEl = document.getElementById('html-source-dialog-title');
        const textarea = document.getElementById('html-source-textarea');
        const modal = document.getElementById('html-source-dialog-modal');

        titleEl.textContent = `HTML — ${label}`;
        textarea.value = formatHtml(currentValue);

        _htmlSourceCallback = (newValue) => {
            if (newValue !== null && newValue !== currentValue) {
                saveField(page, fieldKey, newValue, field, true);
            }
        };

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        setTimeout(() => {
            textarea.focus();
            textarea.setSelectionRange(0, 0);
        }, 100);
    }

    var _htmlSourceCallback = null;

    function createHtmlSourceDialog() {
        if (document.getElementById('html-source-dialog-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'html-source-dialog-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-wide">
                <div class="editor-modal-header">
                    <h3 id="html-source-dialog-title">HTML</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeHtmlSourceDialog(null)">&times;</button>
                </div>
                <div class="editor-modal-body" style="flex:1; display:flex; flex-direction:column; overflow:hidden;">
                    <textarea id="html-source-textarea" class="html-source-textarea" spellcheck="false"></textarea>
                </div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closeHtmlSourceDialog(null)">${t('cancel') || 'Cancel'}</button>
                    <button type="button" class="editor-btn editor-btn-primary" onclick="InlineEditor.submitHtmlSourceDialog()">${escHtml(t('ok'))}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', () => closeHtmlSourceDialog(null));

        // Ctrl+Enter to confirm
        document.getElementById('html-source-textarea').addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                submitHtmlSourceDialog();
            }
            // Tab inserts spaces instead of moving focus
            if (e.key === 'Tab') {
                e.preventDefault();
                const ta = e.target;
                const start = ta.selectionStart;
                const end = ta.selectionEnd;
                ta.value = ta.value.substring(0, start) + '  ' + ta.value.substring(end);
                ta.selectionStart = ta.selectionEnd = start + 2;
            }
        });

        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    function closeHtmlSourceDialog(value) {
        const modal = document.getElementById('html-source-dialog-modal');
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';

        if (_htmlSourceCallback) {
            const cb = _htmlSourceCallback;
            _htmlSourceCallback = null;
            cb(value);
        }
    }

    function submitHtmlSourceDialog() {
        const value = document.getElementById('html-source-textarea').value;
        closeHtmlSourceDialog(value);
    }

    function formatHtml(html) {
        // Simple HTML formatter for readability in the source editor
        let formatted = '';
        let indent = 0;
        const tokens = html.replace(/>\s*</g, '>\n<').split('\n');

        for (let token of tokens) {
            token = token.trim();
            if (!token) continue;

            // Closing tag or self-closing: decrease indent first
            if (/^<\//.test(token)) {
                indent = Math.max(0, indent - 1);
            }

            formatted += '  '.repeat(indent) + token + '\n';

            // Opening tag (not self-closing, not void): increase indent
            if (/^<[a-z][^\/]*>$/i.test(token) && !/^<(br|hr|img|input|meta|link|source)\b/i.test(token)) {
                indent++;
            }
        }
        return formatted.trim();
    }

    function setNestedValue(obj, dotKey, value) {
        const keys = dotKey.split('.');
        let current = obj;
        for (let i = 0; i < keys.length - 1; i++) {
            const key = keys[i];
            if (!current[key] || typeof current[key] !== 'object') {
                current[key] = {};
            }
            current = current[key];
        }
        current[keys[keys.length - 1]] = value;
    }

    function deleteNestedValue(obj, dotKey) {
        const keys = dotKey.split('.');
        let current = obj;
        for (let i = 0; i < keys.length - 1; i++) {
            if (!current[keys[i]] || typeof current[keys[i]] !== 'object') return;
            current = current[keys[i]];
        }
        delete current[keys[keys.length - 1]];
    }

    function saveField(page, fieldKey, newValue, element, isHtml) {
        // Push undo state before making the change
        pushUndoState();

        // Update in-memory contentData
        if (!EditorConfig.contentData[page]) EditorConfig.contentData[page] = {};
        setNestedValue(EditorConfig.contentData[page], fieldKey, newValue);

        // Mark page as dirty
        EditorConfig.dirtyPages.add(page);

        // Update DOM immediately
        if (isHtml) {
            element.innerHTML = newValue;
        } else {
            element.textContent = newValue;
        }

        updateUndoRedoButtons();
    }

    // ============================================================
    // EDITABLE LINKS FOR CUSTOM LAYOUTS
    // ============================================================

    function attachLinkEditHandlers() {
        const links = document.querySelectorAll('[data-editable-link]');
        if (links.length === 0) return;

        links.forEach(link => {
            if (link.dataset.editorReady === 'true') return;
            link.dataset.editorReady = 'true';
            link.classList.add('editable-field-active');
            if (link.closest('[data-editable-group], [data-list-index]')) {
                link.classList.add('editable-field-block-hitbox');
            }

            // Add hide toggle button
            addFieldHideButton(link);

            link.addEventListener('click', (e) => {
                if (!EditorConfig.editMode) return;
                e.preventDefault();
                e.stopPropagation();
                openLinkEditor(link);
            });
        });
    }

    /**
     * Builds HTML for a page picker: dropdown for internal pages + URL input.
     */
    function buildPagePickerHtml(selectId, inputId) {
        const pages = window.NB_PAGES || [];
        let options = `<option value="">${t('link_external') || 'Custom'}</option>`;
        if (pages.length) {
            options += `<option disabled value="">──── ${t('link_internal') || 'Site pages'} ────</option>`;
            pages.forEach(p => {
                options += `<option value="${p.href}">${p.title}</option>`;
            });
        }
        return `
            <select class="prompt-dialog-input link-page-select" id="${selectId}">
                ${options}
            </select>
            <input type="text" class="prompt-dialog-input" id="${inputId}" placeholder="https://..." style="margin-top: 8px;">
        `;
    }

    /**
     * Wires up page-select ↔ URL-input sync after DOM insertion.
     */
    function initPagePicker(selectId, inputId) {
        const select = document.getElementById(selectId);
        const input = document.getElementById(inputId);
        if (!select || !input) return;

        select.addEventListener('change', () => {
            if (select.value) {
                input.value = select.value;
                input.readOnly = true;
                input.style.opacity = '0.6';
            } else {
                input.value = '';
                input.readOnly = false;
                input.style.opacity = '1';
                input.focus();
            }
        });

        // On input change, reset select if user types a custom URL
        input.addEventListener('input', () => {
            if (!input.readOnly) {
                select.value = '';
            }
        });
    }

    /**
     * Syncs page picker state when modal opens with an existing URL.
     */
    function syncPagePicker(selectId, inputId, currentHref) {
        const select = document.getElementById(selectId);
        const input = document.getElementById(inputId);
        if (!select || !input) return;

        input.value = currentHref;

        // Check if currentHref matches a page
        const pages = window.NB_PAGES || [];
        const match = pages.find(p => p.href === currentHref);
        if (match) {
            select.value = match.href;
            input.readOnly = true;
            input.style.opacity = '0.6';
        } else {
            select.value = '';
            input.readOnly = false;
            input.style.opacity = '1';
        }
    }

    function openLinkEditor(element) {
        const page = element.dataset.page;
        const fieldKey = element.dataset.field;
        const currentText = element.textContent.trim();
        const currentHref = element.getAttribute('href') || '';
        const currentTarget = element.getAttribute('target') || '';
        const currentDownload = element.hasAttribute('download');

        createLinkEditorDialog();
        initPagePicker('link-editor-page-select', 'link-editor-href');

        document.getElementById('link-editor-text').value = currentText;
        syncPagePicker('link-editor-page-select', 'link-editor-href', currentHref);
        document.getElementById('link-editor-target').checked = currentTarget === '_blank';
        document.getElementById('link-editor-download').checked = currentDownload;
        updateDownloadCheckboxState();

        const modal = document.getElementById('link-editor-modal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Store reference for save
        modal._editTarget = { element, page, fieldKey };

        setTimeout(() => {
            document.getElementById('link-editor-text').focus();
        }, 100);
    }

    // Files that typically warrant a "download" attribute on links
    const DOWNLOAD_EXTENSIONS = [
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'odt', 'ods', 'odp', 'rtf', 'txt', 'csv',
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'avif',
        // Archives
        'zip', 'rar', '7z', 'tar', 'gz'
    ];

    function isDownloadableHref(href) {
        if (!href) return false;
        // Strip query string and hash, then extract extension
        const cleaned = href.split('#')[0].split('?')[0];
        const match = cleaned.match(/\.([a-z0-9]+)$/i);
        if (!match) return false;
        return DOWNLOAD_EXTENSIONS.includes(match[1].toLowerCase());
    }

    function updateDownloadCheckboxState() {
        const hrefInput = document.getElementById('link-editor-href');
        const downloadCheckbox = document.getElementById('link-editor-download');
        const downloadRow = document.getElementById('link-editor-download-row');
        if (!hrefInput || !downloadCheckbox || !downloadRow) return;

        const allowed = isDownloadableHref(hrefInput.value);
        downloadCheckbox.disabled = !allowed;
        downloadRow.classList.toggle('link-editor-option--disabled', !allowed);
        if (!allowed) downloadCheckbox.checked = false;
    }

    function createLinkEditorDialog() {
        if (document.getElementById('link-editor-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'link-editor-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-small">
                <div class="editor-modal-header">
                    <h3>${formatEditorTitle('Link')}</h3>
                    <button type="button" class="editor-close-btn" id="link-editor-cancel-btn">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div class="prompt-dialog-content">
                        <label class="prompt-dialog-label">Text</label>
                        <input type="text" class="prompt-dialog-input" id="link-editor-text">
                        <label class="prompt-dialog-label" style="margin-top: 12px;">${t('link_target') || 'Link target'}</label>
                        ${buildPagePickerHtml('link-editor-page-select', 'link-editor-href')}
                        <div class="link-editor-options">
                            <label class="link-editor-option">
                                <input type="checkbox" id="link-editor-target">
                                <span>${t('link_open_new_tab') || 'Open in new tab'}</span>
                            </label>
                            <label class="link-editor-option" id="link-editor-download-row">
                                <input type="checkbox" id="link-editor-download">
                                <span>${t('link_offer_download') || 'Offer as download'}</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-secondary" id="link-editor-cancel-btn2">${t('cancel')}</button>
                    <button type="button" class="editor-btn editor-btn-primary" id="link-editor-save-btn">${t('save')}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', closeLinkEditor);
        document.getElementById('link-editor-cancel-btn').addEventListener('click', closeLinkEditor);
        document.getElementById('link-editor-cancel-btn2').addEventListener('click', closeLinkEditor);
        document.getElementById('link-editor-save-btn').addEventListener('click', saveLinkEditor);

        document.getElementById('link-editor-href').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveLinkEditor();
            }
        });

        // Keep the download checkbox in sync with the href type
        document.getElementById('link-editor-href').addEventListener('input', updateDownloadCheckboxState);
        document.getElementById('link-editor-page-select').addEventListener('change', () => {
            // change handler on select already updates the hidden href value; wait a tick
            setTimeout(updateDownloadCheckboxState, 0);
        });
    }

    function closeLinkEditor() {
        const modal = document.getElementById('link-editor-modal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
            // Reset text input state
            const textInput = document.getElementById('link-editor-text');
            if (textInput) { textInput.readOnly = false; textInput.style.opacity = '1'; }
            // Back to the text field with its selection; it stays in edit mode while the dialog is open
            const inlineInsert = modal._inlineInsert;
            if (inlineInsert && inlineInsert.savedRange) {
                inlineInsert.field.focus();
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(inlineInsert.savedRange);
            }
            modal._inlineInsert = null;
        }
    }

    function saveLinkEditor() {
        const modal = document.getElementById('link-editor-modal');
        const inlineInsert = modal._inlineInsert;
        const editTarget = modal._editTarget;
        const newText = document.getElementById('link-editor-text').value;
        const newHref = document.getElementById('link-editor-href').value;
        const newTarget = document.getElementById('link-editor-target').checked ? '_blank' : '';
        const downloadBox = document.getElementById('link-editor-download');
        const newDownload = !downloadBox.disabled && downloadBox.checked;

        // Reset text input state
        const textInput = document.getElementById('link-editor-text');
        textInput.readOnly = false;
        textInput.style.opacity = '1';

        closeLinkEditor();

        // Mode: inline insert (from floating toolbar)
        if (inlineInsert) {
            saveInlineLink(inlineInsert, newText, newHref, newTarget, newDownload);
            return;
        }

        // Mode: editableLink field
        if (!editTarget) return;
        const { element, page, fieldKey } = editTarget;

        // Push undo state
        pushUndoState();

        // Build the value object. Omit target/download when unset so the JSON
        // stays minimal and existing content files aren't bloated with defaults.
        const linkValue = { text: newText, href: newHref };
        if (newTarget) linkValue.target = newTarget;
        if (newDownload) linkValue.download = true;

        // Update in-memory contentData
        if (!EditorConfig.contentData[page]) EditorConfig.contentData[page] = {};
        setNestedValue(EditorConfig.contentData[page], fieldKey, linkValue);

        // Mark as dirty
        EditorConfig.dirtyPages.add(page);

        // Update DOM
        element.textContent = newText;
        element.setAttribute('href', newHref);
        if (newTarget === '_blank') {
            element.setAttribute('target', '_blank');
            element.setAttribute('rel', 'noopener');
        } else {
            element.removeAttribute('target');
            element.removeAttribute('rel');
        }
        if (newDownload) {
            element.setAttribute('download', '');
        } else {
            element.removeAttribute('download');
        }

        updateUndoRedoButtons();
    }

    // ============================================================
    // EDITABLE IMAGES FOR CUSTOM LAYOUTS
    // ============================================================

    function attachImageEditHandlers() {
        const images = document.querySelectorAll('[data-editable-image]');
        if (images.length === 0) return;

        images.forEach(img => {
            if (img.dataset.editorReady === 'true') return;
            img.dataset.editorReady = 'true';
            // Skip images inside event cards — those are edited via the event editor modal
            if (img.closest('.event-card[data-event-id]')) return;
            const wrapper = document.createElement('div');
            wrapper.className = 'editable-image-wrapper';
            // Styled in inline-editor.css (not inline) so sites can adapt it. Images that
            // fill their frame (object-fit) or are absolutely positioned keep doing so.
            const imgStyle = window.getComputedStyle(img);
            if (imgStyle.position === 'absolute') {
                wrapper.classList.add('editable-image-wrapper--positioned');
            } else if (imgStyle.objectFit && imgStyle.objectFit !== 'fill' && img.parentElement) {
                const box = img.getBoundingClientRect();
                const frame = img.parentElement.getBoundingClientRect();
                if (Math.abs(box.width - frame.width) < 2 && Math.abs(box.height - frame.height) < 2) {
                    // A positioned frame (e.g. an aspect-ratio box) is covered absolutely, because
                    // percentage heights do not resolve against aspect-ratio heights
                    const framePosition = window.getComputedStyle(img.parentElement).position;
                    wrapper.classList.add(framePosition === 'static'
                        ? 'editable-image-wrapper--fill'
                        : 'editable-image-wrapper--positioned');
                }
            }
            img.parentNode.insertBefore(wrapper, img);
            wrapper.appendChild(img);

            wrapper.classList.add('editable-field-active');

            // Add placeholder for empty images so they're visible and clickable
            if (!img.getAttribute('src') || img.getAttribute('src') === '' || img.getAttribute('src') === '/') {
                wrapper.classList.add('editable-image-empty');
            }
            img.addEventListener('error', () => wrapper.classList.add('editable-image-empty'));
            img.addEventListener('load', () => wrapper.classList.remove('editable-image-empty'));

            wrapper.addEventListener('click', (e) => {
                if (!EditorConfig.editMode) return;
                e.preventDefault();
                e.stopPropagation();
                openImageFieldEditor(img);
            });
        });
    }

    function openImageFieldEditor(imgElement) {
        const page = imgElement.dataset.page;
        const fieldKey = imgElement.dataset.field;
        const currentAlt = imgElement.getAttribute('alt') || '';
        const format = imgElement.dataset.imageFormat || 'object';

        openImageManager((imagePath) => {
            if (format === 'string') {
                // String-format images (e.g. news cover): save path directly, no alt dialog
                saveImageField(page, fieldKey, imagePath, currentAlt, imgElement);
            } else if (format === 'split') {
                // Split-format: src and alt live at two separate keys
                showPromptDialog(t('image.alt_text'), t('image.alt_hint'), currentAlt, (newAlt) => {
                    if (newAlt === null) newAlt = currentAlt;
                    saveImageField(page, fieldKey, imagePath, newAlt, imgElement);
                });
            } else {
                // Object-format images: ask for alt text, save as {src, alt}
                showPromptDialog(t('image.alt_text'), t('image.alt_hint'), currentAlt, (newAlt) => {
                    if (newAlt === null) newAlt = currentAlt;
                    saveImageField(page, fieldKey, imagePath, newAlt, imgElement);
                });
            }
        }, null, imageFieldPath(imgElement));
    }

    // Stored path of an editable image (the library preselects it); falls back to the rendered src
    function imageFieldPath(imgElement) {
        const page = imgElement.dataset.page;
        const fieldKey = imgElement.dataset.field;
        let stored = page === '__news_post__'
            ? (EditorConfig.newsPostData ? EditorConfig.newsPostData[fieldKey] : undefined)
            : (EditorConfig.contentData[page] ? getNestedValue(EditorConfig.contentData[page], fieldKey) : undefined);
        if (stored && typeof stored === 'object') stored = stored.src;
        return typeof stored === 'string' && stored ? stored : imageElementPath(imgElement);
    }

    // Site path of a rendered image: without origin, query and hash
    function imageElementPath(img) {
        const src = img ? (img.getAttribute('src') || '') : '';
        if (!src || src.indexOf('data:') === 0) return null;
        return src.replace(/^https?:\/\/[^/]+/i, '').split(/[?#]/)[0] || null;
    }

    function saveImageField(page, fieldKey, newSrc, newAlt, element) {
        // Push undo state
        pushUndoState();

        const format = element.dataset.imageFormat || 'object';

        // Update in-memory data
        if (page === '__news_post__' && EditorConfig.newsPostData) {
            // News post: save image path as flat string
            EditorConfig.newsPostData[fieldKey] = newSrc;
        } else {
            if (!EditorConfig.contentData[page]) EditorConfig.contentData[page] = {};
            if (format === 'string') {
                setNestedValue(EditorConfig.contentData[page], fieldKey, newSrc);
            } else if (format === 'split') {
                // Save src and alt at separate sibling keys — preserves other
                // fields in the parent object (e.g. type, caption, width).
                setNestedValue(EditorConfig.contentData[page], fieldKey, newSrc);
                const altFieldKey = element.dataset.altField;
                if (altFieldKey) {
                    setNestedValue(EditorConfig.contentData[page], altFieldKey, newAlt);
                }
            } else {
                setNestedValue(EditorConfig.contentData[page], fieldKey, { src: newSrc, alt: newAlt });
            }
        }

        // Mark as dirty
        EditorConfig.dirtyPages.add(page);

        // Update DOM
        element.setAttribute('src', newSrc);
        element.setAttribute('alt', newAlt);
        syncAiLabel(element);
        // Remove empty placeholder class if image was set
        const wrapper = element.closest('.editable-image-wrapper');
        if (wrapper) wrapper.classList.remove('editable-image-empty');
        const heroDiv = element.closest('.news-post-page__hero--empty');
        if (heroDiv) heroDiv.classList.remove('news-post-page__hero--empty');

        updateUndoRedoButtons();
    }

    // ============================================================
    // AI DISCLOSURE LABELS
    // The label next to an AI-generated or AI-modified image follows image swaps
    // and label changes in the media library right away (window.NB_AI_LABELS).
    // ============================================================

    function aiLabelKindForSrc(src) {
        const files = window.NB_AI_LABELS && window.NB_AI_LABELS.files;
        if (!files || !src) return '';
        let path;
        try {
            path = decodeURIComponent(new URL(src, location.href).pathname);
        } catch (e) {
            return '';
        }
        for (const [type, marker] of [['image', 'assets/images/'], ['video', 'assets/videos/']]) {
            const position = path.indexOf(marker);
            if (position === -1) continue;
            const name = path.slice(position + marker.length);
            return files[type] && Object.prototype.hasOwnProperty.call(files[type], name) ? files[type][name] : '';
        }
        return '';
    }

    function syncAiLabel(img) {
        const markup = window.NB_AI_LABELS && window.NB_AI_LABELS.markup;
        if (!markup || !img) return;
        // The label follows the image, or its editor wrapper
        const anchor = img.closest('.editable-image-wrapper') || img;
        const next = anchor.nextElementSibling;
        const label = next && next.classList.contains('nb-ai-label') ? next : null;
        const kind = aiLabelKindForSrc(img.getAttribute('src'));
        if (label && kind && label.classList.contains('nb-ai-label--' + kind)) return;
        if (label) label.remove();
        if (kind && markup[kind]) anchor.insertAdjacentHTML('afterend', markup[kind]);
    }

    document.addEventListener('nibbly:ai-label-change', event => {
        const config = window.NB_AI_LABELS;
        const detail = event.detail || {};
        if (!config || !detail.type || !detail.name) return;
        if (!config.files || Array.isArray(config.files)) config.files = {};
        if (!config.files[detail.type] || Array.isArray(config.files[detail.type])) config.files[detail.type] = {};
        if (detail.label) config.files[detail.type][detail.name] = detail.label;
        else delete config.files[detail.type][detail.name];
        document.querySelectorAll('img[data-editable-image], img[data-nb-bind="image"]').forEach(syncAiLabel);
    });

    // ============================================================
    // EDITABLE LISTS (Repeatable Items — Add/Delete/Reorder)
    // ============================================================

    let listDragState = {
        element: null,
        index: null,
        page: null,
        listKey: null
    };

    function attachListEditHandlers() {
        const lists = document.querySelectorAll('[data-editable-list]');
        if (lists.length === 0) return;

        lists.forEach(listContainer => {
            if (listContainer.dataset.editorReady === 'true') return;
            listContainer.dataset.editorReady = 'true';
            const page = listContainer.dataset.listPage;
            const listKey = listContainer.dataset.listKey;
            const defaults = JSON.parse(listContainer.dataset.listDefaults || '{}');

            const items = listContainer.querySelectorAll('[data-list-index]');

            items.forEach(item => {
                const index = parseInt(item.dataset.listIndex, 10);
                item.classList.add('editable-list-item');

                // Check if list item is hidden
                const pageData = EditorConfig.contentData[page];
                const listItems = pageData ? getNestedObj(pageData, listKey) : null;
                const itemData = listItems ? (Array.isArray(listItems) ? listItems[index] : listItems[index.toString()]) : null;
                const isItemHidden = itemData && itemData.hidden === true;

                // Create overlay with drag handle + hide + delete buttons
                const overlay = document.createElement('div');
                const hasGroupEditor = item.hasAttribute('data-editable-group');
                overlay.className = 'editable-list-overlay nibbly-editor-list-overlay';
                overlay.innerHTML = `
                    ${hasGroupEditor ? `<button type="button" class="overlay-btn overlay-btn-edit list-edit-btn nibbly-editor-control-btn" data-editor-tooltip="${escHtml(t('edit'))}" aria-label="${escHtml(t('edit'))}" data-action="edit">${Icons.edit}</button>` : ''}
                    <button type="button" class="overlay-btn overlay-btn-hide list-hide-btn nibbly-editor-control-btn" data-editor-tooltip="${escHtml(isItemHidden ? t('show') : t('hide'))}" aria-label="${escHtml(isItemHidden ? t('show') : t('hide'))}" data-action="hide">${isItemHidden ? Icons.eyeClosed : Icons.eyeOpen}</button>
                    <button type="button" class="overlay-btn overlay-btn-delete list-delete-btn nibbly-editor-control-btn" data-editor-tooltip="${escHtml(t('delete'))}" aria-label="${escHtml(t('delete'))}" data-action="delete">${Icons.delete}</button>
                    <span class="list-drag-handle nibbly-editor-drag-handle" data-editor-tooltip="${escHtml(t('drag_reorder'))}" aria-label="${escHtml(t('drag_reorder'))}">${Icons.drag}</span>
                `;
                item.style.position = 'relative';
                item.appendChild(overlay);
                keepOverlayOpenWhileCrossing(item, overlay);

                // Apply hidden state
                if (isItemHidden) {
                    item.dataset.hidden = 'true';
                }

                // Drag starts only from the handle; inline editing and text
                // selection inside list cards remain normal clicks.
                item.setAttribute('draggable', 'false');

                if (hasGroupEditor) {
                    overlay.querySelector('.list-edit-btn').addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        openGroupEditor(item);
                    });
                }

                const dragHandle = overlay.querySelector('.list-drag-handle');
                if (dragHandle) {
                    const armDrag = () => {
                        item.dataset.dragArmed = 'true';
                        item.setAttribute('draggable', 'true');
                    };
                    const disarmDrag = () => {
                        delete item.dataset.dragArmed;
                        item.setAttribute('draggable', 'false');
                    };
                    dragHandle.addEventListener('pointerdown', armDrag);
                    dragHandle.addEventListener('mousedown', armDrag);
                    dragHandle.addEventListener('pointerup', () => setTimeout(disarmDrag, 0));
                }

                // Drag events — use dynamic index from dataset (survives reorder)
                item.addEventListener('dragstart', (e) => {
                    if (!EditorConfig.editMode) { e.preventDefault(); return; }
                    if (item.dataset.dragArmed !== 'true') { e.preventDefault(); return; }
                    const currentIndex = parseInt(item.dataset.listIndex, 10);
                    listDragState = { element: item, index: currentIndex, page, listKey };
                    item.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', currentIndex.toString());
                    beginDragAssist(e, item);
                    document.querySelectorAll('iframe').forEach(f => f.style.pointerEvents = 'none');
                });

                item.addEventListener('dragend', () => {
                    item.classList.remove('dragging');
                    document.querySelectorAll('.editable-list-item').forEach(el => {
                        el.classList.remove('drag-over', 'drag-over-top', 'drag-over-bottom');
                    });
                    listDragState = { element: null, index: null, page: null, listKey: null };
                    document.querySelectorAll('iframe').forEach(f => f.style.pointerEvents = '');
                    delete item.dataset.dragArmed;
                    item.setAttribute('draggable', 'false');
                });

                item.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (listDragState.listKey !== listKey) return;

                    const rect = item.getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    item.classList.remove('drag-over-top', 'drag-over-bottom');
                    if (e.clientY < midY) {
                        item.classList.add('drag-over-top');
                    } else {
                        item.classList.add('drag-over-bottom');
                    }
                });

                item.addEventListener('dragenter', (e) => {
                    e.preventDefault();
                    if (listDragState.listKey === listKey) {
                        item.classList.add('drag-over');
                    }
                });

                item.addEventListener('dragleave', () => {
                    item.classList.remove('drag-over', 'drag-over-top', 'drag-over-bottom');
                });

                item.addEventListener('drop', (e) => {
                    e.preventDefault();
                    item.classList.remove('drag-over', 'drag-over-top', 'drag-over-bottom');

                    if (listDragState.listKey !== listKey) return;

                    const fromIndex = listDragState.index;
                    const currentIndex = parseInt(item.dataset.listIndex, 10);
                    const rect = item.getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    let toIndex = e.clientY < midY ? currentIndex : currentIndex + 1;
                    if (fromIndex < toIndex) toIndex--;

                    if (fromIndex === toIndex) return;

                    reorderListItems(page, listKey, fromIndex, toIndex);
                });

                // Hide button handler — read index dynamically
                overlay.querySelector('.list-hide-btn').addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const currentIndex = parseInt(item.dataset.listIndex, 10);
                    toggleListItemHidden(page, listKey, currentIndex, item, e.currentTarget);
                });

                // Delete button handler — read index dynamically
                overlay.querySelector('.list-delete-btn').addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const currentIndex = parseInt(item.dataset.listIndex, 10);
                    deleteListItem(page, listKey, currentIndex);
                });

                // Add button at bottom edge of each item
                const addItemBtn = document.createElement('button');
                addItemBtn.type = 'button';
                addItemBtn.className = 'list-item-add-btn';
                addItemBtn.innerHTML = Icons.plus;
                addItemBtn.title = 'Add item';
                addItemBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const currentIndex = parseInt(item.dataset.listIndex, 10);
                    addListItemAfter(page, listKey, defaults, currentIndex);
                });
                item.appendChild(addItemBtn);
            });
        });
    }

    // --- Comparison table row hide buttons ---

    function attachComparisonRowHandlers() {
        const rows = document.querySelectorAll('[data-comparison-row]');
        if (rows.length === 0) return;

        rows.forEach(tr => {
            const rowIndex = parseInt(tr.dataset.comparisonRow, 10);
            const page = tr.dataset.rowPage;
            const isHidden = tr.dataset.hidden === 'true';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'comparison-row-hide-btn';
            btn.title = isHidden ? t('show') : t('hide');
            btn.innerHTML = isHidden ? Icons.eyeClosed : Icons.eyeOpen;

            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!EditorConfig.editMode) return;

                pushUndoState();

                const pageData = EditorConfig.contentData[page];
                if (!pageData) return;

                const items = getNestedObj(pageData, 'comparison.rows');
                if (!items) return;

                const itemData = Array.isArray(items) ? items[rowIndex] : items[rowIndex.toString()];
                if (!itemData) return;

                const nowHidden = !itemData.hidden;
                if (nowHidden) {
                    itemData.hidden = true;
                    tr.dataset.hidden = 'true';
                } else {
                    delete itemData.hidden;
                    delete tr.dataset.hidden;
                }

                btn.innerHTML = nowHidden ? Icons.eyeClosed : Icons.eyeOpen;
                btn.title = nowHidden ? t('show') : t('hide');

                EditorConfig.dirtyPages.add(page);
                updateUndoRedoButtons();
                showToast(nowHidden ? t('toast.row_hidden') : t('toast.row_visible'), 'success');
            });

            // Position eye button at the left edge of the label cell
            const labelCell = tr.querySelector('.comparison-table__label');
            if (!labelCell) return;
            labelCell.classList.add('comparison-label-with-eye');
            labelCell.appendChild(btn);
        });
    }

    // ============================================================
    // COMPARISON CELL TOGGLE BUTTONS (yes / no / text)
    // ============================================================

    function attachComparisonCellToggles() {
        const cells = document.querySelectorAll('[data-cell-field]');
        if (cells.length === 0) return;

        cells.forEach(td => {
            const fieldKey = td.dataset.cellField;
            const page = td.dataset.cellPage;
            const currentType = td.dataset.cellType; // 'yes', 'no', or 'text'
            const editableSpan = td.querySelector('.editable-field');
            if (!editableSpan) return;

            // Create toggle button group
            const group = document.createElement('div');
            group.className = 'cell-toggle-group';

            ['yes', 'no', 'text'].forEach(mode => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cell-toggle-btn' + (mode === currentType ? ' cell-toggle-btn--active' : '');
                btn.dataset.mode = mode;
                btn.textContent = mode === 'yes' ? '✓' : mode === 'no' ? '✗' : 'Aa';
                btn.title = mode === 'yes' ? 'Yes (checkmark)' : mode === 'no' ? 'No (cross)' : 'Custom text';

                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!EditorConfig.editMode) return;

                    pushUndoState();

                    // Update the value in contentData
                    const pageData = EditorConfig.contentData[page];
                    if (!pageData) return;

                    let newValue;
                    if (mode === 'yes') {
                        newValue = 'yes';
                    } else if (mode === 'no') {
                        newValue = 'no';
                    } else {
                        // Switch to text mode — keep current value if already text, else clear
                        const current = getNestedValue(pageData, fieldKey);
                        newValue = (current === 'yes' || current === 'no') ? '' : (current || '');
                    }

                    setNestedValue(pageData, fieldKey, newValue);
                    editableSpan.textContent = newValue;
                    td.dataset.cellType = mode === 'text' ? 'text' : mode;

                    // Update active state on buttons
                    group.querySelectorAll('.cell-toggle-btn').forEach(b => {
                        b.classList.toggle('cell-toggle-btn--active', b.dataset.mode === mode);
                    });

                    // Show/hide the editable text span based on mode
                    editableSpan.classList.toggle('cell-text-hidden', mode !== 'text');
                    if (mode === 'text') {
                        // Show placeholder if empty
                        if (!newValue) {
                            editableSpan.dataset.empty = 'true';
                        } else {
                            delete editableSpan.dataset.empty;
                        }
                        editableSpan.focus();
                    }

                    // Update the icon element
                    const icon = td.querySelector('.cell-type-icon');
                    if (mode === 'text') {
                        if (icon) icon.remove();
                    } else {
                        if (icon) {
                            icon.className = 'cell-type-icon ' + (mode === 'yes' ? 'comparison-yes' : 'comparison-no');
                            icon.textContent = mode === 'yes' ? '✓' : '✗';
                        } else {
                            const newIcon = document.createElement('span');
                            newIcon.className = 'cell-type-icon ' + (mode === 'yes' ? 'comparison-yes' : 'comparison-no');
                            newIcon.textContent = mode === 'yes' ? '✓' : '✗';
                            td.insertBefore(newIcon, group);
                        }
                    }

                    EditorConfig.dirtyPages.add(page);
                    updateUndoRedoButtons();
                });

                group.appendChild(btn);
            });

            // Insert toggle group before the editable span
            td.insertBefore(group, editableSpan);

            // For yes/no cells, add an icon element for admin non-edit view
            if (currentType === 'yes' || currentType === 'no') {
                const icon = document.createElement('span');
                icon.className = 'cell-type-icon ' + (currentType === 'yes' ? 'comparison-yes' : 'comparison-no');
                icon.textContent = currentType === 'yes' ? '✓' : '✗';
                td.insertBefore(icon, group);
            }

            // Mark empty text cells for placeholder
            if (currentType === 'text' && !editableSpan.textContent.trim()) {
                editableSpan.dataset.empty = 'true';
            }

            // Track empty state on input
            editableSpan.addEventListener('input', () => {
                if (editableSpan.textContent.trim()) {
                    delete editableSpan.dataset.empty;
                } else {
                    editableSpan.dataset.empty = 'true';
                }
            });
        });
    }

    // --- List helpers: convert between numbered objects and arrays ---

    function objectToArray(obj) {
        const keys = Object.keys(obj).map(Number).filter(k => !isNaN(k)).sort((a, b) => a - b);
        return keys.map(k => obj[k.toString()]);
    }

    function arrayToNumberedObject(arr) {
        const obj = {};
        arr.forEach((item, i) => { obj[i.toString()] = item; });
        return obj;
    }

    function getNestedObj(obj, dotKey) {
        const keys = dotKey.split('.');
        let current = obj;
        for (const key of keys) {
            if (!current || typeof current !== 'object') return null;
            current = current[key];
        }
        return current;
    }

    function setNestedObj(obj, dotKey, value) {
        const keys = dotKey.split('.');
        let current = obj;
        for (let i = 0; i < keys.length - 1; i++) {
            if (!current[keys[i]] || typeof current[keys[i]] !== 'object') {
                current[keys[i]] = {};
            }
            current = current[keys[i]];
        }
        current[keys[keys.length - 1]] = value;
    }

    // --- List operations (batch mode — modify in-memory, save on "Save") ---

    function cloneListDefault(defaults) {
        if (Array.isArray(defaults)) return [...defaults];
        if (defaults && typeof defaults === 'object') return { ...defaults };
        return defaults;
    }

    function reorderListItems(page, listKey, fromIndex, toIndex) {
        pushReorderUndo('reorder-list', { page, listKey, from: fromIndex, to: toIndex });
        executeListReorder(page, listKey, fromIndex, toIndex);
    }

    function executeListReorder(page, listKey, fromIndex, toIndex) {
        const pageData = EditorConfig.contentData[page];
        const items = getNestedObj(pageData, listKey);
        if (!items) return;

        // Update data
        const arr = Array.isArray(items) ? [...items] : objectToArray(items);
        const [moved] = arr.splice(fromIndex, 1);
        arr.splice(toIndex, 0, moved);
        setNestedObj(pageData, listKey, Array.isArray(items) ? arr : arrayToNumberedObject(arr));

        EditorConfig.dirtyPages.add(page);

        // Update DOM
        const listContainer = document.querySelector(
            `[data-editable-list][data-list-page="${page}"][data-list-key="${listKey}"]`
        );
        if (listContainer) {
            const domItems = Array.from(listContainer.querySelectorAll(':scope > [data-list-index]'));
            if (domItems[fromIndex]) {
                const movedEl = domItems[fromIndex];
                if (fromIndex < toIndex) {
                    const refEl = domItems[toIndex];
                    refEl.parentNode.insertBefore(movedEl, refEl.nextSibling);
                } else {
                    const refEl = domItems[toIndex];
                    refEl.parentNode.insertBefore(movedEl, refEl);
                }
                // Update indices
                listContainer.querySelectorAll(':scope > [data-list-index]').forEach((el, i) => {
                    el.dataset.listIndex = i;
                });
            }
        }

        showToast(t('toast.order_changed'), 'success');
    }

    function deleteListItem(page, listKey, index) {
        showConfirmDialog(
            t('item.delete'),
            t('item.delete_confirm'),
            t('section.reload_hint'),
            () => {
                pushUndoState();

                const pageData = EditorConfig.contentData[page];
                const items = getNestedObj(pageData, listKey);
                const arr = Array.isArray(items) ? [...items] : objectToArray(items);
                arr.splice(index, 1);

                setNestedObj(pageData, listKey, Array.isArray(items) ? arr : arrayToNumberedObject(arr));
                EditorConfig.dirtyPages.add(page);
                updateUndoRedoButtons();

                saveStructuralChange(page, 'Item deleted');
            }
        );
    }

    function addListItem(page, listKey, defaults) {
        pushUndoState();

        const pageData = EditorConfig.contentData[page];
        const items = getNestedObj(pageData, listKey) || {};

        if (Array.isArray(items)) {
            items.push(cloneListDefault(defaults));
            setNestedObj(pageData, listKey, items);
        } else {
            const keys = Object.keys(items).map(Number).filter(k => !isNaN(k));
            const nextIndex = keys.length > 0 ? Math.max(...keys) + 1 : 0;
            items[nextIndex.toString()] = cloneListDefault(defaults);
            setNestedObj(pageData, listKey, items);
        }
        EditorConfig.dirtyPages.add(page);
        updateUndoRedoButtons();

        saveStructuralChange(page, 'Item added');
    }

    function addListItemAfter(page, listKey, defaults, afterIndex) {
        pushUndoState();

        const pageData = EditorConfig.contentData[page];
        const items = getNestedObj(pageData, listKey) || {};

        const itemsAreArray = Array.isArray(items);
        const arr = itemsAreArray ? [...items] : objectToArray(items);
        arr.splice(afterIndex + 1, 0, cloneListDefault(defaults));

        setNestedObj(pageData, listKey, itemsAreArray ? arr : arrayToNumberedObject(arr));
        EditorConfig.dirtyPages.add(page);
        updateUndoRedoButtons();

        saveStructuralChange(page, 'Item added');
    }

    // --- Hide / Show list items ---

    function toggleListItemHidden(page, listKey, index, itemEl, btnEl) {
        const pageData = EditorConfig.contentData[page];
        if (!pageData) return;

        const items = getNestedObj(pageData, listKey);
        if (!items) return;

        const itemData = Array.isArray(items) ? items[index] : items[index.toString()];
        if (!itemData) return;

        pushUndoState();

        const nowHidden = !itemData.hidden;

        if (nowHidden) {
            itemData.hidden = true;
            itemEl.dataset.hidden = 'true';
        } else {
            delete itemData.hidden;
            delete itemEl.dataset.hidden;
        }

        // Update button icon
        btnEl.innerHTML = nowHidden ? Icons.eyeClosed : Icons.eyeOpen;
        const label = nowHidden ? t('show') : t('hide');
        btnEl.dataset.editorTooltip = label;
        btnEl.setAttribute('aria-label', label);

        EditorConfig.dirtyPages.add(page);
        updateUndoRedoButtons();

        showToast(nowHidden ? t('toast.hidden') : t('toast.visible'), 'success');
    }

    /**
     * Structural changes that require a page reload (add/delete sections or list items)
     * because PHP templates render the HTML server-side.
     * Saves ALL dirty pages first, then reloads with edit-mode auto-restore.
     */
    async function saveStructuralChange(page, description) {
        try {
            // Collect all dirty pages (including the structural change page)
            const pagesToSave = new Set(EditorConfig.dirtyPages);
            pagesToSave.add(page);

            for (const p of pagesToSave) {
                const pageData = EditorConfig.contentData[p];
                if (!pageData) continue;

                const formData = new FormData();
                formData.append('action', 'save');
                formData.append('page', p);
                formData.append('content', JSON.stringify(pageData));
                formData.append('csrf_token', EditorConfig.csrfToken);

                const response = await fetch(EditorConfig.apiUrl, { method: 'POST', body: formData });
                const result = await response.json();

                if (!result.success) {
                    showToast(t('toast.error_generic', { message: result.message || 'Unknown' }), 'error');
                    return;
                }
            }

            // Persist edit-mode flag so it auto-restores after reload
            sessionStorage.setItem('site-edit-mode', 'true');
            showToast(description, 'success');
            // Detach beforeunload guard before the programmatic reload —
            // otherwise the browser shows its native "leave page?" dialog
            // even though the editor itself is triggering the reload and
            // all dirty pages were just persisted above.
            window.removeEventListener('beforeunload', beforeUnloadGuard);
            setTimeout(() => location.reload(), 300);
        } catch (error) {
            showToast(t('toast.error_saving', { page: '', message: error.message }), 'error');
        }
    }

    function openEventEditor(eventId) {
        const modal = document.getElementById('event-editor-modal');
        const title = document.getElementById('event-editor-title');
        const deleteBtn = document.getElementById('event-delete-btn');
        const langs = Object.keys(EditorConfig.languages);
        const translatableFields = ['title', 'location', 'description', 'admission'];

        // Reset all fields
        document.getElementById('event-id').value = '';
        document.getElementById('event-date').value = '';
        document.getElementById('event-time').value = '';
        document.getElementById('event-end-date').value = '';
        document.getElementById('event-end-time').value = '';
        document.getElementById('event-url').value = '';
        document.getElementById('event-image').value = '';
        translatableFields.forEach(field => {
            langs.forEach(lang => {
                const el = document.getElementById(`event-${field}-${lang}`);
                if (el) el.value = '';
            });
        });

        // Reset tabs to default language
        modal.querySelectorAll('.event-lang-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.lang === EditorConfig.defaultLang);
            t.setAttribute('aria-selected', t.dataset.lang === EditorConfig.defaultLang ? 'true' : 'false');
            t.tabIndex = t.dataset.lang === EditorConfig.defaultLang ? 0 : -1;
        });
        modal.querySelectorAll('.event-lang-panel').forEach(p => {
            const active = p.dataset.lang === EditorConfig.defaultLang;
            p.hidden = !active;
            p.classList.toggle('active', active);
        });

        if (eventId) {
            title.textContent = t('event.edit');
            deleteBtn.style.display = 'inline-block';

            const event = EditorConfig.eventsData?.events?.find(c => c.id === eventId);
            if (event) {
                EditorConfig.currentEvent = event;
                document.getElementById('event-id').value = event.id;
                document.getElementById('event-date').value = event.date || '';
                document.getElementById('event-time').value = event.time || '';
                document.getElementById('event-end-date').value = event['end-date'] || '';
                document.getElementById('event-end-time').value = event['end-time'] || '';
                document.getElementById('event-url').value = event.url || '';
                document.getElementById('event-image').value = event.image || '';

                translatableFields.forEach(field => {
                    langs.forEach(lang => {
                        const el = document.getElementById(`event-${field}-${lang}`);
                        if (el) el.value = (event[field] && event[field][lang]) || '';
                    });
                });
            }
        } else {
            title.textContent = t('event.new');
            deleteBtn.style.display = 'none';
            EditorConfig.currentEvent = null;
        }

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeEventModal() {
        const modal = document.getElementById('event-editor-modal');
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';
        EditorConfig.currentEvent = null;
    }

    async function saveEvent() {
        const langs = Object.keys(EditorConfig.languages);
        const defaultLang = EditorConfig.defaultLang;
        const translatableFields = ['title', 'location', 'description', 'admission'];

        // Build translatable field objects
        const fieldObjects = {};
        translatableFields.forEach(field => {
            fieldObjects[field] = {};
            langs.forEach(lang => {
                const el = document.getElementById(`event-${field}-${lang}`);
                if (el) fieldObjects[field][lang] = el.value;
            });
        });

        const event = {
            id: document.getElementById('event-id').value || '',
            date: document.getElementById('event-date').value,
            time: document.getElementById('event-time').value,
            'end-date': document.getElementById('event-end-date').value,
            'end-time': document.getElementById('event-end-time').value,
            url: document.getElementById('event-url').value,
            ...fieldObjects,
            image: document.getElementById('event-image').value
        };

        if (!event.date || !event.title[defaultLang]) {
            showToast(t('event.date_required'), 'error');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'save-event');
            formData.append('event', JSON.stringify(event));
            formData.append('csrf_token', EditorConfig.csrfToken);

            const response = await fetch(EditorConfig.apiUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message || t('toast.saved'), 'success');
                closeEventModal();
                setTimeout(() => location.reload(), 500);
            } else {
                showToast(t('toast.error_generic', { message: result.message }), 'error');
            }
        } catch (error) {
            showToast(t('toast.error_saving', { page: '', message: error.message }), 'error');
        }
    }

    function deleteEvent() {
        const eventId = document.getElementById('event-id').value;
        if (!eventId) return;

        showConfirmDialog(
            t('event.delete'),
            t('event.delete_confirm'),
            t('event.cannot_undo'),
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete-event');
                    formData.append('id', eventId);
                    formData.append('csrf_token', EditorConfig.csrfToken);

                    const response = await fetch(EditorConfig.apiUrl, {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showToast(t('toast.event_deleted'), 'success');
                        closeEventModal();
                        setTimeout(() => location.reload(), 500);
                    } else {
                        showToast(t('toast.error_generic', { message: result.message }), 'error');
                    }
                } catch (error) {
                    showToast(t('toast.error_deleting', { message: error.message }), 'error');
                }
            }
        );
    }

    // Toggle event visibility (hide/show)
    async function toggleEventVisibility(eventId) {
        if (!eventId) return;

        try {
            const formData = new FormData();
            formData.append('action', 'toggle-event-visibility');
            formData.append('id', eventId);
            formData.append('csrf_token', EditorConfig.csrfToken);

            const response = await fetch(EditorConfig.apiUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                const nowHidden = result.data.hidden;
                const card = document.querySelector('.event-card[data-event-id="' + eventId + '"]');
                if (card) {
                    card.classList.toggle('event-card-hidden', nowHidden);
                    const hideBtn = card.querySelector('.event-hide-btn');
                    if (hideBtn) {
                        hideBtn.title = nowHidden ? t('show') : t('hide');
                        hideBtn.dataset.hidden = nowHidden ? 'true' : 'false';
                        hideBtn.innerHTML = nowHidden ? Icons.eyeClosed : Icons.eyeOpen;
                    }
                }
                showToast(nowHidden ? t('toast.event_hidden') : t('toast.event_visible'), 'success');
            } else {
                showToast(t('toast.error_generic', { message: result.message }), 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', { message: error.message }), 'error');
        }
    }

    // ============================================================
    // VERLAUF-MODAL (Undo-Historie)
    // ============================================================

    function createHistoryModal() {
        if (document.getElementById('history-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'history-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-medium">
                <div class="editor-modal-header">
                    <h3>${t('history.title')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeHistoryModal()">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div id="history-list" class="history-list">
                        <!-- Dynamically populated -->
                    </div>
                </div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-danger" onclick="InlineEditor.clearHistory()">${t('history.clear')}</button>
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closeHistoryModal()">${t('close')}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', closeHistoryModal);

        // Resizable Modal
        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    function openHistoryModal() {
        createHistoryModal();

        const listEl = document.getElementById('history-list');
        listEl.innerHTML = '';

        // Collect all pages with backups
        const allPages = UndoHistory.getAllPages();
        let hasAnyBackups = false;

        allPages.forEach(page => {
            const backups = UndoHistory.getBackups(page);
            if (backups.length > 0) {
                hasAnyBackups = true;

                // Page header
                const pageHeader = document.createElement('div');
                pageHeader.className = 'history-page-header';
                pageHeader.textContent = page.replace('_', ' → ').toUpperCase();
                listEl.appendChild(pageHeader);

                // Backup entries
                backups.forEach((backup, index) => {
                    const entry = document.createElement('div');
                    entry.className = 'history-entry';
                    entry.innerHTML = `
                        <div class="history-entry-info">
                            <span class="history-entry-date">${backup.date}</span>
                            <span class="history-entry-action">${backup.action}</span>
                        </div>
                        <button type="button" class="editor-btn editor-btn-small editor-btn-primary"
                                onclick="InlineEditor.restoreFromHistory('${page}', ${backup.timestamp})">
                            ${t('history.restore')}
                        </button>
                    `;
                    listEl.appendChild(entry);
                });
            }
        });

        if (!hasAnyBackups) {
            listEl.innerHTML = '<p class="history-empty">' + t('history.empty') + '</p>';
        }

        document.getElementById('history-modal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeHistoryModal() {
        const modal = document.getElementById('history-modal');
        if (modal) {
            modal.classList.remove('active');
            ModalResize.reset(modal.querySelector('.editor-modal-content'));
            document.body.style.overflow = '';
        }
    }

    async function restoreFromHistory(contentPage, timestamp) {
        const backups = UndoHistory.getBackups(contentPage);
        const backup = backups.find(b => b.timestamp === timestamp);

        if (!backup) {
            showToast(t('history.not_found'), 'error');
            return;
        }

        showConfirmDialog(
            t('history.restore_title'),
            t('history.restore_confirm', { date: backup.date }),
            t('history.overwrite_warning'),
            async () => {
                closeHistoryModal();
                await restoreContentState(backup.data, contentPage);
            },
            t('history.overwrite')
        );
    }

    function clearHistory() {
        showConfirmDialog(
            t('history.clear'),
            t('history.clear_confirm'),
            t('event.cannot_undo'),
            () => {
                UndoHistory.clearAllHistory();
                closeHistoryModal();
                showToast(t('history.cleared'), 'success');
            }
        );
    }

    // ============================================================
    // IMAGE MANAGER — thin wrappers around NbImageManager (js/image-manager.js)
    // ============================================================

    // Initialize shared image manager with frontend editor context.
    // Called lazily on first open() since EditorConfig.csrfToken is set
    // by initEditor() after the meta tag is parsed.
    let _nbImgMgrInitialized = false;
    function ensureImageManagerInit() {
        if (_nbImgMgrInitialized || !window.NbImageManager) return;
        NbImageManager.init({
            apiUrl: EditorConfig.apiUrl,
            csrfToken: EditorConfig.csrfToken,
            t: t,
            showToast: showToast,
            showConfirm: function(title, message, onYes) {
                showConfirmDialog(title, message, '', onYes);
            }
        });
        _nbImgMgrInitialized = true;
    }

    function updateImagePreview(inputId) {
        // Derive preview element ID from input ID (editor-input-{key}-{type} → editor-image-preview-{key})
        let previewId = 'editor-image-preview';
        let input;
        if (inputId) {
            input = document.getElementById(inputId);
            const m = inputId.match(/^editor-input-(.+?)-[^-]+$/);
            if (m) previewId = 'editor-image-preview-' + m[1];
        } else {
            // Fallback: find any visible image input in the editor modal
            input = document.querySelector('.editor-field[style*="block"] input[id^="editor-input-"][id$="-image"]');
            if (input) {
                inputId = input.id;
                const m = inputId.match(/^editor-input-(.+?)-[^-]+$/);
                if (m) previewId = 'editor-image-preview-' + m[1];
            }
        }
        const preview = document.getElementById(previewId);
        if (!input || !preview) return;
        const imagePath = input.value.trim();

        if (imagePath) {
            const img = document.createElement('img');
            img.src = imagePath;
            img.alt = 'Preview';
            img.onerror = function() { preview.innerHTML = '<p class="preview-placeholder">Image not found</p>'; };
            preview.innerHTML = '';
            preview.appendChild(img);
        } else {
            preview.innerHTML = '<p class="preview-placeholder">' + t('image.no_selection') + '</p>';
        }
    }

    function openImageManager(callback, targetInputId, currentPath) {
        ensureImageManagerInit();
        // Default callback: update a specific editor image input by ID
        const cb = callback || function(imagePath) {
            const input = targetInputId ? document.getElementById(targetInputId) : null;
            if (input) {
                input.value = imagePath;
                updateImagePreview(targetInputId);
            }
        };
        const currentInput = targetInputId ? document.getElementById(targetInputId) : null;
        // The library preselects the current image
        const current = currentPath || (currentInput ? currentInput.value : null);
        NbImageManager.open(cb, current, { types: ['image'], type: 'image' });
    }

    /** Empties a block image field (path, preview and the matching alt-text field) so the block has no image. */
    function clearImageField(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        input.value = '';
        updateImagePreview(inputId);
        const m = inputId.match(/^editor-input-(.+?)-image$/);
        const alt = m ? (document.getElementById(`editor-input-${m[1]}Alt-input`) || document.getElementById('editor-input-alt-input')) : null;
        if (alt) alt.value = '';
    }

    function closeImageManager() { NbImageManager.close(); }
    function confirmImageSelection() { NbImageManager.confirmSelection(); }

    function openImageManagerForEvent() {
        ensureImageManagerInit();
        const eventInput = document.getElementById('event-image');
        NbImageManager.open(function(imagePath) {
            const input = document.getElementById('event-image');
            if (input) {
                input.value = imagePath;
                // Update preview if an updater exists
                if (typeof updateEventImagePreview === 'function') {
                    updateEventImagePreview();
                }
            }
        }, eventInput ? eventInput.value : null, { types: ['image'], type: 'image' });
    }

    // Lightbox for previewing the image currently entered in the editor's image input
    function openEditorImageLightbox(inputId) {
        const input = inputId ? document.getElementById(inputId) : null;
        if (!input) return;
        const imagePath = input.value.trim();
        if (!imagePath) {
            showToast(t('image.no_selection'), 'error');
            return;
        }
        const imageName = imagePath.split('/').pop();
        // NbImageManager lightbox is internal; open it via a temporary approach:
        // simplest: use a built-in modal by opening the manager and letting the user preview
        // For quick preview without manager, create a minimal inline lightbox:
        let lb = document.getElementById('nb-imgmgr-inline-lightbox');
        if (!lb) {
            lb = document.createElement('div');
            lb.id = 'nb-imgmgr-inline-lightbox';
            lb.className = 'nb-imgmgr-lightbox';
            lb.innerHTML =
                '<div class="nb-imgmgr-lightbox-content">' +
                    '<button type="button" class="nb-imgmgr-lightbox-close" aria-label="Close">&times;</button>' +
                    '<img alt="">' +
                    '<div class="nb-imgmgr-lightbox-info"></div>' +
                '</div>';
            document.body.appendChild(lb);
            lb.addEventListener('click', function(e) { if (e.target === lb) lb.classList.remove('active'); });
            lb.querySelector('.nb-imgmgr-lightbox-close').addEventListener('click', function() { lb.classList.remove('active'); });
        }
        lb.querySelector('img').src = imagePath;
        lb.querySelector('.nb-imgmgr-lightbox-info').textContent = imageName;
        lb.classList.add('active');
    }

    // Legacy no-ops (in case any old onclick attribute in dynamically-rendered HTML still references these)
    function closeLightbox() {
        const lb = document.getElementById('nb-imgmgr-inline-lightbox');
        if (lb) lb.classList.remove('active');
    }
    function closeReplaceDialog() { /* handled internally by NbImageManager */ }

    // ============================================================

    // ============================================================
    // AUDIO-MANAGER
    // ============================================================

    let audioManagerCallback = null;
    let audioManagerData = [];

    function createAudioManagerModal() {
        if (document.getElementById('audio-manager-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'audio-manager-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-wide">
                <div class="editor-modal-header">
                    <h3>${t('audio.manager')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeAudioManager()">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div class="image-manager-toolbar">
                        <label class="editor-btn editor-btn-primary image-upload-btn">
                            ${Icons.upload} ${t('audio.upload')}
                            <input type="file" id="audio-upload-input" accept=".mp3,.wav,.ogg,.m4a,.aac,.flac,audio/*" style="display:none;">
                        </label>
                        <span class="image-manager-info">${t('audio.formats_hint')}</span>
                        <div class="image-manager-search">
                            <input type="text" id="audio-search-input" class="image-search-input" placeholder="${t('audio.search')}">
                        </div>
                    </div>
                    <div class="image-manager-container">
                        <div id="audio-manager-list" class="audio-manager-list"></div>
                    </div>
                </div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closeAudioManager()">${t('close')}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', closeAudioManager);

        // Resizable Modal
        ModalResize.init(modal.querySelector('.editor-modal-content'));

        // Upload Event
        document.getElementById('audio-upload-input').addEventListener('change', handleAudioUpload);

        // Suche Event
        document.getElementById('audio-search-input').addEventListener('input', filterAndRenderAudio);
    }

    function openAudioManager(targetInputIdOrCallback) {
        ensureImageManagerInit();
        // Allow either a callback function or a target input ID
        let currentPath = null;
        let callback;
        if (typeof targetInputIdOrCallback === 'function') {
            callback = targetInputIdOrCallback;
        } else {
            const targetInputId = targetInputIdOrCallback || 'editor-input-src-audio';
            const input = document.getElementById(targetInputId);
            currentPath = input ? input.value : null;
            callback = (audioPath) => {
                const input = document.getElementById(targetInputId);
                if (input) input.value = audioPath;
                updateAudioPreview();
            };
        }

        NbImageManager.open(callback, currentPath, { types: ['audio'], type: 'audio' });
    }

    function closeAudioManager() {
        const modal = document.getElementById('audio-manager-modal');
        if (!modal) {
            if (window.NbImageManager) NbImageManager.close();
            return;
        }
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';
        audioManagerCallback = null;
    }

    async function loadAudioFiles() {
        const listEl = document.getElementById('audio-manager-list');
        listEl.innerHTML = '<div class="image-manager-loading">' + t('audio.loading') + '</div>';

        try {
            const response = await fetch(`${EditorConfig.apiUrl}?action=list-audio`);
            const result = await response.json();

            if (result.success) {
                audioManagerData = result.data || [];
                filterAndRenderAudio();
            } else {
                listEl.innerHTML = '<div class="image-manager-error">' + t('audio.error_loading') + '</div>';
            }
        } catch (error) {
            listEl.innerHTML = '<div class="image-manager-error">' + t('toast.error_generic', { message: error.message }) + '</div>';
        }
    }

    function filterAndRenderAudio() {
        const searchInput = document.getElementById('audio-search-input');
        const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';

        let filtered = audioManagerData;
        if (searchTerm) {
            filtered = audioManagerData.filter(audio =>
                audio.name.toLowerCase().includes(searchTerm)
            );
        }

        renderAudioList(filtered);
    }

    function renderAudioList(audioFiles) {
        const listEl = document.getElementById('audio-manager-list');

        if (audioFiles.length === 0) {
            listEl.innerHTML = '<div class="image-manager-empty">' + t('audio.no_files') + '</div>';
            return;
        }

        let html = `
            <div class="image-list-header">
                <span></span>
                <span class="image-list-header-col">${t('image.col_filename')}</span>
                <span class="image-list-header-col">${t('image.col_size')}</span>
                <span class="image-list-header-col">${t('image.col_date')}</span>
                <span class="image-list-header-col">${t('image.col_actions')}</span>
            </div>
            <div class="image-list-body">
        `;

        audioFiles.forEach(audio => {
            html += `
                <div class="image-list-row" data-name="${audio.name}">
                    <span class="audio-list-icon">${Icons.audio}</span>
                    <span class="image-list-name" title="${audio.name}">${audio.name}</span>
                    <span class="image-list-size">${audio.size}</span>
                    <span class="image-list-date">${audio.dateFormatted}</span>
                    <span class="image-list-actions">
                        <button type="button" class="image-action-btn image-action-select audio-action-select" title="${t('audio.select')}">
                            ${Icons.check}
                        </button>
                        <button type="button" class="image-action-btn image-action-preview audio-action-play" title="${t('audio.play')}">
                            ▶
                        </button>
                        <button type="button" class="image-action-btn image-action-delete audio-action-delete" title="${t('delete')}">
                            ${Icons.delete}
                        </button>
                    </span>
                </div>
            `;
        });

        html += '</div>';
        listEl.innerHTML = html;

        // Add event listeners
        listEl.querySelectorAll('.image-list-row').forEach((row, idx) => {
            const audio = audioFiles[idx];

            row.querySelector('.audio-action-select').addEventListener('click', (e) => {
                e.stopPropagation();
                selectAudio(audio.path);
            });

            row.querySelector('.audio-action-play').addEventListener('click', (e) => {
                e.stopPropagation();
                playAudioPreview(audio.path, audio.name);
            });

            row.querySelector('.audio-action-delete').addEventListener('click', (e) => {
                e.stopPropagation();
                deleteAudio(audio.name);
            });
        });
    }

    function selectAudio(audioPath) {
        if (audioManagerCallback) {
            audioManagerCallback(audioPath);
        }
        closeAudioManager();
    }

    function playAudioPreview(audioPath, audioName) {
        // Einfacher Audio-Preview
        let previewAudio = document.getElementById('audio-preview-player');
        if (!previewAudio) {
            previewAudio = document.createElement('audio');
            previewAudio.id = 'audio-preview-player';
            previewAudio.controls = true;
            previewAudio.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:30000;background:#222;border-radius:8px;padding:5px;';
            document.body.appendChild(previewAudio);

            // Remove on end or pause
            previewAudio.addEventListener('ended', () => previewAudio.remove());
            previewAudio.addEventListener('pause', () => setTimeout(() => previewAudio.remove(), 2000));
        }

        previewAudio.src = audioPath;
        previewAudio.play();
        showToast(t('audio.playing', { name: audioName }), 'success');
    }

    async function deleteAudio(filename) {
        showConfirmDialog(
            t('audio.delete'),
            t('audio.delete_confirm', { filename }),
            t('audio.trash_hint'),
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete-audio');
                    formData.append('filename', filename);
                    formData.append('csrf_token', EditorConfig.csrfToken);

                    const response = await fetch(EditorConfig.apiUrl, {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showToast(t('audio.trashed'), 'success');
                        loadAudioFiles();
                    } else {
                        showToast(t('toast.error_generic', { message: result.message }), 'error');
                    }
                } catch (error) {
                    showToast(t('toast.error_generic', { message: error.message }), 'error');
                }
            }
        );
    }

    async function handleAudioUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        // Check file type
        const allowedTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/aac', 'audio/flac'];
        if (!allowedTypes.includes(file.type) && !file.name.match(/\.(mp3|wav|ogg|m4a|aac|flac)$/i)) {
            showToast(t('audio.format_error'), 'error');
            e.target.value = '';
            return;
        }

        // Check file size (max 50MB)
        if (file.size > 50 * 1024 * 1024) {
            showToast(t('audio.size_error'), 'error');
            e.target.value = '';
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'upload-audio');
            formData.append('audio', file);
            formData.append('csrf_token', EditorConfig.csrfToken);

            showToast(t('image.uploading'), 'success');

            const response = await fetch(EditorConfig.apiUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showToast(t('audio.uploaded'), 'success');
                loadAudioFiles();
                // Optional: select audio directly
                if (result.data && result.data.path) {
                    selectAudio(result.data.path);
                }
            } else {
                showToast(t('toast.error_generic', { message: result.message }), 'error');
            }
        } catch (error) {
            showToast(t('toast.upload_error', { message: error.message }), 'error');
        }

        e.target.value = '';
    }

    // ============================================================
    // NEWS POST INLINE EDITING
    // ============================================================

    // Editable parts of a post page. A site template marks them with data-news-field="title|content|author|image|excerpt|cover"
    // (on the image itself or its figure); without the attribute the classes of includes/news-post.php apply.
    function newsPostField(postEl, name) {
        const marked = postEl.querySelector('[data-news-field="' + name + '"]');
        if (marked) return name === 'image' && marked.tagName !== 'IMG' ? marked.querySelector('img') : marked;
        const el = postEl.querySelector(name === 'image' ? '.news-post-page__hero img' : '.news-post-page__' + name);
        // a column of blocks (data-news-blocks) is edited block by block, not as one HTML field
        return el && el.hasAttribute('data-news-blocks') ? null : el;
    }

    // ------------------------------------------------------------
    // Posts built from blocks: the site template marks each block with data-news-block="sections.N|aside.N"
    // and data-block-type (text blocks in a wrapper, image blocks on the <figure>), each column with
    // data-news-blocks. Text, captions and the excerpt are edited in place and saved with "Speichern";
    // adding, moving and deleting blocks saves at once and reloads in edit mode (like page sections).
    // ------------------------------------------------------------
    let pageBlockPage = null; // page whose sections the block tools work on (null on post pages)

    function newsBlockRefs(postEl) {
        return Array.from(postEl.querySelectorAll('[data-news-block]')).map(el => {
            const parts = String(el.dataset.newsBlock).split('.');
            return { el, field: parts[0], index: parseInt(parts[1], 10), type: el.dataset.blockType || '' };
        }).filter(ref => (ref.field === 'sections' || ref.field === 'aside') && Number.isInteger(ref.index));
    }

    function newsBlockList(field) {
        if (pageBlockPage) return EditorConfig.contentData[pageBlockPage].sections;
        if (!Array.isArray(EditorConfig.newsPostData[field])) EditorConfig.newsPostData[field] = [];
        return EditorConfig.newsPostData[field];
    }

    // The template wraps a plain excerpt in one <p>; stored is the text inside
    function newsUnwrapParagraph(html) {
        const match = /^\s*<p>([\s\S]*?)<\/p>\s*$/i.exec(html);
        return match && !/<p[\s>]/i.test(match[1]) ? match[1].trim() : html.trim();
    }

    function newsEditableHtml(el) {
        const copy = el.cloneNode(true);
        copy.querySelectorAll('.news-block-tools, .news-blocks-add, .news-block-add').forEach(node => node.remove());
        return copy.innerHTML.trim();
    }

    // The editable parts as they stand in the page → EditorConfig.newsPostData
    function collectNewsPostFields(postEl) {
        const data = EditorConfig.newsPostData;
        if (!data) return;
        const titleEl = newsPostField(postEl, 'title');
        const contentEl = newsPostField(postEl, 'content');
        const authorEl = newsPostField(postEl, 'author');
        const excerptEl = newsPostField(postEl, 'excerpt');
        if (titleEl) data.title = titleEl.textContent.trim();
        const subtitleEl = newsPostField(postEl, 'subtitle');
        if (subtitleEl) data.subtitle = subtitleEl.textContent.trim();
        if (contentEl) data.content = newsEditableHtml(contentEl);
        if (authorEl) data.author = authorEl.textContent.trim().replace(/^by\s+/i, '');
        if (excerptEl) data.excerpt = newsUnwrapParagraph(newsEditableHtml(excerptEl));
        collectNewsBlockFields(postEl);
    }

    function collectNewsBlockFields(container) {
        newsBlockRefs(container).forEach(ref => {
            const block = newsBlockList(ref.field)[ref.index];
            if (!block) return;
            if (ref.type === 'text') {
                block.content = newsEditableHtml(ref.el);
            } else if (ref.type === 'image') {
                Object.assign(block, newsCaptionData(ref.el.querySelector('figcaption')));
            } else if (ref.type === 'gallery') {
                if (!Array.isArray(block.images)) return;
                ref.el.querySelectorAll('figure[data-gallery-item]').forEach(figure => {
                    const image = block.images[parseInt(figure.dataset.galleryItem, 10)];
                    if (image) Object.assign(image, newsCaptionData(figure.querySelector('figcaption')));
                });
            }
        });
    }

    // caption and credit as edited in a <figcaption> (credit sits in .block-image__credit)
    function newsCaptionData(caption) {
        if (!caption) return {};
        const credit = caption.querySelector('.block-image__credit');
        const copy = caption.cloneNode(true);
        copy.querySelectorAll('.block-image__credit').forEach(node => node.remove());
        return {
            credit: credit ? credit.textContent.replace(/\s+/g, ' ').trim() : '',
            caption: copy.textContent.replace(/\s+/g, ' ').trim()
        };
    }

    async function newsStructuralChange(mutate) {
        if (pageBlockPage) {
            const area = pageBlockArea();
            if (area) collectNewsBlockFields(area);
            savePageSectionChange(pageBlockPage, mutate);
            return;
        }
        const postEl = document.querySelector('[data-news-post]');
        if (!postEl || !EditorConfig.newsPostData) return;
        collectNewsPostFields(postEl);
        mutate(EditorConfig.newsPostData);
        const formData = new FormData();
        formData.append('action', 'save-news');
        formData.append('post', JSON.stringify(EditorConfig.newsPostData));
        formData.append('csrf_token', EditorConfig.csrfToken);
        try {
            const result = await (await fetch(EditorConfig.apiUrl, { method: 'POST', body: formData })).json();
            if (!result.success) {
                showToast(t('toast.error_saving', { page: 'news', message: result.message || 'Unknown' }), 'error');
                return;
            }
            EditorConfig.dirtyPages.delete('__news_post__');
            sessionStorage.setItem('site-edit-mode', 'true');
            // the editor itself reloads: without this the browser asks "leave page?" although everything is saved
            window.removeEventListener('beforeunload', beforeUnloadGuard);
            location.reload();
        } catch (error) {
            showToast(error.message, 'error');
        }
    }

    function newsNewBlock(type, extra) {
        return Object.assign({ id: 'block_' + Date.now().toString(36), type }, extra);
    }

    function addNewsBlockAt(field, index, type) {
        if (type === 'gallery') {
            openImageManager(function(imagePath) {
                closeImageManager();
                newsStructuralChange(() => newsBlockList(field).splice(index, 0, newsNewBlock('gallery', { images: [{ src: imagePath, alt: '', caption: '', credit: '' }] })));
            }, null, null);
            return;
        }
        if (type === 'image') {
            openImageManager(function(imagePath) {
                closeImageManager();
                newsStructuralChange(() => newsBlockList(field).splice(index, 0, newsNewBlock('image', { src: imagePath, alt: '', caption: '', credit: '', width: 'full', align: 'right', lightbox: true })));
            }, null, null);
            return;
        }
        if (type === 'divider' || type === 'spacer') {
            newsStructuralChange(() => newsBlockList(field).splice(index, 0, newsNewBlock(type, newsBlockDefaults(type))));
            return;
        }
        if (type !== 'text' && BlockTypes[type]) {
            // youtube, audio: the field dialog of the page sections collects the data first
            openNewsBlockEditor(newsNewBlock(type, newsBlockDefaults(type)), block => newsStructuralChange(() => newsBlockList(field).splice(index, 0, block)));
            return;
        }
        newsStructuralChange(() => newsBlockList(field).splice(index, 0, newsNewBlock('text', { content: '<p>' + t('news_block.new_text') + '</p>' })));
    }

    function newsBlockDefaults(type) {
        return JSON.parse(JSON.stringify(BlockTypes[type]?.defaults || {}));
    }

    // Field dialog (#editor-modal) for a post block: saveSection() hands the edited block to onSave
    // instead of writing it into a page
    function openNewsBlockEditor(block, onSave) {
        EditorConfig.newsEditorSave = onSave;
        openEditor(-1, block, '__news_post__');
    }

    // The registry's defaults ("New text block", "Image caption") are for new blocks; an existing one opens with what it holds
    function newsBlockForDialog(block) {
        const copy = Object.assign({}, block);
        (BlockTypes[block.type]?.fields || []).forEach(field => {
            if (field.type !== 'select' && field.type !== 'checkbox' && copy[field.key] === undefined) copy[field.key] = '';
        });
        return copy;
    }

    function editNewsBlockAt(field, index) {
        // unsaved typing goes into the dialog; saving collects from the DOM again, then the dialog's block replaces the stored one
        if (pageBlockPage) {
            const area = pageBlockArea();
            if (area) collectNewsBlockFields(area);
        } else {
            const postEl = document.querySelector('[data-news-post]');
            if (postEl) collectNewsPostFields(postEl);
        }
        const current = newsBlockList(field)[index];
        if (!current) return;
        openNewsBlockEditor(newsBlockForDialog(current), block => newsStructuralChange(() => { newsBlockList(field)[index] = block; }));
    }

    function toggleNewsBlockHiddenAt(field, index) {
        newsStructuralChange(() => {
            const block = newsBlockList(field)[index];
            if (!block) return;
            if (block.hidden) delete block.hidden;
            else block.hidden = true;
        });
    }

    function moveNewsBlockAt(field, index, direction) {
        const list = newsBlockList(field);
        const target = index + direction;
        if (target < 0 || target >= list.length) return;
        newsStructuralChange(() => { [list[index], list[target]] = [list[target], list[index]]; });
    }

    // Split a text block at the top-level element that holds the caret (the element and
    // everything after it move into a new block); null when nothing to split
    function newsSplitElement(el) {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount) return null;
        let node = sel.getRangeAt(0).startContainer;
        if (!el.contains(node) || node === el) return null;
        while (node.parentNode !== el) node = node.parentNode;
        if (node.nodeType !== 1 || node.classList.contains('news-block-tools') || node.classList.contains('news-block-add')) return null;
        return node;
    }

    function newsChildrenHtml(el, fromNode, before) {
        const parts = [];
        let seen = false;
        Array.from(el.childNodes).forEach(node => {
            if (node === fromNode) seen = true;
            if (node.nodeType === 1 && (node.classList.contains('news-block-tools') || node.classList.contains('news-block-add'))) return;
            if (before ? !seen : seen) parts.push(node.nodeType === 1 ? node.outerHTML : node.textContent);
        });
        return parts.join('').trim();
    }

    function splitNewsBlockAt(field, index, ref) {
        const node = newsSplitElement(ref.el);
        if (!node || !node.previousElementSibling) {
            showToast(t('news_block.split_hint'), 'error');
            return;
        }
        const before = newsChildrenHtml(ref.el, node, true);
        const after = newsChildrenHtml(ref.el, node, false);
        if (!before || !after) {
            showToast(t('news_block.split_hint'), 'error');
            return;
        }
        newsStructuralChange(data => {
            const list = newsBlockList(field);
            const block = list[index];
            if (!block) return;
            block.content = before;
            list.splice(index + 1, 0, newsNewBlock('text', { content: after }));
        });
    }

    // ------------------------------------------------------------
    // The same tools for text sections of pages (field "sections.N.content"): split at the
    // caret, merge with the next section, block style. They save at once and reload in edit
    // mode, like adding and deleting page sections.
    // ------------------------------------------------------------
    function pageTextSectionRef(field) {
        const match = /^sections\.(\d+)\.content$/.exec(field?.dataset?.field || '');
        if (!match || field.hasAttribute('data-news-block')) return null;
        const page = field.dataset.page;
        const index = parseInt(match[1], 10);
        const section = EditorConfig.contentData[page]?.sections?.[index];
        return section && section.type === 'text' ? { page, index, section } : null;
    }

    function savePageSectionChange(page, mutate) {
        if (activeHtmlField && !activeHtmlField.classList.contains('news-editable')) {
            finishInlineHtmlEdit(activeHtmlField, activeHtmlField._originalHtml, false);
        }
        pushUndoState();
        mutate(EditorConfig.contentData[page].sections);
        EditorConfig.dirtyPages.add(page);
        updateUndoRedoButtons();
        saveStructuralChange(page, t('toast.saved'));
    }

    function splitPageSection(field) {
        const ref = pageTextSectionRef(field);
        const node = ref && newsSplitElement(field);
        const before = node && node.previousElementSibling ? newsChildrenHtml(field, node, true) : '';
        const after = before ? newsChildrenHtml(field, node, false) : '';
        if (!before || !after) {
            showToast(t('news_block.split_hint'), 'error');
            return;
        }
        finishInlineHtmlEdit(field, field._originalHtml, false);
        savePageSectionChange(ref.page, sections => {
            sections[ref.index].content = before;
            sections.splice(ref.index + 1, 0, Object.assign(createNewSection('text'), { title: '', content: after }));
        });
    }

    function pageBlockArea() {
        return document.querySelector(`.editable-content-area[data-content-page="${CSS.escape(pageBlockPage || '')}"]`);
    }

    // Image and gallery sections carry the hooks of post blocks (includes/block-renderers): same in-place editing
    function enablePageBlockEditing() {
        const area = pageBlockArea();
        if (area) enableNewsBlockEditing(area, true);
    }

    function disablePageBlockEditing() {
        const area = pageBlockArea();
        if (area) disableNewsBlockEditing(area);
    }

    // Buttons of a page section's bar that it shares with post blocks; index read from the wrapper (survives reorder)
    function pageSectionTools(wrapper, type, contentPage) {
        const indexOf = () => parseInt(wrapper.dataset.sectionIndex, 10);
        const sections = EditorConfig.contentData[contentPage]?.sections || [];
        const up = newsToolButton(Icons.arrowUp, t('news_block.move_up'), () => moveNewsBlockAt('sections', indexOf(), -1));
        const down = newsToolButton(Icons.arrowDown, t('news_block.move_down'), () => moveNewsBlockAt('sections', indexOf(), 1));
        if (indexOf() === 0) up.disabled = true;
        if (indexOf() >= sections.length - 1) down.disabled = true;
        const tools = [up, down];
        if (type === 'text') {
            const merge = newsToolButton(NEWS_MERGE_ICON, t('news_block.merge'), () => mergePageSectionWithNext(contentPage, indexOf()));
            if (sections[indexOf() + 1]?.type !== 'text') merge.disabled = true;
            tools.push(merge);
        }
        if (wrapper.querySelector('[data-news-block]')) {
            tools.push(...newsBlockTypeButtons({ field: 'sections', get index() { return indexOf(); }, type }));
        }
        return tools;
    }

    function setPageSectionStyle(field, value) {
        const ref = pageTextSectionRef(field);
        if (!ref) return;
        finishInlineHtmlEdit(field, field._originalHtml, false);
        savePageSectionChange(ref.page, sections => {
            if (value) sections[ref.index].style = value; else delete sections[ref.index].style;
        });
    }

    function mergePageSectionWithNext(page, index) {
        const sections = EditorConfig.contentData[page]?.sections;
        if (sections?.[index]?.type !== 'text' || sections[index + 1]?.type !== 'text') return;
        savePageSectionChange(page, list => {
            const next = list[index + 1];
            const title = next.title ? '<' + (next.titleTag || 'h2') + '>' + escHtml(next.title) + '</' + (next.titleTag || 'h2') + '>' : '';
            list[index].content = [list[index].content || '', title, next.content || ''].filter(Boolean).join('\n');
            list.splice(index + 1, 1);
        });
    }

    function mergeNewsBlockAt(field, index) {
        const list = newsBlockList(field);
        const next = list[index + 1];
        if (!next || next.type !== 'text' || list[index].type !== 'text') return;
        newsStructuralChange(() => {
            const current = list[index];
            const title = next.title ? '<h2>' + escHtml(next.title) + '</h2>' : '';
            current.content = [current.content || '', title, next.content || ''].filter(Boolean).join('\n');
            list.splice(index + 1, 1);
        });
    }

    function newsImageFromGalleryItem(image) {
        return { src: image.src || '', alt: image.alt || '', caption: image.caption || '', credit: image.credit || '', width: 'full', align: 'right', lightbox: true };
    }

    function newsGalleryItemFromImage(block) {
        return { src: block.src || '', alt: block.alt || '', caption: block.caption || '', credit: block.credit || '' };
    }

    // Number of image blocks directly following index (they join a new gallery)
    function newsFollowingImageCount(field, index) {
        const list = newsBlockList(field);
        let n = 0;
        while (list[index + 1 + n] && list[index + 1 + n].type === 'image') n++;
        return n;
    }

    // Image block → gallery block with that image plus the directly following image blocks
    function convertNewsImageToGallery(field, index) {
        newsStructuralChange(() => {
            const list = newsBlockList(field);
            const block = list[index];
            if (!block || block.type !== 'image') return;
            const n = newsFollowingImageCount(field, index);
            const images = [block].concat(list.slice(index + 1, index + 1 + n)).map(newsGalleryItemFromImage);
            list.splice(index, 1 + n, { id: block.id || newsNewBlock('gallery').id, type: 'gallery', layout: index === 0 ? 'column' : 'grid', images });
        });
    }

    // Gallery block with exactly one image → image block
    function convertNewsGalleryToImage(field, index) {
        newsStructuralChange(() => {
            const list = newsBlockList(field);
            const block = list[index];
            if (!block || block.type !== 'gallery' || !Array.isArray(block.images) || block.images.length !== 1) return;
            list.splice(index, 1, Object.assign({ id: block.id || newsNewBlock('image').id, type: 'image' }, newsImageFromGalleryItem(block.images[0])));
        });
    }

    function deleteNewsBlockAt(field, index) {
        showConfirmDialog(
            t('news_block.delete'),
            t('news_block.delete_confirm'),
            t('section.reload_hint'),
            () => newsStructuralChange(() => newsBlockList(field).splice(index, 1))
        );
    }

    // Block types a post accepts in the visual editor; youtube/audio stay out of the side column
    const NEWS_BLOCK_TYPES = {
        sections: ['text', 'image', 'gallery', 'youtube', 'audio', 'divider', 'spacer'],
        aside: ['text', 'image', 'gallery', 'divider', 'spacer']
    };
    const NEWS_GALLERY_GRID_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></g></svg>';
    const NEWS_GALLERY_FULL_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="18" height="7" rx="1"/></g></svg>';
    const NEWS_GALLERY_NATURAL_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="10" rx="1"/><rect x="14" y="3" width="7" height="6" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/></g></svg>';
    const NEWS_GALLERY_COLUMN_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h8M3 10h8M3 15h8M3 20h8"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></g></svg>';
    // Title image: full width (wide frame) / hidden uses Icons.eyeClosed / column uses the column icon
    const NEWS_IMAGE_FULL_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="1.5"/></g></svg>';
    // Split: two bars with a gap; merge: two bars pulled together
    const NEWS_SPLIT_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16M4 8h16M4 16h16M4 20h16"/><path d="M3 12h3M10.5 12h3M18 12h3"/></g></svg>';
    const NEWS_MERGE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h16M4 7h16M4 17h16M4 21h16"/><path d="m9 10 3 2 3-2M9 14l3-2 3 2"/></g></svg>';

    function newsToolButton(label, title, onClick, extraClass) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'overlay-btn nibbly-editor-control-btn' + (extraClass ? ' ' + extraClass : '');
        button.innerHTML = label;
        button.dataset.editorTooltip = title;
        button.setAttribute('aria-label', title);
        button.addEventListener('mousedown', event => event.preventDefault());
        button.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); onClick(); });
        return button;
    }

    // Round "+" below a block (or in an empty list), same look as the page sections' add button
    function newsAddButton(field, indexFn) {
        const container = document.createElement('div');
        container.className = 'section-add-buttons news-block-add';
        container.contentEditable = 'false';
        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'section-add-btn-toggle nibbly-editor-add-button';
        const addLabel = tFallback('add_block', 'Add block');
        addBtn.dataset.editorTooltip = addLabel;
        addBtn.setAttribute('aria-label', addLabel);
        addBtn.setAttribute('aria-expanded', 'false');
        addBtn.innerHTML = Icons.plus;
        addBtn.addEventListener('mousedown', event => event.preventDefault());
        addBtn.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            if (!EditorConfig.editMode) return;
            openSectionTypePicker(addBtn, indexFn(), null, {
                types: NEWS_BLOCK_TYPES[field] || NEWS_BLOCK_TYPES.sections,
                onSelect: type => addNewsBlockAt(field, indexFn(), type)
            });
        });
        container.appendChild(addBtn);
        return container;
    }

    function newsBlockTools(ref, count) {
        const tools = document.createElement('div');
        tools.className = 'news-block-tools editable-overlay-buttons';
        tools.contentEditable = 'false';
        // same order as the bar of a page section: edit, hide, arrows, merge and type tools, delete, drag
        if ((BlockTypes[ref.type]?.fields || []).length) {
            tools.append(newsToolButton(Icons.edit, t('edit'), () => editNewsBlockAt(ref.field, ref.index), 'overlay-btn-edit'));
        }
        const isHidden = !!newsBlockList(ref.field)[ref.index]?.hidden;
        tools.append(newsToolButton(isHidden ? Icons.eyeClosed : Icons.eyeOpen, t(isHidden ? 'show' : 'hide'), () => toggleNewsBlockHiddenAt(ref.field, ref.index), 'overlay-btn-hide'));
        const up = newsToolButton(Icons.arrowUp, t('news_block.move_up'), () => moveNewsBlockAt(ref.field, ref.index, -1));
        const down = newsToolButton(Icons.arrowDown, t('news_block.move_down'), () => moveNewsBlockAt(ref.field, ref.index, 1));
        if (ref.index === 0) up.disabled = true;
        if (ref.index >= count - 1) down.disabled = true;
        tools.append(up, down);
        if (ref.type === 'text') {
            const list = newsBlockList(ref.field);
            const merge = newsToolButton(NEWS_MERGE_ICON, t('news_block.merge'), () => mergeNewsBlockAt(ref.field, ref.index));
            const next = list[ref.index + 1];
            if (!next || next.type !== 'text') merge.disabled = true;
            tools.append(merge);
        }
        tools.append(...newsBlockTypeButtons(ref));
        tools.append(newsToolButton(Icons.delete, t('news_block.delete'), () => deleteNewsBlockAt(ref.field, ref.index), 'overlay-btn-delete'));
        tools.append(newsBlockDragHandle(ref));
        return tools;
    }

    // Drag handle of a post block. Draggable itself (the block may be contenteditable); the custom data
    // type keeps a stray drop from typing the value into a text block.
    function newsBlockDragHandle(ref) {
        const handle = document.createElement('span');
        handle.className = 'overlay-drag-handle nibbly-editor-drag-handle';
        handle.draggable = true;
        handle.dataset.editorTooltip = t('drag_reorder');
        handle.setAttribute('aria-label', t('drag_reorder'));
        handle.innerHTML = Icons.drag;
        handle.addEventListener('dragstart', event => startNewsBlockDrag(event, ref, handle));
        return handle;
    }

    function startNewsBlockDrag(event, ref, handle) {
        const postEl = ref.el.closest('[data-news-post]');
        if (!postEl) { event.preventDefault(); return; }
        event.stopPropagation();
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/x-nibbly-block', String(ref.index));
        beginDragAssist(event, ref.el, ref.type);
        ref.el.classList.add('dragging');

        const clearMarks = () => postEl.querySelectorAll('.news-drag-over-top, .news-drag-over-bottom')
            .forEach(el => el.classList.remove('news-drag-over-top', 'news-drag-over-bottom'));
        const dropTarget = e => {
            const area = e.target.closest?.('[data-news-blocks]');
            if (area && area.dataset.newsBlocks !== ref.field) return null; // sections and aside stay apart
            const refs = newsBlockRefs(postEl).filter(other => other.field === ref.field);
            const over = e.target.closest?.('[data-news-block]');
            let target = refs.find(other => other.el === over);
            if (!target) {
                // in a gap between blocks: the one closest in height
                let best = Infinity;
                refs.forEach(other => {
                    const box = other.el.getBoundingClientRect();
                    const distance = e.clientY < box.top ? box.top - e.clientY : Math.max(0, e.clientY - box.bottom);
                    if (distance < best) { best = distance; target = other; }
                });
            }
            if (!target) return null;
            const box = target.el.getBoundingClientRect();
            return { el: target.el, index: target.index, after: e.clientY > box.top + box.height / 2 };
        };
        const onOver = e => {
            const target = dropTarget(e);
            clearMarks();
            if (!target) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            target.el.classList.add(target.after ? 'news-drag-over-bottom' : 'news-drag-over-top');
        };
        const onDrop = e => {
            const target = dropTarget(e);
            clearMarks();
            if (!target) return;
            e.preventDefault();
            // indices come from the hooks: hidden blocks of visitors' lists may leave gaps
            let to = target.after ? target.index + 1 : target.index;
            if (to > ref.index) to--;
            if (to === ref.index) return;
            newsStructuralChange(() => {
                const list = newsBlockList(ref.field);
                const [moved] = list.splice(ref.index, 1);
                list.splice(to, 0, moved);
            });
        };
        const onEnd = () => {
            clearMarks();
            ref.el.classList.remove('dragging');
            postEl.removeEventListener('dragover', onOver);
            postEl.removeEventListener('drop', onDrop);
            handle.removeEventListener('dragend', onEnd);
        };
        postEl.addEventListener('dragover', onOver);
        postEl.addEventListener('drop', onDrop);
        handle.addEventListener('dragend', onEnd);
    }

    // Tools of image and gallery blocks, for the bars of post blocks and page sections
    function newsBlockTypeButtons(ref) {
        const buttons = [];
        if (ref.type === 'gallery') {
            // layout: column (text flows around) / grid / full width; the active one is filled
            const block = newsBlockList(ref.field)[ref.index] || {};
            const current = ['grid', 'full', 'natural'].includes(block.layout) ? block.layout : 'column';
            [['column', NEWS_GALLERY_COLUMN_ICON], ['grid', NEWS_GALLERY_GRID_ICON], ['natural', NEWS_GALLERY_NATURAL_ICON], ['full', NEWS_GALLERY_FULL_ICON]].forEach(([layout, icon]) => {
                const button = newsToolButton(icon, t('gallery.layout_' + layout),
                    () => newsStructuralChange(() => { const b = newsBlockList(ref.field)[ref.index]; if (b) b.layout = layout; }),
                    layout === current ? 'is-active' : '');
                button.setAttribute('aria-pressed', layout === current ? 'true' : 'false');
                buttons.push(button);
            });
            // a gallery down to one image can become a single image block again
            if (Array.isArray(block.images) && block.images.length === 1) {
                buttons.push(newsToolButton(Icons.image, t('news_block.to_image'), () => convertNewsGalleryToImage(ref.field, ref.index)));
            }
        }
        if (ref.type === 'image') {
            const n = newsFollowingImageCount(ref.field, ref.index);
            buttons.push(newsToolButton(NEWS_GALLERY_GRID_ICON, n ? t('news_block.to_gallery_merge') : t('news_block.to_gallery'), () => convertNewsImageToGallery(ref.field, ref.index)));
        }
        return buttons;
    }

    function enableNewsBlockEditing(postEl, sectionBars) {
        newsBlockRefs(postEl).forEach(ref => {
            ref.el.classList.add('news-block-editing');
            if (ref.type === 'text') {
                ref.el.contentEditable = 'true';
                ref.el.classList.add('news-editable');
                ref.el.addEventListener('input', markNewsPostDirty);
                attachNewsToolbar(ref.el);
            } else if (ref.type === 'image') {
                const img = ref.el.querySelector('img');
                if (img) {
                    img.style.cursor = 'pointer';
                    img.classList.add('news-editable');
                    img.title = t('editor.change_image') || 'Change image';
                    img._newsClickHandler = function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        const block = newsBlockList(ref.field)[ref.index] || {};
                        openImageManager(function(imagePath) {
                            img.src = imagePath;
                            img.removeAttribute('srcset');
                            syncAiLabel(img);
                            const link = img.closest('a');
                            if (link) link.setAttribute('href', imagePath);
                            if (newsBlockList(ref.field)[ref.index]) newsBlockList(ref.field)[ref.index].src = imagePath;
                            markNewsPostDirty();
                            closeImageManager();
                        }, null, block.src || imageElementPath(img));
                    };
                    img.addEventListener('click', img._newsClickHandler, true);
                }
                newsEnableCaption(ref.el.querySelector('figcaption'));
            } else if (ref.type === 'gallery') {
                enableNewsGalleryEditing(ref);
            }
            if (sectionBars) return; // on pages the section bar and its "+" do this
            ref.el.appendChild(newsBlockTools(ref, newsBlockList(ref.field).length));
            ref.el.appendChild(newsAddButton(ref.field, () => ref.index + 1));
        });
        if (sectionBars) return;
        postEl.querySelectorAll('[data-news-blocks]').forEach(container => {
            const field = container.dataset.newsBlocks;
            if (newsBlockList(field).length > 0) return;
            const add = document.createElement('div');
            add.className = 'news-blocks-add';
            add.contentEditable = 'false';
            add.appendChild(newsAddButton(field, () => newsBlockList(field).length));
            container.appendChild(add);
        });
    }

    function newsEnableCaption(caption) {
        if (!caption) return;
        caption.contentEditable = 'true';
        caption.classList.add('news-editable');
        caption.dataset.placeholder = t('news_block.caption_placeholder');
        caption.addEventListener('input', markNewsPostDirty);
    }

    function newsDisableCaption(caption) {
        if (!caption) return;
        caption.contentEditable = 'false';
        caption.classList.remove('news-editable');
        delete caption.dataset.placeholder;
        caption.removeEventListener('input', markNewsPostDirty);
    }

    // Gallery block (.block-gallery with one figure[data-gallery-item] per image): the image opens the
    // library, the caption is edited in place (a missing <figcaption> is created for the edit), a small
    // "x" removes the image and a last tile adds one; both save at once like other structural changes.
    function enableNewsGalleryEditing(ref) {
        const images = () => {
            const block = newsBlockList(ref.field)[ref.index];
            if (block && !Array.isArray(block.images)) block.images = [];
            return block ? block.images : null;
        };
        ref.el.querySelectorAll('figure[data-gallery-item]').forEach(figure => {
            const j = parseInt(figure.dataset.galleryItem, 10);
            const img = figure.querySelector('img');
            if (img) {
                img.style.cursor = 'pointer';
                img.classList.add('news-editable');
                img.title = t('editor.change_image') || 'Change image';
                img._newsClickHandler = function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const list = images();
                    openImageManager(function(imagePath) {
                        img.src = imagePath;
                        img.removeAttribute('srcset');
                        syncAiLabel(img);
                        const link = img.closest('a');
                        if (link) link.setAttribute('href', imagePath);
                        if (list && list[j]) list[j].src = imagePath;
                        markNewsPostDirty();
                        closeImageManager();
                    }, null, (list && list[j] && list[j].src) || imageElementPath(img));
                };
                img.addEventListener('click', img._newsClickHandler, true);
            }
            let caption = figure.querySelector('figcaption');
            if (!caption) {
                caption = document.createElement('figcaption');
                caption.dataset.newsTemp = '1';
                figure.appendChild(caption);
            }
            newsEnableCaption(caption);
            // round blue "+" at the bottom edge of the figure, like list items: inserts after this image
            const addAfter = document.createElement('button');
            addAfter.type = 'button';
            addAfter.className = 'list-item-add-btn news-gallery-add';
            addAfter.contentEditable = 'false';
            addAfter.innerHTML = Icons.plus;
            addAfter.dataset.editorTooltip = t('gallery.add_image');
            addAfter.setAttribute('aria-label', t('gallery.add_image'));
            addAfter.addEventListener('mousedown', event => event.preventDefault());
            addAfter.addEventListener('click', event => {
                event.preventDefault();
                event.stopPropagation();
                openImageManager(function(imagePath) {
                    closeImageManager();
                    newsStructuralChange(() => {
                        const list = images();
                        if (list) list.splice(j + 1, 0, { src: imagePath, alt: '', caption: '', credit: '' });
                    });
                }, null, null);
            });
            figure.appendChild(addAfter);
            // per-image tool bar (top right): order, extract as single image block, remove
            const count = (images() || []).length;
            const moveImage = direction => newsStructuralChange(() => {
                const list = images();
                const k = j + direction;
                if (!list || !list[j] || !list[k]) return;
                [list[j], list[k]] = [list[k], list[j]];
            });
            const back = newsToolButton(Icons.arrowUp, t('gallery.move_up'), () => moveImage(-1));
            const forward = newsToolButton(Icons.arrowDown, t('gallery.move_down'), () => moveImage(1));
            if (j === 0) back.disabled = true;
            if (j >= count - 1) forward.disabled = true;
            // the image becomes an image block right after the gallery; an emptied gallery goes away
            const extract = newsToolButton(Icons.extract, t('gallery.extract'), () => newsStructuralChange(() => {
                const blocks = newsBlockList(ref.field);
                const list = images();
                if (!list || !list[j]) return;
                const image = list.splice(j, 1)[0];
                blocks.splice(ref.index + 1, 0, newsNewBlock('image', newsImageFromGalleryItem(image)));
                if (!list.length) blocks.splice(ref.index, 1);
            }));
            const remove = newsToolButton(Icons.delete, t('gallery.remove_image'), () => {
                showConfirmDialog(t('gallery.remove_image'), t('gallery.remove_confirm'), t('section.reload_hint'), () => newsStructuralChange(() => {
                    const list = images();
                    if (list) list.splice(j, 1);
                }));
            }, 'overlay-btn-delete');
            // wrapper: the control buttons themselves are forced to position: relative (tooltip rule)
            const toolsWrap = document.createElement('div');
            toolsWrap.className = 'news-gallery-tools editable-overlay-buttons';
            toolsWrap.contentEditable = 'false';
            toolsWrap.append(back, forward, extract, remove);
            figure.appendChild(toolsWrap);
        });
        // an emptied gallery keeps one visible "+" so images can come back
        if (!ref.el.querySelector('figure[data-gallery-item]')) {
            const add = document.createElement('button');
            addEmpty(add);
        }
        function addEmpty(add) {
            add.type = 'button';
            add.className = 'list-item-add-btn news-gallery-add news-gallery-add--empty';
            add.contentEditable = 'false';
            add.innerHTML = Icons.plus;
            add.dataset.editorTooltip = t('gallery.add_image');
            add.setAttribute('aria-label', t('gallery.add_image'));
            add.addEventListener('mousedown', event => event.preventDefault());
            add.addEventListener('click', event => {
                event.preventDefault();
                event.stopPropagation();
                openImageManager(function(imagePath) {
                    closeImageManager();
                    newsStructuralChange(() => {
                        const list = images();
                        if (list) list.push({ src: imagePath, alt: '', caption: '', credit: '' });
                    });
                }, null, null);
            });
            ref.el.appendChild(add);
        }
    }

    function disableNewsGalleryEditing(ref) {
        ref.el.querySelectorAll('.news-gallery-tools, .news-gallery-add').forEach(node => node.remove());
        ref.el.querySelectorAll('figure[data-gallery-item]').forEach(figure => {
            const img = figure.querySelector('img');
            if (img && img._newsClickHandler) {
                img.style.cursor = '';
                img.classList.remove('news-editable');
                img.title = '';
                img.removeEventListener('click', img._newsClickHandler, true);
                delete img._newsClickHandler;
            }
            const caption = figure.querySelector('figcaption');
            if (caption && caption.dataset.newsTemp && !caption.textContent.trim()) caption.remove();
            else newsDisableCaption(caption);
        });
    }

    function disableNewsBlockEditing(postEl) {
        postEl.querySelectorAll('.news-block-tools, .news-blocks-add, .news-block-add').forEach(node => node.remove());
        newsBlockRefs(postEl).forEach(ref => {
            ref.el.classList.remove('news-block-editing');
            if (ref.type === 'text') {
                ref.el.contentEditable = 'false';
                ref.el.classList.remove('news-editable');
                ref.el.removeEventListener('input', markNewsPostDirty);
                detachNewsToolbar(ref.el);
            } else if (ref.type === 'image') {
                const img = ref.el.querySelector('img');
                if (img && img._newsClickHandler) {
                    img.style.cursor = '';
                    img.classList.remove('news-editable');
                    img.title = '';
                    img.removeEventListener('click', img._newsClickHandler, true);
                    delete img._newsClickHandler;
                }
                newsDisableCaption(ref.el.querySelector('figcaption'));
            } else if (ref.type === 'gallery') {
                disableNewsGalleryEditing(ref);
            }
        });
    }

    function enableNewsPostEditing() {
        const postEl = document.querySelector('[data-news-post]');
        if (!postEl) return;

        ['title', 'subtitle', 'content', 'author', 'excerpt'].forEach(name => {
            const el = newsPostField(postEl, name);
            if (!el) return;
            el.contentEditable = 'true';
            el.classList.add('news-editable');
            el.addEventListener('input', markNewsPostDirty);
            if (name === 'content') attachNewsToolbar(el);
        });

        const heroEl = newsPostField(postEl, 'image');
        if (heroEl) {
            heroEl.style.cursor = 'pointer';
            heroEl.classList.add('news-editable');
            heroEl.title = t('editor.change_image') || 'Change image';
            heroEl._newsClickHandler = function() {
                openImageManager(function(imagePath) {
                    heroEl.src = imagePath;
                    syncAiLabel(heroEl);
                    EditorConfig.newsPostData.image = imagePath;
                    markNewsPostDirty();
                    closeImageManager();
                }, null, EditorConfig.newsPostData.image || imageElementPath(heroEl));
            };
            heroEl.addEventListener('click', heroEl._newsClickHandler);
            // layout of the title image in the post: column (default) / full width / hidden (previews only)
            const heroFigure = heroEl.closest('figure[data-news-field="image"]');
            if (heroFigure) {
                const tools = document.createElement('div');
                tools.className = 'news-block-tools editable-overlay-buttons';
                tools.contentEditable = 'false';
                const current = ['full', 'hidden'].includes(EditorConfig.newsPostData.imageLayout) ? EditorConfig.newsPostData.imageLayout : 'column';
                [['column', NEWS_GALLERY_COLUMN_ICON], ['full', NEWS_IMAGE_FULL_ICON], ['hidden', Icons.eyeClosed]].forEach(([layout, icon]) => {
                    const button = newsToolButton(icon, t('post_image.layout_' + layout), () => newsStructuralChange(data => { data.imageLayout = layout; }), layout === current ? 'is-active' : '');
                    button.setAttribute('aria-pressed', layout === current ? 'true' : 'false');
                    tools.appendChild(button);
                });
                heroFigure.appendChild(tools);
            }
        }

        // Cover of the issue in the sidebar: click opens the media library, the lightbox link follows
        const coverEl = newsPostField(postEl, 'cover');
        if (coverEl) {
            coverEl.style.cursor = 'pointer';
            coverEl.classList.add('news-editable');
            coverEl.title = t('editor.change_image') || 'Change image';
            coverEl._newsClickHandler = function(event) {
                event.preventDefault();
                event.stopPropagation();
                const current = EditorConfig.newsPostData.cover && EditorConfig.newsPostData.cover.src ? EditorConfig.newsPostData.cover.src : imageElementPath(coverEl);
                openImageManager(function(imagePath) {
                    coverEl.src = imagePath;
                    syncAiLabel(coverEl);
                    const link = coverEl.closest('a');
                    if (link) link.setAttribute('href', imagePath);
                    const figure = coverEl.closest('figure');
                    if (figure) figure.classList.remove('news-post-page__cover--empty');
                    EditorConfig.newsPostData.cover = Object.assign({ alt: '', label: '' }, EditorConfig.newsPostData.cover || {}, { src: imagePath });
                    markNewsPostDirty();
                    closeImageManager();
                }, null, current);
            };
            coverEl.addEventListener('click', coverEl._newsClickHandler, true);
        }

        enableNewsBlockEditing(postEl);
    }

    function disableNewsPostEditing() {
        const postEl = document.querySelector('[data-news-post]');
        if (!postEl) return;

        ['title', 'subtitle', 'content', 'author', 'excerpt'].forEach(name => {
            const el = newsPostField(postEl, name);
            if (!el) return;
            el.contentEditable = 'false';
            el.classList.remove('news-editable');
            el.removeEventListener('input', markNewsPostDirty);
            detachNewsToolbar(el);
        });

        const heroEl = newsPostField(postEl, 'image');
        if (heroEl) {
            heroEl.style.cursor = '';
            heroEl.classList.remove('news-editable');
            heroEl.title = '';
            if (heroEl._newsClickHandler) {
                heroEl.removeEventListener('click', heroEl._newsClickHandler);
                delete heroEl._newsClickHandler;
            }
        }

        const coverEl = newsPostField(postEl, 'cover');
        if (coverEl) {
            coverEl.style.cursor = '';
            coverEl.classList.remove('news-editable');
            coverEl.title = '';
            if (coverEl._newsClickHandler) {
                coverEl.removeEventListener('click', coverEl._newsClickHandler, true);
                delete coverEl._newsClickHandler;
            }
        }

        disableNewsBlockEditing(postEl);
    }

    function markNewsPostDirty() {
        if (pageBlockPage) {
            const area = pageBlockArea();
            if (area) collectNewsBlockFields(area);
            EditorConfig.dirtyPages.add(pageBlockPage);
            updateUndoRedoButtons();
            return;
        }
        EditorConfig.dirtyPages.add('__news_post__');
        updateUndoRedoButtons();
    }

    // Floating toolbar (bold, italic, link, footnote, HTML) on an always-editable post text.
    // Unlike page fields there is no start/finish cycle: the toolbar follows focus and blur.
    function attachNewsToolbar(el) {
        if (el._newsToolbar) return;
        const onFocus = () => {
            if (activeHtmlField && activeHtmlField !== el && !activeHtmlField.classList.contains('news-editable')) {
                finishInlineHtmlEdit(activeHtmlField, activeHtmlField._originalHtml, false);
            }
            activeHtmlField = el;
            positionFloatingToolbar(el);
            const scrollHandler = () => positionFloatingToolbar(el);
            el._scrollHandler = scrollHandler;
            window.addEventListener('scroll', scrollHandler, true);
            window.addEventListener('resize', scrollHandler);
        };
        const onBlur = () => {
            setTimeout(() => {
                const active = document.activeElement;
                if (floatingToolbar && floatingToolbar.contains(active)) return;
                if (document.querySelector('.prompt-dialog-overlay, .editor-modal.active')) return;
                if (activeHtmlField === el) hideFloatingToolbar();
            }, 200);
        };
        const onPaste = (e) => {
            e.preventDefault();
            const html = e.clipboardData.getData('text/html');
            const text = e.clipboardData.getData('text/plain');
            if (html) document.execCommand('insertHTML', false, sanitizeHtmlString(html));
            else if (text) document.execCommand('insertText', false, text);
        };
        el.addEventListener('focus', onFocus);
        el.addEventListener('blur', onBlur);
        el.addEventListener('paste', onPaste);
        el._newsToolbar = { onFocus, onBlur, onPaste };
    }

    function detachNewsToolbar(el) {
        if (!el._newsToolbar) return;
        el.removeEventListener('focus', el._newsToolbar.onFocus);
        el.removeEventListener('blur', el._newsToolbar.onBlur);
        el.removeEventListener('paste', el._newsToolbar.onPaste);
        delete el._newsToolbar;
        if (activeHtmlField === el) hideFloatingToolbar();
    }

    // HTML source of a post text (block or legacy content field); saved with "Speichern"
    function openNewsHtmlEditor(el) {
        const currentValue = newsEditableHtml(el);
        createHtmlSourceDialog();
        document.getElementById('html-source-dialog-title').textContent = 'HTML';
        const textarea = document.getElementById('html-source-textarea');
        textarea.value = formatHtml(currentValue);
        _htmlSourceCallback = (newValue) => {
            if (newValue === null || newValue === currentValue) return;
            const tools = Array.from(el.querySelectorAll(':scope > .news-block-tools, :scope > .news-block-add'));
            el.innerHTML = sanitizeHtmlString(newValue);
            tools.forEach(node => el.appendChild(node));
            markNewsPostDirty();
        };
        const modal = document.getElementById('html-source-dialog-modal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => { textarea.focus(); textarea.setSelectionRange(0, 0); }, 100);
    }

    // ============================================================
    // GLOBALES API
    // ============================================================

    window.InlineEditor = {
        init: initEditor,
        openEditor: openEditor,
        closeModal: closeModal,
        saveSection: saveSection,
        closeGroupEditor: closeGroupEditor,
        saveGroupEditor: saveGroupEditor,
        // Add
        openAddModal: openAddModal,
        closeAddModal: closeAddModal,
        addSection: addSection,
        // Event-Funktionen
        openEventEditor: openEventEditor,
        closeEventModal: closeEventModal,
        saveEvent: saveEvent,
        deleteEvent: deleteEvent,
        toggleEventVisibility: toggleEventVisibility,
        // Dialog-Funktionen
        closeConfirmDialog: closeConfirmDialog,
        closePromptDialog: closePromptDialog,
        submitPromptDialog: submitPromptDialog,
        closeHtmlSourceDialog: closeHtmlSourceDialog,
        submitHtmlSourceDialog: submitHtmlSourceDialog,
        // News post editing
        enableNewsPostEditing: enableNewsPostEditing,
        disableNewsPostEditing: disableNewsPostEditing,
        // Undo-Funktionen
        openHistoryModal: openHistoryModal,
        closeHistoryModal: closeHistoryModal,
        restoreFromHistory: restoreFromHistory,
        clearHistory: clearHistory,
        // Edit-Mode
        isEditMode: function() { return EditorConfig.editMode === true; },
        enterEditMode: enterEditMode,
        exitEditMode: exitEditMode,
        saveAllChanges: saveAllChanges,
        undo: undo,
        redo: redo,
        // Image manager
        openImageManager: openImageManager,
        clearImageField: clearImageField,
        updateImagePreview: updateImagePreview,
        updateAudioPreview: updateAudioPreview,
        openImageManagerForEvent: openImageManagerForEvent,
        closeImageManager: closeImageManager,
        confirmImageSelection: confirmImageSelection,
        closeLightbox: closeLightbox,
        closeReplaceDialog: closeReplaceDialog,
        openEditorImageLightbox: openEditorImageLightbox,
        // Audio-Verwaltung
        openAudioManager: openAudioManager,
        closeAudioManager: closeAudioManager
    };

    // Bei DOM-Ready initialisieren
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEditor);
    } else {
        initEditor();
    }
})();
