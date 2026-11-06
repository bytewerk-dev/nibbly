<?php

declare(strict_types=1);

// Responsive sizes: made next to the library, invisible to it, moving with rename/trash/restore,
// gone on permanent delete; <img> markup with dimensions, lazy loading and srcset.

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/nibbly-media-sizes-' . bin2hex(random_bytes(4));
mkdir($tmp . '/assets/images/team', 0755, true);
define('IMAGES_PATH', $tmp . '/assets/images/');
define('NIBBLY_MEDIA_META_PATH', $tmp . '/media-meta.json');
define('SETTINGS_PATH', $tmp . '/settings.json');
require_once $root . '/includes/media-sizes.php';

function sizesAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function sizesImage(string $file, int $width, int $height): void {
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 60, 160));
    imagewebp($image, $file, 80);
}

try {
    sizesAssert(nibblyMediaCanEncodeWebp(), 'GD with WebP is required for this test');
    sizesAssert(nibblyMediaSizesPath() === $tmp . '/assets/images-sizes/', 'sizes folder sits next to the library: ' . nibblyMediaSizesPath());
    sizesAssert(nibblyMediaSizeName('team/anna.webp', 640) === 'team/anna-640.webp', 'size name keeps folder and stem');
    sizesAssert(nibblyMediaSizeName('logo.png', 1280) === 'logo-1280.webp', 'sizes are always WebP');
    sizesAssert(!nibblyMediaSizesEligible('anim.gif') && !nibblyMediaSizesEligible('icon.svg'), 'GIF and SVG get no sizes');

    // A large image raises memory_limit far enough for the check's own margin (4916 x 6600 px needs
    // about 310 MiB; with a limit sized for the bare need the check failed again and the image was skipped)
    $limitBefore = ini_get('memory_limit');
    if ($limitBefore !== false && $limitBefore !== '-1' && @ini_set('memory_limit', '128M') !== false) {
        sizesAssert(nibblyMediaImageFitsMemory(4916, 6600), 'memory_limit is raised enough for a 4916 x 6600 px image');
        sizesAssert(nibblyIniBytes((string)ini_get('memory_limit')) >= 352 * 1048576, 'raised limit covers need plus margin: ' . ini_get('memory_limit'));
        @ini_set('memory_limit', $limitBefore);
    }

    // A wide image gets both sizes, a narrow one only the smaller, a tiny one none
    sizesImage(IMAGES_PATH . 'team/wide.webp', 2000, 1000);
    sizesImage(IMAGES_PATH . 'medium.webp', 900, 1200);
    sizesImage(IMAGES_PATH . 'tiny.webp', 400, 300);
    sizesAssert(nibblyMediaSizesGenerate('team/wide.webp') === [640, 1280], 'wide image: both sizes written');
    sizesAssert(nibblyMediaSizesGenerate('medium.webp') === [640], 'medium image: only the 640 size');
    sizesAssert(nibblyMediaSizesGenerate('tiny.webp') === [], 'tiny image: no size');
    sizesAssert(nibblyMediaSizesGenerate('team/wide.webp') === [], 'existing sizes are not rewritten');
    sizesAssert(nibblyMediaSizesGenerate('team/wide.webp', true) === [640, 1280], '--force rewrites them');
    $sizes = nibblyMediaSizesFor('team/wide.webp');
    sizesAssert(array_keys($sizes) === [640, 1280], 'both sizes are found');
    sizesAssert($sizes[640]['width'] === 640 && $sizes[640]['height'] === 320, 'the 640 size keeps the aspect ratio: ' . $sizes[640]['width'] . 'x' . $sizes[640]['height']);
    sizesAssert(is_file($tmp . '/assets/images-sizes/team/wide-1280.webp'), 'size file sits in images-sizes/ under the same folder');
    sizesAssert(!file_exists(IMAGES_PATH . 'team/wide-640.webp'), 'nothing is written into the library itself');

    // Markup: dimensions, lazy loading, srcset with width descriptors; base path and admin-relative sources work
    $tag = nibblyImageTag('/assets/images/team/wide.webp', 'Anna "K"', ['class' => 'hero']);
    sizesAssert(str_contains($tag, ' width="2000" height="1000"'), 'dimensions from the file: ' . $tag);
    sizesAssert(str_contains($tag, 'srcset="/assets/images-sizes/team/wide-640.webp 640w, /assets/images-sizes/team/wide-1280.webp 1280w, /assets/images/team/wide.webp 2000w"'), 'srcset lists sizes and the image: ' . $tag);
    sizesAssert(str_contains($tag, ' sizes="100vw"') && str_contains($tag, ' loading="lazy" decoding="async"') && str_contains($tag, 'alt="Anna &quot;K&quot;"') && str_contains($tag, 'class="hero"'), 'sizes, lazy, escaped alt, class: ' . $tag);
    $hero = nibblyImageTag('../assets/images/team/wide.webp', '', ['lazy' => false, 'sizes' => '50vw']);
    sizesAssert(str_contains($hero, ' fetchpriority="high"') && !str_contains($hero, 'loading=') && str_contains($hero, 'srcset="../assets/images-sizes/team/wide-640.webp 640w') && str_contains($hero, ' sizes="50vw"'), 'eager image keeps the prefix of its source: ' . $hero);
    $tiny = nibblyImageTag('/assets/images/tiny.webp?v=2', 'x');
    sizesAssert(str_contains($tiny, ' width="400" height="300"') && !str_contains($tiny, 'srcset'), 'image without sizes: dimensions, no srcset: ' . $tiny);
    $remote = nibblyImageTag('https://example.org/a.jpg', 'x');
    sizesAssert($remote === '<img src="https://example.org/a.jpg" alt="x" loading="lazy" decoding="async">', 'remote image: plain tag: ' . $remote);
    $missing = nibblyImageTag('/assets/images/missing.webp', 'x', ['srcset' => false]);
    sizesAssert(!str_contains($missing, 'width='), 'missing file: no dimensions');
    sizesAssert(nibblyImageSources('/assets/images/../admin/x.php') === null, 'traversal is rejected');

    $html = '<p><img src="/assets/images/team/wide.webp" alt=""> <img src="/assets/images/tiny.webp" alt="" loading="eager" width="1" height="1"> <img src="https://x.y/z.png" alt=""></p>';
    $enriched = nibblyImageHtmlEnrich($html, '40rem');
    sizesAssert(str_contains($enriched, '<img width="2000" height="1000" srcset="/assets/images-sizes/team/wide-640.webp 640w') && str_contains($enriched, ' sizes="40rem" loading="lazy" decoding="async" src="/assets/images/team/wide.webp"'), 'filter completes a bare tag: ' . $enriched);
    sizesAssert(str_contains($enriched, '<img src="/assets/images/tiny.webp" alt="" loading="eager" width="1" height="1">'), 'filter leaves set attributes alone: ' . $enriched);
    sizesAssert(str_contains($enriched, '<img loading="lazy" decoding="async" src="https://x.y/z.png" alt="">'), 'remote image only gets lazy loading: ' . $enriched);

    // Rename, trash, restore, delete for good
    nibblyMediaSizesRelocate('team/wide.webp', false, 'team/anna.webp', false);
    sizesAssert(is_file($tmp . '/assets/images-sizes/team/anna-640.webp') && !file_exists($tmp . '/assets/images-sizes/team/wide-640.webp'), 'sizes follow a rename');
    nibblyMediaSizesRelocate('team/anna.webp', false, 'anna.webp', true);
    sizesAssert(is_file($tmp . '/assets/images-sizes-trash/anna-1280.webp') && !file_exists($tmp . '/assets/images-sizes/team/anna-1280.webp'), 'sizes follow the image into the trash');
    nibblyMediaSizesRelocate('anna.webp', true, 'back/anna.webp', false);
    sizesAssert(is_file($tmp . '/assets/images-sizes/back/anna-640.webp'), 'sizes come back on restore, into the new folder');
    nibblyMediaSizesForget('back/anna.webp', false);
    sizesAssert(!file_exists($tmp . '/assets/images-sizes/back/anna-640.webp') && !file_exists($tmp . '/assets/images-sizes/back/anna-1280.webp'), 'sizes are deleted with the image');
    sizesAssert(nibblyMediaSizesFor('back/anna.webp') === [], 'nothing left to find');

    // Orphans: a size whose image went away by hand
    unlink(IMAGES_PATH . 'medium.webp');
    sizesAssert(nibblyMediaSizesOrphans() === ['medium-640.webp'], 'orphan detection: ' . json_encode(nibblyMediaSizesOrphans()));

    // The CLI: status and a dry run read, prune deletes the orphan
    $cli = static function (string $args) use ($root, $tmp): array {
        $cmd = 'cd ' . escapeshellarg($root) . ' && php cli/media-sizes.php --images=' . escapeshellarg(IMAGES_PATH) . ' ' . $args . ' 2>&1';
        exec($cmd, $lines, $code);
        return [$code, implode("\n", $lines)];
    };
    [$code, $out] = $cli('--action=status');
    sizesAssert($code === 0 && str_contains($out, 'Orphaned sizes:        1'), 'status counts the orphan: ' . $out);
    sizesImage(IMAGES_PATH . 'later.webp', 1500, 500);
    [$code, $out] = $cli('--action=generate --dry-run');
    sizesAssert($code === 0 && str_contains($out, 'would make 640/1280  later.webp') && !file_exists($tmp . '/assets/images-sizes/later-640.webp'), 'dry run lists and writes nothing: ' . $out);
    [$code, $out] = $cli('--action=generate --limit=1');
    sizesAssert($code === 0 && is_file($tmp . '/assets/images-sizes/later-1280.webp'), 'generate makes the missing sizes: ' . $out);
    [$code, $out] = $cli('--action=prune');
    sizesAssert($code === 0 && !file_exists($tmp . '/assets/images-sizes/medium-640.webp'), 'prune removes the orphan: ' . $out);

    echo "media-sizes smoke: OK\n";
} finally {
    exec('rm -rf ' . escapeshellarg($tmp));
}
