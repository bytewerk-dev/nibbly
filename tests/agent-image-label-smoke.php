<?php

declare(strict_types=1);

// Images stored by the AI gateway carry the "generated" label automatically.

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/nibbly-image-label-' . bin2hex(random_bytes(4));
mkdir($tmp . '/generated', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($tmp)));
define('NIBBLY_MEDIA_META_PATH', $tmp . '/media-meta.json');
define('NIBBLY_AI_GENERATED_IMAGE_DIR', $tmp . '/generated');
define('SETTINGS_PATH', $tmp . '/settings.json');
ini_set('error_log', $tmp . '/php-errors.log');
require_once $root . '/includes/ai/ai-helper.php';

function labelAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function labelPng(int $color): string {
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, $color);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
}

$path = nibblyAiStoreGeneratedImage(labelPng(0xCC3300), 'Red square');
labelAssert($path === '/assets/images/generated/red-square.png' && is_file($tmp . '/generated/red-square.png'), 'image stored: ' . $path);
labelAssert(nibblyMediaAiLabel($path) === 'generated', 'stored AI image carries the generated label');
$meta = json_decode((string)file_get_contents(NIBBLY_MEDIA_META_PATH), true);
labelAssert(($meta['image']['generated/red-square.png']['ai'] ?? '') === 'generated', 'label is stored under the media-library name');
labelAssert(str_contains(nibblyAiLabelHtml($path), 'ai-label'), 'the label renders wherever the image is used: ' . nibblyAiLabelHtml($path));

// Variants, collisions and format conversion each get their own entry
$second = nibblyAiStoreGeneratedImage(labelPng(0x0033CC), 'Red square');
$webp = nibblyAiStoreGeneratedImage(labelPng(0x00CC33), 'Green', 'auto', 2, 'webp');
labelAssert($second === '/assets/images/generated/red-square-2.png' && $webp === '/assets/images/generated/green-v2.webp', 'file names: ' . $second . ' ' . $webp);
foreach ([$second, $webp] as $stored) {
    labelAssert(nibblyMediaAiLabel($stored) === 'generated', "{$stored} is labelled");
}

// A broken metadata store must not lose the image
unlink(NIBBLY_MEDIA_META_PATH);
file_put_contents(NIBBLY_MEDIA_META_PATH, '{damaged');
$kept = nibblyAiStoreGeneratedImage(labelPng(0x333333), 'Kept despite label failure');
labelAssert(is_file($tmp . '/generated/kept-despite-label-failure.png') && $kept === '/assets/images/generated/kept-despite-label-failure.png', 'image survives a failed label write');
labelAssert(file_get_contents(NIBBLY_MEDIA_META_PATH) === '{damaged', 'a damaged metadata file is not replaced');
labelAssert(str_contains((string)@file_get_contents($tmp . '/php-errors.log'), 'could not store the AI label'), 'the failed label write is logged');

echo "PASS agent image label: generated images are labelled automatically, failures are logged without losing the image\n";
