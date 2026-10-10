<?php

declare(strict_types=1);

// Agent images: checks, provenance, re-encoding, staging, credits, and the move into the
// library on apply (and out again on undo). Throw-away site from tests/fixtures/agent/site.

function mediaAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-media-' . bin2hex(random_bytes(4));
mkdir($site, 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
foreach (['includes', 'admin', 'VERSION'] as $item) exec('cp -R ' . escapeshellarg($root . '/' . $item) . ' ' . escapeshellarg($site . '/'));
foreach (['admin/config.php', 'admin/smtp-config.php', 'includes/nav-config.php'] as $local) @unlink($site . '/' . $local);
exec('cp -R ' . escapeshellarg($root . '/tests/fixtures/agent/site/.') . ' ' . escapeshellarg($site . '/'));
rename($site . '/lang-en', $site . '/en');
mkdir($site . '/assets/images', 0755, true);
mkdir($site . '/content/news', 0755, true);
copy($root . '/assets/images/placeholder-image.webp', $site . '/assets/images/library.webp');
file_put_contents($site . '/content/events.json', json_encode(['events' => []]));
file_put_contents($site . '/content/ai-settings.json', json_encode(['enabled' => true, 'agent' => ['enabled' => true]]));

define('SITE_LANG_DEFAULT', 'en');
define('CONTENT_BASE_PATH', $site . '/content/pages/');
$_SESSION = ['admin_role' => 'editor', 'admin_user_id' => 'u_editor', 'admin_username' => 'editor'];
require_once $site . '/includes/ai/agent/tools.php';

// ---- Test images -------------------------------------------------------------------------------
function mediaGd(int $w, int $h, bool $alpha = false): string {
    $image = imagecreatetruecolor($w, $h);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, $alpha ? imagecolorallocatealpha($image, 200, 30, 30, 64) : imagecolorallocate($image, 30, 120, 200));
    imagefilledrectangle($image, 0, 0, intdiv($w, 2), intdiv($h, 2), imagecolorallocate($image, 250, 250, 20)); // top-left marker
    ob_start();
    $alpha ? imagepng($image) : imagejpeg($image, null, 90);
    return (string)ob_get_clean();
}

/** Insert JPEG segments right after the SOI marker. */
function mediaJpegWith(string $jpeg, array $segments): string {
    $out = '';
    foreach ($segments as [$marker, $payload]) $out .= "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    return substr($jpeg, 0, 2) . $out . substr($jpeg, 2);
}

function mediaExif(int $orientation): string {
    $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
    return "Exif\0\0" . $tiff;
}

function mediaTmp(string $bytes, string $ext = 'jpg'): string {
    $file = tempnam(sys_get_temp_dir(), 'media-test-') . '.' . $ext;
    file_put_contents($file, $bytes);
    register_shutdown_function(static fn() => @unlink($file));
    return $file;
}

function mediaRejects(string $file, string $needle, string $what, array $options = []): void {
    try {
        nibblyAgentIngestImage($file, $options);
    } catch (RuntimeException $e) {
        mediaAssert(stripos($e->getMessage(), $needle) !== false, "{$what}: message '{$e->getMessage()}' should mention '{$needle}'");
        return;
    }
    fwrite(STDERR, "FAIL: {$what} must be rejected\n");
    exit(1);
}

mediaAssert(nibblyAgentImageSupport()['gd'], 'GD is available for these tests');

