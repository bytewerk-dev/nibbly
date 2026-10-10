<?php

declare(strict_types=1);

// File-backed sliding-window rate limiter (includes/ai/agent/rate-limit.php).
//   php tests/agent-rate-limit-smoke.php                 (full run)
//   php tests/agent-rate-limit-smoke.php hits <dir> <n> <limit> <window>   (internal worker)

$root = dirname(__DIR__);

function rateAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (($argv[1] ?? '') === 'hits') {
    define('NIBBLY_AGENT_RATE_DIR', $argv[2]);
    require_once $root . '/includes/ai/agent/rate-limit.php';
    $allowed = 0;
    for ($i = 0; $i < (int)$argv[3]; $i++) {
        $allowed += nibblyAgentRateLimit('shared-user', 'burst', ['limit' => (int)$argv[4], 'window' => (int)$argv[5]], true, 5000)['allowed'] ? 1 : 0;
    }
    echo $allowed;
    exit(0);
}

$dir = sys_get_temp_dir() . '/nibbly-rate-smoke-' . bin2hex(random_bytes(4));
mkdir($dir, 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($dir)));
define('NIBBLY_AGENT_RATE_DIR', $dir . '/rate');
require_once $root . '/includes/ai/agent/rate-limit.php';

// Defaults from the plan
$defaults = nibblyAgentRateLimitDefaults();
rateAssert($defaults['runs'] === ['limit' => 10, 'window' => 3600]
    && $defaults['steps'] === ['limit' => 60, 'window' => 60]
    && $defaults['uploads'] === ['limit' => 10, 'window' => 600], 'default buckets: 10 runs/h, 60 steps/min, 10 uploads/10 min');

// Sliding window
$t = 1_000_000;
for ($i = 1; $i <= 10; $i++) {
    $r = nibblyAgentRateLimit('u_alice', 'runs', null, true, $t + $i);
    rateAssert($r['allowed'] && $r['remaining'] === 10 - $i && $r['limit'] === 10, "run {$i} is allowed");
}
$r = nibblyAgentRateLimit('u_alice', 'runs', null, true, $t + 11);
rateAssert(!$r['allowed'] && $r['remaining'] === 0 && $r['retryAfter'] === 3600 - 10, 'the 11th run is refused with the time until the oldest hit expires: ' . json_encode($r));
rateAssert(!nibblyAgentRateLimit('u_alice', 'runs', null, true, $t + 3599)['allowed'], 'still refused just before the first hit expires');
$r = nibblyAgentRateLimit('u_alice', 'runs', null, true, $t + 3601);
rateAssert($r['allowed'] && $r['remaining'] === 0, 'the window slides: the first hit expired, so one more is allowed (' . json_encode($r) . ')');
$state = json_decode((string)file_get_contents(nibblyAgentRateLimitFile('u_alice')), true);
rateAssert(count($state['buckets']['runs']) === 10, 'expired hits are pruned from the file');

// Peek does not consume; refused calls do not consume either
rateAssert(!nibblyAgentRateLimit('u_alice', 'runs', null, false, $t + 3601)['allowed'], 'peek reports a full bucket');
$peek = nibblyAgentRateLimit('u_bob', 'steps', null, false, $t);
rateAssert($peek['allowed'] && $peek['remaining'] === 60 && !is_file(nibblyAgentRateLimitFile('u_bob')), 'peek on a fresh bucket allows and writes nothing');
for ($i = 0; $i < 5; $i++) {
    rateAssert(!nibblyAgentRateLimit('u_alice', 'runs', null, true, $t + 3601)['allowed'], 'full bucket stays full');
}
$state = json_decode((string)file_get_contents(nibblyAgentRateLimitFile('u_alice')), true);
rateAssert(count($state['buckets']['runs']) === 10, 'refused requests are not recorded');

// Users and buckets are independent
rateAssert(nibblyAgentRateLimit('u_bob', 'runs', null, true, $t)['allowed'], 'another user has their own budget');
for ($i = 0; $i < 60; $i++) {
    rateAssert(nibblyAgentRateLimit('u_carol', 'steps', null, true, $t)['allowed'], 'step ' . $i);
}
rateAssert(!nibblyAgentRateLimit('u_carol', 'steps', null, true, $t)['allowed'], '61st step in a minute is refused');
rateAssert(nibblyAgentRateLimit('u_carol', 'steps', null, true, $t + 61)['allowed'], 'steps recover after the minute');
rateAssert(nibblyAgentRateLimit('u_carol', 'uploads', null, true, $t)['allowed'], 'buckets do not share budget');
for ($i = 0; $i < 9; $i++) {
    nibblyAgentRateLimit('u_dave', 'uploads', null, true, $t);
}
rateAssert(nibblyAgentRateLimit('u_dave', 'uploads', null, true, $t)['allowed'] && !nibblyAgentRateLimit('u_dave', 'uploads', null, true, $t + 599)['allowed'] && nibblyAgentRateLimit('u_dave', 'uploads', null, true, $t + 601)['allowed'], '10 uploads per 10 minutes');

