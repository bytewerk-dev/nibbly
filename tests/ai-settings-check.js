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
  await page.locator('.sidebar-nav-item[data-tab="settings"]').click();
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
  // provider key: x with confirmation
  assert(await page.locator('#aiApiKey').getAttribute('placeholder') === '•'.repeat(8) && await page.locator('#aiRemoveApiKey').isVisible(), 'provider key is masked, x visible');
  await page.locator('#aiRemoveApiKey').click(); await page.locator('#modalConfirm').click(); await page.waitForTimeout(1200);
  const secrets = JSON.parse(fs.readFileSync(path.join(site, 'content/ai-secrets.json'), 'utf8'));
  assert(!(secrets.providerCredentials || {})['openai-compatible'], 'provider key removed from the secrets file');
  assert(!(await page.locator('#aiRemoveApiKey').isVisible()) && (await page.locator('#aiApiKeyHint').textContent()).startsWith('No API key'), 'form shows no key');
  if (errors.length) throw new Error(errors.join('\n'));
  console.log('ai-settings-check ok');
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
