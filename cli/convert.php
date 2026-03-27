#!/usr/bin/env php
<?php
/**
 * Nibbly HTML-to-Editable Converter
 *
 * Converts a static HTML page into a Nibbly-editable PHP template + JSON content file.
 *
 * Usage:
 *   php cli/convert.php input.html [--slug=NAME] [--lang=CODE] [--title=TEXT]
 *                                  [--description=TEXT] [--dry-run] [--json-only] [--force]
 */

// Must run from project root
$projectRoot = dirname(__DIR__);
if (!file_exists($projectRoot . '/router.php')) {
    fwrite(STDERR, "Error: Run this script from the Nibbly project root.\n");
    fwrite(STDERR, "  cd /path/to/nibbly && php cli/convert.php input.html\n");
    exit(1);
}

// ── CLI argument parsing ──────────────────────────────────────────────

// Manual parsing because PHP getopt() stops at first non-option argument
$opts = [];
$args = [];
for ($i = 1; $i < count($argv); $i++) {
    $a = $argv[$i];
    if (preg_match('/^--([a-z-]+)=(.+)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)$/', $a, $m)) {
        $opts[$m[1]] = true;
    } elseif ($a[0] !== '-') {
        $args[] = $a;
    }
}

if (isset($opts['help']) || empty($args)) {
    echo <<<USAGE
Nibbly HTML-to-Editable Converter

Usage:
  php cli/convert.php <input.html> [options]

Options:
  --slug=NAME         Page slug (default: derived from filename)
  --lang=CODE         Language code (default: en)
  --title=TEXT        Page title (default: from <title> or first <h1>)
  --description=TEXT  SEO description (default: from <meta description>)
  --dry-run           Show what would be generated without writing files
  --json-only         Only generate JSON content file, no PHP template
  --no-css            Skip CSS extraction
  --force             Overwrite existing files
  --help              Show this help

Examples:
  php cli/convert.php landing-page.html --slug=landing --lang=en
  php cli/convert.php about.html --dry-run
  php cli/convert.php index.html --slug=home --title="Welcome"

USAGE;
    exit(0);
}

$inputFile = $args[0];
if (!file_exists($inputFile)) {
    fwrite(STDERR, "Error: File not found: $inputFile\n");
    exit(1);
}

$slug = $opts['slug'] ?? pathinfo($inputFile, PATHINFO_FILENAME);
$slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slug));
$slug = trim(preg_replace('/-+/', '-', $slug), '-');
$lang = $opts['lang'] ?? 'en';
$dryRun = isset($opts['dry-run']);
$jsonOnly = isset($opts['json-only']);
$noCss = isset($opts['no-css']);
$force = isset($opts['force']);

$html = file_get_contents($inputFile);

// ── Converter class ───────────────────────────────────────────────────

class HtmlToNibbly
{
    private string $html;
    private string $slug;
    private string $lang;
    private ?string $optTitle;
    private ?string $optDescription;
    private DOMDocument $dom;
    private DOMXPath $xpath;

    private string $pageTitle = '';
    private string $pageDescription = '';

    /** @var array{key: string, elements: array}[] */
    private array $sections = [];

    /** @var array Collected JSON content */
    private array $jsonData = [];

    /** @var string[] PHP template lines for the <main> body */
    private array $templateLines = [];

    /** @var array Track used field keys to avoid duplicates */
    private array $usedKeys = [];

    /** @var string[] Collected CSS blocks */
    private array $cssBlocks = [];

    /** @var string[] External stylesheet paths found in the HTML */
    private array $linkedStylesheets = [];

    /** @var int Counter for generated inline-style classes */
    private int $inlineStyleCounter = 0;

    /** @var string Path to the input HTML file (for resolving relative stylesheet paths) */
    private string $inputDir = '';

    /** @var bool Whether to extract CSS */
    private bool $extractCssEnabled = true;

    public function __construct(string $html, string $slug, string $lang, ?string $title = null, ?string $description = null, string $inputDir = '')
    {
        $this->html = $html;
        $this->slug = $slug;
        $this->lang = $lang;
        $this->optTitle = $title;
        $this->optDescription = $description;
        $this->inputDir = $inputDir;
    }

    public function setExtractCss(bool $enabled): void
    {
        $this->extractCssEnabled = $enabled;
    }

    public function convert(): void
    {
        if ($this->extractCssEnabled) {
            $this->extractCss();
        }
        $this->parseHtml();
        $this->extractMeta();
        $this->extractSections();
        $this->processSections();
    }

    public function getPageTitle(): string { return $this->pageTitle; }
    public function getPageDescription(): string { return $this->pageDescription; }
    public function getSections(): array { return $this->sections; }
    public function getJsonData(): array { return $this->jsonData; }
    public function getLinkedStylesheets(): array { return $this->linkedStylesheets; }
    public function hasCss(): bool { return !empty($this->cssBlocks); }

