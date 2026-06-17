/*
 * Nibbly Image Manager — Unified Component
 *
 * Shared between the frontend inline editor and the backend dashboard.
 * Consumers initialize it once via NbImageManager.init({...}) and then
 * call NbImageManager.open(callback, currentPath, { types: ['image'] }) to pick media.
 *
 * Depends on css/image-manager.css and css/nibbly-admin-tokens.css.
 */

(function () {
    'use strict';

    // ============================================================
    // CONFIGURATION (set via init())
    // ============================================================
    var config = {
        apiUrl: 'api.php',
        csrfToken: '',
        t: function (key) { return key; },
        showToast: function (msg) { console.log(msg); },
        showConfirm: null, // optional; falls back to window.confirm
        canGenerateImages: function () { return false; },
        openImageGenerator: null,
    };

    // ============================================================
    // STATE
    // ============================================================
    var state = {
        data: [],
        filtered: [],
        selectedPath: null,
        selectedPaths: [],
        multiplePicker: false,
        callback: null,
        isPicker: false,
        allowedTypes: ['image'],
        activeType: 'image',
        view: 'grid',
        mode: 'library',
        sort: { field: 'date', dir: 'desc' },
        search: '',
        folders: [],
        folderFilter: 'all',
        expandedFolders: {},
        lightboxItem: null,
    };

    var replaceTarget = null;
    var moveTarget = null;
    var previousFocus = null;
    var mediaTypes = {
        image: {
            labelKey: 'media.type_images',
            uploadLabelKey: 'image.upload',
            formatsKey: 'image.formats_hint',
            accept: '.jpg,.jpeg,.png,.webp,.gif,.svg,image/*',
            extensions: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'],
            maxSize: 5 * 1024 * 1024,
        },
        audio: {
            labelKey: 'media.type_audio',
            uploadLabelKey: 'audio.upload',
            formatsKey: 'audio.formats_hint',
            accept: '.mp3,.wav,.ogg,.m4a,.aac,.flac,audio/*',
            extensions: ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'],
            maxSize: 50 * 1024 * 1024,
        },
        video: {
            labelKey: 'media.type_video',
            uploadLabelKey: 'media.upload',
            formatsKey: 'media.video_formats_hint',
            accept: '.mp4,.webm,.mov,.m4v,video/*',
            extensions: ['mp4', 'webm', 'mov', 'm4v'],
            maxSize: 250 * 1024 * 1024,
        },
        document: {
            labelKey: 'media.type_documents',
            uploadLabelKey: 'media.upload',
            formatsKey: 'media.document_formats_hint',
            accept: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,application/pdf,text/plain',
            extensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf'],
            maxSize: 50 * 1024 * 1024,
        },
    };

    // ============================================================
    // SVG ICONS
    // ============================================================
    var Icons = {
        upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
        grid: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h8v8H3zM13 3h8v8h-8zM3 13h8v8H3zM13 13h8v8h-8z"/></svg>',
        list: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 5h18v2H3zM3 11h18v2H3zM3 17h18v2H3z"/></svg>',
        eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        ai: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l1.6 5.2L19 9l-5.4 1.8L12 16l-1.6-5.2L5 9l5.4-1.8L12 2z"/><path d="M19 14l.8 2.7L22 17.5l-2.2.8L19 21l-.8-2.7-2.2-.8 2.2-.8L19 14z"/><path d="M5 13l.7 2.1L8 16l-2.3.9L5 19l-.7-2.1L2 16l2.3-.9L5 13z"/></svg>',
        replace: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
        delete: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        restore: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
        folder: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6.5A2.5 2.5 0 0 1 5.5 4H10l2 2.5h6.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z"/></svg>',
        folderPlus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6.5A2.5 2.5 0 0 1 5.5 4H10l2 2.5h6.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z"/><path d="M12 11v6M9 14h6"/></svg>',
        layers: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5z"/><path d="m2 12 10 5 10-5M2 17l10 5 10-5"/></svg>',
        move: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h5l2 2h11v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M13 12h5M16 9l3 3-3 3"/></svg>',
        chevronRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>',
        chevronLeft: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>',
    };

    // ============================================================
    // HELPERS
    // ============================================================
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function t(key, params) {
        var s = config.t(key, params);
        if (params && typeof s === 'string') {
            for (var k in params) {
                s = s.replace('{' + k + '}', params[k]);
            }
        }
        return s;
    }

    function confirmAction(title, message, onYes) {
        if (typeof config.showConfirm === 'function') {
            config.showConfirm(title, message, onYes);
        } else {
            showFallbackConfirm(title, message, onYes);
        }
    }

    function showFallbackConfirm(title, message, onYes) {
        var overlay = document.createElement('div');
        overlay.className = 'nibbly-dialog-overlay';
        overlay.innerHTML =
            '<div class="nibbly-dialog" role="dialog" aria-modal="true">' +
                '<h3></h3>' +
                '<p></p>' +
                '<div class="nibbly-dialog__actions">' +
                    '<button type="button" class="nibbly-dialog__btn nibbly-dialog__btn--secondary" data-action="cancel">Abbrechen</button>' +
                    '<button type="button" class="nibbly-dialog__btn nibbly-dialog__btn--primary" data-action="confirm">Bestätigen</button>' +
                '</div>' +
            '</div>';
        overlay.querySelector('h3').textContent = title || 'Bestätigen';
        overlay.querySelector('p').textContent = message || '';
        var style = document.createElement('style');
        style.textContent = '.nibbly-dialog-overlay{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(17,24,39,.55);padding:20px}.nibbly-dialog{width:min(420px,100%);background:#fff;border-radius:10px;box-shadow:0 18px 60px rgba(15,23,42,.25);padding:22px}.nibbly-dialog h3{margin:0 0 10px;font-size:20px}.nibbly-dialog p{margin:0 0 18px;color:#374151;line-height:1.45}.nibbly-dialog__actions{display:flex;gap:10px;justify-content:flex-end}.nibbly-dialog__btn{border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font:inherit;padding:9px 14px}.nibbly-dialog__btn--primary{background:#5aa6a6;border-color:#5aa6a6;color:#fff}.nibbly-dialog__btn--secondary{background:#fff;color:#111827}';
        overlay.appendChild(style);
        function close() {
            overlay.remove();
        }
        overlay.addEventListener('click', function(event) {
            var action = event.target && event.target.getAttribute('data-action');
            if (action === 'cancel' || event.target === overlay) close();
            if (action === 'confirm') {
                close();
                onYes();
            }
        });
        document.body.appendChild(overlay);
        overlay.querySelector('[data-action="confirm"]').focus();
    }

    function getActiveUploadType() {
        if (state.activeType && state.activeType !== 'all') return state.activeType;
        return state.allowedTypes[0] || 'image';
    }

    function mediaTypeLabel(type) {
        return t((mediaTypes[type] || mediaTypes.image).labelKey);
    }

    function isImageOnlyPicker() {
        return state.allowedTypes.length === 1 && state.allowedTypes[0] === 'image';
    }

    function normalizeTypes(types) {
        var input = Array.isArray(types) ? types : [types || 'image'];
        var normalized = input.filter(function (type) { return !!mediaTypes[type]; });
        return normalized.length ? normalized : ['image'];
    }

    function isImage(item) { return (item.type || 'image') === 'image'; }
    function isAudio(item) { return item.type === 'audio'; }
    function isVideo(item) { return item.type === 'video'; }
    function isDocument(item) { return item.type === 'document'; }

    function mediaDisplayName(item) {
        return item.basename || item.name || item.path || t('media.file');
    }

    function mediaDownloadUrl(item) {
        var path = item && item.path ? String(item.path) : '';
        if (!path) return '#';
        return path.charAt(0) === '/' ? '..' + path : path;
    }

    function mediaDownloadActionHtml(item) {
        if (!isImage(item)) return '';
        return '<a class="nb-imgmgr-action-btn" data-action="download" href="' + escapeHtml(mediaDownloadUrl(item)) + '" download="' + escapeHtml(mediaDisplayName(item)) + '" title="' + escapeHtml(t('media.download_file')) + '" aria-label="' + escapeHtml(t('media.download_file')) + '">' + Icons.download + '</a>';
    }

    function canOpenImageGenerator() {
        try {
            return typeof config.openImageGenerator === 'function'
                && typeof config.canGenerateImages === 'function'
                && config.canGenerateImages() === true;
        } catch (e) {
            return false;
        }
    }

    function mediaNameHtml(item, className) {
        var folder = item.folder || '';
        return '<div class="' + className + '" title="' + escapeHtml(item.name || item.path || '') + '">' +
            '<span class="nb-imgmgr-file-name">' + escapeHtml(mediaDisplayName(item)) + '</span>' +
            (folder ? '<span class="nb-imgmgr-folder-name">' + escapeHtml(folder) + '</span>' : '') +
        '</div>';
    }

    function mediaIcon(item) {
        if (isAudio(item)) return '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3v10.5A4 4 0 1 0 14 17V7h4V3h-6z"/></svg>';
        if (isVideo(item)) return '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 5h12a2 2 0 0 1 2 2v1.2l4-2.2v12l-4-2.2V17a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>';
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8M8 9h2"/></svg>';
    }

    function mediaThumbHtml(item, className, previewAction) {
        var actionAttr = previewAction ? ' data-action="preview"' : '';
        var previewAttr = ' data-preview-path="' + escapeHtml(item.path || '') + '" data-preview-name="' + escapeHtml(mediaDisplayName(item)) + '" onclick="window.NbImageManager && NbImageManager.preview(this.dataset.previewPath, this.dataset.previewName); return false;"';
        if (isImage(item)) {
            return '<button type="button" class="' + className + ' nb-imgmgr-thumb-btn nb-imgmgr-thumb-btn--image"' + actionAttr + previewAttr + ' aria-label="' + escapeHtml(t('image_preview')) + '">' +
                '<span class="nb-imgmgr-thumb-surface"><img src="' + escapeHtml(item.path) + '" alt="" loading="lazy"></span>' +
            '</button>';
        }
        return '<button type="button" class="' + className + ' nb-imgmgr-thumb-btn nb-imgmgr-media-icon nb-imgmgr-media-icon--' + escapeHtml(item.type || 'document') + '"' + actionAttr + previewAttr + ' aria-label="' + escapeHtml(t('image_preview')) + '">' + mediaIcon(item) + '</button>';
    }

    // ============================================================
    // MODAL CREATION
    // ============================================================
    function createModal() {
        if (document.getElementById('nb-imgmgr-modal')) return;

        var modal = document.createElement('div');
        modal.id = 'nb-imgmgr-modal';
        modal.className = 'nb-imgmgr-modal';
        modal.innerHTML =
            '<div class="nb-imgmgr-backdrop" aria-hidden="true"></div>' +
            '<div class="nb-imgmgr-dialog" role="dialog" aria-modal="true" aria-labelledby="nb-imgmgr-title">' +
                '<div class="nb-imgmgr-header">' +
                    '<h3 id="nb-imgmgr-title">' + escapeHtml(t('media_manager')) + '</h3>' +
                    '<button type="button" class="nb-imgmgr-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="nb-imgmgr-toolbar">' +
                    '<span class="nb-imgmgr-formats">' + escapeHtml(t('image.formats_hint')) + '</span>' +
                    '<div class="nb-imgmgr-type-toggle"></div>' +
                    '<div class="nb-imgmgr-mode-toggle">' +
                        '<button type="button" class="nb-imgmgr-mode-btn nb-imgmgr-mode-btn--active" data-mode="library" aria-pressed="true">' + escapeHtml(t('image.library')) + '</button>' +
                        '<button type="button" class="nb-imgmgr-mode-btn" data-mode="trash" aria-pressed="false">' + escapeHtml(t('image.trash')) + '</button>' +
                    '</div>' +
                    '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--secondary nb-imgmgr-empty-trash-btn" data-action="empty-trash" hidden>' + escapeHtml(t('image.empty_trash')) + '</button>' +
                    '<span class="nb-imgmgr-spacer"></span>' +
                    '<input type="text" class="nb-imgmgr-search" aria-label="' + escapeHtml(t('image.search')) + '" placeholder="' + escapeHtml(t('image.search')) + '">' +
                    '<div class="nb-imgmgr-view-toggle">' +
                        '<button type="button" class="nb-imgmgr-view-btn nb-imgmgr-view-btn--active" data-view="grid" title="' + escapeHtml(t('image.grid_view')) + '" aria-label="' + escapeHtml(t('image.grid_view')) + '" aria-pressed="true">' + Icons.grid + '</button>' +
                        '<button type="button" class="nb-imgmgr-view-btn" data-view="list" title="' + escapeHtml(t('image.list_view')) + '" aria-label="' + escapeHtml(t('image.list_view')) + '" aria-pressed="false">' + Icons.list + '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="nb-imgmgr-body">' +
                    '<aside class="nb-imgmgr-sidebar" aria-label="' + escapeHtml(t('media.folder')) + '">' +
                        '<div class="nb-imgmgr-sidebar-head">' +
                            '<span class="nb-imgmgr-sidebar-title">' + escapeHtml(t('media.folders')) + '</span>' +
                            '<button type="button" class="nb-imgmgr-action-btn nb-imgmgr-folder-create" title="' + escapeHtml(t('media.folder_create')) + '" aria-label="' + escapeHtml(t('media.folder_create')) + '">' + Icons.folderPlus + '</button>' +
                        '</div>' +
                        '<div class="nb-imgmgr-folder-list" role="listbox" aria-label="' + escapeHtml(t('media.folders')) + '"></div>' +
                    '</aside>' +
                    '<div class="nb-imgmgr-content">' +
                        '<div class="nb-imgmgr-grid" role="list"></div>' +
                        '<div class="nb-imgmgr-list">' +
                            '<div class="nb-imgmgr-list-header">' +
                                '<div class="nb-imgmgr-list-header-col"></div>' +
                                '<div class="nb-imgmgr-list-header-col"></div>' +
                                '<div class="nb-imgmgr-list-header-col sortable" data-sort="name">' + escapeHtml(t('image.col_filename')) + '</div>' +
                                '<div class="nb-imgmgr-list-header-col sortable" data-sort="size">' + escapeHtml(t('image.col_size')) + '</div>' +
                                '<div class="nb-imgmgr-list-header-col sortable" data-sort="date">' + escapeHtml(t('image.col_date')) + '</div>' +
                                '<div class="nb-imgmgr-list-header-col"></div>' +
                            '</div>' +
                            '<div class="nb-imgmgr-list-body"></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="nb-imgmgr-dropzone">' +
                    '<button type="button" class="nb-imgmgr-ai-generator-btn" data-action="ai-generator" hidden>' +
                        Icons.ai + '<span>' + escapeHtml(t('ai.image_generator')) + '</span>' +
                    '</button>' +
                    '<div class="nb-imgmgr-dropzone-upload">' +
                        '<span class="nb-imgmgr-dropzone-text">' + escapeHtml(t('image.drop_files')) + '</span>' +
                        '<span class="nb-imgmgr-dropzone-or">' + escapeHtml(t('image.or')) + '</span>' +
                        '<select class="nb-imgmgr-upload-type"></select>' +
                        '<label class="nb-imgmgr-upload-btn">' +
                            Icons.upload + ' <span>' + escapeHtml(t('image.upload')) + '</span>' +
                            '<input type="file" class="nb-imgmgr-upload-input" accept=".jpg,.jpeg,.png,.webp">' +
                        '</label>' +
                    '</div>' +
                '</div>' +
                '<div class="nb-imgmgr-footer">' +
                    '<div class="nb-imgmgr-selection-info"></div>' +
                    '<div class="nb-imgmgr-footer-actions">' +
                        '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--secondary" data-action="cancel">' + escapeHtml(t('close')) + '</button>' +
                        '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--primary" data-action="confirm" disabled>' + escapeHtml(t('image.select')) + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);

        // Event wiring
        modal.querySelector('.nb-imgmgr-backdrop').addEventListener('click', close);
        modal.querySelector('.nb-imgmgr-close').addEventListener('click', close);
        modal.querySelector('[data-action="cancel"]').addEventListener('click', close);
        modal.querySelector('[data-action="confirm"]').addEventListener('click', confirmSelection);
        modal.querySelector('[data-action="empty-trash"]').addEventListener('click', emptyImageTrash);

        modal.querySelector('.nb-imgmgr-upload-input').addEventListener('change', handleUpload);
        modal.querySelector('.nb-imgmgr-ai-generator-btn').addEventListener('click', function () {
            if (!canOpenImageGenerator()) return;
            config.openImageGenerator('', 'auto');
        });
        modal.addEventListener('click', function (e) {
            var thumb = e.target.closest && e.target.closest('.nb-imgmgr-thumb-btn');
            if (!thumb || !modal.contains(thumb)) return;
            var itemEl = thumb.closest('.nb-imgmgr-item, .nb-imgmgr-row');
            var path = itemEl ? itemEl.dataset.path : '';
            var image = state.data.find(function (entry) { return entry.path === path; });
            if (!image) return;
            e.preventDefault();
            e.stopPropagation();
            openLightbox(image);
        }, true);
        modal.querySelector('.nb-imgmgr-folder-create').addEventListener('click', createMediaFolder);
        var folderListEl = modal.querySelector('.nb-imgmgr-folder-list');
        folderListEl.addEventListener('click', function(e) {
            var entry = e.target.closest('.nb-imgmgr-folder-item');
            if (!entry || !modal.contains(entry)) return;
            var deleteBtn = e.target.closest('.nb-imgmgr-folder-item-delete');
            if (deleteBtn) {
                e.stopPropagation();
                deleteMediaFolder(entry.getAttribute('data-folder') || '');
                return;
            }
            // Root folder uses an empty-string value, which is falsy — read the
            // attribute directly so "Main folder" is not coerced to "all".
            state.folderFilter = entry.getAttribute('data-folder') || '';
            filterAndRender();
            updateFolderUI();
        });
        folderListEl.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var entry = e.target.closest('.nb-imgmgr-folder-item');
            if (!entry) return;
            e.preventDefault();
            state.folderFilter = entry.getAttribute('data-folder') || '';
            filterAndRender();
            updateFolderUI();
        });

        var dropzone = modal.querySelector('.nb-imgmgr-dropzone');
        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('nb-imgmgr-dropzone--active');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('nb-imgmgr-dropzone--active');
            });
        });
        dropzone.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            if (files && files.length > 0) uploadFile(files[0]);
        });

        modal.querySelector('.nb-imgmgr-search').addEventListener('input', function (e) {
            state.search = e.target.value.toLowerCase().trim();
            filterAndRender();
        });

        modal.querySelectorAll('.nb-imgmgr-view-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                switchView(btn.dataset.view);
            });
        });

        modal.querySelectorAll('.nb-imgmgr-mode-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                switchMode(btn.dataset.mode);
            });
        });

        modal.querySelector('.nb-imgmgr-upload-type').addEventListener('change', function (e) {
            state.activeType = e.target.value;
            updateTypeUI();
            loadImages();
        });

        modal.querySelectorAll('.nb-imgmgr-list-header-col.sortable').forEach(function (col) {
            col.addEventListener('click', function () {
                sort(col.dataset.sort);
            });
        });

        // ESC key closes the top-most dialog (replace first, then manager itself).
        // Uses capture phase to intercept before any outer modal's keydown handler runs.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var replaceDialog = document.getElementById('nb-imgmgr-replace');
            var moveDialog = document.getElementById('nb-imgmgr-move');
            var lightbox = document.getElementById('nb-imgmgr-lightbox');
            if (replaceDialog && replaceDialog.classList.contains('active')) {
                e.stopPropagation();
                e.preventDefault();
                closeReplaceDialog();
            } else if (moveDialog && moveDialog.classList.contains('active')) {
                e.stopPropagation();
                e.preventDefault();
                closeMoveDialog();
            } else if (lightbox && lightbox.classList.contains('active')) {
                e.stopPropagation();
                e.preventDefault();
                closeLightbox();
            } else if (modal.classList.contains('active')) {
                e.stopPropagation();
                e.preventDefault();
                close();
            }
        }, true); // capture phase

        createLightbox();
        createReplaceDialog();
        createMoveDialog();
    }

    // ============================================================
    // OPEN / CLOSE
    // ============================================================
    function open(callback, currentPath, options) {
        options = options || {};
        createModal();
        var modal = document.getElementById('nb-imgmgr-modal');
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
        modal.classList.remove('nb-imgmgr-modal--embedded');
        state.callback = typeof callback === 'function' ? callback : null;
        state.isPicker = typeof callback === 'function';
        state.multiplePicker = !!options.multiple;
        state.allowedTypes = normalizeTypes(options.types || (state.isPicker ? ['image'] : ['image', 'audio', 'video', 'document']));
        state.activeType = options.type && state.allowedTypes.indexOf(options.type) !== -1
            ? options.type
            : (state.allowedTypes.length > 1 && !state.isPicker ? 'all' : state.allowedTypes[0]);
        state.selectedPaths = state.multiplePicker && Array.isArray(currentPath)
            ? currentPath.map(normalizeImagePath).filter(Boolean)
            : [];
        state.selectedPath = state.multiplePicker ? null : normalizeImagePath(currentPath);
        state.search = '';
        state.mode = 'library';
        state.folderFilter = 'all';

        modal.querySelector('.nb-imgmgr-search').value = '';
        renderTypeControls();
        updateModeUI();
        updateTypeUI();

        loadImages();
        updateSelectionUI();

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        previousFocus = document.activeElement;
        setTimeout(function () {
            var search = modal.querySelector('.nb-imgmgr-search');
            if (search) search.focus();
        }, 0);
    }

    function mount(container, options) {
        if (!container) return;
        options = options || {};
        createModal();
        var modal = document.getElementById('nb-imgmgr-modal');
        if (modal.parentElement !== container) {
            container.appendChild(modal);
        }
        modal.classList.add('nb-imgmgr-modal--embedded');

        state.callback = null;
        state.multiplePicker = false;
        state.isPicker = false;
        state.allowedTypes = normalizeTypes(options.types || ['image', 'audio', 'video', 'document']);
        state.activeType = options.type && state.allowedTypes.indexOf(options.type) !== -1
            ? options.type
            : (state.allowedTypes.length > 1 ? 'all' : state.allowedTypes[0]);
        state.selectedPath = null;
        state.search = '';
        state.mode = 'library';
        state.folderFilter = 'all';

        modal.querySelector('.nb-imgmgr-search').value = '';
        renderTypeControls();
        updateModeUI();
        updateTypeUI();

        loadImages();
        updateSelectionUI();

        modal.classList.add('active');
    }

    function normalizeImagePath(path) {
        if (!path) return null;
        path = String(path).trim();
        if (!path) return null;
        // Strip leading "../" segments and ensure leading "/" so we match state.data paths.
        path = path.replace(/^(\.\.\/)+/, '/');
        if (path.charAt(0) !== '/') path = '/' + path;
        return path;
    }

    function close() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (modal) modal.classList.remove('active');
        document.body.style.overflow = '';
        state.callback = null;
        if (previousFocus && document.contains(previousFocus)) previousFocus.focus();
        previousFocus = null;
    }

    function confirmSelection() {
        if (typeof state.callback === 'function') {
            if (state.multiplePicker && state.selectedPaths.length) {
                state.callback(state.selectedPaths.slice());
            } else if (state.selectedPath) {
                state.callback(state.selectedPath);
            }
        }
        close();
    }

    function renderTypeControls() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (!modal) return;

        var typeToggle = modal.querySelector('.nb-imgmgr-type-toggle');
        var uploadType = modal.querySelector('.nb-imgmgr-upload-type');
        var types = state.allowedTypes.slice();

        typeToggle.innerHTML = '';
        if (types.length > 1 && !state.isPicker) {
            var allBtn = document.createElement('button');
            allBtn.type = 'button';
            allBtn.className = 'nb-imgmgr-type-btn';
            allBtn.dataset.type = 'all';
            allBtn.textContent = t('media.type_all');
            typeToggle.appendChild(allBtn);
        }

        types.forEach(function (type) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'nb-imgmgr-type-btn';
            btn.dataset.type = type;
            btn.textContent = mediaTypeLabel(type);
            typeToggle.appendChild(btn);
        });

        typeToggle.querySelectorAll('.nb-imgmgr-type-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                state.activeType = btn.dataset.type;
                updateTypeUI();
                loadImages();
            });
        });

        uploadType.innerHTML = '';
        types.forEach(function (type) {
            var option = document.createElement('option');
            option.value = type;
            option.textContent = mediaTypeLabel(type);
            uploadType.appendChild(option);
        });
    }

    function updateTypeUI() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (!modal) return;

        var uploadType = modal.querySelector('.nb-imgmgr-upload-type');
        var uploadInput = modal.querySelector('.nb-imgmgr-upload-input');
        var uploadLabel = modal.querySelector('.nb-imgmgr-upload-btn span');
        var confirmBtn = modal.querySelector('[data-action="confirm"]');
        var formats = modal.querySelector('.nb-imgmgr-formats');
        var uploadTypeValue = getActiveUploadType();
        var typeConfig = mediaTypes[uploadTypeValue] || mediaTypes.image;

        modal.querySelectorAll('.nb-imgmgr-type-btn').forEach(function (btn) {
            btn.classList.toggle('nb-imgmgr-type-btn--active', btn.dataset.type === state.activeType);
        });

        uploadType.hidden = state.allowedTypes.length <= 1;
        uploadType.value = uploadTypeValue;
        uploadInput.accept = typeConfig.accept;
        uploadLabel.textContent = t(typeConfig.uploadLabelKey);
        if (confirmBtn) {
            confirmBtn.textContent = t(uploadTypeValue === 'image' ? 'image.select' : (uploadTypeValue === 'audio' ? 'audio.select' : 'media.select_file'));
        }
        formats.textContent = t(typeConfig.formatsKey);
        var title = modal.querySelector('.nb-imgmgr-header h3');
        if (title) title.textContent = t(isImageOnlyPicker() ? 'image_manager' : 'media_manager');
        updateGeneratorButton();
        updateFolderUI();
    }

    function updateGeneratorButton() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (!modal) return;
        var button = modal.querySelector('.nb-imgmgr-ai-generator-btn');
        var dropzone = modal.querySelector('.nb-imgmgr-dropzone');
        if (!button || !dropzone) return;
        var visible = !state.isPicker && state.mode !== 'trash' && getActiveUploadType() === 'image' && canOpenImageGenerator();
        button.hidden = !visible;
        dropzone.classList.toggle('nb-imgmgr-dropzone--with-ai', visible);
    }

    function folderItemCount(folderValue) {
        return state.data.reduce(function(total, item) {
            return total + ((item.folder || '') === folderValue ? 1 : 0);
        }, 0);
    }

    function updateFolderUI() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (!modal) return;
        var sidebar = modal.querySelector('.nb-imgmgr-sidebar');
        var list = modal.querySelector('.nb-imgmgr-folder-list');
        if (!sidebar || !list) return;

        // The folder sidebar is a library-only concept; hide it in trash mode.
        var isLibrary = state.mode === 'library';
        sidebar.hidden = !isLibrary;
        if (!isLibrary) return;

        var current = state.folderFilter || 'all';
        if (current !== 'all' && current !== '' && state.folders.indexOf(current) === -1) {
            current = 'all';
            state.folderFilter = current;
        }

        var entries = [
            { value: 'all', label: t('media.folder_all'), icon: Icons.layers || Icons.folder, deletable: false },
            { value: '', label: t('media.folder_root'), icon: Icons.folder, deletable: false }
        ].concat(state.folders.map(function(folder) {
            return { value: folder, label: folder, icon: Icons.folder, deletable: true };
        }));

        list.innerHTML = entries.map(function(entry) {
            var active = entry.value === current;
            var count = entry.value === 'all' ? state.data.length : folderItemCount(entry.value);
            return '<div class="nb-imgmgr-folder-item' + (active ? ' is-active' : '') + '"' +
                ' role="option" tabindex="0" aria-selected="' + (active ? 'true' : 'false') + '"' +
                ' data-folder="' + escapeHtml(entry.value) + '">' +
                '<span class="nb-imgmgr-folder-item-icon">' + (entry.icon || '') + '</span>' +
                '<span class="nb-imgmgr-folder-item-label">' + escapeHtml(entry.label) + '</span>' +
                '<span class="nb-imgmgr-folder-item-count">' + count + '</span>' +
                (entry.deletable
                    ? '<button type="button" class="nb-imgmgr-folder-item-delete" title="' + escapeHtml(t('media.folder_delete')) + '" aria-label="' + escapeHtml(t('media.folder_delete')) + '">' + Icons.delete + '</button>'
                    : '') +
                '</div>';
        }).join('');
    }

    // ============================================================
    // LOAD / RENDER
    // ============================================================
    function loadImages() {
        var modal = document.getElementById('nb-imgmgr-modal');
        var gridEl = modal.querySelector('.nb-imgmgr-grid');
        var listBody = modal.querySelector('.nb-imgmgr-list-body');
        var typeParam = state.activeType === 'all' ? state.allowedTypes.join(',') : state.activeType;
        var url = config.apiUrl + '?action=list-media&type=' + encodeURIComponent(typeParam) + '&trash=' + (state.mode === 'trash' ? '1' : '0') + '&_=' + Date.now();

        gridEl.innerHTML = '<p class="nb-imgmgr-loading">' + escapeHtml(t('image.loading')) + '</p>';
        listBody.innerHTML = '';

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (result) {
                var items = result.success && result.data
                    ? (Array.isArray(result.data) ? result.data : (result.data.items || []))
                    : [];
                state.folders = result.success && result.data && Array.isArray(result.data.folders) ? result.data.folders : [];
                updateFolderUI();
                if (items.length > 0) {
                    state.data = items.map(function (img) {
                        return Object.assign({}, img, {
                            type: img.type || 'image',
                            folder: img.folder || '',
                            basename: img.basename || img.name || '',
                            path: img.path.indexOf('api.php?') === 0 ? img.path : img.path.replace(/^\.\.\//, '/')
                        });
                    });
                    updateSortHeaderClasses();
                    applySortOrder();
                    filterAndRender();
                } else {
                    state.data = [];
                    state.filtered = [];
                    var emptyKey = state.mode === 'trash' ? (isImageOnlyPicker() ? 'image.trash_empty' : 'media.trash_empty') : 'media.no_files';
                    var empty = '<p class="nb-imgmgr-empty">' + escapeHtml(t(emptyKey)) + '</p>';
                    gridEl.innerHTML = empty;
                    listBody.innerHTML = empty;
                }
            })
            .catch(function (err) {
                gridEl.innerHTML = '<p class="nb-imgmgr-error">' + escapeHtml(err.message) + '</p>';
            });
    }

    function updateSortHeaderClasses() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (!modal) return;
        modal.querySelectorAll('.nb-imgmgr-list-header-col.sortable').forEach(function (col) {
            col.classList.remove('sorted-asc', 'sorted-desc');
            if (col.dataset.sort === state.sort.field) {
                col.classList.add(state.sort.dir === 'asc' ? 'sorted-asc' : 'sorted-desc');
            }
        });
    }

    function applySortOrder() {
        state.data.sort(function (a, b) {
            var va, vb;
            switch (state.sort.field) {
                case 'name': va = (a.name || '').toLowerCase(); vb = (b.name || '').toLowerCase(); break;
                case 'size': va = a.sizeBytes || 0; vb = b.sizeBytes || 0; break;
                case 'date': va = a.modified || 0; vb = b.modified || 0; break;
                default: return 0;
            }
            if (va < vb) return state.sort.dir === 'asc' ? -1 : 1;
            if (va > vb) return state.sort.dir === 'asc' ? 1 : -1;
            return 0;
        });
    }

    function filterAndRender() {
        state.filtered = state.data.filter(function (img) {
            var folder = img.folder || '';
            var matchesFolder = state.folderFilter === 'all' || folder === state.folderFilter;
            var matchesSearch = !state.search || ((img.name || '') + ' ' + (img.basename || '') + ' ' + folder).toLowerCase().indexOf(state.search) !== -1;
            return matchesFolder && matchesSearch;
        });
        render();
    }

    function render() {
        if (state.view === 'grid') renderGrid();
        else renderList();
    }

    function renderGrid() {
        var modal = document.getElementById('nb-imgmgr-modal');
        var gridEl = modal.querySelector('.nb-imgmgr-grid');

        if (state.filtered.length === 0) {
            var msg = state.search
                ? t('image.no_search_results', { term: state.search })
                : t('media.no_files');
            gridEl.innerHTML = '<p class="nb-imgmgr-empty">' + escapeHtml(msg) + '</p>';
            return;
        }

        gridEl.innerHTML = '';
        state.filtered.forEach(function (image) {
            var item = document.createElement('div');
            var isSelected = state.isPicker && isSelectedPath(image.path);
            item.className = 'nb-imgmgr-item' + (isSelected ? ' selected' : '');
            item.dataset.path = image.path;
            item.setAttribute('role', 'listitem');
            item.setAttribute('aria-label', image.name || image.path || t('media.file'));
            item.innerHTML = state.mode === 'trash'
                ? mediaThumbHtml(image, 'nb-imgmgr-thumb', true) +
                mediaNameHtml(image, 'nb-imgmgr-name') +
                '<div class="nb-imgmgr-actions">' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="preview" title="' + escapeHtml(t('image_preview')) + '" aria-label="' + escapeHtml(t('image_preview')) + '">' + Icons.eye + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="restore" title="' + escapeHtml(t('image.restore')) + '" aria-label="' + escapeHtml(t('image.restore')) + '">' + Icons.restore + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn nb-imgmgr-action-btn--danger" data-action="delete-permanent" title="' + escapeHtml(t('image.delete_permanently')) + '" aria-label="' + escapeHtml(t('image.delete_permanently')) + '">' + Icons.delete + '</button>' +
                '</div>'
                :
                mediaThumbHtml(image, 'nb-imgmgr-thumb', true) +
                mediaNameHtml(image, 'nb-imgmgr-name') +
                '<div class="nb-imgmgr-actions">' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="preview" title="' + escapeHtml(t('image_preview')) + '" aria-label="' + escapeHtml(t('image_preview')) + '">' + Icons.eye + '</button>' +
                    mediaDownloadActionHtml(image) +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="copy" title="' + escapeHtml(t('image.copy_path')) + '" aria-label="' + escapeHtml(t('image.copy_path')) + '">' + Icons.copy + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="move" title="' + escapeHtml(t('media.move_file')) + '" aria-label="' + escapeHtml(t('media.move_file')) + '">' + Icons.move + '</button>' +
                    (isImage(image) ? '<button type="button" class="nb-imgmgr-action-btn" data-action="replace" title="' + escapeHtml(t('image.replace')) + '" aria-label="' + escapeHtml(t('image.replace')) + '">' + Icons.replace + '</button>' : '') +
                    '<button type="button" class="nb-imgmgr-action-btn nb-imgmgr-action-btn--danger" data-action="delete" title="' + escapeHtml(t('delete')) + '" aria-label="' + escapeHtml(t('delete')) + '">' + Icons.delete + '</button>' +
                '</div>';
            if (state.mode !== 'trash' && state.isPicker) {
                item.insertAdjacentHTML('afterbegin', '<div class="nb-imgmgr-check' + (isSelected ? ' checked' : '') + '"></div>');
            }
            gridEl.appendChild(item);
            attachItemEvents(item, image);
        });
    }

    function renderList() {
        var modal = document.getElementById('nb-imgmgr-modal');
        var listBody = modal.querySelector('.nb-imgmgr-list-body');

        if (state.filtered.length === 0) {
            var msg = state.search
                ? t('image.no_search_results', { term: state.search })
                : t('media.no_files');
            listBody.innerHTML = '<p class="nb-imgmgr-empty">' + escapeHtml(msg) + '</p>';
            return;
        }

        listBody.innerHTML = '';
        state.filtered.forEach(function (image) {
            appendMediaRow(listBody, image);
        });
    }

    function appendMediaRow(listBody, image) {
            var row = document.createElement('div');
            var isSelected = state.isPicker && isSelectedPath(image.path);
            row.className = 'nb-imgmgr-row' + (isSelected ? ' selected' : '');
            if (image.folder) row.classList.add('nb-imgmgr-row--child');
            row.dataset.path = image.path;
            row.setAttribute('aria-label', image.name || image.path || t('media.file'));
            row.innerHTML = state.mode === 'trash'
                ? '<div></div>' +
                mediaThumbHtml(image, 'nb-imgmgr-list-thumb', true) +
                mediaNameHtml(image, 'nb-imgmgr-list-name') +
                '<div class="nb-imgmgr-list-size">' + escapeHtml(image.size || '-') + '</div>' +
                '<div class="nb-imgmgr-list-date">' + escapeHtml(image.dateFormatted || '-') + '</div>' +
                '<div class="nb-imgmgr-list-actions">' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="preview" title="' + escapeHtml(t('image_preview')) + '" aria-label="' + escapeHtml(t('image_preview')) + '">' + Icons.eye + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="restore" title="' + escapeHtml(t('image.restore')) + '" aria-label="' + escapeHtml(t('image.restore')) + '">' + Icons.restore + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn nb-imgmgr-action-btn--danger" data-action="delete-permanent" title="' + escapeHtml(t('image.delete_permanently')) + '" aria-label="' + escapeHtml(t('image.delete_permanently')) + '">' + Icons.delete + '</button>' +
                '</div>'
                :
                '<div></div>' +
                mediaThumbHtml(image, 'nb-imgmgr-list-thumb', true) +
                mediaNameHtml(image, 'nb-imgmgr-list-name') +
                '<div class="nb-imgmgr-list-size">' + escapeHtml(image.size || '-') + '</div>' +
                '<div class="nb-imgmgr-list-date">' + escapeHtml(image.dateFormatted || '-') + '</div>' +
                '<div class="nb-imgmgr-list-actions">' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="preview" title="' + escapeHtml(t('image_preview')) + '" aria-label="' + escapeHtml(t('image_preview')) + '">' + Icons.eye + '</button>' +
                    mediaDownloadActionHtml(image) +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="copy" title="' + escapeHtml(t('image.copy_path')) + '" aria-label="' + escapeHtml(t('image.copy_path')) + '">' + Icons.copy + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="move" title="' + escapeHtml(t('media.move_file')) + '" aria-label="' + escapeHtml(t('media.move_file')) + '">' + Icons.move + '</button>' +
                    (isImage(image) ? '<button type="button" class="nb-imgmgr-action-btn" data-action="replace" title="' + escapeHtml(t('image.replace')) + '" aria-label="' + escapeHtml(t('image.replace')) + '">' + Icons.replace + '</button>' : '') +
                    '<button type="button" class="nb-imgmgr-action-btn nb-imgmgr-action-btn--danger" data-action="delete" title="' + escapeHtml(t('delete')) + '" aria-label="' + escapeHtml(t('delete')) + '">' + Icons.delete + '</button>' +
                '</div>';
            if (state.mode !== 'trash' && state.isPicker) {
                row.querySelector('div:first-child').outerHTML = '<div class="nb-imgmgr-check nb-imgmgr-check--list' + (isSelected ? ' checked' : '') + '"></div>';
            }
            listBody.appendChild(row);
            attachItemEvents(row, image);
    }

    function attachItemEvents(element, image) {
        var check = element.querySelector('.nb-imgmgr-check');
        if (check) {
            check.addEventListener('click', function (e) {
                e.stopPropagation();
                toggleSelection(image.path);
            });
        }

        var previewThumb = element.querySelector('.nb-imgmgr-thumb-btn');
        if (previewThumb) {
            previewThumb.addEventListener('click', function (e) {
                e.stopPropagation();
                openLightbox(image);
            });
        }

        element.addEventListener('click', function (e) {
            var actionEl = e.target.closest('[data-action]');
            if (actionEl) {
                e.stopPropagation();
                var action = actionEl.dataset.action;
                if (action === 'preview') openLightbox(image);
                else if (action === 'download') return;
                else if (action === 'copy') copyPath(image.path);
                else if (action === 'move') openMoveDialog(image);
                else if (action === 'replace') openReplaceDialog(image.name, image.path);
                else if (action === 'delete') deleteMedia(image);
                else if (action === 'restore') restoreMedia(image);
                else if (action === 'delete-permanent') deleteMediaPermanently(image);
                return;
            }
            // Click elsewhere on item = toggle selection
            if (state.mode !== 'trash' && state.isPicker) toggleSelection(image.path);
        });
    }

    function isSelectedPath(path) {
        return state.multiplePicker
            ? state.selectedPaths.indexOf(path) !== -1
            : state.selectedPath === path;
    }

    function toggleSelection(path) {
        if (state.multiplePicker) {
            var idx = state.selectedPaths.indexOf(path);
            if (idx === -1) {
                state.selectedPaths.push(path);
            } else {
                state.selectedPaths.splice(idx, 1);
            }
        } else {
            state.selectedPath = state.selectedPath === path ? null : path;
        }
        updateSelectionUI();
    }

    function updateSelectionUI() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (!modal) return;

        modal.querySelectorAll('.nb-imgmgr-item, .nb-imgmgr-row').forEach(function (item) {
            var path = item.dataset.path;
            var isSelected = isSelectedPath(path);
            item.classList.toggle('selected', isSelected);
            var check = item.querySelector('.nb-imgmgr-check');
            if (check) check.classList.toggle('checked', isSelected);
        });

        var info = modal.querySelector('.nb-imgmgr-selection-info');
        var confirmBtn = modal.querySelector('[data-action="confirm"]');

        if (!state.isPicker) {
            info.textContent = '';
            info.classList.remove('has-selection');
            confirmBtn.hidden = true;
            confirmBtn.disabled = true;
            return;
        }

        if (state.mode === 'trash') {
            info.textContent = t('image.trash_hint');
            info.classList.remove('has-selection');
            confirmBtn.hidden = true;
            confirmBtn.disabled = true;
            return;
        }

        confirmBtn.hidden = false;
        var selectedCount = state.multiplePicker ? state.selectedPaths.length : (state.selectedPath ? 1 : 0);
        if (selectedCount) {
            info.textContent = state.multiplePicker
                ? t('media.selection_count', { count: selectedCount })
                : state.selectedPath;
            info.classList.add('has-selection');
            confirmBtn.disabled = false;
        } else {
            info.textContent = t(isImageOnlyPicker() ? 'image.no_selection' : 'media.no_selection');
            info.classList.remove('has-selection');
            confirmBtn.disabled = true;
        }
    }

    function updateModeUI() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (!modal) return;

        modal.querySelectorAll('.nb-imgmgr-mode-btn').forEach(function (btn) {
            btn.classList.toggle('nb-imgmgr-mode-btn--active', btn.dataset.mode === state.mode);
            btn.setAttribute('aria-pressed', btn.dataset.mode === state.mode ? 'true' : 'false');
        });

        var dropzone = modal.querySelector('.nb-imgmgr-dropzone');
        var emptyBtn = modal.querySelector('.nb-imgmgr-empty-trash-btn');
        if (dropzone) dropzone.hidden = state.mode === 'trash';
        if (emptyBtn) emptyBtn.hidden = state.mode !== 'trash';
        updateGeneratorButton();

        if (state.mode === 'trash') {
            state.selectedPath = null;
            state.folderFilter = 'all';
        }

        updateFolderUI();
        updateSelectionUI();
    }

    // ============================================================
    // VIEW / SORT
    // ============================================================
    function switchMode(mode) {
        if (mode !== 'library' && mode !== 'trash') return;
        if (state.mode === mode) return;

        state.mode = mode;
        state.search = '';
        var modal = document.getElementById('nb-imgmgr-modal');
        modal.querySelector('.nb-imgmgr-search').value = '';
        updateModeUI();
        loadImages();
    }

    function switchView(view) {
        state.view = view;
        var modal = document.getElementById('nb-imgmgr-modal');
        modal.querySelectorAll('.nb-imgmgr-view-btn').forEach(function (btn) {
            btn.classList.toggle('nb-imgmgr-view-btn--active', btn.dataset.view === view);
            btn.setAttribute('aria-pressed', btn.dataset.view === view ? 'true' : 'false');
        });
        var gridEl = modal.querySelector('.nb-imgmgr-grid');
        var listEl = modal.querySelector('.nb-imgmgr-list');
        if (view === 'grid') {
            gridEl.classList.remove('hidden');
            listEl.classList.remove('active');
        } else {
            gridEl.classList.add('hidden');
            listEl.classList.add('active');
        }
        render();
    }

    function sort(field) {
        if (state.sort.field === field) {
            state.sort.dir = state.sort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sort.field = field;
            state.sort.dir = 'asc';
        }
        updateSortHeaderClasses();
        applySortOrder();
        filterAndRender();
    }

    // ============================================================
    // ACTIONS
    // ============================================================
    function copyPath(path) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(path).then(function () {
                config.showToast(t('toast.copied'), 'success');
            }).catch(function () {
                fallbackCopy(path);
            });
        } else {
            fallbackCopy(path);
        }
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); config.showToast(t('toast.copied'), 'success'); }
        catch (e) { config.showToast(t('toast.error'), 'error'); }
        document.body.removeChild(ta);
    }

    function deleteMedia(item) {
        if (!item || !item.name) return;
        var formData = new FormData();
        formData.append('action', 'delete-media');
        formData.append('type', item.type || 'image');
        formData.append('filename', item.name);
        formData.append('csrf_token', config.csrfToken);

        fetch(config.apiUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    config.showToast(t('image.trashed'), 'success');
                    if (state.selectedPath === item.path || (state.selectedPath && state.selectedPath.indexOf('/' + item.name) !== -1)) {
                        state.selectedPath = null;
                    }
                    state.selectedPaths = state.selectedPaths.filter(function(path) { return path !== item.path; });
                    loadImages();
                } else {
                    config.showToast(result.message || t('toast.error'), 'error');
                }
            })
            .catch(function (err) {
                config.showToast(err.message || t('toast.error'), 'error');
            });
    }

    function restoreMedia(item) {
        if (!item || !item.name) return;
        var formData = new FormData();
        formData.append('action', 'restore-media');
        formData.append('type', item.type || 'image');
        formData.append('filename', item.name);
        formData.append('csrf_token', config.csrfToken);

        fetch(config.apiUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    config.showToast(t('image.restored'), 'success');
                    loadImages();
                } else {
                    config.showToast(result.message || t('toast.error'), 'error');
                }
            })
            .catch(function (err) {
                config.showToast(err.message || t('toast.error'), 'error');
            });
    }

    function deleteMediaPermanently(item) {
        if (!item || !item.name) return;
        confirmAction(
            t('image.delete_permanently'),
            t('image.delete_permanently_confirm', { filename: item.name }),
            function () {
                var formData = new FormData();
                formData.append('action', 'delete-media-trash');
                formData.append('type', item.type || 'image');
                formData.append('filename', item.name);
                formData.append('csrf_token', config.csrfToken);

                fetch(config.apiUrl, { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (result) {
                        if (result.success) {
                            config.showToast(t('image.deleted_permanently'), 'success');
                            loadImages();
                        } else {
                            config.showToast(result.message || t('toast.error'), 'error');
                        }
                    })
                    .catch(function (err) {
                        config.showToast(err.message || t('toast.error'), 'error');
                    });
            }
        );
    }

    // In-app name prompt: window.prompt() is unavailable in embedded
    // webviews (Electron/VS Code) and clashes with the UI style anyway.
    function promptFolderName(onSubmit) {
        var existing = document.getElementById('nb-imgmgr-folder-prompt');
        if (existing) existing.remove();
        var dialog = document.createElement('div');
        dialog.id = 'nb-imgmgr-folder-prompt';
        dialog.className = 'nb-imgmgr-move active';
        dialog.innerHTML =
            '<div class="nb-imgmgr-move-backdrop"></div>' +
            '<div class="nb-imgmgr-move-dialog">' +
                '<div class="nb-imgmgr-move-header">' +
                    '<h3>' + escapeHtml(t('media.folder_create')) + '</h3>' +
                    '<button type="button" class="nb-imgmgr-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="nb-imgmgr-move-body">' +
                    '<label>' + escapeHtml(t('media.folder_create_prompt')) + '</label>' +
                    '<input type="text" class="nb-imgmgr-folder-name-input" maxlength="60" spellcheck="false">' +
                '</div>' +
                '<div class="nb-imgmgr-move-footer">' +
                    '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--secondary" data-action="cancel">' + escapeHtml(t('cancel')) + '</button>' +
                    '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--primary" data-action="submit">' + escapeHtml(t('media.folder_create')) + '</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(dialog);
        var input = dialog.querySelector('.nb-imgmgr-folder-name-input');
        var close = function () { dialog.remove(); };
        var submit = function () {
            var name = input.value.trim();
            if (!name) { input.focus(); return; }
            close();
            onSubmit(name);
        };
        dialog.querySelector('.nb-imgmgr-move-backdrop').addEventListener('click', close);
        dialog.querySelector('.nb-imgmgr-close').addEventListener('click', close);
        dialog.querySelector('[data-action="cancel"]').addEventListener('click', close);
        dialog.querySelector('[data-action="submit"]').addEventListener('click', submit);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); submit(); }
            if (event.key === 'Escape') { event.stopPropagation(); close(); }
        });
        input.focus();
    }

    function createMediaFolder() {
        if (state.mode !== 'library') return;
        promptFolderName(submitCreateMediaFolder);
    }

    function submitCreateMediaFolder(name) {
        var formData = new FormData();
        formData.append('action', 'create-media-folder');
        formData.append('type', getActiveUploadType());
        formData.append('folder', name);
        formData.append('csrf_token', config.csrfToken);

        fetch(config.apiUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    config.showToast(t('media.folder_created'), 'success');
                    state.folderFilter = result.data && result.data.folder ? result.data.folder : name;
                    state.expandedFolders[state.folderFilter] = true;
                    loadImages();
                } else {
                    config.showToast(result.message || t('toast.error'), 'error');
                }
            })
            .catch(function (err) {
                config.showToast(err.message || t('toast.error'), 'error');
            });
    }

    function deleteMediaFolder(folder) {
        folder = (folder || '').trim();
        if (state.mode !== 'library' || !folder || folder === 'all') return;
        confirmAction(
            t('media.folder_delete'),
            t('media.folder_delete_confirm', { folder: folder }),
            function () {
                var formData = new FormData();
                formData.append('action', 'delete-media-folder');
                formData.append('type', getActiveUploadType());
                formData.append('folder', folder);
                formData.append('csrf_token', config.csrfToken);

                fetch(config.apiUrl, { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (result) {
                        if (result.success) {
                            config.showToast(t('media.folder_deleted'), 'success');
                            delete state.expandedFolders[folder];
                            if (state.folderFilter === folder) state.folderFilter = 'all';
                            loadImages();
                        } else {
                            config.showToast(result.message || t('toast.error'), 'error');
                        }
                    })
                    .catch(function (err) {
                        config.showToast(err.message || t('toast.error'), 'error');
                    });
            }
        );
    }

    function moveMedia(item, target) {
        if (!item || !item.name) return;
        target = (target || '').trim();
        if (target === (item.folder || '')) return;
        var formData = new FormData();
        formData.append('action', 'move-media');
        formData.append('type', item.type || 'image');
        formData.append('filename', item.name);
        formData.append('folder', target);
        formData.append('csrf_token', config.csrfToken);

        fetch(config.apiUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    config.showToast(t('media.file_moved'), 'success');
                    closeMoveDialog();
                    if (target) state.expandedFolders[target] = true;
                    loadImages();
                } else {
                    config.showToast(result.message || t('toast.error'), 'error');
                }
            })
            .catch(function (err) {
                config.showToast(err.message || t('toast.error'), 'error');
            });
    }

    function emptyImageTrash() {
        if (!state.data.length) {
            config.showToast(t(isImageOnlyPicker() ? 'image.trash_empty' : 'media.trash_empty'), 'info');
            return;
        }

        confirmAction(
            t('image.empty_trash'),
            t('image.empty_trash_confirm'),
            function () {
                var formData = new FormData();
                formData.append('action', 'empty-media-trash');
                formData.append('type', state.activeType === 'all' ? state.allowedTypes.join(',') : state.activeType);
                formData.append('csrf_token', config.csrfToken);

                fetch(config.apiUrl, { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (result) {
                        if (result.success) {
                            config.showToast(t('image.trash_emptied'), 'success');
                            loadImages();
                        } else {
                            config.showToast(result.message || t('toast.error'), 'error');
                        }
                    })
                    .catch(function (err) {
                        config.showToast(err.message || t('toast.error'), 'error');
                    });
            }
        );
    }

    function handleUpload(e) {
        var file = e.target.files[0];
        uploadFile(file);
        e.target.value = '';
    }

    function uploadFile(file) {
        if (!file) return;

        var type = getActiveUploadType();
        var typeConfig = mediaTypes[type] || mediaTypes.image;
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (typeConfig.extensions.indexOf(ext) === -1) {
            config.showToast(t(type === 'image' ? 'image.format_error' : 'media.format_error'), 'error');
            return;
        }
        if (file.size > typeConfig.maxSize) {
            config.showToast(t(type === 'image' ? 'image.size_error' : 'media.size_error'), 'error');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'upload-media');
        formData.append('type', type);
        formData.append('file', file);
        if (state.mode === 'library' && state.folderFilter && state.folderFilter !== 'all') {
            formData.append('folder', state.folderFilter);
        }
        formData.append('csrf_token', config.csrfToken);

        config.showToast(t('image.uploading'), 'info');

        fetch(config.apiUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    config.showToast(t('image.uploaded'), 'success');
                    state.sort = { field: 'date', dir: 'desc' };
                    if (state.isPicker) {
                        state.activeType = type;
                    }
                    loadImages();
                    if (result.data && result.data.path) {
                        var uploadedPath = result.data.path.replace(/^\.\.\//, '/');
                        if (state.multiplePicker) {
                            if (state.selectedPaths.indexOf(uploadedPath) === -1) state.selectedPaths.push(uploadedPath);
                        } else {
                            state.selectedPath = uploadedPath;
                        }
                        setTimeout(updateSelectionUI, 300);
                    }
                } else {
                    config.showToast(result.message || t('toast.error'), 'error');
                }
            })
            .catch(function (err) {
                config.showToast(err.message || t('toast.error'), 'error');
            });
    }

    // ============================================================
    // LIGHTBOX
    // ============================================================
    function createLightbox() {
        if (document.getElementById('nb-imgmgr-lightbox')) return;

        var lb = document.createElement('div');
        lb.id = 'nb-imgmgr-lightbox';
        lb.className = 'nb-imgmgr-lightbox';
        lb.innerHTML =
            '<div class="nb-imgmgr-lightbox-content">' +
                '<button type="button" class="nb-imgmgr-lightbox-close" aria-label="Close">&times;</button>' +
                '<button type="button" class="nb-imgmgr-lightbox-nav nb-imgmgr-lightbox-nav--prev" data-action="lightbox-prev" aria-label="' + escapeHtml(t('media.previous_image')) + '">' + Icons.chevronLeft + '</button>' +
                '<button type="button" class="nb-imgmgr-lightbox-nav nb-imgmgr-lightbox-nav--next" data-action="lightbox-next" aria-label="' + escapeHtml(t('media.next_image')) + '">' + Icons.chevronRight + '</button>' +
                '<div class="nb-imgmgr-lightbox-stage"></div>' +
                '<div class="nb-imgmgr-lightbox-info"></div>' +
            '</div>';
        document.body.appendChild(lb);

        lb.addEventListener('click', function (e) {
            if (e.target === lb) closeLightbox();
        });
        lb.querySelector('.nb-imgmgr-lightbox-close').addEventListener('click', closeLightbox);
        lb.querySelector('[data-action="lightbox-prev"]').addEventListener('click', function(e) {
            e.stopPropagation();
            showAdjacentLightboxImage(-1);
        });
        lb.querySelector('[data-action="lightbox-next"]').addEventListener('click', function(e) {
            e.stopPropagation();
            showAdjacentLightboxImage(1);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && lb.classList.contains('active')) closeLightbox();
            if (lb.classList.contains('active') && e.key === 'ArrowLeft') {
                e.preventDefault();
                showAdjacentLightboxImage(-1);
            }
            if (lb.classList.contains('active') && e.key === 'ArrowRight') {
                e.preventDefault();
                showAdjacentLightboxImage(1);
            }
        });
    }

    function openLightbox(itemOrPath, name) {
        var item = typeof itemOrPath === 'object'
            ? itemOrPath
            : { type: 'image', path: itemOrPath, name: name || '' };
        state.lightboxItem = item;
        var lb = document.getElementById('nb-imgmgr-lightbox');
        if (!lb) { createLightbox(); lb = document.getElementById('nb-imgmgr-lightbox'); }
        var stage = lb.querySelector('.nb-imgmgr-lightbox-stage');
        var label = mediaDisplayName(item);
        stage.classList.toggle('nb-imgmgr-lightbox-stage--image', isImage(item));
        if (isImage(item)) {
            stage.innerHTML = '<img alt="" src="' + escapeHtml(item.path) + '">';
        } else if (isAudio(item)) {
            stage.innerHTML = '<div class="nb-imgmgr-lightbox-media nb-imgmgr-lightbox-media--audio">' +
                mediaIcon(item) +
                '<audio controls src="' + escapeHtml(item.path) + '"></audio>' +
            '</div>';
        } else if (isVideo(item)) {
            stage.innerHTML = '<video class="nb-imgmgr-lightbox-video" controls src="' + escapeHtml(item.path) + '"></video>';
        } else {
            stage.innerHTML = '<div class="nb-imgmgr-lightbox-media nb-imgmgr-lightbox-media--document">' +
                mediaIcon(item) +
                '<a href="' + escapeHtml(item.path) + '" target="_blank" rel="noopener">' + escapeHtml(t('media.open_file')) + '</a>' +
            '</div>';
        }
        lb.querySelector('.nb-imgmgr-lightbox-info').textContent = label;
        updateLightboxNav();
        lb.classList.add('active');
    }

    function lightboxImages() {
        var items = (state.filtered && state.filtered.length ? state.filtered : state.data).filter(isImage);
        return items.length ? items : (state.lightboxItem && isImage(state.lightboxItem) ? [state.lightboxItem] : []);
    }

    function updateLightboxNav() {
        var lb = document.getElementById('nb-imgmgr-lightbox');
        if (!lb) return;
        var items = lightboxImages();
        var canNavigate = items.length > 1 && state.lightboxItem && isImage(state.lightboxItem);
        lb.querySelectorAll('.nb-imgmgr-lightbox-nav').forEach(function(btn) {
            btn.hidden = !canNavigate;
        });
    }

    function showAdjacentLightboxImage(direction) {
        var items = lightboxImages();
        if (items.length <= 1 || !state.lightboxItem) return;
        var index = items.findIndex(function(item) { return item.path === state.lightboxItem.path; });
        if (index === -1) index = 0;
        var nextIndex = (index + direction + items.length) % items.length;
        openLightbox(items[nextIndex]);
    }

    function closeLightbox() {
        var lb = document.getElementById('nb-imgmgr-lightbox');
        if (lb) {
            lb.querySelectorAll('audio, video').forEach(function (media) { media.pause(); });
            lb.classList.remove('active');
        }
        state.lightboxItem = null;
    }

    // ============================================================
    // REPLACE DIALOG
    // ============================================================
    function createReplaceDialog() {
        if (document.getElementById('nb-imgmgr-replace')) return;

        var dialog = document.createElement('div');
        dialog.id = 'nb-imgmgr-replace';
        dialog.className = 'nb-imgmgr-replace';
        dialog.innerHTML =
            '<div class="nb-imgmgr-replace-backdrop"></div>' +
            '<div class="nb-imgmgr-replace-dialog">' +
                '<div class="nb-imgmgr-replace-header">' +
                    '<h3>' + escapeHtml(t('image.replace')) + '</h3>' +
                    '<button type="button" class="nb-imgmgr-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="nb-imgmgr-replace-body">' +
                    '<p style="margin-top:0">' + escapeHtml(t('image.replacing')) + ' <strong class="nb-imgmgr-replace-target"></strong></p>' +
                    '<div class="nb-imgmgr-replace-options">' +
                        '<label class="nb-imgmgr-replace-option selected" data-option="replace">' +
                            '<input type="radio" name="nb-replace-option" value="replace" checked>' +
                            '<div class="nb-imgmgr-replace-option-content">' +
                                '<div class="nb-imgmgr-replace-option-title">' + escapeHtml(t('image.overwrite_file')) + '</div>' +
                                '<div class="nb-imgmgr-replace-option-desc">' + escapeHtml(t('image.overwrite_desc')) + '</div>' +
                            '</div>' +
                        '</label>' +
                        '<label class="nb-imgmgr-replace-option" data-option="new">' +
                            '<input type="radio" name="nb-replace-option" value="new">' +
                            '<div class="nb-imgmgr-replace-option-content">' +
                                '<div class="nb-imgmgr-replace-option-title">' + escapeHtml(t('image.save_new_name')) + '</div>' +
                                '<div class="nb-imgmgr-replace-option-desc">' + escapeHtml(t('image.save_new_desc')) + '</div>' +
                            '</div>' +
                        '</label>' +
                    '</div>' +
                    '<div class="nb-imgmgr-replace-file">' +
                        '<label>' + Icons.upload + ' <span>' + escapeHtml(t('image.choose_file')) + '</span>' +
                            '<input type="file" accept=".jpg,.jpeg,.png,.webp">' +
                        '</label>' +
                        '<div class="nb-imgmgr-replace-filename"></div>' +
                    '</div>' +
                '</div>' +
                '<div class="nb-imgmgr-replace-footer">' +
                    '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--secondary" data-action="cancel">' + escapeHtml(t('cancel')) + '</button>' +
                    '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--primary" data-action="submit" disabled>' + escapeHtml(t('image.upload')) + '</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(dialog);

        dialog.querySelector('.nb-imgmgr-replace-backdrop').addEventListener('click', closeReplaceDialog);
        dialog.querySelector('.nb-imgmgr-close').addEventListener('click', closeReplaceDialog);
        dialog.querySelector('[data-action="cancel"]').addEventListener('click', closeReplaceDialog);
        dialog.querySelector('[data-action="submit"]').addEventListener('click', handleReplaceSubmit);

        dialog.querySelectorAll('.nb-imgmgr-replace-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                dialog.querySelectorAll('.nb-imgmgr-replace-option').forEach(function (o) {
                    o.classList.remove('selected');
                });
                opt.classList.add('selected');
                opt.querySelector('input[type="radio"]').checked = true;
            });
        });

        var fileInput = dialog.querySelector('input[type="file"]');
        fileInput.addEventListener('change', function (e) {
            var file = e.target.files[0];
            var filenameEl = dialog.querySelector('.nb-imgmgr-replace-filename');
            var submitBtn = dialog.querySelector('[data-action="submit"]');
            if (file) {
                filenameEl.textContent = file.name;
                submitBtn.disabled = false;
            } else {
                filenameEl.textContent = '';
                submitBtn.disabled = true;
            }
        });
    }

    function openReplaceDialog(filename, filepath) {
        replaceTarget = { name: filename, path: filepath };
        var dialog = document.getElementById('nb-imgmgr-replace');
        if (!dialog) { createReplaceDialog(); dialog = document.getElementById('nb-imgmgr-replace'); }

        dialog.querySelector('.nb-imgmgr-replace-target').textContent = filename;
        dialog.querySelector('input[type="file"]').value = '';
        dialog.querySelector('.nb-imgmgr-replace-filename').textContent = '';
        dialog.querySelector('[data-action="submit"]').disabled = true;

        dialog.querySelectorAll('.nb-imgmgr-replace-option').forEach(function (o, i) {
            o.classList.toggle('selected', i === 0);
        });
        dialog.querySelector('input[name="nb-replace-option"][value="replace"]').checked = true;

        dialog.classList.add('active');
    }

    function closeReplaceDialog() {
        var dialog = document.getElementById('nb-imgmgr-replace');
        if (dialog) dialog.classList.remove('active');
        replaceTarget = null;
    }

    function handleReplaceSubmit() {
        var dialog = document.getElementById('nb-imgmgr-replace');
        var fileInput = dialog.querySelector('input[type="file"]');
        var file = fileInput.files[0];
        if (!file || !replaceTarget) return;

        var allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (allowed.indexOf(file.type) === -1) {
            config.showToast(t('image.format_error'), 'error');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            config.showToast(t('image.size_error'), 'error');
            return;
        }

        var option = dialog.querySelector('input[name="nb-replace-option"]:checked').value;
        var targetFilename = replaceTarget.name;

        if (option === 'new') {
            targetFilename = file.name;
            var existing = state.data.find(function (img) {
                return (img.name || '').toLowerCase() === targetFilename.toLowerCase();
            });
            if (existing) {
                config.showToast(t('image.exists', { filename: targetFilename }), 'error');
                return;
            }
        }

        var formData = new FormData();
        formData.append('action', 'upload-image');
        formData.append('image', file);
        formData.append('filename', targetFilename);
        formData.append('replace', option === 'replace' ? '1' : '0');
        formData.append('csrf_token', config.csrfToken);

        config.showToast(t('image.uploading'), 'info');

        fetch(config.apiUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    config.showToast(option === 'replace' ? t('image.replaced') : t('image.uploaded'), 'success');
                    closeReplaceDialog();
                    state.sort = { field: 'date', dir: 'desc' };
                    loadImages();
                } else {
                    config.showToast(result.message || t('toast.error'), 'error');
                }
            })
            .catch(function (err) {
                config.showToast(err.message || t('toast.error'), 'error');
            });
    }

    // ============================================================
    // MOVE DIALOG
    // ============================================================
    function createMoveDialog() {
        if (document.getElementById('nb-imgmgr-move')) return;

        var dialog = document.createElement('div');
        dialog.id = 'nb-imgmgr-move';
        dialog.className = 'nb-imgmgr-move';
        dialog.innerHTML =
            '<div class="nb-imgmgr-move-backdrop"></div>' +
            '<div class="nb-imgmgr-move-dialog">' +
                '<div class="nb-imgmgr-move-header">' +
                    '<h3>' + escapeHtml(t('media.move_file')) + '</h3>' +
                    '<button type="button" class="nb-imgmgr-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="nb-imgmgr-move-body">' +
                    '<p class="nb-imgmgr-move-filename"></p>' +
                    '<label>' + escapeHtml(t('media.folder')) + '</label>' +
                    '<select class="nb-imgmgr-move-select"></select>' +
                '</div>' +
                '<div class="nb-imgmgr-move-footer">' +
                    '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--secondary" data-action="cancel">' + escapeHtml(t('cancel')) + '</button>' +
                    '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--primary" data-action="submit">' + escapeHtml(t('media.move_file')) + '</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(dialog);

        dialog.querySelector('.nb-imgmgr-move-backdrop').addEventListener('click', closeMoveDialog);
        dialog.querySelector('.nb-imgmgr-close').addEventListener('click', closeMoveDialog);
        dialog.querySelector('[data-action="cancel"]').addEventListener('click', closeMoveDialog);
        dialog.querySelector('[data-action="submit"]').addEventListener('click', function () {
            var select = dialog.querySelector('.nb-imgmgr-move-select');
            moveMedia(moveTarget, select ? select.value : '');
        });
    }

    function openMoveDialog(item) {
        moveTarget = item;
        var dialog = document.getElementById('nb-imgmgr-move');
        if (!dialog) { createMoveDialog(); dialog = document.getElementById('nb-imgmgr-move'); }
        dialog.querySelector('.nb-imgmgr-move-filename').textContent = mediaDisplayName(item);
        var select = dialog.querySelector('.nb-imgmgr-move-select');
        select.innerHTML = '<option value="">' + escapeHtml(t('media.folder_root')) + '</option>' +
            state.folders.map(function(folder) {
                return '<option value="' + escapeHtml(folder) + '">' + escapeHtml(folder) + '</option>';
            }).join('');
        select.value = item.folder || '';
        dialog.classList.add('active');
        select.focus();
    }

    function closeMoveDialog() {
        var dialog = document.getElementById('nb-imgmgr-move');
        if (dialog) dialog.classList.remove('active');
        moveTarget = null;
    }

    // ============================================================
    // PUBLIC API
    // ============================================================
    window.NbImageManager = {
        init: function (options) {
            Object.assign(config, options || {});
        },
        open: open,
        mount: mount,
        preview: function (path, name) {
            var item = state.data.find(function (entry) { return entry.path === path; });
            openLightbox(item || path, name || path);
        },
        close: close,
        confirmSelection: confirmSelection,
        refresh: function () {
            updateModeUI();
            updateTypeUI();
        },
    };
})();
