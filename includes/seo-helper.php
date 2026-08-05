<?php
/**
 * SEO/AEO/GEO helpers for public metadata, sitemaps, robots, and health checks.
 */

require_once __DIR__ . '/page-path.php';

function nibblySeoRoot(): string {
    return dirname(__DIR__);
}

function nibblySeoSettings(): array {
    $path = nibblySeoRoot() . '/content/settings.json';
    if (!is_file($path)) return [];
    $settings = json_decode((string)file_get_contents($path), true);
    return is_array($settings) ? $settings : [];
}

function nibblySeoBaseUrl(): string {
    $settings = nibblySeoSettings();
    $configured = trim((string)($settings['seo']['siteUrl'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    if ($host === '') {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . $host;
}

function nibblySeoPageUrl(string $lang, string $slug): string {
    $base = nibblySeoBaseUrl();
    $path = nibblyPageUrlPath($lang, $slug);
    if ($path !== '/') {
        $path = rtrim($path, '/') . '/';
    }
    return $base . $path;
}

function nibblySeoCurrentUrl(): string {
    $base = nibblySeoBaseUrl();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return $base . $path;
}

function nibblySeoLoadPageData(?string $contentPage): array {
    if (!$contentPage || !nibblyPageIsValidContentKey($contentPage)) {
        return [];
    }
    $path = nibblySeoRoot() . '/content/pages/' . $contentPage . '.json';
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function nibblySeoTextFromHtml(string $html): string {
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
}

function nibblySeoFirstImage(array $data): string {
    $candidates = [];
    $walk = function ($value) use (&$walk, &$candidates) {
        if (!is_array($value)) return;
        if (!empty($value['src']) && is_string($value['src'])) $candidates[] = $value['src'];
        if (!empty($value['image']) && is_string($value['image'])) $candidates[] = $value['image'];
        foreach ($value as $child) {
            if (is_array($child)) $walk($child);
        }
    };
    $walk($data);
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && !str_starts_with($candidate, 'data:') && nibblySeoIsSupportedOgImage($candidate)) return $candidate;
    }
    return '';
}

function nibblySeoIsSupportedOgImage(string $path): bool {
    $path = trim($path);
    if ($path === '') return false;
    $urlPath = parse_url($path, PHP_URL_PATH);
    if (!is_string($urlPath) || $urlPath === '') return false;
    $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png'], true);
}

function nibblySeoNormalizeAssetPath(string $path): string {
    $path = trim($path);
    if (str_starts_with($path, '../assets/images/')) {
        return '/assets/images/' . substr($path, strlen('../assets/images/'));
    }
    if (str_starts_with($path, 'assets/images/')) {
        return '/' . $path;
    }
    return $path;
}

function nibblySeoAbsoluteAssetUrl(string $path): string {
    $path = nibblySeoNormalizeAssetPath($path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim(nibblySeoBaseUrl(), '/') . '/' . ltrim($path, '/');
}

function nibblySeoLocalAssetPath(string $path): string {
    $path = nibblySeoNormalizeAssetPath($path);
    if (!str_starts_with($path, '/assets/images/')) return '';
    $relative = substr($path, strlen('/assets/images/'));
    if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) return '';
    return nibblySeoRoot() . '/assets/images/' . $relative;
}

function nibblySeoOgImageMeta(string $path, string $alt = ''): array {
    if (!nibblySeoIsSupportedOgImage($path)) {
        return ['url' => '', 'width' => null, 'height' => null, 'alt' => ''];
    }

    $meta = [
        'url' => nibblySeoAbsoluteAssetUrl($path),
        'width' => null,
        'height' => null,
        'alt' => nibblySeoTextFromHtml($alt),
    ];

    $localPath = nibblySeoLocalAssetPath($path);
    if ($localPath !== '' && is_file($localPath)) {
        $size = @getimagesize($localPath);
        if (is_array($size)) {
            $meta['width'] = (int)($size[0] ?? 0) ?: null;
            $meta['height'] = (int)($size[1] ?? 0) ?: null;
        }
    }

    return $meta;
}

function nibblySeoRobots(array $seo, array $visibility = []): string {
    if (($visibility['status'] ?? 'public') === 'private') {
        return 'noindex, nofollow';
    }
    $robots = trim((string)($seo['robots'] ?? ''));
    return $robots !== '' ? $robots : 'index, follow';
}

function nibblySeoContext(array $args): array {
    $contentPage = $args['contentPage'] ?? null;
    $data = $args['data'] ?? nibblySeoLoadPageData($contentPage);
    $seo = is_array($data['seo'] ?? null) ? $data['seo'] : [];
    $visibility = is_array($data['visibility'] ?? null) ? $data['visibility'] : [];
    $lang = $args['currentLang'] ?? ($data['lang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'));
    $slug = $args['currentPage'] ?? '';
    if ($slug === '' && $contentPage) {
        $page = nibblyPageParseContentKey((string)$contentPage);
        if ($page !== null) $slug = $page['path'];
    }
    $title = trim((string)($seo['title'] ?? '')) ?: trim((string)($args['pageTitle'] ?? ($data['title'] ?? '')));
    $description = trim((string)($seo['description'] ?? '')) ?: trim((string)($args['pageDescription'] ?? ($data['description'] ?? '')));
    $canonical = trim((string)($seo['canonical'] ?? ''));
    if ($canonical === '' && $slug !== '') {
        $canonical = nibblySeoPageUrl((string)$lang, (string)$slug);
    }
    $ogTitle = trim((string)($seo['ogTitle'] ?? '')) ?: $title;
    $ogDescription = trim((string)($seo['ogDescription'] ?? '')) ?: $description;
    $settings = nibblySeoSettings();
    $defaultOgImage = trim((string)($settings['seo']['defaultOgImage'] ?? ''));
    $ogImage = trim((string)($seo['ogImage'] ?? '')) ?: ($defaultOgImage ?: nibblySeoFirstImage($data));
    $ogImageMeta = nibblySeoOgImageMeta($ogImage, $ogTitle);

    return [
        'title' => $title,
        'description' => $description,
        'robots' => nibblySeoRobots($seo, $visibility),
        'canonical' => $canonical,
        'ogTitle' => $ogTitle,
        'ogDescription' => $ogDescription,
        'ogImage' => $ogImageMeta['url'],
        'ogImageWidth' => $ogImageMeta['width'],
        'ogImageHeight' => $ogImageMeta['height'],
        'ogImageAlt' => $ogImageMeta['alt'],
        'lang' => $lang,
        'slug' => $slug,
        'data' => $data,
        'seo' => $seo,
    ];
}

function nibblySeoPageHasH1(array $data): bool {
    foreach (($data['sections'] ?? []) as $section) {
        if (!is_array($section) || !empty($section['hidden'])) continue;
        if (($section['type'] ?? '') === 'heading' && ($section['level'] ?? '') === 'h1' && trim((string)($section['text'] ?? '')) !== '') return true;
        if (($section['type'] ?? '') === 'text' && ($section['titleTag'] ?? '') === 'h1' && trim((string)($section['title'] ?? '')) !== '') return true;
    }
    return false;
}

function nibblySeoMissingImageAlts(array $data): int {
    $missing = 0;
    $walk = function ($value) use (&$walk, &$missing) {
        if (!is_array($value)) return;
        if (!empty($value['src']) && is_string($value['src']) && trim((string)($value['alt'] ?? '')) === '') $missing++;
        if (!empty($value['image']) && is_string($value['image']) && array_key_exists('alt', $value) && trim((string)$value['alt']) === '') $missing++;
        foreach ($value as $child) {
            if (is_array($child)) $walk($child);
        }
    };
    $walk($data);
    return $missing;
}

function nibblySeoHealth(array $context): array {
    $score = 100;
    $issues = [];
    $warn = function (string $message, int $points) use (&$score, &$issues) {
        $score -= $points;
        $issues[] = $message;
    };

    $titleLen = strlen($context['title'] ?? '');
    if ($titleLen < 20) $warn('SEO-Titel ist zu kurz.', 18);
    if ($titleLen > 70) $warn('SEO-Titel ist zu lang.', 10);

    $descLen = strlen($context['description'] ?? '');
    if ($descLen < 70) $warn('Meta Description ist zu kurz oder fehlt.', 18);
    if ($descLen > 170) $warn('Meta Description ist zu lang.', 8);

    if (empty($context['canonical'])) $warn('Canonical URL fehlt.', 12);
    if (stripos($context['robots'] ?? '', 'noindex') !== false) $warn('Seite ist auf noindex gesetzt.', 20);
    if (!empty($context['data']['sections']) && !nibblySeoPageHasH1($context['data'] ?? [])) {
        $warn('Keine erkennbare H1 in den Seitendaten.', 14);
    }
    if (empty($context['ogImage'])) $warn('Open-Graph-Bild fehlt.', 8);
    $missingAlts = nibblySeoMissingImageAlts($context['data'] ?? []);
    if ($missingAlts > 0) $warn($missingAlts . ' Bild(er) ohne Alt-Text.', min(14, $missingAlts * 4));

    $score = max(0, min(100, $score));
    $status = $score >= 85 ? 'green' : ($score >= 60 ? 'yellow' : 'red');
    return [
        'status' => $status,
        'score' => $score,
        'label' => $status === 'green' ? 'SEO gut' : ($status === 'yellow' ? 'SEO prüfen' : 'SEO kritisch'),
        'issues' => $issues ?: ['Keine wesentlichen technischen SEO-Probleme erkannt.'],
    ];
}

function nibblySeoJsonLd(array $context, array $langLinks = []): array {
    $settings = nibblySeoSettings();
    $siteName = $settings['branding']['name'] ?? (defined('SITE_NAME') ? SITE_NAME : 'Website');
    $base = nibblySeoBaseUrl();
    $canonical = $context['canonical'] ?: nibblySeoCurrentUrl();
    $graph = [
        [
            '@type' => 'WebSite',
            '@id' => $base . '/#website',
            'url' => $base . '/',
            'name' => $siteName,
        ],
        [
            '@type' => 'WebPage',
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => $context['title'],
            'description' => $context['description'],
            'inLanguage' => $context['lang'],
            'isPartOf' => ['@id' => $base . '/#website'],
        ],
    ];
    if (!empty($context['seo']['answerSummary'])) {
        $graph[1]['abstract'] = nibblySeoTextFromHtml((string)$context['seo']['answerSummary']);
    }

    if (!empty($settings['seo']['organizationName'])) {
        $graph[] = [
            '@type' => 'Organization',
            '@id' => $base . '/#organization',
            'name' => $settings['seo']['organizationName'],
            'url' => $base . '/',
            'logo' => nibblySeoAbsoluteAssetUrl((string)($settings['branding']['logo'] ?? $settings['favicon'] ?? '')),
        ];
    }

    if (!empty($context['data']['breadcrumb']) && is_array($context['data']['breadcrumb'])) {
        $items = [];
        $pos = 1;
        foreach ($context['data']['breadcrumb'] as $crumb) {
            if (!is_array($crumb) || empty($crumb['label'])) continue;
            $items[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => (string)$crumb['label'],
                'item' => !empty($crumb['href']) ? nibblySeoAbsoluteAssetUrl((string)$crumb['href']) : $canonical,
            ];
        }
        if ($items) {
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }
    }

    if (!empty($context['data']['faq']['entries']) && is_array($context['data']['faq']['entries'])) {
        $faqItems = [];
        foreach ($context['data']['faq']['entries'] as $entry) {
            if (!is_array($entry) || !empty($entry['hidden']) || empty($entry['question']) || empty($entry['answer'])) continue;
            $faqItems[] = [
                '@type' => 'Question',
                'name' => nibblySeoTextFromHtml((string)$entry['question']),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => nibblySeoTextFromHtml((string)$entry['answer'])],
            ];
        }
        if ($faqItems) $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $faqItems];
    }

    return ['@context' => 'https://schema.org', '@graph' => $graph];
}

function nibblySeoRenderablePages(): array {
    $pages = [];
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    foreach (glob(nibblySeoRoot() . '/content/pages/[a-z][a-z]_*.json') ?: [] as $file) {
        $name = basename($file, '.json');
        $page = nibblyPageParseContentKey($name);
        if ($page === null) continue;
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) continue;
        $visibility = is_array($data['visibility'] ?? null) ? $data['visibility'] : [];
        $seo = is_array($data['seo'] ?? null) ? $data['seo'] : [];
        if (($visibility['status'] ?? 'public') === 'private') continue;
        if (($seo['sitemap'] ?? true) === false) continue;
        if (stripos(nibblySeoRobots($seo, $visibility), 'noindex') !== false) continue;
        $lang = $page['lang'];
        $slug = $page['path'];
        $pages[] = [
            'lang' => $lang,
            'slug' => $slug,
            'url' => nibblySeoPageUrl($lang, $slug),
            'lastmod' => $data['lastModified'] ?? date('c', filemtime($file)),
            'priority' => $seo['priority'] ?? ($slug === 'home' && $lang === $defaultLang ? '1.0' : '0.6'),
            'changefreq' => $seo['changefreq'] ?? 'monthly',
        ];
    }
    foreach (glob(nibblySeoRoot() . '/content/news/*.json') ?: [] as $file) {
        $post = json_decode((string)file_get_contents($file), true);
        if (!is_array($post) || !empty($post['hidden'])) continue;
        $slug = trim((string)($post['slug'] ?? basename($file, '.json')));
        if ($slug === '') continue;
        $lang = trim((string)($post['lang'] ?? $defaultLang));
        $path = $lang === $defaultLang ? '/news/' . $slug : '/' . $lang . '/news/' . $slug;
        $pages[] = [
            'lang' => $lang,
            'slug' => 'news/' . $slug,
            'url' => rtrim(nibblySeoBaseUrl(), '/') . $path,
            'lastmod' => $post['lastModified'] ?? ($post['date'] ?? date('c', filemtime($file))),
            'priority' => '0.5',
            'changefreq' => 'monthly',
        ];
    }
    return $pages;
}

