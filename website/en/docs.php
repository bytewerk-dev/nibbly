<?php
$pageTitle = 'Documentation — Nibbly CMS';
$pageDescription = 'Technical documentation for Nibbly CMS: project structure, JSON content format, section types, inline editing, and template system.';
$currentLang = 'en';
$currentPage = 'docs';
$contentPage = 'en_docs';
if (!isset($basePath)) $basePath = '../';
$pageClass = 'page-docs';

$_includeBase = dirname(__DIR__) . '/';

include $_includeBase . 'includes/header.php';
?>

    <main class="main-content">
        <div class="docs-layout">
            <!-- Sidebar Navigation -->
            <aside class="docs-sidebar" id="docsSidebar">
                <nav class="docs-nav">
                    <h4 class="docs-nav-title">Documentation</h4>
                    <ul>
                        <li><a href="#project-structure" class="active">Project Structure</a></li>
                        <li><a href="#pages">Creating Pages</a></li>
                        <li><a href="#json-format">JSON Content Format</a></li>
                        <li><a href="#shared-content">Shared Content</a></li>
                        <li><a href="#section-types">Section Types</a></li>
                        <li><a href="#inline-editing">Inline Editing</a></li>
                        <li><a href="#render-components">Render Components</a></li>
                        <li><a href="#auto-write">Auto-Write</a></li>
                        <li><a href="#templates">Template System</a></li>
                        <li><a href="#cli-tools">CLI Tools</a></li>
                        <li><a href="#css-system">CSS &amp; Design System</a></li>
                        <li><a href="#showcase">Showcase Page</a></li>
                        <li><a href="#backups">Backups</a></li>
                        <li><a href="#admin">Admin Dashboard</a></li>
                        <li><a href="#accessibility">Accessibility</a></li>
                        <li><a href="#access-controls">Access Controls</a></li>
                        <li><a href="#local-dev">Local Development</a></li>
                        <li><a href="#protected-forms">Bot-Protected Forms</a></li>
                        <li><a href="#security">Security</a></li>
                    </ul>
                </nav>
            </aside>

            <!-- Main Docs Content -->
            <div class="docs-content">

                <!-- Project Structure -->
                <section class="docs-section" id="project-structure">
                    <h1>Project Structure</h1>
                    <p>Nibbly is a flat-file CMS — everything lives in a single directory. No database, no build tools, no package manager.</p>

                    <div class="docs-tree">
                        <pre><code>nibbly/
├── admin/                  Admin panel &amp; API
│   ├── config.php          Your configuration (created by setup)
│   ├── setup.php           First-run setup wizard
│   ├── index.php           Login page
│   ├── dashboard.php       Content editor
│   └── api.php             REST API
├── api/
│   ├── contact.php         Contact form handler
│   └── SmtpMailer.php      SMTP mailer class
├── includes/
│   ├── header.php          HTML head + header + navigation
│   ├── footer.php          Footer + scripts
│   ├── access-guard.php    Maintenance mode + private pages
│   ├── backup-helper.php   Full-site backups, retention, remote uploads
│   ├── content-loader.php  Section rendering + events + editable fields
│   ├── block-renderers/    One PHP file per block type
│   ├── block-types.php     Block type definitions &amp; defaults
│   ├── nav-config.php      Navigation items &amp; language mapping
│   └── page.php            Front controller for JSON-based pages
├── cli/
│   ├── convert.php         HTML-to-Nibbly converter
│   ├── make.php            Page scaffolding tool
│   └── backup.php          Cron-friendly full-site backup runner
├── content/
│   ├── events.json         Shared event data (multilingual)
│   ├── settings.json       Site settings (branding, theme, favicon)
│   └── pages/              JSON content files
│       ├── en_home.json    English homepage content
│       ├── de_home.json    German homepage content
│       └── footer.json     Footer content (multilingual)
├── css/
│   ├── style.css           Main stylesheet (custom properties at top)
│   ├── components.css      Render component styles
│   ├── fonts.css           Font definitions (auto-loaded if present)
│   └── inline-editor.css   Inline editor styles
├── js/
│   ├── inline-editor.js    Inline editing system
│   ├── landing-effects.js  Landing page effects
│   └── audio-player.js     Custom audio player
├── assets/                 Images, audio, fonts
├── backups/                Page history + full-site backup ZIPs
├── en/                     English pages (primary language)
├── de/                     German pages
├── .htaccess               Security rules + routes to route.php (Apache)
├── route.php               Front controller (all routing logic)
├── router.php              Dev server router (php -S localhost:3000 router.php)
└── index.php               Homepage entry point</code></pre>
                    </div>
                </section>

                <!-- Creating Pages -->
                <section class="docs-section" id="pages">
                    <h2>Creating Pages</h2>
                    <p>Every page is a PHP file that sets a few variables, then includes the template system. Here's the minimal structure:</p>

                    <div class="docs-code">
                        <div class="docs-code-header">en/example.php</div>
                        <pre><code>&lt;?php
$pageTitle = 'Example Page';
$pageDescription = 'A meta description for SEO.';
$currentLang = 'en';
$currentPage = 'example';
$contentPage = 'en_example';
if (!isset($basePath)) $basePath = '../';

$_includeBase = dirname(__DIR__) . '/';

include $_includeBase . 'includes/header.php';
include $_includeBase . 'includes/content-loader.php';
?&gt;

    &lt;main class="main-content"&gt;
        &lt;div class="content-inner"&gt;
            &lt;?php echo renderAllSections($contentPage); ?&gt;
        &lt;/div&gt;
    &lt;/main&gt;

