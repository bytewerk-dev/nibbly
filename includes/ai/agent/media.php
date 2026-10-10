<?php
/**
 * Images for the agent (agent phase P5).
 *
 * Every image that enters a run (upload, stock photo, generated image) goes through
 * nibblyAgentIngestImage(): checked, AI provenance read from the original bytes, then decoded
 * and re-encoded, which drops metadata and anything hidden in the file. The result sits in
 * content/ai-agent/staging/{changeset}/ and is registered in the change set as a media
 * reference (m_…). Only when the package is applied do the images that it uses move into the
 * media library, with credit and AI label; unused ones are deleted with the staging folder.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/changeset-apply.php';
require_once __DIR__ . '/net.php';

const NIBBLY_AGENT_IMAGE_MAX_SIDE = 2560;
const NIBBLY_AGENT_IMAGE_MAX_INPUT_SIDE = 12000;
const NIBBLY_AGENT_IMAGE_MAX_PIXELS = 40000000;
const NIBBLY_AGENT_IMAGE_MAX_BYTES = 15 * 1024 * 1024;
const NIBBLY_AGENT_MAX_MEDIA_PER_CHANGESET = 20;

// ---------------------------------------------------------------------------
// Ingest
// ---------------------------------------------------------------------------

/** What this server can do with images; shown in the system status. */
function nibblyAgentImageSupport(): array {
    return ['gd' => function_exists('imagecreatefromstring'), 'webp' => function_exists('imagewebp'), 'jpeg' => function_exists('imagejpeg')];
}

/** EXIF orientation (1-8) of a JPEG, read without the exif extension. 1 when absent or unreadable. */
function nibblyAgentJpegOrientation(string $binary): int {
    if (strlen($binary) < 12 || substr($binary, 0, 2) !== "\xFF\xD8") return 1;
    $offset = 2;
    $length = strlen($binary);
    while ($offset + 4 <= $length && $binary[$offset] === "\xFF") {
        $marker = ord($binary[$offset + 1]);
        if ($marker === 0xDA || $marker === 0xD9) break;
        $size = (ord($binary[$offset + 2]) << 8) | ord($binary[$offset + 3]);
        if ($marker === 0xE1 && substr($binary, $offset + 4, 6) === "Exif\0\0") {
            $tiff = substr($binary, $offset + 10, $size - 8);
            if (strlen($tiff) < 14) return 1;
            $little = substr($tiff, 0, 2) === 'II';
            $u16 = static fn(int $at) => $little ? (ord($tiff[$at + 1]) << 8 | ord($tiff[$at])) : (ord($tiff[$at]) << 8 | ord($tiff[$at + 1]));
            $u32 = static fn(int $at) => $little
                ? (ord($tiff[$at + 3]) << 24 | ord($tiff[$at + 2]) << 16 | ord($tiff[$at + 1]) << 8 | ord($tiff[$at]))
                : (ord($tiff[$at]) << 24 | ord($tiff[$at + 1]) << 16 | ord($tiff[$at + 2]) << 8 | ord($tiff[$at + 3]));
            $ifd = $u32(4);
            if ($ifd + 2 > strlen($tiff)) return 1;
            $entries = $u16($ifd);
            for ($i = 0; $i < $entries && $ifd + 2 + $i * 12 + 12 <= strlen($tiff); $i++) {
                $entry = $ifd + 2 + $i * 12;
                if ($u16($entry) === 0x0112) {
                    $value = $u16($entry + 8);
                    return $value >= 1 && $value <= 8 ? $value : 1;
                }
            }
            return 1;
        }
        $offset += 2 + $size;
    }
    return 1;
}

/** Whether decoding this image fits into the memory this request may use. */
function nibblyAgentImageFitsMemory(int $width, int $height): bool {
    $limit = ini_get('memory_limit');
    if ($limit === '-1' || $limit === false || $limit === '') return true;
    $bytes = (int)$limit;
    $unit = strtolower(substr(trim($limit), -1));
    $bytes *= match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
    // GD keeps about 4 bytes per pixel, and resizing needs a second image.
    return ($width * $height * 9) < max(0, $bytes - memory_get_usage()) * 0.9;
}

