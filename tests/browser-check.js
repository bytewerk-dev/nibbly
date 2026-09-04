const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const base = process.env.REVIEW_URL;
        await page.goto(base + '/admin/index.php');
        await page.locator('#username').fill('admin');
        await page.locator('#password').fill(process.env.REVIEW_PASSWORD);
        await Promise.all([
            page.waitForURL('**/admin/dashboard'),
            page.locator('form').first().locator('button[type=submit]').click()
        ]);
        await page.waitForLoadState('networkidle');
        if (await page.locator('#dashboardAiSection').count()) throw Error('Disabled AI section rendered');
        const ticks = await page.locator('#dashboardTrafficChart .dashboard-chart-grid text').allTextContents();
        if (ticks.length !== 5 || new Set(ticks).size !== 5) throw Error('Chart repeats integer axis labels');
        for (const tab of ['content', 'news', 'mails', 'media', 'icons', 'settings', 'backup', 'home']) {
            await page.locator('.sidebar-nav-item[data-tab="' + tab + '"]').click();
            await page.waitForLoadState('networkidle');
        }
        await page.screenshot({ path: process.env.REVIEW_SCREENSHOTS + '/dashboard.png' });
        await page.goto(base + '/services/test/');
        await page.waitForLoadState('networkidle');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.waitForFunction(() => {
            const bar = document.getElementById('admin-bar');
            const header = document.querySelector('.site-header');
            return bar && header && header.getBoundingClientRect().top >= bar.getBoundingClientRect().bottom - 1;
        });
        await page.screenshot({ path: process.env.REVIEW_SCREENSHOTS + '/mobile-admin.png' });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
        if (overflow) throw Error('Mobile page overflows viewport');
        const guest = await browser.newPage({ viewport: { width: 390, height: 844 } });
        await guest.goto(base + '/services/test/');
        if (await guest.locator('#admin-bar').count()) throw Error('Guest sees admin bar');
        if (errors.length) throw Error(errors.join('\n'));
        console.log('PASS Chromium login, eight dashboard tabs, integer chart ticks, disabled AI, mobile admin offset, guest page and no JS errors');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