&lt;?php include $_includeBase . 'includes/sidebar.php'; ?&gt;
&lt;?php include $_includeBase . 'includes/footer.php'; ?&gt;</code></pre>
                    </div>

                    <h3>Template Variables</h3>
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>Variable</th>
                                <th>Purpose</th>
                                <th>Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>$pageTitle</code></td>
                                <td>HTML <code>&lt;title&gt;</code> and Open Graph title</td>
                                <td><code>'My Page'</code></td>
                            </tr>
                            <tr>
                                <td><code>$pageDescription</code></td>
                                <td>Meta description for SEO</td>
                                <td><code>'About this page.'</code></td>
                            </tr>
                            <tr>
                                <td><code>$currentLang</code></td>
                                <td>Language code (ISO 639-1)</td>
                                <td><code>'en'</code>, <code>'de'</code>, <code>'es'</code></td>
                            </tr>
                            <tr>
                                <td><code>$currentPage</code></td>
                                <td>Page key for navigation highlighting</td>
                                <td><code>'example'</code></td>
                            </tr>
                            <tr>
                                <td><code>$contentPage</code></td>
                                <td>JSON content file identifier</td>
                                <td><code>'en_example'</code></td>
                            </tr>
                            <tr>
                                <td><code>$basePath</code></td>
                                <td>Relative path to document root</td>
                                <td><code>'../'</code></td>
                            </tr>
                            <tr>
                                <td><code>$pageClass</code></td>
                                <td>Optional CSS class added to <code>&lt;body&gt;</code></td>
                                <td><code>'page-landing'</code></td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>URL Routing</h3>
                    <p>Primary language pages are accessible from root without a prefix. Secondary languages use <code>/{code}/</code> prefixes:</p>
                    <table class="docs-table">
                        <thead><tr><th>URL</th><th>File</th><th>Notes</th></tr></thead>
                        <tbody>
                            <tr><td><code>/</code></td><td><code>en/index.php</code></td><td>Root index.php includes primary lang homepage</td></tr>
                            <tr><td><code>/docs</code></td><td><code>en/docs.php</code></td><td>Primary lang page, accessible from root via route.php</td></tr>
                            <tr><td><code>/de/</code></td><td><code>de/index.php</code></td><td>Secondary language with prefix</td></tr>
                            <tr><td><code>/de/showcase</code></td><td><code>de/showcase.php</code></td><td>Secondary language subpage</td></tr>
                            <tr><td><code>/about</code></td><td><code>content/pages/en_about.json</code></td><td>JSON-only page (no PHP template needed)</td></tr>
                        </tbody>
                    </table>

                    <h3>Page Discovery</h3>
                    <p>Pages are auto-discovered from JSON files in <code>content/pages/</code>. Any file matching <code>{lang}_{slug}.json</code> appears in the admin dashboard automatically. Control which menus a page appears in via the <code>"nav"</code> field: <code>"nav": ["header", "footer"]</code> for both, <code>"nav": []</code> to hide from all.</p>

                    <p>For navigation ordering and language switching, edit <code>includes/nav-config.php</code>. For the quickest setup, use the scaffolding tool:</p>

                    <div class="docs-code">
                        <pre><code>php cli/make.php --slug=about --lang=en --title="About Us"</code></pre>
                    </div>

                    <h3>Adding Navigation Links</h3>
                    <p>Edit the <code>$NAV_ITEMS</code> array in <code>includes/nav-config.php</code>:</p>

                    <div class="docs-code">
                        <div class="docs-code-header">includes/nav-config.php</div>
                        <pre><code>$NAV_ITEMS = [
    'en' => [
        ['href' => '.',    'label' => 'Home',    'page' => 'home'],
        ['href' => 'docs', 'label' => 'Docs',    'page' => 'docs'],
    ],
    'de' => [
        ['href' => 'de/',  'label' => 'Startseite', 'page' => 'home'],
        ['href' => 'docs', 'label' => 'Dokumentation', 'page' => 'docs'],
    ],
    'es' => [
        ['href' => 'es/',  'label' => 'Inicio',        'page' => 'home'],
        ['href' => 'docs', 'label' => 'Documentación',  'page' => 'docs'],
    ],
];</code></pre>
                    </div>

                    <p>For language switching, add the mapping in <code>$PAGE_MAPPING</code> (same file):</p>

                    <div class="docs-code">
                        <div class="docs-code-header">includes/nav-config.php</div>
                        <pre><code>$PAGE_MAPPING = [
    'home' => ['en' => '.',    'de' => 'de/',    'es' => 'es/'],
    'docs' => ['en' => 'docs', 'de' => 'docs',   'es' => 'docs'],
];</code></pre>
                    </div>

                    <p>Primary language pages are accessible from root (no prefix). Secondary languages use <code>/{code}/</code> prefixes.</p>
                </section>

                <!-- JSON Content Format -->
                <section class="docs-section" id="json-format">
                    <h2>JSON Content Format</h2>
                    <p>Each page's content is stored as a JSON file in <code>content/pages/</code>. The filename follows the pattern <code>{lang}_{slug}.json</code>.</p>

                    <div class="docs-code">
                        <div class="docs-code-header">content/pages/en_example.json</div>
                        <pre><code>{
    "page": "en_example",
    "lang": "en",
    "lastModified": "2026-03-01T14:30:00+01:00",
    "sections": [
        {
            "id": "section_intro",
            "type": "text",
            "title": "Welcome",
            "titleTag": "h2",
            "content": "&lt;p&gt;Your HTML content here.&lt;/p&gt;"
        },
        {
            "id": "section_video",
            "type": "youtube",
            "videoId": "dQw4w9WgXcQ"
        }
    ]
}</code></pre>
                    </div>

                    <h3>Document Fields</h3>
                    <table class="docs-table">
                        <thead>
                            <tr><th>Field</th><th>Type</th><th>Description</th></tr>
                        </thead>
                        <tbody>
                            <tr><td><code>page</code></td><td>string</td><td>Page identifier (matches filename without <code>.json</code>)</td></tr>
                            <tr><td><code>lang</code></td><td>string</td><td>Language code</td></tr>
                            <tr><td><code>lastModified</code></td><td>string|null</td><td>ISO 8601 timestamp, set automatically on save</td></tr>
                            <tr><td><code>sections</code></td><td>array</td><td>Ordered list of content sections</td></tr>
                        </tbody>
                    </table>
                </section>

                <!-- Shared Content -->
                <section class="docs-section" id="shared-content">
                    <h2>Shared Content</h2>
                    <p>Nibbly uses two patterns for multilingual content:</p>
                    <table class="docs-table">
                        <thead><tr><th>Pattern</th><th>Use Case</th><th>Example</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><strong>Separate files</strong></td>
                                <td>Pages with per-language content</td>
                                <td><code>en_home.json</code>, <code>de_home.json</code>, <code>es_home.json</code></td>
                            </tr>
                            <tr>
                                <td><strong>Nested language objects</strong></td>
                                <td>Shared data with translations</td>
                                <td><code>events.json</code>, <code>footer.json</code></td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>Footer</h3>
                    <p>The footer uses nested language objects in <code>content/pages/footer.json</code>:</p>
                    <div class="docs-code">
                        <div class="docs-code-header">content/pages/footer.json</div>
                        <pre><code>{
    "tagline":  { "en": "Nibbly CMS", "de": "Nibbly CMS", "es": "Nibbly CMS" },
    "services": { "en": "Lightweight Content Management", "de": "Leichtgewichtiges Content Management", "es": "Gestión de contenidos ligera" },
    "contact":  { "phone": "+43 1 234 567", "email": "info@example.com" },
    "credit":   { "text": "Made by", "link": "https://...", "linkText": "..." }
}</code></pre>
                    </div>

                    <h3>Events</h3>
                    <p>Events are stored in <code>content/events.json</code> with nested language objects for translatable fields. Language-agnostic fields (<code>id</code>, <code>date</code>, <code>time</code>, <code>url</code>) exist only once:</p>
                    <div class="docs-code">
                        <div class="docs-code-header">content/events.json</div>
                        <pre><code>{
  "events": [
    {
      "id": "2026-04-07-pytorch-conference",
      "date": "2026-04-07",
      "time": "09:00",
      "url": "https://example.com",
      "title": {
        "en": "PyTorch Conference 2026",
        "de": "PyTorch Conference 2026",
        "es": "PyTorch Conference 2026"
      },
      "location": {
        "en": "Paris, France",
        "de": "Paris, Frankreich",
        "es": "París, Francia"
      },
      "description": { "en": "...", "de": "...", "es": "..." },
      "admission":   { "en": "...", "de": "...", "es": "..." }
    }
  ]
}</code></pre>
                    </div>

                    <h3>Event Functions</h3>
                    <table class="docs-table">
                        <thead><tr><th>Function</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code>loadEvents()</code></td><td>Loads all events, sorted by date (newest first)</td></tr>
                            <tr><td><code>getUpcomingEvents($limit)</code></td><td>Returns future events, sorted ascending</td></tr>
                            <tr><td><code>getPastEvents($limit)</code></td><td>Returns past events, sorted descending</td></tr>
                            <tr><td><code>getNextEvent()</code></td><td>Returns the single next upcoming event</td></tr>
                            <tr><td><code>renderEventList($events, $lang)</code></td><td>Renders an event list with admin edit/add buttons</td></tr>
                            <tr><td><code>renderEvent($event, $lang)</code></td><td>Renders a single event card</td></tr>
                        </tbody>
                    </table>
                </section>

                <!-- Section Types -->
                <section class="docs-section" id="section-types">
                    <h2>Section Types</h2>
                    <p>Nibbly supports 11 built-in section types for standard pages. Each type has its own renderer in <code>includes/block-renderers/</code>.</p>

                    <table class="docs-table">
                        <thead><tr><th>Type</th><th>Category</th><th>Key Fields</th></tr></thead>
                        <tbody>
                            <tr><td><code>text</code></td><td>content</td><td><code>title</code>, <code>content</code> (HTML), <code>titleTag</code>, <code>style</code></td></tr>
                            <tr><td><code>heading</code></td><td>content</td><td><code>text</code>, <code>level</code> (h1–h6), <code>subtitle</code></td></tr>
                            <tr><td><code>quote</code></td><td>content</td><td><code>text</code>, <code>attribution</code>, <code>style</code> (default/large)</td></tr>
                            <tr><td><code>list</code></td><td>content</td><td><code>title</code>, <code>style</code> (bullet/numbered), <code>content</code> (HTML)</td></tr>
                            <tr><td><code>image</code></td><td>media</td><td><code>src</code>, <code>alt</code>, <code>caption</code>, <code>width</code> (full/medium/small)</td></tr>
                            <tr><td><code>card</code></td><td>cards</td><td><code>title</code>, <code>content</code>, <code>image</code></td></tr>
                            <tr><td><code>youtube</code></td><td>media</td><td><code>videoId</code>, <code>title</code></td></tr>
                            <tr><td><code>soundcloud</code></td><td>media</td><td><code>trackId</code>, <code>title</code></td></tr>
                            <tr><td><code>audio</code></td><td>media</td><td><code>src</code>, <code>title</code></td></tr>
                            <tr><td><code>divider</code></td><td>layout</td><td><em>(none)</em></td></tr>
                            <tr><td><code>spacer</code></td><td>layout</td><td><code>height</code> (sm/md/lg/xl)</td></tr>
                        </tbody>
                    </table>

                    <h3>text</h3>
                    <p>Rich text with optional title and highlight styling.</p>
                    <div class="docs-code">
                        <pre><code>{
    "id": "s1",
    "type": "text",
    "title": "Section Title",
    "titleTag": "h2",
    "content": "&lt;p&gt;HTML content with &lt;strong&gt;bold&lt;/strong&gt;, &lt;em&gt;italic&lt;/em&gt;, links, and lists.&lt;/p&gt;",
    "style": "highlight"
}</code></pre>
                    </div>
                    <p>Allowed HTML tags: <code>&lt;p&gt; &lt;br&gt; &lt;strong&gt; &lt;b&gt; &lt;em&gt; &lt;i&gt; &lt;u&gt; &lt;a&gt; &lt;ul&gt; &lt;ol&gt; &lt;li&gt; &lt;h1&gt;–&lt;h6&gt; &lt;blockquote&gt; &lt;span&gt; &lt;div&gt;</code></p>

                    <h3>heading</h3>
                    <p>A standalone heading with optional subtitle.</p>
                    <div class="docs-code">
                        <pre><code>{ "id": "s2", "type": "heading", "text": "Welcome", "level": "h1", "subtitle": "A tagline" }</code></pre>
                    </div>

                    <h3>quote</h3>
                    <p>Blockquote with attribution. Use <code>"style": "large"</code> for a larger display.</p>
                    <div class="docs-code">
                        <pre><code>{ "id": "s3", "type": "quote", "text": "A wise quote.", "attribution": "Someone" }</code></pre>
                    </div>

                    <h3>list</h3>
                    <p>Bullet or numbered list. Content is raw HTML (<code>&lt;ul&gt;&lt;li&gt;...&lt;/li&gt;&lt;/ul&gt;</code>).</p>
                    <div class="docs-code">
                        <pre><code>{ "id": "s4", "type": "list", "title": "Features", "style": "bullet", "content": "&lt;ul&gt;&lt;li&gt;Item 1&lt;/li&gt;&lt;li&gt;Item 2&lt;/li&gt;&lt;/ul&gt;" }</code></pre>
                    </div>

                    <h3>image</h3>
                    <p>Image with optional caption and width control.</p>
                    <div class="docs-code">
                        <pre><code>{ "id": "s5", "type": "image", "src": "assets/images/photo.jpg", "alt": "Description", "caption": "Optional caption" }</code></pre>
                    </div>

                    <h3>card</h3>
                    <p>Image card with title and description. Consecutive cards are automatically arranged in a grid.</p>
                    <div class="docs-code">
                        <pre><code>{ "id": "s6", "type": "card", "title": "Card Title", "content": "Description.", "image": "assets/images/photo.jpg" }</code></pre>
                    </div>

                    <h3>youtube</h3>
                    <p>Embedded YouTube video (uses privacy-enhanced <code>youtube-nocookie.com</code>).</p>
                    <div class="docs-code">
                        <pre><code>{ "id": "s7", "type": "youtube", "videoId": "dQw4w9WgXcQ", "title": "Video Title" }</code></pre>
                    </div>

                    <h3>soundcloud &amp; audio</h3>
                    <p>SoundCloud embed or self-hosted audio with custom player.</p>
                    <div class="docs-code">
                        <pre><code>{ "id": "s8", "type": "soundcloud", "trackId": "1431378517", "title": "Track Name" }
{ "id": "s9", "type": "audio", "src": "assets/audio/track.mp3", "title": "Track Name" }</code></pre>
                    </div>

                    <h3>divider &amp; spacer</h3>
                    <p>Layout helpers. Divider renders a horizontal rule. Spacer adds vertical space.</p>
                    <div class="docs-code">
                        <pre><code>{ "id": "s10", "type": "divider" }
{ "id": "s11", "type": "spacer", "height": "lg" }</code></pre>
                    </div>
                </section>

                <!-- Inline Editing -->
                <section class="docs-section" id="inline-editing">
                    <h2>Inline Editing</h2>
                    <p>When an admin is logged in, every page becomes editable directly in the browser. The system works through HTML attributes and CSS classes that the content loader adds automatically.</p>

                    <h3>How It Activates</h3>
                    <p>The inline editor initializes when two meta tags are present (injected by <code>footer.php</code> for logged-in admins):</p>
                    <div class="docs-code">
                        <pre><code>&lt;meta name="csrf-token" content="..."&gt;
