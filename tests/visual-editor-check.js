const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const assert = require('node:assert');

const base = process.env.REVIEW_URL;
const shots = process.env.REVIEW_SCREENSHOTS;
const stored = file => JSON.parse(readFileSync(process.env.REVIEW_ROOT + '/content/' + file, 'utf8'));
const pageFile = () => stored('pages/en_services__test.json');
const postFile = () => stored('news/2026-01-02-block-post.json');
const step = name => console.log('  ' + name);

// The session keepalive stays open, so "networkidle" never comes in edit mode: watch the stored file
async function until(condition, message) {
    for (let i = 0; i < 50; i++) {
        if (condition()) return;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw Error('Timed out: ' + message);
}

// Class names of a bar's controls, in order, reduced to what each one is
const barOrder = bar => bar.evaluate(el => [...el.children].map(c =>
    /overlay-btn-edit/.test(c.className) ? 'edit' : /overlay-btn-hide/.test(c.className) ? 'hide'
        : /overlay-btn-delete/.test(c.className) ? 'delete' : /drag-handle/.test(c.className) ? 'drag' : 'tool'));

async function editMode(page, url) {
    await page.goto(base + url);
    await page.locator('#admin-btn-edit').click();
    await page.waitForFunction(() => document.body.classList.contains('edit-mode-active'));
}

// Structural changes save and reload in edit mode; wait until the new page has come up
async function afterReload(page, action) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), action()]);
    await page.waitForFunction(() => document.body.classList.contains('edit-mode-active'));
}

// Clear of the fixed admin bar and of the "+" in the middle of a block's lower edge
const clickInView = async locator => {
    await locator.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await locator.click({ position: { x: 12, y: 6 } });
};

async function placeCaret(page, locator, offsetParagraph = 0) {
    await clickInView(locator);
    await page.evaluate(([index]) => {
        const field = document.activeElement.closest('.editable-field-html') || document.activeElement;
        const p = field.querySelectorAll('p')[index];
        getSelection().setBaseAndExtent(p.firstChild, 3, p.firstChild, 3); // removeAllRanges() would blur the field
    }, [offsetParagraph]);
}

async function selectWord(page, locator, word) {
    await clickInView(locator);
    await page.evaluate(([text]) => {
        const field = document.activeElement.closest('.editable-field-html') || document.activeElement;
        const walker = document.createTreeWalker(field, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            const at = node.data.indexOf(text);
            if (at < 0) continue;
            getSelection().setBaseAndExtent(node, at, node, at + text.length);
            field.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
            return;
        }
        throw Error('word not found: ' + text);
    }, [word]);
}

// Real pointer moves from a handle to the top edge of a target. The tall viewport keeps both on
// screen at the top of the page, clear of the fixed admin bar.
async function dragHandle(page, block, handle, target) {
    await page.setViewportSize({ width: 1280, height: 2400 });
    await page.evaluate(() => window.scrollTo(0, 0));
    await block.hover({ position: { x: 8, y: 8 } }); // the bar only shows on hover
    await handle.hover();
    await page.waitForTimeout(400); // the bar fades in and late images still shift the layout
    const from = await handle.boundingBox();
    const to = await target.boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2 + 12, { steps: 4 });
    await page.mouse.move(to.x + 30, to.y + 3, { steps: 12 });
    await page.mouse.move(to.x + 30, to.y + 2, { steps: 2 });
    await page.mouse.up();
    await page.setViewportSize({ width: 1280, height: 1000 });
}

const boxesOverlap = (a, b) => a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;

