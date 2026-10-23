<?php
/**
 * Web versions for uploaded images.
 *
 * Large photographs may enter the library (the limit is a setting, 20 MB by
 * default), but what pages embed should be small. So an upload of a JPEG,
 * PNG or WebP that is bigger than the web needs is re-encoded once: scaled
 * to the configured longest side and saved as WebP under the chosen name.
 * The file as it was uploaded moves to assets/images-originals/ next to the
 * library, keyed by the web file's relative name, so the original can be
 * downloaded, travels along on rename, move, trash and restore, and goes
 * with its web version when that is deleted for good.
 *
 * Settings (content/settings.json, "media"):
 *   maxUploadMb   upload limit for images in MB (1–200, default 20)
 *   webVersion    create web versions at all (default true)
 *   webMaxSide    longest side of the web version in px (800–6000, default 2000)
 *   webQuality    WebP quality (50–95, default 82)
 *
 * The PHP limits upload_max_filesize and post_max_size cap every upload
 * before Nibbly sees it; nibblyUploadServerLimit() reads them so the
 * dashboard can show the limit that really applies.
 */

const NIBBLY_MEDIA_WEB_MIN_BYTES = 400 * 1024;     // smaller files stay as they are
const NIBBLY_MEDIA_LARGE_BYTES = 2 * 1024 * 1024;  // above this a library image is flagged as large
const NIBBLY_MEDIA_MAX_INPUT_SIDE = 12000;
const NIBBLY_MEDIA_MAX_INPUT_PIXELS = 60000000;

function nibblyMediaSettingsDefaults(): array {
    return ['maxUploadMb' => 20, 'webVersion' => true, 'webMaxSide' => 2000, 'webQuality' => 82];
}

/** Sanitised media settings: defaults, overlaid by what the site stored. */
function nibblyMediaSettings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $stored = [];
    if (defined('SETTINGS_PATH') && is_file(SETTINGS_PATH)) {
        $data = json_decode((string)file_get_contents(SETTINGS_PATH), true);
        if (is_array($data) && isset($data['media']) && is_array($data['media'])) $stored = $data['media'];
    }
    return $cache = nibblySanitizeMediaSettings($stored);
}

/** Clamp a media settings array to the allowed values; unknown keys are dropped. */
function nibblySanitizeMediaSettings(array $input): array {
    $d = nibblyMediaSettingsDefaults();
    $int = static function ($value, int $default, int $min, int $max): int {
        return is_numeric($value) ? max($min, min($max, (int)$value)) : $default;
    };
    return [
        'maxUploadMb' => $int($input['maxUploadMb'] ?? null, $d['maxUploadMb'], 1, 200),
        'webVersion' => array_key_exists('webVersion', $input) ? !in_array($input['webVersion'], [false, 0, '0', 'false', '', null], true) : $d['webVersion'],
        'webMaxSide' => $int($input['webMaxSide'] ?? null, $d['webMaxSide'], 800, 6000),
        'webQuality' => $int($input['webQuality'] ?? null, $d['webQuality'], 50, 95),
    ];
}

/** The image upload limit Nibbly enforces, in bytes. */
function nibblyMediaImageMaxBytes(): int {
    return nibblyMediaSettings()['maxUploadMb'] * 1024 * 1024;
}

/** Parse a php.ini size such as "8M" or "512K" into bytes; -1 or 0 mean unlimited. */
function nibblyIniBytes($value): int {
    $value = trim((string)$value);
    if ($value === '' || $value === '-1' || $value === '0') return PHP_INT_MAX;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return (int)($number * match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 });
}

/** The largest file PHP lets through on this server, in bytes (the smaller of the two ini limits). */
function nibblyUploadServerLimit(): int {
    return min(nibblyIniBytes(ini_get('upload_max_filesize')), nibblyIniBytes(ini_get('post_max_size')));
}

/** Whole megabytes for messages: "2 MB", "20 MB", "0.5 MB" for odd values. */
function nibblyMegabytes(int $bytes): string {
    $mb = $bytes / 1048576;
    return ($mb >= 1 && abs($mb - round($mb)) < 0.05) ? (string)round($mb) : rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.');
}

/** Everything the library needs to show and check limits. */
function nibblyMediaUploadLimits(): array {
    $server = nibblyUploadServerLimit();
    $image = nibblyMediaImageMaxBytes();
    $s = nibblyMediaSettings();
    return [
        'serverMaxBytes' => $server === PHP_INT_MAX ? null : $server,
        'imageMaxBytes' => $image,
        'imageEffectiveBytes' => min($image, $server),
        'webVersion' => $s['webVersion'] && nibblyMediaCanEncodeWebp(),
        'webMaxSide' => $s['webMaxSide'],
        'webQuality' => $s['webQuality'],
    ];
}