&lt;meta name="content-page" content="en_example"&gt;
&lt;link rel="stylesheet" href="css/inline-editor.css"&gt;
&lt;script src="js/inline-editor.js"&gt;&lt;/script&gt;</code></pre>
                    </div>

                    <h3>Editable Field Functions</h3>
                    <p>For custom layouts (like the landing page), use these functions instead of <code>renderAllSections()</code>:</p>
                    <table class="docs-table">
                        <thead><tr><th>Function</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code>editableText($page, $fieldKey, $default)</code></td><td>Inline-editable plain text field using dot notation</td></tr>
                            <tr><td><code>editableHtml($page, $fieldKey, $default)</code></td><td>Inline-editable rich HTML field</td></tr>
                            <tr><td><code>editableLink($page, $fieldKey, $text, $href, $class)</code></td><td>Editable link (text + href)</td></tr>
                            <tr><td><code>editableImage($page, $fieldKey, $src, $alt, $class)</code></td><td>Editable image (click to replace)</td></tr>
                            <tr><td><code>editableListAttrs($page, $listKey, $defaults)</code></td><td>Attributes for a repeatable list container</td></tr>
                            <tr><td><code>editableListItemAttrs($page, $listKey, $index)</code></td><td>Attributes for a single list item</td></tr>
                            <tr><td><code>editableListItems($page, $listKey)</code></td><td>Returns list items from JSON for iteration</td></tr>
                        </tbody>
                    </table>

                    <div class="docs-code">
                        <div class="docs-code-header">Usage example</div>
                        <pre><code>&lt;h1&gt;&lt;?php echo editableText('en_home', 'hero.title', 'Default Title'); ?&gt;&lt;/h1&gt;

&lt;?php echo editableLink('en_home', 'hero.cta1', 'Get Started', '#', 'btn btn-primary'); ?&gt;

