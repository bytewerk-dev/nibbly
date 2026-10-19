/* Exercise dashboard deep links with out-of-order API replies; no network or site writes. */
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {execFileSync} = require('node:child_process');
const {resolve} = require('node:path');

const source = execFileSync('php', ['-r', `
    define('NIBBLY_DASHBOARD', true);
    define('SITE_LANG_DEFAULT', 'de');
    $validDashboardTabs = ['home', 'content', 'settings'];
    include 'admin/dashboard/scripts/pages.php';
    include 'admin/dashboard/scripts/navigation.php';
`], {cwd: resolve(__dirname, '..'), encoding: 'utf8'})
    // The harness invokes routing explicitly after the full script initializes.
    .replace(/^    applyDashboardRoute\(true\);$/m, '');

class Element {
    constructor(tag = 'div') {
        this.tag = tag;
        this.children = [];
        this.style = {};
        this.classList = {add() {}, remove() {}};
        this.textContent = '';
        this._value = '';
    }
    set innerHTML(value) { this.children = []; this._value = ''; }
    get options() { return this.children; }
    get value() { return this._value; }
    set value(value) {
        this._value = this.tag !== 'select' || this.options.some(option => option.value === value) ? value : '';
    }
    appendChild(child) {
        this.children.push(child);
        if (this.tag === 'select' && this.children.length === 1) this._value = child.value;
    }
    cloneNode() { const copy = new Element(this.tag); copy.value = this.value; copy.textContent = this.textContent; return copy; }
    querySelectorAll() { return []; }
    remove() {}
}

function deferred() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return {promise, resolve};
}

async function checkRoute(lang, slug, hashRoute, listFirst) {
    const nodes = new Map();
    const get = id => {
        if (!nodes.has(id)) nodes.set(id, new Element());
        return nodes.get(id);
    };
    for (const id of ['langSelect', 'langSelectMobile', 'pageListLang', 'pageSelect', 'pageSelectMobile']) {
        const select = new Element('select');
        nodes.set(id, select);
        if (id.startsWith('lang') || id === 'pageListLang') {
            for (const language of ['de', 'en']) {
                const option = new Element('option'); option.value = language; select.appendChild(option);
            }
        }
    }
    const pageKey = `${lang}_${slug}`;
    const pageList = {pages: ['datenschutz', 'home', 'team', 'contact'].map(page => ({
        slug: page,
        languages: Object.fromEntries(['de', 'en'].map(language => [language, {exists: true, title: `${language}: ${page}`}]))
    }))};
    const content = {page: pageKey, lang, title: `${lang}: ${slug}`};
    const listReply = deferred(), contentReply = deferred(), contentRequested = deferred();
    let listRequests = 0;
    const loadedPages = [];
    const response = data => ({status: 200, json: async () => ({success: true, data})});
    const location = {
        pathname: '/admin/dashboard',
        search: hashRoute ? '' : `?page=${pageKey}`,
        hash: hashRoute ? `#page/${pageKey}` : ''
    };
    const context = vm.createContext({
        console, URLSearchParams,
        localStorage: {getItem() { return null; }},
        document: {getElementById: get, createElement: tag => new Element(tag), querySelectorAll: () => [], querySelector: () => null, addEventListener() {}},
        window: {location, addEventListener() {}, fetch: async url => {
            if (url.includes('action=list-pages')) {
                if (++listRequests === 1) return response(pageList);
                await listReply.promise;
                return response(pageList);
            }
            if (url.includes('action=load&page=')) {
                loadedPages.push(new URL(url, 'http://localhost/admin/').searchParams.get('page'));
                contentRequested.resolve();
                await contentReply.promise;
                return response(content);
            }
            throw new Error(`Unexpected request: ${url}`);
        }},
        history: {replaceState(state, title, url) { location.search = ''; location.hash = url.slice(url.indexOf('#')); }},
        t: (key, params) => params?.message ? `${key}: ${params.message}` : key,
        escapeHtml: value => String(value),
        isDashboardModuleEnabled: () => true,
        clearUndoHistory() {}, loadBackups() {},
        showToast(message) { throw new Error(message); }
    });
    context.fetch = (...args) => context.window.fetch(...args);
    vm.runInContext(source + `
        const DASHBOARD_PATH = '/admin/dashboard';
        renderPageList = function() {};
        createEditorShell = function() { return {content: {}}; };
        renderPageSettings = function() {};
        renderContentPanel = function() {};
    `, context);
    const routed = vm.runInContext('applyDashboardRoute(true)', context);
    await contentRequested.promise;
    if (listFirst) {
        listReply.resolve();
        await new Promise(setImmediate);
    }
    contentReply.resolve();
    await routed;
    listReply.resolve();
    await new Promise(setImmediate);

    assert.deepEqual(loadedPages, [pageKey]);
    assert.equal(get('pageSelect').value, slug, 'Desktop selection survives background refresh');
    assert.equal(get('pageSelectMobile').value, slug, 'Mobile page matches the editor');
    assert.equal(get('langSelectMobile').value, lang, 'Mobile language matches the editor');
    assert.equal(get('editorTitle').textContent, content.title, 'Heading describes loaded content');
    assert.equal(vm.runInContext('currentPage', context), pageKey);
    assert.equal(vm.runInContext('currentContent.page', context), pageKey);
    assert.equal(location.hash, `#page/${pageKey}`);
}

(async () => {
    let scenarios = 0;
    for (const [lang, slug] of [['de', 'home'], ['de', 'team'], ['en', 'contact']]) {
        for (const hashRoute of [false, true]) {
            for (const listFirst of [false, true]) {
                await checkRoute(lang, slug, hashRoute, listFirst);
                scenarios++;
            }
        }
    }
    console.log(`Editor routing: ${scenarios} scenarios passed (query/hash links, both API reply orders, desktop/mobile selectors).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
