<?php
/**
 * Security self-check for the admin system status; never exposed to visitors
 * or editors.
 *
 * The folder check proves that internal folders cannot be downloaded through
 * the site's own public URL. A proxy such as nginx can serve static files
 * itself and bypass .htaccess (HestiaCP, or Plesk with "Serve static files
 * directly by nginx"). A missing file is no test: nginx then hands the request
 * to Apache, which answers 403. The check therefore creates short-lived probe
 * files with random names, requests them and evaluates only the status code.
 * A public control file in assets/images/ proves that the request reached this
 * installation, so a wrong address cannot pass as "protected".
 */

require_once __DIR__ . '/json-store.php';
require_once __DIR__ . '/version.php';

function nibblySecurityServerHttps(): bool {
    // nibblySessionStart() sets the session cookie's Secure flag from this detection.
    return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

function nibblySecurityIsLoopbackIp(string $ip): bool {
    $ip = strtolower(trim($ip));
    return $ip === '::1' || str_starts_with($ip, '127.') || str_starts_with($ip, '::ffff:127.');
}

/** Development and intranet hosts: no public exposure to check automatically. */
function nibblySecurityIsLocalHost(string $host): bool {
    $host = strtolower(trim($host));
    if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/', $host, $match)) {
        $host = $match[1];
    } elseif (substr_count($host, ':') === 1) {
        $host = explode(':', $host, 2)[0];
    }
    $host = rtrim($host, '.');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    return $host === '' || $host === 'localhost' || !str_contains($host, '.')
        || (bool)preg_match('/\.(localhost|test|local|internal|lan|home\.arpa)$/', $host);
}

function nibblySecurityBasePath(): string {
    // admin/api.php and admin/dashboard.php live one level below the site root.
    $base = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), 2));
    return rtrim($base === '.' ? '' : $base, '/');
}

/** Public address of this installation as the current admin request sees it. */
function nibblySecurityBaseUrl(string $scheme): string {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!preg_match('/^(?:\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::\d{1,5})?$/', $host)) return '';
    return $scheme . '://' . $host . nibblySecurityBasePath();
}

/** nginx rules for every folder that .htaccess protects. */
function nibblySecurityNginxRules(string $basePath = ''): string {
    $rules = [];
    foreach (['content', 'backups', 'cli', 'tests', 'assets/images-trash', 'assets/audio-trash',
        'assets/videos-trash', 'assets/documents-trash'] as $folder) {
        $rules[] = 'location ^~ ' . $basePath . '/' . $folder . '/ { deny all; }';
    }
    return implode("\n", $rules);
}

/** Folders to probe, with extensions that proxies commonly serve as static files. */
function nibblySecurityProbeTargets(string $root): array {
    $targets = [['content', 'json'], ['backups', 'zip']];
    foreach (['images' => 'jpg', 'audio' => 'mp3', 'videos' => 'mp4', 'documents' => 'pdf'] as $type => $extension) {
        if (is_dir($root . '/assets/' . $type . '-trash')) $targets[] = ['assets/' . $type . '-trash', $extension];
    }
    return $targets;
}

/** Remove probe files left behind by an interrupted run (callers hold the check lock). */
function nibblySecuritySweepProbeFiles(string $root): void {
    foreach (array_merge(['assets/images'], array_column(nibblySecurityProbeTargets($root), 0)) as $folder) {
        foreach (glob($root . '/' . $folder . '/nibbly-access-check-*') ?: [] as $file) {
            if (preg_match('/^nibbly-access-check-[0-9a-f]{16}\.(txt|json|zip|jpg|mp3|mp4|pdf)$/', basename($file))) {
                @unlink($file);
            }
        }
    }
}

function nibblySecurityWriteProbe(string $file, string $extension): bool {
    $body = ['json' => "{}\n", 'zip' => "PK\x05\x06" . str_repeat("\0", 18)][$extension] ?? "nibbly security check\n";
    if (!is_dir(dirname($file)) || @file_put_contents($file, $body) === false) return false;
    // Readable like an uploaded file, even under a restrictive PHP umask.
    @chmod($file, 0644);
    return true;
}

