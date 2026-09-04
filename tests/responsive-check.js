const { chromium } = require('playwright');
const { writeFileSync } = require('node:fs');

(async () => {
    const browser = await chromium.launch({ headless: true });
    const findings = [];
    let states = 0;
    try {
        const page = await browser.newPage({ reducedMotion: 'reduce' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(process.env.REVIEW_URL + '/admin/index.php');
        await page.locator('#username').fill('admin');
        await page.locator('#password').fill(process.env.REVIEW_PASSWORD);
        await Promise.all([page.waitForURL('**/admin/dashboard'), page.locator('form button[type=submit]').first().click()]);
        await page.waitForLoadState('networkidle');
        for (const width of (process.env.REVIEW_WIDTH ? [Number(process.env.REVIEW_WIDTH)] : [360, 390, 768, 1024, 1440])) {
            await page.setViewportSize({ width, height: 1000 });
            const settingsTabs = await page.locator('[data-settings-tab]').evaluateAll(nodes => nodes.map(node => 'settings:' + node.dataset.settingsTab));
            for (const tab of ['backup', 'settings', 'content', 'news', 'events', 'mails', 'media', 'icons', 'home', 'system', ...settingsTabs]) {
                await page.evaluate(tab => { switchTab(tab.split(':')[0]); if (tab.includes(':')) document.querySelector('[data-settings-tab="' + tab.split(':')[1] + '"]').click(); }, tab);
                await page.waitForLoadState('networkidle');
                states++;
                const layout = await page.evaluate(() => {
                    const visible = element => !element.closest('.nb-imgmgr-folder-list, .users-table-wrap') && element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden' && getComputedStyle(element).opacity !== '0';
                    const controls = [...document.querySelectorAll('.admin-main input:not([type=checkbox]):not([type=radio]):not([type=hidden]), .admin-main select, .admin-main textarea')].filter(visible);
                    return {
                        overflow: document.documentElement.scrollWidth > innerWidth + 1,
                        wide: [...document.querySelectorAll('body *')].filter(visible).filter(el => el.getBoundingClientRect().right > innerWidth + 2).slice(-12).map(el => [el.tagName, el.id || el.className, Math.round(el.getBoundingClientRect().right)]),
                        narrow: controls.filter(element => element.getBoundingClientRect().width < 120 && !['number', 'color'].includes(element.type) && !element.classList.contains('color-hex-input')).map(element => element.id || element.name),
                        outside: [...document.querySelectorAll('.admin-main button, .admin-main input, .admin-main textarea, .nb-imgmgr-modal--embedded button, .nb-imgmgr-modal--embedded input')].filter(visible).filter(element => {
                            const rect = element.getBoundingClientRect(); return rect.right > innerWidth + 1 || rect.left < -1;
                        }).map(element => element.id || element.className)
                    };
                });
                if (layout.overflow || layout.narrow.length || layout.outside.length) findings.push({ width, tab, ...layout });
                if (tab === 'backup' || tab === 'settings:menus' || width === 390) {
                    const filename = tab.replace(/[^a-z0-9_-]/gi, '-') + '-' + width + '.png';
                    await page.screenshot({ path: process.env.REVIEW_SCREENSHOTS + '/' + filename, fullPage: true });
                }
            }
        }
        writeFileSync(process.env.REVIEW_SCREENSHOTS + '/layout.json', JSON.stringify({ findings, errors }, null, 2));
        console.log(JSON.stringify({ findings, errors }, null, 2));
        if (errors.length || findings.length) process.exitCode = 1;
        else console.log('PASS ' + states + ' responsive dashboard and settings states');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
