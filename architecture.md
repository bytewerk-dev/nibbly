# Nibbly CMS - Architecture Reference

## System Overview

Nibbly is a flat-file CMS built on PHP with no database dependency. Content is stored as JSON files, pages are rendered via PHP templates, and the admin interface provides an inline editor for on-page editing plus a dashboard for page management.

### Request Flow

1. **Request arrives** at `router.php` (dev server) or `.htaccess` (Apache)
2. **Static files** (CSS, JS, images) are served directly
3. **PHP templates**: if `{lang}/{path}.php` exists, it is included directly
4. **JSON pages**: if the matching `content/pages/{lang}_{encoded-path}.json`
   exists, the front controller `includes/page.php` renders it using
   `renderAllSections()`. `/` is encoded as `__`, so
   `/products/vitamin-d` maps to `en_products__vitamin-d.json`.
5. **News posts**: URLs like `/en/news/my-post` route to `{lang}/news-post.php`
6. **404**: falls through to `404.php`

### Key Directories

```
admin/           Admin panel (login, dashboard, API, setup wizard)
content/
  pages/         Page JSON files ({lang}_{slug}.json, footer.json)
  forms/         Public form definitions
  news/          News post JSON files ({slug}.json)
  settings.json  Site-wide settings (favicon, theme, etc.)
  events.json    Events data
css/
  style.css      Base styles + CSS custom properties
  components.css Render component styles (pricing, FAQ, etc.)
  fonts.css      Custom font definitions (optional)
includes/
  header.php     HTML head + navigation + language switcher
  footer.php     Footer + scripts + inline editor bootstrap
  access-guard.php  Maintenance mode, preview bypass, and private page enforcement
  content-loader.php  Template API (editable fields, render components)
  forms.php            JSON-backed public forms
  ai/               AI provider gateway, limits, usage, audit, generated images
  block-types.php     Block type registry
  block-renderers/    One PHP file per block type
  nav-config.php      Navigation items + page mapping
  page.php            Front controller for JSON-only pages
  contact-form.php    Contact form partial
  sidebar.php         Sidebar partial
js/
  inline-editor.js    Inline editing system
  audio-player.js     Custom audio player
  faq-accordion.js    FAQ toggle behavior
router.php       Dev server router (replaces .htaccess)
examples/        Example templates and content files
```

## Content File Format

### Page JSON (`content/pages/{lang}_{slug}.json`)

Standard pages use a `sections` array:

```json
{
  "page": "en_about",
  "lang": "en",
  "title": "About Us",
  "description": "Meta description for SEO.",
  "lastModified": "2026-03-23T12:00:00",
  "sections": [
    {
      "id": "section_1",
      "type": "heading",
      "text": "About Us",
      "level": "h1"
    },
    {
      "id": "section_2",
      "type": "text",
      "title": "Our Story",
      "content": "<p>HTML content here.</p>"
    }
  ]
}
```

Custom layout pages use arbitrary nested keys instead of `sections`:

```json
{
  "page": "en_home",
  "lang": "en",
  "title": "Home",
  "hero": {
    "title": "Welcome",
    "subtitle": "Tagline here.",
    "cta": { "text": "Get Started", "href": "#features" },
    "image": { "src": "assets/images/hero.webp", "alt": "Hero" }
  },
  "features": {
    "heading": "Features",
    "items": {
      "0": { "icon": "zap", "title": "Fast", "desc": "No database." },
      "1": { "icon": "shield", "title": "Secure", "desc": "Minimal attack surface." }
    }
  }
}
```

List items are stored as numbered objects (`"0": {...}, "1": {...}`) for dot-notation compatibility with the inline editor.

### Data-First Principle & Auto-Generation

The JSON file is the single source of truth. Best practice is to populate JSON with all content before referencing it in PHP templates.

However, **missing fields are auto-generated**: when an admin visits a page, any `editableText`, `editableHtml`, `editableLink`, or `editableImage` call whose key doesn't exist in JSON will automatically create the key using the PHP fallback value. This means an AI agent can write PHP templates freely and the JSON structure wires itself up on first admin visit. The auto-generated values appear in both the Visual Editor and the Content Editor immediately.

### News Post JSON (`content/news/{slug}.json`)

```json
{
  "slug": "my-post",
  "lang": "en",
  "title": "Post Title",
  "excerpt": "Short description.",
  "date": "2026-03-23",
  "author": "Author Name",
  "image": "assets/images/post-cover.webp",
  "hidden": false,
  "sections": [
    { "id": "s1", "type": "text", "title": "", "content": "<p>Post body.</p>" }
  ]
}
```

### Footer JSON (`content/pages/footer.json`)