// ---- What is refused -------------------------------------------------------------------------------
$png = (static function () { $i = imagecreatetruecolor(4, 4); ob_start(); imagepng($i); return (string)ob_get_clean(); })();
mediaRejects(mediaTmp('', 'jpg'), 'empty', 'an empty file');
mediaRejects(mediaTmp('<?php echo 1; ?>', 'jpg'), 'Only JPEG, PNG and WebP', 'PHP code named .jpg');
mediaRejects(mediaTmp('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'png'), 'Only JPEG, PNG and WebP', 'an SVG named .png');
mediaRejects(mediaTmp("GIF89a\x01\x00\x01\x00\x00\x00\x00;<?php system(\$_GET['x']); ?>", 'jpg'), 'Only JPEG, PNG and WebP', 'a GIF with PHP behind it');
mediaRejects(mediaTmp($png, 'png'), 'too large', 'a file above the limit', ['maxBytes' => 50]);
// An image that claims 13,000 x 5 pixels, and one with 50 megapixels: refused from the header, before any decoding
$hugeHeader = static function (int $w, int $h): string {
    $ihdr = pack('NN', $w, $h) . "\x08\x02\x00\x00\x00";
    $chunk = static fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', gzcompress(str_repeat("\0", 32))) . $chunk('IEND', '');
};
mediaRejects(mediaTmp($hugeHeader(13000, 5), 'png'), 'too many pixels', 'one side above 12,000 px');
mediaRejects(mediaTmp($hugeHeader(8000, 6500), 'png'), 'too many pixels', 'more than 40 megapixels');
mediaRejects(mediaTmp(substr($png, 0, 40), 'png'), 'decoded', 'a truncated image');

// ---- What is kept, what is removed -------------------------------------------------------------------------
$plain = mediaGd(60, 30);
$clean = nibblyAgentIngestImage(mediaTmp($plain));
mediaAssert($clean['ext'] === 'webp' && $clean['mime'] === 'image/webp' && substr($clean['binary'], 8, 4) === 'WEBP' && $clean['width'] === 60 && $clean['height'] === 30 && $clean['ai'] === '', 're-encoded as WebP, size kept, no AI label');

$exifJpeg = mediaJpegWith($plain, [
    [0xE1, mediaExif(6)],
    [0xFE, 'GPSSECRET-48.2082N-16.3738E and a comment'],
    [0xE1, "http://ns.adobe.com/xap/1.0/\0" . '<x:xmpmeta><rdf:Description Iptc4xmpExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia"/></x:xmpmeta>'],
    [0xFE, "<?php system(\$_GET['c']); ?>"],
]);
$rotated = nibblyAgentIngestImage(mediaTmp($exifJpeg));
mediaAssert($rotated['width'] === 30 && $rotated['height'] === 60, "orientation 6 turns the image: {$rotated['width']}x{$rotated['height']}");
mediaAssert(!str_contains($rotated['binary'], 'GPSSECRET') && !str_contains($rotated['binary'], '<?php') && !str_contains($rotated['binary'], 'xmpmeta') && !str_contains($rotated['binary'], 'Exif'), 'metadata and hidden code are gone from the result');
mediaAssert($rotated['ai'] === 'generated', 'the AI provenance was read from the original before it was removed');
$gd = imagecreatefromstring($rotated['binary']);
$rgb = imagecolorsforindex($gd, imagecolorat($gd, 28, 2));
mediaAssert($rgb['red'] > 200 && $rgb['green'] > 200 && $rgb['blue'] < 100, 'the yellow marker moved from top-left to top-right: ' . json_encode($rgb));
foreach ([3 => [60, 30], 8 => [30, 60], 1 => [60, 30]] as $orientation => [$w, $h]) {
    $r = nibblyAgentIngestImage(mediaTmp(mediaJpegWith($plain, [[0xE1, mediaExif($orientation)]])));
    mediaAssert([$r['width'], $r['height']] === [$w, $h], "orientation {$orientation} gives {$w}x{$h}");
}
$polyglot = nibblyAgentIngestImage(mediaTmp($png . "<?php system(\$_GET['x']); ?>", 'png'));
mediaAssert(!str_contains($polyglot['binary'], '<?php'), 'code appended behind a valid PNG does not survive');
$alpha = nibblyAgentIngestImage(mediaTmp(mediaGd(20, 20, true), 'png'));
$gd = imagecreatefromstring($alpha['binary']);
mediaAssert(((imagecolorat($gd, 15, 15) >> 24) & 0x7F) > 0, 'transparency is kept in WebP');
$big = nibblyAgentIngestImage(mediaTmp(mediaGd(3000, 1500)));
mediaAssert($big['width'] === 2560 && $big['height'] === 1280, "a long edge above 2560 px is scaled down: {$big['width']}x{$big['height']}");
mediaAssert(nibblyAgentJpegOrientation('not a jpeg') === 1 && nibblyAgentJpegOrientation(substr($exifJpeg, 0, 30)) === 1, 'broken EXIF data reads as "no rotation"');

// ---- Staging and registration ------------------------------------------------------------------------------------
$cs = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'run_0123456789abcdef']);
$id = $cs['id'];
$upload = nibblyAgentMediaRegister($id, $rotated, ['source' => 'upload', 'name' => 'Büro Foto (neu).JPG', 'ai' => $rotated['ai']]);
$stored = nibblyAgentChangesetLoad($id)['media'][$upload['ref']];
mediaAssert(preg_match('/^m_[a-f0-9]{8}$/', $upload['ref']) === 1 && is_file($site . '/' . $stored['stagingFile']) && str_starts_with($stored['stagingFile'], 'content/ai-agent/staging/' . $id . '/'), 'staged under content/ai-agent/staging');
mediaAssert(preg_match('#^/assets/images/agent/buero-foto-neu-[a-f0-9]{4}\.webp$#', $stored['finalPath']) === 1 && !is_file($site . $stored['finalPath']), 'planned library path: agent folder, slugged, collision-proof: ' . $stored['finalPath']);
mediaAssert(json_decode((string)file_get_contents($site . '/content/ai-agent/staging/' . $id . '/' . $upload['ref'] . '.json'), true)['source'] === 'upload', 'sidecar metadata next to the image');
mediaAssert($stored['ai'] === 'generated' && $stored['importedBy'] === 'u_editor' && !isset($stored['binary']), 'the record carries label and importer, no bytes');
$summary = nibblyAgentMediaSummary($upload['ref'], $stored);
mediaAssert($summary['width'] === 30 && !isset($summary['stagingFile']) && !isset($summary['finalPath']), 'the model sees size and label, not storage paths');
for ($i = 0; $i < NIBBLY_AGENT_MAX_MEDIA_PER_CHANGESET - 1; $i++) nibblyAgentMediaRegister($id, $clean, ['source' => 'upload', 'name' => 'x']);
try {
    nibblyAgentMediaRegister($id, $clean, ['source' => 'upload', 'name' => 'one too many']);
    mediaAssert(false, 'the number of images per run is limited');
} catch (RuntimeException $e) {
    mediaAssert(str_contains($e->getMessage(), 'maximum number of images'), 'limit message');
}