function nibblySeoServeSitemap(): void {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (nibblySeoRenderablePages() as $page) {
        echo "  <url>\n";
        echo '    <loc>' . htmlspecialchars($page['url'], ENT_XML1, 'UTF-8') . "</loc>\n";
        echo '    <lastmod>' . htmlspecialchars(date('c', strtotime((string)$page['lastmod']) ?: time()), ENT_XML1, 'UTF-8') . "</lastmod>\n";
        echo '    <changefreq>' . htmlspecialchars((string)$page['changefreq'], ENT_XML1, 'UTF-8') . "</changefreq>\n";
        echo '    <priority>' . htmlspecialchars((string)$page['priority'], ENT_XML1, 'UTF-8') . "</priority>\n";
        echo "  </url>\n";
    }
    echo '</urlset>';
    exit;
}

function nibblySeoServeRobots(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $settings = nibblySeoSettings();
    $disallowAll = !empty($settings['seo']['noindexSite']);
    echo "User-agent: *\n";
    if ($disallowAll) {
        echo "Disallow: /\n";
    } else {
        echo "Disallow: /admin/\nDisallow: /api/\nDisallow: /content/\nDisallow: /backups/\n";
    }
    $base = nibblySeoBaseUrl();
    if ($base !== '') echo "\nSitemap: " . $base . "/sitemap.xml\n";
    exit;
}