/**
 * GET a URL and return only its final HTTP status (0 = no response).
 * TLS is verified first; a self-signed staging certificate falls back to an
 * unverified request, which is acceptable here: nothing secret is sent and
 * the response body is ignored.
 */
function nibblySecurityHttpStatus(string $url, bool &$insecure, int $timeout = 5): array {
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_USERAGENT => 'Nibbly-Security-Check/' . nibblyVersion(),
            CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
        ]);
        if (defined('CURLOPT_PROTOCOLS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        if (defined('CURLOPT_REDIR_PROTOCOLS')) curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        unset($ch);
        if ($errno && !$insecure && in_array($errno, [35, 51, 58, 60, 77, 83], true)) {
            $insecure = true;
            continue;
        }
        return ['status' => $errno ? 0 : $status, 'finalUrl' => $finalUrl, 'error' => $errno ? $error : ''];
    }
    return ['status' => 0, 'finalUrl' => $url, 'error' => 'TLS'];
}

function nibblySecurityProbeState(int $status): string {
    if ($status >= 200 && $status < 300) return 'exposed';
    return in_array($status, [403, 404, 410], true) ? 'protected' : 'unknown';
}

/**
 * Create probe files, request them through the public URL and remove them again.
 * $plainBaseUrl is the http:// counterpart of an https:// site; by default only
 * addresses on standard ports have one.
 */
function nibblySecurityRunProbe(string $root, string $baseUrl, ?string $plainBaseUrl = null): array {
    $result = ['version' => 1, 'baseUrl' => $baseUrl, 'checkedAt' => date('c'), 'checkedTs' => time(),
        'state' => 'unknown', 'reason' => '', 'detail' => '', 'probes' => [], 'http' => 'skipped'];
    if (!function_exists('curl_init')) {
        $result['reason'] = 'no_curl';
        return $result;
    }
    $name = 'nibbly-access-check-' . bin2hex(random_bytes(8));
    $created = [];
    try {
        nibblySecuritySweepProbeFiles($root);
        $control = 'css/style.css';
        if (nibblySecurityWriteProbe($root . '/assets/images/' . $name . '.txt', 'txt')) {
            $created[] = $root . '/assets/images/' . $name . '.txt';
            $control = 'assets/images/' . $name . '.txt';
        }
        $urls = [];
        foreach (nibblySecurityProbeTargets($root) as [$folder, $extension]) {
            $urls[] = $folder . '/' . $name . '.' . $extension;
            $written = nibblySecurityWriteProbe($root . '/' . end($urls), $extension);
            if ($written) $created[] = $root . '/' . end($urls);
            $result['probes'][] = ['path' => $folder . '/', 'status' => 0, 'state' => $written ? 'pending' : 'skipped'];
        }

        $insecure = false;
        $response = nibblySecurityHttpStatus($baseUrl . '/' . $control, $insecure);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $result['reason'] = $response['status'] === 401 ? 'auth' : 'unreachable';
            $result['detail'] = $response['status'] ? 'HTTP ' . $response['status'] : $response['error'];
            return $result;
        }
        foreach ($result['probes'] as $index => $probe) {
            if ($probe['state'] === 'skipped') continue;
            $status = nibblySecurityHttpStatus($baseUrl . '/' . $urls[$index], $insecure)['status'];
            $result['probes'][$index] = ['path' => $probe['path'], 'status' => $status, 'state' => nibblySecurityProbeState($status)];
        }
        $states = array_column($result['probes'], 'state');
        if (in_array('exposed', $states, true)) {
            $result['state'] = 'exposed';
        } elseif (in_array('protected', $states, true) && !in_array('unknown', $states, true)) {
            $result['state'] = 'protected';
        } else {
            $unexpected = array_values(array_filter($result['probes'], fn($probe) => $probe['state'] === 'unknown'));
            $result['reason'] = $unexpected ? 'status' : 'not_writable';
            $result['detail'] = $unexpected ? 'HTTP ' . $unexpected[0]['status'] : '';
        }

        // Plain http:// should end on https://.
        if ($plainBaseUrl === null) {
            $parts = parse_url($baseUrl);
            $plainBaseUrl = ($parts['scheme'] ?? '') === 'https' && !isset($parts['port'])
                ? 'http://' . $parts['host'] . ($parts['path'] ?? '') : '';
        }
        if ($plainBaseUrl !== '') {
            $plain = nibblySecurityHttpStatus($plainBaseUrl . '/' . $control, $insecure, 3);
            $result['http'] = str_starts_with(strtolower($plain['finalUrl']), 'https://') ? 'redirect'
                : ($plain['status'] >= 200 && $plain['status'] < 300 ? 'open' : 'closed');
        }
        return $result;
    } finally {
        foreach ($created as $file) @unlink($file);
    }
}