/**
 * Check and clean one image file.
 *
 * @param array $options maxBytes (default 15 MB), sourceBytes (binary of the original when it is not $file)
 * @return array{binary:string,ext:string,mime:string,width:int,height:int,ai:string,bytes:int}
 * @throws RuntimeException with a message that is safe to show
 */
function nibblyAgentIngestImage(string $file, array $options = []): array {
    $support = nibblyAgentImageSupport();
    if (!$support['gd'] || (!$support['webp'] && !$support['jpeg'])) {
        throw new RuntimeException('This server cannot process images (the PHP GD extension is missing), so the agent cannot use images.');
    }
    $maxBytes = (int)($options['maxBytes'] ?? NIBBLY_AGENT_IMAGE_MAX_BYTES);
    $size = @filesize($file);
    if ($size === false || $size === 0) throw new RuntimeException('The image file is empty or unreadable.');
    if ($size > $maxBytes) throw new RuntimeException('The image is too large (maximum ' . round($maxBytes / 1048576) . ' MB).');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $file) : '';
    unset($finfo);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Only JPEG, PNG and WebP images are accepted.');
    }
    $info = @getimagesize($file);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('The file is not a valid image.');
    }
    [$width, $height] = [(int)$info[0], (int)$info[1]];
    if ($width < 1 || $height < 1 || $width > NIBBLY_AGENT_IMAGE_MAX_INPUT_SIDE || $height > NIBBLY_AGENT_IMAGE_MAX_INPUT_SIDE || $width * $height > NIBBLY_AGENT_IMAGE_MAX_PIXELS) {
        throw new RuntimeException('The image has too many pixels (at most 12,000 px per side and 40 megapixels).');
    }
    if (!nibblyAgentImageFitsMemory($width, $height)) {
        throw new RuntimeException('The image is too large to process on this server.');
    }

    // Provenance lives in the metadata that re-encoding removes: read it first.
    $ai = nibblyDetectAiLabel($file);
    $binary = (string)file_get_contents($file);
    $image = @imagecreatefromstring($binary);
    if (!$image instanceof GdImage) throw new RuntimeException('The image could not be decoded.');
    try {
        if ($mime === 'image/jpeg') {
            // EXIF orientation: 2 mirror, 3 upside down, 4 flipped, 5 transposed, 6 turned right, 7 transversed, 8 turned left.
            $orientation = nibblyAgentJpegOrientation($binary);
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
        if ($longest > NIBBLY_AGENT_IMAGE_MAX_SIDE) {
            $scaled = imagescale($image, (int)max(1, round($width * NIBBLY_AGENT_IMAGE_MAX_SIDE / $longest)), (int)max(1, round($height * NIBBLY_AGENT_IMAGE_MAX_SIDE / $longest)));
            if ($scaled instanceof GdImage) $image = $scaled;
            $width = imagesx($image);
            $height = imagesy($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        if ($support['webp']) {
            imagewebp($image, null, 82);
            $ext = 'webp';
            $outMime = 'image/webp';
        } else {
            // JPEG has no alpha: flatten onto white.
            $flat = imagecreatetruecolor($width, $height);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagealphablending($flat, true);
            imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
            imagejpeg($flat, null, 85);
            $ext = 'jpg';
            $outMime = 'image/jpeg';
        }
        $out = (string)ob_get_clean();
    } finally {
        unset($image);
    }
    if ($out === '' || strlen($out) > $maxBytes) throw new RuntimeException('The image could not be re-encoded.');
    return ['binary' => $out, 'ext' => $ext, 'mime' => $outMime, 'width' => $width, 'height' => $height, 'ai' => $ai, 'bytes' => strlen($out)];
}

// ---------------------------------------------------------------------------
// Staging and registration
// ---------------------------------------------------------------------------

function nibblyAgentMediaStagingDir(string $csId): string {
    return nibblyAgentDir() . '/staging/' . $csId;
}

function nibblyAgentMediaSlug(string $name): string {
    $name = strtolower(pathinfo($name, PATHINFO_FILENAME));
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']));
        if (is_string($converted) && $converted !== '') $name = $converted;
    }
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $name) ?? '', '-');
    return substr($slug !== '' ? $slug : 'image', 0, 48);
}

