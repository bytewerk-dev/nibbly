const { chromium } = require('playwright');
const assert = require('node:assert/strict');

// Inspect the actual rendered Lottie layer so these checks cover frames, direction and timing.
async function playback(group) {
    return group.evaluate(async el => {
        const { default: lottie } = await import('../js/vendor/lottie-web/lottie_light.min.js');
        const p = lottie.getRegisteredAnimations().find(p => el.contains(p.renderer.layerElement));
        return p && { frame: p.currentRawFrame, last: p.totalFrames - 1, fps: p.frameRate, paused: p.isPaused, direction: p.playDirection, loop: p.loop };
    });
}

async function waitPlayback(group, predicate, description) {
    for (let i = 0; i < 100; i++) {
        const state = await playback(group);
        if (state && predicate(state)) return state;
        await group.page().waitForTimeout(30);
    }
    assert.fail(description + ': ' + JSON.stringify(await playback(group)));
}

async function checkDashboardIconMotion(page) {
    const inbox = page.locator('#dashboardStatusStrip button:has([data-nb-ua="mail"])');
    const mail = inbox.locator('[data-nb-ua="mail"]');
    await inbox.hover();
    await waitPlayback(mail, s => s.frame > 2 && s.fps === 30 && s.last === 19, 'Mail plays on hover');
    await page.mouse.move(0, 0);
    await waitPlayback(mail, s => s.paused && s.frame === 0, 'Mail reverses to rest');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await inbox.hover();
    assert.equal((await playback(mail)).frame, 0);
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await inbox.click();
    await page.locator('#mailsTab').waitFor({ state: 'visible' });
}

