// AI settings in the dashboard: legacy installs, effort per purpose, agent offer, own prices, key removal.
const { chromium } = require('playwright');
const fs = require('fs'); const path = require('path');
const base = process.env.REVIEW_URL, password = process.env.REVIEW_PASSWORD, site = process.env.REVIEW_SITE;
const assert = (c, m) => { if (!c) throw new Error('ASSERT: ' + m); };
(async () => {
  // teach the registry the mock host
  const capsPath = path.join(site, 'includes/ai/model-capabilities.json');
  const caps = JSON.parse(fs.readFileSync(capsPath, 'utf8'));
  const host = new URL(base).hostname;
  const mk = (model, tier) => ({ provider: 'openai-compatible', host: '127.0.0.1', model, label: model, tier, effort: { param: 'openai', levels: ['low', 'medium', 'high'], recommended: { chat: 'medium', text: 'low' }, providerDefault: 'medium' }, omitTemperature: true });
  caps.models.push(mk('gpt-4.1', 'power'), mk('gpt-4.1-mini', 'light'));
  fs.writeFileSync(capsPath, JSON.stringify(caps));
  const settingsPath = path.join(site, 'content/ai-settings.json');
  { const f = JSON.parse(fs.readFileSync(settingsPath, 'utf8')); delete f.reasoning; delete f.providerModels; f.agent = Object.assign(f.agent || {}, { enabled: true }); fs.writeFileSync(settingsPath, JSON.stringify(f)); } // an install from before the setting
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await context.newPage();
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  await page.goto(base + '/admin/index.php');
  await page.locator('#username').fill('admin'); await page.locator('#password').fill(password);
  await Promise.all([page.waitForURL('**/admin/dashboard.php'), page.locator('form').first().locator('button[type=submit]').click()]);
  await page.locator('.sidebar-nav-item[data-tab="settings"]').click();
  await page.locator('.settings-tab-btn[data-settings-tab="ai"]').click();
  await page.locator('#aiAgentSection').waitFor({ state: 'visible' });
  await page.waitForTimeout(600);
  // legacy install with a known model: extra choice, nothing sent
  assert(await page.locator('#aiChatEffort').inputValue() === 'legacy', 'legacy install shows "Previous behavior"');
  assert((await page.locator('#aiChatEffort option').allTextContents()).some(t => /Previous behavior/.test(t)), 'legacy option is listed');
  // turn the agent off, save: no offer (model unchanged)
  await page.locator('label.toggle-label', { has: page.locator('#aiAgentEnabled') }).click();
  await page.locator('#saveAiSettingsBtn').click();
  await page.waitForTimeout(1500);
  assert(JSON.parse(fs.readFileSync(settingsPath, 'utf8')).agent.enabled === false, 'agent is off');
  assert(JSON.parse(fs.readFileSync(settingsPath, 'utf8')).reasoning.chat === 'legacy', 'saving untouched keeps legacy');
  assert(!(await page.locator('#modalOverlay').isVisible()), 'no question when the model did not change');
  // change to a powerful model, choose a level, save: question appears
  await page.fill('#aiChatModel', 'gpt-4.1'); await page.dispatchEvent('#aiChatModel', 'change');
  assert((await page.locator('#aiChatEffort option').allTextContents()).join('|') === 'Recommended (Medium)|Low|Medium|High', 'levels offered, no legacy for another model');
  await page.selectOption('#aiChatEffort', 'high');
  await page.selectOption('#aiTextEffort', 'low').catch(() => {});
  await page.locator('#saveAiSettingsBtn').click();
  await page.locator('#modalOverlay').waitFor({ state: 'visible', timeout: 10000 });
  assert(/Enable the AI agent/.test(await page.locator('#modalTitle').textContent()), 'agent question is shown: ' + await page.locator('#modalText').textContent());
  let saved = JSON.parse(fs.readFileSync(settingsPath, 'utf8'));
  assert(saved.reasoning.chat === 'high' && saved.chatModel === 'gpt-4.1' && saved.providerModels['openai-compatible'].reasoning.chat === 'high', 'chat level and model saved per provider');
  await page.locator('#modalConfirm').click();
  await page.waitForTimeout(1500);
  saved = JSON.parse(fs.readFileSync(settingsPath, 'utf8'));
  assert(saved.agent.enabled === true, 'confirming enables the agent');
  assert(saved.reasoning.chat === 'high', 'level survives the second save');
  // asked only once per model
  await page.locator('label.toggle-label', { has: page.locator('#aiAgentEnabled') }).click();
  await page.locator('#saveAiSettingsBtn').click();
  await page.waitForTimeout(1500);
  assert(!(await page.locator('#modalOverlay').isVisible()), 'not asked again for the same model');
  // reload: the level is shown again
  await page.reload();
  // The page reopens in the settings; a click on their button would close them again
  await page.evaluate(() => switchTab('settings'));
  await page.locator('.settings-tab-btn[data-settings-tab="ai"]').click();
  await page.waitForTimeout(800);
  assert(await page.locator('#aiChatEffort').inputValue() === 'high' && await page.locator('#aiChatModel').inputValue() === 'gpt-4.1', 'settings survive a reload');
  // price: enter, save, reload
  await page.fill('#aiPriceIn_chat', '3'); await page.fill('#aiPriceOut_chat', '9');
  await page.locator('#saveAiSettingsBtn').click(); await page.waitForTimeout(1500);
  saved = JSON.parse(fs.readFileSync(settingsPath, 'utf8'));
  assert(saved.pricing.models['openai-compatible|gpt-4.1'].input === 3 && saved.pricing.models['openai-compatible|gpt-4.1'].currency === 'USD', 'own price saved');
  assert((await page.locator('#aiPriceRow_chat [data-price-status]').textContent()).startsWith('Own price: 3 / 9 USD'), 'status shows own price');
  await page.locator('#aiPriceRemove_chat').click(); await page.locator('#saveAiSettingsBtn').click(); await page.waitForTimeout(1500);
  assert(!JSON.parse(fs.readFileSync(settingsPath, 'utf8')).pricing.models['openai-compatible|gpt-4.1'], 'own price removed');
  // Image service: its own section, key and price, apart from the chat provider
  await page.locator('#aiImageServiceSection').scrollIntoViewIfNeeded();
  assert(await page.locator('#aiImageService').inputValue() === 'inherit' && !(await page.locator('#aiImageServiceKeyGroup').isVisible()), 'images follow the chat provider by default, no key field');
  await page.selectOption('#aiImageService', 'openai-compatible');
  // The chat provider of this check is a local mock, not api.openai.com: its key must not be offered for OpenAI images.
  assert((await page.locator('#aiImageApiKeyHint').textContent()).startsWith('No API key stored for this service'), 'a chat key for another host is not used for OpenAI images: ' + await page.locator('#aiImageApiKeyHint').textContent());
  await page.selectOption('#aiImageService', 'fal');
  assert(await page.locator('#aiImageServiceModelGroup').isVisible() && await page.locator('#aiImageServiceKeyGroup').isVisible(), 'an own service shows model and key');
  assert(await page.locator('#aiImageServiceModel').getAttribute('placeholder') === 'google/nano-banana-2.1', 'the default model is the placeholder');
  assert((await page.locator('#aiImageApiKeyHint').textContent()).startsWith('No API key stored for this service'), 'no key for fal yet: ' + await page.locator('#aiImageApiKeyHint').textContent());
  await page.waitForFunction(() => /could not be loaded/.test(document.getElementById('aiImageServiceModelHint').textContent), null, { timeout: 15000 });
  await page.locator('#aiImageServiceModel').fill('fal-ai/brand-new-model');
  assert(await page.locator('#aiImageServiceNotice').isVisible() && /not been checked/.test(await page.locator('#aiImageServiceNotice').textContent()), 'an unverified model is marked');
  assert(await page.locator('#aiPriceRow_image').isVisible() && /default price of 5 cents/.test(await page.locator('#aiPriceRow_image [data-price-status]').textContent()), 'the image price row shows the default price');
  await page.fill('#aiImageApiKey', 'fal-test-key-0123456789');
  await page.fill('#aiPriceImage', '0.04');
  await page.locator('#aiPriceImageDetails summary').click();
  await page.fill('#aiPriceRes_4K', '0.12'); await page.fill('#aiPriceQual_high', '0.2');
  assert(/also set/.test(await page.locator('#aiPriceRow_image [data-price-status]').textContent()), 'tables are announced in the status: ' + await page.locator('#aiPriceRow_image [data-price-status]').textContent());
  await page.locator('#saveAiSettingsBtn').click();
  await page.waitForTimeout(1500);
  if (await page.locator('#modalOverlay').isVisible()) { await page.locator('#modalConfirm').click(); await page.waitForTimeout(800); }
  saved = JSON.parse(fs.readFileSync(settingsPath, 'utf8'));
  assert(saved.imageService.provider === 'fal' && saved.imageService.model === 'fal-ai/brand-new-model' && !JSON.stringify(saved).includes('fal-test-key'), 'image service saved, key not in the settings file');
  assert(saved.pricing.imageModels['fal|fal-ai/brand-new-model'].perImage === 0.04 && saved.pricing.imageModels['fal|fal-ai/brand-new-model'].byResolution['4K'] === 0.12 && saved.pricing.imageModels['fal|fal-ai/brand-new-model'].byQuality.high === 0.2, 'image price saved per model, with the tables');
  assert(await page.locator('#aiPriceRes_4K').inputValue() === '0.12' && await page.locator('#aiPriceQual_high').inputValue() === '0.2' && await page.locator('#aiPriceImage').inputValue() === '0.04', 'the tables come back after saving');
  const imageSecrets = JSON.parse(fs.readFileSync(path.join(site, 'content/ai-secrets.json'), 'utf8'));
  assert(imageSecrets.imageServices.fal.apiKey === 'fal-test-key-0123456789', 'image key in the secrets file');
  assert(await page.locator('#aiImageApiKey').getAttribute('placeholder') === '•'.repeat(8) && await page.locator('#aiRemoveImageApiKey').isVisible() && (await page.locator('#aiImageApiKeyHint').textContent()).startsWith('Own key saved'), 'image key is masked, x visible');
  assert(!(await page.content()).includes('fal-test-key'), 'the key is never in the page');
  await page.locator('#aiRemoveImageApiKey').click();
  await page.locator('#modalConfirm').click();
  await page.waitForTimeout(1200);
  assert(!JSON.parse(fs.readFileSync(path.join(site, 'content/ai-secrets.json'), 'utf8')).imageServices, 'image key removed from the secrets file');
  assert(!(await page.locator('#aiRemoveImageApiKey').isVisible()) && (await page.locator('#aiImageApiKeyHint').textContent()).startsWith('No API key stored for this service'), 'form shows no image key: ' + await page.locator('#aiImageApiKeyHint').textContent() + ' / remove visible: ' + await page.locator('#aiRemoveImageApiKey').isVisible());
  await page.selectOption('#aiImageService', 'custom');
  assert(await page.locator('#aiImageCustomGroup').isVisible() && await page.locator('#aiImageExtraGroup').isVisible(), 'custom service shows base URL, size mode and parameters');
  await page.selectOption('#aiImageService', 'inherit');
  assert(!(await page.locator('#aiImageServiceModelGroup').isVisible()), 'back to the chat provider: nothing else to fill in');
  // Saved again, so the checks that follow find the images with the chat provider (an own service without a key would switch the image tool off)
  await page.locator('#saveAiSettingsBtn').click();
  await page.waitForTimeout(1500);
  if (await page.locator('#modalOverlay').isVisible()) { await page.locator('#modalConfirm').click(); await page.waitForTimeout(800); }
  assert(JSON.parse(fs.readFileSync(settingsPath, 'utf8')).imageService.provider === 'inherit', 'the image service is back at the chat provider');
  // provider key: x with confirmation
  assert(await page.locator('#aiApiKey').getAttribute('placeholder') === '•'.repeat(8) && await page.locator('#aiRemoveApiKey').isVisible(), 'provider key is masked, x visible');
  await page.locator('#aiRemoveApiKey').click(); await page.locator('#modalConfirm').click(); await page.waitForTimeout(1200);
  const secrets = JSON.parse(fs.readFileSync(path.join(site, 'content/ai-secrets.json'), 'utf8'));
  assert(!(secrets.providerCredentials || {})['openai-compatible'], 'provider key removed from the secrets file');
  assert(!(await page.locator('#aiRemoveApiKey').isVisible()) && (await page.locator('#aiApiKeyHint').textContent()).startsWith('No API key'), 'form shows no key');
  // The assistant button goes with the key and comes back without a reload
  assert(await page.locator('.nb-copilot').evaluate(el => getComputedStyle(el).display) === 'none', 'the assistant button is gone when the key is removed');
  await page.reload();
  // The page reopens in the settings; a click on their button would close them again
  await page.evaluate(() => switchTab('settings'));
  await page.locator('.settings-tab-btn[data-settings-tab="ai"]').click();
  await page.waitForTimeout(600);
  assert((await page.locator('.nb-copilot').count()) === 0, 'a page loaded without a key has no assistant button');
  await page.fill('#aiApiKey', 'sk-test-key-0123456789abcdef');
  await page.locator('#saveAiSettingsBtn').click();
  await page.locator('#modalOverlay').waitFor({ state: 'visible', timeout: 10000 });
  assert(/Enable the AI agent/.test(await page.locator('#modalTitle').textContent()), 'a new key gets the agent question again, even for a model that was asked about before');
  await page.locator('.nb-copilot').waitFor({ state: 'visible', timeout: 10000 }); // its script is loaded after the save
  assert((await page.locator('.nb-copilot').count()) === 1 && await page.locator('.nb-copilot').isVisible(), 'the assistant button appears without a reload');
  await page.locator('#modalConfirm').click();
  await page.waitForTimeout(1500);
  // Classic mode can be switched off: the chat then shows the agent only
  await page.locator('#aiAgentSection').scrollIntoViewIfNeeded();
  assert(await page.locator('#aiAgentClassicMode').isChecked(), 'the classic mode is offered by default');
  await page.locator('label.toggle-label', { has: page.locator('#aiAgentClassicMode') }).click();
  await page.locator('#saveAiSettingsBtn').click();
  await page.waitForTimeout(1500);
  assert(JSON.parse(fs.readFileSync(settingsPath, 'utf8')).agent.classicMode === false, 'the choice is saved');
  await page.goto(base + '/');
  await page.waitForFunction(() => window.NibblyAiCopilot, null, { timeout: 15000 });
  await page.evaluate(() => window.NibblyAiCopilot.open());
  await page.waitForTimeout(800);
  assert(await page.evaluate(() => { const m = document.querySelector('.nb-agent-mode'); return !m || getComputedStyle(m).display === 'none'; }), 'the chat has no mode switch without the classic mode');
  if (errors.length) throw new Error(errors.join('\n'));
  console.log('ai-settings-check ok');
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
