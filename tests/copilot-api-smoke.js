const { readFileSync } = require('fs');
const { dirname, resolve } = require('path');

const root = dirname(__dirname);
const source = readFileSync(resolve(root, 'admin/api.php'), 'utf8');
const helper = readFileSync(resolve(root, 'includes/ai/ai-helper.php'), 'utf8');
const context = readFileSync(resolve(root, 'includes/ai/copilot-context.php'), 'utf8');

function blockFor(action) {
    const needle = "case '" + action + "':";
    const start = source.indexOf(needle);
    if (start === -1) {
        throw new Error('Missing API action: ' + action);
    }
    const rest = source.slice(start + needle.length);
    const next = rest.search(/\n\s*case '/);
    return next === -1 ? rest : rest.slice(0, next);
}

function assertContains(block, needle, message) {
    if (!block.includes(needle)) {
        throw new Error(message);
    }
}

const actions = [
    'ai-copilot-context',
    'ai-copilot-chat-stream',
    'ai-copilot-translate',
    'ai-content-audit',
    'ai-content-audit-suggest',
    'ai-content-audit-apply',
    'ai-copilot-history-list',
    'ai-copilot-history-load',
    'ai-copilot-history-save',
    'ai-copilot-history-delete',
    'ai-copilot-chat',
    'ai-copilot-suggest',
    'ai-copilot-format-html',
    'ai-copilot-visibility',
    'ai-copilot-apply',
    'ai-copilot-apply-visibility',
    'ai-copilot-undo',
    'ai-copilot-draft-content',
    'ai-copilot-create-content',
    'ai-copilot-publish-content',
    'ai-copilot-generate-image'
];

const blocks = Object.fromEntries(actions.map(action => [action, blockFor(action)]));
const keepaliveBlock = blockFor('keepalive');

for (const [action, block] of Object.entries(blocks)) {
    assertContains(block, 'dashboardAiModuleEnabled()', action + ' should respect the global AI module gate.');
    assertContains(block, 'validateCsrfToken()', action + ' should require CSRF validation.');
}
assertContains(source, 'return nibblySessionValidate();', 'Authenticated API requests should use shared session validation and activity refresh.');
assertContains(keepaliveBlock, 'validateCsrfToken()', 'Keepalive should require CSRF validation.');
assertContains(keepaliveBlock, "jsonResponse(true, ['time' => time()]", 'Keepalive should return a successful timestamp response.');

for (const action of ['ai-copilot-history-list', 'ai-copilot-history-load', 'ai-copilot-history-save', 'ai-copilot-history-delete', 'ai-copilot-chat', 'ai-copilot-suggest', 'ai-copilot-format-html', 'ai-copilot-visibility', 'ai-copilot-apply', 'ai-copilot-apply-visibility', 'ai-copilot-undo', 'ai-copilot-draft-content', 'ai-copilot-create-content', 'ai-copilot-publish-content', 'ai-copilot-generate-image']) {
    assertContains(blocks[action], 'nibblyCopilotAssertBurstLimit(', action + ' should enforce a Copilot burst limit.');
}

for (const action of ['ai-copilot-apply', 'ai-copilot-apply-visibility', 'ai-copilot-undo', 'ai-copilot-create-content', 'ai-copilot-publish-content']) {
    assertContains(blocks[action], 'dashboardCopilotConfirmed()', action + ' should require explicit confirmation.');
}

assertContains(blocks['ai-copilot-chat'], "nibblyCopilotCan('chat')", 'Copilot chat should require chat permission.');
assertContains(blocks['ai-copilot-chat-stream'], "nibblyCopilotCan('chat')", 'Copilot chat streaming should require chat permission.');
assertContains(blocks['ai-copilot-chat-stream'], 'nibblyCopilotAssertBurstLimit(', 'Copilot chat streaming should enforce a burst limit.');
assertContains(blocks['ai-copilot-translate'], "nibblyCopilotCan('suggestField')", 'Copilot translate should require suggest permission.');
assertContains(blocks['ai-copilot-translate'], 'nibblyCopilotAssertBurstLimit(', 'Copilot translate should enforce a burst limit.');
assertContains(blocks['ai-copilot-translate'], 'nibblyCopilotValidateProposals(', 'Copilot translate should validate and sign proposals.');
assertContains(blocks['ai-content-audit'], 'isAdmin()', 'Content audit should be admin-only.');
assertContains(blocks['ai-content-audit-suggest'], 'isAdmin()', 'Content audit suggestions should be admin-only.');
assertContains(blocks['ai-content-audit-apply'], 'isAdmin()', 'Content audit apply should be admin-only.');
assertContains(blocks['ai-content-audit-apply'], 'dashboardCopilotConfirmed()', 'Content audit apply should require explicit confirmation.');
assertContains(blocks['ai-content-audit-apply'], 'dashboardCopilotCreatePageBackup(', 'Content audit apply should create a page backup first.');
assertContains(blocks['ai-copilot-context'], "dashboardCopilotUiLanguage()", 'Copilot context should include the active dashboard language.');
assertContains(blocks['ai-copilot-chat'], "dashboardCopilotUiLanguage()", 'Copilot chat should include the active dashboard language.');
assertContains(helper, "'assistantForceEnglish' => false", 'AI defaults should include the Assistant force-English setting.');
assertContains(helper, "'assistantForceEnglish' => !empty($input['assistantForceEnglish'])", 'AI settings save should persist the Assistant force-English setting.');
assertContains(helper, "'assistantSurfaces' => [", 'AI defaults should include Assistant surface visibility settings.');
assertContains(helper, "'visualEditor' => !array_key_exists('assistantSurfaces', $input) || !empty($input['assistantSurfaces']['visualEditor'])", 'AI settings save should persist the Visual Editor Assistant visibility setting.');
assertContains(helper, "'dashboard' => !array_key_exists('assistantSurfaces', $input) || !empty($input['assistantSurfaces']['dashboard'])", 'AI settings save should persist the Dashboard Assistant visibility setting.');
assertContains(context, 'assistantLanguage', 'Copilot context should expose the resolved Assistant language.');
assertContains(context, 'function nibblyCopilotContentPath(string $contentPage): string', 'Copilot context should resolve non-page content targets.');
assertContains(context, "preg_match('/^news:", 'Copilot context should support news post targets.');
assertContains(context, 'Always answer in English', 'Copilot system prompt should force English when configured.');
assertContains(context, 'Answer in the dashboard UI language', 'Copilot system prompt should follow the dashboard language by default.');
for (const action of ['ai-copilot-history-list', 'ai-copilot-history-load', 'ai-copilot-history-save', 'ai-copilot-history-delete']) {
    assertContains(blocks[action], "nibblyCopilotCan('chat')", action + ' should require chat permission.');
}
assertContains(blocks['ai-copilot-history-save'], 'dashboardCopilotCleanHistoryMessages', 'Copilot history save should sanitize archived messages.');
assertContains(blocks['ai-copilot-history-load'], 'dashboardCopilotLoadOwnedHistory', 'Copilot history load should enforce per-user ownership.');
assertContains(blocks['ai-copilot-history-delete'], 'dashboardCopilotLoadOwnedHistory', 'Copilot history delete should enforce per-user ownership.');
assertContains(blocks['ai-copilot-suggest'], "nibblyCopilotCan('suggestField')", 'Copilot suggestions should require suggest permission.');
assertContains(blocks['ai-copilot-format-html'], "nibblyCopilotCan('suggestField')", 'Copilot HTML formatting should require suggest permission.');
assertContains(blocks['ai-copilot-visibility'], "nibblyCopilotCan('toggleVisibility')", 'Copilot visibility drafts should require visibility permission.');
assertContains(blocks['ai-copilot-apply'], "nibblyCopilotCan('applyField')", 'Copilot apply should require apply permission.');
assertContains(blocks['ai-copilot-apply-visibility'], "nibblyCopilotCan('toggleVisibility')", 'Copilot visibility apply should require visibility permission.');
assertContains(blocks['ai-copilot-undo'], "nibblyCopilotCan('undoField')", 'Copilot undo should require undo permission.');
assertContains(blocks['ai-copilot-generate-image'], "nibblyCopilotCan('generateImage')", 'Copilot image generation should require image permission.');
assertContains(blocks['ai-copilot-generate-image'], "nibblyAiEnsureFeature($settings, 'imageGeneration')", 'Copilot image generation should require the imageGeneration feature.');
assertContains(blocks['ai-copilot-generate-image'], "['auto', '1024x1024', '1536x1024', '1024x1536']", 'Copilot image generation should allowlist image sizes.');
assertContains(blocks['ai-copilot-generate-image'], 'max(1, min(4', 'Copilot image generation should clamp variant count.');
assertContains(blocks['ai-copilot-generate-image'], "['auto', 'low', 'medium', 'high']", 'Copilot image generation should allowlist image quality.');
assertContains(blocks['ai-copilot-create-content'], "'page' => 'createPage'", 'Copilot create should map page create permission.');
assertContains(blocks['ai-copilot-create-content'], "'news' => 'createNews'", 'Copilot create should map news create permission.');
assertContains(blocks['ai-copilot-create-content'], "'event' => 'createEvent'", 'Copilot create should map event create permission.');
assertContains(blocks['ai-copilot-publish-content'], "'page' => 'publishPage'", 'Copilot publish should map page publish permission.');
assertContains(blocks['ai-copilot-publish-content'], "'news' => 'publishNews'", 'Copilot publish should map news publish permission.');
assertContains(blocks['ai-copilot-publish-content'], "'event' => 'publishEvent'", 'Copilot publish should map event publish permission.');

console.log(JSON.stringify({
    ok: true,
    checks: [
        'copilotActionsPresent',
        'moduleGates',
        'csrfGates',
        'burstLimits',
        'confirmationGates',
        'permissionGates',
        'visibilityPermissionGates',
        'imageFeatureGate',
        'imageOptionValidation',
        'contentPermissionMaps'
    ]
}));