```json
{
  "tagline": { "en": "Built with Nibbly.", "de": "Erstellt mit Nibbly." },
  "services": { "en": "Web Design & Development", "de": "Webdesign & Entwicklung" },
  "claim": { "en": "Your claim here.", "de": "Ihr Claim hier." },
  "contact": { "phone": "+1 234 567", "email": "info@example.com" },
  "contactHeading": { "en": "Contact", "de": "Kontakt" },
  "legalHeading": { "en": "Legal", "de": "Rechtliches" },
  "legalLinks": {
    "privacy": {
      "text": { "en": "Privacy Policy", "de": "Datenschutz" },
      "href": { "en": "privacy", "de": "de/datenschutz" }
    },
    "imprint": {
      "text": { "en": "Legal Notice", "de": "Impressum" },
      "href": { "en": "legal-notice", "de": "de/impressum" }
    }
  },
  "copyright": "&copy; [id=\"adminAccess\"]2026[/id] Your Company",
  "credit": { "text": "Built with", "link": "https://example.com", "linkText": "Nibbly" }
}
```

The `copyright` field supports shortcode syntax: `[id="foo"]content[/id]` renders as `<span id="foo">content</span>`. The shortcode is the official way to wire up the **hidden admin-access trigger** — see _Hidden Admin Access_ below.

### Hidden Admin Access (footer double-click)

The default footer markup tags the year inside the copyright line with `id="adminAccess"` via the shortcode shown above. `includes/footer.php` registers a `dblclick` handler on that element; double-clicking sends the user to `/admin/?redirect=<current-path>`. This gives admins a discreet entry point to the login page from any public page.

After successful login the behaviour is controlled by the `general.frontendLoginRedirect` site setting (configurable under **Dashboard → Settings → Login**):

| Mode | Behaviour |
| --- | --- |
| `auto` (default) | The user is sent back to the page they came from, with the inline editor ready to use. |
| `dashboard` | The user always lands in the dashboard. A one-shot info banner offers a link back to the source page. |

The `?redirect=…` parameter is sanitised by `validateRedirectUrl()` in `admin/index.php` — it must be same-origin and may not point inside `/admin/`, so the shortcode cannot be used for open redirects.

To move the trigger somewhere else (e.g. the tagline or a hidden element), simply place the `[id="adminAccess"]…[/id]` shortcode around any other footer text. Removing the shortcode disables the hidden access entirely; admins must then visit `/admin/` directly.

### Form JSON (`content/forms/{id}.json`)

Public forms can be stored as JSON definitions. This keeps the no-database
model intact while giving admins a limited, safe editor for existing forms under
**Dashboard -> Settings -> Forms**.

```json
{
  "id": "contact",
  "label": "Kontaktformular",
  "description": "",
  "enabled": true,
  "submit": {
    "store": true,
    "email": true,
    "subject": "Kontaktanfrage: {name}",
    "successText": "Vielen Dank für deine Nachricht."
  },
  "fields": [
    { "key": "name", "type": "text", "label": "Name", "required": true, "width": 6 },
    { "key": "email", "type": "email", "label": "E-Mail", "required": true, "width": 6 },
    { "key": "phone", "type": "tel", "label": "Telefon", "required": false, "width": 12 },
    { "key": "message", "type": "textarea", "label": "Nachricht", "required": true, "width": 12 }
  ]
}
```

`id` is normalized to lowercase letters, numbers, dashes, and underscores and is
used for `content/forms/{id}.json`, lazy rendering, CSRF-style form tokens, and
mail metadata. Supported `type` values are `text`, `email`, `tel`, `textarea`,
`select`, `radio`, `checkbox`, `date`, `time`, `hidden`, `heading`, and `note`.
Supported `width` values are `3`, `4`, `6`, `8`, and `12` on a 12-column grid.
`select` and `radio` fields use `options[]` entries with `value` and `label`.

`submit.store=false` skips local inbox storage. `submit.email=false` skips email
notification even if SMTP/sendmail is configured. `submit.subject` supports
simple placeholders such as `{form}`, `{name}`, and `{email}`.

### Settings JSON (`content/settings.json`)

Generated by the setup wizard. Used by admin login, dashboard, header, footer, SEO helpers, and frontend access controls for branding, theme, login behaviour, maintenance mode, and privacy options.

```json
{
  "branding": {
    "logo": "",
    "name": "My Site",
    "showBranding": true
  },
  "theme": {
    "adminTheme": "light",
    "primaryColor": "#2563eb",
    "accentColor": "#60a5fa"
  },
  "general": {
    "adminLanguage": "",
    "frontendLoginRedirect": "auto"
  },
  "access": {
    "maintenance": {
      "enabled": false,
      "mode": "maintenance",
      "title": "Maintenance",
      "text": "We will be back online shortly.",
      "until": "",
      "showCountdown": false,
      "bypassParam": "preview",
      "bypassKeyHash": ""
    },
    "privacy": {
      "obfuscateEmails": false
    }
  }
}
```

Optional top-level keys: `favicon` (path to favicon file, default: `assets/images/favicon.svg`), `favicon_png` (PNG fallback for SVG favicons, also used as `apple-touch-icon`).

The `theme.adminTheme` value can be `"light"`, `"dark"`, or `"system"`. `primaryColor` and `accentColor` override `--nb-primary` and `--nb-brand` in the admin panel CSS.

`general.frontendLoginRedirect` controls the post-login redirect. `"auto"` returns the user to the page from which they triggered the login (via the footer double-click); `"dashboard"` always lands in the dashboard. See _Hidden Admin Access_ above for details.

