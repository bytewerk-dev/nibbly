<?php
declare(strict_types=1);
/** Password reset links use a trusted address; login throttling is atomic under parallel requests. */

$folder = sys_get_temp_dir() . '/nibbly-login-security-' . bin2hex(random_bytes(4));
mkdir($folder);
define('LOGIN_ATTEMPTS_FILE', $folder . '/login_attempts.json');
require_once __DIR__ . '/../includes/login-throttle.php';
require_once __DIR__ . '/../includes/site-url.php';

function loginAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

try {
    // --- NIB-001/003: reset links never follow the Host header -------------------------
    $settings = $folder . '/settings.json';
    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
    $_SERVER['HTTP_HOST'] = 'evil.example';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTPS'] = 'on';

    loginAssert(nibblyPasswordResetUrl('tok', $settings) === '', 'Reset link built from Host header without a configured address');

    file_put_contents($settings, json_encode(['seo' => ['siteUrl' => 'https://www.live.example/sub/']]));
    $url = nibblyPasswordResetUrl('a b', $settings);
    loginAssert($url === 'https://www.live.example/admin/index.php?action=reset&token=a%20b', 'Configured site address not used: ' . $url);
    loginAssert(!str_contains($url, 'evil'), 'Host header leaked into reset link');

    $_SERVER['SCRIPT_NAME'] = '/cms/admin/index.php';
    loginAssert(nibblyPasswordResetUrl('t', $settings) === 'https://www.live.example/cms/admin/index.php?action=reset&token=t', 'Subfolder install lost');
    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';

    foreach (['javascript:alert(1)', 'ftp://live.example', '//live.example', 'https://user:pw@live.example', 'https://live.example\\@evil.example', 'nonsense'] as $bad) {
        loginAssert(nibblySiteUrlOrigin($bad) === '', 'Unsafe site address accepted: ' . $bad);
    }
    loginAssert(nibblySiteUrlOrigin('https://Live.Example:8443/x') === 'https://live.example:8443', 'Port or case handling failed');

    file_put_contents($settings, json_encode(['seo' => ['siteUrl' => '']]));
    $_SERVER['HTTP_HOST'] = 'localhost:3000';
    loginAssert(nibblyPasswordResetUrl('t', $settings) === '', 'Remote client used a loopback Host header');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    unset($_SERVER['HTTPS']);
    loginAssert(nibblyPasswordResetUrl('t', $settings) === 'http://localhost:3000/admin/index.php?action=reset&token=t', 'Local development reset link missing');
    $_SERVER['HTTP_HOST'] = 'evil.example';
    loginAssert(nibblyPasswordResetUrl('t', $settings) === '', 'Loopback client with a foreign Host header was trusted');

    // --- NIB-002: check and count are one locked step ---------------------------------
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    for ($i = 0; $i < BRUTE_FORCE_THRESHOLD; $i++) loginAssert(reserveLoginAttempt()['allowed'], 'Free attempt refused');
    $blocked = reserveLoginAttempt();
    loginAssert(!$blocked['allowed'] && $blocked['wait'] > 0, 'Attempt past the threshold allowed');
    loginAssert(!checkBruteForce()['allowed'], 'checkBruteForce disagrees with the reservation');
    resetAttempts();
    loginAssert(reserveLoginAttempt()['allowed'], 'Successful login did not clear the counter');
    resetAttempts();

    for ($i = 0; $i < RESET_RATE_LIMIT; $i++) loginAssert(reserveResetAttempt(), 'Reset request refused too early');
    loginAssert(!reserveResetAttempt(), 'Reset rate limit not enforced');

    file_put_contents(LOGIN_ATTEMPTS_FILE, '{broken');
    loginAssert(reserveLoginAttempt()['allowed'], 'Damaged counter store locks everyone out');
    loginAssert(is_array(json_decode((string)file_get_contents(LOGIN_ATTEMPTS_FILE), true)), 'Damaged store not replaced');
    unlink(LOGIN_ATTEMPTS_FILE);

    // Parallel requests from one address: exactly THRESHOLD may pass.
    $child = $folder . '/child.php';
    file_put_contents($child, '<?php define("LOGIN_ATTEMPTS_FILE", ' . var_export(LOGIN_ATTEMPTS_FILE, true) . ');'
        . 'require ' . var_export(__DIR__ . '/../includes/login-throttle.php', true) . ';'
        . '$_SERVER["REMOTE_ADDR"] = "192.0.2.50"; while (!is_file($argv[1])) usleep(500);'
        . 'echo reserveLoginAttempt()["allowed"] ? "1" : "0";');
    $go = $folder . '/go';
    $processes = [];
    for ($i = 0; $i < 12; $i++) {
        $processes[] = proc_open([PHP_BINARY, $child, $go], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outputs[] = $pipes;
    }
    usleep(300000);
    touch($go);
    $allowed = 0;
    foreach ($processes as $index => $process) {
        $allowed += (int)trim((string)stream_get_contents($outputs[$index][1]));
        $error = (string)stream_get_contents($outputs[$index][2]);
        loginAssert($error === '', 'Child failed: ' . $error);
        proc_close($process);
    }
    loginAssert($allowed === BRUTE_FORCE_THRESHOLD, "Parallel requests passed the login throttle: $allowed allowed");
    $stored = json_decode((string)file_get_contents(LOGIN_ATTEMPTS_FILE), true);
    loginAssert(($stored[hash('sha256', '192.0.2.50nibbly')]['count'] ?? 0) === BRUTE_FORCE_THRESHOLD, 'Lost counter updates');
} finally {
    foreach (glob($folder . '/{,.}*', GLOB_BRACE) ?: [] as $file) if (is_file($file)) unlink($file);
    @rmdir($folder);
}

echo "PASS login security: trusted reset links, atomic login and reset throttling\n";