&lt;?php foreach (editableListItems('en_home', 'features.items') as $i =&gt; $item): ?&gt;
    &lt;div&lt;?php echo editableListItemAttrs('en_home', 'features.items', $i); ?&gt;&gt;
        &lt;?php echo editableText('en_home', "features.items.$i.title", 'Feature'); ?&gt;
    &lt;/div&gt;
&lt;?php endforeach; ?&gt;</code></pre>
                    </div>

                    <h3>Data Attributes</h3>
                    <p>These attributes control what's editable and how:</p>
                    <table class="docs-table">
                        <thead><tr><th>Attribute</th><th>Element</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><code>data-content-page</code></td>
                                <td>Container</td>
                                <td>Identifies which JSON file this area belongs to</td>
                            </tr>
                            <tr>
                                <td><code>data-section-index</code></td>
                                <td><code>.editable-section</code></td>
                                <td>Zero-based position in the sections array</td>
                            </tr>
                            <tr>
                                <td><code>data-section-type</code></td>
                                <td><code>.editable-section</code></td>
                                <td>Section type (text, card, audio, ...)</td>
                            </tr>
                            <tr>
                                <td><code>data-section-id</code></td>
                                <td><code>.editable-section</code></td>
                                <td>Unique section identifier</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>Admin Bar</h3>
                    <p>When logged in, a fixed bar appears at the top of every page with edit mode toggle, undo/redo history, dashboard link, and logout. The body receives a <code>has-admin-bar</code> class that adds top padding.</p>

                    <h3>Drag &amp; Drop</h3>
                    <p>Sections can be reordered by dragging the handle that appears on hover. The inline editor sets <code>draggable="true"</code> on each section and handles the reordering via the API.</p>
                </section>

                <!-- Render Components -->
                <section class="docs-section" id="render-components">
                    <h2>Render Components</h2>
                    <p>Pre-built components for common patterns. Each reads from a specific JSON key and renders editable HTML. Use them in custom layout pages alongside <code>editableText()</code> fields.</p>

                    <table class="docs-table">
                        <thead><tr><th>Function</th><th>JSON Key</th><th>Item Fields</th></tr></thead>
                        <tbody>
                            <tr><td><code>renderFeatureGrid($page)</code></td><td><code>features.items</code></td><td><code>{icon, title, desc}</code></td></tr>
                            <tr><td><code>renderPricingTable($page)</code></td><td><code>pricing.plans</code></td><td><code>{name, price, period, desc, features, cta}</code></td></tr>
                            <tr><td><code>renderFaqAccordion($page)</code></td><td><code>faq.entries</code></td><td><code>{question, answer}</code></td></tr>
                            <tr><td><code>renderTeamGrid($page)</code></td><td><code>team.members</code></td><td><code>{name, role, bio, image}</code></td></tr>
                            <tr><td><code>renderGallery($page)</code></td><td><code>gallery.images</code></td><td><code>{src, alt, caption}</code></td></tr>
                            <tr><td><code>renderTimeline($page)</code></td><td><code>timeline.entries</code></td><td><code>{date, title, content}</code></td></tr>
                            <tr><td><code>renderStats($page)</code></td><td><code>stats.items</code></td><td><code>{value, label}</code></td></tr>
                            <tr><td><code>renderTestimonials($page)</code></td><td><code>testimonials.items</code></td><td><code>{text, author, role}</code></td></tr>
                            <tr><td><code>renderComparisonTable($page)</code></td><td><code>comparison.rows</code></td><td><code>{feature, us, them}</code></td></tr>
                            <tr><td><code>renderNewsList($limit, $lang)</code></td><td><code>content/news/*.json</code></td><td>News post cards</td></tr>
                        </tbody>
                    </table>

                    <p>List items in JSON must use <strong>numbered object keys</strong>, not arrays:</p>
                    <div class="docs-code">
                        <div class="docs-code-header">Correct format</div>
                        <pre><code>"features": {
    "heading": "Features",
    "items": {
        "0": { "icon": "zap", "title": "Fast", "desc": "No database." },
        "1": { "icon": "shield", "title": "Secure", "desc": "Minimal attack surface." }
    }
}</code></pre>
                    </div>

                    <h3>Editable Text List</h3>
                    <p><code>editableTextList($page, $listKey)</code> renders a list of editable HTML paragraphs with add/remove/reorder controls. Each paragraph gets inline editing with a floating toolbar.</p>
                    <div class="docs-code">
                        <pre><code>&lt;div class="about-text"&gt;
    &lt;?php echo editableTextList($_p, 'about.paragraphs'); ?&gt;
&lt;/div&gt;</code></pre>
                    </div>
                    <div class="docs-code">
                        <div class="docs-code-header">JSON</div>
                        <pre><code>"about": {
    "paragraphs": {
        "0": { "content": "First paragraph..." },
        "1": { "content": "Second paragraph with &lt;strong&gt;formatting&lt;/strong&gt;..." }
    }
}</code></pre>
                    </div>
                </section>

                <!-- Auto-Write -->
                <section class="docs-section" id="auto-write">
                    <h2>Auto-Write</h2>
                    <p>When an admin browses a page, every <code>editableText()</code>, <code>editableHtml()</code>, <code>editableImage()</code>, and <code>editableLink()</code> call checks whether its key exists in the JSON. If the key is missing, Nibbly <strong>automatically writes the fallback value to the JSON file</strong>.</p>

                    <p>This means you can focus on the PHP template — the JSON is populated on first admin page view. A toast notification tells the admin how many fields were auto-generated.</p>

                    <h3>What auto-writes</h3>
                    <table class="docs-table">
                        <thead><tr><th>Function</th><th>Auto-writes?</th><th>JSON format</th></tr></thead>
                        <tbody>
                            <tr><td><code>editableText()</code></td><td>Yes</td><td>String value</td></tr>
                            <tr><td><code>editableHtml()</code></td><td>Yes</td><td>String value</td></tr>
                            <tr><td><code>editableImage()</code></td><td>Yes</td><td><code>{src, alt}</code></td></tr>
                            <tr><td><code>editableLink()</code></td><td>Yes</td><td><code>{text, href}</code></td></tr>
                            <tr><td><code>editableListItems()</code></td><td>No</td><td>Returns <code>[]</code> if missing</td></tr>
                        </tbody>
                    </table>

                    <h3>When you must pre-populate JSON</h3>
                    <ul>
                        <li><strong>Standard pages with <code>sections[]</code></strong> — <code>renderAllSections()</code> needs the sections array</li>
                        <li><strong>Editable lists</strong> — list structure (with at least one item) must exist in JSON</li>
                        <li><strong>Content that should differ from the PHP fallback</strong> — edit the JSON directly</li>
                    </ul>
                </section>

                <!-- Template System -->
                <section class="docs-section" id="templates">
                    <h2>Template System</h2>
                    <p>Nibbly uses a simple include-based template system. Every page includes three files in order:</p>

                    <div class="docs-code">
                        <pre><code>$_includeBase = dirname(__DIR__) . '/';

include $_includeBase . 'includes/header.php';
include $_includeBase . 'includes/content-loader.php';

// ... your page content ...

include $_includeBase . 'includes/sidebar.php';
include $_includeBase . 'includes/footer.php';</code></pre>
                    </div>

                    <h3>header.php</h3>
                    <p>Outputs the complete HTML head, fixed header with logo and navigation, mobile navigation overlay, theme toggle (light/dark), and language selector. Auto-loads <code>admin/config.php</code> for language settings and <code>includes/nav-config.php</code> for navigation items.</p>

                    <h3>content-loader.php</h3>
                    <p>Provides the core rendering functions:</p>
                    <table class="docs-table">
                        <thead><tr><th>Function</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><code>renderAllSections($page)</code></td>
                                <td>Renders all sections for a page. Groups consecutive cards into a grid. Wraps in editable container for admins.</td>
                            </tr>
                            <tr>
                                <td><code>renderSection($section, $index, $editable)</code></td>
                                <td>Renders a single section based on its type.</td>
                            </tr>
                            <tr>
                                <td><code>loadContent($page)</code></td>
                                <td>Loads and parses the JSON file for a page.</td>
                            </tr>
                            <tr>
                                <td><code>sanitizeHtml($html)</code></td>
                                <td>Strips disallowed tags, event handlers, and dangerous URI schemes.</td>
                            </tr>
                            <tr>
                                <td><code>loadEvents()</code></td>
                                <td>Loads all events from <code>content/events.json</code>.</td>
                            </tr>
                            <tr>
                                <td><code>renderEventList($events, $lang)</code></td>
                                <td>Renders a list of event cards with admin controls.</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>footer.php</h3>
                    <p>Outputs the footer with editable fields, main JavaScript (scroll behavior, mobile nav, smooth scroll, reveal animations, contact form), audio player script, and — for logged-in admins — the inline editor.</p>
                </section>

                <!-- CLI Tools -->
                <section class="docs-section" id="cli-tools">
                    <h2>CLI Tools</h2>

                    <h3>Page Scaffolding</h3>
                    <p>Generate a new page with PHP template, JSON content file, and optional navigation registration:</p>
                    <div class="docs-code">
                        <pre><code>php cli/make.php --slug=about --lang=en --title="About Us"
php cli/make.php --slug=about --lang=en --type=custom --title="About Us"</code></pre>
                    </div>
                    <p>Options: <code>--type=standard|custom</code> (default: standard), <code>--force</code> (overwrite existing).</p>

                    <h3>HTML-to-Nibbly Converter</h3>
                    <p>Convert any static HTML page into an editable Nibbly template + JSON + CSS:</p>
                    <div class="docs-code">
                        <pre><code>php cli/convert.php landing.html --slug=home --lang=en --dry-run
php cli/convert.php about.html --slug=about --lang=en --force</code></pre>
                    </div>
                    <p>The converter detects sections, headings, text, images, links, and repeating patterns (cards, testimonials). It generates <code>editableText()</code>, <code>editableImage()</code>, <code>editableLink()</code>, and editable list calls. Inline styles are extracted to <code>css/page-{slug}.css</code>.</p>

                    <table class="docs-table">
                        <thead><tr><th>Option</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code>--slug=NAME</code></td><td>Page slug for URLs (default: from filename)</td></tr>
                            <tr><td><code>--lang=CODE</code></td><td>Language code (default: en)</td></tr>
                            <tr><td><code>--title=TEXT</code></td><td>Page title (default: from &lt;title&gt; or &lt;h1&gt;)</td></tr>
                            <tr><td><code>--dry-run</code></td><td>Preview output without writing files</td></tr>
                            <tr><td><code>--json-only</code></td><td>Only generate JSON, no PHP template</td></tr>
                            <tr><td><code>--no-css</code></td><td>Skip CSS extraction</td></tr>
                            <tr><td><code>--force</code></td><td>Overwrite existing files</td></tr>
                        </tbody>
                    </table>
                </section>

                <!-- CSS & Design System -->
                <section class="docs-section" id="css-system">
                    <h2>CSS &amp; Design System</h2>
                    <p>All styles use CSS custom properties defined in <code>:root</code>. Change the look of your site by modifying these variables in <code>css/style.css</code>:</p>

                    <h3>Colors</h3>
                    <div class="docs-code">
                        <pre><code>:root {
    --color-primary: #60c8cd;           /* Main brand color (türkis) */
    --color-primary-dark: #0d959c;      /* Hover/active states */
    --color-primary-light: #7df6fc;     /* Accents */
    --color-secondary: #61abcd;         /* Secondary blue */
    --color-text: #171717;              /* Body text */
    --color-text-secondary: #525252;    /* Muted text */
    --color-background: #ffffff;        /* Page background */
    --color-background-section: #f5f5f4;/* Alternating sections */
    --color-border: rgba(0, 0, 0, 0.08);/* Borders */
}</code></pre>
                    </div>

                    <h3>Typography</h3>
                    <div class="docs-code">
                        <pre><code>:root {
    --font-display: 'Quicksand', system-ui, sans-serif; /* Headings */
    --font-body: 'Quicksand', system-ui, sans-serif;    /* Body text */
    --font-mono: 'Geist Mono', 'SF Mono', monospace;    /* Code blocks */
}</code></pre>
                    </div>

                    <h3>Spacing &amp; Layout</h3>
                    <div class="docs-code">
                        <pre><code>:root {
    --spacing-xs: 0.5rem;    /* 8px  */
    --spacing-sm: 1rem;      /* 16px */
    --spacing-md: 2rem;      /* 32px */
    --spacing-lg: 4rem;      /* 64px */
    --spacing-xl: 6rem;      /* 96px */
    --header-height: 80px;
    --container-max: 1400px;    /* Full-width container */
    --container-narrow: 800px;  /* Content container */
}</code></pre>
                    </div>

                    <h3>Key CSS Classes</h3>
                    <table class="docs-table">
                        <thead><tr><th>Class</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr><td><code>.main-content</code></td><td>Main content area with header offset</td></tr>
                            <tr><td><code>.content-inner</code></td><td>Narrow centered container (800px)</td></tr>
                            <tr><td><code>.content-highlight</code></td><td>Highlighted background box for text sections</td></tr>
                            <tr><td><code>.cards-grid</code></td><td>Auto-layout grid for card sections</td></tr>
                            <tr><td><code>.reveal</code></td><td>Scroll-triggered fade-in animation</td></tr>
                            <tr><td><code>.stagger-reveal</code></td><td>Staggered child animations</td></tr>
                        </tbody>
                    </table>

                    <h3>Responsive Breakpoints</h3>
                    <p>Primary breakpoint at <strong>768px</strong>. The layout switches from multi-column to single-column below this width. A secondary breakpoint at <strong>1024px</strong> is used for the landing page grid.</p>
                </section>

                <!-- Showcase Page -->
                <section class="docs-section" id="showcase">
                    <h2>Showcase Page</h2>
                    <p>The showcase page demonstrates all content types and components Nibbly supports. It uses a custom layout with <code>editableText()</code> fields rather than <code>renderAllSections()</code>, and is available in all configured languages.</p>

                    <h3>Page Structure</h3>
                    <p>The showcase page consists of these components, top to bottom:</p>
                    <table class="docs-table">
                        <thead><tr><th>Component</th><th>Class</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><strong>Hero</strong></td>
                                <td><code>.showcase-hero</code></td>
                                <td>Two-column grid: editable text (label, title, intro) on the left, mascot image on the right. The image switches between light and dark variants based on <code>data-theme</code>.</td>
                            </tr>
                            <tr>
                                <td><strong>Jump Nav</strong></td>
                                <td><code>.showcase-jumpnav</code></td>
                                <td>Sticky navigation bar with a label and 10 numbered links (01–10). Items fly in pairwise from left/right with staggered delays, then pulse sequentially in brand color.</td>
                            </tr>
                            <tr>
                                <td><strong>Explainer</strong></td>
                                <td><code>.showcase-explainer</code></td>
                                <td>Two-column grid: description on the left, code window on the right. The code window slides in from the right with a randomized rotation (–12° to +12°, set via PHP <code>rand()</code>).</td>
                            </tr>
                            <tr>
                                <td><strong>Examples (×10)</strong></td>
                                <td><code>.showcase-example</code></td>
                                <td>Each example has an explainer section with a code window, followed by a live rendered component (calendar, FAQ, pricing table, etc.).</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>Dark Mode Images</h3>
                    <p>The hero mascot uses two <code>&lt;img&gt;</code> elements toggled via CSS based on the <code>data-theme</code> attribute (not <code>prefers-color-scheme</code>, since the theme is controlled by JavaScript):</p>
                    <div class="docs-code">
                        <pre><code>&lt;div class="showcase-hero__image"&gt;
    &lt;img src="images/nibbly-beaver-showcase.webp"
         class="showcase-hero__beaver showcase-hero__beaver--light"&gt;
    &lt;img src="images/nibbly-beaver-showcase-darkmode.webp"
         class="showcase-hero__beaver showcase-hero__beaver--dark"&gt;
