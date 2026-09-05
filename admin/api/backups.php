<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'create-site-backup':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';

        // Tag as "manual" so the prune algorithm protects this backup
        // from automatic eviction — the admin explicitly asked for it.
        try {
            $created = backupWithLock(fn() => backupCreate('manual'));
        } catch (BackupLockException $e) {
            http_response_code(409);
            jsonResponse(false, null, $e->getMessage());
        }
        if (!$created['ok']) {
            jsonResponse(false, null, $created['message']);
        }

        // One-time download token. The file stays in the backup pool
        // after download (unlike the previous flow which deleted on
        // download) so it's still available later for restore.
        $token = bin2hex(random_bytes(32));
        $_SESSION['backup_download'] = [
            'token'   => $token,
            'file'    => $created['file'],
            'created' => time()
        ];

        jsonResponse(true, ['token' => $token, 'filename' => $created['file']], 'Backup created');
        break;

    case 'download-site-backup':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        // Validate one-time token
        if (!isset($_SESSION['backup_download'])) {
            http_response_code(403);
            jsonResponse(false, null, 'No backup download pending.');
        }

        $backupInfo = $_SESSION['backup_download'];
        $providedToken = $_GET['token'] ?? '';

        if (!hash_equals($backupInfo['token'], $providedToken)) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid download token.');
        }

        // Token expires after 5 minutes. The backup file stays — it's
        // a manual backup in the pool, the user can still download it
        // later via the scheduled-backup list (which generates a fresh
        // token).
        if (time() - $backupInfo['created'] > 300) {
            unset($_SESSION['backup_download']);
            http_response_code(410);
            jsonResponse(false, null, 'Download token expired.');
        }

        $zipPath = BACKUP_PATH . $backupInfo['file'];
        if (!is_file($zipPath)) {
            unset($_SESSION['backup_download']);
            http_response_code(404);
            jsonResponse(false, null, 'Backup file not found.');
        }

        // Consume the token (one-time use)
        $downloadName = $_GET['filename'] ?? 'site-backup.zip';
        $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '-', $downloadName);
        unset($_SESSION['backup_download']);

        // Release session lock before streaming
        session_write_close();

        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Send ZIP headers
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        // Stream file. Don't delete — the backup is now part of the
        // pool (tagged "manual") and the admin may want to keep it
        // server-side as well.
        readfile($zipPath);
        exit;

    case 'restore-site-backup':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        if (!class_exists('ZipArchive')) {
            jsonResponse(false, null, 'ZIP extension not available on this server.');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';

        // Source can be either an uploaded ZIP for off-server restore
        // or a pool file (the dashboard list
        // lets the admin restore an existing backup without uploading
        // it first). Normalise both to $uploadedFile + $maxSize.
        $poolFile = $_POST['pool_file'] ?? '';
        if ($poolFile !== '') {
            if (!backupIsPoolFilename($poolFile)) {
                jsonResponse(false, null, 'Invalid backup filename');
            }
            $uploadedFile = BACKUP_PATH . $poolFile;
            if (!is_file($uploadedFile)) {
                jsonResponse(false, null, 'Backup not found in pool');
            }
        } else {
            // Validate upload
            if (!isset($_FILES['backup_zip']) || $_FILES['backup_zip']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder missing.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                ];
                $code = $_FILES['backup_zip']['error'] ?? UPLOAD_ERR_NO_FILE;
                jsonResponse(false, null, $uploadErrors[$code] ?? 'Upload failed.');
            }
            $uploadedFile = $_FILES['backup_zip']['tmp_name'];
        }

        $mode = $_POST['restore_mode'] ?? '';
        if (!in_array($mode, ['full', 'content'])) {
            jsonResponse(false, null, 'Invalid restore mode.');
        }
        $maxSize = 500 * 1024 * 1024; // 500 MB
        if (filesize($uploadedFile) > $maxSize) {
            jsonResponse(false, null, 'File too large (max 500 MB).');
        }

        // Open and validate ZIP
        $zip = new ZipArchive();
        $result = $zip->open($uploadedFile);
        if ($result !== true) {
            jsonResponse(false, null, 'Invalid or corrupted ZIP file.');
        }

        // Collect all entries and run security checks
        $entries = [];
        $hasContentPage = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Path traversal check
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                jsonResponse(false, null, 'ZIP contains unsafe paths (path traversal detected).');
            }

            $entries[] = $name;

            // Check for content pages
            if (preg_match('#^content/pages/[a-z]{2}_[a-z0-9_-]+\.json$#i', $name)) {
                $hasContentPage = true;
            }
        }

        // Structure checks: required nibbly files must be present
        $requiredFiles = backupRestoreRequiredFiles();
        $missingFiles = [];
        foreach ($requiredFiles as $req) {
            if ($zip->locateName($req) === false) {
                $missingFiles[] = $req;
            }
        }
        if (!empty($missingFiles)) {
            $zip->close();
            jsonResponse(false, null, 'Not a valid nibbly backup. Missing: ' . implode(', ', $missingFiles));
        }
        if (!$hasContentPage) {
            $zip->close();
            jsonResponse(false, null, 'Not a valid nibbly backup. No content pages found.');
        }

        // File extension whitelist
        $allowedExtensions = backupRestoreAllowedExtensions();

        $rejectedPhpFiles = [];
        foreach ($entries as $entry) {
            // Skip directories
            if (str_ends_with($entry, '/')) continue;

            // Older full-site backups may contain development tests. Accept
            // the archive, but do not treat or restore these as site PHP.
            if (backupRestoreEntryIgnored($entry)) continue;

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            // Check PHP files are in allowed locations
            if ($ext === 'php' && !backupRestorePhpEntryAllowed($entry)) {
                $rejectedPhpFiles[] = $entry;
            }

            // Check file extension whitelist (skip dirs)
            if ($ext !== '' && !in_array($ext, $allowedExtensions) && basename($entry) !== '.htaccess') {
                // Silently skip — will not extract these files
            }
        }

        if (!empty($rejectedPhpFiles)) {
            $zip->close();
            jsonResponse(false, null, 'ZIP contains PHP files in unexpected locations: ' . implode(', ', array_slice($rejectedPhpFiles, 0, 5)));
        }

        $siteRoot = realpath(NIBBLY_ADMIN_DIR . '/..');
        require_once NIBBLY_ADMIN_DIR . '/../includes/restore-helper.php';
        $selected = [];
        $skipped = 0;
        foreach ($entries as $entry) {
            if (str_ends_with($entry, '/')) continue;
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (backupRestoreEntryIgnored($entry)
                || ($ext !== '' && !in_array($ext, $allowedExtensions, true) && basename($entry) !== '.htaccess')
                || ($mode === 'content' && !backupRestoreContentEntryAllowed($entry))) {
                $skipped++;
                continue;
            }
            $selected[] = $entry;
        }
        try {
            $extracted = backupWithLock(function () use ($zip, $selected, $siteRoot, $mode): int {
                if ($mode === 'full') {
                    $safetyBackup = backupCreate('manual');
                    if (empty($safetyBackup['ok'])) throw new RuntimeException('Pre-restore backup failed: ' . ($safetyBackup['message'] ?? 'unknown error'));
                }
                return nibblyRestoreFiles($zip, $selected, $siteRoot, $mode === 'content');
            });
        } catch (Throwable $error) {
            $zip->close();
            jsonResponse(false, null, $error->getMessage());
        }
        $zip->close();

        jsonResponse(true, [
            'extracted' => $extracted,
            'skipped' => $skipped,
            'mode' => $mode,
        ], $mode === 'full' ? 'Full site restored' : 'Content restored');
        break;

    // ============================================================
    // SCHEDULED BACKUPS — pool of *-backup-*-{tier}.zip files
    // ============================================================
    // These endpoints back the dashboard's "Automated backups" UI and
    // complement the manual create/download/restore flow above. The
    // ZIP-creation, retention, and tier logic lives in
    // includes/backup-helper.php so the cron CLI uses the exact same
    // code path as the admin UI.

    case 'backup-status':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        jsonResponse(true, backupStatus());
        break;

    case 'backup-list':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        jsonResponse(true, ['backups' => backupListAll()]);
        break;

    case 'backup-create-now':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        // Tag as "manual" — won't be auto-evicted by storage budget.
        try {
            $created = backupWithLock(fn() => backupCreate('manual'));
        } catch (BackupLockException $e) {
            http_response_code(409);
            jsonResponse(false, null, $e->getMessage());
        }
        if (!$created['ok']) {
            jsonResponse(false, null, $created['message']);
        }
        jsonResponse(true, $created, 'Backup created');
        break;

    case 'backup-prune':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        try {
            $deleted = backupWithLock(fn() => backupPrune());
        } catch (BackupLockException $e) {
            http_response_code(409);
            jsonResponse(false, null, $e->getMessage());
        }
        jsonResponse(true, ['deleted' => $deleted], count($deleted) . ' backup(s) pruned');
        break;

    case 'backup-delete':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $file = $_POST['file'] ?? '';
        if (!backupIsPoolFilename($file)) {
            jsonResponse(false, null, 'Invalid backup filename');
        }
        $path = BACKUP_PATH . $file;
        if (!is_file($path)) {
            jsonResponse(false, null, 'Backup not found');
        }
        if (!@unlink($path)) {
            jsonResponse(false, null, 'Could not delete backup');
        }
        jsonResponse(true, null, 'Backup deleted');
        break;

    case 'backup-upload-remote':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $file = $_POST['file'] ?? '';
        $targetId = $_POST['target_id'] ?? '';
        if (!backupIsPoolFilename($file)) {
            jsonResponse(false, null, 'Invalid backup filename');
        }
        if (!is_file(BACKUP_PATH . $file)) {
            jsonResponse(false, null, 'Backup not found');
        }
        try {
            $results = backupWithLock(fn() => backupUploadRemoteTargets($file, $targetId !== '' ? $targetId : null));
        } catch (BackupLockException $e) {
            http_response_code(409);
            jsonResponse(false, null, $e->getMessage());
        }
        $failed = array_values(array_filter($results, fn($result) => empty($result['ok'])));
        jsonResponse(empty($failed), ['results' => $results, 'status' => backupStatus()], empty($failed) ? 'Remote upload complete' : ($failed[0]['message'] ?? 'Remote upload failed'));
        break;

    case 'backup-remote-list':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $target = backupRemoteTargetById($_GET['target_id'] ?? '');
        if (!$target) jsonResponse(false, null, 'Remote target not found');
        try {
            backupRemoteRefreshTarget($target);
            backupSaveRemoteTarget($target);
            $result = backupRemoteList($target);
            jsonResponse(!empty($result['ok']), ['files' => $result['files'] ?? []], $result['message'] ?? '');
        } catch (Throwable $e) {
            jsonResponse(false, ['files' => []], $e->getMessage());
        }
        break;

    case 'backup-remote-import':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $target = backupRemoteTargetById($_POST['target_id'] ?? '');
        $file = $_POST['file'] ?? '';
        if (!$target) jsonResponse(false, null, 'Remote target not found');
        if (!backupIsPoolFilename($file)) jsonResponse(false, null, 'Invalid backup filename');
        if (!is_dir(BACKUP_PATH)) @mkdir(BACKUP_PATH, 0755, true);
        $destination = BACKUP_PATH . $file;
        if (is_file($destination)) jsonResponse(true, ['file' => $file, 'status' => backupStatus()], 'Backup already exists locally');
        try {
            backupRemoteRefreshTarget($target);
            backupSaveRemoteTarget($target);
            $result = backupRemoteDownload($file, $target, $destination);
        } catch (Throwable $e) {
            @unlink($destination);
            jsonResponse(false, null, $e->getMessage());
        }
        if (empty($result['ok'])) {
            @unlink($destination);
            jsonResponse(false, null, $result['message']);
        }
        jsonResponse(true, ['file' => $file, 'status' => backupStatus()], 'Backup imported');
        break;

    case 'backup-remote-delete':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $target = backupRemoteTargetById($_POST['target_id'] ?? '');
        $file = $_POST['file'] ?? '';
        if (!$target) jsonResponse(false, null, 'Remote target not found');
        try {
            backupRemoteRefreshTarget($target);
            backupSaveRemoteTarget($target);
            $result = backupRemoteDelete($file, $target);
            jsonResponse(!empty($result['ok']), null, $result['message'] ?? '');
        } catch (Throwable $e) {
            jsonResponse(false, null, $e->getMessage());
        }
        break;

    case 'backup-test-remote':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $targetId = $_POST['target_id'] ?? '';
        $config = backupConfig();
        $target = null;
        foreach ($config['remote_targets'] as $candidate) {
            if ($candidate['id'] === $targetId) {
                $target = $candidate;
                break;
            }
        }
        if (!$target) {
            jsonResponse(false, null, 'Remote target not found');
        }
        if (!is_dir(BACKUP_PATH)) @mkdir(BACKUP_PATH, 0755, true);
        $testFile = BACKUP_PATH . 'nibbly-remote-test-' . date('Ymd-His') . '.txt';
        file_put_contents($testFile, "nibbly remote backup test\n" . date('c') . "\n");
        try {
            backupRemoteRefreshTarget($target);
            $result = backupRemoteUpload($testFile, $target);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
        @unlink($testFile);
        $target['last_upload'] = date('c');
        $target['last_status'] = $result['ok'] ? 'success' : 'error';
        $target['last_message'] = $result['message'];
        $target['last_file'] = basename($testFile);
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $targetId) {
                $config['remote_targets'][$idx] = $target;
                break;
            }
        }
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        jsonResponse($result['ok'], ['status' => backupStatus()], $result['message']);
        break;

    case 'backup-dropbox-oauth-start':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $targetId = $_GET['target_id'] ?? '';
        $config = backupConfig();
        $target = null;
        foreach ($config['remote_targets'] as $candidate) {
            if ($candidate['id'] === $targetId && $candidate['type'] === 'dropbox') {
                $target = $candidate;
                break;
            }
        }
        if (!$target) jsonResponse(false, null, 'Dropbox target not found');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), '/\\');
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        if ($brokerUrl !== '') {
            $state = bin2hex(random_bytes(24));
            $exchangeSecret = bin2hex(random_bytes(32));
            $_SESSION['backup_dropbox_broker_oauth'] = [
                'state' => $state,
                'target_id' => $targetId,
                'exchange_secret' => $exchangeSecret,
                'created' => time(),
            ];
            $returnUrl = $scheme . '://' . $host . $base . '/api.php?action=backup-dropbox-broker-callback';
            $brokerStartUrl = $brokerUrl . '/dropbox/start?' . http_build_query([
                'return_url' => $returnUrl,
                'state' => $state,
                'exchange_challenge' => hash('sha256', $exchangeSecret),
            ]);
            header_remove('Content-Type');
            header('Location: ' . $brokerStartUrl, true, 302);
            exit;
        }

        $appKey = $target['settings']['app_key'] ?? '';
        if ($appKey === '') $appKey = backupRemoteGlobalOAuthValue('dropbox', 'app_key');
        if ($appKey === '') jsonResponse(false, null, 'Dropbox app key is missing');

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(24));
        $_SESSION['backup_dropbox_oauth'] = [
            'state' => $state,
            'target_id' => $targetId,
            'code_verifier' => $verifier,
            'created' => time(),
        ];
        $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-dropbox-oauth-callback';
        $authUrl = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
            'client_id' => $appKey,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'files.content.write files.content.read files.metadata.read',
            'token_access_type' => 'offline',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        header_remove('Content-Type');
        header('Location: ' . $authUrl, true, 302);
        exit;

    case 'backup-dropbox-oauth-callback':
        if (!isAdmin()) redirectHtml('Dropbox connection failed', 'Your admin session is no longer active.');
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $pending = $_SESSION['backup_dropbox_oauth'] ?? null;
        if (!is_array($pending) || time() - ($pending['created'] ?? 0) > 600) {
            redirectHtml('Dropbox connection failed', 'The authorization request expired.');
        }
        if (!hash_equals($pending['state'], $_GET['state'] ?? '')) {
            redirectHtml('Dropbox connection failed', 'The authorization state did not match.');
        }
        $code = $_GET['code'] ?? '';
        if ($code === '') {
            redirectHtml('Dropbox connection failed', $_GET['error_description'] ?? ($_GET['error'] ?? 'Dropbox did not return an authorization code.'));
        }
        $config = backupConfig();
        $targetIndex = null;
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $pending['target_id'] && $candidate['type'] === 'dropbox') {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null) redirectHtml('Dropbox connection failed', 'Dropbox target no longer exists.');
        $target = $config['remote_targets'][$targetIndex];
        $appKey = $target['settings']['app_key'] ?? '';
        if ($appKey === '') $appKey = backupRemoteGlobalOAuthValue('dropbox', 'app_key');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), '/\\');
        $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-dropbox-oauth-callback';
        try {
            $tokenResult = backupRemoteCurl('https://api.dropboxapi.com/oauth2/token', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'client_id' => $appKey,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $pending['code_verifier'],
                ]),
                'timeout' => 60,
            ]);
        } catch (Throwable $e) {
            redirectHtml('Dropbox connection failed', $e->getMessage());
        }
        $tokenJson = json_decode($tokenResult['body'], true);
        if (!is_array($tokenJson) || empty($tokenJson['access_token'])) {
            redirectHtml('Dropbox connection failed', 'Dropbox token exchange failed.');
        }
        $target['settings']['access_token'] = $tokenJson['access_token'];
        if (!empty($tokenJson['refresh_token'])) {
            $target['settings']['refresh_token'] = $tokenJson['refresh_token'];
        }
        if (!empty($tokenJson['expires_in'])) {
            $target['settings']['expires_at'] = time() + (int)$tokenJson['expires_in'];
        }
        if (!empty($tokenJson['account_id'])) {
            $target['settings']['account_id'] = $tokenJson['account_id'];
        }
        $target['last_status'] = 'success';
        $target['last_message'] = 'Dropbox connected.';
        $target['last_upload'] = date('c');
        $config['remote_targets'][$targetIndex] = $target;
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        unset($_SESSION['backup_dropbox_oauth']);
        redirectHtml('Dropbox connected', 'You can close this tab and return to nibbly.', 'dashboard');

    case 'backup-dropbox-broker-callback':
        if (!isAdmin()) redirectHtml('Dropbox connection failed', 'Your admin session is no longer active.');
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $pending = $_SESSION['backup_dropbox_broker_oauth'] ?? null;
        if (!is_array($pending) || time() - ($pending['created'] ?? 0) > 600) {
            redirectHtml('Dropbox connection failed', 'The authorization request expired.');
        }
        if (!hash_equals($pending['state'], $_GET['state'] ?? '')) {
            redirectHtml('Dropbox connection failed', 'The authorization state did not match.');
        }
        $exchangeId = $_GET['exchange_id'] ?? '';
        if (!preg_match('/^[a-f0-9]{32,64}$/', $exchangeId)) {
            redirectHtml('Dropbox connection failed', 'The auth broker did not return a valid exchange ID.');
        }
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        try {
            $exchangeResult = backupRemoteCurl($brokerUrl . '/token/exchange', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'provider' => 'dropbox',
                    'exchange_id' => $exchangeId,
                    'exchange_secret' => $pending['exchange_secret'],
                ]),
                'timeout' => 60,
            ]);
        } catch (Throwable $e) {
            redirectHtml('Dropbox connection failed', $e->getMessage());
        }
        $exchangeJson = json_decode($exchangeResult['body'], true);
        if (!is_array($exchangeJson) || empty($exchangeJson['ok']) || empty($exchangeJson['token']['access_token'])) {
            redirectHtml('Dropbox connection failed', 'The auth broker token exchange failed.');
        }
        $config = backupConfig();
        $targetIndex = null;
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $pending['target_id'] && $candidate['type'] === 'dropbox') {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null) redirectHtml('Dropbox connection failed', 'Dropbox target no longer exists.');
        $target = $config['remote_targets'][$targetIndex];
        $tokenJson = $exchangeJson['token'];
        $target['settings']['access_token'] = $tokenJson['access_token'];
        if (!empty($tokenJson['refresh_token'])) {
            $target['settings']['refresh_token'] = $tokenJson['refresh_token'];
        }
        if (!empty($tokenJson['expires_in'])) {
            $target['settings']['expires_at'] = time() + (int)$tokenJson['expires_in'];
        }
        if (!empty($tokenJson['account_id'])) {
            $target['settings']['account_id'] = $tokenJson['account_id'];
        }
        $target['settings']['oauth_broker'] = backupRemoteOAuthBrokerUrl();
        $target['last_status'] = 'success';
        $target['last_message'] = 'Dropbox connected.';
        $target['last_upload'] = date('c');
        $config['remote_targets'][$targetIndex] = $target;
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        unset($_SESSION['backup_dropbox_broker_oauth']);
        redirectHtml('Dropbox connected', 'You can close this tab and return to nibbly.', 'dashboard');

    case 'backup-google-oauth-start':
    case 'backup-onedrive-oauth-start':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $type = $action === 'backup-google-oauth-start' ? 'google_drive' : 'onedrive';
        $label = $type === 'google_drive' ? 'Google Drive' : 'OneDrive';
        $targetId = $_GET['target_id'] ?? '';
        $config = backupConfig();
        $target = null;
        foreach ($config['remote_targets'] as $candidate) {
            if ($candidate['id'] === $targetId && $candidate['type'] === $type) {
                $target = $candidate;
                break;
            }
        }
        if (!$target) jsonResponse(false, null, "$label target not found");
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), '/\\');
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        if (($type === 'google_drive' || $type === 'onedrive') && $brokerUrl !== '') {
            $state = bin2hex(random_bytes(24));
            $exchangeSecret = bin2hex(random_bytes(32));
            $brokerSessionKey = $type === 'google_drive' ? 'backup_google_broker_oauth' : 'backup_onedrive_broker_oauth';
            $brokerPath = $type === 'google_drive' ? 'google' : 'onedrive';
            $brokerCallbackAction = $type === 'google_drive' ? 'backup-google-broker-callback' : 'backup-onedrive-broker-callback';
            $_SESSION[$brokerSessionKey] = [
                'state' => $state,
                'target_id' => $targetId,
                'exchange_secret' => $exchangeSecret,
                'created' => time(),
            ];
            $returnUrl = $scheme . '://' . $host . $base . '/api.php?action=' . $brokerCallbackAction;
            $brokerStartUrl = $brokerUrl . '/' . $brokerPath . '/start?' . http_build_query([
                'return_url' => $returnUrl,
                'state' => $state,
                'exchange_challenge' => hash('sha256', $exchangeSecret),
            ]);
            header_remove('Content-Type');
            header('Location: ' . $brokerStartUrl, true, 302);
            exit;
        }
        $clientId = $target['settings']['client_id'] ?? '';
        if ($clientId === '') $clientId = backupRemoteGlobalOAuthValue($type, 'client_id');
        if ($clientId === '') jsonResponse(false, null, "$label client ID is missing");

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(24));
        $_SESSION['backup_oauth'] = [
            'provider' => $type,
            'state' => $state,
            'target_id' => $targetId,
            'code_verifier' => $verifier,
            'created' => time(),
        ];
        if ($type === 'google_drive') {
            $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-google-oauth-callback';
            $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $clientId,
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'scope' => 'https://www.googleapis.com/auth/drive.file',
                'access_type' => 'offline',
                'include_granted_scopes' => 'true',
                'prompt' => 'consent',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]);
        } else {
            $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-onedrive-oauth-callback';
            $authUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query([
                'client_id' => $clientId,
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
                'response_mode' => 'query',
                'state' => $state,
                'scope' => 'offline_access Files.ReadWrite.AppFolder',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]);
        }
        header_remove('Content-Type');
        header('Location: ' . $authUrl, true, 302);
        exit;

    case 'backup-google-broker-callback':
    case 'backup-onedrive-broker-callback':
        $type = $action === 'backup-google-broker-callback' ? 'google_drive' : 'onedrive';
        $label = $type === 'google_drive' ? 'Google Drive' : 'OneDrive';
        $sessionKey = $type === 'google_drive' ? 'backup_google_broker_oauth' : 'backup_onedrive_broker_oauth';
        if (!isAdmin()) redirectHtml("$label connection failed", 'Your admin session is no longer active.');
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $pending = $_SESSION[$sessionKey] ?? null;
        if (!is_array($pending) || time() - ($pending['created'] ?? 0) > 600) {
            redirectHtml("$label connection failed", 'The authorization request expired.');
        }
        if (!hash_equals($pending['state'], $_GET['state'] ?? '')) {
            redirectHtml("$label connection failed", 'The authorization state did not match.');
        }
        $exchangeId = $_GET['exchange_id'] ?? '';
        if (!preg_match('/^[a-f0-9]{32,64}$/', $exchangeId)) {
            redirectHtml("$label connection failed", 'The auth broker did not return a valid exchange ID.');
        }
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        try {
            $exchangeResult = backupRemoteCurl($brokerUrl . '/token/exchange', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'provider' => $type,
                    'exchange_id' => $exchangeId,
                    'exchange_secret' => $pending['exchange_secret'],
                ]),
                'timeout' => 60,
            ]);
        } catch (Throwable $e) {
            redirectHtml("$label connection failed", $e->getMessage());
        }
        $exchangeJson = json_decode($exchangeResult['body'], true);
        if (!is_array($exchangeJson) || empty($exchangeJson['ok']) || empty($exchangeJson['token']['access_token'])) {
            redirectHtml("$label connection failed", 'The auth broker token exchange failed.');
        }
        $config = backupConfig();
        $targetIndex = null;
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $pending['target_id'] && $candidate['type'] === $type) {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null) redirectHtml("$label connection failed", "$label target no longer exists.");
        $target = $config['remote_targets'][$targetIndex];
        $tokenJson = $exchangeJson['token'];
        $target['settings']['access_token'] = $tokenJson['access_token'];
        if (!empty($tokenJson['refresh_token'])) {
            $target['settings']['refresh_token'] = $tokenJson['refresh_token'];
        }
        if (!empty($tokenJson['expires_in'])) {
            $target['settings']['expires_at'] = time() + (int)$tokenJson['expires_in'];
        }
        if (!empty($tokenJson['client_id'])) {
            $target['settings']['client_id'] = $tokenJson['client_id'];
        }
        $target['settings']['oauth_broker'] = backupRemoteOAuthBrokerUrl();
        $target['last_status'] = 'success';
        $target['last_message'] = "$label connected.";
        $target['last_upload'] = date('c');
        $config['remote_targets'][$targetIndex] = $target;
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        unset($_SESSION[$sessionKey]);
        redirectHtml("$label connected", 'You can close this tab and return to nibbly.', 'dashboard');

    case 'backup-google-oauth-callback':
    case 'backup-onedrive-oauth-callback':
        $type = $action === 'backup-google-oauth-callback' ? 'google_drive' : 'onedrive';
        $label = $type === 'google_drive' ? 'Google Drive' : 'OneDrive';
        if (!isAdmin()) redirectHtml("$label connection failed", 'Your admin session is no longer active.');
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $pending = $_SESSION['backup_oauth'] ?? null;
        if (!is_array($pending) || ($pending['provider'] ?? '') !== $type || time() - ($pending['created'] ?? 0) > 600) {
            redirectHtml("$label connection failed", 'The authorization request expired.');
        }
        if (!hash_equals($pending['state'], $_GET['state'] ?? '')) {
            redirectHtml("$label connection failed", 'The authorization state did not match.');
        }
        $code = $_GET['code'] ?? '';
        if ($code === '') {
            redirectHtml("$label connection failed", $_GET['error_description'] ?? ($_GET['error'] ?? "$label did not return an authorization code."));
        }
        $config = backupConfig();
        $targetIndex = null;
        foreach ($config['remote_targets'] as $idx => $candidate) {
            if ($candidate['id'] === $pending['target_id'] && $candidate['type'] === $type) {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null) redirectHtml("$label connection failed", "$label target no longer exists.");
        $target = $config['remote_targets'][$targetIndex];
        $clientId = $target['settings']['client_id'] ?? '';
        if ($clientId === '') $clientId = backupRemoteGlobalOAuthValue($type, 'client_id');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'), '/\\');
        if ($type === 'google_drive') {
            $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-google-oauth-callback';
            $tokenUrl = 'https://oauth2.googleapis.com/token';
            $tokenBody = [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $pending['code_verifier'],
            ];
            $clientSecret = $target['settings']['client_secret'] ?? backupRemoteGlobalOAuthValue('google_drive', 'client_secret');
            if ($clientSecret !== '') $tokenBody['client_secret'] = $clientSecret;
        } else {
            $redirectUri = $scheme . '://' . $host . $base . '/api.php?action=backup-onedrive-oauth-callback';
            $tokenUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
            $tokenBody = [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $pending['code_verifier'],
                'scope' => 'offline_access Files.ReadWrite.AppFolder',
            ];
            $clientSecret = $target['settings']['client_secret'] ?? backupRemoteGlobalOAuthValue('onedrive', 'client_secret');
            if ($clientSecret !== '') $tokenBody['client_secret'] = $clientSecret;
        }
        try {
            $tokenResult = backupRemoteCurl($tokenUrl, [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query($tokenBody),
                'timeout' => 60,
            ]);
        } catch (Throwable $e) {
            redirectHtml("$label connection failed", $e->getMessage());
        }
        $tokenJson = json_decode($tokenResult['body'], true);
        if (!is_array($tokenJson) || empty($tokenJson['access_token'])) {
            redirectHtml("$label connection failed", "$label token exchange failed.");
        }
        $target['settings']['access_token'] = $tokenJson['access_token'];
        if (!empty($tokenJson['refresh_token'])) {
            $target['settings']['refresh_token'] = $tokenJson['refresh_token'];
        }
        if (!empty($tokenJson['expires_in'])) {
            $target['settings']['expires_at'] = time() + (int)$tokenJson['expires_in'];
        }
        $target['last_status'] = 'success';
        $target['last_message'] = "$label connected.";
        $target['last_upload'] = date('c');
        $config['remote_targets'][$targetIndex] = $target;
        backupSaveConfig(['remote_targets' => $config['remote_targets']]);
        unset($_SESSION['backup_oauth']);
        redirectHtml("$label connected", 'You can close this tab and return to nibbly.', 'dashboard');

    case 'backup-prepare-download':
        // Issues a one-time token for downloading an existing backup
        // from the pool. The download itself reuses the existing
        // download-site-backup endpoint — same token format.
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $file = $_POST['file'] ?? '';
        if (!backupIsPoolFilename($file)) {
            jsonResponse(false, null, 'Invalid backup filename');
        }
        if (!is_file(BACKUP_PATH . $file)) {
            jsonResponse(false, null, 'Backup not found');
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['backup_download'] = [
            'token'   => $token,
            'file'    => $file,
            'created' => time(),
        ];
        jsonResponse(true, ['token' => $token, 'filename' => $file], 'Download token issued');
        break;

    case 'backup-update-settings':
        if (!isAdmin()) jsonResponse(false, null, 'Forbidden');
        if (!validateCsrfToken()) {
            http_response_code(403);
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        require_once NIBBLY_ADMIN_DIR . '/../includes/backup-helper.php';
        $patch = [];
        if (isset($_POST['enabled'])) {
            $patch['enabled'] = ($_POST['enabled'] === 'true' || $_POST['enabled'] === '1');
        }
        if (isset($_POST['storage_limit_mb'])) {
            $patch['storage_limit_mb'] = max(0, (int)$_POST['storage_limit_mb']);
        }
        if (isset($_POST['cron_mode'])) {
            $patch['cron_mode'] = $_POST['cron_mode'] === 'web' ? 'web' : 'server';
        }
        $retention = [];
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $tier) {
            $key = "retention_$tier";
            if (isset($_POST[$key])) $retention[$tier] = max(0, (int)$_POST[$key]);
        }
        if (!empty($retention)) $patch['retention'] = $retention;
        if (isset($_POST['remote_targets'])) {
            $submitted = json_decode($_POST['remote_targets'], true);
            if (!is_array($submitted)) {
                jsonResponse(false, null, 'Invalid remote target settings');
            }
            $patch['remote_targets'] = backupRemoteMergeSubmittedTargets($submitted);
        }
        if (empty($patch)) {
            jsonResponse(false, null, 'No settings to update');
        }
        if (!backupSaveConfig($patch)) {
            jsonResponse(false, null, 'Could not write settings');
        }
        jsonResponse(true, backupStatus(), 'Backup settings updated');
        break;

    // (No backup-restore-from-pool — restore-site-backup now accepts
    // either a file upload OR a `pool_file` parameter referencing a
    // file in BACKUP_PATH. The dashboard uses the pool_file form.)

    // ============================================================
    // USER MANAGEMENT (admin only)
    // ============================================================

}
