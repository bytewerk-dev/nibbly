/**
 * Inline Editor - Nibbly CMS
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
                        window.location.href = '/admin/index.php?timeout=1';
                        return response;
                    }
                } catch(e) {}
            }
            return response;
        };
        window._nibblyFetchWrapped = true;
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
        isHtmlMode: false,
        contentData: {},
        loadedPages: [],
        currentEvent: null,
        eventsData: null,
        draggedElement: null,
        draggedIndex: null,
        draggedContentPage: null,
        // Language config (populated from meta tags during init)
        languages: {},
        defaultLang: 'en',
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
        edit: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>',
        delete: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>',
        drag: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11 18c0 1.1-.9 2-2 2s-2-.9-2-2 .9-2 2-2 2 .9 2 2zm-2-8c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0-6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm6 4c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>',
        audio: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>',
        video: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z"/></svg>',
        text: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.5 4v3h5v12h3V7h5V4h-13zm19 5h-9v3h3v7h3v-7h3V9z"/></svg>',
        plus: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>',
        eraser: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16.24 3.56l4.95 4.94c.78.79.78 2.05 0 2.84L12 20.53a4.008 4.008 0 0 1-5.66 0L2.81 17c-.78-.79-.78-2.05 0-2.84l10.6-10.6c.79-.78 2.05-.78 2.83 0M4.22 15.58l3.54 3.53c.78.79 2.04.79 2.83 0l3.53-3.53-4.95-4.95-4.95 4.95z"/></svg>',
        undo: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z"/></svg>',
        redo: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.4 10.6C16.55 8.99 14.15 8 11.5 8c-4.65 0-8.58 3.03-9.96 7.22L3.9 16c1.05-3.19 4.06-5.5 7.6-5.5 1.95 0 3.73.72 5.12 1.88L13 16h9V7l-3.6 3.6z"/></svg>',
        save: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
        history: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>',
        eyeOpen: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>',
        eyeClosed: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>',
        image: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>',
        card: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>',
        upload: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>',
        folder: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>',
        grid: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 8h4V4H4v4zm6 12h4v-4h-4v4zm-6 0h4v-4H4v4zm0-6h4v-4H4v4zm6 0h4v-4h-4v4zm6-10v4h4V4h-4zm-6 4h4V4h-4v4zm6 6h4v-4h-4v4zm0 6h4v-4h-4v4z"/></svg>',
        list: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>',
        eye: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>',
        replace: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>',
        check: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
        heading: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M5 4v3h5.5v12h3V7H19V4H5z"/></svg>',
        quote: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z"/></svg>',
        spacer: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 18H3v2h18v-2zM21 4H3v2h18V4zm-9 8.5l3.5 3.5-1.41 1.41L12 15.33l-2.09 2.08L8.5 16l3.5-3.5zM12 7.5L8.5 11l1.41 1.41L12 10.33l2.09 2.08L15.5 11 12 7.5z"/></svg>',
        // Offizielle Brand Icons
        soundcloud: '<svg viewBox="0 0 24 24"><defs><style>.cls-1{fill:currentColor;stroke:currentColor;stroke-miterlimit:10;stroke-width:1.91px;}</style></defs><path class="cls-1" d="M22.5,13.93a2.87,2.87,0,0,1-2.86,2.87H13V7.47a4.82,4.82,0,0,1,1.44-.22,4.07,4.07,0,0,1,4.29,3.82h1A2.86,2.86,0,0,1,22.5,13.93Z"/><line class="cls-1" x1="10.09" y1="8.2" x2="10.09" y2="17.75"/><line class="cls-1" x1="7.23" y1="9.16" x2="7.23" y2="17.75"/><line class="cls-1" x1="4.36" y1="9.16" x2="4.36" y2="17.75"/><line class="cls-1" x1="1.5" y1="11.07" x2="1.5" y2="16.8"/></svg>',
        youtube: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>'
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
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-small">
                <div class="editor-modal-header">
                    <h3 id="confirm-dialog-title">Confirm</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeConfirmDialog(false)">&times;</button>
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

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', () => closeConfirmDialog(false));

        // Resizable Modal
        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    function showConfirmDialog(title, message, hint, callback, buttonText = t('delete')) {
        createConfirmDialog();
        confirmCallback = callback;

        document.getElementById('confirm-dialog-title').textContent = title;
        document.getElementById('confirm-dialog-message').textContent = message;
        document.getElementById('confirm-dialog-hint').textContent = hint || '';
        document.getElementById('confirm-dialog-hint').style.display = hint ? 'block' : 'none';
        document.getElementById('confirm-dialog-action-btn').textContent = buttonText;

        document.getElementById('confirm-dialog-modal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeConfirmDialog(confirmed) {
        const modal = document.getElementById('confirm-dialog-modal');
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';

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
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-small">
                <div class="editor-modal-header">
                    <h3 id="prompt-dialog-title">Eingabe</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closePromptDialog(null)">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div class="prompt-dialog-content">
                        <label class="prompt-dialog-label" id="prompt-dialog-label"></label>
                        <input type="text" class="prompt-dialog-input" id="prompt-dialog-input">
                    </div>
                </div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closePromptDialog(null)">Cancel</button>
                    <button type="button" class="editor-btn editor-btn-primary" onclick="InlineEditor.submitPromptDialog()">OK</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

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

        document.getElementById('prompt-dialog-modal').classList.add('active');
        document.body.style.overflow = 'hidden';

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

        // Alle Content-Daten laden
        const loadPromises = Array.from(contentPages).map(page => loadContent(page));
        loadPromises.push(loadEvents());

        Promise.all(loadPromises).then(() => {
            createEditorUI();
            createEventEditorUI();
            createAddSectionUI();
            attachEditHandlers();
            attachEventEditHandlers();
            attachFooterEditHandlers();
            attachFieldEditHandlers();
            attachLinkEditHandlers();
            attachImageEditHandlers();
            attachListEditHandlers();
            attachComparisonRowHandlers();
            attachComparisonCellToggles();
            showAdminBar();

            // Auto-restore edit mode after structural-change reload
            if (sessionStorage.getItem('site-edit-mode') === 'true') {
                sessionStorage.removeItem('site-edit-mode');
                enterEditMode();
            }
        });
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
                EditorConfig.loadedPages.push(page);
            }
        } catch (error) {
            console.error('Error loading ' + page + ':', error);
        }
    }

    // ============================================================
    // ADMIN BAR
    // ============================================================

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
        const logoHtml = showBranding
            ? `<img src="${brandLogo}" alt="${brandName}" width="24" height="24" class="admin-bar-logo-icon">`
            : '';
        bar.innerHTML = `
            <div class="admin-bar-inner">
                <div class="admin-bar-left">
                    ${logoHtml}
                    <a href="/admin/dashboard" class="admin-bar-link"><svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg> ${t('dashboard')}</a>
                </div>
                <div class="admin-bar-center">
                    <span class="admin-bar-info" id="admin-bar-info"></span>
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
                    </div>
                    <div class="admin-bar-actions">
                        <button type="button" class="admin-bar-btn admin-bar-btn-edit" id="admin-btn-edit">
                            ${t('visual_editor')}
                        </button>
                        <a href="${EditorConfig.isNewsPost ? '/admin/dashboard.php?tab=news&post=' + (EditorConfig.newsPostId || '') : '/admin/dashboard?page=' + (EditorConfig.currentPage || '')}" class="admin-bar-btn admin-bar-btn-content-editor" id="admin-btn-content-editor">
                            ${t('content_editor')}
                        </a>
                    </div>
                </div>
                <div class="admin-bar-right">
                    <a href="/admin/?logout=1" class="admin-bar-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg> ${t('logout')}</a>
                </div>
            </div>
        `;
        document.body.insertBefore(bar, document.body.firstChild);
        document.body.classList.add('has-admin-bar');

        // Button Event Listeners
        document.getElementById('admin-btn-edit').addEventListener('click', enterEditMode);
        document.getElementById('admin-btn-save').addEventListener('click', saveAllChanges);
        document.getElementById('admin-btn-cancel').addEventListener('click', () => exitEditMode(false));
        document.getElementById('admin-btn-undo').addEventListener('click', undo);
        document.getElementById('admin-btn-redo').addEventListener('click', redo);
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
        const saveBtn = document.getElementById('admin-btn-save');
        if (undoBtn) undoBtn.disabled = EditorConfig.undoStack.length === 0;
        if (redoBtn) redoBtn.disabled = EditorConfig.redoStack.length === 0;
        if (saveBtn) {
            if (EditorConfig.dirtyPages.size > 0) {
                saveBtn.classList.add('has-changes');
            } else {
                saveBtn.classList.remove('has-changes');
            }
        }
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
        if (!href || href === '#' || href.startsWith('#') || href.startsWith('javascript:')) return;

        // Don't block admin bar buttons (they have their own logic)
        if (link.closest('.admin-bar')) return;
        // Don't block editor modal links
        if (link.closest('.editor-modal')) return;
        // Don't block footer editable fields (handled by footer editor)
        if (link.classList.contains('editable-footer-field')) return;
        // Don't block editable links (handled by link editor)
        if (link.hasAttribute('data-editable-link')) return;

        e.preventDefault();
        e.stopPropagation();

        const hasDirty = EditorConfig.dirtyPages.size > 0;
        const message = hasDirty
            ? t('nav.unsaved_changes')
            : t('nav.edit_mode_active');

        if (confirm(message)) {
            window.removeEventListener('beforeunload', beforeUnloadGuard);
            document.removeEventListener('click', navClickGuard, true);
            window.location.href = link.href;
        }
    }

    function enterEditMode() {
        EditorConfig.editMode = true;
        EditorConfig.originalPageData = JSON.parse(JSON.stringify(EditorConfig.contentData));
        EditorConfig.dirtyPages = new Set();
        EditorConfig.undoStack = [];
        EditorConfig.redoStack = [];

        document.body.classList.add('edit-mode-active');
        updateAdminBarMode(true);
        updateUndoRedoButtons();

        // Enable news post inline editing
        if (EditorConfig.isNewsPost) {
            enableNewsPostEditing();
        }

        // Register keyboard shortcuts
        document.addEventListener('keydown', handleEditModeKeyboard);

        // Guard against accidental navigation with unsaved changes
        window.addEventListener('beforeunload', beforeUnloadGuard);
        document.addEventListener('click', navClickGuard, true);
    }

    function exitEditMode(save) {
        if (save) {
            // saveAllChanges handles the exit after successful save
            saveAllChanges();
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
        document.body.classList.remove('edit-mode-active');
        updateAdminBarMode(false);

        // Disable news post editing
        if (EditorConfig.isNewsPost) {
            disableNewsPostEditing();
        }

        // If there were unsaved changes, reload to restore original content
        if (Object.keys(EditorConfig.originalPageData).length > 0 || (EditorConfig.isNewsPost && EditorConfig.dirtyPages.size > 0)) {
            // Restore contentData to original
            EditorConfig.contentData = JSON.parse(JSON.stringify(EditorConfig.originalPageData));
            // Reload to restore DOM to server state
            location.reload();
        }
    }

    async function saveAllChanges() {
        // For news posts, check if the news post data is dirty
        const hasNewsChanges = EditorConfig.isNewsPost && EditorConfig.dirtyPages.has('__news_post__');
        const hasPageChanges = Array.from(EditorConfig.dirtyPages).some(p => p !== '__news_post__');

        if (EditorConfig.dirtyPages.size === 0) {
            showToast(t('toast.no_changes'), 'info');
            exitEditModeClean();
            return;
        }

        const saveBtn = document.getElementById('admin-btn-save');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = t('saving');
        }

        try {
            let allSuccess = true;

            // Save news post if dirty
            if (hasNewsChanges && EditorConfig.newsPostData) {
                const postEl = document.querySelector('[data-news-post]');
                if (postEl) {
                    const titleEl = postEl.querySelector('.news-post-page__title');
                    const contentEl = postEl.querySelector('.news-post-page__content');
                    const authorEl = postEl.querySelector('.news-post-page__author');
                    if (titleEl) EditorConfig.newsPostData.title = titleEl.textContent.trim();
                    if (contentEl) EditorConfig.newsPostData.content = contentEl.innerHTML.trim();
                    if (authorEl) {
                        // Strip "by " prefix if present
                        let authorText = authorEl.textContent.trim();
                        authorText = authorText.replace(/^by\s+/i, '');
                        EditorConfig.newsPostData.author = authorText;
                    }
                }

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

                    if (!result.success) {
                        showToast(t('toast.error_saving', { page, message: result.message || 'Unknown' }), 'error');
                        allSuccess = false;
                        break;
                    }
                }
            }

            if (allSuccess) {
                showToast(t('toast.saved'), 'success');
                exitEditModeClean();
                // Reload to ensure server-rendered HTML matches saved data
                setTimeout(() => location.reload(), 500);
            }
        } catch (error) {
            console.error('Save all error:', error);
            showToast(t('toast.error_saving_short'), 'error');
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = Icons.save + ' ' + t('save');
            }
        }
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
        document.body.classList.remove('edit-mode-active');
        updateAdminBarMode(false);
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
            executeSectionReorder(entry.from, entry.to, entry.contentPage);
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
            executeSectionReorder(entry.to, entry.from, entry.contentPage);
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

            if (value.text) link.textContent = value.text;
            if (value.href) link.setAttribute('href', value.href);
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
        if (!EditorConfig.editMode) return;

        // Don't intercept when in input/textarea/contenteditable
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.target.isContentEditable) return;

        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const ctrlKey = isMac ? e.metaKey : e.ctrlKey;

        if (ctrlKey && !e.shiftKey && e.key === 'z') {
            e.preventDefault();
            undo();
        } else if (ctrlKey && e.shiftKey && (e.key === 'z' || e.key === 'Z')) {
            e.preventDefault();
            redo();
        } else if (ctrlKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            saveAllChanges();
        }
    }

    // ============================================================
    // EDITOR UI - HAUPTMODAL
    // ============================================================

    /**
     * Renders an editor field HTML snippet based on field definition.
     * Each field gets a wrapper with id="editor-field-{key}" and the input gets id="editor-input-{key}".
     */
    function renderEditorField(field) {
        const fid = `editor-field-${field.key}`;
        const iid = `editor-input-${field.key}`;

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
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <div class="editor-toolbar">
                        <button type="button" onclick="InlineEditor.execCommand('bold')" title="${t('bold')}"><b>B</b></button>
                        <button type="button" onclick="InlineEditor.execCommand('italic')" title="${t('italic')}"><i>I</i></button>
                        <button type="button" onclick="InlineEditor.insertLink()" title="${t('insert_link')}">🔗</button>
                        <button type="button" onclick="InlineEditor.cleanHtml()" title="${t('clean_formatting')}" class="editor-toolbar-eraser">${Icons.eraser}</button>
                        <span class="editor-toolbar-separator"></span>
                        <label class="editor-toggle">
                            <input type="checkbox" id="editor-html-toggle" onchange="InlineEditor.toggleHtmlMode()">
                            <span>HTML</span>
                        </label>
                    </div>
                    <div id="editor-wysiwyg" class="editor-wysiwyg" contenteditable="true"></div>
                    <textarea id="editor-html" class="editor-html" style="display:none;"></textarea>
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
                        <input type="text" id="${iid}" placeholder="Path to image..." readonly>
                        <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.openImageManager()">
                            ${Icons.folder} ${t('image_manager')}
                        </button>
                        <button type="button" class="editor-btn editor-btn-icon" id="editor-image-preview-btn" onclick="InlineEditor.openEditorImageLightbox()" title="${t('image_preview')}">
                            ${Icons.eye}
                        </button>
                    </div>
                    <div id="editor-image-preview" class="editor-image-preview"></div>
                </div>`;

            case 'audio':
                return `<div class="editor-field" id="${fid}" style="display:none;">
                    <label>${field.label}</label>
                    <input type="text" id="${iid}" placeholder="Path to audio file..." readonly>
                    <div class="editor-audio-buttons">
                        <label class="editor-btn editor-btn-primary editor-btn-inline">
                            <input type="file" id="editor-audio-upload-input" accept=".mp3,.wav,.ogg,.m4a,.aac,.flac,audio/*" style="display:none;">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
                            ${t('image.upload')}
                        </label>
                        <button type="button" class="editor-btn editor-btn-secondary editor-btn-inline" onclick="InlineEditor.openAudioManager()">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
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

    function createEditorUI() {
        // Build all field HTML from all block types
        const allFieldsHtml = {};
        const renderedKeys = new Set();

        for (const [type, def] of Object.entries(BlockTypes)) {
            for (const field of (def.fields || [])) {
                if (!renderedKeys.has(field.key)) {
                    renderedKeys.add(field.key);
                    allFieldsHtml[field.key] = renderEditorField(field);
                }
            }
        }

        const fieldsHtml = Object.values(allFieldsHtml).join('\n');

        const modal = document.createElement('div');
        modal.id = 'editor-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content">
                <div class="editor-modal-header">
                    <h3 id="editor-modal-title">${t('edit')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeModal()">&times;</button>
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

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', closeModal);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
        });

        // Attach event listeners for special field types
        const youtubeInput = document.getElementById('editor-input-videoId');
        if (youtubeInput) youtubeInput.addEventListener('input', updateYoutubePreview);

        const scInput = document.getElementById('editor-input-trackId');
        if (scInput) scInput.addEventListener('input', updateSoundcloudPreview);

        const audioUpload = document.getElementById('editor-audio-upload-input');
        if (audioUpload) audioUpload.addEventListener('change', handleEditorAudioUpload);

        // Resizable Modal
        ModalResize.init(modal.querySelector('.editor-modal-content'));
    }

    // ============================================================
    // "ADD SECTION" MODAL
    // ============================================================

    function getBlockIcon(type) {
        const iconMap = {
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
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-small">
                <div class="editor-modal-header">
                    <h3>${t('add_block')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeAddModal()">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div class="add-section-search">
                        <input type="text" id="add-section-search-input" placeholder="${t('search_blocks')}" autocomplete="off">
                    </div>
                    ${categoriesHtml}
                </div>
            </div>
        `;
        document.body.appendChild(modal);

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

    function attachEditHandlers() {
        const sections = document.querySelectorAll('.editable-section');

        sections.forEach((wrapper) => {
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
            overlay.className = 'editable-overlay editable-overlay-buttons';
            // Check if section is hidden
            const sectionIdx = parseInt(wrapper.dataset.sectionIndex, 10);
            const pageData = EditorConfig.contentData[contentPage];
            const sectionData = pageData && pageData.sections ? pageData.sections[sectionIdx] : null;
            const isHidden = sectionData && sectionData.hidden === true;
            const hideIcon = isHidden ? Icons.eyeClosed : Icons.eyeOpen;
            const hideTitle = isHidden ? t('show') : t('hide');

            overlay.innerHTML = `
                <button type="button" class="overlay-btn overlay-btn-edit" title="${t('edit')}" data-action="edit">${Icons.edit}</button>
                <button type="button" class="overlay-btn overlay-btn-hide" title="${hideTitle}" data-action="hide">${hideIcon}</button>
                <button type="button" class="overlay-btn overlay-btn-delete" title="${t('delete')}" data-action="delete">${Icons.delete}</button>
                <span class="overlay-drag-handle" title="${t('drag_reorder')}">${Icons.drag}</span>
            `;
            wrapper.appendChild(overlay);

            // Apply hidden state
            if (isHidden) {
                wrapper.dataset.hidden = 'true';
            }

            // Drag & Drop aktivieren
            wrapper.setAttribute('draggable', 'true');
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
                dragHandle.addEventListener('mousedown', () => {
                    wrapper.setAttribute('draggable', 'true');
                });
            }

            // Click handler for buttons — read index dynamically (survives reorder)
            overlay.addEventListener('click', (e) => {
                const btn = e.target.closest('.overlay-btn');
                if (!btn) return;

                e.preventDefault();
                e.stopPropagation();

                const currentIndex = parseInt(wrapper.dataset.sectionIndex, 10);
                const action = btn.dataset.action;
                if (action === 'edit') {
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

            // Click on section itself opens editor
            wrapper.addEventListener('click', (e) => {
                if (!EditorConfig.editMode) return;
                if (e.target.closest('.editable-overlay')) return;
                if (e.target.closest('a')) return;
                if (e.target.closest('.section-add-buttons')) return;

                e.preventDefault();
                e.stopPropagation();

                const currentIndex = parseInt(wrapper.dataset.sectionIndex, 10);
                const pageData = EditorConfig.contentData[contentPage];
                if (pageData && pageData.sections && pageData.sections[currentIndex]) {
                    openEditor(currentIndex, pageData.sections[currentIndex], contentPage);
                }
            });

            // "Add" buttons below the section
            createAddButtonsForSection(wrapper, index, contentPage);
        });
    }

    function createAddButtonsForSection(wrapper, index, contentPage) {
        // Check if already present
        if (wrapper.querySelector('.section-add-buttons')) return;

        const addButtons = document.createElement('div');
        addButtons.className = 'section-add-buttons';
        addButtons.innerHTML = `
            <button type="button" class="section-add-btn" data-type="soundcloud" title="${t('add_soundcloud')}">
                ${Icons.soundcloud}
            </button>
            <button type="button" class="section-add-btn" data-type="audio" title="${t('add_audio')}">
                ${Icons.audio}
            </button>
            <button type="button" class="section-add-btn" data-type="youtube" title="${t('add_youtube')}">
                ${Icons.youtube}
            </button>
            <button type="button" class="section-add-btn" data-type="text" title="${t('add_text')}">
                ${Icons.text}
            </button>
            <button type="button" class="section-add-btn" data-type="card" title="${t('add_card')}">
                ${Icons.card}
            </button>
        `;

        addButtons.addEventListener('click', (e) => {
            const btn = e.target.closest('.section-add-btn');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            // Read index dynamically (survives reorder)
            const currentIndex = parseInt(wrapper.dataset.sectionIndex, 10);
            const type = btn.dataset.type;
            addSectionAfter(currentIndex, type, contentPage);
        });

        wrapper.appendChild(addButtons);
    }

    // ============================================================
    // DRAG & DROP
    // ============================================================

    function handleDragStart(e) {
        if (!EditorConfig.editMode) { e.preventDefault(); return; }
        const wrapper = e.target.closest('.editable-section');
        if (!wrapper) return;

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

        // For better visual feedback
        if (e.dataTransfer.setDragImage && wrapper) {
            e.dataTransfer.setDragImage(wrapper, 20, 20);
        }
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
            document.querySelectorAll(`.editable-section[data-content-page="${contentPage}"]`).forEach((el, i) => {
                el.dataset.sectionIndex = i;
            });
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
        addAfterIndex = null;
        addContentPage = null;
    }

    function addSectionAfter(afterIndex, type, contentPage) {
        const pageData = EditorConfig.contentData[contentPage];
        if (!pageData) return;

        // Neue Section erstellen
        const newSection = createNewSection(type, pageData.sections.length);

        // Insert at correct position
        pageData.sections.splice(afterIndex + 1, 0, newSection);

        // Save and open editor
        saveAndOpenEditor(afterIndex + 1, contentPage);
    }

    function addSection(type) {
        if (addAfterIndex === null || !addContentPage) return;

        const pageData = EditorConfig.contentData[addContentPage];
        if (!pageData) return;

        const newSection = createNewSection(type, pageData.sections.length);
        pageData.sections.splice(addAfterIndex + 1, 0, newSection);

        closeAddModal();
        saveAndOpenEditor(addAfterIndex + 1, addContentPage);
    }

    function createNewSection(type, count) {
        const id = `new_${type}_${Date.now()}`;
        const def = BlockTypes[type];

        if (def && def.defaults) {
            return { id, type, ...JSON.parse(JSON.stringify(def.defaults)) };
        }
        return { id, type };
    }

    function saveAndOpenEditor(index, contentPage) {
        pushUndoState();
        EditorConfig.dirtyPages.add(contentPage);
        updateUndoRedoButtons();
        saveStructuralChange(contentPage, 'Section added');
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
        btnEl.title = nowHidden ? t('show') : t('hide');

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
    function populateEditorField(field, section) {
        const fid = `editor-field-${field.key}`;
        const iid = `editor-input-${field.key}`;
        const fieldEl = document.getElementById(fid);
        if (!fieldEl) return;

        fieldEl.style.display = 'block';
        const value = section[field.key] ?? '';

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
                const htmlToggle = document.getElementById('editor-html-toggle');
                if (htmlToggle) htmlToggle.checked = false;
                const wysiwyg = document.getElementById('editor-wysiwyg');
                const htmlArea = document.getElementById('editor-html');
                if (wysiwyg) { wysiwyg.style.display = 'block'; wysiwyg.innerHTML = value; }
                if (htmlArea) { htmlArea.style.display = 'none'; htmlArea.value = value; }
                break;
            }

            case 'select':
                document.getElementById(iid).value = value;
                break;

            case 'checkbox':
                document.getElementById(iid).checked = !!value;
                break;

            case 'image':
                document.getElementById(iid).value = value;
                updateImagePreview();
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
        const iid = `editor-input-${field.key}`;

        switch (field.type) {
            case 'input':
            case 'textarea':
            case 'number':
            case 'url':
            case 'image':
                return (document.getElementById(iid)?.value || '').trim();

            case 'wysiwyg':
                if (EditorConfig.isHtmlMode) {
                    return document.getElementById('editor-html')?.value || '';
                }
                return document.getElementById('editor-wysiwyg')?.innerHTML || '';

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

    function openEditor(index, section, contentPage) {
        EditorConfig.currentSectionIndex = index;
        EditorConfig.currentSection = { ...section };
        EditorConfig.currentContentPage = contentPage;
        EditorConfig.isHtmlMode = false;

        const modal = document.getElementById('editor-modal');
        const modalTitle = document.getElementById('editor-modal-title');

        // Hide all fields
        modal.querySelectorAll('.editor-field').forEach(f => f.style.display = 'none');

        // Resolve type (legacy alias)
        const type = section.type === 'project' ? 'card' : section.type;
        const def = BlockTypes[type];

        if (!def) {
            modalTitle.textContent = t('edit') + ' ' + type;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            return;
        }

        modalTitle.textContent = t('edit') + ' ' + def.label;

        // Show and populate fields from registry
        for (const field of (def.fields || [])) {
            populateEditorField(field, section);

            // Special preview triggers
            if (field.key === 'videoId') updateYoutubePreview();
            if (field.key === 'trackId') updateSoundcloudPreview();
        }

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        const modal = document.getElementById('editor-modal');
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';
        EditorConfig.currentSection = null;
        EditorConfig.currentSectionIndex = null;
        EditorConfig.currentContentPage = null;
    }

    // ============================================================
    // WYSIWYG FUNKTIONEN
    // ============================================================

    function execCommand(command) {
        document.execCommand(command, false, null);
        document.getElementById('editor-wysiwyg').focus();
    }

    /**
     * Bereinigt HTML-Inhalt von Formatierungen
     * Removes: style/class attributes, DIV/SPAN tags (keeps content)
     * Keeps: P, H1-H6, A, B, STRONG, I, EM, BR, UL, OL, LI
     */
    function cleanHtml() {
        const wysiwyg = document.getElementById('editor-wysiwyg');
        let html = wysiwyg.innerHTML;

        // Create temporary DOM element for processing
        const temp = document.createElement('div');
        temp.innerHTML = html;

        // Recursive function to sanitize
        function cleanNode(node) {
            // Iterate child nodes (backwards, since we may remove nodes)
            const children = Array.from(node.childNodes);
            children.forEach(child => {
                if (child.nodeType === Node.ELEMENT_NODE) {
                    const tagName = child.tagName.toLowerCase();

                    // Erlaubte Tags
                    const allowedTags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'b', 'strong', 'i', 'em', 'br', 'ul', 'ol', 'li', 'img'];

                    if (allowedTags.includes(tagName)) {
                        // Allowed tag: remove all attributes except href on links and src/alt on images
                        const attrs = Array.from(child.attributes);
                        attrs.forEach(attr => {
                            if (tagName === 'a' && attr.name === 'href') {
                                // href bei Links behalten
                            } else if (tagName === 'img' && (attr.name === 'src' || attr.name === 'alt')) {
                                // Keep src and alt on images
                            } else {
                                child.removeAttribute(attr.name);
                            }
                        });

                        // Recursively sanitize children
                        cleanNode(child);
                    } else {
                        // Disallowed tag (DIV, SPAN, etc.): replace with content
                        // First sanitize children
                        cleanNode(child);

                        // Then replace the tag with its children
                        while (child.firstChild) {
                            node.insertBefore(child.firstChild, child);
                        }
                        node.removeChild(child);
                    }
                }
            });
        }

        cleanNode(temp);

        // Write result back
        wysiwyg.innerHTML = temp.innerHTML;
        wysiwyg.focus();

        showToast(t('toast.formatting_cleaned'), 'success');
    }

    function insertLink() {
        const wysiwyg = document.getElementById('editor-wysiwyg');

        // Save current selection before dialog opens
        const selection = window.getSelection();
        let savedRange = null;

        if (selection.rangeCount > 0) {
            savedRange = selection.getRangeAt(0).cloneRange();
        }

        // Check if text is selected
        const selectedText = selection.toString();
        if (!selectedText) {
            showToast(t('toast.select_text_first'), 'error');
            return;
        }

        showPromptDialog(t('insert_link'), t('insert_link'), 'https://', (url) => {
            if (url && savedRange) {
                // Set focus on editor
                wysiwyg.focus();

                // Selektion wiederherstellen
                const newSelection = window.getSelection();
                newSelection.removeAllRanges();
                newSelection.addRange(savedRange);

                // Link erstellen
                document.execCommand('createLink', false, url);
            }
        });
    }

    function toggleHtmlMode() {
        const wysiwyg = document.getElementById('editor-wysiwyg');
        const html = document.getElementById('editor-html');
        const toggle = document.getElementById('editor-html-toggle');

        EditorConfig.isHtmlMode = toggle.checked;

        if (EditorConfig.isHtmlMode) {
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

    // ============================================================
    // MEDIA PREVIEWS
    // ============================================================

    function updateYoutubePreview() {
        const input = document.getElementById('editor-input-videoId');
        const preview = document.getElementById('editor-youtube-preview');
        const videoId = input.value.trim();

        if (videoId) {
            preview.innerHTML = `
                <iframe src="https://www.youtube-nocookie.com/embed/${videoId}"
                    frameborder="0" allowfullscreen style="width:100%;height:200px;"></iframe>
            `;
        } else {
            preview.innerHTML = '<p class="preview-placeholder">Enter video ID for preview</p>';
        }
    }

    function updateSoundcloudPreview() {
        const input = document.getElementById('editor-input-trackId');
        const preview = document.getElementById('editor-soundcloud-preview');
        const trackId = input.value.trim();

        if (trackId) {
            preview.innerHTML = `
                <iframe width="100%" height="120" scrolling="no" frameborder="no"
                    src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/${trackId}&color=%233b82f6&inverse=true&auto_play=false&show_user=true">
                </iframe>
            `;
        } else {
            preview.innerHTML = '<p class="preview-placeholder">Enter track ID for preview</p>';
        }
    }

    function updateAudioPreview() {
        const input = document.getElementById('editor-input-src');
        const preview = document.getElementById('editor-audio-preview');
        const src = input.value.trim();

        if (src) {
            preview.innerHTML = `
                <audio controls preload="metadata" style="width:100%;">
                    <source src="${src}" type="audio/mpeg">
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
                document.getElementById('editor-input-src').value = result.data.path;
                updateAudioPreview();

                // Generate title from filename (without extension, hyphens/underscores → spaces)
                const titleInput = document.getElementById('editor-input-title');
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

        updateUndoRedoButtons();
        showToast(t('toast.change_queued'), 'success');
        closeModal();
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

        // Build language tabs
        const tabsHtml = langs.map(([code, name], i) => {
            const isDefault = code === defaultLang;
            const active = isDefault ? ' active' : '';
            return `<button type="button" class="event-lang-tab${active}" data-lang="${code}">${name}${isDefault ? ' ★' : ''}</button>`;
        }).join('');

        // Build language panels (translatable fields)
        const panelsHtml = langs.map(([code, name]) => {
            const isDefault = code === defaultLang;
            const display = isDefault ? '' : ' style="display:none;"';
            const reqMark = isDefault ? ' *' : '';
            return `
                <div class="event-lang-panel" data-lang="${code}"${display}>
                    <div class="editor-field">
                        <label>${t('event.title')}${reqMark}</label>
                        <input type="text" id="event-title-${code}"${isDefault ? ' required' : ''}>
                    </div>
                    <div class="editor-field">
                        <label>${t('event.location')}</label>
                        <input type="text" id="event-location-${code}">
                    </div>
                    <div class="editor-field">
                        <label>${t('event.description')}</label>
                        <textarea id="event-description-${code}" rows="3"></textarea>
                    </div>
                    <div class="editor-field">
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

                    <div class="event-lang-section">
                        <div class="event-lang-tabs">
                            ${tabsHtml}
                            <button type="button" class="event-lang-copy-btn" id="event-copy-default" title="${t('event.copy_to_all')}">
                                ${Icons.copy || '⧉'} ${t('event.copy_to_all')}
                            </button>
                        </div>
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
        modal.querySelectorAll('.event-lang-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                modal.querySelectorAll('.event-lang-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                const lang = tab.dataset.lang;
                modal.querySelectorAll('.event-lang-panel').forEach(p => {
                    p.style.display = p.dataset.lang === lang ? '' : 'none';
                });
            });
        });

        // Copy default language to all others
        document.getElementById('event-copy-default').addEventListener('click', () => {
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

        // Legal link fields: edit text + href (text is lang-dependent)
        if (fieldName.startsWith('legalLinks.')) {
            const linkKey = fieldName.split('.')[1]; // 'impressum' or 'datenschutz'
            const currentText = field.textContent.trim();
            const currentHref = linkHref || field.getAttribute('href') || '';
            showPromptDialog(t('footer.edit'), t('footer.link_text_lang', { lang }), currentText, (newText) => {
                if (newText === null) return;
                showPromptDialog(t('footer.edit'), t('footer.link_url'), currentHref, (newHref) => {
                    if (newHref === null) return;
                    saveFooterLegalLink(linkKey, lang, newText, newHref, field);
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
    function saveFooterLegalLink(linkKey, lang, text, href, element) {
        pushUndoState();

        if (!EditorConfig.contentData['footer']) EditorConfig.contentData['footer'] = {};
        const footerData = EditorConfig.contentData['footer'];
        if (!footerData.legalLinks) footerData.legalLinks = {};
        if (!footerData.legalLinks[linkKey]) footerData.legalLinks[linkKey] = {};
        if (!footerData.legalLinks[linkKey].text) footerData.legalLinks[linkKey].text = {};
        footerData.legalLinks[linkKey].text[lang] = text;
        footerData.legalLinks[linkKey].href = href;

        EditorConfig.dirtyPages.add('footer');
        updateUndoRedoButtons();
    }

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
            field.classList.add('editable-field-active');

            // Add hide toggle button
            addFieldHideButton(field);

            if (field.classList.contains('editable-field-html')) {
                field.addEventListener('click', (e) => {
                    if (!EditorConfig.editMode) return;
                    e.preventDefault();
                    e.stopPropagation();
                    openFieldHtmlEditor(field);
                });
                return;
            }

            // Plain text fields: inline editing with contentEditable
            field.addEventListener('click', (e) => {
                if (!EditorConfig.editMode) return;
                e.stopPropagation();
                if (field.isContentEditable) return;
                startInlineEdit(field);
            });
        });
    }

    function addFieldHideButton(field) {
        // Skip fields inside comparison table (rows handle hiding at row level)
        if (field.closest('.comparison-table')) return;

        const isHidden = field.dataset.hidden === 'true';
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
    }

    function openFieldHtmlEditor(field) {
        const fieldKey = field.dataset.field;
        const page = field.dataset.page;
        const currentValue = field.innerHTML.trim();
        const label = fieldKey.split('.').pop();

        showPromptDialog('Edit HTML', label, currentValue, (newValue) => {
            if (newValue !== null && newValue !== currentValue) {
                saveField(page, fieldKey, newValue, field, true);
            }
        });
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
            link.classList.add('editable-field-active');

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

    function openLinkEditor(element) {
        const page = element.dataset.page;
        const fieldKey = element.dataset.field;
        const currentText = element.textContent.trim();
        const currentHref = element.getAttribute('href') || '';

        createLinkEditorDialog();

        document.getElementById('link-editor-text').value = currentText;
        document.getElementById('link-editor-href').value = currentHref;

        const modal = document.getElementById('link-editor-modal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Store reference for save
        modal._editTarget = { element, page, fieldKey };

        setTimeout(() => {
            document.getElementById('link-editor-text').focus();
        }, 100);
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
                    <h3>${t('edit')} Link</h3>
                    <button type="button" class="editor-close-btn" id="link-editor-cancel-btn">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div class="prompt-dialog-content">
                        <label class="prompt-dialog-label">Text</label>
                        <input type="text" class="prompt-dialog-input" id="link-editor-text">
                        <label class="prompt-dialog-label" style="margin-top: 12px;">URL</label>
                        <input type="text" class="prompt-dialog-input" id="link-editor-href" placeholder="https://...">
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
    }

    function closeLinkEditor() {
        const modal = document.getElementById('link-editor-modal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    function saveLinkEditor() {
        const modal = document.getElementById('link-editor-modal');
        const { element, page, fieldKey } = modal._editTarget;
        const newText = document.getElementById('link-editor-text').value;
        const newHref = document.getElementById('link-editor-href').value;

        closeLinkEditor();

        // Push undo state
        pushUndoState();

        // Update in-memory contentData
        if (!EditorConfig.contentData[page]) EditorConfig.contentData[page] = {};
        setNestedValue(EditorConfig.contentData[page], fieldKey, { text: newText, href: newHref });

        // Mark as dirty
        EditorConfig.dirtyPages.add(page);

        // Update DOM
        element.textContent = newText;
        element.setAttribute('href', newHref);

        updateUndoRedoButtons();
    }

    // ============================================================
    // EDITABLE IMAGES FOR CUSTOM LAYOUTS
    // ============================================================

    function attachImageEditHandlers() {
        const images = document.querySelectorAll('[data-editable-image]');
        if (images.length === 0) return;

        images.forEach(img => {
            // Skip images inside event cards — those are edited via the event editor modal
            if (img.closest('.event-card[data-event-id]')) return;
            const wrapper = document.createElement('div');
            wrapper.className = 'editable-image-wrapper';
            wrapper.style.position = 'relative';
            wrapper.style.display = 'inline-block';
            img.parentNode.insertBefore(wrapper, img);
            wrapper.appendChild(img);

            wrapper.classList.add('editable-field-active');

            // Add hide toggle button for image
            const isHidden = img.dataset.hidden === 'true';
            const hideBtn = document.createElement('button');
            hideBtn.type = 'button';
            hideBtn.className = 'field-hide-btn';
            hideBtn.title = isHidden ? t('show') : t('hide');
            hideBtn.innerHTML = isHidden ? Icons.eyeClosed : Icons.eyeOpen;
            hideBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!EditorConfig.editMode) return;
                toggleFieldHidden(img, hideBtn);
            });
            wrapper.appendChild(hideBtn);

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

        openImageManager((imagePath) => {
            // After image selected, ask for alt text
            showPromptDialog('Alt text', 'Alt text', currentAlt, (newAlt) => {
                if (newAlt === null) newAlt = currentAlt;
                saveImageField(page, fieldKey, imagePath, newAlt, imgElement);
            });
        });
    }

    function saveImageField(page, fieldKey, newSrc, newAlt, element) {
        // Push undo state
        pushUndoState();

        // Update in-memory contentData
        if (!EditorConfig.contentData[page]) EditorConfig.contentData[page] = {};
        setNestedValue(EditorConfig.contentData[page], fieldKey, { src: newSrc, alt: newAlt });

        // Mark as dirty
        EditorConfig.dirtyPages.add(page);

        // Update DOM
        element.setAttribute('src', newSrc);
        element.setAttribute('alt', newAlt);

        updateUndoRedoButtons();
    }

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
                overlay.className = 'editable-list-overlay';
                overlay.innerHTML = `
                    <span class="list-drag-handle" title="${t('drag_reorder')}">${Icons.drag}</span>
                    <button type="button" class="overlay-btn overlay-btn-hide list-hide-btn" title="${isItemHidden ? t('show') : t('hide')}" data-action="hide">${isItemHidden ? Icons.eyeClosed : Icons.eyeOpen}</button>
                    <button type="button" class="overlay-btn overlay-btn-delete list-delete-btn" title="${t('delete')}" data-action="delete">${Icons.delete}</button>
                `;
                item.style.position = 'relative';
                item.appendChild(overlay);

                // Apply hidden state
                if (isItemHidden) {
                    item.dataset.hidden = 'true';
                }

                // Make draggable
                item.setAttribute('draggable', 'true');

                // Drag events — use dynamic index from dataset (survives reorder)
                item.addEventListener('dragstart', (e) => {
                    if (!EditorConfig.editMode) { e.preventDefault(); return; }
                    const currentIndex = parseInt(item.dataset.listIndex, 10);
                    listDragState = { element: item, index: currentIndex, page, listKey };
                    item.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', currentIndex.toString());
                    document.querySelectorAll('iframe').forEach(f => f.style.pointerEvents = 'none');
                });

                item.addEventListener('dragend', () => {
                    item.classList.remove('dragging');
                    document.querySelectorAll('.editable-list-item').forEach(el => {
                        el.classList.remove('drag-over', 'drag-over-top', 'drag-over-bottom');
                    });
                    listDragState = { element: null, index: null, page: null, listKey: null };
                    document.querySelectorAll('iframe').forEach(f => f.style.pointerEvents = '');
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
            items.push({ ...defaults });
            setNestedObj(pageData, listKey, items);
        } else {
            const keys = Object.keys(items).map(Number).filter(k => !isNaN(k));
            const nextIndex = keys.length > 0 ? Math.max(...keys) + 1 : 0;
            items[nextIndex.toString()] = { ...defaults };
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

        const arr = objectToArray(items);
        arr.splice(afterIndex + 1, 0, { ...defaults });

        setNestedObj(pageData, listKey, arrayToNumberedObject(arr));
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
        btnEl.title = nowHidden ? t('show') : t('hide');

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
        });
        modal.querySelectorAll('.event-lang-panel').forEach(p => {
            p.style.display = p.dataset.lang === EditorConfig.defaultLang ? '' : 'none';
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
    // IMAGE MANAGER (extended with grid/list, sorting, replace, lightbox)
    // ============================================================

    let imageManagerCallback = null;
    let imageManagerData = [];
    let imageManagerFilteredData = []; // Filtered data for display
    let imageManagerView = 'grid'; // 'grid' or 'list'
    let imageManagerSort = { field: 'date', dir: 'desc' }; // Default: newest first
    let imageManagerSearchTerm = '';
    let imageManagerSelectedPath = null; // Currently selected image

    function updateImagePreview() {
        const input = document.getElementById('editor-input-image');
        const preview = document.getElementById('editor-image-preview');
        const imagePath = input.value.trim();

        if (imagePath) {
            preview.innerHTML = `<img src="${imagePath}" alt="Preview" onerror="this.parentNode.innerHTML='<p class=\\'preview-placeholder\\'>Image not found</p>'">`;
        } else {
            preview.innerHTML = '<p class="preview-placeholder">' + t('image.no_selection') + '</p>';
        }
    }

    function createImageManagerModal() {
        if (document.getElementById('image-manager-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'image-manager-modal';
        modal.className = 'editor-modal';
        modal.innerHTML = `
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-wide">
                <div class="editor-modal-header">
                    <h3>${t('image_manager')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeImageManager()">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <div class="image-manager-toolbar">
                        <label class="editor-btn editor-btn-primary image-upload-btn">
                            ${Icons.upload} ${t('image.upload_image')}
                            <input type="file" id="image-upload-input" accept=".jpg,.jpeg,.png,.webp" style="display:none;">
                        </label>
                        <span class="image-manager-info">${t('image.formats_hint')}</span>
                        <div class="image-manager-search">
                            <input type="text" id="image-search-input" placeholder="${t('image.search')}" class="image-search-input">
                        </div>
                        <div class="image-manager-view-toggle">
                            <button type="button" class="view-toggle-btn active" data-view="grid" title="${t('image.grid_view')}">
                                ${Icons.grid}
                            </button>
                            <button type="button" class="view-toggle-btn" data-view="list" title="${t('image.list_view')}">
                                ${Icons.list}
                            </button>
                        </div>
                    </div>
                    <div class="image-manager-container">
                        <div id="image-manager-grid" class="image-manager-grid">
                            <p class="image-manager-loading">${t('image.loading')}</p>
                        </div>
                        <div id="image-manager-list" class="image-manager-list">
                            <div class="image-list-header">
                                <div class="image-list-header-col"></div>
                                <div class="image-list-header-col">${t('image.col_image')}</div>
                                <div class="image-list-header-col sortable" data-sort="name">${t('image.col_filename')}</div>
                                <div class="image-list-header-col sortable" data-sort="size">${t('image.col_size')}</div>
                                <div class="image-list-header-col sortable" data-sort="date">${t('image.col_date')}</div>
                                <div class="image-list-header-col">${t('image.col_actions')}</div>
                            </div>
                            <div id="image-list-body" class="image-list-body"></div>
                        </div>
                    </div>
                </div>
                <div class="editor-modal-footer image-manager-footer">
                    <div class="image-selection-info">
                        <span class="image-selection-path" id="image-selection-path">${t('image.no_selection')}</span>
                    </div>
                    <div class="image-selection-actions">
                        <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closeImageManager()">${t('cancel')}</button>
                        <button type="button" class="editor-btn editor-btn-primary" id="image-confirm-btn" onclick="InlineEditor.confirmImageSelection()" disabled>${t('image.select')}</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.editor-modal-backdrop').addEventListener('click', closeImageManager);

        // Resizable Modal
        ModalResize.init(modal.querySelector('.editor-modal-content'));

        // Upload Event
        document.getElementById('image-upload-input').addEventListener('change', handleImageUpload);

        // View Toggle Events
        modal.querySelectorAll('.view-toggle-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const view = btn.dataset.view;
                switchImageManagerView(view);
            });
        });

        // Sort events for list view
        modal.querySelectorAll('.image-list-header-col.sortable').forEach(col => {
            col.addEventListener('click', () => {
                const field = col.dataset.sort;
                sortImages(field);
            });
        });

        // Such-Event
        document.getElementById('image-search-input').addEventListener('input', (e) => {
            imageManagerSearchTerm = e.target.value.toLowerCase().trim();
            filterAndRenderImages();
        });

        // Lightbox erstellen
        createImageLightbox();

        // Create replace dialog
        createReplaceDialog();
    }

    function switchImageManagerView(view) {
        imageManagerView = view;
        const gridEl = document.getElementById('image-manager-grid');
        const listEl = document.getElementById('image-manager-list');
        const toggleBtns = document.querySelectorAll('.view-toggle-btn');

        toggleBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === view);
        });

        if (view === 'grid') {
            gridEl.classList.remove('hidden');
            listEl.classList.remove('active');
        } else {
            gridEl.classList.add('hidden');
            listEl.classList.add('active');
        }

        renderImages();
    }

    function filterAndRenderImages() {
        // Filter based on search term
        if (imageManagerSearchTerm) {
            imageManagerFilteredData = imageManagerData.filter(img =>
                img.name.toLowerCase().includes(imageManagerSearchTerm)
            );
        } else {
            imageManagerFilteredData = [...imageManagerData];
        }
        renderImages();
    }

    function sortImages(field) {
        // Sortierrichtung umschalten
        if (imageManagerSort.field === field) {
            imageManagerSort.dir = imageManagerSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            imageManagerSort.field = field;
            imageManagerSort.dir = 'asc';
        }

        // Header-Klassen aktualisieren
        document.querySelectorAll('.image-list-header-col.sortable').forEach(col => {
            col.classList.remove('sorted-asc', 'sorted-desc');
            if (col.dataset.sort === field) {
                col.classList.add(imageManagerSort.dir === 'asc' ? 'sorted-asc' : 'sorted-desc');
            }
        });

        // Sort
        imageManagerData.sort((a, b) => {
            let valA, valB;

            switch (field) {
                case 'name':
                    valA = a.name.toLowerCase();
                    valB = b.name.toLowerCase();
                    break;
                case 'size':
                    valA = a.sizeBytes || 0;
                    valB = b.sizeBytes || 0;
                    break;
                case 'date':
                    valA = a.modified || 0;
                    valB = b.modified || 0;
                    break;
                default:
                    return 0;
            }

            if (valA < valB) return imageManagerSort.dir === 'asc' ? -1 : 1;
            if (valA > valB) return imageManagerSort.dir === 'asc' ? 1 : -1;
            return 0;
        });

        // Nach Sortierung neu filtern
        filterAndRenderImages();
    }

    function openImageManager(callback) {
        createImageManagerModal();
        imageManagerCallback = callback || ((imagePath) => {
            document.getElementById('editor-input-image').value = imagePath;
            updateImagePreview();
        });

        // Reset selection
        imageManagerSelectedPath = null;

        // Reset search field
        const searchInput = document.getElementById('image-search-input');
        if (searchInput) {
            searchInput.value = '';
            imageManagerSearchTerm = '';
        }

        loadImages();

        // Reset selection UI
        updateImageSelectionUI();

        document.getElementById('image-manager-modal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeImageManager() {
        const modal = document.getElementById('image-manager-modal');
        modal.classList.remove('active');
        ModalResize.reset(modal.querySelector('.editor-modal-content'));
        document.body.style.overflow = '';
        imageManagerCallback = null;
    }

    function openImageManagerForEvent() {
        openImageManager((imagePath) => {
            document.getElementById('event-image').value = imagePath;
        });
    }

    async function loadImages() {
        const gridEl = document.getElementById('image-manager-grid');
        const listBody = document.getElementById('image-list-body');

        gridEl.innerHTML = '<p class="image-manager-loading">' + t('image.loading') + '</p>';
        listBody.innerHTML = '';

        try {
            const response = await fetch(`${EditorConfig.apiUrl}?action=list-images`);
            const result = await response.json();

            if (result.success && result.data && result.data.length > 0) {
                // Normalize paths: ../images/... → /images/... (absolute)
                imageManagerData = result.data.map(img => ({
                    ...img,
                    path: img.path.replace(/^\.\.\//, '/')
                }));
                // Set header classes for current sort
                document.querySelectorAll('.image-list-header-col.sortable').forEach(col => {
                    col.classList.remove('sorted-asc', 'sorted-desc');
                    if (col.dataset.sort === imageManagerSort.field) {
                        col.classList.add(imageManagerSort.dir === 'asc' ? 'sorted-asc' : 'sorted-desc');
                    }
                });
                // Sort and filter
                sortImages(imageManagerSort.field);
            } else {
                imageManagerData = [];
                imageManagerFilteredData = [];
                gridEl.innerHTML = '<p class="image-manager-empty">No images found.</p>';
                listBody.innerHTML = '<p class="image-manager-empty">No images found.</p>';
            }
        } catch (error) {
            gridEl.innerHTML = `<p class="image-manager-error">Error loading: ${error.message}</p>`;
        }
    }

    function renderImages() {
        if (imageManagerView === 'grid') {
            renderGridView();
        } else {
            renderListView();
        }
    }

    function renderGridView() {
        const gridEl = document.getElementById('image-manager-grid');

        if (imageManagerFilteredData.length === 0) {
            if (imageManagerSearchTerm) {
                gridEl.innerHTML = '<p class="image-manager-empty">No images found for: "' + imageManagerSearchTerm + '"</p>';
            } else if (imageManagerData.length === 0) {
                gridEl.innerHTML = '<p class="image-manager-empty">No images found.</p>';
            } else {
                gridEl.innerHTML = '<p class="image-manager-empty">No images found.</p>';
            }
            return;
        }

        gridEl.innerHTML = '';

        imageManagerFilteredData.forEach(image => {
            const item = document.createElement('div');
            const isSelected = imageManagerSelectedPath === image.path;
            item.className = 'image-manager-item' + (isSelected ? ' selected' : '');
            item.dataset.path = image.path;
            item.innerHTML = `
                <div class="image-selection-checkbox${isSelected ? ' checked' : ''}" data-path="${image.path}"></div>
                <div class="image-manager-thumb" style="background-image: url('${image.path}')"></div>
                <div class="image-manager-name" title="${image.name}">${image.name}</div>
                <div class="image-manager-actions">
                    <button type="button" class="image-action-btn image-action-preview" title="${t('image_preview')}" data-path="${image.path}" data-name="${image.name}">
                        ${Icons.eye}
                    </button>
                    <button type="button" class="image-action-btn image-action-replace" title="${t('image.replace')}" data-name="${image.name}" data-path="${image.path}">
                        ${Icons.replace}
                    </button>
                    <button type="button" class="image-action-btn image-action-delete" title="${t('delete')}" data-name="${image.name}">
                        ${Icons.delete}
                    </button>
                </div>
            `;
            gridEl.appendChild(item);

            attachImageItemEvents(item, image);
        });
    }

    function renderListView() {
        const listBody = document.getElementById('image-list-body');

        if (imageManagerFilteredData.length === 0) {
            if (imageManagerSearchTerm) {
                listBody.innerHTML = '<p class="image-manager-empty">No images found for: "' + imageManagerSearchTerm + '"</p>';
            } else if (imageManagerData.length === 0) {
                listBody.innerHTML = '<p class="image-manager-empty">No images found.</p>';
            } else {
                listBody.innerHTML = '<p class="image-manager-empty">No images found.</p>';
            }
            return;
        }

        listBody.innerHTML = '';

        imageManagerFilteredData.forEach(image => {
            const row = document.createElement('div');
            const isSelected = imageManagerSelectedPath === image.path;
            row.className = 'image-list-row' + (isSelected ? ' selected' : '');
            row.dataset.path = image.path;
            row.innerHTML = `
                <div class="image-list-checkbox">
                    <div class="image-selection-checkbox${isSelected ? ' checked' : ''}" data-path="${image.path}"></div>
                </div>
                <div class="image-list-thumb" style="background-image: url('${image.path}')" data-path="${image.path}" data-name="${image.name}"></div>
                <div class="image-list-name" title="${image.name}">${image.name}</div>
                <div class="image-list-size">${image.size || '-'}</div>
                <div class="image-list-date">${image.dateFormatted || '-'}</div>
                <div class="image-list-actions">
                    <button type="button" class="image-action-btn image-action-preview" title="${t('image_preview')}" data-path="${image.path}" data-name="${image.name}">
                        ${Icons.eye}
                    </button>
                    <button type="button" class="image-action-btn image-action-replace" title="${t('image.replace')}" data-name="${image.name}" data-path="${image.path}">
                        ${Icons.replace}
                    </button>
                    <button type="button" class="image-action-btn image-action-delete" title="${t('delete')}" data-name="${image.name}">
                        ${Icons.delete}
                    </button>
                </div>
            `;
            listBody.appendChild(row);

            attachImageItemEvents(row, image);
        });
    }

    function attachImageItemEvents(element, image) {
        // Click on entire item or checkbox = toggle selection
        const checkbox = element.querySelector('.image-selection-checkbox');
        const thumb = element.querySelector('.image-manager-thumb');

        // Checkbox-Klick
        if (checkbox) {
            checkbox.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleImageSelection(image.path);
            });
        }

        // Thumbnail-Klick in Grid-Ansicht = Auswahl togglen
        if (thumb && element.classList.contains('image-manager-item')) {
            thumb.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleImageSelection(image.path);
            });
        }

        // Thumbnail-Klick in List-Ansicht = Vorschau
        const listThumb = element.querySelector('.image-list-thumb');
        if (listThumb) {
            listThumb.addEventListener('click', (e) => {
                e.stopPropagation();
                openImageLightbox(image.path, image.name);
            });
        }

        // Vorschau-Button
        element.querySelector('.image-action-preview').addEventListener('click', (e) => {
            e.stopPropagation();
            openImageLightbox(image.path, image.name);
        });

        // Replace
        element.querySelector('.image-action-replace').addEventListener('click', (e) => {
            e.stopPropagation();
            openReplaceDialog(image.name, image.path);
        });

        // Delete
        element.querySelector('.image-action-delete').addEventListener('click', (e) => {
            e.stopPropagation();
            deleteImage(image.name);
        });

        // Click on row in list view = toggle selection
        if (element.classList.contains('image-list-row')) {
            element.addEventListener('click', (e) => {
                // Only if not clicked on a button
                if (!e.target.closest('.image-action-btn') && !e.target.closest('.image-list-thumb')) {
                    toggleImageSelection(image.path);
                }
            });
        }
    }

    function toggleImageSelection(imagePath) {
        // Toggle: if already selected, deselect
        if (imageManagerSelectedPath === imagePath) {
            imageManagerSelectedPath = null;
        } else {
            imageManagerSelectedPath = imagePath;
        }
        updateImageSelectionUI();
    }

    function updateImageSelectionUI() {
        // Alle Items aktualisieren
        document.querySelectorAll('.image-manager-item, .image-list-row').forEach(item => {
            const path = item.dataset.path;
            const isSelected = path === imageManagerSelectedPath;
            item.classList.toggle('selected', isSelected);

            const checkbox = item.querySelector('.image-selection-checkbox');
            if (checkbox) {
                checkbox.classList.toggle('checked', isSelected);
            }
        });

        // Footer aktualisieren
        const pathDisplay = document.getElementById('image-selection-path');
        const confirmBtn = document.getElementById('image-confirm-btn');

        if (imageManagerSelectedPath) {
            // Convert relative path to absolute URL for better readability
            let displayPath = imageManagerSelectedPath;
            if (displayPath.startsWith('../')) {
                // ../images/... -> absolute URL
                displayPath = window.location.origin + '/' + displayPath.replace(/^\.\.\//, '');
            } else if (displayPath.startsWith('/')) {
                displayPath = window.location.origin + displayPath;
            }
            pathDisplay.textContent = displayPath;
            pathDisplay.classList.add('has-selection');
            confirmBtn.disabled = false;
        } else {
            pathDisplay.textContent = t('image.no_selection');
            pathDisplay.classList.remove('has-selection');
            confirmBtn.disabled = true;
        }
    }

    function confirmImageSelection() {
        if (imageManagerSelectedPath && imageManagerCallback) {
            imageManagerCallback(imageManagerSelectedPath);
        }
        closeImageManager();
    }

    // Legacy function for direct selection (no longer used)
    function selectImage(imagePath) {
        imageManagerSelectedPath = imagePath;
        updateImageSelectionUI();
    }

    // ============================================================
    // LIGHTBOX
    // ============================================================

    function createImageLightbox() {
        if (document.getElementById('image-lightbox')) return;

        const lightbox = document.createElement('div');
        lightbox.id = 'image-lightbox';
        lightbox.className = 'image-lightbox';
        lightbox.innerHTML = `
            <div class="image-lightbox-content">
                <button type="button" class="image-lightbox-close" onclick="InlineEditor.closeLightbox()">&times;</button>
                <img id="lightbox-image" src="" alt="">
                <div id="lightbox-info" class="image-lightbox-info"></div>
            </div>
        `;
        document.body.appendChild(lightbox);

        // Click on background closes lightbox
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) {
                closeLightbox();
            }
        });

        // Escape key closes lightbox
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && lightbox.classList.contains('active')) {
                closeLightbox();
            }
        });
    }

    function openImageLightbox(imagePath, imageName) {
        const lightbox = document.getElementById('image-lightbox');
        const img = document.getElementById('lightbox-image');
        const info = document.getElementById('lightbox-info');

        img.src = imagePath;
        info.textContent = imageName;
        lightbox.classList.add('active');
    }

    function closeLightbox() {
        const lightbox = document.getElementById('image-lightbox');
        if (lightbox) {
            lightbox.classList.remove('active');
        }
    }

    function openEditorImageLightbox() {
        const imagePath = document.getElementById('editor-input-image').value.trim();
        if (!imagePath) {
            showToast(t('image.no_selection'), 'error');
            return;
        }
        // Extract filename from path
        const imageName = imagePath.split('/').pop();
        // Create lightbox if needed
        createImageLightbox();
        openImageLightbox(imagePath, imageName);
    }

    // ============================================================
    // ERSETZEN-DIALOG
    // ============================================================

    let replaceDialogTarget = null;

    function createReplaceDialog() {
        if (document.getElementById('replace-dialog')) return;

        const dialog = document.createElement('div');
        dialog.id = 'replace-dialog';
        dialog.className = 'editor-modal';
        dialog.innerHTML = `
            <div class="editor-modal-backdrop"></div>
            <div class="editor-modal-content editor-modal-small">
                <div class="editor-modal-header">
                    <h3>${t('image.replace')}</h3>
                    <button type="button" class="editor-close-btn" onclick="InlineEditor.closeReplaceDialog()">&times;</button>
                </div>
                <div class="editor-modal-body">
                    <p style="margin-top:0;">${t('image.replacing')} <strong id="replace-dialog-filename"></strong></p>

                    <div class="replace-dialog-options">
                        <label class="replace-dialog-option selected" data-option="replace">
                            <input type="radio" name="replace-option" value="replace" checked>
                            <div class="replace-dialog-option-content">
                                <div class="replace-dialog-option-title">${t('image.overwrite_file')}</div>
                                <div class="replace-dialog-option-desc">${t('image.overwrite_desc')}</div>
                            </div>
                        </label>
                        <label class="replace-dialog-option" data-option="new">
                            <input type="radio" name="replace-option" value="new">
                            <div class="replace-dialog-option-content">
                                <div class="replace-dialog-option-title">${t('image.save_new_name')}</div>
                                <div class="replace-dialog-option-desc">${t('image.save_new_desc')}</div>
                            </div>
                        </label>
                    </div>

                    <div class="replace-dialog-file">
                        <label>
                            ${Icons.upload} ${t('image.choose_file')}
                            <input type="file" id="replace-dialog-file-input" accept=".jpg,.jpeg,.png,.webp">
                        </label>
                        <div id="replace-dialog-selected-file" class="replace-dialog-filename"></div>
                    </div>
                </div>
                <div class="editor-modal-footer">
                    <button type="button" class="editor-btn editor-btn-secondary" onclick="InlineEditor.closeReplaceDialog()">${t('cancel')}</button>
                    <button type="button" class="editor-btn editor-btn-primary" id="replace-dialog-submit" disabled>${t('image.upload')}</button>
                </div>
            </div>
        `;
        document.body.appendChild(dialog);

        dialog.querySelector('.editor-modal-backdrop').addEventListener('click', closeReplaceDialog);

        // Resizable Modal
        ModalResize.init(dialog.querySelector('.editor-modal-content'));

        // Option-Auswahl
        dialog.querySelectorAll('.replace-dialog-option').forEach(option => {
            option.addEventListener('click', () => {
                dialog.querySelectorAll('.replace-dialog-option').forEach(o => o.classList.remove('selected'));
                option.classList.add('selected');
                option.querySelector('input[type="radio"]').checked = true;
            });
        });

        // File selection
        const fileInput = document.getElementById('replace-dialog-file-input');
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            const display = document.getElementById('replace-dialog-selected-file');
            const submitBtn = document.getElementById('replace-dialog-submit');

            if (file) {
                display.textContent = file.name;
                submitBtn.disabled = false;
            } else {
                display.textContent = '';
                submitBtn.disabled = true;
            }
        });

        // Submit
        document.getElementById('replace-dialog-submit').addEventListener('click', handleReplaceUpload);
    }

    function openReplaceDialog(filename, filepath) {
        replaceDialogTarget = { name: filename, path: filepath };

        document.getElementById('replace-dialog-filename').textContent = filename;

        // Reset
        document.getElementById('replace-dialog-file-input').value = '';
        document.getElementById('replace-dialog-selected-file').textContent = '';
        document.getElementById('replace-dialog-submit').disabled = true;

        // Select first option
        const options = document.querySelectorAll('.replace-dialog-option');
        options.forEach((o, i) => o.classList.toggle('selected', i === 0));
        document.querySelector('input[name="replace-option"][value="replace"]').checked = true;

        document.getElementById('replace-dialog').classList.add('active');
    }

    function closeReplaceDialog() {
        const dialog = document.getElementById('replace-dialog');
        dialog.classList.remove('active');
        ModalResize.reset(dialog.querySelector('.editor-modal-content'));
        replaceDialogTarget = null;
    }

    async function handleReplaceUpload() {
        const fileInput = document.getElementById('replace-dialog-file-input');
        const file = fileInput.files[0];

        if (!file || !replaceDialogTarget) return;

        // Check file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showToast(t('image.format_error'), 'error');
            return;
        }

        // Check file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            showToast(t('image.size_error'), 'error');
            return;
        }

        const option = document.querySelector('input[name="replace-option"]:checked').value;
        let targetFilename = replaceDialogTarget.name;

        if (option === 'new') {
            // Use the filename of the uploaded file
            targetFilename = file.name;

            // Check if filename already exists
            const existingFile = imageManagerData.find(img =>
                img.name.toLowerCase() === targetFilename.toLowerCase()
            );

            if (existingFile) {
                showToast(t('image.exists', { filename: targetFilename }), 'error');
                return;
            }
        }

        try {
            const formData = new FormData();
            formData.append('action', 'upload-image');
            formData.append('image', file);
            formData.append('filename', targetFilename);
            formData.append('replace', option === 'replace' ? '1' : '0');
            formData.append('csrf_token', EditorConfig.csrfToken);

            showToast(t('image.uploading'), 'success');

            const response = await fetch(EditorConfig.apiUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showToast(option === 'replace' ? 'Image replaced!' : 'Image uploaded!', 'success');
                closeReplaceDialog();
                // After upload: set sort to date descending
                imageManagerSort = { field: 'date', dir: 'desc' };
                loadImages();
            } else {
                showToast(t('toast.error_generic', { message: result.message }), 'error');
            }
        } catch (error) {
            showToast(t('toast.upload_error', { message: error.message }), 'error');
        }
    }

    // ============================================================
    // DELETE & UPLOAD
    // ============================================================

    async function deleteImage(filename) {
        showConfirmDialog(
            t('image.delete'),
            t('image.delete_confirm', { filename }),
            t('image.trash_hint'),
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete-image');
                    formData.append('filename', filename);
                    formData.append('csrf_token', EditorConfig.csrfToken);

                    const response = await fetch(EditorConfig.apiUrl, {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showToast(t('image.trashed'), 'success');
                        loadImages();
                    } else {
                        showToast(t('toast.error_generic', { message: result.message }), 'error');
                    }
                } catch (error) {
                    showToast(t('toast.error_generic', { message: error.message }), 'error');
                }
            }
        );
    }

    async function handleImageUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        // Check file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showToast(t('image.format_error'), 'error');
            e.target.value = '';
            return;
        }

        // Check file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            showToast(t('image.size_error'), 'error');
            e.target.value = '';
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'upload-image');
            formData.append('image', file);
            formData.append('csrf_token', EditorConfig.csrfToken);

            showToast(t('image.uploading'), 'success');

            const response = await fetch(EditorConfig.apiUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showToast(t('image.uploaded'), 'success');
                // After upload: set sort to date descending
                imageManagerSort = { field: 'date', dir: 'desc' };
                loadImages();
                // Optional: select image directly
                if (result.data && result.data.path) {
                    selectImage(result.data.path.replace(/^\.\.\//, '/'));
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

    function openAudioManager(callback) {
        createAudioManagerModal();
        audioManagerCallback = callback || ((audioPath) => {
            document.getElementById('editor-input-src').value = audioPath;
            updateAudioPreview();
        });

        loadAudioFiles();

        // Reset search field
        const searchInput = document.getElementById('audio-search-input');
        if (searchInput) searchInput.value = '';

        document.getElementById('audio-manager-modal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeAudioManager() {
        const modal = document.getElementById('audio-manager-modal');
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

    function enableNewsPostEditing() {
        const postEl = document.querySelector('[data-news-post]');
        if (!postEl) return;

        const titleEl = postEl.querySelector('.news-post-page__title');
        const contentEl = postEl.querySelector('.news-post-page__content');
        const authorEl = postEl.querySelector('.news-post-page__author');
        const heroEl = postEl.querySelector('.news-post-page__hero img');

        if (titleEl) {
            titleEl.contentEditable = 'true';
            titleEl.classList.add('news-editable');
            titleEl.addEventListener('input', markNewsPostDirty);
        }

        if (contentEl) {
            contentEl.contentEditable = 'true';
            contentEl.classList.add('news-editable');
            contentEl.addEventListener('input', markNewsPostDirty);
        }

        if (authorEl) {
            authorEl.contentEditable = 'true';
            authorEl.classList.add('news-editable');
            authorEl.addEventListener('input', markNewsPostDirty);
        }

        if (heroEl) {
            heroEl.style.cursor = 'pointer';
            heroEl.classList.add('news-editable');
            heroEl.title = t('editor.change_image') || 'Change image';
            heroEl._newsClickHandler = function() {
                openImageManager(function(imagePath) {
                    heroEl.src = imagePath;
                    EditorConfig.newsPostData.image = imagePath;
                    markNewsPostDirty();
                    closeImageManager();
                });
            };
            heroEl.addEventListener('click', heroEl._newsClickHandler);
        }
    }

    function disableNewsPostEditing() {
        const postEl = document.querySelector('[data-news-post]');
        if (!postEl) return;

        const titleEl = postEl.querySelector('.news-post-page__title');
        const contentEl = postEl.querySelector('.news-post-page__content');
        const authorEl = postEl.querySelector('.news-post-page__author');
        const heroEl = postEl.querySelector('.news-post-page__hero img');

        if (titleEl) {
            titleEl.contentEditable = 'false';
            titleEl.classList.remove('news-editable');
            titleEl.removeEventListener('input', markNewsPostDirty);
        }

        if (contentEl) {
            contentEl.contentEditable = 'false';
            contentEl.classList.remove('news-editable');
            contentEl.removeEventListener('input', markNewsPostDirty);
        }

        if (authorEl) {
            authorEl.contentEditable = 'false';
            authorEl.classList.remove('news-editable');
            authorEl.removeEventListener('input', markNewsPostDirty);
        }

        if (heroEl) {
            heroEl.style.cursor = '';
            heroEl.classList.remove('news-editable');
            heroEl.title = '';
            if (heroEl._newsClickHandler) {
                heroEl.removeEventListener('click', heroEl._newsClickHandler);
                delete heroEl._newsClickHandler;
            }
        }
    }

    function markNewsPostDirty() {
        EditorConfig.dirtyPages.add('__news_post__');
        updateUndoRedoButtons();
    }

    // ============================================================
    // GLOBALES API
    // ============================================================

    window.InlineEditor = {
        init: initEditor,
        openEditor: openEditor,
        closeModal: closeModal,
        execCommand: execCommand,
        insertLink: insertLink,
        toggleHtmlMode: toggleHtmlMode,
        saveSection: saveSection,
        cleanHtml: cleanHtml,
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
        // News post editing
        enableNewsPostEditing: enableNewsPostEditing,
        disableNewsPostEditing: disableNewsPostEditing,
        // Undo-Funktionen
        openHistoryModal: openHistoryModal,
        closeHistoryModal: closeHistoryModal,
        restoreFromHistory: restoreFromHistory,
        clearHistory: clearHistory,
        // Edit-Mode
        enterEditMode: enterEditMode,
        exitEditMode: exitEditMode,
        saveAllChanges: saveAllChanges,
        undo: undo,
        redo: redo,
        // Image manager
        openImageManager: openImageManager,
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