&lt;/div&gt;</code></pre>
                    </div>

                    <h3>Jump Nav Animations</h3>
                    <p>The numbered items use two chained CSS animations:</p>
                    <ol>
                        <li><strong>Fly-in:</strong> Items arrive pairwise — the center pair (05 + 06) lands first, then 04 + 07, and so on outward to 01 + 10. Each item flies in from its respective side (left or right).</li>
                        <li><strong>Pulse:</strong> After all items have landed, they pulse sequentially from 01 → 10 — scaling up briefly and flashing in the brand color (<code>--color-primary-light</code> in dark mode, <code>--color-primary</code> in light mode).</li>
                    </ol>

                    <h3>Code Window Rotation</h3>
                    <p>Each explainer code window gets a random start rotation via a CSS custom property set by PHP:</p>
                    <div class="docs-code">
                        <pre><code>&lt;div class="showcase-explainer__code"
     style="--code-rotation: &lt;?php echo rand(-12, 12); ?&gt;deg"&gt;</code></pre>
                    </div>
                    <p>The CSS uses this variable as the initial rotation, animating to <code>0deg</code> when the section scrolls into view. This creates visual variety across the 10 examples.</p>
                </section>

                <!-- Backups -->
                <section class="docs-section" id="backups">
                    <h2>Backups</h2>
                    <p>Nibbly has three backup layers: automatic per-page JSON history, manual full-site ZIP snapshots, and scheduled full-site backups that can be copied to external storage.</p>

                    <h3>Per-Page History</h3>
                    <p>Every save in the inline editor or dashboard stores the previous page JSON in <code>backups/</code>. This is meant for quick content rollback: restore one page without touching templates, images, or the rest of the site.</p>

                    <h3>Manual Full-Site Backups</h3>
                    <p>The dashboard's <strong>Backup → Create Backup</strong> button creates a ZIP archive of the site, starts a download, and keeps the ZIP in the server-side backup pool. Manual ZIPs are tagged as <code>manual</code>, so scheduled retention and storage limits do not delete them automatically.</p>

                    <h3>Scheduled Backups via Cron</h3>
                    <p>Scheduled backups are run by <code>cli/backup.php</code>. The dashboard stores the policy in <code>content/settings.json</code>; the CLI reads that policy, creates a full-site ZIP, prunes old ZIPs, and uploads enabled remote targets.</p>
                    <p>In Plesk, create a daily scheduled task at 03:00 and choose <strong>Run a PHP script</strong>. Use the script path and arguments below. This avoids relying on a <code>php</code> command in Plesk's <code>PATH</code>. In cPanel or shell cron, use the full command as the job body.</p>
                    <div class="docs-code">
                        <pre><code>Script path: /path/to/site/cli/backup.php
