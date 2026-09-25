<?php
/**
 * Manual browser fixture using the real inline editor with read-only fake API data.
 * NIBBLY_EDITOR_TEST_ROOT=/path/to/nibbly php -S 127.0.0.1:3099 tests/fixtures/editor-mode-hint.php
 * Two content clicks: reminder. Controls/links: no reminder. Escape/Keep browsing:
 * no repeat. Activate: real editor toolbar. ?guest=1: no editor. ?lang=en: English.
 */
$root = realpath(getenv('NIBBLY_EDITOR_TEST_ROOT') ?: '');
if (PHP_SAPI !== 'cli-server' || !$root || !is_file($root . '/js/inline-editor.js')) {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/admin/api.php') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $responses = [
        'load' => ['heading' => 'Testinhalt', 'sections' => []],
        'load-events' => [],
        'load-settings' => ['branding' => ['name' => 'Fixture', 'showBranding' => false]],
        'keepalive' => [],
    ];
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !array_key_exists($action, $responses)) {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Fixture: writes are disabled.']);
    } else echo json_encode(['success' => true, 'data' => $responses[$action]]);
    exit;
}
if (in_array($path, ['/js/inline-editor.js', '/css/inline-editor.css', '/css/website.css'], true)) {
    header('Content-Type: ' . (str_ends_with($path, '.js') ? 'text/javascript' : 'text/css'));
    if (is_file($root . $path)) readfile($root . $path);
    exit;
}
$guest = isset($_GET['guest']);
$lang = ($_GET['lang'] ?? '') === 'en' ? 'en' : 'de';
?>
<!doctype html>
<html lang="<?= $lang ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Editor-Hinweis · Funktionsprüfung</title>
<?php if (!$guest): ?>
<meta name="csrf-token" content="fixture-token"><meta name="content-page" content="fixture">
<link rel="stylesheet" href="/css/inline-editor.css">
<?php endif; ?>
<?php if (is_file($root . '/site/team.php')): ?><link rel="stylesheet" href="/css/website.css"><?php endif; ?>
<style>body{margin:0;font-family:system-ui}main{padding:100px 32px}main p{padding:20px;background:#f0f3f7}main input,main button,main summary{margin:12px;padding:8px} :root{--editor-primary:#1d3460;--editor-btn-bg:#1d3460;--editor-btn-bg-hover:#304c7c}</style>
</head><body class="notare-site">
<main><h1>Editor-Hinweis prüfen</h1>
<p id="test-first">Erster Inhaltsbereich</p><p id="test-second">Zweiter Inhaltsbereich</p>
<a href="#test-first">Interner Link</a><button type="button">Normaler Button</button>
<label>Eingabefeld <input></label>
<details><summary>Aufklappen</summary>Geöffneter Inhalt</details>
<button type="button" onclick="document.querySelector('#other-dialog').showModal()">Anderen Dialog öffnen</button>
<dialog id="other-dialog"><h2>Anderer Dialog</h2><p>Dialoginhalt</p><form method="dialog"><button>Schließen</button></form></dialog>
</main>
<?php if (!$guest): ?>
<script>window.NB_LANG=<?= file_get_contents($root . '/admin/lang/' . $lang . '.json') ?>;function t(key){return NB_LANG[key]||key;}</script>
<script src="/js/inline-editor.js"></script>
<?php endif; ?>
</body></html>
