<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

/*
 * File operations of the media library, shared by the single-file actions
 * (move-media, delete-media, restore-media, delete-media-trash, upload-media)
 * and the batch actions (bulk-media, zip-media, unzip-media).
 *
 * Every operation returns ['ok' => bool, 'data' => mixed, 'message' => string],
 * so a batch can collect results per file instead of stopping at the first error.
 */

/** Files one batch action (bulk-media, zip-media) may touch. */
const NIBBLY_MEDIA_BATCH_MAX = 500;
/** Total size of the files a ZIP download may hold. */
const NIBBLY_MEDIA_ZIP_DOWNLOAD_MAX_BYTES = 1024 * 1024 * 1024;
/** Size of an uploaded ZIP archive (the server limit may be lower). */
const NIBBLY_MEDIA_ZIP_UPLOAD_MAX_BYTES = 512 * 1024 * 1024;
/** Entries (files and folders) an uploaded archive may list at all. */
const NIBBLY_MEDIA_ZIP_MAX_ENTRIES = 2000;
/** Files an uploaded archive may import. */
const NIBBLY_MEDIA_ZIP_MAX_FILES = 500;
/** Expanded size of everything an archive imports. */
const NIBBLY_MEDIA_ZIP_MAX_EXPANDED_BYTES = 1024 * 1024 * 1024;
/** Expansion ratio above which an entry counts as a ZIP bomb (only above 1 MB expanded). */
const NIBBLY_MEDIA_ZIP_MAX_RATIO = 100;

function nibblyMediaOpResult(bool $ok, $data = null, string $message = ''): array {
    return ['ok' => $ok, 'data' => $data, 'message' => $message];
}

/** Move a library file into another folder ('' = main folder); the folder is created when missing. */
function nibblyMediaOpMove(string $type, string $filename, string $targetFolder): array {
    $config = getMediaConfig($type);
    $targetFolder = trim($targetFolder);
    if (!$config || !validateMediaFilename($filename, $type)) {
        return nibblyMediaOpResult(false, null, 'Invalid media file');
    }
    if ($targetFolder !== '' && !validateMediaFolderName($targetFolder)) {
        return nibblyMediaOpResult(false, null, 'Invalid folder name');
    }

    $sourcePath = $config['path'] . $filename;
    if (!is_file($sourcePath) || is_link($sourcePath)) {
        return nibblyMediaOpResult(false, null, 'File not found');
    }

    $targetRelative = ($targetFolder !== '' ? $targetFolder . '/' : '') . basename($filename);
    if ($targetRelative === $filename) {
        return nibblyMediaOpResult(true, [
            'type' => $type,
            'name' => $filename,
            'path' => $config['publicPath'] . $filename,
        ], 'Media already in folder');
    }
    if (!validateMediaFilename($targetRelative, $type)) {
        return nibblyMediaOpResult(false, null, 'Invalid folder name');
    }

    $targetDirectory = $config['path'] . $targetFolder;
    if (file_exists($targetDirectory) && (!is_dir($targetDirectory) || is_link($targetDirectory))) {
        return nibblyMediaOpResult(false, null, 'A file with this name already exists');
    }
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true)) {
        return nibblyMediaOpResult(false, null, 'Error creating target folder');
    }

    $targetRelative = uniqueMediaRelativePath($config['path'], $targetRelative);
    if (!rename($sourcePath, $config['path'] . $targetRelative)) {
        return nibblyMediaOpResult(false, null, 'Error moving media');
    }
    nibblyMoveMediaMeta($type, $filename, $type, $targetRelative);
    if ($type === 'image') {
        nibblyMediaOriginalRelocate($filename, false, $targetRelative, false);
        nibblyMediaSizesRelocate($filename, false, $targetRelative, false);
    }
    return nibblyMediaOpResult(true, [
        'type' => $type,
        'name' => $targetRelative,
        'path' => $config['publicPath'] . $targetRelative,
    ], 'Media moved');
}

/** Move a library file into the media trash (original and responsive sizes follow). */
function nibblyMediaOpTrash(string $type, string $filename): array {
    $config = getMediaConfig($type);
    if (!$config || !validateMediaFilename($filename, $type)) {
        return nibblyMediaOpResult(false, null, 'Invalid media file');
    }
    $sourcePath = $config['path'] . $filename;
    if (!is_file($sourcePath) || is_link($sourcePath)) {
        return nibblyMediaOpResult(false, null, 'File not found');
    }
    if (!is_dir($config['trashPath'])) {
        mkdir($config['trashPath'], 0755, true);
    }
    $targetFilename = uniqueMediaRelativePath($config['trashPath'], $filename);
    $targetDirectory = dirname($config['trashPath'] . $targetFilename);
    if (!is_dir($targetDirectory)) {
        mkdir($targetDirectory, 0755, true);
    }
    if (!rename($sourcePath, $config['trashPath'] . $targetFilename)) {
        return nibblyMediaOpResult(false, null, 'Error moving');
    }
    nibblyMoveMediaMeta($type, $filename, $type . '-trash', $targetFilename);
    if ($type === 'image') {
        nibblyMediaOriginalRelocate($filename, false, $targetFilename, true);
        nibblyMediaSizesRelocate($filename, false, $targetFilename, true);
    }
    return nibblyMediaOpResult(true, ['type' => $type, 'name' => $filename, 'trashName' => $targetFilename], 'Media moved to trash');
}

