<?php
/**
 * File-backed sliding-window rate limits per user and bucket.
 *
 * State lives in content/ai-agent/rate/{userKey}.json and is updated under the
 * shared JSON lock, so limits hold across PHP processes and survive a logout
 * (unlike the session-scoped burst limiter, which stays in place as well).
 */

require_once dirname(__DIR__, 2) . '/json-store.php';

/** Default buckets: [max hits, window in seconds]. */
function nibblyAgentRateLimitDefaults(): array {
    return [
        'runs' => ['limit' => 10, 'window' => 3600],
        'steps' => ['limit' => 60, 'window' => 60],
        'uploads' => ['limit' => 10, 'window' => 600],
    ];
}

function nibblyAgentRateLimitDir(): string {
    return defined('NIBBLY_AGENT_RATE_DIR') ? rtrim((string)NIBBLY_AGENT_RATE_DIR, '/') : dirname(__DIR__, 3) . '/content/ai-agent/rate';
}

/** Stable, filesystem-safe file name for a user key; odd keys are hashed. */
function nibblyAgentRateLimitFile(string $userKey): string {
    $name = preg_match('/^[A-Za-z0-9_-]{1,64}$/', $userKey) ? $userKey : 'h_' . substr(hash('sha256', $userKey), 0, 40);
    return nibblyAgentRateLimitDir() . '/' . $name . '.json';
}

/**
 * Count one hit (or only look, with $consume = false) against a bucket. If the
 * state cannot be read or written the call is denied (fail closed).
 *
 * @param array|null $config ['limit' => int, 'window' => seconds]; null = default for the bucket
 * @return array{allowed:bool,limit:int,remaining:int,retryAfter:int}
 */
function nibblyAgentRateLimit(string $userKey, string $bucket, ?array $config = null, bool $consume = true, ?int $now = null): array {
    $config = $config ?? (nibblyAgentRateLimitDefaults()[$bucket] ?? null);
    if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $bucket) || $config === null || trim($userKey) === '') {
        throw new InvalidArgumentException('Unknown rate limit bucket or empty user key.');
    }
    $limit = max(1, (int)($config['limit'] ?? 1));
    $window = max(1, (int)($config['window'] ?? 60));
    $now = $now ?? time();
    $result = ['allowed' => false, 'limit' => $limit, 'remaining' => 0, 'retryAfter' => $window];
    $evaluated = false;

    $update = static function (array &$data) use ($bucket, $limit, $window, $now, $consume, &$result, &$evaluated): bool {
        $evaluated = true;
        $stored = is_array($data['buckets'][$bucket] ?? null) ? $data['buckets'][$bucket] : [];
        // Keep only hits inside the window; a timestamp from the far future is clock noise.
        $hits = array_values(array_filter($stored, static fn($time) => is_int($time) && $time > $now - $window && $time <= $now + 60));
        sort($hits);
        if (count($hits) >= $limit) {
            $result['retryAfter'] = max(1, $hits[count($hits) - $limit] + $window - $now);
            $data['buckets'][$bucket] = $hits;
            return $hits !== $stored; // only persist the pruning
        }
        $result['allowed'] = true;
        if ($consume) {
            $hits[] = $now;
        }
        $result['remaining'] = max(0, $limit - count($hits));
        $result['retryAfter'] = 0;
        $data['buckets'][$bucket] = $hits;
        return $consume;
    };

    $file = nibblyAgentRateLimitFile($userKey);
    nibblyJsonUpdate($file, $update, ['buckets' => []]);
    if (!$evaluated && is_file($file) && !is_array(json_decode((string)@file_get_contents($file), true))) {
        // A damaged state file would lock the user out for good, and rate data is
        // disposable: set it aside once and start over.
        @rename($file, $file . '.corrupt');
        nibblyJsonUpdate($file, $update, ['buckets' => []]);
    }
    return $evaluated ? $result : ['allowed' => false, 'limit' => $limit, 'remaining' => 0, 'retryAfter' => $window];
}

/** Like nibblyAgentRateLimit(), but throws a RuntimeException (code 429) when the limit is hit. */
function nibblyAgentAssertRateLimit(string $userKey, string $bucket, ?array $config = null): array {
    $result = nibblyAgentRateLimit($userKey, $bucket, $config);
    if (!$result['allowed']) {
        throw new RuntimeException('Rate limit reached. Try again in ' . $result['retryAfter'] . ' seconds.', 429);
    }
    return $result;
}
