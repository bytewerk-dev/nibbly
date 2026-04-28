<?php
/**
 * Asset Loader Helpers
 *
 * Guarantees Nibbly-Core stylesheets are loaded — including from a
 * site-owned custom header.php. Sites that call nibblyCoreStyles() once
 * never have to maintain the Core-CSS link list across Nibbly upgrades.
 *
 * Usage from a custom includes/header.php:
 *   <?php echo nibblyCoreStyles($basePath); ?>
 *   <link rel="stylesheet" href="<?php echo $basePath; ?>css/website.css">
 */

/**
 * Returns <link> tags for Nibbly-Core stylesheets that MUST always load:
 *   - css/style.css       (variables, reset, .main-content, .content-inner, .block-*)
 *   - css/components.css  (block renderer components: feature-grid, faq-accordion, etc.)
 *
 * Site-specific stylesheets (website.css, fonts.css, page-*.css) are NOT
 * emitted here — they remain the site owner's responsibility so the load
 * order relative to Core stays explicit.
 *
 * @param string $basePath  e.g. '' (root) or '../' (subdirectory)
 * @return string  HTML <link> tags, newline-separated, with trailing newline
 */
function nibblyCoreStyles(string $basePath = ''): string {
    $base = htmlspecialchars($basePath);
    return '<link rel="stylesheet" href="' . $base . 'css/style.css">' . "\n    "
         . '<link rel="stylesheet" href="' . $base . 'css/components.css">' . "\n";
}

/**
 * Renders an icon. If $path points to the Nibbly default favicon (or another
 * SVG file in the project), the SVG is inlined so its `currentColor` fills
 * pick up the surrounding theme. For raster images and external/uploaded
 * logos, a regular <img> tag is emitted.
 *
 * @param string $path        URL or relative path of the asset
 * @param string $alt         Alt text (used as <img alt> or aria-label)
 * @param array  $opts        width, height, class
 * @param string $projectRoot Filesystem path to the project root (for resolving $path)
 */
function nibblyIconOrImg(string $path, string $alt = '', array $opts = [], string $projectRoot = ''): string {
    $width  = $opts['width']  ?? null;
    $height = $opts['height'] ?? null;
    $class  = $opts['class']  ?? '';

    if ($projectRoot === '') {
        $projectRoot = dirname(__DIR__);
    }

    $isSvg = str_ends_with(strtolower(parse_url($path, PHP_URL_PATH) ?? $path), '.svg');
    if ($isSvg) {
        $relative = ltrim(parse_url($path, PHP_URL_PATH) ?? $path, '/');
        $absolute = $projectRoot . '/' . $relative;
        if (is_file($absolute) && is_readable($absolute)) {
            $svg = file_get_contents($absolute);
            if ($svg !== false) {
                $attrs = '';
                if ($class !== '') $attrs .= ' class="' . htmlspecialchars($class) . '"';
                if ($width)        $attrs .= ' width="' . (int)$width . '"';
                if ($height)       $attrs .= ' height="' . (int)$height . '"';
                if ($alt !== '')   $attrs .= ' role="img" aria-label="' . htmlspecialchars($alt) . '"';
                else               $attrs .= ' aria-hidden="true" focusable="false"';
                $svg = preg_replace('/<svg\b([^>]*)>/i', '<svg$1' . $attrs . '>', $svg, 1);
                return $svg;
            }
        }
    }

    $imgAttrs = ' src="' . htmlspecialchars($path) . '" alt="' . htmlspecialchars($alt) . '"';
    if ($width)     $imgAttrs .= ' width="' . (int)$width . '"';
    if ($height)    $imgAttrs .= ' height="' . (int)$height . '"';
    if ($class !== '') $imgAttrs .= ' class="' . htmlspecialchars($class) . '"';
    return '<img' . $imgAttrs . '>';
}