/** Library folder for a source: generated images keep their usual folder, everything else goes to the agent folder. */
function nibblyAgentMediaFolder(string $source): string {
    return $source === 'generated' ? 'generated' : nibblyAgentSettings()['mediaFolder'];
}

/**
 * Store an ingested image in the staging folder and register it in the change set.
 *
 * @param array $record source (upload|stock|generated), name (original name or hint), credit?, ai
 * @return array the media record, including its reference 'ref'
 */
function nibblyAgentMediaRegister(string $csId, array $image, array $record): array {
    $registered = null;
    $error = null;
    $stored = nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($image, $record, &$registered, &$error) {
        if ($cs['status'] !== 'staging') { $error = 'This change set is ' . $cs['status'] . '.'; return false; }
        if (count($cs['media']) >= NIBBLY_AGENT_MAX_MEDIA_PER_CHANGESET) { $error = 'This run already has the maximum number of images.'; return false; }
        do {
            $ref = 'm_' . bin2hex(random_bytes(4));
        } while (isset($cs['media'][$ref]));
        $source = in_array($record['source'] ?? '', ['upload', 'stock', 'generated'], true) ? $record['source'] : 'upload';
        $folder = nibblyAgentMediaFolder($source);
        $imagesRoot = nibblyAgentSiteRoot() . '/assets/images';
        do {
            $finalName = nibblyAgentMediaSlug((string)($record['name'] ?? 'image')) . '-' . bin2hex(random_bytes(2)) . '.' . $image['ext'];
            $final = '/assets/images/' . $folder . '/' . $finalName;
            $taken = is_file($imagesRoot . '/' . $folder . '/' . $finalName);
            foreach ($cs['media'] as $other) $taken = $taken || ($other['finalPath'] ?? '') === $final;
        } while ($taken);
        $dir = nibblyAgentMediaStagingDir($cs['id']);
        $stagingFile = $dir . '/' . $ref . '.' . $image['ext'];
        if (!nibblyAgentRawWrite($stagingFile, $image['binary'])) { $error = 'Could not store the image.'; return false; }
        $entry = [
            'source' => $source,
            'name' => mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u', '', (string)($record['name'] ?? '')) ?? '', 0, 80, 'UTF-8'),
            'stagingFile' => nibblyAgentRelative($stagingFile),
            'finalPath' => $final,
            'width' => $image['width'], 'height' => $image['height'], 'bytes' => $image['bytes'], 'mime' => $image['mime'],
            'ai' => in_array($record['ai'] ?? '', ['generated', 'modified'], true) ? $record['ai'] : '',
            'credit' => array_intersect_key((array)($record['credit'] ?? []), array_flip(['source', 'author', 'authorUrl', 'sourceUrl', 'license', 'attribution'])),
            'importedAt' => date('c'), 'importedBy' => (string)$cs['owner'],
        ];
        // Sidecar next to the image: what it is and where it came from, without the bytes.
        nibblyJsonAtomicWrite($dir . '/' . $ref . '.json', ['ref' => $ref] + $entry);
        $cs['media'][$ref] = $entry;
        $registered = ['ref' => $ref] + $entry;
        return true;
    });
    if ($stored === null) throw new RuntimeException('Unknown change set.');
    if ($registered === null) throw new RuntimeException($error ?? 'Could not register the image.');
    return $registered;
}

