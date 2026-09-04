# Nibbly

A flat-file CMS built on PHP with no database. Content lives in JSON files, pages are PHP templates, and an inline editor lets you edit everything directly on the page. Zero dependencies, zero build steps.

**Current release:** tracked in [`VERSION`](VERSION). Examples and full documentation at [nibbly.dev](https://nibbly.dev/).

## Features

- **No database** -- all content stored as JSON files
- **Inline editing** -- click and edit text, images, and links on the live page
- **Multi-language** -- built-in language switching and per-language content files
- **Block-based content** -- 11 block types (text, heading, quote, list, image, card, youtube, soundcloud, audio, divider, spacer)
- **Render components** -- pricing tables, FAQ accordions, team grids, feature grids, galleries, timelines, stats, testimonials, comparison tables, news listings, breadcrumbs
- **Dark/light theme** -- toggle with localStorage persistence
- **Accessibility foundations** -- skip links, landmarks, reduced-motion support, labelled controls, and SEO health checks for missing image alt text
- **Access controls** -- maintenance mode with preview bypass and password-protected private pages
- **Customizable lock/login screens** -- logo/favicon, image layouts, overlays, and login box colors
- **SEO/AEO tools** -- per-page metadata, Open Graph image fallback, sitemap/robots, and health indicators
- **AI tools** -- server-side AI gateway plus frontend editor Copilot for safe chat guidance, field suggestions, content drafts, image generation/editing, usage limits, audit logs, and provider-specific API keys
- **Custom layouts** -- full PHP template control with editable field API
- **JSON-backed forms** -- manage multiple simple forms from JSON, render them in templates, and collect submissions in the dashboard inbox
- **Automatic backups** -- per-page JSON history with restore via admin panel
- **Site backup & restore** -- download full site as ZIP, restore from backup
- **Scheduled backups** -- server-cron or web-cron full-site ZIP backups with retention and storage limits
- **Remote backup targets** -- upload scheduled ZIPs to Dropbox, Google Drive, OneDrive, SFTP/SCP, S3-compatible storage, or WebDAV
- **Forms & messages** -- lazy-loaded, spam-protected public forms with local message storage, optional SMTP, and form-based inbox filtering
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

- **`AI-AGENT-GUIDE.md`** -- tool-neutral agent guide with block types, template API, and step-by-step instructions for creating pages.
- **`AGENTS.md` / `CLAUDE.md`** -- short entry points for tools that auto-load those filenames; both point to the shared guide.
- **`SKILLS.md`** -- task-oriented workflows for common agent jobs.
- **`architecture.md`** -- full technical reference covering JSON schemas, every PHP function signature, API endpoints, and the inline editor system.

An AI agent can create a new page, build a custom layout, add content blocks, and style components without asking you how things work. The documentation gives it everything it needs.

## Requirements

- PHP 7.4 or newer
- Apache with `mod_rewrite` (production) or PHP built-in server (development)
- No Composer, no npm, no database

## Directory Structure

```
admin/              Admin panel, API, setup wizard
api/                Public endpoints (contact form, etc.)
assets/
  images/           Uploaded images
  audio/            Uploaded audio files
  fonts/            Custom font files
cli/                Command-line tools (make.php, convert.php)
content/
  pages/            Page content JSON files
  forms/            Public form definitions
  news/             Blog post JSON files
  events.json       Events
  settings.json     Site-wide settings
  ai-settings.json  AI provider settings, feature flags, and limits
  ai-usage.json     AI usage counters
css/
  style.css         Core styles + CSS custom properties (do not edit)
  components.css    Core render component styles (do not edit)
  website.css       Site-owned overrides (created by you, optional)
includes/
  header.php        HTML head + navigation
  footer.php        Footer + scripts
  asset-helpers.php Core stylesheet loader (nibblyCoreStyles)
  content-loader.php  Template API
  forms.php         JSON form loading, rendering, validation, notifications
  block-types.php   Block type definitions
  block-renderers/  Per-block-type renderers
  nav-config.php    Navigation configuration
  access-guard.php  Maintenance mode and private page enforcement
  ai/               AI provider gateway, Copilot context/actions, usage limits, audit, and generated images
  menu-helpers.php  Menu registry
  page.php          Front controller for JSON-only pages
js/                 Client-side scripts
examples/           Example templates, content, css/website.css.template
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
    { "id": "s1", "type": "heading", "text": "About Us", "level": "h1" },
    { "id": "s2", "type": "text", "title": "", "content": "<p>Our story.</p>" }
  ]
}
```

Nested clean URLs are supported as well. Use a slash in the public page path
and a reserved double underscore in the flat JSON filename:

```text
content/pages/en_products__vitamin-d.json  →  /products/vitamin-d
content/pages/de_produkte__vitamin-d.json  →  /de/produkte/vitamin-d
```

When the language is the configured default, its language prefix is omitted.
The Dashboard and `php cli/make.php --slug=products/vitamin-d --lang=en`
create this mapping automatically.

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

## Accessibility Best Practices

Nibbly provides a basic accessible foundation for public pages and admin tools: skip links, landmark-friendly templates, keyboard focus styles, reduced-motion handling, labelled controls, and SEO health checks for missing image alt text.

When building custom templates or site-specific components, follow these rules:

- Use one clear `<main>` area per page. Core templates expose `id="main-content"` for the skip link; custom templates should include the same id or let `includes/footer.php` add it to the first `<main>`.
- Keep heading order meaningful. Each public page should have one visible H1, then nested H2/H3 sections.
- Enter useful alt text for informative images. Leave alt empty only for purely decorative images.
- Use real buttons for actions and real links for navigation.
- Keep visible focus states intact; do not remove outlines without replacing them.
- Respect `prefers-reduced-motion` for page-specific animations.
- Label every form field and connect validation errors with nearby text.
- Test important pages with keyboard-only navigation and a screen reader before launch.

## Access Controls

Nibbly includes frontend access controls without adding a database:

- **Maintenance mode** lives in **Dashboard -> Settings -> Access**. It can show a maintenance, back-soon, or launch-countdown page with custom title/text, optional unavailable-until time, and optional countdown.
- **Preview bypass** is configured with a URL parameter and secret key. Visiting a URL like `/?preview=secret-value` stores a session bypass so editors or clients can review the site while maintenance mode is active.
- **Private pages** are set per page in the Content Editor's **Access** card. Set visibility to `Private`, enter a password, and optionally customize the password page title/text.
- **Logged-in admins and editors bypass** maintenance mode and private page locks automatically.
- **Passwords and bypass secrets are hashed** in JSON. Do not store plaintext passwords directly in `content/pages/*.json` or `content/settings.json`.

## Theming

All design tokens are CSS custom properties defined in `css/style.css` (Nibbly Core). To customize them for your site, **don't edit `style.css` directly** -- it gets overwritten on upgrades. Instead, copy the override template and edit your own file:

```bash
cp examples/css/website.css.template css/website.css
```

`includes/header.php` loads `css/website.css` automatically after Core, so any `:root` overrides you set there win:

```css
:root {
    --color-primary: #2563eb;
    --color-text: #171717;
    --font-display: system-ui, -apple-system, sans-serif;
    --spacing-md: 2rem;
}
```

Dark theme variables are included and toggled via `data-theme="dark"` on the `<html>` element.

To use custom fonts, place font files in `assets/fonts/` and define `@font-face` rules in `css/fonts.css`. `includes/header.php` loads `css/fonts.css` automatically when both the file and the `assets/fonts/` directory exist.

## License

Nibbly is licensed under the Mozilla Public License 2.0 starting with version
1.4.0. Earlier releases up to and including 1.3.2 were published under the MIT
License. See [LICENSE](LICENSE) for details.

Third-party components and their license terms are listed in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.
