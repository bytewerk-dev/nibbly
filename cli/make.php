#!/usr/bin/env php
<?php
/**
 * Nibbly Page Scaffolding Tool
 *
 * Generates page boilerplate (JSON content file + optional PHP template).
 *
 * Usage:
 *   php cli/make.php --slug=about --lang=en [options]
 */

// Must run from project root
$projectRoot = dirname(__DIR__);
if (!file_exists($projectRoot . '/router.php')) {
    fwrite(STDERR, "Error: Run this script from the Nibbly project root.\n");
    fwrite(STDERR, "  cd /path/to/nibbly && php cli/make.php --slug=about\n");
    exit(1);
}

// ── CLI argument parsing ──────────────────────────────────────────────

$opts = [];
for ($i = 1; $i < count($argv); $i++) {
    $a = $argv[$i];
    if (preg_match('/^--([a-z-]+)=(.+)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)$/', $a, $m)) {
        $opts[$m[1]] = true;
    }
}

// ── Help ──────────────────────────────────────────────────────────────

if (isset($opts['help']) || !isset($opts['slug'])) {
    echo <<<USAGE
Nibbly Page Scaffolding Tool

Usage:
  php cli/make.php --slug=about --lang=en [options]

Options:
  --slug=NAME         Page slug (required)
  --lang=CODE         Language code (default: en)
  --type=TYPE         Page type: standard or custom (default: standard)
  --title=TEXT        Page title (default: derived from slug)
  --description=TEXT  SEO meta description (default: empty)
  --nav=LOCATIONS     Comma-separated nav locations (e.g. header,footer)
                      Use --nav=none to hide from all navigation
                      Default: header (omits "nav" field, auto-discovered in header)
  --breadcrumb=PATH   Breadcrumb trail as "Label:href,Label:href,Current Label"
                      Last item has no href (current page). Example:
                      --breadcrumb="Docs:docs,API Reference"
  --dry-run           Show what would be generated without writing files
  --force             Overwrite existing files
  --help              Show this help

Page types:
  standard   JSON content file only. Served by the front controller using
             renderAllSections(). No PHP template needed.
  custom     PHP template + JSON file. Full control over HTML layout
             using editableText(), editableImage(), etc.

Examples:
  php cli/make.php --slug=about --lang=en --title="About Us"
  php cli/make.php --slug=services --lang=de --type=custom
  php cli/make.php --slug=terms --lang=en --nav=none --dry-run
  php cli/make.php --slug=api --lang=en --nav=header,footer --breadcrumb="Docs:docs,API Reference"

USAGE;
    exit(isset($opts['help']) ? 0 : 1);
}

// ── Input validation ──────────────────────────────────────────────────

$slug = $opts['slug'];
$lang = $opts['lang'] ?? 'en';
$type = $opts['type'] ?? 'standard';
$dryRun = isset($opts['dry-run']);
$force = isset($opts['force']);
$navLocations = null;
if (isset($opts['nav'])) {
    $navLocations = $opts['nav'] === 'none' ? [] : array_map('trim', explode(',', $opts['nav']));
}
// Parse breadcrumb: "Label:href,Label:href,Current Label"
$breadcrumb = null;
if (isset($opts['breadcrumb'])) {
    $breadcrumb = [];
    foreach (explode(',', $opts['breadcrumb']) as $part) {
        $parts = explode(':', trim($part), 2);
        $crumb = ['label' => $parts[0]];
        if (isset($parts[1]) && $parts[1] !== '') $crumb['href'] = $parts[1];
        $breadcrumb[] = $crumb;
    }
}

// Sanitize slug (same as convert.php)
$slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slug));
$slug = trim(preg_replace('/-+/', '-', $slug), '-');

if ($slug === '') {
    fwrite(STDERR, "Error: --slug is required and must contain alphanumeric characters.\n");
    exit(1);
}

if (!preg_match('/^[a-z]{2}$/', $lang)) {
    fwrite(STDERR, "Error: --lang must be a 2-letter lowercase code (e.g. en, de, es).\n");
    exit(1);
}

if (!in_array($type, ['standard', 'custom'])) {
    fwrite(STDERR, "Error: --type must be 'standard' or 'custom'.\n");
    exit(1);
}

$title = $opts['title'] ?? ucfirst(str_replace('-', ' ', $slug));
$description = $opts['description'] ?? '';

// ── Path computation ──────────────────────────────────────────────────

$pageKey = $lang . '_' . $slug;
$jsonPath = $projectRoot . '/content/pages/' . $pageKey . '.json';
$templatePath = $projectRoot . '/' . $lang . '/' . $slug . '.php';

