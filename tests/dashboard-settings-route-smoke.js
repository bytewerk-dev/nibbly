/* Regression: a #settings/… link activates its tab during the initial route, before settings.php has run. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../admin/dashboard/scripts/settings.php'), 'utf8');
const head = source.match(/^<\?php[^\n]*\n([^]*?\n    function activateSettingsTab\([^]*?\n    }\n)/);
assert.ok(head, 'settings.php starts with the settings sub-navigation and activateSettingsTab');

const element = extra => Object.assign({ classList: { add() {}, remove() {} }, dataset: {}, value: '', add() {}, addEventListener() {} }, extra);
const tabs = ['branding', 'language'].map(tab => element({ dataset: { settingsTab: tab }, textContent: tab }));
let activePanel = null;
let activeTab = null;
tabs[1].classList = { add: () => { activeTab = 'language'; }, remove() {} };
const panel = element({ classList: { add: () => { activePanel = 'language'; }, remove() {} } });
const document = {
    getElementById: id => (id === 'settingsPanel-language' ? panel : null),
    querySelector: selector => (selector.includes('"language"') ? tabs[1] : null),
    querySelectorAll: () => tabs,
    addEventListener() {}
};
const window = { matchMedia: () => ({ matches: false, addEventListener() {} }) };
const context = vm.createContext({ document, window });

// The dashboard runs all scripts in one block and navigation.php applies the route first:
// the settings constants are still in their temporal dead zone at that moment.
vm.runInContext("activateSettingsTab('language', { silent: true });\n" + head[1], context);
assert.equal(activePanel, 'language', 'the linked settings tab is activated');
assert.equal(activeTab, 'language', 'the sub-navigation marks the linked tab');

console.log(JSON.stringify({ ok: true }));
