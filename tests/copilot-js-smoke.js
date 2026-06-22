const { readFileSync } = require('fs');
const { dirname, resolve } = require('path');

const root = dirname(__dirname);
const source = readFileSync(resolve(root, 'js/ai-copilot.js'), 'utf8');
const styles = readFileSync(resolve(root, 'css/ai-copilot.css'), 'utf8');
const adminStyles = readFileSync(resolve(root, 'admin/style.css'), 'utf8');
const footer = readFileSync(resolve(root, 'includes/footer.php'), 'utf8');
const dashboard = readFileSync(resolve(root, 'admin/dashboard.php'), 'utf8');
const adminApi = readFileSync(resolve(root, 'admin/api.php'), 'utf8');
const adminTokens = readFileSync(resolve(root, 'css/nibbly-admin-tokens.css'), 'utf8');
const inlineEditor = readFileSync(resolve(root, 'js/inline-editor.js'), 'utf8');

function assertContains(haystack, needle, message) {
    if (!haystack.includes(needle)) {
        throw new Error(message);
    }
}

function assertMatches(haystack, pattern, message) {
    if (!pattern.test(haystack)) {
        throw new Error(message);
    }
}

assertContains(source, 'role="dialog"', 'Copilot panel should expose a dialog role.');
assertContains(source, 'aria-live="polite"', 'Copilot messages should be announced politely.');
assertContains(source, 'function renderInlineMarkdown(value)', 'Copilot chat bubbles should render safe inline Markdown.');
assertContains(source, '<strong>$1</strong>', 'Copilot Markdown renderer should support bold text.');
assertContains(source, '<em>$2</em>', 'Copilot Markdown renderer should support italic text.');
assertContains(source, 'rel="noopener noreferrer"', 'Copilot Markdown links should be opened safely.');
assertContains(source, "list.setAttribute('aria-busy'", 'Copilot messages should expose loading state.');
assertContains(source, 'AbortController', 'Copilot requests should use abortable timeout handling.');
assertContains(source, 'copilot.request_timeout', 'Copilot request timeouts should produce explicit feedback.');
assertContains(source, 'const imageRequestTimeoutMs = 300000', 'Image generation should get a longer timeout than ordinary chat requests.');
assertContains(source, 'copilot.image_request_timeout', 'Image generation timeouts should produce image-specific feedback.');
assertContains(source, 'copilot.session_expired', 'Copilot should show a specific re-login message when the admin session expires.');
assertContains(source, 'data.session_expired', 'Copilot API calls should detect server-side session expiration.');
assertContains(source, 'state.sessionExpired', 'Copilot should keep session expiration as panel state.');
assertContains(source, 'nb-copilot-spinner', 'Copilot loading feedback should include a visible spinner.');
assertContains(source, 'setLoading(true', 'Copilot actions should use explicit loading messages.');
assertContains(source, 'function confirmCopilot(options)', 'Copilot confirmations should use an internal modal promise.');
assertContains(source, 'data-copilot-modal-host', 'Copilot should render an internal modal host instead of native browser dialogs.');
assertContains(source, 'function closeCopilotModal(result)', 'Copilot internal modal should resolve explicit confirm/cancel choices.');
assertContains(source, 'function renderContentDraftForm()', 'Copilot should render missing content details in an internal modal form.');
assertContains(source, 'data-content-draft-field', 'Copilot content detail modal should expose structured form fields.');
assertContains(source, 'function submitContentDraftForm(form)', 'Copilot should submit content detail form fields back into the draft flow.');
assertContains(source, 'nb-copilot-history-new', 'History modal should use a text-button class separate from the header new-chat icon button.');
assertContains(source, 'function restoreSession()', 'Assistant chat history should be restored after a page reload.');
assertContains(source, 'window.sessionStorage.setItem(sessionKey()', 'Assistant chat history should persist in session storage.');
assertContains(source, "'nibbly-ai-assistant:'", 'Assistant session storage should use the new product name.');
assertContains(source, "post('ai-copilot-history-save'", 'Assistant chats should be archived through the server API.');
assertContains(source, "post('ai-copilot-history-list'", 'Assistant should load archived chat sessions.');
assertContains(source, "post('ai-copilot-history-load'", 'Assistant should restore archived chat sessions.');
assertContains(source, "post('ai-copilot-history-delete'", 'Assistant should delete archived chat sessions through the server API.');
assertContains(source, 'nb-copilot-history-btn', 'Assistant header should expose a chat history button.');
assertContains(source, 'nb-copilot-new-btn', 'Assistant header should expose a new chat button.');
assertContains(source, 'nb-copilot-delete-btn', 'Assistant header should expose a delete current chat button.');
assertContains(source, 'function setMaximized(maximized)', 'Assistant panel should support maximized and normal states.');
assertContains(source, 'nb-copilot-maximize', 'Assistant header should expose a maximize/minimize button.');
assertContains(source, 'lastFocusedBeforeOpen', 'Copilot should remember focus before opening.');
assertContains(source, 'trapPanelFocus(event)', 'Copilot should trap Tab focus while open.');
assertContains(source, "event.key === 'Escape'", 'Copilot should close on Escape.');
assertContains(source, 'restoreTarget.focus()', 'Copilot should restore focus after closing.');
assertContains(source, 'window.NB_AI_FEATURES_ENABLED === false', 'Copilot JS should stop when AI module features are disabled.');
assertContains(source, 'window.NB_AI_COPILOT_AVAILABLE === false', 'Copilot JS should stop when Copilot is unavailable.');
assertContains(source, "window.NB_AI_COPILOT_MODE === 'dashboard'", 'Copilot JS should support a dashboard runtime mode.');
assertContains(source, 'syncContentPageFromHost()', 'Copilot JS should refresh dashboard page context from the host application.');
assertContains(source, 'function assistantUiLanguage()', 'Copilot JS should normalize the active dashboard language for API prompts.');
assertContains(source, 'uiLanguage: assistantUiLanguage()', 'Copilot JS should send the active dashboard language to Assistant API calls.');
assertContains(source, "if (copilotMode === 'dashboard') return false;", 'Dashboard mode should not expose frontend field-apply actions without a dashboard adapter.');
assertContains(source, 'meta[name="csrf-token"]', 'Copilot JS should require a CSRF meta token.');
assertContains(source, 'window.NB_ADMIN_API_URL || inferAdminApiUrl()', 'Copilot JS should fall back to a script-derived admin API URL.');
assertContains(source, "url.pathname.replace(/\\/js\\/ai-copilot\\.js$/", 'Copilot JS should infer admin/api.php from the loaded script path.');
assertContains(source, 'function inferHostContentPage()', 'Copilot JS should infer special frontend content targets such as news posts.');
assertContains(source, "'news:' + String(window.__cmsNewsPost.id).trim()", 'Copilot JS should target the current news post when no page meta is present.');
assertContains(source, 'detectHtmlFormatIntent(instruction)', 'Copilot JS should detect deterministic HTML formatting intents.');
assertContains(source, "post('ai-copilot-format-html'", 'Copilot JS should request server-signed HTML format proposals without a model call.');
assertContains(source, "targetField.type === 'html'", 'Copilot JS should only use direct HTML formatting for a selected HTML field.');
assertContains(source, 'detectVisibilityIntent(instruction)', 'Copilot JS should detect explicit hide/show intents.');
assertContains(source, 'looksLikeChangeRequest(content)', 'Copilot Send should route likely change requests into the draft flow.');
assertContains(source, 'looksLikeImageRequest(content)', 'Copilot Send should route image generation requests into the image draft flow before generic field edits.');
assertContains(source, 'function looksLikeImageRequest(instruction)', 'Copilot JS should detect likely image generation requests.');
assertContains(source, 'looksLikeContentCreateRequest(content)', 'Copilot Send should route content creation requests into the content draft flow before generic chat.');
assertContains(source, 'function looksLikeContentCreateRequest(instruction)', 'Copilot JS should detect likely page/news/event creation requests.');
assertContains(source, 'draftContent(content)', 'Copilot JS should open the structured content draft flow for creation requests.');
assertContains(source, 'copilot.created_page_navigation_hint', 'Copilot should remind editors to configure navigation after creating a page.');
assertContains(source, 'function looksLikeNavigationMenuRequest(instruction)', 'Copilot JS should detect navigation/menu edit requests that are not safely writable yet.');
assertContains(source, 'copilot.navigation_manual_required', 'Copilot should clearly finish unsupported navigation requests instead of leaving a wait-style chat reply.');
assertContains(source, 'function ensureContextReady()', 'Copilot should wait for page context before routing image requests.');
assertContains(source, 'if (!state.context && !await ensureContextReady()) return;', 'Image requests should not fall back to generic chat while context is still loading.');
assertContains(source, 'isConfirmationIntent(content)', 'Copilot Send should route simple confirmations to pending preview actions.');
assertContains(source, 'ensureVisualEditorForWrite()', 'Copilot write flows should require the Visual Editor before preparing or applying page changes.');
assertContains(source, 'window.InlineEditor.enterEditMode()', 'Copilot should offer to activate the Visual Editor through the public editor API.');
assertContains(source, 'function applyAllProposals(options)', 'Copilot should support applying a grouped set of related proposals after one confirmation.');
assertContains(source, 'applyProposal(index, { skipConfirm: true })', 'Proposal Apply buttons should use the visible preview card as the confirmation step.');
assertContains(source, 'applyAllProposals({ skipConfirm: true })', 'Apply all should not show a second redundant confirmation modal after the preview summary.');
assertContains(source, 'copilot.no_pending_preview', 'Simple confirmations without a pending preview should not be forwarded to the model.');
assertContains(source, 'nb-copilot-proposal-summary', 'Copilot should summarize grouped proposals before individual cards.');
assertContains(source, 'const summary = state.proposals.length > 1', 'Copilot should not show Apply all summary for a single proposal.');
assertContains(source, 'proposalSummaryText(proposal)', 'Copilot grouped proposal summary should describe each pending change.');
assertContains(source, "post('ai-copilot-visibility'", 'Copilot JS should request server-signed visibility proposals without a model call.');
assertContains(source, "post('ai-copilot-apply-visibility'", 'Copilot JS should apply visibility proposals through a dedicated write endpoint.');
assertContains(source, 'visibilitySignature', 'Copilot JS should send the server visibility signature when applying.');
assertContains(source, 'resolveSelectedFieldForInstruction(instruction)', 'Copilot JS should refine selected fields based on the current instruction.');
assertContains(source, "state.selectedElement.matches('a[data-editable-link]')", 'Copilot JS should special-case selected editable links.');
assertContains(source, "basePath + '.href'", 'Copilot JS should prefer href/url fields for selected editable link target requests.');
assertContains(source, "basePath + '.text'", 'Copilot JS should prefer text fields for selected editable link label requests.');
assertContains(source, "path.endsWith('.url')", 'Copilot JS should patch editable links when URL-like fields change.');
assertContains(source, "fieldSelector(path + '.src')", 'Copilot JS should patch split image src fields when an object image path is returned.');
assertContains(source, "fieldSelector(path + '.image')", 'Copilot JS should patch alternative image key fields when an object image path is returned.');
assertContains(source, "path.replace(/\\.(src|image)$/", 'Copilot JS should patch object image fields when a split image path is returned.');
assertContains(source, 'patchAltField(element.getAttribute(\'data-alt-field\')', 'Copilot JS should patch split image alt fields after image updates.');
assertContains(source, 'function patchVisibility(path, hidden)', 'Copilot JS should patch field visibility after explicit hide/show applies.');
assertContains(source, "element.setAttribute('data-hidden', 'true')", 'Copilot JS should mark hidden fields in the DOM.');
assertContains(source, 'function renderImageDraftOptions()', 'Copilot JS should render image field/mode options inside the panel.');
assertContains(source, 'function imageFieldPreviewSrc(field)', 'Copilot image picker should resolve local, external, and DOM image thumbnail sources.');
assertContains(source, 'referrerpolicy="no-referrer"', 'Copilot image picker should render external thumbnail previews cautiously.');
assertContains(source, 'nb-copilot-modal--wide', 'Copilot image options should render in a modal instead of the chat stream.');
assertContains(source, 'nb-copilot-modal--image', 'Copilot image options modal should have a dedicated resizable dialog class.');
assertContains(source, 'data-image-draft-field', 'Copilot JS should let editors choose the target image field without a browser prompt.');
assertContains(source, 'data-image-draft-mode', 'Copilot JS should let editors choose generate/edit mode without a browser confirm.');
assertContains(source, 'data-image-draft-option', 'Copilot JS should let editors choose image provider options inside the panel.');
assertContains(source, 'data-image-draft-prompt', 'Copilot image draft options should expose an editable prompt field.');
assertContains(source, 'state.imageDraft.instruction = imagePromptEl.value', 'Copilot image prompt edits should update the pending image draft.');
assertContains(source, 'copilot.image_prompt_required', 'Copilot should require a prompt before starting image generation.');
assertContains(source, 'function extractImagePrompt(instruction)', 'Copilot should prefill image prompts from the likely visual subject, not the whole chat sentence.');
assertContains(source, "count: '1'", 'Copilot should default image generation to one variant to reduce provider timeouts.');
assertContains(source, 'function updateImageDraftSizeFromField(field)', 'Copilot should infer image generation size from the selected image field ratio.');
assertContains(source, 'imageSizeFromDimensions(img.naturalWidth, img.naturalHeight)', 'Copilot should map loaded image dimensions to a supported generation size.');
assertContains(source, 'state.imageDraft.sizeTouched', 'Copilot should not override a manually selected image size.');
assertContains(source, 'function runImageDraft()', 'Copilot JS should run image generation after panel options are chosen.');
assertContains(source, 'function updateImageDraftOption(name, value)', 'Copilot JS should normalize image option changes before sending them.');
assertContains(source, "imageMode: useCurrentAsReference ? 'edit' : 'generate'", 'Copilot JS should send the chosen image mode to the API.');
assertContains(source, "size: draft.options && draft.options.size", 'Copilot JS should send the chosen image size to the API.');
assertContains(source, "outputFormat: draft.options && draft.options.outputFormat", 'Copilot JS should send the chosen output format to the API.');
assertContains(source, "quality: draft.options && draft.options.quality", 'Copilot JS should send the chosen image quality to the API.');
assertContains(source, '}, imageRequestTimeoutMs).then(result => {', 'Copilot image generation should use the longer image request timeout.');
assertContains(source, 'function renderLastImageResult()', 'Copilot should keep a visible result strip after applying a generated image.');
assertContains(source, 'renderLastImageResult()', 'Copilot should render the applied generated image result in the message list.');
assertContains(source, 'lastImageResult', 'Copilot should persist the last applied generated image summary across reloads.');
assertContains(source, 'copilot.applied_image_message', 'Copilot should not describe applied generated images as drafts.');
assertContains(source, 'sendBtn.disabled = state.loading || state.sessionExpired || !hasPrompt', 'Copilot should block empty chat submissions and additional submissions while an action is running.');
assertContains(source, 'const value = input.value.trim();', 'Copilot action shortcuts should use only the current input value.');
assertContains(source, 'function renderChangeDraftForm()', 'Copilot change shortcut should open a structured change dialog.');
assertContains(source, 'data-change-draft-field', 'Copilot change dialog should let editors choose the target field.');
assertContains(source, 'function getChangeTargetFields()', 'Copilot change dialog should list safe editable target fields.');
assertContains(source, "if (/^seo(?:\\.|$)/i.test(String(field.path || ''))) return false;", 'Copilot change target dropdown should not list SEO fields.');
assertContains(source, "if (!/^sections\\./i.test(String(field.path || ''))) return false;", 'Copilot change target dropdown should only list fields addressable in the Visual Editor.');
assertContains(source, 'function truncateOptionLabel(value, limit)', 'Copilot change target dropdown should shorten long option labels.');
assertContains(source, 'function truncateTargetLabel(value, limit)', 'Copilot change target dropdown should preserve the trailing field name when shortening labels.');
assertContains(source, "truncateTargetLabel('[' + section[1] + '] ' + section[2] + ' - ' + section[3], 104)", 'Copilot change target dropdown should allow the wider modal label length.');
assertContains(source, "'[' + section[1] + '] ' + section[2] + ' - ' + section[3]", 'Copilot change target dropdown should structure section labels with bracketed context.');
assertContains(source, 'function renderCurrentFieldPreview(field)', 'Copilot change dialog should render the selected field current content preview.');
assertContains(source, 'data-change-draft-current', 'Copilot change dialog should expose a current-content preview block.');
assertContains(source, "label('copilot.current_field_content'", 'Copilot current-content preview should have a translatable heading.');
assertContains(source, "label('copilot.current_field_empty'", 'Copilot current-content preview should show a clear empty state.');
assertContains(source, "label('copilot.current_field_select'", 'Copilot current-content preview should explain when no target is selected.');
assertContains(source, "state.changeDraft.field = field || null;", 'Copilot target field changes should update the pending draft target.');
assertContains(source, "markContextElementByPath('');\n                }\n                renderMessages();", 'Copilot target field changes should refresh the dialog preview immediately.');
assertContains(styles, 'padding: 7px 40px 7px 14px;', 'Copilot selects should leave 40px right padding before the caret.');
assertContains(styles, '.nb-copilot select:focus', 'Copilot fields should override native browser focus colors.');
assertContains(styles, 'var(--editor-primary-light, var(--nb-brand', 'Copilot field focus should use the configured accent color instead of native orange.');
assertContains(styles, '.nb-copilot-content-fields label:has([data-change-draft-field])', 'Copilot target field select should span the full modal width.');
assertContains(styles, '.nb-copilot-current-field', 'Copilot current-content preview should be styled as an inline modal block.');
assertContains(styles, 'padding: 0 16px;', 'Copilot modal text buttons should have comfortable horizontal padding.');
assertContains(source, "label('copilot.choose_target_required'", 'Copilot change dialog should require a chosen target field.');
assertContains(source, 'function openContentDraftForm(briefing)', 'Copilot content shortcut should open a structured content dialog.');
assertContains(source, 'data-content-draft-type', 'Copilot content dialog should let editors choose the content type.');
assertContains(source, 'draftImage(value, { silent: true })', 'Copilot image shortcut should open image options without sending a chat message.');
assertContains(source, "if (copilotMode === 'frontend' && !await ensureVisualEditorForWrite()) return;", 'Frontend action shortcuts should check/activate the Visual Editor before opening workflows.');
assertContains(source, "const instruction = String(draft.instruction || '').trim();", 'Copilot image generation should require the visible image prompt field.');
assertContains(source, 'readableFieldLabel(state.lastApplied.path || undo.path ||', 'Copilot undo card should show a readable field label instead of a raw path.');
if (source.includes('input.value.trim() || state.lastInstruction')) {
    throw new Error('Copilot action shortcuts should not silently reuse the previous instruction.');
}
assertContains(source, "size: ['auto', '1024x1024', '1536x1024', '1024x1536']", 'Copilot JS should allow only supported image sizes.');
assertContains(source, "quality: ['auto', 'low', 'medium', 'high']", 'Copilot JS should allow only supported image qualities.');
assertContains(source, 'function fieldSelector(fieldPath)', 'Copilot JS should keep data-field selector construction centralized.');
assertContains(source, "document.querySelectorAll('[data-page]')", 'Copilot JS should infer the content page from editable field data when page metadata is missing.');
assertContains(inlineEditor, 'isEditMode: function() { return EditorConfig.editMode === true; }', 'Inline editor should expose read-only edit-mode state for Copilot gating.');

