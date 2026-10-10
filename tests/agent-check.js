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
    const card = page.locator('.nb-agent-package').first();
    const text = await card.textContent();
    assert(text.includes('Updated the services page and drafted a news post.'), 'the summary is shown');
    assert(text.includes('Page: en_services__test') && text.includes('News post: 2026-10-10-agent-news'), 'targets are grouped: ' + text.slice(0, 300));
    assert(text.includes('All checks passed'), 'validation status is shown');
    assert(await page.locator('.nb-agent-steps-details summary').textContent().then(value => /\d+ steps/.test(value)), 'finished runs fold the steps');
    await page.locator('.nb-agent-op summary').first().click();
    assert((await page.locator('.nb-agent-diff').first().textContent()).includes('Updated by the agent'), 'before/after is shown');
    assert(!(await page.locator('.nb-agent-package script, .nb-agent-package iframe, .nb-agent-package img[src="x"]').count()), 'no markup is injected');
    assert((await page.locator('.nb-agent-summary').first().textContent()).includes('<img src=x onerror="window.__xss=1">') && await page.evaluate(() => window.__xss === undefined), 'text from the agent is shown as text and never runs');
    await page.screenshot({ path: path.join(shots, 'agent-review-' + user + '.png') });

    // A "yes" in the chat never applies anything
    await send(page, 'yes');
    await page.locator('.nb-agent-summary', { hasText: 'Nothing is applied by a chat reply.' }).waitFor({ state: 'visible', timeout: 60000 });
    assert(newsFiles().length === 0 && !(await (await page.request.get(base + '/services/test/')).text()).includes('Updated by the agent'), 'a "yes" in the chat does not apply the package');

    // Preview opens the page with the staged content, for the owner only
    const [preview] = await Promise.all([
        context.waitForEvent('page'),
        page.locator('[data-agent-action="preview"]').first().click()
    ]);
    await preview.waitForLoadState('load');
    assert(await preview.locator('.nb-agent-preview-banner').count() === 1, 'preview banner');
    assert((await preview.locator('body').textContent()).includes('Updated by the agent'), 'preview shows the staged text');
    assert(!(await preview.locator('#admin-bar').count()), 'no editor in the preview');
    await preview.close();

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
        await context.close();

        // ---- Admin: may publish ------------------------------------------------------------------------------------------------------------------
        ({ context, page } = await mainFlow(browser, 'admin'));
        await page.locator('[data-agent-action="apply"]').click();
        await confirmModal(page);
        await page.locator('.nb-agent-package--applied').waitFor({ state: 'visible', timeout: 20000 });
        assert(newsFiles().length === 1 && JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/news', newsFiles()[0]), 'utf8')).hidden === true, 'new news is hidden after the apply');
        await page.screenshot({ path: path.join(shots, 'agent-applied-admin.png') });
        await page.locator('[data-agent-action="publish"]').first().click();
        await confirmModal(page);
        await page.locator('.nb-agent-package--applied .nb-agent-tag--ok', { hasText: 'Published' }).waitFor({ state: 'visible', timeout: 20000 });
        assert(JSON.parse(fs.readFileSync(path.join(siteRoot, 'content/news', newsFiles()[0]), 'utf8')).hidden !== true, 'publishing makes the news post visible');
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
