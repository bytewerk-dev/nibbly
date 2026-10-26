/* Regression: the admin bar keeps coloured site icons and draws single-colour logos in the primary colour (as a mask). */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../js/inline-editor.js'), 'utf8');
const match = source.match(/    function markAdminBarLogo\([^]*?\n    }\n/);
assert.ok(match, 'markAdminBarLogo');
assert.ok(!/isDefaultFavicon/.test(source), 'the admin bar no longer decides by path alone');

// Fake 24×24 logo: pixels(i) returns [r, g, b, a] for pixel index i
function run(pixels, src, { throws = false } = {}) {
    const data = new Uint8ClampedArray(24 * 24 * 4);
    for (let i = 0; i < 24 * 24; i++) data.set(pixels(i), i * 4);
    const classes = new Set();
    const styles = {};
    const img = { complete: true, naturalWidth: 24, currentSrc: '', style: { setProperty: (name, value) => { styles[name] = value; } }, classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) } };
    const context = vm.createContext({
        document: { createElement: () => ({ getContext: () => ({ drawImage() {}, getImageData: () => { if (throws) throw new Error('tainted'); return { data }; } }) }) }
    });
    vm.runInContext(match[0] + '\nmarkAdminBarLogo(img, src);', Object.assign(context, { img, src }));
    const mono = classes.has('admin-bar-logo-icon--default');
    if (mono) assert.equal(styles['--ab-logo-mask'], 'url("' + src + '")', 'the logo file becomes the mask');
    return mono;
}
const transparent = [0, 0, 0, 0];
const defaultPath = '/assets/images/favicon.svg';

// Site icon at the default path: dark square, light letter, coloured dots → keeps its colours
assert.equal(run(i => (i % 24 < 8 ? [27, 42, 58, 255] : i % 24 < 16 ? [244, 239, 230, 255] : [232, 137, 108, 255]), defaultPath), false, 'multi-coloured icons stay in colour');
// Default Nibbly icon: dark glyph on transparent background → primary colour
assert.equal(run(i => (i % 3 ? [10, 10, 10, 255] : transparent), defaultPath), true, 'dark single-colour logos take the primary colour');
// Light single-colour logo (e.g. dark-mode variant) would vanish on a light bar → primary colour too
assert.equal(run(i => (i % 3 ? [229, 229, 229, 255] : transparent), '/assets/images/logo.svg'), true, 'light single-colour logos take the primary colour');
// Unreadable pixels (cross-origin logo): fall back to the path
assert.equal(run(() => transparent, defaultPath, { throws: true }), true, 'unreadable default path keeps the monochrome treatment');
assert.equal(run(() => transparent, 'https://cdn.example.org/logo.svg', { throws: true }), false, 'unreadable other logos stay unchanged');

console.log(JSON.stringify({ ok: true }));
