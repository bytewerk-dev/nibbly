# Nibbly CMS — AI Agent Skills

Machine-readable instructions for common tasks. Each skill describes what to do, which files to touch, and how to verify the result.

**What Nibbly does:** Nibbly turns any HTML or PHP page into an editable website — no database required. You take an existing page (hand-crafted, designed in Figma, or vibe-coded with AI), replace hardcoded text, images, and links with PHP helper functions (`editableText()`, `editableImage()`, `editableLink()`), and get a fully functional CMS with inline editing. The helpers inject `data-*` attributes that the inline editor JavaScript discovers. Admins click to edit directly on the page. Content is stored as JSON files. Visitors see clean HTML. See `CLAUDE.md` → "How Inline Editing Works" for the full attribute reference.

---

## Skill: make-page-editable

### Description
This is Nibbly's primary use case. Take any existing HTML or PHP page — hand-built, designed in Figma and exported, or vibe-coded with AI — and turn it into a fully editable CMS page. Replace hardcoded text, images, and links with Nibbly's PHP helpers. The result: admins can edit everything directly on the page, content is stored in JSON, and visitors see clean HTML. No database, no complex migration.

### Automated Option
Run the converter tool to do this automatically:
```bash
php cli/convert.php my-page.html --slug=my-page --lang=en --dry-run
```
This parses the HTML, detects sections/headings/images/links/repeating patterns, and generates the PHP template + JSON file. Review the output with `--dry-run` first, then run without it to write files. See `cli/README.md` for all options.

### Manual Steps (Prerequisites)
- Read `CLAUDE.md` → "How Inline Editing Works" for the data-attribute system
- Read `CLAUDE.md` → "Template API" for available editable functions
- Have the static HTML page ready

### Concept
Every piece of content that should be editable needs to be wrapped in an editable PHP function. The function reads from a JSON file and outputs `data-*` attributes that the inline editor recognizes:

| Static HTML | Editable replacement |
|---|---|
| `<h1>Welcome</h1>` | `<h1><?php echo editableText($_p, 'hero.title', 'Welcome'); ?></h1>` |
| `<p>Some text</p>` | `<p><?php echo editableText($_p, 'intro.text', 'Some text'); ?></p>` |
| `<div>Rich HTML</div>` | `<div><?php echo editableHtml($_p, 'about.content', '<p>Rich HTML</p>'); ?></div>` |
| `<a href="/x">Click</a>` | `<?php echo editableLink($_p, 'hero.cta', 'Click', '/x', 'btn'); ?>` |
| `<img src="x.jpg" alt="Y">` | `<?php echo editableImage($_p, 'hero.image', 'x.jpg', 'Y', 'img-class'); ?>` |

### Steps

1. **Add the PHP boilerplate** at the top of the page template:
   ```php
   <?php
   $pageTitle = 'Page Title';
   $pageDescription = 'SEO description.';
   $currentLang = 'en';
   $currentPage = 'my-page';
   $contentPage = 'en_my-page';
   $basePath = '../';

   include '../includes/header.php';
   include '../includes/content-loader.php';
   $_p = $contentPage;
   ?>
   ```
   And the footer at the bottom:
   ```php
   <?php include '../includes/footer.php'; ?>
   ```

2. **Replace every hardcoded text** with `editableText()` or `editableHtml()`:
   - Use `editableText()` for single-line text (headings, labels, short paragraphs)
   - Use `editableHtml()` for multi-paragraph rich content with formatting
   - Choose meaningful dot-notation keys: `section.field` (e.g. `hero.title`, `about.content`, `pricing.heading`)

3. **Replace every hardcoded link** with `editableLink()`:
   ```php
   // Before: <a href="/contact" class="btn btn-primary">Contact Us</a>
   // After:
   <?php echo editableLink($_p, 'hero.cta', 'Contact Us', '/contact', 'btn btn-primary'); ?>
   ```