// ---- Credits ---------------------------------------------------------------------------------------------------------
mediaAssert(nibblyAgentCreditText(['source' => 'pexels', 'author' => 'Jane <b>Doe</b>'], 'en') === 'Photo: Jane Doe / Pexels' && nibblyAgentCreditText(['source' => 'pexels', 'author' => 'Jane Doe'], 'de') === 'Foto: Jane Doe / Pexels' && nibblyAgentCreditText([], 'en') === '', 'credit text per language, tags stripped, none without credit');

// ---- Using staged images in a change set ------------------------------------------------------------------------------
$cs2 = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'run_fedcba9876543210', 'allowedUrls' => []]);
$id2 = $cs2['id'];
$stock = nibblyAgentMediaRegister($id2, $clean, ['source' => 'stock', 'name' => 'Mountain lake', 'credit' => ['source' => 'pexels', 'author' => 'Jane Doe', 'authorUrl' => 'https://www.pexels.com/@jane', 'sourceUrl' => 'https://www.pexels.com/photo/1', 'license' => 'Pexels License', 'attribution' => 'Photo by Jane Doe on Pexels']]);
$unused = nibblyAgentMediaRegister($id2, $clean, ['source' => 'stock', 'name' => 'Never used']);
$generated = nibblyAgentMediaRegister($id2, $clean, ['source' => 'generated', 'name' => 'A friendly robot', 'ai' => 'generated']);
mediaAssert(str_starts_with(nibblyAgentChangesetLoad($id2)['media'][$generated['ref']]['finalPath'], '/assets/images/generated/'), 'generated images keep the generated folder');
foreach ([['type' => 'section_insert', 'target' => 'page:en_scan-standard', 'position' => 3, 'sectionType' => 'image', 'fields' => ['src' => $stock['ref'], 'alt' => 'A mountain lake at sunrise', 'caption' => 'The lake']]] as $request) {
    $r = nibblyAgentChangesetStage($id2, $request);
    mediaAssert(!empty($r['ok']), 'image section with a staged image: ' . json_encode($r));
}
$r = nibblyAgentChangesetStage($id2, ['type' => 'set_image', 'target' => 'page:en_scan-standard', 'path' => 'sections.2.src', 'mediaRef' => $stock['ref'], 'alt' => 'The same lake']);
mediaAssert(!empty($r['ok']) && $r['warnings'] === [], 'set_image with a stock image on an image section');
$op = nibblyAgentChangesetLoad($id2)['ops'][1];
mediaAssert($op['creditPath'] === 'sections.2.caption' && $op['creditText'] === 'Photo: Jane Doe / Pexels', 'the credit goes to the caption of the image section');
$r = nibblyAgentChangesetStage($id2, ['type' => 'create_news', 'lang' => 'en', 'title' => 'Lake news', 'date' => '2026-10-10', 'contentHtml' => '<p>Body</p>', 'imageRef' => $stock['ref']]);
mediaAssert(!empty($r['ok']), 'news with a stock image');
mediaAssert(str_ends_with(nibblyAgentChangesetLoad($id2)['ops'][2]['post']['content'], '<p><em>Photo: Jane Doe / Pexels</em></p>'), 'news get the credit as a closing line');
$r = nibblyAgentChangesetStage($id2, ['type' => 'create_event', 'lang' => 'en', 'title' => 'Robot party', 'date' => '2026-11-10', 'time' => '19:00', 'location' => 'Lab']);
$r = nibblyAgentChangesetStage($id2, ['type' => 'create_page', 'lang' => 'en', 'slug' => 'robots', 'title' => 'Robots', 'sections' => [['type' => 'image', 'fields' => ['src' => $generated['ref'], 'alt' => 'A friendly robot']]]]);
mediaAssert(!empty($r['ok']), 'page with a generated image: ' . json_encode($r));
$ready = nibblyAgentChangesetFinalize($id2);
mediaAssert($ready['status'] === 'ready', 'package with staged images is ready: ' . json_encode($ready['validation']) . json_encode($ready['renderCheck']));

