<?php
/**
 * Guide page, rendered by admin/handbook.php after the session check.
 * Expects $settings (content/settings.json). The page uses the dashboard's
 * stylesheet and theme tokens, so it follows the installation's preset,
 * colours, font and light/dark mode.
 */
if (!defined('NIBBLY_HANDBOOK')) { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/version.php';

$theme = nibblyThemeSettings(is_array($settings['theme'] ?? null) ? $settings['theme'] : []);
$adminMode = in_array($theme['adminTheme'], ['light', 'dark', 'system'], true) ? $theme['adminTheme'] : 'light';
$preset = nibblyThemePreset($theme['preset']);
$font = $theme['font'] === 'geist' ? 'plex' : $theme['font'];
$font = in_array($font, ['system', 'inter', 'plex', 'atkinson', 'public', 'geist-mono'], true) ? $font : 'system';
$fontSize = in_array($theme['fontSize'], ['small', 'default', 'large'], true) ? $theme['fontSize'] : 'default';
$radius = max(0, min(24, (int)$theme['buttonRadius']));
$flat = $theme['buttonGlow'] === false;

$modules = array_replace(
    ['news' => true, 'events' => true, 'people' => true, 'messages' => true, 'menus' => true, 'iconManager' => true, 'ai' => true, 'handbook' => true],
    is_array($settings['modules'] ?? null) ? $settings['modules'] : []
);
$isAdmin = ($_SESSION['admin_role'] ?? 'editor') === 'admin';
$lang = nibblyHandbookLanguage();
$manifest = nibblyHandbookSiteManifest();
$chapters = nibblyHandbookChapters($lang, $isAdmin, $modules, $manifest);
$siteName = trim((string)($settings['branding']['name'] ?? '')) ?: (defined('SITE_NAME') ? (string)SITE_NAME : 'nibbly');
$hb = new NibblyHandbook($lang, $isAdmin, $modules, $siteName, $chapters);
$esc = [NibblyHandbook::class, 'esc'];

// Same favicon as the website and the dashboard (Settings → Branding)
$favicon = trim((string)($settings['favicon'] ?? ''));
if (!preg_match('~^/[A-Za-z0-9/._-]+\.(?:svg|png|ico)$~D', $favicon) || str_contains($favicon, '..')) {
    $favicon = defined('NIBBLY_DEFAULT_FAVICON') ? NIBBLY_DEFAULT_FAVICON : '/assets/images/favicon.svg';
}
$faviconType = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon'][strtolower(pathinfo($favicon, PATHINFO_EXTENSION))] ?? 'image/svg+xml';
$asset = static fn(string $path): string => $path . '?v=' . (@filemtime(dirname(__DIR__, 2) . '/admin/' . $path) ?: time());
?>
<!DOCTYPE html>
<html lang="<?php echo $esc($lang); ?>" data-site-theme="<?php echo $adminMode === 'dark' ? 'dark' : 'light'; ?>" data-admin-theme="<?php echo $esc($adminMode); ?>" data-theme-preset="<?php echo $esc($preset); ?>" data-density="auto" data-font="<?php echo $esc($font); ?>" data-font-size="<?php echo $esc($fontSize); ?>"<?php echo $flat ? ' class="nb-flat-buttons"' : ''; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $esc(t('handbook.title') . ' – ' . $siteName); ?></title>
<link rel="icon" href="<?php echo $esc($favicon); ?>" type="<?php echo $esc($faviconType); ?>">
<script>
// Saved choice first, else the admin theme ("system" follows the device), before the first paint
(function () {
    var root = document.documentElement, mode = null;
    try { mode = localStorage.getItem('nibbly-handbook-theme'); } catch (e) {}
    if (mode !== 'light' && mode !== 'dark') {
        mode = root.getAttribute('data-admin-theme');
        if (mode === 'system') mode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    root.setAttribute('data-site-theme', mode === 'dark' ? 'dark' : 'light');
})();
</script>
<link rel="stylesheet" href="<?php echo $esc($asset('fonts.css')); ?>">
<link rel="stylesheet" href="<?php echo $esc($asset('style.css')); ?>">
<?php echo nibblyThemeServerStyle($theme); ?>
<style>:root{--editor-btn-radius:<?php echo $radius; ?>px}</style>
<style><?php readfile(__DIR__ . '/handbook.css'); ?></style>
</head>
<body class="hb">
<a class="skip-link" href="#hb-main"><?php echo $esc(t('nav.skip_content')); ?></a>

<header class="hb-masthead">
  <div class="hb-masthead-inner">
    <button type="button" class="hb-theme-toggle" id="hb-theme-toggle" data-label-light="<?php echo $esc(t('handbook.theme_light')); ?>" data-label-dark="<?php echo $esc(t('handbook.theme_dark')); ?>" hidden>
      <span class="hb-icon-moon"><?php echo $hb->svg('moon'); ?></span>
      <span class="hb-icon-sun"><?php echo $hb->svg('sun'); ?></span>
    </button>
    <div>
      <p class="hb-eyebrow"><?php echo $esc(t('handbook.title')); ?> · <?php echo $esc($siteName); ?></p>
      <h1><?php echo t('handbook.headline', ['site' => '<em>' . $esc($siteName) . '</em>']); ?></h1>
      <p class="hb-lede"><?php echo $esc(t('handbook.lede')); ?></p>
    </div>
    <div class="hb-stamp">
      <b><?php echo $esc(t($isAdmin ? 'handbook.stamp_admin' : 'handbook.stamp_editor')); ?></b>
      <span>nibbly <?php echo $esc(nibblyVersion()); ?></span>
    </div>
  </div>
</header>

<div class="hb-wrap">
  <nav class="hb-toc" aria-label="<?php echo $esc(t('handbook.toc')); ?>">
    <p><?php echo $esc(t('handbook.toc')); ?></p>
    <ol>
      <?php foreach ($chapters as $chapter): ?>
      <li><a href="#<?php echo $esc($chapter['id']); ?>"><?php echo $esc($chapter['title']); ?></a></li>
      <?php endforeach; ?>
    </ol>
    <ul class="hb-toc-links">
      <li><a href="dashboard.php"><?php echo $hb->svg('dashboard'); ?><?php echo $esc(t('dashboard')); ?></a></li>
      <li><a href="../"><?php echo $hb->svg('globe'); ?><?php echo $esc(t('handbook.website')); ?></a></li>
    </ul>
  </nav>

  <main id="hb-main">
    <?php foreach ($chapters as $number => $chapter): ?>
    <section class="hb-chapter<?php echo $chapter['site'] ? ' hb-chapter--site' : ''; ?>" id="<?php echo $esc($chapter['id']); ?>">
      <p class="hb-chapter-num"><?php echo $esc(t('handbook.chapter', ['n' => $number + 1])); ?></p>
      <h2><?php echo $esc($chapter['title']); ?></h2>
      <?php (static function (NibblyHandbook $hb, string $__file) { include $__file; })($hb, $chapter['file']); ?>
      <?php if ($chapter['id'] === 'tips' && $manifest['contact'] !== null && ($contact = nibblyHandbookPick($manifest['contact'], $lang)) !== ''): ?>
      <footer class="hb-end"><p><?php echo $contact; ?></p></footer>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  </main>
</div>

<dialog id="hb-lightbox" aria-label="<?php echo $esc(t('handbook.zoom')); ?>">
  <button class="hb-lightbox-close" type="button" aria-label="<?php echo $esc(t('btn.close')); ?>">×</button>
  <img alt="">
  <p></p>
</dialog>

<script>
(function () {
    var dialog = document.getElementById('hb-lightbox');
    var image = dialog.querySelector('img');
    var caption = dialog.querySelector('p');
    document.querySelectorAll('.hb-shot button').forEach(function (button) {
        button.addEventListener('click', function () {
            var inner = button.querySelector('img');
            var figcaption = button.parentElement.querySelector('figcaption');
            image.src = button.dataset.full;
            image.alt = inner.alt;
            caption.textContent = figcaption ? figcaption.textContent : '';
            if (dialog.showModal) dialog.showModal();
        });
    });
    dialog.querySelector('.hb-lightbox-close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });

    // Light/dark: a click overrides the admin theme and is remembered in this browser
    var root = document.documentElement;
    var toggle = document.getElementById('hb-theme-toggle');
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var render = function () {
        var dark = root.getAttribute('data-site-theme') === 'dark';
        var label = dark ? toggle.dataset.labelLight : toggle.dataset.labelDark;
        toggle.dataset.mode = dark ? 'dark' : 'light';
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
    };
    toggle.addEventListener('click', function () {
        var next = root.getAttribute('data-site-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-site-theme', next);
        try { localStorage.setItem('nibbly-handbook-theme', next); } catch (e) {}
        render();
    });
    // Without a saved choice, "system" keeps following the device
    if (media && media.addEventListener && root.getAttribute('data-admin-theme') === 'system') {
        media.addEventListener('change', function (event) {
            var saved = null;
            try { saved = localStorage.getItem('nibbly-handbook-theme'); } catch (e) {}
            if (saved !== 'light' && saved !== 'dark') {
                root.setAttribute('data-site-theme', event.matches ? 'dark' : 'light');
                render();
            }
        });
    }
    render();
    toggle.hidden = false;

    // Current chapter in the table of contents
    var links = Array.prototype.slice.call(document.querySelectorAll('.hb-toc ol a'));
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                links.forEach(function (link) {
                    link.classList.toggle('active', link.getAttribute('href') === '#' + entry.target.id);
                });
            });
        }, { rootMargin: '-30% 0px -60% 0px' });
        document.querySelectorAll('.hb-chapter').forEach(function (section) { observer.observe(section); });
    }
})();
</script>
</body>
</html>
