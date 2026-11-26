#!/usr/bin/env php
<?php
/**
 * Nibbly media originals CLI.
 *
 * Up to 2.11.0 an image uploaded into a library folder ("2026/live") kept its original in the
 * root of assets/images-originals/ instead of the same folder. The library then showed no
 * "Original" badge, and rename, move, trash and delete left the original behind. This tool
 * finds such originals and moves them to the folder of their web version.
 *
 * Usage:
 *   php cli/media-originals.php --action=status              # list misplaced originals
 *   php cli/media-originals.php --action=repair [--dry-run]  # move them into their folders
 *
 * An original in the root is left alone when a web version with its name exists in the root
 * (it belongs there), when no web version is found (reported as orphan), or when web versions
 * with that name exist in several folders (reported as ambiguous: move it by hand). Nothing is
 * overwritten. The library trash (images-originals-trash/) is handled the same way.
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
$options = getopt('', ['action:', 'images:', 'dry-run']);
if (isset($options['images'])) {
    // Another library (tests, a copy): its images folder instead of the site's config
    define('IMAGES_PATH', rtrim((string)$options['images'], '/') . '/');
} elseif (is_file($projectRoot . '/admin/config.php')) {
    require_once $projectRoot . '/admin/config.php';
}
require_once $projectRoot . '/includes/media-web-version.php';

$action = $options['action'] ?? 'status';
$dryRun = array_key_exists('dry-run', $options) || $action === 'status';
if (!in_array($action, ['status', 'repair'], true)) {
    fwrite(STDERR, "Error: --action must be status or repair.\n");
    exit(2);
}

$imagesRoot = defined('IMAGES_PATH') ? IMAGES_PATH : $projectRoot . '/assets/images/';
$trashRoot = defined('IMAGES_TRASH_PATH') ? IMAGES_TRASH_PATH : rtrim(dirname(rtrim($imagesRoot, '/')), '/') . '/images-trash/';

/** Folders (relative, without the root) that hold "<stem>.webp", below $root. */
function mediaOriginalsCliWebFolders(string $root, string $stem): array {
    if (!is_dir($root)) return [];
    $folders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink() || $file->getFilename() !== $stem . '.webp') continue;
        $relative = ltrim(str_replace('\\', '/', substr($file->getPath(), strlen(rtrim($root, '/')))), '/');
        if ($relative !== '') $folders[] = $relative;
    }
    sort($folders);
    return $folders;
}

$counts = ['moved' => 0, 'correct' => 0, 'orphan' => 0, 'ambiguous' => 0, 'conflict' => 0, 'failed' => 0];
foreach ([false => $imagesRoot, true => $trashRoot] as $trash => $webRoot) {
    $originalsRoot = nibblyMediaOriginalsPath((bool)$trash);
    if (!is_dir($originalsRoot)) continue;
    $label = $trash ? 'images-originals-trash/' : 'images-originals/';
    foreach (scandir($originalsRoot) ?: [] as $name) {
        $path = $originalsRoot . $name;
        if ($name[0] === '.' || !is_file($path) || is_link($path)) continue;
        $stem = pathinfo($name, PATHINFO_FILENAME);
        if (is_file(rtrim($webRoot, '/') . '/' . $stem . '.webp')) {
            $counts['correct']++;
            continue;
        }
        $folders = mediaOriginalsCliWebFolders($webRoot, $stem);
        if (!$folders) {
            $counts['orphan']++;
            echo "orphan     {$label}{$name} (no web version found)\n";
            continue;
        }
        if (count($folders) > 1) {
            $counts['ambiguous']++;
            echo "ambiguous  {$label}{$name} -> " . implode(', ', $folders) . " (move by hand)\n";
            continue;
        }
        $target = $originalsRoot . $folders[0] . '/' . $name;
        if (file_exists($target)) {
            $counts['conflict']++;
            echo "conflict   {$label}{$name} -> {$label}{$folders[0]}/{$name} exists already\n";
            continue;
        }
        echo ($dryRun ? 'would move ' : 'move       ') . "{$label}{$name} -> {$label}{$folders[0]}/{$name}\n";
        if ($dryRun) { $counts['moved']++; continue; }
        if ((!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) || !@rename($path, $target)) {
            $counts['failed']++;
            fwrite(STDERR, "Error: could not move {$label}{$name}\n");
            continue;
        }
        $counts['moved']++;
    }
}

printf("%s: %d %s, %d in place, %d orphaned, %d ambiguous, %d conflicts, %d failed\n",
    $action === 'status' ? 'Status' : ($dryRun ? 'Dry run' : 'Repair'),
    $counts['moved'], $dryRun ? 'to move' : 'moved', $counts['correct'], $counts['orphan'],
    $counts['ambiguous'], $counts['conflict'], $counts['failed']);
exit($counts['failed'] ? 1 : 0);