Arguments:   --action=run
Shell cron:  php /path/to/site/cli/backup.php --action=run</code></pre>
                    </div>
                    <p>The default retention follows a grandfather-father-son pattern: daily, weekly, monthly, and yearly tiers. A hard storage limit can additionally evict the oldest non-manual ZIPs when the backup pool grows too large.</p>

                    <h3>Remote Backup Targets</h3>
                    <p>After a scheduled ZIP is created locally, Nibbly can upload it to external storage. New ZIP files include the site domain in the filename, and remote uploads are placed in a per-site subfolder below the configured remote path so multiple sites can use the same storage account without mixing files. Supported target types:</p>
                    <ul>
                        <li><strong>Dropbox</strong> — browser-based OAuth connection with refresh token support</li>
                        <li><strong>Google Drive</strong> — OAuth connection using the Drive <code>drive.file</code> scope</li>
                        <li><strong>Microsoft OneDrive</strong> — OAuth connection through Microsoft Graph</li>
                        <li><strong>SFTP / SCP</strong> — server-to-server upload for agencies, VPS, NAS, and classic hosting</li>
                        <li><strong>S3-Compatible Storage</strong> — AWS S3, Hetzner Object Storage, Wasabi, MinIO, DigitalOcean Spaces, and similar providers</li>
                        <li><strong>WebDAV</strong> — Nextcloud, ownCloud, NAS, and providers with WebDAV endpoints</li>
                    </ul>
                    <p>Dropbox, Google Drive, and OneDrive use a browser login flow from the dashboard. The provider app/client ID is entered once, the admin clicks <strong>Connect</strong>, and the OAuth callback stores a refresh token so future cron runs can upload without another login.</p>

                    <h3>CLI Reference</h3>
                    <table class="docs-table">
                        <thead><tr><th>Command</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr><td><code>php cli/backup.php --action=run</code></td><td>Create one ZIP, prune old ZIPs, upload enabled remote targets</td></tr>
                            <tr><td><code>php cli/backup.php --action=run --skip-remote</code></td><td>Create and prune locally, without remote upload</td></tr>
                            <tr><td><code>php cli/backup.php --action=prune</code></td><td>Apply retention/storage limits without creating a new ZIP</td></tr>
                            <tr><td><code>php cli/backup.php --action=status</code></td><td>Print local and remote backup status</td></tr>
                            <tr><td><code>php cli/backup.php --action=list</code></td><td>List stored ZIP backups</td></tr>
                            <tr><td><code>php cli/backup.php --action=upload-remote --file=example.com-backup-...</code></td><td>Retry remote upload for an existing ZIP</td></tr>
                        </tbody>
                    </table>

                    <h3>Security Notes</h3>
                    <ul>
                        <li>The backup lock file prevents concurrent runs.</li>
                        <li>Remote secrets are masked in the dashboard.</li>
                        <li>When <code>content/settings.json</code> is written into a ZIP, remote tokens and passwords are scrubbed from the archived copy.</li>
                        <li>Remote upload failures are reported but do not delete the local ZIP.</li>
                    </ul>
                </section>

                <!-- Admin Dashboard -->
                <section class="docs-section" id="admin">
                    <h2>Admin Dashboard</h2>
                    <p>Access the admin panel by double-clicking the year in the footer copyright, or go directly to <code>/admin/</code>.</p>

                    <h3>Hidden Admin Access via Footer</h3>
                    <p>Nibbly hides the login behind a double-click on a designated footer element — by default the year inside the copyright line. The element is marked up via the <code>[id="adminAccess"]…[/id]</code> shortcode in <code>content/pages/footer.json</code>:</p>
                    <div class="docs-code">
                        <div class="docs-code-header">content/pages/footer.json</div>
                        <pre><code>"copyright": "&amp;copy; [id=\"adminAccess\"]2026[/id] Your Company"</code></pre>
                    </div>
                    <p>Anything between the shortcode tags becomes a <code>&lt;span id="adminAccess"&gt;</code>. The footer JavaScript registers a <code>dblclick</code> handler on that span; double-clicking sends the user to <code>/admin/?redirect=&lt;current-path&gt;</code>. After login, the <em>Login</em> setting determines what happens next:</p>
                    <table class="docs-table">
                        <thead><tr><th>Setting</th><th>Behaviour</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><code>auto</code> <span class="docs-default">(default)</span></td>
                                <td>The user is redirected back to the page they came from, with the inline editor ready to use.</td>
                            </tr>
                            <tr>
                                <td><code>dashboard</code></td>
                                <td>The user always lands in the dashboard. A one-shot info banner offers a link back to the source page.</td>
                            </tr>
                        </tbody>
                    </table>
                    <p>Change the mode in <strong>Dashboard → Settings → Login</strong>. The redirect URL is sanitised by <code>validateRedirectUrl()</code>: it must be same-origin and may not point inside <code>/admin/</code>, so the shortcode cannot be abused for open redirects.</p>

                    <h3>First-Time Setup</h3>
                    <p>On first visit, the setup wizard (<code>admin/setup.php</code>) creates your <code>config.php</code>. It asks for site name, primary language, optional secondary language, admin username, and password. Once configured, the wizard deactivates itself.</p>

                    <h3>Dashboard Tabs</h3>
                    <table class="docs-table">
                        <thead><tr><th>Tab</th><th>Function</th></tr></thead>
                        <tbody>
                            <tr><td><strong>Content Editor</strong></td><td>Select language and page, edit sections, save with automatic backups, restore previous versions</td></tr>
                            <tr><td><strong>News</strong></td><td>Create, edit, publish, and unpublish news posts</td></tr>
                            <tr><td><strong>Events</strong></td><td>Manage events with multilingual fields</td></tr>
                            <tr><td><strong>Messages</strong></td><td>View, read, and delete contact form submissions</td></tr>
                            <tr><td><strong>Settings</strong></td><td>Branding, theme, language, <strong>login behaviour</strong>, access controls, email, menus, users, password, and danger zone</td></tr>
                        </tbody>
                    </table>

                    <h3>API Endpoints</h3>
                    <p>The dashboard and inline editor communicate through <code>admin/api.php</code>. All POST requests require a CSRF token.</p>
                    <table class="docs-table">
                        <thead><tr><th>Action</th><th>Method</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code>load</code></td><td>GET</td><td>Load page content</td></tr>
                            <tr><td><code>save</code></td><td>POST</td><td>Save page (creates backup)</td></tr>
                            <tr><td><code>backups</code></td><td>GET</td><td>List available backups</td></tr>
                            <tr><td><code>restore</code></td><td>POST</td><td>Restore a backup</td></tr>
                            <tr><td><code>backup-status</code></td><td>GET</td><td>Load scheduled backup status, retention, and remote targets</td></tr>
                            <tr><td><code>backup-update-settings</code></td><td>POST</td><td>Save scheduled backup and remote target settings</td></tr>
                            <tr><td><code>backup-test-remote</code></td><td>POST</td><td>Test a remote backup target</td></tr>
                            <tr><td><code>backup-*-oauth-start</code></td><td>GET</td><td>Start Dropbox, Google Drive, or OneDrive OAuth connection</td></tr>
                            <tr><td><code>load-events</code></td><td>GET</td><td>Load all events</td></tr>
                            <tr><td><code>save-event</code></td><td>POST</td><td>Create or update an event</td></tr>
                            <tr><td><code>delete-event</code></td><td>POST</td><td>Delete an event</td></tr>
                            <tr><td><code>load-event</code></td><td>GET</td><td>Load a single event by ID</td></tr>
                            <tr><td><code>upload-image</code></td><td>POST</td><td>Upload image file</td></tr>
                            <tr><td><code>upload-audio</code></td><td>POST</td><td>Upload audio file</td></tr>
                            <tr><td><code>change-password</code></td><td>POST</td><td>Change admin password</td></tr>
                        </tbody>
                    </table>
                </section>

                <!-- Accessibility -->
                <section class="docs-section" id="accessibility">
                    <h2>Accessibility Best Practices</h2>
                    <p>Nibbly includes accessibility foundations for public websites and admin editing workflows. The goal is not to replace a project-specific audit, but to make the default output easier to navigate with keyboard, screen readers, reduced-motion settings, and robust semantics.</p>

                    <h3>What Core Provides</h3>
                    <ul>
                        <li>A skip link that jumps to <code>#main-content</code>.</li>
                        <li>Landmark-friendly header, main content, footer, and labelled navigation areas.</li>
                        <li>Mobile menu state via <code>aria-expanded</code>, <code>aria-controls</code>, and <code>aria-hidden</code>.</li>
                        <li>Visible focus styles and reduced-motion handling through <code>prefers-reduced-motion</code>.</li>
                        <li>Accessible editor and media dialogs with dialog roles, labelled titles, focus handling, and live status messages.</li>
                        <li>SEO health checks that also flag images without alt text.</li>
                    </ul>

                    <h3>Template Best Practices</h3>
                    <p>When creating custom layouts, keep the Core accessibility hooks intact:</p>
                    <div class="docs-code">
                        <div class="docs-code-header">custom template</div>
                        <pre><code>&lt;main class="main-content" id="main-content"&gt;
    &lt;div class="content-inner"&gt;
        &lt;h1&gt;&lt;?php echo editableText($_p, 'hero.title', 'Page title'); ?&gt;&lt;/h1&gt;
        &lt;?php echo renderAllSections($_p); ?&gt;
    &lt;/div&gt;