function nibblyMediaCanEncodeWebp(): bool {
    return function_exists('imagecreatefromstring') && function_exists('imagewebp');
}

/** Folder for the uploaded originals, a sibling of the images folder. */
function nibblyMediaOriginalsPath(bool $trash = false): string {
    $images = defined('IMAGES_PATH') ? IMAGES_PATH : dirname(__DIR__) . '/assets/images/';
    return rtrim(dirname(rtrim($images, '/')), '/') . ($trash ? '/images-originals-trash/' : '/images-originals/');
}

/** Public path (relative to admin/) of the originals folder. */
function nibblyMediaOriginalsPublicPath(): string {
    return '../assets/images-originals/';
}

/**
 * Whether an image of this size should get a web version, and whether the
 * server can make one. Returns a reason string when it stays as uploaded.
 */
function nibblyMediaWebVersionDecision(string $file, string $mime, int $bytes, array $limits): ?string {
    if (!$limits['webVersion']) return 'off';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return 'format';
    $info = @getimagesize($file);
    if ($info === false) return 'unreadable';
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w < 1 || $h < 1 || $w > NIBBLY_MEDIA_MAX_INPUT_SIDE || $h > NIBBLY_MEDIA_MAX_INPUT_SIDE || $w * $h > NIBBLY_MEDIA_MAX_INPUT_PIXELS) return 'pixels';
    if (max($w, $h) <= $limits['webMaxSide'] && $bytes <= NIBBLY_MEDIA_WEB_MIN_BYTES) return 'small';
    if (!nibblyMediaImageFitsMemory($w, $h)) return 'memory';
    return null;
}

/** GD needs about 4 bytes per pixel, twice while scaling; raise the limit once when the server allows it. */
function nibblyMediaImageFitsMemory(int $width, int $height): bool {
    $needed = $width * $height * 9 + 32 * 1048576;
    $fits = static function () use ($needed): bool {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') return true;
        return $needed < (nibblyIniBytes($limit) - memory_get_usage()) * 0.9;
    };
    if ($fits()) return true;
    $wanted = (int)ceil(($needed + memory_get_usage()) / 1048576 / 64) * 64;
    @ini_set('memory_limit', max($wanted, 256) . 'M');
    return $fits();
}

/** EXIF orientation of a JPEG (1 when none); a copy of the agent's reader so the library has no agent dependency. */
function nibblyMediaJpegOrientation(string $binary): int {
    if (strncmp($binary, "\xFF\xD8", 2) !== 0) return 1;
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode(substr($binary, 0, 262144)));
        if (is_array($exif) && isset($exif['Orientation'])) return max(1, min(8, (int)$exif['Orientation']));
    }
    return 1;
}

/**
 * Write the web version of $sourceFile as $targetFile (WebP). Returns
 * [width, height, bytes] or null when encoding failed; the caller keeps the
 * upload as it is in that case.
 */
