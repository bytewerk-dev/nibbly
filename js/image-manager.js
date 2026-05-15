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
    };

    // ============================================================
    // STATE
    // ============================================================
    var state = {
        data: [],
        filtered: [],
        selectedPath: null,
        callback: null,
        isPicker: false,
        allowedTypes: ['image'],
        activeType: 'image',
        view: 'grid',
        mode: 'library',
        sort: { field: 'date', dir: 'desc' },
        search: '',
    };

    var replaceTarget = null;
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
        replace: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
        delete: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        restore: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
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
        } else if (window.confirm((title ? title + '\n\n' : '') + message)) {
            onYes();
        }
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

    function mediaIcon(item) {
        if (isAudio(item)) return '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3v10.5A4 4 0 1 0 14 17V7h4V3h-6z"/></svg>';
        if (isVideo(item)) return '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 5h12a2 2 0 0 1 2 2v1.2l4-2.2v12l-4-2.2V17a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>';
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8M8 9h2"/></svg>';
    }

    function mediaThumbHtml(item, className, previewAction) {
        var actionAttr = previewAction ? ' data-action="preview"' : '';
        if (isImage(item)) {
            return '<div class="' + className + '" style="background-image:url(\'' + escapeHtml(item.path) + '\')"' + actionAttr + '></div>';
        }
        return '<div class="' + className + ' nb-imgmgr-media-icon nb-imgmgr-media-icon--' + escapeHtml(item.type || 'document') + '"' + actionAttr + '>' + mediaIcon(item) + '</div>';
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
                '<div class="nb-imgmgr-dropzone">' +
                    '<span class="nb-imgmgr-dropzone-text">' + escapeHtml(t('image.drop_files')) + '</span>' +
                    '<span class="nb-imgmgr-dropzone-or">' + escapeHtml(t('image.or')) + '</span>' +
                    '<select class="nb-imgmgr-upload-type"></select>' +
                    '<label class="nb-imgmgr-upload-btn">' +
                        Icons.upload + ' <span>' + escapeHtml(t('image.upload')) + '</span>' +
                        '<input type="file" class="nb-imgmgr-upload-input" accept=".jpg,.jpeg,.png,.webp">' +
                    '</label>' +
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
            var lightbox = document.getElementById('nb-imgmgr-lightbox');
            if (replaceDialog && replaceDialog.classList.contains('active')) {
                e.stopPropagation();
                e.preventDefault();
                closeReplaceDialog();
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
    }

    // ============================================================
    // OPEN / CLOSE
    // ============================================================
    function open(callback, currentPath, options) {
        options = options || {};
        createModal();
        state.callback = typeof callback === 'function' ? callback : null;
        state.isPicker = typeof callback === 'function';
        state.allowedTypes = normalizeTypes(options.types || (state.isPicker ? ['image'] : ['image', 'audio', 'video', 'document']));
        state.activeType = options.type && state.allowedTypes.indexOf(options.type) !== -1
            ? options.type
            : (state.allowedTypes.length > 1 && !state.isPicker ? 'all' : state.allowedTypes[0]);
        state.selectedPath = normalizeImagePath(currentPath);
        state.search = '';
        state.mode = 'library';

        var modal = document.getElementById('nb-imgmgr-modal');
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
        if (state.selectedPath && typeof state.callback === 'function') {
            state.callback(state.selectedPath);
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
                if (items.length > 0) {
                    state.data = items.map(function (img) {
                        return Object.assign({}, img, {
                            type: img.type || 'image',
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
        if (state.search) {
            state.filtered = state.data.filter(function (img) {
                return (img.name || '').toLowerCase().indexOf(state.search) !== -1;
            });
        } else {
            state.filtered = state.data.slice();
        }
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
            var isSelected = state.isPicker && state.selectedPath === image.path;
            item.className = 'nb-imgmgr-item' + (isSelected ? ' selected' : '');
            item.dataset.path = image.path;
            item.setAttribute('role', 'listitem');
            item.setAttribute('aria-label', image.name || image.path || t('media.file'));
            item.innerHTML = state.mode === 'trash'
                ? mediaThumbHtml(image, 'nb-imgmgr-thumb', false) +
                '<div class="nb-imgmgr-name" title="' + escapeHtml(image.name) + '">' + escapeHtml(image.name) + '</div>' +
                '<div class="nb-imgmgr-actions">' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="preview" title="' + escapeHtml(t('image_preview')) + '" aria-label="' + escapeHtml(t('image_preview')) + '">' + Icons.eye + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="restore" title="' + escapeHtml(t('image.restore')) + '" aria-label="' + escapeHtml(t('image.restore')) + '">' + Icons.restore + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn nb-imgmgr-action-btn--danger" data-action="delete-permanent" title="' + escapeHtml(t('image.delete_permanently')) + '" aria-label="' + escapeHtml(t('image.delete_permanently')) + '">' + Icons.delete + '</button>' +
                '</div>'
                :
                mediaThumbHtml(image, 'nb-imgmgr-thumb', false) +
                '<div class="nb-imgmgr-name" title="' + escapeHtml(image.name) + '">' + escapeHtml(image.name) + '</div>' +
                '<div class="nb-imgmgr-actions">' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="preview" title="' + escapeHtml(t('image_preview')) + '" aria-label="' + escapeHtml(t('image_preview')) + '">' + Icons.eye + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="copy" title="' + escapeHtml(t('image.copy_path')) + '" aria-label="' + escapeHtml(t('image.copy_path')) + '">' + Icons.copy + '</button>' +
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
            var row = document.createElement('div');
            var isSelected = state.isPicker && state.selectedPath === image.path;
            row.className = 'nb-imgmgr-row' + (isSelected ? ' selected' : '');
            row.dataset.path = image.path;
            row.setAttribute('aria-label', image.name || image.path || t('media.file'));
            row.innerHTML = state.mode === 'trash'
                ? '<div></div>' +
                mediaThumbHtml(image, 'nb-imgmgr-list-thumb', true) +
                '<div class="nb-imgmgr-list-name" title="' + escapeHtml(image.name) + '">' + escapeHtml(image.name) + '</div>' +
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
                '<div class="nb-imgmgr-list-name" title="' + escapeHtml(image.name) + '">' + escapeHtml(image.name) + '</div>' +
                '<div class="nb-imgmgr-list-size">' + escapeHtml(image.size || '-') + '</div>' +
                '<div class="nb-imgmgr-list-date">' + escapeHtml(image.dateFormatted || '-') + '</div>' +
                '<div class="nb-imgmgr-list-actions">' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="preview" title="' + escapeHtml(t('image_preview')) + '" aria-label="' + escapeHtml(t('image_preview')) + '">' + Icons.eye + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="copy" title="' + escapeHtml(t('image.copy_path')) + '" aria-label="' + escapeHtml(t('image.copy_path')) + '">' + Icons.copy + '</button>' +
                    (isImage(image) ? '<button type="button" class="nb-imgmgr-action-btn" data-action="replace" title="' + escapeHtml(t('image.replace')) + '" aria-label="' + escapeHtml(t('image.replace')) + '">' + Icons.replace + '</button>' : '') +
                    '<button type="button" class="nb-imgmgr-action-btn nb-imgmgr-action-btn--danger" data-action="delete" title="' + escapeHtml(t('delete')) + '" aria-label="' + escapeHtml(t('delete')) + '">' + Icons.delete + '</button>' +
                '</div>';
            if (state.mode !== 'trash' && state.isPicker) {
                row.querySelector('div:first-child').outerHTML = '<div class="nb-imgmgr-check nb-imgmgr-check--list' + (isSelected ? ' checked' : '') + '"></div>';
            }
            listBody.appendChild(row);
            attachItemEvents(row, image);
        });
    }

    function attachItemEvents(element, image) {
        var check = element.querySelector('.nb-imgmgr-check');
        if (check) {
            check.addEventListener('click', function (e) {
                e.stopPropagation();
                toggleSelection(image.path);
            });
        }

        element.addEventListener('click', function (e) {
            var actionEl = e.target.closest('[data-action]');
            if (actionEl) {
                e.stopPropagation();
                var action = actionEl.dataset.action;
                if (action === 'preview') openLightbox(image);
                else if (action === 'copy') copyPath(image.path);
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

    function toggleSelection(path) {
        state.selectedPath = state.selectedPath === path ? null : path;
        updateSelectionUI();
    }

    function updateSelectionUI() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (!modal) return;

        modal.querySelectorAll('.nb-imgmgr-item, .nb-imgmgr-row').forEach(function (item) {
            var path = item.dataset.path;
            var isSelected = path === state.selectedPath;
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
        if (state.selectedPath) {
            info.textContent = state.selectedPath;
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

        if (state.mode === 'trash') {
            state.selectedPath = null;
        }

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
                        state.selectedPath = result.data.path.replace(/^\.\.\//, '/');
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
                '<div class="nb-imgmgr-lightbox-stage"></div>' +
                '<div class="nb-imgmgr-lightbox-info"></div>' +
            '</div>';
        document.body.appendChild(lb);

        lb.addEventListener('click', function (e) {
            if (e.target === lb) closeLightbox();
        });
        lb.querySelector('.nb-imgmgr-lightbox-close').addEventListener('click', closeLightbox);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && lb.classList.contains('active')) closeLightbox();
        });
    }

    function openLightbox(itemOrPath, name) {
        var item = typeof itemOrPath === 'object'
            ? itemOrPath
            : { type: 'image', path: itemOrPath, name: name || '' };
        var lb = document.getElementById('nb-imgmgr-lightbox');
        if (!lb) { createLightbox(); lb = document.getElementById('nb-imgmgr-lightbox'); }
        var stage = lb.querySelector('.nb-imgmgr-lightbox-stage');
        var label = item.name || '';
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
        lb.classList.add('active');
    }

    function closeLightbox() {
        var lb = document.getElementById('nb-imgmgr-lightbox');
        if (lb) {
            lb.querySelectorAll('audio, video').forEach(function (media) { media.pause(); });
            lb.classList.remove('active');
        }
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
    // PUBLIC API
    // ============================================================
    window.NbImageManager = {
        init: function (options) {
            Object.assign(config, options || {});
        },
        open: open,
        close: close,
        confirmSelection: confirmSelection,
    };
})();