// Custom limits
$custom = ['limit' => 2, 'window' => 30];
rateAssert(nibblyAgentRateLimit('u_erin', 'custom', $custom, true, $t)['allowed'] && nibblyAgentRateLimit('u_erin', 'custom', $custom, true, $t)['allowed'], 'custom bucket allows its limit');
rateAssert(nibblyAgentRateLimit('u_erin', 'custom', $custom, true, $t + 10)['retryAfter'] === 20, 'custom retryAfter');
nibblyAgentAssertRateLimit('u_zoe', 'custom', $custom);
nibblyAgentAssertRateLimit('u_zoe', 'custom', $custom);
try {
    nibblyAgentAssertRateLimit('u_zoe', 'custom', $custom);
    rateAssert(false, 'assert variant must throw when exhausted');
} catch (RuntimeException $e) {
    rateAssert($e->getCode() === 429, 'assert variant throws with code 429');
}

// Invalid input and file names
foreach ([['u_x', 'unknown-bucket'], ['', 'runs'], ['u_x', '../runs'], ['u_x', 'Runs']] as [$user, $bucket]) {
    try {
        nibblyAgentRateLimit($user, $bucket);
        rateAssert(false, "bucket '{$bucket}' / user '{$user}' must be rejected");
    } catch (InvalidArgumentException $e) {
    }
}
$hostile = nibblyAgentRateLimitFile('../../../etc/passwd');
rateAssert(dirname($hostile) === $dir . '/rate' && preg_match('#/h_[0-9a-f]{40}\.json$#', $hostile) === 1, 'hostile user keys are hashed into the rate directory');
rateAssert(nibblyAgentRateLimitFile('u_alice') === $dir . '/rate/u_alice.json' && nibblyAgentRateLimitFile('a@b.c') !== nibblyAgentRateLimitFile('a_b_c'), 'safe keys stay readable, others cannot collide');
rateAssert(nibblyAgentRateLimit('a@b.c', 'runs', null, true, $t)['allowed'], 'a hashed user key works');

// A damaged state file is set aside instead of locking the user out
file_put_contents(nibblyAgentRateLimitFile('u_frank'), '{broken');
rateAssert(nibblyAgentRateLimit('u_frank', 'runs', null, true, $t)['allowed'], 'damaged state is recovered');
rateAssert(is_file(nibblyAgentRateLimitFile('u_frank') . '.corrupt') && is_array(json_decode((string)file_get_contents(nibblyAgentRateLimitFile('u_frank')), true)), 'damaged state kept aside, fresh state written');

// Parallel processes share one budget
$shared = $dir . '/shared';
$workers = [];
for ($i = 0; $i < 10; $i++) {
    $workers[] = proc_open([PHP_BINARY, __FILE__, 'hits', $shared, '6', '20', '60'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$allowed = 0;
foreach ($workers as $i => $process) {
    $allowed += (int)stream_get_contents($pipes[$i][1]);
    proc_close($process);
}
rateAssert($allowed === 20, "60 parallel hits against a limit of 20 admitted exactly 20 (got {$allowed})");

// Unwritable storage fails closed
$blocked = proc_open([PHP_BINARY, '-r', '
    define("NIBBLY_AGENT_RATE_DIR", ' . var_export($dir . '/not-a-directory/rate', true) . ');
    file_put_contents(' . var_export($dir . '/not-a-directory', true) . ', "file");
    require ' . var_export($root . '/includes/ai/agent/rate-limit.php', true) . ';
    $r = nibblyAgentRateLimit("u_gina", "runs");
    echo $r["allowed"] ? "open" : "closed";
'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $blockedPipes);
$answer = stream_get_contents($blockedPipes[1]);
proc_close($blocked);
rateAssert($answer === 'closed', 'storage failure denies the request instead of running without a limit');

echo "PASS agent rate limit: sliding window, buckets, users, peek, hashed keys, corrupt-state recovery, parallel processes, fail closed\n";