(async () => {
    const browser = await chromium.launch({ headless: true });
    const errors = [];
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => dialog.accept());
        await page.goto(base + '/admin/index.php');
        await page.locator('#username').fill('admin');
        await page.locator('#password').fill(process.env.REVIEW_PASSWORD);
        await Promise.all([page.waitForURL('**/admin/dashboard.php'), page.locator('form').first().locator('button[type=submit]').click()]);

        // ---- Content editor and comment list in the dashboard ----
        step('dashboard: page with its own template in the writing view');
        await page.waitForLoadState('load');
        await page.evaluate(() => switchTab('content'));
        await page.waitForSelector('#pageSelect option[value="custom"]', { state: 'attached' });
        await page.evaluate(() => openPageFromList('en', 'custom'));
        await page.waitForSelector('#editorContainer.nb-writing-view .nb-page-rich-editor', { state: 'attached' });
        const expandLabel = await page.locator('#groupToggleAll').textContent();
        await page.locator('#groupToggleAll').click();
        assert.notStrictEqual(await page.locator('#groupToggleAll').textContent(), expandLabel, 'the link names the other state');
        const rich = page.locator('.ce-group .nb-page-rich-editor');
        await rich.waitFor({ state: 'visible' }); // strict: only the field the template outputs as HTML is rich
        assert.ok((await rich.innerHTML()).includes('<b>text</b>') || (await rich.innerHTML()).includes('<strong>text</strong>'));
        const buttons = await rich.evaluate(el => [...el.parentElement.querySelectorAll('button')].map(b => b.dataset.command || b.dataset.action || ''));
        assert.ok(buttons.indexOf('footnote') === buttons.indexOf('removeFormat') - 1, 'footnote before the eraser: ' + buttons);
        assert.ok(buttons.includes('unlink'), 'unlink in the dashboard toolbar: ' + buttons);
        await page.waitForTimeout(300);
        await page.screenshot({ path: shots + '/content-custom-template.png' });

        step('dashboard: section list collapses and stays collapsed');
        await page.locator('.ce-section-nav-toggle--collapse').click();
        assert.ok(!(await page.locator('.ce-section-nav').isVisible()), 'navigation collapsed');
        assert.ok(await page.locator('.ce-section-nav-toggle--expand').isVisible(), 'button to bring it back');
        await page.evaluate(() => openPageFromList('en', 'home'));
        await page.waitForSelector('#editorViewBtn');
        await page.waitForFunction(() => /\/\.\.\/$|^\.\.\/$/.test(document.getElementById('editorViewBtn').getAttribute('href')));
        await page.evaluate(() => localStorage.removeItem('nibblySectionNavCollapsed'));

        step('dashboard: comments grouped by post');
        await page.evaluate(() => switchTab('comments'));
        await page.waitForSelector('#commentsGroups table');
        assert.strictEqual(await page.locator('#commentsGroups table').count(), 1, 'one table per post');
        assert.strictEqual(await page.locator('#commentsGroups a.nb-comment-table__title').textContent(), 'Block post');
        await page.locator('.nb-comment-admin--clamped .nb-comment-admin__more').first().click();
        assert.ok(await page.locator('.nb-comment-admin--open').count() === 1, 'long comment opens');
        await page.screenshot({ path: shots + '/comments.png' });

        step('dashboard: sidebar colour follows the preset, top bar hover stays readable');
        const sidebarColour = preset => page.evaluate(p => sanitizeThemeContrast({ preset: p, sidebarBg: '#102a5c' }).theme.sidebarBg, preset);
        assert.strictEqual(await sidebarColour('pop'), '#102a5c', 'dark colour stays with light sidebar text');
        assert.strictEqual(await sidebarColour('classic'), '#102a5c');
        assert.notStrictEqual(await sidebarColour('soft'), '#102a5c', 'lightened under dark sidebar text');
        await page.locator('.topbar-viewsite').hover();
        await page.waitForTimeout(300);
        assert.strictEqual(await page.locator('.topbar-viewsite').evaluate(el => getComputedStyle(el).color), 'rgb(255, 255, 255)', 'white on the dark top bar of Pop');

        // ---- Page sections ----
        step('page: bars of text, image and gallery sections');
        await editMode(page, '/services/test/');
        const sections = page.locator('.editable-section');
        assert.strictEqual(await sections.count(), 5);
        const bar = i => sections.nth(i).locator(':scope > .nibbly-editor-section-overlay');
        await sections.nth(0).hover();
        assert.deepStrictEqual(await barOrder(bar(0)), ['edit', 'hide', 'tool', 'tool', 'tool', 'delete', 'drag']);
        await page.screenshot({ path: shots + '/page-text-bar.png' });
        await sections.nth(2).hover();
        assert.deepStrictEqual(await barOrder(bar(2)), ['edit', 'hide', 'tool', 'tool', 'tool', 'delete', 'drag'], 'image section');
        assert.strictEqual(await sections.nth(2).locator(':scope > figure[data-news-block="sections.2"]').count(), 1, 'image hook');
        await page.screenshot({ path: shots + '/page-image-bar.png' });
        await sections.nth(3).hover();
        assert.strictEqual(await bar(3).locator('.overlay-btn-edit').count(), 0, 'gallery has no fields for the dialog');
        assert.strictEqual(await sections.nth(3).locator(':scope > .block-gallery[data-news-block="sections.3"]').count(), 1, 'gallery hook');
        await page.screenshot({ path: shots + '/page-gallery-bar.png' });

        step('page: frames follow the colour rule');
        const blockColour = await page.evaluate(() => getComputedStyle(document.querySelector('.news-block-tools, .nibbly-editor-section-overlay')).backgroundColor);
        assert.notStrictEqual(blockColour, 'rgba(0, 0, 0, 0)', 'section bar is coloured');

        step('page: text toolbar starts at the field and does not cover the block bar');
        const field = sections.nth(0).locator('.editable-field-html');
        await placeCaret(page, field, 1);
        const toolbar = page.locator('.floating-toolbar');
        await toolbar.waitFor({ state: 'visible' });
        const toolbarBox = await toolbar.boundingBox();
        const fieldBox = await field.boundingBox();
        assert.ok(Math.abs(toolbarBox.x - fieldBox.x) < 2, `toolbar at ${toolbarBox.x}, field at ${fieldBox.x}`);
        const barBox = await bar(0).boundingBox();
        if (barBox) assert.ok(!boxesOverlap(toolbarBox, barBox), 'text toolbar overlaps the block bar');
        assert.ok(await toolbar.locator('[data-action="split"]').isVisible(), 'split button on a page');
        assert.ok(await toolbar.locator('[data-action="style-menu"]').isVisible(), 'style menu on a page');
        assert.ok(await toolbar.locator('[data-action="unlink"]').isVisible(), 'unlink button');
        await page.screenshot({ path: shots + '/page-text-toolbar.png' });

        step('page: link dialog keeps the field active and inserts the link');
        await selectWord(page, field, 'Paragraph');
        await toolbar.locator('[data-action="link"]').click();
        const linkModal = page.locator('#link-editor-modal');
        await linkModal.waitFor({ state: 'visible' });
        assert.ok(await field.evaluate(el => el.isContentEditable), 'field stays editable behind the dialog');
        assert.strictEqual(await page.locator('#link-editor-text').inputValue(), 'Paragraph');
        await page.locator('#link-editor-href').fill('https://example.com/a');
        await linkModal.locator('.editor-btn-primary').click();
        await linkModal.waitFor({ state: 'hidden' });
        await page.waitForTimeout(300);
        assert.strictEqual(await field.locator('a[href="https://example.com/a"]').textContent(), 'Paragraph');
        assert.ok(await field.evaluate(el => el.isContentEditable), 'field stays in edit mode after the dialog');
        assert.ok(await toolbar.isVisible(), 'text toolbar stays after the dialog');

        step('page: existing link loads into the dialog, an empty target removes it');
        await selectWord(page, field, 'Paragraph');
        await toolbar.locator('[data-action="link"]').click();
        await linkModal.waitFor({ state: 'visible' });
        assert.strictEqual(await page.locator('#link-editor-href').inputValue(), 'https://example.com/a');
        await page.locator('#link-editor-href').fill('');
        await linkModal.locator('.editor-btn-primary').click();
        await linkModal.waitFor({ state: 'hidden' });
        await page.waitForTimeout(300);
        assert.strictEqual(await field.locator('a').count(), 0, 'empty target removes the link');

        step('page: unlink with only the caret in the link');
        await selectWord(page, field, 'number');
        await toolbar.locator('[data-action="link"]').click();
        await linkModal.waitFor({ state: 'visible' });
        await page.locator('#link-editor-href').fill('/internal');
        await linkModal.locator('.editor-btn-primary').click();
        await linkModal.waitFor({ state: 'hidden' });
        await page.waitForTimeout(300);
        await page.evaluate(() => {
            const a = document.querySelector('.editable-field-html a');
            getSelection().setBaseAndExtent(a.firstChild, 2, a.firstChild, 2);
        });
        await toolbar.locator('[data-action="unlink"]').click();
        assert.strictEqual(await field.locator('a').count(), 0, 'caret unlink');

        step('page: a saved link keeps the target from the dialog and download');
        await selectWord(page, field, 'enough');
        await toolbar.locator('[data-action="link"]').click();
        await linkModal.waitFor({ state: 'visible' });
        await page.locator('#link-editor-href').fill('https://example.org/file.pdf');
        await page.locator('#link-editor-download').check({ force: true });
        await linkModal.locator('.editor-btn-primary').click();
        await linkModal.waitFor({ state: 'hidden' });
        await page.waitForTimeout(300);
        await page.locator('#admin-btn-save').click();
        await until(() => pageFile().sections[0].content.includes('example.org'), 'link stored');
        const stored = /<a [^>]*example\.org[^>]*>/.exec(pageFile().sections[0].content)[0];
        assert.ok(stored.includes('target="_self"'), 'no new tab chosen: ' + stored);
        assert.ok(stored.includes('download'), 'download kept: ' + stored);
        await page.waitForFunction(() => !document.querySelector('#admin-btn-save.has-changes') && !document.querySelector('#admin-btn-save').disabled);
        await page.waitForTimeout(300);

        step('page: split, merge, style');
        await placeCaret(page, field, 1);
        await afterReload(page, () => toolbar.locator('[data-action="split"]').click());
        assert.strictEqual(pageFile().sections.length, 6, 'split adds a section');
        assert.ok(pageFile().sections[1].content.includes('number 2'), 'second part holds the rest');
        await sections.nth(0).hover();
        await afterReload(page, () => bar(0).locator('.overlay-btn').nth(4).click());
        assert.strictEqual(pageFile().sections.length, 5, 'merge removes a section');
        assert.ok(pageFile().sections[0].content.includes('number 3'), 'merged text kept');
        await placeCaret(page, sections.nth(1).locator('.editable-field-html'), 0);
        await toolbar.locator('[data-action="style-menu"]').click();
        await afterReload(page, () => toolbar.locator('.floating-toolbar-style-menu [data-style="highlight"]').click());
        assert.strictEqual(pageFile().sections[1].style, 'highlight', 'block style stored');

        step('page: image dialog knows credit, half width and alignment');
        await sections.nth(2).hover();
        await bar(2).locator('.overlay-btn-edit').click();
        await page.locator('#editor-input-credit-input').waitFor({ state: 'visible' });
        await page.locator('#editor-input-credit-input').fill('Photographer');
        await page.locator('#editor-input-width-select').selectOption('half');
        await page.locator('#editor-input-align-select').selectOption('left');
        await page.waitForTimeout(400);
        await page.screenshot({ path: shots + '/page-image-dialog.png' });
        await afterReload(page, () => page.locator('#editor-modal .editor-btn-primary').last().click());
        const image = pageFile().sections[2];
        assert.deepStrictEqual([image.credit, image.width, image.align, image.src], ['Photographer', 'half', 'left', '/assets/images/one.png']);

        step('page: caption typed in place reaches the save');
        const caption = sections.nth(2).locator('figcaption');
        await clickInView(caption);
        await page.keyboard.press('End');
        await page.keyboard.type(' more');
        await page.locator('#admin-btn-save').click();
        await page.waitForFunction(() => !document.querySelector('#admin-btn-save.has-changes'));
        assert.ok(pageFile().sections[2].caption.includes('more'), 'caption saved');

        step('page: dragging a section renumbers the hooks');
        await dragHandle(page, sections.nth(2), bar(2).locator('.overlay-drag-handle'), sections.nth(0));
        await page.waitForFunction(() => document.querySelector('.editable-section[data-section-index="0"] [data-news-block="sections.0"]'), null, { timeout: 5000 });
        // Page reorders are batched until "Save", unlike the post blocks below
        await page.locator('#admin-btn-save').click();
        await until(() => pageFile().sections[0].type === 'image', 'image moved to the top');
        assert.ok(pageFile().sections[0].caption.includes('more'), 'image keeps its caption');

        // ---- Post blocks ----
        step('post: same bar with edit, hide, handle');
        await editMode(page, '/news/block-post');
        const blocks = page.locator('[data-news-block^="sections."]');
        assert.strictEqual(await blocks.count(), 4);
        const tools = i => blocks.nth(i).locator(':scope > .news-block-tools');
        await blocks.nth(1).hover();
        assert.deepStrictEqual(await barOrder(tools(1)), ['edit', 'hide', 'tool', 'tool', 'tool', 'delete', 'drag']);
        const toolsBox = await tools(1).boundingBox();
        const blockBox = await blocks.nth(1).boundingBox();
        assert.ok(toolsBox.y + toolsBox.height <= blockBox.y + 4, 'post bar sits above the block');
        assert.ok(Math.abs(toolsBox.x + toolsBox.width - (blockBox.x + blockBox.width)) < 2, 'post bar flush right');
        await page.waitForTimeout(600); // outlines and bars fade in
        await page.screenshot({ path: shots + '/post-block-bar.png' });

        step('post: moving the pointer from the block to its bar keeps the bar');
        await page.mouse.move(toolsBox.x + toolsBox.width / 2, toolsBox.y + toolsBox.height / 2, { steps: 8 });
        assert.ok(await tools(1).evaluate(el => getComputedStyle(el).visibility === 'visible'), 'bar stays while crossing');

        step('post: edit dialog');
        await tools(1).locator('.overlay-btn-edit').click();
        await page.locator('#editor-modal.active, #editor-modal').first().waitFor({ state: 'visible' });
        await page.waitForTimeout(400);
        await page.screenshot({ path: shots + '/post-block-dialog.png' });
        assert.strictEqual(await page.locator('#editor-input-title-input').inputValue(), '', 'an existing block opens without registry defaults');
        await page.locator('#editor-input-title-input').fill('New title');
        await afterReload(page, () => page.locator('#editor-modal .editor-btn-primary').last().click());
        assert.strictEqual(postFile().sections[1].title, 'New title');
        assert.ok(postFile().sections[1].content.includes('Beta'), 'content survives the dialog');

        step('post: hide and show');
        await blocks.nth(1).hover();
        await afterReload(page, () => tools(1).locator('.overlay-btn-hide').click());
        assert.strictEqual(postFile().sections[1].hidden, true);
        assert.strictEqual(await blocks.count(), 4, 'hidden block stays in the editor');
        assert.strictEqual(await blocks.nth(1).getAttribute('data-hidden'), 'true');
        await page.waitForTimeout(600);
        await page.screenshot({ path: shots + '/post-block-hidden.png' });
        const guest = await browser.newPage();
        await guest.goto(base + '/news/block-post');
        const guestHtml = await guest.content();
        assert.ok(!guestHtml.includes('Beta block'), 'visitors do not get a hidden block');
        assert.ok(guestHtml.includes('Alpha block'), 'visitors get the others');
        assert.ok(!guestHtml.includes('data-news-block'), 'visitors get no hooks');
        await guest.close();
        await blocks.nth(1).hover();
        await afterReload(page, () => tools(1).locator('.overlay-btn-hide').click());
        assert.strictEqual(postFile().sections[1].hidden, undefined, 'shown again');

        step('post: drag handle moves a block and keeps sections and aside apart');
        await afterReload(page, () => dragHandle(page, blocks.nth(3), tools(3).locator('.overlay-drag-handle'), blocks.nth(0)));
        assert.deepStrictEqual(postFile().sections.map(s => s.id), ['d', 'a', 'b', 'c'], 'block dragged to the top');
        const asideBlock = page.locator('[data-news-block^="aside."]').first();
        await dragHandle(page, blocks.nth(0), tools(0).locator('.overlay-drag-handle'), asideBlock);
        await page.waitForTimeout(500);
        assert.deepStrictEqual(postFile().sections.map(s => s.id), ['d', 'a', 'b', 'c'], 'dropping on the aside does nothing');
        assert.deepStrictEqual(postFile().aside.map(s => s.id), ['s']);

        assert.deepStrictEqual(errors, [], 'page errors');
        console.log('PASS visual editor check');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
