const { chromium } = require('playwright');
(async () => {
    const browser = await chromium.launch();
    try {
        const context = await browser.newContext();
        const first = await context.newPage();
        const second = await context.newPage();
        await first.goto(process.env.REVIEW_URL + '/admin/index.php');
        await first.locator('#username').fill('admin');
        await first.locator('#password').fill(process.env.REVIEW_PASSWORD);
        await Promise.all([first.waitForURL('**/admin/dashboard'), first.locator('form button[type=submit]').first().click()]);
        await second.goto(process.env.REVIEW_URL + '/admin/dashboard');
        for (const page of [first, second]) await page.evaluate(async () => { await fetch('api.php?action=load&page=en_home'); });
        const save = page => page.evaluate(async () => {
            const form = new FormData(); form.set('action', 'save'); form.set('page', 'en_home');
            form.set('content', JSON.stringify({ title: 'My unsaved revision', sections: [] })); form.set('csrf_token', CSRF_TOKEN);
            return (await fetch('api.php', { method: 'POST', body: form })).status;
        });
        if (await save(first) !== 200 || await save(second) !== 409) throw Error('Two-tab concurrency failed');
        await second.locator('.nibbly-conflict[open]').waitFor();
        if (await second.locator('.nibbly-conflict textarea').count() !== 2) throw Error('Conflict comparison missing');
        await second.setViewportSize({ width: 360, height: 844 });
        if (await second.evaluate(() => document.documentElement.scrollWidth > innerWidth)) throw Error('Conflict dialog overflows');
        await second.screenshot({ path: process.env.REVIEW_SCREENSHOTS + '/revision-conflict.png' });
        console.log('PASS two browser tabs: stale save blocked, comparison and mobile dialog');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