function nibblyMediaWriteWebVersion(string $sourceFile, string $targetFile, string $mime, array $limits): ?array {
    $binary = (string)file_get_contents($sourceFile);
    if ($binary === '') return null;
    $image = @imagecreatefromstring($binary);
    if (!$image instanceof GdImage) return null;
    try {
        if ($mime === 'image/jpeg') {
            $orientation = nibblyMediaJpegOrientation($binary);
            $rotate = [3 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90][$orientation] ?? 0;
            if ($rotate !== 0) {
                $rotated = imagerotate($image, $rotate, 0);
                if ($rotated instanceof GdImage) $image = $rotated;
            }
            if (in_array($orientation, [2, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
            if ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        $maxSide = (int)$limits['webMaxSide'];
        if ($longest > $maxSide) {
            // Default interpolation: some GD builds refuse IMG_BICUBIC and return false
            $scaled = imagescale($image, (int)max(1, round($width * $maxSide / $longest)), (int)max(1, round($height * $maxSide / $longest)));
            if ($scaled instanceof GdImage) $image = $scaled;
            $width = imagesx($image);
            $height = imagesy($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        $ok = imagewebp($image, null, (int)$limits['webQuality']);
        $out = (string)ob_get_clean();
    } finally {
        unset($image);
    }
    if (!$ok || $out === '') return null;
    if (file_put_contents($targetFile, $out, LOCK_EX) === false) return null;
    return ['width' => $width, 'height' => $height, 'bytes' => strlen($out)];
}

/** The original's relative name for a web file: same folder and base name, the uploaded extension. */
function nibblyMediaOriginalName(string $webRelative, string $extension): string {
    $folder = trim(str_replace('\\', '/', dirname($webRelative)), '.');
    return ($folder !== '' ? $folder . '/' : '') . pathinfo($webRelative, PATHINFO_FILENAME) . '.' . $extension;
}

/** Find the stored original for a library image, if any. */
function nibblyMediaOriginalFor(string $webRelative, bool $trash = false): ?array {
    $dir = nibblyMediaOriginalsPath($trash);
    $folder = trim(str_replace('\\', '/', dirname($webRelative)), '.');
    $stem = pathinfo($webRelative, PATHINFO_FILENAME);
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $relative = ($folder !== '' ? $folder . '/' : '') . $stem . '.' . $ext;
        $file = $dir . $relative;
        if (is_file($file) && !is_link($file)) {
            $bytes = (int)filesize($file);
            $info = @getimagesize($file);
            return [
                'name' => $relative,
                'path' => $trash ? '' : nibblyMediaOriginalsPublicPath() . $relative,
                'sizeBytes' => $bytes,
                'size' => function_exists('formatAssetSize') ? formatAssetSize($bytes) : nibblyMegabytes($bytes) . ' MB',
                'width' => $info ? (int)$info[0] : 0,
                'height' => $info ? (int)$info[1] : 0,
            ];
        }
    }
    return null;
}

/** Move an original along with its web file (rename, move, trash, restore). */
function nibblyMediaOriginalRelocate(string $fromRelative, bool $fromTrash, string $toRelative, bool $toTrash): void {
    $original = nibblyMediaOriginalFor($fromRelative, $fromTrash);
    if ($original === null) return;
    $ext = pathinfo($original['name'], PATHINFO_EXTENSION);
    $target = nibblyMediaOriginalsPath($toTrash) . nibblyMediaOriginalName($toRelative, $ext);
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    @rename(nibblyMediaOriginalsPath($fromTrash) . $original['name'], $target);
}

/** Delete the original of a web file that is being removed for good. */
function nibblyMediaOriginalForget(string $webRelative, bool $trash): void {
    $original = nibblyMediaOriginalFor($webRelative, $trash);
    if ($original !== null) @unlink(nibblyMediaOriginalsPath($trash) . $original['name']);
}

/**
 * Store an uploaded image: as a web version with the original kept, or as
 * it is. $moveFile moves the upload into place (move_uploaded_file in the
 * API, rename in tests). Returns the final relative name and what happened.
 *
 * @return array{name:string,webVersion:?array,reason:?string}|null null when nothing could be stored
 */
function nibblyMediaStoreImage(string $tmpFile, string $directory, string $relativeStem, string $extension, string $mime, callable $moveFile): ?array {
    $limits = nibblyMediaUploadLimits();
    $bytes = (int)@filesize($tmpFile);
    $reason = nibblyMediaWebVersionDecision($tmpFile, $mime, $bytes, $limits);
    $plainName = $relativeStem . '.' . $extension;
    if ($reason !== null) {
        if (!$moveFile($tmpFile, $directory . $plainName)) return null;
        return ['name' => $plainName, 'webVersion' => null, 'reason' => $reason];
    }
    $webName = $relativeStem . '.webp';
    $written = nibblyMediaWriteWebVersion($tmpFile, $directory . $webName, $mime, $limits);
    if ($written === null) {
        @unlink($directory . $webName);
        if (!$moveFile($tmpFile, $directory . $plainName)) return null;
        return ['name' => $plainName, 'webVersion' => null, 'reason' => 'encode'];
    }
    $originalFile = nibblyMediaOriginalsPath() . nibblyMediaOriginalName($webName, $extension);
    $originalDir = dirname($originalFile);
    if ((!is_dir($originalDir) && !@mkdir($originalDir, 0755, true)) || !$moveFile($tmpFile, $originalFile)) {
        // The web version exists; losing the original is the lesser harm, but say so.
        return ['name' => $webName, 'webVersion' => $written + ['originalKept' => false, 'originalBytes' => $bytes], 'reason' => null];
    }
    return ['name' => $webName, 'webVersion' => $written + ['originalKept' => true, 'originalBytes' => $bytes], 'reason' => null];
}
