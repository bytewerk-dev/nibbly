// Forms in the dashboard: the notice on the Messages page when nothing is stored locally, and the layout of the field cards.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.env.REVIEW_URL;
const password = process.env.REVIEW_PASSWORD;
const site = process.env.REVIEW_SITE;
const formFile = path.join(site, 'content/forms/contact.json');
const errors = [];

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

function setForm(changes) {
    const form = JSON.parse(fs.readFileSync(formFile, 'utf8'));
    form.enabled = changes.enabled !== undefined ? changes.enabled : form.enabled;
    form.submit = Object.assign({}, form.submit, changes.submit || {});
    fs.writeFileSync(formFile, JSON.stringify(form));
}

async function login(browser, viewport) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/admin/index.php');
    await page.locator('#username').fill('admin');
    await page.locator('#password').fill(password);
    await Promise.all([page.waitForURL('**/admin/dashboard.php'), page.locator('form').first().locator('button[type=submit]').click()]);
    return { context, page };
}

(async () => {
    const browser = await chromium.launch();
    const original = fs.readFileSync(formFile, 'utf8');
    try {
        // ---- Messages: notice when no enabled form stores locally ----
        setForm({ enabled: true, submit: { store: true } });
        let { context, page } = await login(browser, { width: 1280, height: 900 });
        await page.evaluate(() => switchTab('mails'));
        await page.locator('#mailsTab').waitFor({ state: 'visible' });
        await page.waitForTimeout(700);
        assert(await page.locator('#mailStorageBanner').isHidden(), 'no storage notice while a form stores locally');

        setForm({ submit: { store: false } });
        await page.evaluate(() => loadMails());
        await page.locator('#mailStorageBanner').waitFor({ state: 'visible', timeout: 5000 });
        assert(/not stored here/.test(await page.locator('#mailStorageBannerTitle').textContent()), 'notice: nothing is stored');
        await page.locator('#mailStorageBanner .info-banner__cta').click();
        await page.locator('#settingsPanel-forms').waitFor({ state: 'visible' });
        assert(await page.locator('#mailsTab').isHidden(), 'the button leads to the form settings');

        setForm({ enabled: false, submit: { store: true } });
        await page.evaluate(() => switchTab('mails'));
        await page.evaluate(() => loadMails());
        await page.waitForTimeout(500);
        assert(/No form is enabled/.test(await page.locator('#mailStorageBannerTitle').textContent()), 'notice: no form enabled');

        setForm({ enabled: true, submit: { store: true } });
        await page.evaluate(() => loadMails());
        await page.waitForTimeout(500);
        assert(await page.locator('#mailStorageBanner').isHidden(), 'the notice goes away again');
        await context.close();

        // ---- Form field cards ----
        for (const viewport of [{ width: 1280, height: 900 }, { width: 2000, height: 900 }, { width: 820, height: 900 }, { width: 390, height: 900 }]) {
            ({ context, page } = await login(browser, viewport));
            await page.evaluate(() => switchTab('settings'));
            await page.evaluate(() => document.querySelector('.settings-tab-btn[data-settings-tab="forms"]').click());
            await page.locator('.forms-field-row').first().waitFor({ state: 'visible' });
            const where = viewport.width + 'px: ';
            const geometry = await page.evaluate(() => {
                const row = document.querySelector('.forms-field-row');
                const r = row.getBoundingClientRect();
                const del = row.querySelector('.btn-icon--danger').getBoundingClientRect();
                const key = row.querySelector('[data-field-prop="key"]').getBoundingClientRect();
                const check = row.querySelector('.forms-field-check input').getBoundingClientRect();
                const cell = row.querySelector('.forms-field-check').getBoundingClientRect();
                const type = row.querySelector('[data-field-prop="type"]').closest('td').getBoundingClientRect();
                const cells = Array.from(row.querySelectorAll('td')).map(td => td.getBoundingClientRect());
                return {
                    row: [r.left, r.top, r.right, r.bottom], del: [del.left, del.top, del.right, del.bottom], key: [key.left, key.top, key.right, key.bottom],
                    checkWidth: check.width, checkOffset: check.left - cell.left, cellWidth: cell.width, typeTop: type.top,
                    overlap: cells.some((a, i) => cells.some((b, j) => i < j && a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1))
                };
            });
            assert(geometry.del[0] >= geometry.row[0] && geometry.del[2] <= geometry.row[2] && geometry.del[1] >= geometry.row[1] && geometry.del[3] <= geometry.row[3], where + 'the delete button stays inside its card');
            assert(geometry.del[1] < geometry.typeTop + 70, where + 'the delete button sits at the top of the card, level with the first input');
            assert(!geometry.overlap, where + 'no field overlaps another (or the delete button)');
            assert(geometry.checkWidth <= 24 && geometry.checkOffset < geometry.cellWidth * 0.25, where + 'the required checkbox is a small box at the left of its cell (' + geometry.checkWidth + 'px wide, offset ' + geometry.checkOffset + ')');
            assert(geometry.del[0] >= geometry.key[2] - 1 || geometry.del[1] > geometry.key[3] || geometry.del[3] < geometry.key[1], where + 'the delete button does not cover an input');
            await context.close();
        }
        if (errors.length) throw new Error(errors.join('\n'));
        console.log('forms-ui-check ok');
    } finally {
        fs.writeFileSync(formFile, original);
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exit(1); });
