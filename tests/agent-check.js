const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.env.REVIEW_URL;
const password = process.env.REVIEW_PASSWORD;
const shots = process.env.REVIEW_SCREENSHOTS;
const imageBase64 = fs.readFileSync(process.env.REVIEW_IMAGE).toString('base64');
const siteRoot = process.env.REVIEW_SITE;
const errors = [];
let currentPage = null;

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

async function login(browser, user) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto(base + '/admin/index.php');
    await page.locator('#username').fill(user);
    await page.locator('#password').fill(password);
    await Promise.all([
        page.waitForURL('**/admin/dashboard.php'),
        page.locator('form').first().locator('button[type=submit]').click()
    ]);
    page.on('pageerror', error => errors.push(user + ': ' + error.message)); // the dashboard is not what this checks
    currentPage = page;
    return { context, page };
}

async function openAssistant(page) {
    await page.goto(base + '/services/test/');
    await page.waitForLoadState('networkidle');
    await page.locator('.nb-copilot-toggle').click();
    await page.locator('#nb-copilot-panel').waitFor({ state: 'visible' });
}

async function send(page, text) {
    const input = page.locator('.nb-copilot-input');
    await input.fill(text);
    await input.press('Enter');
}

async function waitForPackage(page) {
    await page.locator('.nb-agent-package:not(.nb-agent-package--applied):not(.nb-agent-package--quiet)').waitFor({ state: 'visible', timeout: 60000 });
}

async function confirmModal(page) {
    await page.locator('.nb-copilot-modal').waitFor({ state: 'visible' });
    await page.locator('.nb-copilot-modal-confirm').click();
    await page.locator('.nb-copilot-modal').waitFor({ state: 'detached' });
}

function newsFiles() {
    const dir = path.join(siteRoot, 'content/news');
    return fs.existsSync(dir) ? fs.readdirSync(dir).filter(name => name.endsWith('.json')) : [];
}