`login` controls admin login page presentation. `brandAsset` is `none`, `favicon`, or `logo`; `image` is an optional `/assets/images/...` path; `imageLayout` is `none`, `background`, `left`, or `right`; `overlayColor` and `overlayOpacity` define the background-image overlay; and `boxStyle`/`boxColor`/`boxTextColor` control whether the login form is shown in a card plus its background and text colors.

`access.maintenance` controls the public maintenance lock. `mode` is one of `maintenance`, `offline`, or `launch`; `until` is an optional local datetime string; `showCountdown` enables countdown rendering for visitors; `brandAsset`, `image`, `imageLayout`, `overlayColor`, and `overlayOpacity` mirror the login visual options; and `bypassParam` plus `bypassKeyHash` define a session-based preview bypass such as `/?preview=secret`. The plaintext bypass key is never stored by the settings API.

`access.privacy.obfuscateEmails` enables JavaScript-decoded email address placeholders on public pages. The original email remains available to visitors with JavaScript enabled but is not emitted as a simple raw address in the initial HTML.

### AI Settings (`content/ai-settings.json`)

AI configuration is intentionally separate from general site settings because it
contains provider credentials, budgets, model names, feature flags, and local
provider controls. The gateway in `includes/ai/ai-helper.php` supports
OpenAI-compatible providers, including OpenAI, OpenRouter, Ollama, LM Studio, or
self-hosted compatible endpoints.

Provider credentials are stored per provider. This allows an installation to keep
separate API keys and base URLs for OpenAI and OpenRouter while switching the
active provider in the admin UI. `load-ai-settings` never returns raw API keys;
it only exposes `hasApiKey` and non-secret provider metadata.

Usage counters live in `content/ai-usage.json`; request audit lines live in
`content/ai-audit/YYYY-MM-DD.jsonl`; generated image metadata lives in
`content/ai-image-history.json`; generated image files are written to
`assets/images/generated/` and surfaced through the Media Library. Admin UI and
future core features should use the AI gateway functions or `admin/api.php` AI
actions so limits, audit logging, key handling, and generated-file storage stay
centralized.

The dashboard uses the gateway for assistant chat, text generation, SEO/AEO field
prefill, image generation, and image-to-image workflows. Local/private provider
URLs require the explicit local-provider setting. AI controls are hidden when the
AI module is disabled and rendered disabled when a configured capability lacks
the required key or model.

### Page Visibility (`content/pages/{lang}_{slug}.json`)

Pages are public by default. A page becomes password-protected when its JSON contains a private `visibility` object:

```json
{
  "visibility": {
    "status": "private",
    "title": "Protected page",
    "text": "Enter the password to continue.",
    "passwordHash": "$2y$..."
  }
}
```

The dashboard writes this through the Content Editor's Access card. The API accepts a transient `visibility.password`, hashes it with `password_hash()`, and persists only `passwordHash`. Direct JSON edits should never store plaintext passwords.

`includes/access-guard.php` enforces both maintenance mode and private pages. Logged-in admins/editors bypass both locks. Private page access is stored in the session per page after a correct password. Maintenance mode renders a `503` response with `Retry-After` when an unavailable-until value exists; private pages render a `403` password form. Both lock responses send noindex headers.

## Accessibility

Nibbly Core ships with accessibility foundations for both visitor pages and admin editing surfaces:

- Public templates include a skip link that targets `#main-content`; standard JSON pages set that id in `includes/page.php`, and `includes/footer.php` adds it to the first `<main>` as a fallback for custom templates.
- Header, mobile, and footer navigation use labelled landmarks. Current language links use `aria-current`, mobile navigation exposes `aria-hidden`, and the hamburger button uses `aria-expanded`/`aria-controls`.
- Core CSS keeps visible focus states and includes a global `prefers-reduced-motion: reduce` rule for transitions and animations.
- Inline editor and media-manager dialogs use `role="dialog"`, `aria-modal`, labelled titles, focus trapping, and focus return on close.
- Toasts and editor status messages use `role="status"` or `role="alert"` with `aria-live`.
- SEO health checks include missing image alt text, so accessibility issues surface in the same editorial workflow as metadata quality.

Site-specific templates should preserve these contracts. Use one meaningful H1, keep heading levels in order, write useful alt text for informative images, keep decorative images `alt=""`, use native buttons/links/labels, and test keyboard-only navigation before launch.

## Block Types

Defined in `includes/block-types.php`. Each block type has: label, category, icon, default values, and editor field definitions. Renderers are in `includes/block-renderers/{type}.php`.

### text
- **Category**: content
- **Fields**: `title` (input), `titleTag` (select: h1-h6), `content` (wysiwyg), `style` (select: default, highlight)
- **Defaults**: `{ "title": "", "content": "<p></p>", "titleTag": "h2" }`

### heading
- **Category**: content
- **Fields**: `text` (input), `level` (select: h1-h6), `subtitle` (input)
- **Defaults**: `{ "text": "", "level": "h2", "subtitle": "" }`

### quote
- **Category**: content
- **Fields**: `text` (textarea), `attribution` (input), `style` (select: default, large)
- **Defaults**: `{ "text": "", "attribution": "", "style": "default" }`