async function checkIconCatalog(page) {
    const filters = page.locator('#iconManagerFilterLinks');
    const facet = label => filters.getByRole('list', { name: label, exact: true });
    const option = (label, value) => facet(label).locator('button[data-filter-value="' + value + '"]');
    const pick = (label, value) => option(label, value).click();
    const all = filters.getByRole('button', { name: /^All \(/ });
    const cards = page.locator('.icon-manager-card');
    const search = page.locator('#iconManagerSearch');
    const mail = page.locator('.icon-manager-card[data-icon-key="useanimations-mail"]');
    await all.waitFor();
    assert.equal(await all.count(), 1);
    assert.equal(await all.getAttribute('aria-pressed'), 'true');
    assert.equal(await page.locator('#iconManagerPath').count(), 0);
    const rows = await Promise.all(['Category', 'Library', 'Animation'].map(label => facet(label).boundingBox()));
    assert.ok(rows[0].y + rows[0].height <= rows[1].y);
    assert.ok(Math.abs(rows[1].y - rows[2].y) < 1);
    assert.ok(rows[2].x - (rows[1].x + rows[1].width) >= 16);
    assert.equal(await facet('Category').getByRole('button').first().innerText(), await all.innerText());
    assert.deepEqual(await facet('Animation').getByRole('button').evaluateAll(buttons => buttons.map(b => b.dataset.filterValue)), ['animated', 'static']);
    assert.equal(await filters.locator('.nb-filter-links__label').count(), 0);
    await pick('Animation', 'animated');
    await pick('Category', 'navigation');
    assert.ok(await cards.count() > 0);
    assert.equal(await mail.count(), 0);
    assert.equal(await option('Library', 'useAnimations').innerText(), 'useAnimations (' + await cards.count() + ')');
    await option('Category', 'navigation').press('Space');
    assert.equal(await option('Category', 'navigation').getAttribute('aria-pressed'), 'false');
    assert.equal(await option('Animation', 'animated').getAttribute('aria-pressed'), 'true');
    assert.equal(await page.evaluate(() => document.activeElement.dataset.filterValue), 'navigation');
    await all.click();

    await mail.locator('[data-icon-edit]').click();
    assert.equal(await page.locator('#iconManagerSvg').isVisible(), false);
    assert.equal(await page.locator('#iconManagerCategory').inputValue(), 'Communication');
    assert.equal(await page.locator('#iconManagerLibrary').inputValue(), 'useAnimations');
    assert.equal(await page.locator('#iconManagerLibrary').evaluate(el => el.readOnly), true);
    await page.locator('#iconManagerCategory').fill('Editorial');
    await page.locator('#iconManagerTrigger').selectOption('static');
    assert.equal(await page.locator('#iconManagerSvg').isVisible(), false);
    await page.locator('#iconManagerForm button[type=submit]').click();
    await page.locator('#iconManagerModalOverlay').waitFor({ state: 'hidden' });
    await pick('Category', 'Editorial');
    await pick('Library', 'useAnimations');
    await pick('Animation', 'static');
    await search.fill('Editorial');
    assert.equal(await cards.count(), 1);
    assert.equal(await mail.count(), 1);
    await search.fill('no-such-icon');
    assert.equal(await cards.count(), 0);
    assert.equal(await facet('Animation').getByRole('button', {pressed: true}).innerText(), 'Static (0)');
    assert.equal(await option('Animation', 'animated').isDisabled(), true);
    await all.click();
    assert.equal(await filters.getByRole('button', {pressed: true}).count(), 1);
    assert.equal(await all.getAttribute('aria-pressed'), 'true');
    assert.equal(await all.innerText(), 'All (0)');
    assert.equal(await search.inputValue(), 'no-such-icon');
    await search.fill('');
}

async function checkIconStrokeInheritance(page) {
    const key = 'test-inherited-stroke';
    const card = page.locator('.icon-manager-card[data-icon-key="' + key + '"]');
    const width = page.locator('#iconManagerStrokeWidth');
    const useGlobal = page.getByRole('switch', {name: 'Use global width'});
    const svg = page.locator('#iconManagerSvg');
    await page.locator('#iconManagerSearch').fill(key);
    await page.locator('button[onclick="openIconManagerModal()"]').click();
    assert.equal(await svg.isVisible(), true);
    assert.equal(await page.locator('#iconManagerSvgDetails summary').isVisible(), false);
    assert.equal(await page.locator('#iconManagerCleanupBtn').isVisible(), true);
    assert.equal(await page.locator('#iconManagerCurrentColorBtn').isVisible(), true);
    await page.locator('#iconManagerKey').fill(key);
    await page.locator('#iconManagerCategory').fill('Editorial');
    await page.locator('#iconManagerLibrary').fill('Studio');
    const markup = '<path stroke="currentColor" stroke-width="1.5" d="M2 12h20"/>';
    await svg.fill(markup);
    assert.equal(await useGlobal.isChecked(), true);
    assert.equal(await width.isDisabled(), true);
    assert.equal(await width.inputValue(), '2');
    await useGlobal.focus();
    await page.keyboard.press('Space');
    assert.equal(await width.isEnabled(), true);
    assert.equal(await width.inputValue(), '1.5');
    await width.fill('0');
    assert.equal(await width.evaluate(el => el.validity.rangeUnderflow), true);
    await width.fill('1.75');
    await useGlobal.check();
    await svg.fill(markup.replace('h20', 'h18'));
    assert.equal(await width.inputValue(), '2');
    await useGlobal.uncheck();
    assert.equal(await width.inputValue(), '1.75');
    await useGlobal.check();
    await page.locator('#iconManagerForm button[type=submit]').click();
    await page.locator('#iconManagerModalOverlay').waitFor({ state: 'hidden' });
    const globalWidth = page.locator('#iconManagerGlobalStrokeWidth');
    await globalWidth.fill('3');
    await globalWidth.press('Tab');
    await page.waitForFunction(key => document.querySelector('[data-icon-key="' + key + '"] .icon-manager-card__preview svg')?.getAttribute('stroke-width') === '3', key);
    await card.locator('[data-icon-edit]').click();
    assert.equal(await page.locator('#iconManagerCategory').inputValue(), 'Editorial');
    assert.equal(await page.locator('#iconManagerLibrary').inputValue(), 'Studio');
    assert.equal(await useGlobal.isChecked(), true);
    assert.equal(await width.isDisabled(), true);
    assert.equal(await width.inputValue(), '3');
    await useGlobal.uncheck();
    await width.fill('1.75');
    await page.locator('#iconManagerForm button[type=submit]').click();
    await page.locator('#iconManagerModalOverlay').waitFor({ state: 'hidden' });
    await card.locator('[data-icon-edit]').click();
    assert.equal(await useGlobal.isChecked(), false);
    assert.equal(await width.inputValue(), '1.75');
    await page.locator('#iconManagerModalOverlay .modal-actions button[type=button]').click();
}

async function checkIconMotion(page) {
    await page.locator('#iconManagerSearch').fill('useAnimations');
    const card = id => page.locator('.icon-manager-card[data-icon-key="useanimations-' + id + '"]');
    const preview = id => card(id).locator('[data-nb-preview]');
    const group = id => preview(id).locator('[data-nb-ua]');
    for (const [id, last] of [['send', 39], ['refresh', 59]]) {
        await preview(id).hover();
        await waitPlayback(group(id), s => s.frame === 0 && s.paused, id + ' waits for click');
        assert.equal(await group(id).locator('path[stroke="currentColor"]').first().evaluate(el => getComputedStyle(el).strokeWidth), '2px');
        await preview(id).click();
        await waitPlayback(group(id), s => s.frame > 4 && s.last === last && s.fps === 30, id + ' plays its own timeline');
        await preview(id).click();
        assert.ok((await playback(group(id))).frame < 5);
    }
    await preview('bookmark').scrollIntoViewIfNeeded();
    await preview('bookmark').focus();
    await page.keyboard.press('Enter');
    await waitPlayback(group('bookmark'), s => s.paused && s.frame === s.last, 'Bookmark reaches selected state');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await preview('bookmark').click();
    await waitPlayback(group('bookmark'), s => s.paused && s.frame === 0, 'Reduced motion changes state immediately');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await preview('activity').scrollIntoViewIfNeeded();
    await waitPlayback(group('activity'), s => s.loop && s.frame > 0, 'Activity loops while visible');
    await preview('globe').hover();
    await waitPlayback(group('globe'), s => s.loop && s.frame > 0, 'Globe loops on hover');
    await page.mouse.move(0, 0);
    await waitPlayback(group('globe'), s => !s.loop && s.paused, 'Globe finishes its cycle');

    // Saving the icon preserves its playback settings.
    await card('send').locator('[data-icon-edit]').click();
    const svgDetails = page.locator('#iconManagerSvgDetails');
    assert.equal(await svgDetails.evaluate(el => el.open), false);
    await page.locator('#iconManagerTrigger').selectOption('once');
    await page.locator('#iconManagerReverse').check();
    const edit = page.locator('#iconManagerPreview [data-nb-ua="send"]');
    await edit.locator('xpath=ancestor::button[1]').scrollIntoViewIfNeeded();
    await waitPlayback(edit, s => s.direction === -1 && s.frame < s.last && s.frame > 0, 'Reversed single play');
    await svgDetails.locator('summary').press('Enter');
    assert.equal(await page.locator('#iconManagerSvg').isVisible(), true);
    assert.match(await page.locator('#iconManagerSvgHint').innerText(), /Lottie/);
    assert.equal(await page.locator('#iconManagerCleanupBtn').isVisible(), false);
    assert.equal(await page.locator('#iconManagerCurrentColorBtn').isVisible(), false);
    await page.locator('#iconManagerForm button[type=submit]').click();
    await page.locator('#iconManagerModalOverlay').waitFor({ state: 'hidden' });
    assert.equal(await group('send').getAttribute('data-nb-play'), 'once');
    assert.equal(await group('send').getAttribute('data-nb-reverse'), 'true');
}

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
            page.waitForURL('**/admin/dashboard.php'),
            page.locator('form').first().locator('button[type=submit]').click()
        ]);
        await page.waitForLoadState('networkidle');
        if (await page.locator('#dashboardAiSection').count()) throw Error('Disabled AI section rendered');
        if (!(await page.locator('#dashboardAnalyticsState').textContent()).includes('disabled')) throw Error('Disabled analytics state missing');
        const ticks = await page.locator('#dashboardTrafficChart .dashboard-chart-grid text').allTextContents();
        if (ticks.length !== 5 || new Set(ticks).size !== 5) throw Error('Chart repeats integer axis labels');
        await checkDashboardIconMotion(page);
        for (const tab of ['content', 'news', 'mails', 'media', 'menus', 'icons', 'settings', 'backup', 'home']) {
            await page.locator('.sidebar-nav-item[data-tab="' + tab + '"]').click();
            await page.waitForLoadState('networkidle');
            if (tab === 'menus') await page.waitForSelector('#mfMenus .mf-panel [data-mf-add-toggle]', { timeout: 5000 });
            if (tab === 'icons') {
                await checkIconCatalog(page);
                await checkIconMotion(page);
                await checkIconStrokeInheritance(page);
            }
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
        // A site rule that stretches every image of a container must not enlarge the AI label.
        const label = await guest.evaluate(async base => {
            document.head.insertAdjacentHTML('beforeend', '<style>#label-probe.hero img { width: 100%; height: auto; min-height: 80px; }</style>');
            document.body.insertAdjacentHTML('beforeend', '<div id="label-probe" class="hero" style="width:360px"><span class="nb-ai-label nb-ai-label--generated">'
                + '<img class="nb-ai-label__badge" src="' + base + '/css/ai-labels/en-generated.svg" alt=""></span></div>');
            const badge = document.querySelector('#label-probe img');
            await badge.decode();
            const box = badge.getBoundingClientRect();
            const custom = (document.querySelector('#label-probe').style.setProperty('--nb-ai-label-height', '30px'), badge.getBoundingClientRect().height);
            document.querySelector('#label-probe').remove();
            return { width: box.width, height: box.height, custom };
        }, base);
        assert.equal(label.height, 22);
        assert.ok(label.width < 200, 'AI label keeps its aspect ratio: ' + label.width);
        assert.equal(label.custom, 30);
        // Editors get a smaller dashboard: the script must not stop at fields only admins have
        const editor = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
        editor.on('pageerror', error => errors.push('editor: ' + error.message));
        await editor.goto(base + '/admin/index.php');
        await editor.locator('#username').fill('editor');
        await editor.locator('#password').fill(process.env.REVIEW_PASSWORD);
        await Promise.all([
            editor.waitForURL('**/admin/dashboard.php'),
            editor.locator('form').first().locator('button[type=submit]').click()
        ]);
        await editor.waitForLoadState('networkidle');
        for (const tab of ['people', 'events', 'settings', 'content']) {
            await editor.locator('.sidebar-nav-item[data-tab="' + tab + '"]').click();
            await editor.waitForLoadState('networkidle');
            if (tab === 'people') await editor.waitForSelector('#peopleMount #peopleRows', { timeout: 5000 });
        }
        if (await editor.locator('.toast-error, .toast.error').count()) throw Error('Editor dashboard shows an error toast');
        if (errors.length) throw Error(errors.join('\n'));
        console.log('PASS Chromium login, nine dashboard tabs, editor dashboard, integer chart ticks, disabled AI, mobile admin offset, guest page and no JS errors');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
