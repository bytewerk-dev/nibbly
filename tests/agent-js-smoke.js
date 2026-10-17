const { readFileSync } = require('fs');
const { dirname, resolve } = require('path');

// Structure and safety rules of js/ai-agent.js and its hooks in js/ai-copilot.js.
const root = dirname(__dirname);
const agent = readFileSync(resolve(root, 'js/ai-agent.js'), 'utf8');
const copilot = readFileSync(resolve(root, 'js/ai-copilot.js'), 'utf8');
const styles = readFileSync(resolve(root, 'css/ai-copilot.css'), 'utf8');
const footer = readFileSync(resolve(root, 'includes/footer.php'), 'utf8');

function fail(message) { throw new Error(message); }
function assertContains(haystack, needle, message) { if (!haystack.includes(needle)) fail(message); }
function assertMatches(haystack, pattern, message) { if (!pattern.test(haystack)) fail(message); }
function assertAbsent(haystack, pattern, message) { if (pattern.test(haystack)) fail(message); }
function functionBody(source, name) {
    const start = source.search(new RegExp('(async )?function ' + name + '\\('));
    if (start === -1) fail('Missing function ' + name);
    const rest = source.slice(start);
    const next = rest.slice(10).search(/\n    (async )?function /);
    return next === -1 ? rest : rest.slice(0, next + 10);
}

// ---- Loading and mounting --------------------------------------------------------------------------
assertContains(agent, 'window.NB_AI_AGENT', 'The agent reads its availability and the user\'s rights from the page.');
assertContains(agent, 'config.available !== true', 'Without the page flag the agent does nothing.');
assertContains(agent, 'window.NibblyAiCopilot.registerAgent', 'The agent plugs into the assistant through registerAgent.');
assertContains(copilot, 'function registerAgent(hook)', 'The assistant offers registerAgent.');
assertContains(copilot, 'if (agentHook || !hook) return false;', 'The agent registers in both modes: the website and the dashboard.');
if (copilot.includes("copilotMode !== 'frontend'")) fail('The agent also runs in the dashboard: registerAgent must not be limited to the frontend.');
assertContains(copilot, 'if (agentHook) agentHook.mount(root, agentHost());', 'The shell mounts the agent controls.');
assertContains(copilot, 'embed,\n        registerAgent\n    };', 'embed and registerAgent are part of the public assistant API.');
assertContains(footer, "window.NB_AI_AGENT = <?php echo json_encode($_aiAgentConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;", 'The page config is JSON-encoded with HEX_TAG.');

// ---- A "yes" never applies a package -------------------------------------------------------------------
const handle = functionBody(copilot, 'handleUserMessage');
const agentIndex = handle.indexOf('agentHook.handleMessage(content)');
const confirmIndex = handle.indexOf('isConfirmationIntent(content)');
if (agentIndex === -1 || confirmIndex === -1 || agentIndex > confirmIndex) fail('In agent mode the agent must take the message before any confirmation intent is looked at.');
assertContains(handle, 'return;', 'The assistant stops after handing the message to the agent.');
assertAbsent(agent, /isConfirmationIntent|looksLike[A-Z]|applyProposal/, 'The agent never interprets chat text as a confirmation.');
const applies = agent.split("'ai-agent-apply'").length - 1;
if (applies !== 1) fail('ai-agent-apply is called from exactly one place (found ' + applies + ').');
const applyBody = functionBody(agent, 'applyPackage');
if (applyBody.indexOf("host.post('ai-agent-apply'") < applyBody.indexOf('host.confirm(')) fail('Applying a package asks for confirmation first.');
assertContains(applyBody, 'if (!confirmed) return;', 'Cancelling the confirmation applies nothing.');
assertContains(applyBody, "review.status !== 'ready'", 'Only a ready package can be applied.');
assertContains(applyBody, 'previewHash: review.previewHash', 'Apply is bound to the reviewed hash.');
assertContains(applyBody, "confirmed: '1'", 'Apply sends the explicit confirmation flag.');
for (const [action, name] of [['ai-agent-discard', 'discardPackage'], ['ai-agent-undo', 'undoPackage'], ['ai-agent-publish', 'publishItem']]) {
    const body = functionBody(agent, name);
    if (body.indexOf("host.post('" + action + "'") < body.indexOf('host.confirm(')) fail(name + ' asks for confirmation first.');
}