### list
- **Category**: content
- **Fields**: `title` (input), `style` (select: bullet, numbered), `content` (wysiwyg)
- **Defaults**: `{ "title": "", "style": "bullet", "content": "<ul><li></li></ul>" }`

### image
- **Category**: media
- **Fields**: `src` (image upload), `alt` (input), `caption` (input), `width` (select: full, medium, small)
- **Defaults**: `{ "src": "", "alt": "", "caption": "", "width": "full" }`

### card
- **Category**: cards
- **Fields**: `title` (input), `content` (textarea), `image` (image upload)
- **Defaults**: `{ "title": "", "content": "", "image": "" }`
- Consecutive cards are automatically wrapped in a `.cards-grid` container by `renderAllSections()`.

### youtube
- **Category**: media
- **Fields**: `title` (input), `videoId` (input)
- **Defaults**: `{ "title": "", "videoId": "" }`

### soundcloud
- **Category**: media
- **Fields**: `title` (input), `trackId` (input)
- **Defaults**: `{ "title": "", "trackId": "" }`

### audio
- **Category**: media
- **Fields**: `title` (input), `src` (audio upload)
- **Defaults**: `{ "title": "", "src": "" }`

### divider
- **Category**: layout
- **Fields**: none
- Renders a horizontal rule.

### spacer
- **Category**: layout
- **Fields**: `height` (select: sm, md, lg, xl)
- **Defaults**: `{ "height": "md" }`

## Template API

All functions are defined in `includes/content-loader.php`.

### Content Loading

#### `loadContent(string $page): array`
Load content from a JSON file. Returns `['sections' => []]` if file not found.
```php
$data = loadContent('en_home');
```

#### `loadContentCached(string $page): array`
Same as `loadContent()` but caches per request. Used internally by editable field functions.

#### `getContentValue(string $page, string $key): mixed`
Get a single top-level value from a page's JSON.
```php
$title = getContentValue('en_home', 'title');
```

#### `getNestedValue(array $data, string $dotKey): mixed`
Navigate a nested array using dot notation.
```php
$value = getNestedValue($data, 'hero.title'); // $data['hero']['title']
```

### Editable Text

#### `editableText(string $page, string $fieldKey, string $default = ''): string`
Render an editable plain-text field.
- **Admin**: `<span class="editable-field" data-page="..." data-field="...">text</span>`
- **Visitor**: escaped text string
- Supports `__hidden` sibling key to hide fields from visitors.

```php
<h1><?php echo editableText('en_home', 'hero.title', 'Welcome'); ?></h1>
```

#### `editableHtml(string $page, string $fieldKey, string $default = ''): string`
Render an editable rich-text (HTML) field. Admin clicks to edit inline directly on the page; a floating mini-toolbar appears with Bold, Italic, Link, Clean formatting, and HTML source toggle.
- **Admin**: `<div class="editable-field editable-field-html" ...>sanitized HTML</div>`
- **Visitor**: sanitized HTML output
- HTML is sanitized via `sanitizeHtml()` which allows safe tags and strips event handlers.

```php
<div><?php echo editableHtml('en_home', 'hero.body', '<p>Default</p>'); ?></div>
```

### Editable Links

#### `editableLink(string $page, string $fieldKey, string $defaultText = '', string $defaultHref = '#', string $class = '', string $attrs = ''): string`
Render an editable link. JSON stores `{text, href}` at the field key.
- **Admin**: `<a href="..." data-editable-link data-page="..." data-field="...">text</a>`
- **Visitor**: `<a href="..." class="...">text</a>`

```php
<?php echo editableLink('en_home', 'hero.cta', 'Get Started', '#features', 'btn btn-primary'); ?>
```

### Editable Images

#### `editableImage(string $page, string $fieldKey, string $defaultSrc = '', string $defaultAlt = '', string $class = ''): string`
Render an editable image. JSON stores `{src, alt}` at the field key.
- **Admin**: `<img ... data-editable-image data-page="..." data-field="...">`
- **Visitor**: `<img src="..." alt="..." class="...">`

```php
<?php echo editableImage('en_home', 'hero.image', 'assets/images/hero.webp', 'Hero', 'hero__img'); ?>
```

### Editable Lists

#### `editableListAttrs(string $page, string $listKey, array $defaults = []): string`
Return HTML attributes for an editable list container. The `$defaults` array defines field names and default values for newly added items.

```php
<div class="feature-grid" <?php echo editableListAttrs('en_home', 'features.items', ['icon' => 'star', 'title' => 'New', 'desc' => '']); ?>>
```

#### `editableListItemAttrs(string $page, string $listKey, int $index): string`
Return HTML attributes for a single editable list item.

```php
<div class="feature-card" <?php echo editableListItemAttrs('en_home', 'features.items', $i); ?>>
```

#### `editableListItems(string $page, string $listKey): array`
Get list items from JSON content. Returns an indexed array sorted by numeric key. Filters out hidden items for visitors.

```php
$items = editableListItems('en_home', 'features.items');
foreach ($items as $i => $item) {
    echo editableText('en_home', "features.items.$i.title", 'Feature');
}
```