/** Bring a trashed file back to its folder in the library. */
function nibblyMediaOpRestore(string $type, string $filename): array {
    $config = getMediaConfig($type);
    if (!$config || !validateMediaFilename($filename, $type)) {
        return nibblyMediaOpResult(false, null, 'Invalid media file');
    }
    $sourcePath = $config['trashPath'] . $filename;
    if (!is_file($sourcePath) || is_link($sourcePath)) {
        return nibblyMediaOpResult(false, null, 'File not found');
    }
    $targetFilename = uniqueMediaRelativePath($config['path'], $filename);
    $targetDirectory = dirname($config['path'] . $targetFilename);
    if (!is_dir($targetDirectory)) {
        mkdir($targetDirectory, 0755, true);
    }
    if (!rename($sourcePath, $config['path'] . $targetFilename)) {
        return nibblyMediaOpResult(false, null, 'Error restoring');
    }
    nibblyMoveMediaMeta($type . '-trash', $filename, $type, $targetFilename);
    if ($type === 'image') {
        nibblyMediaOriginalRelocate($filename, true, $targetFilename, false);
        nibblyMediaSizesRelocate($filename, true, $targetFilename, false);
    }
    return nibblyMediaOpResult(true, [
        'type' => $type,
        'name' => $targetFilename,
        'path' => $config['publicPath'] . $targetFilename,
    ], 'Media restored');
}

/** Delete a trashed file for good. */
function nibblyMediaOpPurge(string $type, string $filename): array {
    $config = getMediaConfig($type);
    if (!$config || !validateMediaFilename($filename, $type)) {
        return nibblyMediaOpResult(false, null, 'Invalid media file');
    }
    $path = $config['trashPath'] . $filename;
    if (!is_file($path) || is_link($path)) {
        return nibblyMediaOpResult(false, null, 'File not found');
    }
    if (!unlink($path)) {
        return nibblyMediaOpResult(false, null, 'Error deleting');
    }
    nibblyForgetMediaMeta($type . '-trash', $filename);
    if ($type === 'image') {
        nibblyMediaOriginalForget($filename, true);
        nibblyMediaSizesForget($filename, true);
    }
    return nibblyMediaOpResult(true, ['type' => $type, 'name' => $filename], 'Media permanently deleted');
}

/** File name part as the upload stores it: letters, digits, hyphen, underscore. */
function nibblyMediaSafeStem(string $name, string $fallback): string {
    $safe = preg_replace('/[^a-z0-9\-_]/i', '-', $name);
    $safe = trim((string)preg_replace('/-+/', '-', (string)$safe), '-');
    return $safe !== '' ? substr($safe, 0, 120) : $fallback . '-' . time();
}

/** A folder name from free text (archive folders, archive name); '' when nothing usable is left. */
function nibblyMediaSafeFolderSegment(string $name): string {
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $name);
    $safe = (string)preg_replace('/-+/', '-', (string)$safe);
    $safe = ltrim($safe, '.-_');
    $safe = rtrim(substr($safe, 0, 64), '.-');
    return validateMediaFolderName($safe) ? $safe : '';
}

/**
 * Store a checked file in the library, as upload-media does: JPEG/PNG/WebP may
 * become a web version (original kept), SVG is sanitized, provenance labels are
 * read before re-encoding. $moveFile moves $tmpFile into place.
 * The caller has checked extension, MIME type and size.
 */