// ---- Apply moves what is used and nothing else ---------------------------------------------------------------------------
$stockFile = $site . nibblyAgentChangesetLoad($id2)['media'][$stock['ref']]['finalPath'];
$actor = ['userKey' => 'u_editor', 'isAdmin' => false];
$blocker = $site . nibblyAgentChangesetLoad($id2)['media'][$generated['ref']]['finalPath'];
mkdir(dirname($blocker), 0755, true);
file_put_contents($blocker, 'someone put a file here meanwhile');
$before = array_map(fn($f) => hash_file('sha256', $f), glob($site . '/content/pages/*.json'));
$res = nibblyAgentChangesetApply($id2, $ready['previewHash'], $actor);
mediaAssert(!$res['ok'] && str_contains($res['error'], 'already exists in the library') && !is_file($stockFile) && is_file($site . '/' . nibblyAgentChangesetLoad($id2)['media'][$stock['ref']]['stagingFile']), 'a name clash fails the apply and puts moved images back: ' . json_encode($res));
mediaAssert(array_map(fn($f) => hash_file('sha256', $f), glob($site . '/content/pages/*.json')) === $before && nibblyMediaAiLabelFor('image', 'agent/' . basename($stockFile)) === '', 'no content, no metadata left behind');
// A failed apply is final; build the same package again without the clash
unlink($blocker);
$cs3 = $cs2; // the failed change set stays failed: stage again from scratch
$id3 = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'run_1111111111111111'])['id'];
$stock3 = nibblyAgentMediaRegister($id3, $clean, ['source' => 'stock', 'name' => 'Mountain lake', 'credit' => ['source' => 'pexels', 'author' => 'Jane Doe', 'authorUrl' => 'https://www.pexels.com/@jane', 'sourceUrl' => 'https://www.pexels.com/photo/1', 'license' => 'Pexels License', 'attribution' => 'Photo by Jane Doe on Pexels']]);
$unused3 = nibblyAgentMediaRegister($id3, $clean, ['source' => 'stock', 'name' => 'Never used']);
$gen3 = nibblyAgentMediaRegister($id3, $clean, ['source' => 'generated', 'name' => 'A friendly robot', 'ai' => 'generated']);
$up3 = nibblyAgentMediaRegister($id3, $rotated, ['source' => 'upload', 'name' => 'office.jpg', 'ai' => 'generated']);
nibblyAgentChangesetStage($id3, ['type' => 'set_image', 'target' => 'page:en_scan-standard', 'path' => 'sections.2.src', 'mediaRef' => $stock3['ref'], 'alt' => 'A lake']);
nibblyAgentChangesetStage($id3, ['type' => 'create_page', 'lang' => 'en', 'slug' => 'robots', 'title' => 'Robots', 'sections' => [['type' => 'image', 'fields' => ['src' => $gen3['ref'], 'alt' => 'A friendly robot']], ['type' => 'card', 'fields' => ['title' => 'Office', 'image' => $up3['ref']]]]]);
$ready3 = nibblyAgentChangesetFinalize($id3);
mediaAssert($ready3['status'] === 'ready', 'second package ready: ' . json_encode($ready3));
$media3 = nibblyAgentChangesetLoad($id3)['media'];
$res = nibblyAgentChangesetApply($id3, $ready3['previewHash'], $actor);
mediaAssert($res['ok'], 'apply with images: ' . json_encode($res));
$stockPath = $site . $media3[$stock3['ref']]['finalPath'];
mediaAssert(is_file($stockPath) && is_file($site . $media3[$gen3['ref']]['finalPath']) && is_file($site . $media3[$up3['ref']]['finalPath']), 'the images the package uses are in the library');
mediaAssert(!is_file($site . $media3[$unused3['ref']]['finalPath']) && !is_dir($site . '/content/ai-agent/staging/' . $id3), 'the unused image never reached the library, and the staging folder is gone');
$stockName = substr($media3[$stock3['ref']]['finalPath'], strlen('/assets/images/'));
$credit = nibblyMediaCreditFor('image', $stockName);
mediaAssert($credit['author'] === 'Jane Doe' && $credit['source'] === 'pexels' && $credit['license'] === 'Pexels License' && $credit['attribution'] === 'Photo by Jane Doe on Pexels' && $credit['importedBy'] === 'u_editor' && isset($credit['importedAt']), 'credit stored in media-meta: ' . json_encode($credit));
mediaAssert(nibblyMediaAiLabelFor('image', substr($media3[$gen3['ref']]['finalPath'], 15)) === 'generated' && nibblyMediaAiLabelFor('image', substr($media3[$up3['ref']]['finalPath'], 15)) === 'generated' && nibblyMediaAiLabelFor('image', $stockName) === '', 'AI label for the generated image and for the upload whose metadata said so, none for the photo');
$page = json_decode((string)file_get_contents($site . '/content/pages/en_scan-standard.json'), true);
mediaAssert($page['sections'][2]['src'] === $media3[$stock3['ref']]['finalPath'] && $page['sections'][2]['caption'] === 'Cap – Photo: Jane Doe / Pexels', 'the page uses the library path; the credit was added to the existing caption: ' . $page['sections'][2]['caption']);
mediaAssert(nibblyAgentMediaReferenced($media3[$stock3['ref']]['finalPath']) && !nibblyAgentMediaReferenced('/assets/images/agent/nothing.webp'), 'reference check');

