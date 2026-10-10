const { apiSource } = require('./source-helpers');
const { readFileSync } = require('fs');
const { dirname, resolve } = require('path');

// Contract of every ai-agent-* API action: module gate, right, method, CSRF, rate limits, ownership and audit.
const root = dirname(__dirname);
const source = apiSource();
const agent = readFileSync(resolve(root, 'admin/api/agent.php'), 'utf8');
const routes = readFileSync(resolve(root, 'admin/api/routes.php'), 'utf8');
const contracts = readFileSync(resolve(root, 'admin/api/contracts.php'), 'utf8');
const includes = ['run', 'tools', 'changeset', 'changeset-apply', 'changeset-ops', 'media', 'research', 'settings', 'agent-config', 'preview']
    .map(name => readFileSync(resolve(root, 'includes/ai/agent/' + name + '.php'), 'utf8')).join('\n');

function fail(message) { throw new Error(message); }
function assertContains(haystack, needle, message) { if (!haystack.includes(needle)) fail(message); }

function blockFor(action) {
    const needle = "case '" + action + "':";
    const start = agent.indexOf(needle);
    if (start === -1) fail('Missing API action: ' + action);
    const rest = agent.slice(start + needle.length);
    const next = rest.search(/\n    case '/);
    return next === -1 ? rest : rest.slice(0, next);
}

const readActions = ['ai-agent-status', 'ai-agent-changeset', 'ai-agent-media'];
const writeActions = ['ai-agent-start', 'ai-agent-step', 'ai-agent-reply', 'ai-agent-cancel', 'ai-agent-revalidate', 'ai-agent-apply', 'ai-agent-discard', 'ai-agent-undo', 'ai-agent-publish', 'ai-agent-upload'];
const modelActions = ['ai-agent-start', 'ai-agent-step', 'ai-agent-reply', 'ai-agent-upload'];
const all = readActions.concat(writeActions);

// The route table knows exactly these actions, and the agent file serves exactly these.
const routed = Array.from(routes.matchAll(/'(ai-agent-[a-z-]+)' => 'agent'/g)).map(match => match[1]).sort();
if (JSON.stringify(routed) !== JSON.stringify(all.slice().sort())) fail('Route table and contract disagree: ' + routed.join(', '));
const served = Array.from(agent.matchAll(/case '(ai-agent-[a-z-]+)':/g)).map(match => match[1]).sort();
if (JSON.stringify(served) !== JSON.stringify(all.slice().sort())) fail('agent.php serves ' + served.join(', '));

// Reads may use GET, everything else is POST with the session CSRF token (checked once for all of them).
const readOnlyList = contracts.slice(contracts.indexOf('$readOnly'), contracts.indexOf('$oauthCallback'));
for (const action of readActions) assertContains(readOnlyList, "'" + action + "'", action + ' should be a read-only (GET) action.');
for (const action of writeActions) if (readOnlyList.includes("'" + action + "'")) fail(action + ' must not be allowed over GET.');
assertContains(contracts, "$_SERVER['REQUEST_METHOD'] === 'GET' && !$readOnly", 'GET on a write action must be refused.');
assertContains(contracts, "$_SERVER['REQUEST_METHOD'] !== 'GET' && !validateCsrfToken()", 'Every non-GET request must carry the CSRF token.');
assertContains(contracts, 'http_response_code(405)', 'GET on a write action should answer 405.');

for (const action of all) {
    const block = blockFor(action);
    assertContains(block, 'nibblyAgentApiGuard(', action + ' should go through the shared guard (module gate, chat right, user).');
}
assertContains(agent, 'dashboardAiModuleEnabled()', 'The guard should respect the global AI module gate.');
assertContains(agent, "nibblyAgentCan('chat')", 'The guard should check the agent right.');
for (const action of modelActions) assertContains(blockFor(action), 'nibblyAgentApiGuard(true)', action + ' needs the model, so it should check availability.');
for (const action of all.filter(action => !modelActions.includes(action))) assertContains(blockFor(action), 'nibblyAgentApiGuard(false)', action + ' should not need the model.');

// Rate limits
assertContains(blockFor('ai-agent-start'), "nibblyAgentApiRateLimit($actor, 'runs')", 'Starting runs is rate limited per user.');
assertContains(blockFor('ai-agent-step'), "nibblyAgentApiRateLimit($actor, 'steps')", 'Steps are rate limited per user.');
assertContains(blockFor('ai-agent-upload'), "'uploads'", 'Uploads are rate limited per user.');
assertContains(blockFor('ai-agent-start'), "nibblyCopilotAssertBurstLimit('agent'", 'Starting runs also has the session burst limiter.');
assertContains(blockFor('ai-agent-step'), "nibblyCopilotAssertBurstLimit('agent'", 'Steps also have the session burst limiter.');
assertContains(agent, 'http_response_code(429)', 'Rate limits answer 429.');
assertContains(agent, "header('Retry-After:", 'Rate limits tell when to retry.');
assertContains(blockFor('ai-agent-step'), 'session_write_close()', 'A step must not hold the session lock while the model works.');

// Rights and ownership
assertContains(blockFor('ai-agent-upload'), "nibblyAgentCan('uploadImages')", 'Uploads need their own right.');
assertContains(blockFor('ai-agent-upload'), "$target['owner'] !== $actor['userKey']", 'Uploads attach only to the user\'s own change sets.');
assertContains(blockFor('ai-agent-media'), 'nibblyAgentCanSeeChangeset', 'Staged images are for the owner and admins only.');
assertContains(blockFor('ai-agent-changeset'), 'nibblyAgentCanSeeChangeset', 'Change sets are for the owner and admins only.');
assertContains(blockFor('ai-agent-publish'), 'array_intersect_key($item', 'The publish item is cut down to known keys.');
assertContains(includes, 'function nibblyAgentCanSeeRun', 'Runs have an owner check.');
assertContains(includes, "$cs['status'] !== 'ready'", 'The preview is only for ready packages.');
assertContains(includes, "hash_equals((string)$cs['owner'], $user)", 'The preview is only for the owner of the package.');
assertContains(includes, 'You are not allowed to publish.', 'Publishing needs its own right.');
assertContains(blockFor('ai-agent-apply'), "($_POST['confirmed'] ?? '') !== '1'", 'Apply needs an explicit confirmation flag.');
assertContains(blockFor('ai-agent-apply'), 'previewHash', 'Apply is bound to the hash the user reviewed.');
assertContains(blockFor('ai-agent-apply'), 'http_response_code(409)', 'A conflict answers 409.');

// The browser never sends the transcript, tool results or a model: only ids and the user's own words.
for (const field of ['messages', 'transcript', 'toolResults', 'model', 'tools']) {
    if (blockFor('ai-agent-start').includes("$_POST['" + field + "']") || blockFor('ai-agent-step').includes("$_POST['" + field + "']") || blockFor('ai-agent-reply').includes("$_POST['" + field + "']")) {
        fail('Agent actions must not read ' + field + ' from the browser.');
    }
}

// Audit: every state change leaves a record, without content
for (const action of ['agent-run-start', 'agent-run-cancelled', 'agent-run-failed', 'agent-tool', 'agent-apply', 'agent-undo', 'agent-publish', 'agent-discard', 'agent-upload', 'agent-research-search', 'agent-research-read', 'agent-image-job']) {
    assertContains(includes + agent, "nibblyAgentAudit('" + action + "'", 'Missing audit record: ' + action);
}
assertContains(includes, "'argsHash' => hash('sha256'", 'Tool calls are audited with a hash of the arguments, not the arguments.');
assertContains(includes, "'goalHash' => hash('sha256', $goal)", 'The run start audits a hash of the goal, not the goal.');
if (/'goal'\s*=>\s*\$goal/.test(includes)) fail('The goal text must not be written to the audit log.');

// Without the module or without the agent the page offers no agent at all
const footer = readFileSync(resolve(root, 'includes/footer.php'), 'utf8');
assertContains(footer, 'nibblyAgentAvailable($_aiAgentReason) && nibblyAgentCan(\'chat\')', 'The footer offers the agent only when it is available for this user.');
assertContains(footer, "if ($_aiAgentConfig !== null && file_exists(__DIR__ . '/../js/ai-agent.js'))", 'js/ai-agent.js is only loaded when the agent is available.');
assertContains(footer, "array_map('nibblyAgentCan', NIBBLY_AGENT_RIGHTS)", 'The page gets the rights of the signed-in user only.');
if (!source.includes('ai-agent-')) fail('Agent actions are not part of the API source.');

console.log('Agent API contract: ' + all.length + ' actions (gate, right, method, CSRF, rate limits, ownership, audit)');