4. **Replace every content image** with `editableImage()`:
   ```php
   // Before: <img src="assets/images/hero.jpg" alt="Hero image" class="hero__img">
   // After:
   <?php echo editableImage($_p, 'hero.image', 'assets/images/hero.jpg', 'Hero image', 'hero__img'); ?>
   ```

5. **For repeating content** (cards, features, team members), use the editable list system:
   ```php
   <div class="cards" <?php echo editableListAttrs($_p, 'features.items', ['title' => 'New', 'desc' => '']); ?>>
       <?php foreach (editableListItems($_p, 'features.items') as $i => $item): ?>
           <div class="card" <?php echo editableListItemAttrs($_p, 'features.items', $i); ?>>
               <h3><?php echo editableText($_p, "features.items.$i.title", 'Feature'); ?></h3>
               <p><?php echo editableText($_p, "features.items.$i.desc", 'Description'); ?></p>
           </div>
       <?php endforeach; ?>
   </div>
   ```

6. **Create the matching JSON file** `content/pages/{lang}_{slug}.json` with all the keys used in the template:
   ```json
   {
     "page": "en_my-page",
     "lang": "en",
     "title": "Page Title",
     "hero": {
       "title": "Welcome",
       "subtitle": "Tagline",
       "cta": { "text": "Contact Us", "href": "/contact" },
       "image": { "src": "assets/images/hero.jpg", "alt": "Hero image" }
     },
     "features": {
       "heading": "Features",
       "items": {
         "0": { "title": "Feature 1", "desc": "Description 1" },
         "1": { "title": "Feature 2", "desc": "Description 2" }
       }
     }
   }
   ```
   Note: List items use numbered-object format (`"0": {...}`) not arrays.

7. **Register the page** in `includes/nav-config.php` (`$PAGE_MAPPING` and optionally `$NAV_ITEMS`).

### What NOT to make editable
- Navigation structure (handled by `nav-config.php`)
- CSS classes, layout structure, HTML skeleton
- Decorative images that are part of the design (use CSS `background-image` instead)
- JavaScript behavior or interactive elements

### Validation
- PHP template renders without errors
- When logged in: elements show `data-page` and `data-field` attributes in DOM inspector
- When logged in + edit mode active: clicking text makes it editable inline
- When logged in: clicking images opens image picker, clicking links opens link editor
- When logged out: page shows clean HTML with no `data-*` attributes
- Changes made in edit mode persist after "Speichern" (save) and page reload

---

## Skill: create-page

### Description
Create a new standard page with content editable via the inline editor. No PHP template needed.

### Prerequisites
- Read `CLAUDE.md` for conventions
- Read `includes/nav-config.php` for existing pages and language config

### Steps
1. Create `content/pages/{lang}_{slug}.json`:
   ```json
   {
     "page": "{lang}_{slug}",
     "lang": "{lang}",
     "title": "Page Title",
     "description": "SEO description.",
     "sections": [
       { "id": "s1", "type": "heading", "text": "Page Title", "level": "h1" },
       { "id": "s2", "type": "text", "title": "", "content": "<p>Content here.</p>" }
     ]
   }
   ```
2. Add entry to `$PAGE_MAPPING` in `includes/nav-config.php`:
   ```php
   '{slug}' => '{lang}_{slug}',
   ```
3. (Optional) Add to `$NAV_ITEMS` if the page should appear in navigation.

### Validation
- `content/pages/{lang}_{slug}.json` exists and is valid JSON
- Page is accessible at `/{slug}` (primary lang) or `/{lang}/{slug}`
- Sections render correctly via `renderAllSections()`

---

## Skill: add-section

### Description
Add a new content section to an existing page.

### Prerequisites
- Read `CLAUDE.md` → Block Types table for available types and fields
- Read the target page's JSON file

### Steps
1. Open `content/pages/{lang}_{slug}.json`
2. Append a new object to the `sections` array:
   ```json
   {
     "id": "s{next_number}",
     "type": "{block_type}",
     ...fields per block type...
   }
   ```
3. Ensure `id` is unique within the page

