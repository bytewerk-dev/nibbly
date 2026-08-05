<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/page-path.php';

if (!defined('SITE_LANG_DEFAULT')) define('SITE_LANG_DEFAULT', 'de');

function nestedPageAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$path = 'produkte/rokivit-d3';
$key = nibblyPageContentKey('de', $path);
nestedPageAssert($key === 'de_produkte__rokivit-d3', 'nested path must use the reserved content-key separator');
nestedPageAssert(nibblyPageParseContentKey($key) === ['lang' => 'de', 'path' => $path], 'content key must decode losslessly');
nestedPageAssert(nibblyPageIsValidPath('studien-vitamin-d/vitamin-d'), 'multiple valid path segments must be accepted');
nestedPageAssert(!nibblyPageIsValidPath('../admin'), 'path traversal must be rejected');
nestedPageAssert(!nibblyPageIsValidPath('products//vitamin-d'), 'empty path segments must be rejected');
nestedPageAssert(!nibblyPageIsValidPath('Products/Vitamin-D'), 'uppercase paths must be rejected instead of stored inconsistently');
nestedPageAssert(nibblyPageBasePath($path, false) === '../', 'default-language nested page must resolve back to root');
nestedPageAssert(nibblyPageBasePath($path, true) === '../../', 'language-prefixed nested page must resolve back to root');
nestedPageAssert(nibblyPageUrlPath('de', $path) === '/produkte/rokivit-d3', 'default-language URL must stay unprefixed');
nestedPageAssert(nibblyPageUrlPath('en', $path) === '/en/produkte/rokivit-d3', 'secondary-language URL must be prefixed');
nestedPageAssert(str_ends_with(nibblyPageJsonPath($root, 'de', $path), '/content/pages/' . $key . '.json'), 'JSON path must use encoded key');
nestedPageAssert(str_ends_with(nibblyPageTemplatePath($root, 'de', $path), '/de/produkte/rokivit-d3.php'), 'custom templates must use nested directories');

$testFile = $root . '/content/pages/' . $key . '.json';
$testData = [
    'page' => $key,
    'path' => $path,
    'lang' => 'de',
    'title' => 'Rokivit D3',
    'description' => 'Nested page smoke test.',
    'nav' => ['header'],
    'lastModified' => '2026-08-04T00:00:00+02:00',
    'seo' => ['sitemap' => true],
];

file_put_contents($testFile, json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
try {
    require_once $root . '/includes/menu-helpers.php';
    require_once $root . '/includes/seo-helper.php';

    $items = getMenuItems('header', 'de', '', []);
    $item = current(array_filter($items, static fn(array $candidate): bool => ($candidate['page'] ?? '') === $path));
    nestedPageAssert(is_array($item), 'nested page must be auto-discovered for navigation');
    nestedPageAssert(($item['href'] ?? '') === 'produkte/rokivit-d3', 'navigation href must expose the public path');

    $pages = nibblySeoRenderablePages();
    $seoPage = current(array_filter($pages, static fn(array $candidate): bool => ($candidate['lang'] ?? '') === 'de' && ($candidate['slug'] ?? '') === $path));
    nestedPageAssert(is_array($seoPage), 'nested page must be included in the sitemap source');
    nestedPageAssert(str_ends_with((string)($seoPage['url'] ?? ''), '/produkte/rokivit-d3/'), 'sitemap URL must use the clean canonical route with trailing slash');
} finally {
    @unlink($testFile);
}

$dryRun = shell_exec('cd ' . escapeshellarg($root) . ' && php cli/make.php --slug=produkte/rokivit-d3 --lang=de --dry-run 2>&1');
nestedPageAssert(is_string($dryRun) && str_contains($dryRun, 'de_produkte__rokivit-d3.json'), 'page scaffolder must generate the encoded JSON filename');
nestedPageAssert(str_contains($dryRun, '"path": "produkte/rokivit-d3"'), 'page scaffolder must preserve the public path in JSON');

echo "Nested page path smoke test passed.\n";