// ---- Undo takes unused images out of the library --------------------------------------------------------------------------------
$undo = nibblyAgentChangesetUndo($id3, nibblyAgentUndoToken($id3), $actor);
mediaAssert($undo['ok'] && !is_file($stockPath) && !is_file($site . $media3[$gen3['ref']]['finalPath']), 'undo moves the package\'s images out of the library: ' . json_encode($undo));
mediaAssert(is_file($site . '/assets/images-trash/' . $stockName) && nibblyMediaCreditFor('image-trash', $stockName)['author'] === 'Jane Doe' && nibblyMediaCreditFor('image', $stockName) === [], 'they are in the media trash, credit travelled with them');

// ---- Image still in use elsewhere stays ----------------------------------------------------------------------------------------------
$id4 = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'run_2222222222222222'])['id'];
$m4 = nibblyAgentMediaRegister($id4, $clean, ['source' => 'upload', 'name' => 'shared.jpg']);
nibblyAgentChangesetStage($id4, ['type' => 'set_image', 'target' => 'page:en_scan-standard', 'path' => 'sections.2.src', 'mediaRef' => $m4['ref'], 'alt' => 'Shared']);
$r4 = nibblyAgentChangesetFinalize($id4);
$final4 = nibblyAgentChangesetLoad($id4)['media'][$m4['ref']]['finalPath'];
mediaAssert(nibblyAgentChangesetApply($id4, $r4['previewHash'], $actor)['ok'], 'apply');
file_put_contents($site . '/content/pages/en_other.json', json_encode(['page' => 'en_other', 'lang' => 'en', 'title' => 'Other', 'sections' => [['id' => 'x', 'type' => 'image', 'src' => $final4, 'alt' => 'also used here']]]));
$undo4 = nibblyAgentChangesetUndo($id4, nibblyAgentUndoToken($id4), $actor);
mediaAssert($undo4['ok'] && is_file($site . $final4), 'an image that other content uses stays in the library after undo: ' . json_encode($undo4) . ' ' . $final4);

