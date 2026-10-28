// Drives Chromium through a fresh installation and saves the guide's screenshots
// as PNG (tests/handbook-screenshots.py sets it up and converts them to WebP).
// Frames mark where to click; the guide's text never relies on their colour.
const { chromium } = require('playwright');
const path = require('path');

const base = process.env.HANDBOOK_URL;
const lang = process.env.HANDBOOK_LANG || 'de';
const password = process.env.HANDBOOK_PASSWORD;
const out = process.env.HANDBOOK_SHOTS;
const only = process.env.HANDBOOK_ONLY || '';
const other = lang === 'de' ? 'en' : 'de';
const FRAME = '#ef9524'; // Pop's notice colour

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

async function setup(request) {
    const form = await (await request.get(base + '/admin/setup.php')).text();
    const token = (form.match(/name="csrf_token" value="([^"]+)"/) || [])[1];
    if (!token) throw Error('Setup form without CSRF token');
    const body = new URLSearchParams();
    body.append('csrf_token', token);
    body.append('site_name', 'nibbly Demo');
    body.append('languages[]', lang);
    body.append('languages[]', other);
    body.append('primary_lang', lang);
    body.append('username', 'admin');
    body.append('email', 'admin@example.test');
    body.append('password', password);
    body.append('password_confirm', password);
    const response = await request.post(base + '/admin/setup.php', {
        data: body.toString(),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    });
    if (!response.ok()) throw Error('Setup failed: ' + response.status());
}

/** Frames around elements (selectors or locators), drawn above everything. */
async function mark(page, targets, padding = 4) {
    const boxes = [];
    for (const target of targets) {
        const locator = typeof target === 'string' ? page.locator(target).first() : target;
        if (!(await locator.count())) throw Error('Nothing to mark: ' + target);
        const box = await locator.boundingBox();
        if (box) boxes.push(box);
    }
    await page.evaluate(({ boxes, padding, colour }) => {
        for (const box of boxes) {
            // Inside the viewport, so a frame at the edge stays whole
            const left = Math.max(3, box.x - padding);
            const top = Math.max(3, box.y - padding);
            const right = Math.min(innerWidth - 3, box.x + box.width + padding);
            const bottom = Math.min(innerHeight - 3, box.y + box.height + padding);
            const frame = document.createElement('div');
            frame.className = 'hb-shot-frame';
            Object.assign(frame.style, {
                position: 'fixed', zIndex: 2147483647, pointerEvents: 'none', boxSizing: 'border-box',
                left: left + 'px', top: top + 'px', width: (right - left) + 'px', height: (bottom - top) + 'px',
                border: '3px solid ' + colour, borderRadius: '8px',
                boxShadow: '0 0 0 3px rgba(255,255,255,.55)'
            });
            document.body.appendChild(frame);
        }
    }, { boxes, padding, colour: FRAME });
}

async function unmark(page) {
    await page.evaluate(() => document.querySelectorAll('.hb-shot-frame').forEach(frame => frame.remove()));
}

/** Top part of the viewport, for shots about the admin bar: larger in the guide's column. */
const TOP = height => ({ x: 0, y: 0, width: 1440, height });

/** An element's box with a margin, for shots of a single dialog. */
async function around(page, selector, margin = 24) {
    const box = await page.locator(selector).first().boundingBox();
    const x = Math.max(0, box.x - margin);
    const y = Math.max(0, box.y - margin);
    return { x, y, width: Math.min(1440 - x, box.width + margin * 2), height: Math.min(900 - y, box.height + margin * 2) };
}

async function shot(page, name, targets = [], clip = undefined) {
    // Toasts come and go with timing; they are not part of any explanation
    await page.addStyleTag({ content: '.editor-toast, .toast { display: none !important; }' });
    if (targets.length) await mark(page, targets);
    await page.screenshot({ path: path.join(out, name + '.png'), animations: 'disabled', clip });
    await unmark(page);
    console.log('  ' + name);
}

