const { readFileSync, readdirSync } = require('node:fs');
const { resolve } = require('node:path');
const root = resolve(__dirname, '..');

function source(path) { return readFileSync(resolve(root, path), 'utf8'); }
function folderSources(path) {
    return readdirSync(resolve(root, path), { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))
        .map(entry => entry.isDirectory() ? folderSources(path + '/' + entry.name)
            : /\.(php|js)$/.test(entry.name) ? source(path + '/' + entry.name) : '').join('\n');
}
function apiSource() { return source('admin/api.php') + '\n' + folderSources('admin/api'); }
function dashboardSource() {
    return source('admin/dashboard.php').replace(/<\?php require __DIR__ \. '\/(dashboard\/[^']+)'; \?>/g,
        (_, path) => source('admin/' + path));
}
module.exports = { apiSource, dashboardSource };
