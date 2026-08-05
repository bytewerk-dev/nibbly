const { readFileSync } = require('fs');
const { dirname, resolve } = require('path');

const root = dirname(__dirname);
const source = readFileSync(resolve(root, 'js/image-manager.js'), 'utf8');
const styles = readFileSync(resolve(root, 'css/image-manager.css'), 'utf8');
const dashboard = readFileSync(resolve(root, 'admin/dashboard.php'), 'utf8');

function assertContains(haystack, needle, message) {
    if (!haystack.includes(needle)) {
        throw new Error(message);
    }
}

assertContains(source, 'function mediaDownloadActionHtml(item)', 'Media manager should render a per-image download action.');
assertContains(source, 'data-action="download"', 'Media manager image actions should include a download link.');
assertContains(source, 'download="', 'Media manager download action should use the browser download attribute.');
assertContains(source, 'class="nb-imgmgr-upload-input" accept=".jpg,.jpeg,.png,.webp" multiple', 'Media manager upload input should allow selecting multiple files.');
assertContains(source, 'function uploadFiles(files)', 'Media manager should route selected and dropped files through multi-file upload handling.');
assertContains(source, 'uploadFile(file, {', 'Media manager should upload multiple files through the existing single-file API.');
assertContains(source, 'itemsPerPage: 25', 'Media manager should default pagination to 25 entries.');
assertContains(source, 'function renderPagination()', 'Media manager should render pagination controls.');
assertContains(source, 'nb-imgmgr-pagination--top', 'Media manager should render top pagination only when page controls are needed.');
assertContains(source, 'function canOpenImageGenerator()', 'Media manager should gate the image generator button through config.');
assertContains(source, 'nb-imgmgr-ai-generator-btn', 'Media manager dropzone should include the optional image generator button.');
assertContains(source, "config.openImageGenerator('', 'auto')", 'Media manager image generator button should open the dashboard generator.');
assertContains(source, 'refresh: function ()', 'Media manager should expose a refresh hook for async AI settings updates.');
assertContains(source, 'function detectTransparency(image)', 'Media manager should inspect rendered images for actual transparency.');
assertContains(source, "context.getImageData(0, 0, canvas.width, canvas.height)", 'Media manager transparency detection should inspect rendered alpha values.');
assertContains(source, "surface.classList.toggle('nb-imgmgr-has-transparency', hasTransparency)", 'Media manager should mark only images with transparent pixels.');
const mediaDataAssignment = source.indexOf('state.data = items.map');
const folderCountRefresh = source.indexOf('updateFolderUI();', mediaDataAssignment);
if (mediaDataAssignment < 0 || folderCountRefresh < mediaDataAssignment) {
    throw new Error('Media manager folder counts should refresh after the current media data is assigned.');
}
assertContains(styles, '.nb-imgmgr-dropzone--with-ai', 'Media manager dropzone should adapt layout when the AI generator is visible.');
assertContains(styles, '.nb-imgmgr-ai-generator-btn', 'Media manager should style the image generator button.');
assertContains(styles, '.nb-imgmgr-pagination-btn', 'Media manager should style pagination buttons.');
assertContains(styles, '.nb-imgmgr-thumb.nb-imgmgr-has-transparency', 'Media manager should reserve the checkerboard for transparent image surfaces.');
assertContains(styles, 'background-color: var(--nb-bg-sunken, #f0f0f0);', 'Media manager should use a neutral default thumbnail background.');
assertContains(dashboard, 'canGenerateImages: function()', 'Dashboard should pass current AI image availability into the media manager.');
assertContains(dashboard, "openAiImageGenerator(prompt || '', aspectRatio || 'auto')", 'Dashboard should connect media manager to the existing image generator.');
assertContains(dashboard, 'settingsMediaItemsPerPage', 'Dashboard settings should expose a Media Library page-size field.');
assertContains(dashboard, 'NbImageManager.refresh();', 'Dashboard should refresh media manager controls when AI settings load.');

console.log(JSON.stringify({ ok: true }));