&lt;/main&gt;</code></pre>
                    </div>

                    <ul>
                        <li>Use one visible H1 per page, then H2/H3 levels in logical order.</li>
                        <li>Write useful alt text for informative images; use empty alt text only for decorative images.</li>
                        <li>Use real <code>&lt;button&gt;</code> elements for actions and real links for navigation.</li>
                        <li>Keep labels connected to form fields and place error/help text close to the field.</li>
                        <li>Do not remove focus outlines unless you provide an equally visible replacement.</li>
                        <li>Respect reduced-motion preferences for page-specific animations.</li>
                        <li>Test important pages with keyboard-only navigation before launch.</li>
                    </ul>
                </section>

                <!-- Access Controls -->
                <section class="docs-section" id="access-controls">
                    <h2>Access Controls</h2>
                    <p>Nibbly can temporarily lock a whole site for maintenance and protect individual pages with a password. Both features are flat-file friendly: configuration stays in JSON, passwords and bypass secrets are hashed, and no database is required.</p>

                    <h3>Maintenance Mode</h3>
                    <p>Enable maintenance mode in <strong>Dashboard → Settings → Access</strong>. The visitor-facing lock page can use one of three modes: regular maintenance, back-soon messaging, or launch countdown. Each mode supports custom title/text, an optional unavailable-until time, and an optional countdown.</p>
                    <p>Logged-in admins and editors bypass maintenance mode automatically, so they can keep editing and previewing the site. For clients or reviewers, configure a bypass parameter and secret key. Example: if the parameter is <code>preview</code>, opening <code>/?preview=secret-value</code> grants access for the current browser session.</p>

                    <div class="docs-code">
                        <div class="docs-code-header">content/settings.json</div>
                        <pre><code>"access": {
  "maintenance": {
    "enabled": true,
    "mode": "maintenance",
    "title": "Maintenance",
    "text": "We will be back online shortly.",
    "until": "2026-05-20T12:00",
    "showCountdown": true,
    "bypassParam": "preview",
    "bypassKeyHash": "$2y$..."
  }
}</code></pre>
                    </div>

                    <h3>Password-Protected Pages</h3>
                    <p>Open a page in the Content Editor and use the <strong>Access</strong> card to set its visibility to <strong>Private</strong>. Add a password and, optionally, customize the title and text shown on the password page.</p>
                    <p>Private pages are enforced server-side. Knowing the URL is not enough to view the content: the page JSON is checked before rendering, successful access is stored per page in the session, and logged-in admins/editors bypass the lock.</p>

                    <div class="docs-code">
                        <div class="docs-code-header">content/pages/en_private-page.json</div>
                        <pre><code>"visibility": {
  "status": "private",
  "title": "Protected page",
  "text": "Enter the password to continue.",
  "passwordHash": "$2y$..."
}</code></pre>
                    </div>

                    <h3>Security Behaviour</h3>
                    <ul>
                        <li>Maintenance mode returns a <code>503</code> response and can send <code>Retry-After</code> when an end time is set.</li>
                        <li>Private pages return a password form until the correct password is submitted.</li>
                        <li>Lock pages use <code>noindex</code> headers so temporary or protected states are not promoted to crawlers.</li>
                        <li>Static assets, admin routes, API routes, <code>robots.txt</code>, and <code>sitemap.xml</code> remain reachable while maintenance mode is active.</li>
                        <li>Do not edit JSON with plaintext secrets. Use the dashboard or store hashes created with <code>password_hash()</code>.</li>
                    </ul>
                </section>

                <!-- Local Development -->
                <section class="docs-section" id="local-dev">
                    <h2>Local Development</h2>
                    <p>For local development, use PHP's built-in server with <code>router.php</code>:</p>

                    <div class="docs-code">
                        <pre><code>php -S localhost:3000 router.php</code></pre>
                    </div>

                    <p>Both <code>router.php</code> (dev) and <code>route.php</code> (Apache production) read <code>SITE_LANG_DEFAULT</code> from <code>admin/config.php</code> — no hardcoded language anywhere. Changing the primary language in config updates all routing immediately.</p>

                    <h3>How Routing Works</h3>
                    <p>On Apache, <code>.htaccess</code> handles security rules and serves static files directly. Everything else goes to <code>route.php</code>, which handles:</p>
                    <ul>
                        <li>Primary language root access: <code>/about</code> → <code>en/about.php</code> or JSON</li>
                        <li>Language-prefixed pages: <code>/de/beispiel</code> → <code>de/beispiel.php</code> or JSON</li>
                        <li>News posts: <code>/news/slug</code> and <code>/en/news/slug</code></li>
                        <li>JSON-only pages served via <code>includes/page.php</code> (no PHP template needed)</li>
                        <li>404 fallback</li>
                    </ul>
                    <p>The dev server <code>router.php</code> implements the same logic, plus static file serving and access control for <code>/content/</code> and <code>/backups/</code>.</p>
                </section>

                <!-- Bot-Protected Forms -->
                <section class="docs-section" id="protected-forms">
                    <h2>Bot-Protected Forms</h2>
                    <p>Nibbly's public contact forms are protected without Google reCAPTCHA or other third-party tracking services. The protection is designed for simple PHP hosting and flat-file projects: no database, no external account, no consent banner just for spam prevention.</p>

                    <h3>How It Works</h3>
                    <ul>
                        <li><strong>Lazy-loaded form HTML</strong> — the initial page only contains a placeholder; the real form is fetched from <code>api/form.php</code> after a short delay.</li>
                        <li><strong>Server-generated one-time tokens</strong> — every rendered form receives a fresh token stored server-side in <code>content/form-tokens.json</code>.</li>
                        <li><strong>Minimum submit time</strong> — forms submitted immediately after token creation are rejected, which blocks many automated direct POST attempts.</li>
                        <li><strong>Honeypot field</strong> — a visually hidden field catches bots that fill every input.</li>
                        <li><strong>Rate limiting</strong> — repeated submissions and failed checks are throttled via hashed client keys in <code>content/form-rate-limit.json</code>.</li>
                        <li><strong>Conservative content checks</strong> — obvious link spam is rejected before it reaches the message inbox.</li>
                    </ul>

                    <h3>Using Protection Fields</h3>
                    <p>For custom forms, add the protection helper inside the form and validate the same form id in the submit endpoint:</p>
                    <div class="docs-code">
                        <pre><code>&lt;?php require_once __DIR__ . '/../includes/form-protection.php'; ?&gt;

