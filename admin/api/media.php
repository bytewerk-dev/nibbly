<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

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
        $folders = array_values(array_unique($folders));
        natcasesort($folders);
        $folders = array_values($folders);
        usort($items, function($a, $b) {
            return ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0);
        });

        jsonResponse(true, [
            'items' => $items,
            'folders' => $folders,
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
            $field = 'file';
        }

        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, null, 'Upload error');
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
            jsonResponse(false, null, 'File too large');
        }

        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $safeName = preg_replace('/[^a-z0-9\-_]/i', '-', $originalName);
        $safeName = preg_replace('/-+/', '-', $safeName);
        $safeName = trim($safeName, '-');
        if ($safeName === '') {
            $safeName = $config['fallbackName'] . '-' . time();
        }

        $folder = trim((string)($_POST['folder'] ?? ''));
        if ($folder !== '' && !validateMediaFolderName($folder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }
        $targetBase = $config['path'] . ($folder !== '' ? $folder . '/' : '');
        $targetPublicBase = $config['publicPath'] . ($folder !== '' ? $folder . '/' : '');

        $filename = uniqueMediaFilename($targetBase, $safeName . '.' . $extension);
        if (!is_dir($targetBase)) {
            mkdir($targetBase, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], $targetBase . $filename)) {
            $relativeName = ($folder !== '' ? $folder . '/' : '') . $filename;
            jsonResponse(true, [
                'type' => $type,
                'name' => $relativeName,
                'path' => $targetPublicBase . $filename,
            ], 'Media uploaded');
        }

        jsonResponse(false, null, 'Error saving');
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

        $type = normalizeMediaType($_POST['type'] ?? 'image');
        $filename = $_POST['filename'] ?? '';
        $targetFolder = trim((string)($_POST['folder'] ?? ''));
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }
        if ($targetFolder !== '' && !validateMediaFolderName($targetFolder)) {
            jsonResponse(false, null, 'Invalid folder name');
        }

        $sourcePath = $config['path'] . $filename;
        if (!is_file($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        $basename = basename($filename);
        $targetRelative = ($targetFolder !== '' ? $targetFolder . '/' : '') . $basename;
        if ($targetRelative === $filename) {
            jsonResponse(true, [
                'type' => $type,
                'name' => $filename,
                'path' => $config['publicPath'] . $filename,
            ], 'Media already in folder');
        }

        $targetDirectory = $config['path'] . ($targetFolder !== '' ? $targetFolder : '');
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true)) {
            jsonResponse(false, null, 'Error creating target folder');
        }

        $targetRelative = uniqueMediaRelativePath($config['path'], $targetRelative);
        if (rename($sourcePath, $config['path'] . $targetRelative)) {
            jsonResponse(true, [
                'type' => $type,
                'name' => $targetRelative,
                'path' => $config['publicPath'] . $targetRelative,
            ], 'Media moved');
        }

        jsonResponse(false, null, 'Error moving media');
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

    case 'delete-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? '');
        $filename = $_POST['filename'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }

        $sourcePath = $config['path'] . $filename;
        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        if (!is_dir($config['trashPath'])) {
            mkdir($config['trashPath'], 0755, true);
        }

        $targetFilename = uniqueMediaRelativePath($config['trashPath'], $filename);
        $targetDirectory = dirname($config['trashPath'] . $targetFilename);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }
        if (rename($sourcePath, $config['trashPath'] . $targetFilename)) {
            jsonResponse(true, null, 'Media moved to trash');
        }

        jsonResponse(false, null, 'Error moving');
        break;

    case 'restore-media':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? '');
        $filename = $_POST['filename'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }

        $sourcePath = $config['trashPath'] . $filename;
        if (!file_exists($sourcePath)) {
            jsonResponse(false, null, 'File not found');
        }

        $targetFilename = uniqueMediaRelativePath($config['path'], $filename);
        $targetDirectory = dirname($config['path'] . $targetFilename);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }
        if (rename($sourcePath, $config['path'] . $targetFilename)) {
            jsonResponse(true, [
                'type' => $type,
                'name' => $targetFilename,
                'path' => $config['publicPath'] . $targetFilename,
            ], 'Media restored');
        }

        jsonResponse(false, null, 'Error restoring');
        break;

    case 'delete-media-trash':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $type = normalizeMediaType($_POST['type'] ?? '');
        $filename = $_POST['filename'] ?? '';
        $config = getMediaConfig($type);
        if (!$config || !validateMediaFilename($filename, $type)) {
            jsonResponse(false, null, 'Invalid media file');
        }

        $path = $config['trashPath'] . $filename;
        if (!file_exists($path)) {
            jsonResponse(false, null, 'File not found');
        }

        if (unlink($path)) {
            jsonResponse(true, null, 'Media permanently deleted');
        }

        jsonResponse(false, null, 'Error deleting');
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
                        $errorMsg = 'File too large';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMsg = 'No file selected';
                        break;
                }
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

        if ($file['size'] > 5 * 1024 * 1024) {
            jsonResponse(false, null, 'Maximum file size: 5 MB');
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

        $targetDirectory = dirname(IMAGES_PATH . $filename);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], IMAGES_PATH . $filename)) {
            jsonResponse(true, [
                'name' => $filename,
                'path' => '../assets/images/' . $filename
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
