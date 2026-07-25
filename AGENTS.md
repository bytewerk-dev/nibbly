# Nibbly CMS - Agent Entry Point

This file is the short, tool-neutral entry point for AI coding agents. The full implementation guide lives in [`AI-AGENT-GUIDE.md`](AI-AGENT-GUIDE.md). Read that guide before making non-trivial changes.

## What Nibbly Is

Nibbly turns existing HTML or PHP pages into editable websites without a database. Content is stored in JSON files, editable PHP helpers render clean visitor HTML, and logged-in admins can edit content inline or through the dashboard.

## First Decision: Core Work or Site Work

Before editing, decide which mode applies:

- **Site adaptation mode:** You are building, converting, or customizing a website that uses Nibbly.
- **Core development mode:** You are changing Nibbly itself: admin UI, APIs, CLI tools, backup, routing, setup, editor behavior, shared helpers, or default styling.

This distinction matters because site changes should survive Nibbly updates, while core changes intentionally modify the files that updates replace.

## Site Adaptation Rules

When adapting a customer/site project that runs on Nibbly:

- For existing-site migrations, preserve the source visual design exactly. Copy
  the original local CSS, JavaScript, assets, class names, layout wrappers, and
  animation hooks first; then replace only hardcoded content with editable
  helpers. Do not redesign, simplify, reinterpret, or rebuild the CSS.
- Avoid modifying Nibbly-Core directories and files: `admin/`, `api/`, `cli/`, most of `includes/`, core `css/`, and core `js/`.
- Put editable content in `content/pages/*.json`, `content/news/*.json`, `content/events.json`, or other content JSON files.
- Put custom layouts in language/page templates such as `en/about.php` or `de/services.php`.
- Put site-owned styles in `css/website.css`, `css/page-*.css`, or `css/fonts.css`, not in `css/style.css` or `css/components.css`.
- Use documented extension points such as `includes/site-page-hook.php`.
- Use `$basePath` for asset and link paths.
- Set `$contentPage` before including `includes/header.php` so the admin bar works.
- Before finishing an existing-site migration, run the original and converted
  pages locally and compare screenshots section by section, including mobile
  breakpoints and scroll/animation states. Treat visual drift as a bug.

## Core Development Rules

When the user explicitly asks for Nibbly core behavior, admin UI, API, CLI, backup, routing, setup, editor, or shared helper changes:

- Changes to `admin/`, `api/`, `cli/`, `includes/`, core `css/`, and core `js/` are allowed.
- Keep changes focused and compatible with existing flat-file, no-database architecture.
- Preserve upgrade boundaries documented in `AI-AGENT-GUIDE.md`.
- Validate PHP with `php -l` for touched PHP files.
- Validate JSON files after editing translation or content JSON.
- For UI changes, test in the local browser when practical.

## Content Model

- JSON is the source of truth for editable content.
- For custom layout pages, meaningful PHP fallback values are acceptable and are auto-written to JSON on first admin render.
- Standard pages using `sections[]` need the section structure in JSON.
- Editable list structures must exist in JSON; individual item fields can auto-write once the list exists.

See `AI-AGENT-GUIDE.md` for examples and exact helper APIs.

## Development Server

```bash
php -S localhost:3000 router.php
```

If setup runs for a fresh test copy, use a strong generated admin password and report the credentials to the user.

## GitHub Workflow

- `main` is protected.
- Push feature or test branches.
- Merge into `main` through a Pull Request.
- Do not expect direct pushes to `main` to succeed.

## Reference Docs

- [`AI-AGENT-GUIDE.md`](AI-AGENT-GUIDE.md) - full tool-neutral implementation guide
- [`SKILLS.md`](SKILLS.md) - task-oriented workflows for AI agents
- [`architecture.md`](architecture.md) - architecture notes
- [`README.md`](README.md) - product overview
