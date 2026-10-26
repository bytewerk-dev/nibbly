const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.env.REVIEW_URL;
const password = process.env.REVIEW_PASSWORD;
const shots = process.env.REVIEW_SCREENSHOTS;
const siteRoot = process.env.REVIEW_SITE;
const errors = [];

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

async function openPageList(browser) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/admin/index.php');
    await page.locator('#username').fill('admin');
    await page.locator('#password').fill(password);
    await Promise.all([page.waitForURL('**/admin/dashboard.php'), page.locator('form').first().locator('button[type=submit]').click()]);
    await page.locator('.sidebar-nav-item[data-tab="content"]').click();
    await page.locator('#pageListBody .page-list-row').first().waitFor({ state: 'visible', timeout: 20000 });
    return { context, page };
}

const rows = page => page.locator('#pageListBody .page-list-row');

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        let { context, page } = await openPageList(browser);
        // The view language is German (the site's primary language): one row per page, each with its own German path
        await page.click('#pageFilterLinks [data-filter-value="de"]');
        assert(await rows(page).count() === 4, 'four pages, one row each: ' + await rows(page).count());
        const slugs = await page.locator('#pageListBody .page-list-slug').allTextContents();
        assert(slugs.includes('/leistungen') && slugs.includes('/ueber-uns') && !slugs.includes('/services'), 'the German paths are shown: ' + slugs);
        const leistungen = rows(page).filter({ hasText: 'Leistungen' });
        // The English column offers "Edit" because the version exists under another path
        const editEnglish = leistungen.locator('.page-list-lang-btn');
        assert(await editEnglish.count() === 1, '"Services" is the English version of "Leistungen", not a missing page');
        await page.screenshot({ path: path.join(shots, 'page-list-de.png') });
        await editEnglish.click();
        await page.locator('#editorContainer').waitFor({ state: 'visible', timeout: 20000 });
        assert(await page.locator('#langSelect').inputValue() === 'en' && await page.locator('#pageSelect').inputValue() === 'services', 'the English editor opens under its own path');
        assert((await page.locator('#editorTitle').textContent()).trim() === 'Services', 'with the English title: ' + await page.locator('#editorTitle').textContent());
        assert(page.url().includes('#page/en_services'), 'the editor link names the English version: ' + page.url());
        assert((await page.locator('#editorViewBtn').getAttribute('href')) === '../en/services', 'the view link is the public English URL: ' + await page.locator('#editorViewBtn').getAttribute('href'));
        await page.screenshot({ path: path.join(shots, 'editor-en-services.png') });

        // The language switch under the heading follows the page: German leads to "leistungen", English back to "services"
        const switchTo = async (lang, title) => {
            await page.locator(`#editorContainer .nb-lang-switch input[value="${lang}"]`).locator('xpath=..').click();
            await page.waitForFunction(text => document.getElementById('editorTitle').textContent.trim() === text, title, { timeout: 15000 });
        };
        await switchTo('de', 'Leistungen');
        assert(await page.locator('#langSelect').inputValue() === 'de' && await page.locator('#pageSelect').inputValue() === 'leistungen', 'switching to German opens the German version: ' + await page.locator('#pageSelect').inputValue());
        await switchTo('en', 'Services');
        assert(await page.locator('#pageSelect').inputValue() === 'services', 'and back to English');
        assert(!(await page.locator('#topbarSelectors').isVisible()), 'the old language and page selects in the top bar are gone');

        // Back in the list: the missing English version of "Über uns" is offered with the mapped path
        await page.evaluate(() => showPageList());
        await page.locator('#pageListBody .page-list-row').first().waitFor({ state: 'visible' });
        const about = rows(page).filter({ hasText: 'Über uns' });
        // Columns beside German: English, Spanish (a third language the site has but no page uses yet)
        const create = about.locator('.page-list-cell-lang').nth(0).locator('.page-list-create-link');
        assert(await create.count() === 1, 'a missing translation offers "create"');
        await create.click();
        await about.locator('.page-list-cell-lang').nth(0).locator('.page-list-lang-btn').waitFor({ state: 'visible', timeout: 15000 });
        assert(fs.existsSync(path.join(siteRoot, 'content/pages/en_about-us.json')), 'the copy is stored under the path the mapping intends');
        assert(!fs.existsSync(path.join(siteRoot, 'content/pages/en_ueber-uns.json')), 'not under the German path');
        assert(await rows(page).count() === 4, 'creating the translation does not add a row');
        await page.screenshot({ path: path.join(shots, 'page-list-after-create.png') });

        // English view: same four pages, English paths, Edit leads to the German versions
        await page.click('#pageFilterLinks [data-filter-value="en"]');
        assert(await rows(page).count() === 4, 'four rows in English too');
        const englishSlugs = await page.locator('#pageListBody .page-list-slug').allTextContents();
        assert(englishSlugs.includes('/services') && englishSlugs.includes('/about-us') && !englishSlugs.includes('/leistungen'), 'the English paths are shown: ' + englishSlugs);
        await rows(page).filter({ hasText: 'Services' }).locator('.page-list-lang-btn').click();
        await page.locator('#editorContainer').waitFor({ state: 'visible' });
        assert(await page.locator('#langSelect').inputValue() === 'de' && await page.locator('#pageSelect').inputValue() === 'leistungen', 'the German version opens from the English list');
        await context.close();

        // A direct editor link works with the version's own path
        ({ context, page } = await openPageList(browser));
        await page.goto(base + '/admin/dashboard.php#page/en_services');
        await page.locator('#editorContainer').waitFor({ state: 'visible', timeout: 20000 });
        assert(await page.locator('#langSelect').inputValue() === 'en' && await page.locator('#pageSelect').inputValue() === 'services', 'direct link: English version');
        await page.goto(base + '/admin/dashboard.php#page/de_leistungen');
        await page.reload();
        await page.locator('#editorContainer').waitFor({ state: 'visible', timeout: 20000 });
        assert(await page.locator('#langSelect').inputValue() === 'de' && await page.locator('#pageSelect').inputValue() === 'leistungen', 'direct link: German version');
        assert(!errors.length, 'no script errors: ' + errors.join('; '));
        await context.close();
        console.log('page-list-check ok');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exit(1);
});
