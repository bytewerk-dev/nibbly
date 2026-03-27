# Nibbly CMS — Examples

Reference templates and demo content to help you build pages.

## Templates

| File | Description |
|------|-------------|
| `templates/page-simple.php` | Standard page using `renderAllSections()` — content comes entirely from JSON |
| `templates/page-custom.php` | Custom layout with `editableText()`, `editableImage()`, and render components |
| `templates/page-news.php` | News listing page using `renderNewsList()` |

## Content

| File | Description |
|------|-------------|
| `content/all-block-types.json` | Every section type with example data |
| `content/custom-layout.json` | Hero + feature grid + FAQ + CTA pattern |

## Fonts

The `fonts/` directory contains optional web fonts (Playfair Display, Inter, Geist Mono).
To use them:

1. Copy the font files to your project's `assets/fonts/` directory
2. Uncomment the `fonts.css` line in `includes/header.php`
3. Update CSS variables in `css/style.css`:

```css
:root {
    --font-display: 'Playfair Display', serif;
    --font-body: 'Inter', sans-serif;
}
```

## How to Use

Copy any template to your language directory and rename it:

```bash
cp examples/templates/page-simple.php en/about.php
```

Then create the matching content file:

```bash
cp examples/content/all-block-types.json content/pages/en_about.json
```

Edit the JSON to set `"page": "en_about"` and customize the sections.
Add the page to `includes/nav-config.php` to include it in navigation.