    public function generateJson(): string
    {
        $data = [
            'page' => $this->lang . '_' . $this->slug,
            'lang' => $this->lang,
            'title' => $this->pageTitle,
        ];
        if ($this->pageDescription) {
            $data['description'] = $this->pageDescription;
        }
        $data['lastModified'] = null;
        $data = array_merge($data, $this->jsonData);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public function generateTemplate(): string
    {
        $contentPage = $this->lang . '_' . $this->slug;
        $title = addslashes($this->pageTitle);
        $desc = addslashes($this->pageDescription);
        $cssFile = 'css/page-' . $this->slug . '.css';

        $php = "<?php\n";
        $php .= "\$pageTitle = '$title';\n";
        $php .= "\$pageDescription = '$desc';\n";
        $php .= "\$currentLang = '{$this->lang}';\n";
        $php .= "\$currentPage = '{$this->slug}';\n";
        $php .= "\$contentPage = '$contentPage';\n";
        $php .= "\$basePath = '../';\n";

        if ($this->hasCss()) {
            $php .= "\$pageStylesheet = '$cssFile';\n";
        }

        // External CDN stylesheets (Google Fonts etc.)
        $externalUrls = array_filter($this->linkedStylesheets, fn($u) => preg_match('#^https?://#', $u));
        if (!empty($externalUrls)) {
            $php .= "\$pageExternalStyles = [\n";
            foreach ($externalUrls as $url) {
                $php .= "    '" . addslashes($url) . "',\n";
            }
            $php .= "];\n";
        }

        $php .= "\n";
        $php .= "include '../includes/header.php';\n";
        $php .= "include '../includes/content-loader.php';\n";
        $php .= "\$_p = \$contentPage;\n";
        $php .= "?>\n";
        $php .= "    <main class=\"main-content\">\n";

        foreach ($this->templateLines as $line) {
            $php .= $line . "\n";
        }

        $php .= "    </main>\n";
        $php .= "<?php include '../includes/footer.php'; ?>\n";

        return $php;
    }

    public function generateCss(): string
    {
        if (empty($this->cssBlocks)) {
            return '';
        }

        $css = "/*\n";
        $css .= " * Extracted styles for page: {$this->slug}\n";
        $css .= " * Generated by Nibbly HTML-to-Editable Converter\n";
        $css .= " *\n";
        $css .= " * Review and adjust these styles:\n";
        $css .= " * - Replace hardcoded colors with CSS custom properties from css/style.css\n";
        $css .= " * - Replace hardcoded spacing with --spacing-* tokens\n";
        $css .= " * - Check that font families match your project's typography\n";
        $css .= " */\n\n";

        $css .= implode("\n\n", $this->cssBlocks) . "\n";

        return $css;
    }

    // ── CSS Extraction ─────────────────────────────────────────────────

    private function extractCss(): void
    {
        // 1. Extract <style> blocks
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $this->html, $matches)) {
            foreach ($matches[1] as $styleContent) {
                $css = trim($styleContent);
                if ($css !== '') {
                    $this->cssBlocks[] = "/* ── Embedded <style> block ── */\n" . $css;
                }
            }
        }