// ---- Output safety --------------------------------------------------------------------------------------------
assertAbsent(agent, /\beval\s*\(|new Function\s*\(|document\.write|insertAdjacentHTML|outerHTML\s*=|srcdoc|javascript:/, 'No dynamic code, document.write or javascript: URLs.');
const innerHtml = agent.match(/\.innerHTML\s*=/g) || [];
if (innerHtml.length !== 3) fail('innerHTML is assigned in exactly three places (clearing the chips, the chips, the controls; all with escaped values): ' + innerHtml.length);
assertContains(agent, 'function esc(value)', 'All dynamic text goes through esc().');
assertContains(agent, 'function safeHttpUrl(value)', 'External links are checked.');
assertContains(agent, "url.protocol === 'http:' || url.protocol === 'https:'", 'Only http(s) links are rendered.');
assertContains(agent, 'target="_blank" rel="noopener noreferrer"', 'External links open safely.');
assertContains(agent, 'function safeLocalPath(value)', 'Preview paths are checked.');
assertContains(agent, "/^\\/(?!\\/)[^\\s\\\\]*$/", 'Preview links stay on this site.');
assertContains(agent, "new DOMParser().parseFromString(text, 'text/html').body.textContent", 'HTML values are reduced to text with a parser that never runs scripts.');
assertContains(agent, "window.location.assign(url.href)", 'The preview opens in this tab, where the open package stays.');
assertContains(agent, "/^#nb-agent-(apply|open)(?:=(cs_[a-f0-9]{8,}))?$/", 'Only the two known hashes from the preview bar are read.');
assertAbsent(agent, /BroadcastChannel|nibbly-agent-apply-request/, 'No cross-tab messaging between the preview and the assistant.');
assertContains(agent, "url.searchParams.set('nb_agent_preview', review.id)", 'The preview is requested with the package id only.');
// ---- Behaviour ----------------------------------------------------------------------------------------------------------
assertContains(agent, "host.post('ai-agent-start'", 'The agent starts runs through the API.');
assertContains(agent, "host.post('ai-agent-step', { runId }, stepTimeoutMs)", 'Steps are driven by the browser with a long timeout.');
assertContains(agent, "'ai-agent-reply'", 'Answers and change requests go through reply.');
assertContains(agent, "'ai-agent-cancel'", 'Runs can be cancelled.');
assertContains(agent, "apiGet('ai-agent-changeset'", 'The review is read with GET.');
assertContains(agent, "apiGet('ai-agent-status'", 'A run is resumed with GET.');
assertContains(agent, "const stored = { runId: state.run.runId, goal: String(state.lastGoal || '').slice(0, 2000) };", 'Only the run id and the goal are kept in session storage.');
assertContains(agent, "sessionStorage.setItem(RUN_KEY, JSON.stringify(stored))", 'The session storage entry is that object.');
assertContains(agent, "JSON.stringify({ at: Date.now(), runId: stored.runId })", 'Local storage keeps nothing but the run id and a time, so another tab finds the open package.');
assertAbsent(agent, /localStorage\.setItem\([^)]*(token|csrf|goal|stored\b)/i, 'No goal text or token in local storage.');
assertContains(agent, "csrf_token', host.csrfToken", 'Uploads carry the CSRF token.');
assertContains(agent, "if (!/^image\\/(jpeg|png|webp)$/.test(file.type)", 'The browser prefilters image types (the server decides).');
assertContains(agent, 'maxUploadFiles', 'The number of attached images is limited.');
assertContains(agent, 'failures >= 3', 'A flaky connection is retried a few times, then reported.');
assertContains(agent, "data.status === 'busy'", 'A busy run is polled again instead of failing.');
assertContains(agent, "'waiting_jobs'", 'Image jobs are waited for.');
assertContains(agent, "state.run.status === 'waiting_jobs' ? 2500 : 150", 'Waiting for images polls slowly.');
for (const tool of ['search_web', 'read_web_result', 'read_url', 'generate_image', 'import_stock_image', 'stage_create_news', 'present_changeset']) {
    assertContains(agent, "'" + tool + "'", tool + ' has a timeline text.');
}

// ---- Accessibility and layout ------------------------------------------------------------------------------------------
assertContains(agent, 'role="img" aria-label=', 'Warning icons have a text alternative.');
assertContains(agent, 'aria-pressed', 'The mode switch exposes its state.');
assertContains(agent, "role=\"' + (notice.type === 'error' ? 'alert' : 'status') + '\"", 'Notices are announced.');
assertContains(agent, 'aria-label="' + "' + esc(label('agent.mode_label'", 'The mode switch has a group label.');
assertContains(styles, '.nb-copilot--agent .nb-copilot-draft', 'Classic shortcuts are hidden in agent mode.');
assertContains(styles, '.nb-agent-drop', 'The drop zone has an overlay.');
assertContains(styles, 'overflow-wrap: anywhere', 'Long values wrap instead of overflowing.');
assertMatches(styles, /\.nb-agent-media-grid\s*\{[^}]*minmax\(120px, 1fr\)/, 'The image grid adapts to narrow panels.');
assertContains(styles, 'cursor: not-allowed', 'Disabled actions look disabled.');

// ---- Safety notes and places in the dashboard ---------------------------------------------------------
assertContains(applyBody, "confirmRisk: '1'", 'A package with safety notes is applied with a second, explicit confirmation flag.');
assertContains(applyBody, "agent.risk_confirm_button", 'The second confirmation has its own button text.');
if (applyBody.indexOf("host.post('ai-agent-apply'") < applyBody.indexOf("agent.risk_confirm_title")) fail('The safety confirmation is asked before the package is sent.');
assertContains(agent, "warning.code.indexOf('safety_') === 0", 'Safety notes are told apart by their code and shown on their own.');
assertContains(agent, "role=\"alert\"", 'Safety notes are announced.');
const linksBody = functionBody(agent, 'renderLinksCard');
assertContains(linksBody, 'target="_blank" rel="noopener"', 'Places in the dashboard open in a new tab without access to the opener.');
assertContains(linksBody, 'safeDashboardUrl(link.url)', 'Only addresses on this site are linked.');
const safeUrl = functionBody(agent, 'safeDashboardUrl');
assertContains(safeUrl, '(?!\\/)', 'A protocol-relative address is not accepted.');
const check = new Function('url', safeUrl.replace(/^function safeDashboardUrl\(url\)/, 'return (function(url)') + ')(url)');
for (const bad of ['//evil.example/x', 'https://evil.example', 'javascript:alert(1)', '/a b', '/"onmouseover="x', '']) if (check(bad) !== '') fail('Not a safe dashboard address: ' + bad);
if (check('/admin/dashboard.php#settings/language') === '') fail('A dashboard address is accepted.');

console.log('Agent JS: hooks, no implicit apply, confirmations, escaping, safe links, accessibility');