if (source.includes('window.confirm(') || source.includes('window.prompt(')) {
    throw new Error('Copilot should not use native browser confirm/prompt dialogs.');
}

const draftImageStart = source.indexOf('function draftImage(instruction, options)');
const runImageStart = source.indexOf('function runImageDraft()');
const trackSelectedStart = source.indexOf('function trackSelectedEditableField');
if (draftImageStart === -1 || runImageStart === -1 || trackSelectedStart === -1) {
    throw new Error('Could not isolate Copilot image draft functions.');
}
const draftImageBlock = source.slice(draftImageStart, runImageStart);
const runImageBlock = source.slice(runImageStart, trackSelectedStart);
if (draftImageBlock.includes('window.prompt') || draftImageBlock.includes('window.confirm') || runImageBlock.includes('window.prompt') || runImageBlock.includes('window.confirm')) {
    throw new Error('Image drafting should use in-panel options instead of native prompt/confirm dialogs.');
}

assertContains(footer, 'if ($isAdminLoggedIn):', 'Footer should load editor/Copilot assets only inside the logged-in admin block.');
assertContains(footer, 'nibblyAiLoadSettings(true)', 'Footer should use public AI settings for Copilot gating.');
assertContains(footer, '!empty($_aiPublicSettings[\'enabled\'])', 'Footer should require AI settings to be enabled.');
assertContains(footer, '!empty($_aiPublicSettings[\'hasApiKey\'])', 'Footer should require an AI API key before loading Copilot.');
assertContains(footer, '!empty($_aiPublicSettings[\'features\'][\'backendAssistant\'])', 'Footer should require backendAssistant before loading Copilot.');
assertContains(footer, "$_aiPublicSettings['assistantSurfaces']['visualEditor']", 'Footer should respect the Visual Editor Assistant visibility setting.');
assertContains(footer, "document.addEventListener('click', function(e) {\n            const adminAccess = e.target && e.target.closest ? e.target.closest('#adminAccess') : null;", 'Footer hidden admin access should use delegated click handling so inline-editor rerenders do not lose the handler.');
assertContains(footer, "adminUrl.searchParams.set('redirect', window.location.pathname + window.location.search + window.location.hash);", 'Footer hidden admin access should preserve the source page for post-login redirects.');
assertContains(footer, 'window.NB_ADMIN_BASE_URL', 'Footer should expose a base-path-aware admin base URL.');
assertContains(inlineEditor, "logoutUrl.searchParams.set('redirect', window.location.pathname + window.location.search + window.location.hash);", 'Frontend admin bar logout should preserve the current page.');
assertContains(footer, 'window.NB_AI_FEATURES_ENABLED', 'Footer should expose AI module availability to JS.');
assertContains(footer, 'window.NB_AI_COPILOT_AVAILABLE', 'Footer should expose Copilot availability to JS.');
assertContains(footer, 'window.NB_AI_ASSISTANT_LANGUAGE', 'Footer should expose the active admin language to the Assistant.');
assertContains(footer, "window.NB_ADMIN_API_URL = <?php echo json_encode($basePath . 'admin/api.php'", 'Footer should provide a base-path-aware admin API URL.');
assertContains(footer, "<?php if ($_aiCopilotAvailable && file_exists(__DIR__ . '/../css/ai-copilot.css')):", 'Footer should gate Copilot CSS by availability.');
assertContains(footer, "<?php if ($_aiCopilotAvailable && file_exists(__DIR__ . '/../js/ai-copilot.js')):", 'Footer should gate Copilot JS by availability.');
assertContains(dashboard, "window.NB_AI_COPILOT_MODE = 'dashboard';", 'Dashboard should load the shared Assistant in dashboard mode.');
assertContains(dashboard, 'window.NB_AI_ASSISTANT_LANGUAGE', 'Dashboard should expose the active admin language to the Assistant.');
assertContains(dashboard, 'window.NB_AI_COPILOT_GET_CONTENT_PAGE', 'Dashboard should expose the active page to the shared Assistant.');
assertContains(dashboard, "$_aiDashboardPublicSettings['assistantSurfaces']['dashboard']", 'Dashboard should respect the Dashboard Assistant visibility setting.');
assertContains(dashboard, 'ai-feature-toggle-grid', 'AI feature settings should render as a compact toggle grid.');
assertContains(dashboard, 'aiAssistantSurfaceVisualEditor', 'AI settings should include a Visual Editor Assistant visibility toggle.');
assertContains(dashboard, 'aiAssistantSurfaceDashboard', 'AI settings should include a Dashboard Assistant visibility toggle.');
assertContains(dashboard, 'imageGeneration: true', 'AI image generation should be enabled by default once a provider key is configured.');
assertContains(dashboard, 'function applyAiDefaultsForNewApiKey()', 'AI settings should auto-enable default features when a provider key is first entered.');
assertContains(dashboard, "primaryColor: '#3858e9'", 'Dashboard theme defaults should use the updated nibbly primary color.');
assertContains(dashboard, "accentColor: '#b45309'", 'Dashboard theme defaults should use a readable distinct accent color.');
assertContains(dashboard, 'function sanitizeThemeContrast(theme)', 'Dashboard theme save should enforce readable color contrast.');
assertContains(dashboard, 'function updateThemeContrastFeedback()', 'Dashboard theme form should show live contrast feedback for color fields.');
assertContains(dashboard, 'data-contrast-for="primaryColor"', 'Theme primary color field should expose contrast feedback.');
assertContains(dashboard, 'data-contrast-for="darkSidebarBg"', 'Theme dark sidebar color field should expose contrast feedback.');
assertContains(dashboard, "settings.contrast_error", 'Theme contrast feedback should label colors below the 3:1 UI minimum.');
assertContains(dashboard, 'ratio: ratio.toFixed(1)', 'Theme contrast feedback should round ratios to one decimal place.');
assertContains(dashboard, "hex.addEventListener('blur'", 'Theme color text inputs should refresh contrast when the field is left.');
assertContains(dashboard, "contrastRatio(result.primaryColor, '#0b0d12') < minReadable", 'Dashboard theme save should protect dark-theme auto inheritance from too-dark primary colors.');
assertContains(dashboard, '--nb-brand-subtle:', 'Dashboard first-paint theme CSS should expose an accent subtle token.');
assertContains(dashboard, "hexToRgba(c.accent, isDark ? 0.18 : 0.12)", 'Dashboard runtime theme CSS should expose an accent subtle token.');
assertContains(dashboard, "dashboard-status-chip--accent", 'Dashboard unread status chips should be able to use accent styling.');
assertContains(dashboard, '../css/ai-copilot.css', 'Dashboard should load Assistant CSS when available.');
assertContains(dashboard, '../js/ai-copilot.js', 'Dashboard should load Assistant JS when available.');
assertContains(adminStyles, 'background: var(--nb-brand);', 'Unread message badges should use the configured accent color.');
assertContains(adminStyles, 'box-shadow: 0 0 0 2px var(--nb-brand-subtle);', 'Unread message badges should use the accent subtle ring.');
assertContains(adminStyles, '.dashboard-status-chip--accent', 'Dashboard status chips should expose an accent modifier.');
assertContains(adminStyles, 'top: calc(56px + var(--nb-space-4));', 'Dashboard toasts should appear below the topbar instead of colliding with the Assistant button.');
assertContains(adminStyles, 'background: var(--nb-brand-subtle);', 'Success toasts should use the accent subtle fill.');
assertContains(adminStyles, 'background: var(--nb-brand);', 'Success toast icons should use the accent color.');
assertContains(adminStyles, '.toast.warning', 'Dashboard toasts should support warning messages for contrast guidance.');
assertContains(adminStyles, '.theme-contrast-feedback[data-state="error"]', 'Theme contrast feedback should style unreadable color warnings.');
assertContains(adminStyles, 'background: #16a34a;', 'Theme contrast ok dots should use fixed green.');
assertContains(adminStyles, 'background: #dc2626;', 'Theme contrast error dots should use fixed red.');
assertContains(dashboard, 'class="theme-color-preview" data-mode="light"', 'Light theme color preview should include an outer rectangular theme background wrapper.');
assertContains(dashboard, 'class="theme-color-preview" data-mode="dark"', 'Dark theme color preview should include an outer rectangular theme background wrapper.');
assertContains(adminStyles, '.theme-color-preview[data-mode="dark"] input', 'Dark theme color preview box should render controls in dark mode independent of the active dashboard theme.');
assertContains(adminStyles, 'background: color-mix(in srgb, var(--nb-bg-elevated) 82%, var(--nb-bg));', 'Button style preview should use the lighter preview background.');
assertMatches(adminStyles, /\.settings-tabs\s*\{[\s\S]*?box-shadow:\s*none;/, 'Settings sidebar should use a border-only container without shadow.');
assertMatches(adminStyles, /\.settings-panel\s*\{[\s\S]*?box-shadow:\s*none;/, 'Settings panels should use a border-only container without shadow.');
assertContains(adminApi, "'primaryColor' => '#3858e9'", 'Settings API defaults should use the updated nibbly primary color.');
assertContains(adminApi, "'accentColor' => '#b45309'", 'Settings API defaults should use a readable distinct accent color.');
assertContains(adminApi, 'function nibblySanitizeThemeContrast(array $theme): array', 'Settings API should enforce readable theme contrast server-side.');
assertContains(adminApi, 'nibblyAdjustColorForContrast((string)$theme[$key], \'#ffffff\'', 'Settings API should darken overly light light-theme colors.');
assertContains(adminApi, 'nibblyAdjustColorForContrast((string)$theme[$key], \'#0b0d12\'', 'Settings API should lighten overly dark dark-theme colors.');
assertContains(adminApi, "nibblyContrastRatio((string)$theme['primaryColor'], '#0b0d12') < $minReadable", 'Settings API should protect dark-theme auto inheritance from too-dark primary colors.');
assertContains(adminTokens, '--nb-primary: #3858e9;', 'Admin CSS tokens should use the updated nibbly primary color.');
assertContains(adminTokens, '--nb-brand: #b45309;', 'Admin CSS tokens should use the readable default accent color.');
assertContains(dashboard, "settings.contrast_ok", 'Theme contrast feedback should include compact ok labels.');

console.log(JSON.stringify({
    ok: true,
    checks: [
        'dialogRole',
        'liveRegion',
        'busyState',
        'requestTimeouts',
        'actionIntentRouting',
        'visualEditorGate',
        'internalModals',
        'groupedProposalApply',
        'focusRestore',
        'tabTrap',
        'escapeClose',
        'jsAvailabilityGuards',
        'csrfGuard',
        'apiUrlFallback',
        'htmlFormatIntent',
        'visibilityIntent',
        'editableLinkMapping',
        'visibilityPatch',
        'imageOptionsPanel',
        'imageProviderOptions',
        'imagePatchFallbacks',
        'footerAvailabilityGates'
    ]
}));