/** What the model and the browser may know about a staged image. */
function nibblyAgentMediaSummary(string $ref, array $media): array {
    $summary = ['mediaRef' => $ref, 'source' => $media['source'], 'width' => $media['width'], 'height' => $media['height']];
    if (($media['name'] ?? '') !== '') $summary['name'] = $media['name'];
    if (!empty($media['ai'])) $summary['ai'] = $media['ai'];
    if (!empty($media['credit']['attribution'])) $summary['credit'] = $media['credit']['attribution'];
    return $summary;
}

// ---------------------------------------------------------------------------
// Credit text
// ---------------------------------------------------------------------------

/** "Photo: Jane Doe / Pexels" in the language of the page, or '' when the image needs no credit. */
function nibblyAgentCreditText(array $credit, string $lang): string {
    $author = trim(strip_tags((string)($credit['author'] ?? '')));
    $source = trim(strip_tags((string)($credit['source'] ?? '')));
    if ($author === '' && $source === '') return '';
    $label = ['de' => 'Foto', 'fr' => 'Photo', 'es' => 'Foto', 'it' => 'Foto', 'pt' => 'Foto', 'tr' => 'Fotoğraf', 'cs' => 'Foto', 'pl' => 'Zdjęcie'][$lang] ?? 'Photo';
    return $label . ': ' . implode(' / ', array_filter([$author, ucfirst($source)]));
}

// ---------------------------------------------------------------------------
// Apply and undo
// ---------------------------------------------------------------------------

/** Whether a public image path is used by any content file other than the ones in $except. */
function nibblyAgentMediaReferenced(string $publicPath, array $except = []): bool {
    $root = nibblyAgentSiteRoot() . '/content';
    $files = array_merge(glob($root . '/pages/*.json') ?: [], glob($root . '/news/*.json') ?: [], [$root . '/events.json']);
    foreach ($files as $file) {
        if (in_array($file, $except, true) || !is_file($file)) continue;
        $content = (string)file_get_contents($file);
        // Older files and some editors write "\/" for "/".
        if (str_contains($content, $publicPath) || str_contains($content, str_replace('/', '\\/', $publicPath))) return true;
    }
    return false;
}

/**
 * Move the staged images that the applied documents use into the media library, with their
 * credit and AI label. All or nothing: on failure everything moved so far goes back.
 *
 * @param array $changed file => {after} from nibblyAgentChangedFiles()
 * @return array<int,array{ref:string,name:string,path:string,staging:string}> the moved images
 * @throws RuntimeException
 */
function nibblyAgentApplyMedia(array $cs, array $changed): array {
    $json = '';
    foreach ($changed as $change) $json .= (string)json_encode($change['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $moved = [];
    try {
        foreach ($cs['media'] as $ref => $media) {
            if (!str_contains($json, $media['finalPath'])) continue; // staged but not used: stays out of the library
            $from = nibblyAgentAbsolute((string)$media['stagingFile']);
            $to = nibblyAgentSiteRoot() . $media['finalPath'];
            if (!is_file($from)) throw new RuntimeException("The staged image {$ref} is missing.");
            if (is_file($to)) throw new RuntimeException('An image named ' . basename($to) . ' already exists in the library.');
            if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true) && !is_dir(dirname($to))) throw new RuntimeException('Could not create the library folder.');
            if (!@rename($from, $to) && !(@copy($from, $to) && @unlink($from))) throw new RuntimeException('Could not move the image into the library.');
            $name = substr($media['finalPath'], strlen('/assets/images/'));
            $moved[] = ['ref' => $ref, 'name' => $name, 'path' => $to, 'staging' => $from];
            if (!empty($media['ai']) && !nibblySetMediaAiLabel('image', $name, (string)$media['ai'])) throw new RuntimeException('Could not store the AI label.');
            if ($media['credit'] && !nibblySetMediaCredit('image', $name, $media['credit'] + ['importedAt' => $media['importedAt'], 'importedBy' => $media['importedBy']])) {
                throw new RuntimeException('Could not store the image credit.');
            }
        }
    } catch (Throwable $error) {
        nibblyAgentRollbackMedia($moved);
        throw $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
    }
    return $moved;
}