#### `editableTextList(string $page, string $listKey, array $defaults = ['content' => '']): string`
Render a list of editable HTML paragraphs with add/remove/reorder/hide controls. Each paragraph is an `editableHtml()` field with inline editing and the floating toolbar. Uses the standard list system internally.

```php
echo editableTextList('en_about', 'intro.paragraphs');
```

JSON structure:
```json
{
  "intro": {
    "paragraphs": {
      "0": { "content": "First paragraph..." },
      "1": { "content": "Second paragraph with <strong>formatting</strong>..." }
    }
  }
}
```

### Field Visibility

#### `isFieldHidden(array $data, string $fieldKey): bool`
Check if a field is marked hidden via a `{fieldKey}__hidden` sibling key set to `true`. All editable field functions check this automatically.

### Render Components

#### `renderAllSections(string $page, bool $staggerCards = false): string`
Render all sections from a page's JSON `sections` array. Automatically wraps consecutive `card` blocks in a `.cards-grid` div. When admin is logged in, each section gets editable overlay controls.

```php
echo renderAllSections('en_about', true); // true = stagger animation on card grids
```

#### `renderFeatureGrid(string $page): string`
Render a grid of feature cards with icons. Reads `features.items` from JSON.
- **Item fields**: `icon` (icon ID), `title`, `desc`
- **Available icons**: database, edit, backup, image, globe, link, shield, feather, star, sparkles, zap, terminal, upload, server, folder, wand

```php
echo renderFeatureGrid('en_home');
```

#### `renderPricingTable(string $page): string`
Render pricing plan cards. Reads `pricing.plans` from JSON.
- **Item fields**: `name`, `price`, `period`, `desc`, `features` (newline-separated string), `highlight` (bool), `badge`, `cta` ({text, href})
- Also reads `pricing.vat_note` for an optional footnote.

```php
echo renderPricingTable('en_pricing');
```

#### `renderFaqAccordion(string $page): string`
Render a collapsible FAQ. Reads `faq.entries` from JSON.
- **Item fields**: `question`, `answer`
- Requires `js/faq-accordion.js` (auto-loaded by footer.php if present).

```php
echo renderFaqAccordion('en_faq');
```

#### `renderTeamGrid(string $page): string`
Render a team/contributors grid. Reads `team.members` from JSON.
- **Item fields**: `name`, `role`, `bio`, `image` ({src, alt})

```php
echo renderTeamGrid('en_about');
```

#### `renderGallery(string $page): string`
Render an image gallery with lightbox. Reads `gallery.images` from JSON.
- **Item fields**: `src`, `alt`, `caption`
- Includes a lightbox overlay in the output.

```php
echo renderGallery('en_gallery');
```

#### `renderTimeline(string $page): string`
Render a timeline/changelog. Reads `timeline.entries` from JSON.
- **Item fields**: `date`, `version`, `title`, `desc`, `status` (released/upcoming)

```php
echo renderTimeline('en_roadmap');
```

#### `renderStats(string $page): string`
Render key figures/statistics. Reads `stats.items` from JSON.
- **Item fields**: `value`, `label`, `desc`

```php
echo renderStats('en_home');
```

#### `renderTestimonials(string $page): string`
Render testimonial quote cards. Reads `testimonials.items` from JSON.
- **Item fields**: `quote`, `author`, `role`

```php
echo renderTestimonials('en_home');
```

#### `renderComparisonTable(string $page): string`
Render a feature comparison table. Reads `comparison.rows` from JSON. Columns are defined in `getComparisonColumns()`.
- **Row fields**: `label`, plus one value per column ID (use `"yes"`, `"no"`, or free text)

```php
echo renderComparisonTable('en_showcase');
```

#### `renderNewsList(int $limit = 0, string $lang = 'en'): string`
Render a grid of news post cards. Reads from `content/news/*.json`. Posts are sorted by date descending.

```php
echo renderNewsList(0, 'en'); // All posts in English
echo renderNewsList(3, 'en'); // Latest 3 posts
```

## Custom Layout Pages vs Standard Pages

**Standard pages** only need a JSON file with a `sections` array. The router serves them via `includes/page.php` which calls `renderAllSections()`. No PHP template needed. To add per-page logic (external styles/scripts, page classes) without modifying core files, create `includes/site-page-hook.php` — it runs after `$slug`, `$lang`, `$data`, `$basePath` are set but before rendering.

**Custom layout pages** have a PHP template file at `{lang}/page-slug.php` with full control over HTML structure. They use `editableText()`, `editableHtml()`, `editableImage()`, `editableLink()`, and render components to keep content editable via the admin system. Templates can set `$pageExternalStyles` (array of CSS URLs) and `$pageExternalScripts` (array of JS URLs) to load external resources per page.

**When to use which:**
- Standard: simple content pages (about, legal notices, basic articles)
- Custom: landing pages, homepages, pages with specific layouts, pages using render components

## CLI Tools

### `cli/make.php` — Page Scaffolding

Generates page boilerplate with a single command:

```bash
php cli/make.php --slug=about --lang=en --title="About Us"                  # Standard (JSON only)
php cli/make.php --slug=services --lang=de --type=custom --title="Dienste"  # Custom (PHP + JSON)
```

