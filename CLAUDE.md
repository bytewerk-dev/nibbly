# Nibbly CMS - Claude Entry Point

This file exists for tools that automatically look for `CLAUDE.md`.

The canonical, tool-neutral agent guide is [`AI-AGENT-GUIDE.md`](AI-AGENT-GUIDE.md). Read it before making non-trivial changes. For task-oriented workflows, also see [`SKILLS.md`](SKILLS.md).

## Critical Rules

- Decide first whether you are doing **site adaptation** or **Nibbly core development**.
- For site adaptation, avoid editing Nibbly-Core files such as `admin/`, `api/`, `cli/`, most of `includes/`, core `css/`, and core `js/`; use content JSON, page templates, `css/website.css`, `css/page-*.css`, assets, and documented hooks instead.
- For Nibbly core development, core files may be edited when the user explicitly asks for admin UI, API, CLI, backup, routing, setup, editor, or shared behavior changes.
- JSON is the source of truth for editable content.
- Use `$basePath` for asset and link paths.
- Set `$contentPage` before including `includes/header.php`.
- `main` is protected on GitHub; push branches and merge through Pull Requests.
