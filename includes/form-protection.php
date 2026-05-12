<?php
/**
 * Lightweight form protection for public forms.
 *
 * Uses flat JSON files instead of third-party services or sessions:
 * - one-time tokens with minimum and maximum age
 * - hashed client rate limits
 * - honeypot and conservative content heuristics
 */

const NIBBLY_FORM_TOKEN_FILE = __DIR__ . '/../content/form-tokens.json';
const NIBBLY_FORM_RATE_FILE = __DIR__ . '/../content/form-rate-limit.json';
const NIBBLY_FORM_TOKEN_MIN_AGE = 4;
const NIBBLY_FORM_TOKEN_MAX_AGE = 3600;
const NIBBLY_FORM_RATE_WINDOW = 600;
const NIBBLY_FORM_RATE_MAX_SUBMISSIONS = 3;
const NIBBLY_FORM_RATE_MAX_FAILURES = 8;

function nibblyFormReadJsonFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function nibblyFormWriteJsonFile(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }

    return file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;
}

function nibblyFormTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function nibblyCreateFormToken(string $formId = 'contact'): string
{
    $token = bin2hex(random_bytes(32));
    $now = time();
    $tokens = nibblyFormReadJsonFile(NIBBLY_FORM_TOKEN_FILE);
    $cleaned = [];

    foreach ($tokens as $hash => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $created = (int) ($entry['created'] ?? 0);
        if ($created > 0 && ($now - $created) <= NIBBLY_FORM_TOKEN_MAX_AGE) {
            $cleaned[$hash] = $entry;
        }
    }

    $cleaned[nibblyFormTokenHash($token)] = [
        'form' => $formId,
        'created' => $now,
    ];

    nibblyFormWriteJsonFile(NIBBLY_FORM_TOKEN_FILE, $cleaned);

    return $token;
}

function nibblyFormProtectionFields(string $formId = 'contact'): string
{
    $token = nibblyCreateFormToken($formId);

    return sprintf(
        '<input type="hidden" name="form_id" value="%s">' . "\n" .
        '        <input type="hidden" name="form_token" value="%s">' . "\n" .
        '        <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">' . "\n" .
        '            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>' . "\n" .
        '        </div>',
        htmlspecialchars($formId, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
    );
}

function nibblyIsLazyFormRender(): bool
{
    return defined('NIBBLY_RENDER_LAZY_FORM') && NIBBLY_RENDER_LAZY_FORM === true;
}

function nibblySafeFormBasePath(string $basePath): string
{
    if (preg_match('/[\x00-\x1F\x7F<>"\'\\\\]/', $basePath)) {
        return '';
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $basePath)) {
        return '';
    }

    return $basePath;
}

function nibblyLazyFormPlaceholder(string $formId, array $options = []): string
{
    $basePath = nibblySafeFormBasePath((string) ($options['basePath'] ?? ''));
    $endpoint = $options['endpoint'] ?? ($basePath . 'api/form.php');
    $delay = (int) ($options['delay'] ?? 3500);
    $attrs = [
        'data-nibbly-lazy-form' => $formId,
        'data-endpoint' => $endpoint,
        'data-delay' => (string) max(0, $delay),
    ];

    foreach (($options['params'] ?? []) as $key => $value) {
        if (!preg_match('/^[a-z0-9_-]+$/i', (string) $key)) {
            continue;
        }
        $attrs['data-param-' . strtolower((string) $key)] = (string) $value;
    }

    $htmlAttrs = '';
    foreach ($attrs as $key => $value) {
        $htmlAttrs .= ' ' . $key . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
    }

    return '<div' . $htmlAttrs . '><noscript>This form requires JavaScript.</noscript></div>';
}

function nibblyValidateFormToken(?string $token, string $formId = 'contact'): array
{
    $token = is_string($token) ? trim($token) : '';
    if ($token === '') {
        return ['valid' => false, 'reason' => 'missing'];
    }

    $now = time();
    $hash = nibblyFormTokenHash($token);
    $tokens = nibblyFormReadJsonFile(NIBBLY_FORM_TOKEN_FILE);
    $entry = $tokens[$hash] ?? null;

    if (!is_array($entry) || (($entry['form'] ?? '') !== $formId)) {
        return ['valid' => false, 'reason' => 'invalid'];
    }

    unset($tokens[$hash]);
    nibblyFormWriteJsonFile(NIBBLY_FORM_TOKEN_FILE, $tokens);

    $age = $now - (int) ($entry['created'] ?? 0);
    if ($age < NIBBLY_FORM_TOKEN_MIN_AGE) {
        return ['valid' => false, 'reason' => 'too_fast'];
    }

    if ($age > NIBBLY_FORM_TOKEN_MAX_AGE) {
        return ['valid' => false, 'reason' => 'expired'];
    }

    return ['valid' => true, 'reason' => 'ok'];
}

function nibblyFormClientKey(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $salt = __DIR__;

    return hash('sha256', $ip . '|' . $userAgent . '|' . $salt);
}

function nibblyFormRateState(string $clientKey): array
{
    $now = time();
    $rate = nibblyFormReadJsonFile(NIBBLY_FORM_RATE_FILE);
    $cleaned = [];

    foreach ($rate as $key => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $submissions = array_values(array_filter(
            $entry['submissions'] ?? [],
            fn ($timestamp) => ($now - (int) $timestamp) <= NIBBLY_FORM_RATE_WINDOW
        ));
        $failures = array_values(array_filter(
            $entry['failures'] ?? [],
            fn ($timestamp) => ($now - (int) $timestamp) <= NIBBLY_FORM_RATE_WINDOW
        ));

        if ($submissions || $failures || $key === $clientKey) {
            $cleaned[$key] = [
                'submissions' => $submissions,
                'failures' => $failures,
            ];
        }
    }

    if (!isset($cleaned[$clientKey])) {
        $cleaned[$clientKey] = ['submissions' => [], 'failures' => []];
    }

    nibblyFormWriteJsonFile(NIBBLY_FORM_RATE_FILE, $cleaned);

    return $cleaned[$clientKey];
}

function nibblyFormIsRateLimited(string $clientKey): bool
{
    $state = nibblyFormRateState($clientKey);

    return count($state['submissions'] ?? []) >= NIBBLY_FORM_RATE_MAX_SUBMISSIONS
        || count($state['failures'] ?? []) >= NIBBLY_FORM_RATE_MAX_FAILURES;
}

function nibblyFormRecordAttempt(string $clientKey, bool $success): void
{
    $state = nibblyFormRateState($clientKey);
    $rate = nibblyFormReadJsonFile(NIBBLY_FORM_RATE_FILE);
    $state[$success ? 'submissions' : 'failures'][] = time();
    $rate[$clientKey] = $state;
    nibblyFormWriteJsonFile(NIBBLY_FORM_RATE_FILE, $rate);
}

function nibblyFormLooksLikeSpam(array $fields): bool
{
    $text = implode("\n", array_map('strval', $fields));
    preg_match_all('#https?://|www\.#i', $text, $links);

    if (count($links[0]) > 6) {
        return true;
    }

    if (preg_match('/\[url=|<\/a>|<a\s/i', $text)) {
        return true;
    }

    return false;
}
