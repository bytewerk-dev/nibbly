# Changelog

All notable changes to Nibbly are documented in this file. The project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-04-27

### Added
- **Events trash**: events are now moved to a trash collection inside
  `content/events.json` instead of being deleted immediately. Restore or empty
  via the new trash view (mirrors the Pages trash workflow).
- **Branding settings**: separate Favicon and Logo fields, plus a 3-way header
  display selector (favicon / site name / both) shown when no custom logo is
  set. Sectioned form layout (Site identity / Admin interface).
- **Image manager — drag-and-drop upload**: a footer dropzone with explicit
  upload button, replacing the toolbar-only button. Drop a file anywhere in
  the dropzone or click to browse.
- **Image manager — pre-selection**: opening the image manager from a field
  with an existing value pre-selects that image automatically.
- **Clear-X on image-path inputs**: every image path input (favicon, logo)
  now has a small × at the right edge to empty the field with one click.
- **Events list**: Title / Date / Location / Status table with hover actions
  (Edit / View / Duplicate / Trash). Status auto-derived from the event date
  (Upcoming / Today / Past / Draft).
- **Messages list**: Sender / Subject / Received table with hover actions
  (View / Delete). Unread messages rendered in bold.
- **Per-event editor view**: editing a single event now opens a dedicated
  view with Back / Save / Move-to-Trash, parallel to the Pages editor.
- **Inline mail-detail view**: replaces the modal dialog; consistent with
  the Pages and Events editor pattern.
- **Setup wizard**: writes the extended settings schema (top-level `favicon`,
  `branding.logoDisplay`).
- **CLAUDE.md rule**: page-specific styles must live in `css/page-{slug}.css`;
  `.page-*` selectors are no longer allowed in `style.css` / `components.css`.

### Changed
- **Site header**: site name is shown next to the logo when no custom logo
  is set; CSS truncation handles long names. A `branding.logo` value pointing
  at the favicon is treated as "no logo set" for migration safety.
- **Topbar layout**: on wide viewports the `View Site` button is right-
  aligned with `.admin-container`'s inner edge instead of floating at the
  far edge of the screen.
- **Admin sidebar**: sticky to the viewport bottom so the bottom block
  (Settings / Backup / Logout) sits at the bottom on tall screens.
- **Sidebar nav hover**: now uses `--nb-primary-subtle` for clear contrast
  on the blue sidebar background.
- **Admin lang files**: synchronised to 443 keys across all 9 languages
  (en / de / es / fr / it / pt / pl / cs / tr).

### Removed
- Dead `.mail-item*`, `.mails-header`, `.mails-actions` CSS (~90 lines).
- Obsolete `events.confirm_delete` translation key (replaced by the
  trash workflow, no confirm needed).

### Migration notes
- Existing installations keep working unchanged. If `content/settings.json`
  has `branding.logo` pointing at the favicon path, it is treated as "no
  logo set" and the new 3-way display selector takes effect.
- The `NIBBLY_VERSION` constant is set in the generated `admin/config.php`.
  Existing installations from 1.0.0 will still show "1.0.0" until the
  `config.php` is regenerated or the line manually updated to `1.1.0`.

## [1.0.0] — Initial release

- Flat-file PHP CMS with inline editing, no database, no build tools.
- Page templates with `editableText()`, `editableHtml()`, `editableImage()`,
  `editableLink()` helpers.
- Admin dashboard with Pages, News, Events, Messages, Settings, Backup tabs.
- Multi-language support (9 admin languages).
- Page scaffolding CLI (`cli/make.php`) and HTML-to-Nibbly converter
  (`cli/convert.php`).
