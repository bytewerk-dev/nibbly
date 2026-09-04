<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // IMAGE MANAGER — thin wrappers around NbImageManager (js/image-manager.js)
    // ============================================================

    // Initialize the shared image manager component with dashboard dependencies.
    // (Deferred to end of script where CSRF_TOKEN and t() are defined.)
    window.addEventListener('DOMContentLoaded', function() {
        initDashboardImageManager();
    });

    function initDashboardImageManager() {
        if (!window.NbImageManager) return;
        NbImageManager.init({
            apiUrl: 'api.php',
            csrfToken: CSRF_TOKEN,
            t: function(key, params) {
                let s = NB_LANG[key] || key;
                if (params) {
                    for (const [k, v] of Object.entries(params)) {
                        s = s.replace('{' + k + '}', v);
                    }
                }
                return s;
            },
            showToast: function(msg, type) {
                if (typeof showToast === 'function') showToast(msg, type);
            },
            showConfirm: null,
            canGenerateImages: function() {
                return dashboardAiImageUsable === true;
            },
            openImageGenerator: function(prompt, aspectRatio) {
                openAiImageGenerator(prompt || '', aspectRatio || 'auto');
            },
            itemsPerPage: clampMediaPageSize(currentSettings?.dashboard?.mediaItemsPerPage)
        });
    }

    function browseImageForField(inputEl, previewEl) {
        NbImageManager.open(function(path) {
            inputEl.value = path;
            inputEl.dispatchEvent(new Event('input'));
            if (previewEl) {
                const img = previewEl.querySelector('img');
                if (img) { img.src = path.startsWith('/') ? '..' + path : path; img.style.display = ''; }
            }
            markDirty();
        }, inputEl ? inputEl.value : null, { types: ['image'], type: 'image' });
    }

    // Backward-compat globals (in case any onclick attribute still references them)
    window.openImageManager = function() { NbImageManager.open(null, null, { types: ['image', 'audio', 'video', 'document'] }); };
    window.closeImageManager = function() { NbImageManager.close(); };
    function normalizeOgImageInputPath(path) {
        path = (path || '').trim();
        if (path.startsWith('../assets/images/')) {
            return '/assets/images/' + path.substring('../assets/images/'.length);
        }
        if (path.startsWith('assets/images/')) {
            return '/' + path;
        }
        return path;
    }
    function isSupportedOgImagePath(path) {
        path = normalizeOgImageInputPath(path);
        if (!path) return true;
        var cleanPath = path.split('?')[0].split('#')[0].toLowerCase();
        return /^\/assets\/images\/.+\.(jpe?g|png)$/.test(cleanPath);
    }
    function setOgImageInputValue(input, path) {
        var normalized = normalizeOgImageInputPath(path);
        if (!isSupportedOgImagePath(normalized)) {
            showToast(t('editor.seo_og_image_format'), 'error');
            return false;
        }
        input.value = normalized;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        return true;
    }
    window.browseSeoOgImage = function() {
        const input = document.getElementById('seoOgImage');
        if (!input) return;
        NbImageManager.open(function(path) {
            if (setOgImageInputValue(input, path)) {
                markDirty();
            }
        }, input.value || null, { types: ['image'], type: 'image' });
    };
    window.browseSectionMedia = function(btn, type) {
        type = type || 'image';
        const input = btn.parentElement.querySelector('.section-field');
        const preview = btn.closest('.form-group').querySelector('.ce-image-preview');
        NbImageManager.open(function(path) {
            if (path && input) {
                input.value = path;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                if (type === 'image' && preview) {
                    const src = path.startsWith('/') ? '..' + path : path;
                    preview.innerHTML = '<img src="' + escapeHtml(src) + '" alt="preview" onerror="this.style.display=\'none\'">';
                } else if (type === 'image') {
                    const previewDiv = document.createElement('div');
                    previewDiv.className = 'ce-image-preview';
                    const src = path.startsWith('/') ? '..' + path : path;
                    previewDiv.innerHTML = '<img src="' + escapeHtml(src) + '" alt="preview">';
                    input.parentElement.before(previewDiv);
                }
                markDirty();
            }
        }, input ? input.value : null, { types: [type], type: type });
    };
    window.browseSectionImage = function(btn) {
        window.browseSectionMedia(btn, 'image');
    };

    // Track unsaved changes
    let isDirty = false;
    function markDirty() {
        isDirty = true;
    }

    // Undo/Redo system — snapshot-based
    const MAX_UNDO = 50;
    let undoStack = [];
    let redoStack = [];

    function pushUndoSnapshot() {
        collectAllContent();
        undoStack.push(JSON.stringify(currentContent));
        if (undoStack.length > MAX_UNDO) undoStack.shift();
        redoStack = [];
        updateUndoRedoButtons();
    }

    function editorUndo() {
        if (undoStack.length === 0) return;
        collectAllContent();
        redoStack.push(JSON.stringify(currentContent));
        const snapshot = undoStack.pop();
        currentContent = JSON.parse(snapshot);
        const openPaths = getOpenGroupPaths();
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
        updateUndoRedoButtons();
    }

    function editorRedo() {
        if (redoStack.length === 0) return;
        collectAllContent();
        undoStack.push(JSON.stringify(currentContent));
        const snapshot = redoStack.pop();
        currentContent = JSON.parse(snapshot);
        const openPaths = getOpenGroupPaths();
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
        updateUndoRedoButtons();
    }

    function updateUndoRedoButtons() {
        const undoBtn = document.getElementById('undoBtn');
        const redoBtn = document.getElementById('redoBtn');
        if (undoBtn) undoBtn.disabled = undoStack.length === 0;
        if (redoBtn) redoBtn.disabled = redoStack.length === 0;
    }

    function clearUndoHistory() {
        undoStack = [];
        redoStack = [];
        updateUndoRedoButtons();
    }

    function isDashboardElementVisible(el) {
        if (!el || el.hidden) return false;
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    }

    function submitDashboardForm(form) {
        if (!form) return false;
        const submitter = form.querySelector('button[type="submit"]:not([disabled]), input[type="submit"]:not([disabled])');
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter || undefined);
        } else {
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
        return true;
    }

    function triggerDashboardSaveShortcut() {
        const pageEditor = document.getElementById('editorContainer');
        if (currentContent && isDashboardElementVisible(pageEditor)) {
            saveContent();
            return true;
        }

        const newsEditor = document.getElementById('newsEditorContainer');
        if (isDashboardElementVisible(newsEditor)) {
            savePost();
            return true;
        }

        const eventEditor = document.getElementById('eventsEditorView');
        if (isDashboardElementVisible(eventEditor)) {
            saveCurrentEvent();
            return true;
        }

        const settingsTab = document.getElementById('settingsTab');
        if (isDashboardElementVisible(settingsTab)) {
            const activePanel = settingsTab.querySelector('.settings-panel.active');
            if (!activePanel) return false;

            if (activePanel.id === 'settingsPanel-menus') {
                const menuSaveBtn = document.getElementById('saveMenuOrderBtn');
                if (menuSaveBtn && !menuSaveBtn.disabled) {
                    menuSaveBtn.click();
                    return true;
                }
                return false;
            }

            return submitDashboardForm(activePanel.querySelector('form.settings-form'));
        }

        const iconsTab = document.getElementById('iconsTab');
        if (isDashboardElementVisible(iconsTab)) {
            return submitDashboardForm(document.getElementById('iconManagerForm'));
        }

        return false;
    }

    // Keyboard shortcuts for save and undo/redo
    document.addEventListener('keydown', function(e) {
        const tag = document.activeElement?.tagName;
        const isTyping = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement?.isContentEditable;
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            triggerDashboardSaveShortcut();
            return;
        }
        if ((e.metaKey || e.ctrlKey) && e.key === 'z') {
            if (!currentContent) return;
            // Ignore when typing in input/textarea
            if (isTyping) return;
            e.preventDefault();
            if (e.shiftKey) {
                editorRedo();
            } else {
                editorUndo();
            }
            return;
        }
        if (!currentContent || isTyping) return;
        if (e.key === '/') {
            const search = document.getElementById('sectionSearchInput');
            if (search) {
                e.preventDefault();
                search.focus();
            }
            return;
        }
        if (e.key === 'j' || e.key === 'k') {
            e.preventDefault();
            const direction = e.key === 'j' ? 1 : -1;
            const next = Math.max(0, Math.min(activeSectionIndex + direction, (currentContent.sections || []).length - 1));
            scrollToSection(next);
            return;
        }
        if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            const direction = e.key === 'ArrowDown' ? 1 : -1;
            reorderSection(activeSectionIndex, activeSectionIndex + direction);
        }
    });

    // Array manipulation
    function getNestedValue(obj, path) {
        return path.split('.').reduce((o, k) => (o && o[k] !== undefined) ? o[k] : undefined, obj);
    }

    function setNestedValue(obj, path, value) {
        const keys = path.split('.');
        let current = obj;
        for (let i = 0; i < keys.length - 1; i++) {
            const k = keys[i];
            if (current[k] === undefined) {
                current[k] = isNaN(keys[i + 1]) ? {} : [];
            }
            current = current[k];
        }
        current[keys[keys.length - 1]] = value;
    }

    function addArrayItem(basePath) {
        pushUndoSnapshot();
        const openPaths = getOpenGroupPaths();
        const arr = getNestedValue(currentContent, basePath);
        if (!Array.isArray(arr)) return;

        // Clone the structure of the first item or create an empty string
        if (arr.length > 0 && typeof arr[0] === 'object' && arr[0] !== null) {
            const template = {};
            for (const k of Object.keys(arr[0])) {
                template[k] = '';
            }
            arr.push(template);
        } else {
            arr.push('');
        }
        // Auto-open the new item
        openPaths.add(basePath + '.' + (arr.length - 1));
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
    }

    function removeArrayItem(basePath, index) {
        pushUndoSnapshot();
        const openPaths = getOpenGroupPaths();
        const arr = getNestedValue(currentContent, basePath);
        if (!Array.isArray(arr)) return;
        arr.splice(index, 1);
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
    }

    function moveArrayItem(basePath, index, direction) {
        pushUndoSnapshot();
        const openPaths = getOpenGroupPaths();
        const arr = getNestedValue(currentContent, basePath);
        if (!Array.isArray(arr)) return;
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= arr.length) return;
        const temp = arr[index];
        arr[index] = arr[newIndex];
        arr[newIndex] = temp;
        // Swap the open state of moved items
        const pathA = basePath + '.' + index;
        const pathB = basePath + '.' + newIndex;
        const hadA = openPaths.has(pathA);
        const hadB = openPaths.has(pathB);
        openPaths.delete(pathA);
        openPaths.delete(pathB);
        if (hadA) openPaths.add(pathB);
        if (hadB) openPaths.add(pathA);
        renderEditor();
        restoreOpenGroupPaths(openPaths);
        markDirty();
    }

    // Collect all content from the form back into currentContent
    function collectAllContent() {
        // Collect page settings (title, description, nav, breadcrumb)
        collectPageSettings();

        // Collect generic fields
        document.querySelectorAll('[data-path]').forEach(el => {
            const path = el.dataset.path;
            let value;
            if (el.dataset.type === 'boolean') {
                value = el.checked;
            } else if (el.dataset.type === 'number') {
                value = Number(el.value);
            } else {
                value = el.value;
            }
            setNestedValue(currentContent, path, value);
        });

        // Collect sections (legacy)
        collectSectionData();
    }

    // Add section element (registry-driven)
    function addSectionElement(section, index, container) {
        if (!container) container = document.getElementById('sectionsLegacyContainer');
        const div = document.createElement('div');
        div.className = 'section-item is-open';
        div.id = `section-${index}`;
        div.dataset.index = index;
        div.dataset.type = section.type;
        div.dataset.search = getSectionSearchText(section, index);
        div.dataset.editorSectionId = div.id;
        div.dataset.editorSectionKind = 'section';
        div.setAttribute('draggable', 'true');

        const def = window.BlockTypeRegistry?.[section.type];
        const typeLabel = getSectionTypeLabel(section);
        const preview = getSectionPreview(section);
        const fullTitle = getSectionHeading(section, index);
        const issues = getSectionIssues(section, index, currentContent.sections || []);
        const issueLabel = issues.length === 1
            ? t('editor.section_issue', {count: issues.length})
            : t('editor.section_issues', {count: issues.length});

        // Build form fields from registry
        let content = '';
        if (def && def.fields) {
            for (const field of def.fields) {
                const val = escapeHtml(section[field.key] ?? '');
                const fieldClass = 'section-field-group section-field-group--' + String(field.key || 'field').replace(/[^a-z0-9_-]/gi, '-').toLowerCase();
                switch (field.type) {
                    case 'input':
                    case 'url':
                    case 'number':
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            <input type="${field.type === 'input' ? 'text' : field.type}" class="section-field" data-key="${field.key}" value="${val}" placeholder="${field.label}...">
                            ${field.hint ? `<small style="color: #666;">${field.hint}</small>` : ''}
                        </div>`;
                        break;
                    case 'textarea':
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            <textarea class="section-field" data-key="${field.key}" placeholder="${field.label}...">${val}</textarea>
                        </div>`;
                        break;
                    case 'wysiwyg':
                        content += `<div class="form-group html-editor ${fieldClass}">
                            <label>${field.label} (HTML)</label>
                            <textarea class="section-field" data-key="${field.key}">${val}</textarea>
                        </div>`;
                        break;
                    case 'select':
                        const opts = (field.options || []).map(o =>
                            `<option value="${o.value}"${section[field.key] === o.value ? ' selected' : ''}>${o.label}</option>`
                        ).join('');
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            <select class="section-field" data-key="${field.key}">${opts}</select>
                        </div>`;
                        break;
                    case 'checkbox':
                        content += `<div class="form-group ${fieldClass}">
                            <label><input type="checkbox" class="section-field" data-key="${field.key}"${section[field.key] ? ' checked' : ''}> ${field.label}</label>
                        </div>`;
                        break;
                    case 'image':
                        const imgSrc = val ? (val.startsWith('/') ? '..' + val : val) : '';
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            ${imgSrc ? `<div class="ce-image-preview"><img src="${escapeHtml(imgSrc)}" alt="preview" onerror="this.style.display='none'"></div>` : ''}
                            <div class="ce-image-input-row">
                                <input type="text" class="section-field ce-input" data-key="${field.key}" value="${val}" placeholder="Path to image...">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="browseSectionImage(this)">${t('btn.browse')}</button>
                            </div>
                        </div>`;
                        break;
                    case 'audio':
                        content += `<div class="form-group ${fieldClass}">
                            <label>${field.label}</label>
                            <div class="ce-image-input-row">
                                <input type="text" class="section-field ce-input" data-key="${field.key}" value="${val}" placeholder="Path to audio file...">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="browseSectionMedia(this, 'audio')">${t('btn.browse')}</button>
                            </div>
                        </div>`;
                        break;
                }
            }
        }

        div.innerHTML = `
            <div class="section-header">
                <div class="section-heading">
                    <button type="button" class="section-drag-handle" draggable="true" title="${t('editor.drag_section')}" aria-label="${t('editor.drag_section')}">⋮⋮</button>
                    <button type="button" class="section-toggle-btn" onclick="toggleSectionOpen(${index})" aria-expanded="true" title="${t('editor.collapse_section')}">▾</button>
                    <span class="section-index-label">${index + 1}</span>
                    <span class="section-type ${section.type}">${typeLabel}</span>
                    <span class="section-title-preview" title="${escapeHtml(fullTitle)}">${preview ? escapeHtml(preview) : 'Section ' + (index + 1)}</span>
                    ${issues.length ? `<span class="section-issue-badge" title="${escapeHtml(issues.join('\n'))}">${escapeHtml(issueLabel)}</span>` : ''}
                </div>
                <div class="section-actions">
                    <button class="btn btn-sm btn-secondary" onclick="moveSection(${index}, -1)">&#8593;</button>
                    <button class="btn btn-sm btn-secondary" onclick="moveSection(${index}, 1)">&#8595;</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteSection(${index})">${icon('trash', 14)}</button>
                </div>
            </div>
            <div class="section-fields">${content}</div>
        `;

        container.appendChild(div);
        div.addEventListener('dragstart', e => {
            if (!e.target.closest('.section-drag-handle') && !e.target.closest('.section-header')) {
                e.preventDefault();
                return;
            }
            sectionDragIndex = index;
            e.dataTransfer.effectAllowed = 'move';
            div.classList.add('is-dragging');
        });
        div.addEventListener('dragend', () => {
            sectionDragIndex = null;
            document.querySelectorAll('.is-dragging, .is-drop-target').forEach(el => el.classList.remove('is-dragging', 'is-drop-target'));
        });
        div.addEventListener('dragover', e => {
            e.preventDefault();
            div.classList.add('is-drop-target');
        });
        div.addEventListener('dragleave', () => div.classList.remove('is-drop-target'));
        div.addEventListener('drop', e => {
            e.preventDefault();
            div.classList.remove('is-drop-target');
            reorderSection(sectionDragIndex, index);
        });
        sectionCounter++;
    }

    // Add new section (registry-driven)
    function addSection(type, insertIndex) {
        pushUndoSnapshot();
        if (!currentContent) {
            currentContent = { sections: [] };
        }
        if (!currentContent.sections) {
            currentContent.sections = [];
        }

        const def = window.BlockTypeRegistry?.[type];
        const defaults = def?.defaults ? JSON.parse(JSON.stringify(def.defaults)) : {};
        const newSection = {
            id: 'section_' + Date.now(),
            type: type,
            ...defaults
        };

        const targetIndex = Number.isInteger(insertIndex)
            ? Math.max(0, Math.min(insertIndex, currentContent.sections.length))
            : currentContent.sections.length;
        currentContent.sections.splice(targetIndex, 0, newSection);
        renderEditor();
        setActiveSection(targetIndex);
        requestAnimationFrame(() => scrollToSection(targetIndex));
        markDirty();
    }

    // Move section
    function moveSection(index, direction) {
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= currentContent.sections.length) return;
        reorderSection(index, newIndex);
    }

    // Delete section
    function deleteSection(index) {
        pushUndoSnapshot();
        currentContent.sections.splice(index, 1);
        renderEditor();
        markDirty();
    }

    // Collect form data (registry-driven)
    function collectSectionData() {
        const sectionElements = document.querySelectorAll('.section-item');

        sectionElements.forEach((el, index) => {
            const section = currentContent.sections[index];
            if (!section) return;

            // Read all fields with data-key attributes
            el.querySelectorAll('.section-field').forEach(fieldEl => {
                const key = fieldEl.dataset.key;
                if (!key) return;

                if (fieldEl.type === 'checkbox') {
                    if (fieldEl.checked) {
                        section[key] = fieldEl.checked;
                    } else {
                        delete section[key];
                    }
                } else {
                    section[key] = fieldEl.value || '';
                }
            });
        });
    }
