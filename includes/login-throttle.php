<?php
/**
 * IP-based throttling for login and password reset requests (CWE-307).
 *
 * Check and count happen in one locked transaction, so parallel requests can
 * not all pass the check before the first failure is written (CWE-362).
 */

require_once __DIR__ . '/json-store.php';

if (!defined('LOGIN_ATTEMPTS_FILE')) define('LOGIN_ATTEMPTS_FILE', dirname(__DIR__) . '/content/login_attempts.json');
if (!defined('BRUTE_FORCE_DELAY_STEP')) define('BRUTE_FORCE_DELAY_STEP', 15);      // seconds per failed attempt after threshold
if (!defined('BRUTE_FORCE_THRESHOLD')) define('BRUTE_FORCE_THRESHOLD', 3);         // free attempts before delay kicks in
if (!defined('BRUTE_FORCE_MAX_ATTEMPTS')) define('BRUTE_FORCE_MAX_ATTEMPTS', 20);  // hard lockout after this many
if (!defined('BRUTE_FORCE_LOCKOUT_TIME')) define('BRUTE_FORCE_LOCKOUT_TIME', 86400); // 24 hours hard lockout
if (!defined('RESET_RATE_LIMIT')) define('RESET_RATE_LIMIT', 5);                   // reset mails per IP and window
if (!defined('RESET_RATE_WINDOW')) define('RESET_RATE_WINDOW', 3600);

function getIpHash(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $ip . 'nibbly');
}

/**
 * Run $update on the attempts store under an exclusive lock. A damaged store is
 * replaced (it only holds counters); a store that can not be locked or written
 * makes the caller fail closed.
 */
function nibblyLoginAttemptsTransaction(callable $update): bool {
    $path = LOGIN_ATTEMPTS_FILE;
    $lock = nibblyJsonLock($path);
    if ($lock === false) return false;
    try {
        $data = [];
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) $data = $decoded;
        }
        $now = time();
        foreach ($data as $key => $entry) {
            if (!is_array($entry) || $now - (int)($entry['last'] ?? 0) > BRUTE_FORCE_LOCKOUT_TIME) unset($data[$key]);
        }
        $update($data, $now);
        return nibblyJsonAtomicWrite($path, $data);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Throttle state of one login counter at $now. */
function nibblyLoginThrottleState(?array $entry, int $now): array {
    if ($entry === null) return ['allowed' => true, 'wait' => 0, 'locked' => false];
    $count = (int)($entry['count'] ?? 0);
    $last = (int)($entry['last'] ?? 0);
    if ($count >= BRUTE_FORCE_MAX_ATTEMPTS) {
        $remaining = BRUTE_FORCE_LOCKOUT_TIME - ($now - $last);
        return $remaining > 0 ? ['allowed' => false, 'wait' => $remaining, 'locked' => true]
            : ['allowed' => true, 'wait' => 0, 'locked' => false, 'expired' => true];
    }
    if ($count >= BRUTE_FORCE_THRESHOLD) {
        $remaining = ($count - BRUTE_FORCE_THRESHOLD + 1) * BRUTE_FORCE_DELAY_STEP - ($now - $last);
        if ($remaining > 0) return ['allowed' => false, 'wait' => $remaining, 'locked' => false];
    }
    return ['allowed' => true, 'wait' => 0, 'locked' => false];
}

/**
 * Atomically check the login throttle and, when allowed, count this attempt up
 * front. A successful login clears the counter afterwards (resetAttempts), so
 * only failures remain counted. Returns ['allowed', 'wait', 'locked'].
 */
function reserveLoginAttempt(): array {
    $key = getIpHash();
    $result = ['allowed' => false, 'wait' => BRUTE_FORCE_DELAY_STEP, 'locked' => false];
    $ok = nibblyLoginAttemptsTransaction(function (array &$data, int $now) use ($key, &$result) {
        $state = nibblyLoginThrottleState($data[$key] ?? null, $now);
        if (!empty($state['expired'])) unset($data[$key]);
        unset($state['expired']);
        $result = $state;
        if (!$state['allowed']) return;
        if (!isset($data[$key])) $data[$key] = ['count' => 0, 'first' => $now, 'last' => $now];
        $data[$key]['count'] = (int)$data[$key]['count'] + 1;
        $data[$key]['last'] = $now;
    });
    if (!$ok) {
        error_log('Nibbly: login throttle store not writable: ' . LOGIN_ATTEMPTS_FILE);
        return ['allowed' => false, 'wait' => BRUTE_FORCE_DELAY_STEP, 'locked' => false];
    }
    return $result;
}

/** Current login throttle state without counting (for the countdown after a failure). */
function checkBruteForce(): array {
    $key = getIpHash();
    $data = [];
    if (is_file(LOGIN_ATTEMPTS_FILE)) {
        $decoded = json_decode((string)file_get_contents(LOGIN_ATTEMPTS_FILE), true);
        if (is_array($decoded)) $data = $decoded;
    }
    $state = nibblyLoginThrottleState(is_array($data[$key] ?? null) ? $data[$key] : null, time());
    unset($state['expired']);
    return $state;
}

function resetAttempts(): void {
    $key = getIpHash();
    nibblyLoginAttemptsTransaction(function (array &$data) use ($key) {
        unset($data[$key]);
    });
}

/**
 * Atomically check and count a password reset request (RESET_RATE_LIMIT per
 * RESET_RATE_WINDOW and IP). Returns true when the request may proceed.
 */
function reserveResetAttempt(): bool {
    $key = 'reset_' . getIpHash();
    $allowed = false;
    $ok = nibblyLoginAttemptsTransaction(function (array &$data, int $now) use ($key, &$allowed) {
        $entry = $data[$key] ?? null;
        if ($entry !== null && $now - (int)($entry['first'] ?? $now) > RESET_RATE_WINDOW) $entry = null;
        if ($entry !== null && (int)($entry['count'] ?? 0) >= RESET_RATE_LIMIT) return;
        $entry ??= ['count' => 0, 'first' => $now, 'last' => $now];
        $entry['count'] = (int)$entry['count'] + 1;
        $entry['last'] = $now;
        $data[$key] = $entry;
        $allowed = true;
    });
    if (!$ok) error_log('Nibbly: login throttle store not writable: ' . LOGIN_ATTEMPTS_FILE);
    return $ok && $allowed;
}