function nibblySecurityCachePath(): string {
    return dirname(__DIR__) . '/content/security-check.json';
}

/** Re-check soon while something is wrong; a protected result stays valid for 12 hours. */
function nibblySecurityCacheTtl(string $state): int {
    return ['protected' => 43200, 'exposed' => 600][$state] ?? 3600;
}

/** Cached probe result for this address, refreshed when $run allows it. */
function nibblySecurityProbeResult(string $baseUrl, bool $local, bool $run, bool $force): array {
    $path = nibblySecurityCachePath();
    $cached = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    // A restored backup or a moved site must not inherit another address's result.
    if (!is_array($cached) || ($cached['baseUrl'] ?? '') !== $baseUrl || !isset($cached['checkedTs'], $cached['state'], $cached['probes'])) {
        $cached = null;
    }
    $stale = !$cached || time() - (int)$cached['checkedTs'] > nibblySecurityCacheTtl((string)$cached['state']);
    $running = false;
    if ($run && ($force || ($stale && !$local))) {
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || flock($lock, LOCK_EX | LOCK_NB)) {
            try {
                $cached = nibblySecurityRunProbe(dirname(__DIR__), $baseUrl);
                nibblyJsonAtomicWrite($path, $cached);
                $stale = false;
            } finally {
                if ($lock) flock($lock, LOCK_UN);
            }
        } else {
            $running = true;
        }
        if ($lock) fclose($lock);
    }
    return ['result' => $cached, 'stale' => $stale, 'running' => $running];
}

/** Items "folders" and "http" come from the same (cached) network probe. */
function nibblySecurityProbeItems(string $baseUrl, bool $local, bool $devServer, string $scheme, bool $run, bool $force): array {
    $folders = ['id' => 'folders', 'canRun' => !$devServer && $baseUrl !== '', 'url' => $baseUrl];
    $result = null;
    if ($devServer) {
        // The single-threaded development server cannot request itself; router.php blocks these folders.
        $folders += ['state' => 'skipped', 'reason' => 'dev_server'];
    } elseif ($baseUrl === '') {
        $folders += ['state' => 'unknown', 'reason' => 'host'];
    } else {
        $probe = nibblySecurityProbeResult($baseUrl, $local, $run, $force);
        $result = $probe['result'];
        $folders += ['stale' => $probe['stale'], 'running' => $probe['running']];
        if (!$result) {
            $folders += $local ? ['state' => 'skipped', 'reason' => 'local'] : ['state' => 'pending'];
        } else {
            $exposed = array_values(array_column(array_filter($result['probes'], fn($probe) => $probe['state'] === 'exposed'), 'path'));
            // Accounts, credentials, submissions and backups are critical; deleted media alone is a warning.
            $critical = array_intersect($exposed, ['content/', 'backups/']) !== [];
            $folders += [
                'state' => ['exposed' => $critical ? 'critical' : 'warning', 'protected' => 'ok'][$result['state']] ?? 'unknown',
                'reason' => $result['reason'], 'detail' => $result['detail'], 'checkedAt' => $result['checkedAt'],
                'probes' => $result['probes'], 'exposed' => $exposed,
            ];
            if ($exposed) $folders['rules'] = nibblySecurityNginxRules(nibblySecurityBasePath());
        }
    }
    $mode = (string)($result['http'] ?? 'skipped');
    if ($devServer || $scheme !== 'https') {
        $http = ['state' => 'skipped', 'reason' => $devServer ? 'dev_server' : 'scheme'];
    } elseif (!$result) {
        $http = array_intersect_key($folders, ['state' => true, 'reason' => true]);
    } elseif ($mode === 'skipped') {
        $http = $result['state'] === 'unknown' ? ['state' => 'unknown'] : ['state' => 'skipped', 'reason' => 'port'];
    } else {
        $http = ['state' => $mode === 'open' ? 'warning' : 'ok', 'mode' => $mode];
    }
    return [$folders, ['id' => 'http'] + $http];
}

