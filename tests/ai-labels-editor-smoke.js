/* AI disclosure labels in the inline editor: labels follow image swaps and media library changes. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
const editor = fs.readFileSync(path.join(root, 'js/inline-editor.js'), 'utf8');
const manager = fs.readFileSync(path.join(root, 'js/image-manager.js'), 'utf8');
const footer = fs.readFileSync(path.join(root, 'includes/footer.php'), 'utf8');

function implementation(name) {
    const match = editor.match(new RegExp(`    function ${name}\\([^]*?\\n    }`));
    assert.ok(match, name);
    return match[0];
}
const listener = editor.match(/    document\.addEventListener\('nibbly:ai-label-change', [^]*?\n    }\);/);
assert.ok(listener, 'inline editor listens for label changes from the media library');

// Minimal DOM: nodes with a parent, siblings in document order
function node(parent, props = {}) {
    const n = Object.assign({ parent, className: '', attrs: {}, children: [] }, props);
    n.classList = { contains: cls => n.className.split(/\s+/).includes(cls) };
    n.getAttribute = name => (name in n.attrs ? n.attrs[name] : null);
    n.closest = selector => {
        for (let el = n; el; el = el.parent) if (el.classList.contains(selector.replace(/^\./, ''))) return el;
        return null;
    };
    Object.defineProperty(n, 'nextElementSibling', { get: () => n.parent.children[n.parent.children.indexOf(n) + 1] || null });
    n.remove = () => n.parent.children.splice(n.parent.children.indexOf(n), 1);
    n.insertAdjacentHTML = (where, html) => {
        assert.equal(where, 'afterend');
        const label = node(null, { className: html.match(/class="([^"]+)"/)[1], html });
        label.parent = n.parent;
        n.parent.children.splice(n.parent.children.indexOf(n) + 1, 0, label);
    };
    if (parent) parent.children.push(n);
    return n;
}
const labels = frame => frame.children.filter(child => child.classList.contains('nb-ai-label')).map(child => child.className.replace('nb-ai-label nb-ai-label--', ''));
const markup = kind => `<span class="nb-ai-label nb-ai-label--${kind}"><img class="nb-ai-label__badge" src="../css/ai-labels/de-${kind}.svg" alt=""></span>`;

const page = node(null);
const frameEditable = node(page, { className: 'frame' });
const wrapper = node(frameEditable, { className: 'editable-image-wrapper' });
const editableImg = node(wrapper, { attrs: { src: '/assets/images/a.webp', 'data-editable-image': '' } });
node(frameEditable, { className: 'nb-ai-label nb-ai-label--generated' });
const frameBound = node(page, { className: 'frame' });
const boundImg = node(frameBound, { attrs: { src: 'assets/images/photo.webp', 'data-nb-bind': 'image' } });
node(frameBound, { className: 'caption' });

const handlers = {};
const context = vm.createContext({
    URL,
    location: { href: 'http://localhost/de/seite/' },
    window: { NB_AI_LABELS: { files: { image: { 'a.webp': 'generated', 'team/b c.webp': 'modified' } }, markup: { generated: markup('generated'), modified: markup('modified') } } },
    document: {
        addEventListener: (type, handler) => { handlers[type] = handler; },
        querySelectorAll: selector => {
            assert.equal(selector, 'img[data-editable-image], img[data-nb-bind="image"]');
            return [editableImg, boundImg];
        }
    }
});
vm.runInContext(implementation('aiLabelKindForSrc') + '\n' + implementation('syncAiLabel') + '\n' + listener[0], context);
const { aiLabelKindForSrc, syncAiLabel } = context;

// Sources map to the stored label like nibblyMediaRefFromSrc() in PHP
assert.equal(aiLabelKindForSrc('/assets/images/a.webp'), 'generated');
assert.equal(aiLabelKindForSrc('../assets/images/team/b%20c.webp?v=2'), 'modified');
assert.equal(aiLabelKindForSrc('https://cdn.example.org/other.webp'), '');
assert.equal(aiLabelKindForSrc('/assets/images/constructor'), '', 'only own entries count as labels');

// Image swaps: remove, add after the editor wrapper, switch the kind
editableImg.attrs.src = '/assets/images/photo.webp';
syncAiLabel(editableImg);
assert.deepEqual(labels(frameEditable), [], 'swapping to an unlabelled image removes the label');
editableImg.attrs.src = '/assets/images/a.webp';
syncAiLabel(editableImg);
assert.deepEqual(labels(frameEditable), ['generated'], 'swapping to a labelled image adds the label');
assert.equal(frameEditable.children.indexOf(wrapper) + 1, frameEditable.children.findIndex(child => child.classList.contains('nb-ai-label')), 'the label follows the editor wrapper, not the image inside it');
editableImg.attrs.src = '/assets/images/team/b%20c.webp';
syncAiLabel(editableImg);
assert.deepEqual(labels(frameEditable), ['modified'], 'a different label kind replaces the label');
const labelNode = () => frameEditable.children.find(child => child.classList.contains('nb-ai-label'));
const current = labelNode();
syncAiLabel(editableImg);
assert.equal(labelNode(), current, 'an unchanged label stays in place, so refreshes do not make labels flicker');
assert.deepEqual(labels(frameEditable), ['modified']);

// Bound images (contentBindAttrs) get their label directly after the image
boundImg.attrs.src = 'assets/images/a.webp';
syncAiLabel(boundImg);
assert.deepEqual(frameBound.children.map(child => child === boundImg ? 'img' : child.className.split(' ').pop()), ['img', 'nb-ai-label--generated', 'caption']);

// Label changes in the media library update every image showing the file
boundImg.attrs.src = 'assets/images/photo.webp';
syncAiLabel(boundImg);
handlers['nibbly:ai-label-change']({ detail: { type: 'image', name: 'photo.webp', label: 'modified' } });
assert.deepEqual(labels(frameBound), ['modified'], 'a new label shows up right away');
handlers['nibbly:ai-label-change']({ detail: { type: 'image', name: 'team/b c.webp', label: '' } });
assert.deepEqual(labels(frameEditable), [], 'a removed label disappears right away');

// Pages without label data (older site footers) are left alone
context.window.NB_AI_LABELS = undefined;
syncAiLabel(boundImg);
assert.deepEqual(labels(frameBound), ['modified']);

// Wiring: editor data, image changes and media library events
assert.ok(footer.includes('window.NB_AI_LABELS = <?php echo json_encode(nibblyAiLabelEditorConfig('), 'footer passes label data to the editor');
assert.ok((editor.match(/syncAiLabel\((img|el|element|heroEl)\);/g) || []).length >= 5, 'editor syncs labels wherever it changes an image');
assert.ok((manager.match(/announceAiLabel\(/g) || []).length >= 4, 'media library announces label changes, detections and replacements');

console.log(JSON.stringify({ ok: true }));