/** Put moved images back into the staging folder and forget their metadata. */
function nibblyAgentRollbackMedia(array $moved): void {
    foreach (array_reverse($moved) as $entry) {
        if (is_file($entry['path']) && !is_file($entry['staging'])) {
            @mkdir(dirname($entry['staging']), 0755, true);
            @rename($entry['path'], $entry['staging']);
        }
        nibblyForgetMediaMeta('image', $entry['name']);
    }
}

/**
 * After an undo: images this change set brought into the library go to the media trash,
 * unless other content uses them now.
 *
 * @return string[] names moved to the trash
 */
function nibblyAgentUndoMedia(array $appliedMedia): array {
    $trashed = [];
    $trash = nibblyAgentSiteRoot() . '/assets/images-trash';
    foreach ($appliedMedia as $entry) {
        $publicPath = '/assets/images/' . $entry['name'];
        $file = nibblyAgentSiteRoot() . $publicPath;
        if (!is_file($file) || nibblyAgentMediaReferenced($publicPath)) continue;
        $target = $trash . '/' . $entry['name'];
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) continue;
        if (is_file($target)) $target = dirname($target) . '/' . pathinfo($target, PATHINFO_FILENAME) . '-' . bin2hex(random_bytes(2)) . '.' . pathinfo($target, PATHINFO_EXTENSION);
        if (@rename($file, $target)) {
            nibblyMoveMediaMeta('image', $entry['name'], 'image-trash', substr($target, strlen($trash) + 1));
            $trashed[] = $entry['name'];
        }
    }
    return $trashed;
}

// ---------------------------------------------------------------------------
// Stock photos (Pexels first; the interface is search + fetch)
// ---------------------------------------------------------------------------

/** Keys come from the raw ai-settings.json, like the other agent settings (the settings UI follows in P8). */
function nibblyAgentStockSettings(): array {
    $raw = [];
    if (is_file(NIBBLY_AI_SETTINGS_PATH)) {
        $stored = json_decode((string)file_get_contents(NIBBLY_AI_SETTINGS_PATH), true);
        $raw = is_array($stored['stock'] ?? null) ? $stored['stock'] : [];
    }
    $key = is_string($raw['pexels']['apiKey'] ?? null) ? trim($raw['pexels']['apiKey']) : '';
    return ['provider' => 'pexels', 'apiKey' => $key, 'available' => $key !== ''];
}

/**
 * Network options of the stock provider. Tests may redirect it to a loopback mock through
 * NIBBLY_AGENT_TEST_NET (JSON: apiBase, resolve {host: [ip]}, ports, connectMap); this only
 * works under the CLI and the built-in server.
 */
function nibblyAgentStockNet(): array {
    $net = ['apiBase' => 'https://api.pexels.com', 'secure' => true, 'options' => []];
    $raw = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? getenv('NIBBLY_AGENT_TEST_NET') : false;
    $test = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($test)) {
        $resolve = (array)($test['resolve'] ?? []);
        $net['apiBase'] = (string)($test['apiBase'] ?? $net['apiBase']);
        $net['secure'] = false;
        $net['options'] = [
            'resolver' => static fn(string $host): array => (array)($resolve[$host] ?? []),
            'testAllowedPorts' => array_map('intval', (array)($test['ports'] ?? [])),
            'testConnectMap' => (array)($test['connectMap'] ?? []),
        ];
    }
    return $net;
}

/**
 * Search stock photos.
 *
 * @return array[] photos: id, alt, width, height, photographer, photographerUrl, pageUrl, downloadUrl, previewUrl
 * @throws RuntimeException
 */
