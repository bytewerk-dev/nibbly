<?php

declare(strict_types=1);

// AI limits as guides: tolerance for runs under way, strict image limit, warnings before a limit is reached.

function limitAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-limits-' . bin2hex(random_bytes(4));
mkdir($site . '/content', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
foreach (['includes', 'admin', 'VERSION'] as $item) exec('cp -R ' . escapeshellarg($root . '/' . $item) . ' ' . escapeshellarg($site . '/'));
foreach (['admin/config.php', 'admin/smtp-config.php', 'includes/nav-config.php'] as $local) @unlink($site . '/' . $local);
require_once $site . '/includes/ai/ai-helper.php';

$settings = nibblyAiDefaults();
$settings['limits'] = ['monthlyBudgetCents' => 1000, 'dailyRequests' => 10, 'dailyTextRequests' => 8, 'dailyImageRequests' => 3, 'maxInputTokens' => 64000, 'maxOutputTokens' => 16000, 'requestTimeoutSeconds' => 300];
$today = gmdate('Y-m-d');
$month = gmdate('Y-m');
$usage = static fn(int $requests = 0, int $text = 0, int $images = 0, int $cents = 0): array => [
    'days' => [$today => ['requests' => $requests, 'textRequests' => $text, 'imageRequests' => $images, 'inputTokens' => 0, 'outputTokens' => 0, 'estimatedCostCents' => 0]],
    'months' => [$month => ['requests' => $requests, 'textRequests' => $text, 'imageRequests' => $images, 'inputTokens' => 0, 'outputTokens' => 0, 'estimatedCostCents' => $cents]],
];
$allowed = static function (string $type, int $estimate, array $usage, string $requestId = '') use (&$settings): bool {
    if ($requestId === '') unset($GLOBALS['nibblyAiRequestId']); else $GLOBALS['nibblyAiRequestId'] = $requestId;
    try {
        nibblyAiCheckLimits($settings, $type, $estimate, $usage);
        return true;
    } catch (RuntimeException $e) {
        return false;
    } finally {
        unset($GLOBALS['nibblyAiRequestId']);
    }
};
$first = 'agent_run_abc123_1';
$later = 'agent_run_abc123_3';
$read = 'agent_read_run_abc123_1';

// ---- Defaults ---------------------------------------------------------------------------------------------
$defaults = nibblyAiDefaults()['limits'];
limitAssert($defaults['maxInputTokens'] === 64000 && $defaults['maxOutputTokens'] === 16000 && $defaults['dailyRequests'] === 300 && $defaults['dailyTextRequests'] === 250, 'sensible defaults for the agent: ' . json_encode($defaults));
limitAssert(!nibblyAiIsAgentContinuation(), 'no request, no continuation');

// ---- Daily requests: a standalone request needs room, a run under way gets 10 % more ------------------------------
limitAssert($allowed('text', 1, $usage(9, 0)), 'the tenth request fits');
limitAssert(!$allowed('text', 1, $usage(10, 0)), 'a standalone request over the limit is refused');
limitAssert(!$allowed('text', 1, $usage(10, 0), $first), 'the first turn of a run needs room below the limit');
limitAssert($allowed('text', 1, $usage(10, 0), $later), 'a run under way may take the eleventh request');
limitAssert(!$allowed('text', 1, $usage(11, 0), $later), 'but not the twelfth');
limitAssert($allowed('text', 1, $usage(10, 0), $read), 'a reading step belongs to a run under way');
limitAssert($allowed('text', 1, $usage(10, 0), 'agent_run_abc123_2') && !$allowed('text', 1, $usage(10, 0), 'req_deadbeef') && !$allowed('text', 1, $usage(10, 0), 'agent_run_x_notanumber'), 'only agent turns after the first count as continuations');
// Text requests
limitAssert($allowed('text', 1, $usage(1, 8), $later) && !$allowed('text', 1, $usage(1, 9), $later) && !$allowed('text', 1, $usage(1, 8)), 'daily text requests: same rule (8 limit, 9 for a run under way)');
// Images stay strict, also for agents
limitAssert($allowed('image', 1, $usage(1, 0, 2)) && !$allowed('image', 1, $usage(1, 0, 3)) && !$allowed('image', 1, $usage(1, 0, 3), $later), 'image requests are strict');
// Unlimited
$settings['limits']['dailyRequests'] = 0;
limitAssert($allowed('text', 1, $usage(5000, 0)), 'zero means no limit');
$settings['limits']['dailyRequests'] = 10;

// ---- Monthly budget: the worst case of one request may reach 10 % past the budget ----------------------------------------------
limitAssert($allowed('text', 150, $usage(0, 0, 0, 900)), 'worst case 1050 of 1000 is fine (limit plus 10 %)');
limitAssert(!$allowed('text', 250, $usage(0, 0, 0, 900)), 'worst case 1150 is too much');
limitAssert($allowed('text', 100, $usage(0, 0, 0, 999)), 'still under the budget: the request may start');
limitAssert(!$allowed('text', 1, $usage(0, 0, 0, 1000)), 'a standalone request at the budget is refused');
limitAssert(!$allowed('text', 1, $usage(0, 0, 0, 1000), $first), 'so is the first turn of a run');
limitAssert($allowed('text', 20, $usage(0, 0, 0, 1050), $later) && $allowed('text', 20, $usage(0, 0, 0, 1050), $read), 'a run under way may finish within the tolerance');
limitAssert(!$allowed('text', 20, $usage(0, 0, 0, 1090), $later) && !$allowed('text', 1, $usage(0, 0, 0, 1100), $later), 'and is stopped at the ceiling');
$settings['limits']['monthlyBudgetCents'] = 0;
limitAssert($allowed('text', 100000, $usage(0, 0, 0, 100000)), 'zero budget means unlimited');
$settings['limits']['monthlyBudgetCents'] = 1000;

// ---- Warnings -------------------------------------------------------------------------------------------------------------------------------
$kinds = static fn(array $warnings): array => array_column($warnings, 'level', 'kind');
limitAssert(nibblyAiLimitWarnings($settings, $usage(7, 6, 2, 790)) === [], 'below 80 % there is nothing to say');
$warnings = nibblyAiLimitWarnings($settings, $usage(8, 6, 2, 800));
limitAssert($kinds($warnings) === ['month' => 'warning', 'requests' => 'warning'], 'at 80 % a warning: ' . json_encode($warnings));
limitAssert($warnings[0] === ['kind' => 'month', 'level' => 'warning', 'used' => 800, 'limit' => 1000, 'percent' => 80], 'with numbers: ' . json_encode($warnings[0]));
$warnings = nibblyAiLimitWarnings($settings, $usage(10, 8, 3, 1000));
limitAssert($kinds($warnings) === ['month' => 'reached', 'requests' => 'reached', 'text' => 'reached', 'images' => 'reached'], 'at the limit: reached for all four');
limitAssert(nibblyAiLimitWarnings(array_replace_recursive($settings, ['limits' => ['dailyRequests' => 0, 'monthlyBudgetCents' => 0, 'dailyTextRequests' => 0, 'dailyImageRequests' => 0]]), $usage(99, 99, 99, 99999)) === [], 'no limit, no warning');
// Running requests count: a reservation is part of the usage
$reserved = $usage(0, 0, 0, 0);
$reserved['reservations']['req_a'] = ['id' => 'req_a', 'type' => 'text', 'status' => 'reserved', 'reservedCents' => 850, 'createdAt' => gmdate('c'), 'updatedAt' => gmdate('c')];
limitAssert($kinds(nibblyAiLimitWarnings($settings, $reserved))['month'] === 'warning', 'reserved money counts');

// The summary the dashboard and the assistant read carries them
file_put_contents(NIBBLY_AI_SETTINGS_PATH, json_encode($settings));
file_put_contents(NIBBLY_AI_USAGE_PATH, json_encode($usage(9, 0, 0, 100)));
$summary = nibblyAiUsageSummary();
limitAssert($kinds($summary['warnings']) === ['requests' => 'warning'] && isset($summary['today'], $summary['month']), 'the usage summary has the warnings: ' . json_encode($summary['warnings']));

echo "PASS AI limits: tolerance for runs under way, strict first requests and images, budget ceiling, warnings at 80 % and when reached\n";