function nibblyMediaStoreIncoming(string $type, string $tmpFile, string $mimeType, string $safeName, string $extension, string $folder, callable $moveFile): array {
    $config = getMediaConfig($type);
    if (!$config) {
        return nibblyMediaOpResult(false, null, 'Invalid media type');
    }
    if ($folder !== '' && !validateMediaFolderName($folder)) {
        return nibblyMediaOpResult(false, null, 'Invalid folder name');
    }
    $prefix = $folder !== '' ? $folder . '/' : '';
    $targetBase = $config['path'] . $prefix;
    $targetPublicBase = $config['publicPath'] . $prefix;
    if (file_exists(rtrim($targetBase, '/')) && (!is_dir($targetBase) || is_link(rtrim($targetBase, '/')))) {
        return nibblyMediaOpResult(false, null, 'A file with this name already exists');
    }
    if (!is_dir($targetBase) && !mkdir($targetBase, 0755, true)) {
        return nibblyMediaOpResult(false, null, 'Error creating target folder');
    }

    if ($type === 'image' && in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        // Provenance sits in metadata that re-encoding drops: read it from the incoming file
        $aiLabel = nibblyDetectAiLabel($tmpFile);
        // The web version and the original share a stem; it must be free in both places
        $stem = pathinfo(uniqueMediaFilename($targetBase, $safeName . '.' . $extension), PATHINFO_FILENAME);
        while (file_exists($targetBase . $stem . '.webp') || nibblyMediaOriginalFor($prefix . $stem . '.webp') !== null) {
            $stem = pathinfo(uniqueMediaFilename($targetBase, $stem . '-1.' . $extension), PATHINFO_FILENAME);
        }
        $stored = nibblyMediaStoreImage($tmpFile, $targetBase, $stem, $extension, $mimeType, $moveFile);
        if ($stored === null) {
            return nibblyMediaOpResult(false, null, 'Error saving');
        }
        $relativeName = $prefix . $stored['name'];
        if ($aiLabel !== '') {
            nibblySetMediaAiLabel($type, $relativeName, $aiLabel);
        }
        // Responsive sizes next to the library, see includes/media-sizes.php
        $sizes = nibblyMediaSizesGenerate($relativeName);
        nibblyMediaEvent('uploaded', $type, $relativeName);
        return nibblyMediaOpResult(true, [
            'type' => $type,
            'name' => $relativeName,
            'path' => $targetPublicBase . $stored['name'],
            'aiLabel' => $aiLabel,
            'webVersion' => $stored['webVersion'],
            'original' => nibblyMediaOriginalFor($relativeName),
            'sizes' => $sizes,
        ], 'Media uploaded');
    }

    $filename = uniqueMediaFilename($targetBase, $safeName . '.' . $extension);
    if (!$moveFile($tmpFile, $targetBase . $filename)) {
        return nibblyMediaOpResult(false, null, 'Error saving');
    }
    if ($extension === 'svg' && !nibblySanitizeSvgFile($targetBase . $filename)) {
        @unlink($targetBase . $filename);
        return nibblyMediaOpResult(false, null, 'SVG could not be processed safely');
    }
    $relativeName = $prefix . $filename;
    $aiLabel = in_array($type, ['image', 'video'], true) ? nibblyDetectAiLabel($targetBase . $filename) : '';
    if ($aiLabel !== '') {
        nibblySetMediaAiLabel($type, $relativeName, $aiLabel);
    }
    nibblyMediaEvent('uploaded', $type, $relativeName);
    return nibblyMediaOpResult(true, [
        'type' => $type,
        'name' => $relativeName,
        'path' => $targetPublicBase . $filename,
        'aiLabel' => $aiLabel,
        'webVersion' => null,
    ], 'Media uploaded');
}

/**
 * Batch items from a request: a JSON list of {type, name}, without duplicates.
 * Returns the list or a message when the request is not usable.
 *
 * @return array{0:?array,1:string}
 */
function nibblyMediaBatchItems($raw): array {
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !array_is_list($decoded) || !$decoded) {
        return [null, 'No files selected'];
    }
    if (count($decoded) > NIBBLY_MEDIA_BATCH_MAX) {
        return [null, sprintf('Too many files: at most %d per action', NIBBLY_MEDIA_BATCH_MAX)];
    }
    $items = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry) || !is_string($entry['type'] ?? null) || !is_string($entry['name'] ?? null)) {
            return [null, 'Invalid media file'];
        }
        $type = normalizeMediaType($entry['type']);
        $name = $entry['name'];
        if ($type === '' || !validateMediaFilename($name, $type)) {
            return [null, 'Invalid media file'];
        }
        $items[$type . "\0" . $name] = ['type' => $type, 'name' => $name];
    }
    return [array_values($items), ''];
}

/** A private working directory for archives; removed by the caller. */
function nibblyMediaWorkDir(): ?string {
    $candidates = [rtrim(sys_get_temp_dir(), '/\\')];
    if (defined('BACKUP_PATH')) $candidates[] = rtrim(BACKUP_PATH, '/\\');
    foreach ($candidates as $base) {
        if ($base === '' || !@is_dir($base) || !@is_writable($base)) continue;
        $dir = $base . '/.nibbly-media-' . bin2hex(random_bytes(8));
        if (@mkdir($dir, 0700)) return $dir;
    }
    return null;
}

