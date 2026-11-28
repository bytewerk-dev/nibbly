<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    <?php if ($isAdminUser): ?>
    // ============================================================
    // ICON MANAGER
    // ============================================================

    let iconManagerData = [];
    let iconManagerSortMode = 'alpha';
    let iconManagerSearchTerm = '';
    const iconManagerFilters = { motion: null, category: null, library: null };
    const bundledIconCategories = ['navigation', 'actions', 'content', 'communication', 'system', 'status'];
    let iconManagerHighlightedKeys = new Set();
    let iconManagerHighlightTimer = null;
    let iconManagerSettings = { strokeWidth: '', defaultStrokeWidth: '2' };
    // Set once the editor picks a style by hand, so SVG edits stop overriding the choice.
    let iconManagerStyleTouched = false;
    let iconManagerCustomStrokeWidth = '';
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
            'gradientUnits', 'gradientTransform', 'data-nb-motion', 'data-nb-ua', 'data-nb-trigger', 'data-nb-play', 'data-nb-reverse', 'data-nb-start',
            ...Object.keys(window.NB_ICON_EVENT_ACTIONS).map(event => 'data-nb-' + event)
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

    function iconCategoryLabel(category) {
        return bundledIconCategories.includes(category) ? t('icons.category_' + category) : category;
    }

    function iconMotionType(svg) {
        if (!svg.includes('data-nb-')) return 'static';
        const doc = new DOMParser().parseFromString('<svg>' + svg + '</svg>', 'image/svg+xml');
        if (doc.querySelector('[data-nb-motion]')) return 'animated';
        const animated = Array.from(doc.querySelectorAll('g[data-nb-ua][data-nb-trigger]')).some(group => {
            const mode = group.getAttribute('data-nb-play') || group.getAttribute('data-nb-trigger');
            if (mode === 'events') {
                const clips = IconEventFields.clipsFrom(group);
                return Object.entries(window.NB_ICON_EVENT_ACTIONS).some(([key, actions]) => (clips[key]?.action || actions[0]) !== 'off');
            }
            return mode !== 'static' && ICON_PLAYBACK_MODES.includes(mode);
        });
        return animated ? 'animated' : 'static';
    }

    function matchesIconFilters(item, except) {
        return Object.entries(iconManagerFilters).every(([field, value]) =>
            field === except || value === null || item[field] === value);
    }

    function buildIconManagerFilters() {
        for (const list of document.querySelectorAll('#iconManagerFilterLinks [data-icon-filter]')) {
            const field = list.dataset.iconFilter;
            const values = field === 'motion' ? ['animated', 'static'] : [...new Set(iconManagerData.map(item => item[field]))];
            const options = values.map(value => {
                let label = value;
                if (field === 'motion') label = t('icons.' + value);
                if (field === 'category') label = value ? iconCategoryLabel(value) : t('icons.no_category');
                if (field === 'library' && !value) label = t('icons.no_library');
                return { value, label };
            });
            if (field !== 'motion') options.sort((a, b) => a.label.localeCompare(b.label));
            if (field === 'category') options.unshift({ value: null, label: t('filter.all') });

            list.replaceChildren(...options.map(({ value, label }) => {
                const entry = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                if (value !== null) button.dataset.filterValue = value;
                const count = document.createElement('span');
                count.className = 'nb-filter-links__count';
                button.append(label + ' ', count);
                button.addEventListener('click', () => {
                    if (value === null) {
                        for (const key of Object.keys(iconManagerFilters)) iconManagerFilters[key] = null;
                    } else {
                        iconManagerFilters[field] = iconManagerFilters[field] === value ? null : value;
                    }
                    dashboardListPages.icons = 1;
                    renderIconManager();
                });
                entry.appendChild(button);
                return entry;
            }));
        }
    }

    function updateIconManagerFilters(searchResults) {
        const allSelected = Object.values(iconManagerFilters).every(value => value === null);
        for (const list of document.querySelectorAll('#iconManagerFilterLinks [data-icon-filter]')) {
            const field = list.dataset.iconFilter;
            const counts = new Map();
            for (const item of searchResults) {
                if (!matchesIconFilters(item, field)) continue;
                counts.set(item[field], (counts.get(item[field]) || 0) + 1);
            }
            for (const button of list.querySelectorAll('button')) {
                const value = button.dataset.filterValue;
                const isAll = value === undefined;
                const active = isAll ? allSelected : iconManagerFilters[field] === value;
                const count = isAll ? searchResults.length : (counts.get(value) || 0);
                button.setAttribute('aria-pressed', String(active));
                button.disabled = !isAll && !active && count === 0;
                button.lastElementChild.textContent = '(' + count + ')';
            }
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
                    iconItem.source || '',
                    iconCategoryLabel(iconItem.category),
                    iconItem.library
                ].join(' ').toLowerCase();
                return haystack.includes(term);
            });
        }

        updateIconManagerFilters(icons);
        icons = icons.filter(item => matchesIconFilters(item));
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

    function iconManagerGlobalStrokeWidth() {
        return iconManagerSettings.strokeWidth || iconManagerSettings.defaultStrokeWidth || '2';
    }

    function applyIconManagerSettings(settings) {
        if (settings && typeof settings === 'object') {
            iconManagerSettings = Object.assign({}, iconManagerSettings, settings);
        }
        const input = document.getElementById('iconManagerGlobalStrokeWidth');
        if (input && document.activeElement !== input) {
            input.value = iconManagerSettings.strokeWidth || '';
            input.placeholder = iconManagerSettings.defaultStrokeWidth || '2';
        }
        const perIcon = document.getElementById('iconManagerStrokeWidth');
        if (perIcon?.disabled) perIcon.value = iconManagerGlobalStrokeWidth();
    }

    function syncIconStrokeMode() {
        const input = document.getElementById('iconManagerStrokeWidth');
        const inherit = document.getElementById('iconManagerInheritStroke').checked;
        input.disabled = inherit;
        input.required = !inherit;
        if (inherit) {
            input.value = iconManagerGlobalStrokeWidth();
        } else {
            const svg = document.getElementById('iconManagerSvg').value;
            input.value = iconManagerCustomStrokeWidth || extractUniformStrokeWidthClient(svg).strokeWidth || iconManagerGlobalStrokeWidth();
        }
    }

    function iconSvgAttributes(iconItem, strokeWidth) {
        if ((iconItem.style || 'stroke') === 'fill') return 'fill="currentColor"';
        const width = strokeWidth || iconItem.strokeWidth || iconManagerGlobalStrokeWidth();
        return 'fill="none" stroke="currentColor" stroke-width="' + escapeHtml(String(width)) + '" stroke-linecap="round" stroke-linejoin="round"';
    }

    // Mirrors detectIconStyleFromSvg() in includes/content-loader.php.
    function detectIconStyleClient(svg) {
        const markup = String(svg || '');
        if (!markup.trim()) return '';
        if (/\sstroke\s*=\s*(["'])(?!\s*none\s*\1)[^"']+\1/i.test(markup)) return 'stroke';
        const shapes = markup.match(/<\s*(path|circle|ellipse|line|polyline|polygon|rect)\b[^>]*>/gi) || [];
        if (!shapes.length) return '';
        const groupFill = /<\s*g\b[^>]*\sfill\s*=\s*(["'])(?!\s*none\s*\1)[^"']+\1/i.test(markup);
        const fillAttr = /\sfill\s*=\s*(["'])(?!\s*none\s*\1)[^"']+\1/i;
        return shapes.every(shape => fillAttr.test(shape) || groupFill) ? 'fill' : '';
    }

    function normalizeStrokeWidthClient(value) {
        const number = parseFloat(String(value ?? '').replace(',', '.'));
        if (!isFinite(number) || number < 0.25 || number > 6) return '';
        return String(Math.round(number * 100) / 100);
    }

    // Mirrors extractUniformIconStrokeWidth() in includes/content-loader.php.
    function extractUniformStrokeWidthClient(svg) {
        const markup = String(svg || '');
        const matches = [...markup.matchAll(/\sstroke-width\s*=\s*(["'])\s*([0-9.]+)(?:px)?\s*\1/gi)];
        if (!matches.length) return { svg: markup, strokeWidth: '' };
        const values = new Set(matches.map(m => normalizeStrokeWidthClient(m[2])));
        if (values.size !== 1 || values.has('')) return { svg: markup, strokeWidth: '' };
        const shapes = markup.match(/<\s*(path|circle|ellipse|line|polyline|polygon|rect)\b[^>]*>/gi) || [];
        const shapesWithWidth = shapes.filter(shape => /\sstroke-width\s*=/i.test(shape)).length;
        const groupsWithWidth = (markup.match(/<\s*g\b[^>]*\sstroke-width\s*=/gi) || []).length;
        const uniform = (shapes.length > 0 && shapesWithWidth === shapes.length) || (shapesWithWidth === 0 && groupsWithWidth > 0);
        if (!uniform) return { svg: markup, strokeWidth: '' };
        return {
            svg: markup.replace(/\sstroke-width\s*=\s*(["'])[^"']*\1/gi, '').trim(),
            strokeWidth: [...values][0],
        };
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
        if (!grid) return;

        if (data) {
            iconManagerData = (data.icons || []).map(item => ({ ...item, motion: iconMotionType(item.svg) }));
            for (const field of Object.keys(iconManagerFilters)) {
                if (!iconManagerData.some(item => item[field] === iconManagerFilters[field])) iconManagerFilters[field] = null;
            }
            buildIconManagerFilters();
            applyIconManagerSettings(data.settings);
        }

        grid.innerHTML = '';

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
            const preview = card.querySelector('.icon-manager-card__preview');
            const motion = preview.querySelector('g[data-nb-ua][data-nb-trigger]');
            if (motion) {
                const mode = t('icons.motion_' + (motion.dataset.nbPlay || motion.dataset.nbTrigger));
                const label = document.createElement('small');
                label.textContent = motion.dataset.nbReverse === 'true' ? mode + ' · ' + t('icons.motion_reverse') : mode;
                card.querySelector('.icon-manager-card__body').append(label);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = preview.className;
                button.dataset.nbPreview = '';
                button.setAttribute('aria-label', (iconItem.label || iconItem.key) + ' · ' + mode);
                button.append(...preview.childNodes);
                preview.replaceWith(button);
            }
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
        const categories = new Set([...bundledIconCategories, ...iconManagerData.map(item => item.category).filter(Boolean)]);
        const libraries = new Set([...Object.values(ICONIFY_IMPORT_SETS).map(set => set.label), ...iconManagerData.map(item => item.library).filter(Boolean)]);
        function fillSuggestions(id, values, labelFor) {
            const options = Array.from(values, value => {
                const option = document.createElement('option');
                option.value = labelFor(value);
                option.dataset.key = value;
                return option;
            }).sort((a, b) => a.value.localeCompare(b.value));
            document.getElementById(id).replaceChildren(...options);
        }
        fillSuggestions('iconManagerCategories', categories, iconCategoryLabel);
        fillSuggestions('iconManagerLibraries', libraries, value => value);
        document.getElementById('iconManagerCategory').value = iconCategoryLabel(iconItem?.category || '');
        const library = document.getElementById('iconManagerLibrary');
        library.value = iconItem?.library || '';
        library.readOnly = !!iconItem?.libraryFromSource;
        library.title = library.readOnly ? t('icons.library_from_source') : '';
        document.getElementById('iconManagerViewBox').value = isEdit ? (iconItem.viewBox || '0 0 24 24') : '0 0 24 24';
        document.getElementById('iconManagerSvg').value = isEdit ? (iconItem.svg || '') : '';
        document.getElementById('iconManagerStyle').value = isEdit ? (iconItem.style || 'stroke') : 'stroke';
        iconManagerCustomStrokeWidth = iconItem?.strokeWidth || '';
        document.getElementById('iconManagerInheritStroke').checked = !iconManagerCustomStrokeWidth;
        syncIconStrokeMode();
        iconManagerStyleTouched = isEdit;
        applyIconManagerSettings();
        document.getElementById('iconRenameWarning').hidden = true;
        document.getElementById('iconManagerSvgDetails').open = false;
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
            const style = document.getElementById('iconManagerStyle')?.value || 'stroke';
            const strokeWidth = normalizeStrokeWidthClient(document.getElementById('iconManagerStrokeWidth')?.value);
            let markup = sanitizeIconSvgClient(svgInput.value);
            if (style === 'stroke') {
                // Same as on save: a uniform stroke-width leaves the markup so the wrapper width applies.
                markup = extractUniformStrokeWidthClient(markup).svg;
            }
            const previewItem = { style: style, strokeWidth: strokeWidth };
            preview.setAttribute('viewBox', normalizeIconViewBoxClient(viewBoxInput?.value || ''));
            ['fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'].forEach(name => preview.removeAttribute(name));
            iconSvgAttributes(previewItem, strokeWidth).replace(/([a-z-]+)="([^"]*)"/g, function(match, name, value) {
                preview.setAttribute(name, value);
                return match;
            });
            preview.innerHTML = iconSvgMarkup(Object.assign({ svg: markup }, previewItem));
            const motion = preview.querySelector('g[data-nb-ua][data-nb-trigger]');
            document.getElementById('iconManagerPlayback').hidden = !motion;
            const svgDetails = document.getElementById('iconManagerSvgDetails');
            svgDetails.querySelector('summary').hidden = !motion;
            svgDetails.querySelector('.icon-manager-svg-tools').hidden = !!motion;
            if (!motion) svgDetails.open = true;
            document.getElementById('iconManagerSvgHint').textContent = t(motion ? 'icons.static_svg_hint' : 'icons.svg_hint');
            const button = document.getElementById('iconManagerPlayPreview');
            button.disabled = !motion;
            button.removeAttribute('aria-pressed');
            if (motion) {
                const trigger = document.getElementById('iconManagerTrigger');
                trigger.options[0].textContent = t('icons.motion_original', {mode: t('icons.motion_' + motion.dataset.nbTrigger)});
                trigger.value = motion.dataset.nbPlay || '';
                const reverse = document.getElementById('iconManagerReverse');
                reverse.checked = motion.dataset.nbReverse === 'true';
                reverse.disabled = trigger.value === 'static';
                iconManagerEvents.load(IconEventFields.clipsFrom(motion));
                iconManagerEvents.update(trigger.value === 'events');
                document.getElementById('iconManagerSource').href = 'https://useanimations.com/icons/' + encodeURIComponent(motion.dataset.nbUa);
            }
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
        const styleSelect = document.getElementById('iconManagerStyle');
        if (styleSelect && !iconManagerStyleTouched) {
            const detected = detectIconStyleClient(this.value);
            if (detected) styleSelect.value = detected;
        }
        updateIconManagerPreview();
    });
    function changeIconPlayback() {
        const field = document.getElementById('iconManagerSvg');
        const doc = new DOMParser().parseFromString('<svg xmlns="http://www.w3.org/2000/svg">' + sanitizeIconSvgClient(field.value) + '</svg>', 'image/svg+xml');
        const motion = doc.querySelector('g[data-nb-ua][data-nb-trigger]');
        if (!motion) return;
        const mode = document.getElementById('iconManagerTrigger').value;
        if (mode) motion.setAttribute('data-nb-play', mode);
        else motion.removeAttribute('data-nb-play');
        const attrs = mode === 'events' ? NibblyIconPlacement.eventAttributes(iconManagerEvents.value) : {};
        for (const event of Object.keys(window.NB_ICON_EVENT_ACTIONS)) {
            if (attrs[event]) motion.setAttribute('data-nb-' + event, attrs[event]);
            else motion.removeAttribute('data-nb-' + event);
        }
        if (document.getElementById('iconManagerReverse').checked) motion.setAttribute('data-nb-reverse', 'true');
        else motion.removeAttribute('data-nb-reverse');
        // XML serialization repeats the namespace on every top-level element.
        field.value = doc.documentElement.innerHTML.replaceAll(' xmlns="http://www.w3.org/2000/svg"', '');
        updateIconManagerPreview();
    }
    document.getElementById('iconManagerTrigger')?.addEventListener('change', changeIconPlayback);
    const iconManagerEvents = new IconEventFields(document.getElementById('iconManagerEvents'), t);
    iconManagerEvents.container.addEventListener('change', changeIconPlayback);
    document.getElementById('iconManagerReverse')?.addEventListener('change', changeIconPlayback);
    document.getElementById('iconManagerViewBox')?.addEventListener('input', updateIconManagerPreview);
    document.getElementById('iconManagerStrokeWidth')?.addEventListener('input', updateIconManagerPreview);
    document.getElementById('iconManagerInheritStroke')?.addEventListener('change', function() {
        if (this.checked) iconManagerCustomStrokeWidth = normalizeStrokeWidthClient(document.getElementById('iconManagerStrokeWidth').value);
        syncIconStrokeMode();
        updateIconManagerPreview();
    });
    document.getElementById('iconManagerStyle')?.addEventListener('change', function() {
        iconManagerStyleTouched = true;
        updateIconManagerPreview();
    });
    document.getElementById('iconManagerGlobalStrokeWidth')?.addEventListener('change', async function() {
        const input = this;
        const raw = input.value.trim();
        if (raw !== '' && !normalizeStrokeWidthClient(raw)) {
            showToast(t('icons.stroke_width_invalid'), 'error');
            input.value = iconManagerSettings.strokeWidth || '';
            return;
        }
        const formData = new FormData();
        formData.append('action', 'save-icon-settings');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('strokeWidth', raw);
        input.disabled = true;
        try {
            const result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, 15000);
            if (result.success) {
                renderIconManager(result.data);
                showToast(t('icons.settings_saved'), 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('icons.settings_error', {message: error.message}), 'error');
        } finally {
            input.disabled = false;
        }
    });
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
        for (const [field, id] of [['category', 'iconManagerCategory'], ['library', 'iconManagerLibrary']]) {
            const input = document.getElementById(id);
            const value = input.value.trim();
            const match = Array.from(input.list.options).find(option => option.value.toLowerCase() === value.toLowerCase());
            formData.append(field, match ? match.dataset.key : value);
        }
        formData.append('viewBox', document.getElementById('iconManagerViewBox').value);
        formData.append('svg', document.getElementById('iconManagerSvg').value);
        formData.append('style', document.getElementById('iconManagerStyle').value);
        const widthInput = document.getElementById('iconManagerStrokeWidth');
        formData.append('strokeWidth', widthInput.disabled ? '' : widthInput.value);

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
