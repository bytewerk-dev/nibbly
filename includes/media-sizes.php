<?php
/**
 * Responsive sizes for library images, and the <img> markup that uses them.
 *
 * Every JPEG, PNG or WebP in the library can have smaller copies, one per
 * width in NIBBLY_MEDIA_SIZE_WIDTHS (640 and 1280 px wide, WebP), made only
 * when the image is wider than that width. They live in assets/images-sizes/
 * next to the library under the image's own relative name plus the width
 * (team/anna.webp -> images-sizes/team/anna-640.webp), so they never show up
 * in the media library, and they travel along on rename, move, trash and
 * restore and go with the image when it is deleted for good
 * (nibblyMediaSizesRelocate() / nibblyMediaSizesForget(), the same pattern
 * as the uploaded originals in media-web-version.php).
 *
 * Sizes are made on upload (admin/api/media.php) and by cli/media-sizes.php
 * for images that were already in the library. Rendering never writes:
 * nibblyImageTag() and nibblyImageHtmlEnrich() add width/height, lazy
 * loading and, when sizes exist, srcset/sizes to public <img> tags; an image
 * without sizes gets a plain tag with its dimensions.
 */

require_once __DIR__ . '/media-web-version.php';
require_once __DIR__ . '/ai-labels.php';

/** Widths of the generated sizes, in px. */
const NIBBLY_MEDIA_SIZE_WIDTHS = [640, 1280];

/** Default sizes attribute for srcset: the site passes a better one where it knows the layout. */
const NIBBLY_IMAGE_SIZES_DEFAULT = '100vw';

function nibblyMediaSizeWidths(): array {
    return NIBBLY_MEDIA_SIZE_WIDTHS;
}

/** The image library folder (absolute, trailing slash). */
function nibblyMediaImagesPath(bool $trash = false): string {
    if ($trash) return rtrim(dirname(rtrim(nibblyMediaImagesPath(), '/')), '/') . '/images-trash/';
    return defined('IMAGES_PATH') ? IMAGES_PATH : dirname(__DIR__) . '/assets/images/';
}

/** Folder for the generated sizes, a sibling of the images folder (absolute, trailing slash). */
function nibblyMediaSizesPath(bool $trash = false): string {
    return rtrim(dirname(rtrim(nibblyMediaImagesPath(), '/')), '/') . ($trash ? '/images-sizes-trash/' : '/images-sizes/');
}

/** Relative name of one size: same folder and stem, the width appended, always .webp. */
function nibblyMediaSizeName(string $relative, int $width): string {
    $folder = trim(str_replace('\\', '/', dirname($relative)), '.');
    return ($folder !== '' ? $folder . '/' : '') . pathinfo($relative, PATHINFO_FILENAME) . '-' . $width . '.webp';
}

/** Whether a library file can have sizes at all (by extension). */
function nibblyMediaSizesEligible(string $relative): bool {
    return in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
}

/**
 * The sizes that exist for a library image: [width => [name, file, width, height, bytes]],
 * smallest first. Dimensions are read from the files, so the result is the truth on disk.
 */
function nibblyMediaSizesFor(string $relative, bool $trash = false): array {
    static $cache = [];
    $key = ($GLOBALS['nibblyMediaSizesCacheGeneration'] ?? 0) . ($trash ? '|T:' : '|L:') . $relative;
    if (isset($cache[$key])) return $cache[$key];
    $sizes = [];
    if (nibblyMediaSizesEligible($relative)) {
        $dir = nibblyMediaSizesPath($trash);
        foreach (nibblyMediaSizeWidths() as $width) {
            $name = nibblyMediaSizeName($relative, $width);
            $file = $dir . $name;
            if (!is_file($file) || is_link($file)) continue;
            $info = @getimagesize($file);
            if (!$info) continue;
            $sizes[$width] = ['name' => $name, 'file' => $file, 'width' => (int)$info[0], 'height' => (int)$info[1], 'bytes' => (int)filesize($file)];
        }
        ksort($sizes);
    }
    return $cache[$key] = $sizes;
}

/** Invalidate the per-request caches (sizes per image, srcset per source) after files changed. */
function nibblyMediaSizesCacheReset(): void {
    $GLOBALS['nibblyMediaSizesCacheGeneration'] = ($GLOBALS['nibblyMediaSizesCacheGeneration'] ?? 0) + 1;
}

