<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    <?php if ($isAdminUser): ?>
    // ============================================================
    // ICON MANAGER
    // ============================================================

    let iconManagerData = [];
    let iconManagerPath = '';
    let iconManagerSortMode = 'alpha';
    let iconManagerSearchTerm = '';
    let iconManagerHighlightedKeys = new Set();
    let iconManagerHighlightTimer = null;
    const ICONIFY_IMPORT_SETS = {
        lucide:   { label: 'Lucide',         license: 'ISC', licenseUrl: 'https://github.com/lucide-icons/lucide/blob/main/LICENSE' },
        tabler:   { label: 'Tabler Icons',   license: 'MIT', licenseUrl: 'https://github.com/tabler/tabler-icons/blob/master/LICENSE' },
        heroicons:{ label: 'Heroicons',      license: 'MIT', licenseUrl: 'https://github.com/tailwindlabs/heroicons/blob/master/LICENSE' },
        ph:       { label: 'Phosphor',       license: 'MIT', licenseUrl: 'https://github.com/phosphor-icons/core/blob/main/LICENSE' },
        bi:       { label: 'Bootstrap Icons',license: 'MIT', licenseUrl: 'https://github.com/twbs/icons/blob/main/LICENSE.md', style: 'fill' },
        iconoir:  { label: 'Iconoir',        license: 'MIT', licenseUrl: 'https://github.com/iconoir-icons/iconoir/blob/main/LICENSE' },
        ion:      { label: 'Ionicons',       license: 'MIT', licenseUrl: 'https://github.com/ionic-team/ionicons/blob/main/LICENSE' },
        mynaui:   { label: 'Myna UI',        license: 'MIT', licenseUrl: 'https://github.com/MynaUI/icons/blob/main/LICENSE' },
        mingcute: { label: 'MingCute',       license: 'Apache-2.0', licenseUrl: 'https://github.com/mingcute-design/mingcute-icons/blob/main/LICENSE' },
        tdesign:  { label: 'TDesign Icons',  license: 'MIT', licenseUrl: 'https://github.com/Tencent/tdesign-icons/blob/main/LICENSE' }
    };

    async function fetchJsonWithTimeout(url, options = {}, timeoutMs = 12000) {
        const controller = new AbortController();
        const timer = setTimeout(function() { controller.abort(); }, timeoutMs);
        try {
            const response = await fetch(url, Object.assign({}, options, {
                signal: controller.signal,
                cache: 'no-store',
            }));
            return await response.json();
        } finally {
            clearTimeout(timer);
        }
    }

    function cleanSvgAttributes(attrs) {
        if (!attrs) return '';
        const allowedAttrs = new Set([
            'd', 'points', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
            'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap',
            'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
            'fill-rule', 'clip-rule', 'opacity', 'fill-opacity', 'stroke-opacity',
            'transform', 'id', 'clip-path', 'mask', 'offset', 'stop-color', 'stop-opacity',
            'gradientUnits', 'gradientTransform'
        ]);
        let cleaned = '';
        attrs.replace(/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("[^"]*"|'[^']*'|[^\s>\/]+))?/g, function(match, rawName, rawValue) {
            const name = rawName.replace(/^xlink:/i, '').trim();
            const lower = name.toLowerCase();
            if (lower.startsWith('on') || lower === 'style' || lower === 'class') return '';
            if (!allowedAttrs.has(name) && !allowedAttrs.has(lower)) return '';

            const attrName = allowedAttrs.has(name) ? name : lower;
            let value = rawValue || '';
            if (value) {
                const unquoted = value.replace(/^["']|["']$/g, '').trim();
                if ((lower === 'clip-path' || lower === 'mask') && unquoted && !/^url\(#[-_a-zA-Z0-9]+\)$/.test(unquoted)) return '';
                if ((lower === 'fill' || lower === 'stroke') && /^url\(/i.test(unquoted) && !/^url\(#[-_a-zA-Z0-9]+\)$/i.test(unquoted)) return '';
                value = '="' + escapeHtml(unquoted) + '"';
            }
            cleaned += ' ' + attrName + value;
            return '';
        });
        return cleaned;
    }

    function sanitizeIconSvgClient(svg) {
        svg = (svg || '').trim()
            .replace(/<\?xml[\s\S]*?\?>/gi, '')
            .replace(/<!DOCTYPE[\s\S]*?>/gi, '')
            .replace(/<\s*\/?\s*svg\b[^>]*>/gi, '')
            .replace(/<!--[\s\S]*?-->/g, '')
            .replace(/<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/gi, '')
            .replace(/<\s*(metadata|desc|style|sodipodi:namedview)\b[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
            .replace(/<\s*defs\b[^>]*>\s*(?:<\s*style\b[^>]*>[\s\S]*?<\s*\/\s*style\s*>\s*)*<\s*\/\s*defs\s*>/gi, '')
            .replace(/\s+on[a-z]+\s*=\s*(["']).*?\1/gi, '')
            .replace(/\s+(href|xlink:href)\s*=\s*(["'])\s*(?!#)[^"']*\2/gi, '');
        const allowed = new Set(['path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'g', 'defs', 'clipPath', 'mask', 'linearGradient', 'radialGradient', 'stop', 'title']);
        svg = svg.replace(/<\s*(\/?)([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>/g, function(match, closing, tag, attrs) {
            if (!allowed.has(tag)) return '';
            if (closing) return `</${tag}>`;
            const selfClosing = /\/\s*$/.test(attrs || '');
            const cleanedAttrs = cleanSvgAttributes(attrs || '');
            return `<${tag}${cleanedAttrs}${selfClosing ? '/' : ''}>`;
        });
        svg = svg.replace(/^\s*(?:\.[\w-]+\s*\{[^}]*\}\s*)+/gm, '');
        svg = removeEmptySvgContainers(svg);
        return svg.trim();
    }

    function normalizeIconViewBoxClient(viewBox) {
        const raw = (viewBox || '').trim();
        if (!raw) return '0 0 24 24';
        const parts = raw.split(/[\s,]+/);
        if (parts.length !== 4) return '0 0 24 24';
        const numbers = parts.map(Number);
        if (numbers.some(function(value) { return !Number.isFinite(value); }) || numbers[2] <= 0 || numbers[3] <= 0) {
            return '0 0 24 24';
        }
        return numbers.map(function(value) {
            return Number.isInteger(value) ? String(value) : String(parseFloat(value.toFixed(6)));
        }).join(' ');
    }

    function extractIconViewBoxClient(svg) {
        const markup = String(svg || '');
        const viewBoxMatch = markup.match(/<\s*svg\b[^>]*\sviewBox\s*=\s*(["'])(.*?)\1/i);
        if (viewBoxMatch) return normalizeIconViewBoxClient(viewBoxMatch[2]);

        const widthMatch = markup.match(/<\s*svg\b[^>]*\swidth\s*=\s*(["'])([0-9.]+)(?:px)?\1/i);
        const heightMatch = markup.match(/<\s*svg\b[^>]*\sheight\s*=\s*(["'])([0-9.]+)(?:px)?\1/i);
        if (widthMatch && heightMatch) return normalizeIconViewBoxClient('0 0 ' + widthMatch[2] + ' ' + heightMatch[2]);

        return '';
    }

    function removeEmptySvgContainers(svg) {
        let previous = '';
        let cleaned = svg || '';
        while (cleaned !== previous) {
            previous = cleaned;
            cleaned = cleaned
                .replace(/<defs\b[^>]*>\s*<\/defs>/gi, '')
                .replace(/<g\b[^>]*>\s*<\/g>/gi, '')
                .replace(/<clipPath\b[^>]*>\s*<\/clipPath>/gi, '')
                .replace(/<mask\b[^>]*>\s*<\/mask>/gi, '');
        }
        return cleaned;
    }

    function forceIconCurrentColor(svg) {
        let normalized = sanitizeIconSvgClient(svg)
            .replace(/\s(fill|stroke)\s*=\s*(["'])(?!none\2|currentColor\2|url\(#)[^"']*\2/gi, ' $1="currentColor"')
            .replace(/\sstop-color\s*=\s*(["'])(?!currentColor\1|none\1)[^"']*\1/gi, ' stop-color="currentColor"');
        normalized = normalized.replace(/<\s*(polygon|circle|ellipse|rect)\b([^>]*)\/?>/gi, function(match, tag, attrs) {
            if (/\s(?:fill|stroke)\s*=/i.test(attrs)) return match;
            const selfClosing = /\/\s*>$/.test(match);
            const cleanedAttrs = (attrs || '').replace(/\/\s*$/, '');
            return `<${tag}${cleanedAttrs} fill="currentColor"${selfClosing ? '/>' : '>'}`;
        });
        return normalized;
    }

    async function loadIconManager() {
        const button = document.getElementById('iconManagerRefreshBtn');
        if (button) button.disabled = true;
        try {
            const result = await fetchJsonWithTimeout('api.php?action=list-icons&csrf_token=' + encodeURIComponent(CSRF_TOKEN) + '&_=' + Date.now());
            if (result.success) {
                iconManagerData = result.data.icons || [];
                iconManagerPath = result.data.path || 'content/settings/iconset.json';
                renderIconManager(result.data);
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('icons.load_error', {message: error.message}), 'error');
        } finally {
            if (button) button.disabled = false;
        }
    }

    function getFilteredIconManagerIcons() {
        const term = iconManagerSearchTerm.toLowerCase();
        let icons = iconManagerData.slice();
        if (term) {
            icons = icons.filter(function(iconItem) {
                const haystack = [
                    iconItem.key || '',
                    iconItem.label || '',
                    (iconItem.tags || []).join(' '),
                    iconItem.source || ''
                ].join(' ').toLowerCase();
                return haystack.includes(term);
            });
        }

        icons.sort(function(a, b) {
            if (iconManagerSortMode === 'newest' || iconManagerSortMode === 'oldest') {
                const aTime = Date.parse(a.createdAt || a.updatedAt || '') || 0;
                const bTime = Date.parse(b.createdAt || b.updatedAt || '') || 0;
                if (aTime !== bTime) {
                    return iconManagerSortMode === 'newest' ? bTime - aTime : aTime - bTime;
                }
            }
            return String(a.key || '').localeCompare(String(b.key || ''), undefined, { sensitivity: 'base' });
        });

        return icons;
    }

    function iconSvgAttributes(iconItem) {
        return (iconItem.style || 'stroke') === 'fill'
            ? 'fill="currentColor"'
            : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    }

    function iconSvgMarkup(iconItem) {
        const svg = iconItem.svg || '';
        return (iconItem.style || 'stroke') === 'fill'
            ? svg.replace(/\s+stroke\s*=\s*(["'])currentColor\1/gi, '')
            : svg;
    }

    function highlightIconManagerCard(key) {
        if (key) iconManagerHighlightedKeys.add(key);
        if (iconManagerHighlightTimer) clearTimeout(iconManagerHighlightTimer);
        if (iconManagerHighlightedKeys.size) {
            iconManagerHighlightTimer = setTimeout(function() {
                iconManagerHighlightedKeys.clear();
                renderIconManager();
            }, 60000);
        }
    }

    function renderIconManager(data = null) {
        const grid = document.getElementById('iconManagerGrid');
        const empty = document.getElementById('iconManagerEmpty');
        const path = document.getElementById('iconManagerPath');
        if (!grid) return;

        if (data) {
            iconManagerData = data.icons || [];
            iconManagerPath = data.path || iconManagerPath;
        }

        grid.innerHTML = '';
        if (path) path.textContent = t('icons.path', {path: iconManagerPath || 'content/settings/iconset.json'});

        const icons = getFilteredIconManagerIcons();
        if (!icons.length) {
            if (empty) empty.style.display = '';
            renderAdminListFooter('iconManagerFooter', 'icons', 0, getDashboardPageSize('icons'), renderIconManager, 'iconManagerFooterTop');
            return;
        }
        if (empty) empty.style.display = 'none';

        const pageSize = getDashboardPageSize('icons');
        const paged = pageSlice(icons, 'icons', pageSize);
        renderAdminListFooter('iconManagerFooter', 'icons', icons.length, pageSize, renderIconManager, 'iconManagerFooterTop');

        paged.items.forEach(function(iconItem) {
            const card = document.createElement('article');
            card.className = 'icon-manager-card';
            if (iconManagerHighlightedKeys.has(iconItem.key)) {
                card.classList.add('icon-manager-card--highlight');
            }
            const sourceLabel = iconItem.source === 'custom' ? t('icons.source_custom') : t('icons.source_core');
            card.title = sourceLabel;
            card.dataset.iconKey = iconItem.key || '';
            card.dataset.iconTags = (iconItem.tags || []).join(' ');
            card.innerHTML = `
                <div class="icon-manager-card__preview">
                    <svg viewBox="${escapeHtml(iconItem.viewBox || '0 0 24 24')}" ${iconSvgAttributes(iconItem)}>${iconSvgMarkup(iconItem)}</svg>
                </div>
                <div class="icon-manager-card__body">
                    <strong>${escapeHtml(iconItem.key)}</strong>
                    <span>${escapeHtml(iconItem.label || '')}</span>
                </div>
                <div class="icon-manager-card__actions">
                    <button type="button" class="icon-manager-action-btn" data-icon-edit="${escapeHtml(iconItem.key)}" title="${t('pages.edit')}" aria-label="${t('pages.edit')}">${icon('edit', 14, '2')}</button>
                    <button type="button" class="icon-manager-action-btn icon-manager-action-btn--danger" data-icon-delete="${escapeHtml(iconItem.key)}" title="${t('btn.delete')}" aria-label="${t('btn.delete')}"${iconItem.canDelete ? '' : ' disabled'}>${icon('trash', 14, '2')}</button>
                </div>
            `;
            grid.appendChild(card);
        });

        grid.querySelectorAll('[data-icon-edit]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const iconItem = iconManagerData.find(function(item) { return item.key === btn.dataset.iconEdit; });
                if (iconItem) openIconManagerModal(iconItem);
            });
        });
        grid.querySelectorAll('[data-icon-delete]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const iconItem = iconManagerData.find(function(item) { return item.key === btn.dataset.iconDelete; });
                if (iconItem) deleteIconManagerIcon(iconItem);
            });
        });
    }

    function openIconManagerModal(iconItem) {
        const isEdit = !!iconItem;
        document.getElementById('iconManagerModalTitle').textContent = isEdit ? t('icons.edit_icon') : t('icons.add_icon');
        document.getElementById('iconManagerOldKey').value = isEdit ? iconItem.key : '';
        document.getElementById('iconManagerKey').value = isEdit ? iconItem.key : '';
        document.getElementById('iconManagerLabel').value = isEdit ? (iconItem.label || '') : '';
        document.getElementById('iconManagerTags').value = isEdit ? (iconItem.tags || []).join(', ') : '';
        document.getElementById('iconManagerViewBox').value = isEdit ? (iconItem.viewBox || '0 0 24 24') : '0 0 24 24';
        document.getElementById('iconManagerSvg').value = isEdit ? (iconItem.svg || '') : '';
        document.getElementById('iconRenameWarning').hidden = true;
        updateIconManagerPreview();
        closeAllComboboxes();
        document.getElementById('iconManagerModalOverlay').style.display = 'flex';
    }

    function closeIconManagerModal() {
        document.getElementById('iconManagerModalOverlay').style.display = 'none';
    }

    // Custom icon-set picker (replaces native <select> to avoid modal overlay clipping)
    (function() {
        var picker = document.getElementById('iconifyImportSetPicker');
        if (!picker) return;
        var btn = document.getElementById('iconifyImportSetBtn');
        var label = document.getElementById('iconifyImportSetLabel2');
        var list = document.getElementById('iconifyImportSetList');
        var hidden = document.getElementById('iconifyImportSet');

        function openPicker() {
            var rect = btn.getBoundingClientRect();
            list.style.top = (rect.bottom + 4) + 'px';
            list.style.left = rect.left + 'px';
            list.style.width = rect.width + 'px';
            list.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
        }
        function closePicker() {
            list.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            list.hidden ? openPicker() : closePicker();
        });
        list.addEventListener('click', function(e) {
            var opt = e.target.closest('[role="option"]');
            if (!opt) return;
            list.querySelectorAll('[role="option"]').forEach(function(o) { o.setAttribute('aria-selected', 'false'); });
            opt.setAttribute('aria-selected', 'true');
            hidden.value = opt.dataset.value;
            label.textContent = opt.textContent;
            closePicker();
            updateIconifyImportLicense();
        });
        document.addEventListener('click', function(e) {
            if (!picker.contains(e.target)) closePicker();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !list.hidden) closePicker();
        });
    })();

    function openIconifyImportModal() {
        const overlay = document.getElementById('iconifyImportModalOverlay');
        if (!overlay) return;
        closeAllComboboxes();
        // Also close icon-set picker if open
        var setList = document.getElementById('iconifyImportSetList');
        if (setList) setList.hidden = true;
        overlay.style.display = 'flex';
        updateIconifyImportLicense();
        setTimeout(function() {
            document.getElementById('iconifyImportQuery')?.focus();
        }, 50);
    }

    function closeIconifyImportModal() {
        document.getElementById('iconifyImportModalOverlay').style.display = 'none';
        var setList = document.getElementById('iconifyImportSetList');
        if (setList) setList.hidden = true;
    }

    function updateIconifyImportLicense() {
        const prefix = document.getElementById('iconifyImportSet')?.value || 'lucide';
        const setInfo = ICONIFY_IMPORT_SETS[prefix] || ICONIFY_IMPORT_SETS.lucide;
        const license = document.getElementById('iconifyImportLicense');
        if (license) {
            license.innerHTML = escapeHtml(t('icons.import_license_hint', {
                set: setInfo.label,
                license: setInfo.license
            }));
        }
    }

    async function searchIconifyImport() {
        const prefix = document.getElementById('iconifyImportSet')?.value || 'lucide';
        const query = document.getElementById('iconifyImportQuery')?.value.trim() || '';
        const results = document.getElementById('iconifyImportResults');
        const empty = document.getElementById('iconifyImportEmpty');
        const button = document.getElementById('iconifyImportSearchBtn');
        if (!results || query.length < 2) {
            showToast(t('icons.import_query_short'), 'error');
            return;
        }

        results.innerHTML = '<p class="form-hint">' + escapeHtml(t('icons.import_loading')) + '</p>';
        if (empty) empty.style.display = 'none';
        if (button) button.disabled = true;

        try {
            const url = 'api.php?action=iconify-search&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                + '&prefix=' + encodeURIComponent(prefix)
                + '&query=' + encodeURIComponent(query);
            const result = await fetchJsonWithTimeout(url, {}, 15000);
            if (!result.success) {
                showToast(result.message, 'error');
                results.innerHTML = '';
                return;
            }
            renderIconifyImportResults(result.data.icons || []);
        } catch (error) {
            showToast(t('icons.import_error', {message: error.message}), 'error');
            results.innerHTML = '';
        } finally {
            if (button) button.disabled = false;
        }
    }

    function renderIconifyImportResults(icons) {
        const results = document.getElementById('iconifyImportResults');
        const empty = document.getElementById('iconifyImportEmpty');
        if (!results) return;
        results.innerHTML = '';
        if (!icons.length) {
            if (empty) empty.style.display = '';
            return;
        }
        if (empty) empty.style.display = 'none';

        icons.forEach(function(iconItem) {
            const card = document.createElement('article');
            card.className = 'iconify-import-card';
            card.innerHTML = `
                <div class="iconify-import-card__preview">
                    <svg viewBox="${escapeHtml(iconItem.viewBox || '0 0 24 24')}" ${iconSvgAttributes(iconItem)}>${iconSvgMarkup(iconItem)}</svg>
                </div>
                <div class="iconify-import-card__body">
                    <strong>${escapeHtml(iconItem.name || '')}</strong>
                    <span>${escapeHtml(iconItem.setLabel || iconItem.prefix || '')} · ${escapeHtml(iconItem.license || '')}</span>
                    <code>${escapeHtml(iconItem.key || '')}</code>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" data-iconify-import="${escapeHtml(iconItem.full || '')}" data-iconify-key="${escapeHtml(iconItem.key || '')}">${iconManagerData.some(item => item.key === iconItem.key) ? t('icons.imported_short') : t('icons.import')}</button>
            `;
            if (iconManagerData.some(item => item.key === iconItem.key)) {
                card.querySelector('[data-iconify-import]').disabled = true;
                card.classList.add('iconify-import-card--imported');
            }
            results.appendChild(card);
        });

        results.querySelectorAll('[data-iconify-import]').forEach(function(button) {
            button.addEventListener('click', function() {
                importIconifyIcon(button.dataset.iconifyImport, button);
            });
        });
    }

    async function importIconifyIcon(fullName, button) {
        const formData = new FormData();
        formData.append('action', 'iconify-import');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('icon', fullName);

        if (button) button.disabled = true;
        try {
            const result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, 15000);
            if (!result.success) {
                showToast(result.message, 'error');
                return;
            }
            iconManagerSortMode = 'newest';
            const sortInput = document.getElementById('iconManagerSort');
            if (sortInput) sortInput.value = 'newest';
            const importedKey = String(fullName || '').replace(':', '-');
            highlightIconManagerCard(importedKey);
            renderIconManager(result.data);
            showToast(t('icons.imported'), 'success');
            if (button) {
                button.textContent = t('icons.imported_short');
                button.disabled = true;
                button.closest('.iconify-import-card')?.classList.add('iconify-import-card--imported');
            }
        } catch (error) {
            showToast(t('icons.import_error', {message: error.message}), 'error');
        } finally {
            if (button && button.textContent !== t('icons.imported_short')) button.disabled = false;
        }
    }

    function updateIconManagerPreview() {
        const preview = document.getElementById('iconManagerPreview');
        const svgInput = document.getElementById('iconManagerSvg');
        const viewBoxInput = document.getElementById('iconManagerViewBox');
        if (preview) {
            preview.setAttribute('viewBox', normalizeIconViewBoxClient(viewBoxInput?.value || ''));
            preview.innerHTML = sanitizeIconSvgClient(svgInput.value);
        }
        const oldKey = document.getElementById('iconManagerOldKey').value;
        const newKey = document.getElementById('iconManagerKey').value;
        const warning = document.getElementById('iconRenameWarning');
        if (warning) warning.hidden = !(oldKey && newKey && oldKey !== newKey);
    }

    document.getElementById('iconManagerSvg')?.addEventListener('input', function() {
        const viewBoxInput = document.getElementById('iconManagerViewBox');
        const extractedViewBox = extractIconViewBoxClient(this.value);
        if (extractedViewBox && viewBoxInput && (!viewBoxInput.value.trim() || normalizeIconViewBoxClient(viewBoxInput.value) === '0 0 24 24')) {
            viewBoxInput.value = extractedViewBox;
        }
        updateIconManagerPreview();
    });
    document.getElementById('iconManagerViewBox')?.addEventListener('input', updateIconManagerPreview);
    document.getElementById('iconManagerKey')?.addEventListener('input', updateIconManagerPreview);
    document.getElementById('iconManagerCleanupBtn')?.addEventListener('click', function() {
        const input = document.getElementById('iconManagerSvg');
        const extractedViewBox = extractIconViewBoxClient(input.value);
        if (extractedViewBox) document.getElementById('iconManagerViewBox').value = extractedViewBox;
        input.value = sanitizeIconSvgClient(input.value);
        updateIconManagerPreview();
        showToast(t('icons.cleanup_done'), 'success');
    });
    document.getElementById('iconManagerCurrentColorBtn')?.addEventListener('click', function() {
        const input = document.getElementById('iconManagerSvg');
        input.value = forceIconCurrentColor(input.value);
        updateIconManagerPreview();
        showToast(t('icons.current_color_done'), 'success');
    });
    document.getElementById('iconManagerForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const oldKey = document.getElementById('iconManagerOldKey').value;
        const newKey = document.getElementById('iconManagerKey').value.trim();
        if (!/^[a-z0-9][a-z0-9_-]{0,63}$/.test(newKey)) {
            showToast('Invalid icon key. Use lowercase letters, numbers, hyphens, and underscores.', 'error');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'save-icon');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('old_key', oldKey);
        formData.append('key', newKey);
        formData.append('label', document.getElementById('iconManagerLabel').value);
        formData.append('tags', document.getElementById('iconManagerTags').value);
        formData.append('viewBox', document.getElementById('iconManagerViewBox').value);
        formData.append('svg', document.getElementById('iconManagerSvg').value);

        try {
            const result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, 15000);
            if (result.success) {
                closeIconManagerModal();
                iconManagerSortMode = 'newest';
                const sortInput = document.getElementById('iconManagerSort');
                if (sortInput) sortInput.value = 'newest';
                highlightIconManagerCard(newKey);
                renderIconManager(result.data);
                showToast(t('icons.saved'), 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('icons.save_error', {message: error.message}), 'error');
        }
    });
    document.getElementById('iconifyImportSet')?.addEventListener('change', function() {
        updateIconifyImportLicense();
        const query = document.getElementById('iconifyImportQuery')?.value.trim() || '';
        if (query.length >= 2) searchIconifyImport();
    });
    document.getElementById('iconManagerSearch')?.addEventListener('input', function() {
        iconManagerSearchTerm = this.value.trim();
        dashboardListPages.icons = 1;
        renderIconManager();
    });
    document.getElementById('iconManagerSort')?.addEventListener('change', function() {
        iconManagerSortMode = this.value || 'alpha';
        dashboardListPages.icons = 1;
        renderIconManager();
    });
    document.getElementById('iconifyImportQuery')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchIconifyImport();
        }
    });
    document.getElementById('iconifyImportSearchBtn')?.addEventListener('click', searchIconifyImport);

    function deleteIconManagerIcon(iconItem) {
        showModal(t('icons.delete_icon'), t('icons.delete_confirm', {key: iconItem.key}), async function() {
            closeModal();
            const formData = new FormData();
            formData.append('action', 'delete-icon');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('key', iconItem.key);

            try {
                const result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, 15000);
                if (result.success) {
                    renderIconManager(result.data);
                    showToast(t('icons.deleted'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('icons.delete_error', {message: error.message}), 'error');
            }
        });
    }
    <?php endif; ?>
