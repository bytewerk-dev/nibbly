# Nibbly

A flat-file CMS built on PHP with no database. Content lives in JSON files, pages are PHP templates, and an inline editor lets you edit everything directly on the page. Zero dependencies, zero build steps.

**Version 1.0**

## Features

- **No database** -- all content stored as JSON files
- **Inline editing** -- click and edit text, images, and links on the live page
- **Multi-language** -- built-in language switching and per-language content files
- **Block-based content** -- 11 block types (text, headings, images, cards, quotes, lists, video, audio, dividers, spacers)
- **Render components** -- pricing tables, FAQ accordions, team grids, galleries, timelines, stats, testimonials, comparison tables, news listings
- **Dark/light theme** -- toggle with localStorage persistence
- **Custom layouts** -- full PHP template control with editable field API
- **Automatic backups** -- content versioning with restore via admin panel
- **Site backup & restore** -- download full site as ZIP, restore from backup
- **Contact form** -- built-in form with file-based message storage, optional SMTP
- **News/blog system** -- post management with listing and single-post views
- **Event management** -- calendar events with multi-language support
- **No build step** -- plain PHP, HTML, CSS, vanilla JS

## Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/bytewerk-dev/nibbly.git
cd nibbly

# 2. Start the development server
php -S localhost:3000 router.php

# 3. Open the setup wizard
# Visit http://localhost:3000/admin/setup.php
# Set your site name, language, and admin password
```

That's it. Your site is running.

## Built for AI

Nibbly ships with structured documentation designed for AI coding agents:

- **`CLAUDE.md`** -- concise agent guide with block types, template API, and step-by-step instructions for creating pages. This is what Claude Code, Cursor, Copilot Workspace, or any AI tool reads first.
- **`architecture.md`** -- full technical reference covering JSON schemas, every PHP function signature, API endpoints, and the inline editor system.

An AI agent can create a new page, build a custom layout, add content blocks, and style components without asking you how things work. The documentation gives it everything it needs.

## Requirements

- PHP 7.4 or newer
- Apache with `mod_rewrite` (production) or PHP built-in server (development)
- No Composer, no npm, no database

## Directory Structure

```
admin/              Admin panel, API, setup wizard
assets/
  images/           Uploaded images
  audio/            Uploaded audio files
  fonts/            Custom font files
content/
  pages/            Page content JSON files
  news/             Blog post JSON files
  settings.json     Site-wide settings
css/
  style.css         Base styles + CSS custom properties
  components.css    Render component styles
includes/
  header.php        HTML head + navigation
  footer.php        Footer + scripts
  content-loader.php  Template API
  block-types.php   Block type definitions
  nav-config.php    Navigation configuration
  page.php          Front controller for JSON-only pages
js/                 Client-side scripts
examples/           Example templates and content files
router.php          Development server router
```

## Creating a Page

### Option 1: JSON only (standard page)

Create `content/pages/en_about.json`:

```json
{
  "page": "en_about",
  "lang": "en",
  "title": "About Us",
  "description": "Learn more about us.",
  "sections": [
    { "id": "s1", "type": "heading", "heading": "About Us", "level": "h1" },
    { "id": "s2", "type": "text", "title": "", "content": "<p>Our story.</p>" }
  ]
}
```

The page is now live at `/about`. No PHP file needed.

### Option 2: Custom layout (PHP template)

Create `en/services.php`:

```php
<?php
$pageTitle = 'Services';
$pageDescription = 'What we offer.';
$currentLang = 'en';
$currentPage = 'services';
$contentPage = 'en_services';
$basePath = '../';

include '../includes/header.php';
include '../includes/content-loader.php';
$_p = $contentPage;
?>
    <main class="main-content">
        <div class="content-inner">
            <h1><?php echo editableText($_p, 'hero.title', 'Our Services'); ?></h1>
            <?php echo renderFeatureGrid($_p); ?>
            <?php echo renderPricingTable($_p); ?>
        </div>
    </main>
<?php include '../includes/footer.php'; ?>
```

Create `content/pages/en_services.json` with the matching data structure.

See `examples/` for complete working examples.

## Theming

All design tokens are CSS custom properties in `css/style.css`:

```css
:root {
    --color-primary: #2563eb;
    --color-text: #171717;
    --color-background: #ffffff;
    --font-display: system-ui, -apple-system, sans-serif;
    --font-body: system-ui, -apple-system, sans-serif;
    --spacing-sm: 1rem;
    --spacing-md: 2rem;
    --spacing-lg: 4rem;
}
```

Change these values to match your brand. Dark theme variables are included and toggled via `data-theme="dark"` on the `<html>` element.

To use custom fonts, place font files in `assets/fonts/`, define `@font-face` rules in `css/fonts.css`, and uncomment the stylesheet link in `includes/header.php`.

## License

MIT License -- see [LICENSE](LICENSE) for details.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.
