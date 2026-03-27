# Nibbly CLI Tools

## convert.php — HTML to Nibbly Converter

Converts a static HTML page into a Nibbly-editable PHP template + JSON content file.

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

**After conversion**, review the CSS file and:
- Replace hardcoded colors with CSS custom properties from `css/style.css`
- Replace hardcoded spacing values with `--spacing-*` tokens
- Merge duplicate rules or rename `.converted-style-*` classes to semantic names

### After conversion

1. Review the generated files (template, JSON, CSS) and adjust as needed
2. Add the page to `includes/nav-config.php` (`$PAGE_MAPPING` + `$NAV_ITEMS`)
3. Copy images to `assets/images/` and update paths if needed
4. Test with `php -S localhost:3000 router.php`
