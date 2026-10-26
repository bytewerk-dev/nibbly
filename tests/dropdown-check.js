// Every custom dropdown (nb-select) of the dashboard: opened at several window sizes, no option may be squeezed or clipped,
// long lists scroll, and the list stays inside the window. Guards against the flex-shrink bug that cut the text of long lists.
const { chromium } = require('playwright');

const base = process.env.REVIEW_URL;
const password = process.env.REVIEW_PASSWORD;
const errors = [];
let opened = 0;
const seen = new Set();

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

async function inspectOpenList(page, label) {
    return page.evaluate(() => {
        const list = Array.from(document.querySelectorAll('.nb-select__list')).find(el => !el.hidden);
        if (!list) return { error: 'the list did not open' };
        const rect = list.getBoundingClientRect();
        const options = Array.from(list.querySelectorAll('.nb-select__option, .nb-select__group'));
        const squeezed = options.filter(el => {
            const style = getComputedStyle(el);
            const needed = parseFloat(style.lineHeight === 'normal' ? parseFloat(style.fontSize) * 1.2 : style.lineHeight) + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
            return el.getBoundingClientRect().height + 0.5 < needed || el.scrollHeight > el.clientHeight + 1;
        }).map(el => el.textContent.trim() + ' [h=' + el.getBoundingClientRect().height + ' ch=' + el.clientHeight + ' sh=' + el.scrollHeight + ' lh=' + getComputedStyle(el).lineHeight + ']');
        return {
            count: options.length, squeezed,
            scrolls: list.scrollHeight > list.clientHeight + 1,
            fits: list.scrollHeight <= list.clientHeight + 1,
            inside: rect.top >= -1 && rect.bottom <= window.innerHeight + 1 && rect.left >= -1 && rect.right <= window.innerWidth + 1,
            height: Math.round(rect.height)
        };
    });
}

async function checkVisibleDropdowns(page, where) {
    const buttons = await page.locator('.nb-select__button').all();
    for (const button of buttons) {
        if (!(await button.isVisible())) continue;
        const id = (await button.getAttribute('data-nb-select-for')) || '(no id)';
        const key = where + ' / ' + id;
        if (seen.has(key)) continue;
        seen.add(key);
        await button.scrollIntoViewIfNeeded();
        await button.click();
        const result = await inspectOpenList(page, key);
        assert(!result.error, key + ': ' + result.error);
        assert(result.squeezed.length === 0, key + ': options are squeezed or clipped: ' + result.squeezed.join(', '));
        assert(result.fits || result.scrolls, key + ': the list neither fits nor scrolls');
        assert(result.inside, key + ': the list leaves the window (' + result.height + 'px high)');
        opened++;
        await page.keyboard.press('Escape');
        await page.waitForTimeout(60);
        if (await page.locator('.nb-select__list:not([hidden])').count()) await page.mouse.click(2, 2);
    }
}

async function walk(page, sizeName) {
    const topTabs = await page.evaluate(() => Array.from(document.querySelectorAll('.sidebar-nav-item[data-tab]')).map(el => el.getAttribute('data-tab')));
    for (const tab of topTabs) {
        await page.evaluate(name => switchTab(name), tab);
        await page.waitForTimeout(150);
        if (tab !== 'settings') { await checkVisibleDropdowns(page, sizeName + ' ' + tab); continue; }
        const settingsTabs = await page.evaluate(() => Array.from(document.querySelectorAll('.settings-tab-btn[data-settings-tab]')).map(el => el.getAttribute('data-settings-tab')));
        for (const settingsTab of Array.from(new Set(settingsTabs))) {
            // Activated directly: on narrow screens a tap on the slim sub-navigation first opens it
            await page.evaluate(name => activateSettingsTab(name), settingsTab);
            await page.waitForTimeout(200);
            await checkVisibleDropdowns(page, sizeName + ' settings/' + settingsTab);
        }
    }
}

(async () => {
    const browser = await chromium.launch();
    for (const [name, viewport] of [['desktop', { width: 1280, height: 900 }], ['short', { width: 1280, height: 520 }], ['tablet', { width: 820, height: 700 }], ['phone', { width: 390, height: 640 }]]) {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(name + ': ' + error.message));
        await page.goto(base + '/admin/index.php');
        await page.locator('#username').fill('admin');
        await page.locator('#password').fill(password);
        await Promise.all([page.waitForURL('**/admin/dashboard.php'), page.locator('form').first().locator('button[type=submit]').click()]);
        await page.waitForTimeout(400);
        await walk(page, name);
        await context.close();
    }
    await browser.close();
    assert(opened > 20, 'only ' + opened + ' dropdowns were checked');
    if (errors.length) throw new Error(errors.join('\n'));
    console.log('dropdown-check ok: ' + opened + ' dropdowns opened and inspected');
})().catch(error => { console.error(error.message); process.exit(1); });
