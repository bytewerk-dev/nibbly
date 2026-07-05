#!/usr/bin/env php
<?php
/**
 * Run one queued AI image job from PHP CLI.
 *
 * This is primarily used by the local PHP built-in server, where keeping the
 * image request inside the web server process can make localhost appear frozen.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from PHP CLI.\n");
    exit(1);
}

$jobId = trim((string)($argv[1] ?? ''));
if ($jobId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $jobId)) {
    fwrite(STDERR, "Usage: php cli/ai-image-job-worker.php <job_id>\n");
    exit(1);
}

$root = dirname(__DIR__);
$config = is_file($root . '/admin/config.php')
    ? $root . '/admin/config.php'
    : $root . '/admin/config.example.php';

require_once $config;
require_once $root . '/admin/users.php';
require_once $root . '/admin/lang/i18n.php';
require_once $root . '/includes/content-loader.php';
require_once $root . '/includes/seo-helper.php';
require_once $root . '/includes/ai/ai-helper.php';
require_once $root . '/includes/ai/copilot-context.php';
require_once $root . '/includes/ai/image-job-runner.php';
require_once $root . '/includes/analytics-helper.php';
require_once $root . '/includes/forms.php';

try {
    nibblyAiRunImageJobCore($jobId);
    exit(0);
} catch (Throwable $e) {
    try {
        nibblyAiFailImageJob($jobId, $e->getMessage());
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
