/* Run with python3 tests/browser-check.py page-history-check.js. */
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const base = process.env.REVIEW_URL;
        await page.goto(base + '/admin/index.php');
        await page.locator('#username').fill('admin');
        await page.locator('#password').fill(process.env.REVIEW_PASSWORD);
        await Promise.all([page.waitForURL('**/admin/dashboard.php'), page.locator('form button[type=submit]').first().click()]);
        const csrf = await page.evaluate(() => CSRF_TOKEN);
        const api = base + '/admin/api.php';
        let state = await (await page.request.get(api, {params: {action: 'load', page: 'en_home'}})).json();
        const content = state.data;
        for (let i = 0; i < 9; i++) {
            state = await (await page.request.post(api, {form: {action: 'save', page: 'en_home',
                revision: state.revision, content: JSON.stringify({...content, title: 'Version ' + i}), csrf_token: csrf}})).json();
            assert.ok(state.success, state.message);
        }
        await page.goto(base + '/admin/dashboard.php#page/en_home');
        const versions = page.locator('#backupList .backup-item');
        await versions.last().locator('[data-backup-protection]').click();
        await page.locator('#backupPinLabel').fill('Before <relaunch>');
        await page.locator('#modalConfirm').click();
        const pinned = versions.filter({hasText: 'Before <relaunch>'});
        await pinned.waitFor();
        assert.equal(await pinned.locator('.section-delete-btn').count(), 0);
        await page.locator('[data-tab="backup"]').click();
        await page.locator('#pageHistoryPolicy summary').getByText('Page history retention', {exact: true}).click();
        await page.locator('#historyPreview li').first().waitFor();
        assert.equal(await page.locator('#historyPreview li').count(), 9);
        await page.getByRole('combobox', {name: 'Page history retention', exact: true}).click();
        await page.getByRole('option', {name: 'Calendar tiers'}).click();
        await page.waitForFunction(() => document.querySelector('#historyStatus').textContent.startsWith('6 kept'));
        assert.equal(await page.locator('#historyPreview li').filter({hasText: 'Remove during cleanup'}).count(), 3);
        await page.locator('#pageHistoryForm button[type=submit]').click();
        await page.waitForFunction(() => document.querySelector('#pageHistoryForm button[type=submit]').disabled && !document.querySelector('#historyMode').disabled);
        await page.reload();
        await page.locator('#pageHistoryPolicy summary').click();
        await page.locator('#historyPreview li').first().waitFor();
        assert.equal(await page.locator('#historyMode').inputValue(), 'tiered');
        assert.equal(await page.locator('#historyPreview li').count(), 9);
        await page.goto(base + '/admin/dashboard.php#page/en_home');
        await pinned.locator('[data-backup-protection]').click();
        assert.equal(await page.locator('#backupPinLabel').inputValue(), 'Before <relaunch>');
        await page.locator('#modalAlt').click();
        await pinned.waitFor({state: 'detached'});
        assert.equal(await versions.locator('.section-delete-btn').count(), 9);
        assert.deepEqual(errors, []);
        console.log('PASS Chromium page history: protected label, keep/remove preview, saved policy on reload and unpin');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