&lt;form class="contact-form" action="&lt;?php echo $basePath; ?&gt;api/contact.php" method="post"&gt;
    &lt;?php echo nibblyFormProtectionFields('contact'); ?&gt;
    &lt;!-- visible form fields --&gt;
&lt;/form&gt;</code></pre>
                    </div>

                    <h3>Lazy-Loading a Form</h3>
                    <p>The built-in contact forms use lazy loading automatically. For additional whitelisted forms, render only a placeholder in the page template:</p>
                    <div class="docs-code">
                        <pre><code>&lt;?php
echo nibblyLazyFormPlaceholder('contact', [
    'basePath' =&gt; $basePath,
    'params' =&gt; ['lang' =&gt; $currentLang, 'basePath' =&gt; $basePath],
]);
?&gt;</code></pre>
                    </div>

                    <p>The lazy endpoint intentionally only renders known forms. If a project adds a new public form, register it deliberately in <code>api/form.php</code> instead of including arbitrary PHP files from request parameters.</p>
                </section>

                <!-- Security -->
                <section class="docs-section" id="security">
                    <h2>Security</h2>

                    <h3>Authentication</h3>
                    <ul>
                        <li>Passwords stored as <strong>bcrypt hashes</strong> (<code>password_hash()</code> with <code>PASSWORD_DEFAULT</code>)</li>
                        <li>Sessions use <code>HttpOnly</code> + <code>SameSite=Strict</code> cookies</li>
                        <li>Session timeout after 1 hour (configurable)</li>
                        <li>CSRF tokens on all POST requests</li>
                    </ul>

                    <h3>Brute Force Protection</h3>
                    <ul>
                        <li>IP-based tracking (SHA-256 hashed, stored in <code>content/login_attempts.json</code>)</li>
                        <li>3 free attempts, then progressive delay (+15s per attempt)</li>
                        <li>Hard lockout after 20 failed attempts (24 hours)</li>
                        <li>Countdown timer shown to user</li>
                    </ul>

                    <h3>Content Protection</h3>
                    <ul>
                        <li>HTML sanitization strips event handlers, dangerous URI schemes (<code>javascript:</code>, <code>data:</code>, <code>vbscript:</code>), and disallowed tags</li>
                        <li><code>.htaccess</code> blocks direct access to <code>content/</code>, <code>backups/</code>, and trash directories</li>
                        <li>Config files protected via <code>FilesMatch</code> directive</li>
                        <li>File upload validation: MIME type checking, size limits, extension whitelist</li>
                        <li>Path traversal prevention on all file operations</li>
                    </ul>

                    <h3>Password Requirements</h3>
                    <p>Minimum 8 characters with at least one uppercase letter, one lowercase letter, one digit, and one special character. A warning banner appears after login if the current password doesn't meet these requirements.</p>
                </section>

            </div>
        </div>
    </main>

    <script>
    // Docs sidebar active link tracking
    (function() {
        var sections = document.querySelectorAll('.docs-section');
        var navLinks = document.querySelectorAll('.docs-nav a');
        var sidebar = document.getElementById('docsSidebar');

        if (!sections.length || !navLinks.length) return;

        // Smooth scroll for sidebar links
        navLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                var targetId = this.getAttribute('href');
                var target = document.querySelector(targetId);
                if (target) {
                    e.preventDefault();
                    var headerHeight = document.getElementById('siteHeader').offsetHeight + 20;
                    var top = target.getBoundingClientRect().top + window.scrollY - headerHeight;
                    window.scrollTo({ top: top, behavior: 'smooth' });
                }
            });
        });

        // Highlight current section on scroll
        var ticking = false;
        window.addEventListener('scroll', function() {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    var headerHeight = document.getElementById('siteHeader').offsetHeight + 40;
                    var current = '';

                    sections.forEach(function(section) {
                        var top = section.offsetTop - headerHeight;
                        if (window.scrollY >= top) {
                            current = section.getAttribute('id');
                        }
                    });

                    navLinks.forEach(function(link) {
                        link.classList.remove('active');
                        if (link.getAttribute('href') === '#' + current) {
                            link.classList.add('active');
                        }
                    });

                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    })();
    </script>

<?php include $_includeBase . 'includes/sidebar.php'; ?>
<?php include $_includeBase . 'includes/footer.php'; ?>
