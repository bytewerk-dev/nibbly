/*
 * Rich text fields of Nibbly's editors: the Visual / Code editor of block content (richEditor), the one-line
 * variant for teasers (inlineEditor), the HTML code field with colours and line numbers (codeField,
 * codeToolbar) and the sanitizer in front of the editing surface (cleanHtml). Used by the dashboard
 * (admin/dashboard/scripts/page-writing.php, news.php, people.php) and by the block dialog of the visual
 * editor on the site (js/inline-editor.js). Styles: css/rich-editor.css. Needs the global t() for labels.
 */
(() => {
    'use strict';
    const escapeHtml = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const toolbarIcons = {
        italic: '<i>I</i>',
        unorderedList: '<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6h12M9 12h12M9 18h12"/><path d="M4 6h.01M4 12h.01M4 18h.01" stroke-width="3"/></svg>',
        orderedList: '<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6h11M10 12h11M10 18h11"/><path d="M3 3h1v6M2.5 9h3M2 14a2 2 0 0 1 4 0c0 1-1 2-4 5h4"/></svg>',
        link: '<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        unlink: '<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/><path d="m3 3 18 18"/></svg>',
        eraser: '<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21M22 21H7M5 11l9 9"/></svg>',
        quote: '<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 17h3l2-4V7H5v6h3zM14 17h3l2-4V7h-6v6h3z"/></svg>',
        footnote: '<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16M5.07 8l13.86 8M5.07 16 18.93 8"/></svg>',
    };
    // Mirrors includes/html-sanitizer.php (the server's sanitizer remains final)
    const allowedTags = new Set('p br strong b em i u a ul ol li h1 h2 h3 h4 h5 h6 blockquote span div figure figcaption img table thead tbody tfoot tr th td caption sup sub hr pre code small cite q mark s del ins abbr dl dt dd details summary video audio source'.split(' '));
    const mediaAttrs = { img: ['src','alt','width','height','loading','decoding'], td: ['colspan','rowspan','scope'], th: ['colspan','rowspan','scope'], video: ['src','controls','preload','poster','playsinline','muted','loop'], audio: ['src','controls','preload','loop'], source: ['src','type'], details: ['open'], abbr: ['title'] };
    const safeSrc = value => {
        const probe = value.replace(/[\x00-\x20\x7f]+/g, '');
        return probe !== '' && !probe.includes('\\') && !probe.startsWith('//') && (!/^[a-z][a-z0-9+.-]*:/i.test(probe) || /^https?:/i.test(probe));
    };
    const dropTags = new Set('script style iframe object embed svg math template form input button textarea select link meta base'.split(' '));
    const safeHref = value => {
        const probe = value.replace(/[\x00-\x20\x7f]+/g, '');
        return !probe.includes('\\') && !probe.startsWith('//') && (!/^[a-z][a-z0-9+.-]*:/i.test(probe) || /^(https?|mailto|tel):/i.test(probe));
    };
    // Inert parsing before inserting HTML into the editing surface. The server's sanitizer remains final.
    function cleanHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        const clean = parent => [...parent.childNodes].forEach(node => {
            if (node.nodeType === 8) return node.remove();
            if (node.nodeType !== 1) return;
            const tag = node.tagName.toLowerCase();
            if (dropTags.has(tag)) return node.remove();
            clean(node);
            if (!allowedTags.has(tag)) return node.replaceWith(...node.childNodes);
            [...node.attributes].forEach(({name, value}) => {
                if (name === 'style') {
                    const styles = value.split(';').filter(declaration => {
                        const [property, ...rest] = declaration.split(':');
                        const css = rest.join(':').trim();
                        return /^(color|background-color|text-align|font-weight|font-style|text-decoration|font-size|letter-spacing|line-height)$/.test(property.trim().toLowerCase()) && /^[a-z0-9#.,%()\s-]+$/i.test(css) && !/url|expression|var\s*\(/i.test(css);
                    });
                    if (styles.length) node.setAttribute(name, styles.join(';')); else node.removeAttribute(name);
                    return;
                }
                if (!['class','title','lang','dir'].includes(name) && !(name === 'id' && /^[A-Za-z][\w:.-]{0,80}$/.test(value)) && !(tag === 'a' && ['href','target','rel','download'].includes(name)) && !(tag === 'ol' && ['start','reversed'].includes(name)) && !(mediaAttrs[tag] || []).includes(name)) node.removeAttribute(name);
                if (name === 'href' && !safeHref(value)) node.setAttribute(name, '#');
                if (['src','poster'].includes(name) && !safeSrc(value)) node.removeAttribute(name);
                if (name === 'target' && !['_blank','_self'].includes(value)) node.removeAttribute(name);
            });
            if (tag === 'a' && node.target === '_blank') node.rel = 'noopener noreferrer';
            if ((tag === 'img' || tag === 'source') && !node.hasAttribute('src')) node.remove();
        });
        clean(template.content);
        return template.innerHTML;
    }
    /*
     * HTML source field with colours, line numbers, line wrapping and auto-indent. The textarea stays the
     * real field (saves, dirty state and undo keep working); a layer behind it repeats the text, one block
     * per line, so wrapped lines grow their block and the line number stays beside the line's start.
     */
    const voidTags = new Set('area base br col embed hr img input link meta source track wbr'.split(' '));
    const escapeCode = text => text.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
    // [class, text] pieces of an HTML string; text outside tags stays unclassed
    function htmlTokens(code) {
        const tokens = [];
        const markup = /<!--[\s\S]*?(?:-->|$)|<!doctype[^>]*>?|<\/?[a-zA-Z][\w:.-]*(?:[^<>"']|"[^"]*"?|'[^']*'?)*>?|&#?\w+;/gi;
        let last = 0;
        for (const match of code.matchAll(markup)) {
            if (match.index > last) tokens.push(['', code.slice(last, match.index)]);
            const text = match[0];
            if (text.startsWith('<!--')) tokens.push(['comment', text]);
            else if (/^<!doctype/i.test(text)) tokens.push(['doctype', text]);
            else if (text.startsWith('&')) tokens.push(['entity', text]);
            else {
                const [, open, name, rest] = text.match(/^(<\/?)([^\s/>]+)([\s\S]*)$/);
                tokens.push(['punct', open], ['tag', name]);
                const attributes = /(\s+)|([^\s=/>"']+)(\s*=\s*)?("[^"]*"?|'[^']*'?|[^\s>"']+)?|(\/?>)|([\s\S])/g;
                for (const [piece, space, attr, equals, value, close, other] of rest.matchAll(attributes)) {
                    if (space) tokens.push(['', space]);
                    else if (attr) { tokens.push(['attr', attr]); if (equals) tokens.push(['punct', equals]); if (value) tokens.push(['value', value]); }
                    else if (close) tokens.push(['punct', close]);
                    else tokens.push(['', other ?? piece]);
                }
            }
            last = match.index + text.length;
        }
        if (last < code.length) tokens.push(['', code.slice(last)]);
        return tokens;
    }
    function highlightLines(code) {
        const lines = [''];
        htmlTokens(code).forEach(([type, text]) => text.split('\n').forEach((part, index) => {
            if (index) lines.push('');
            if (part) lines[lines.length - 1] += type ? `<span class="nb-code-${type}">${escapeCode(part)}</span>` : escapeCode(part);
        }));
        return lines.map(line => `<div class="nb-code-line">${line || '​'}</div>`).join('');
    }
    function codeField(source) {
        const box = document.createElement('div'); box.className = 'nb-code-field';
        const view = document.createElement('div'); view.className = 'nb-code-view'; view.setAttribute('aria-hidden', 'true');
        source.replaceWith(box); box.append(view, source);
        source.classList.add('nb-code-input'); source.spellcheck = false; source.setAttribute('wrap', 'soft');
        const refresh = () => {
            view.innerHTML = highlightLines(source.value);
            source.style.height = 'auto'; source.style.height = source.scrollHeight + 'px';
        };
        // Insert through the browser's text command, so Cmd/Ctrl+Z also undoes the inserted text
        const insert = (text, start = source.selectionStart, end = source.selectionEnd) => {
            source.setSelectionRange(start, end);
            if (!document.execCommand('insertText', false, text)) source.setRangeText(text, start, end, 'end');
            source.dispatchEvent(new Event('input', { bubbles: true }));
        };
        const unit = '  ';
        let tabLeaves = false; // after Escape, Tab moves on to the next control again
        source.addEventListener('keydown', event => {
            if (event.key === 'Escape') { tabLeaves = true; return; }
            if (event.isComposing || event.altKey || event.metaKey || event.ctrlKey) return;
            const value = source.value, start = source.selectionStart, end = source.selectionEnd;
            const lineStart = value.lastIndexOf('\n', start - 1) + 1;
            if (event.key === 'Enter' && !event.shiftKey) {
                const before = value.slice(lineStart, start);
                const indent = before.match(/^[ \t]*/)[0];
                // One step deeper after an opening tag that is not closed on the same line
                const open = before.match(/<([a-zA-Z][\w:.-]*)(?:[^<>"']|"[^"]*"|'[^']*')*>\s*$/);
                const deeper = open && !voidTags.has(open[1].toLowerCase()) && !/\/>\s*$/.test(before);
                event.preventDefault();
                if (deeper && /^\s*<\//.test(value.slice(end))) {
                    insert('\n' + indent + unit + '\n' + indent);
                    const caret = start + 1 + indent.length + unit.length;
                    source.setSelectionRange(caret, caret);
                } else insert('\n' + indent + (deeper ? unit : ''));
                return;
            }
            if (event.key !== 'Tab' || tabLeaves) { tabLeaves = false; return; }
            event.preventDefault();
            if (start === end && !event.shiftKey) return insert(unit);
            // Indent or outdent every line touched by the selection
            const blockEnd = value.indexOf('\n', end - (end > start && value[end - 1] === '\n' ? 1 : 0));
            const stop = blockEnd === -1 ? value.length : blockEnd;
            const lines = value.slice(lineStart, stop).split('\n');
            const changed = lines.map(line => event.shiftKey ? line.replace(/^( {1,2}|\t)/, '') : unit + line).join('\n');
            insert(changed, lineStart, stop);
            source.setSelectionRange(lineStart, lineStart + changed.length);
        });
        source.addEventListener('input', () => { if (!box.hidden) refresh(); });
        new ResizeObserver(() => { if (!box.hidden) refresh(); }).observe(box);
        return { box, refresh, insert };
    }

    // Simple prettify: block elements on their own lines, nested ones indented; inline markup stays in the text
    const blockTags = new Set('address article aside blockquote details div dl dt dd fieldset figcaption figure footer header hr h1 h2 h3 h4 h5 h6 li main nav ol p pre section summary table thead tbody tfoot tr td th ul'.split(' '));
    function formatHtml(html) {
        const template = document.createElement('template'); template.innerHTML = html;
        const lines = [];
        const isBlock = node => node.nodeType === 1 && blockTags.has(node.tagName.toLowerCase());
        const openTag = el => { const markup = el.cloneNode(false).outerHTML; return voidTags.has(el.tagName.toLowerCase()) ? markup : markup.slice(0, markup.lastIndexOf('</')); };
        const text = value => value.replace(/[&<>\u00a0]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\u00a0':'&nbsp;'}[c]));
        const inline = node => node.nodeType === 3 ? text(node.textContent) : node.nodeType === 8 ? `<!--${node.data}-->` : node.nodeType === 1 ? node.outerHTML : '';
        const walk = (nodes, depth) => {
            const pad = '  '.repeat(depth); let line = '';
            const flush = () => { const value = line.replace(/\s+/g, ' ').trim(); if (value) lines.push(pad + value); line = ''; };
            nodes.forEach(node => {
                if (!isBlock(node)) { line += inline(node); return; }
                flush();
                const tag = node.tagName.toLowerCase(), children = [...node.childNodes];
                if (voidTags.has(tag)) lines.push(pad + openTag(node));
                else if (tag === 'pre') lines.push(pad + node.outerHTML);
                else if (!children.some(isBlock)) lines.push(pad + openTag(node) + children.map(inline).join('').replace(/\s+/g, ' ').trim() + `</${tag}>`);
                else { lines.push(pad + openTag(node)); walk(children, depth + 1); lines.push(pad + `</${tag}>`); }
            });
            flush();
        };
        walk([...template.content.childNodes], 0);
        return lines.join('\n');
    }
    // Closing tags for everything still open before the caret, innermost first
    function unclosedTags(html) {
        const open = [];
        for (const [, slash, name, selfClose] of html.replace(/<!--[\s\S]*?-->/g, '').matchAll(/<(\/?)([a-zA-Z][\w:.-]*)(?:[^<>"']|"[^"]*"|'[^']*')*?(\/?)>/g)) {
            const tag = name.toLowerCase();
            if (voidTags.has(tag) || selfClose) continue;
            if (!slash) open.push(tag); else { const at = open.lastIndexOf(tag); if (at !== -1) open.length = at; }
        }
        return open.reverse().map(tag => `</${tag}>`).join('');
    }
    // Tag buttons above the code field, after WordPress's text editor ("quicktags")
    function codeToolbar(source, code, compact = false) {
        const bar = document.createElement('div'); bar.className = 'news-wysiwyg-toolbar nb-page-rich-toolbar nb-code-toolbar'; bar.setAttribute('role', 'toolbar'); bar.setAttribute('aria-label', t('editor.writing.code_tools'));
        const selection = () => [source.selectionStart, source.selectionEnd, source.value.slice(source.selectionStart, source.selectionEnd)];
        const wrap = (open, close) => {
            const [start, end, selected] = selection();
            source.focus(); code.insert(open + selected + close, start, end);
            source.setSelectionRange(start + open.length, start + open.length + selected.length);
        };
        const list = tag => {
            const [start, end, selected] = selection();
            const items = selected.split('\n').map(line => line.trim()).filter(Boolean);
            if (items.length < 2) return wrap(`<${tag}>\n  <li>`, `</li>\n</${tag}>`);
            source.focus(); code.insert(`<${tag}>\n${items.map(item => `  <li>${item}</li>`).join('\n')}\n</${tag}>`, start, end);
        };
        const panel = document.createElement('div'); panel.className = 'nb-page-link-panel nb-code-link-panel'; panel.hidden = true;
        panel.innerHTML = `<label>${escapeHtml(t('editor.writing.link_target'))}<input type="text" autocomplete="off" placeholder="${escapeHtml(t('editor.writing.link_placeholder'))}"></label>
            <label class="nb-code-link-tab"><input type="checkbox"> ${escapeHtml(t('editor.writing.link_new_tab'))}</label>
            <button type="button" class="btn btn-primary btn-sm">${escapeHtml(t('editor.writing.apply'))}</button>
            <button type="button" class="btn btn-secondary btn-sm">${escapeHtml(t('btn.cancel'))}</button>`;
        const [url, newTab] = panel.querySelectorAll('input'); const [apply, cancel] = panel.querySelectorAll('button');
        let linkRange = [0, 0];
        const openLink = () => {
            const [start, end, selected] = selection(); linkRange = [start, end];
            url.value = /^(https?:\/\/|\/|mailto:|tel:)\S+$/.test(selected.trim()) ? selected.trim() : ''; newTab.checked = false;
            url.setCustomValidity(''); panel.hidden = false; url.focus();
        };
        apply.onclick = () => {
            const href = url.value.trim();
            if (!href || !safeHref(href)) { url.setCustomValidity(t('editor.writing.invalid_link')); url.reportValidity(); return; }
            const [start, end] = linkRange; const selected = source.value.slice(start, end) || href;
            const attributes = ` href="${href.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"` + (newTab.checked ? ' target="_blank" rel="noopener noreferrer"' : '');
            panel.hidden = true; source.focus(); code.insert(`<a${attributes}>${selected}</a>`, start, end);
        };
        cancel.onclick = () => { panel.hidden = true; source.focus(); };
        url.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); apply.click(); } else if (event.key === 'Escape') cancel.click(); });
        const buttons = [
            [t('editor.writing.bold'), '<b>B</b>', () => wrap('<strong>', '</strong>')],
            [t('editor.writing.italic'), toolbarIcons.italic, () => wrap('<em>', '</em>')],
            [t('editor.writing.link'), toolbarIcons.link, openLink],
        ];
        // Teaser fields hold one line of inline markup: only bold, italic and link apply there
        if (!compact) buttons.push(
            [t('editor.writing.quote'), toolbarIcons.quote, () => wrap('<blockquote>', '</blockquote>')],
            [t('editor.writing.unordered'), toolbarIcons.unorderedList, () => list('ul')],
            [t('editor.writing.ordered'), toolbarIcons.orderedList, () => list('ol')],
            [t('editor.writing.footnote'), toolbarIcons.footnote, () => wrap('<span class="footnote">', '</span>')],
            [t('editor.writing.list_item'), 'li', () => wrap('<li>', '</li>'), 'nb-code-toolbar__text'],
            [t('editor.writing.inline_code'), 'code', () => wrap('<code>', '</code>'), 'nb-code-toolbar__text'],
            [t('editor.writing.close_tags'), escapeHtml(t('editor.writing.close_tags')), () => { const closing = unclosedTags(source.value.slice(0, source.selectionStart)); if (closing) { source.focus(); code.insert(closing); } }, 'nb-code-toolbar__text'],
            [t('editor.writing.format_code_hint'), escapeHtml(t('editor.writing.format_code')), () => { source.focus(); code.insert(formatHtml(source.value), 0, source.value.length); source.setSelectionRange(0, 0); }, 'btn btn-secondary btn-sm nb-code-toolbar__format'],
        );
        buttons.forEach(([title, label, action, className]) => {
            const button = document.createElement('button'); button.type = 'button'; button.title = title; button.setAttribute('aria-label', title); button.innerHTML = label;
            if (className) button.className = className;
            button.addEventListener('mousedown', event => event.preventDefault()); // keep the selection in the field
            button.addEventListener('click', action);
            bar.append(button);
        });
        return { bar, panel };
    }
    // Teaser fields (post excerpt, page description) are one short rich-text line: bold, italic and
    // link only, no paragraphs. Block elements and line breaks become spaces; cards set their own <p>.
    const inlineTags = new Set(['b','strong','i','em','a','sub','sup']);
    function cleanInline(html) {
        const template = document.createElement('template'); template.innerHTML = cleanHtml(html);
        const walk = parent => [...parent.childNodes].forEach(node => {
            if (node.nodeType !== 1) return;
            walk(node);
            const tag = node.tagName.toLowerCase();
            if (!inlineTags.has(tag)) { node.replaceWith(' ', ...node.childNodes, ' '); return; }
            [...node.attributes].forEach(({name}) => { if (!(tag === 'a' && ['href','target','rel','download'].includes(name))) node.removeAttribute(name); });
            // execCommand writes <b>/<i>; the content uses the semantic tags
            if (tag === 'b' || tag === 'i') { const semantic = document.createElement(tag === 'b' ? 'strong' : 'em'); semantic.append(...node.childNodes); node.replaceWith(semantic); }
        });
        walk(template.content);
        return template.innerHTML.replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
    }
    // The textarea stays the source the save routines read; the editing surface writes back into it.
    // Same frame as the content field: label with Visual / Code tabs, toolbar, and in Code the
    // highlighted source with a short tag bar.
    function inlineEditor(source, index, hooks) {
        if (!source || source.dataset.writingReady) return;
        source.dataset.writingReady = 'true';
        const id = 'nb-inline-rich-' + index;
        const label = source.id ? document.querySelector(`label[for="${source.id}"]`) : source.closest('.ce-field, .section-fields, .editor-form-row')?.querySelector('label');
        const wrap = document.createElement('div'); wrap.className = 'nb-inline-rich';
        const head = document.createElement('div'); head.className = 'nb-page-rich-head';
        if (label) { label.id = id + '-label'; head.append(label); }
        const modes = document.createElement('div'); modes.className = 'segmented nb-page-modes'; modes.setAttribute('role', 'radiogroup'); modes.setAttribute('aria-label', t('editor.writing.view_modes'));
        const [visualChoice, codeChoice] = [['visual', t('editor.writing.visual')], ['code', t('editor.writing.code')]].map(([value, text]) => {
            const option = document.createElement('label'); const input = document.createElement('input'); const caption = document.createElement('span');
            input.type = 'radio'; input.name = id + '-mode'; input.value = value; caption.textContent = text;
            option.append(input, caption); modes.append(option); return input;
        });
        head.append(modes);
        const toolbar = document.createElement('div'); toolbar.className = 'news-wysiwyg-toolbar nb-page-rich-toolbar nb-inline-rich-toolbar'; toolbar.setAttribute('role', 'toolbar'); toolbar.setAttribute('aria-label', t('editor.writing.format_text'));
        [['bold',t('editor.writing.bold'),'<b>B</b>'],['italic',t('editor.writing.italic'),toolbarIcons.italic],['createLink',t('editor.writing.link'),toolbarIcons.link],['unlink',t('editor.writing.unlink'),toolbarIcons.unlink],['removeFormat',t('editor.writing.clear'),toolbarIcons.eraser]].forEach(([command, title, text]) => {
            const button = document.createElement('button'); button.type = 'button'; button.dataset.command = command; button.title = title; button.setAttribute('aria-label', title); button.innerHTML = text;
            if (command === 'bold' || command === 'italic') button.setAttribute('aria-pressed', 'false');
            toolbar.append(button);
        });
        const visual = document.createElement('div'); visual.className = 'nb-page-rich-editor nb-inline-rich-editor'; visual.id = id;
        visual.contentEditable = 'true'; visual.setAttribute('role', 'textbox'); visual.setAttribute('aria-multiline', 'false'); visual.spellcheck = true;
        if (label) visual.setAttribute('aria-labelledby', label.id);
        visual.innerHTML = cleanInline(source.value);
        if (!source.id) source.id = id + '-source';
        source.setAttribute('aria-label', t('editor.writing.html_content')); source.classList.add('nb-inline-rich-source');
        source.replaceWith(wrap); wrap.append(head, toolbar, visual, source);
        const code = codeField(source); code.box.hidden = true;
        const codeBar = codeToolbar(source, code, true); code.box.before(codeBar.bar, codeBar.panel); codeBar.bar.hidden = true;
        let range = null;
        const remember = () => {
            const selection = window.getSelection();
            if (!selection.rangeCount || !visual.contains(selection.anchorNode) || !visual.contains(selection.focusNode)) return;
            range = selection.getRangeAt(0).cloneRange();
            toolbar.querySelectorAll('[aria-pressed]').forEach(button => button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.command))));
        };
        const restore = () => {
            visual.focus();
            const selection = window.getSelection();
            if (range && visual.contains(range.commonAncestorContainer)) { selection.removeAllRanges(); selection.addRange(range); }
        };
        const sync = () => { source.value = cleanInline(visual.innerHTML); source.dispatchEvent(new Event('input', {bubbles: true})); hooks.markDirty(); remember(); };
        const command = (name, value = null) => {
            restore();
            // With only the caret in a link the browser's unlink does nothing: select the whole link first
            if (name === 'unlink') {
                const selection = window.getSelection();
                const node = selection.rangeCount && selection.isCollapsed ? selection.getRangeAt(0).startContainer : null;
                const anchor = node ? (node.nodeType === 1 ? node : node.parentElement).closest('a') : null;
                if (anchor) selection.selectAllChildren(anchor);
            }
            document.execCommand(name, false, value);
            sync();
        };
        visual.addEventListener('focus', () => hooks.pushUndoSnapshot());
        visual.addEventListener('input', sync);
        visual.addEventListener('keyup', remember); visual.addEventListener('mouseup', remember); visual.addEventListener('blur', remember);
        visual.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
        visual.addEventListener('click', event => { if (event.target.closest('a')) event.preventDefault(); });
        visual.addEventListener('paste', event => {
            event.preventDefault();
            const html = event.clipboardData.getData('text/html');
            if (html) document.execCommand('insertHTML', false, cleanInline(html));
            else document.execCommand('insertText', false, event.clipboardData.getData('text/plain').replace(/\s+/g, ' '));
            sync();
        });
        visual.addEventListener('drop', event => event.preventDefault());
        toolbar.addEventListener('mousedown', event => { if (event.target.closest('button')) event.preventDefault(); });
        const linkPanel = document.createElement('div'); linkPanel.className = 'nb-page-link-panel'; linkPanel.hidden = true;
        const linkLabel = document.createElement('label'); linkLabel.textContent = t('editor.writing.link_target');
        const url = document.createElement('input'); url.type = 'text'; url.placeholder = t('editor.writing.link_placeholder'); url.autocomplete = 'off'; linkLabel.append(url);
        const apply = document.createElement('button'); apply.type = 'button'; apply.className = 'btn btn-primary btn-sm'; apply.textContent = t('editor.writing.apply');
        const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn-secondary btn-sm'; cancel.textContent = t('btn.cancel');
        linkPanel.append(linkLabel, apply, cancel); toolbar.after(linkPanel);
        apply.onclick = () => { const href = url.value.trim(); if (!href || !safeHref(href)) { url.setCustomValidity(t('editor.writing.invalid_link')); url.reportValidity(); return; } command('createLink', href); linkPanel.hidden = true; };
        cancel.onclick = () => { linkPanel.hidden = true; restore(); };
        url.oninput = () => url.setCustomValidity('');
        url.onkeydown = event => { if (event.key === 'Enter') { event.preventDefault(); apply.click(); } if (event.key === 'Escape') cancel.click(); };
        toolbar.addEventListener('click', event => {
            const button = event.target.closest('[data-command]'); if (!button) return;
            if (button.dataset.command === 'createLink') {
                const node = range?.startContainer; const anchor = (node?.nodeType === 1 ? node : node?.parentElement)?.closest('a');
                url.value = anchor?.getAttribute('href') || ''; linkPanel.hidden = false; url.focus(); return;
            }
            command(button.dataset.command);
        });
        let inCode = false;
        const mode = html => {
            // back from Code, the source takes the cleaned one-line form the field stands for
            if (!html) { const clean = cleanInline(source.value); visual.innerHTML = clean; if (inCode && clean !== source.value) { source.value = clean; source.dispatchEvent(new Event('input', {bubbles: true})); } }
            inCode = html;
            visual.hidden = html; toolbar.hidden = html; code.box.hidden = !html; codeBar.bar.hidden = !html; codeBar.panel.hidden = true; linkPanel.hidden = true;
            if (html) code.refresh();
            if (label) label.htmlFor = html ? source.id : visual.id;
            visualChoice.checked = !html; codeChoice.checked = html;
        };
        visualChoice.addEventListener('change', () => mode(false)); codeChoice.addEventListener('change', () => mode(true)); mode(false);
        source.addEventListener('focus', () => hooks.pushUndoSnapshot());
        source.addEventListener('input', hooks.markDirty);
    }
    function richEditor(field, index, hooks) {
        const source = field.querySelector('textarea.section-field');
        if (!source || !field.querySelector('label') || field.dataset.writingReady) return;
        field.dataset.writingReady = 'true';
        field.classList.add('nb-rich');
        const id = 'nb-page-rich-' + index;
        const label = field.querySelector('label');
        if (source.dataset.key === 'content') label.textContent = t('editor.writing.content');
        else label.textContent = label.textContent.replace(/ \(HTML\)$/, '');
        label.id = id + '-label';
        const head = document.createElement('div'); head.className = 'nb-page-rich-head';
        label.replaceWith(head); head.append(label);
        // Visual / Code as a segmented choice, like the option groups in the settings
        const modes = document.createElement('div'); modes.className = 'segmented nb-page-modes'; modes.setAttribute('role', 'radiogroup'); modes.setAttribute('aria-label', t('editor.writing.view_modes'));
        const [visualChoice, codeChoice] = [['visual', t('editor.writing.visual')], ['code', t('editor.writing.code')]].map(([value, text]) => {
            const option = document.createElement('label'); const input = document.createElement('input'); const caption = document.createElement('span');
            input.type = 'radio'; input.name = id + '-mode'; input.value = value; caption.textContent = text;
            option.append(input, caption); modes.append(option); return input;
        });
        head.append(modes);
        const toolbar = document.createElement('div'); toolbar.className = 'news-wysiwyg-toolbar nb-page-rich-toolbar'; toolbar.setAttribute('role', 'toolbar'); toolbar.setAttribute('aria-label', t('editor.writing.format_text'));
        const format = document.createElement('select');
        format.setAttribute('aria-label', t('editor.writing.paragraph_format'));
        [['p', t('editor.writing.paragraph')], ...[1,2,3,4,5,6].map(level => ['h'+level, t('editor.writing.heading')+' '+level]), ['blockquote', t('editor.writing.quote')]].forEach(([value, text]) => format.add(new Option(text, value)));
        toolbar.append(format);
        const commands = [['bold',t('editor.writing.bold'),'<b>B</b>'],['italic',t('editor.writing.italic'),toolbarIcons.italic],['insertUnorderedList',t('editor.writing.unordered'),toolbarIcons.unorderedList],['insertOrderedList',t('editor.writing.ordered'),toolbarIcons.orderedList],['createLink',t('editor.writing.link'),toolbarIcons.link],['unlink',t('editor.writing.unlink'),toolbarIcons.unlink],['removeFormat',t('editor.writing.clear'),toolbarIcons.eraser]];
        commands.forEach(([command, title, text]) => {
            const button = document.createElement('button'); button.type = 'button'; button.dataset.command = command; button.title = title; button.setAttribute('aria-label', title); button.innerHTML = text;
            if (['bold','italic','insertUnorderedList','insertOrderedList'].includes(command)) button.setAttribute('aria-pressed', 'false');
            toolbar.append(button);
        });
        // Fußnote: Markierung in <span class="footnote"> fassen, innerhalb einer Fußnote diese auflösen
        const footnoteButton = document.createElement('button'); footnoteButton.type = 'button'; footnoteButton.dataset.action = 'footnote';
        footnoteButton.title = t('editor.writing.footnote'); footnoteButton.setAttribute('aria-label', t('editor.writing.footnote')); footnoteButton.innerHTML = toolbarIcons.footnote;
        toolbar.querySelector('[data-command="removeFormat"]').before(footnoteButton); // same order as the inline toolbar
        const visual = document.createElement('div'); visual.className = 'nb-page-rich-editor'; visual.id = id;
        visual.contentEditable = 'true'; visual.setAttribute('role', 'textbox'); visual.setAttribute('aria-multiline','true'); visual.setAttribute('aria-labelledby',label.id); visual.spellcheck = true;
        visual.innerHTML = cleanHtml(source.value);
        source.id = id + '-source'; source.setAttribute('aria-label', t('editor.writing.html_content')); source.classList.add('nb-page-html-source');
        field.insertBefore(toolbar, source); field.insertBefore(visual, source);
        const code = codeField(source); code.box.hidden = true;
        const codeBar = codeToolbar(source, code); code.box.before(codeBar.bar, codeBar.panel); codeBar.bar.hidden = true;
        let range = null;
        const remember = () => {
            const selection = window.getSelection();
            if (!selection.rangeCount || !visual.contains(selection.anchorNode) || !visual.contains(selection.focusNode)) return;
            range = selection.getRangeAt(0).cloneRange();
            toolbar.querySelectorAll('[aria-pressed]').forEach(button => button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.command))));
            const block = document.queryCommandValue('formatBlock').replace(/[<>]/g,'').toLowerCase();
            format.value = ['p','h1','h2','h3','h4','h5','h6','blockquote'].includes(block) ? block : 'p';
        };
        const restore = () => {
            visual.focus();
            const selection = window.getSelection();
            if (range && visual.contains(range.commonAncestorContainer)) { selection.removeAllRanges(); selection.addRange(range); }
        };
        const sync = () => { source.value = cleanHtml(visual.innerHTML); source.dispatchEvent(new Event('input',{bubbles:true})); hooks.markDirty(); remember(); };
        const command = (name, value = null) => {
            restore();
            // With only the caret in a link the browser's unlink does nothing: select the whole link first
            if (name === 'unlink') {
                const selection = window.getSelection();
                const node = selection.rangeCount && selection.isCollapsed ? selection.getRangeAt(0).startContainer : null;
                const anchor = node ? (node.nodeType === 1 ? node : node.parentElement).closest('a') : null;
                if (anchor) selection.selectAllChildren(anchor);
            }
            document.execCommand(name, false, value);
            sync();
        };
        const footnote = () => {
            restore();
            const selection = window.getSelection(); if (!selection.rangeCount) return;
            const current = selection.getRangeAt(0);
            const startNode = current.startContainer.nodeType === 1 ? current.startContainer : current.startContainer.parentElement;
            const existing = startNode?.closest('.footnote');
            if (existing && visual.contains(existing)) { existing.replaceWith(...existing.childNodes); visual.normalize(); sync(); return; }
            // A double-click selects the word plus its trailing space; the space stays outside the footnote
            const isSpace = (ch) => ch === ' ' || ch === '\u00a0' || ch === '\t' || ch === '\n';
            while (!current.collapsed && current.startContainer.nodeType === 3 && current.startOffset < current.startContainer.data.length && isSpace(current.startContainer.data[current.startOffset])) current.setStart(current.startContainer, current.startOffset + 1);
            while (!current.collapsed && current.endContainer.nodeType === 3 && current.endOffset > 0 && isSpace(current.endContainer.data[current.endOffset - 1])) current.setEnd(current.endContainer, current.endOffset - 1);
            selection.removeAllRanges(); selection.addRange(current);
            const holder = document.createElement('div'); holder.append(current.cloneContents());
            const inner = current.collapsed ? escapeHtml(t('editor.writing.footnote_placeholder')) : holder.innerHTML;
            document.execCommand('insertHTML', false, `<span class="footnote" id="nb-footnote-new">${inner}</span>`);
            const span = visual.querySelector('#nb-footnote-new');
            if (span) {
                span.removeAttribute('id');
                // insertHTML turns the spaces next to the span into non-breaking ones; give them back
                const before = span.previousSibling, after = span.nextSibling;
                if (before && before.nodeType === 3 && before.data.endsWith('\u00a0')) before.data = before.data.slice(0, -1) + ' ';
                if (after && after.nodeType === 3 && after.data.startsWith('\u00a0')) after.data = ' ' + after.data.slice(1);
                const next = document.createRange();
                if (current.collapsed) next.selectNodeContents(span); else { next.setStartAfter(span); next.collapse(true); }
                selection.removeAllRanges(); selection.addRange(next);
            }
            sync();
        };
        visual.addEventListener('focus', () => hooks.pushUndoSnapshot());
        visual.addEventListener('input', sync);
        visual.addEventListener('keyup', remember); visual.addEventListener('mouseup', remember);
        visual.addEventListener('blur', remember);
        visual.addEventListener('click', event => { if (event.target.closest('a')) event.preventDefault(); });
        visual.addEventListener('paste', event => {
            event.preventDefault();
            const html = event.clipboardData.getData('text/html');
            if (html) document.execCommand('insertHTML', false, cleanHtml(html));
            else document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
            sync();
        });
        // Dragging markup/files into the editable area must not bypass the paste sanitizer.
        visual.addEventListener('drop', event => event.preventDefault());
        toolbar.addEventListener('mousedown', event => { if (event.target.closest('button')) event.preventDefault(); });
        const linkPanel = document.createElement('div'); linkPanel.className = 'nb-page-link-panel'; linkPanel.hidden = true;
        const linkLabel = document.createElement('label'); linkLabel.textContent = t('editor.writing.link_target');
        const url = document.createElement('input'); url.type = 'text'; url.placeholder = t('editor.writing.link_placeholder'); url.autocomplete = 'off'; linkLabel.append(url);
        const apply = document.createElement('button'); apply.type = 'button'; apply.className = 'btn btn-primary btn-sm'; apply.textContent = t('editor.writing.apply');
        const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn-secondary btn-sm'; cancel.textContent = t('btn.cancel');
        linkPanel.append(linkLabel,apply,cancel); toolbar.after(linkPanel);
        apply.onclick = () => { const href = url.value.trim(); if (!href || !safeHref(href)) { url.setCustomValidity(t('editor.writing.invalid_link')); url.reportValidity(); return; } command('createLink',href); linkPanel.hidden = true; };
        cancel.onclick = () => { linkPanel.hidden = true; restore(); };
        url.oninput = () => url.setCustomValidity('');
        url.onkeydown = event => { if (event.key === 'Enter') { event.preventDefault(); apply.click(); } if (event.key === 'Escape') cancel.click(); };
        toolbar.addEventListener('click', event => {
            if (event.target.closest('[data-action="footnote"]')) { footnote(); return; }
            const button = event.target.closest('[data-command]'); if (!button) return;
            if (button.dataset.command === 'createLink') {
                const node = range?.startContainer; const anchor = (node?.nodeType === 1 ? node : node?.parentElement)?.closest('a');
                url.value = anchor?.getAttribute('href') || ''; linkPanel.hidden = false; url.focus();
            } else command(button.dataset.command);
        });
        toolbar.querySelector('select').addEventListener('change', event => command('formatBlock', event.target.value));
        const mode = html => {
            if (!html) visual.innerHTML = cleanHtml(source.value);
            visual.hidden = html; toolbar.hidden = html; code.box.hidden = !html; codeBar.bar.hidden = !html; codeBar.panel.hidden = true; linkPanel.hidden = true;
            if (html) code.refresh();
            label.htmlFor = html ? source.id : visual.id;
            visualChoice.checked = !html; codeChoice.checked = html;
        };
        visualChoice.addEventListener('change', () => mode(false)); codeChoice.addEventListener('change', () => mode(true)); mode(false);
        source.addEventListener('focus', () => hooks.pushUndoSnapshot());
        source.addEventListener('input', hooks.markDirty);
        // Hosts that fill the field later (the inline editor's block dialog) set the value through the API,
        // so the editing surface and the source stay in step
        const api = {
            field, source, visual,
            setValue(html) { source.value = html; visual.innerHTML = cleanHtml(html); if (codeChoice.checked) code.refresh(); },
            getValue: () => source.value,
            focus() { (codeChoice.checked ? source : visual).focus(); },
            setMode(html) { mode(html); },
        };
        field._nbRich = api;
        return api;
    }
    window.NbRichEditor = { richEditor, inlineEditor, cleanHtml, cleanInline, formatHtml, codeField, codeToolbar };
})();
