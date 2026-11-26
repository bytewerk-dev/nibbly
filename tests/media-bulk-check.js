// Media library batch actions in the dashboard (Playwright). Run: python3 tests/browser-check.py media-bulk-check.js
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ headless: true });
    const shots = process.env.REVIEW_SCREENSHOTS;
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const base = process.env.REVIEW_URL;
        await page.goto(base + '/admin/index.php');
        await page.locator('#username').fill('admin');
        await page.locator('#password').fill(process.env.REVIEW_PASSWORD);
        await Promise.all([
            page.waitForURL('**/admin/dashboard.php'),
            page.locator('form').first().locator('button[type=submit]').click()
        ]);
        await page.waitForLoadState('networkidle');

        // Six small files through the normal upload
        await page.evaluate(async () => {
            const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#5aa6a6"/></svg>';
            for (let i = 1; i <= 6; i++) {
                const form = new FormData();
                form.append('action', 'upload-media');
                form.append('type', 'image');
                form.append('file', new Blob([svg], { type: 'image/svg+xml' }), 'bulk-' + i + '.svg');
                form.append('csrf_token', CSRF_TOKEN);
                const result = await (await fetch('api.php', { method: 'POST', body: form })).json();
                if (!result.success) throw Error(result.message);
            }
        });
        await page.locator('.sidebar-nav-item[data-tab="media"]').click();
        await page.waitForLoadState('networkidle');
        const modal = page.locator('#nb-imgmgr-modal');
        const bar = modal.locator('.nb-imgmgr-bulkbar');
        await bar.waitFor({ state: 'visible' });
        await modal.locator('.nb-imgmgr-search').fill('bulk-');
        const tiles = modal.locator('.nb-imgmgr-grid .nb-imgmgr-item');
        if (await tiles.count() !== 6) throw Error('Expected 6 tiles, got ' + await tiles.count());

        // Select one, then a range with shift; a tile click toggles while selecting
        await tiles.nth(0).hover();
        await tiles.nth(0).locator('.nb-imgmgr-check--bulk').click();
        await tiles.nth(2).locator('.nb-imgmgr-check--bulk').click({ modifiers: ['Shift'] });
        if (await modal.locator('.nb-imgmgr-item.selected').count() !== 3) throw Error('Shift range did not select 3');
        await tiles.nth(3).locator('.nb-imgmgr-name').click();
        if (await modal.locator('.nb-imgmgr-item.selected').count() !== 4) throw Error('Tile click did not add to the selection');
        if (await page.locator('#nb-imgmgr-lightbox.active').count()) throw Error('Lightbox opened while selecting');
        await page.screenshot({ path: shots + '/media-bulk-selection.png' });

        // Move into a new folder
        await bar.locator('[data-bulk="move"]').click();
        const moveDialog = page.locator('#nb-imgmgr-bulk-move');
        await moveDialog.locator('[data-role="target"]').selectOption('__new__');
        await moveDialog.locator('#nb-imgmgr-bulk-move-name').fill('bulk-test');
        await page.screenshot({ path: shots + '/media-bulk-move.png' });
        await moveDialog.locator('[data-action="submit"]').click();
        await page.waitForFunction(() => document.querySelector('.nb-imgmgr-folder-item[data-folder="bulk-test"]'));
        await page.waitForLoadState('networkidle');
        await modal.locator('.nb-imgmgr-folder-item[data-folder="bulk-test"]').click();
        if (await tiles.count() !== 4) throw Error('Folder should hold 4 moved files, has ' + await tiles.count());

        // Select the page and download it as ZIP
        await bar.locator('.nb-imgmgr-bulk-page').check();
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            bar.locator('[data-bulk="zip"]').click()
        ]);
        const zipPath = shots + '/media-bulk.zip';
        await download.saveAs(zipPath);
        if (!/^media-.*\.zip$/.test(download.suggestedFilename())) throw Error('Unexpected download name ' + download.suggestedFilename());

        // Delete to trash (confirm dialog), then restore from the trash view
        await bar.locator('[data-bulk="trash"]').click();
        await page.locator('.nibbly-dialog [data-action="confirm"]').click();
        await page.waitForLoadState('networkidle');
        await page.waitForFunction(() => !document.querySelector('.nb-imgmgr-folder-item[data-folder="bulk-test"]') || document.querySelectorAll('#nb-imgmgr-modal .nb-imgmgr-grid .nb-imgmgr-item').length === 0);
        await modal.locator('.nb-imgmgr-mode-btn[data-mode="trash"]').click();
        await page.waitForLoadState('networkidle');
        await modal.locator('.nb-imgmgr-search').fill('bulk-');
        // The trash listing loads after the mode switch
        await page.waitForFunction(() => document.querySelectorAll('#nb-imgmgr-modal .nb-imgmgr-grid .nb-imgmgr-item').length === 4, null, { timeout: 5000 })
            .catch(async () => { throw Error('Trash should show 4 files, has ' + await tiles.count()); });
        await bar.locator('.nb-imgmgr-bulk-page').check();
        await page.screenshot({ path: shots + '/media-bulk-trash.png' });
        await bar.locator('[data-bulk="restore"]').click();
        await page.waitForLoadState('networkidle');
        await page.waitForFunction(() => document.querySelectorAll('#nb-imgmgr-modal .nb-imgmgr-grid .nb-imgmgr-item').length === 0);
        await modal.locator('.nb-imgmgr-mode-btn[data-mode="library"]').click();
        await page.waitForLoadState('networkidle');

        // Unpack the downloaded archive into a new folder named after it
        await modal.locator('.nb-imgmgr-unzip-input').setInputFiles(zipPath);
        const unzipDialog = page.locator('#nb-imgmgr-unzip');
        await unzipDialog.waitFor({ state: 'visible' });
        await unzipDialog.locator('.nb-imgmgr-unzip-subfolder').check();
        await page.screenshot({ path: shots + '/media-bulk-unzip.png' });
        await unzipDialog.locator('[data-action="submit"]').click();
        await unzipDialog.waitFor({ state: 'hidden' });
        await page.waitForLoadState('networkidle');
        await page.waitForFunction(() => document.querySelector('.nb-imgmgr-folder-item[data-folder="media-bulk"]'));
        await modal.locator('.nb-imgmgr-search').fill('bulk-');
        await modal.locator('.nb-imgmgr-folder-item[data-folder="media-bulk"]').click();
        if (await tiles.count() !== 4) throw Error('Unpacked folder should hold 4 files, has ' + await tiles.count());

        // Escape clears the selection first
        await tiles.nth(0).hover();
        await tiles.nth(0).locator('.nb-imgmgr-check--bulk').click();
        await page.keyboard.press('Escape');
        if (await modal.locator('.nb-imgmgr-item.selected').count() !== 0) throw Error('Escape did not clear the selection');

        // List view and a narrow window keep the bar usable
        await modal.locator('.nb-imgmgr-view-btn[data-view="list"]').click();
        await modal.locator('.nb-imgmgr-row').first().locator('.nb-imgmgr-check--bulk').click();
        if (await modal.locator('.nb-imgmgr-row.selected').count() !== 1) throw Error('List checkbox did not select');
        await page.setViewportSize({ width: 600, height: 900 });
        await page.screenshot({ path: shots + '/media-bulk-narrow.png' });
        const overflow = await bar.evaluate(el => el.scrollWidth > el.clientWidth + 1);
        if (overflow) throw Error('Bulk bar overflows in a narrow window');

        // A server without the PHP zip extension: both ZIP actions disappear
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.route('**/api.php?action=list-media*', async route => {
            const response = await route.fetch();
            const json = await response.json();
            json.data.zip = false;
            await route.fulfill({ response, json });
        });
        await modal.locator('.nb-imgmgr-mode-btn[data-mode="trash"]').click();
        await modal.locator('.nb-imgmgr-mode-btn[data-mode="library"]').click();
        await page.waitForLoadState('networkidle');
        await modal.locator('.nb-imgmgr-search').fill('bulk-');
        await modal.locator('.nb-imgmgr-row').first().locator('.nb-imgmgr-check--bulk').click();
        if (await modal.locator('.nb-imgmgr-unzip-btn').isVisible()) throw Error('Unpack ZIP shown without zip extension');
        if (await bar.locator('[data-bulk="zip"]').count()) throw Error('ZIP download shown without zip extension');
        if (!(await bar.locator('[data-bulk="move"]').isVisible())) throw Error('Other batch actions must stay');
        await page.unroute('**/api.php?action=list-media*');

        if (await page.locator('.toast-error, .toast.error').count()) throw Error('Error toast shown');
        if (errors.length) throw Error(errors.join('\n'));
        console.log('PASS media batch UI: selection, shift range, move to new folder, ZIP download, trash and restore, unzip into folder, Escape, list view, narrow layout');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exit(1);
});
