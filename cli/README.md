# Nibbly CLI Tools

## make.php — Page Scaffolding

Generates page boilerplate files with a single command.

### Usage

```bash
# Run from project root
php cli/make.php --slug=about --lang=en [options]
```

### Options

| Option | Default | Description |
|---|---|---|
| `--slug=NAME` | *(required)* | Page slug for URLs |
| `--lang=CODE` | `en` | Language code |
| `--type=TYPE` | `standard` | `standard` (JSON only) or `custom` (PHP + JSON) |
| `--title=TEXT` | From slug | Page title |
| `--description=TEXT` | *(empty)* | SEO meta description |
| `--hide-nav` | | Hide page from auto-discovered navigation |
| `--dry-run` | | Show output without writing files |
| `--force` | | Overwrite existing files |

### Examples

```bash
# Standard page — creates JSON only, front controller serves it
php cli/make.php --slug=about --lang=en --title="About Us"

# Custom layout page — creates PHP template + JSON
php cli/make.php --slug=services --lang=de --type=custom --title="Unsere Dienste"

# Preview what would be generated
php cli/make.php --slug=pricing --lang=en --type=custom --dry-run

# Page hidden from navigation (e.g. terms of service)
php cli/make.php --slug=terms --lang=en --hide-nav
```

### What it generates

**Standard pages** (`--type=standard`, default):
- `content/pages/{lang}_{slug}.json` — content file with heading + text section
- The front controller (`includes/page.php`) serves it automatically
- Navigation auto-discovery adds it to the menu

**Custom layout pages** (`--type=custom`):
- `{lang}/{slug}.php` — PHP template with `editableText()` / `editableHtml()` calls
- `content/pages/{lang}_{slug}.json` — matching content file with hero + content keys

---

## convert.php — HTML to Nibbly Converter

Converts a static HTML page into a Nibbly-editable PHP template + JSON content file.

The converter's job is to preserve the source page and make content editable.
For an existing designed page, do not use conversion as a redesign pass: keep
the original CSS, JavaScript, assets, class names, layout wrappers, animation
hooks, and responsive behavior, then replace only text, images, links, and
repeaters with Nibbly helpers.

### Usage

```bash
# Run from project root
php cli/convert.php <input.html> [options]
```

### Options

| Option | Default | Description |
|---|---|---|
| `--slug=NAME` | From filename | Page slug for URLs |
| `--lang=CODE` | `en` | Language code |
| `--title=TEXT` | From `<title>` or `<h1>` | Page title |
| `--description=TEXT` | From `<meta description>` | SEO description |
| `--dry-run` | | Show output without writing files |
| `--json-only` | | Only generate JSON, no PHP template |
| `--no-css` | | Skip CSS extraction |
| `--force` | | Overwrite existing files |

### Examples

```bash
# Preview what would be generated
php cli/convert.php my-page.html --dry-run

# Convert with custom slug and language
php cli/convert.php landing.html --slug=home --lang=de --title="Startseite"

# Only generate the JSON content file
php cli/convert.php about.html --json-only

# Convert without CSS extraction
php cli/convert.php page.html --slug=about --no-css
```

### What it does

1. Parses the HTML and identifies content sections (`<section>`, `<article>`, semantic `<div>`s)
2. Recognizes content elements: headings, text, images, links, blockquotes, lists
3. Detects repeating patterns (e.g. feature cards, testimonials) → editable lists
4. **Extracts CSS** from `<style>` blocks, linked local stylesheets, and inline `style` attributes
5. Generates a PHP template with `editableText()`, `editableImage()`, `editableLink()` calls
6. Generates a JSON content file with all extracted text and media

### CSS extraction

The converter preserves the visual design of the source HTML by extracting all CSS:

- **`<style>` blocks** — embedded CSS is extracted verbatim
- **Linked local stylesheets** (`<link rel="stylesheet" href="styles.css">`) — file contents are read and included
- **Inline styles** (`style="..."` attributes) — converted to named CSS classes (`.converted-style-1`, etc.) and the class is added to the element
- **External CDN stylesheets** (e.g. Google Fonts) — referenced via `$pageExternalStyles` array, auto-loaded by `header.php`

The extracted CSS is saved to `css/page-{slug}.css` and automatically linked via the `$pageStylesheet` variable in the generated template.

**After conversion**, review the CSS file against the original page. For
customer/site migrations, preserving the original visual behavior comes before
tokenization or cleanup:

- Keep original local stylesheets verbatim when possible, especially for
  hand-tuned layouts, animations, image crops, gradients, and responsive rules.
- Put site-owned CSS in `css/website.css` or `css/page-{slug}.css`; do not move
  customer design rules into Nibbly core `css/style.css` or `css/components.css`.
- Add only minimal compatibility CSS when editable helper wrappers affect
  existing selectors or spacing.
- Rename `.converted-style-*` classes or replace hardcoded values only after
  screenshot comparison confirms the page still matches the source.
- Do not replace the original design system with Nibbly theme variables unless
  the user explicitly requested that refactor.

### After conversion

1. Review the generated files (template, JSON, CSS) and adjust as needed
2. The page appears in navigation automatically via auto-discovery. To control ordering or labels, add it to `includes/nav-config.php` (`$PAGE_MAPPING` + `$NAV_ITEMS`)
3. Copy images to `assets/images/` and update paths if needed
4. Test with `php -S localhost:3000 router.php`
5. Run the original and converted pages locally and compare screenshots for the
   hero, every section, footer, mobile breakpoints, hover/open states, and
   scroll/animation states. Treat visual drift as a conversion bug.

---

## backup.php — Site Backup Runner

Creates full-site ZIP backups for cron jobs and ad-hoc maintenance. The dashboard writes backup policy to `content/settings.json`; the CLI reads the same settings. If a hosting plan does not allow server cron jobs, the dashboard can also generate a secret web-cron URL for external schedulers.

### Usage

```bash
# Run from project root
php cli/backup.php --action=run
```

### Actions

| Action | Description |
|---|---|
| `run` | Create one full-site ZIP, apply retention, then upload enabled remote targets |
| `prune` | Apply retention and storage limits without creating a new backup |
| `status` | Print schedule, retention, storage, and remote-target status |
| `list` | List stored backup ZIP files |
| `upload-remote` | Upload an existing backup ZIP to enabled remote targets |

### Examples

```bash
# Nightly cron job in shell/cPanel. In Plesk, prefer "Run a PHP script":
# script path: /path/to/site/cli/backup.php
# arguments:   --action=run
0 3 * * * php /path/to/site/cli/backup.php --action=run

# Web-cron fallback for external schedulers such as cron-job.org or EasyCron:
# https://example.com/api/backup-cron.php?token=<secret-token>

# Manual protected snapshot
php cli/backup.php --action=run --tier=manual

# Local ZIP only, no remote upload
php cli/backup.php --action=run --skip-remote

# Retry remote upload for an existing backup
php cli/backup.php --action=upload-remote --file=example.com-backup-2026-05-02_030000-daily.zip
```

Remote targets supported by the dashboard: Dropbox, Google Drive, Microsoft OneDrive, SFTP/SCP, S3-compatible storage, and WebDAV. Dropbox, Google Drive, and OneDrive include browser-based OAuth connect flows for refresh-token based cron uploads. Remote uploads are placed in a per-site subfolder below the configured remote path.