/** Security findings for the system status. $run allows the network probe. */
function nibblySecurityStatus(string $scheme = '', bool $run = false, bool $force = false): array {
    $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme : (nibblySecurityServerHttps() ? 'https' : 'http');
    $local = nibblySecurityIsLocalHost((string)($_SERVER['HTTP_HOST'] ?? ''));
    $devServer = PHP_SAPI === 'cli-server';
    $items = nibblySecurityProbeItems(nibblySecurityBaseUrl($scheme), $local, $devServer, $scheme, $run, $force);

    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    // A local reverse proxy that hides the visitor address makes every request look alike.
    $proxy = $remote !== '' && (nibblySecurityIsLoopbackIp($remote) || $remote === (string)($_SERVER['SERVER_ADDR'] ?? ''));
    // admin/api/bootstrap.php records the value before switching error display off for JSON.
    $displayErrors = defined('NIBBLY_INITIAL_DISPLAY_ERRORS') ? NIBBLY_INITIAL_DISPLAY_ERRORS : (string)ini_get('display_errors');
    $devLogin = !defined('NIBBLY_DEV_LOGIN') || NIBBLY_DEV_LOGIN === true;
    if ($local) {
        foreach (['https', 'cookie', 'client_ip', 'errors', 'dev_login'] as $id) {
            $items[] = ['id' => $id, 'state' => 'skipped', 'reason' => 'local'];
        }
    } else {
        $items[] = ['id' => 'https', 'state' => $scheme === 'https' ? 'ok' : 'warning'];
        $items[] = $scheme === 'https' ? ['id' => 'cookie', 'state' => nibblySecurityServerHttps() ? 'ok' : 'warning']
            : ['id' => 'cookie', 'state' => 'skipped', 'reason' => 'scheme'];
        $items[] = ['id' => 'client_ip', 'state' => $proxy ? 'warning' : 'ok', 'ip' => $proxy ? $remote : ''];
        $items[] = ['id' => 'errors', 'state' => strtolower($displayErrors) === 'stdout'
            || filter_var($displayErrors, FILTER_VALIDATE_BOOLEAN) ? 'warning' : 'ok'];
        $items[] = ['id' => 'dev_login', 'state' => !$devLogin ? 'ok' : (nibblySecurityIsLoopbackIp($remote) ? 'warning' : 'note')];
    }

    // End of upstream security support, https://www.php.net/supported-versions.php (extend with each PHP release).
    $until = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31',
        '8.5' => '2029-12-31'][PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION] ?? '';
    $items[] = ['id' => 'php', 'state' => $until !== '' && date('Y-m-d') > $until ? 'warning' : 'ok',
        'version' => PHP_VERSION, 'until' => $until];

    return ['local' => $local, 'devServer' => $devServer, 'items' => $items];
}
