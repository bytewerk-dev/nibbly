<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

require_once NIBBLY_ADMIN_DIR . '/../includes/svg-sanitizer.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/media-states.php';
require_once NIBBLY_ADMIN_DIR . '/../includes/media-local-tools.php';
require_once __DIR__ . '/media-operations.php';

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'list-images':
        jsonResponse(true, listImageFiles(IMAGES_PATH, '../assets/images/'));
        break;

    case 'list-media':
        $requestedType = $_GET['type'] ?? 'all';
        $trash = ($_GET['trash'] ?? '0') === '1';
        $types = $requestedType === 'all'
            ? array_keys(getMediaConfig())
            : array_filter(array_map('normalizeMediaType', explode(',', $requestedType)));

        $items = [];
        foreach ($types as $type) {
            $items = array_merge($items, listMediaFiles($type, $trash));
        }
        $folders = [];
        foreach ($types as $type) {
            $folders = array_merge($folders, listMediaFolders($type, $trash));
        }
        // Keep the tree order (parents before children) and only drop duplicates across types
        $folders = array_values(array_unique($folders));
        usort($items, function($a, $b) {
            return ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0);
        });

        jsonResponse(true, [
            'items' => $items,
            'folders' => $folders,
            // What the server lets through and what Nibbly allows, so the library
            // can check before sending and say which limit applies
            'limits' => nibblyMediaUploadLimits(),
            // Registered media states for the library's properties panel (includes/media-states.php)
            'states' => nibblyMediaStatesPublic(),
            // Reveal/open/path on the editor's own machine, null on a web host (includes/media-local-tools.php)
            'localTools' => nibblyLocalToolsInfo(),
            // ZIP download and import need the PHP zip extension; without it the library hides both
            'zip' => class_exists('ZipArchive'),
            'types' => array_values(array_map(function($config) {
                return [
                    'type' => $config['type'],
                    'label' => $config['label'],
                    'extensions' => $config['extensions'],
                    'maxSize' => $config['maxSize'],
                ];
            }, getMediaConfig())),
        ]);
        break;

    case 'upload-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $config = getMediaConfig($type);
        if (!$config) {
            jsonResponse(false, null, 'Invalid media type');
        }

        $field = $config['field'];
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $field = isset($_FILES['file']) ? 'file' : $field;
        }

        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            // A request above post_max_size reaches PHP with an empty $_FILES, so an
            // empty upload with a Content-Length counts as "too large" as well
            $code = $_FILES[$field]['error'] ?? null;
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE || (empty($_FILES) && $contentLength > nibblyUploadServerLimit())) {
                $serverMb = nibblyMegabytes(nibblyUploadServerLimit());
                jsonResponse(false, ['serverLimitBytes' => nibblyUploadServerLimit()], sprintf('File too large: the server accepts uploads up to %s MB (upload_max_filesize / post_max_size)', $serverMb));
            }
            jsonResponse(false, null, $code === UPLOAD_ERR_PARTIAL ? 'Upload error: the file arrived only partially' : 'Upload error');
        }

        $file = $_FILES[$field];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $config['extensions'], true)) {
            jsonResponse(false, null, 'Invalid file extension');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        unset($finfo);
        if (!in_array($mimeType, $config['mimeTypes'], true)) {
            jsonResponse(false, null, 'Invalid file type');
        }

        if ($file['size'] > $config['maxSize']) {
            jsonResponse(false, null, sprintf('File too large: the limit for %s is %s MB', $config['type'], nibblyMegabytes((int)$config['maxSize'])));
        }

        $safeName = nibblyMediaSafeStem(pathinfo($file['name'], PATHINFO_FILENAME), $config['fallbackName']);
        $folder = trim((string)($_POST['folder'] ?? ''));
        if ($folder !== '' && !validateMediaFolderName($folder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }

        $stored = nibblyMediaStoreIncoming($type, $file['tmp_name'], $mimeType, $safeName, $extension, $folder, 'move_uploaded_file');
        jsonResponse($stored['ok'], $stored['ok'] ? $stored['data'] : null, $stored['message']);
        break;

    case 'bulk-media':
        // Several files at once: op = trash | move | restore | purge, items = JSON [{type, name}],
        // folder = target folder for move ('' = main folder; a new folder is created).
        // Each file is handled on its own; the response lists what worked and what did not.
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $op = (string)($_POST['op'] ?? '');
        if (!in_array($op, ['trash', 'move', 'restore', 'purge'], true)) {
            jsonResponse(false, null, 'Invalid bulk action');
        }
        [$items, $problem] = nibblyMediaBatchItems($_POST['items'] ?? '');
        if ($items === null) {
            jsonResponse(false, null, $problem);
        }
        $targetFolder = trim((string)($_POST['folder'] ?? ''));
        if ($op === 'move' && $targetFolder !== '' && !validateMediaFolderName($targetFolder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }
        @set_time_limit(300);

        $done = [];
        $failed = [];
        foreach ($items as $item) {
            $result = match ($op) {
                'trash' => nibblyMediaOpTrash($item['type'], $item['name']),
                'move' => nibblyMediaOpMove($item['type'], $item['name'], $targetFolder),
                'restore' => nibblyMediaOpRestore($item['type'], $item['name']),
                'purge' => nibblyMediaOpPurge($item['type'], $item['name']),
            };
            if ($result['ok']) {
                $done[] = ['type' => $item['type'], 'name' => $item['name'], 'newName' => $result['data']['name'] ?? $item['name']];
            } else {
                $failed[] = ['type' => $item['type'], 'name' => $item['name'], 'message' => $result['message']];
            }
        }
        jsonResponse($done !== [], [
            'op' => $op,
            'folder' => $op === 'move' ? $targetFolder : null,
            'done' => $done,
            'failed' => $failed,
        ], $done === [] ? ($failed[0]['message'] ?? 'Error') : ($failed ? 'Some files could not be processed' : 'Done'));
        break;

    case 'zip-media':
        // Download selected library files as one ZIP archive (items = JSON [{type, name}])
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        if (!class_exists('ZipArchive')) {
            jsonResponse(false, null, 'ZIP archives are not supported on this server (PHP zip extension missing)');
        }
        [$items, $problem] = nibblyMediaBatchItems($_POST['items'] ?? '');
        if ($items === null) {
            jsonResponse(false, null, $problem);
        }
        $files = [];
        $total = 0;
        foreach ($items as $item) {
            $path = getMediaConfig($item['type'])['path'] . $item['name'];
            if (!is_file($path) || is_link($path)) {
                jsonResponse(false, ['name' => $item['name']], 'File not found: ' . $item['name']);
            }
            $total += (int)filesize($path);
            if ($total > NIBBLY_MEDIA_ZIP_DOWNLOAD_MAX_BYTES) {
                jsonResponse(false, null, sprintf('The selection is too large for one archive (at most %s MB)', nibblyMegabytes(NIBBLY_MEDIA_ZIP_DOWNLOAD_MAX_BYTES)));
            }
            $files[] = $path;
        }
        @set_time_limit(300);
        $workDir = nibblyMediaWorkDir();
        if ($workDir === null) {
            jsonResponse(false, null, 'No writable temporary folder for the archive');
        }
        register_shutdown_function('nibblyMediaRemoveWorkDir', $workDir);
        $zipPath = $workDir . '/media.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            jsonResponse(false, null, 'Could not create the archive');
        }
        foreach (nibblyMediaZipEntryNames($items) as $index => $entryName) {
            if (!$zip->addFile($files[$index], $entryName)) {
                $zip->close();
                jsonResponse(false, null, 'Could not create the archive');
            }
            if (nibblyMediaZipStoredExtension(strtolower(pathinfo($entryName, PATHINFO_EXTENSION)))) {
                $zip->setCompressionName($entryName, ZipArchive::CM_STORE);
            }
        }
        if (!$zip->close() || !is_file($zipPath)) {
            jsonResponse(false, null, 'Could not create the archive');
        }
        $downloadName = 'media-' . date('Y-m-d-His') . '.zip';
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($zipPath);
        exit;

    case 'unzip-media':
        // Import the files of an uploaded ZIP archive into the library. Each entry is checked on its own:
        // safe path, no link, not encrypted, allowed extension, real file type (finfo) matching it, size
        // limits and expansion ratio; SVG is sanitized and images take the normal upload path (web version,
        // AI label, responsive sizes). Nothing is overwritten. folder = target folder, structure = 1 keeps
        // the archive's folders, subfolder = 1 puts everything into a new folder named after the archive.
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        if (!class_exists('ZipArchive')) {
            jsonResponse(false, null, 'ZIP archives are not supported on this server (PHP zip extension missing)');
        }
        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            $code = is_array($upload) ? ($upload['error'] ?? null) : null;
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                jsonResponse(false, ['serverLimitBytes' => nibblyUploadServerLimit()], sprintf('File too large: the server accepts uploads up to %s MB (upload_max_filesize / post_max_size)', nibblyMegabytes(nibblyUploadServerLimit())));
            }
            jsonResponse(false, null, $code === UPLOAD_ERR_PARTIAL ? 'Upload error: the file arrived only partially' : 'Upload error');
        }
        if (strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION)) !== 'zip') {
            jsonResponse(false, null, 'Invalid file extension');
        }
        if ((int)$upload['size'] > NIBBLY_MEDIA_ZIP_UPLOAD_MAX_BYTES) {
            jsonResponse(false, null, sprintf('File too large: archives may have up to %s MB', nibblyMegabytes(NIBBLY_MEDIA_ZIP_UPLOAD_MAX_BYTES)));
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $archiveMime = (string)finfo_file($finfo, $upload['tmp_name']);
        $head = (string)@file_get_contents($upload['tmp_name'], false, null, 0, 4);
        if (!in_array($archiveMime, ['application/zip', 'application/x-zip-compressed', 'application/x-zip'], true) || $head !== "PK\x03\x04") {
            jsonResponse(false, null, 'Invalid file type');
        }

        $folder = trim((string)($_POST['folder'] ?? ''));
        if ($folder !== '' && !validateMediaFolderName($folder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }
        if (($_POST['subfolder'] ?? '0') === '1') {
            $archiveFolder = nibblyMediaSafeFolderSegment(pathinfo((string)$upload['name'], PATHINFO_FILENAME));
            if ($archiveFolder === '') $archiveFolder = 'zip-' . date('Y-m-d');
            $folder = $folder !== '' ? $folder . '/' . $archiveFolder : $archiveFolder;
            if (!validateMediaFolderName($folder)) {
                jsonResponse(false, null, 'Invalid folder name');
            }
        }
        $keepStructure = ($_POST['structure'] ?? '1') === '1';

        $zip = new ZipArchive();
        if ($zip->open($upload['tmp_name'], ZipArchive::RDONLY) !== true) {
            jsonResponse(false, null, 'The archive could not be read');
        }
        if ($zip->numFiles > NIBBLY_MEDIA_ZIP_MAX_ENTRIES) {
            $zip->close();
            jsonResponse(false, null, sprintf('The archive has too many entries (at most %d)', NIBBLY_MEDIA_ZIP_MAX_ENTRIES));
        }

        // First pass: decide per entry from the central directory, before anything is written
        $plan = [];
        $skipped = [];
        $ignored = 0;
        $expanded = 0;
        $folderDepth = $folder === '' ? 0 : count(explode('/', $folder));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
            if (!is_array($stat)) {
                $zip->close();
                jsonResponse(false, null, 'The archive could not be read');
            }
            $entryName = (string)$stat['name'];
            if (str_ends_with(str_replace('\\', '/', $entryName), '/')) continue; // folder entry
            $parsed = nibblyMediaZipParseEntryName($entryName);
            if (!empty($parsed['ignore'])) { $ignored++; continue; }
            $label = mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '?', $entryName), 0, 200);
            if (isset($parsed['skip'])) { $skipped[] = ['entry' => $label, 'reason' => $parsed['skip']]; continue; }
            $mode = nibblyMediaZipEntryMode($zip, $i);
            if ($mode !== null && ($mode & 0xF000) !== 0 && ($mode & 0xF000) !== 0x8000) {
                $skipped[] = ['entry' => $label, 'reason' => 'not_regular']; // symbolic link, device
                continue;
            }
            if ((int)($stat['encryption_method'] ?? 0) !== 0) {
                $skipped[] = ['entry' => $label, 'reason' => 'encrypted'];
                continue;
            }
            $extension = strtolower(pathinfo($parsed['file'], PATHINFO_EXTENSION));
            $type = nibblyMediaTypeForExtension($extension);
            if ($type === '') {
                $skipped[] = ['entry' => $label, 'reason' => 'type_not_allowed'];
                continue;
            }
            $config = getMediaConfig($type);
            $size = (int)$stat['size'];
            $compressed = (int)$stat['comp_size'];
            if ($size > $config['maxSize']) {
                $skipped[] = ['entry' => $label, 'reason' => 'too_large'];
                continue;
            }
            if ($size > 1048576 && ($compressed <= 0 || $size / $compressed > NIBBLY_MEDIA_ZIP_MAX_RATIO)) {
                $zip->close();
                jsonResponse(false, null, 'The archive was rejected: an entry expands suspiciously far (ZIP bomb protection)');
            }
            $segments = [];
            if ($keepStructure) {
                foreach ($parsed['folders'] as $segment) {
                    $safe = nibblyMediaSafeFolderSegment($segment);
                    if ($safe !== '') $segments[] = $safe;
                }
            }
            if ($folderDepth + count($segments) > NIBBLY_MEDIA_MAX_DEPTH) {
                $skipped[] = ['entry' => $label, 'reason' => 'too_deep'];
                continue;
            }
            if (count($plan) >= NIBBLY_MEDIA_ZIP_MAX_FILES) {
                $zip->close();
                jsonResponse(false, null, sprintf('The archive has too many files (at most %d)', NIBBLY_MEDIA_ZIP_MAX_FILES));
            }
            $expanded += $size;
            if ($expanded > NIBBLY_MEDIA_ZIP_MAX_EXPANDED_BYTES) {
                $zip->close();
                jsonResponse(false, null, sprintf('The archive expands to more than %s MB', nibblyMegabytes(NIBBLY_MEDIA_ZIP_MAX_EXPANDED_BYTES)));
            }
            $plan[] = [
                'stat' => $stat,
                'label' => $label,
                'type' => $type,
                'extension' => $extension,
                'stem' => nibblyMediaSafeStem(pathinfo($parsed['file'], PATHINFO_FILENAME), $config['fallbackName']),
                'folder' => implode('/', array_filter([$folder, implode('/', $segments)], 'strlen')),
            ];
        }

        // Second pass: extract each planned entry into a private folder, check the content, store it
        @set_time_limit(600);
        $workDir = nibblyMediaWorkDir();
        if ($workDir === null) {
            $zip->close();
            jsonResponse(false, null, 'No writable temporary folder for the archive');
        }
        register_shutdown_function('nibblyMediaRemoveWorkDir', $workDir);
        $imported = [];
        foreach ($plan as $index => $entry) {
            $tmp = $workDir . '/entry-' . $index;
            if (!nibblyMediaZipExtractEntry($zip, $entry['stat'], $tmp)) {
                $skipped[] = ['entry' => $entry['label'], 'reason' => 'damaged'];
                continue;
            }
            $config = getMediaConfig($entry['type']);
            $mimeType = (string)finfo_file($finfo, $tmp);
            if (!in_array($mimeType, $config['mimeTypes'], true)) {
                @unlink($tmp);
                $skipped[] = ['entry' => $entry['label'], 'reason' => 'mime_mismatch'];
                continue;
            }
            $stored = nibblyMediaStoreIncoming($entry['type'], $tmp, $mimeType, $entry['stem'], $entry['extension'], $entry['folder'], 'rename');
            @unlink($tmp);
            if (!$stored['ok']) {
                $skipped[] = ['entry' => $entry['label'], 'reason' => 'error', 'message' => $stored['message']];
                continue;
            }
            $imported[] = [
                'type' => $stored['data']['type'],
                'name' => $stored['data']['name'],
                'path' => $stored['data']['path'],
                'webVersion' => $stored['data']['webVersion'] !== null,
            ];
        }
        $zip->close();
        unset($finfo);

        jsonResponse($imported !== [], [
            'folder' => $folder,
            'imported' => $imported,
            'skipped' => array_slice($skipped, 0, 200),
            'skippedCount' => count($skipped),
            'ignored' => $ignored,
        ], $imported === [] ? 'The archive contains no files the media library accepts' : 'Archive imported');
        break;

    case 'create-media-folder':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $folder = trim((string)($_POST['folder'] ?? ''));
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFolderName($folder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }

        $path = $config['path'] . $folder;
        if (is_dir($path)) {
            jsonResponse(true, ['folder' => $folder], 'Folder exists');
        }
        if (file_exists($path)) {
            jsonResponse(false, null, 'A file with this name already exists');
        }
        if (mkdir($path, 0755, true)) {
            jsonResponse(true, ['folder' => $folder], 'Folder created');
        }

        jsonResponse(false, null, 'Error creating folder');
        break;

    case 'delete-media-folder':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $folder = trim((string)($_POST['folder'] ?? ''));
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFolderName($folder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }

        $path = $config['path'] . $folder;
        if (!is_dir($path) || is_link($path)) {
            jsonResponse(false, null, 'Folder not found');
        }
        $contents = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        // OS metadata files do not count as content and are removed with the folder.
        $junkFiles = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];
        $realContents = array_values(array_diff($contents, $junkFiles));
        if (count($realContents) > 0) {
            jsonResponse(false, null, 'Folder must be empty before it can be deleted');
        }
        foreach (array_intersect($contents, $junkFiles) as $junkFile) {
            @unlink($path . '/' . $junkFile);
        }
        if (rmdir($path)) {
            jsonResponse(true, ['folder' => $folder], 'Folder deleted');
        }

        jsonResponse(false, null, 'Error deleting folder');
        break;

    case 'move-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $moved = nibblyMediaOpMove(normalizeMediaType($_POST['type'] ?? 'image'), (string)($_POST['filename'] ?? ''), (string)($_POST['folder'] ?? ''));
        jsonResponse($moved['ok'], $moved['ok'] ? $moved['data'] : null, $moved['message']);
        break;

    case 'rename-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $filename = $_POST['filename'] ?? '';
        $newName = trim((string)($_POST['newName'] ?? ''));
        $scanReferences = ($_POST['scanReferences'] ?? '0') === '1';
        $confirmReferences = ($_POST['confirmReferences'] ?? '0') === '1';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }
        if (!validateMediaRenameBasename($newName, $type)) {
            jsonResponse(false, null, 'Invalid file name');
        }

        $oldBasename = basename($filename);
        $oldExt = normalizeRenameExtension(pathinfo($oldBasename, PATHINFO_EXTENSION));
        $newExt = normalizeRenameExtension(pathinfo($newName, PATHINFO_EXTENSION));
        if ($oldExt === '' || $newExt === '' || $oldExt !== $newExt) {
            jsonResponse(false, null, 'File extension cannot be changed');
        }

        $folder = trim(str_replace('\\', '/', dirname($filename)), '.');
        $targetRelative = ($folder !== '' ? $folder . '/' : '') . $newName;
        if (!validateMediaFilename($targetRelative, $type)) {
            jsonResponse(false, null, 'Invalid file name');
        }
        if ($targetRelative === $filename) {
            jsonResponse(true, [
                'type' => $type,
                'name' => $filename,
                'path' => $config['publicPath'] . $filename,
                'references' => [],
            ], 'Media already has this name');
        }

        $sourcePath = $config['path'] . $filename;
        $targetPath = $config['path'] . $targetRelative;
        if (!is_file($sourcePath) || is_link($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }
        if (file_exists($targetPath)) {
            jsonResponse(false, null, 'A file with this name already exists');
        }

        $references = [];
        if ($scanReferences) {
            $referencesByPath = [];
            foreach ([
                $oldBasename,
                $filename,
                ltrim($config['publicPath'], './') . $filename,
                '/' . ltrim($config['publicPath'], './') . $filename,
            ] as $needle) {
                foreach (findMediaJsonReferences($needle) as $match) {
                    $key = $match['file'];
                    $referencesByPath[$key] = $match;
                }
            }
            $references = array_values($referencesByPath);
            if ($references && !$confirmReferences) {
                jsonResponse(false, [
                    'requiresConfirmation' => true,
                    'references' => $references,
                    'oldName' => $filename,
                    'newName' => $targetRelative,
                ], 'References found');
            }
        }

        if (rename($sourcePath, $targetPath)) {
            nibblyMoveMediaMeta($type, $filename, $type, $targetRelative);
            if ($type === 'image') { nibblyMediaOriginalRelocate($filename, false, $targetRelative, false); nibblyMediaSizesRelocate($filename, false, $targetRelative, false); }
            jsonResponse(true, [
                'type' => $type,
                'name' => $targetRelative,
                'path' => $config['publicPath'] . $targetRelative,
                'oldName' => $filename,
                'references' => $references,
            ], 'Media renamed');
        }

        jsonResponse(false, null, 'Error renaming media');
        break;

    case 'set-media-ai-label':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $filename = $_POST['filename'] ?? '';
        $aiLabel = (string)($_POST['label'] ?? '');
        $config = getMediaConfig($type);
        if (!$config || !in_array($type, ['image', 'video'], true) || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }
        if ($aiLabel !== '' && !in_array($aiLabel, NIBBLY_AI_LABEL_KINDS, true)) {
            jsonResponse(false, null, 'Invalid AI label');
        }
        if (!is_file($config['path'] . $filename)) {
            jsonResponse(false, null, 'File not found');
        }
        if (!nibblySetMediaAiLabel($type, $filename, $aiLabel)) {
            jsonResponse(false, null, 'Error saving');
        }

        jsonResponse(true, ['type' => $type, 'name' => $filename, 'aiLabel' => $aiLabel], 'AI label saved');
        break;

    case 'set-media-states':
        // Flags and properties per file (includes/media-states.php): states = JSON object id => value;
        // true / an option value sets, false / "" / null removes; other states keep their values
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $filename = $_POST['filename'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }
        $changes = json_decode((string)($_POST['states'] ?? ''), true);
        if (!is_array($changes) || !$changes) {
            jsonResponse(false, null, 'Invalid media states');
        }
        foreach ($changes as $stateId => $value) {
            $state = nibblyMediaStates()[$stateId] ?? null;
            if ($state === null || !in_array($type, $state['media'], true)) {
                jsonResponse(false, null, 'Invalid media states');
            }
        }
        if (!is_file($config['path'] . $filename)) {
            jsonResponse(false, null, 'File not found');
        }
        if (!nibblySetMediaStates($type, $filename, $changes)) {
            jsonResponse(false, null, 'Invalid media states');
        }

        jsonResponse(true, [
            'type' => $type,
            'name' => $filename,
            'states' => (object)nibblyMediaStatesFor($type, $filename),
            'aiLabel' => nibblyMediaAiLabelFor($type, $filename),
        ], 'Media states saved');
        break;

    case 'local-media':
        // Local tools (includes/media-local-tools.php): do = reveal | open | path, only on the editor's own machine
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        if (!nibblyLocalToolsEnabled()) {
            jsonResponse(false, null, 'Local tools are not available here');
        }
        $type = normalizeMediaType($_POST['type'] ?? '');
        $filename = $_POST['filename'] ?? '';
        $do = $_POST['do'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type) || !in_array($do, ['reveal', 'open', 'path'], true)) {
            jsonResponse(false, null, 'Invalid media file');
        }
        $absolute = nibblyLocalToolsPath($config['path'], $filename);
        if ($absolute === null) {
            jsonResponse(false, null, 'File not found');
        }
        if ($do !== 'path' && !nibblyLocalToolsRun($do, $absolute)) {
            jsonResponse(false, ['path' => $absolute], 'Could not run the local command');
        }
        jsonResponse(true, ['path' => $absolute, 'do' => $do]);
        break;

    case 'delete-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $trashed = nibblyMediaOpTrash(normalizeMediaType($_POST['type'] ?? ''), (string)($_POST['filename'] ?? ''));
        jsonResponse($trashed['ok'], null, $trashed['message']);
        break;

    case 'restore-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $restored = nibblyMediaOpRestore(normalizeMediaType($_POST['type'] ?? ''), (string)($_POST['filename'] ?? ''));
        jsonResponse($restored['ok'], $restored['ok'] ? $restored['data'] : null, $restored['message']);
        break;

    case 'delete-media-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }
        $purged = nibblyMediaOpPurge(normalizeMediaType($_POST['type'] ?? ''), (string)($_POST['filename'] ?? ''));
        jsonResponse($purged['ok'], null, $purged['message']);
        break;

    case 'empty-media-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $requestedType = $_POST['type'] ?? 'all';
        $types = $requestedType === 'all'
            ? array_keys(getMediaConfig())
            : array_filter(array_map('normalizeMediaType', explode(',', $requestedType)));
        $deleted = 0;
        foreach ($types as $type) {
            $config = getMediaConfig($type);
            foreach (listMediaFiles($type, true) as $media) {
                $path = $config['trashPath'] . $media['name'];
                if (is_file($path) && unlink($path)) {
                    nibblyForgetMediaMeta($type . '-trash', $media['name']);
                    if ($type === 'image') { nibblyMediaOriginalForget($media['name'], true); nibblyMediaSizesForget($media['name'], true); }
                    $deleted++;
                }
            }
        }

        jsonResponse(true, ['deleted' => $deleted], 'Media trash emptied');
        break;

    case 'media-trash-file':
        $type = normalizeMediaType($_GET['type'] ?? '');
        $filename = $_GET['filename'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            http_response_code(400);
            header_remove('Content-Type');
            echo 'Invalid media file';
            exit;
        }

        $path = $config['trashPath'] . $filename;
        if (!is_file($path)) {
            http_response_code(404);
            header_remove('Content-Type');
            echo 'File not found';
            exit;
        }

        serveLocalImage($path, $filename);
        break;

    case 'list-image-trash':
        $images = listImageFiles(IMAGES_TRASH_PATH, 'api.php?action=image-trash-file&filename=');
        foreach ($images as &$image) {
            $image['path'] = 'api.php?action=image-trash-file&filename=' . rawurlencode($image['name']);
        }
        unset($image);
        jsonResponse(true, $images);
        break;

    case 'image-trash-file':
        $filename = $_GET['filename'] ?? '';
        if (!validateImageFilename($filename)) {
            http_response_code(400);
            header_remove('Content-Type');
            echo 'Invalid filename';
            exit;
        }

        $path = IMAGES_TRASH_PATH . $filename;
        if (!is_file($path)) {
            http_response_code(404);
            header_remove('Content-Type');
            echo 'File not found';
            exit;
        }

        serveLocalImage($path, $filename);
        break;

    case 'upload-image':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Upload error';
            if (isset($_FILES['image']['error'])) {
                switch ($_FILES['image']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMsg = sprintf('File too large: the server accepts uploads up to %s MB (upload_max_filesize / post_max_size)', nibblyMegabytes(nibblyUploadServerLimit()));
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMsg = 'No file selected';
                        break;
                }
            } elseif (empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > nibblyUploadServerLimit()) {
                $errorMsg = sprintf('File too large: the server accepts uploads up to %s MB (upload_max_filesize / post_max_size)', nibblyMegabytes(nibblyUploadServerLimit()));
            }
            jsonResponse(false, null, $errorMsg);
        }

        $file = $_FILES['image'];

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        unset($finfo);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            jsonResponse(false, null, 'Only JPG, PNG, WebP and SVG allowed');
        }

        if ($file['size'] > nibblyMediaImageMaxBytes()) {
            jsonResponse(false, null, sprintf('Maximum file size: %s MB', nibblyMegabytes(nibblyMediaImageMaxBytes())));
        }

        $explicitFilename = $_POST['filename'] ?? '';
        $replaceMode = ($_POST['replace'] ?? '0') === '1';

        if (!empty($explicitFilename)) {
            if (!validateMediaFilename($explicitFilename, 'image')) {
                jsonResponse(false, null, 'Invalid file extension');
            }

            $extension = strtolower(pathinfo($explicitFilename, PATHINFO_EXTENSION));
            $filename = $explicitFilename;

            if ($replaceMode && file_exists(IMAGES_PATH . $filename)) {
                if (!is_dir(IMAGES_TRASH_PATH)) {
                    mkdir(IMAGES_TRASH_PATH, 0755, true);
                }
                $folder = trim(str_replace('\\', '/', dirname($filename)), '.');
                $backupName = ($folder !== '' ? $folder . '/' : '') . pathinfo($filename, PATHINFO_FILENAME) . '_' . date('Y-m-d_His') . '.' . $extension;
                $backupDirectory = dirname(IMAGES_TRASH_PATH . $backupName);
                if (!is_dir($backupDirectory)) {
                    mkdir($backupDirectory, 0755, true);
                }
                rename(IMAGES_PATH . $filename, IMAGES_TRASH_PATH . $backupName);
            } elseif (!$replaceMode && file_exists(IMAGES_PATH . $filename)) {
                $filename = uniqueMediaRelativePath(IMAGES_PATH, $filename);
            }
        } else {
            $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $safeName = preg_replace('/[^a-z0-9\-_]/i', '-', $originalName);
            $safeName = preg_replace('/-+/', '-', $safeName);
            $safeName = trim($safeName, '-');

            if (empty($safeName)) {
                $safeName = 'image-' . time();
            }

            $filename = $safeName . '.' . $extension;

            $counter = 1;
            while (file_exists(IMAGES_PATH . $filename)) {
                $filename = $safeName . '-' . $counter . '.' . $extension;
                $counter++;
            }
        }

        // Final gate: never store a filename whose extension is not an allowed
        // image type. finfo checks the MIME type, but a polyglot file can carry
        // valid image magic bytes while keeping a .php name, so the extension
        // must be validated independently before the file lands in a web-served
        // directory (assets/images/ has no PHP-execution block).
        if (!validateMediaFilename($filename, 'image')) {
            jsonResponse(false, null, 'Invalid file extension');
        }

        $targetDirectory = dirname(IMAGES_PATH . $filename);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], IMAGES_PATH . $filename)) {
            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg'
                && !nibblySanitizeSvgFile(IMAGES_PATH . $filename)) {
                @unlink(IMAGES_PATH . $filename);
                jsonResponse(false, null, 'SVG could not be processed safely');
            }
            $detectedAiLabel = nibblyDetectAiLabel(IMAGES_PATH . $filename);
            if ($detectedAiLabel !== '') {
                nibblySetMediaAiLabel('image', $filename, $detectedAiLabel);
            }
            nibblyMediaEvent('uploaded', 'image', $filename);
            jsonResponse(true, [
                'name' => $filename,
                'path' => '../assets/images/' . $filename,
                'aiLabel' => nibblyMediaAiLabelFor('image', $filename),
            ], $replaceMode ? 'Image replaced' : 'Image uploaded');
        } else {
            jsonResponse(false, null, 'Error saving');
        }
        break;

    case 'delete-image':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $filename = $_POST['filename'] ?? '';

        if (!validateImageFilename($filename)) {
            jsonResponse(false, null, 'Invalid filename');
        }

        $sourcePath = IMAGES_PATH . $filename;

        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        if (!is_dir(IMAGES_TRASH_PATH)) {
            mkdir(IMAGES_TRASH_PATH, 0755, true);
        }

        $targetFilename = $filename;
        $counter = 1;
        while (file_exists(IMAGES_TRASH_PATH . $targetFilename)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $targetFilename = $name . '-' . $counter . '.' . $ext;
            $counter++;
        }

        if (rename($sourcePath, IMAGES_TRASH_PATH . $targetFilename)) {
            jsonResponse(true, null, 'Image moved to trash');
        } else {
            jsonResponse(false, null, 'Error moving');
        }
        break;

    case 'restore-image':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $filename = $_POST['filename'] ?? '';
        if (!validateImageFilename($filename)) {
            jsonResponse(false, null, 'Invalid filename');
        }

        $sourcePath = IMAGES_TRASH_PATH . $filename;
        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        if (!is_dir(IMAGES_PATH)) {
            mkdir(IMAGES_PATH, 0755, true);
        }

        $targetFilename = $filename;
        $counter = 1;
        while (file_exists(IMAGES_PATH . $targetFilename)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $targetFilename = $name . '-' . $counter . '.' . $ext;
            $counter++;
        }

        if (rename($sourcePath, IMAGES_PATH . $targetFilename)) {
            jsonResponse(true, [
                'name' => $targetFilename,
                'path' => '../assets/images/' . $targetFilename
            ], 'Image restored');
        }

        jsonResponse(false, null, 'Error restoring');
        break;

    case 'delete-image-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $filename = $_POST['filename'] ?? '';
        if (!validateImageFilename($filename)) {
            jsonResponse(false, null, 'Invalid filename');
        }

        $path = IMAGES_TRASH_PATH . $filename;
        if (!file_exists($path)) {
            jsonResponse(false, null, 'File not found');
        }

        if (unlink($path)) {
            jsonResponse(true, null, 'Image permanently deleted');
        }

        jsonResponse(false, null, 'Error deleting');
        break;

    case 'empty-image-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $deleted = 0;
        foreach (listImageFiles(IMAGES_TRASH_PATH, '../assets/images-trash/') as $image) {
            $path = IMAGES_TRASH_PATH . $image['name'];
            if (is_file($path) && unlink($path)) {
                $deleted++;
            }
        }

        jsonResponse(true, ['deleted' => $deleted], 'Image trash emptied');
        break;

    // ============================================================
    // AUDIO MANAGEMENT
    // ============================================================

    case 'list-audio':
        if (!is_dir(AUDIO_PATH)) {
            jsonResponse(true, []);
        }

        $audioFiles = [];
        $allowedExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];

        $files = scandir(AUDIO_PATH);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExtensions)) {
                $sizeBytes = filesize(AUDIO_PATH . $file);
                $modified = filemtime(AUDIO_PATH . $file);

                if ($sizeBytes >= 1048576) {
                    $sizeFormatted = round($sizeBytes / 1048576, 1) . ' MB';
                } elseif ($sizeBytes >= 1024) {
                    $sizeFormatted = round($sizeBytes / 1024, 0) . ' KB';
                } else {
                    $sizeFormatted = $sizeBytes . ' B';
                }

                $audioFiles[] = [
                    'name' => $file,
                    'path' => '../assets/audio/' . $file,
                    'sizeBytes' => $sizeBytes,
                    'size' => $sizeFormatted,
                    'modified' => $modified,
                    'dateFormatted' => date('d.m.Y H:i', $modified)
                ];
            }
        }

        usort($audioFiles, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        jsonResponse(true, $audioFiles);
        break;

    case 'upload-audio':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Upload error';
            if (isset($_FILES['audio']['error'])) {
                switch ($_FILES['audio']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMsg = 'File too large';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMsg = 'No file selected';
                        break;
                }
            }
            jsonResponse(false, null, $errorMsg);
        }

        $file = $_FILES['audio'];

        $allowedMimeTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/aac', 'audio/flac'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        unset($finfo);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            jsonResponse(false, null, 'Only MP3, WAV, OGG, M4A, AAC and FLAC allowed');
        }

        if ($file['size'] > 50 * 1024 * 1024) {
            jsonResponse(false, null, 'Maximum file size: 50 MB');
        }

        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-z0-9\-_]/i', '-', $originalName);
        $safeName = preg_replace('/-+/', '-', $safeName);
        $safeName = trim($safeName, '-');

        if (empty($safeName)) {
            $safeName = 'audio-' . time();
        }

        $filename = $safeName . '.' . $extension;

        $counter = 1;
        while (file_exists(AUDIO_PATH . $filename)) {
            $filename = $safeName . '-' . $counter . '.' . $extension;
            $counter++;
        }

        // Validate the final extension independently of the MIME type: a polyglot
        // can pass the finfo check while keeping an executable .php name.
        if (!validateMediaFilename($filename, 'audio')) {
            jsonResponse(false, null, 'Invalid file extension');
        }

        if (!is_dir(AUDIO_PATH)) {
            mkdir(AUDIO_PATH, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], AUDIO_PATH . $filename)) {
            jsonResponse(true, [
                'name' => $filename,
                'path' => '../assets/audio/' . $filename
            ], 'Audio file uploaded');
        } else {
            jsonResponse(false, null, 'Error saving');
        }
        break;

    case 'delete-audio':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $filename = $_POST['filename'] ?? '';

        if (empty($filename) || strpos($filename, '/') !== false || strpos($filename, '\\') !== false || strpos($filename, '..') !== false) {
            jsonResponse(false, null, 'Invalid filename');
        }

        $sourcePath = AUDIO_PATH . $filename;

        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        if (!is_dir(AUDIO_TRASH_PATH)) {
            mkdir(AUDIO_TRASH_PATH, 0755, true);
        }

        $targetFilename = $filename;
        $counter = 1;
        while (file_exists(AUDIO_TRASH_PATH . $targetFilename)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $targetFilename = $name . '-' . $counter . '.' . $ext;
            $counter++;
        }

        if (rename($sourcePath, AUDIO_TRASH_PATH . $targetFilename)) {
            jsonResponse(true, null, 'Audio file moved to trash');
        } else {
            jsonResponse(false, null, 'Error moving');
        }
        break;

    // ============================================================
    // NEWS / BLOG MANAGEMENT
    // ============================================================

}
