# CLAUDE.md — Frontend Website Rules

## Setup (once per project)
- If `screenshot.mjs` is missing: create it using Playwright (viewport 1440×900, deviceScaleFactor 2, saves to `./screenshots/screenshot-N.png`).
- Install dependencies: `npm install playwright` and `npx playwright install chromium`.
- Playwright's Chromium binary is cached globally — only the package itself is installed locally.

## Reference Images
- With reference image: match layout, spacing, typography, and colors exactly. No improving, no adding.
- Without reference image: design from scratch with high craft (see guardrails below).
- Screenshot → compare → fix → re-screenshot. At least 2 rounds. Stop only when no visible differences remain or user says so.

## Local Server
- Always serve over localhost — never screenshot a `file:///` URL.
- Start the PHP built-in server: `php -S localhost:3000` (serves project root, processes PHP)
- Check if port 3000 is in use before starting: `lsof -i :3000`. Never start a second instance.

## Screenshot Workflow (MANDATORY)
**After every visual change** (CSS, HTML layout, images), you MUST take a screenshot and verify the result visually. Do not rely on reading CSS alone — always confirm with a real browser screenshot.

1. Ensure `php -S localhost:3000` is running (check with `lsof -i :3000` first)
2. Run: `node screenshot.mjs http://localhost:3000/en/ label`
3. Read the screenshot PNG with the Read tool — analyze it visually
4. Fix any issues found, then screenshot again. **Minimum 2 rounds.**
5. Stop only when no visible issues remain or user says so.

- Screenshots saved to `./screenshots/screenshot-N.png` (auto-incremented, never overwritten)
- Optional label suffix: `node screenshot.mjs http://localhost:3000/en/ my-label` → `screenshot-N-my-label.png`
- When comparing, be specific: "heading is 32px but reference shows ~24px", "card gap is 16px but should be 24px"
- Check: spacing/padding, font size/weight/line-height, colors (exact hex), alignment, border-radius, shadows, image sizing

## Stack
- PHP templates with plain HTML/CSS — no JS frameworks, no build steps
- One external stylesheet per template or component, linked in `<head>`
- No inline styles except for dynamic values set via PHP
- Vanilla JS only, and only when strictly necessary

## CSS Approach
- Plain CSS — no Tailwind, no utility frameworks
- Semantic class names, BEM preferred: `.block__element--modifier`
- CSS Custom Properties for all design tokens:
```css
:root {
  --color-primary: #183678;
  --color-accent:  #2E95D2;
  --spacing-sm:    0.75rem;
  --spacing-md:    1.5rem;
  --spacing-lg:    3rem;
  --font-heading:  'Playfair Display', serif;
  --font-body:     'Inter', sans-serif;
  --radius-card:   0.75rem;
}
```

- All colors, spacing, and typography values must reference Custom Properties — no magic numbers in component styles

## Output Defaults
- Responsive layouts via CSS Grid and Flexbox
- Media queries use `min-width` (mobile-first breakpoints): `sm: 640px`, `md: 768px`, `lg: 1024px`, `xl: 1280px`
- Placeholder images: `https://placehold.co/WIDTHxHEIGHT`

## Brand Assets
- Always check the `brand_assets/` folder first (logos, color guides, style guides, images).
- Use existing assets — no placeholders where real assets are available.
- Use defined colors exactly — do not invent brand colors.

## Language
- All code comments: English
- UI copy and content: match the language of the reference or brief

## Anti-Generic Guardrails
- **Colors:** Define a purposeful palette via Custom Properties. No arbitrary color values scattered through stylesheets.
- **Shadows:** Avoid generic `box-shadow: 0 2px 4px rgba(0,0,0,0.1)`. Use layered, color-tinted shadows that reflect the brand palette.
- **Typography:** Choose fonts deliberately. Heading and body may share the same typeface if it serves the design — but the pairing must be intentional, not accidental. Good body text fonts include clean sans-serifs as well as readable slab-serifs; the goal is legibility, not a fixed formula. Apply tight tracking (`letter-spacing: -0.03em`) on large headings and generous line-height (`1.7`) on body text.
- **Gradients:** Use sparingly and intentionally. Layer subtle radial gradients or add grain/texture via SVG noise filter where depth is needed.
- **Animations:** Only animate `transform` and `opacity`. Never `transition: all`. Use `cubic-bezier` easing for spring-like feel.
- **Interactive states:** Every clickable element needs `:hover`, `:focus-visible`, and `:active` states. No exceptions.
- **Images:** Consider gradient overlays and color treatment layers (`mix-blend-mode: multiply`) for visual cohesion.
- **Spacing:** Use spacing tokens exclusively — reference `--spacing-*` Custom Properties, not arbitrary `px` or `rem` values.
- **Depth:** Surfaces should have a layering system (base → elevated → floating), not all sit at the same z-plane.

## Accessibility (required)
- All `<img>` elements need `alt` attributes.
- `:focus-visible` ring always visible — never `outline: none` without a visible alternative.
- Color contrast: min. 4.5:1 for body text (WCAG AA).
- Semantic HTML: use `<nav>`, `<main>`, `<section>`, `<article>` correctly.

## Hard Rules
- Do not add sections, features, or content not in the reference
- Do not "improve" a reference design — match it
- Do not stop after one screenshot pass
- Never use `transition: all`
- Never scatter magic numbers through stylesheets — use Custom Properties
- Never use inline styles except for PHP-injected dynamic values