function nibblyAgentStockSearch(string $query, string $orientation = '', int $count = 6): array {
    $settings = nibblyAgentStockSettings();
    if (!$settings['available']) throw new RuntimeException('Stock photos are not set up on this site.');
    $net = nibblyAgentStockNet();
    $params = ['query' => mb_substr($query, 0, 200, 'UTF-8'), 'per_page' => max(1, min(8, $count)), 'size' => 'large'];
    if (in_array($orientation, ['landscape', 'portrait', 'square'], true)) $params['orientation'] = $orientation;
    try {
        $response = nibblyNetSafeFetch($net['apiBase'] . '/v1/search?' . http_build_query($params), $net['options'] + [
            'allowedHosts' => ['api.pexels.com'], 'allowedContentTypes' => ['application/json'], 'maxBytes' => 1048576, 'timeout' => 15,
            'headers' => ['Authorization: ' . $settings['apiKey']], 'accept' => 'application/json', 'userAgent' => 'nibbly-cms/1.0',
        ]);
    } catch (NibblyNetException $e) {
        throw new RuntimeException($e->reason === 'http_status' && in_array($e->httpStatus, [401, 403], true) ? 'The stock photo service refused the API key.' : ($e->httpStatus === 429 ? 'The stock photo service is busy. Try again later.' : 'The stock photo search failed.'), 0, $e);
    }
    $data = json_decode($response['body'], true);
    $photos = [];
    foreach ((array)($data['photos'] ?? []) as $photo) {
        if (!is_array($photo) || !isset($photo['id'], $photo['src']) || !is_array($photo['src'])) continue;
        $download = (string)($photo['src']['large2x'] ?? $photo['src']['large'] ?? '');
        if ($download === '') continue;
        $photos[] = [
            'id' => (string)$photo['id'], 'alt' => mb_substr(trim(strip_tags((string)($photo['alt'] ?? ''))), 0, 200, 'UTF-8'),
            'width' => (int)($photo['width'] ?? 0), 'height' => (int)($photo['height'] ?? 0),
            'photographer' => mb_substr(trim(strip_tags((string)($photo['photographer'] ?? ''))), 0, 100, 'UTF-8'),
            'photographerUrl' => (string)($photo['photographer_url'] ?? ''), 'pageUrl' => (string)($photo['url'] ?? ''),
            'downloadUrl' => $download, 'previewUrl' => (string)($photo['src']['medium'] ?? ''),
        ];
    }
    return $photos;
}

/**
 * Download a stock photo found by nibblyAgentStockSearch() and ingest it.
 *
 * @return array{image:array,credit:array}
 * @throws RuntimeException
 */
function nibblyAgentStockFetch(array $photo): array {
    $net = nibblyAgentStockNet();
    $url = (string)$photo['downloadUrl'];
    if ($net['secure'] && !str_starts_with($url, 'https://')) throw new RuntimeException('The stock photo address is not secure.');
    try {
        $response = nibblyNetSafeFetch($url, $net['options'] + [
            'allowedHosts' => ['images.pexels.com'], 'allowedContentTypes' => ['image/jpeg', 'image/png', 'image/webp'],
            'maxBytes' => NIBBLY_AGENT_IMAGE_MAX_BYTES, 'timeout' => 25, 'accept' => 'image/jpeg,image/png,image/webp', 'userAgent' => 'nibbly-cms/1.0',
        ]);
    } catch (NibblyNetException $e) {
        throw new RuntimeException('The stock photo could not be downloaded.', 0, $e);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'nibbly-stock-');
    if ($tmp === false) throw new RuntimeException('Could not create a temporary file.');
    try {
        file_put_contents($tmp, $response['body']);
        $image = nibblyAgentIngestImage($tmp);
    } finally {
        @unlink($tmp);
    }
    $credit = [
        'source' => 'pexels', 'author' => $photo['photographer'], 'authorUrl' => $photo['photographerUrl'], 'sourceUrl' => $photo['pageUrl'],
        'license' => 'Pexels License', 'attribution' => 'Photo by ' . $photo['photographer'] . ' on Pexels',
    ];
    return ['image' => $image, 'credit' => $credit];
}