### Available Block Types
| Type | Required Fields |
|---|---|
| `text` | `content` (HTML) |
| `heading` | `text`, `level` (h1-h6) |
| `quote` | `text` |
| `list` | `title`, `style` (bullet/numbered), `content` (HTML) |
| `image` | `src`, `alt` |
| `card` | `title`, `content` |
| `youtube` | `videoId` |
| `soundcloud` | `trackId` |
| `audio` | `src` |
| `divider` | *(none)* |
| `spacer` | `height` (sm/md/lg/xl) |

### Validation
- JSON is valid after edit
- New section has a unique `id`
- Page renders without errors

---

## Skill: create-news-post

### Description
Create a news article that appears in the news listing.

### Prerequisites
- Read `CLAUDE.md` for conventions
- Check `content/news/` for existing slug patterns

### Steps
1. Create `content/news/{slug}.json`:
   ```json
   {
     "slug": "{slug}",
     "lang": "{lang}",
     "title": "Article Title",
     "description": "Short summary for listing.",
     "date": "YYYY-MM-DD",
     "image": "assets/images/news_{slug}.webp",
     "sections": [
       { "id": "s1", "type": "heading", "text": "Article Title", "level": "h1" },
       { "id": "s2", "type": "text", "title": "", "content": "<p>Article body.</p>" }
     ]
   }
   ```
2. (Optional) Add an image to `assets/images/`

### Validation
- `content/news/{slug}.json` exists and is valid JSON
- Post appears in news listing rendered by `renderNewsList()`
- Post is accessible at `/{lang}/news/{slug}`

---

## Skill: add-component

### Description
Add a render component (FAQ, Pricing, Team, Gallery, etc.) to a custom layout page.

### Prerequisites
- Read `CLAUDE.md` → Render Components for available components
- Read the target page's JSON and PHP template

### Steps
1. Add the data structure to the page's JSON file. Each component reads from a specific key:

   | Component | JSON Key | Item Structure |
   |---|---|---|
   | `renderFeatureGrid()` | `features.items` | `{icon, title, description}` |
   | `renderPricingTable()` | `pricing.plans` | `{name, price, period, desc, features, cta}` |
   | `renderFaqAccordion()` | `faq.entries` | `{question, answer}` |
   | `renderTeamGrid()` | `team.members` | `{name, role, bio, image}` |
   | `renderGallery()` | `gallery.images` | `{src, alt, caption}` |
   | `renderTimeline()` | `timeline.entries` | `{date, title, content}` |
   | `renderStats()` | `stats.items` | `{value, label}` |
   | `renderTestimonials()` | `testimonials.items` | `{text, author, role}` |
   | `renderComparisonTable()` | `comparison.rows` | `{feature, values[]}` |

2. Add the PHP render call in the page template:
   ```php
   <?php echo renderFaqAccordion($_p); ?>
   ```

3. Component CSS classes are in `css/components.css` (auto-loaded).

### Validation
- JSON data matches expected structure for the component
- Component renders on the page without errors
- Styling matches other components (uses BEM classes from `components.css`)

---

## Skill: change-theme

### Description
Customize the site's colors, fonts, and spacing via CSS Custom Properties.

### Prerequisites
- Read `css/style.css` → `:root` block for current token values

### Steps
1. Edit CSS custom properties in `css/style.css`:
   ```css
   :root {
       --color-primary: #your-color;
       --color-primary-dark: #darker-shade;
       --color-primary-light: #lighter-shade;
       --color-secondary: #accent-color;
       --font-display: 'Your Font', sans-serif;
       --font-body: 'Your Font', sans-serif;
   }
   ```
2. For custom fonts: place font files in `assets/fonts/`, create `css/fonts.css` with `@font-face` declarations — `header.php` loads it automatically if the file exists.
3. Update gradient tokens to match new primary color:
   ```css
   --color-primary-btn: radial-gradient(ellipse at 50% 0%, #lighter 0%, #primary 70%);
   --gradient-brand: linear-gradient(135deg, #lighter 0%, #darker 100%);
   ```