/**
 * Make the sizes of a library image from its file. Returns the widths written;
 * widths the image is not wider than are skipped, existing files are kept
 * unless $force. Nothing happens for GIF, SVG, unreadable files or without GD.
 */
function nibblyMediaSizesGenerate(string $relative, bool $force = false, ?string $file = null): array {
    if (!nibblyMediaSizesEligible($relative) || !nibblyMediaCanEncodeWebp()) return [];
    $file ??= nibblyMediaImagesPath() . $relative;
    if (!is_file($file) || is_link($file)) return [];
    $info = @getimagesize($file);
    if (!$info) return [];
    [$imageWidth, $imageHeight] = [(int)$info[0], (int)$info[1]];
    if ($imageWidth < 1 || $imageHeight < 1 || $imageWidth > NIBBLY_MEDIA_MAX_INPUT_SIDE || $imageHeight > NIBBLY_MEDIA_MAX_INPUT_SIDE
        || $imageWidth * $imageHeight > NIBBLY_MEDIA_MAX_INPUT_PIXELS) return [];

    $wanted = [];
    $dir = nibblyMediaSizesPath();
    foreach (nibblyMediaSizeWidths() as $width) {
        if ($imageWidth <= $width) continue;
        $target = $dir . nibblyMediaSizeName($relative, $width);
        if (!$force && is_file($target)) continue;
        $wanted[$width] = $target;
    }
    if (!$wanted) return [];
    if (!nibblyMediaImageFitsMemory($imageWidth, $imageHeight)) return [];

    $binary = (string)file_get_contents($file);
    if ($binary === '') return [];
    $image = @imagecreatefromstring($binary);
    if (!$image instanceof GdImage) return [];
    $quality = (int)nibblyMediaSettings()['webQuality'];
    $written = [];
    try {
        // The source is the stored library file, so EXIF orientation is already applied
        foreach ($wanted as $width => $target) {
            $height = (int)max(1, round($imageHeight * $width / $imageWidth));
            $scaled = imagescale($image, $width, $height);
            if (!$scaled instanceof GdImage) continue;
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) { unset($scaled); continue; }
            ob_start();
            $ok = imagewebp($scaled, null, $quality);
            $out = (string)ob_get_clean();
            unset($scaled);
            if ($ok && $out !== '' && file_put_contents($target, $out, LOCK_EX) !== false) $written[] = $width;
        }
    } finally {
        unset($image);
    }
    nibblyMediaSizesCacheReset();
    return $written;
}

/** Move the sizes along with their image (rename, move, trash, restore). */
function nibblyMediaSizesRelocate(string $fromRelative, bool $fromTrash, string $toRelative, bool $toTrash): void {
    if (!nibblyMediaSizesEligible($fromRelative)) return;
    $fromDir = nibblyMediaSizesPath($fromTrash);
    $toDir = nibblyMediaSizesPath($toTrash);
    foreach (nibblyMediaSizeWidths() as $width) {
        $from = $fromDir . nibblyMediaSizeName($fromRelative, $width);
        if (!is_file($from) || is_link($from)) continue;
        $to = $toDir . nibblyMediaSizeName($toRelative, $width);
        $dir = dirname($to);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) continue;
        @rename($from, $to);
    }
    nibblyMediaSizesCacheReset();
}

/** Delete the sizes of an image that is being removed for good. */
function nibblyMediaSizesForget(string $relative, bool $trash): void {
    if (!nibblyMediaSizesEligible($relative)) return;
    $dir = nibblyMediaSizesPath($trash);
    foreach (nibblyMediaSizeWidths() as $width) {
        $file = $dir . nibblyMediaSizeName($relative, $width);
        if (is_file($file)) @unlink($file);
    }
    nibblyMediaSizesCacheReset();
}

/**
 * Sizes files whose image is no longer in the library (or trash): [relative size name].
 * The CLI removes them; the API never leaves any behind, this is for files moved by hand.
 */
