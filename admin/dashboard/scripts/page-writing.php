/* Native writing view. Source fields, revisions and saves remain authoritative. */
(() => {
    'use strict';
    // The rich text fields themselves (Visual / Code editor, sanitizer, code field) live in js/rich-editor.js
    const { richEditor, inlineEditor } = window.NbRichEditor;
    function details(label, className = '') {
        const el = document.createElement('details');
        el.className = 'nb-page-details ' + className;
        const summary = document.createElement('summary');
        const text = document.createElement('span'); text.textContent = label;
        summary.append(text); el.append(summary);
        return el;
    }
    // The page settings leave the tab panels of the plain editor (scripts/pages.php): title image,
    // address and access become sidebar panels like in the news editor, navigation and breadcrumb
    // fold out under the title, the description follows the blocks, and SEO closes the page at the
    // bottom behind a divider, where it is easy to find after scrolling down a long page.
    function sidePanel(label, className) {
        const section = document.createElement('section');
        section.className = 'nb-page-panel nb-page-panel--meta ' + className;
        const heading = document.createElement('h3'); heading.textContent = label;
        section.append(heading);
        return section;
    }
    function placeSettings(sections, panel, nav) {
        const fieldOf = selector => sections.querySelector(selector)?.closest('.ce-field') || null;
        const imagePanel = sidePanel(t('editor.page_image'), 'nb-page-image');
        const image = fieldOf('[data-path="image"]'); if (image) { image.querySelector('.ce-field-label')?.remove(); imagePanel.append(image); }
        const imageAlt = fieldOf('[data-path="imageAlt"]'); if (imageAlt) imagePanel.append(imageAlt);
        const basicsPanel = sidePanel(t('editor.page_settings'), 'nb-page-basics');
        const address = fieldOf('.ce-address-row'); if (address) basicsPanel.append(address);
        const accessPanel = sidePanel(t('editor.settings_access'), 'nb-page-access');
        const access = fieldOf('.ce-visibility-grid'); if (access) accessPanel.append(access);
        nav.after(...[imagePanel, basicsPanel, accessPanel].filter(p => p.children.length > 1));

        const meta = document.createElement('div'); meta.className = 'nb-page-meta';
        [['.ce-nav-checkboxes', t('editor.nav_locations')], ['#breadcrumbEditor', t('editor.breadcrumb')]].forEach(([selector, label]) => {
            const field = fieldOf(selector); if (!field) return;
            field.querySelector('.ce-card-field-label')?.remove();
            const disclosure = details(label, 'nb-page-details--meta'); disclosure.append(field); meta.append(disclosure);
        });
        if (meta.children.length) { const titleField = panel.querySelector('.nb-page-title-field'); titleField ? titleField.after(meta) : panel.prepend(meta); }

        // The description (card text, fallback for the meta description) needs the width of the main
        // column for its toolbar and tabs; it sits after the blocks, ahead of the SEO section.
        const description = fieldOf('[data-path="description"]');
        if (description) { const wrap = document.createElement('section'); wrap.className = 'nb-page-description'; wrap.append(description); panel.append(wrap); }
        const seo = sections.querySelector('.ce-settings-card--seo');
        if (seo) {
            seo.querySelectorAll('.ce-form-tile').forEach(tile => { const control = tile.querySelector('input,textarea,select'); if (control?.id) tile.dataset.seoField = control.id; });
            const wrap = document.createElement('section'); wrap.className = 'nb-page-seo'; wrap.setAttribute('aria-label', t('editor.seo'));
            wrap.append(seo); panel.append(wrap);
        }
        // emptied cards of the hidden tab panels
        sections.querySelectorAll('.ce-settings-card:not(.ce-settings-card--seo)').forEach(card => { if (!card.querySelector('.ce-field')) card.remove(); });
    }
    function enhance(content, hooks) {
        const root = document.getElementById('editorContainer'); const sections = document.getElementById('sectionsContainer');
        if (!root || !sections) return;
        // Pages of a custom template have field groups instead of a block list
        const hasBlocks = Array.isArray(content.sections);
        root.classList.add('nb-writing-view');
        let layout = root.querySelector('.nb-writing-layout');
        if (!layout) {
            layout = document.createElement('div'); layout.className = 'nb-writing-layout'; sections.before(layout); layout.append(sections);
            const aside = document.createElement('aside'); aside.className = 'nb-page-sidebar'; aside.setAttribute('aria-label',t('editor.writing.page_actions'));
            aside.innerHTML = `<section class="nb-page-save"><h3>${escapeHtml(t('editor.writing.save_page'))}</h3><div class="nb-page-save-controls"></div></section><nav class="nb-page-navigation" aria-label="${escapeHtml(t('editor.writing.page_settings'))}"></nav>`;
            const backups = document.getElementById('backupContainer'); if (backups) aside.append(backups);
            layout.append(aside);
        }
        const actions = root.querySelector('.editor-header-right');
        if (actions) layout.querySelector('.nb-page-save-controls').append(actions);
        root.classList.remove('nb-writing-single');
        const aside = layout.querySelector('.nb-page-sidebar'); const nav = layout.querySelector('.nb-page-navigation');
        aside.querySelectorAll('.nb-page-panel--meta').forEach(el => el.remove());
        nav.replaceChildren(); nav.hidden = true;
        sections.querySelector('.ce-editor-tabs')?.remove();
        const panel = sections.querySelector('[data-editor-panel="content"]');
        const title = sections.querySelector('[data-path="title"]');
        if (title && panel) { title.autocomplete='off'; title.name='page-title'; title.id='nb-page-title'; const field=title.closest('.ce-field'); field.classList.add('nb-page-title-field'); field.querySelector('label').htmlFor=title.id; panel.prepend(field); }
        inlineEditor(sections.querySelector('textarea[data-path="description"]'), 'page-description', hooks);
        if (panel) placeSettings(sections, panel, nav);
        const single = hasBlocks && content.sections.length === 1 && content.sections[0].type === 'text'; root.classList.toggle('nb-writing-single', single);
        if (single) sections.querySelector('.section-item')?.classList.add('is-open');
        sections.querySelectorAll('.html-editor').forEach((field,index) => richEditor(field,index,hooks));
        sections.querySelectorAll('.section-item[data-type="text"]').forEach(section => {
            const fields = section.querySelector('.section-fields'); const options = details(t('editor.writing.more_options'));
            [...fields.children].filter(field=>!field.classList.contains('html-editor')).forEach(field=>options.append(field));
            if (single) { const actions=section.querySelector('.section-actions'); if(actions) options.append(actions); }
            fields.append(options);
            section.querySelectorAll('input').forEach(input=>{input.autocomplete='off'; input.name='page-section-'+section.dataset.index+'-'+input.dataset.key;});
        });
        if (!hasBlocks) return; // the field groups are the page's content, not "further fields"
        const add = sections.querySelector('.add-section-container');
        if (add) { const disclosure=details(t('editor.writing.add_sections')); disclosure.open = content.sections.length === 0; add.before(disclosure); disclosure.append(add); }
        const groups=[...sections.querySelectorAll('.ce-section-editor>.ce-group')];
        if(groups.length) {const disclosure=details(t('editor.writing.more_fields')); groups[0].before(disclosure); groups.forEach(group=>disclosure.append(group));}
    }
    // richEditor and inlineEditor are shared with the news editor (scripts/news.php)
    window.NbPageEditor = { enhance, richEditor, inlineEditor };
})();