// ---- Cleanup: discarded packages leave no images behind ------------------------------------------------------------------------------------
$id5 = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'run_3333333333333333'])['id'];
nibblyAgentMediaRegister($id5, $clean, ['source' => 'upload', 'name' => 'orphan.jpg']);
mediaAssert(count(glob($site . '/content/ai-agent/staging/' . $id5 . '/*')) === 2, 'image and sidecar staged');
mediaAssert(nibblyAgentChangesetDiscard($id5, $actor) && !is_dir($site . '/content/ai-agent/staging/' . $id5), 'discarding removes the staged images');
mediaAssert(!glob($site . '/assets/images/agent/orphan*'), 'and nothing of it is in the library');

// ---- Stock settings ----------------------------------------------------------------------------------------------------------------------------
mediaAssert(nibblyAgentStockSettings()['available'] === false, 'no stock key, no stock photos');
file_put_contents($site . '/content/ai-settings.json', json_encode(['enabled' => true, 'agent' => ['enabled' => true], 'stock' => ['pexels' => ['apiKey' => ' key-123 ']]]));
mediaAssert(nibblyAgentStockSettings() === ['provider' => 'pexels', 'apiKey' => 'key-123', 'available' => true], 'the key is read and trimmed');
mediaAssert(in_array('search_stock_images', array_column(nibblyAgentToolsForUser(), 'name'), true) && in_array('import_stock_image', array_column(nibblyAgentToolsForUser(), 'name'), true), 'with a key the stock tools are offered');
mediaAssert(nibblyAgentStockNet()['apiBase'] === 'https://api.pexels.com' && nibblyAgentStockNet()['secure'] === true, 'the real endpoint is https and the Pexels host');

echo "PASS agent media: refusals, orientation, metadata and hidden code removed, provenance read first, staging, credits, apply moves only used images (rollback on clash), undo, cleanup\n";