function nibblyMediaSizesOrphans(bool $trash = false): array {
    $dir = nibblyMediaSizesPath($trash);
    if (!is_dir($dir)) return [];
    $images = nibblyMediaImagesPath($trash);
    $orphans = [];
    $widths = nibblyMediaSizeWidths();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) continue;
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($dir)));
        if (!preg_match('/^(.*)-(\d+)\.webp$/', $relative, $m) || !in_array((int)$m[2], $widths, true)) { $orphans[] = $relative; continue; }
        $stem = $m[1];
        $found = false;
        foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
            if (is_file($images . $stem . '.' . $ext)) { $found = true; break; }
        }
        if (!$found) $orphans[] = $relative;
    }
    sort($orphans);
    return $orphans;
}

/* --------------------------------------------------------------------------
   Public <img> markup
   -------------------------------------------------------------------------- */

/**
 * What a public image source maps to on disk: ['file' => absolute path, 'ref' => library name or null],
 * or null for remote images, traversal and files that do not exist. Library images are found through
 * the assets/images/ marker (so "../assets/images/x.webp" from admin pages and "/assets/images/x.webp"
 * both work); anything else is taken relative to the site root.
 */
function nibblyImageLocalFile(string $src): ?array {
    static $cache = [];
    if (isset($cache[$src])) return $cache[$src] ?: null;
    $result = null;
    $clean = (string)preg_replace('/[?#].*$/', '', trim($src));
    // "../assets/images/x.webp" (admin pages) is fine; ".." anywhere else is traversal
    $inner = (string)preg_replace('#^(\.\./)+#', '', $clean);
    if ($clean !== '' && !preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $clean) && !str_contains($inner, '..')) {
        $path = rawurldecode($clean);
        $ref = nibblyMediaRefFromSrc($path);
        if ($ref !== null && $ref['type'] === 'image') {
            $file = nibblyMediaImagesPath() . $ref['name'];
            if (is_file($file) && !is_link($file)) $result = ['file' => $file, 'ref' => $ref['name']];
        } else {
            $file = dirname(__DIR__) . '/' . ltrim($path, '/');
            if (is_file($file) && !is_link($file)) $result = ['file' => $file, 'ref' => null];
        }
    }
    $cache[$src] = $result ?? false;
    return $result;
}

/**
 * Dimensions and srcset of a public image source, or null when the file is not local:
 * ['width', 'height', 'srcset' => string|null]. srcset lists the generated sizes and the image
 * itself with width descriptors; it is null when no size exists (one candidate is no srcset).
 */
function nibblyImageSources(string $src): ?array {
    static $cache = [];
    $generation = $GLOBALS['nibblyMediaSizesCacheGeneration'] ?? 0;
    $key = $generation . '|' . $src;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $local = nibblyImageLocalFile($src);
    if ($local === null) return $cache[$key] = null;
    $info = @getimagesize($local['file']);
    if (!$info || (int)$info[0] < 1) return $cache[$key] = null;
    $result = ['width' => (int)$info[0], 'height' => (int)$info[1], 'srcset' => null];
    if ($local['ref'] !== null && nibblyMediaSizesEligible($local['ref'])) {
        $candidates = [];
        foreach (nibblyMediaSizesFor($local['ref']) as $width => $size) {
            if ($size['width'] >= $result['width']) continue;
            $candidates[] = nibblyImageSizeSrc($src, $local['ref'], $width) . ' ' . $size['width'] . 'w';
        }
        if ($candidates) {
            $candidates[] = $src . ' ' . $result['width'] . 'w';
            $result['srcset'] = implode(', ', $candidates);
        }
    }
    return $cache[$key] = $result;
}

/** Public URL of one size, derived from the image's own URL (keeps its prefix: base path, host). */
function nibblyImageSizeSrc(string $src, string $relative, int $width): string {
    $marker = 'assets/images/';
    $position = strpos($src, $marker);
    $prefix = $position === false ? '/' : substr($src, 0, $position);
    return $prefix . 'assets/images-sizes/' . str_replace('%2F', '/', rawurlencode(nibblyMediaSizeName($relative, $width)));
}

/**
 * A public <img> tag: width/height from the file, lazy loading, and srcset/sizes when sizes
 * exist. Options: class, lazy (default true; false adds fetchpriority="high" for the hero
 * image), sizes (the sizes attribute, default 100vw), srcset (false leaves it out), attrs
 * (raw extra attributes). The src is emitted as given, so the caller keeps control of paths.
 */
