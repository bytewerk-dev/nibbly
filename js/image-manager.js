/*
 * Nibbly Image Manager — Unified Component
 *
 * Shared between the frontend inline editor and the backend dashboard.
 * Consumers initialize it once via NbImageManager.init({...}) and then
 * call NbImageManager.open(callback) to pick an image.
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
        view: 'grid',
        sort: { field: 'date', dir: 'desc' },
        search: '',
    };

    var replaceTarget = null;

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

    // ============================================================
    // MODAL CREATION
    // ============================================================
    function createModal() {
        if (document.getElementById('nb-imgmgr-modal')) return;

        var modal = document.createElement('div');
        modal.id = 'nb-imgmgr-modal';
        modal.className = 'nb-imgmgr-modal';
        modal.innerHTML =
            '<div class="nb-imgmgr-backdrop"></div>' +
            '<div class="nb-imgmgr-dialog">' +
                '<div class="nb-imgmgr-header">' +
                    '<h3>' + escapeHtml(t('image_manager')) + '</h3>' +
                    '<button type="button" class="nb-imgmgr-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="nb-imgmgr-toolbar">' +
                    '<label class="nb-imgmgr-upload-btn">' +
                        Icons.upload + ' <span>' + escapeHtml(t('image.upload')) + '</span>' +
                        '<input type="file" class="nb-imgmgr-upload-input" accept=".jpg,.jpeg,.png,.webp">' +
                    '</label>' +
                    '<span class="nb-imgmgr-formats">' + escapeHtml(t('image.formats_hint')) + '</span>' +
                    '<span class="nb-imgmgr-spacer"></span>' +
                    '<input type="text" class="nb-imgmgr-search" placeholder="' + escapeHtml(t('image.search')) + '">' +
                    '<div class="nb-imgmgr-view-toggle">' +
                        '<button type="button" class="nb-imgmgr-view-btn nb-imgmgr-view-btn--active" data-view="grid" title="' + escapeHtml(t('image.grid_view')) + '">' + Icons.grid + '</button>' +
                        '<button type="button" class="nb-imgmgr-view-btn" data-view="list" title="' + escapeHtml(t('image.list_view')) + '">' + Icons.list + '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="nb-imgmgr-body">' +
                    '<div class="nb-imgmgr-grid"></div>' +
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
                '<div class="nb-imgmgr-footer">' +
                    '<div class="nb-imgmgr-selection-info"></div>' +
                    '<div class="nb-imgmgr-footer-actions">' +
                        '<button type="button" class="nb-imgmgr-btn nb-imgmgr-btn--secondary" data-action="cancel">' + escapeHtml(t('cancel')) + '</button>' +
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

        modal.querySelector('.nb-imgmgr-upload-input').addEventListener('change', handleUpload);

        modal.querySelector('.nb-imgmgr-search').addEventListener('input', function (e) {
            state.search = e.target.value.toLowerCase().trim();
            filterAndRender();
        });

        modal.querySelectorAll('.nb-imgmgr-view-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                switchView(btn.dataset.view);
            });
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
    function open(callback) {
        createModal();
        state.callback = callback || null;
        state.selectedPath = null;
        state.search = '';

        var modal = document.getElementById('nb-imgmgr-modal');
        modal.querySelector('.nb-imgmgr-search').value = '';

        loadImages();
        updateSelectionUI();

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        var modal = document.getElementById('nb-imgmgr-modal');
        if (modal) modal.classList.remove('active');
        document.body.style.overflow = '';
        state.callback = null;
    }

    function confirmSelection() {
        if (state.selectedPath && typeof state.callback === 'function') {
            state.callback(state.selectedPath);
        }
        close();
    }

    // ============================================================
    // LOAD / RENDER
    // ============================================================
    function loadImages() {
        var modal = document.getElementById('nb-imgmgr-modal');
        var gridEl = modal.querySelector('.nb-imgmgr-grid');
        var listBody = modal.querySelector('.nb-imgmgr-list-body');

        gridEl.innerHTML = '<p class="nb-imgmgr-loading">' + escapeHtml(t('image.loading')) + '</p>';
        listBody.innerHTML = '';

        fetch(config.apiUrl + '?action=list-images')
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success && result.data && result.data.length > 0) {
                    state.data = result.data.map(function (img) {
                        return Object.assign({}, img, {
                            path: img.path.replace(/^\.\.\//, '/')
                        });
                    });
                    updateSortHeaderClasses();
                    applySortOrder();
                    filterAndRender();
                } else {
                    state.data = [];
                    state.filtered = [];
                    var empty = '<p class="nb-imgmgr-empty">' + escapeHtml(t('image.no_images')) + '</p>';
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
                : t('image.no_images');
            gridEl.innerHTML = '<p class="nb-imgmgr-empty">' + escapeHtml(msg) + '</p>';
            return;
        }

        gridEl.innerHTML = '';
        state.filtered.forEach(function (image) {
            var item = document.createElement('div');
            var isSelected = state.selectedPath === image.path;
            item.className = 'nb-imgmgr-item' + (isSelected ? ' selected' : '');
            item.dataset.path = image.path;
            item.innerHTML =
                '<div class="nb-imgmgr-check' + (isSelected ? ' checked' : '') + '"></div>' +
                '<div class="nb-imgmgr-thumb" style="background-image:url(\'' + escapeHtml(image.path) + '\')"></div>' +
                '<div class="nb-imgmgr-name" title="' + escapeHtml(image.name) + '">' + escapeHtml(image.name) + '</div>' +
                '<div class="nb-imgmgr-actions">' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="preview" title="' + escapeHtml(t('image_preview')) + '">' + Icons.eye + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="copy" title="' + escapeHtml(t('image.copy_path')) + '">' + Icons.copy + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="replace" title="' + escapeHtml(t('image.replace')) + '">' + Icons.replace + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn nb-imgmgr-action-btn--danger" data-action="delete" title="' + escapeHtml(t('delete')) + '">' + Icons.delete + '</button>' +
                '</div>';
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
                : t('image.no_images');
            listBody.innerHTML = '<p class="nb-imgmgr-empty">' + escapeHtml(msg) + '</p>';
            return;
        }

        listBody.innerHTML = '';
        state.filtered.forEach(function (image) {
            var row = document.createElement('div');
            var isSelected = state.selectedPath === image.path;
            row.className = 'nb-imgmgr-row' + (isSelected ? ' selected' : '');
            row.dataset.path = image.path;
            row.innerHTML =
                '<div class="nb-imgmgr-check nb-imgmgr-check--list' + (isSelected ? ' checked' : '') + '"></div>' +
                '<div class="nb-imgmgr-list-thumb" style="background-image:url(\'' + escapeHtml(image.path) + '\')" data-action="preview"></div>' +
                '<div class="nb-imgmgr-list-name" title="' + escapeHtml(image.name) + '">' + escapeHtml(image.name) + '</div>' +
                '<div class="nb-imgmgr-list-size">' + escapeHtml(image.size || '-') + '</div>' +
                '<div class="nb-imgmgr-list-date">' + escapeHtml(image.dateFormatted || '-') + '</div>' +
                '<div class="nb-imgmgr-list-actions">' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="preview" title="' + escapeHtml(t('image_preview')) + '">' + Icons.eye + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="copy" title="' + escapeHtml(t('image.copy_path')) + '">' + Icons.copy + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn" data-action="replace" title="' + escapeHtml(t('image.replace')) + '">' + Icons.replace + '</button>' +
                    '<button type="button" class="nb-imgmgr-action-btn nb-imgmgr-action-btn--danger" data-action="delete" title="' + escapeHtml(t('delete')) + '">' + Icons.delete + '</button>' +
                '</div>';
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
                if (action === 'preview') openLightbox(image.path, image.name);
                else if (action === 'copy') copyPath(image.path);
                else if (action === 'replace') openReplaceDialog(image.name, image.path);
                else if (action === 'delete') deleteImage(image.name);
                return;
            }
            // Click elsewhere on item = toggle selection
            toggleSelection(image.path);
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

        if (state.selectedPath) {
            info.textContent = state.selectedPath;
            info.classList.add('has-selection');
            confirmBtn.disabled = false;
        } else {
            info.textContent = t('image.no_selection');
            info.classList.remove('has-selection');
            confirmBtn.disabled = true;
        }
    }

    // ============================================================
    // VIEW / SORT
    // ============================================================
    function switchView(view) {
        state.view = view;
        var modal = document.getElementById('nb-imgmgr-modal');
        modal.querySelectorAll('.nb-imgmgr-view-btn').forEach(function (btn) {
            btn.classList.toggle('nb-imgmgr-view-btn--active', btn.dataset.view === view);
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

    function deleteImage(filename) {
        confirmAction(
            t('image.delete'),
            t('image.delete_confirm', { filename: filename }),
            function () {
                var formData = new FormData();
                formData.append('action', 'delete-image');
                formData.append('filename', filename);
                formData.append('csrf_token', config.csrfToken);

                fetch(config.apiUrl, { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (result) {
                        if (result.success) {
                            config.showToast(t('image.trashed'), 'success');
                            if (state.selectedPath && state.selectedPath.indexOf('/' + filename) !== -1) {
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
        );
    }

    function handleUpload(e) {
        var file = e.target.files[0];
        if (!file) return;

        var allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (allowed.indexOf(file.type) === -1) {
            config.showToast(t('image.format_error'), 'error');
            e.target.value = '';
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            config.showToast(t('image.size_error'), 'error');
            e.target.value = '';
            return;
        }

        var formData = new FormData();
        formData.append('action', 'upload-image');
        formData.append('image', file);
        formData.append('csrf_token', config.csrfToken);

        config.showToast(t('image.uploading'), 'info');

        fetch(config.apiUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    config.showToast(t('image.uploaded'), 'success');
                    state.sort = { field: 'date', dir: 'desc' };
                    loadImages();
                    if (result.data && result.data.path) {
                        state.selectedPath = result.data.path.replace(/^\.\.\//, '/');
                        // Re-render once loaded
                        setTimeout(updateSelectionUI, 300);
                    }
                } else {
                    config.showToast(result.message || t('toast.error'), 'error');
                }
            })
            .catch(function (err) {
                config.showToast(err.message || t('toast.error'), 'error');
            })
            .finally(function () { e.target.value = ''; });
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
                '<img alt="">' +
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

    function openLightbox(path, name) {
        var lb = document.getElementById('nb-imgmgr-lightbox');
        if (!lb) { createLightbox(); lb = document.getElementById('nb-imgmgr-lightbox'); }
        lb.querySelector('img').src = path;
        lb.querySelector('.nb-imgmgr-lightbox-info').textContent = name;
        lb.classList.add('active');
    }

    function closeLightbox() {
        var lb = document.getElementById('nb-imgmgr-lightbox');
        if (lb) lb.classList.remove('active');
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