### Validation
- All color values in `:root` are consistent (dark/light/gradient variants match primary)
- No hardcoded color values outside of `:root`
- Text contrast meets WCAG AA (4.5:1 for body text)

---

## Skill: add-language

### Description
Add a new language to the site.

### Prerequisites
- Read `includes/nav-config.php` for `$SITE_LANGUAGES` and `$NAV_ITEMS`
- Read `admin/config.php` for `SITE_LANG_DEFAULT`

### Steps
1. Create directory `{lang}/` (e.g. `fr/`)
2. Create `{lang}/index.php` (homepage template):
   ```php
   <?php
   $pageTitle = 'Accueil';
   $pageDescription = 'Description.';
   $currentLang = 'fr';
   $currentPage = 'home';
   $contentPage = 'fr_home';
   $basePath = '../';
   include '../includes/header.php';
   include '../includes/content-loader.php';
   $_p = $contentPage;
   ?>
   <main class="main-content">
       <div class="content-inner">
           <?php echo renderAllSections($_p); ?>
       </div>
   </main>
   <?php include '../includes/footer.php'; ?>
   ```
3. Copy content files: for each `content/pages/{source_lang}_*.json`, create `content/pages/{lang}_*.json` with translated text and updated `"page"` and `"lang"` fields.
4. Add the language to `$SITE_LANGUAGES` in `includes/nav-config.php`:
   ```php
   $SITE_LANGUAGES = ['en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français'];
   ```
5. Add `$NAV_ITEMS['{lang}']` array mirroring the structure of existing languages.

### Validation
- `{lang}/index.php` exists
- Content files exist for all pages in the new language
- Language appears in the language switcher
- Navigation works for the new language

---

## Skill: create-custom-layout

### Description
Create a page with a custom PHP template (hero sections, grids, components) instead of the standard `renderAllSections()` approach.

### Prerequisites
- Read `CLAUDE.md` → "How to Create a Custom Layout Page"
- Read `examples/templates/page-custom.php` for reference
- Read `examples/content/custom-layout.json` for JSON structure

### Steps
1. Create `{lang}/{slug}.php`:
   ```php
   <?php
   $pageTitle = 'Page Title';
   $pageDescription = 'Description.';
   $currentLang = '{lang}';
   $currentPage = '{slug}';
   $contentPage = '{lang}_{slug}';
   $basePath = '../';

   include '../includes/header.php';
   include '../includes/content-loader.php';
   $_p = $contentPage;
   ?>
       <main class="main-content">
           <section class="hero">
               <h1><?php echo editableText($_p, 'hero.title', 'Default Title'); ?></h1>
               <p><?php echo editableText($_p, 'hero.subtitle', 'Default subtitle'); ?></p>
               <?php echo editableImage($_p, 'hero.image', 'https://placehold.co/800x400', 'Hero image'); ?>
           </section>
           <?php echo renderFeatureGrid($_p); ?>
           <?php echo renderFaqAccordion($_p); ?>
       </main>
   <?php include '../includes/footer.php'; ?>
   ```
2. Create `content/pages/{lang}_{slug}.json` with matching keys:
   ```json
   {
     "page": "{lang}_{slug}",
     "lang": "{lang}",
     "title": "Page Title",
     "hero": {
       "title": "Welcome",
       "subtitle": "Tagline",
       "image": { "src": "https://placehold.co/800x400", "alt": "Hero" }
     },
     "features": {
       "heading": "Features",
       "items": { "0": { "icon": "zap", "title": "Fast", "description": "No database." } }
     },
     "faq": {
       "heading": "FAQ",
       "entries": { "0": { "question": "How?", "answer": "Like this." } }
     }
   }
   ```
3. Add to `$PAGE_MAPPING` and optionally `$NAV_ITEMS` in `includes/nav-config.php`.

### Validation
- PHP template renders without errors
- All `editableText()`/`editableImage()`/`editableLink()` fields display content from JSON
- Render components (features, FAQ, etc.) display correctly
- Inline editor works when logged in (fields are editable, changes save)
