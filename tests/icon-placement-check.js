const assert = require('node:assert/strict');
const { chromium } = require('playwright');

async function checkCombinedPlayback(page, guest, base) {
    const field = (root, key) => root.locator('[data-playback-field="' + key + '"]');
    const number = async (root, key, value) => {
        await field(root, key).fill(String(value));
        await field(root, key).press('Tab');
    };
    await page.goto(base + '/admin/dashboard.php#icons');
    await page.locator('#iconManagerSearch').fill('useanimations-mail');
    const card = page.locator('[data-icon-key="useanimations-mail"]');
    await card.locator('[data-icon-edit]').click();
    await page.locator('#iconManagerTrigger').selectOption('events');
    await page.locator('#iconManagerReverse').uncheck();
    const events = page.locator('#iconManagerEvents');
    await number(events, 'intro-to', 70);
    await number(events, 'hover-from', 20);
    await number(events, 'hover-to', 60);
    await number(events, 'click-from', 5);
    await number(events, 'click-to', 90);
    await field(events, 'leave-action').selectOption('once');
    await number(events, 'leave-from', 60);
    await number(events, 'leave-to', 0);
    await page.locator('#iconManagerForm button[type=submit]').click();
    await page.locator('#iconManagerModalOverlay').waitFor({state: 'hidden'});
    assert.equal(await card.locator('g[data-nb-ua]').first().getAttribute('data-nb-hover'), 'loop:20:60');

    // Override only hover in the second placement. Its other rows still inherit the library.
    await page.goto(base + '/placements/');
    await page.locator('#admin-btn-edit').click();
    const second = page.locator('.feature-card').nth(1);
    await second.hover();
    await second.locator('.list-edit-btn').click();
    const modal = page.locator('#editable-group-modal');
    assert.equal(await field(modal, 'hover-from').inputValue(), '20');
    await field(modal, 'hover-action').selectOption('off');
    await modal.locator('button[onclick="InlineEditor.saveGroupEditor()"]').click();
    await Promise.all([
        page.waitForResponse(r => r.url().includes('api.php') && r.request().postData()?.includes('en_placements')),
        page.locator('#admin-btn-save').click()
    ]);
    await page.goto(base + '/admin/dashboard.php#page/en_placements');
    await page.locator('#groupToggleAll').click();
    const saved = page.locator('[data-icon-playback-path="features.items.1.iconPlayback"]');
    if (!await saved.isVisible()) await page.locator('[data-group-path="features.items.1"] > .ce-array-item-header .ce-chevron').click();
    assert.equal(await field(saved, 'hover-action').inputValue(), 'off');
    assert.equal(await field(saved, 'click-action').inputValue(), '');
    assert.equal(await field(saved, 'click-to').inputValue(), '90');

    await guest.mouse.move(0, 0);
    await guest.reload({waitUntil: 'domcontentloaded'});
    const svg = guest.locator('.feature-card__icon > svg').first();
    await guest.evaluate(async () => {
        const player = (await import('/js/vendor/lottie-web/lottie_light.min.js')).default;
        window.clipPlayer = () => player.getRegisteredAnimations().find(p =>
            document.querySelector('.feature-card__icon > svg').contains(p.renderer.layerElement));
    });
    const wait = async (expected) => {
        await guest.waitForFunction(values => {
            const p = window.clipPlayer();
            if (!p) return false;
            const actual = {start: p.firstFrame, end: p.firstFrame + p.totalFrames - 1, frame: p.firstFrame + p.currentRawFrame,
                loop: p.loop, paused: p.isPaused, repeated: p.playCount > 0};
            return Object.entries(values).every(([key, value]) => actual[key] === value);
        }, expected, {timeout: 5000}).catch(e => { throw Error('Missing playback ' + JSON.stringify(expected), {cause: e}); });
    };
    await wait({start: 0, end: 13, paused: false}); // entrance
    await wait({frame: 1, paused: true}); // passive pose
    await svg.hover();
    await wait({start: 4, end: 11, loop: true, repeated: true});
    await svg.click();
    await wait({frame: 17, paused: true, loop: false});
    // A decorative icon animates without posing as a control.
    assert.deepEqual(await svg.evaluate(el => ['role', 'tabindex', 'aria-pressed', 'aria-label'].map(name => el.getAttribute(name))), [null, null, null, null]);
    assert.equal(await svg.getAttribute('aria-hidden'), 'true');
    await guest.mouse.move(0, 0);
    await wait({start: 0, end: 11, paused: false}); // separate exit clip
    await wait({frame: 17, paused: true}); // exit restores the active pose
    await svg.hover();
    await wait({loop: true});
    await svg.click();
    await wait({frame: 1, paused: true});
    // Reverse a toggle in flight, while the pointer is still on the icon.
    await svg.click();
    await guest.waitForFunction(() => {
        const p = window.clipPlayer();
        const frame = p.firstFrame + p.currentRawFrame;
        if (p.isPaused || frame < 4 || frame > 13) return false;
        document.querySelector('.feature-card__icon > svg').dispatchEvent(new MouseEvent('click', {bubbles: true}));
        return true;
    });
    await wait({frame: 1, paused: true});
    await guest.emulateMedia({reducedMotion: 'reduce'});
    await svg.click();
    await wait({frame: 17, paused: true, loop: false});
    await svg.click();
    await wait({frame: 1, paused: true});
    const secondSvg = guest.locator('.feature-card__icon > svg').nth(1);
    assert.equal(await secondSvg.getAttribute('data-nb-hover'), 'off:20:60');
    assert.equal(await secondSvg.getAttribute('data-nb-click'), null);
    // An application-owned toggle supplies its state directly, including while motion is reduced.
    await guest.evaluate(() => {
        const control = document.createElement('button');
        control.id = 'controlled-icon';
        control.setAttribute('aria-pressed', 'true');
        const copy = document.querySelector('.feature-card__icon > svg').cloneNode(true);
        control.append(copy);
        document.querySelector('main').prepend(control);
        control.scrollIntoView();
    });
    for (const [pressed, frame] of [['true', 17], ['false', 1]]) {
        await guest.locator('#controlled-icon').evaluate((el, state) => el.setAttribute('aria-pressed', state), pressed);
        await guest.waitForFunction(async frame => {
            const lottie = (await import('/js/vendor/lottie-web/lottie_light.min.js')).default;
            const host = document.querySelector('#controlled-icon');
            const p = lottie.getRegisteredAnimations().find(item => host.contains(item.renderer.layerElement));
            return p?.isPaused && p.firstFrame + p.currentRawFrame === frame;
        }, frame);
    }
    console.log('PASS combined entrance, hover loop, exit, both click states, reduced motion and per-event inheritance');
}