Options: `--slug` (required), `--lang`, `--type` (standard/custom), `--title`, `--description`, `--hide-nav`, `--dry-run`, `--force`.

### `cli/convert.php` — HTML to Nibbly Converter

Converts a static HTML page into an editable PHP template + JSON content file + extracted CSS. See `cli/README.md` for full options.

## Inline Editor System

When an admin is logged in (`$_SESSION['admin_logged_in'] === true`), the editable field functions add `data-*` attributes that the inline editor JavaScript uses to enable on-page editing.

### How It Works

1. `includes/footer.php` checks `isAdminLoggedIn()` and loads `css/inline-editor.css` + `js/inline-editor.js`
2. The editor uses an explicit **edit mode** toggle ("Edit page" button in admin bar)
3. `body.edit-mode-active` CSS class gates all editing outlines and overlays
4. Changes are collected in memory (`EditorConfig.contentData`) and only saved when the user clicks "Save"
5. Structural operations (add/delete sections, reorder list items) trigger an immediate save + page reload

### Data Attributes

- `data-page` + `data-field` on `.editable-field` elements (text, HTML)
- `data-editable-link` + `data-page` + `data-field` on links
- `data-editable-image` + `data-page` + `data-field` on images
- `data-editable-list` + `data-list-page` + `data-list-key` + `data-list-defaults` on list containers
- `data-list-page` + `data-list-key` + `data-list-index` on list items
- `data-hidden="true"` on any element that is hidden from visitors but visible to admins

## Navigation System

### Auto-Discovery

Pages with JSON content files are automatically added to navigation by `header.php`. The system scans `content/pages/{lang}_*.json` and appends any page not already listed in `$NAV_ITEMS`. System partials (`home`, `footer`, `sidebar`, `header`) are excluded automatically.

To control which menus a page appears in, set `"nav": ["header", "footer"]` in its JSON file. Use `"nav": []` to hide from all menus. Default (no field): `["header"]`. The title for auto-discovered pages is read from the JSON `title` field, with a fallback to the titlecased slug.

### `includes/nav-config.php`

Optional — only needed for explicit ordering, custom labels, or the language switcher's page mapping.

Two main data structures:

**`$PAGE_MAPPING`** - Maps page slugs to URL paths per language. Used by the language switcher.

```php
$PAGE_MAPPING = [
    'home' => ['en' => '.', 'de' => 'de/'],
    'about' => ['en' => 'about', 'de' => 'de/ueber-uns'],
];
```

**`$NAV_ITEMS`** - Navigation items per language. Each item has `href`, `label`, and `page` (slug for active state highlighting). Pages listed here appear first; auto-discovered pages are appended after.

```php
$NAV_ITEMS = [
    'en' => [
        ['href' => '.', 'label' => 'Home', 'page' => 'home'],
        ['href' => 'about', 'label' => 'About', 'page' => 'about'],
    ],
];
```

### Language Switching

`header.php` automatically generates language switch links from `$PAGE_MAPPING`. If the current page exists in the target language (via JSON file), it links there. Otherwise it falls back to the home page of that language.

The primary language (`SITE_LANG_DEFAULT`) serves pages at the root (`/about`). Secondary languages use a prefix (`/de/ueber-uns`).

## Admin API Endpoints

All endpoints are in `admin/api.php`. Authenticated via PHP sessions with CSRF token protection.

### Page Management
| Action | Method | Description |
|---|---|---|
| `list-pages` | GET | List all page JSON files |
| `create-page` | POST | Create a new page (params: `page`, `title`) |
| `copy-page` | POST | Copy page to another language |
| `delete-page` | POST | Move page to trash |
| `duplicate-page` | POST | Duplicate a page |

### Trash
| Action | Method | Description |
|---|---|---|
| `list-trash` | GET | List trashed pages |
| `restore-page` | POST | Restore page from trash |
| `delete-trash` | POST | Permanently delete trashed page |
| `empty-trash` | POST | Permanently delete all trashed pages |

### Content
| Action | Method | Description |
|---|---|---|
| `load` | GET | Load page JSON content (param: `page`) |
| `save` | POST | Save page JSON content (param: `page`, `content`) |

### Backups
| Action | Method | Description |
|---|---|---|
| `backups` | GET | List backups for a page |
| `restore` | POST | Restore a backup |
| `delete-backup` | POST | Delete a specific backup |
| `preview-backup` | GET | Preview backup content |
| `create-site-backup` | POST | Create a manual full-site ZIP backup and issue a download token |
| `download-site-backup` | GET | Stream a site backup ZIP using a one-time token |
| `restore-site-backup` | POST | Restore a full-site ZIP in `full` or `content` mode |
| `backup-status` | GET | Return scheduled backup status, retention, storage, and remote target metadata |
| `backup-list` | GET | List stored full-site ZIP backups |
| `backup-update-settings` | POST | Save scheduled backup retention, storage limit, and remote target config |
| `backup-delete` | POST | Delete a stored full-site ZIP backup |
| `backup-prepare-download` | POST | Issue a one-time token for a stored ZIP |
| `backup-upload-remote` | POST | Upload an existing ZIP to enabled remote targets |
| `backup-test-remote` | POST | Test one configured remote target with a small upload |
| `backup-dropbox-oauth-start` / `backup-dropbox-oauth-callback` | GET | Dropbox OAuth PKCE connect flow |
| `backup-google-oauth-start` / `backup-google-oauth-callback` | GET | Google Drive OAuth PKCE connect flow |
| `backup-onedrive-oauth-start` / `backup-onedrive-oauth-callback` | GET | Microsoft OneDrive OAuth PKCE connect flow |

