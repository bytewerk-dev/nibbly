const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { execFileSync } = require('node:child_process');
const { runInNewContext } = require('node:vm');

const source = readFileSync(resolve(__dirname, '../admin/dashboard.php'), 'utf8');
const assignment = source.match(/^\$aiDashboardVisible = .+;$/m);
assert.ok(assignment, 'Dashboard visibility gate must exist');
const phpResults = JSON.parse(execFileSync('php', ['-r',
    '$results = []; foreach ([false, true] as $aiFeaturesEnabled) {' +
    'foreach ([false, true] as $isAdminUser) {' + assignment[0] +
    '$results[] = $aiDashboardVisible; }} echo json_encode($results);'
], { encoding: 'utf8' }));
assert.deepEqual(phpResults, [false, false, true, true],
    'Disabled module must hide the server-rendered panel, including for admins');
assert.match(source, /<\?php if \(\$aiDashboardVisible\): \?>\s*<section[^>]+id="dashboardAiSection"/,
    'Visibility gate must wrap the AI section');

const start = source.indexOf('    function updateDashboardAiPanel(settings) {');
const end = source.indexOf("\n    document.getElementById('aiUnavailableDismiss')", start);
assert.ok(start >= 0 && end > start, 'AI panel updater must exist');
const updater = source.slice(start, end);
let cases = 0;
for (const moduleEnabled of [false, true]) {
    for (const configured of [false, true]) {
        for (const enabled of [false, true]) {
            for (const dismissed of [false, true]) {
                const ids = ['dashboardAiSection', 'aiUnavailableBanner', 'aiUnavailableText',
                    'dashboardAiTools', 'aiUsageSummary', 'aiImageJobsPanel',
                    'aiAssistantCard', 'aiToolsCard'];
                const elements = Object.fromEntries(ids.map(id => [id, { hidden: false }]));
                const settings = { enabled };
                const context = {
                    AI_FEATURES_ENABLED: moduleEnabled,
                    currentAiSettings: settings,
                    dashboardAiImageUsable: false,
                    document: {
                        getElementById: id => elements[id] || null,
                        querySelectorAll: () => [],
                        querySelector: () => null
                    },
                    window: {},
                    aiProviderIsConfigured: () => configured,
                    aiUnavailableNoticeDismissed: () => dismissed,
                    aiFeatureEnabled: () => true,
                    updateDashboardAiStatus: () => {},
                    switchAiToolTab: () => {},
                    t: key => key
                };
                runInNewContext(updater + '\nupdateDashboardAiPanel(currentAiSettings);', context);
                const usable = moduleEnabled && configured && enabled;
                const label = JSON.stringify({ moduleEnabled, configured, enabled, dismissed });
                assert.equal(elements.dashboardAiSection.hidden,
                    !moduleEnabled || (!usable && dismissed), label + ': panel visibility');
                assert.equal(elements.aiUnavailableBanner.hidden, usable, label + ': notice');
                assert.equal(elements.dashboardAiTools.hidden, !usable, label + ': tools');
                assert.equal(context.dashboardAiImageUsable, usable, label + ': image capability');
                cases++;
            }
        }
    }
}
runInNewContext(updater + '\nupdateDashboardAiPanel({});', {
    document: { getElementById: () => null }
});
console.log('Dashboard AI visibility smoke passed (PHP role/module gate, ' + cases + ' UI states, absent panel).');