function nibblyMediaRemoveWorkDir(?string $dir): void {
    if ($dir === null || !is_dir($dir) || is_link($dir)) return;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) nibblyMediaRemoveWorkDir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

/** Formats that are compressed already: stored as they are instead of deflated again. */
function nibblyMediaZipStoredExtension(string $extension): bool {
    return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp3', 'm4a', 'aac', 'ogg', 'flac', 'mp4', 'webm', 'mov', 'm4v', 'docx', 'xlsx', 'pptx', 'pdf'], true);
}

/**
 * Archive names for library files: the folder all of them share is left out,
 * and with several media types each type gets its own top folder.
 *
 * @param array<int,array{type:string,name:string}> $items
 * @return array<int,string>
 */
function nibblyMediaZipEntryNames(array $items): array {
    $types = array_unique(array_column($items, 'type'));
    $common = null;
    foreach ($items as $item) {
        $dir = trim(str_replace('\\', '/', dirname($item['name'])), '.');
        $segments = $dir === '' ? [] : explode('/', $dir);
        if ($common === null) { $common = $segments; continue; }
        $shared = [];
        foreach ($common as $i => $segment) {
            if (($segments[$i] ?? null) !== $segment) break;
            $shared[] = $segment;
        }
        $common = $shared;
    }
    $strip = $common ? implode('/', $common) . '/' : '';
    $names = [];
    foreach ($items as $item) {
        $name = $strip !== '' && str_starts_with($item['name'], $strip) ? substr($item['name'], strlen($strip)) : $item['name'];
        if (count($types) > 1) {
            $name = basename(rtrim(getMediaConfig($item['type'])['publicPath'], '/')) . '/' . $name;
        }
        $names[] = $name;
    }
    return $names;
}

/** Unix file mode of an archive entry, or null when the archive does not record one. */
function nibblyMediaZipEntryMode(ZipArchive $zip, int $index): ?int {
    $opsys = 0;
    $attr = 0;
    if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) return null;
    return $opsys === ZipArchive::OPSYS_UNIX ? (($attr >> 16) & 0xFFFF) : null;
}

/**
 * Check one archive entry name and split it into folder segments and file name.
 * Returns ['skip' => reason] for entries that are not imported, or
 * ['ignore' => true] for system files (__MACOSX, .DS_Store, hidden files).
 */
function nibblyMediaZipParseEntryName(string $name): array {
    if ($name === '' || preg_match('/[\x00-\x1f\x7f]/', $name)) return ['skip' => 'unsafe_path'];
    $normalized = str_replace('\\', '/', $name);
    if (str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:/', $normalized)) return ['skip' => 'unsafe_path'];
    $segments = explode('/', $normalized);
    foreach ($segments as $segment) {
        if ($segment === '..') return ['skip' => 'unsafe_path'];
    }
    $segments = array_values(array_filter($segments, static fn($s) => $s !== '' && $s !== '.'));
    if (!$segments) return ['skip' => 'unsafe_path'];
    foreach ($segments as $segment) {
        if ($segment === '__MACOSX' || str_starts_with($segment, '.')) return ['ignore' => true];
    }
    $file = array_pop($segments);
    if (in_array(strtolower($file), ['thumbs.db', 'desktop.ini'], true)) return ['ignore' => true];
    return ['folders' => $segments, 'file' => $file];
}

/** The media type that accepts a file extension, '' when none does. */
function nibblyMediaTypeForExtension(string $extension): string {
    foreach (getMediaConfig() as $type => $config) {
        if (in_array($extension, $config['extensions'], true)) return $type;
    }
    return '';
}

/**
 * Copy one archive entry into $target, never more than the size its header
 * names, and verify the checksum. Returns false for broken or lying entries.
 */
function nibblyMediaZipExtractEntry(ZipArchive $zip, array $stat, string $target): bool {
    $input = $zip->getStream($stat['name']);
    $output = @fopen($target, 'xb');
    if (!$input || !$output) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        return false;
    }
    $limit = (int)$stat['size'];
    $written = 0;
    $hash = hash_init('crc32b');
    $ok = true;
    try {
        while (!feof($input)) {
            $chunk = fread($input, 1048576);
            if ($chunk === false) { $ok = false; break; }
            if ($chunk === '') continue;
            $written += strlen($chunk);
            if ($written > $limit) { $ok = false; break; }
            hash_update($hash, $chunk);
            if (fwrite($output, $chunk) !== strlen($chunk)) { $ok = false; break; }
        }
    } finally {
        fclose($input);
        fclose($output);
    }
    if (!$ok || $written !== $limit || hash_final($hash) !== sprintf('%08x', $stat['crc'])) {
        @unlink($target);
        return false;
    }
    return true;
}
