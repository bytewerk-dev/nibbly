#!/usr/bin/env php
<?php
/**
 * Nibbly media sizes CLI.
 *
 * Responsive sizes (640 and 1280 px wide, WebP, see includes/media-sizes.php) are made on
 * upload. This tool makes them for images that were already in the library, reports what
 * exists, and removes sizes whose image is gone.
 *
 * Usage:
 *   php cli/media-sizes.php --action=status                  # how many images have their sizes
 *   php cli/media-sizes.php --action=generate [--dry-run] [--folder=team] [--limit=50] [--force]
 *   php cli/media-sizes.php --action=prune [--dry-run]       # delete orphaned sizes
 *
 * --dry-run lists what would happen and writes nothing. --folder limits generate to one
 * library folder (and its subfolders). --limit stops after that many images. --force
 * re-encodes sizes that already exist. --images=PATH works on another images folder
 * (its sizes folder sits next to it) instead of the site's own.
 *
 * Exit codes: 0 success, 1 failure, 2 invalid arguments.
 */

// Command-line tools must never run as a web request, even where a server ignores .htaccess.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
if (!file_exists($projectRoot . '/route.php')) {
    fwrite(STDERR, "Error: Run this script from the Nibbly project root.\n");
    exit(1);
}
$options = getopt('', ['action:', 'folder:', 'limit:', 'images:', 'dry-run', 'force']);
if (isset($options['images'])) {
    // Another library (tests, a copy): its images folder instead of the site's config
    define('IMAGES_PATH', rtrim((string)$options['images'], '/') . '/');
} elseif (is_file($projectRoot . '/admin/config.php')) {
    require_once $projectRoot . '/admin/config.php';
}
require_once $projectRoot . '/includes/media-sizes.php';

$action = $options['action'] ?? 'status';
$dryRun = array_key_exists('dry-run', $options);
$force = array_key_exists('force', $options);
$folder = trim((string)($options['folder'] ?? ''), '/');
$limit = isset($options['limit']) ? max(0, (int)$options['limit']) : 0;

if ($folder !== '' && (str_contains($folder, '..') || !preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._/-]*$#', $folder))) {
    fwrite(STDERR, "Error: invalid --folder.\n");
    exit(2);
}

/** Every library image that can have sizes: [relative name], sorted. */
function mediaSizesCliImages(string $folder): array {
    $root = nibblyMediaImagesPath();
    $base = $root . ($folder !== '' ? $folder . '/' : '');
    if (!is_dir($base)) return [];
    $images = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) continue;
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root)));
        if (nibblyMediaSizesEligible($relative)) $images[] = $relative;
    }
    sort($images, SORT_STRING | SORT_FLAG_CASE);
    return $images;
}

/** Widths an image should have: those it is wider than. */
function mediaSizesCliWanted(string $relative): array {
    $info = @getimagesize(nibblyMediaImagesPath() . $relative);
    if (!$info) return [];
    return array_values(array_filter(nibblyMediaSizeWidths(), static fn(int $w): bool => (int)$info[0] > $w));
}

function mediaSizesCliBytes(int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, '.', '') . ' MB' : number_format($bytes / 1024, 0, '.', '') . ' KB';
}

switch ($action) {
    case 'status':
        $images = mediaSizesCliImages($folder);
        $complete = $missing = $none = 0;
        $missingBytes = 0;
        foreach ($images as $relative) {
            $wanted = mediaSizesCliWanted($relative);
            if (!$wanted) { $none++; continue; }
            $have = array_keys(nibblyMediaSizesFor($relative));
            if (array_diff($wanted, $have)) { $missing++; $missingBytes += (int)filesize(nibblyMediaImagesPath() . $relative); } else { $complete++; }
        }
        $orphans = count(nibblyMediaSizesOrphans()) + count(nibblyMediaSizesOrphans(true));
        echo "Images (jpg/png/webp)" . ($folder !== '' ? " in {$folder}/" : '') . ": " . count($images) . "\n";
        echo "  with all sizes:      {$complete}\n";
        echo "  sizes missing:       {$missing}" . ($missing ? ' (' . mediaSizesCliBytes($missingBytes) . ' of source files)' : '') . "\n";
        echo "  too small for sizes: {$none} (not wider than " . min(nibblyMediaSizeWidths()) . " px)\n";
        echo "Orphaned sizes:        {$orphans}\n";
        echo "Sizes folder:          " . nibblyMediaSizesPath() . "\n";
        echo "WebP encoder:          " . (nibblyMediaCanEncodeWebp() ? 'available' : 'MISSING (GD without WebP)') . "\n";
        exit(0);

    case 'generate':
        if (!nibblyMediaCanEncodeWebp()) {
            fwrite(STDERR, "Error: GD with WebP support is required.\n");
            exit(1);
        }
        $images = mediaSizesCliImages($folder);
        $done = $skipped = $failed = 0;
        $bytes = 0;
        $started = microtime(true);
        foreach ($images as $relative) {
            $wanted = mediaSizesCliWanted($relative);
            $have = array_keys(nibblyMediaSizesFor($relative));
            $todo = $force ? $wanted : array_values(array_diff($wanted, $have));
            if (!$todo) { $skipped++; continue; }
            if ($limit > 0 && $done + $failed >= $limit) break;
            if ($dryRun) {
                echo "would make " . implode('/', $todo) . "  {$relative}\n";
                $done++;
                continue;
            }
            $written = nibblyMediaSizesGenerate($relative, $force);
            if (!$written) {
                $failed++;
                echo "FAILED  {$relative}\n";
                continue;
            }
            $done++;
            $made = 0;
            foreach (nibblyMediaSizesFor($relative) as $size) $made += $size['bytes'];
            $bytes += $made;
            echo "made " . implode('/', $written) . "  {$relative}  (" . mediaSizesCliBytes($made) . ")\n";
        }
        $seconds = round(microtime(true) - $started, 1);
        echo ($dryRun ? "Dry run: " : "Done: ") . "{$done} images" . ($dryRun ? '' : " ({$seconds} s, " . mediaSizesCliBytes($bytes) . " of sizes)") . ", {$skipped} already complete or too small, {$failed} failed.\n";
        exit($failed > 0 ? 1 : 0);

    case 'prune':
        $removed = 0;
        foreach ([false, true] as $trash) {
            $dir = nibblyMediaSizesPath($trash);
            foreach (nibblyMediaSizesOrphans($trash) as $relative) {
                echo ($dryRun ? 'would delete  ' : 'deleted  ') . ($trash ? 'trash/' : '') . $relative . "\n";
                if (!$dryRun && @unlink($dir . $relative)) $removed++;
                elseif ($dryRun) $removed++;
            }
        }
        echo ($dryRun ? "Dry run: " : "Done: ") . "{$removed} orphaned sizes" . ($dryRun ? " would be deleted" : " deleted") . ".\n";
        exit(0);

    default:
        fwrite(STDERR, "Error: unknown --action '{$action}'. Use status, generate or prune.\n");
        exit(2);
}