Full-site backups are created by `includes/backup-helper.php` and can be run from cron via `cli/backup.php --action=run`. Scheduled backups use tiered retention (`daily`, `weekly`, `monthly`, `yearly`) plus an optional storage cap. Backup files are named `{site-domain}-backup-YYYY-MM-DD_HHMMSS-{tier}.zip`. Remote targets are stored under `backup.remote_targets` in `content/settings.json`; uploads are placed in a per-site subfolder below the configured remote path, secrets are masked in the dashboard, and secrets are scrubbed from `content/settings.json` when that file is written into a backup ZIP.

### Events
| Action | Method | Description |
|---|---|---|
| `load-events` | GET | Load all events |
| `load-event` | GET | Load single event |
| `save-event` | POST | Create or update event |
| `delete-event` | POST | Delete event |
| `toggle-event-visibility` | POST | Show/hide event |

### News/Blog
| Action | Method | Description |
|---|---|---|
| `load-news` | GET | List all news posts |
| `save-news` | POST | Create or update news post |
| `toggle-news-status` | POST | Show/hide news post |
| `delete-news` | POST | Delete news post |

### Media
| Action | Method | Description |
|---|---|---|
| `list-images` | GET | List uploaded images |
| `upload-image` | POST | Upload image file |
| `delete-image` | POST | Delete image file |
| `list-audio` | GET | List uploaded audio files |
| `upload-audio` | POST | Upload audio file |
| `delete-audio` | POST | Delete audio file |

### Mail & Settings
| Action | Method | Description |
|---|---|---|
| `load-mails` | GET | Load form submissions and available form metadata |
| `mark-mail-read` | POST | Mark single mail as read |
| `update-mail-flags` | POST | Update mail read/starred flags |
| `mark-all-mails-read` | POST | Mark all mails as read |
| `delete-mail` | POST | Delete a mail |
| `delete-read-mails` | POST | Delete all read mails |
| `unread-mail-count` | GET | Get count of unread mails |
| `change-password` | POST | Change admin password |
| `load-settings` | GET | Load site settings |
| `save-settings` | POST | Save site settings |

### Forms
| Action | Method | Description |
|---|---|---|
| `list-forms` | GET | List `content/forms/*.json` definitions |
| `load-form` | GET | Load one normalized form definition |
| `save-form` | POST | Save one form definition from the Forms settings panel |

Public form endpoints live in `api/`:

| Endpoint | Method | Description |
|---|---|---|
| `api/form.php?form={id}` | GET | Lazy-render a JSON form when `content/forms/{id}.json` exists, otherwise fall back to whitelisted legacy forms |
| `api/form-submit.php` | POST | Validate, notify, and store JSON form submissions |
| `api/contact.php` | POST | Legacy contact form endpoint; still stores compatible `formId` / `formLabel` metadata |

## Theming

### CSS Custom Properties

All design tokens are defined as CSS custom properties in `css/style.css`:

```css
:root {
    /* Colors — neutral blue primary */
    --color-primary: #2563eb;
    --color-primary-dark: #1d4ed8;
    --color-primary-dark-muted: #1e40af;
    --color-primary-light: #60a5fa;
    --color-primary-really-dark: #172554;
    --color-primary-btn: radial-gradient(ellipse at 50% 0%, #3b82f6 0%, #2563eb 70%);
    --color-primary-btn-hover: radial-gradient(ellipse at 50% 0%, #60a5fa 0%, #2563eb 70%);
    --color-secondary: #60a5fa;
    --color-text: #171717;
    --color-text-secondary: #525252;
    --color-background: #ffffff;
    --color-background-dark: #eff6ff;
    --color-background-darker: #dbeafe;
    --color-background-section: #f5f5f4;
    --color-background-card: #ffffff;
    --color-white: #ffffff;
    --color-black: #0a0a0a;
    --color-border: rgba(0, 0, 0, 0.08);

    /* Dark theme palette */
    --color-bg-dark: #0c0c0c;
    --color-bg-dark-elevated: #141414;
    --color-bg-dark-card: #1c1c1c;
    --color-bg-light: #fafaf9;
    --color-text-on-dark: #e5e5e5;
    --color-text-on-dark-muted: #a3a3a3;
    --color-border-dark: rgba(255, 255, 255, 0.06);
    --color-border-light: rgba(0, 0, 0, 0.06);

    /* Accent — brand gradients */
    --gradient-brand: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    --gradient-brand-text: linear-gradient(90deg, #60a5fa 0%, #3b82f6 50%, #60a5fa 100%);

    /* Glass effect */
    --glass-bg: rgba(255, 255, 255, 0.03);
    --glass-border: rgba(255, 255, 255, 0.06);

    /* Typography — system fonts */
    --font-display: system-ui, -apple-system, sans-serif;
    --font-body: system-ui, -apple-system, sans-serif;
    --font-mono: 'SF Mono', 'Fira Code', Menlo, Consolas, monospace;

    /* Spacing */
    --spacing-xs: 0.5rem;
    --spacing-sm: 1rem;
    --spacing-md: 2rem;
    --spacing-lg: 4rem;
    --spacing-xl: 6rem;

    /* Transitions */
    --transition-fast: 0.2s ease;
    --transition-normal: 0.3s ease;
    --transition-slow: 0.5s ease;

    /* Layout */
    --header-height: 80px;
    --header-bg: rgba(255, 255, 255, 0.95);
    --container-max: 1400px;
    --container-narrow: 800px;
}
```