async function login(page) {
    await page.goto(base + '/admin/index.php');
    await page.locator('#username').fill('admin');
    await page.locator('#password').fill(password);
    await Promise.all([
        page.waitForURL('**/admin/dashboard.php**'),
        page.locator('form').first().locator('button[type=submit]').click()
    ]);
    await page.waitForLoadState('networkidle');
}

async function dashboard(page, hash, ready) {
    await page.goto(base + '/admin/dashboard.php#' + hash);
    await page.waitForLoadState('networkidle');
    if (ready) await page.locator(ready).first().waitFor({ state: 'visible', timeout: 10000 });
    await sleep(400);
}

async function frontend(page, url, edit) {
    await page.goto(base + url);
    await page.waitForLoadState('networkidle');
    await page.locator('#admin-bar').waitFor();
    if (edit) {
        await page.locator('#admin-btn-edit:not([disabled])').click();
        await page.locator('body.edit-mode-active').waitFor();
        await sleep(400);
    }
}

// Every shot: name and how to get there. Names stay stable; chapters refer to them.
const SHOTS = [
    ['login', async ({ guest }) => {
        await guest.goto(base + '/admin/index.php');
        await guest.waitForLoadState('networkidle');
        await shot(guest, 'login', ['#username', '#password']);
    }],
    ['dashboard', async ({ page }) => {
        await dashboard(page, 'home', '.sidebar-nav');
        await shot(page, 'dashboard', ['.admin-sidebar .sidebar-nav', '.topbar-handbook']);
    }],
    ['adminbar', async ({ page }) => {
        await frontend(page, '/', false);
        await page.locator('#admin-btn-edit:not([disabled])').waitFor();
        await shot(page, 'adminbar', ['#admin-btn-edit', '.admin-bar-link--handbook'], TOP(420));
    }],
    ['edit-mode', async ({ page }) => {
        await frontend(page, '/', true);
        const text = page.locator('.editable-field-html').first();
        await text.scrollIntoViewIfNeeded();
        await text.hover();
        await sleep(300);
        await shot(page, 'edit-mode', ['#admin-btn-save', '#admin-btn-save-exit'], TOP(620));
    }],
    ['text-toolbar', async ({ page }) => {
        await frontend(page, '/', true);
        const text = page.locator('.editable-field-html').first();
        await text.scrollIntoViewIfNeeded();
        await text.click();
        await page.locator('.floating-toolbar').first().waitFor({ state: 'visible' });
        await sleep(300);
        const box = await text.boundingBox();
        await shot(page, 'text-toolbar', ['.floating-toolbar'], { x: 0, y: Math.max(0, box.y - 150), width: 1440, height: 340 });
    }],
    ['undo', async ({ page }) => {
        await frontend(page, '/', true);
        const title = page.locator('[data-field$=".text"].editable-field').first();
        await title.click();
        // Caret to the end of the heading, whatever the click selected
        await title.evaluate(element => {
            const range = document.createRange();
            range.selectNodeContents(element);
            range.collapse(false);
            const selection = getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        });
        await page.keyboard.type(lang === 'de' ? ' – neu' : ' – new');
        await page.locator('body').click({ position: { x: 20, y: 600 } });
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.mouse.move(1400, 880);
        await sleep(300);
        await shot(page, 'undo', ['#admin-btn-undo', '#admin-btn-cancel', '#admin-btn-save', '#admin-btn-save-exit'], TOP(420));
    }],
    ['sections', async ({ page }) => {
        await frontend(page, '/', true);
        const section = page.locator('.editable-section[data-section-type="image"]').first();
        await section.scrollIntoViewIfNeeded();
        await section.hover({ position: { x: 120, y: 120 } });
        await page.locator('.editable-section.is-overlay-active .overlay-btn').first().waitFor({ state: 'visible' });
        await sleep(300);
        await shot(page, 'sections', ['.editable-section.is-overlay-active .editable-overlay']);
    }],
    ['link-editor', async ({ page }) => {
        await frontend(page, '/components', true);
        const link = page.locator('a[data-editable-link]').first();
        await link.scrollIntoViewIfNeeded();
        await link.click();
        await sleep(800);
        await shot(page, 'link-editor', [], await around(page, '#link-editor-modal .editor-modal-content', 40));
    }],
    ['image-manager', async ({ page }) => {
        await frontend(page, '/', true);
        const image = page.locator('img[data-editable-image]').first();
        await image.scrollIntoViewIfNeeded();
        await image.click();
        await page.locator('#nb-imgmgr-modal.active').waitFor({ state: 'visible', timeout: 10000 });
        await sleep(800);
        await shot(page, 'image-manager', [], await around(page, '#nb-imgmgr-modal.active .nb-imgmgr-dialog', 24));
    }],
    ['pages', async ({ page }) => {
        await dashboard(page, 'content', '.page-list, #pageList, table');
        const row = page.locator('tbody tr').first();
        await row.hover();
        await sleep(300);
        await shot(page, 'pages', [row]);
    }],
    ['page-editor', async ({ page }) => {
        await dashboard(page, 'page/' + lang + '_home', '#adminMain');
        await sleep(800);
        await shot(page, 'page-editor');
    }],
    ['media', async ({ page }) => {
        await dashboard(page, 'media', '#adminMain');
        await sleep(800);
        await shot(page, 'media');
    }],
    ['menus', async ({ page }) => {
        await dashboard(page, 'menus', '#mfMenus .mf-panel');
        await shot(page, 'menus');
    }],
    ['news', async ({ page }) => {
        await dashboard(page, 'news', '#adminMain');
        await sleep(600);
        await shot(page, 'news');
    }],
    ['events', async ({ page }) => {
        await dashboard(page, 'events', '#adminMain');
        await sleep(600);
        await shot(page, 'events');
    }],
    ['people', async ({ page }) => {
        await dashboard(page, 'people', '#adminMain');
        await sleep(600);
        await shot(page, 'people');
    }],
    ['messages', async ({ page }) => {
        await dashboard(page, 'mails', '#adminMain');
        await sleep(600);
        await shot(page, 'messages');
    }],
    ['icons', async ({ page }) => {
        await dashboard(page, 'icons', '#iconManagerGrid');
        await sleep(600);
        await shot(page, 'icons');
    }],
    ['settings-design', async ({ page }) => {
        await dashboard(page, 'settings/theme', '#settingsPanel-theme');
        await shot(page, 'settings-design');
    }],
    ['settings-modules', async ({ page }) => {
        await dashboard(page, 'settings/modules', '#settingsPanel-modules');
        await shot(page, 'settings-modules', ['#moduleHandbook + .toggle-slider']);
    }],
    ['settings-ai', async ({ page }) => {
        await dashboard(page, 'settings/ai', '#settingsPanel-ai');
        await shot(page, 'settings-ai');
    }],
    ['users', async ({ page }) => {
        await dashboard(page, 'settings/users', '#settingsPanel-users');
        await shot(page, 'users');
    }],
    ['my-account', async ({ page }) => {
        await dashboard(page, 'settings/my-account', '#settingsPanel-my-account');
        await shot(page, 'my-account');
    }],
    ['backups', async ({ page }) => {
        await dashboard(page, 'backup', '#adminMain');
        await sleep(600);
        await shot(page, 'backups');
    }],
    ['system', async ({ page }) => {
        await dashboard(page, 'system', '#adminMain');
        await sleep(800);
        await shot(page, 'system');
    }],
];

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const options = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.25, colorScheme: 'light', locale: lang };
        const setupContext = await browser.newContext(options);
        await setup(setupContext.request);
        await setupContext.close();

        const guestContext = await browser.newContext(options);
        const guest = await guestContext.newPage();
        const context = await browser.newContext(options);
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await login(page);

        console.log(lang + ':');
        const failed = [];
        for (const [name, take] of SHOTS) {
            if (only && !name.includes(only)) continue;
            try {
                await take({ page, guest });
            } catch (error) {
                failed.push(name);
                console.error('  ' + name + ' FAILED: ' + error.message.split('\n')[0]);
            }
        }
        if (errors.length) console.warn('Page errors:\n' + errors.join('\n'));
        if (failed.length) process.exitCode = 1;
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