// A visitor pays for the player only once an icon has to move, and decorative icons add no tab stops.
async function checkLazyDecorativeIcons(browser, base) {
    const guest = await browser.newPage();
    const requests = [];
    guest.on('request', request => {
        if (/lottie|useanimations\/[^/]+\.json/.test(request.url())) requests.push(request.url());
    });
    await guest.goto(base + '/lazy/');
    await guest.locator('#lazy-card g[data-nb-ua]').waitFor({state: 'attached'});
    await guest.waitForTimeout(600);
    assert.deepEqual(requests, []);
    assert.equal(await guest.locator('main svg:is([tabindex], [role], [aria-label])').count(), 0);
    await guest.keyboard.press('Tab');
    assert.equal(await guest.evaluate(() => !!document.activeElement.closest('main svg')), false);
    await guest.locator('#lazy-card').hover();
    await guest.waitForFunction(() => document.querySelector('#lazy-card g[data-nb-ua] defs, #lazy-card g[data-nb-ua] g'));
    assert.equal(requests.length, 2);
    await guest.close();
    console.log('PASS no player request before interaction and no tab stops on decorative icons');
}

(async () => {
    const browser = await chromium.launch();
    try {
        const base = process.env.REVIEW_URL;
        await checkLazyDecorativeIcons(browser, base);
        const page = await browser.newPage({ viewport: { width: 1360, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(base + '/admin/index.php');
        await page.locator('#username').fill('admin');
        await page.locator('#password').fill(process.env.REVIEW_PASSWORD);
        await Promise.all([page.waitForURL('**/admin/dashboard.php'), page.locator('form button[type=submit]').first().click()]);
        await page.goto(base + '/placements/');
        await page.locator('#admin-btn-edit').click();
        const cards = page.locator('.feature-card');
        const modal = page.locator('#editable-group-modal');
        const settings = modal.locator('.nb-icon-placement');
        const select = (root, field, value) => root.locator('select[data-playback-field="' + field + '"]').selectOption(value);
        const cardSvg = i => cards.nth(i).locator('.feature-card__icon > svg');
        await cards.nth(0).hover();
        await cards.nth(0).locator('.list-edit-btn').click();
        assert.match(await settings.locator('select').first().innerText(), /Library default.*click/i);
        await select(settings, 'mode', 'once');
        await select(settings, 'direction', 'forward');
        await modal.locator('button[onclick="InlineEditor.saveGroupEditor()"]').click();
        assert.equal(await cardSvg(0).getAttribute('data-nb-play'), 'once');
        assert.equal(await cardSvg(1).getAttribute('data-nb-play'), null);
        await page.locator('#admin-btn-undo').click();
        assert.equal(await cardSvg(0).getAttribute('data-nb-play'), null);
        await page.locator('#admin-btn-redo').click();
        assert.equal(await cardSvg(0).getAttribute('data-nb-play'), 'once');
        await cards.first().hover();
        await cards.first().locator('.list-drag-handle').dragTo(cards.nth(1), {targetPosition: {x: 30, y: (await cards.nth(1).boundingBox()).height - 8}});
        assert.equal(await cardSvg(1).getAttribute('data-nb-play'), 'once');
        await cards.nth(1).hover();
        await cards.nth(1).locator('.list-edit-btn').click();
        assert.equal(await settings.locator('[data-playback-field="mode"]').inputValue(), 'once');
        await modal.locator('.editor-modal-footer button[onclick="InlineEditor.closeGroupEditor()"]').click();
        await page.locator('#admin-btn-undo').click();
        assert.equal(await cardSvg(0).getAttribute('data-nb-play'), 'once');
        await page.locator('[data-editable-icon][data-field="standalone.icon"]').click();
        await select(settings, 'mode', 'static');
        await modal.locator('button[onclick="InlineEditor.saveGroupEditor()"]').click();
        await Promise.all([
            page.waitForResponse(r => r.url().includes('api.php') && r.request().postData()?.includes('en_placements')),
            page.locator('#admin-btn-save').click()
        ]);
        const guest = await browser.newPage();
        await guest.goto(base + '/placements/');
        const svgs = guest.locator('.feature-card__icon > svg');
        assert.equal(await guest.locator('main svg:is([tabindex], [role])').count(), 0);
        assert.equal(await svgs.nth(0).getAttribute('data-nb-reverse'), 'false');
        assert.equal(await svgs.nth(1).getAttribute('data-nb-play'), null);
        assert.equal(await guest.locator('[data-icon="useanimations-mail"] > svg').getAttribute('data-nb-play'), 'static');
        await guest.evaluate(async () => {
            window.placementTestPlayer = (await import('/js/vendor/lottie-web/lottie_light.min.js')).default;
        });
        await guest.waitForFunction(() => {
            const icons = Array.from(document.querySelectorAll('.feature-card__icon > svg'));
            const players = icons.map(svg => window.placementTestPlayer.getRegisteredAnimations().find(p => svg.contains(p.renderer.layerElement)));
            return players.every(p => p && p.isPaused && p.currentRawFrame === p.totalFrames - 1);
        });
        await svgs.nth(1).click();
        await guest.waitForFunction(() => {
            const second = document.querySelectorAll('.feature-card__icon > svg')[1];
            const player = window.placementTestPlayer.getRegisteredAnimations().find(p => second.contains(p.renderer.layerElement));
            return player && player.isPaused && player.currentRawFrame === 0;
        });

        await page.goto(base + '/admin/dashboard.php#page/en_placements');
        await page.locator('#groupToggleAll').click();
        const first = page.locator('[data-icon-playback-path="features.items.0.iconPlayback"]');
        if (!await first.isVisible()) await page.locator('[data-group-path="features.items.0"] > .ce-array-item-header .ce-chevron').click();
        assert.equal(await first.locator('select').first().inputValue(), 'once');
        await select(first, 'mode', '');
        await select(first, 'direction', '');
        await page.evaluate(() => saveContent());
        await guest.reload();
        assert.equal(await svgs.nth(0).getAttribute('data-nb-play'), null);
        assert.equal(await svgs.nth(0).getAttribute('data-nb-reverse'), null);
        assert.equal(await svgs.nth(0).locator('g[data-nb-ua]').getAttribute('data-nb-reverse'), 'true');
        const data = (await (await page.request.get(base + '/admin/api.php?action=load&page=en_placements')).json()).data;
        assert.equal(data.features.items[0].iconPlayback, undefined);
        assert.equal(data.features.items[1].iconPlayback, undefined);
        assert.deepEqual(data.standalone.iconPlayback, {mode: 'static'});
        await checkCombinedPlayback(page, guest, base);
        assert.deepEqual(errors, []);
        console.log('PASS independent playback, direction, inheritance, undo/redo, dashboard reset and guest animation');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