async function mainFlow(browser, user) {
    const { context, page } = await login(browser, user);
    await openAssistant(page);

    // The agent is the default; the classic shortcuts are out of the way.
    assert(await page.locator('[data-agent-action="mode"][data-arg="agent"]').getAttribute('aria-pressed') === 'true', 'agent mode is on by default');
    assert(!(await page.locator('.nb-copilot-draft').isVisible()), 'classic shortcuts are hidden in agent mode');
    assert((await page.locator('.nb-agent-intro').textContent()).includes('Nothing goes live until you apply it'), 'intro explains the review');

    // Mode switch
    await page.locator('[data-agent-action="mode"][data-arg="classic"]').click();
    assert(await page.locator('.nb-copilot-draft').isVisible() && !(await page.locator('.nb-agent-intro').count()), 'classic mode shows the classic shortcuts and no agent cards');
    await page.locator('[data-agent-action="mode"][data-arg="agent"]').click();
    assert(await page.locator('.nb-agent-intro').count() === 1, 'agent mode is back');

    // Run: the timeline, then the package
    await send(page, 'Update the services page and write a news post about it.');
    await page.locator('.nb-agent-run').waitFor({ state: 'visible', timeout: 30000 });
    await waitForPackage(page);
    assert((await page.locator('.nb-copilot-msg--assistant .nb-copilot-msg__bubble', { hasText: 'Updated the services page and drafted a news post' }).count()) === 1, 'what the agent prepared is also in the chat, so the history keeps it');
    const card = page.locator('.nb-agent-package').first();
    const text = await card.textContent();
    assert(text.includes('Updated the services page and drafted a news post.'), 'the summary is shown');
    assert(text.includes('Page: en_services__test') && text.includes('News post: 2026-10-10-agent-news'), 'targets are grouped: ' + text.slice(0, 300));
    assert(text.includes('All checks passed'), 'validation status is shown');
    assert(await page.locator('.nb-agent-steps-details summary').textContent().then(value => /\d+ steps/.test(value)), 'finished runs fold the steps');
    assert(await page.locator('.nb-agent-op details').first().evaluate(node => node.open), 'a small package shows before and after right away');
    assert(await page.locator('.nb-agent-diff').first().isVisible() && (await page.locator('.nb-agent-diff').first().textContent()).includes('Updated by the agent'), 'before/after is shown');
    assert(!(await page.locator('.nb-agent-package script, .nb-agent-package iframe, .nb-agent-package img[src="x"]').count()), 'no markup is injected');
    assert((await page.locator('.nb-agent-summary').first().textContent()).includes('<img src=x onerror="window.__xss=1">') && await page.evaluate(() => window.__xss === undefined), 'text from the agent is shown as text and never runs');
    await page.screenshot({ path: path.join(shots, 'agent-review-' + user + '.png') });

    // A "yes" in the chat never applies anything
    await send(page, 'yes');
    await page.locator('.nb-agent-summary', { hasText: 'Nothing is applied by a chat reply.' }).waitFor({ state: 'visible', timeout: 60000 });
    assert(newsFiles().length === 0 && !(await (await page.request.get(base + '/services/test/')).text()).includes('Updated by the agent'), 'a "yes" in the chat does not apply the package');

    // Preview opens the page with the staged content in this tab, for the owner only
    const assistantUrl = page.url();
    await Promise.all([page.waitForNavigation(), page.locator('[data-agent-action="preview"]').first().click()]);
    assert(await page.locator('.nb-agent-preview-banner').count() === 1, 'preview banner');
    assert((await page.locator('body').textContent()).includes('Updated by the agent'), 'preview shows the staged text');
    assert(!(await page.locator('#admin-bar').count()), 'no editor in the preview');
    assert((await page.locator('[data-nb-preview="apply"]').textContent()).trim() === 'Apply changes' && (await page.locator('[data-nb-preview="back"]').textContent()).trim() === 'Back to the assistant', 'preview bar buttons');
    // "Back" returns to the page the assistant was on; the open package is still there
    await Promise.all([page.waitForNavigation(), page.locator('[data-nb-preview="back"]').click()]);
    assert(page.url().split('#')[0] === assistantUrl.split('#')[0] && !page.url().includes('nb_agent'), 'back leads to the page the person came from: ' + page.url());
    await page.locator('#nb-copilot-panel').waitFor({ state: 'visible', timeout: 20000 });
    await waitForPackage(page);
    assert(!page.url().includes('#nb-agent'), 'the hash is cleaned up');
    // "Apply changes" returns and asks for the assistant's own confirmation; the preview itself applies nothing
    await Promise.all([page.waitForNavigation(), page.locator('[data-agent-action="preview"]').first().click()]);
    await Promise.all([page.waitForNavigation(), page.locator('[data-nb-preview="apply"]').click()]);
    await page.locator('.nb-copilot-modal').waitFor({ state: 'visible', timeout: 20000 });
    assert((await page.locator('.nb-copilot-modal').textContent()).includes('Apply this package'), 'the assistant asks for the confirmation');
    assert(newsFiles().length === 0 && !(await (await page.request.get(base + '/services/test/')).text()).includes('Updated by the agent'), 'nothing is applied before the confirmation');
    await page.locator('.nb-copilot-modal-cancel').click();
    await page.locator('.nb-copilot-modal').waitFor({ state: 'detached' });
    await waitForPackage(page);

    return { context, page };
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        // ---- Editor: no publish right ----------------------------------------------------------------------------------------
        let { context, page } = await mainFlow(browser, 'editor');

        // Request changes: the agent revises the package
        await page.locator('[data-agent-action="changes"]').click();
        assert((await page.locator('.nb-copilot-input').getAttribute('placeholder')).includes('different'), 'the input asks for the changes');
        await send(page, 'Make the services text shorter.');
        await page.locator('.nb-agent-summary', { hasText: 'shorter' }).waitFor({ state: 'visible', timeout: 60000 });
        assert((await page.locator('.nb-agent-package').first().textContent()).includes('Shorter text'), 'the revised package shows the new text');

        // The package survives a reload
        await page.reload();
        await page.locator('.nb-agent-package').first().waitFor({ state: 'visible', timeout: 20000 });
        assert((await page.locator('.nb-agent-summary').first().textContent()).includes('shorter'), 'the review is back after a reload');

        // Mobile layout
        await page.setViewportSize({ width: 390, height: 844 });
        const overflow = await page.evaluate(() => {
            const list = document.querySelector('.nb-copilot-messages');
            const panel = document.querySelector('.nb-copilot-panel');
            return { list: list.scrollWidth - list.clientWidth, panel: panel.scrollWidth - panel.clientWidth, page: document.documentElement.scrollWidth - innerWidth };
        });
        assert(overflow.list <= 1 && overflow.panel <= 1 && overflow.page <= 1, 'no horizontal overflow on a phone: ' + JSON.stringify(overflow));
        await page.screenshot({ path: path.join(shots, 'agent-mobile.png') });
        await page.setViewportSize({ width: 1280, height: 900 });

        // Apply
        await page.locator('[data-agent-action="apply"]').click();
        await confirmModal(page);
        await page.locator('.nb-agent-package--applied').waitFor({ state: 'visible', timeout: 20000 });
        assert(newsFiles().length === 1, 'the news post exists after the apply');
        const appliedText = await page.locator('.nb-agent-package--applied').textContent();
        assert(appliedText.includes('Changes applied') && appliedText.includes('Publishing needs a role with that right.'), 'editors without the right see the hint: ' + appliedText);
        assert(!(await page.locator('[data-agent-action="publish"]').count()), 'no publish button without the right');
        const live = await page.request.get(base + '/services/test/');
        assert((await live.text()).includes('Shorter text'), 'the page shows the applied text');
        await page.screenshot({ path: path.join(shots, 'agent-applied-editor.png') });

        // Undo
        await page.locator('[data-agent-action="undo"]').click();
        await confirmModal(page);
        await page.locator('.nb-agent-package--quiet').waitFor({ state: 'visible', timeout: 20000 });
        assert((await page.locator('.nb-agent-package--quiet').textContent()).includes('The package was undone.'), 'undo is confirmed');
        assert(!(await (await page.request.get(base + '/services/test/')).text()).includes('Shorter text'), 'the page is back to its old text');
        assert(newsFiles().length === 0, 'the news post is gone again');

        // Cancel a slow run
        await send(page, '[flow:slow] look around');
        await page.locator('.nb-agent-run__head [data-agent-action="cancel"]').waitFor({ state: 'visible', timeout: 20000 });
        await page.locator('.nb-agent-run__head [data-agent-action="cancel"]').click();
        await page.locator('.nb-copilot-msg__bubble', { hasText: 'The agent was cancelled' }).waitFor({ state: 'visible', timeout: 30000 });
        assert(!(await page.locator('.nb-agent-run__head').count()), 'the progress card is gone after cancelling');

        // Attach images with the button and by drop; the chips show thumbnails
        await page.setInputFiles('[data-agent-file]', process.env.REVIEW_IMAGE);
        await page.locator('.nb-agent-chip img').first().waitFor({ state: 'visible', timeout: 20000 });
        await page.waitForFunction(() => document.querySelector('.nb-agent-chip img').naturalWidth > 0, null, { timeout: 20000 });
        const dataTransfer = await page.evaluateHandle(encoded => {
            const transfer = new DataTransfer();
            const bytes = Uint8Array.from(atob(encoded), ch => ch.charCodeAt(0));
            transfer.items.add(new File([bytes], 'dropped.png', { type: 'image/png' }));
            return transfer;
        }, imageBase64);
        await page.dispatchEvent('.nb-copilot-panel', 'dragenter', { dataTransfer });
        assert(await page.locator('.nb-copilot-panel.is-dragging').count() === 1, 'the drop overlay shows while dragging files');
        await page.dispatchEvent('.nb-copilot-panel', 'drop', { dataTransfer });
        await page.waitForFunction(() => document.querySelectorAll('.nb-agent-chip img').length === 2, null, { timeout: 20000 });
        assert(await page.locator('.nb-copilot-panel.is-dragging').count() === 0, 'the overlay is gone after the drop');
        await page.screenshot({ path: path.join(shots, 'agent-chips.png') });
        await send(page, '[flow:upload] put my images on the page');
        await page.locator('.nb-copilot-msg__bubble', { hasText: 'I can see your image' }).waitFor({ state: 'visible', timeout: 60000 });
        assert(!(await page.locator('.nb-agent-chip').count()), 'chips are cleared once the request is sent');
        // The answer is also kept when the page loses it: it comes back after a reload (a new login, a closed tab)
        await page.evaluate(() => {
            Object.keys(sessionStorage).filter(key => key.startsWith('nibbly-ai-assistant:')).forEach(key => {
                const stored = JSON.parse(sessionStorage.getItem(key));
                stored.messages = stored.messages.filter(message => !message.content.includes('I can see your image'));
                sessionStorage.setItem(key, JSON.stringify(stored));
            });
        });
        await page.reload();
        await page.locator('.nb-copilot-msg__bubble', { hasText: 'I can see your image' }).waitFor({ state: 'visible', timeout: 30000 });
        assert((await page.locator('.nb-copilot-msg__bubble', { hasText: 'I can see your image' }).count()) === 1, 'the answer is back once, not twice');
        await page.reload();
        await page.locator('.nb-copilot-msg__bubble', { hasText: 'I can see your image' }).first().waitFor({ state: 'visible', timeout: 30000 });
        assert((await page.locator('.nb-copilot-msg__bubble', { hasText: 'I can see your image' }).count()) === 1, 'and a second reload does not add it again');
        await context.close();

        // ---- Admin: may publish ------------------------------------------------------------------------------------------------------------------
        ({ context, page } = await mainFlow(browser, 'admin'));
        // Applying from the preview: the bar leads back, the assistant asks, one confirmation applies it
        await Promise.all([page.waitForNavigation(), page.locator('[data-agent-action="preview"]').first().click()]);
        await Promise.all([page.waitForNavigation(), page.locator('[data-nb-preview="apply"]').click()]);
        await page.locator('.nb-copilot-modal').waitFor({ state: 'visible', timeout: 30000 });
        await confirmModal(page);
        await page.locator('.nb-agent-package--applied').waitFor({ state: 'visible', timeout: 20000 });
        assert(newsFiles().length === 1 && JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/news', newsFiles()[0]), 'utf8')).hidden === true, 'new news is hidden after the apply');
        await page.screenshot({ path: path.join(shots, 'agent-applied-admin.png') });
        await page.locator('[data-agent-action="publish"]').first().click();
        await confirmModal(page);
        await page.locator('.nb-agent-package--applied .nb-agent-tag--ok', { hasText: 'Published' }).waitFor({ state: 'visible', timeout: 20000 });
        assert(JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/news', newsFiles()[0]), 'utf8')).hidden !== true, 'publishing makes the news post visible');
        await context.close();

        // ---- Limit warnings: the dashboard, the settings and the assistant tell before a limit is reached ----------------------------------
        const settingsFile = path.join(siteRoot, 'content/ai-settings.json');
        const originalSettings = fs.readFileSync(settingsFile, 'utf8');
        const tight = JSON.parse(originalSettings);
        tight.limits.monthlyBudgetCents = 1000;
        fs.writeFileSync(settingsFile, JSON.stringify(tight));
        const today = new Date().toISOString().slice(0, 10);
        fs.writeFileSync(path.join(siteRoot, 'content/ai-usage.json'), JSON.stringify({
            days: { [today]: { requests: 5, textRequests: 5, imageRequests: 0, inputTokens: 0, outputTokens: 0, estimatedCostCents: 0 } },
            months: { [today.slice(0, 7)]: { requests: 5, textRequests: 5, imageRequests: 0, inputTokens: 0, outputTokens: 0, estimatedCostCents: 900 } }
        }));
        ({ context, page } = await login(browser, 'editor'));
        await openAssistant(page);
        await page.locator('.nb-copilot-limit').waitFor({ state: 'visible', timeout: 15000 });
        const barText = await page.locator('.nb-copilot-limit').textContent();
        assert(barText.includes('Monthly AI budget') && barText.includes('9.00 EUR of 10.00 EUR') && barText.includes('administrator'), 'the assistant shows the nearly used budget: ' + barText);
        await context.close();
        ({ context, page } = await login(browser, 'admin'));
        await page.waitForSelector('#aiLimitBanner:not([hidden])', { timeout: 15000 });
        assert((await page.locator('#aiLimitBanner').textContent()).includes('Monthly AI budget'), 'the dashboard shows the warning');
        await page.locator('#aiLimitBanner button', { hasText: 'Adjust limits' }).click();
        await page.locator('#aiLimitBannerSettings:not([hidden])').waitFor({ state: 'visible', timeout: 10000 });
        const budgetBefore = Number(await page.locator('#aiMonthlyBudget').inputValue());
        await page.locator('#aiLimitBannerSettings button', { hasText: 'Raise by 50' }).click();
        assert(Number(await page.locator('#aiMonthlyBudget').inputValue()) === Math.ceil(budgetBefore * 1.5), 'the button raises the limit by half');
        await page.screenshot({ path: path.join(shots, 'agent-limit-banner.png') });
        await context.close();
        fs.writeFileSync(path.join(siteRoot, 'content/ai-usage.json'), JSON.stringify({ days: {}, months: {} }));
        fs.writeFileSync(settingsFile, originalSettings);

        // ---- Dashboard assistant: cards that open an area or change the dashboard look -------------------------------------------------------
        ({ context, page } = await login(browser, 'admin'));
        await page.locator('.nb-copilot-toggle').click();
        await page.locator('#nb-copilot-panel').waitFor({ state: 'visible' });
        await send(page, 'Please switch the dashboard to dark mode');
        await page.locator('.nb-copilot-dash', { hasText: 'Change dashboard theme' }).waitFor({ state: 'visible', timeout: 30000 });
        assert((await page.locator('.nb-copilot-dash').first().textContent()).includes('Light → Dark'), 'the card says what changes');
        const themeNow = () => JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/settings.json'), 'utf8')).theme || {};
        assert(themeNow().adminTheme !== 'dark', 'nothing changes before the click');
        await page.screenshot({ path: path.join(shots, 'dashboard-action-card.png') });
        await page.locator('.nb-copilot-dash-run').first().click();
        await page.locator('.nb-copilot-dash__ok').first().waitFor({ state: 'visible', timeout: 30000 });
        await page.waitForFunction(() => document.documentElement.getAttribute('data-site-theme') === 'dark', null, { timeout: 10000 });
        assert(themeNow().adminTheme === 'dark', 'the theme is saved');
        await send(page, 'Make the accent colour blue');
        await page.locator('.nb-copilot-dash', { hasText: 'accent colour' }).waitFor({ state: 'visible', timeout: 30000 });
        await page.locator('.nb-copilot-dash-run').last().click();
        await page.locator('.nb-copilot-dash__ok').nth(1).waitFor({ state: 'visible', timeout: 30000 });
        assert(String(themeNow().accentColor).toLowerCase() === '#0b5cad', 'the colour is saved: ' + JSON.stringify(themeNow()));
        await send(page, 'Where are the language settings?');
        await page.locator('.nb-copilot-dash', { hasText: 'Open Settings › Language' }).waitFor({ state: 'visible', timeout: 30000 });
        await page.locator('.nb-copilot-dash-run').last().click();
        await page.locator('#settingsPanel-language.active').waitFor({ state: 'visible', timeout: 10000 });
        await send(page, 'Switch the dashboard to German');
        await page.locator('.nb-copilot-dash', { hasText: 'dashboard language' }).waitFor({ state: 'visible', timeout: 30000 });
        await page.locator('.nb-copilot-dash-dismiss').last().click();
        assert(!(await page.locator('.nb-copilot-dash', { hasText: 'dashboard language' }).count()), 'a dismissed card is gone and changes nothing');
        assert(!(JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/settings.json'), 'utf8')).general || {}).adminLanguage, 'dismissing does not change the language');
        await send(page, 'Switch the dashboard to German');
        await page.locator('.nb-copilot-dash', { hasText: 'dashboard language' }).waitFor({ state: 'visible', timeout: 30000 });
        await Promise.all([
            page.waitForNavigation({ timeout: 20000 }),
            page.locator('.nb-copilot-dash-run').last().click()
        ]);
        assert((JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/settings.json'), 'utf8')).general || {}).adminLanguage === 'de', 'the language is saved and the dashboard reloads');
        await context.close();
        const resetSettings = JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/settings.json'), 'utf8'));
        delete resetSettings.general; delete resetSettings.theme;
        fs.writeFileSync(path.join(siteRoot, 'content/settings.json'), JSON.stringify(resetSettings));

        // ---- Places in the dashboard: a card with a link that opens in a new tab, and refusals --------------------------------------------------
        ({ context, page } = await login(browser, 'admin'));
        await page.locator('.nb-copilot-toggle').click();
        await page.locator('#nb-copilot-panel').waitFor({ state: 'visible' });
        await send(page, 'Please set the system language to English');
        const placeCard = page.locator('.nb-copilot-dash--link', { hasText: 'Admin Language' });
        await placeCard.waitFor({ state: 'visible', timeout: 30000 });
        assert((await placeCard.textContent()).includes('Settings › Language › Admin Language'), 'the card names the place: ' + await placeCard.textContent());
        const placeLink = placeCard.locator('a.nb-copilot-dash-link');
        assert(await placeLink.getAttribute('target') === '_blank' && (await placeLink.getAttribute('rel')).includes('noopener'), 'the link opens in a new tab without the opener');
        assert(await placeLink.getAttribute('href') === '/admin/dashboard.php#settings/language', 'the link leads to the language settings');
        await page.screenshot({ path: path.join(shots, 'dashboard-place-card.png') });
        const [popup] = await Promise.all([context.waitForEvent('page'), placeLink.click()]);
        await popup.waitForLoadState('domcontentloaded');
        await popup.locator('#settingsPanel-language.active').waitFor({ state: 'visible', timeout: 15000 });
        assert(popup.url().endsWith('#settings/language'), 'the new tab opened the language settings: ' + popup.url());
        await popup.close();
        await send(page, 'Delete everything on the home page');
        await page.locator('.nb-copilot-msg--assistant .nb-copilot-msg__bubble', { hasText: 'few at a time' }).waitFor({ state: 'visible', timeout: 30000 });
        await context.close();

        // ---- Safety in the agent: a refused request, and a package with a note needs a second confirmation -------------------------------------
        ({ context, page } = await login(browser, 'editor'));
        await openAssistant(page);
        await send(page, 'Please delete everything on the home page');
        await page.locator('.nb-agent-note--warn', { hasText: 'few at a time' }).waitFor({ state: 'visible', timeout: 30000 });
        assert(!(await page.locator('.nb-agent-run').count()), 'a refused request never starts a run');
        await send(page, '[flow:risk] Link the first section to https://bit.ly/x1');
        await waitForPackage(page);
        const riskNote = page.locator('.nb-agent-risk');
        await riskNote.waitFor({ state: 'visible', timeout: 30000 });
        assert((await riskNote.textContent()).includes('Safety check'), 'the safety note is shown apart from the other notes: ' + await riskNote.textContent());
        await page.screenshot({ path: path.join(shots, 'agent-safety-note.png') });
        await page.locator('[data-agent-action="apply"]').click();
        await page.locator('.nb-copilot-modal').waitFor({ state: 'visible' });
        const riskModal = await page.locator('.nb-copilot-modal').textContent();
        assert(riskModal.includes('Apply despite the safety notes?') && riskModal.includes('Apply anyway'), 'the second confirmation names the safety notes: ' + riskModal);
        await page.locator('.nb-copilot-modal-cancel').click();
        await page.locator('.nb-copilot-modal').waitFor({ state: 'detached' });
        assert(!(await (await page.request.get(base + '/services/test/')).text()).includes('bit.ly/x1'), 'cancelling applies nothing');
        await page.locator('[data-agent-action="apply"]').click();
        await confirmModal(page);
        await page.locator('.nb-agent-package--applied').waitFor({ state: 'visible', timeout: 20000 });
        assert((await (await page.request.get(base + '/services/test/')).text()).includes('bit.ly/x1'), 'the package is applied after the second confirmation');
        await page.locator('[data-agent-action="undo"]').click();
        await confirmModal(page);
        await page.locator('.nb-agent-package--quiet').waitFor({ state: 'visible', timeout: 20000 });
        assert(!(await (await page.request.get(base + '/services/test/')).text()).includes('bit.ly/x1'), 'and it can be undone');
        await context.close();

        // ---- Dashboard: the agent section of the AI settings ------------------------------------------------------------------------------
        ({ context, page } = await login(browser, 'admin'));
        await page.locator('.sidebar-nav-item[data-tab="settings"]').click();
        await page.locator('.settings-tab-btn[data-settings-tab="ai"]').click();
        await page.locator('#aiAgentSection').waitFor({ state: 'visible' });
        await page.waitForFunction(() => document.getElementById('aiAgentEnabled').checked === true, null, { timeout: 15000 });
        // The groups are folded; the save bar stays in reach however long the page is.
        assert(!(await page.locator('#aiBraveApiKey').isVisible()), 'the research group starts folded');
        for (const summary of await page.locator('.ai-agent-details > summary').all()) await summary.click();
        assert(await page.locator('#aiBraveApiKey').isVisible(), 'a folded group opens');
        await page.locator('#aiAgentSection').scrollIntoViewIfNeeded();
        const saveBox = await page.locator('#saveAiSettingsBtn').boundingBox();
        assert(saveBox && saveBox.y + saveBox.height <= 900 + 1, 'the save button is on screen without scrolling to the end of the form: ' + JSON.stringify(saveBox));
        assert((await page.locator('#aiAgentStatus').textContent()).includes('ready'), 'the status says the agent is ready: ' + await page.locator('#aiAgentStatus').textContent());
        assert(await page.locator('#aiBraveApiKeyHint').textContent().then(text => /No API key stored/.test(text)), 'no research key yet');
        await page.fill('#aiBraveApiKey', 'BSA-browser-brave-key-1234');
        await page.fill('#aiAgentRunsPerHour', '5');
        await page.fill('#aiAgentAllowDomains', 'Example.org\nhttps://www.example.net/page');
        for (const [id, wanted] of [['aiAgentRight_createEvents', false], ['aiAgentRight_publish', true]]) {
            if (await page.locator('#' + id).isChecked() !== wanted) await page.locator('label.toggle-label', { has: page.locator('#' + id) }).click();
            assert(await page.locator('#' + id).isChecked() === wanted, id + ' toggles');
        }
        await page.screenshot({ path: path.join(shots, 'agent-settings.png'), fullPage: false });
        await page.locator('#saveAiSettingsBtn').click();
        await page.locator('#aiBraveApiKeyHint', { hasText: 'API key saved' }).waitFor({ state: 'visible', timeout: 20000 });
        assert((await page.locator('#aiBraveApiKey').getAttribute('placeholder')) === '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022', 'a saved key shows a masked placeholder');
        assert(await page.locator('#aiRemoveBraveApiKey').isVisible(), 'the remove button appears next to a saved key');
        assert((await page.locator('#aiBraveApiKey').inputValue()) === '', 'the key field is empty again after saving');
        assert(!(await page.content()).includes('BSA-browser-brave-key'), 'the key is never rendered back');
        assert((await page.locator('#aiAgentAllowDomains').inputValue()) === 'example.org\nexample.net', 'domains are shown normalised');
        const savedSettings = JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/ai-settings.json'), 'utf8'));
        const savedSecrets = JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/ai-secrets.json'), 'utf8'));
        assert(savedSecrets.research.brave.apiKey === 'BSA-browser-brave-key-1234' && !JSON.stringify(savedSettings).includes('BSA-browser-brave-key'), 'the key is in the secrets file, not in the settings file');
        assert( savedSettings.agent.limits.runsPerHour === 5 && savedSettings.agent.roles.editor.createEvents === false && savedSettings.agent.roles.editor.publish === true, 'the form saved what was entered');
        // saving again without typing the key keeps it
        await page.locator('#saveAiSettingsBtn').click();
        await page.waitForTimeout(1500);
        assert(JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/ai-secrets.json'), 'utf8')).research.brave.apiKey === 'BSA-browser-brave-key-1234', 'an empty key field keeps the saved key');
        // The x asks first; cancelling keeps the key, confirming deletes it at once.
        await page.locator('#aiRemoveBraveApiKey').click();
        await page.locator('#modalConfirm').waitFor({ state: 'visible' });
        await page.locator('#modalOverlay .modal-actions .btn-secondary').click();
        assert(JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/ai-secrets.json'), 'utf8')).research.brave.apiKey === 'BSA-browser-brave-key-1234', 'cancelling keeps the key');
        await page.locator('#aiRemoveBraveApiKey').click();
        await page.locator('#modalConfirm').click();
        await page.locator('#aiBraveApiKeyHint', { hasText: 'No API key stored' }).waitFor({ state: 'visible', timeout: 10000 });
        assert(!(JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/ai-secrets.json'), 'utf8')).research || {}).brave, 'confirming removes the key from the secrets file');
        assert(!(await page.locator('#aiRemoveBraveApiKey').isVisible()), 'the remove button is gone without a saved key');
        await page.locator('#aiAgentClearCache').click();
        await page.locator('.toast, [role="status"]', { hasText: 'Cache cleared' }).first().waitFor({ state: 'visible', timeout: 10000 });
        // The system status lists what the agent needs, never a key
        await page.locator('.sidebar-nav-item[data-tab="system"]').click().catch(() => null);
        const systemText = await page.evaluate(async () => (await (await fetch('api.php?action=system-status&scheme=http')).text()));
        assert(systemText.includes('"agent"') && !systemText.includes('BSA-browser'), 'system status without keys');
        await context.close();

        if (errors.length) throw new Error(errors.join('\n'));
        console.log('agent-check ok');
    } catch (error) {
        if (currentPage) await currentPage.screenshot({ path: path.join(shots, 'failure.png') }).catch(() => null);
        throw error;
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