        // 2. Find <link rel="stylesheet"> references
        if (preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $this->html, $linkMatches)) {
            foreach ($linkMatches[0] as $linkTag) {
                if (preg_match('/href=["\']([^"\']+)["\']/i', $linkTag, $hrefMatch)) {
                    $href = $hrefMatch[1];
                    // Skip external CDN stylesheets (Google Fonts, etc.) — keep as-is
                    if (preg_match('#^https?://#', $href)) {
                        $this->linkedStylesheets[] = $href;
                        continue;
                    }
                    // Try to read local stylesheet
                    $resolvedPath = $this->resolveFilePath($href);
                    if ($resolvedPath && file_exists($resolvedPath)) {
                        $content = file_get_contents($resolvedPath);
                        if (trim($content) !== '') {
                            $this->cssBlocks[] = "/* ── From linked file: $href ── */\n" . trim($content);
                        }
                    } else {
                        $this->cssBlocks[] = "/* ── Linked stylesheet not found: $href ── */\n/* Copy this file manually into css/ */";
                        $this->linkedStylesheets[] = $href;
                    }
                }
            }
        }

        // 3. Convert inline styles to classes
        $this->html = preg_replace_callback(
            '/(<\w[^>]*)\sstyle="([^"]+)"([^>]*>)/i',
            function ($match) {
                $style = $match[2];
                // Skip trivial inline styles (display:none etc. that are likely JS-controlled)
                if (preg_match('/^\s*display\s*:\s*none\s*;?\s*$/i', $style)) {
                    return $match[0];
                }
                $this->inlineStyleCounter++;
                $className = 'converted-style-' . $this->inlineStyleCounter;
                $this->cssBlocks[] = "/* ── Converted inline style ── */\n.$className {\n    " .
                    implode(";\n    ", array_filter(array_map('trim', explode(';', $style)))) .
                    ";\n}";

                // Add class to the element (merge with existing class if present)
                $before = $match[1];
                $after = $match[3];
                if (preg_match('/class="([^"]*)"/i', $before, $classMatch)) {
                    $before = str_replace(
                        'class="' . $classMatch[1] . '"',
                        'class="' . $classMatch[1] . ' ' . $className . '"',
                        $before
                    );
                } else {
                    $before .= ' class="' . $className . '"';
                }
                return $before . $after;
            },
            $this->html
        );
    }

    private function resolveFilePath(string $href): ?string
    {
        // Remove query strings and fragments
        $href = preg_replace('/[?#].*$/', '', $href);

        if ($this->inputDir && !preg_match('#^/#', $href)) {
            // Relative to the input HTML file
            $path = $this->inputDir . '/' . $href;
            if (file_exists($path)) return realpath($path);
        }

        // Try as-is (absolute or relative to cwd)
        if (file_exists($href)) return realpath($href);

        return null;
    }

    // ── HTML Parsing ──────────────────────────────────────────────────

    private function parseHtml(): void
    {
        $this->dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // Ensure UTF-8 handling
        $html = $this->html;
        if (stripos($html, '<meta charset') === false && stripos($html, 'encoding') === false) {
            $html = '<meta charset="UTF-8">' . $html;
        }
        $this->dom->loadHTML($html, LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();
        $this->xpath = new DOMXPath($this->dom);
    }

    private function extractMeta(): void
    {
        // Title
        if ($this->optTitle) {
            $this->pageTitle = $this->optTitle;
        } else {
            $titleNodes = $this->xpath->query('//title');
            if ($titleNodes->length > 0) {
                $this->pageTitle = trim($titleNodes->item(0)->textContent);
            }
            if (!$this->pageTitle) {
                $h1 = $this->xpath->query('//h1');
                if ($h1->length > 0) {
                    $this->pageTitle = trim($h1->item(0)->textContent);
                }
            }
            if (!$this->pageTitle) {
                $this->pageTitle = ucfirst(str_replace('-', ' ', $this->slug));
            }
        }

        // Description
        if ($this->optDescription) {
            $this->pageDescription = $this->optDescription;
        } else {
            $meta = $this->xpath->query('//meta[@name="description"]/@content');
            if ($meta->length > 0) {
                $this->pageDescription = trim($meta->item(0)->textContent);
            }
        }
    }

    // ── Section Extraction ────────────────────────────────────────────

    private function extractSections(): void
    {
        // Find <body> or use document root
        $body = $this->xpath->query('//body')->item(0);
        if (!$body) {
            $body = $this->dom->documentElement;
        }
        if (!$body) {
            return;
        }

        $pendingElements = [];
        $currentKey = null;

        foreach ($this->childElements($body) as $node) {
            $tag = strtolower($node->nodeName);

            // Skip non-content elements
            if (in_array($tag, ['script', 'style', 'link', 'meta', 'head', 'nav', 'noscript'])) {
                continue;
            }

            // Check if this is a section boundary
            if ($this->isSectionBoundary($node)) {
                // Flush pending
                if ($currentKey && $pendingElements) {
                    $this->sections[] = ['key' => $currentKey, 'nodes' => $pendingElements];
                }
                $currentKey = $this->inferSectionKey($node);
                $pendingElements = [$node];
            } else {
                // If no section started yet, start one
                if ($currentKey === null) {
                    $currentKey = $this->inferSectionKey($node);
                }
                $pendingElements[] = $node;
            }
        }

        // Flush remaining
        if ($currentKey && $pendingElements) {
            $this->sections[] = ['key' => $currentKey, 'nodes' => $pendingElements];
        }

        // Deduplicate section keys
        $seen = [];
        foreach ($this->sections as &$section) {
            $base = $section['key'];
            if (isset($seen[$base])) {
                $seen[$base]++;
                $section['key'] = $base . '_' . $seen[$base];
            } else {
                $seen[$base] = 1;
            }
        }
    }

    private function isSectionBoundary(DOMElement $node): bool
    {
        $tag = strtolower($node->nodeName);

        // Semantic section elements
        if (in_array($tag, ['section', 'article', 'header', 'footer', 'aside'])) {
            return true;
        }

        // <main> is a section boundary
        if ($tag === 'main') {
            return true;
        }

        // Div with semantic class names
        if ($tag === 'div') {
            $class = $node->getAttribute('class');
            $id = $node->getAttribute('id');
            $combined = strtolower($class . ' ' . $id);
            $semanticPatterns = [
                'hero', 'banner', 'feature', 'about', 'service', 'pricing',
                'testimonial', 'team', 'contact', 'cta', 'faq', 'gallery',
                'stats', 'timeline', 'comparison', 'section', 'block',
                'wrapper', 'container',
            ];
            foreach ($semanticPatterns as $pattern) {
                if (strpos($combined, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function inferSectionKey(DOMElement $node): string
    {
        $tag = strtolower($node->nodeName);

        // Try class name first
        $class = strtolower($node->getAttribute('class'));
        $id = strtolower($node->getAttribute('id'));

        // Extract semantic name from class/id
        $candidates = array_merge(
            preg_split('/[\s\-_]+/', $class) ?: [],
            preg_split('/[\s\-_]+/', $id) ?: []
        );
        $candidates = array_filter($candidates);

        $semanticNames = [
            'hero', 'banner', 'header', 'intro',
            'features', 'feature', 'services', 'service',
            'about', 'story', 'mission',
            'pricing', 'plans', 'packages',
            'testimonials', 'testimonial', 'reviews',
            'team', 'members', 'people',
            'contact', 'cta', 'action',
            'faq', 'questions',
            'gallery', 'portfolio', 'work',
            'stats', 'numbers', 'figures',
            'timeline', 'history', 'roadmap',
            'comparison', 'compare',
            'footer', 'bottom',
            'content', 'main', 'body',
        ];

        foreach ($candidates as $c) {
            if (in_array($c, $semanticNames)) {
                return $c;
            }
        }

        // Try tag name for semantic elements
        if (in_array($tag, ['header', 'footer', 'article', 'aside'])) {
            return $tag;
        }

        // Fallback: use a generic section key
        return 'section';
    }

    // ── Section Processing ────────────────────────────────────────────

    private function processSections(): void
    {
        foreach ($this->sections as $section) {
            $key = $section['key'];
            $nodes = $section['nodes'];

            // If the section has exactly one container node, process its children
            if (count($nodes) === 1 && $this->hasContentChildren($nodes[0])) {
                $this->processContainer($key, $nodes[0]);
            } else {
                // Process nodes directly
                $this->processNodeList($key, $nodes, 2);
            }
        }
    }

    private function processContainer(string $sectionKey, DOMElement $container): void
    {
        $tag = strtolower($container->nodeName);
        $attrs = $this->buildAttrString($container, ['class', 'id']);

        // Check if children form a repeating pattern
        $pattern = $this->detectRepeatingPattern($container);
        if ($pattern) {
            $this->processRepeatingPattern($sectionKey, $container, $pattern);
            return;
        }

        // Open the container tag
        $indent = str_repeat(' ', 8);
        $this->templateLines[] = "$indent<$tag$attrs>";

        // Check for inner wrapper (main > div.container > actual content)
        $children = $this->childElements($container);
        if (count($children) === 1 && $this->isWrapper($children[0])) {
            $inner = $children[0];
            $innerTag = strtolower($inner->nodeName);
            $innerAttrs = $this->buildAttrString($inner, ['class', 'id']);
            $this->templateLines[] = "$indent    <$innerTag$innerAttrs>";
            $this->processNodeList($sectionKey, $this->childElements($inner), 4);
            $this->templateLines[] = "$indent    </$innerTag>";
        } else {
            $this->processNodeList($sectionKey, $children, 3);
        }

        $this->templateLines[] = "$indent</$tag>";
    }

    private function processNodeList(string $sectionKey, array $nodes, int $indentLevel): void
    {
        $indent = str_repeat(' ', $indentLevel * 4);
        $fieldCounter = [];

        // Pre-scan: count consecutive <p> tags to decide editableHtml vs editableText
        $consecutiveP = [];
        $pRun = 0;
        $pRunStart = -1;
        foreach ($nodes as $i => $node) {
            if ($node instanceof DOMElement && strtolower($node->nodeName) === 'p') {
                if ($pRun === 0) $pRunStart = $i;
                $pRun++;
            } else {
                if ($pRun >= 2) {
                    for ($j = $pRunStart; $j < $pRunStart + $pRun; $j++) {
                        $consecutiveP[$j] = true;
                    }
                }
                $pRun = 0;
            }
        }
        if ($pRun >= 2) {
            for ($j = $pRunStart; $j < $pRunStart + $pRun; $j++) {
                $consecutiveP[$j] = true;
            }
        }

        // Collect consecutive <p> for editableHtml
        $htmlBuffer = [];
        $htmlBufferStart = -1;

        $flushHtmlBuffer = function () use (&$htmlBuffer, &$htmlBufferStart, $sectionKey, $indent, &$fieldCounter) {
            if (empty($htmlBuffer)) return;
            $fieldName = $this->uniqueFieldName($sectionKey, 'content', $fieldCounter);
            $htmlContent = implode("\n", $htmlBuffer);
            $escaped = addslashes($htmlContent);
            $this->templateLines[] = "$indent<div><?php echo editableHtml(\$_p, '$fieldName', '$escaped'); ?></div>";
            $this->setJsonValue($fieldName, $htmlContent);
            $htmlBuffer = [];
            $htmlBufferStart = -1;
        };

        foreach ($nodes as $i => $node) {
            if (!($node instanceof DOMElement)) {
                // Text nodes with meaningful content
                $text = trim($node->textContent);
                if ($text !== '') {
                    $flushHtmlBuffer();
                    $fieldName = $this->uniqueFieldName($sectionKey, 'text', $fieldCounter);
                    $escaped = addslashes($text);
                    $this->templateLines[] = "$indent<?php echo editableText(\$_p, '$fieldName', '$escaped'); ?>";
                    $this->setJsonValue($fieldName, $text);
                }
                continue;
            }

            $tag = strtolower($node->nodeName);

            // Consecutive <p> → editableHtml
            if (isset($consecutiveP[$i])) {
                $htmlBuffer[] = $this->getInnerHtml($node, true);
                if ($htmlBufferStart === -1) $htmlBufferStart = $i;
                continue;
            }

            $flushHtmlBuffer();

            // Headings
            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $text = trim($node->textContent);
                $role = !isset($fieldCounter['title']) ? 'title' : 'heading';
                $fieldName = $this->uniqueFieldName($sectionKey, $role, $fieldCounter);
                $escaped = addslashes($text);
                $this->templateLines[] = "$indent<$tag><?php echo editableText(\$_p, '$fieldName', '$escaped'); ?></$tag>";
                $this->setJsonValue($fieldName, $text);
                continue;
            }

            // Single paragraph
            if ($tag === 'p') {
                $innerHtml = $this->getInnerHtml($node);
                $text = trim($node->textContent);
                $hasFormatting = ($innerHtml !== htmlspecialchars($text, ENT_QUOTES, 'UTF-8') && $innerHtml !== $text);
                $pAttrs = $this->buildAttrString($node, ['class']);

                if ($hasFormatting || mb_strlen($text) > 200) {
                    $fieldName = $this->uniqueFieldName($sectionKey, 'content', $fieldCounter);
                    $wrapped = '<p>' . $innerHtml . '</p>';
                    $escaped = addslashes($wrapped);
                    $this->templateLines[] = "$indent<div><?php echo editableHtml(\$_p, '$fieldName', '$escaped'); ?></div>";
                    $this->setJsonValue($fieldName, $wrapped);
                } else {
                    $role = (!isset($fieldCounter['title']) && !isset($fieldCounter['subtitle']) && isset($fieldCounter['title_done']))
                        ? 'subtitle'
                        : 'text';
                    $fieldName = $this->uniqueFieldName($sectionKey, $role, $fieldCounter);
                    $escaped = addslashes($text);
                    $this->templateLines[] = "$indent<p$pAttrs><?php echo editableText(\$_p, '$fieldName', '$escaped'); ?></p>";
                    $this->setJsonValue($fieldName, $text);
                }
                continue;
            }

            // Images
            if ($tag === 'img') {
                $src = $node->getAttribute('src') ?: '';
                $alt = $node->getAttribute('alt') ?: '';
                $class = $node->getAttribute('class') ?: '';
                $fieldName = $this->uniqueFieldName($sectionKey, 'image', $fieldCounter);
                $srcE = addslashes($src);
                $altE = addslashes($alt);
                $classArg = $class ? ", '$class'" : '';
                $this->templateLines[] = "$indent<?php echo editableImage(\$_p, '$fieldName', '$srcE', '$altE'$classArg); ?>";
                $this->setJsonValue($fieldName, ['src' => $src, 'alt' => $alt]);
                continue;
            }

            // Links (standalone, button-like)
            if ($tag === 'a' && $this->isStandaloneLink($node)) {
                $text = trim($node->textContent);
                $href = $node->getAttribute('href') ?: '#';
                $class = $node->getAttribute('class') ?: '';
                $fieldName = $this->uniqueFieldName($sectionKey, 'cta', $fieldCounter);
                $textE = addslashes($text);
                $hrefE = addslashes($href);
                $classArg = $class ? ", '$class'" : '';
                $this->templateLines[] = "$indent<?php echo editableLink(\$_p, '$fieldName', '$textE', '$hrefE'$classArg); ?>";
                $this->setJsonValue($fieldName, ['text' => $text, 'href' => $href]);
                continue;
            }

            // Blockquote
            if ($tag === 'blockquote') {
                $this->processBlockquote($sectionKey, $node, $indent, $fieldCounter);
                continue;
            }

            // Lists (ul/ol)
            if (in_array($tag, ['ul', 'ol'])) {
                $fieldName = $this->uniqueFieldName($sectionKey, 'list', $fieldCounter);
                $listHtml = '<' . $tag . '>' . $this->getInnerHtml($node) . '</' . $tag . '>';
                $escaped = addslashes($listHtml);
                $this->templateLines[] = "$indent<div><?php echo editableHtml(\$_p, '$fieldName', '$escaped'); ?></div>";
                $this->setJsonValue($fieldName, $listHtml);
                continue;
            }

            // Nested container — check for repeating pattern
            if (in_array($tag, ['div', 'section', 'article', 'ul', 'ol']) && $this->hasContentChildren($node)) {
                $pattern = $this->detectRepeatingPattern($node);
                if ($pattern) {
                    $this->processRepeatingPattern($sectionKey, $node, $pattern);
                } else {
                    // Recurse into the container
                    $nodeAttrs = $this->buildAttrString($node, ['class', 'id']);
                    $this->templateLines[] = "$indent<$tag$nodeAttrs>";
                    $this->processNodeList($sectionKey, $this->childElements($node), $indentLevel + 1);
                    $this->templateLines[] = "$indent</$tag>";
                }
                continue;
            }

            // Picture element — find inner img
            if ($tag === 'picture') {
                $img = $this->xpath->query('.//img', $node)->item(0);
                if ($img) {
                    $src = $img->getAttribute('src') ?: '';
                    $alt = $img->getAttribute('alt') ?: '';
                    $class = $img->getAttribute('class') ?: '';
                    $fieldName = $this->uniqueFieldName($sectionKey, 'image', $fieldCounter);
                    $srcE = addslashes($src);
                    $altE = addslashes($alt);
                    $classArg = $class ? ", '$class'" : '';
                    $this->templateLines[] = "$indent<?php echo editableImage(\$_p, '$fieldName', '$srcE', '$altE'$classArg); ?>";
                    $this->setJsonValue($fieldName, ['src' => $src, 'alt' => $alt]);
                } else {
                    $this->templateLines[] = "$indent" . $this->dom->saveHTML($node);
                }
                continue;
            }

            // Fallback: keep as-is but try to process inline content
            $this->templateLines[] = "$indent" . trim($this->dom->saveHTML($node));
        }

        $flushHtmlBuffer();
    }

    // ── Repeating Pattern Detection ───────────────────────────────────

    private function detectRepeatingPattern(DOMElement $container): ?array
    {
        $children = $this->childElements($container);
        if (count($children) < 2) {
            return null;
        }

        // Check if all children share the same tag + class pattern
        $firstTag = strtolower($children[0]->nodeName);
        $firstClass = $children[0]->getAttribute('class');
        $matching = 0;

        foreach ($children as $child) {
            if (strtolower($child->nodeName) === $firstTag && $child->getAttribute('class') === $firstClass) {
                $matching++;
            }
        }

        // At least 2 matching children, and majority of children match
        if ($matching < 2 || $matching < count($children) * 0.6) {
            return null;
        }

        // Analyze the structure of the first item to build a field map
        $fields = $this->analyzeItemStructure($children[0]);
        if (empty($fields)) {
            return null;
        }

        return [
            'tag' => $firstTag,
            'class' => $firstClass,
            'fields' => $fields,
            'items' => $children,
        ];
    }

    private function analyzeItemStructure(DOMElement $item): array
    {
        $fields = [];
        foreach ($this->childElements($item) as $child) {
            $tag = strtolower($child->nodeName);
            $text = trim($child->textContent);
            if (!$text && $tag !== 'img') continue;

            if (preg_match('/^h[1-6]$/', $tag)) {
                $fields[] = ['role' => 'title', 'tag' => $tag];
            } elseif ($tag === 'p') {
                $fields[] = ['role' => 'text', 'tag' => $tag];
            } elseif (in_array($tag, ['cite', 'figcaption'])) {
                $fields[] = ['role' => 'attribution', 'tag' => $tag];
            } elseif ($tag === 'footer') {
                // <footer> inside blockquote typically contains attribution
                $fields[] = ['role' => 'attribution', 'tag' => $tag];
            } elseif ($tag === 'img') {
                $fields[] = ['role' => 'image', 'tag' => $tag];
            } elseif ($tag === 'a' && $this->isStandaloneLink($child)) {
                $fields[] = ['role' => 'link', 'tag' => $tag];
            } elseif ($tag === 'span' || $tag === 'div') {
                // Try to infer from class
                $class = strtolower($child->getAttribute('class'));
                if (strpos($class, 'title') !== false || strpos($class, 'name') !== false) {
                    $fields[] = ['role' => 'title', 'tag' => $tag];
                } elseif (strpos($class, 'desc') !== false || strpos($class, 'text') !== false || strpos($class, 'content') !== false) {
                    $fields[] = ['role' => 'desc', 'tag' => $tag];
                } elseif (strpos($class, 'icon') !== false) {
                    $fields[] = ['role' => 'icon', 'tag' => $tag];
                } else {
                    $fields[] = ['role' => 'text', 'tag' => $tag];
                }
            }
        }
        return $fields;
    }

    private function processRepeatingPattern(string $sectionKey, DOMElement $container, array $pattern): void
    {
        $containerTag = strtolower($container->nodeName);
        $containerAttrs = $this->buildAttrString($container, ['class', 'id']);
        $itemTag = $pattern['tag'];
        $itemClass = $pattern['class'];
        $fields = $pattern['fields'];
        $items = $pattern['items'];
        $listKey = $sectionKey . '.items';

        // Build defaults array
        $defaults = [];
        foreach ($fields as $f) {
            if ($f['role'] === 'image') {
                $defaults[$f['role']] = ['src' => '', 'alt' => ''];
            } elseif ($f['role'] === 'link') {
                $defaults[$f['role']] = ['text' => '', 'href' => '#'];
            } elseif ($f['role'] !== 'icon') {
                $defaults[$f['role']] = '';
            }
        }
        $defaultsJson = var_export($defaults, true);
        // Compact the var_export output
        $defaultsJson = preg_replace('/\s+/', ' ', $defaultsJson);
        $defaultsJson = str_replace(['array (', ')'], ['[', ']'], $defaultsJson);
        $defaultsJson = str_replace(' => ', ' => ', $defaultsJson);

        $indent = str_repeat(' ', 8);
        $indent2 = $indent . '    ';
        $indent3 = $indent2 . '    ';

        $itemClassAttr = $itemClass ? " class=\"$itemClass\"" : '';

        $this->templateLines[] = "$indent<$containerTag$containerAttrs <?php echo editableListAttrs(\$_p, '$listKey', $defaultsJson); ?>>";
        $this->templateLines[] = "$indent2<?php foreach (editableListItems(\$_p, '$listKey') as \$i => \$item): ?>";
        $this->templateLines[] = "$indent2<$itemTag$itemClassAttr <?php echo editableListItemAttrs(\$_p, '$listKey', \$i); ?>>";

        // Generate field templates
        foreach ($fields as $f) {
            $role = $f['role'];
            $tag = $f['tag'];
            if ($role === 'icon') {
                $this->templateLines[] = "$indent3<!-- icon: consider keeping as-is or using editableText -->";
                continue;
            }
            $fieldPath = "$listKey.\$i.$role";
            if ($role === 'image') {
                $this->templateLines[] = "$indent3<?php echo editableImage(\$_p, \"$fieldPath\", '', '', ''); ?>";
            } elseif ($role === 'link') {
                $this->templateLines[] = "$indent3<?php echo editableLink(\$_p, \"$fieldPath\", '', '#', ''); ?>";
            } else {
                $this->templateLines[] = "$indent3<$tag><?php echo editableText(\$_p, \"$fieldPath\", ''); ?></$tag>";
            }
        }

        $this->templateLines[] = "$indent2</$itemTag>";
        $this->templateLines[] = "$indent2<?php endforeach; ?>";
        $this->templateLines[] = "$indent</$containerTag>";

        // Generate JSON data for items
        $jsonItems = [];
        foreach ($items as $idx => $itemNode) {
            $itemData = [];
            $childElements = $this->childElements($itemNode);
            $fieldIdx = 0;
            foreach ($childElements as $child) {
                if ($fieldIdx >= count($fields)) break;
                $f = $fields[$fieldIdx];
                $childTag = strtolower($child->nodeName);

                // Try to match by expected tag
                if ($childTag === $f['tag'] || ($f['role'] === 'title' && preg_match('/^h[1-6]$/', $childTag))) {
                    if ($f['role'] === 'image') {
                        $itemData[$f['role']] = [
                            'src' => $child->getAttribute('src') ?: '',
                            'alt' => $child->getAttribute('alt') ?: '',
                        ];
                    } elseif ($f['role'] === 'link') {
                        $itemData[$f['role']] = [
                            'text' => trim($child->textContent),
                            'href' => $child->getAttribute('href') ?: '#',
                        ];
                    } elseif ($f['role'] === 'icon') {
                        // skip icons in JSON
                    } else {
                        $itemData[$f['role']] = trim($child->textContent);
                    }
                    $fieldIdx++;
                }
            }
            $jsonItems[(string)$idx] = $itemData;
        }

        // Cast to object so JSON encodes as {"0": {...}, "1": {...}} not [...]
        $this->setJsonValue($sectionKey . '.items', (object)$jsonItems);
    }

    // ── Blockquote ────────────────────────────────────────────────────

    private function processBlockquote(string $sectionKey, DOMElement $node, string $indent, array &$fieldCounter): void
    {
        $quoteText = '';
        $attribution = '';

        foreach ($this->childElements($node) as $child) {
            $tag = strtolower($child->nodeName);
            if (in_array($tag, ['cite', 'footer', 'figcaption'])) {
                $attribution = trim($child->textContent);
            } else {
                $quoteText .= trim($child->textContent) . ' ';
            }
        }
        if (!$quoteText) {
            $quoteText = trim($node->textContent);
            if ($attribution && strpos($quoteText, $attribution) !== false) {
                $quoteText = trim(str_replace($attribution, '', $quoteText));
            }
        }
        $quoteText = trim($quoteText);

        $fieldName = $this->uniqueFieldName($sectionKey, 'quote', $fieldCounter);
        $quotE = addslashes($quoteText);
        $this->templateLines[] = "$indent<blockquote>";
        $this->templateLines[] = "$indent    <p><?php echo editableText(\$_p, '$fieldName', '$quotE'); ?></p>";

        if ($attribution) {
            $attrField = $this->uniqueFieldName($sectionKey, 'attribution', $fieldCounter);
            $attrE = addslashes($attribution);
            $this->templateLines[] = "$indent    <cite><?php echo editableText(\$_p, '$attrField', '$attrE'); ?></cite>";
            $this->setJsonValue($attrField, $attribution);
        }

        $this->templateLines[] = "$indent</blockquote>";
        $this->setJsonValue($fieldName, $quoteText);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** @return DOMElement[] */
    private function childElements(DOMNode $parent): array
    {
        $elements = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            }
        }
        return $elements;
    }

    private function hasContentChildren(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) return true;
        }
        return false;
    }

    private function isWrapper(DOMElement $node): bool
    {
        $tag = strtolower($node->nodeName);
        if ($tag !== 'div') return false;
        $class = strtolower($node->getAttribute('class'));
        return (bool)preg_match('/\b(container|wrapper|inner|content)\b/', $class);
    }

    private function isStandaloneLink(DOMElement $node): bool
    {
        // A link is "standalone" if it's not wrapping complex content (like images or blocks)
        $children = $this->childElements($node);
        foreach ($children as $child) {
            $tag = strtolower($child->nodeName);
            if (in_array($tag, ['img', 'div', 'section', 'article', 'ul', 'ol', 'table'])) {
                return false;
            }
        }

        // Check for button-like classes
        $class = strtolower($node->getAttribute('class'));
        if (preg_match('/\b(btn|button|cta|action|link)\b/', $class)) {
            return true;
        }

        // If it's a direct child of a section (not nested in a paragraph)
        $parent = $node->parentNode;
        if ($parent instanceof DOMElement) {
            $parentTag = strtolower($parent->nodeName);
            if (!in_array($parentTag, ['p', 'span', 'li', 'td', 'th'])) {
                return true;
            }
        }

        return false;
    }

    private function getInnerHtml(DOMElement $node, bool $wrapInP = false): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $this->dom->saveHTML($child);
        }
        $html = trim($html);
        if ($wrapInP) {
            $html = '<p>' . $html . '</p>';
        }
        return $html;
    }

    private function buildAttrString(DOMElement $node, array $preserve): string
    {
        $attrs = '';
        foreach ($preserve as $name) {
            $val = $node->getAttribute($name);
            if ($val) {
                $attrs .= " $name=\"" . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . "\"";
            }
        }
        return $attrs;
    }

    private function uniqueFieldName(string $section, string $role, array &$counter): string
    {
        $key = $section . '.' . $role;
        if (!isset($counter[$role])) {
            $counter[$role] = 1;
            if ($role === 'title') $counter['title_done'] = true;
            if (!isset($this->usedKeys[$key])) {
                $this->usedKeys[$key] = true;
                return $key;
            }
        }

        // Find next available
        $n = $counter[$role] + 1;
        while (isset($this->usedKeys[$section . '.' . $role . '_' . $n])) {
            $n++;
        }
        $counter[$role] = $n;
        $finalKey = $section . '.' . $role . '_' . $n;
        $this->usedKeys[$finalKey] = true;
        return $finalKey;
    }

    private function setJsonValue(string $dotKey, $value): void
    {
        $parts = explode('.', $dotKey);
        $ref = &$this->jsonData;
        foreach ($parts as $part) {
            if (!isset($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }
}

// ── Main ──────────────────────────────────────────────────────────────

$inputDir = realpath(dirname($inputFile)) ?: dirname($inputFile);
$converter = new HtmlToNibbly(
    $html,
    $slug,
    $lang,
    $opts['title'] ?? null,
    $opts['description'] ?? null,
    $inputDir
);

if ($noCss) {
    $converter->setExtractCss(false);
}
$converter->convert();

$sections = $converter->getSections();

// ── Summary output ────────────────────────────────────────────────────

echo "Parsing $inputFile...\n\n";

if (empty($sections)) {
    echo "No content sections found.\n";
    exit(1);
}

echo "Found " . count($sections) . " section(s):\n";
foreach ($sections as $s) {
    $nodeCount = count($s['nodes']);
    $types = [];
    foreach ($s['nodes'] as $node) {
        if ($node instanceof DOMElement) {
            $types[] = strtolower($node->nodeName);
        }
    }
    $typeStr = implode(', ', array_count_values($types));
    $typeStr = preg_replace_callback('/(\w+),\s*(\d+)/', fn($m) => $m[2] . '× ' . $m[1], $typeStr);
    // Simpler: just show tag names
    $tagList = implode(', ', array_unique($types));
    echo "  " . str_pad($s['key'], 20) . " → $tagList\n";
}
echo "\n";

// ── Generate files ────────────────────────────────────────────────────

$contentPage = $lang . '_' . $slug;
$jsonPath = $projectRoot . '/content/pages/' . $contentPage . '.json';
$templateDir = $projectRoot . '/' . $lang;
$templatePath = $templateDir . '/' . $slug . '.php';
$cssPath = $projectRoot . '/css/page-' . $slug . '.css';

$jsonContent = $converter->generateJson();
$templateContent = $converter->generateTemplate();
$cssContent = $noCss ? '' : $converter->generateCss();

if ($dryRun) {
    echo "── DRY RUN ──\n\n";
    echo "Would create: $jsonPath\n";
    echo str_repeat('─', 60) . "\n";
    echo $jsonContent;
    echo "\n";

    if (!$jsonOnly) {
        echo "Would create: $templatePath\n";
        echo str_repeat('─', 60) . "\n";
        echo $templateContent;
        echo "\n";
    }

    if ($cssContent) {
        echo "Would create: $cssPath\n";
        echo str_repeat('─', 60) . "\n";
        echo $cssContent;
        echo "\n";
    }

    $linkedStyles = $converter->getLinkedStylesheets();
    $externalStyles = array_filter($linkedStyles, fn($u) => preg_match('#^https?://#', $u));
    if (!empty($externalStyles)) {
        echo "External stylesheets (loaded via \$pageExternalStyles):\n";
        foreach ($externalStyles as $url) {
            echo "  → $url\n";
        }
        echo "\n";
    }

    echo "── No files written ──\n";
    exit(0);
}

// Write JSON
if (file_exists($jsonPath) && !$force) {
    fwrite(STDERR, "Error: $jsonPath already exists. Use --force to overwrite.\n");
    exit(1);
}
file_put_contents($jsonPath, $jsonContent);
echo "  \033[32m✓\033[0m $jsonPath\n";

// Write template
if (!$jsonOnly) {
    if (!is_dir($templateDir)) {
        mkdir($templateDir, 0755, true);
    }
    if (file_exists($templatePath) && !$force) {
        fwrite(STDERR, "Error: $templatePath already exists. Use --force to overwrite.\n");
        exit(1);
    }
    file_put_contents($templatePath, $templateContent);
    echo "  \033[32m✓\033[0m $templatePath\n";
}

// Write CSS
if ($cssContent && !$jsonOnly) {
    if (file_exists($cssPath) && !$force) {
        fwrite(STDERR, "Error: $cssPath already exists. Use --force to overwrite.\n");
        exit(1);
    }
    file_put_contents($cssPath, $cssContent);
    echo "  \033[32m✓\033[0m $cssPath\n";
}

// Show external stylesheets info
$linkedStyles = $converter->getLinkedStylesheets();
$externalStyles = array_filter($linkedStyles, fn($u) => preg_match('#^https?://#', $u));
if (!empty($externalStyles)) {
    echo "\nExternal stylesheets (auto-loaded via \$pageExternalStyles):\n";
    foreach ($externalStyles as $url) {
        echo "  → $url\n";
    }
}

echo "\nAdd to navigation:\n";
echo "  Edit includes/nav-config.php and add '$slug' to \$PAGE_MAPPING and \$NAV_ITEMS\n";