function nibblyImageTag(string $src, string $alt = '', array $options = []): string {
    $class = (string)($options['class'] ?? '');
    $lazy = array_key_exists('lazy', $options) ? (bool)$options['lazy'] : true;
    $attrs = ' src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"';
    if ($class !== '') $attrs .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';
    $sources = nibblyImageSources($src);
    if ($sources !== null) {
        $attrs .= ' width="' . $sources['width'] . '" height="' . $sources['height'] . '"';
        if ($sources['srcset'] !== null && ($options['srcset'] ?? true)) {
            $attrs .= ' srcset="' . htmlspecialchars($sources['srcset'], ENT_QUOTES, 'UTF-8') . '"';
            $attrs .= ' sizes="' . htmlspecialchars((string)($options['sizes'] ?? NIBBLY_IMAGE_SIZES_DEFAULT), ENT_QUOTES, 'UTF-8') . '"';
        }
    }
    $attrs .= $lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"';
    $extra = trim((string)($options['attrs'] ?? ''));
    if ($extra !== '') $attrs .= ' ' . $extra;
    return '<img' . $attrs . '>';
}

/**
 * Complete the <img> tags in rendered HTML (stored text, imported content): width/height from
 * the file, loading="lazy" decoding="async" unless a loading attribute is set, and srcset/sizes
 * when sizes exist and the tag has none. Remote images and tags without a quoted src stay as
 * they are. $sizes is the sizes attribute for every image the filter completes.
 */
function nibblyImageHtmlEnrich(string $html, string $sizes = NIBBLY_IMAGE_SIZES_DEFAULT): string {
    if (!str_contains($html, '<img')) return $html;
    return (string)preg_replace_callback('/<img\b[^>]*>/i', static function (array $m) use ($sizes): string {
        $tag = $m[0];
        if (!preg_match('/\ssrc\s*=\s*"([^"]+)"/i', $tag, $src)) return $tag;
        $url = html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sources = nibblyImageSources($url);
        $add = '';
        if ($sources !== null) {
            if (!preg_match('/\swidth\s*=/i', $tag)) $add .= ' width="' . $sources['width'] . '" height="' . $sources['height'] . '"';
            if ($sources['srcset'] !== null && !preg_match('/\ssrcset\s*=/i', $tag)) {
                $add .= ' srcset="' . htmlspecialchars($sources['srcset'], ENT_QUOTES, 'UTF-8') . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        if (!preg_match('/\sloading\s*=/i', $tag)) $add .= ' loading="lazy"' . (preg_match('/\sdecoding\s*=/i', $tag) ? '' : ' decoding="async"');
        return $add === '' ? $tag : preg_replace('/<img\b/i', '<img' . $add, $tag, 1);
    }, $html);
}

/**
 * Pixel size of a library image for the media library listing, cached in content/media-dimensions.json
 * (key: absolute file, stamp: mtime|bytes) so listing 2000 files costs a few milliseconds instead of a
 * getimagesize() per file. The cache is written once per request at shutdown; a missing or unreadable
 * image yields null and is not cached.
 */
function nibblyMediaDimensions(string $file): ?array {
    static $cache = null, $dirty = false, $store = null;
    if ($cache === null) {
        $store = dirname(__DIR__) . '/content/media-dimensions.json';
        $cache = is_file($store) ? (json_decode((string)@file_get_contents($store), true) ?: []) : [];
        register_shutdown_function(function () use (&$cache, &$dirty, $store) {
            if ($dirty) @file_put_contents($store, json_encode($cache), LOCK_EX);
        });
    }
    $stat = @stat($file);
    if (!$stat) return null;
    $stamp = $stat['mtime'] . '|' . $stat['size'];
    if (isset($cache[$file]) && ($cache[$file]['s'] ?? '') === $stamp) {
        return $cache[$file]['w'] ? ['width' => $cache[$file]['w'], 'height' => $cache[$file]['h']] : null;
    }
    $info = @getimagesize($file);
    $w = $info ? (int)$info[0] : 0;
    $h = $info ? (int)$info[1] : 0;
    $cache[$file] = ['s' => $stamp, 'w' => $w, 'h' => $h];
    $dirty = true;
    return $w ? ['width' => $w, 'height' => $h] : null;
}
