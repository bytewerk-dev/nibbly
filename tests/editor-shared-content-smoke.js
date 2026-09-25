/* Regression: custom-layout helpers must load full shared documents before editing. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../js/inline-editor.js'), 'utf8');
function implementation(name) {
    const match = source.match(new RegExp(`    (?:async )?function ${name}\\([^]*?\\n    }`));
    assert.ok(match, name);
    return match[0];
}
const fixtures = [
    {'data-content-page': 'de_home'},
    {class: 'editable-field', 'data-page': 'de_shared'},
    {class: 'editable-field editable-field-html', 'data-page': 'de_shared'},
    {'data-editable-group': '', 'data-page': 'de_groups'},
    {'data-editable-link': '', 'data-page': 'de_links'},
    {'data-editable-image': '', 'data-page': 'de_images'},
    {'data-editable-icon': '', 'data-page': 'de_icons'},
    {'data-editable-list': '', 'data-list-page': 'de_lists'},
    {'data-editable-image': '', 'data-page': 'events'},
    {'data-page': 'unrelated-widget'}
];
const nodes = fixtures.map(attrs => ({attrs, dataset: Object.fromEntries(Object.entries(attrs)
    .filter(([key]) => key.startsWith('data-'))
    .map(([key, value]) => [key.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()), value]))}));
const matches = (node, selector) => selector.split(',').some(part => {
    const classes = [...part.matchAll(/\.([\w-]+)/g)].map(match => match[1]);
    const attributes = [...part.matchAll(/\[([\w-]+)\]/g)].map(match => match[1]);
    return classes.every(cls => (node.attrs.class || '').split(' ').includes(cls))
        && attributes.every(attr => Object.hasOwn(node.attrs, attr));
});
const config = {apiUrl: '/admin/api.php', contentData: {}, contentRevisions: {}, loadedPages: [], dirtyPages: new Set()};
const loads = [];
const shared = {ui: {jurists: 'Juristen'}, footer: {company: 'Keep the complete footer'}, contact: {phone: '123'}};
const context = vm.createContext({
    console, URL, Set, JSON, EditorConfig: config,
    document: {
        addEventListener() {},
        querySelector(selector) {
            return selector === 'meta[name="csrf-token"]' ? {content: 'fixture-token'}
                : selector === 'meta[name="content-page"]' ? {content: 'de_home'} : null;
        },
        querySelectorAll: selector => nodes.filter(node => matches(node, selector))
    },
    window: {}, localStorage: {getItem: () => null}, sessionStorage: {getItem: () => null},
    fetch: async url => {
        const page = new URL(url, 'http://localhost').searchParams.get('page');
        loads.push(page);
        return {json: async () => ({success: true, data: structuredClone(page === 'de_shared' ? shared : {page}), revision: 'revision-' + page})};
    }
});
for (const name of ['exitEditModeClean', 'loadEvents', 'attachEditorChromeHoverStates', 'createEditorUI',
    'createGroupEditorUI', 'createEventEditorUI', 'createAddSectionUI', 'attachEditHandlers',
    'attachEventEditHandlers', 'attachFooterEditHandlers', 'attachGroupEditHandlers', 'attachFieldEditHandlers',
    'attachLinkEditHandlers', 'attachImageEditHandlers', 'attachListEditHandlers', 'attachComparisonRowHandlers',
    'attachComparisonCellToggles', 'showAdminBar', 'attachEditModeHint', 'pushUndoState', 'updateUndoRedoButtons']) context[name] = () => {};
vm.runInContext(['initEditor', 'loadContent', 'setNestedValue', 'saveField', 'toggleListItemHidden'].map(implementation).join('\n'), context);
(async () => {
    vm.runInContext('initEditor()', context);
    await new Promise(setImmediate);
    assert.deepEqual(loads.sort(), ['de_home', 'de_shared', 'de_groups', 'de_links', 'de_images', 'de_icons', 'de_lists'].sort());
    assert.equal(config.contentRevisions.de_shared, 'revision-de_shared');
    context.edited = {textContent: ''};
    vm.runInContext("saveField('de_shared', 'ui.jurists', 'Updated heading', edited, false)", context);
    assert.deepEqual(config.contentData.de_shared, {...shared, ui: {jurists: 'Updated heading'}});
    assert.equal(context.edited.textContent, 'Updated heading');
    assert.ok(config.dirtyPages.has('de_shared'));
    config.contentData.de_team = {staff: [{name: 'First'}, {name: 'Second'}]};
    Object.assign(context, {
        getNestedObj: (value, key) => value[key], t: key => key, showToast() {},
        Icons: {eyeClosed: 'closed', eyeOpen: 'open'}, item: {dataset: {}},
        button: {dataset: {}, setAttribute(key, value) { this[key] = value; }}
    });
    for (const hidden of [true, false]) {
        vm.runInContext("toggleListItemHidden('de_team', 'staff', 0, item, button)", context);
        assert.equal(!!config.contentData.de_team.staff[0].hidden, hidden);
        assert.equal(context.item.dataset.hidden, hidden ? 'true' : undefined);
        assert.equal(context.button['aria-label'], hidden ? 'show' : 'hide');
        assert.equal(context.button.dataset.editorTooltip, hidden ? 'show' : 'hide');
        assert.deepEqual(config.contentData.de_team.staff[1], {name: 'Second'});
    }
    console.log('Shared editor content: all helper types loaded once with revisions; unrelated fields preserved; event images use their separate API.');
    console.log('List visibility: hide/show updates data, accessible labels and tooltips without affecting the next item.');
})().catch(error => { console.error(error); process.exitCode = 1; });
