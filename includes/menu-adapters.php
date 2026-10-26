<?php
/**
 * Menu and footer adapters
 *
 * Templates with their own navigation or footer markup register here what the
 * dashboard's "Menus & Footer" may edit. A registration never changes a
 * template: the template keeps its markup and reads the data through
 * nibblyMenuItems() (menus) or its own footer.json fields.
 *
 * Project registrations belong in includes/site-menus.php (site-owned, never
 * overwritten by core updates). See AI-AGENT-GUIDE.md → "Menus & Footer".
 *
 *   nibblyRegisterMenu('service-nav', [
 *       'label'    => ['en' => 'Service navigation', 'de' => 'Service-Navigation'],
 *       'template' => 'includes/header.php',      // where it is rendered (shown as origin)
 *       'depth'    => 1,                          // 1 = flat, 2 = one dropdown level
 *       'fallback' => fn(string $lang) => [       // what the template showed before
 *           ['label' => 'Shop', 'href' => 'https://shop.example.com', 'target' => '_blank'],
 *           ['label' => 'Contact', 'page' => 'contact', 'type' => 'page'],
 *       ],
 *   ]);
 *
 *   nibblyRegisterFooterField('social', [
 *       'type'     => 'linkgroup',                 // text | link | linkgroup | image | contact
 *       'label'    => ['en' => 'Social links', 'de' => 'Social-Media-Links'],
 *       'template' => 'includes/footer.php',
 *       'supports' => ['add' => true, 'remove' => true, 'order' => true, 'hidden' => true],
 *   ]);
 */

function &nibblyMenuAdapterStore(): array {
    static $store = ['menus' => [], 'footer' => [], 'loaded' => false];
    return $store;
}

function nibblyRegisterMenu(string $id, array $definition): void {
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) return;
    $store = &nibblyMenuAdapterStore();
    $store['menus'][$id] = [
        'id' => $id,
        'label' => is_array($definition['label'] ?? null) ? $definition['label'] : ['en' => (string)($definition['label'] ?? $id)],
        'template' => is_string($definition['template'] ?? null) ? $definition['template'] : '',
        'depth' => max(1, min(3, (int)($definition['depth'] ?? 1))),
        'weight' => (int)($definition['weight'] ?? 50),
        'fallback' => $definition['fallback'] ?? null,
    ];
}

function nibblyRegisterFooterField(string $path, array $definition): void {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}(\.[A-Za-z0-9_-]{1,64}){0,3}$/', $path)) return;
    $type = $definition['type'] ?? 'text';
    if (!in_array($type, ['text', 'link', 'linkgroup', 'image', 'contact'], true)) return;
    $store = &nibblyMenuAdapterStore();
    $store['footer'][$path] = [
        'path' => $path,
        'type' => $type,
        'label' => $definition['label'] ?? $path,
        'html' => !empty($definition['html']),
        'multiline' => !empty($definition['multiline']),
        'template' => is_string($definition['template'] ?? null) ? $definition['template'] : '',
        'supports' => array_replace(
            ['add' => $type === 'linkgroup', 'remove' => $type === 'linkgroup', 'order' => $type === 'linkgroup', 'hidden' => false],
            is_array($definition['supports'] ?? null) ? $definition['supports'] : []
        ),
        'default' => $definition['default'] ?? null,
    ];
}

/** Core registrations, then includes/site-menus.php. Unbound adapters are left out. */
function nibblyMenuAdaptersLoad(): void {
    $store = &nibblyMenuAdapterStore();
    if ($store['loaded']) return;
    $store['loaded'] = true;
    $root = dirname(__DIR__);

    // Core footer (includes/footer.php): its link row and two texts.
    $footer = (string)@file_get_contents($root . '/includes/footer.php');
    if (str_contains($footer, "nibblyMenuItems('footer-links'")) {
        $labels = [
            'docs' => ['en' => 'Docs', 'de' => 'Dokumentation', 'es' => 'Docs'],
            'privacy' => ['en' => 'Privacy', 'de' => 'Datenschutz', 'es' => 'Privacidad'],
            'imprint' => ['en' => 'Imprint', 'de' => 'Impressum', 'es' => 'Aviso legal'],
        ];
        nibblyRegisterMenu('footer-links', [
            'label' => ['en' => 'Footer links', 'de' => 'Footer-Links', 'es' => 'Enlaces del pie'],
            'template' => 'includes/footer.php',
            'depth' => 1,
            'weight' => 30,
            'fallback' => static fn(string $lang): array => [
                ['label' => $labels['docs'][$lang] ?? $labels['docs']['en'], 'page' => 'docs', 'href' => nibblyMenuMappedHref('docs', $lang)],
                ['label' => 'GitHub', 'href' => 'https://github.com/bytewerk-dev/nibbly', 'target' => '_blank', 'rel' => 'noopener'],
                ['label' => $labels['privacy'][$lang] ?? $labels['privacy']['en'], 'page' => 'privacy', 'href' => nibblyMenuMappedHref('privacy', $lang)],
                ['label' => $labels['imprint'][$lang] ?? $labels['imprint']['en'], 'page' => 'legal-notice', 'href' => nibblyMenuMappedHref('legal-notice', $lang)],
            ],
        ]);
    }
    if (str_contains($footer, "\$footerData['summary']")) {
        nibblyRegisterFooterField('summary', [
            'type' => 'text',
            'label' => ['en' => 'Summary', 'de' => 'Kurzbeschreibung'],
            'html' => true,
            'multiline' => true,
            'template' => 'includes/footer.php',
            'default' => static fn(string $lang): string => nibblyCoreFooterSummary($lang),
        ]);
    }
    if (str_contains($footer, "\$footerData['copyright']")) {
        nibblyRegisterFooterField('copyright', [
            'type' => 'text',
            'label' => ['en' => 'Copyright line', 'de' => 'Copyright-Zeile'],
            'html' => true,
            'template' => 'includes/footer.php',
        ]);
    }

    $site = $root . '/includes/site-menus.php';
    if (is_file($site)) include $site;
}

/** The summary the core footer shows while footer.json has none. */
function nibblyCoreFooterSummary(string $lang): string {
    $summary = [
        'en' => 'nibbly is a flat-file CMS written in PHP.<br>Small. Friendly. Open source.',
        'de' => 'nibbly ist ein Flat-File-CMS in PHP.<br>Klein. Freundlich. Open Source.',
        'es' => 'nibbly es un CMS flat-file escrito en PHP.<br>Pequeño. Amable. Open source.',
    ];
    return $summary[$lang] ?? $summary['en'];
}

function nibblyMenuAdapters(): array {
    nibblyMenuAdaptersLoad();
    return nibblyMenuAdapterStore()['menus'];
}

function nibblyFooterFieldAdapters(): array {
    nibblyMenuAdaptersLoad();
    return nibblyMenuAdapterStore()['footer'];
}
