<?php
/**
 * Newsletter send worker for a server cron (includes/newsletter.php).
 *
 *   * * * * * php /path/to/site/cli/newsletter.php
 *
 * Each run sends at most one batch (Newsletter → Settings → Mails per run) and respects the
 * daily limit. Exit codes: 0 done or nothing to do, 1 error, 2 another run is still active.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$configFile = __DIR__ . '/../admin/config.php';
if (!is_file($configFile)) { fwrite(STDERR, "Nibbly is not set up yet.\n"); exit(1); }
require_once $configFile;
require_once __DIR__ . '/../includes/newsletter.php';

if (!nibblyNewsletterModuleEnabled()) { echo "Newsletter module is switched off.\n"; exit(0); }
if (nibblyNewsletterBaseUrl() === '') {
    fwrite(STDERR, "No website address configured (Settings → Branding → Search engines or NIBBLY_SITE_URL).\n");
    exit(1);
}
$summary = nibblyNewsletterRunQueue();
if ($summary['busy']) { echo "Another run is still active.\n"; exit(2); }
printf("sent %d, failed %d, remaining %d%s%s\n", $summary['sent'], $summary['failed'], $summary['remaining'],
    $summary['limit'] ? ', daily limit reached' : '', $summary['error'] !== '' ? ', last error: ' . $summary['error'] : '');
exit($summary['error'] !== '' && $summary['sent'] === 0 && $summary['remaining'] > 0 ? 1 : 0);
