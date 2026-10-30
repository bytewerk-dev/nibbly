#!/usr/bin/env php
<?php
/**
 * Nibbly search index CLI.
 *
 * Rebuilds content/search-index.json (pages and posts) or shows its state. The dashboard
 * rebuilds the index after every save; run this after imports or edits made outside it.
 *
 * Usage:
 *   php cli/search-index.php --action=rebuild     # rebuild the index
 *   php cli/search-index.php --action=status      # document count and age
 *   php cli/search-index.php --action=query --q="HOSI Wien" [--lang=de]   # try a search
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
if (is_file($projectRoot . '/admin/config.php')) {
    require_once $projectRoot . '/admin/config.php';
}
require_once $projectRoot . '/includes/search-helper.php';

$options = getopt('', ['action:', 'q:', 'lang:']);
$action = $options['action'] ?? 'rebuild';

switch ($action) {
    case 'rebuild':
        $count = nibblySearchIndexRebuild();
        if ($count === false) {
            fwrite(STDERR, "Error: could not write " . nibblySearchIndexPath() . "\n");
            exit(1);
        }
        echo "Search index rebuilt: {$count} documents → " . nibblySearchIndexPath() . "\n";
        exit(0);

    case 'status':
        $path = nibblySearchIndexPath();
        if (!is_file($path)) {
            echo "No index yet ({$path}); it is built on the first search.\n";
            exit(0);
        }
        $index = json_decode((string)file_get_contents($path), true);
        $stale = (int)($index['contentMtime'] ?? 0) < nibblySearchContentMtime();
        echo "Index: " . (int)($index['count'] ?? 0) . " documents, generated " . ($index['generatedAt'] ?? '?') . ($stale ? " (stale, rebuilt on the next search)" : " (current)") . "\n";
        exit(0);

    case 'query':
        $query = (string)($options['q'] ?? '');
        if ($query === '') {
            fwrite(STDERR, "Usage: --action=query --q=\"term\" [--lang=de]\n");
            exit(2);
        }
        $result = nibblySearchQuery($query, ['lang' => $options['lang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'), 'perPage' => 20]);
        echo $result['total'] . " results for \"{$query}\"\n";
        foreach ($result['results'] as $hit) {
            echo sprintf("  %3d  %-5s %-10s %s\n       %s\n", $hit['score'], $hit['type'], $hit['date'], $hit['title'], $hit['url']);
        }
        exit(0);

    default:
        fwrite(STDERR, "Unknown action: {$action}\n");
        exit(2);
}
