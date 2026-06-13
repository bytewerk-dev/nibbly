/**
 * nb-select — progressive enhancement for <select> elements.
 *
 * Replaces the unstylable native dropdown list with a token-styled listbox
 * while keeping the original <select> in the DOM as the source of truth, so
 * existing JS (.value reads/writes, change listeners, option rebuilds) keeps
 * working unchanged. Dynamically added selects (editor modals, media manager)
 * are enhanced automatically via MutationObserver.
 *
 * Opt out per element with: <select data-native-select>.
 */
(function () {
    'use strict';

    var openInstance = null;

    function isEligible(select) {
        return select instanceof HTMLSelectElement
            && !select.multiple
            && !select.hasAttribute('size')
            && !select.hasAttribute('data-native-select')
            && !select._nbSelect;
    }

    function selectedLabel(select) {
        var option = select.options[select.selectedIndex];
        return option ? option.textContent : '';
    }

    function removeOrphanLists() {
        document.querySelectorAll('.nb-select__list[data-nb-select-list]').forEach(function (list) {
            if (!list._nbOwner || !list._nbOwner.isConnected) {
                list.remove();
            }
        });
    }

    function enhance(select) {
        if (!isEligible(select)) return;

        var wrap = document.createElement('div');
        wrap.className = 'nb-select';
        // Expose the select's classes as prefixed modifiers on the wrapper
        // for contextual styling. The button itself must NOT mirror them:
        // existing JS queries selects by class and would match the button.
        String(select.className || '').split(/\s+/).forEach(function (cls) {
            if (cls) wrap.classList.add('nb-select--' + cls);
        });
        select.parentNode.insertBefore(wrap, select);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'nb-select__button';
        button.setAttribute('role', 'combobox');
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        var selectLabel = select.getAttribute('aria-label');
        if (selectLabel) button.setAttribute('aria-label', selectLabel);
        if (select.id) {
            button.setAttribute('data-nb-select-for', select.id);
            var labelEl = document.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(select.id) : select.id) + '"]');
            if (labelEl) {
                if (!labelEl.id) labelEl.id = select.id + '-nb-label';
                button.setAttribute('aria-labelledby', labelEl.id);
            }
        }

        var valueEl = document.createElement('span');
        valueEl.className = 'nb-select__value';
        button.appendChild(valueEl);

        var caret = document.createElement('span');
        caret.className = 'nb-select__caret';
        caret.setAttribute('aria-hidden', 'true');
        caret.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
        button.appendChild(caret);

        // The list lives on <body> with fixed positioning so it can never be
        // clipped by overflow/transform containers (modals, tables).
        var list = document.createElement('div');
        list.className = 'nb-select__list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('data-nb-select-list', '');
        list.hidden = true;
        list._nbOwner = select;

        wrap.appendChild(button);
        wrap.appendChild(select);
        document.body.appendChild(list);

        select.classList.add('nb-select__native');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        var state = { activeIndex: -1, values: [] };

        function syncFromSelect() {
            valueEl.textContent = selectedLabel(select);
            button.disabled = select.disabled;
        }

        function position() {
            var rect = button.getBoundingClientRect();
            var spaceBelow = window.innerHeight - rect.bottom;
            var maxHeight = Math.min(280, Math.max(spaceBelow - 12, 120));
            list.style.minWidth = rect.width + 'px';
            list.style.left = Math.round(rect.left) + 'px';
            list.style.maxHeight = Math.round(maxHeight) + 'px';
            if (spaceBelow < 140 && rect.top > window.innerHeight - rect.bottom) {
                list.style.top = 'auto';
                list.style.bottom = Math.round(window.innerHeight - rect.top + 4) + 'px';
                list.style.maxHeight = Math.round(Math.min(280, rect.top - 12)) + 'px';
            } else {
                list.style.bottom = 'auto';
                list.style.top = Math.round(rect.bottom + 4) + 'px';
            }
        }

        function highlight(index, scroll) {
            state.activeIndex = index;
            Array.from(list.querySelectorAll('.nb-select__option')).forEach(function (item, i) {
                var active = i === index;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
                if (active && scroll !== false) item.scrollIntoView({ block: 'nearest' });
            });
        }

        function close() {
            if (openInstance === api) openInstance = null;
            list.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            window.removeEventListener('scroll', onReposition, true);
            window.removeEventListener('resize', onReposition);
        }

        function onReposition() {
            if (!list.hidden) position();
        }

        function commit(index) {
            var value = state.values[index];
            if (value === undefined) return;
            close();
            if (select.value !== value) {
                select.value = value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncFromSelect();
            button.focus();
        }

        function buildList() {
            list.innerHTML = '';
            state.values = [];
            var flatIndex = 0;
            var build = function (parent) {
                Array.from(parent.children).forEach(function (child) {
                    if (child.tagName === 'OPTGROUP') {
                        var group = document.createElement('div');
                        group.className = 'nb-select__group';
                        group.textContent = child.label;
                        list.appendChild(group);
                        build(child);
                        return;
                    }
                    if (child.tagName !== 'OPTION') return;
                    var item = document.createElement('div');
                    item.className = 'nb-select__option';
                    item.setAttribute('role', 'option');
                    item.textContent = child.textContent;
                    if (child.disabled) {
                        item.setAttribute('aria-disabled', 'true');
                    } else {
                        var index = flatIndex;
                        item.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                            commit(index);
                        });
                    }
                    if (child.disabled) {
                        item.dataset.nbDisabled = '1';
                    }
                    state.values.push(child.disabled ? undefined : child.value);
                    if (child.value === select.value && !child.disabled) {
                        item.classList.add('is-selected');
                    }
                    list.appendChild(item);
                    flatIndex++;
                });
            };
            build(select);
        }

        function open() {
            if (button.disabled) return;
            if (openInstance && openInstance !== api) openInstance.close();
            syncFromSelect();
            buildList();
            if (!state.values.length) return;
            removeOrphanLists();
            list.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            position();
            openInstance = api;
            var selectedIndex = state.values.indexOf(select.value);
            highlight(selectedIndex, true);
            window.addEventListener('scroll', onReposition, true);
            window.addEventListener('resize', onReposition);
        }

        function moveActive(delta) {
            var count = state.values.length;
            if (!count) return;
            var index = state.activeIndex;
            for (var step = 0; step < count; step++) {
                index = (index + delta + count) % count;
                if (state.values[index] !== undefined) break;
            }
            highlight(index);
        }

        button.addEventListener('click', function () {
            if (list.hidden) open(); else close();
        });
        button.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (list.hidden) { open(); return; }
                moveActive(event.key === 'ArrowDown' ? 1 : -1);
            } else if (event.key === 'Enter' || event.key === ' ') {
                if (!list.hidden) {
                    event.preventDefault();
                    if (state.activeIndex >= 0) commit(state.activeIndex);
                }
            } else if (event.key === 'Escape' && !list.hidden) {
                event.stopPropagation();
                close();
            } else if (event.key === 'Tab') {
                close();
            } else if (event.key.length === 1 && /\S/.test(event.key)) {
                // Type-ahead: jump to the next option starting with the key.
                if (list.hidden) open();
                var query = event.key.toLowerCase();
                var labels = Array.from(list.querySelectorAll('.nb-select__option')).map(function (item) {
                    return item.textContent.trim().toLowerCase();
                });
                for (var offset = 1; offset <= labels.length; offset++) {
                    var i = (state.activeIndex + offset) % labels.length;
                    if (state.values[i] !== undefined && labels[i].indexOf(query) === 0) {
                        highlight(i);
                        break;
                    }
                }
            }
        });
        button.addEventListener('blur', function () {
            window.setTimeout(function () {
                if (!list.contains(document.activeElement) && document.activeElement !== button) close();
            }, 120);
        });
        button.addEventListener('focus', syncFromSelect);
        button.addEventListener('mouseenter', syncFromSelect);

        select.addEventListener('change', syncFromSelect);
        var observer = new MutationObserver(function () {
            syncFromSelect();
            if (!list.hidden) buildList();
        });
        observer.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });

        var api = { close: close, sync: syncFromSelect, select: select };
        select._nbSelect = api;
        syncFromSelect();
    }

    function enhanceWithin(root) {
        if (root instanceof HTMLSelectElement) {
            enhance(root);
            return;
        }
        if (root && root.querySelectorAll) {
            root.querySelectorAll('select').forEach(enhance);
        }
    }

    document.addEventListener('mousedown', function (event) {
        if (!openInstance) return;
        var target = event.target;
        if (target.closest && (target.closest('.nb-select__list') || target.closest('.nb-select'))) return;
        openInstance.close();
    });

    // Programmatic `select.value = x` / `select.selectedIndex = n` fire no
    // event, so hook the prototype setters to keep button labels in sync.
    ['value', 'selectedIndex'].forEach(function (prop) {
        var descriptor = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, prop);
        if (!descriptor || !descriptor.set) return;
        Object.defineProperty(HTMLSelectElement.prototype, prop, {
            configurable: true,
            enumerable: descriptor.enumerable,
            get: descriptor.get,
            set: function (value) {
                descriptor.set.call(this, value);
                if (this._nbSelect) this._nbSelect.sync();
            }
        });
    });

    function init() {
        enhanceWithin(document);
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) enhanceWithin(node);
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    window.nbSelectSync = function (select) {
        if (select && select._nbSelect) select._nbSelect.sync();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