### Dark/Light Theme

The theme toggle sets `data-theme="dark"` or `data-theme="light"` on `<html>`. Theme preference is stored in `localStorage` under key `site-theme`. A blocking `<script>` in `<head>` applies the stored theme before first paint to prevent FOUC.

### Custom Fonts

1. Place font files in `assets/fonts/`
2. Define `@font-face` rules in `css/fonts.css`
3. Uncomment the `<link rel="stylesheet" href="css/fonts.css">` in `includes/header.php`
4. Update `--font-display` and `--font-body` in `css/style.css`

Example fonts are provided in `examples/fonts/`.

## Form System

Nibbly has two public form paths:

1. **JSON-backed forms** are the current default for simple forms. Definitions
   live in `content/forms/*.json`, render through `includes/forms.php`, and
   submit to `api/form-submit.php`.
2. **Legacy PHP forms** such as `includes/contact-form.php` still submit to
   `api/contact.php` and remain supported for older templates or highly custom
   markup.

`includes/forms.php` provides the JSON form API:

| Function | Purpose |
|---|---|
| `nibblyFormLoad($id)` | Load and normalize one form definition |
| `nibblyFormSave($form)` | Normalize and write a form JSON file |
| `nibblyFormsList()` | List available forms for settings and inbox filters |
| `nibblyFormRender($id, $options)` / `nibblyForm($id, $options)` | Render a visitor-facing form |
| `nibblyFormValidateSubmission($form, $lang)` | Validate required fields and email fields |
| `nibblyFormsSaveSubmission($submission)` | Store a submission in `content/mails.json` |
| `nibblyFormsSendNotification(...)` | Send SMTP/sendmail notifications using `content/settings.json` email config |

The renderer emits `.contact-form.nibbly-json-form`, a hidden `form_id`, a
one-time token, a honeypot, `.btn-text` / `.btn-loading`, `.form-feedback`, and
a `.nibbly-form-grid` wrapper. The shared JavaScript in `includes/footer.php`
handles AJAX submission for both legacy and JSON forms.

Submissions are stored in `content/mails.json` with at least:

```json
{
  "id": "mail_...",
  "formId": "contact",
  "formLabel": "Kontaktformular",
  "name": "Example",
  "email": "hello@example.com",
  "occasion": "Kontaktanfrage: Example",
  "message": "Message body",
  "fields": [
    { "key": "name", "label": "Name", "type": "text", "value": "Example" }
  ],
  "timestamp": "2026-06-02T12:00:00+00:00",
  "status": "local_only",
  "read": false,
  "starred": false
}
```

The dashboard messages view is labelled as contact/form submissions, supports a
form filter, shows the source form in the table, and displays structured
`fields[]` values in the detail view. Older messages without `formId` are
normalized to the contact form for display.

The Forms settings panel intentionally stays narrower than a full form builder:
admins can switch forms through a select, create a new simple definition, edit
form metadata, enable/disable local storage and email notification, and edit
field type, key, label, placeholder, required state, width, and options.
Conditional display logic and custom frontend behaviour should remain in
site-owned templates or scripts.

## News/Blog System

News posts are individual JSON files in `content/news/`. Each file contains metadata (title, excerpt, date, author, image, slug, lang) and a `sections` array for the post body.

**Listing**: `renderNewsList($limit, $lang)` renders a card grid of posts. Posts are sorted by date descending and filtered by language.

**Single post**: The router maps `/en/news/slug` to `{lang}/news-post.php`, which loads the matching JSON file and renders its sections.

**Admin**: Posts are created and managed via the admin dashboard, which uses the `save-news`, `load-news`, `toggle-news-status`, and `delete-news` API actions.

## File Security

### `.htaccess` Rules

- `content/`, `backups/`, and trash directories are blocked from direct HTTP access
- The router.php dev server replicates these blocks

### Authentication

- bcrypt password hashing (`password_hash` / `password_verify`)
- IP-based brute force protection (file-backed rate limiting)
- CSRF tokens on all POST requests
- Secure session cookies (`httponly`, `samesite: Strict`)
- Session timeout enforced on every API request

### Content Sanitization

`sanitizeHtml()` strips all but safe HTML tags and removes `on*` event handlers and `javascript:` URLs from user-provided content.
