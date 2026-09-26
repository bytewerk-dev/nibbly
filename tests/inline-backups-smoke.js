/* Test the actual toolbar controller with isolated DOM/API state; never writes to a site. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
process.env.TZ = 'Europe/Vienna';
const source = fs.readFileSync(path.join(__dirname, '../js/inline-editor.js'), 'utf8');
const start = source.indexOf('    // Persisted page backups');
const end = source.indexOf('    async function showAdminBar()', start);
assert.ok(start > 0 && end > start);
const backup = {filename: 'de_team_2026-09-16_100000_abcdef.json', timestamp: Date.parse('2026-09-16T10:00:00Z') / 1000};
const translations = JSON.parse(fs.readFileSync(path.join(__dirname, '../admin/lang/editor-de.json'), 'utf8'));

function harness(replies = []) {
    const nodes = new Map();
    function element() {
        return {hidden: true, disabled: false, children: [], _value: '', classList: {toggle() {}},
            setAttribute() {},
            get value() { return this._value; }, set value(value) { this._value = value; },
            get options() { return this.children; },
            get selectedIndex() { return this.children.findIndex(option => option.value === this.value); },
            appendChild(child) { this.children.push(child); if (this.children.length === 1) this.value = child.value; },
            replaceChildren(...children) { this.children = []; this.value = ''; children.forEach(child => this.appendChild(child)); }
        };
    }
    const ids = ['admin-page-backups', 'admin-backup-select', 'admin-btn-restore-backup', 'admin-bar'];
    ids.forEach(id => nodes.set(id, element()));
    const config = {currentPage: 'de_team', csrfToken: 'fixture', apiUrl: '/admin/api.php',
        contentRevisions: {de_team: 'r1'}, dirtyPages: new Set(), editMode: false, saving: false};
    const requests = [], confirmations = [], toasts = [], session = new Map();
    let reloads = 0, cleanups = 0;
    const context = vm.createContext({
        document: {documentElement: {lang: 'de'}, getElementById: id => nodes.get(id),
            createElement: () => element(), querySelectorAll: () => [nodes.get('admin-btn-restore-backup')]},
        EditorConfig: config, window: {}, FormData, Intl, Date,
        fetch: async (url, options) => {
            requests.push({url, fields: options?.body ? Object.fromEntries(options.body) : null});
            const reply = replies.shift();
            assert.ok(reply, 'Unexpected API request');
            if (reply instanceof Error) throw reply;
            return {ok: (reply.status || 200) < 400, status: reply.status || 200,
                json: async () => ({success: true, ...reply})};
        },
        t: (key, params = {}) => Object.entries(params).reduce((s, [name, value]) => s.replace(`{${name}}`, value), translations[key] || key),
        showToast: (...args) => toasts.push(args),
        showConfirmDialog: (...args) => confirmations.push(args),
        flushActiveInlineEdits() {},
        exitEditModeClean() { cleanups++; config.dirtyPages.clear(); config.editMode = false; },
        sessionStorage: {setItem: (key, value) => session.set(key, value), removeItem: key => session.delete(key)},
        location: {reload() { reloads++; }}
    });
    const controller = vm.runInContext(source.slice(start, end) + '\nPageBackups;', context);
    return {controller, config, nodes, requests, confirmations, toasts, session,
        reloads: () => reloads, cleanups: () => cleanups};
}

(async () => {
    const h = harness([{data: [backup, {...backup, filename: 'de_team_child_2026-09-16_100000.json'}]}, {data: null}]);
    await h.controller.load();
    assert.equal(h.controller.items.length, 1);
    assert.equal(h.nodes.get('admin-page-backups').hidden, false);
    assert.equal(h.nodes.get('admin-backup-select').options[0].textContent, 'Backup 16.09.26 12:00');
    h.config.editMode = true;
    h.config.dirtyPages.add('de_shared');
    h.controller.requestRestore();
    assert.equal(h.requests.length, 1, 'Selection and opening confirmation never restore');
    assert.match(h.confirmations[0][2], /Ungespeicherte.*gemeinsam/);
    await h.confirmations[0][3]();
    assert.deepEqual(h.requests[1].fields, {action: 'restore', page: 'de_team', backup: backup.filename, revision: 'r1', csrf_token: 'fixture'});
    assert.equal(h.reloads(), 1);
    assert.equal(h.cleanups(), 1);
    assert.equal(h.session.get('site-edit-mode'), 'true');

    const empty = harness([{data: []}]);
    await empty.controller.load();
    assert.equal(empty.nodes.get('admin-page-backups').hidden, true);
    const news = harness(); news.config.isNewsPost = true;
    await news.controller.load();
    assert.equal(news.requests.length, 0);

    const unavailable = harness([new Error('offline'), {data: [backup]}]);
    await unavailable.controller.load();
    assert.equal(unavailable.nodes.get('admin-btn-restore-backup').textContent, 'Erneut laden');
    assert.equal(unavailable.nodes.get('admin-backup-select').disabled, true);
    await unavailable.controller.load();
    assert.equal(unavailable.nodes.get('admin-btn-restore-backup').textContent, 'Übernehmen');
    unavailable.config.saving = true;
    unavailable.controller.requestRestore();
    assert.equal(unavailable.confirmations.length, 0, 'Restore cannot race an active save');

    for (const reply of [{status: 409, success: false}, new Error('response lost')]) {
        const failure = harness([{data: [backup]}, reply]);
        await failure.controller.load();
        failure.config.dirtyPages.add('de_team');
        failure.controller.requestRestore();
        await failure.confirmations[0][3]();
        assert.equal(failure.config.dirtyPages.size, 1, 'Failure preserves unsaved edits');
        assert.equal(failure.cleanups(), 0);
        assert.equal(failure.reloads(), 0);
        assert.equal(failure.nodes.get('admin-btn-restore-backup').disabled, true);
        failure.controller.requestRestore();
        assert.equal(failure.requests.length, 2, 'Stale or uncertain restores are never retried');
    }

    const browsing = harness([{data: [backup]}, {data: null}]);
    await browsing.controller.load();
    browsing.controller.requestRestore();
    assert.match(browsing.confirmations[0][2], /gespeicherte Stand/);
    await browsing.confirmations[0][3]();
    assert.equal(browsing.session.has('site-edit-mode'), false);
    assert.equal(browsing.reloads(), 1);
    console.log('PASS inline backups: local dates, page scope, confirmation, unsaved edits, browse/edit modes, empty/error/retry states, save guard and failed/conflicting restore');
})().catch(error => {console.error(error); process.exitCode = 1;});