// Check for existing files (without --force)
if (!$dryRun && !$force) {
    if (file_exists($jsonPath)) {
        fwrite(STDERR, "Error: $jsonPath already exists. Use --force to overwrite.\n");
        exit(1);
    }
    if ($type === 'custom' && file_exists($templatePath)) {
        fwrite(STDERR, "Error: $templatePath already exists. Use --force to overwrite.\n");
        exit(1);
    }
}

// ── Generate JSON content ─────────────────────────────────────────────

$json = [
    'page' => $pageKey,
    'lang' => $lang,
    'title' => $title,
    'description' => $description,
    'lastModified' => null,
];

if ($navLocations !== null) {
    $json['nav'] = $navLocations;
}
if ($breadcrumb !== null) {
    $json['breadcrumb'] = $breadcrumb;
}

if ($type === 'standard') {
    $json['sections'] = [
        [
            'id' => 's1',
            'type' => 'heading',
            'text' => $title,
            'level' => 'h1',
        ],
        [
            'id' => 's2',
            'type' => 'text',
            'title' => '',
            'content' => '<p>Add your content here.</p>',
        ],
    ];
} else {
    $json['hero'] = [
        'title' => $title,
        'subtitle' => 'Your tagline here.',
    ];
    $json['content'] = [
        'heading' => 'Content',
        'text' => '<p>Add your content here.</p>',
    ];
}

$jsonContent = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

// ── Generate PHP template (custom only) ───────────────────────────────

$templateContent = '';
if ($type === 'custom') {
    $safeTitle = addcslashes($title, "'");
    $safeDesc = addcslashes($description, "'");

    $templateContent = <<<PHP
<?php
\$pageTitle = '$safeTitle';
\$pageDescription = '$safeDesc';
\$currentLang = '$lang';
\$currentPage = '$slug';
\$contentPage = '$pageKey';
\$basePath = '../';

include '../includes/header.php';
include '../includes/content-loader.php';

\$_p = \$contentPage;
?>

    <main class="main-content">
        <div class="content-inner">

            <!-- Hero -->
            <section class="hero">
                <h1><?php echo editableText(\$_p, 'hero.title', '$safeTitle'); ?></h1>
                <p><?php echo editableText(\$_p, 'hero.subtitle', 'Your tagline here.'); ?></p>
            </section>

            <!-- Content -->
            <section>
                <h2><?php echo editableText(\$_p, 'content.heading', 'Content'); ?></h2>
                <?php echo editableHtml(\$_p, 'content.text', '<p>Add your content here.</p>'); ?>
            </section>

        </div>
    </main>

<?php include '../includes/footer.php'; ?>

PHP;
}

// ── Output ────────────────────────────────────────────────────────────

if ($dryRun) {
    echo "── DRY RUN ──\n\n";

    echo "Would create: $jsonPath\n";
    echo str_repeat('─', 60) . "\n";
    echo $jsonContent;
    echo "\n";

    if ($type === 'custom') {
        echo "Would create: $templatePath\n";
        echo str_repeat('─', 60) . "\n";
        echo $templateContent;
        echo "\n";
    }

    echo "── No files written ──\n";
    exit(0);
}

// ── Write files ───────────────────────────────────────────────────────

// JSON content file
file_put_contents($jsonPath, $jsonContent, LOCK_EX);
echo "  \033[32m✓\033[0m $jsonPath\n";

// PHP template (custom only)
if ($type === 'custom') {
    $templateDir = dirname($templatePath);
    if (!is_dir($templateDir)) {
        mkdir($templateDir, 0755, true);
    }
    file_put_contents($templatePath, $templateContent, LOCK_EX);
    echo "  \033[32m✓\033[0m $templatePath\n";
}

// Summary
echo "\nPage '$slug' created for language '$lang'.\n";
if ($type === 'standard') {
    echo "  Served automatically by the front controller.\n";
} else {
    echo "  Edit the PHP template to customize the layout.\n";
}

$urlPath = ($lang === (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : '')) ? $slug : "$lang/$slug";
echo "  URL: /$urlPath\n";

if ($navLocations !== null) {
    if (empty($navLocations)) {
        echo "  Navigation: hidden from all menus.\n";
    } else {
        echo "  Navigation: " . implode(', ', $navLocations) . "\n";
    }
} else {
    echo "  Navigation: header (default auto-discovery).\n";
}
if ($breadcrumb !== null) {
    $trail = implode(' / ', array_column($breadcrumb, 'label'));
    echo "  Breadcrumb: $trail\n";
}
